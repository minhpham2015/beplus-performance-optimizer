<?php
/**
 * Standalone test of the opt-in HTML lazy-load engine.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
$GLOBALS['hooks'] = array(); $GLOBALS['ctx'] = array(); $GLOBALS['skip_filter'] = null;
function add_action( $h, $cb = null, $p = 10 ) { $GLOBALS['hooks'][ $h ][] = array( $cb, $p ); }
function apply_filters( $tag, $value ) { if ( 'bepluspb_lazy_html_skip_image' === $tag && is_callable( $GLOBALS['skip_filter'] ) ) { return call_user_func( $GLOBALS['skip_filter'], $value, func_get_arg( 2 ), func_get_arg( 3 ) ); } return $value; }
function is_admin() { return ! empty( $GLOBALS['ctx']['admin'] ); }
function is_feed() { return ! empty( $GLOBALS['ctx']['feed'] ); }
function wp_doing_ajax() { return ! empty( $GLOBALS['ctx']['ajax'] ); }
function wp_is_json_request() { return ! empty( $GLOBALS['ctx']['rest'] ); }
function is_customize_preview() { return false; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-lazy-html.php';

$base = array( 'lazy_load' => 1, 'lazy_engine' => 'html', 'lazy_html_skip_first' => 2, 'lazy_exclude_class' => 'hero-image', 'lazy_exclude_id' => 'site-logo', 'lazy_exclude_filename' => 'banner.jpg' );
$page = function ( $body, $head = '' ) { return "<!doctype html><html><head><meta charset=\"utf-8\">$head<title>t</title></head><body>$body</body></html>"; };
$n = 0; $t = function ( $c, $m ) use ( &$n ) { ++$n; ok( $c, $m ); };
$count = function ( $needle, $hay ) { return substr_count( $hay, $needle ); };

// ---- registration ---------------------------------------------------------------------------
$t( true === BEPLUSPB_Lazy_HTML::init( $base ) && 2 === $GLOBALS['hooks']['template_redirect'][0][1], 'html engine opens its buffer at template_redirect 2' );
foreach ( array( array( 'lazy_load' => 0 ), array( 'lazy_engine' => 'core' ) ) as $over ) { $GLOBALS['hooks'] = array(); $t( false === BEPLUSPB_Lazy_HTML::init( array_merge( $base, $over ) ) && array() === $GLOBALS['hooks'], 'inactive for ' . key( $over ) ); }
foreach ( array( 'admin', 'feed', 'ajax', 'rest' ) as $gate ) { $GLOBALS['hooks'] = array(); $GLOBALS['ctx'] = array( $gate => 1 ); $t( false === BEPLUSPB_Lazy_HTML::init( $base ) && array() === $GLOBALS['hooks'], "request gate $gate" ); }
$GLOBALS['ctx'] = array();

// ---- plain conversion --------------------------------------------------------------------------
$imgs = '<img src="/a.jpg" width="10" height="10"><img src="/b.jpg"><img src="/c.jpg" width="640" height="480" srcset="/c-300.jpg 300w, /c.jpg 640w" sizes="(max-width: 640px) 100vw, 640px" alt="C &amp; D" class="wp-image-3" loading="lazy" decoding="async"><img src=\'/d.jpg\' alt=\'x\'/>';
$out  = BEPLUSPB_Lazy_HTML::process( $page( $imgs ), $base );
$t( false !== strpos( $out, '<img src="/a.jpg" width="10" height="10">' ) && false !== strpos( $out, '<img src="/b.jpg">' ), 'the first two images stay eager' );
$t( 1 === preg_match( '#<img data-bpb-src="/c\.jpg" src="data:image/svg\+xml,%3Csvg xmlns=\'http://www\.w3\.org/2000/svg\' viewBox=\'0 0 640 480\'%3E%3C/svg%3E" width="640" height="480" data-bpb-srcset="/c-300\.jpg 300w, /c\.jpg 640w" data-bpb-sizes="\(max-width: 640px\) 100vw, 640px" alt="C &amp; D" class="wp-image-3" decoding="async">#', $out ), 'img 3: placeholder with its ratio, real src/srcset/sizes moved, loading=lazy removed, other attributes kept' );
$t( false !== strpos( $out, '<noscript><img src="/c.jpg" width="640" height="480" srcset="/c-300.jpg 300w, /c.jpg 640w" sizes="(max-width: 640px) 100vw, 640px" alt="C &amp; D" class="wp-image-3" loading="lazy" decoding="async"></noscript>' ), 'the original tag is kept in <noscript>' );
$t( false !== strpos( $out, '<img data-bpb-src="/d.jpg" src="data:image/svg+xml,' ) && false !== strpos( $out, " alt='x'/>" ), 'single-quoted attributes and a self-closing slash survive' );
$t( 2 === $count( 'data-bpb-src="', $out ), 'only the two images after the skipped ones are converted' );
$t( 1 === $count( 'id="bepluspb-lazy-loader"', $out ) && 1 === $count( 'id="bepluspb-lazy-nojs"', $out ), 'one loader script and one no-JS rule' );
$t( strpos( $out, 'bepluspb-lazy-nojs' ) < strpos( $out, '</head>' ) && strpos( $out, 'bepluspb-lazy-loader' ) > strpos( $out, '</noscript>' ) && strrpos( $out, 'bepluspb-lazy-loader' ) < strrpos( $out, '</body>' ), 'no-JS rule ends the head, loader sits before </body>' );

// ---- skipping ----------------------------------------------------------------------------------
$skip = '<img src="/1.jpg"><img src="/2.jpg">'
	. '<img src="/eager.jpg" loading="eager"><img src="/hi.jpg" fetchpriority="high"><img src="/nl.jpg" data-no-lazy="1"><img src="/sk.jpg" class="foo skip-lazy">'
	. '<img src="/hero.jpg" class="a hero-image"><img src="/logo.jpg" id="site-logo"><img src="/img/banner.jpg?v=2"><img src="data:image/gif;base64,R0lGOD"><img alt="no src"><img src="">'
	. '<img src="/s.jpg" data-src="/real.jpg"><img src="/l.jpg" data-lazy-src="/real.jpg"><img src="/smile.png" class="wp-smiley"><img src="/x/wpcf7_captcha/1.png">'
	. '<img src="/ok.jpg" alt="a > b">';
$out  = BEPLUSPB_Lazy_HTML::process( $page( $skip ), $base );
$t( 1 === $count( 'data-bpb-src="', $out ) && false !== strpos( $out, '<img data-bpb-src="/ok.jpg" src="data:' ), 'only the ordinary image is converted (a ">" inside an attribute does not end the tag)' );
foreach ( array( '/eager.jpg', '/hi.jpg', '/nl.jpg', '/sk.jpg', '/hero.jpg', '/logo.jpg', 'banner.jpg', 'data:image/gif', '/real.jpg', '/smile.png', 'wpcf7_captcha' ) as $needle ) { $t( false !== strpos( $out, $needle ), "$needle untouched" ); }
$t( false === strpos( $out, 'data-bpb-src="/s.jpg"' ) && false === strpos( $out, 'data-bpb-src="/l.jpg"' ), 'images another lazy loader already handles are left alone' );

// ---- skip count 0 / bounds -----------------------------------------------------------------------
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg"><img src="/b.jpg">' ), array_merge( $base, array( 'lazy_html_skip_first' => 0 ) ) );
$t( 2 === $count( 'data-bpb-src="', $out ), 'skip 0 converts everything' );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg"><img src="/b.jpg">' ), array_merge( $base, array( 'lazy_html_skip_first' => 99 ) ) );
$t( false === strpos( $out, 'bepluspb-lazy-loader' ) && $out === $page( '<img src="/a.jpg"><img src="/b.jpg">' ), 'a large skip count leaves a short page untouched (no loader added)' );

// ---- <picture> -----------------------------------------------------------------------------------
$o2  = array_merge( $base, array( 'lazy_html_skip_first' => 0 ) );
$pic = '<picture><source type="image/webp" srcset="/p.webp 1x, /p2.webp 2x" sizes="100vw" data-x="1"><source srcset="/p.jpg"><img src="/p.jpg" alt="p"></picture>';
$out = BEPLUSPB_Lazy_HTML::process( $page( $pic ), $o2 );
$t( false !== strpos( $out, '<source type="image/webp" data-bpb-srcset="/p.webp 1x, /p2.webp 2x" data-bpb-sizes="100vw" data-x="1">' ) && false !== strpos( $out, '<source data-bpb-srcset="/p.jpg">' ) && false !== strpos( $out, '<img data-bpb-src="/p.jpg" src="data:' ), 'picture: sources and img are converted together' );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<picture><source srcset="/p.webp"><img src="/p.jpg" loading="eager"></picture><img src="/z.jpg">' ), $o2 );
$t( false !== strpos( $out, '<picture><source srcset="/p.webp"><img src="/p.jpg" loading="eager"></picture>' ) && 1 === $count( 'data-bpb-src="', $out ), 'an excluded picture image keeps its sources untouched' );

// ---- things that must never be touched --------------------------------------------------------------
$prot = '<script>var h=\'<img src="/s1.jpg">\';</script><style>.a{background:url(/s2.jpg)}</style><!-- <img src="/s3.jpg"> --><noscript><img src="/s4.jpg"></noscript><template><img src="/s5.jpg"></template><textarea><img src="/s6.jpg"></textarea><img src="/real.jpg">';
$out  = BEPLUSPB_Lazy_HTML::process( $page( $prot ), $o2 );
foreach ( array( '<img src="/s1.jpg">', '<img src="/s3.jpg">', '<noscript><img src="/s4.jpg"></noscript>', '<template><img src="/s5.jpg"></template>', '<textarea><img src="/s6.jpg"></textarea>' ) as $keep ) { $t( false !== strpos( $out, $keep ), "$keep untouched" ); }
$t( 1 === $count( 'data-bpb-src="/real.jpg"', $out ), 'the one real image is converted' );
$t( false === strpos( $out, "\x1a" ), 'no placeholder byte leaks' );
$head_img = '<img src="/in-head.jpg">';
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/b.jpg">', $head_img ), $o2 );
$t( false !== strpos( $out, '<head><meta charset="utf-8">' . $head_img ), 'the <head> is not rewritten' );

// ---- not a document / partial ----------------------------------------------------------------------
foreach ( array( '<img src="/a.jpg">', '{"a":"<img src=\"/a.jpg\">"}', '<html><body><p>no images</p></body></html>', '' ) as $frag ) { $t( $frag === BEPLUSPB_Lazy_HTML::process( $frag, $o2 ), 'fragment/JSON/imageless left as is' ); }
$t( true === is_string( BEPLUSPB_Lazy_HTML::buffer_callback( 'x', PHP_OUTPUT_HANDLER_START ) ) && 'x' === BEPLUSPB_Lazy_HTML::buffer_callback( 'x', PHP_OUTPUT_HANDLER_START ), 'a partial flush is returned unchanged' );
$GLOBALS['opts'] = $o2;
$whole = BEPLUSPB_Lazy_HTML::buffer_callback( $page( '<img src="/b.jpg">' ), PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL );
$t( false !== strpos( $whole, 'data-bpb-src="/b.jpg"' ), 'a whole response is rewritten through the buffer callback' );

// ---- Content-Security-Policy: native fallback ---------------------------------------------------------
$meta = '<meta http-equiv="Content-Security-Policy" content="script-src \'self\' \'nonce-abc\'">';
$out  = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg"><img src="/b.jpg" loading="lazy"><img src="/c.jpg" class="skip-lazy">', $meta ), $o2 );
$t( false === strpos( $out, 'data-bpb' ) && false === strpos( $out, 'bepluspb-lazy-loader' ) && false !== strpos( $out, '<img loading="lazy" src="/a.jpg">' ) && false !== strpos( $out, '<img src="/b.jpg" loading="lazy">' ) && false !== strpos( $out, '<img src="/c.jpg" class="skip-lazy">' ), 'a CSP blocking inline scripts: native loading="lazy" only, no loader' );

// ---- public filter ---------------------------------------------------------------------------------------
$GLOBALS['skip_filter'] = function ( $skip, $attrs, $tag ) { return isset( $attrs['alt'] ) && 'keep' === $attrs['alt'] ? true : $skip; };
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg" alt="keep"><img src="/b.jpg">' ), $o2 );
$t( false !== strpos( $out, '<img src="/a.jpg" alt="keep">' ) && false !== strpos( $out, 'data-bpb-src="/b.jpg"' ), 'bepluspb_lazy_html_skip_image can skip an image' );
$GLOBALS['skip_filter'] = null;

// ---- the loader itself ------------------------------------------------------------------------------------
$loader = ( new ReflectionMethod( 'BEPLUSPB_Lazy_HTML', 'loader_script' ) );
$loader->setAccessible( true );
$js = $loader->invoke( null );
$t( preg_match( '#^<script id="bepluspb-lazy-loader"[^>]*>(.*)</script>$#s', $js, $jm ) && strlen( $jm[1] ) < 2600 && false !== strpos( $jm[1], 'IntersectionObserver' ) && false !== strpos( $jm[1], 'T=300,' ) && false !== strpos( $jm[1], 'px 0px' ), 'loader is small and uses IntersectionObserver with a 300 px margin' );
$t( false !== strpos( $jm[1], '.swiper' ) && false !== strpos( $jm[1], '.slick-slider' ) && false !== strpos( $jm[1], '.elementor-image-carousel-wrapper' ), 'loader knows the common sliders (it observes the slider, not the clipped slide)' );
$js2 = $loader->invoke( null, 650 );
$t( false !== strpos( $js2, 'T=650,' ), 'the loader margin follows the setting' );
file_put_contents( sys_get_temp_dir() . '/bpb-lazy-loader.js', $jm[1] );

// ---- configurable margin reaches the page ----------------------------------------------------------------------------------
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg"><img src="/b.jpg">' ), array_merge( $base, array( 'lazy_html_skip_first' => 0, 'lazy_html_threshold' => 800 ) ) );
$t( false !== strpos( $out, 'T=800,' ), 'lazy_html_threshold is used by the loader' );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg">' ), array_merge( $base, array( 'lazy_html_skip_first' => 0, 'lazy_html_threshold' => 99999 ) ) );
$t( false !== strpos( $out, 'T=2000,' ), 'the margin is capped at 2000 px' );

// ---- iframes: native lazy loading ----------------------------------------------------------------------------------------------
$fr = array_merge( $base, array( 'lazy_html_skip_first' => 0 ) );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315" allowfullscreen></iframe><iframe src="/map" class="x"></iframe>' ), $fr );
$t( 2 === $count( '<iframe loading="lazy" src=', $out ) && false === strpos( $out, 'bepluspb-lazy-loader' ), 'iframes get loading="lazy" (and a page with only iframes needs no loader script)' );
foreach ( array(
	'<iframe src="/a" loading="eager"></iframe>' => 'already says how to load',
	'<iframe src="/a" loading="lazy"></iframe>' => 'already lazy',
	'<iframe src="/a" data-src="/real"></iframe>' => 'another lazy loader',
	'<iframe src="/a" data-lazy-src="/real"></iframe>' => 'another lazy loader (Rocket)',
	'<iframe src="/a" class="skip-lazy"></iframe>' => 'skip-lazy class',
	'<iframe src="/a" data-no-lazy="1"></iframe>' => 'data-no-lazy',
	'<iframe src="/a" width="0" height="0"></iframe>' => '0x0 tracking frame',
	'<iframe src="/a" width="1" height="1"></iframe>' => '1x1 tracking frame',
	'<iframe src="/a" style="display:none"></iframe>' => 'hidden frame',
	'<iframe src="/a" style="visibility: hidden"></iframe>' => 'hidden frame (visibility)',
	'<iframe srcdoc="<p>x</p>" src="/a"></iframe>' => 'srcdoc',
	'<iframe src="about:blank"></iframe>' => 'blank frame',
	'<iframe></iframe>' => 'no src',
	'<iframe src="https://example.com/?gform_ajax_frame=1"></iframe>' => 'Gravity Forms ajax frame',
	'<iframe src="https://www.google.com/recaptcha/api/fallback?k=1"></iframe>' => 'reCAPTCHA fallback',
	'<iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X"></iframe>' => 'GTM frame',
	'<iframe src="/a" class="hero-image"></iframe>' => 'excluded class',
	'<iframe src="/banner.jpg-frame"></iframe>' => 'excluded filename',
) as $frame => $why ) {
	$out = BEPLUSPB_Lazy_HTML::process( $page( $frame . '<img src="/z.jpg">' ), $fr );
	$t( false !== strpos( $out, $frame ), "iframe left alone: $why" );
}
$out = BEPLUSPB_Lazy_HTML::process( $page( '<iframe src="/a"></iframe><img src="/z.jpg">' ), array_merge( $fr, array( 'lazy_html_iframes' => 0 ) ) );
$t( false !== strpos( $out, '<iframe src="/a"></iframe>' ), 'the iframe option can be switched off' );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<script>var h=\'<iframe src="/s"></iframe>\';</script><noscript><iframe src="/n"></iframe></noscript><!-- <iframe src="/c"></iframe> --><iframe src="/real"></iframe>' ), $fr );
$t( false !== strpos( $out, '<iframe src="/s">' ) && false !== strpos( $out, '<noscript><iframe src="/n"></iframe></noscript>' ) && false !== strpos( $out, '<iframe src="/c">' ) && false !== strpos( $out, '<iframe loading="lazy" src="/real">' ), 'iframes inside script/noscript/comments are never touched' );
$out = BEPLUSPB_Lazy_HTML::process( $page( '<iframe src="/a"></iframe>', $meta ), $fr );
$t( false !== strpos( $out, '<iframe loading="lazy" src="/a">' ), 'iframes use native lazy loading even when a CSP blocks the image loader' );

// ---- inline background images (opt-in) ------------------------------------------------------------------------------
$bgo = array_merge( $base, array( 'lazy_html_bg' => 1, 'lazy_html_skip_first' => 1 ) );
$body = '<div id="b0" style="background-image:url(/first.jpg)">0</div>'
	. '<div id="b1" class="hero" style="color:red;background-image:url(\'/img/a.jpg?x=1&amp;y=2\');margin:0">1</div>'
	. '<section id="b2" style="background-image: url(&quot;/img/b.jpg&quot;)">2</section>'
	. '<div id="b3" style="background:url(/short.jpg) no-repeat">3</div>'
	. '<div id="b4" style="background-image:linear-gradient(red,blue),url(/g.jpg)">4</div>'
	. '<div id="b5" style="background-image:url(data:image/png;base64,AAAA)">5</div>'
	. '<div id="b6" class="skip-lazy" style="background-image:url(/skip.jpg)">6</div>'
	. '<div id="b7" style="background-image:url(/x(1).jpg)">7</div>'
	. '<span id="b8" style="background-image:url(/img/c.jpg)"/>'
	. '<script>var s=\'<div style="background-image:url(/in-script.jpg)">\';</script>';
$out = BEPLUSPB_Lazy_HTML::process( $page( $body ), $bgo );
$t( false !== strpos( $out, '<div id="b0" style="background-image:url(/first.jpg)">' ), 'bg: the first background (skip count) stays eager' );
$t( false !== strpos( $out, '<div id="b1" class="hero" style="color:red;margin:0" data-bpb-bg="/img/a.jpg?x=1&amp;y=2">' ), 'bg: the declaration moves to data-bpb-bg, other declarations stay' );
$t( false !== strpos( $out, '<section id="b2" data-bpb-bg="/img/b.jpg">' ), 'bg: an emptied style attribute is dropped, &quot; quoting understood' );
$t( false !== strpos( $out, 'style="background:url(/short.jpg) no-repeat"' ) && false !== strpos( $out, 'linear-gradient(red,blue),url(/g.jpg)' ) && false !== strpos( $out, 'url(data:image/png;base64,AAAA)' ), 'bg: shorthand, gradient layers and data: URLs are left alone' );
$t( false !== strpos( $out, 'class="skip-lazy" style="background-image:url(/skip.jpg)"' ) && false !== strpos( $out, 'url(/x(1).jpg)' ), 'bg: skip-lazy and URLs with unsafe characters are left alone' );
$t( false !== strpos( $out, '<span id="b8" data-bpb-bg="/img/c.jpg"/>' ), 'bg: a self-closing tag keeps its slash' );
$t( false !== strpos( $out, '/in-script.jpg' ) && 1 === $count( 'background-image:url(/in-script.jpg)', $out ), 'bg: markup inside a script is untouched' );
$t( false !== strpos( $out, 'id="bepluspb-lazy-loader"' ) && false !== strpos( $out, "B='data-bpb-bg'" ), 'bg: the loader handles data-bpb-bg' );
$t( false !== strpos( $out, '<noscript><style id="bepluspb-lazy-nojs">[data-bpb-bg="/img/a.jpg?x=1&y=2"]{background-image:url("/img/a.jpg?x=1&y=2")}' ), 'bg: a no-JavaScript rule per URL puts the background back' );
$off = BEPLUSPB_Lazy_HTML::process( $page( $body ), array_merge( $bgo, array( 'lazy_html_bg' => 0 ) ) );
$t( false === strpos( $off, 'data-bpb-bg' ) && false === strpos( $off, "B='data-bpb-bg'" ), 'bg: off by default (no attribute, no loader code)' );
$none = BEPLUSPB_Lazy_HTML::process( $page( '<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg">' ), $bgo );
$t( false === strpos( $none, "B='data-bpb-bg'" ), 'bg: the loader stays lean when the page has no deferred background' );
$csp = '<meta http-equiv="Content-Security-Policy" content="script-src \'self\'">';
$out = BEPLUSPB_Lazy_HTML::process( $page( $body, $csp ), $bgo );
$t( false === strpos( $out, 'data-bpb-bg' ), 'bg: with a CSP blocking the loader, backgrounds are left alone' );
$GLOBALS['skip_filter'] = null;

echo "OK ($n assertions)\n";
