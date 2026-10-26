<?php
/**
 * Static-HTML page cache (WordPress side).
 *
 * The serving/storing/purging engine lives in lib/class-bepluspb-page-cache-engine.php and is
 * plain PHP so it can run from wp-content/advanced-cache.php before WordPress
 * boots. This class owns everything that needs WordPress: the guarded config
 * file, the advanced-cache.php stub lifecycle, capturing the rendered page,
 * and — critically — invalidation (CLAUDE.md hard rule #7).
 *
 * Only anonymous GET requests for public HTML pages are ever cached. Logged-in
 * users, personalised cookies (cart, comment author, password-protected posts),
 * query strings, errors, feeds, search, previews and anything that sets a
 * cookie or asks not to be cached are bypassed.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once BEPLUSPB_PLUGIN_DIR . 'lib/class-bepluspb-page-cache-engine.php';

/** WordPress integration for the static-HTML page cache. */
class BEPLUSPB_Page_Cache {

	/** Marker proving advanced-cache.php was written by this plugin. */
	const DROPIN_MARKER = 'BEPLUSPB_PAGE_CACHE_DROPIN';

	/** WP-Cron hook that removes expired cache files. */
	const CRON_HOOK = 'bepluspb_page_cache_gc';

	/** Transient holding the last sync problem for the admin UI. */
	const ERROR_TRANSIENT = 'bepluspb_page_cache_error';

	/** Transient caching whether the site shows widgets/blocks that list content everywhere. */
	const SITEWIDE_TRANSIENT = 'bepluspb_page_cache_sitewide';

	/** Classic widgets whose output changes whenever any post or comment does. */
	private const DYNAMIC_WIDGETS = array( 'recent-posts', 'recent-comments', 'archives', 'calendar', 'tag_cloud', 'categories' );

	/** Block markup (widget areas, template parts) that lists content on every page it appears on. */
	private const DYNAMIC_BLOCKS_REGEX = '/<!--\s+wp:(latest-posts|latest-comments|archives|calendar|tag-cloud|categories|query)(?=[\s\/}])/';

	/**
	 * Request context from the engine for the page being buffered.
	 *
	 * @var array|null
	 */
	private static $ctx = null;

