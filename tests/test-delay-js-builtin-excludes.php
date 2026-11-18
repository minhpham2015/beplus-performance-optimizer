<?php
/**
 * Standalone test: Delay JS never delays consent banners, Elementor's lazy-background script, "no-js" togglers,
 * other lazy loaders, or scripts carrying a conventional opt-out attribute (both modes).
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
$GLOBALS['opts'] = array( 'js_exclude' => 'my-keyword', 'js_delay_rdelay' => 0 );
$GLOBALS['filter_extra'] = null;
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_password( $n = 12, $s = true ) { return substr( bin2hex( random_bytes( 16 ) ), 0, $n ); }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function bepluspb_parse_exclude_list( $t ) { return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $t ) ) ) ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function apply_filters( $tag, $value, ...$args ) { return 'bepluspb_delay_js_exclude' === $tag && is_callable( $GLOBALS['filter_extra'] ) ? call_user_func( $GLOBALS['filter_extra'], $value, $args[0] ) : $value; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$rewrite = new ReflectionMethod( 'BEPLUSPB_JS', 'rewrite_scripts' ); $rewrite->setAccessible( true );
$page = function ( $body ) use ( $rewrite ) { return $rewrite->invoke( null, '<!doctype html><html><head><title>t</title></head><body>' . $body . '</body></html>' ); };
$blocked = function ( $out, $needle ) { return 1 === preg_match( '#<script[^>]*javascript/blocked[^>]*>[^<]*' . preg_quote( $needle, '#' ) . '#', $out ) || 1 === preg_match( '#<script[^>]*javascript/blocked[^>]*' . preg_quote( $needle, '#' ) . '#', $out ); };

// ---- the list itself ------------------------------------------------------------------------------------------------
foreach ( array(
	'const lazyloadRunObserver = () => { const x = document.querySelectorAll(".e-con.e-parent:not(.e-lazyloaded)"); };',
	'document.body.classList.remove("no-js");',
	'document.documentElement.className = "js";',
	"document.documentElement.className = document.documentElement.className.replace( 'no-js', 'js' );",
	'c.replace(/woocommerce-no-js/, "woocommerce-js")',
	'function et_core_page_resource_fallback(a,b){}',
	'var lazyLoadOptions = {elements_selector: "img"};',
	'https://x.test/wp-content/plugins/wp-rocket/assets/js/lazyload/17.5/lazyload.min.js',
	'https://x.test/wp-content/plugins/cookie-notice/js/front.min.js',
	'https://cdn.cookielaw.org/scripttemplates/otSDKStub.js',
	'https://consent.cookiebot.com/uc.js',
	'cmplz-cookiebanner',
	'https://ap.legalblink.it/api/scripts/lb_cs.js',
) as $s ) {
	t_ok( BEPLUSPB_JS::is_builtin_excluded( $s ), 'never delayed: ' . substr( $s, 0, 60 ) );
}
foreach ( array(
	'https://x.test/wp-content/plugins/contact-form-7/includes/js/index.js?ver=6.2.5',
	'https://x.test/wp-includes/js/jquery/jquery.min.js',
	'https://x.test/wp-content/plugins/elementor/assets/js/frontend.min.js',
	'window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);}',
	'var form = document.querySelector("form"); form.addEventListener("submit", send);',
	'',
) as $s ) {
	t_ok( ! BEPLUSPB_JS::is_builtin_excluded( $s ), 'still delayed: ' . substr( $s, 0, 60 ) );
}
foreach ( array(
	'<script data-no-optimize="1" src="/a.js">' => true,
	'<script src="/a.js" data-no-defer>' => true,
	'<script src="/a.js" data-no-delay="1">' => true,
	'<script src="/a.js" data-cfasync="false">' => true,
	"<script src='/a.js' data-cfasync='false'>" => true,
	'<script nowprocket src="/a.js">' => true,
	'<script data-wpmeteor-nooptimize="true">' => true,
	'<script src="/a.js" data-pagespeed-no-defer>' => true,
	'<script src="/a.js" defer>' => false,
	'<script src="/a.js" data-cfasync="true">' => false,
	'<script src="/data-no-optimize.js">' => false,
	'<script>' => false,
) as $tag => $expected ) {
	t_ok( BEPLUSPB_JS::has_opt_out_attr( $tag ) === $expected, 'opt-out attribute ' . ( $expected ? 'recognised' : 'not matched' ) . ': ' . $tag );
}

// ---- Advanced mode: what the rewriter blocks --------------------------------------------------------------------------------
$out = $page(
	'<script>const lazyloadRunObserver = () => {};</script>'
	. '<script>document.body.classList.remove("no-js");</script>'
	. '<script src="https://x.test/wp-content/plugins/cookie-notice/js/front.min.js"></script>'
	. '<script id="cmplz-cookiebanner-js" src="https://x.test/c.js"></script>'
	. '<script data-no-optimize="1">var optout = 1;</script>'
	. '<script src="https://x.test/o.js" data-cfasync="false"></script>'
	. '<script>var ordinaryInline = 1;</script>'
	. '<script src="https://x.test/wp-content/plugins/contact-form-7/includes/js/index.js"></script>'
	. '<script>var my-keyword = 1;</script>'
);
t_ok( ! $blocked( $out, 'lazyloadRunObserver' ), 'advanced: Elementor lazy-background script is not blocked' );
t_ok( ! $blocked( $out, 'classList.remove("no-js")' ), 'advanced: the no-js toggler is not blocked' );
t_ok( ! $blocked( $out, 'data-bepluspb-src="https://x.test/wp-content/plugins/cookie-notice' ) && false !== strpos( $out, 'src="https://x.test/wp-content/plugins/cookie-notice/js/front.min.js"' ), 'advanced: the cookie-notice script keeps its src' );
t_ok( false !== strpos( $out, 'id="cmplz-cookiebanner-js" src="https://x.test/c.js"' ), 'advanced: a script whose id names a consent banner keeps its src' );
t_ok( ! $blocked( $out, 'var optout' ) && false !== strpos( $out, 'src="https://x.test/o.js" data-cfasync="false"' ), 'advanced: data-no-optimize / data-cfasync="false" scripts are left alone' );
t_ok( $blocked( $out, 'var ordinaryInline' ), 'advanced: an ordinary inline script is still blocked' );
t_ok( false !== strpos( $out, 'data-bepluspb-src="https://x.test/wp-content/plugins/contact-form-7/includes/js/index.js"' ), 'advanced: an ordinary external script is still blocked' );
t_ok( ! $blocked( $out, 'var my-keyword' ), 'advanced: the user\'s own keyword still works' );
$GLOBALS['filter_extra'] = function ( $hit, $haystack ) { return $hit || false !== strpos( $haystack, 'site-specific' ); };
$out = $page( '<script>var site-specific = 1;</script><script>var other = 1;</script>' );
t_ok( ! $blocked( $out, 'var site-specific' ) && $blocked( $out, 'var other' ), 'advanced: the bepluspb_delay_js_exclude filter extends the list' );
$GLOBALS['filter_extra'] = null;

// ---- Elementor entrance animations (config handed to the runtime) --------------------------------------------------
$out = $page( '<script>var a = 1;</script>' );
t_ok( false === strpos( $out, 'elementor-animations"' ) || false !== strpos( $out, 'if(!C||!C["elementor-animations"])' ), 'without Elementor the config does not enable the animations' );
t_ok( 0 === preg_match( '/var _bepluspb=\{[^}]*elementor-animations/', $out ), 'without Elementor: config has no elementor-animations flag' );
define( 'ELEMENTOR_VERSION', '3.30.0' );
$out = $page( '<script>var a = 1;</script>' );
t_ok( 1 === preg_match( '/var _bepluspb=\{[^}]*"elementor-animations":true/', $out ), 'with Elementor active the config enables the animations' );

// ---- scripts that are only text inside other markup are not rewritten ------------------------------------------------
$out = $page(
	'<noscript><script>var inNoscript=1;</script></noscript><template><script>var inTemplate=1;</script></template>'
	. '<textarea><script>var inTextarea=1;</script></textarea><!--[if lt IE 9]><script src="/ie.js"></script><![endif]-->'
	. '<div data-x=\'<script>var inAttr=1;</script>\'>x</div><script>document.write("<!--");</script><script>var real=1;</script>'
);
t_ok( 2 === substr_count( $out, '<script type="javascript/blocked"' ), 'inert markup: only the two real scripts are blocked' );
t_ok( false !== strpos( $out, '<noscript><script>var inNoscript=1;</script></noscript>' ) && false !== strpos( $out, '<template><script>var inTemplate=1;</script></template>' ) && false !== strpos( $out, '<textarea><script>var inTextarea=1;</script></textarea>' ), 'inert markup: noscript/template/textarea content is unchanged' );
t_ok( false !== strpos( $out, '<!--[if lt IE 9]><script src="/ie.js"></script><![endif]-->' ) && false !== strpos( $out, "<div data-x='<script>var inAttr=1;</script>'>" ), 'inert markup: conditional comments and attribute values are unchanged' );
t_ok( $blocked( $out, 'var real=1' ) && $blocked( $out, 'document.write("<!--")' ), 'inert markup: a "<!--" inside a script does not hide the scripts after it' );
t_ok( false === strpos( $out, "\x1a" ), 'inert markup: no token is left behind' );
t_ok( strpos( $out, 'var _bepluspb=' ) > strpos( $out, '<![endif]-->' ) && strpos( $out, 'var _bepluspb=' ) < strpos( $out, 'document.write(' ), 'inert markup: the runtime sits right before the first real script, not inside a comment' );
$out = $page( '<!--[if lt IE 9]><script src="/ie.js"></script><![endif]--><script>var after=1;</script>' );
t_ok( false !== strpos( $out, 'var _bepluspb=' ), 'inert markup: a script that only appears inside a comment does not take the runtime with it' );

// ---- Simple mode ---------------------------------------------------------------------------------------------------------------
$tag = function ( $src, $extra = '' ) { return "<script src=\"$src\"$extra></script>\n"; };
$simple = function ( $src, $handle, $extra = '' ) use ( $tag ) { return BEPLUSPB_JS::delay_scripts( $tag( $src, $extra ), $handle, $src ); };
t_ok( false !== strpos( $simple( 'https://x.test/p/contact-form-7/index.js', 'contact-form-7' ), 'data-bepluspb-delay' ), 'simple: an ordinary script is delayed' );
t_ok( false === strpos( $simple( 'https://x.test/wp-content/plugins/cookie-notice/js/front.min.js', 'cn' ), 'data-bepluspb-delay' ), 'simple: a consent-banner script (by URL) is not delayed' );
t_ok( false === strpos( $simple( 'https://x.test/a.js', 'cookie-law-info' ), 'data-bepluspb-delay' ), 'simple: a consent-banner script (by handle) is not delayed' );
t_ok( false === strpos( $simple( 'https://x.test/a.js', 'h', ' data-cfasync="false"' ), 'data-bepluspb-delay' ), 'simple: data-cfasync="false" is respected' );
t_ok( false === strpos( $simple( 'https://x.test/a.js', 'h', ' data-no-optimize="1"' ), 'data-bepluspb-delay' ), 'simple: data-no-optimize is respected' );
t_ok( false === strpos( $simple( 'https://x.test/my-keyword.js', 'h' ), 'data-bepluspb-delay' ), 'simple: the user\'s own keyword still works' );

echo "OK ($n assertions)\n";
