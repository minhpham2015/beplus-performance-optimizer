<?php
/**
 * Asset cleanup: emoji scripts, wp-embed, Gutenberg block CSS, WooCommerce on non-shop pages.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_Cleanup
 *
 * Removes unnecessary WordPress default assets from the front-end:
 *
 *  - Emoji detection script, inline style, and DNS prefetch hint.
 *  - wp-embed script and oEmbed discovery links.
 *  - Gutenberg block-library stylesheets (wp-block-library, global-styles, etc.).
 *  - WooCommerce scripts and styles on pages unrelated to the shop.
 *
 * Each removal is independently toggle-able from the admin settings page.
 *
 * Anonymous closures have been intentionally avoided throughout so every hook
 * registered by this class can be removed with remove_action() / remove_filter()
 * if a third-party plugin or theme needs to restore the behaviour.
 */
class SOB_Cleanup {

	/**
	 * Register cleanup hooks based on current settings.
	 *
	 * Called from sob_boot_frontend() on the 'wp' action, which fires after
	 * the current user is authenticated and the query is set up.
	 *
	 * NOTE: oEmbed REST route removal (remove_embed_early) is handled separately
	 * on 'plugins_loaded' in the main plugin file because it must run before
	 * 'parse_request' (where rest_api_init fires).
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['remove_emoji'] ) {
			self::remove_emoji();
		}

		if ( $opts['remove_embed'] ) {
			self::remove_embed_late();
		}

		if ( $opts['remove_block_css'] ) {
			self::remove_block_css();
		}

		if ( $opts['remove_woo_scripts'] ) {
			self::remove_woo_non_shop();
		}
	}

	// -------------------------------------------------------------------------
	// Emoji
	// -------------------------------------------------------------------------

	/**
	 * Unhook all WordPress emoji-related output on the front-end.
	 *
	 * Removes:
	 *  - The emoji detection script added to <head>.
	 *  - The emoji inline stylesheet.
	 *  - Emoji staticization filters on feeds and wp_mail.
	 *  - The wpemoji TinyMCE plugin.
	 *  - The emoji SVG DNS prefetch hint.
	 */
	private static function remove_emoji() {
		remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles',     'print_emoji_styles' );
		remove_action( 'admin_print_styles',  'print_emoji_styles' );
		remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
		remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins',  array( __CLASS__, 'remove_emoji_tinymce' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove the wpemoji plugin from TinyMCE.
	 *
	 * @param  array $plugins List of TinyMCE plugins.
	 * @return array
	 */
	public static function remove_emoji_tinymce( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Remove the emoji SVG origin from DNS prefetch resource hints.
	 *
	 * @param  array  $urls          Array of hint URLs.
	 * @param  string $relation_type Relation type (dns-prefetch, preconnect, etc.).
	 * @return array
	 */
	public static function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' === $relation_type ) {
			$emoji_url = apply_filters( 'emoji_svg_url', 'https://s.w.org/images/core/emoji/2/svg/' );
			$urls      = array_diff( $urls, array( $emoji_url ) );
		}
		return $urls;
	}

	// -------------------------------------------------------------------------
	// oEmbed / wp-embed  — split into early (REST route) and late (WP head/script)
	// -------------------------------------------------------------------------

	/**
	 * Early embed removal: unregister the oEmbed REST endpoint and the
	 * embed_oembed_discover filter.
	 *
	 * MUST be called at plugins_loaded (priority ≥ 20) so it fires before
	 * WordPress registers the route via rest_api_init (which runs inside
	 * parse_request). Called from sob_early_embed_cleanup() in the main file.
	 *
	 * Note: is_admin() / is_user_logged_in() are not available at this stage,
	 * so the oEmbed REST endpoint is removed site-wide. This is acceptable
	 * because the endpoint is only useful for allowing external sites to embed
	 * your content — an intentional opt-out.
	 */
	public static function remove_embed_early() {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * Late embed removal: dequeue wp-embed script and remove head links.
	 *
	 * Called from init() which runs on the 'wp' action (after query setup),
	 * so all target actions/filters are still pending at this point.
	 */
	private static function remove_embed_late() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_wp_embed' ) );
	}

	/**
	 * Dequeue the wp-embed script.
	 * Named method so it can be removed with remove_action() if needed.
	 */
	public static function dequeue_wp_embed() {
		wp_dequeue_script( 'wp-embed' );
	}

	// -------------------------------------------------------------------------
	// Block / Gutenberg CSS
	// -------------------------------------------------------------------------

	/**
	 * Register the dequeue callback for Gutenberg block-library stylesheets.
	 *
	 * Priority 100 ensures this runs after all enqueue hooks have fired.
	 */
	private static function remove_block_css() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_block_styles' ), 100 );
	}

	/**
	 * Dequeue Gutenberg block-library stylesheets on the front-end.
	 * Named method so it can be removed with remove_action() if needed.
	 */
	public static function dequeue_block_styles() {
		wp_dequeue_style( 'wp-block-library' );       // Core block styles
		wp_dequeue_style( 'wp-block-library-theme' ); // Core block theme styles
		wp_dequeue_style( 'wc-block-style' );          // WooCommerce Blocks styles
		wp_dequeue_style( 'global-styles' );           // FSE / Full Site Editing global styles
	}

	// -------------------------------------------------------------------------
	// WooCommerce on non-shop pages
	// -------------------------------------------------------------------------

	/**
	 * Register the WooCommerce dequeue callback on pages unrelated to the shop.
	 *
	 * Does nothing if WooCommerce is not active.
	 * Keeps assets on: shop, product, cart, checkout, and account pages.
	 * Priority 99 ensures WooCommerce's own enqueue has already run.
	 */
	private static function remove_woo_non_shop() {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		// Keep WooCommerce assets where they are actually needed.
		if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_woo_assets' ), 99 );
	}

	/**
	 * Dequeue WooCommerce scripts and styles on non-shop pages.
	 * Named method so it can be removed with remove_action() if needed.
	 */
	public static function dequeue_woo_assets() {
		// Styles
		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'woocommerce_frontend_styles' );
		wp_dequeue_style( 'wc-block-style' );
		wp_dequeue_style( 'woocommerce-inline' );

		// Scripts
		// NOTE: wc-cart-fragments is intentionally excluded from this list.
		// It maintains live cart counts across all pages via AJAX; removing it
		// breaks the mini-cart widget and the floating cart icon in most themes.
		wp_dequeue_script( 'woocommerce' );
		wp_dequeue_script( 'wc-add-to-cart' );
		wp_dequeue_script( 'wc-single-product' );
	}
}
