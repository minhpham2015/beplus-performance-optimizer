<?php
/**
 * Plugin Name: Beplus Performance Booster
 * Description: Smart caching, JS/CSS minification, lazy loading, and site cleanup in one lightweight plugin — frontend performance without touching the admin.
 * Version: 1.3.4
 * Author:      Minh BePlus
 * Author URI:  https://beplusthemes.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beplus-performance-booster
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 8.2
 *
 * @package Beplus_Performance_Booster
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// CONSTANTS
// ---------------------------------------------------------------------------

define( 'BEPLUSPB_VERSION', '1.3.4' );
define( 'BEPLUSPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEPLUSPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );

/**
 * Filesystem path to the CSS/JS minification cache directory.
 *
 * Stored inside wp-content/uploads/ so that managed hosting environments
 * (WP Engine, Kinsta, Pantheon, etc.) which only guarantee the uploads
 * directory is writable can still use the cache. The matching public URL is
 * returned by BEPLUSPB_Minify::cache_url() — computed lazily to guarantee
 * wp_upload_dir() is called after WordPress has fully initialised.
 */
define( 'BEPLUSPB_CACHE_DIR', wp_upload_dir()['basedir'] . '/bepluspb-cache/' );

/**
 * Multisite compatibility note:
 *
 * This plugin is not designed for WordPress Multisite. The BEPLUSPB_OPTIONS_KEY
 * option is stored in the sub-site options table, but the single shared
 * BEPLUSPB_CACHE_DIR path and a single .htaccess block mean that settings from
 * one sub-site affect all others. Network activation is therefore NOT
 * recommended. Install this plugin on individual sub-sites only.
 */

// ---------------------------------------------------------------------------
// SHARED HELPERS
// Global functions available to all classes.
// ---------------------------------------------------------------------------

/**
 * Returns the full set of default option values.
 * All features are OFF by default so the plugin is safe on first activation.
 *
 * @return array
 */
