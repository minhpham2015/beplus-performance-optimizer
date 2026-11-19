<?php
/**
 * Cloudflare API integration: cache purge, development mode, and zone
 * lookup by API Token. Admin-triggered only (no front-end/outbound calls
 * on regular page loads) — mirrors the "no outbound requests from the
 * front-end path" principle already followed by class-bepluspb-cdn.php.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Cloudflare
 */
class BEPLUSPB_Cloudflare {

	const API_BASE = 'https://api.cloudflare.com/client/v4';

	const FILES_PER_REQUEST = 30;   // Cloudflare's limit for "purge by URL" on most plans.
	const MAX_FILES         = 60;   // More URLs than this (bulk edits): purge everything once instead.
	const ALL_COOLDOWN      = 60;   // Seconds between two automatic "purge everything" calls.
	const RESULT_TRANSIENT  = 'bepluspb_cf_auto_purge';
	const MAX_TERMS         = 10;   // Term archives purged per taxonomy for one edit.

	/** Post ids and extra URLs collected during this request, purged once at shutdown. @var array */
	private static $queue = array(
		'posts' => array(),
		'urls'  => array(),
	);

	/** Whether the shutdown flush is already registered. @var bool */
	private static $flush_hooked = false;

	/**
	 * Purge everything on the configured Cloudflare zone.
	 *
	 * Safe to call unconditionally — no-ops (returns a wp_error-like array)
	 * when Cloudflare integration is disabled or not configured, so callers
	 * (e.g. handle_clear_cache()) never need their own enabled-check.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function purge_all() {
		$opts = bepluspb_get_options();

		if ( empty( $opts['cloudflare_enabled'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Cloudflare integration is turned off.', 'beplus-performance-booster' ),
			);
		}

		$zone_id = $opts['cloudflare_zone_id'];
		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured. Test the connection first.', 'beplus-performance-booster' ),
			);
		}

		$result = self::call(
			$opts['cloudflare_api_token'],
			'DELETE',
			"/zones/{$zone_id}/purge_cache",
			array( 'purge_everything' => true )
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to purge Cloudflare cache: ', 'beplus-performance-booster' ) . $result['message'],
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Cloudflare cache purged successfully.', 'beplus-performance-booster' ),
		);
	}

	// =========================================================================
	// Purge by URL when a post is edited (opt-in, `cloudflare_purge_on_edit`)
	// =========================================================================

	/**
	 * Register the hooks that purge the edited post's URLs. Runs from plugins_loaded, so saves made in wp-admin,
	 * over REST, by WP-Cron (scheduled posts) and from WP-CLI are all seen. Nothing is registered unless the
	 * administrator turned the feature on and the zone is configured.
	 */
	public static function register_hooks() {
		if ( ! self::auto_purge_ready( bepluspb_get_options() ) ) {
			return;
		}
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 20, 3 );
	}

	/**
	 * Whether automatic purge by URL is switched on and usable.
	 *
	 * @param array $opts Options.
	 * @return bool
	 */
	public static function auto_purge_ready( $opts ) {
		return ! empty( $opts['cloudflare_enabled'] ) && ! empty( $opts['cloudflare_purge_on_edit'] )
			&& ! empty( $opts['cloudflare_zone_id'] ) && ! empty( $opts['cloudflare_api_token'] );
	}

	/**
	 * Whether this request may purge: a save in wp-admin, over REST, from WP-Cron or WP-CLI. An ordinary
	 * front-end page load never talks to Cloudflare.
	 *
	 * @return bool
	 */
	private static function is_save_context() {
		return ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * A post changed status (also fires for plain updates of a published post, as publish -> publish).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! is_object( $post ) || ( 'publish' !== $new_status && 'publish' !== $old_status ) || ! self::is_save_context() ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( function_exists( 'is_post_type_viewable' ) && ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		self::enqueue_post( (int) $post->ID );
	}

	/**
	 * A post was updated: when a published post's address changed (slug, parent, date), the OLD address is
	 * still cached at Cloudflare.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public static function on_post_updated( $post_id, $after, $before ) {
		if ( ! is_object( $before ) || ! is_object( $after ) || 'publish' !== $before->post_status || ! self::is_save_context() ) {
			return;
		}
		if ( $before->post_name === $after->post_name && $before->post_parent === $after->post_parent && $before->post_date === $after->post_date ) {
			return;
		}
		$old = get_permalink( $before );
		if ( is_string( $old ) && '' !== $old ) {
			self::$queue['urls'][] = $old;
			self::hook_flush();
		}
	}

	/**
	 * Remember a post to purge at the end of the request (one purge for any number of hooks and saves).
	 *
	 * @param int $post_id Post id.
	 */
	private static function enqueue_post( $post_id ) {
		if ( $post_id < 1 ) {
			return;
		}
		self::$queue['posts'][ $post_id ] = true;
		self::hook_flush();
	}

	/** Register the shutdown flush once. */
	private static function hook_flush() {
		if ( ! self::$flush_hooked ) {
			self::$flush_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 20 );
		}
	}

	/**
	 * Purge everything queued during this request. Runs at shutdown, so the page the editor sees is not held
	 * up (the response is released first when PHP-FPM allows it) and the final state of terms and meta is used.
	 */
	public static function flush_queue() {
		$queue       = self::$queue;
		self::$queue = array(
			'posts' => array(),
			'urls'  => array(),
		);
		self::$flush_hooked = false;
		$opts               = bepluspb_get_options();
		if ( ! self::auto_purge_ready( $opts ) || ( ! $queue['posts'] && ! $queue['urls'] ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$urls = $queue['urls'];
		foreach ( array_keys( $queue['posts'] ) as $post_id ) {
			$post = get_post( $post_id );
			if ( is_object( $post ) ) {
				$urls = array_merge( $urls, self::urls_for_post( $post ) );
			}
		}
		$urls = self::clean_urls( $urls, home_url( '/' ) );
		if ( ! $urls ) {
			return;
		}
		self::store_result( self::purge_urls( $urls ) );
	}

	/**
	 * Every public address that shows an edited post: its own, the home page, the posts page / post type
	 * archive, the archives of its terms, its author's archive and the main feed. (Page 2+ of archives and
	 * date archives are not listed: they expire with the Cloudflare cache lifetime.)
	 *
	 * @param WP_Post $post Post.
	 * @return array Raw URLs, validated later by clean_urls().
	 */
	public static function urls_for_post( $post ) {
		$urls      = array();
		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			// A trashed post's slug carries a "__trashed" suffix: purge the address visitors had.
			$urls[] = preg_replace( '/__trashed(?=\/?$)/', '', $permalink );
		}
		$urls[] = home_url( '/' );
		if ( 'post' === $post->post_type ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page > 0 ) {
				$urls[] = get_permalink( $posts_page );
			}
		} else {
			$archive = get_post_type_archive_link( $post->post_type );
			if ( $archive ) {
				$urls[] = $archive;
			}
		}
		foreach ( (array) get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( array_slice( $terms, 0, self::MAX_TERMS ) as $term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}
		if ( ! empty( $post->post_author ) ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}
		$urls[] = get_feed_link();
		return $urls;
	}

	/**
	 * Keep only addresses of THIS site (same scheme, host and port as the home URL), without credentials or
	 * fragments, once each. The list only ever travels to the Cloudflare API as text; nothing here is requested.
	 *
	 * @param array  $urls Raw URLs.
	 * @param string $home Site home URL.
	 * @return array
	 */
	public static function clean_urls( $urls, $home ) {
		$site = wp_parse_url( (string) $home );
		if ( ! is_array( $site ) || empty( $site['host'] ) || empty( $site['scheme'] ) ) {
			return array();
		}
		$site_port = isset( $site['port'] ) ? (int) $site['port'] : ( 'https' === strtolower( $site['scheme'] ) ? 443 : 80 );
		$out       = array();
		foreach ( (array) $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20\x7F"<>\\\\^`{|}]/', $url ) ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
				continue;
			}
			$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
			if ( strtolower( $parts['scheme'] ) !== strtolower( $site['scheme'] ) || strtolower( $parts['host'] ) !== strtolower( $site['host'] ) || $port !== $site_port ) {
				continue;
			}
			$out[ $url ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * Purge a list of this site's URLs at Cloudflare. More than MAX_FILES (a bulk edit) purges everything once,
	 * with a cooldown so a series of bulk edits cannot hammer the API.
	 *
	 * @param array $urls Clean URLs.
	 * @return array{success:bool,message:string,count:int,mode:string}
	 */
	public static function purge_urls( $urls ) {
		$opts = bepluspb_get_options();
		if ( ! self::auto_purge_ready( $opts ) ) {
			return array(
				'success' => false,
				'message' => __( 'Cloudflare is not configured.', 'beplus-performance-booster' ),
				'count'   => 0,
				'mode'    => 'files',
			);
		}
		$count = count( $urls );
		if ( $count > self::MAX_FILES ) {
			if ( get_transient( self::RESULT_TRANSIENT . '_lock' ) ) {
				return array(
					'success' => false,
					'message' => __( 'A full Cloudflare purge ran less than a minute ago; this one was skipped.', 'beplus-performance-booster' ),
					'count'   => $count,
					'mode'    => 'all',
				);
			}
			set_transient( self::RESULT_TRANSIENT . '_lock', 1, self::ALL_COOLDOWN );
			$result = self::purge_all();
			return array(
				'success' => ! empty( $result['success'] ),
				'message' => (string) $result['message'],
				'count'   => $count,
				'mode'    => 'all',
			);
		}
		foreach ( array_chunk( array_values( $urls ), self::FILES_PER_REQUEST ) as $chunk ) {
			$result = self::call( $opts['cloudflare_api_token'], 'POST', '/zones/' . rawurlencode( $opts['cloudflare_zone_id'] ) . '/purge_cache', array( 'files' => $chunk ), 8 );
			if ( ! $result['success'] ) {
				return array(
					'success' => false,
					'message' => $result['message'],
					'count'   => $count,
					'mode'    => 'files',
				);
			}
		}
		return array(
			'success' => true,
			'message' => '',
			'count'   => $count,
			'mode'    => 'files',
		);
	}

	/**
	 * Keep the outcome of the last automatic purge for the settings screen (no URLs, no token).
	 *
	 * @param array $result Result of purge_urls().
	 */
	private static function store_result( $result ) {
		set_transient(
			self::RESULT_TRANSIENT,
			array(
				'time'    => time(),
				'success' => ! empty( $result['success'] ),
				'count'   => (int) $result['count'],
				'mode'    => 'all' === $result['mode'] ? 'all' : 'files',
				'message' => sanitize_text_field( (string) $result['message'] ),
			),
			WEEK_IN_SECONDS
		);
	}

	/**
	 * The last automatic purge, for the settings screen.
	 *
	 * @return array|null
	 */
	public static function last_auto_purge() {
		$value = get_transient( self::RESULT_TRANSIENT );
		return is_array( $value ) && isset( $value['time'] ) ? $value : null;
	}

	/**
	 * Look up the Cloudflare zone matching the current site's domain and
	 * persist its zone_id into the plugin options.
	 *
	 * Called from the "Test Connection" button — never runs automatically
	 * on a page load, only on explicit admin action.
	 *
	 * @param string $api_token API Token to test/use (not necessarily the
	 *                          one already saved — lets "Test Connection"
	 *                          validate a token before the settings form is
	 *                          submitted).
	 * @return array{success:bool,message:string,zone_id:string,zone_name:string}
	 */
	public static function test_connection_and_fetch_zone( $api_token ) {
		if ( ! $api_token ) {
			return array(
				'success'   => false,
				'message'   => __( 'No API Token provided.', 'beplus-performance-booster' ),
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		$domain = self::current_site_domain();

		$result = self::call( $api_token, 'GET', '/zones?status=active&name=' . rawurlencode( $domain ) );

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'message'   => $result['message'],
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		if ( empty( $result['data'] ) ) {
			return array(
				'success'   => false,
				/* translators: %s: site domain that was looked up on Cloudflare. */
				'message'   => sprintf( __( 'No Cloudflare zone found for domain "%s". Confirm the domain is added to your Cloudflare account.', 'beplus-performance-booster' ), $domain ),
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		$zone = $result['data'][0];

		return array(
			'success'   => true,
			/* translators: %s: matched Cloudflare zone name (domain). */
			'message'   => sprintf( __( 'Connected. Matched zone: %s', 'beplus-performance-booster' ), $zone['name'] ),
			'zone_id'   => $zone['id'],
			'zone_name' => $zone['name'],
		);
	}

	/**
	 * Get the current Cloudflare development-mode status for the
	 * configured zone.
	 *
	 * @return array{success:bool,message:string,enabled:bool}
	 */
	public static function get_development_mode() {
		$opts    = bepluspb_get_options();
		$zone_id = $opts['cloudflare_zone_id'];

		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured.', 'beplus-performance-booster' ),
				'enabled' => false,
			);
		}

		$result = self::call( $opts['cloudflare_api_token'], 'GET', "/zones/{$zone_id}/settings/development_mode" );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => $result['message'],
				'enabled' => false,
			);
		}

		return array(
			'success' => true,
			'message' => '',
			'enabled' => 'on' === $result['data']['value'],
		);
	}

	/**
	 * Turn Cloudflare Development Mode on or off for the configured zone.
	 *
	 * Development Mode auto-expires after 3 hours — that expiry is a
	 * Cloudflare-side behavior, not something this plugin tracks or relies
	 * on; "Check Status" always re-queries the live value.
	 *
	 * @param bool $on True to turn on, false to turn off.
	 * @return array{success:bool,message:string}
	 */
	public static function set_development_mode( $on ) {
		$opts    = bepluspb_get_options();
		$zone_id = $opts['cloudflare_zone_id'];

		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured.', 'beplus-performance-booster' ),
			);
		}

		$result = self::call(
			$opts['cloudflare_api_token'],
			'PATCH',
			"/zones/{$zone_id}/settings/development_mode",
			array( 'value' => $on ? 'on' : 'off' )
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => $result['message'],
			);
		}

		return array(
			'success' => true,
			'message' => $on
				? __( 'Development Mode turned ON. It will automatically turn off after 3 hours.', 'beplus-performance-booster' )
				: __( 'Development Mode turned OFF.', 'beplus-performance-booster' ),
		);
	}

	/**
	 * Low-level Cloudflare API call, API-Token auth only.
	 *
	 * Never logs the token or the raw request/response — Cloudflare API
	 * Tokens are as sensitive as the Redis AUTH password this plugin
	 * already protects (see class-bepluspb-object-cache.php Hard Rule #1);
	 * they are stored in wp_options (DB-only, never a static file), and
	 * intentionally never written to any debug log.
	 *
	 * @param string      $api_token API Token.
	 * @param string      $method    HTTP method.
	 * @param string      $path      API path, starting with '/'.
	 * @param array|false $body      Request body to JSON-encode, or false for none.
	 * @param int         $timeout   Seconds to wait for the answer.
	 * @return array{success:bool,message:string,data:mixed}
	 */
	private static function call( $api_token, $method, $path, $body = false, $timeout = 15 ) {
		$args = array(
			'method'  => $method,
			'timeout' => (int) $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( false !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'data'    => null,
			);
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) || empty( $json['success'] ) ) {
			$error_message = __( 'Unexpected response from Cloudflare.', 'beplus-performance-booster' );
			if ( is_array( $json ) && ! empty( $json['errors'][0]['message'] ) ) {
				$error_message = sanitize_text_field( $json['errors'][0]['message'] );
			}
			return array(
				'success' => false,
				'message' => $error_message,
				'data'    => null,
			);
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => $json['result'],
		);
	}

	/**
	 * Get the current site's bare domain (no scheme, no www, no path) for
	 * matching against Cloudflare zone names.
	 *
	 * @return string
	 */
	private static function current_site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = (string) $host;
		return preg_replace( '/^www\./', '', $host );
	}
}
