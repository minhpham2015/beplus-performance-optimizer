<?php
/**
 * JavaScript optimization: defer attribute and interaction-based delay.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_JS
 *
 * Handles two JS performance strategies:
 *  1. defer  — adds the `defer` attribute to non-critical script tags.
 *  2. delay  — delays all script execution until the first user interaction
 *              (mousemove, click, scroll, keydown, touch) with a 5-second fallback.
 *
 * Delay implementation
 * --------------------
 * The `delay_scripts` filter converts external <script src="…"> tags to
 * <script type="text/plain" data-sob-delay="1" data-sob-src="…"> so the
 * browser does not parse or execute them immediately. The inline snippet
 * output by `output_delay_script()` listens for the first user interaction
 * and then dynamically creates real <script src="…"> elements for each
 * delayed script.
 *
 * Both strategies respect a user-defined exclude list of URL keywords.
 * jQuery, jquery-core, and jquery-migrate are always protected from defer/delay.
 */
class SOB_JS {

	/**
	 * Register front-end hooks based on current settings.
	 * Called from sob_boot_frontend() which already guards against admin / logged-in admins.
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['js_defer'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'defer_scripts' ), 10, 3 );
		}

		if ( $opts['js_delay'] ) {
			// Convert script tags to the delayed format (type="text/plain" + data-sob-src).
			add_filter( 'script_loader_tag', array( __CLASS__, 'delay_scripts' ), 20, 3 );
			// Output the loader snippet that fires on first user interaction.
			add_action( 'wp_footer', array( __CLASS__, 'output_delay_script' ), 1 );
		}

		// Remove specific JS handles before scripts are printed.
		if ( ! empty( $opts['remove_js_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_js_handles' ), 100 );
		}
	}

	// -------------------------------------------------------------------------
	// Remove JS handles
	// -------------------------------------------------------------------------

	/**
	 * Dequeue and deregister JS handles listed in the remove_js_handles option.
	 *
	 * Runs at wp_enqueue_scripts priority 100 so it fires after all plugins
	 * and themes have registered their scripts.
	 *
	 * Named method (not an anonymous closure) so it can be removed with
	 * remove_action() by third-party code if needed.
	 */
	public static function dequeue_js_handles() {
		$opts    = sob_get_options();
		$handles = sob_parse_exclude_list( $opts['remove_js_handles'] );

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
	 * Always skips:
	 *  - jQuery handles (jquery, jquery-core, jquery-migrate)
	 *  - Scripts already containing defer or async
	 *  - Scripts whose src matches any keyword in the exclude list
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function defer_scripts( $tag, $handle, $src ) {
		// jQuery and wc-cart-fragments must always be synchronous.
		// wc-cart-fragments powers live cart counts in WooCommerce mini-carts;
		// deferring it causes the count to stay at zero until a hard reload.
		$never_defer = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments' );
		if ( in_array( $handle, $never_defer, true ) ) {
			return $tag;
		}

		// Respect the user-defined exclude list.
		$opts    = sob_get_options();
		$exclude = sob_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		// Skip scripts that already declare their own loading strategy.
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
	 * The converted tag uses type="text/plain" so the browser ignores it,
	 * and carries the original src as data-sob-src so the footer loader
	 * snippet can recreate a real <script> element when the user first
	 * interacts with the page.
	 *
	 * Skipped scripts:
	 *  - jQuery handles (must always load immediately)
	 *  - Inline scripts (no src attribute)
	 *  - Scripts already converted / carrying a loading strategy
	 *  - Scripts in the user-defined exclude list
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function delay_scripts( $tag, $handle, $src ) {
		// Protect jQuery and wc-cart-fragments — must always load synchronously.
		$never_delay = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments' );
		if ( in_array( $handle, $never_delay, true ) ) {
			return $tag;
		}

		// Inline scripts have no src; nothing to delay.
		if ( empty( $src ) || false === strpos( $tag, ' src=' ) ) {
			return $tag;
		}

		// Already converted.
		if ( false !== strpos( $tag, 'data-sob-delay' ) ) {
			return $tag;
		}

		// Respect the user-defined exclude list.
		$opts    = sob_get_options();
		$exclude = sob_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		// Convert: change type to "text/plain" and move src to data-sob-src.
		// This prevents the browser from loading/executing the script.
		$tag = preg_replace_callback(
			'/<script([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>/i',
			function ( $m ) use ( $handle ) {
				$before = $m[1]; // attributes before src=
				$quote  = $m[2];
				$src    = $m[3];
				$after  = $m[4]; // attributes after src=

				// Strip any existing type= attribute so we can replace it.
				$before = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $before );
				$after  = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $after );

				return '<script' . $before . $after
					. ' type="text/plain"'
					. ' data-sob-delay="1"'
					. ' data-sob-handle="' . esc_attr( $handle ) . '"'
					. ' data-sob-src="' . esc_url( $src ) . '"'
					. '>';
			},
			$tag
		);

		return $tag;
	}