function bepluspb_default_options() {
	return array(
		// --- JavaScript Optimization ---
		'js_delay'                           => 0,
		'js_delay_mode'                      => 'simple',   // 'simple' or 'advanced'. phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- not code, just quoting the two valid option values.
		'js_delay_rdelay'                    => 0,           // ms to wait after above-fold images load (advanced mode).
		'js_defer'                           => 0,
		'js_exclude'                         => '',

		// --- CSS Optimization ---
		'css_minify'                         => 0,
		'css_non_blocking'                   => 0,
		'css_exclude'                        => '',

		// --- Remove Unused CSS ---
		'css_remove_unused'                  => 0,   // Strip CSS rules unused on the current page.
		'css_unused_safelist'                => '',  // Selectors to always keep (newline-separated, '*' wildcard supported).
		'css_unused_exclude'                 => '',  // URL keywords to skip (newline-separated).

		// --- Lazy Load Images ---
		'lazy_load'                          => 0,

		// --- Remove Unused Assets ---
		'remove_emoji'                       => 0,
		'remove_embed'                       => 0,
		'remove_block_css'                   => 0,
		'remove_woo_scripts'                 => 0,

		// --- HTML Optimization ---
		'html_minify'                        => 0,
		'html_remove_comments'               => 0,
		'html_remove_js_comments'            => 0,
		'html_remove_css_comments'           => 0,

		// --- Browser Cache (.htaccess) ---
		'cache_headers'                      => 0,

		// --- Lazy Load — Advanced options ---
		'lazy_skip_first_n'                  => 1,   // Legacy (no UI, unused since Lazy Load v2): only seeds lazy_core_threshold when that field is missing. Use "Priority Images" for the hero/LCP image.
		'lazy_core_threshold'                => 3,   // Core-managed media omission threshold.
		'lazy_engine'                        => 'core', // 'core' (WordPress loading=lazy) or 'html' (rewrite the page HTML: works with page builders).
		'lazy_html_skip_first'               => 2,   // HTML engine: leave the first N eligible images eager (above the fold).
		'lazy_html_iframes'                  => 1,   // HTML engine: also give iframes the native loading="lazy".
		'lazy_html_bg'                       => 0,   // HTML engine: also lazy load inline background-image:url() (opt-in).
		'lazy_html_threshold'                => 300, // HTML engine: load an image this many px before it is seen (doubled on slow connections).
		'lazy_exclude_class'                 => '',  // CSS class names to exclude (comma-separated).
		'lazy_exclude_id'                    => '',  // Element IDs to exclude (comma-separated).
		'lazy_exclude_filename'              => '',  // Partial filename strings to exclude (comma-sep).

		// --- File Minification ---
		'minify_css_files'                   => 0,   // Minify enqueued CSS files → cache.
		'minify_js_files'                    => 0,   // Minify enqueued JS files  → cache.

		// --- Advanced CSS ---
		'css_inline_all'                     => 0,   // Inline all local CSS files into <head>.
		'css_inline_skip_elementor'          => 1,   // Inline All CSS leaves Elementor's own stylesheets as links (Elementor has its own CSS cache/optimisation).
		'css_remove_handles'                 => '',  // CSS handles to dequeue (newline-separated).

		// --- Advanced JS ---
		'remove_js_handles'                  => '',  // JS handles to dequeue (newline-separated).

		// --- Priority Images (LCP) ---
		'priority_images'                    => '',  // "image URL | home, all or /path/ | mobile, desktop or all", one per line (max 4).
		'priority_images_hints'              => array(), // id => { srcset, sizes } captured by the LCP Analyzer (see BEPLUSPB_Priority_Images::entry_id()).

		// --- Critical CSS (needs Non-Render-Blocking CSS; entries live in their own option, see BEPLUSPB_Critical_CSS) ---
		'critical_css_enabled'               => 0,   // Print the stored critical CSS of the page type and defer every stylesheet (theme's included).

		// --- Google Fonts ---
		'google_fonts_mode'                  => 'off',   // off, optimize (font-display + preconnect) or selfhost (download the fonts once, serve them from this site).
		'google_fonts_display'               => 'swap',  // swap, fallback or optional.

		// --- Font Preload ---
		'font_preload'                       => '',  // Font URLs to preload (newline-separated).

		// --- Cache Exclusions ---
		'cache_exclude_pages'                => "/checkout/\n/my-account/\n/cart/",

		// --- Master Cache Switch ---
		'cache_enabled'                      => 0,

		// --- Page Cache (static HTML for anonymous visitors) ---
		'page_cache_enabled'                 => 0,   // Needs the master cache toggle too. Off by default.
		'page_cache_preload'                 => 0,   // Warm the cache from WP-Cron after it was emptied. Needs Page Cache.
		'page_cache_ttl'                     => 10,  // Hours. Keep under 12 so WordPress nonces in cached forms stay valid.
		'page_cache_exclude_cookies'         => '',  // Extra cookie-name prefixes that bypass the page cache (newline-separated).
		'page_cache_mobile'                  => 'auto', // 'auto' | 'separate' | 'same' | 'bypass': how mobile devices (wp_is_mobile()) are cached.
		'page_cache_vary_cookies'            => '',  // Cookie names whose value gets its own cached copy (language/currency), newline-separated.
		'page_cache_purge_options'           => '',  // Extra option names ("prefix*" allowed) whose change clears the page cache, newline-separated.
		'page_cache_purge_scope'             => 'auto', // 'auto' | 'related' | 'all': what editing a published post purges.

		// --- Automatic exclusions for popular plugins/themes (static list, see BEPLUSPB_Compat) ---
		'auto_exclude'                       => 1,   // Keep the listed plugins' scripts/styles out of minify/defer/delay/CSS deferral and their checkout pages out of the page cache.
		'auto_exclude_off'                   => '',  // Rule ids the administrator switched off (newline-separated).

		// --- Safe testing ---
		'safe_mode_admins_only'              => 0,   // Only logged-in administrators get the front-end optimisations (and Page Cache is paused): visitors see the site as without the plugin.

		// --- Heartbeat control (WordPress Heartbeat API; works without the master cache toggle) ---
		'heartbeat_dashboard'                => 'default', // default | reduce | disable (wp-admin pages except the editing screens).
		'heartbeat_editor'                   => 'default', // default | reduce (post editor, site editor, widgets, Customizer: "disable" is not offered, block editors depend on the script).
		'heartbeat_frontend'                 => 'default', // default | reduce | disable.
		'heartbeat_interval'                 => 120,       // Seconds between pulses when "reduce" is chosen (15-120).

		// --- Resource hints (preconnect / dns-prefetch for third-party origins) ---
		'resource_hints_preconnect'          => '',  // Origins to preconnect to, one per line (max 4).
		'resource_hints_dns_prefetch'        => '',  // Origins to dns-prefetch, one per line (max 10).

		// --- Predictive Navigation (WordPress 6.8+ native Speculation Rules) ---
		'predictive_navigation_enabled'      => 0,
		'predictive_navigation_mode'         => 'safe',
		'predictive_navigation_excludes'     => '',

		// --- CDN (custom pull-zone rewriter) ---
		'cdn_enabled'                        => 0,
		'cdn_url'                            => '',  // e.g. https://xxxxxxxx.quic.cloud or a CNAME'd custom domain.
		'cdn_file_types'                     => BEPLUSPB_CDN::default_file_types(),
		'cdn_exclude'                        => '',
		'cdn_webp_avif'                      => 0,  // Serve a sibling .webp/.avif file instead of .jpg/.jpeg/.png when one exists and the browser supports it.

		// --- Cloudflare (API-triggered cache purge + development mode) ---
		'cloudflare_enabled'                 => 0,
		'cloudflare_purge_on_edit'           => 0,  // Purge the edited post's URLs at Cloudflare when a post is saved (needs the zone from Test Connection).
		'cloudflare_api_token'               => '',  // Cloudflare API Token (Bearer auth only, no legacy Global API Key support).
		'cloudflare_zone_id'                 => '',  // Auto-populated by "Test Connection" — never user-editable directly.
		'cloudflare_zone_name'               => '',  // Matched zone name, shown read-only in the UI.

		// --- Object Cache (Redis / Memcached) ---
		'object_cache_enabled'               => 0,
		'object_cache_driver'                => 'redis',   // 'redis' or 'memcached'. phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- not code, just quoting the two valid option values.
		'object_cache_host'                  => '127.0.0.1',
		'object_cache_port'                  => 6379,
		'object_cache_password'              => '',        // Redis AUTH only.
		'object_cache_db'                    => 0,         // Redis DB index only.
		'object_cache_persistent'            => 1,
		'object_cache_global_groups'         => "userlogins\nuseremail\nuser_meta\nsite-options",
		'object_cache_non_persistent_groups' => "users\nsite-transient\ncomment\ncounts\nplugins",
	);
}

