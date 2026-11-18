<?php
/**
 * Lazy load images by rewriting the page HTML (opt-in "HTML" engine).
 *
 * The default engine ({@see BEPLUSPB_Images}) only adjusts what WordPress Core
 * generates. That misses images printed by page builders (Elementor), themes and
 * widgets, and browsers load natively lazy images from 1250-2500 px away, which
 * Lighthouse still counts as "offscreen images". This engine works on the final
 * HTML instead, like the "Lazy Load by WP Rocket" plugin:
 *
 *  - every eligible <img> (and <picture>) gets a tiny SVG placeholder, its real
 *    URL moves to data-bpb-src/-srcset/-sizes and a <noscript> copy is kept;
 *  - one small inline script (IntersectionObserver, 300 px margin) puts the
 *    attributes back when the image is about to be seen;
 *  - the first N eligible images are left alone (above the fold / LCP), and so
 *    is anything the author marked eager, high priority or no-lazy;
 *  - images inside a slider/carousel are released when the slider comes into view
 *    (the slides are clipped, so they would otherwise stay blank until they slide in);
 *  - iframes get the browser's native loading="lazy" (no JS needed).
 *  - opt-in: inline `background-image:url()` styles move to data-bpb-bg and are applied by the same loader.
 *
 * It fails open: scripts/styles/comments/noscript/templates are never touched,
 * partial or non-HTML output is returned as is, and when a Content-Security-Policy
 * would block the inline loader the page gets native loading="lazy" instead.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Output-buffer image lazy loader. */
class BEPLUSPB_Lazy_HTML {

	const ATTR_SRC    = 'data-bpb-src';
	const ATTR_SRCSET = 'data-bpb-srcset';
	const ATTR_SIZES  = 'data-bpb-sizes';
	const ATTR_BG     = 'data-bpb-bg';
	const MAX_PROBES  = 40;

