<?php
/**
 * Critical CSS storage: page-type keys, lookup, sanitising, limits, CSP.
 * Run: php -n tests/test-critical-css.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['db'] = array(); $GLOBALS['autoload'] = array(); $GLOBALS['q'] = array( 'rtl' => false, 'front' => false, 'home' => false, 'singular' => false, 'type' => 'post', 'search' => false, 'archive' => false );
function __( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['db'][ $k ] = $v; $GLOBALS['autoload'][ $k ] = $autoload; $GLOBALS['updates'] = ( $GLOBALS['updates'] ?? 0 ) + 1; return true; }
function is_rtl() { return $GLOBALS['q']['rtl']; } function is_front_page() { return $GLOBALS['q']['front']; } function is_home() { return $GLOBALS['q']['home']; }
function is_singular() { return $GLOBALS['q']['singular']; } function get_post_type() { return $GLOBALS['q']['type']; } function is_search() { return $GLOBALS['q']['search']; } function is_archive() { return $GLOBALS['q']['archive']; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-critical-css.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function q( $over ) { $GLOBALS['q'] = array_merge( array( 'rtl' => false, 'front' => false, 'home' => false, 'singular' => false, 'type' => 'post', 'search' => false, 'archive' => false ), $over ); }
$C = 'BEPLUSPB_Critical_CSS';

// ---- keys -----------------------------------------------------------------------------------
foreach ( array( 'default', 'front', 'blog', 'search', 'archive', 'other', 'single-post', 'single-product', 'single-my_type-2', 'front:rtl', 'single-page:rtl', 'default:rtl' ) as $k ) { t_ok( $C::is_valid_key( $k ), "valid key $k" ); }
foreach ( array( '', 'Front', 'single-', 'single-UPPER', 'single-' . str_repeat( 'a', 41 ), '../etc', 'front:ltr', 'front:rtl:rtl', "front\n", 'front ', 'single-a/b', 5, null, array( 'front' ) ) as $k ) { t_ok( ! $C::is_valid_key( $k ), 'invalid key ' . json_encode( $k ) ); }

q( array( 'front' => true, 'singular' => true, 'type' => 'page' ) );
t_ok( 'front' === $C::current_key(), 'a static front page is "front", not "single-page"' );
q( array( 'home' => true ) ); t_ok( 'blog' === $C::current_key(), 'blog index' );
q( array( 'singular' => true, 'type' => 'product' ) ); t_ok( 'single-product' === $C::current_key(), 'single post type' );
q( array( 'singular' => true, 'type' => 'My Type!' ) ); t_ok( 'single-mytype' === $C::current_key(), 'post type name is sanitised' );
q( array( 'singular' => true, 'type' => '' ) ); t_ok( 'other' === $C::current_key(), 'empty post type falls back to other' );
q( array( 'singular' => true, 'type' => str_repeat( 'z', 80 ) ) ); t_ok( 'single-' . str_repeat( 'z', 40 ) === $C::current_key(), 'long post type truncated to the key limit' );
q( array( 'search' => true, 'archive' => true ) ); t_ok( 'search' === $C::current_key(), 'search before archive' );
q( array( 'archive' => true ) ); t_ok( 'archive' === $C::current_key(), 'archive' );
q( array() ); t_ok( 'other' === $C::current_key(), 'anything else' );
q( array( 'front' => true, 'rtl' => true ) ); t_ok( 'front:rtl' === $C::current_key(), 'right-to-left sites use their own set' );
q( array( 'archive' => true, 'rtl' => true ) ); t_ok( 'archive:rtl' === $C::current_key(), 'rtl suffix on every type' );
t_ok( 'Front page' === $C::key_label( 'front' ) && 'Front page (RTL)' === $C::key_label( 'front:rtl' ) && 'Single: product' === $C::key_label( 'single-product' ), 'labels' );

// ---- sanitising -----------------------------------------------------------------------------
$r = $C::sanitize_css( "/* c */ body { margin : 0 }\n.a   >   .b{color:red;}" );
t_ok( 'body{margin:0}.a>.b{color:red}' === $r['css'], 'minified: ' . ( $r['css'] ?? $r['error'] ) );
$r = $C::sanitize_css( 'a{color:red}</style><script>alert(1)</script><style>b{}' );
t_ok( false === strpos( $r['css'], '<' ) && false !== strpos( $r['css'], 'a{color:red}' ), 'no "<" can survive: nothing can close the <style> or open a tag' );
t_ok( false === strpos( $C::sanitize_css( '@charset "utf-8";@import url(//evil.test/x.css);a{b:c}' )['css'], '@' ) && 'a{b:c}' === $C::sanitize_css( '@charset "utf-8";@import url(//evil.test/x.css);a{b:c}' )['css'], '@import and @charset removed' );
t_ok( 'a{b:c}' === $C::sanitize_css( "@import 'x.css'\n;a{b:c}" )['css'], '@import with odd spacing removed' );
$r = $C::sanitize_css( 'a{width:expression(alert(1));background:url(javascript:alert(1));-moz-binding:url(x);behavior:url(x.htc)}' );
t_ok( false === stripos( $r['css'], 'expression' ) && false === stripos( $r['css'], 'javascript' ) && false === stripos( $r['css'], 'binding' ) && false === stripos( $r['css'], 'behavior' ), 'script-ish constructs removed' );
t_ok( '@media (min-width:768px){.a{b:c}}' === $C::sanitize_css( '@media (min-width: 768px) { .a { b: c; } }' )['css'], 'nested at-rules kept' );
t_ok( 'a:after{content:"}"}' === $C::sanitize_css( 'a:after{content:"}"}' )['css'], 'a brace inside a string is not a brace' );
t_ok( "a:after{content:'{'}" === $C::sanitize_css( "a:after{content:'{'}" )['css'], '... nor an opening one' );
foreach ( array( 'a{b:c' => 'unclosed', 'a{b:c}}' => 'extra close', '}a{b:c}' => 'leading close', 'a{b:"c}' => 'unclosed string', '' => 'empty', '/* only a comment */' => 'only a comment', '<>' => 'only angle brackets' ) as $bad => $why ) {
	t_ok( isset( $C::sanitize_css( $bad )['error'] ), "refused: $why" );
}
t_ok( isset( $C::sanitize_css( array( 'a{}' ) )['error'] ) && isset( $C::sanitize_css( null )['error'] ), 'non-string refused' );
$big = str_repeat( '.x{color:red}', 13000 ); // ~169 KB
t_ok( strlen( $big ) > $C::MAX_BYTES && isset( $C::sanitize_css( $big )['error'] ), 'over the size limit refused' );
t_ok( isset( $C::sanitize_css( str_repeat( 'a', $C::MAX_BYTES * 3 + 1 ) )['error'] ), 'absurd input refused before any processing' );
t_ok( isset( $C::sanitize_css( "a{b:c}\0" )['css'] ) && false === strpos( $C::sanitize_css( "a{b:c}\0" )['css'], "\0" ), 'NUL bytes removed' );