/**
 * Returns saved options merged with defaults.
 *
 * Uses a process-level cache (global variable) so get_option() is only
 * called once per request.
 *
 * @return array
 */
function bepluspb_get_options() {
	global $bepluspb_options_cache;
	if ( isset( $bepluspb_options_cache ) && is_array( $bepluspb_options_cache ) ) {
		return $bepluspb_options_cache;
	}
	$saved                  = get_option( BEPLUSPB_OPTIONS_KEY, array() );
	$bepluspb_options_cache = wp_parse_args( $saved, bepluspb_default_options() );
	return $bepluspb_options_cache;
}

/**
 * Invalidate the bepluspb_get_options() cache so the next call re-reads from the DB.
 */
function bepluspb_flush_options_cache() {
	global $bepluspb_options_cache;
	$bepluspb_options_cache = null;
}
add_action( 'update_option_' . BEPLUSPB_OPTIONS_KEY, 'bepluspb_flush_options_cache' );

/**
 * Notify safe page-cache integrations when the font list changes.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value New option value.
 */
function bepluspb_font_preload_option_changed( $old_value, $value ) {
	$old_fonts = is_array( $old_value ) ? ( $old_value['font_preload'] ?? '' ) : '';
	$new_fonts = is_array( $value ) ? ( $value['font_preload'] ?? '' ) : '';
	if ( $old_fonts !== $new_fonts ) {
		do_action( 'bepluspb_font_preload_changed', $new_fonts, $old_fonts );
	}
}
add_action( 'update_option_' . BEPLUSPB_OPTIONS_KEY, 'bepluspb_font_preload_option_changed', 10, 2 );

/**
 * Parse a newline-separated textarea value into a trimmed, filtered array.
 *
 * @param  string $textarea Raw textarea value from settings.
 * @return array
 */
