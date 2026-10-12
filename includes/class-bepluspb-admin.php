<?php
/**
 * Admin settings page, Settings API registration, options sanitization,
 * meta box, admin bar menu, and cache management UI.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin/trait-bepluspb-admin-tabs-basic.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-tabs-delivery.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-tabs-status.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-tabs-misc.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-tabs-object-cache.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-admin-bar.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-actions.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-meta-box.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-ajax-object-cache.php';
require_once __DIR__ . '/admin/trait-bepluspb-admin-ajax-cloudflare.php';

/**
 * Class BEPLUSPB_Admin
 *
 * Registers the Settings > Beplus Performance Booster page and handles all
 * option storage via the WordPress Settings API.
 *
 * Also provides:
 *  - Meta box on post/page edit screens ("Disable cache for this page").
 *  - Admin bar "Clear Cache" menu with a "Clear CSS/JS Cache" link.
 *  - A "Clear Cache" button on the settings page itself.
 *  - Admin notice confirming successful cache clears.
 */
class BEPLUSPB_Admin {

	use BEPLUSPB_Admin_Tabs_Basic;
	use BEPLUSPB_Admin_Tabs_Delivery;
	use BEPLUSPB_Admin_Tabs_Status;
	use BEPLUSPB_Admin_Tabs_Misc;
	use BEPLUSPB_Admin_Tabs_Object_Cache;
	use BEPLUSPB_Admin_Admin_Bar;
	use BEPLUSPB_Admin_Actions;
	use BEPLUSPB_Admin_Meta_Box;
	use BEPLUSPB_Admin_Ajax_Object_Cache;
	use BEPLUSPB_Admin_Ajax_Cloudflare;

	// =========================================================================
	// Bootstrap
	// =========================================================================

	/**
	 * Register all admin hooks.
	 * Called on 'plugins_loaded'.
	 */
	public static function init() {
		// Settings page.
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Per-page cache-disable meta box.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		// Admin bar "Beplus Performance Booster" menu (visible on frontend + backend for admins).
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_menu' ), 100 );

		// Enqueue admin bar stylesheet on front-end pages where the bar is showing.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_adminbar_styles' ) );

		// Destructive cache maintenance is POST-only.
		add_action( 'admin_post_bepluspb_purge_all_cache', array( __CLASS__, 'handle_purge_all_cache' ) );
		add_action( 'admin_post_bepluspb_purge_object_cache', array( __CLASS__, 'handle_purge_object_cache' ) );

		// POST handler for quick-enable buttons on the Dashboard tab.
		add_action( 'admin_post_bepluspb_quick_enable', array( __CLASS__, 'handle_quick_enable' ) );
		add_action( 'admin_post_bepluspb_enable_all_recommended', array( __CLASS__, 'handle_enable_all_recommended' ) );
		add_action( 'admin_post_bepluspb_recommendation_action', array( __CLASS__, 'handle_recommendation_action' ) );

		// AJAX handler for the master cache on/off toggle on the Dashboard tab.
		add_action( 'wp_ajax_bepluspb_toggle_cache', array( __CLASS__, 'handle_ajax_toggle_cache' ) );

		// Object Cache AJAX handlers.
		add_action( 'wp_ajax_bepluspb_test_oc_connection', array( __CLASS__, 'handle_ajax_test_oc' ) );
		add_action( 'wp_ajax_bepluspb_install_oc_dropin', array( __CLASS__, 'handle_ajax_install_oc' ) );
		add_action( 'wp_ajax_bepluspb_remove_oc_dropin', array( __CLASS__, 'handle_ajax_remove_oc' ) );
		add_action( 'wp_ajax_bepluspb_preflight_oc_replace', array( __CLASS__, 'handle_ajax_preflight_oc_replace' ) );
		add_action( 'wp_ajax_bepluspb_backup_replace_oc', array( __CLASS__, 'handle_ajax_backup_replace_oc' ) );
		add_action( 'wp_ajax_bepluspb_restore_oc', array( __CLASS__, 'handle_ajax_restore_oc' ) );

		// Cloudflare AJAX handlers (all admin-only, nonce + capability checked).
		add_action( 'wp_ajax_bepluspb_cf_test_connection', array( __CLASS__, 'handle_ajax_cf_test_connection' ) );
		add_action( 'wp_ajax_bepluspb_cf_purge', array( __CLASS__, 'handle_ajax_cf_purge' ) );
		add_action( 'wp_ajax_bepluspb_cf_devmode', array( __CLASS__, 'handle_ajax_cf_devmode' ) );

		// Admin notices for cache clears and unavailable configured backends.
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_cleared_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_object_cache_warning' ) );
	}

