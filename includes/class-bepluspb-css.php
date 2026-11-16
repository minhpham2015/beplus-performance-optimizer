<?php
/**
 * CSS optimization: non-render-blocking preload swap, inline style minification,
 * font preloading, inline-all-CSS, and per-handle CSS removal.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_CSS
 *
 * Uses a wp_head output buffer to transform stylesheet links and
 * minify inline <style> blocks without touching external .css files.
 */
class BEPLUSPB_CSS {

	/**
	 * The ob_get_level() value recorded immediately before we call ob_start().
	 *
	 * Stored so buffer_end() can verify it is consuming only the buffer it
	 * opened. We compare against self::$buffer_level + 1 (the exact level of
	 * our buffer) rather than just > self::$buffer_level to avoid accidentally
	 * cleaning a buffer opened by another plugin on top of ours.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Register wp_head output-buffer hooks when at least one CSS feature is enabled.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( ! empty( $opts['font_preload'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'output_font_preload' ), 2 );
		}

		if ( ! empty( $opts['css_remove_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_css_handles' ), 9999 );
		}

		if ( $opts['css_non_blocking'] || $opts['css_minify'] || $opts['css_inline_all'] ) {
			add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), 1 );
			add_action( 'wp_head', array( __CLASS__, 'buffer_end' ), 9999 );
		}
	}

	// -------------------------------------------------------------------------
	// Font preload
	// -------------------------------------------------------------------------

	/**
	 * Output <link rel="preload" as="font"> tags for each URL in font_preload.
	 */
	public static function output_font_preload() {
		$opts = bepluspb_get_options();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes attributes.
		echo BEPLUSPB_Font_Preload::render( $opts['font_preload'] );
	}


	// -------------------------------------------------------------------------
	// Remove CSS handles
	// -------------------------------------------------------------------------

