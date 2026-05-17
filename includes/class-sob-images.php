<?php
/**
 * Lazy load images: native loading="lazy" attribute + IntersectionObserver fallback.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_Images
 *
 * Applies native lazy loading to images across the page with fine-grained
 * control over which images are affected.
 *
 * Strategy
 * --------
 * 1. Add `loading="lazy"` to every qualifying `<img>` tag found in
 *    post content, post thumbnails, widget text, and `<picture>` elements.
 * 2. Honor the "skip first N images" option (images 1–N are loaded eagerly
 *    so the LCP / hero image is never lazy-loaded).
 * 3. Allow exclusion by CSS class, element ID, or filename keyword.
 * 4. For browsers that do not support the native `loading` attribute an
 *    IntersectionObserver-based polyfill is injected into wp_footer.
 *
 * `<picture>` elements
 * --------------------
 * The native `loading="lazy"` attribute belongs on the `<img>` inside
 * `<picture>`, NOT on `<source>`. Modern browsers handle the srcset
 * selection for lazy-loaded `<picture>` images automatically when
 * `loading="lazy"` is present on the `<img>`. The polyfill also handles
 * `data-srcset` → `srcset` restoration if the data-srcset pattern is used.
 *
 * Script self-exclusion
 * ---------------------
 * Both `sob-lazy-fallback` and `sob-delay-js` are output as inline
 * `<script>` blocks (no `src` attribute). They are never processed by the
 * `script_loader_tag` filter and therefore cannot be deferred or delayed
 * regardless of the JS optimisation settings.
 */
class SOB_Images {

	/**
	 * Running count of <img> tags processed in this HTTP request.
	 * Shared across multiple filter invocations (the_content, widget_text…)
	 * so the "skip first N" threshold applies page-wide.
	 *
	 * @var int
	 */
	private static $image_count = 0;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register all lazy-load hooks when the feature is enabled.
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		if ( ! $opts['lazy_load'] ) {
			return;
		}

		// Reset the per-request image counter.
		self::$image_count = 0;

		// Gutenberg block-level filter — fires per-block BEFORE WordPress's own
		// wp_filter_content_tags() hook (which runs at render_block priority 10).
		// Running at priority 8 lets us apply skip-N / class / ID / filename
		// exclusion rules before WP adds its own generic loading="lazy".
		add_filter( 'render_block',        array( __CLASS__, 'process_html' ), 8 );

