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
 *    is anything the author marked eager, high priority or no-lazy.
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
		if ( false === stripos( $html, '<img' ) || false === stripos( $html, '</body>' ) || ! preg_match( '/<html\b/i', $html ) ) {
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

		$use_js = true;
		if ( class_exists( 'BEPLUSPB_CSS' ) && BEPLUSPB_CSS::csp_blocks_inline_handlers( BEPLUSPB_CSS::collect_csp_policies( $head ) ) ) {
			$use_js = false; // The inline loader would be blocked: native loading="lazy" instead.
		}

		$cfg   = array(
			'js'         => $use_js,
			'skip'       => isset( $opts['lazy_html_skip_first'] ) ? max( 0, min( 10, (int) $opts['lazy_html_skip_first'] ) ) : 2,
			'ex_class'   => self::parse_list( isset( $opts['lazy_exclude_class'] ) ? $opts['lazy_exclude_class'] : '' ),
			'ex_id'      => self::parse_list( isset( $opts['lazy_exclude_id'] ) ? $opts['lazy_exclude_id'] : '' ),
			'ex_file'    => self::parse_list( isset( $opts['lazy_exclude_filename'] ) ? $opts['lazy_exclude_filename'] : '' ),
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

		$rest = strtr( $safe, $map );

		if ( $use_js && $state['converted'] > 0 ) {
			$end = strripos( $rest, '</body>' );
			if ( false !== $end ) {
				$rest = substr( $rest, 0, $end ) . self::loader_script() . substr( $rest, $end );
			}
			$close = stripos( $head, '</head>' );
			if ( false !== $close ) {
				$head = substr( $head, 0, $close ) . '<noscript><style id="bepluspb-lazy-nojs">img[' . self::ATTR_SRC . ']{display:none!important}</style></noscript>' . substr( $head, $close );
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
		$placeholder = "data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%20" . $w . '%20' . $h . "'%3E%3C/svg%3E";

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
	 * The inline loader: IntersectionObserver with a 300 px margin.
	 *
	 * The attributes keep it out of other plugins' delay/defer/minify handling.
	 *
	 * @return string
	 */
	private static function loader_script() {
		$js = "(function(w,d){var S='data-bpb-src',Q='data-bpb-srcset',Z='data-bpb-sizes',io,t;"
			. 'function mv(e,a,b){var v=e.getAttribute(a);if(v!==null){e.removeAttribute(a);e.setAttribute(b,v);}}'
			. 'function go(i){if(!i.hasAttribute(S))return;var p=i.parentNode;'
			. "if(p&&p.nodeName==='PICTURE'){var c=p.getElementsByTagName('source');for(var k=0;k<c.length;k++){mv(c[k],Q,'srcset');mv(c[k],Z,'sizes');}}"
			. "mv(i,Z,'sizes');mv(i,Q,'srcset');mv(i,S,'src');}"
			. "function all(){var n=d.querySelectorAll('img['+S+']');for(var k=0;k<n.length;k++)go(n[k]);}"
			. "function scan(){var n=d.querySelectorAll('img['+S+']');if(!('IntersectionObserver' in w)){all();return;}"
			. 'if(!io)io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting||e.intersectionRatio>0){io.unobserve(e.target);go(e.target);}});},{rootMargin:\'300px 0px\'});'
			. 'for(var k=0;k<n.length;k++)io.observe(n[k]);}'
			. "scan();if('MutationObserver' in w)new MutationObserver(function(){clearTimeout(t);t=setTimeout(scan,100);}).observe(d.documentElement,{childList:true,subtree:true});"
			. "w.addEventListener('beforeprint',all);w.bepluspbLazy={scan:scan,all:all};})(window,document);";

		return '<script id="bepluspb-lazy-loader" data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-cfasync="false" data-wpmeteor-nooptimize="true" data-pagespeed-no-defer>' . $js . '</script>';
	}
}
