<?php
/**
 * Remove Unused CSS: strips CSS rules whose selectors do not appear anywhere
 * in the rendered page HTML, cached per page URL and per stylesheet.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_UCSS
 *
 * Unlike LiteSpeed's UCSS (which renders the page in a real browser via
 * QUIC.cloud), this is a pure-PHP, static-text match: it scans the final
 * HTML for class/id/tag names and drops CSS rules whose selectors never
 * appear in that text. Selectors matched by the safelist, or that cannot be
 * parsed safely (e.g. `:root`, `*`), are always kept.
 *
 * Flow
 * ----
 * 1. style_loader_src: if a fresh cached "used-only" copy of a stylesheet
 *    already exists for this URL, serve it immediately.
 * 2. template_redirect -> shutdown: buffer the entire page, extract the set
 *    of classes/ids/tags actually used, and (re)generate the cache file for
 *    every local stylesheet seen on this request so the *next* visit to the
 *    same URL gets the trimmed CSS.
 */
class BEPLUSPB_UCSS {

	/**
	 * Local stylesheet handles seen on this request: handle => file path.
	 *
	 * @var array
	 */
	private static $seen_styles = array();

	/**
	 * Public URL of each local stylesheet seen on this request: handle => src.
	 * Needed to keep relative url()/@import references working once the used-only
	 * copy lives in the cache directory.
	 *
	 * @var array
	 */
	private static $seen_urls = array();

	/**
	 * Cache key fragment identifying the current page URL.
	 *
	 * @var string|null
	 */
	private static $url_tag = null;

	/**
	 * Whether buffer_start() successfully opened a buffer.
	 *
	 * @var bool
	 */
	private static $buffering = false;

	/**
	 * Whether this request is rebuilding used-only files (see is_building()).
	 *
	 * @var bool
	 */
	private static $building = false;

	/**
	 * Learned-state header of the current page URL: ['v' => token-set version, 'n' => rebuild count].
	 *
	 * @var array|null
	 */
	private static $meta = null;

	/**
	 * Build plan computed by plan_builds() and consumed by buffer_end().
	 *
	 * @var array|null
	 */
	private static $plan = null;

	/**
	 * Hard cap on the learned tokens kept per page URL (bounds disk and memory).
	 */
	const TOKEN_CAP = 20000;

	/**
	 * Whether the last filter_css() call parsed the stylesheet cleanly.
	 *
	 * @var bool
	 */
	private static $parse_ok = true;

	/**
	 * The ob_get_level() value recorded immediately before buffer_start()'s
	 * ob_start() call. Used by buffer_end() to verify it is closing exactly
	 * the buffer it opened, not an inner buffer opened by another feature
	 * (e.g. Delay JS advanced mode) that hasn't been closed yet. See
	 * BEPLUSPB_JS's own $buffer_level docblock for the bug this prevents.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register hooks when the feature is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( empty( $opts['css_remove_unused'] ) ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( self::is_page_excluded( $opts ) ) {
			return;
		}

		if ( ! BEPLUSPB_Minify::ensure_cache_dir() ) {
			return;
		}

		self::$url_tag = self::build_url_tag();

		// Serve an already-cached "used-only" stylesheet immediately.
		add_filter( 'style_loader_src', array( __CLASS__, 'maybe_serve_cached' ), 20, 2 );

		// Buffer the full page so we can see what markup actually rendered.
		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'buffer_end' ), 0 );
	}

	/**
	 * Register invalidation hooks so stale used-only CSS is purged as soon
	 * as content that could affect it changes.
	 *
	 * Deliberately called unconditionally from plugins_loaded (see bottom of
	 * beplus-performance-booster.php), NOT from init() above — init() only
	 * runs on the front-end ('wp' action, is_admin() === false), but the
	 * events that need to trigger a purge (saving a post, switching a theme,
	 * saving the Customizer) all happen from wp-admin. If these hooks lived
	 * inside init(), editing a post in wp-admin would never register them
	 * and the purge would silently never fire.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function register_invalidation_hooks( $opts ) {
		if ( empty( $opts['css_remove_unused'] ) ) {
			return;
		}

		add_action( 'save_post', array( __CLASS__, 'purge_on_content_change' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'purge_on_status_transition' ), 10, 3 );
		add_action( 'switch_theme', array( __CLASS__, 'purge_all' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'purge_all' ) );
	}

	/**
	 * Whether the current request URI matches a css_unused_exclude keyword.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 * @return bool
	 */
	private static function is_page_excluded( $opts ) {
		$patterns = bepluspb_parse_exclude_list( $opts['css_unused_exclude'] );
		if ( empty( $patterns ) ) {
			return false;
		}

		$current_url = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $current_url, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a short, filename-safe tag identifying the current page path.
	 *
	 * @return string
	 */
	private static function build_url_tag() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$path = strtok( $uri, '?' );

		return substr( md5( $path ), 0, 12 );
	}

	// -------------------------------------------------------------------------
	// Serving cached "used-only" CSS
	// -------------------------------------------------------------------------

