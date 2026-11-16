<?php
/**
 * Remove Unused CSS: parser, selector matching, URL rewriting, response guard.
 * Run: php -n tests/test-ucss-filter.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $tag, $value ) { return $value; }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
$tmp = sys_get_temp_dir() . '/ucss-test-' . getmypid() . '/';
mkdir( $tmp );
define( 'BEPLUSPB_CACHE_DIR', $tmp );
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-ucss.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function f( $css, $html, $safe = array() ) {
	return BEPLUSPB_UCSS::filter_css( $css, BEPLUSPB_UCSS::extract_used_tokens( $html ), $safe );
}
function has( $out, $needle ) { return false !== strpos( $out, $needle ); }

// --- token extraction -------------------------------------------------------
$u = BEPLUSPB_UCSS::extract_used_tokens( '<html><body class=plain id=main><div class=\'a b\' id="x"><my-el class="w-1/2"></my-el>' );
t_ok( isset( $u['.plain'], $u['#main'], $u['.a'], $u['.b'], $u['#x'], $u['.w-1/2'], $u['my-el'], $u['div'] ), 'unquoted/single/double quoted attrs and custom elements' );

// --- basic keep / drop ------------------------------------------------------
$o = f( '.keep{a:b}.drop{c:d}', '<p class="keep">' );
t_ok( has( $o, '.keep{a:b}' ) && ! has( $o, '.drop' ), 'unused rule dropped, used kept' );

// --- escaped classes (Tailwind) --------------------------------------------
$o = f( '.md\:flex{display:flex}.w-1\/2{width:50%}.gone\:x{y:z}', '<div class="md:flex w-1/2">' );
t_ok( has( $o, '.md\:flex' ) && has( $o, '.w-1\/2' ) && ! has( $o, 'gone' ), 'escaped class selectors match' );
$o = f( '.\31 0{a:b}', '<div class="10">' );
t_ok( has( $o, 'a:b' ), 'hex-escaped class matches' );

// --- statement at-rules must not swallow the next rule ---------------------
$o = f( '@layer base,theme;.keep{a:b}', '<p class="keep">' );
t_ok( has( $o, '@layer base,theme;' ) && has( $o, '.keep{a:b}' ), '@layer statement kept and next rule survives' );
$o = f( '@import url(x.css);@charset "utf-8";.drop{a:b}', '<p>' );
t_ok( has( $o, '@import url(x.css);' ) && ! has( $o, '.drop' ), '@import statement kept, following unused rule still dropped' );
$o = f( '@layer base{.drop{a:b}}.keep{c:d}', '<p class="keep">' );
t_ok( has( $o, '@layer base;' ) && ! has( $o, '.drop' ), 'emptied named layer keeps order declaration' );

// --- strings, braces, data URIs --------------------------------------------
$o = f( '.keep::after{content:"}"}.drop{a:b}.keep2{background:url(data:image/svg+xml;utf8,<svg>{x}</svg>)}', '<i class="keep keep2">' );
t_ok( has( $o, 'content:"}"' ) && ! has( $o, '.drop' ) && has( $o, '.keep2' ), 'brace inside string / data URI does not break parsing' );
$o = f( '/* .drop{a:b} */.keep{content:"/* not a comment */"}', '<i class="keep">' );
t_ok( ! has( $o, '.drop' ) && has( $o, '/* not a comment */' ), 'comments dropped but comment-like strings preserved' );

// --- malformed CSS fails open (original served, nothing silently lost) -------
foreach ( array( '.x(a{b:c}.keep{d:e}', '.keep{a:b', '.keep{a:b{c:d}' ) as $bad ) {
	t_ok( $bad === f( $bad, '<i class="keep">' ), "malformed CSS returned untouched: $bad" );
}
t_ok( ! has( f( '.drop{a:b}.keep{c:d}', '<i class="keep">' ), '.drop' ), 'well-formed CSS still filtered after a malformed one' );

// --- selector lists, :is(), :not(), attributes ------------------------------
$o = f( ':is(.a,.b){c:d}', '<i class="zzz">' );
t_ok( has( $o, ':is(.a,.b)' ), ':is() list is not split into broken pieces (kept safely)' );
$o = f( '.keep:not(.nope){a:b}.nope2:not(.keep){c:d}', '<i class="keep">' );
t_ok( has( $o, '.keep:not(.nope)' ), 'pseudo with argument stripped before matching' );
$o = f( 'a[href^="http,x"],.drop{a:b}', '<a href="#">' );
t_ok( has( $o, 'a[href^="http,x"]' ), 'comma inside attribute string does not split the selector' );
$o = f( '.drop[data-x="y"]{a:b}[data-aos]{c:d}', '<p>' );
t_ok( ! has( $o, '.drop' ) && has( $o, '[data-aos]' ), 'attribute-only selector kept, class+attribute dropped when class unused' );

// --- block at-rules ---------------------------------------------------------
$o = f( '@media (min-width:600px){.drop{a:b}}@media print{.keep{a:b}}@font-face{font-family:x;src:url(a.woff)}@keyframes k{from{a:b}to{a:c}}@property --x{syntax:"*";inherits:false}', '<p class="keep">' );
t_ok( ! has( $o, 'min-width' ) && has( $o, '@media print{.keep{a:b}}' ) && has( $o, '@font-face' ) && has( $o, '@keyframes k' ) && has( $o, '@property --x' ), 'media recursed; font-face/keyframes/property kept' );
$o = f( '@container (min-width:1px){.keep{a:b}}@supports (display:grid){.drop{a:b}}', '<p class="keep">' );
t_ok( has( $o, '@container' ) && ! has( $o, '@supports' ), '@container/@supports recursed' );

