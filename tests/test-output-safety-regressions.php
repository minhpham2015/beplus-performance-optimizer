<?php
/**
 * Regression tests: PCRE-failure guard, Delay JS inline-script integrity,
 * defer safety, and Remove Unused CSS serving rules.
 *
 * Run: php -n tests/test-output-safety-regressions.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-osr-' . bin2hex( random_bytes( 4 ) );
mkdir( "$root/cache", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
define( 'BEPLUSPB_CACHE_DIR', "$root/cache/" );

$GLOBALS['logged_in'] = false; $GLOBALS['after_data'] = array();
function add_action() {} function add_filter() {}
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function bepluspb_get_options() { return array( 'js_exclude' => '', 'js_delay_rdelay' => 0, 'css_unused_exclude' => '' ); }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
function wp_generate_password() { return 'abcdefghijklmnop'; }
function wp_json_encode( $a ) { return json_encode( $a ); }
function esc_attr( $s ) { return $s; } function esc_url( $s ) { return $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $n ); }
class WPS_Stub { function get_data( $h, $k ) { return isset( $GLOBALS['after_data'][ $h ] ) && 'after' === $k ? $GLOBALS['after_data'][ $h ] : false; } }
function wp_scripts() { return new WPS_Stub(); }
class BEPLUSPB_Minify {
	public static $path = '';
	public static function is_css_file_excluded( $s ) { return false; }
	public static function url_to_path( $s ) { return self::$path; }
	public static function cache_url() { return 'http://example.test/cache/'; }
}
function t_ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-html.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-ucss.php';

// --- guard_transform --------------------------------------------------------
t_ok( 'orig' === BEPLUSPB_Utils::guard_transform( null, 'orig' ), 'null result falls back' );
t_ok( 'orig' === BEPLUSPB_Utils::guard_transform( '', 'orig' ), 'blanked result falls back' );
t_ok( '' === BEPLUSPB_Utils::guard_transform( '', '' ), 'empty in / empty out is fine' );
t_ok( 'new' === BEPLUSPB_Utils::guard_transform( 'new', 'orig' ), 'good result kept' );
$big = '<html><script>' . str_repeat( "var a = 'x'; // c\n", 400000 ) . '</script></html>';
$res = BEPLUSPB_HTML::strip_inline_js_comments( $big );
$g = BEPLUSPB_Utils::guard_transform( $res, $big );
t_ok( is_string( $g ) && '' !== $g && 0 === strpos( $g, '<html>' ), 'oversized inline script never yields a blank page' );

// --- Delay JS (advanced): inline-script strings are not rewritten ----------
$html = '<html><body><script>var tpl = "<img src=x onerror=\"fix(this)\">";</script><img src="a.jpg" onload="ok()"></body></html>';
$out  = BEPLUSPB_JS::advanced_rewrite( $html );
t_ok( false !== strpos( $out, 'var tpl = "<img src=x onerror=\"fix(this)\">";' ), 'inline JS string left byte-identical: ' . $out );
t_ok( false !== strpos( $out, 'data-bepluspb-onload="ok()"' ), 'real <img onload> still intercepted' );

// --- defer ------------------------------------------------------------------
$tag = '<script>var cfg = "<b src=1>";</script><script src="http://example.test/a.js"></script>';
$d   = BEPLUSPB_JS::defer_scripts( $tag, 'plain', 'http://example.test/a.js' );
t_ok( false !== strpos( $d, '<script defer src="http://example.test/a.js">' ) && false !== strpos( $d, '"<b src=1>"' ), 'defer touches only the script tag src: ' . $d );
$GLOBALS['after_data']['needs-after'] = array( 'init();' );
t_ok( $tag === BEPLUSPB_JS::defer_scripts( $tag, 'needs-after', 'http://example.test/a.js' ), 'handles with inline "after" code are not deferred' );

// --- Remove Unused CSS: cached file not served to logged-in users ----------
$src_css = "$root/style.css"; file_put_contents( $src_css, 'a{b:c}' ); BEPLUSPB_Minify::$path = $src_css;
$cp = new ReflectionMethod( BEPLUSPB_UCSS::class, 'cache_path' ); $cp->setAccessible( true );
$ut = new ReflectionProperty( BEPLUSPB_UCSS::class, 'url_tag' ); $ut->setAccessible( true ); $ut->setValue( null, 'abc' );
file_put_contents( $cp->invoke( null, 'h', $src_css ), 'a{}' ); touch( $cp->invoke( null, 'h', $src_css ), time() + 5 );
$url = 'http://example.test/style.css?ver=1';
$GLOBALS['logged_in'] = false;
t_ok( 0 === strpos( BEPLUSPB_UCSS::maybe_serve_cached( $url, 'h' ), 'http://example.test/cache/ucss-abc-h-' ), 'anonymous visitors get the cached used-only CSS' );
$GLOBALS['logged_in'] = true;
t_ok( $url === BEPLUSPB_UCSS::maybe_serve_cached( $url, 'h' ), 'logged-in users keep the original stylesheet' );

echo "PASS: output safety regressions\n";
