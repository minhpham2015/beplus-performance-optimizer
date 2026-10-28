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
	private const DYNAMIC_BLOCKS_REGEX = '/<!--\s+wp:(latest-posts|latest-comments|archives|calendar|tag-cloud|categories)(?=[\s\/}])/';

	/**
	 * Non-public post types whose saves must NOT purge the cache: logs, queues, orders,
	 * form submissions and other records that are not shown on pages. Every other
	 * non-public type (templates, template parts, global styles, navigation, reusable
	 * blocks, page-builder libraries, forms, tables…) can appear on many pages, so a
	 * save purges everything. Extend with the bepluspb_page_cache_ignored_post_types filter.
	 */
	private const IGNORED_POST_TYPES = array(
		'revision',
		'nav_menu_item',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'scheduled-action',
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_coupon',
		'product_variation',
		'flamingo_inbound',
		'flamingo_outbound',
		'flamingo_contact',
		'feedback',
		'wpforms_log',
	);

	/** How mobile visitors are cached. */
	const MOBILE_MODES = array( 'auto', 'separate', 'same', 'bypass' );

	/**
	 * Whether the current request asked WordPress if the visitor is on a mobile device.
	 *
	 * @var bool
	 */
	private static $device_aware = false;

	/**
	 * Whether the current request ran a secondary query for posts or comments
	 * (recent/related posts, recent comments…): the page then shows content other than its own.
	 *
	 * @var bool
	 */
	private static $lists_others = false;

	/**
	 * What wp_is_mobile() answered while this page was being built (null: never asked).
	 *
	 * @var bool|null
	 */
	private static $observed_mobile = null;

	/**
	 * True while the live compatibility check runs, so the wp-admin gate of the observers is skipped.
	 *
	 * @var bool
	 */
	private static $self_test = false;

	/**
	 * Whether pages that list other content were already purged in this request.
	 *
	 * @var bool
	 */
	private static $flagged_purged = false;

	/**
	 * Mobile mode in force for this request.
	 *
	 * @var string
	 */
	private static $mobile_mode = 'auto';

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
		'comment_moderation',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'comment_order',
		'default_comments_page',
		'show_comments_cookies_opt_in',
		'site_icon',
		'site_logo',
		'show_avatars',
		'avatar_default',
		'avatar_rating',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'image_default_align',
		'image_default_size',
		'start_of_week',
		'sticky_posts',
		'users_can_register',
		'default_category',
		'blog_charset',
		'rss_use_excerpt',
		'posts_per_rss',
		'wpmm_settings', // WP Maintenance Mode: switching it on/off must show/hide stored pages at once.
	);

	/** WooCommerce settings that change how every product, shop and price is displayed. */
	private const WOOCOMMERCE_OPTIONS = array(
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_num_decimals',
		'woocommerce_prices_include_tax',
		'woocommerce_calc_taxes',
		'woocommerce_tax_display_shop',
		'woocommerce_tax_display_cart',
		'woocommerce_tax_total_display',
		'woocommerce_enable_reviews',
		'woocommerce_enable_review_rating',
		'woocommerce_review_rating_required',
		'woocommerce_manage_stock',
		'woocommerce_hide_out_of_stock_items',
		'woocommerce_stock_format',
		'woocommerce_notify_no_stock_amount',
		'woocommerce_catalog_columns',
		'woocommerce_catalog_rows',
		'woocommerce_default_catalog_orderby',
		'woocommerce_shop_page_id',
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
		'woocommerce_enable_ajax_add_to_cart',
		'woocommerce_cart_redirect_after_add',
		'woocommerce_placeholder_image',
		'woocommerce_sale_flash',
	);

	/**
	 * Active plugins whose own logic is skipped when a stored page is served, by plugin folder.
	 * Shown on the settings screen so nobody is surprised.
	 */
	private const ACCESS_CONTROL_PLUGINS = array(
		'wordfence/'                           => 'Wordfence (firewall / blocking)',
		'better-wp-security/'                  => 'Solid Security / iThemes Security',
		'all-in-one-wp-security-and-firewall/' => 'All In One WP Security & Firewall',
		'sucuri-scanner/'                      => 'Sucuri Security',
		'ip-geo-block/'                        => 'IP Geo Block',
		'wp-maintenance-mode/'                 => 'WP Maintenance Mode',
		'under-construction-page/'             => 'Under Construction',
		'coming-soon/'                         => 'SeedProd Coming Soon / Maintenance',
		'age-gate/'                            => 'Age Gate',
		'redirection/'                         => 'Redirection (new redirects do not apply to a stored page until it is purged)',
		'wp-statistics/'                       => 'WP Statistics (server-side counting)',
		'post-views-counter/'                  => 'Post Views Counter (server-side counting)',
		'wp-postviews/'                        => 'WP-PostViews',
	);

	/** Transient holding the result of the live check of the page-observation hooks. */
	const OBSERVER_TRANSIENT = 'bepluspb_page_cache_observers';

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
	 * Normalise the "extra options that clear the cache" list.
	 *
	 * Entries are option names; a trailing "*" makes an entry a prefix ("my_theme_*").
	 *
	 * @param mixed $raw Raw textarea value.
	 * @return string Newline-separated list.
	 */
	public static function sanitize_option_names( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', is_string( $raw ) ? $raw : '' ) as $name ) {
			$name = trim( $name );
			if ( '' !== $name && '*' !== $name && preg_match( '/^[A-Za-z0-9_\-:.]{1,100}\*?$/D', $name ) ) {
				$out[] = $name;
			}
		}
		return implode( "\n", array_slice( array_values( array_unique( $out ) ), 0, 50 ) );
	}

	/**
	 * Whether an option name matches a list of exact names and "prefix*" entries.
	 *
	 * @param string   $option Option name.
	 * @param string[] $names  Names; entries ending in "*" are prefixes.
	 * @return bool
	 */
	private static function option_in_list( $option, array $names ) {
		foreach ( $names as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				continue;
			}
			if ( '*' === substr( $entry, -1 ) ? 0 === strpos( $option, substr( $entry, 0, -1 ) ) : $option === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether changing this option must clear every stored page.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	public static function option_clears_cache( $option ) {
		if ( ! is_string( $option ) || 0 === strpos( $option, '_transient_' ) || 0 === strpos( $option, '_site_transient_' ) ) {
			return false;
		}
		if ( in_array( $option, self::SITEWIDE_OPTIONS, true ) || in_array( $option, self::WOOCOMMERCE_OPTIONS, true ) || 0 === strpos( $option, 'widget_' ) || 0 === strpos( $option, 'theme_mods_' ) ) {
			return true;
		}
		$user_list = bepluspb_parse_exclude_list( bepluspb_get_options()['page_cache_purge_options'] ?? '' );
		/**
		 * Filters extra option names (or "prefix*" entries) whose change clears the page cache,
		 * e.g. theme-framework or builder settings printed on every page.
		 *
		 * @param string[] $names Option names.
		 */
		$extra = apply_filters( 'bepluspb_page_cache_sitewide_options', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		return self::option_in_list( $option, array_merge( $user_list, is_array( $extra ) ? $extra : array() ) );
	}

	/**
	 * Active plugins whose logic does not run for a stored page.
	 *
	 * @return string[] Human-readable names.
	 */
	public static function bypassed_plugins() {
		$found  = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::ACCESS_CONTROL_PLUGINS as $folder => $label ) {
			foreach ( $active as $plugin ) {
				if ( is_string( $plugin ) && 0 === strpos( $plugin, $folder ) ) {
					$found[] = $label;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Restrict a select-style option to its allowed values.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $allowed  Allowed values.
	 * @param string   $fallback Fallback.
	 * @return string
	 */
	public static function sanitize_choice( $value, array $allowed, $fallback ) {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
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
			if ( is_array( $widget ) && isset( $widget['content'] ) && is_string( $widget['content'] ) && self::markup_lists_content( $widget['content'] ) ) {
				return true;
			}
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
				$parts_dir = trailingslashit( $dir ) . 'parts';
				foreach ( is_dir( $parts_dir ) ? (array) scandir( $parts_dir ) : array() as $name ) { // scandir, not glob(): the theme path may contain glob metacharacters.
					if ( '.html' !== substr( $name, -5 ) || ! is_file( $parts_dir . '/' . $name ) ) {
						continue;
					}
					$markup = file_get_contents( $parts_dir . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					if ( is_string( $markup ) && self::markup_lists_content( $markup ) ) {
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
				if ( is_string( $part->post_content ) && self::markup_lists_content( $part->post_content ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether block markup lists posts/comments/terms on every page it appears on.
	 *
	 * A Query Loop counts only when it runs its OWN query: one that inherits the page's
	 * main query ("inherit": true) just shows the archive being viewed.
	 *
	 * @param string $markup Serialized blocks.
	 * @return bool
	 */
	public static function markup_lists_content( $markup ) {
		if ( preg_match( self::DYNAMIC_BLOCKS_REGEX, $markup ) ) {
			return true;
		}
		if ( preg_match_all( '/<!--\s+wp:query(?:\s+(\{.*?\}))?\s*\/?-->/s', $markup, $blocks ) ) {
			foreach ( $blocks[1] as $json ) {
				$attrs = '' === $json ? null : json_decode( $json, true );
				if ( ! is_array( $attrs ) || empty( $attrs['query']['inherit'] ) ) {
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
	 * Whether the hooks used to watch pages while they are built really fire on this site.
	 *
	 * "Automatic" device handling and "related pages that list other content" both rely on
	 * the wp_is_mobile filter and the pre_get_posts / pre_get_comments actions. They are
	 * checked here, on the live WordPress, instead of assumed: if any is silent (a very old
	 * WordPress, a plugin that short-circuits them) the cache falls back to the safe modes
	 * (separate mobile copies, purge everything on edit). Re-checked whenever settings are
	 * saved and after a WordPress update.
	 *
	 * @return bool
	 */
	public static function observers_ok() {
		$version = (string) get_bloginfo( 'version' );
		$known   = get_transient( self::OBSERVER_TRANSIENT );
		if ( is_array( $known ) && isset( $known['wp'], $known['ok'] ) && $known['wp'] === $version ) {
			return (bool) $known['ok'];
		}
		$ok = self::run_observer_self_test();
		set_transient(
			self::OBSERVER_TRANSIENT,
			array(
				'wp' => $version,
				'ok' => $ok,
			),
			7 * DAY_IN_SECONDS
		);
		return $ok;
	}

	/**
	 * Run one device check, one posts query and one comments query and see whether the observers saw them.
	 *
	 * Calls get_posts() with its default suppress_filters, exactly like most plugins do.
	 *
	 * @return bool
	 */
	private static function run_observer_self_test() {
		$saved           = array( self::$device_aware, self::$lists_others, self::$observed_mobile );
		self::$self_test = true;
		self::register_observers(); // Idempotent; the cache may not be enabled yet when this runs.

		self::$device_aware = false;
		wp_is_mobile();
		$device_seen = self::$device_aware;

		self::$lists_others = false;
		get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$posts_seen = self::$lists_others;

		self::$lists_others = false;
		get_comments(
			array(
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$comments_seen = self::$lists_others;

		self::$self_test = false;
		list( self::$device_aware, self::$lists_others, self::$observed_mobile ) = $saved;
		return $device_seen && $posts_seen && $comments_seen;
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
		// Without working observers nothing marks which pages list other content: be safe.
		return 'auto' === $scope && ( ! self::observers_ok() || self::has_sitewide_dynamic_content() );
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
	 * Whether the config the engine reads still lists the hosts WordPress is configured for.
	 *
	 * The list is computed when settings are saved; a domain change made through wp-config.php
	 * constants, WP-CLI search-replace or SQL fires no hook, and the cache would silently stop
	 * storing and serving pages while the settings screen still said "Active".
	 *
	 * @return bool
	 */
	public static function hosts_in_sync() {
		$cfg = BEPLUSPB_Page_Cache_Engine::load_config();
		if ( ! $cfg ) {
			return false;
		}
		$configured = $cfg['hosts'];
		$expected   = self::allowed_hosts();
		sort( $configured );
		sort( $expected );
		return $configured === $expected;
	}

	/**
	 * The mobile mode the engine will run: "automatic" needs working observers, otherwise it
	 * behaves as "always keep a separate mobile copy".
	 *
	 * @param array $opts Options merged with defaults.
	 * @return string
	 */
	private static function effective_mobile_mode( $opts ) {
		$mode = self::sanitize_choice( $opts['page_cache_mobile'] ?? 'auto', self::MOBILE_MODES, 'auto' );
		return ( 'auto' === $mode && ! self::observers_ok() ) ? 'separate' : $mode;
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
			'mobile'          => self::effective_mobile_mode( $opts ),
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
		delete_transient( self::OBSERVER_TRANSIENT ); // Re-check the observation hooks with the current plugins.

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
	 * @param mixed $new_value New value.
	 */
	public static function on_settings_updated( $old, $new_value ) {
		unset( $old );
		self::sync( wp_parse_args( is_array( $new_value ) ? $new_value : array(), bepluspb_default_options() ) );
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
	 * from a backup, site address changed…). Admin screens only; see init() for the front-end check.
	 */
	public static function maybe_repair() {
		if ( is_admin() ) {
			self::repair( false );
		}
	}

	/**
	 * Re-sync when the config or stub no longer matches the site.
	 *
	 * The attempt is throttled per set of site hosts: a changed domain is repaired on the very next
	 * request instead of waiting for the previous attempt to age out.
	 *
	 * @param bool $light Only compare the host list (cheap enough for every front-end request).
	 */
	private static function repair( $light ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		// Healthy is the overwhelmingly common case: decide it from files already in memory, and
		// only then touch the transient (a database read on sites without a persistent object cache).
		if ( $light ) {
			$healthy = self::hosts_in_sync();
		} else {
			$status  = self::get_status( false );
			$healthy = $status['config_ok'] && $status['hosts_ok'] && in_array( $status['dropin'], array( 'ours', 'foreign' ), true );
		}
		if ( $healthy ) {
			return;
		}
		$key = 'bepluspb_page_cache_repair_' . substr( md5( implode( ',', self::allowed_hosts() ) ), 0, 8 );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
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
			'hosts_ok'    => self::hosts_in_sync(),
			'observers'   => self::observers_ok(),
			'bypassed'    => self::bypassed_plugins(),
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
		self::repair( true );
		// Priority -100: open BEFORE the HTML/CDN/CSS buffers (priority 0) so this
		// outermost buffer receives the final, fully optimised markup.
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), -100 );
		// The engine's config is what serves pages, so it decides how they are stored too
		// (it already accounts for the live check of the observation hooks).
		$cfg               = BEPLUSPB_Page_Cache_Engine::load_config();
		self::$mobile_mode = $cfg ? $cfg['mobile'] : self::sanitize_choice( $opts['page_cache_mobile'] ?? 'auto', self::MOBILE_MODES, 'auto' );
		if ( 'separate' === self::$mobile_mode ) {
			// Every page may now depend on the device: downstream caches (proxies, CDNs) must key on it too.
			// Mode "auto" announces this only on pages that actually asked WordPress about the device.
			add_action( 'template_redirect', array( __CLASS__, 'send_vary_user_agent' ), -101 );
		}
	}

	/**
	 * Watch what a page asks WordPress while it is being built, so the cache can
	 * tell which pages depend on the device and which show other posts/comments.
	 * Read-only: nothing here changes a query or a return value.
	 */
	private static function register_observers() {
		add_filter( 'wp_is_mobile', array( __CLASS__, 'observe_wp_is_mobile' ), 1, 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'observe_posts_query' ), 1, 1 );
		add_action( 'pre_get_comments', array( __CLASS__, 'observe_comments_query' ), 1, 1 );
	}

	/**
	 * A theme or plugin asked whether this is a mobile device.
	 *
	 * @param bool $is_mobile WordPress' answer.
	 * @return bool Unchanged.
	 */
	public static function observe_wp_is_mobile( $is_mobile ) {
		self::$device_aware    = true;
		self::$observed_mobile = (bool) $is_mobile;
		return $is_mobile;
	}

	/**
	 * A secondary query for public content is about to run (widget, related posts, shortcode…).
	 *
	 * @param WP_Query $query The query.
	 */
	public static function observe_posts_query( $query ) {
		if ( self::$lists_others || ! $query instanceof WP_Query || $query->is_main_query() || ( is_admin() && ! self::$self_test ) ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( array() === array_filter( $types ) ) {
			$types = array( 'post' );
		}
		foreach ( $types as $type ) {
			if ( 'any' === $type ) {
				self::$lists_others = true;
				return;
			}
			if ( is_string( $type ) && 'attachment' !== $type && post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
				self::$lists_others = true;
				return;
			}
		}
	}

	/**
	 * A comment query is about to run. Comments of the page's own post are not "other" content.
	 *
	 * @param WP_Comment_Query $query The query.
	 */
	public static function observe_comments_query( $query ) {
		if ( self::$lists_others || ( is_admin() && ! self::$self_test ) || ! is_object( $query ) || ! isset( $query->query_vars ) ) {
			return;
		}
		$post_id    = isset( $query->query_vars['post_id'] ) ? (int) $query->query_vars['post_id'] : 0;
		$queried_id = (int) get_queried_object_id();
		if ( 0 === $post_id || $post_id !== $queried_id ) {
			self::$lists_others = true;
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
		$device_aware = self::$device_aware && 'auto' === self::$mobile_mode;
		if ( $device_aware && ! headers_sent() ) {
			// This page's HTML depends on the device: shared caches in front of WordPress must key on it.
			header( 'Vary: User-Agent', false );
		}
		// Only a complete, un-flushed, un-discarded document is stored.
		$complete = ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && ! ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( $complete && self::$ctx && self::response_is_cacheable( $html ) && ! self::mobile_classification_differs() ) {
			try {
				BEPLUSPB_Page_Cache_Engine::store(
					self::$ctx,
					$html . "\n<!-- Page cached by Beplus Performance Booster on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n",
					self::replayable_headers(),
					array(
						'device'       => $device_aware,
						'lists_others' => self::$lists_others,
					)
				);
			} catch ( Throwable $e ) {
				unset( $e ); // Never break the response because caching failed.
			}
		}
		return $html;
	}

	/**
	 * Whether the page was built for a different kind of device than the cache keyed it under.
	 *
	 * The cache classifies visitors itself (it runs before WordPress). If the page then asks
	 * wp_is_mobile() and gets another answer — a client-hint/User-Agent disagreement, an older
	 * WordPress that ignores hints, a plugin overriding the filter — storing it would hand mobile
	 * HTML to desktops or the reverse. Such a response is simply not stored.
	 *
	 * @return bool
	 */
	private static function mobile_classification_differs() {
		if ( null === self::$observed_mobile || ! in_array( self::$mobile_mode, array( 'auto', 'separate' ), true ) ) {
			return false;
		}
		$engine_mobile = BEPLUSPB_Page_Cache_Engine::is_mobile_request( $_SERVER );
		return self::$observed_mobile !== $engine_mobile;
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
		self::$flagged_purged = false;
		$result               = BEPLUSPB_Page_Cache_Engine::purge_all();
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

		// Pages that showed OTHER content when they were built (related/recent posts, recent
		// comments from any plugin) may show this post: drop them too, wherever they live.
		if ( ! self::$flagged_purged && 'related' !== self::sanitize_choice( bepluspb_get_options()['page_cache_purge_scope'] ?? 'auto', array( 'auto', 'related', 'all' ), 'auto' ) ) {
			self::$flagged_purged = true; // Admin requests store no pages, so one walk per request is enough.
			if ( false === BEPLUSPB_Page_Cache_Engine::purge_flagged() ) {
				self::purge_all(); // Too many files to walk: be conservative.
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

		self::register_observers();

		// Posts.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 20, 3 );
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
		// Authors' names, bios and avatars are printed on their posts and archives.
		add_action( 'profile_update', array( __CLASS__, 'on_user_changed' ), 20, 1 );
		add_action( 'deleted_user', array( __CLASS__, 'purge_all' ), 20, 0 );
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
	 * Whether a post type is shown on pages without being a page itself (template parts,
	 * global styles, navigation, reusable blocks, builder libraries, forms, tables…).
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_structural_post_type( $type ) {
		/**
		 * Filters the non-public post types whose saves never purge the page cache.
		 *
		 * @param string[] $types Post type names.
		 */
		$ignored = apply_filters( 'bepluspb_page_cache_ignored_post_types', self::IGNORED_POST_TYPES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		return is_string( $type ) && ! in_array( $type, (array) $ignored, true ) && post_type_exists( $type ) && ! is_post_type_viewable( $type );
	}

	/**
	 * Whether the current request is someone editing the site, not a visitor or a background job.
	 *
	 * Front-end form entries, cron and queues also create non-public posts; they must not
	 * wipe the cache. Editors (wp-admin, block editor/REST) and WP-CLI do.
	 *
	 * @return bool
	 */
	private static function is_site_editor_request() {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Title, slug, parent or order of a published post changed. Menus, breadcrumbs, page
	 * lists, previous/next links and every child URL show these on OTHER pages, so
	 * purging the post and its archives is not enough.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public static function on_post_updated( $post_id, $after, $before ) {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $after->post_status && 'publish' !== $before->post_status ) {
			return;
		}
		if ( ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}
		foreach ( array( 'post_title', 'post_name', 'post_parent', 'menu_order' ) as $field ) {
			if ( (string) $after->$field !== (string) $before->$field ) {
				self::purge_all();
				return;
			}
		}
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
		if ( self::is_structural_post_type( $post->post_type ) ) {
			if ( self::is_site_editor_request() ) {
				self::purge_all();
			}
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
		if ( ( 'publish' === $new_status ) === ( 'publish' === $old_status ) || ! $post instanceof WP_Post ) {
			return;
		}
		if ( is_post_type_viewable( $post->post_type ) || ( self::is_structural_post_type( $post->post_type ) && self::is_site_editor_request() ) ) {
			self::purge_all();
		}
	}

	/**
	 * A published post was deleted outright.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_deleted_post( $post_id, $post = null ) {
		unset( $post_id );
		if ( $post instanceof WP_Post && 'publish' === $post->post_status && ( is_post_type_viewable( $post->post_type ) || ( self::is_structural_post_type( $post->post_type ) && self::is_site_editor_request() ) ) ) {
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
	 * A profile was saved. Only people who can write posts have bylines, author boxes and
	 * author archives; customers or subscribers editing their account must not wipe the cache.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_user_changed( $user_id ) {
		if ( user_can( $user_id, 'edit_posts' ) ) {
			self::purge_all();
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
		if ( 'home' === $option || 'siteurl' === $option ) {
			// The host allowlist in the config must follow the address; sync() rewrites it and purges.
			self::sync( bepluspb_get_options() );
			return;
		}
		if ( 0 === strpos( $option, 'widget_' ) || 'sidebars_widgets' === $option ) {
			self::flush_sitewide_detection();
		}
		if ( self::option_clears_cache( $option ) ) {
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
