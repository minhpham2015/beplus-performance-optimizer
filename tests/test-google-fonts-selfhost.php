<?php
/**
 * Google Fonts "self-host" mode on the page: a downloaded stylesheet is linked from this site (no preconnect
 * needed), one that is not stays on Google and is queued; Optimize mode never uses local files.
 * Run: php -n tests/test-google-fonts-selfhost.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-gfs-' . bin2hex( random_bytes( 5 ) );
mkdir( "$root/uploads/bepluspb-cache/fonts", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', "$root/uploads/bepluspb-cache/" );
$GLOBALS['db'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['opts'] = array( 'google_fonts_mode' => 'selfhost', 'google_fonts_display' => 'swap' ); $GLOBALS['requests'] = 0;
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_upload_dir() { return array( 'baseurl' => 'https://ex.com/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); } function wp_is_writable( $p ) { return is_writable( $p ); } function wp_delete_file( $f ) { @unlink( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; } function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; $GLOBALS['writes'] = ( $GLOBALS['writes'] ?? 0 ) + 1; return true; } function delete_option( $k ) { unset( $GLOBALS['db'][ $k ] ); return true; }
function do_action() {} function add_action( $t, $cb, $p = 10 ) { $GLOBALS['hooks'][ $t ][] = array( $cb, $p ); }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; } function wp_schedule_single_event( $ts, $h ) { $GLOBALS['cron'][ $h ] = $ts; return true; }
function wp_remote_get() { ++$GLOBALS['requests']; return new WP_Error(); } class WP_Error {} function is_wp_error( $x ) { return true; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$L = 'BEPLUSPB_Google_Fonts_Local'; $G = 'BEPLUSPB_Google_Fonts';
function world() { global $L; $L::reset(); $GLOBALS['db'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['writes'] = 0; foreach ( scandir( BEPLUSPB_CACHE_DIR . 'fonts' ) as $f ) { if ( '.' !== $f && '..' !== $f ) { unlink( BEPLUSPB_CACHE_DIR . "fonts/$f" ); } } }
function make_local( $canonical ) { global $L; file_put_contents( BEPLUSPB_CACHE_DIR . 'fonts/' . $L::id_for( $canonical ) . '.css', "@font-face{font-family:'X';src:url(/f/x.woff2) format('woff2')}" ); }
function localize() { return array( 'BEPLUSPB_Google_Fonts_Local', 'frontend_localize' ); }
function run( $html, $over = null ) { return BEPLUSPB_Google_Fonts::process( $html, 'swap', $over ?? localize() ); }
$HEEBO = 'https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap';
$PRE = '<link rel="preconnect" href="https://fonts.googleapis.com">';
function local_re( $id ) { return '#/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+#'; }

// ---- a downloaded stylesheet is linked from this site -------------------------------------------------
world(); make_local( $HEEBO ); $id = $L::id_for( $HEEBO );
$out = run( "<link rel='stylesheet' id='gf-css' href='https://fonts.googleapis.com/css2?family=Heebo:wght@400&#038;display=swap' media='all' />" );
t_ok( 1 === preg_match( "#<link rel='stylesheet' id='gf-css' href='/wp-content/uploads/bepluspb-cache/fonts/$id\\.css\\?ver=\\d+' media='all' />#", $out ), 'WordPress-printed link: href replaced, every other attribute kept' );
t_ok( false === strpos( $out, 'googleapis' ) && false === strpos( $out, 'preconnect' ) && false === strpos( $out, 'gstatic' ), 'nothing left on Google, so no preconnect' );
t_ok( array() === $L::queue() && 0 === $GLOBALS['requests'], 'nothing queued, no request made' );

// display=swap is added BEFORE the lookup: a page URL without it finds the local copy of the swap version
world(); make_local( $HEEBO );
$out = run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400">' );
t_ok( 1 === preg_match( local_re( $id ), $out ) && false === strpos( $out, 'googleapis' ), 'a URL without display finds the local copy of its display=swap version' );

// entity / scheme variants are the same stylesheet
foreach ( array( 'https://fonts.googleapis.com/css2?family=Heebo:wght@400&amp;display=swap', '//fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap', 'http://fonts.googleapis.com/css2?family=Heebo:wght@400&#038;display=swap', 'https://FONTS.googleapis.com/css2?family=Heebo:wght@400&display=swap' ) as $variant ) {
	world(); make_local( $HEEBO );
	t_ok( 1 === preg_match( local_re( $id ), run( '<link rel="stylesheet" href="' . $variant . '">' ) ), "same stylesheet: $variant" );
}

// ---- not downloaded yet: stays on Google, is queued ----------------------------------------------------------
world();
$out = run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap">' );
t_ok( false !== strpos( $out, 'href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap"' ), 'the original URL is kept (fail open)' );
t_ok( 0 === strpos( $out, $PRE . "\n" . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n" ), 'and preconnect is still added for it' );
$q = $L::queue(); $e = reset( $q );
t_ok( 1 === count( $q ) && $HEEBO === $e['url'] && isset( $GLOBALS['cron']['bepluspb_gf_fetch'] ) && 0 === $GLOBALS['requests'], 'it is queued with a cron run; the visitor\'s request makes no outgoing request' );
$w = $GLOBALS['writes']; run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap">' ); $L::reset(); run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap">' );
t_ok( $w === $GLOBALS['writes'], 'later page views of an already queued stylesheet write nothing' );

// ---- mixed ----------------------------------------------------------------------------------------------------
world(); make_local( $HEEBO );
$out = run( '<link rel="stylesheet" href="' . $HEEBO . '"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap">' );
t_ok( 1 === preg_match( local_re( $id ), $out ) && 1 === substr_count( $out, 'family=Inter' ) && 0 === strpos( $out, $PRE ), 'one local, one still remote: preconnect only because one is remote' );

// ---- @import inside <style> (CSS context) -------------------------------------------------------------------
world(); make_local( $HEEBO );
$out = run( "<style>@import url('https://fonts.googleapis.com/css2?family=Heebo:wght@400');body{x:y}</style>" );
t_ok( 1 === preg_match( "#@import url\('/wp-content/uploads/bepluspb-cache/fonts/$id\\.css\\?ver=\\d+'\);body\{x:y\}#", $out ) && false === strpos( $out, 'preconnect' ), '@import url(...) is replaced inside the quotes, the rest of the CSS untouched' );
$out = run( '<style>@import "https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap";</style>' );
t_ok( 1 === preg_match( '#@import "/wp-content/[^"]+\.css\?ver=\d+";#', $out ), 'bare quoted @import handled' );

// ---- icon fonts ------------------------------------------------------------------------------------------------
world(); $ICON = 'https://fonts.googleapis.com/icon?family=Material+Icons'; make_local( $ICON );
$out = run( '<link rel="stylesheet" href="' . $ICON . '">' );
t_ok( 1 === preg_match( local_re( $L::id_for( $ICON ) ), $out ) && false === strpos( $out, 'display=' ) && false === strpos( $out, 'preconnect' ), 'icon font: localized without a display parameter' );

// ---- untouched things --------------------------------------------------------------------------------------------
world(); make_local( $HEEBO );
$js = "<script>l.href='https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap';</script>"; $cm = '<!-- <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap"> -->'; $other = '<link rel="stylesheet" href="/local.css"><a href="https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap">x</a>';
t_ok( $js === run( $js ) && $cm === run( $cm ) && $other === run( $other ), 'scripts, comments, non-<link> markup and other stylesheets are never touched' );
t_ok( $G === 'BEPLUSPB_Google_Fonts' && 'x' === run( 'x' ) && '' === run( '' ), 'pages without Google Fonts are returned as they are' );

// ---- bad callbacks keep the Google URL ---------------------------------------------------------------------------
$h = '<link rel="stylesheet" href="' . $HEEBO . '">';
foreach ( array( function () { return null; }, function () { return ''; }, function () { return false; }, function () { return 5; }, 'not_a_function', array( 'Nope', 'nope' ) ) as $bad ) {
	t_ok( false !== strpos( run( $h, $bad ), 'googleapis.com/css2' ) && 0 === strpos( run( $h, $bad ), $PRE ), 'a callback that returns nothing usable keeps the Google URL (and preconnect)' );
}
t_ok( $h !== run( $h, function ( $u ) { return '/x.css'; } ) && false === strpos( run( $h, function ( $u ) { return '/x.css'; } ), 'preconnect' ), 'a custom callback can localize (pure unit)' );

// ---- Optimize mode never uses local files -----------------------------------------------------------------------
world(); make_local( $HEEBO );
$out = BEPLUSPB_Google_Fonts::process( '<link rel="stylesheet" href="' . $HEEBO . '">', 'swap' );
t_ok( false !== strpos( $out, 'googleapis.com/css2' ) && 0 === strpos( $out, $PRE ) && array() === $L::queue(), 'process() without a callback (Optimize mode): Google URL kept, nothing queued' );

// ---- the real buffer, both modes ---------------------------------------------------------------------------------
function page( $mode ) {
	$GLOBALS['opts']['google_fonts_mode'] = $mode; $GLOBALS['hooks'] = array(); BEPLUSPB_Google_Fonts::init( $GLOBALS['opts'] );
	global $HEEBO; $base = ob_get_level(); ob_start(); BEPLUSPB_Google_Fonts::buffer_start(); echo '<meta name="x"><link rel="stylesheet" href="' . $HEEBO . '">'; BEPLUSPB_Google_Fonts::buffer_end(); $o = ob_get_clean();
	if ( ob_get_level() !== $base ) { fwrite( STDERR, "buffer leaked\n" ); exit( 1 ); } return $o;
}
world(); make_local( $HEEBO );
$o = page( 'selfhost' );
t_ok( 1 === preg_match( local_re( $id ), $o ) && 2 === count( $GLOBALS['hooks']['wp_head'] ) && false === strpos( $o, 'googleapis' ), 'self-host mode through the real wp_head buffer: local stylesheet, buffer hooks registered' );
$o = page( 'optimize' );
t_ok( false !== strpos( $o, 'googleapis.com/css2' ) && false === strpos( $o, '/fonts/' . $id ), 'Optimize mode ignores the local files even when they exist' );
$o = page( 'off' ); t_ok( array() === $GLOBALS['hooks'], 'off registers nothing' );

echo "OK ($n assertions)\n";