	/**
	 * Register the buffer when this engine is selected.
	 *
	 * @param  array $opts Plugin options.
	 * @return bool True when the buffer will be opened.
	 */
	public static function init( $opts ) {
		if ( empty( $opts['lazy_load'] ) || ! isset( $opts['lazy_engine'] ) || 'html' !== $opts['lazy_engine'] ) {
			return false;
		}
		if ( is_admin() || is_feed() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return false;
		}
		if ( defined( 'DONOTLAZYLOAD' ) && DONOTLAZYLOAD ) {
			return false;
		}
		// Inside the CDN/HTML-minify buffers (priority 0) and outside Delay JS (last), so the
		// CDN also rewrites our data attributes and our loader is not itself delayed.
		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 2 );
		return true;
	}

	/** Open the output buffer. */
	public static function buffer_start() {
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return;
		}
		ob_start( array( __CLASS__, 'buffer_callback' ) );
	}

	/**
	 * Output-buffer callback. Only a complete response (one flush) is rewritten.
	 *
	 * @param  string   $html  Buffered output.
	 * @param  int|null $phase PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public static function buffer_callback( $html, $phase = null ) {
		if ( null !== $phase && ! ( ( $phase & PHP_OUTPUT_HANDLER_START ) && ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) ) {
			return $html;
		}
		try {
			$out = self::process( $html, bepluspb_get_options() );
		} catch ( \Throwable $e ) {
			return $html;
		}
		return BEPLUSPB_Utils::guard_transform( $out, $html );
	}

	/**
	 * Rewrite the images of a full HTML document.
	 *
	 * @param  string $html Document.
	 * @param  array  $opts Plugin options.
	 * @return string
	 */
	public static function process( $html, $opts ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( ( false === stripos( $html, '<img' ) && false === stripos( $html, '<iframe' ) && ( empty( $opts['lazy_html_bg'] ) || false === stripos( $html, 'background' ) ) ) || false === stripos( $html, '</body>' ) || ! preg_match( '/<html\b/i', $html ) ) {
			return $html;
		}
		if ( false !== strpos( $html, "\x1a" ) ) {
			return $html; // Our placeholder byte: never in real HTML, but be safe.
		}
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'html' ) ) {
					return $html;
				}
			}
		}

		$body_at = stripos( $html, '<body' );
		if ( false === $body_at ) {
			return $html;
		}
		$head = substr( $html, 0, $body_at );
		$rest = substr( $html, $body_at );

		// Hide everything an <img> look-alike could live in.
		$map  = array();
		$safe = preg_replace_callback(
			'#<(script|style|noscript|template|textarea)\b[^>]*+>.*?</\1\s*>|<!--.*?-->#is',
			function ( $m ) use ( &$map ) {
				$token         = "\x1aBPB" . count( $map ) . "\x1a";
				$map[ $token ] = $m[0];
				return $token;
			},
			$rest
		);
		if ( null === $safe ) {
			return $html;
		}

		// An attribute value that holds markup (data-content='<img src="...">', tooltips, JSON with HTML) is not
		// part of the page: converting an <img> inside it would break the attribute (our placeholder has quotes).
		$safe = preg_replace_callback(
			'#<(?!img\b|picture\b|iframe\b|source\b)[a-z][a-z0-9-]*+\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
			function ( $m ) use ( &$map ) {
				$original = strtr( $m[0], $map ); // A comment/script token inside the attribute is put back first: strtr() does not re-scan.
				if ( false === strpos( $original, '<', 1 ) ) {
					return $m[0];
				}
				$token         = "\x1aBPB" . count( $map ) . "\x1a";
				$map[ $token ] = $original;
				return $token;
			},
			$safe
		);
		if ( null === $safe ) {
			return $html;
		}

		$use_js = true;
		if ( class_exists( 'BEPLUSPB_CSS' ) && BEPLUSPB_CSS::csp_blocks_inline_handlers( BEPLUSPB_CSS::collect_csp_policies( $head ) ) ) {
			$use_js = false; // The inline loader would be blocked: native loading="lazy" instead.
		}

		$cfg   = array(
			'js'        => $use_js,
			'iframes'   => ! isset( $opts['lazy_html_iframes'] ) || ! empty( $opts['lazy_html_iframes'] ),
			'bg'        => ! empty( $opts['lazy_html_bg'] ),
			'threshold' => isset( $opts['lazy_html_threshold'] ) ? max( 0, min( 2000, (int) $opts['lazy_html_threshold'] ) ) : 300,
			'skip'      => isset( $opts['lazy_html_skip_first'] ) ? max( 0, min( 10, (int) $opts['lazy_html_skip_first'] ) ) : 2,
			'ex_class'  => self::parse_list( isset( $opts['lazy_exclude_class'] ) ? $opts['lazy_exclude_class'] : '' ),
			'ex_id'     => self::parse_list( isset( $opts['lazy_exclude_id'] ) ? $opts['lazy_exclude_id'] : '' ),
			'ex_file'   => self::parse_list( isset( $opts['lazy_exclude_filename'] ) ? $opts['lazy_exclude_filename'] : '' ),
		);
		$state = array(
			'seen'      => 0,
			'converted' => 0,
		);

		$safe = preg_replace_callback(
			'#<picture\b[^>]*+>.*?</picture\s*>|<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#is',
			function ( $m ) use ( &$state, $cfg ) {
				if ( 0 === stripos( $m[0], '<picture' ) ) {
					return self::convert_picture( $m[0], $state, $cfg );
				}
				return self::convert_img( $m[0], $state, $cfg );
			},
			$safe
		);
		if ( null === $safe ) {
			return $html;
		}

		if ( $cfg['iframes'] ) {
			$with_frames = preg_replace_callback(
				'#<iframe\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
				function ( $m ) use ( $cfg ) {
					return self::convert_iframe( $m[0], $cfg );
				},
				$safe
			);
			if ( null !== $with_frames ) {
				$safe = $with_frames;
			}
		}

		$bg_urls = array();
		if ( $cfg['bg'] && $use_js ) {
			$with_bg = preg_replace_callback(
				'#<(?!img\b|picture\b|iframe\b|body\b|html\b)[a-z][a-z0-9-]*+\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
				function ( $m ) use ( &$state, &$bg_urls, $cfg ) {
					return false === stripos( $m[0], 'url(' ) ? $m[0] : self::convert_background( $m[0], $state, $bg_urls, $cfg );
				},
				$safe
			);
			if ( null !== $with_bg ) {
				$safe = $with_bg;
			}
		}

		$rest = strtr( $safe, $map );

		if ( $use_js && ( $state['converted'] > 0 || $bg_urls ) ) {
			$end = strripos( $rest, '</body>' );
			if ( false !== $end ) {
				$rest = substr( $rest, 0, $end ) . self::loader_script( $cfg['threshold'], (bool) $bg_urls ) . substr( $rest, $end );
			}
			$close = stripos( $head, '</head>' );
			if ( false !== $close ) {
				$nojs = '';
				if ( $state['converted'] > 0 ) {
					$nojs .= 'img[' . self::ATTR_SRC . ']{display:none!important}';
				}
				foreach ( array_keys( $bg_urls ) as $url ) { // Without JavaScript the backgrounds are put back by CSS.
					$nojs .= '[' . self::ATTR_BG . '="' . $url . '"]{background-image:url("' . $url . '")}';
				}
				$head = substr( $head, 0, $close ) . '<noscript><style id="bepluspb-lazy-nojs">' . $nojs . '</style></noscript>' . substr( $head, $close );
			}
		}

		return $head . $rest;
	}

	/**
	 * Convert a <picture>: its <img> decides, its <source>s follow.
	 *
	 * @param  string $picture <picture>…</picture>.
	 * @param  array  $state   Counters (by reference).
	 * @param  array  $cfg     Settings.
	 * @return string
	 */
	private static function convert_picture( $picture, &$state, $cfg ) {
		if ( ! preg_match( '#<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i', $picture, $img ) ) {
			return $picture;
		}
		$new = self::convert_img( $img[0], $state, $cfg );
		if ( $new === $img[0] ) {
			return $picture;
		}
		if ( $cfg['js'] ) {
			$picture = preg_replace_callback(
				'#<source\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
				function ( $m ) {
					$attrs = self::parse_attrs( $m[0], 7 );
					$edits = array();
					foreach ( array(
						'srcset' => self::ATTR_SRCSET,
						'sizes'  => self::ATTR_SIZES,
					) as $from => $to ) {
						if ( isset( $attrs[ $from ] ) ) {
							$edits[] = array( $attrs[ $from ]['pos'], strlen( $attrs[ $from ]['raw'] ), ' ' . $to . '=' . self::quoted( $attrs[ $from ]['value'] ) );
						}
					}
					return self::apply_edits( $m[0], $edits );
				},
				$picture
			);
			if ( null === $picture ) {
				return $img[0];
			}
		}
		$pos = strpos( $picture, $img[0] );
		if ( false === $pos ) {
			return $picture;
		}
		return substr( $picture, 0, $pos ) . $new . substr( $picture, $pos + strlen( $img[0] ) );
	}

	/**
	 * Convert one <img> (or leave it alone).
	 *
	 * @param  string $tag   The <img …> tag.
	 * @param  array  $state Counters (by reference).
	 * @param  array  $cfg   Settings.
	 * @return string
	 */
	private static function convert_img( $tag, &$state, $cfg ) {
		$attrs = self::parse_attrs( $tag, 4 );
		if ( ! isset( $attrs['src'] ) ) {
			return $tag;
		}
		$src = trim( $attrs['src']['value'] );
		if ( '' === $src || preg_match( '#^(?:data|blob|about|javascript):#i', $src ) ) {
			return $tag;
		}
		if ( self::is_skipped( $attrs, $src, $cfg ) ) {
			return $tag;
		}
		/**
		 * Filters whether an <img> is left out of lazy loading by the HTML engine.
		 *
		 * @param bool   $skip  Whether to skip (false by default).
		 * @param array  $attrs Lower-case attribute name => raw value.
		 * @param string $tag   The tag.
		 */
		if ( apply_filters( 'bepluspb_lazy_html_skip_image', false, self::values( $attrs ), $tag ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
			return $tag;
		}

		++$state['seen'];
		if ( $state['seen'] <= $cfg['skip'] ) {
			return $tag; // Above the fold: keep eager.
		}

		$edits = array();
		if ( isset( $attrs['loading'] ) ) {
			$mode = strtolower( trim( $attrs['loading']['value'] ) );
			if ( ! $cfg['js'] ) {
				return $tag; // Already native: nothing to add.
			}
			if ( 'lazy' === $mode || 'auto' === $mode ) {
				$edits[] = array( $attrs['loading']['pos'], strlen( $attrs['loading']['raw'] ), '' );
			}
		}

		if ( ! $cfg['js'] ) {
			return '<img loading="lazy"' . substr( $tag, 4 );
		}

		$w = 0;
		$h = 0;
		if ( isset( $attrs['width'] ) && preg_match( '/^\s*(\d{1,5})\s*$/', $attrs['width']['value'], $mw ) ) {
			$w = (int) $mw[1];
		}
		if ( isset( $attrs['height'] ) && preg_match( '/^\s*(\d{1,5})\s*$/', $attrs['height']['value'], $mh ) ) {
			$h = (int) $mh[1];
		}
		if ( $w < 1 || $h < 1 ) {
			// No usable width/height: reserve the image's real proportions instead, so the page does not jump
			// when the picture replaces the placeholder. Nothing is added to the <img> itself (a theme without
			// "height:auto" would stretch it).
			$known = self::intrinsic_size( $src );
			if ( $known ) {
				list( $w, $h ) = $known;
			}
		}
		$placeholder = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 " . $w . ' ' . $h . "'%3E%3C/svg%3E";

		$edits[] = array( $attrs['src']['pos'], strlen( $attrs['src']['raw'] ), ' src="' . $placeholder . '"' );
		foreach ( array(
			'srcset' => self::ATTR_SRCSET,
			'sizes'  => self::ATTR_SIZES,
		) as $from => $to ) {
			if ( isset( $attrs[ $from ] ) ) {
				$edits[] = array( $attrs[ $from ]['pos'], strlen( $attrs[ $from ]['raw'] ), ' ' . $to . '=' . self::quoted( $attrs[ $from ]['value'] ) );
			}
		}
		$new = self::apply_edits( $tag, $edits );
		$new = '<img ' . self::ATTR_SRC . '=' . self::quoted( $attrs['src']['value'] ) . substr( $new, 4 );

		++$state['converted'];
		return $new . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Move an inline `background-image:url(...)` to data-bpb-bg so the loader applies it near the viewport.
	 *
	 * Only a declaration that is exactly one plain url() is handled (not the `background` shorthand, not
	 * gradients or several layers, not !important), and only for URLs made of safe characters, so the value can
	 * be put back through the CSSOM and repeated in the no-JavaScript style rule without escaping.
	 *
	 * @param  string $tag     Opening tag.
	 * @param  array  $state   Counters (by reference).
	 * @param  array  $bg_urls Decoded URL => true, for the no-JavaScript rules (by reference).
	 * @param  array  $cfg     Settings.
	 * @return string
	 */
	private static function convert_background( $tag, &$state, &$bg_urls, $cfg ) {
		$attrs = self::parse_attrs( $tag, 1 + strcspn( $tag, " \t\r\n>/", 1 ) );
		if ( ! isset( $attrs['style'] ) || isset( $attrs[ self::ATTR_BG ] ) ) {
			return $tag;
		}
		$style = $attrs['style']['value'];
		if ( ! preg_match( '/(?:^|;)\s*background-image\s*:\s*url\(\s*(?:&quot;|&#0?34;|"|\'|)([^\s"\'()\\<>&;]*+(?:&amp;[^\s"\'()\\<>&;]*+)*+)(?:&quot;|&#0?34;|"|\'|)\s*\)\s*(?=;|$)/i', $style, $m, PREG_OFFSET_CAPTURE ) ) {
			return $tag;
		}
		$raw = $m[1][0];
		$url = str_replace( '&amp;', '&', $raw );
		if ( '' === $url || ! preg_match( '#^[A-Za-z0-9\-._~:/?\#\[\]@!$&*+,;=%]+$#D', $url ) || preg_match( '#^(?:data|blob|about|javascript):#i', $url ) ) {
			return $tag;
		}
		if ( self::is_skipped( $attrs, $url, $cfg ) || apply_filters( 'bepluspb_lazy_html_skip_background', false, self::values( $attrs ), $tag ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
			return $tag;
		}
		if ( ! isset( $state['bg_seen'] ) ) {
			$state['bg_seen'] = 0;
		}
		++$state['bg_seen'];
		if ( $state['bg_seen'] <= $cfg['skip'] ) {
			return $tag; // Above the fold: keep eager.
		}

		// Drop the declaration (and its separator) from the style attribute.
		$whole = $m[0][0];
		$rest  = str_replace( $whole, '', $style );
		$rest  = trim( ltrim( $rest, "; \t\r\n" ) );
		$edits = array();
		if ( '' === $rest ) {
			$edits[] = array( $attrs['style']['pos'], strlen( $attrs['style']['raw'] ), '' );
		} else {
			$edits[] = array( $attrs['style']['pos'], strlen( $attrs['style']['raw'] ), ' style=' . self::quoted( $rest ) );
		}
		$new = self::apply_edits( $tag, $edits );
		$new = '/>' === substr( $new, -2 ) ? substr( $new, 0, -2 ) . ' ' . self::ATTR_BG . '="' . $raw . '"/>' : substr( $new, 0, -1 ) . ' ' . self::ATTR_BG . '="' . $raw . '">';

		$bg_urls[ $url ] = true;
		return $new;
	}

	/**
	 * Width and height of a local image, without touching the database: from the `-300x200` suffix WordPress puts
	 * on generated sizes, or else from the file header (getimagesize reads a few bytes).
	 *
	 * The URL is mapped to a file only inside the uploads folder, through realpath() and a prefix check
	 * (Hard Rule #3), with an image-extension allowlist, and at most MAX_PROBES files are opened per page.
	 *
	 * @param  string $url Image URL as printed (HTML-escaped).
	 * @return int[]|null array( width, height ) or null.
	 */
	private static function intrinsic_size( $url ) {
		static $cache  = array();
		static $probes = 0;

		$url = html_entity_decode( $url, ENT_QUOTES );
		if ( isset( $cache[ $url ] ) || array_key_exists( $url, $cache ) ) {
			return $cache[ $url ];
		}
		$size = null;
		$path = (string) self::url_part( $url, PHP_URL_PATH );
		if ( preg_match( '#-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp|avif)$#i', $path, $m ) ) {
			$size = array( (int) $m[1], (int) $m[2] );
		} elseif ( $probes < self::MAX_PROBES && preg_match( '#\.(?:jpe?g|png|gif|webp|avif)$#i', $path ) ) {
			$file = self::upload_file_for( $url );
			if ( null !== $file ) {
				++$probes;
				$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable/corrupt files simply give no size.
				if ( is_array( $info ) && $info[0] > 0 && $info[1] > 0 ) {
					$size = array( (int) $info[0], (int) $info[1] );
				}
			}
		}
		/**
		 * Filters the proportions used for an image that has no width/height attributes.
		 *
		 * @param int[]|null $size array( width, height ) or null.
		 * @param string     $url  Image URL.
		 */
		$size = apply_filters( 'bepluspb_lazy_html_image_size', $size, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		if ( ! is_array( $size ) || count( $size ) < 2 || (int) $size[0] < 1 || (int) $size[1] < 1 || (int) $size[0] > 20000 || (int) $size[1] > 20000 ) {
			$size = null;
		} else {
			$size = array( (int) $size[0], (int) $size[1] );
		}
		$cache[ $url ] = $size;
		return $size;
	}

	/**
	 * Active plugins that lazy load images themselves: running two loaders on the same image wastes work and
	 * can leave it blank. The engine already leaves alone images other loaders have marked (data-src, data-lazy-src...),
	 * so this only informs the administrator.
	 *
	 * @return string[] Names.
	 */
	public static function conflicting_plugins() {
		$known = array(
			'rocket-lazy-load/rocket-lazy-load.php' => 'Lazy Load by WP Rocket',
			'a3-lazy-load/a3-lazy-load.php'         => 'a3 Lazy Load',
			'bj-lazy-load/bj-lazy-load.php'         => 'BJ Lazy Load',
			'lazy-load/lazy-load.php'               => 'Lazy Load',
			'jquery-image-lazy-loading/jquery_image_lazy_loading.php' => 'jQuery Image Lazy Load',
		);
		$names = array();
		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( $known as $file => $name ) {
				if ( is_plugin_active( $file ) ) {
					$names[] = $name;
				}
			}
		}
		if ( function_exists( 'get_rocket_option' ) && get_rocket_option( 'lazyload' ) ) {
			$names[] = 'WP Rocket (Lazyload for images)';
		}
		/**
		 * Filters the names of other plugins that lazy load images (shown in a notice next to the Lazy Load Method).
		 *
		 * @param string[] $names Plugin names.
		 */
		return array_values( array_filter( (array) apply_filters( 'bepluspb_lazy_conflicting_plugins', $names ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
	}

	/**
	 * One component of a URL (wp_parse_url() when WordPress is loaded).
	 *
	 * @param  string $url       URL.
	 * @param  int    $component PHP_URL_* constant.
	 * @return string|null
	 */
	private static function url_part( $url, $component ) {
		$part = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, $component ) : parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- only used outside WordPress (tests).
		return is_string( $part ) ? $part : null;
	}

	/**
	 * The file behind an uploads URL, or null. Never leaves the uploads folder.
	 *
	 * @param  string $url Image URL.
	 * @return string|null
	 */
	private static function upload_file_for( $url ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$dirs = wp_upload_dir( null, false );
		if ( ! is_array( $dirs ) || empty( $dirs['baseurl'] ) || empty( $dirs['basedir'] ) ) {
			return null;
		}
		$base_host = (string) self::url_part( $dirs['baseurl'], PHP_URL_HOST );
		$base_path = rtrim( (string) self::url_part( $dirs['baseurl'], PHP_URL_PATH ), '/' ) . '/';
		$host      = self::url_part( $url, PHP_URL_HOST );
		$path      = (string) self::url_part( $url, PHP_URL_PATH );
		if ( null !== $host && 0 !== strcasecmp( (string) $host, $base_host ) ) {
			return null; // Another host (a CDN or a third party): not our file.
		}
		if ( 0 !== strpos( $path, $base_path ) ) {
			return null;
		}
		$relative = rawurldecode( substr( $path, strlen( $base_path ) ) );
		if ( '' === $relative || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '\\' ) || preg_match( '#(?:^|/)\.\.?(?:/|$)#', $relative ) ) {
			return null;
		}
		$root = realpath( $dirs['basedir'] );
		$file = realpath( $root . '/' . $relative );
		if ( false === $root || false === $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		return $file;
	}

	/**
	 * Give an <iframe> the browser's native lazy loading (embeds: YouTube, Maps, forms...).
	 *
	 * Left alone: iframes that already say how to load, other lazy loaders' markers, hidden or tracking
	 * frames, srcdoc/data/blank frames, and the usual exclusions.
	 *
	 * @param  string $tag <iframe ...> tag.
	 * @param  array  $cfg Settings.
	 * @return string
	 */
	private static function convert_iframe( $tag, $cfg ) {
		$attrs = self::parse_attrs( $tag, 7 );
		if ( ! isset( $attrs['src'] ) || isset( $attrs['loading'] ) || isset( $attrs['srcdoc'] ) ) {
			return $tag;
		}
		$src = trim( $attrs['src']['value'] );
		if ( '' === $src || preg_match( '#^(?:data|blob|about|javascript):#i', $src ) ) {
			return $tag;
		}
		foreach ( array( 'data-src', 'data-lazy-src', 'data-lazyload', 'data-rocket-lazyload', 'data-no-lazy', 'data-skip-lazy' ) as $name ) {
			if ( isset( $attrs[ $name ] ) ) {
				return $tag;
			}
		}
		foreach ( array( 'width', 'height' ) as $dim ) {
			if ( isset( $attrs[ $dim ] ) && 1 === preg_match( '/^\s*[01](?:px)?\s*$/', $attrs[ $dim ]['value'] ) ) {
				return $tag; // 0x0 / 1x1 tracking frames.
			}
		}
		if ( isset( $attrs['style'] ) && 1 === preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', $attrs['style']['value'] ) ) {
			return $tag;
		}
		if ( 1 === preg_match( '#gform_ajax_frame|recaptcha/api/fallback|google_ads_iframe_|googletagmanager\.com/ns\.html|facebook\.com/tr#i', $tag ) ) {
			return $tag;
		}
		$class_attr = isset( $attrs['class'] ) ? $attrs['class']['value'] : '';
		$classes    = preg_split( '/\s+/', trim( $class_attr ) );
		if ( in_array( 'skip-lazy', $classes, true ) || in_array( 'no-lazy', $classes, true ) || array_intersect( $cfg['ex_class'], $classes ) ) {
			return $tag;
		}
		if ( isset( $attrs['id'] ) && in_array( trim( $attrs['id']['value'] ), $cfg['ex_id'], true ) ) {
			return $tag;
		}
		foreach ( $cfg['ex_file'] as $part ) {
			if ( '' !== $part && false !== strpos( $src, $part ) ) {
				return $tag;
			}
		}
		return '<iframe loading="lazy"' . substr( $tag, 7 );
	}

	/**
	 * Built-in and user exclusions.
	 *
	 * @param  array  $attrs Parsed attributes.
	 * @param  string $src   Source URL (raw).
	 * @param  array  $cfg   Settings.
	 * @return bool
	 */
	private static function is_skipped( $attrs, $src, $cfg ) {
		$blocking_attrs = array(
			'data-src',
			'data-srcset',
			'data-lazy-src',
			'data-lazy-srcset',
			'data-lazysrc',
			'data-lazyload',
			'data-lazy-original',
			'data-original',
			self::ATTR_SRC,
			'data-no-lazy',
			'data-skip-lazy',
			'data-bgposition',
			'data-envira-src',
			'fullurl',
			'lazy-slider-img',
			'data-height-percentage',
			'data-large_image',
		);
		foreach ( $blocking_attrs as $name ) {
			if ( isset( $attrs[ $name ] ) ) {
				return true;
			}
		}
		if ( isset( $attrs['loading'] ) && 'eager' === strtolower( trim( $attrs['loading']['value'] ) ) ) {
			return true;
		}
		if ( isset( $attrs['fetchpriority'] ) && 'high' === strtolower( trim( $attrs['fetchpriority']['value'] ) ) ) {
			return true;
		}

		$class_attr = isset( $attrs['class'] ) ? $attrs['class']['value'] : '';
		$classes    = preg_split( '/\s+/', trim( $class_attr ) );
		$builtin    = array( 'skip-lazy', 'no-lazy', 'ls-l', 'ls-bg', 'soliloquy-image', 'swatch-img', 'avia-bg-style-fixed', 'wp-smiley', 'emoji' );
		foreach ( $builtin as $class ) {
			if ( in_array( $class, $classes, true ) ) {
				return true;
			}
		}
		if ( false !== strpos( $class_attr, 'image-compare__' ) ) {
			return true;
		}
		foreach ( $cfg['ex_class'] as $class ) {
			if ( in_array( $class, $classes, true ) ) {
				return true;
			}
		}
		if ( isset( $attrs['id'] ) && in_array( trim( $attrs['id']['value'] ), $cfg['ex_id'], true ) ) {
			return true;
		}
		$files = array_merge( $cfg['ex_file'], array( '/wpcf7_captcha/', 'timthumb.php?src', 'woocommerce/assets/images/placeholder.png' ) );
		foreach ( $files as $part ) {
			if ( '' !== $part && false !== strpos( $src, $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Attributes of a tag, in document order: lower-case name => position, raw text, value.
	 * The first occurrence of a name wins (as in browsers).
	 *
	 * @param  string $tag   Tag text.
	 * @param  int    $start Offset after the tag name.
	 * @return array
	 */
	private static function parse_attrs( $tag, $start ) {
		$out = array();
		if ( ! preg_match_all( '#\s([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $tag, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, $start ) ) {
			return $out;
		}
		foreach ( $m as $a ) {
			$name = strtolower( $a[1][0] );
			if ( isset( $out[ $name ] ) ) {
				continue;
			}
			$value = '';
			foreach ( array( 2, 3, 4 ) as $g ) {
				if ( isset( $a[ $g ] ) && $a[ $g ][1] >= 0 ) {
					$value = $a[ $g ][0];
					break;
				}
			}
			$out[ $name ] = array(
				'pos'   => $a[0][1],
				'raw'   => $a[0][0],
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * Lower-case attribute name => raw value, for the public filter.
	 *
	 * @param  array $attrs Parsed attributes.
	 * @return array
	 */
	private static function values( $attrs ) {
		$out = array();
		foreach ( $attrs as $name => $a ) {
			$out[ $name ] = $a['value'];
		}
		return $out;
	}

	/**
	 * Replace/remove parts of a tag.
	 *
	 * @param  string $tag   Tag text.
	 * @param  array  $edits List of array( offset, length, replacement ).
	 * @return string
	 */
	private static function apply_edits( $tag, $edits ) {
		usort(
			$edits,
			function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $edits as $edit ) {
			$tag = substr( $tag, 0, $edit[0] ) . $edit[2] . substr( $tag, $edit[0] + $edit[1] );
		}
		return $tag;
	}

	/**
	 * A raw attribute value wrapped in double quotes.
	 *
	 * @param  string $value Raw value (already HTML-escaped by the source).
	 * @return string
	 */
	private static function quoted( $value ) {
		return '"' . str_replace( '"', '&quot;', $value ) . '"';
	}

	/**
	 * Comma-separated list.
	 *
	 * @param  string $value Raw list.
	 * @return string[]
	 */
	private static function parse_list( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	/**
	 * The inline loader: IntersectionObserver with a configurable margin (doubled on slow connections).
	 *
	 * Images inside a slider/carousel are released when the SLIDER comes into view: the slides outside
	 * its clip never intersect on their own, so they would stay blank until they slide in.
	 * The attributes keep it out of other plugins' delay/defer/minify handling.
	 *
	 * @param  int  $threshold Margin in pixels.
	 * @param  bool $bg        Also handle data-bpb-bg (inline background images).
	 * @return string
	 */
	private static function loader_script( $threshold = 300, $bg = false ) {
		// Selector of everything the loader releases: images, plus (opt-in) elements with a deferred background.
		$sel = $bg ? "'img['+S+'],['+B+']'" : "'img['+S+']'";
		$js  = "(function(w,d){var S='data-bpb-src',Q='data-bpb-srcset',Z='data-bpb-sizes'," . ( $bg ? "B='data-bpb-bg'," : '' ) . 'T=' . (int) $threshold . ','
			. "H='.swiper,.swiper-container,.slick-slider,.owl-carousel,.flickity-viewport,.splide,.tns-slider,.glide,.elementor-image-carousel-wrapper',io,t;"
			. 'function mv(e,a,b){var v=e.getAttribute(a);if(v!==null){e.removeAttribute(a);e.setAttribute(b,v);}}'
			// Like other lazy loaders: class "lazyloaded" and a bubbling "lazyloaded" event (plus our own name) once the picture is there.
			. "function ev(t,n){try{t.dispatchEvent(new Event(n,{bubbles:true}));}catch(x){}}function ok(t){if(t.classList)t.classList.add('lazyloaded');ev(t,'lazyloaded');ev(t,'bepluspb-lazyloaded');}"
			. "function fin(e){var t=e.target;t.removeEventListener('load',fin);ok(t);}"
			. ( $bg ? "function bg(e){var v=e.getAttribute(B);e.removeAttribute(B);if(v){e.style.backgroundImage='url('+JSON.stringify(v)+')';ok(e);}}" : '' )
			. 'function go(i){' . ( $bg ? 'if(i.hasAttribute(B)){bg(i);return;}' : '' ) . 'if(!i.hasAttribute(S))return;i.addEventListener(\'load\',fin);var p=i.parentNode;'
			. "if(p&&p.nodeName==='PICTURE'){var c=p.getElementsByTagName('source');for(var k=0;k<c.length;k++){mv(c[k],Q,'srcset');mv(c[k],Z,'sizes');}}"
			. "mv(i,Z,'sizes');mv(i,Q,'srcset');mv(i,S,'src');}"
			. 'function all(){var n=d.querySelectorAll(' . $sel . ');for(var k=0;k<n.length;k++)go(n[k]);}'
			. 'function run(e){if(e.hasAttribute(S)' . ( $bg ? '||e.hasAttribute(B)' : '' ) . '){go(e);return;}var n=e.querySelectorAll(' . $sel . ');for(var k=0;k<n.length;k++)go(n[k]);}'
			. 'function scan(){var n=d.querySelectorAll(' . $sel . ");if(!('IntersectionObserver' in w)){all();return;}"
			. "if(!io){var c=w.navigator&&w.navigator.connection,m=T*(c&&/(^|-)[23]g\$/.test(c.effectiveType||'')?2:1);"
			. "io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting||e.intersectionRatio>0){io.unobserve(e.target);run(e.target);}});},{rootMargin:m+'px 0px'});}"
			. 'for(var k=0;k<n.length;k++){var h=n[k].closest?n[k].closest(H):null;io.observe(h||n[k]);}}'
			. "scan();if('MutationObserver' in w)new MutationObserver(function(){clearTimeout(t);t=setTimeout(scan,100);}).observe(d.documentElement,{childList:true,subtree:true});"
			. "w.addEventListener('beforeprint',all);w.bepluspbLazy={scan:scan,all:all};})(window,document);";

		return '<script id="bepluspb-lazy-loader" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-wpmeteor-nooptimize="true" data-pagespeed-no-defer>' . $js . '</script>';
	}
}