function bepluspb_parse_exclude_list( $textarea ) {
	if ( empty( $textarea ) ) {
		return array();
	}
	$lines = explode( "\n", $textarea );
	return array_values( array_filter( array_map( 'trim', $lines ) ) );
}

/**
 * The effective exclusion list of one kind: what the administrator typed plus the automatic exclusions
 * of the detected plugins/themes (BEPLUSPB_Compat). Front-end code uses this instead of reading the
 * "Exclude ..." settings directly; the saved settings themselves are never changed.
 *
 * @param  array  $opts Options.
 * @param  string $kind js, css or pages.
 * @return array
 */
function bepluspb_exclude_list( $opts, $kind ) {
	if ( class_exists( 'BEPLUSPB_Compat' ) ) {
		return BEPLUSPB_Compat::exclusions( $opts, $kind );
	}
	$key = array(
		'js'    => 'js_exclude',
		'css'   => 'css_exclude',
		'pages' => 'cache_exclude_pages',
	);
	return isset( $key[ $kind ], $opts[ $key[ $kind ] ] ) ? bepluspb_parse_exclude_list( $opts[ $key[ $kind ] ] ) : array();
}


// ---------------------------------------------------------------------------
// LOAD CLASS FILES
// ---------------------------------------------------------------------------

require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-object-cache.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-dropin-workflow.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-recommendations.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-utils.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-admin.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-font-preload.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-htaccess.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-cleanup.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-compat.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-heartbeat.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-resource-hints.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-css.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-js.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-images.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-lazy-html.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-priority-images.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-critical-css.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-google-fonts-local.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-google-fonts.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-html.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-minify.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-ucss.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-cdn.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-cloudflare.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-predictive-navigation.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php';
require_once BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache-preload.php';

// Settings-change sync, GC cron and self-repair must exist whether or not the cache is on.
BEPLUSPB_Page_Cache::register_core_hooks();
BEPLUSPB_Page_Cache_Preload::register_core_hooks();
// Self-hosted Google Fonts: the download run is a WP-Cron event that may be pending whatever the setting says.
BEPLUSPB_Google_Fonts_Local::register_core_hooks();

// Fallback path: when advanced-cache.php is not active (WP_CACHE off, or another
// plugin owns the drop-in) serve a stored page right now — before the theme and
// the main query load. A no-op whenever the drop-in already had its chance.
BEPLUSPB_Page_Cache::maybe_serve_fallback();

// ---------------------------------------------------------------------------
// ACTIVATION / DEACTIVATION HOOKS
// Must be registered in the main file because they use __FILE__.
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	function () {
		$existing = get_option( BEPLUSPB_OPTIONS_KEY, null );
		if ( null === $existing ) {
			add_option( BEPLUSPB_OPTIONS_KEY, bepluspb_default_options() );
		}

		$opts = bepluspb_get_options();
		if ( $opts['cache_headers'] ) {
			BEPLUSPB_Htaccess::add_rules();
		}
		// Deactivation removes the page-cache stub and config; restore them on re-activation.
		if ( BEPLUSPB_Page_Cache::is_enabled( $opts ) ) {
			BEPLUSPB_Page_Cache::sync( $opts );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		BEPLUSPB_Htaccess::remove_rules();
		BEPLUSPB_Page_Cache::shutdown();
		wp_clear_scheduled_hook( BEPLUSPB_Google_Fonts_Local::CRON_HOOK );
	}
);

// ---------------------------------------------------------------------------
// BOOT
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', array( 'BEPLUSPB_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'BEPLUSPB_Predictive_Navigation', 'init' ), 20 );
add_action( 'plugins_loaded', 'bepluspb_boot_heartbeat', 20 );
add_action( 'plugins_loaded', array( 'BEPLUSPB_Cloudflare', 'register_hooks' ), 20 );

/**
 * Heartbeat control runs in wp-admin and on the front end, so it is set up here instead of in
 * bepluspb_boot_frontend() (which skips wp-admin and needs the master cache toggle).
 */
function bepluspb_boot_heartbeat() {
	BEPLUSPB_Heartbeat::init( bepluspb_get_options() );
}

/**
 * Auto-clear the CSS/JS cache whenever a plugin or theme update completes.
 *
 * @param WP_Upgrader $upgrader  Upgrader instance.
 * @param array       $hook_extra Information about the update.
 */
