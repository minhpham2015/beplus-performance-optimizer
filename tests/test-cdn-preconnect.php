<?php
/**
 * CDN: preconnect hints for the CDN host.
 * Run: php -n tests/test-cdn-preconnect.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function home_url() { return 'https://ex.com'; }
function content_url() { return 'https://ex.com/wp-content'; }
function includes_url() { return 'https://ex.com/wp-includes/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url( $u ) { return str_replace( array( '"', "'", '<', '>' ), '', $u ); }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-cdn.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function boot( $o ) { $GLOBALS['hooks'] = array(); BEPLUSPB_CDN::init( $o ); }
function hints( $rel = 'preconnect', $in = array() ) { $cb = $GLOBALS['hooks']['wp_resource_hints'][0][0] ?? null; return $cb ? call_user_func( $cb, $in, $rel ) : $in; }
function font_tag() { ob_start(); BEPLUSPB_CDN::output_font_preconnect(); return ob_get_clean(); }

$base = array( 'cdn_enabled' => 1, 'cdn_url' => 'https://cdn.example.net', 'cdn_file_types' => 'css,js,jpg,png', 'cdn_exclude' => '' );

boot( $base );
t_ok( array( 'https://cdn.example.net' ) === hints(), 'plain preconnect for the CDN host' );
t_ok( array( 'https://other.test' ) === hints( 'dns-prefetch', array( 'https://other.test' ) ), 'other relation types untouched' );
t_ok( array( 'https://a.test', 'https://cdn.example.net' ) === hints( 'preconnect', array( 'https://a.test' ) ), 'existing hints are kept' );
t_ok( '' === font_tag(), 'no crossorigin preconnect when no font type is served' );

boot( array_merge( $base, array( 'cdn_file_types' => 'css,js,woff2' ) ) );
t_ok( '<link rel="preconnect" href="https://cdn.example.net" crossorigin>' . "\n" === font_tag(), 'crossorigin preconnect when fonts are served' );
t_ok( 2 === $GLOBALS['hooks']['wp_head'][0][1], 'font preconnect printed at wp_head priority 2' );
boot( array_merge( $base, array( 'cdn_file_types' => 'CSS, .WOFF' ) ) );
t_ok( '' !== font_tag(), 'file types are matched case-insensitively, with or without a dot' );

boot( array_merge( $base, array( 'cdn_url' => 'cdn.example.net/path' ) ) );
t_ok( array( 'https://cdn.example.net' ) === hints(), 'bare host names get https and lose the path' );
boot( array_merge( $base, array( 'cdn_url' => 'http://cdn.example.net' ) ) );
t_ok( array( 'http://cdn.example.net' ) === hints(), 'an explicit http scheme is respected' );

boot( array_merge( $base, array( 'cdn_url' => 'https://ex.com' ) ) );
t_ok( array() === hints() && '' === font_tag(), 'a CDN on the site host needs no preconnect' );
boot( array_merge( $base, array( 'cdn_url' => 'https://EX.com' ) ) );
t_ok( array() === hints(), '... compared case-insensitively' );

boot( array_merge( $base, array( 'cdn_enabled' => 0 ) ) );
t_ok( array() === $GLOBALS['hooks'], 'disabled CDN registers nothing' );
boot( array_merge( $base, array( 'cdn_url' => '' ) ) );
t_ok( array() === $GLOBALS['hooks'], 'missing CDN URL registers nothing' );

// Self-hosted Google Fonts are never moved to the CDN (cross-origin fonts need CORS the CDN may not send).
boot( array_merge( $base, array( 'cdn_file_types' => 'css,js,jpg,png,woff2' ) ) );
foreach ( array( '/wp-content/uploads/bepluspb-cache/fonts/776bd13aeb772f45.css?ver=1', 'https://ex.com/wp-content/uploads/bepluspb-cache/fonts/abc.woff2', '/wp-content/uploads/bepluspb-cache/fonts/' . str_repeat( 'a', 20 ) . '.woff2' ) as $local_font ) {
	t_ok( $local_font === BEPLUSPB_CDN::rewrite_url( $local_font ), "stays on this site: $local_font" );
}
t_ok( 'https://cdn.example.net/wp-content/uploads/2026/photo.png' === BEPLUSPB_CDN::rewrite_url( '/wp-content/uploads/2026/photo.png' ) && 'https://cdn.example.net/wp-content/uploads/bepluspb-cache/main-abc.css' === BEPLUSPB_CDN::rewrite_url( '/wp-content/uploads/bepluspb-cache/main-abc.css' ), 'other uploads and the plugin\'s minified CSS/JS are still served from the CDN' );
$html = "<link rel='stylesheet' href='/wp-content/uploads/bepluspb-cache/fonts/776bd13aeb772f45.css?ver=1'><img src='/wp-content/uploads/a.png'>";
t_ok( false !== strpos( BEPLUSPB_CDN::rewrite_html( $html ), "href='/wp-content/uploads/bepluspb-cache/fonts/776bd13aeb772f45.css?ver=1'" ) && false !== strpos( BEPLUSPB_CDN::rewrite_html( $html ), 'https://cdn.example.net/wp-content/uploads/a.png' ), 'the full-page rewrite keeps the font stylesheet local and still rewrites images' );
boot( array_merge( $base, array( 'cdn_exclude' => "my-keyword\nanother" ) ) );
t_ok( '/x/my-keyword.jpg' === BEPLUSPB_CDN::rewrite_url( '/x/my-keyword.jpg' ) && '/wp-content/uploads/bepluspb-cache/fonts/a.css' === BEPLUSPB_CDN::rewrite_url( '/wp-content/uploads/bepluspb-cache/fonts/a.css' ), 'the administrator\'s own exclusions are kept next to the built-in one' );

echo "OK ($n assertions)\n";