// Real generator output (assets/js/critical-css-generator.js): nesting, layers, supports, keyframes, url().
$generated = '@layer base, components;html,body{margin: 0px; font-family: Inter, sans-serif; --stack: "Inter", sans-serif;}
@font-face { font-family: Inter; src: url("/fonts/inter.woff2") format("woff2"); }
@keyframes fade { 
  0% { opacity: 0; }

  100% { opacity: 1; }

}
.hero{height: 500px; background: url("/img/bg.png") center center / cover; animation: 1s ease 0s 1 normal none running fade; position: relative;}
.nav a:hover{color: red;}
@media (min-width: 768px){.hero{height: 700px;}
.desktop-bar{display: flex; height: 40px;}
}
@supports (display: grid){.grid{display: grid; gap: 4px;}
}
@layer base{.layered{color: blue;}
}
.card {
  color: rgb(34, 34, 34);
  & .title { font-weight: 700; }

}';
$r = $C::sanitize_css( $generated );
t_ok( isset( $r['css'] ), 'generator output is accepted' . ( $r['error'] ?? '' ) );
foreach ( array( '@layer base,components;', 'url("/img/bg.png")', '@media (min-width:768px){.hero{height:700px}.desktop-bar{display:flex;height:40px}}', '@supports (display:grid){.grid{display:grid;gap:4px}}', '@layer base{.layered{color:blue}}', '& .title{font-weight:700}', '@keyframes fade{0%{opacity:0}100%{opacity:1}}', '.nav a:hover{color:red}', '--stack:"Inter",sans-serif' ) as $needle ) {
	t_ok( false !== strpos( $r['css'], $needle ), "minified output keeps: $needle" );
}
t_ok( strlen( $r['css'] ) < strlen( $generated ), 'and is smaller than the generator output (' . strlen( $r['css'] ) . ' vs ' . strlen( $generated ) . ' bytes)' );