	/**
	 * Point a stylesheet at its cached used-only version if one exists and is
	 * still fresh (source file not modified since the cache was generated).
	 *
	 * @param  string $src    Stylesheet URL.
	 * @param  string $handle Registered style handle.
	 * @return string Possibly replaced URL.
	 */
	public static function maybe_serve_cached( $src, $handle ) {
		// Used-only CSS is built from the anonymous view of a page, so it can
		// lack selectors that only logged-in markup uses.
		if ( is_user_logged_in() ) {
			return $src;
		}

		if ( BEPLUSPB_Minify::is_css_file_excluded( $src ) ) {
			return $src;
		}

		$path = BEPLUSPB_Minify::url_to_path( $src );
		if ( ! $path ) {
			return $src; // External / not a local file — nothing we can do.
		}

		self::$seen_styles[ $handle ] = $path;
		self::$seen_urls[ $handle ]   = $src;

		$cache_file = self::cache_path( $handle, $path );
		if ( file_exists( $cache_file ) && filemtime( $cache_file ) >= filemtime( $path ) ) {
			$cache_url = self::cache_url( $handle, $path );
			$query     = wp_parse_url( $src, PHP_URL_QUERY );
			return $query ? $cache_url . '?' . $query : $cache_url;
		}

		return $src;
	}

	/**
	 * Build the cache filename for one stylesheet on the current page.
	 *
	 * @param  string      $handle Style handle.
	 * @param  string      $path    Absolute filesystem path of the source file.
	 * @param  string|null $version Token-set version (null = the page URL's current one).
	 * @return string
	 */
	private static function cache_name( $handle, $path, $version = null ) {
		// The salt invalidates files written by older versions of the filter; the
		// version is the learned token set, so a rebuild with more tokens gets a new
		// file name (browsers and CDNs never keep serving the previous, smaller one).
		$version      = null === $version ? self::meta_version() : $version;
		$content_hash = substr( md5( md5_file( $path ) . '|3|' . $version ), 0, 10 );
		return 'ucss-' . self::$url_tag . '-' . sanitize_file_name( $handle ) . '-' . $content_hash . '.css';
	}

	/**
	 * Absolute filesystem path of the cache file for a stylesheet.
	 *
	 * @param  string      $handle Style handle.
	 * @param  string      $path    Absolute filesystem path of the source file.
	 * @param  string|null $version Token-set version (null = the page URL's current one).
	 * @return string
	 */
	private static function cache_path( $handle, $path, $version = null ) {
		return BEPLUSPB_CACHE_DIR . self::cache_name( $handle, $path, $version );
	}

	/**
	 * Public URL of the cache file for a stylesheet.
	 *
	 * @param  string $handle Style handle.
	 * @param  string $path   Absolute filesystem path of the source file.
	 * @return string
	 */
	private static function cache_url( $handle, $path ) {
		return BEPLUSPB_Minify::cache_url() . self::cache_name( $handle, $path );
	}

	// -------------------------------------------------------------------------
	// Learned token set (union across visits)
	// -------------------------------------------------------------------------

	/**
	 * Path of the learned-state file of the current page URL.
	 *
	 * Format: line 1 = JSON header {"v":version,"n":rebuilds}, line 2 = JSON list of
	 * every class/id/tag seen on this URL. Serving only reads line 1.
	 *
	 * @return string
	 */
	private static function state_path() {
		return BEPLUSPB_CACHE_DIR . 'ucss-tokens-' . self::$url_tag . '.txt';
	}