	// -------------------------------------------------------------------------
	// Footer loader snippet
	// -------------------------------------------------------------------------

	/**
	 * Output the inline JS delay snippet into wp_footer (priority 1).
	 *
	 * The snippet listens for the first user interaction event and then
	 * loads any scripts tagged with data-sob-delay / data-sob-src.
	 * A 5-second setTimeout acts as a safety fallback so bots and
	 * interaction-free sessions still receive all scripts.
	 *
	 * Listened events: mousemove, mousedown, keydown, scroll, touchstart,
	 * touchmove, wheel, click — comprehensive coverage of all real interactions.
	 */
	public static function output_delay_script() {
		$opts         = sob_get_options();
		$exclude      = sob_parse_exclude_list( $opts['js_exclude'] );
		$exclude_json = wp_json_encode( array_values( $exclude ) );

		// wp_json_encode() returns false on failure; fall back to an empty array.
		if ( false === $exclude_json ) {
			$exclude_json = '[]';
		}
		?>
<script id="sob-delay-js">
(function () {
	'use strict';

	var _sobExclude = <?php echo $exclude_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value is JSON-encoded via wp_json_encode() ?>;
	var _sobLoaded  = false;

	function _sobIsExcluded(src) {
		for (var i = 0; i < _sobExclude.length; i++) {
			if (_sobExclude[i] && src.indexOf(_sobExclude[i]) !== -1) return true;
		}
		return false;
	}

	function _sobLoadAll() {
		if (_sobLoaded) return;
		_sobLoaded = true;

		// Find all placeholder <script type="text/plain" data-sob-delay="1"> elements.
		var delayed = document.querySelectorAll('script[data-sob-delay="1"]');
		delayed.forEach(function (placeholder) {
			var src = placeholder.getAttribute('data-sob-src') || '';
			if (src && !_sobIsExcluded(src)) {
				var s   = document.createElement('script');
				s.src   = src;
				s.async = false; // preserve execution order
				document.body.appendChild(s);
			}
			// Remove the placeholder from the DOM.
			if (placeholder.parentNode) {
				placeholder.parentNode.removeChild(placeholder);
			}
		});
	}

	// All meaningful user-interaction events.
	var _sobEvents = [
		'mousemove', 'mousedown',
		'keydown',
		'scroll', 'wheel',
		'touchstart', 'touchmove',
		'click'
	];

	function _sobOnInteraction() {
		_sobLoadAll();
		_sobEvents.forEach(function (e) {
			document.removeEventListener(e, _sobOnInteraction, {passive: true});
		});
	}

	_sobEvents.forEach(function (e) {
		document.addEventListener(e, _sobOnInteraction, {once: true, passive: true});
	});

	// Safety fallback: load after 5 s even with no interaction.
	setTimeout(_sobLoadAll, 5000);
})();
</script>
		<?php
	}
}