// ---- storage --------------------------------------------------------------------------------
$GLOBALS['db'] = array(); $C::reset();
t_ok( array() === $C::all() && '' === $C::css_for_request(), 'nothing stored: nothing printed' );
t_ok( isset( $C::save( 'nope', 'a{b:c}' )['error'] ) && isset( $C::save( 'front', 'a{' )['error'] ) && array() === ( $GLOBALS['db'][ $C::OPTION ] ?? array() ), 'invalid key or CSS stores nothing' );
$r = $C::save( 'front', 'body{margin:0}', 'generated' );
t_ok( true === $r['ok'] && 14 === $r['bytes'] && false === $GLOBALS['autoload'][ $C::OPTION ], 'saved, NOT autoloaded' );
t_ok( 'generated' === $C::all()['front']['source'] && $C::all()['front']['updated'] > 1e9, 'entry records source and time' );
$C::save( 'default', '.d{x:y}' ); $C::save( 'single-product', '.p{x:y}' );
t_ok( 'manual' === $C::all()['default']['source'] && 'manual' === $C::all()['single-product']['source'], 'unknown source is manual' );
q( array( 'front' => true ) ); t_ok( 'body{margin:0}' === $C::css_for_request(), 'front page gets its own entry' );
q( array( 'singular' => true, 'type' => 'product' ) ); t_ok( '.p{x:y}' === $C::css_for_request(), 'a product gets the product entry' );
q( array( 'archive' => true ) ); t_ok( '.d{x:y}' === $C::css_for_request(), 'a type without an entry falls back to default' );
q( array( 'archive' => true, 'rtl' => true ) ); t_ok( '' === $C::css_for_request(), 'an RTL page never gets the LTR default' );
$C::save( 'default:rtl', '.r{x:y}' ); q( array( 'archive' => true, 'rtl' => true ) ); t_ok( '.r{x:y}' === $C::css_for_request(), 'RTL falls back to the RTL default' );
t_ok( true === $C::delete( 'default' ) && false === $C::delete( 'default' ) && false === $C::delete( '../x' ), 'delete: once, and only valid keys' );
q( array( 'archive' => true ) ); t_ok( '' === $C::css_for_request(), 'after deleting the default nothing is printed' );
$C::save( 'front', 'body{margin:1px}' ); t_ok( 'body{margin:1px}' === $C::all()['front']['css'], 'replacing overwrites the entry' );
t_ok( 3 === count( $C::all() ), 'and keeps one entry per key (front, single-product, default:rtl)' );

// garbage in the option is ignored, not trusted
$GLOBALS['db'][ $C::OPTION ] = array( 'front' => array( 'css' => 'ok{a:b}' ), '../evil' => array( 'css' => 'x{}' ), 'blog' => 'string', 'archive' => array( 'css' => '' ), 'search' => array( 'nocss' => 1 ), 'other' => array( 'css' => array( 'x' ) ) ); $C::reset();
t_ok( array( 'front' ) === array_keys( $C::all() ), 'only well-formed entries with valid keys are read' );
$GLOBALS['db'][ $C::OPTION ] = array( 'front' => array( 'css' => 'a{}</style><script>x</script>' ), 'blog' => array( 'css' => 'ok{a:b}' ) ); $C::reset();
t_ok( array( 'blog' ) === array_keys( $C::all() ), 'an entry with "<" (tampered option) is ignored' );
$GLOBALS['db'][ $C::OPTION ] = 'garbage'; $C::reset(); t_ok( array() === $C::all(), 'a non-array option is ignored' );

// entry limit
$GLOBALS['db'] = array(); $C::reset();
for ( $i = 0; $i < $C::MAX_ENTRIES; $i++ ) { $GLOBALS['db'][ $C::OPTION ]['single-t' . $i] = array( 'css' => 'a{b:c}' ); }
$C::reset();
t_ok( isset( $C::save( 'front', 'a{b:c}' )['error'] ), 'a new entry beyond the limit is refused' );
t_ok( true === $C::save( 'single-t3', 'x{y:z}' )['ok'], 'replacing an existing entry at the limit is fine' );

// ---- CSP ------------------------------------------------------------------------------------
t_ok( ! $C::csp_blocks_inline_styles( array() ), 'no policy: allowed' );
t_ok( ! $C::csp_blocks_inline_styles( array( "script-src 'self'" ) ), 'no style directive: allowed' );
t_ok( $C::csp_blocks_inline_styles( array( "style-src 'self'" ) ), "style-src 'self': blocked" );
t_ok( ! $C::csp_blocks_inline_styles( array( "style-src 'self' 'unsafe-inline'" ) ), "'unsafe-inline': allowed" );
t_ok( $C::csp_blocks_inline_styles( array( "style-src 'self' 'unsafe-inline' 'nonce-abc'" ) ), 'a nonce makes unsafe-inline ignored: blocked' );
t_ok( $C::csp_blocks_inline_styles( array( "style-src 'unsafe-inline' 'sha256-xyz='" ) ), 'a hash makes unsafe-inline ignored: blocked' );
t_ok( $C::csp_blocks_inline_styles( array( "default-src 'self'" ) ), 'default-src governs when style-src is absent: blocked' );
t_ok( ! $C::csp_blocks_inline_styles( array( "default-src 'self'; style-src 'unsafe-inline'" ) ), 'style-src beats default-src' );
t_ok( $C::csp_blocks_inline_styles( array( "style-src 'unsafe-inline'; style-src-elem 'self'" ) ), 'style-src-elem beats style-src for <style> elements' );
t_ok( ! $C::csp_blocks_inline_styles( array( "style-src 'self'; style-src-elem 'unsafe-inline'" ) ), '... both ways' );
t_ok( $C::csp_blocks_inline_styles( array( "style-src 'unsafe-inline'", "default-src 'self'" ) ), 'every enforced policy applies: one blocking is enough' );
t_ok( $C::csp_blocks_inline_styles( array( "STYLE-SRC 'SELF'" ) ), 'directive names and values are case-insensitive' );
t_ok( false === $C::csp_blocks_inline_styles( 'garbage' ), 'a string instead of a list is treated as one policy without style directives' );

echo "OK ($n assertions)\n";