	/**
	 * Option names whose change alters rendered pages on every URL.
	 *
	 * @var string[]
	 */
	private const SITEWIDE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'permalink_structure',
		'category_base',
		'tag_base',
		'posts_per_page',
		'date_format',
		'time_format',
		'timezone_string',
		'gmt_offset',
		'WPLANG',
		'sidebars_widgets',
		'stylesheet',
		'template',
		'home',
		'siteurl',
		'blog_public',
		'default_comment_status',
		'require_name_email',
		'comment_registration',
		'thread_comments',
		'comments_per_page',
		'page_comments',
	);

	// =========================================================================
	// Paths
	// =========================================================================

	/**
	 * Path of the engine loaded by the stub.
	 *
	 * @return string
	 */
	public static function engine_file() {
		return BEPLUSPB_PLUGIN_DIR . 'lib/class-bepluspb-page-cache-engine.php';
	}

	/**
	 * Path of the advanced-cache.php drop-in WordPress loads when WP_CACHE is on.
	 *
	 * @return string
	 */
	public static function dropin_file() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	// =========================================================================
	// Settings
	// =========================================================================

	/**
	 * Whether the page cache is switched on (master toggle AND its own toggle).
	 *
	 * @param array|null $opts Options (defaults to the saved ones).
	 * @return bool
	 */
	public static function is_enabled( $opts = null ) {
		$opts = is_array( $opts ) ? $opts : bepluspb_get_options();
		return ! empty( $opts['cache_enabled'] ) && ! empty( $opts['page_cache_enabled'] );
	}

	/**
	 * Clamp the TTL setting to a sane number of hours.
	 *
	 * @param mixed $hours Raw value.
	 * @return int
	 */
	public static function sanitize_ttl_hours( $hours ) {
		$hours = absint( $hours );
		return $hours < 1 ? 10 : min( 72, $hours );
	}

	/**
	 * Normalise the "extra bypass cookies" textarea to safe cookie-name prefixes.
	 *
	 * @param mixed $raw Raw textarea value.
	 * @return string Newline-separated list.
	 */
	public static function sanitize_cookie_list( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', is_string( $raw ) ? $raw : '' ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name && preg_match( '/^[A-Za-z0-9_.\-]{1,100}$/', $name ) ) {
				$out[] = $name;
			}
		}
		return implode( "\n", array_slice( array_values( array_unique( $out ) ), 0, 50 ) );
	}

	/**
	 * Restrict a select-style option to its allowed values.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Fallback.
	 * @return string
	 */
	public static function sanitize_choice( $value, array $allowed, $default ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Cookies that decide language or currency, auto-detected from active plugins.
	 * Each distinct value gets its own cached copy instead of bypassing the cache.
	 *
	 * @return string[]
	 */
	public static function detected_vary_cookies() {
		$cookies = array();
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) { // WPML.
			$cookies[] = 'wp-wpml_current_language';
		}
		if ( defined( 'POLYLANG_VERSION' ) ) {
			$cookies[] = 'pll_language';
		}
		if ( defined( 'WCML_VERSION' ) ) { // WooCommerce Multilingual.
			$cookies[] = 'wcml_client_currency';
			$cookies[] = 'wcml_client_currency_language';
		}
		return $cookies;
	}

	/**
	 * Whether the site renders content lists (recent posts/comments, archives,
	 * calendar, tag cloud, categories, query loops in template parts) on many pages.
	 * If so, editing one post makes lots of OTHER cached pages stale.
	 *
	 * @return bool
	 */
	public static function has_sitewide_dynamic_content() {
		$cached = get_transient( self::SITEWIDE_TRANSIENT );
		if ( false !== $cached ) {
			return '1' === (string) $cached;
		}
		$found = self::detect_sitewide_dynamic_content();
		set_transient( self::SITEWIDE_TRANSIENT, $found ? '1' : '0', 12 * HOUR_IN_SECONDS );
		return $found;
	}

	/**
	 * Scan active widgets, block widgets and block-theme template parts.
	 *
	 * @return bool
	 */
	private static function detect_sitewide_dynamic_content() {
		if ( function_exists( 'is_active_widget' ) ) {
			foreach ( self::DYNAMIC_WIDGETS as $base ) {
				if ( is_active_widget( false, false, $base, true ) ) {
					return true;
				}
			}
		}
		$block_widgets = get_option( 'widget_block' );
		foreach ( is_array( $block_widgets ) ? $block_widgets : array() as $widget ) {
			if ( is_array( $widget ) && isset( $widget['content'] ) && is_string( $widget['content'] ) && preg_match( self::DYNAMIC_BLOCKS_REGEX, $widget['content'] ) ) {
				return true;
			}
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
				foreach ( glob( trailingslashit( $dir ) . 'parts/*.html' ) ?: array() as $file ) {
					$markup = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( is_string( $markup ) && preg_match( self::DYNAMIC_BLOCKS_REGEX, $markup ) ) {
						return true;
					}
				}
			}
			$parts = get_posts(
				array(
					'post_type'      => 'wp_template_part',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'no_found_rows'  => true,
				)
			);
			foreach ( is_array( $parts ) ? $parts : array() as $part ) {
				if ( is_string( $part->post_content ) && preg_match( self::DYNAMIC_BLOCKS_REGEX, $part->post_content ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Forget the widget/template scan so the next purge decision re-reads them.
	 */
	public static function flush_sitewide_detection() {
		delete_transient( self::SITEWIDE_TRANSIENT );
	}

	/**
	 * Whether editing a published post must purge every page, not just related ones.
	 *
	 * @param array|null $opts Options (defaults to the saved ones).
	 * @return bool
	 */
	public static function purges_everything_on_post_change( $opts = null ) {
		$opts  = is_array( $opts ) ? $opts : bepluspb_get_options();
		$scope = self::sanitize_choice( $opts['page_cache_purge_scope'] ?? 'auto', array( 'auto', 'related', 'all' ), 'auto' );
		if ( 'all' === $scope ) {
			return true;
		}
		return 'auto' === $scope && self::has_sitewide_dynamic_content();
	}

	/**
	 * Hosts the cache may serve (home and site URL, with non-default ports).
	 *
	 * @return string[]
	 */
	private static function allowed_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$port = wp_parse_url( $url, PHP_URL_PORT );
			if ( $host ) {
				$hosts[] = strtolower( $host ) . ( $port ? ':' . $port : '' );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Build the config consumed by the engine.
	 *
	 * @param array $opts Options merged with defaults.
	 * @return array
	 */
	public static function build_config( $opts ) {
		return array(
			'version'         => 1,
			'enabled'         => self::is_enabled( $opts ),
			'ttl'             => self::sanitize_ttl_hours( $opts['page_cache_ttl'] ?? 10 ) * HOUR_IN_SECONDS,
			'hosts'           => self::allowed_hosts(),
			'exclude_paths'   => bepluspb_parse_exclude_list( $opts['cache_exclude_pages'] ?? '' ),
			'exclude_cookies' => bepluspb_parse_exclude_list( $opts['page_cache_exclude_cookies'] ?? '' ),
			'accept_variants' => ! empty( $opts['cdn_webp_avif'] ),
			'mobile'          => self::sanitize_choice( $opts['page_cache_mobile'] ?? 'separate', array( 'same', 'separate', 'bypass' ), 'separate' ),
			'vary_cookies'    => array_slice( array_values( array_unique( array_merge( self::detected_vary_cookies(), bepluspb_parse_exclude_list( $opts['page_cache_vary_cookies'] ?? '' ) ) ) ), 0, 8 ),
		);
	}

	/**
	 * Atomically write and verify the guarded config file.
	 *
	 * The config holds no secret, but it keeps the same executable guard as
	 * the Object Cache config so it can never be read over HTTP.
	 *
	 * @param array $cfg Config.
	 * @return bool
	 */
	public static function write_config( $cfg ) {
		$json = wp_json_encode( $cfg, JSON_PRETTY_PRINT );
		if ( ! $json ) {
			return false;
		}
		$path = BEPLUSPB_Page_Cache_Engine::config_file();
		$tmp  = tempnam( WP_CONTENT_DIR, '.bepluspb_pc-' );
		if ( false === $tmp ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$ok = false !== file_put_contents( $tmp, BEPLUSPB_Page_Cache_Engine::CONFIG_GUARD . $json, LOCK_EX );
		$ok = $ok && chmod( $tmp, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$ok = $ok && rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		BEPLUSPB_Page_Cache_Engine::reset();
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $raw
			&& 0 === strpos( $raw, BEPLUSPB_Page_Cache_Engine::CONFIG_GUARD )
			&& json_decode( substr( $raw, strlen( BEPLUSPB_Page_Cache_Engine::CONFIG_GUARD ) ), true ) === $cfg;
	}

	/**
	 * Remove the config file.
	 */
	public static function delete_config() {
		$path = BEPLUSPB_Page_Cache_Engine::config_file();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		BEPLUSPB_Page_Cache_Engine::reset();
	}

	// =========================================================================
	// advanced-cache.php drop-in
	// =========================================================================

	/**
	 * Contents of the advanced-cache.php stub. All logic lives in the engine;
	 * the stub only loads it, and fails open if anything is missing or throws.
	 *
	 * @return string
	 */
	public static function dropin_contents() {
		$engine = var_export( self::engine_file(), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		return "<?php\n"
			. "/**\n"
			. ' * ' . self::DROPIN_MARKER . " v1\n"
			. " * Managed by Beplus Performance Booster. Safe to delete: pages then render normally.\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
			. "if ( ! defined( 'BEPLUSPB_PC_DROPIN' ) ) {\n\tdefine( 'BEPLUSPB_PC_DROPIN', true );\n}\n"
			. '$bepluspb_pc_engine = ' . $engine . ";\n"
			. "if ( is_file( \$bepluspb_pc_engine ) && is_readable( \$bepluspb_pc_engine ) ) {\n"
			. "\ttry {\n"
			. "\t\trequire_once \$bepluspb_pc_engine;\n"
			. "\t\tif ( class_exists( 'BEPLUSPB_Page_Cache_Engine', false ) ) {\n"
			. "\t\t\tBEPLUSPB_Page_Cache_Engine::serve();\n"
			. "\t\t}\n"
			. "\t} catch ( \\Throwable \$e ) {\n"
			. "\t\t// Fail open: render the page normally.\n"
			. "\t}\n"
			. "}\n"
			. "unset( \$bepluspb_pc_engine );\n";
	}

	/**
	 * Whether a file was written by this plugin.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_our_dropin( $path ) {
		if ( ! is_file( $path ) ) {
			return false;
		}
		$head = file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $head && false !== strpos( $head, self::DROPIN_MARKER );
	}

	/**
	 * Install (or refresh) the advanced-cache.php stub. A foreign drop-in is
	 * never overwritten.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function install_dropin() {
		$dst = self::dropin_file();
		if ( file_exists( $dst ) && ! self::is_our_dropin( $dst ) ) {
			return array(
				'success' => false,
				'message' => __( 'Another plugin already provides wp-content/advanced-cache.php. Beplus will serve cached pages from the plugin instead (slightly slower).', 'beplus-performance-booster' ),
			);
		}
		$bytes = self::dropin_contents();
		if ( file_exists( $dst ) && file_get_contents( $dst ) === $bytes ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return array(
				'success' => true,
				'message' => '',
			);
		}
		$tmp = tempnam( WP_CONTENT_DIR, '.bepluspb_ac-' );
		if ( false === $tmp ) {
			return array(
				'success' => false,
				'message' => __( 'wp-content is not writable, so advanced-cache.php could not be installed. Pages are served from the plugin instead.', 'beplus-performance-booster' ),
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$ok = false !== file_put_contents( $tmp, $bytes, LOCK_EX ) && chmod( $tmp, 0644 ) && rename( $tmp, $dst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return array(
				'success' => false,
				'message' => __( 'advanced-cache.php could not be written. Pages are served from the plugin instead.', 'beplus-performance-booster' ),
			);
		}
		return array(
			'success' => true,
			'message' => '',
		);
	}

	/**
	 * Remove advanced-cache.php, but only if this plugin wrote it.
	 *
	 * @return bool True when no Beplus drop-in remains.
	 */
	public static function uninstall_dropin() {
		$dst = self::dropin_file();
		if ( ! file_exists( $dst ) ) {
			return true;
		}
		if ( ! self::is_our_dropin( $dst ) ) {
			return true;
		}
		wp_delete_file( $dst );
		return ! file_exists( $dst );
	}

	// =========================================================================
	// Lifecycle: sync / deactivate
	// =========================================================================

	/**
	 * Bring config, drop-in, schedule and stored pages in line with the settings.
	 *
	 * Runs on every settings change. Any change can alter the rendered HTML
	 * (minify, CDN, lazy load…), so stored pages are always purged.
	 *
	 * @param array $opts Options merged with defaults.
	 */
	public static function sync( $opts ) {
		delete_transient( self::ERROR_TRANSIENT );

		if ( ! self::is_enabled( $opts ) ) {
			self::shutdown();
			return;
		}

		$problems = array();
		if ( ! BEPLUSPB_Page_Cache_Engine::ensure_root( BEPLUSPB_Page_Cache_Engine::cache_root() ) ) {
			$problems[] = __( 'wp-content/cache is not writable, so pages cannot be stored.', 'beplus-performance-booster' );
		}
		if ( ! self::write_config( self::build_config( $opts ) ) ) {
			$problems[] = __( 'The page cache configuration could not be written to wp-content. Check its permissions.', 'beplus-performance-booster' );
		} else {
			$dropin = self::install_dropin();
			if ( ! $dropin['success'] && '' !== $dropin['message'] ) {
				$problems[] = $dropin['message'];
			}
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		BEPLUSPB_Page_Cache_Engine::purge_all();
		if ( $problems ) {
			set_transient( self::ERROR_TRANSIENT, $problems, 10 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Turn everything off: remove the drop-in and config, stop the schedule, purge.
	 */
	public static function shutdown() {
		self::uninstall_dropin();
		self::delete_config();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		BEPLUSPB_Page_Cache_Engine::purge_all();
	}

	/**
	 * Settings-change hook: re-sync with the new values.
	 *
	 * @param mixed $old Previous value (unused).
	 * @param mixed $new New value.
	 */
	public static function on_settings_updated( $old, $new ) {
		unset( $old );
		self::sync( wp_parse_args( is_array( $new ) ? $new : array(), bepluspb_default_options() ) );
	}

	/**
	 * First-save hook (option did not exist yet).
	 *
	 * @param string $option Option name (unused).
	 * @param mixed  $value  New value.
	 */
	public static function on_settings_added( $option, $value ) {
		unset( $option );
		self::on_settings_updated( null, $value );
	}

	/**
	 * Repair a missing/stale config or stub (plugin moved, wp-content restored
	 * from a backup…). Admin-only and at most twice a day.
	 */
	public static function maybe_repair() {
		if ( ! is_admin() || ! self::is_enabled() || get_transient( 'bepluspb_page_cache_repair' ) ) {
			return;
		}
		$status = self::get_status( false );
		if ( 'ours' === $status['dropin'] && $status['config_ok'] ) {
			return;
		}
		if ( 'foreign' === $status['dropin'] && $status['config_ok'] ) {
			return;
		}
		set_transient( 'bepluspb_page_cache_repair', 1, 12 * HOUR_IN_SECONDS );
		self::sync( bepluspb_get_options() );
	}

	// =========================================================================
	// Status (admin UI)
	// =========================================================================

	/**
	 * Current state of the page cache for the settings screen.
	 *
	 * @param bool $with_stats Include the (directory-walking) page count and size.
	 * @return array
	 */
	public static function get_status( $with_stats = true ) {
		$dst = self::dropin_file();
		if ( ! file_exists( $dst ) ) {
			$dropin = 'missing';
		} elseif ( ! self::is_our_dropin( $dst ) ) {
			$dropin = 'foreign';
		} else {
			$dropin = ( file_get_contents( $dst ) === self::dropin_contents() ) ? 'ours' : 'stale'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		BEPLUSPB_Page_Cache_Engine::reset();
		$enabled = self::is_enabled();
		$running = defined( 'BEPLUSPB_PC_DROPIN' );
		$errors  = get_transient( self::ERROR_TRANSIENT );

		return array(
			'enabled'     => $enabled,
			'wp_cache'    => defined( 'WP_CACHE' ) && WP_CACHE,
			'dropin'      => $dropin,
			'running'     => $running,
			'config_ok'   => null !== BEPLUSPB_Page_Cache_Engine::load_config(),
			'mode'        => ! $enabled ? 'off' : ( $running ? 'dropin' : 'plugin' ),
			'root_exists' => is_dir( BEPLUSPB_Page_Cache_Engine::cache_root() ),
			'stats'       => $with_stats ? BEPLUSPB_Page_Cache_Engine::count_files() : array(
				'files'     => 0,
				'bytes'     => 0,
				'truncated' => false,
			),
			'errors'      => is_array( $errors ) ? $errors : array(),
		);
	}

	// =========================================================================
	// Serving (fallback when advanced-cache.php did not run)
	// =========================================================================

	/**
	 * Serve a cached page from the plugin file when advanced-cache.php is not
	 * active (WP_CACHE off, or a different plugin owns the drop-in). Skips the
	 * theme and the main query, but runs after other plugins have loaded.
	 */
	public static function maybe_serve_fallback() {
		if ( defined( 'BEPLUSPB_PC_DROPIN' ) || ! is_file( BEPLUSPB_Page_Cache_Engine::config_file() ) ) {
			return;
		}
		try {
			BEPLUSPB_Page_Cache_Engine::serve();
		} catch ( Throwable $e ) {
			unset( $e ); // Fail open: render the page normally.
		}
	}

	// =========================================================================
	// Storing
	// =========================================================================

	/**
	 * Register the capture buffer. Called on the front-end 'wp' action.
	 *
	 * @param array $opts Options.
	 */
	public static function init( $opts ) {
		if ( ! self::is_enabled( $opts ) ) {
			return;
		}
		// Priority -100: open BEFORE the HTML/CDN/CSS buffers (priority 0) so this
		// outermost buffer receives the final, fully optimised markup.
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), -100 );
		if ( 'separate' === self::sanitize_choice( $opts['page_cache_mobile'] ?? 'separate', array( 'same', 'separate', 'bypass' ), 'separate' ) ) {
			// The HTML now depends on the device: downstream caches (proxies, CDNs) must key on it too.
			add_action( 'template_redirect', array( __CLASS__, 'send_vary_user_agent' ), -101 );
		}
	}

	/**
	 * Tell shared caches that the HTML varies by User-Agent.
	 */
	public static function send_vary_user_agent() {
		if ( ! headers_sent() ) {
			header( 'Vary: User-Agent', false );
		}
	}

	/**
	 * Headers set during this request that a cached copy must replay on a HIT.
	 *
	 * @return string[]
	 */
	private static function replayable_headers() {
		return BEPLUSPB_Page_Cache_Engine::sanitize_headers( headers_list() );
	}

	/**
	 * Whether the page being rendered may be stored. Returns the engine context.
	 *
	 * @return array|null
	 */
	private static function storable_context() {
		$ctx = BEPLUSPB_Page_Cache_Engine::request_context();
		if ( ! $ctx ) {
			return null;
		}
		// A request with tracking parameters may be SERVED from the clean copy, but
		// is never STORED: themes echo the current URL (add_query_arg( null, null ),
		// $_SERVER['REQUEST_URI']) and a crafted ?utm_x= value would be cached for everyone.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== strpos( $uri, '?' ) ) {
			return null;
		}
		if (
			is_user_logged_in() || is_admin() || is_404() || is_search() || is_feed() || is_preview()
			|| is_customize_preview() || is_embed() || is_trackback() || is_robots()
			|| ( function_exists( 'wp_is_xml_request' ) && wp_is_xml_request() )
			|| get_query_var( 'sitemap' ) || get_query_var( 'sitemap-stylesheet' )
			|| ( is_singular() && post_password_required() )
			|| BEPLUSPB_Utils::is_buffer_excluded_request()
			|| BEPLUSPB_Minify::is_cache_disabled_for_page()
		) {
			return null;
		}
		$code = http_response_code();
		if ( $code && 200 !== $code ) {
			return null;
		}
		/**
		 * Filters whether the current page may enter the page cache.
		 *
		 * @param bool  $cacheable Whether the page is cacheable.
		 * @param array $ctx       Engine context: dir, file, path.
		 */
		return apply_filters( 'bepluspb_page_cache_cacheable', true, $ctx ) ? $ctx : null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
	}

	/**
	 * Open the capture buffer for a cacheable request.
	 */
	public static function start_buffer() {
		if ( null !== self::$ctx ) {
			return;
		}
		$ctx = self::storable_context();
		if ( ! $ctx ) {
			return;
		}
		self::$ctx = $ctx;
		ob_start( array( __CLASS__, 'handle_buffer' ) );
	}

	/**
	 * Output-buffer callback: store the finished page, pass it through untouched.
	 *
	 * @param string $html  Buffered output.
	 * @param int    $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string The unmodified output.
	 */
	public static function handle_buffer( $html, $phase ) {
		// Only a complete, un-flushed, un-discarded document is stored.
		$complete = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && ! ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( $complete && self::$ctx && self::response_is_cacheable( $html ) ) {
			try {
				BEPLUSPB_Page_Cache_Engine::store(
					self::$ctx,
					$html . "\n<!-- Page cached by Beplus Performance Booster on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n",
					self::replayable_headers()
				);
			} catch ( Throwable $e ) {
				unset( $e ); // Never break the response because caching failed.
			}
		}
		return $html;
	}

	/**
	 * Final checks on the response itself, once rendering is done.
	 *
	 * @param string $html Final HTML.
	 * @return bool
	 */
	private static function response_is_cacheable( $html ) {
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || is_user_logged_in() ) {
			return false;
		}
		$code = http_response_code();
		if ( $code && 200 !== $code ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			$header = strtolower( $header );
			if (
				0 === strpos( $header, 'set-cookie:' ) || 0 === strpos( $header, 'location:' )
				|| ( 0 === strpos( $header, 'cache-control:' ) && preg_match( '/no-cache|no-store|private/', $header ) )
				|| ( 0 === strpos( $header, 'content-type:' ) && false === strpos( $header, 'text/html' ) )
			) {
				return false;
			}
		}
		if ( strlen( $html ) < 256 || false === stripos( $html, '<html' ) || false === stripos( $html, '</html>' ) ) {
			return false;
		}
		/**
		 * Filters whether the finished HTML may be written to the page cache.
		 *
		 * @param bool   $store Whether to store the page.
		 * @param string $html  Final HTML.
		 */
		return (bool) apply_filters( 'bepluspb_page_cache_store', true, $html ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
	}

	// =========================================================================
	// Purging
	// =========================================================================

	/**
	 * Remove every cached page.
	 *
	 * @return array{status:string,matched:int,deleted:int,failed:int}
	 */
	public static function purge_all() {
		$result = BEPLUSPB_Page_Cache_Engine::purge_all();
		/** Fires after every cached page was removed. */
		do_action( 'bepluspb_page_cache_purged_all', $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		return $result;
	}

	/**
	 * Remove the cached copy of one URL (all variants).
	 *
	 * @param string $url        Permalink or archive URL.
	 * @param bool   $pagination Also remove its /page/N/ and comment pages.
	 * @return int Files removed.
	 */
	public static function purge_url( $url, $pagination = false ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! $parts || ! empty( $parts['query'] ) ) {
			return 0; // Query-string URLs are never cached.
		}
		return BEPLUSPB_Page_Cache_Engine::purge_path( $parts['path'] ?? '/', null, $pagination );
	}

	/**
	 * Purge a post and every listing that shows it.
	 *
	 * By default an edit to an already-published post purges the post, the
	 * home page, the posts page, its archives (post type, terms, author, date).
	 * Return true from the filter to wipe everything instead (needed when
	 * widgets or shortcodes list recent posts everywhere).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function purge_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		/**
		 * Filters whether editing a published post purges the WHOLE page cache.
		 *
		 * @param bool    $purge_all Default from the "Purge scope" setting (auto = true only when
		 *                           widgets/blocks list content site-wide).
		 * @param WP_Post $post      The edited post.
		 */
		if ( apply_filters( 'bepluspb_page_cache_purge_all_on_post_change', self::purges_everything_on_post_change(), $post ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
			self::purge_all();
			return;
		}

		$urls           = array(
			get_permalink( $post ) => false,
			home_url( '/' )        => true,
		);
		$page_for_posts = (int) get_option( 'page_for_posts' );
		if ( $page_for_posts ) {
			$urls[ get_permalink( $page_for_posts ) ] = true;
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[ $archive ] = true;
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[ $link ] = true;
				}
			}
		}
		$urls[ get_author_posts_url( (int) $post->post_author ) ] = true;
		$timestamp = strtotime( $post->post_date );
		if ( $timestamp ) {
			$year  = (int) gmdate( 'Y', $timestamp );
			$month = (int) gmdate( 'm', $timestamp );
			$day   = (int) gmdate( 'd', $timestamp );

			$urls[ get_year_link( $year ) ]              = true;
			$urls[ get_month_link( $year, $month ) ]     = true;
			$urls[ get_day_link( $year, $month, $day ) ] = true;
		}
		/**
		 * Filters the URLs purged when a published post changes.
		 *
		 * @param array   $urls Map of URL => also purge pagination.
		 * @param WP_Post $post The changed post.
		 */
		$urls = apply_filters( 'bepluspb_page_cache_post_urls', $urls, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		foreach ( $urls as $url => $pagination ) {
			if ( is_string( $url ) && '' !== $url ) {
				self::purge_url( $url, (bool) $pagination );
			}
		}
	}

	// =========================================================================
	// Invalidation hooks (Hard Rule #7)
	// =========================================================================

	/**
	 * Register every content-changing event that must drop cached pages.
	 *
	 * MUST run from plugins_loaded: the front-end boot gate never runs in
	 * wp-admin, where almost every content edit happens.
	 *
	 * @param array $opts Options.
	 */
	public static function register_invalidation_hooks( $opts ) {
		if ( ! self::is_enabled( $opts ) ) {
			return;
		}

		// Posts.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		// Block-editor/REST saves set terms and meta AFTER save_post; this fires once all are stored.
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_after_insert_post' ), 20, 4 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 20, 6 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 20, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ), 20, 2 );

		// Comments change the post page (and comment counts on listings).
		add_action( 'comment_post', array( __CLASS__, 'on_comment_post' ), 20, 2 );
		foreach ( array( 'edit_comment', 'delete_comment', 'trashed_comment', 'untrashed_comment', 'spammed_comment', 'unspammed_comment', 'wp_set_comment_status' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_comment_changed' ), 20, 1 );
		}

		// Site-wide look and structure.
		foreach ( array( 'switch_theme', 'after_switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_update_nav_menu_item', 'edited_term', 'created_term', 'delete_term', 'bepluspb_assets_cache_cleared', 'bepluspb_page_cache_purge_all' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge_all' ), 20, 0 );
		}
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_all' ), 20, 0 );
		// A (de)activated plugin can add language/currency cookies to the config, so re-sync rather than only purge.
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_toggled' ), 20, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_toggled' ), 20, 1 );
		// Widgets/templates decide whether an edit must purge everything; re-scan when they change.
		foreach ( array( 'switch_theme', 'after_switch_theme', 'customize_save_after', 'save_post_wp_template_part', 'save_post_wp_template' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_sitewide_detection' ), 5, 0 );
		}
		add_action( 'updated_option', array( __CLASS__, 'on_option_changed' ), 20, 1 );
		add_action( 'added_option', array( __CLASS__, 'on_option_changed' ), 20, 1 );

		// Stock changes alter a product page without a post save.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_stock' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product_stock' ), 20, 1 );

		// Explicit integration point for other code.
		add_action( 'bepluspb_page_cache_purge_url', array( __CLASS__, 'purge_url' ), 10, 2 );
	}

	/**
	 * Register hooks that must exist whenever the plugin is loaded, enabled or not.
	 */
	public static function register_core_hooks() {
		add_action( 'update_option_' . BEPLUSPB_OPTIONS_KEY, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_' . BEPLUSPB_OPTIONS_KEY, array( __CLASS__, 'on_settings_added' ), 10, 2 );
		add_action( self::CRON_HOOK, array( 'BEPLUSPB_Page_Cache_Engine', 'gc' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_repair' ) );
	}

	/**
	 * Post saved: purge it and the listings that show it.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		self::purge_post( $post_id );
	}

	/**
	 * Post fully saved (terms, meta and REST updates included): purge it, and its
	 * previous URL when the slug or parent changed.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post object.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Post as it was before the update.
	 */
	public static function on_after_insert_post( $post_id, $post, $update = false, $post_before = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		self::purge_post( $post_id );
		if ( $update && $post_before instanceof WP_Post && 'publish' === $post_before->post_status ) {
			$old = get_permalink( $post_before );
			if ( $old && get_permalink( $post ) !== $old ) {
				self::purge_url( $old, true );
			}
		}
	}

	/**
	 * Categories/tags of a published post changed: the term archives it left are
	 * stale too, and only the old term IDs know about them, so purge everything.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Submitted terms.
	 * @param array  $tt_ids     New term-taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term-taxonomy IDs.
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $taxonomy, $append );
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old ) {
			return;
		}
		$post = get_post( $object_id );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			self::purge_all();
		}
	}

	/**
	 * Publish/unpublish changes which listings, menus and widgets exist: purge all.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ( 'publish' === $new_status ) === ( 'publish' === $old_status ) || ! $post instanceof WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}
		self::purge_all();
	}

	/**
	 * A published post was deleted outright.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_deleted_post( $post_id, $post = null ) {
		unset( $post_id );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
			self::purge_all();
		}
	}

	/**
	 * New comment: only an approved one changes the page.
	 *
	 * @param int        $comment_id Comment ID.
	 * @param int|string $approved   1, 0 or 'spam'.
	 */
	public static function on_comment_post( $comment_id, $approved ) {
		if ( 1 === (int) $approved && '1' === (string) $approved ) {
			self::on_comment_changed( $comment_id );
		}
	}

	/**
	 * Comment edited, moderated or removed.
	 *
	 * @param int $comment_id Comment ID.
	 */
	public static function on_comment_changed( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			self::purge_post( (int) $comment->comment_post_ID );
		}
	}

	/**
	 * A plugin was activated or deactivated: refresh config and drop stored pages.
	 *
	 * Beplus itself is skipped: its own deactivation hook has just removed the
	 * drop-in and config, and re-syncing here would put them straight back.
	 *
	 * @param string $plugin Plugin basename, e.g. "dir/file.php".
	 */
	public static function on_plugin_toggled( $plugin = '' ) {
		if ( 'beplus-performance-booster.php' === basename( (string) $plugin ) ) {
			return;
		}
		self::flush_sitewide_detection();
		self::sync( bepluspb_get_options() );
	}

	/**
	 * Options that restyle or restructure the whole site.
	 *
	 * @param string $option Option name.
	 */
	public static function on_option_changed( $option ) {
		if ( 0 === strpos( $option, 'widget_' ) || 'sidebars_widgets' === $option ) {
			self::flush_sitewide_detection();
		}
		if ( in_array( $option, self::SITEWIDE_OPTIONS, true ) || 0 === strpos( $option, 'widget_' ) || 0 === strpos( $option, 'theme_mods_' ) ) {
			self::purge_all();
		}
	}

	/**
	 * WooCommerce stock changed on a product or variation.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function on_product_stock( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			self::purge_post( (int) $id );
		}
	}
}