	/**
	 * Header of the learned state (version + rebuild count), read once per request.
	 *
	 * @return array{v:string,n:int}
	 */
	private static function load_meta() {
		if ( null !== self::$meta ) {
			return self::$meta;
		}
		self::$meta = array(
			'v' => '',
			'n' => 0,
		);
		$file       = self::state_path();
		if ( is_file( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$h = @fopen( $file, 'r' );
			if ( $h ) {
				$line = fgets( $h, 512 );
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$data = is_string( $line ) ? json_decode( $line, true ) : null;
				if ( is_array( $data ) && isset( $data['v'], $data['n'] ) && is_string( $data['v'] ) && preg_match( '/^[a-f0-9]{10}$/D', $data['v'] ) ) {
					self::$meta = array(
						'v' => $data['v'],
						'n' => max( 0, (int) $data['n'] ),
					);
				}
			}
		}
		return self::$meta;
	}

	/**
	 * Current token-set version of this page URL ('' before anything was learned).
	 *
	 * @return string
	 */
	private static function meta_version() {
		return self::load_meta()['v'];
	}

	/**
	 * Every token learned so far for the current page URL.
	 *
	 * @return array Token set (token => true).
	 */
	private static function load_tokens() {
		$set  = array();
		$file = self::state_path();
		if ( ! is_file( $file ) || '' === self::load_meta()['v'] ) {
			return $set;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw   = @file_get_contents( $file );
		$lines = is_string( $raw ) ? explode( "\n", $raw, 3 ) : array();
		$list  = isset( $lines[1] ) ? json_decode( $lines[1], true ) : null;
		if ( is_array( $list ) ) {
			foreach ( array_slice( $list, 0, self::TOKEN_CAP ) as $token ) {
				if ( is_string( $token ) && '' !== $token && strlen( $token ) <= 120 ) {
					$set[ $token ] = true;
				}
			}
		}
		return $set;
	}

	/**
	 * Persist the learned state.
	 *
	 * @param array  $tokens  Token set (token => true).
	 * @param string $version Version of that set.
	 * @param int    $count   Rebuilds done for this URL.
	 * @return bool
	 */
	private static function save_state( $tokens, $version, $count ) {
		$body       = wp_json_encode(
			array(
				'v' => $version,
				'n' => $count,
			)
		) . "\n" . wp_json_encode( array_map( 'strval', array_keys( $tokens ) ) ) . "\n";
		self::$meta = array(
			'v' => $version,
			'n' => $count,
		);
		return BEPLUSPB_Minify::write_cache_file_atomic( self::state_path(), $body );
	}

	// -------------------------------------------------------------------------
	// Cache invalidation
	// -------------------------------------------------------------------------

	/**
	 * Delete every cached "used-only" CSS file (ucss-*.css) without touching
	 * the unrelated minified-JS/CSS cache managed by BEPLUSPB_Minify.
	 *
	 * Deliberately a blanket purge rather than a single-URL purge: we have
	 * no reliable way to know which OTHER pages (home, archives, widgets)
	 * render an excerpt/snippet of the post that just changed, so the safe
	 * choice is to invalidate every used-only cache file and let the normal
	 * "first visit re-generates" flow repopulate them.
	 *
	 * @return int Number of files deleted.
	 */
	public static function purge_all() {
		$dir = BEPLUSPB_CACHE_DIR;
		if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted = 0;

		// Per-generation lock files are dot-prefixed and would otherwise pile up.
		$locks = glob( $dir . '.ucss-*' );
		if ( is_array( $locks ) ) {
			foreach ( $locks as $lock ) {
				if ( is_file( $lock ) ) {
					wp_delete_file( $lock );
				}
			}
		}

		// Learned token sets describe the old content; start learning again.
		$states = glob( $dir . 'ucss-tokens-*.txt' );
		if ( is_array( $states ) ) {
			foreach ( $states as $state ) {
				if ( is_file( $state ) ) {
					wp_delete_file( $state );
				}
			}
		}
		self::$meta = null;

		$files = glob( $dir . 'ucss-*.css' );
		if ( ! $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_wp_delete_file
			if ( @unlink( $file ) ) {
				++$deleted;
			}
		}

		if ( $deleted > 0 ) {
			// Stored pages link these files; keep them in step.
			do_action( 'bepluspb_assets_cache_cleared' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		}

		return $deleted;
	}

	/**
	 * Save_post fires on every save (including autosave/revision) — filter
	 * down to real, user-triggered saves of a public, viewable post before
	 * purging.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public static function purge_on_content_change( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		self::purge_all();
	}

	/**
	 * Transition_post_status catches publish/unpublish transitions that
	 * don't necessarily fire save_post with the final status yet visible
	 * (e.g. scheduled posts going live via wp-cron).
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function purge_on_status_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}

		// Only care about transitions that change what's publicly visible.
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::purge_all();
		}
	}

	// -------------------------------------------------------------------------
	// Buffering + generation
	// -------------------------------------------------------------------------

	/**
	 * Whether this request may create new used-only CSS cache files.
	 *
	 * Cache names are keyed on the request path, so unbounded URL spaces
	 * (404s, searches) would let anonymous visitors create unlimited files.
	 * Logged-in and preview renders also contain markup (admin bar, draft
	 * content) that must not decide the stylesheet served to everyone else.
	 * Already-cached files are still served to all of these requests.
	 *
	 * @return bool
	 */
	private static function is_generation_allowed() {
		if ( is_404() || is_search() || is_preview() || is_customize_preview() || is_user_logged_in() || BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Start buffering the full page output.
	 */
	public static function buffer_start() {
		self::$buffer_level = null;

		if ( is_admin() || ! self::is_generation_allowed() ) {
			return;
		}
		self::$buffering    = true;
		self::$buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * Flush the buffered page to the browser, then regenerate any stale
	 * used-only CSS caches for the stylesheets seen on this request.
	 */
	public static function buffer_end() {
		// Only close the exact buffer level this class itself opened
		// (level + 1). Using an exact match instead of a "< 1" check
		// prevents accidentally closing an inner buffer opened by another
		// feature (e.g. Delay JS advanced mode) that hasn't finished yet —
		// see BEPLUSPB_JS's $buffer_level docblock for the failure mode
		// this used to cause.
		if ( ! self::$buffering || null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			if ( self::$buffering && null !== self::$buffer_level && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional WP_DEBUG_LOG diagnostic, not left-over debugging; helps diagnose a future conflict with another plugin's own output buffering (see CHANGELOG 1.0.10).
				error_log(
					sprintf(
						'[BEPLUSPB] Remove Unused CSS: expected to close output buffer at level %d but current level is %d — another plugin/theme may have opened or closed an output buffer unexpectedly; used-only CSS was not regenerated for this page.',
						(int) self::$buffer_level + 1,
						ob_get_level()
					)
				);
			}
			self::$buffering    = false;
			self::$buffer_level = null;
			return;
		}

		self::$buffering    = false;
		self::$buffer_level = null;

		$html = ob_get_clean();
		if ( false === $html ) {
			return;
		}

		// Decide BEFORE the page is sent whether files will be (re)built: a page
		// cache must not store this response, because it still links the full
		// stylesheets. The next visit renders with the used-only links and is stored.
		$todo           = self::plan_builds( $html );
		self::$building = ! empty( $todo );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;

		// Let the browser start rendering before we do the (heavier) CSS work.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		if ( empty( $todo ) ) {
			return;
		}

		$plan     = self::$plan;
		$used     = $plan['tokens'];
		$opts     = bepluspb_get_options();
		$safelist = array_merge( self::default_safelist(), bepluspb_parse_exclude_list( $opts['css_unused_safelist'] ) );

		foreach ( $todo as $handle => $path ) {
			$cache_file = self::cache_path( $handle, $path, $plan['version'] );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$css = @file_get_contents( $path );
			if ( false === $css || '' === trim( $css ) ) {
				continue;
			}

			$filtered = self::filter_css( $css, $used, $safelist );
			if ( ! empty( self::$seen_urls[ $handle ] ) ) {
				$filtered = self::absolutize_css_urls( $filtered, self::$seen_urls[ $handle ] );
			}
			$filtered = BEPLUSPB_Minify::minify_css( $filtered );

			self::write_deduplicated_cache( $cache_file, $filtered );
		}

		if ( $plan['grow'] ) {
			// Switch the URL over to the enlarged token set only after its files exist.
			self::save_state( $used, $plan['version'], $plan['count'] );

			// A stored copy of this page links the previous, smaller generation.
			if ( '' !== $plan['old_version'] ) {
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				$uri  = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				do_action( 'bepluspb_page_cache_purge_url', ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
			}
		}
	}

	/**
	 * Whether this request is about to (re)build used-only files, i.e. the HTML
	 * being sent still references the original stylesheets and must not be
	 * stored by a page cache.
	 *
	 * @return bool
	 */
	public static function is_building() {
		return self::$building;
	}

	/**
	 * Which of the stylesheets seen on this request need a (re)build.
	 *
	 * Empty when the response is not a complete 200 HTML page, when every file
	 * is already fresh, or when the cache is full.
	 *
	 * @param  string $html Buffered page output.
	 * @return array handle => source path.
	 */
	private static function plan_builds( $html ) {
		if ( empty( self::$seen_styles ) ) {
			return array();
		}

		// Only a complete, successful HTML page may decide which rules are "used".
		if ( ! self::is_complete_html_response( $html, function_exists( 'http_response_code' ) ? (int) http_response_code() : 0 ) ) {
			return array();
		}
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
					return array();
				}
			}
		}

		// Learn: the stylesheet is built from the UNION of what every visit to this URL
		// showed, so markup that only some visits render (cookie banner, notices,
		// logged-out popups) is covered after it has been seen once.
		$meta  = self::load_meta();
		$known = self::load_tokens();
		$seen  = array();
		foreach ( array_keys( self::extract_used_tokens( $html ) ) as $token ) {
			if ( strlen( $token ) <= 120 ) {
				$seen[ $token ] = true;
			}
		}
		$new = array_diff_key( $seen, $known );

		/**
		 * Filters how many times the used-only CSS of one page URL may be rebuilt
		 * because new markup appeared (bounds work for pages that print random ids).
		 *
		 * @param int $max Default 8.
		 */
		$max_rebuilds = (int) apply_filters( 'bepluspb_ucss_max_rebuilds', 8 );
		$grow         = ! empty( $new ) && count( $known ) < self::TOKEN_CAP && ( $meta['n'] < $max_rebuilds || '' === $meta['v'] );

		$tokens = $known;
		if ( $grow ) {
			$tokens = $known + array_slice( $new, 0, self::TOKEN_CAP - count( $known ), true );
			ksort( $tokens, SORT_STRING );
			$version = substr( md5( implode( "\n", array_keys( $tokens ) ) ), 0, 10 );
		} else {
			$tokens  = $known + $seen; // Built from everything known plus this view; not persisted.
			$version = $meta['v'];
		}

		$todo = array();
		foreach ( self::$seen_styles as $handle => $path ) {
			$cache_file = self::cache_path( $handle, $path, $version );
			if ( ! $grow && file_exists( $cache_file ) && filemtime( $cache_file ) >= filemtime( $path ) ) {
				continue; // Already fresh.
			}
			$todo[ $handle ] = $path;
		}

		/**
		 * Filters the maximum number of used-only CSS cache files kept on disk.
		 *
		 * @param int $max Default 2000.
		 */
		$max_files = (int) apply_filters( 'bepluspb_ucss_max_files', 2000 );
		if ( $todo && $max_files > 0 ) {
			$files = glob( BEPLUSPB_CACHE_DIR . 'ucss-*.css' );
			$slots = $max_files - ( is_array( $files ) ? count( $files ) : 0 );
			$todo  = $slots > 0 ? array_slice( $todo, 0, $slots, true ) : array(); // Cache full: keep serving the originals.
		}

		self::$plan = array(
			'tokens'      => $tokens,
			'version'     => $version,
			'grow'        => $grow && ! empty( $todo ),
			'count'       => $meta['n'] + 1,
			'old_version' => $meta['v'],
		);

		return $todo;
	}

	/**
	 * Write a UCSS cache file while sharing disk blocks with an identical one.
	 *
	 * Cache names remain URL-specific because two pages can legitimately use
	 * different selectors. When the generated bytes are identical, a hard link
	 * preserves those independent names without storing duplicate content. If
	 * the filesystem cannot create hard links, fall back to a normal write.
	 *
	 * Also removes obsolete content-hash generations for the same URL/handle.
	 *
	 * @param string $cache_file Destination cache path.
	 * @param string $content    Minified used-only CSS.
	 * @return bool Whether the cache was written or linked successfully.
	 */
	public static function write_deduplicated_cache( $cache_file, $content ) {
		$dir = dirname( $cache_file ) . '/';
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$base         = basename( $cache_file );
		$generation   = preg_replace( '/-[a-f0-9]{10}\.css$/', '', $base );
		$content_hash = hash( 'sha256', $content );
		$lock_file    = $dir . '.ucss-lock-' . hash( 'sha256', $generation );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$lock_handle = @fopen( $lock_file, 'c' );
		if ( false === $lock_handle || ! flock( $lock_handle, LOCK_EX ) ) {
			if ( is_resource( $lock_handle ) ) {
				fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return false;
		}

		$temp_file = tempnam( $dir, '.ucss-' );
		if ( false === $temp_file ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		$prepared   = false;
		$candidates = glob( $dir . 'ucss-*.css' );
		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				if ( $candidate === $cache_file || ! is_file( $candidate ) || filesize( $candidate ) !== strlen( $content ) ) {
					continue;
				}
				if ( function_exists( 'link' ) && hash_file( 'sha256', $candidate ) === $content_hash ) {
					wp_delete_file( $temp_file );
					$prepared = @link( $candidate, $temp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( $prepared && hash_file( 'sha256', $temp_file ) !== $content_hash ) {
						wp_delete_file( $temp_file );
						$temp_file = tempnam( $dir, '.ucss-' );
						$prepared  = false;
						if ( false === $temp_file ) {
							flock( $lock_handle, LOCK_UN );
							fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							return false;
						}
					}
					break;
				}
			}
		}

		if ( ! $prepared ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$prepared = false !== @file_put_contents( $temp_file, $content );
		}

		if ( ! $prepared || ! @rename( $temp_file, $cache_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		// A hard link shares the older file's mtime; freshness compares it with the source file's.
		@touch( $cache_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_touch

		// The current generation is now safely in place. Only then remove older
		// content-hash variants for this exact URL tag and stylesheet handle.
		$old_files = glob( $dir . $generation . '-*.css' );
		if ( is_array( $old_files ) ) {
			foreach ( $old_files as $old_file ) {
				// Pages already sent to browsers/CDNs may still link the previous generation: keep it a day.
				if ( $old_file !== $cache_file && is_file( $old_file ) && filemtime( $old_file ) < time() - 86400 ) {
					wp_delete_file( $old_file );
				}
			}
		}

		flock( $lock_handle, LOCK_UN );
		fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	// -------------------------------------------------------------------------
	// Used-token extraction
	// -------------------------------------------------------------------------

	/**
	 * Build a lookup set of every class (.foo), id (#bar), and tag name used
	 * anywhere in the given HTML. Handles double-quoted, single-quoted and
	 * unquoted attribute values.
	 *
	 * @param  string $html Full rendered page HTML.
	 * @return array Associative array used as a set (token => true).
	 */
	public static function extract_used_tokens( $html ) {
		$tokens = array();

		if ( preg_match_all( '/\bclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$value = '' !== ( $match[1] ?? '' ) ? $match[1] : ( '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' ) );
				foreach ( preg_split( '/\s+/', trim( $value ) ) as $c ) {
					if ( '' !== $c ) {
						$tokens[ '.' . $c ] = true;
					}
				}
			}
		}

		if ( preg_match_all( '/\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$value = '' !== ( $match[1] ?? '' ) ? $match[1] : ( '' !== ( $match[2] ?? '' ) ? $match[2] : ( $match[3] ?? '' ) );
				$value = trim( $value );
				if ( '' !== $value ) {
					$tokens[ '#' . $value ] = true;
				}
			}
		}

		if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)\b/', $html, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tokens[ strtolower( $tag ) ] = true;
			}
		}

		return $tokens;
	}

	/**
	 * Selector keywords that are always kept: classes that JavaScript toggles
	 * or generates at runtime and therefore never appear in the server-rendered
	 * HTML this feature scans. Matched as substrings (see selector_is_used()).
	 * The admin's own safelist is merged on top.
	 *
	 * @return string[]
	 */
	public static function default_safelist() {
		$list = array(
			// Generic state classes toggled by scripts.
			'.open',
			'.opened',
			'.active',
			'.show',
			'.showing',
			'.shown',
			'.hide',
			'.hidden',
			'.visible',
			'.collapse',
			'.collapsed',
			'.collapsing',
			'.expanded',
			'.fade',
			'.selected',
			'.checked',
			'.sticky',
			'.stuck',
			'.fixed',
			'.scrolled',
			'.loading',
			'.loaded',
			'.lazy',
			'.animated',
			'.animate',
			'.is-open',
			'.is-active',
			'.is-visible',
			'.is-hidden',
			'.is-sticky',
			'.is-stuck',
			'.is-fixed',
			'.is-loading',
			'.is-loaded',
			'.is-expanded',
			'.is-collapsed',
			'.is-selected',
			'.is-checked',
			'.is-scrolled',
			'.is-show',
			'.is-shown',
			'.is-focused',
			'.is-opened',
			'.is-closed',
			'-open',
			'-opened',
			'-active',
			'-visible',
			'-expanded',
			'-stuck',
			'-scrolled',
			'.js',
			'.no-js',
			'.error',
			'.invalid',
			'.success',
			'.valid',
			// Lazy load / animation helpers.
			'.lazyload',
			'.aos-',
			'.animate__',
			'.wow',
			// Sliders, lightboxes, popups that build their own markup.
			'.swiper-',
			'.slick-',
			'.owl-',
			'.flickity-',
			'.splide',
			'.glide',
			'.flex-',
			'.mfp-',
			'.pswp',
			'.fancybox',
			'.lightbox',
			'.modal',
			'.tooltip',
			'.popover',
			'.dropdown',
			// WordPress / WooCommerce / forms / cookie banners (state set after load or on action).
			'.woocommerce-message',
			'.woocommerce-error',
			'.woocommerce-info',
			'.woocommerce-notice',
			'.blockUI',
			'.added_to_cart',
			'.adding_to_cart',
			'.added',
			'.select2',
			'.wpcf7-',
			'cookie',
			'gdpr',
			'consent',
			'cmplz',
			// Page builders.
			'.elementor-sticky',
			'.elementor-popup',
			'.elementor-invisible',
		);

		/**
		 * Filters the built-in Remove Unused CSS safelist.
		 *
		 * @param string[] $list Substrings of selectors that must always be kept.
		 */
		$list = apply_filters( 'bepluspb_ucss_default_safelist', $list );

		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/**
	 * Whether the buffered response may be used to build used-only CSS: a
	 * complete HTML document sent with HTTP 200. Anything else (maintenance
	 * 503, error pages, output cut short by a fatal) would produce a stylesheet
	 * that misses rules the real page needs, and that file is then served to
	 * every visitor.
	 *
	 * @param string $html     Buffered page output.
	 * @param int    $status   HTTP status code of the response.
	 * @return bool
	 */
	public static function is_complete_html_response( $html, $status ) {
		if ( 200 !== (int) $status ) {
			return false;
		}
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
			return false;
		}

		return false !== stripos( substr( $html, -2048 ), '</html>' );
	}

	// -------------------------------------------------------------------------
	// CSS URL rewriting
	// -------------------------------------------------------------------------

	/**
	 * Rewrite relative url() and @import references to absolute URLs.
	 *
	 * @param  string $css      CSS content.
	 * @param  string $base_url Public URL of the ORIGINAL stylesheet.
	 * @return string
	 */
	public static function absolutize_css_urls( $css, $base_url ) {
		return BEPLUSPB_Minify::absolutize_css_urls( $css, $base_url );
	}

	// -------------------------------------------------------------------------
	// CSS parsing / filtering
	// -------------------------------------------------------------------------

	/**
	 * Filter unused rules out of a CSS string.
	 *
	 * @param  string $css      Raw CSS content.
	 * @param  array  $used     Used-token lookup set from extract_used_tokens().
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string Filtered CSS.
	 */
	public static function filter_css( $css, $used, $safelist ) {
		self::$parse_ok = true;
		$out            = self::filter_block( $css, $used, $safelist );

		// Fail open: malformed CSS (unclosed block or parenthesis) is served untouched
		// rather than silently losing every rule after the break.
		return self::$parse_ok ? $out : $css;
	}

	/**
	 * Find the end of a quoted string. $i points at the opening quote.
	 *
	 * @param  string $css CSS text.
	 * @param  int    $i   Index of the opening quote.
	 * @param  int    $len Length of $css.
	 * @return int Index just past the closing quote (or $len when unterminated).
	 */
	private static function skip_string( $css, $i, $len ) {
		$quote = $css[ $i ];
		++$i;
		while ( $i < $len ) {
			if ( '\\' === $css[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( $css[ $i ] === $quote ) {
				return $i + 1;
			}
			++$i;
		}
		return $len;
	}

	/**
	 * Find the end of a /* comment. $i points at the opening slash.
	 *
	 * @param  string $css CSS text.
	 * @param  int    $i   Index of the opening slash.
	 * @param  int    $len Length of $css.
	 * @return int Index just past the closing delimiter.
	 */
	private static function skip_comment( $css, $i, $len ) {
		$end = strpos( $css, '*/', $i + 2 );
		return false === $end ? $len : $end + 2;
	}

	/**
	 * Find the index just past the "}" that closes a block whose "{" is at $open.
	 * Aware of strings, comments, escapes and parentheses (so a "}" inside
	 * content:"}" or an unquoted data: URL does not end the block).
	 *
	 * @param  string $css  CSS text.
	 * @param  int    $open Index of the opening "{".
	 * @param  int    $len  Length of $css.
	 * @return int|false False when the block is never closed.
	 */
	private static function find_block_end( $css, $open, $len ) {
		$depth = 0;
		$paren = 0;
		$i     = $open;
		while ( $i < $len ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				$i += 2;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$i = self::skip_string( $css, $i, $len );
				continue;
			}
			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$i = self::skip_comment( $css, $i, $len );
				continue;
			}
			if ( '(' === $c ) {
				++$paren;
			} elseif ( ')' === $c && $paren > 0 ) {
				--$paren;
			} elseif ( 0 === $paren ) {
				if ( '{' === $c ) {
					++$depth;
				} elseif ( '}' === $c ) {
					--$depth;
					if ( 0 === $depth ) {
						return $i + 1;
					}
				}
			}
			++$i;
		}
		return false;
	}

	/**
	 * Recursively filter a block of CSS (top-level or inside an @-rule).
	 * Comments are dropped; statement at-rules (@import, @charset, @layer a,b;
	 *
	 * @namespace) are copied through untouched and never merged into the next rule.
	 *
	 * @param  string $css      CSS block to filter.
	 * @param  array  $used     Used-token lookup set.
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string Filtered CSS.
	 */
	private static function filter_block( $css, $used, $safelist ) {
		$out    = '';
		$len    = strlen( $css );
		$i      = 0;
		$buffer = '';
		$paren  = 0;

		while ( $i < $len ) {
			$char = $css[ $i ];

			if ( '\\' === $char ) {
				$buffer .= substr( $css, $i, 2 );
				$i      += 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$end     = self::skip_string( $css, $i, $len );
				$buffer .= substr( $css, $i, $end - $i );
				$i       = $end;
				continue;
			}

			if ( '/' === $char && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$i = self::skip_comment( $css, $i, $len );
				continue;
			}

			if ( '(' === $char ) {
				++$paren;
			} elseif ( ')' === $char && $paren > 0 ) {
				--$paren;
			}

			if ( 0 === $paren && ';' === $char ) {
				$statement = trim( $buffer );
				if ( '' !== $statement && '@' === $statement[0] ) {
					$out .= $statement . ';';
				}
				$buffer = '';
				++$i;
				continue;
			}

			if ( 0 === $paren && '}' === $char ) {
				$buffer = ''; // Stray closing brace: ignore.
				++$i;
				continue;
			}

			if ( 0 === $paren && '{' === $char ) {
				$selector = trim( $buffer );
				$buffer   = '';
				$end      = self::find_block_end( $css, $i, $len );
				if ( false === $end ) {
					self::$parse_ok = false; // Never closed: do not guess, the caller keeps the original CSS.
					break;
				}
				$inner = substr( $css, $i + 1, $end - $i - 2 );

				if ( '' !== $selector && '@' === $selector[0] ) {
					$out .= self::handle_at_rule( $selector, $inner, $used, $safelist );
				} elseif ( '' !== $selector && self::selector_is_used( $selector, $used, $safelist ) ) {
					$out .= $selector . '{' . $inner . '}';
				}

				$i = $end;
				continue;
			}

			$buffer .= $char;
			++$i;
		}

		if ( $paren > 0 ) {
			self::$parse_ok = false; // Unbalanced "(": everything after it would be swallowed.
		}

		return $out;
	}

	/**
	 * Handle an @-rule: recurse into conditional group rules (@media, @supports,
	 *
	 * @layer, @container, ...) and drop them if the filtered contents end up
	 * empty; keep every other block at-rule (@font-face, @keyframes, @page,
	 * @property, unknown future rules) verbatim.
	 *
	 * @param  string $selector The @-rule prelude, e.g. "@media (min-width:600px)".
	 * @param  string $inner    Raw CSS inside the @-rule's braces.
	 * @param  array  $used     Used-token lookup set.
	 * @param  array  $safelist Selector keywords that must always be kept.
	 * @return string
	 */
	private static function handle_at_rule( $selector, $inner, $used, $safelist ) {
		$name = preg_match( '/^@(?:-[a-z]+-)?([a-zA-Z-]+)/', $selector, $m ) ? strtolower( $m[1] ) : '';

		$recurse = array( 'media', 'supports', 'document', 'layer', 'container', 'scope', 'starting-style' );
		if ( ! in_array( $name, $recurse, true ) ) {
			return $selector . '{' . $inner . '}';
		}

		$filtered = self::filter_block( $inner, $used, $safelist );
		if ( '' === trim( $filtered ) ) {
			// A named empty layer still fixes the layer order: keep the declaration.
			if ( 'layer' === $name && preg_match( '/^@layer\s+([^\s{;,]+)\s*$/i', $selector, $lm ) ) {
				return '@layer ' . $lm[1] . ';';
			}
			return '';
		}

		return $selector . '{' . $filtered . '}';
	}

	/**
	 * Split a selector list on top-level commas only (commas inside (), [] or
	 * strings, e.g. :is(.a, .b), belong to their selector).
	 *
	 * @param  string $selectors Selector list.
	 * @return string[]
	 */
	private static function split_selector_list( $selectors ) {
		$parts = array();
		$cur   = '';
		$depth = 0;
		$len   = strlen( $selectors );

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $selectors[ $i ];
			if ( '\\' === $c ) {
				$cur .= substr( $selectors, $i, 2 );
				++$i;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$end  = self::skip_string( $selectors, $i, $len );
				$cur .= substr( $selectors, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ( ')' === $c || ']' === $c ) && $depth > 0 ) {
				--$depth;
			}
			if ( ',' === $c && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
				continue;
			}
			$cur .= $c;
		}
		$parts[] = $cur;

		return $parts;
	}

	/**
	 * Decode CSS escapes in an identifier (".md\:flex" => "md:flex", "\31 0" => "10").
	 *
	 * @param  string $ident Identifier as written in a stylesheet.
	 * @return string
	 */
	private static function unescape_ident( $ident ) {
		if ( false === strpos( $ident, '\\' ) ) {
			return $ident;
		}
		$ident = preg_replace_callback(
			'/\\\\([0-9a-fA-F]{1,6})\s?/',
			static function ( $m ) {
				return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
			},
			$ident
		);
		return preg_replace( '/\\\\(.)/s', '$1', $ident );
	}

	/**
	 * Whether at least one comma-separated selector in $selector_list matches
	 * a used class/id/tag token or a safelist entry ('*' wildcard supported).
	 * Selectors that can't be safely parsed (e.g. `:root`, `*`) are kept.
	 *
	 * @param  string $selector_list Raw selector list, e.g. ".a, .b:hover".
	 * @param  array  $used          Used-token lookup set.
	 * @param  array  $safelist      Selector keywords that must always be kept.
	 * @return bool
	 */
	private static function selector_is_used( $selector_list, $used, $safelist ) {
		foreach ( self::split_selector_list( $selector_list ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}

			foreach ( $safelist as $safe ) {
				$safe = trim( $safe );
				if ( '' === $safe ) {
					continue;
				}
				if ( '*' === substr( $safe, -1 ) ) {
					if ( 0 === strpos( $selector, rtrim( $safe, '*' ) ) ) {
						return true;
					}
				} elseif ( $selector === $safe || false !== strpos( $selector, $safe ) ) {
					return true;
				}
			}

			// Drop attribute selectors, then pseudo-classes/elements (including
			// balanced arguments such as :not(.a) / :is(.a, .b)), then combinators.
			$clean = preg_replace( '/\[(?:[^\]"\']|"[^"]*"|\'[^\']*\')*\]/', '', $selector );
			$clean = preg_replace( '/(?<!\\\\)::?[a-zA-Z-]+(\((?:[^()]++|(?1))*\))?/', '', $clean );
			$clean = preg_replace( '/(?<!\\\\)[>+~]/', ' ', $clean );

			$ident = '(?:[a-zA-Z0-9_-]|[^\x00-\x7F]|\\\\(?:[0-9a-fA-F]{1,6}\s?|.))+';
			if ( preg_match_all( '/(\.' . $ident . '|#' . $ident . '|(?<![\w\\\\-])[a-zA-Z][a-zA-Z0-9-]*)/s', $clean, $m ) && ! empty( $m[1] ) ) {
				foreach ( $m[1] as $token ) {
					$first  = $token[0];
					$lookup = ( '.' === $first || '#' === $first )
						? $first . self::unescape_ident( substr( $token, 1 ) )
						: strtolower( $token );
					if ( isset( $used[ $lookup ] ) ) {
						return true;
					}
				}
			} else {
				// Universal selector `*`, `:root`, or something unparseable — keep it safe.
				return true;
			}
		}

		return false;
	}
}
