<?php
/**
 * JavaScript optimization: defer attribute and interaction-based delay.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_JS
 *
 * Handles two JS performance strategies:
 *  1. defer  — adds the `defer` attribute to non-critical script tags.
 *  2. delay  — delays all script execution until the first user interaction
 *              with a 5-second fallback.
 */
class BEPLUSPB_JS {

	/**
	 * Register front-end hooks based on current settings.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['js_defer'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'defer_scripts' ), 10, 3 );
		}

		if ( $opts['js_delay'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'delay_scripts' ), 20, 3 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_delay_script' ) );
		}

		if ( ! empty( $opts['remove_js_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_js_handles' ), 100 );
		}
	}

	// -------------------------------------------------------------------------
	// Remove JS handles
	// -------------------------------------------------------------------------

	/**
	 * Dequeue and deregister JS handles listed in the remove_js_handles option.
	 */
	public static function dequeue_js_handles() {
		$opts    = bepluspb_get_options();
		$handles = bepluspb_parse_exclude_list( $opts['remove_js_handles'] );

		foreach ( $handles as $handle ) {
			if ( ! empty( $handle ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Defer
	// -------------------------------------------------------------------------

	/**
	 * Add the `defer` attribute to script tags.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function defer_scripts( $tag, $handle, $src ) {
		$never_defer = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments', 'bepluspb-delay-js', 'bepluspb-lazy-fallback' );
		if ( in_array( $handle, $never_defer, true ) ) {
			return $tag;
		}

		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}

		return str_replace( ' src=', ' defer src=', $tag );
	}

	// -------------------------------------------------------------------------
	// Delay
	// -------------------------------------------------------------------------

	/**
	 * Convert an enqueued <script src="…"> tag to a "delayed" placeholder.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function delay_scripts( $tag, $handle, $src ) {
		$never_delay = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments', 'bepluspb-delay-js', 'bepluspb-lazy-fallback' );
		if ( in_array( $handle, $never_delay, true ) ) {
			return $tag;
		}

		if ( empty( $src ) || false === strpos( $tag, ' src=' ) ) {
			return $tag;
		}

		if ( false !== strpos( $tag, 'data-bepluspb-delay' ) ) {
			return $tag;
		}

		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		$tag = preg_replace_callback(
			'/<script([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>/i',
			function ( $m ) use ( $handle ) {
				$before = $m[1];
				$quote  = $m[2];
				$src    = $m[3];
				$after  = $m[4];

				$before = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $before );
				$after  = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $after );

				return '<script' . $before . $after
					. ' type="text/plain"'
					. ' data-bepluspb-delay="1"'
					. ' data-bepluspb-handle="' . esc_attr( $handle ) . '"'
					. ' data-bepluspb-src="' . esc_url( $src ) . '"'
					. '>';
			},
			$tag
		);

		return $tag;
	}

	// -------------------------------------------------------------------------
	// Enqueue delay loader script
	// -------------------------------------------------------------------------

	/**
	 * Register and enqueue the delay loader script with the exclude list passed
	 * via wp_localize_script so no raw PHP is echoed into a <script> tag.
	 */
	public static function enqueue_delay_script() {
		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );

		wp_enqueue_script(
			'bepluspb-delay-js',
			BEPLUSPB_PLUGIN_URL . 'assets/js/delay.js',
			array(),
			BEPLUSPB_VERSION,
			true
		);

		wp_localize_script(
			'bepluspb-delay-js',
			'bepluspbDelayConfig',
			array( 'skip' => array_values( $exclude ) )
		);
	}
}