add_action(
	'upgrader_process_complete',
	function ( $upgrader, $hook_extra ) {
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			BEPLUSPB_Minify::clear_cache();
		}
	},
	10,
	2
);

/**
 * Early cleanup: remove oEmbed REST route and discovery links.
 */
function bepluspb_early_embed_cleanup() {
	$opts = bepluspb_get_options();
	if ( ! empty( $opts['remove_embed'] ) ) {
		BEPLUSPB_Cleanup::remove_embed_early();
	}
}
add_action( 'plugins_loaded', 'bepluspb_early_embed_cleanup', 20 );

/**
 * Register cache-invalidation hooks that must fire from BOTH wp-admin and
 * the front-end (e.g. saving a post from wp-admin must purge the Remove
 * Unused CSS cache, but bepluspb_boot_frontend() below never runs in
 * wp-admin because of its is_admin() gate).
 */
function bepluspb_register_invalidation_hooks() {
	$opts = bepluspb_get_options();
	if ( empty( $opts['cache_enabled'] ) ) {
		return;
	}
	BEPLUSPB_UCSS::register_invalidation_hooks( $opts );
	BEPLUSPB_Page_Cache::register_invalidation_hooks( $opts );
}
add_action( 'plugins_loaded', 'bepluspb_register_invalidation_hooks', 20 );

/**
 * Initialise all front-end modules on the 'wp' action.
 */
add_action( 'wp', 'bepluspb_boot_frontend' );

/**
 * Safety-gate for front-end optimisations.
 */
function bepluspb_boot_frontend() {
	if ( is_admin() ) {
		return;
	}

	// `?bepluspb_off` loads this one request as if the plugin's front-end optimisations were off
	// (compare a page with and without them, or find out which one breaks something). It only ever
	// turns things OFF and stores nothing; the page cache never serves or stores a URL with a query
	// string it does not know, and the response is marked uncacheable for browsers and CDNs.
	if ( bepluspb_is_bypass_request() ) {
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		return;
	}

	$opts = bepluspb_get_options();

	if ( empty( $opts['cache_enabled'] ) ) {
		return;
	}

	$is_admin_user = is_user_logged_in() && current_user_can( 'manage_options' );

	// Safe testing: only administrators get the optimisations, visitors see the untouched site.
	// (The Page Cache is paused as well, see BEPLUSPB_Page_Cache::is_enabled(): a stored page would
	// otherwise be served to visitors.)
	$preview = ! empty( $opts['safe_mode_admins_only'] );
	if ( $preview && ! $is_admin_user ) {
		return;
	}

	// The Page Cache never stores a logged-in visitor's page, so it can be set up first.
	BEPLUSPB_Page_Cache::init( $opts );

	// Administrators see the untouched page so they can edit and debug it: no minified
	// CSS/JS, no defer/delay, no HTML/CDN rewriting. Every other logged-in user (members,
	// customers, editors) is optimised exactly like a visitor, since the files are static.
	// Return true from this filter to preview exactly what visitors get. Safe testing is the
	// opposite: the administrator is the only one who gets the optimised page.
	$optimize_admins = $preview || (bool) apply_filters( 'bepluspb_optimize_for_admins', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
	if ( ! $optimize_admins && $is_admin_user ) {
		return;
	}

	BEPLUSPB_Minify::init( $opts );
	BEPLUSPB_JS::init( $opts );

	BEPLUSPB_Cleanup::init( $opts );
	BEPLUSPB_CSS::init( $opts );
	BEPLUSPB_UCSS::init( $opts );
	BEPLUSPB_Images::init( $opts );
	BEPLUSPB_Lazy_HTML::init( $opts );
	BEPLUSPB_Priority_Images::init( $opts );
	BEPLUSPB_Resource_Hints::init( $opts );
	BEPLUSPB_Google_Fonts::init( $opts );
	BEPLUSPB_HTML::init( $opts );
	BEPLUSPB_CDN::init( $opts );
}

/**
 * Whether this request asked for the front-end optimisations to be skipped (`?bepluspb_off`).
 *
 * @return bool
 */
function bepluspb_is_bypass_request() {
	return isset( $_GET['bepluspb_off'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch that only turns optimisations off, nothing is stored.
}