	// =========================================================================
	// Assets
	// =========================================================================

	/**
	 * Enqueue admin assets.
	 *
	 * CSS is loaded on all admin pages because the admin bar panel is visible
	 * on every admin screen — not just the plugin settings page.
	 * JS (tab switcher) is only needed on the settings page itself.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		// Load CSS on every admin page — needed for the admin bar cache panel.
		wp_enqueue_style(
			'bepluspb-admin',
			BEPLUSPB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BEPLUSPB_VERSION
		);

		// CQ-3: Tab-switching logic lives in an external file so it can be
		// cached by the browser and linted/tested independently.
		if ( 'settings_page_beplus-performance-booster' === $hook_suffix ) {
			wp_enqueue_script(
				'bepluspb-admin-js',
				BEPLUSPB_PLUGIN_URL . 'assets/js/admin.js',
				array(),
				BEPLUSPB_VERSION,
				true // load in footer.
			);
			// Pass AJAX URL, nonce, and translated strings for the master cache toggle.
			wp_localize_script(
				'bepluspb-admin-js',
				'bepluspbAdmin',
				array(
					'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
					'toggleNonce'    => wp_create_nonce( 'bepluspb_toggle_cache' ),
					'testOcNonce'    => wp_create_nonce( 'bepluspb_test_oc' ),
					'installOcNonce' => wp_create_nonce( 'bepluspb_install_oc_dropin' ),
					'removeOcNonce'  => wp_create_nonce( 'bepluspb_remove_oc_dropin' ),
					'labelEnabled'   => __( 'Enabled', 'beplus-performance-booster' ),
					'labelDisabled'  => __( 'Disabled', 'beplus-performance-booster' ),
					'noticeDisabled' => __( 'All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.', 'beplus-performance-booster' ),
					'testingOc'      => __( 'Testing…', 'beplus-performance-booster' ),
					'installingOc'   => __( 'Installing…', 'beplus-performance-booster' ),
					'removingOc'     => __( 'Removing…', 'beplus-performance-booster' ),
				)
			);
		}
	}

	/**
	 * Enqueue admin bar stylesheet on the front end.
	 *
	 * Fires on wp_enqueue_scripts so the admin bar cache panel looks correct
	 * for logged-in admins viewing the public-facing site.
	 */
	public static function enqueue_adminbar_styles() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_enqueue_style(
			'bepluspb-admin',
			BEPLUSPB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BEPLUSPB_VERSION
		);
	}

	// =========================================================================
	// Settings page
	// =========================================================================

	/**
	 * Register the options page under Settings > Beplus Performance Booster.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'Beplus Performance Booster', 'beplus-performance-booster' ),
			__( 'Beplus Performance Booster', 'beplus-performance-booster' ),
			'manage_options',
			'beplus-performance-booster',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the single option key with the WordPress Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'bepluspb_settings_group',
			BEPLUSPB_OPTIONS_KEY,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
			)
		);
	}

	/**
	 * Sanitize and save submitted settings.
	 *
	 * Also handles toggling .htaccess rules when the cache option changes.
	 *
	 * @param  array $input Raw POST values.
	 * @return array Sanitized option values.
	 */
	public static function sanitize_options( $input ) {
		$sanitized = array();

		// ---- Boolean toggles (checkbox = 1 when present, 0 when absent). ----
		$booleans = array(
			// JS.
			'js_delay',
			'js_defer',
			// CSS.
			'css_minify',
			'css_non_blocking',
			'css_inline_all',
			'css_remove_unused',
			// Lazy load.
			'lazy_load',
			// Cleanup.
			'remove_emoji',
			'remove_embed',
			'remove_block_css',
			'remove_woo_scripts',
			// HTML.
			'html_minify',
			'html_remove_comments',
			'html_remove_js_comments',
			'html_remove_css_comments',
			// Cache.
			'cache_headers',
			// File minification.
			'minify_css_files',
			'minify_js_files',
			// Cache exclusions.
			'cache_for_logged_in',
			// CDN.
			'cdn_enabled',
			'cdn_webp_avif',
			// Cloudflare.
			'cloudflare_enabled',
			'predictive_navigation_enabled',
		);
		foreach ( $booleans as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		// ---- Textarea / text fields. ----
		$sanitized['js_exclude'] = isset( $input['js_exclude'] )
			? sanitize_textarea_field( $input['js_exclude'] )
			: '';

		// ---- Cloudflare API Token. Zone id/name are NOT sanitized from this
		// form — they are only ever written by the "Test Connection" AJAX
		// handler (BEPLUSPB_Admin::handle_ajax_cf_test_connection()), so a
		// stale/mismatched zone can never be typed in by hand. Preserve the
		// existing DB value here exactly like cache_enabled does below. ----
		$sanitized['cloudflare_api_token'] = isset( $input['cloudflare_api_token'] )
			? sanitize_text_field( $input['cloudflare_api_token'] )
			: '';
		$existing_cf                       = get_option( BEPLUSPB_OPTIONS_KEY, array() );
		$sanitized['cloudflare_zone_id']   = isset( $existing_cf['cloudflare_zone_id'] ) ? $existing_cf['cloudflare_zone_id'] : '';
		$sanitized['cloudflare_zone_name'] = isset( $existing_cf['cloudflare_zone_name'] ) ? $existing_cf['cloudflare_zone_name'] : '';

		// ---- JS delay mode + rdelay. ----
		$sanitized['js_delay_mode'] = ( isset( $input['js_delay_mode'] ) && 'advanced' === $input['js_delay_mode'] )
			? 'advanced'
			: 'simple';

		$sanitized['js_delay_rdelay'] = isset( $input['js_delay_rdelay'] )
			? absint( $input['js_delay_rdelay'] )
			: 0;

		$sanitized['css_exclude'] = isset( $input['css_exclude'] )
			? sanitize_textarea_field( $input['css_exclude'] )
			: '';

		$sanitized['css_remove_handles'] = isset( $input['css_remove_handles'] )
			? sanitize_textarea_field( $input['css_remove_handles'] )
			: '';

		$sanitized['css_unused_safelist'] = isset( $input['css_unused_safelist'] )
			? sanitize_textarea_field( $input['css_unused_safelist'] )
			: '';

		$sanitized['css_unused_exclude'] = isset( $input['css_unused_exclude'] )
			? sanitize_textarea_field( $input['css_unused_exclude'] )
			: '';

		$sanitized['remove_js_handles'] = isset( $input['remove_js_handles'] )
			? sanitize_textarea_field( $input['remove_js_handles'] )
			: '';

		$sanitized['font_preload'] = isset( $input['font_preload'] )
			? sanitize_textarea_field( wp_unslash( $input['font_preload'] ) )
			: '';

		$sanitized['cache_exclude_pages'] = isset( $input['cache_exclude_pages'] )
			? sanitize_textarea_field( $input['cache_exclude_pages'] )
			: '';

		$allowed_predictive_modes                    = array( 'safe', 'balanced', 'fast' );
		$predictive_mode                             = isset( $input['predictive_navigation_mode'] ) ? sanitize_key( $input['predictive_navigation_mode'] ) : 'safe';
		$sanitized['predictive_navigation_mode']     = in_array( $predictive_mode, $allowed_predictive_modes, true ) ? $predictive_mode : 'safe';
		$sanitized['predictive_navigation_excludes'] = BEPLUSPB_Predictive_Navigation::sanitize_excludes( $input['predictive_navigation_excludes'] ?? '' );

		// ---- CDN (custom pull-zone rewriter). ----
		$sanitized['cdn_url'] = isset( $input['cdn_url'] )
			? esc_url_raw( trim( $input['cdn_url'] ) )
			: '';

		$sanitized['cdn_file_types'] = isset( $input['cdn_file_types'] ) && '' !== trim( $input['cdn_file_types'] )
			? sanitize_text_field( $input['cdn_file_types'] )
			: BEPLUSPB_CDN::default_file_types();

		$sanitized['cdn_exclude'] = isset( $input['cdn_exclude'] )
			? sanitize_textarea_field( $input['cdn_exclude'] )
			: '';

		// ---- Lazy load advanced options. ----
		$sanitized['lazy_skip_first_n']   = isset( $input['lazy_skip_first_n'] )
			? absint( $input['lazy_skip_first_n'] )
			: 1;
		$sanitized['lazy_core_threshold'] = isset( $input['lazy_core_threshold'] )
			? min( 20, absint( $input['lazy_core_threshold'] ) )
			: ( isset( $input['lazy_skip_first_n'] ) ? min( 20, absint( $input['lazy_skip_first_n'] ) ) : 3 );

		$sanitized['lazy_exclude_class'] = isset( $input['lazy_exclude_class'] )
			? sanitize_text_field( $input['lazy_exclude_class'] )
			: '';

		$sanitized['lazy_exclude_id'] = isset( $input['lazy_exclude_id'] )
			? sanitize_text_field( $input['lazy_exclude_id'] )
			: '';

		$sanitized['lazy_exclude_filename'] = isset( $input['lazy_exclude_filename'] )
			? sanitize_text_field( $input['lazy_exclude_filename'] )
			: '';

		// ---- Object Cache settings. ----
		$sanitized['object_cache_enabled']               = ! empty( $input['object_cache_enabled'] ) ? 1 : 0;
		$sanitized['object_cache_persistent']            = ! empty( $input['object_cache_persistent'] ) ? 1 : 0;
		$sanitized['object_cache_driver']                = ( isset( $input['object_cache_driver'] ) && 'memcached' === $input['object_cache_driver'] )
			? 'memcached' : 'redis';
		$sanitized['object_cache_host']                  = isset( $input['object_cache_host'] )
			? sanitize_text_field( $input['object_cache_host'] ) : '127.0.0.1';
		$sanitized['object_cache_port']                  = isset( $input['object_cache_port'] )
			? absint( $input['object_cache_port'] ) : 6379;
		$sanitized['object_cache_password']              = isset( $input['object_cache_password'] )
			? sanitize_text_field( $input['object_cache_password'] ) : '';
		$sanitized['object_cache_db']                    = isset( $input['object_cache_db'] )
			? absint( $input['object_cache_db'] ) : 0;
		$sanitized['object_cache_global_groups']         = isset( $input['object_cache_global_groups'] )
			? sanitize_textarea_field( $input['object_cache_global_groups'] ) : '';
		$sanitized['object_cache_non_persistent_groups'] = isset( $input['object_cache_non_persistent_groups'] )
			? sanitize_textarea_field( $input['object_cache_non_persistent_groups'] ) : '';

		// Write the guarded config file used by the drop-in.
		if ( ! BEPLUSPB_Object_Cache::write_config( $sanitized ) ) {
			add_settings_error(
				BEPLUSPB_OPTIONS_KEY,
				'bepluspb_oc_config_failed',
				__( 'Object Cache configuration was not written because the guarded configuration file could not be created, verified, or migrated. Check wp-content permissions and save again.', 'beplus-performance-booster' ),
				'error'
			);
		}

		// ---- Master cache switch — preserved from DB when not submitted. ----
		// cache_enabled lives on the Dashboard tab outside the main <form>, so it
		// is never present in the Settings API POST. Read the existing DB value
		// rather than defaulting to 0 (which would undo every AJAX toggle save).
		// Fallback uses 0 to match bepluspb_default_options() — the master toggle
		// must stay OFF on a fresh install until the user explicitly enables it.
		if ( isset( $input['cache_enabled'] ) ) {
			$sanitized['cache_enabled'] = (int) (bool) $input['cache_enabled'];
		} else {
			$existing                   = get_option( BEPLUSPB_OPTIONS_KEY, array() );
			$sanitized['cache_enabled'] = isset( $existing['cache_enabled'] ) ? (int) $existing['cache_enabled'] : 0;
		}

		// ---- Sync .htaccess rules when the cache_headers toggle changes. ----
		$old = bepluspb_get_options();
		if ( $sanitized['cache_headers'] && ! $old['cache_headers'] ) {
			BEPLUSPB_Htaccess::add_rules();
		} elseif ( ! $sanitized['cache_headers'] && $old['cache_headers'] ) {
			BEPLUSPB_Htaccess::remove_rules();
		}

		return $sanitized;
	}

	/**
	 * Format a recommendation value for the preview table only.
	 *
	 * Stored option values and recommendation behavior remain unchanged.
	 *
	 * @param mixed $value Raw recommendation value.
	 * @return string
	 */
	private static function format_recommendation_value( $value ) {
		if ( 1 === $value || '1' === $value || true === $value ) {
			return 'Active';
		}
		if ( 0 === $value || '0' === $value || false === $value ) {
			return 'Inactive';
		}
		return (string) $value;
	}

	/**
	 * Render the full admin settings page with a tabbed interface.
	 *
	 * Five tabs:
	 *  1. Dashboard   — outside the <form> (has its own action forms)
	 *  2. Cache Files — JS + CSS + Media merged
	 *  3. Fonts       — font preload
	 *  4. Cleanup     — Remove Assets + HTML
	 *  5. Cache Exclusions — page exclusions + user exclusions + browser cache
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts = bepluspb_get_options();

		$cache_dir_writable = BEPLUSPB_Minify::ensure_cache_dir();

		// Tab definitions: id => label.
		// NOTE: Fonts, CDN, and Cache Exclusions were merged into a single
		// 'advanced' tab (v1.1.11) — see render order inside the
		// bepluspb-tab-advanced panel below. Backward-compat aliasing of the
		// old 'fonts'/'cdn'/'exclusions' hash values to 'advanced' lives in
		// assets/js/admin.js.
		$tabs = array(
			'dashboard'    => '📊 ' . __( 'Dashboard', 'beplus-performance-booster' ),
			'cache_files'  => '⚡ ' . __( 'Cache Files', 'beplus-performance-booster' ),
			'cloudflare'   => '🔶 ' . __( 'Cloudflare', 'beplus-performance-booster' ),
			'cleanup'      => '🧹 ' . __( 'Cleanup', 'beplus-performance-booster' ),
			'advanced'     => '🛠️ ' . __( 'Advanced', 'beplus-performance-booster' ),
			'predictive'   => '⚡ ' . __( 'Predictive Navigation', 'beplus-performance-booster' ),
			'object_cache' => '🗄️ ' . __( 'Object Cache', 'beplus-performance-booster' ),
			'status'       => '🔍 ' . __( 'Status', 'beplus-performance-booster' ),
			'ai_optimizer' => '🤖 ' . __( 'AI Optimizer', 'beplus-performance-booster' ),
		);
		?>
		<div class="wrap bepluspb-settings-wrap">
			<h1><?php esc_html_e( 'Beplus Performance Booster', 'beplus-performance-booster' ); ?></h1>
			<p class="bepluspb-tagline"><?php esc_html_e( 'Beplus Performance Booster is a Smart caching, JS/CSS minification, lazy loading, and site cleanup in one lightweight plugin — frontend performance without touching the admin.', 'beplus-performance-booster' ); ?></p>

			<!-- Tab navigation -->
			<div class="bepluspb-tabs-nav" role="tablist">
				<?php foreach ( $tabs as $id => $label ) : ?>
				<button type="button"
					id="bepluspb-tab-btn-<?php echo esc_attr( $id ); ?>"
					class="bepluspb-tab-btn"
					data-tab="<?php echo esc_attr( $id ); ?>"
					role="tab"
					aria-controls="bepluspb-tab-<?php echo esc_attr( $id ); ?>"
					aria-selected="false">
					<?php echo esc_html( $label ); ?>
				</button>
				<?php endforeach; ?>
			</div>

			<!-- Dashboard tab: rendered outside the settings form so it can have its own forms -->
			<div id="bepluspb-tab-dashboard" class="bepluspb-tab-panel" role="tabpanel">
				<?php self::render_section_dashboard( $opts, $cache_dir_writable ); ?>
			</div>

			<!-- Settings tabs: all inside a single <form> for the Settings API -->
			<form method="post" action="options.php" class="bepluspb-settings-form">
				<?php settings_fields( 'bepluspb_settings_group' ); ?>

				<div id="bepluspb-tab-cache_files" class="bepluspb-tab-panel" role="tabpanel">
					<?php self::render_section_cache_files( $opts, $cache_dir_writable ); ?>
				</div>

				<div id="bepluspb-tab-cloudflare" class="bepluspb-tab-panel" role="tabpanel">
					<?php self::render_section_cloudflare( $opts ); ?>
				</div>

				<div id="bepluspb-tab-cleanup" class="bepluspb-tab-panel" role="tabpanel">
					<?php self::render_section_cleanup_all( $opts ); ?>
				</div>

				<!-- Advanced tab: Font Optimization, CDN & Asset Delivery, Cache
					Exclusions merged into one panel (v1.1.11). Each section keeps
					its own <h2> card heading (rendered by the existing
					render_section_* methods, unchanged) under a labelled <section>
					landmark so no duplicate top-level h2 hierarchy is introduced. -->
				<div id="bepluspb-tab-advanced" class="bepluspb-tab-panel" role="tabpanel" aria-labelledby="bepluspb-tab-btn-advanced">
					<section id="bepluspb-advanced-section-fonts" class="bepluspb-advanced-section" aria-label="<?php esc_attr_e( 'Font Optimization', 'beplus-performance-booster' ); ?>">
						<?php self::render_section_fonts( $opts ); ?>
					</section>

					<section id="bepluspb-advanced-section-cdn" class="bepluspb-advanced-section" aria-label="<?php esc_attr_e( 'CDN & Asset Delivery', 'beplus-performance-booster' ); ?>">
						<?php self::render_section_cdn( $opts ); ?>
					</section>

					<section id="bepluspb-advanced-section-exclusions" class="bepluspb-advanced-section" aria-label="<?php esc_attr_e( 'Cache Exclusions', 'beplus-performance-booster' ); ?>">
						<?php self::render_section_exclusions( $opts ); ?>
					</section>
				</div>

				<div id="bepluspb-tab-predictive" class="bepluspb-tab-panel" role="tabpanel">
					<?php self::render_section_predictive_navigation( $opts ); ?>
				</div>

				<div id="bepluspb-tab-object_cache" class="bepluspb-tab-panel" role="tabpanel">
					<?php self::render_section_object_cache( $opts ); ?>
				</div>

				<div class="bepluspb-save-bar" id="bepluspb-save-bar">
					<?php submit_button( __( 'Save Settings', 'beplus-performance-booster' ), 'primary', 'submit', false ); ?>
				</div>
			</form>

			<form id="bepluspb-object-purge-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bepluspb-purge-form" data-confirm="<?php esc_attr_e( 'This may clear the entire configured Redis database or Memcached pool. Continue?', 'beplus-performance-booster' ); ?>">
				<input type="hidden" name="action" value="bepluspb_purge_object_cache">
				<input type="hidden" name="bepluspb_confirm_object_purge" value="1">
				<?php wp_nonce_field( 'bepluspb_purge_object_cache', 'bepluspb_object_purge_nonce' ); ?>
			</form>

			<!-- Status tab: outside the settings form — read-only system report -->
			<div id="bepluspb-tab-status" class="bepluspb-tab-panel" role="tabpanel">
				<?php self::render_section_status(); ?>
			</div>

			<!-- AI Optimizer tab: outside the settings form — no settings to save -->
			<div id="bepluspb-tab-ai_optimizer" class="bepluspb-tab-panel" role="tabpanel">
				<?php self::render_section_ai_optimizer(); ?>
			</div>

		</div>
		<?php
		// Tab-switching JavaScript is loaded via wp_enqueue_script() in
		// enqueue_admin_assets() — see assets/js/admin.js.
	}

	// =========================================================================
	// Section renderers
	// =========================================================================
}