		// PERF-4: For Gutenberg posts the render_block filter already processes
		// every image, so hooking the_content as well would double-count images
		// against the skip-N threshold and waste time re-processing the same HTML.
		// Skip the_content when the current queried post uses blocks.
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_blocks( $post->post_content ) ) {
			add_filter( 'the_content', array( __CLASS__, 'process_html' ), 20 );
		}

		add_filter( 'post_thumbnail_html', array( __CLASS__, 'process_html' ), 20 );
		add_filter( 'widget_text',         array( __CLASS__, 'process_html' ), 20 );

		// Polyfill for browsers without native loading="lazy" support.
		add_action( 'wp_footer', array( __CLASS__, 'output_fallback_script' ), 20 );
	}

	// -------------------------------------------------------------------------
	// HTML processing
	// -------------------------------------------------------------------------

	/**
	 * Main entry point for content transformation.
	 *
	 * Processing order:
	 *  1. Protect <picture> blocks with tokens.
	 *  2. Process standalone <img> tags (those outside <picture>).
	 *  3. Restore <picture> blocks, processing the inner <img> inside each one.
	 *
	 * This two-pass approach ensures that images inside <picture> are counted
	 * and excluded by the same logic as standalone <img> tags, and avoids any
	 * risk of double-processing.
	 *
	 * @param  string $content HTML markup (post body, thumbnail, widget, etc.).
	 * @return string Transformed HTML.
	 */
	public static function process_html( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		$opts            = sob_get_options();
		$skip_n          = absint( $opts['lazy_skip_first_n'] );
		$exc_classes     = self::parse_comma_list( $opts['lazy_exclude_class'] );
		$exc_ids         = self::parse_comma_list( $opts['lazy_exclude_id'] );
		$exc_filenames   = self::parse_comma_list( $opts['lazy_exclude_filename'] );

		// --- Pass 1: protect <picture>…</picture> blocks with tokens ----
		$picture_blocks = array();
		$pic_idx        = 0;
		$content = preg_replace_callback(
			'/<picture[^>]*>[\s\S]*?<\/picture>/i',
			function ( $m ) use ( &$picture_blocks, &$pic_idx ) {
				$token                    = 'SOBPIC' . $pic_idx . 'END';
				$picture_blocks[ $token ] = $m[0];
				$pic_idx++;
				return $token;
			},
			$content
		);

		// --- Pass 2: standalone <img> tags --------------------------------
		$content = preg_replace_callback(
			'/<img([^>]*)>/i',
			function ( $m ) use ( $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
				return self::maybe_lazy( $m[0], $m[1], $skip_n, $exc_classes, $exc_ids, $exc_filenames );
			},
			$content
		);

		// --- Pass 3: restore <picture> blocks, lazifying their inner <img> --
		foreach ( $picture_blocks as $token => $picture_html ) {
			$picture_html = preg_replace_callback(
				'/<img([^>]*)>/i',
				function ( $m ) use ( $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
					return self::maybe_lazy( $m[0], $m[1], $skip_n, $exc_classes, $exc_ids, $exc_filenames );
				},
				$picture_html
			);
			$content = str_replace( $token, $picture_html, $content );
		}

		return $content;
	}

	// -------------------------------------------------------------------------
	// Per-image decision logic
	// -------------------------------------------------------------------------

	/**
	 * Decide whether to add loading="lazy" to one <img> tag.
	 *
	 * Exclusion rules applied in order:
	 *  1. Already has a loading= attribute → leave unchanged.
	 *  2. Image count ≤ skip_n → load eagerly (LCP protection).
	 *  3. Has an excluded CSS class → load eagerly.
	 *  4. Has an excluded element ID → load eagerly.
	 *  5. src contains an excluded filename keyword → load eagerly.
	 *
	 * @param  string   $full_tag      The full <img ...> string.
	 * @param  string   $attrs         Everything between <img and >.
	 * @param  int      $skip_n        Number of leading images to skip.
	 * @param  string[] $exc_classes   CSS class names that trigger exclusion.
	 * @param  string[] $exc_ids       Element IDs that trigger exclusion.
	 * @param  string[] $exc_filenames Partial filename strings that trigger exclusion.
	 * @return string   Possibly modified <img> tag.
	 */
	private static function maybe_lazy( $full_tag, $attrs, $skip_n, $exc_classes, $exc_ids, $exc_filenames ) {
		// 1. Already has loading= → do not interfere.
		if ( false !== strpos( $attrs, 'loading=' ) ) {
			return $full_tag;
		}

		// 2. Increment the page-level image counter and apply the skip threshold.
		self::$image_count++;
		if ( self::$image_count <= $skip_n ) {
			// Mark the first N images explicitly as eager so browsers never
			// lazy-load them even if a parent container has CSS that would
			// otherwise trigger native lazy loading.
			return '<img' . $attrs . ' loading="eager">';
		}

		// 3. Exclude by CSS class.
		if ( ! empty( $exc_classes ) ) {
			if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $attrs, $cls_m ) ) {
				$img_classes = array_filter( explode( ' ', $cls_m[1] ) );
				foreach ( $exc_classes as $cls ) {
					if ( '' !== $cls && in_array( $cls, $img_classes, true ) ) {
						return $full_tag;
					}
				}
			}
		}

		// 4. Exclude by element ID.
		if ( ! empty( $exc_ids ) ) {
			if ( preg_match( '/\bid=["\']([^"\']*)["\']/', $attrs, $id_m ) ) {
				foreach ( $exc_ids as $exc_id ) {
					if ( '' !== $exc_id && $exc_id === $id_m[1] ) {
						return $full_tag;
					}
				}
			}
		}

		// 5. Exclude by filename keyword in src.
		if ( ! empty( $exc_filenames ) ) {
			if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $attrs, $src_m ) ) {
				foreach ( $exc_filenames as $keyword ) {
					if ( '' !== $keyword && false !== strpos( $src_m[1], $keyword ) ) {
						return $full_tag;
					}
				}
			}
		}

		// All checks passed — apply native lazy loading.
		return '<img' . $attrs . ' loading="lazy">';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Split a comma-separated string into a trimmed, filtered array.
	 *
	 * @param  string $value Raw option value.
	 * @return string[]
	 */
	private static function parse_comma_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}

	// -------------------------------------------------------------------------
	// IntersectionObserver polyfill (wp_footer)
	// -------------------------------------------------------------------------

	/**
	 * Output a minimal IntersectionObserver polyfill into wp_footer.
	 *
	 * The polyfill only activates in browsers that do NOT support the native
	 * `loading` attribute (~5 % of global traffic as of 2025). It handles:
	 *  - `img[loading="lazy"]`  — swaps `data-src` → `src` on intersection.
	 *  - `img[data-srcset]`     — swaps `data-srcset` → `srcset` as a bonus.
	 *
	 * If neither native loading nor IntersectionObserver is available the
	 * images load eagerly (safe graceful degradation).
	 *
	 * Note: This script is output as an inline block (no `src` attribute) so
	 * it is never affected by the JS defer or delay optimisation options.
	 */
	public static function output_fallback_script() {
		?>
<script id="sob-lazy-fallback">
(function () {
	'use strict';

	// Native lazy loading supported — nothing to polyfill.
	if ( 'loading' in HTMLImageElement.prototype ) {
		return;
	}

	var lazyImgs = [].slice.call( document.querySelectorAll( 'img[loading="lazy"]' ) );
	if ( ! lazyImgs.length ) {
		return;
	}

	function loadImage( img ) {
		if ( img.dataset && img.dataset.src ) {
			img.src = img.dataset.src;
		}
		if ( img.dataset && img.dataset.srcset ) {
			img.srcset = img.dataset.srcset;
		}
		img.removeAttribute( 'loading' );
	}

	if ( 'IntersectionObserver' in window ) {
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( ! entry.isIntersecting ) {
						return;
					}
					loadImage( entry.target );
					observer.unobserve( entry.target );
				} );
			},
			{ rootMargin: '200px 0px', threshold: 0.01 }
		);
		lazyImgs.forEach( function ( img ) {
			observer.observe( img );
		} );
	} else {
		// No IntersectionObserver — load all images immediately.
		lazyImgs.forEach( loadImage );
	}
})();
</script>
		<?php
	}
}
