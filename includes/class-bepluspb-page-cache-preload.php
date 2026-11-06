<?php
/**
 * Page Cache preload: warms the cache after it was emptied.
 *
 * The URL list is built from the site's own published content (home page plus public
 * posts, pages and custom post types), never from user input or a remote sitemap, and
 * every request goes to the site's own host only: no redirects are followed and a URL
 * whose host differs from home_url() is skipped. That keeps this from becoming an SSRF
 * vector (CLAUDE.md: CDN/Cloudflare notes, Things NOT to do).
 *
 * Work runs from WP-Cron in small batches so no single request is long. Requests are
 * anonymous desktop GETs, so only the shared/desktop copy is warmed; mobile copies
 * (when stored separately) are still created on the first real mobile visit.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cache warm-up queue driven by WP-Cron. */
class BEPLUSPB_Page_Cache_Preload {

	/** Cron hook that builds the URL list and starts a run. */
	const START_HOOK = 'bepluspb_page_cache_preload_start';

	/** Cron hook that warms the next batch. */
	const TICK_HOOK = 'bepluspb_page_cache_preload_tick';

	/** Transient with the queue and progress of the current run. */
	const STATE_TRANSIENT = 'bepluspb_page_cache_preload';

	/** Transient preventing two ticks from running at once. */
	const LOCK_TRANSIENT = 'bepluspb_page_cache_preload_lock';

	/** Default / hard upper bound for the number of URLs per run. */
	const DEFAULT_MAX_URLS = 500;
	const HARD_MAX_URLS    = 2000;

	/** URLs warmed per cron tick. */
	const BATCH_SIZE = 5;

	/** Consecutive failed requests after which a run is abandoned. */
	const MAX_FAILURES = 5;

	/** Seconds to wait after a purge before starting (collapses bursts of purges). */
	const START_DELAY = 60;

	/** Seconds between two batches. */
	const TICK_DELAY = 30;

	/**
	 * Whether preload is switched on (and the page cache itself is enabled).
	 *
	 * @param array|null $opts Options (defaults to the saved ones).
	 * @return bool
	 */
	public static function is_enabled( $opts = null ) {
		$opts = is_array( $opts ) ? $opts : bepluspb_get_options();
		return ! empty( $opts['page_cache_preload'] ) && BEPLUSPB_Page_Cache::is_enabled( $opts );
	}