// --- default safelist keeps JS-toggled classes ------------------------------
$safe = BEPLUSPB_UCSS::default_safelist();
$o = f( '.menu.is-open{a:b}.nav-open .x{c:d}.slick-active{e:f}.woocommerce-message{g:h}.totally-unused{i:j}', '<p>', $safe );
t_ok( has( $o, '.is-open' ) && has( $o, '.nav-open' ) && has( $o, '.slick-active' ) && has( $o, '.woocommerce-message' ) && ! has( $o, 'totally-unused' ), 'default safelist keeps runtime classes only' );

// --- url() / @import rewriting ----------------------------------------------
$base = 'https://ex.com/wp-content/themes/t/css/style.css?ver=1';
$o = BEPLUSPB_UCSS::absolutize_css_urls( '@import "a.css";@import url(b.css);.x{background:url(../img/a.png)}.y{background:url( "f.woff" )}.z{background:url(data:image/png;base64,AAA=)}.w{background:url(/abs.png)}.v{background:url(https://c.com/x.png)}.u{filter:url(#f)}', $base );
t_ok( has( $o, '@import "https://ex.com/wp-content/themes/t/css/a.css"' ), '@import string rewritten' );
t_ok( has( $o, 'url("https://ex.com/wp-content/themes/t/css/b.css")' ), '@import url() rewritten' );
t_ok( has( $o, 'url("https://ex.com/wp-content/themes/t/css/../img/a.png")' ), 'parent-relative url rewritten' );
t_ok( has( $o, 'url("https://ex.com/wp-content/themes/t/css/f.woff")' ), 'quoted url with spaces rewritten' );
t_ok( has( $o, 'url(data:image/png;base64,AAA=)' ) && has( $o, 'url(/abs.png)' ) && has( $o, 'url(https://c.com/x.png)' ) && has( $o, 'url(#f)' ), 'data:, root-relative, absolute and fragment urls untouched' );
t_ok( has( BEPLUSPB_UCSS::absolutize_css_urls( '.a{background:url(i.png)}', '/wp-content/t/s.css' ), 'url("/wp-content/t/i.png")' ), 'root-relative stylesheet src works' );

// --- response guard -----------------------------------------------------------
$page = "<!doctype html><html><head></head><body>x</body></html>\n";
t_ok( BEPLUSPB_UCSS::is_complete_html_response( $page, 200 ), 'complete 200 page accepted' );
t_ok( ! BEPLUSPB_UCSS::is_complete_html_response( $page, 503 ), 'maintenance 503 rejected' );
t_ok( ! BEPLUSPB_UCSS::is_complete_html_response( $page, 404 ), '404 rejected' );
t_ok( ! BEPLUSPB_UCSS::is_complete_html_response( substr( $page, 0, 40 ), 200 ), 'truncated output rejected' );
t_ok( ! BEPLUSPB_UCSS::is_complete_html_response( '', 200 ), 'empty output rejected' );
t_ok( ! BEPLUSPB_UCSS::is_complete_html_response( '{"a":1}', 200 ), 'non-HTML rejected' );

// --- page cache must not store a page that still links the full stylesheets ---
t_ok( false === BEPLUSPB_UCSS::is_building(), 'not building by default' );
t_ok( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-page-cache.php' ), 'BEPLUSPB_UCSS::is_building()' ), 'page cache consults UCSS::is_building()' );
t_ok( false === strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-css.php' ), 'function rewrite_relative_urls' ), 'CSS class reuses the shared url rewriter' );

// --- learned token union -------------------------------------------------------
$r = new ReflectionClass( 'BEPLUSPB_UCSS' );
$call = function ( $name, ...$args ) use ( $r ) { $m = $r->getMethod( $name ); $m->setAccessible( true ); return $m->invoke( null, ...$args ); };
$set = function ( $prop, $val ) use ( $r ) { $p = $r->getProperty( $prop ); $p->setAccessible( true ); $p->setValue( null, $val ); };
$set( 'url_tag', 'abc123abc123' );
t_ok( '' === $call( 'meta_version' ), 'no state yet: empty version' );
t_ok( array() === $call( 'load_tokens' ), 'no state yet: no tokens' );
t_ok( true === $call( 'save_state', array( '.a' => true, '#b' => true, 'div' => true ), 'abcdef0123', 2 ), 'state saved' );
$set( 'meta', null );
t_ok( 'abcdef0123' === $call( 'meta_version' ) && 2 === $call( 'load_meta' )['n'], 'header round-trips' );
t_ok( array( '.a' => true, '#b' => true, 'div' => true ) === $call( 'load_tokens' ), 'tokens round-trip' );
$src = $tmp . 'style.css';
file_put_contents( $src, '.a{b:c}' );
$n1 = $call( 'cache_name', 'h', $src, 'abcdef0123' );
$n2 = $call( 'cache_name', 'h', $src, '0123456789' );
t_ok( $n1 !== $n2 && preg_match( '/^ucss-abc123abc123-h-[a-f0-9]{10}\.css$/', $n1 ), 'a larger token set gets a new file name (no stale browser copy)' );
t_ok( $call( 'cache_name', 'h', $src ) === $n1, 'default version = learned version' );
file_put_contents( $tmp . 'ucss-tokens-abc123abc123.txt', "not json\n[]\n" );
$set( 'meta', null );
t_ok( '' === $call( 'meta_version' ), 'corrupt header is ignored (fails open)' );
file_put_contents( $tmp . 'ucss-tokens-abc123abc123.txt', "{\"v\":\"../../etc/pw\",\"n\":1}\n[]\n" );
$set( 'meta', null );
t_ok( '' === $call( 'meta_version' ), 'version with path characters is rejected' );
array_map( 'unlink', glob( $tmp . '*' ) );
rmdir( $tmp );

echo "OK ($n assertions)\n";