	/**
	 * Dequeue and deregister CSS handles listed in the css_remove_handles option.
	 */
	public static function remove_css_handles() {
		$opts    = bepluspb_get_options();
		$handles = bepluspb_parse_exclude_list( $opts['css_remove_handles'] );

		foreach ( $handles as $handle ) {
			if ( ! empty( $handle ) ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Output buffer
	// -------------------------------------------------------------------------

	/**
	 * Start output buffering at the very start of wp_head (priority 1).
	 *
	 * Records the current ob nesting level so buffer_end() can verify it is
	 * closing exactly the buffer it opened (level + 1), not one opened by
	 * another plugin on top of ours.
	 */
	public static function buffer_start() {
		self::$buffer_level = null;

		// Page-builder editors, Customizer/post previews, AMP, REST/JSON, login.
		if ( BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}

		self::$buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * End the buffer, apply transforms, and echo the result.
	 * Runs at priority 9999 — after all enqueued styles have been printed.
	 */
	public static function buffer_end() {
		// Safety: only clean the exact buffer level we opened (level + 1).
		// Using !== instead of <= prevents us from accidentally closing a buffer
		// opened by another plugin on top of ours.
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			return;
		}

		$html = ob_get_clean();

		if ( false === $html ) {
			return;
		}

		$opts = bepluspb_get_options();

		if ( $opts['css_inline_all'] ) {
			$exclude = bepluspb_parse_exclude_list( $opts['css_exclude'] );
			$html    = BEPLUSPB_Utils::guard_transform( self::inline_all_css( $html, $exclude ), $html );
		}

		if ( $opts['css_non_blocking'] ) {
			$exclude = bepluspb_parse_exclude_list( $opts['css_exclude'] );
			$html    = BEPLUSPB_Utils::guard_transform( self::make_non_blocking( $html, $exclude ), $html );
		}

		if ( $opts['css_minify'] ) {
			$html = BEPLUSPB_Utils::guard_transform( self::minify_inline_styles( $html ), $html );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	// -------------------------------------------------------------------------
	// Inline all CSS
	// -------------------------------------------------------------------------

	/**
	 * Replace every local <link rel="stylesheet"> with a <style> block
	 * containing the file's (minified) content.
	 *
	 * @param  string   $html    Buffered wp_head output.
	 * @param  string[] $exclude URL keywords whose stylesheets must be kept as links.
	 * @return string Modified HTML.
	 */
	public static function inline_all_css( $html, $exclude = array() ) {
		return preg_replace_callback(
			'/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i',
			function ( $matches ) use ( $exclude ) {
				$tag = $matches[0];

				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				if ( ! preg_match( '/\bhref=[\'"]([^\'"]+)[\'"]/', $tag, $href_m ) ) {
					return $tag;
				}
				$href = $href_m[1];

				$path = BEPLUSPB_Minify::url_to_path( $href );
				if ( ! $path ) {
					return $tag;
				}

				$minified = self::get_inline_css( $path, $href );
				if ( null === $minified ) {
					return $tag;
				}

				$media = '';
				if ( preg_match( '/\bmedia=[\'"]([^\'"]*)[\'"]/', $tag, $media_m ) ) {
					$media = ' media="' . esc_attr( $media_m[1] ) . '"';
				}

				return '<style' . $media . '>' . $minified . '</style>';
			},
			$html
		);
	}

	/**
	 * Minified, URL-rewritten CSS for one stylesheet, cached on disk.
	 *
	 * Inlining runs on every page view, so the expensive part (url() rewriting
	 * and minification) is cached per stylesheet, keyed on its path, public URL
	 * (the rewrite depends on it), mtime and size. Superseded generations of the
	 * same stylesheet are deleted when a new one is written.
	 *
	 * @param  string $path Absolute filesystem path of the stylesheet.
	 * @param  string $href Public URL the <link> pointed at.
	 * @return string|null Minified CSS, or null when the file is empty/unreadable.
	 */
	private static function get_inline_css( $path, $href ) {
		clearstatcache( true, $path );
		$mtime = @filemtime( $path );
		$size  = @filesize( $path );

		$cache_file = null;
		$prefix     = '';
		$name       = '';
		if ( false !== $mtime && false !== $size && $size > 0 ) {
			$prefix     = 'bepluspb-inline-' . substr( md5( $path . '|' . strtok( $href, '?' ) ), 0, 10 ) . '-';
			$name       = $prefix . substr( md5( $mtime . '|' . $size . '|u2' ), 0, 12 ) . '.css';
			$cache_file = BEPLUSPB_CACHE_DIR . $name;

			if ( is_file( $cache_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$cached = @file_get_contents( $cache_file );
				if ( false !== $cached && '' !== $cached ) {
					return $cached;
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = @file_get_contents( $path );
		if ( false === $content || '' === trim( $content ) ) {
			return null;
		}

		$minified = BEPLUSPB_Minify::minify_css( BEPLUSPB_Minify::absolutize_css_urls( $content, $href ) );

		if ( null !== $cache_file
			&& BEPLUSPB_Minify::ensure_cache_dir()
			&& BEPLUSPB_Minify::write_cache_file_atomic( $cache_file, $minified )
		) {
			BEPLUSPB_Minify::delete_stale_variants( $prefix, 'css', $name );
		}

		return $minified;
	}

	// -------------------------------------------------------------------------
	// Non-blocking CSS
	// -------------------------------------------------------------------------

	/**
	 * Replace <link rel="stylesheet"> tags with the preload + onload swap pattern.
	 *
	 * Left untouched (still render-blocking, on purpose): the active theme's
	 * main stylesheet(s) — matched by registered handle, so it still works once
	 * Minify/Remove Unused CSS moved the file to the cache directory —, URL
	 * keywords in $exclude, links that already preload or carry their own
	 * onload, links with a conditional `media` (preload would never swap them
	 * in), and every link when a Content-Security-Policy forbids inline event
	 * handlers (the swap would never run and the site would stay unstyled).
	 *
	 * @param  string $html    Buffered wp_head output.
	 * @param  array  $exclude URL keywords whose stylesheets must be left untouched.
	 * @return string Modified HTML.
	 */
	public static function make_non_blocking( $html, $exclude ) {
		if ( self::csp_blocks_inline_handlers( self::collect_csp_policies( $html ) ) ) {
			return $html;
		}

		$theme_paths = array();
		foreach ( array( get_bloginfo( 'stylesheet_url' ), trailingslashit( get_template_directory_uri() ) . 'style.css' ) as $theme_url ) {
			$path = $theme_url ? wp_parse_url( $theme_url, PHP_URL_PATH ) : null;
			if ( $path ) {
				$theme_paths[ $path ] = true;
				$exclude[]            = $path;
			}
		}
		$theme_handles = self::theme_stylesheet_handles( $theme_paths );

		return preg_replace_callback(
			'/<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>/i',
			function ( $matches ) use ( $exclude, $theme_handles ) {
				$tag = $matches[0];

				foreach ( $exclude as $keyword ) {
					if ( ! empty( $keyword ) && false !== strpos( $tag, $keyword ) ) {
						return $tag;
					}
				}

				if ( preg_match( '/\bid=[\'"]([^\'"]+)-css[\'"]/i', $tag, $id_m ) && isset( $theme_handles[ $id_m[1] ] ) ) {
					return $tag;
				}

				if ( false !== strpos( $tag, 'rel="preload"' ) || false !== strpos( $tag, "rel='preload'" ) ) {
					return $tag;
				}

				// Someone else already made this link async (media swap, loadCSS, ...).
				if ( preg_match( '/\sonload\s*=/i', $tag ) ) {
					return $tag;
				}

				// A preload only fetches when its media matches at load time, so a
				// conditional stylesheet would never be swapped in later.
				if ( preg_match( '/\smedia=([\'"])(.*?)\1/i', $tag, $media_m ) ) {
					$media = strtolower( trim( $media_m[2] ) );
					if ( '' !== $media && 'all' !== $media && 'screen' !== $media ) {
						return $tag;
					}
				}

				$preload = preg_replace(
					'/rel=[\'"]stylesheet[\'"]/i',
					'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
					$tag
				);

				return $preload . "\n<noscript>" . $tag . '</noscript>';
			},
			$html
		);
	}

	/**
	 * Handles of registered styles whose source is one of the theme stylesheets.
	 *
	 * @param  array $theme_paths URL paths of the theme stylesheets (path => true).
	 * @return array Handle set (handle => true).
	 */
	private static function theme_stylesheet_handles( $theme_paths ) {
		$handles = array();
		$styles  = function_exists( 'wp_styles' ) ? wp_styles() : null;
		if ( ! $styles || empty( $styles->registered ) || ! is_array( $styles->registered ) ) {
			return $handles;
		}
		foreach ( $styles->registered as $handle => $dep ) {
			$src  = is_object( $dep ) && isset( $dep->src ) && is_string( $dep->src ) ? $dep->src : '';
			$path = '' !== $src ? wp_parse_url( $src, PHP_URL_PATH ) : null;
			if ( $path && isset( $theme_paths[ $path ] ) ) {
				$handles[ (string) $handle ] = true;
			}
		}
		return $handles;
	}

	/**
	 * Every enforced Content-Security-Policy of this response: HTTP headers plus
	 * <meta http-equiv> tags already present in the buffered head.
	 *
	 * @param  string $html Buffered wp_head output.
	 * @return string[]
	 */
	private static function collect_csp_policies( $html ) {
		$policies = array();
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Content-Security-Policy:' ) ) {
					$policies[] = substr( $header, strlen( 'Content-Security-Policy:' ) );
				}
			}
		}
		if ( preg_match_all( '/<meta\b[^>]*http-equiv=[\'"]Content-Security-Policy[\'"][^>]*>/i', $html, $metas ) ) {
			foreach ( $metas[0] as $meta ) {
				if ( preg_match( '/\scontent=([\'"])(.*?)\1/is', $meta, $content_m ) ) {
					$policies[] = $content_m[2];
				}
			}
		}
		return $policies;
	}

	/**
	 * Whether any of the policies would block an inline event handler such as onload="".
	 *
	 * Blocked unless the governing directive (script-src-attr, else script-src,
	 * else default-src) allows 'unsafe-inline' without a nonce/hash/'strict-dynamic'
	 * (which make browsers ignore 'unsafe-inline'). No such directive = not blocked.
	 *
	 * @param  string[] $policies Policy strings.
	 * @return bool
	 */
	public static function csp_blocks_inline_handlers( $policies ) {
		foreach ( (array) $policies as $policy ) {
			$directives = array();
			foreach ( explode( ';', (string) $policy ) as $part ) {
				$tokens = preg_split( '/\s+/', trim( $part ) );
				if ( $tokens && '' !== $tokens[0] ) {
					$name = strtolower( array_shift( $tokens ) );
					if ( ! isset( $directives[ $name ] ) ) {
						$directives[ $name ] = array_map( 'strtolower', $tokens );
					}
				}
			}
			$governing = null;
			foreach ( array( 'script-src-attr', 'script-src', 'default-src' ) as $name ) {
				if ( isset( $directives[ $name ] ) ) {
					$governing = $directives[ $name ];
					break;
				}
			}
			if ( null === $governing ) {
				continue;
			}
			$value = ' ' . implode( ' ', $governing ) . ' ';
			if ( false === strpos( $value, "'unsafe-inline'" ) || preg_match( "/'nonce-|'sha(?:256|384|512)-|'strict-dynamic'/", $value ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Inline style minification
	// -------------------------------------------------------------------------

	/**
	 * Minify the content of every inline <style> block in the given HTML.
	 *
	 * @param  string $html Buffered HTML (wp_head output).
	 * @return string HTML with minified inline styles.
	 */
	public static function minify_inline_styles( $html ) {
		return preg_replace_callback(
			'/<style([^>]*)>(.*?)<\/style>/is',
			function ( $matches ) {
				$attrs = $matches[1];
				$css   = BEPLUSPB_Minify::minify_css( $matches[2] );
				return '<style' . $attrs . '>' . $css . '</style>';
			},
			$html
		);
	}
}