	/**
	 * Register the cron callbacks. Must exist whether or not preload is on so a pending
	 * event still finds its handler after the option is switched off.
	 */
	public static function register_core_hooks() {
		add_action( self::START_HOOK, array( __CLASS__, 'start' ) );
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Register the purge listener (only while the page cache is on).
	 *
	 * @param array $opts Options.
	 */
	public static function register_invalidation_hooks( $opts ) {
		if ( self::is_enabled( $opts ) ) {
			add_action( 'bepluspb_page_cache_purged_all', array( __CLASS__, 'on_purged' ), 20, 0 );
		}
	}

	/**
	 * Purge listener: the action passes arguments that must not be mistaken for options.
	 */
	public static function on_purged() {
		self::schedule();
	}

	/**
	 * Queue a fresh run shortly from now. Any run in progress is dropped: its list is stale
	 * because the cache it was warming has just been emptied.
	 *
	 * @param array|null $opts Options when the caller already holds them (otherwise the saved ones).
	 */
	public static function schedule( $opts = null ) {
		if ( ! self::is_enabled( is_array( $opts ) ? $opts : null ) ) {
			return;
		}
		delete_transient( self::STATE_TRANSIENT );
		wp_clear_scheduled_hook( self::TICK_HOOK );
		if ( ! wp_next_scheduled( self::START_HOOK ) ) {
			wp_schedule_single_event( time() + self::START_DELAY, self::START_HOOK );
		}
	}

	/**
	 * Stop everything and forget the queue.
	 */
	public static function cancel() {
		wp_clear_scheduled_hook( self::START_HOOK );
		wp_clear_scheduled_hook( self::TICK_HOOK );
		delete_transient( self::STATE_TRANSIENT );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Cron: build the URL list and begin warming.
	 */
	public static function start() {
		if ( ! self::is_enabled() ) {
			return;
		}
		$urls = self::collect_urls();
		set_transient(
			self::STATE_TRANSIENT,
			array(
				'state'   => $urls ? 'running' : 'done',
				'urls'    => $urls,
				'pos'     => 0,
				'ok'      => 0,
				'failed'  => 0,
				'streak'  => 0,
				'started' => time(),
			),
			12 * HOUR_IN_SECONDS
		);
		if ( $urls ) {
			wp_schedule_single_event( time() + 5, self::TICK_HOOK );
		}
	}

	/**
	 * Cron: warm the next batch, then queue the following one.
	 */
	public static function tick() {
		if ( ! self::is_enabled() ) {
			self::cancel();
			return;
		}
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		$state = get_transient( self::STATE_TRANSIENT );
		if ( ! is_array( $state ) || 'running' !== ( $state['state'] ?? '' ) || empty( $state['urls'] ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 2 * MINUTE_IN_SECONDS );

		$total = count( $state['urls'] );
		for ( $i = 0; $i < self::BATCH_SIZE && $state['pos'] < $total; $i++ ) {
			$url = $state['urls'][ $state['pos'] ];
			++$state['pos'];
			if ( self::warm( $url ) ) {
				++$state['ok'];
				$state['streak'] = 0;
			} else {
				++$state['failed'];
				++$state['streak'];
				if ( $state['streak'] >= self::MAX_FAILURES ) {
					$state['state'] = 'failed'; // Loopback blocked, server down… stop hammering it.
					break;
				}
			}
		}
		if ( 'running' === $state['state'] && $state['pos'] >= $total ) {
			$state['state'] = 'done';
		}
		set_transient( self::STATE_TRANSIENT, $state, 12 * HOUR_IN_SECONDS );
		delete_transient( self::LOCK_TRANSIENT );

		if ( 'running' === $state['state'] ) {
			wp_schedule_single_event( time() + self::TICK_DELAY, self::TICK_HOOK );
		}
	}

	/**
	 * Request one URL as an anonymous visitor so the cache stores it.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return bool Whether the page answered 200.
	 */
	private static function warm( $url ) {
		if ( ! self::is_own_url( $url ) ) {
			return false;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0, // A redirect may leave the site: never follow it.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, same as WP's own loopback requests.
				'headers'     => array( 'User-Agent' => 'BeplusPB-Preload' ),
				'cookies'     => array(),
			)
		);
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Whether a URL points at this site's own host over http(s), without a query string.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! $parts || ! $home || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || ! empty( $parts['query'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return false;
		}
		return strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& ( $parts['port'] ?? null ) === ( $home['port'] ?? null );
	}

	/**
	 * Build the list of URLs worth warming: home page first, then the most recently modified content.
	 *
	 * @return string[]
	 */
	public static function collect_urls() {
		$max = (int) apply_filters( 'bepluspb_page_cache_preload_max_urls', self::DEFAULT_MAX_URLS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		$max = max( 1, min( self::HARD_MAX_URLS, $max ) );

		$urls = array( home_url( '/' ) );

		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		if ( $types ) {
			$ids = get_posts(
				array(
					'post_type'              => array_values( $types ),
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => $max,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( (array) $ids as $id ) {
				$link = get_permalink( $id );
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		$urls = (array) apply_filters( 'bepluspb_page_cache_preload_urls', $urls ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		$cfg  = BEPLUSPB_Page_Cache_Engine::load_config();

		$clean = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || ! self::is_own_url( $url ) ) {
				continue;
			}
			// Same rules as real visitors: unusable paths and excluded URLs are never stored, so don't request them.
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( null === BEPLUSPB_Page_Cache_Engine::path_segments( '' === $path ? '/' : $path ) ) {
				continue;
			}
			if ( $cfg && BEPLUSPB_Page_Cache_Engine::is_excluded_path( $cfg, '' === $path ? '/' : $path, '' ) ) {
				continue;
			}
			$clean[ $url ] = true;
			if ( count( $clean ) >= $max ) {
				break;
			}
		}
		return array_keys( $clean );
	}

	/**
	 * Progress for the settings screen.
	 *
	 * @return array{state:string,total:int,done:int,failed:int}|null Null when no run is known.
	 */
	public static function get_status() {
		$state = get_transient( self::STATE_TRANSIENT );
		if ( ! is_array( $state ) || empty( $state['state'] ) ) {
			return null;
		}
		return array(
			'state'  => (string) $state['state'],
			'total'  => isset( $state['urls'] ) ? count( $state['urls'] ) : 0,
			'done'   => (int) ( $state['ok'] ?? 0 ),
			'failed' => (int) ( $state['failed'] ?? 0 ),
		);
	}
}
