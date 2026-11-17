<?php
/**
 * Google Fonts "Optimize": font-display and preconnect on the buffered head.
 * Run: php -n tests/test-google-fonts.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
$GLOBALS['excluded'] = false;
$GLOBALS['opts'] = array( 'google_fonts_mode' => 'optimize', 'google_fonts_display' => 'swap' );
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function bepluspb_get_options() { return $GLOBALS['opts']; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$G = 'BEPLUSPB_Google_Fonts';
function run( $html, $display = 'swap' ) { return BEPLUSPB_Google_Fonts::process( $html, $display ); }
$pre_api  = '<link rel="preconnect" href="https://fonts.googleapis.com">';
$pre_stat = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';

// ---- <link> tags -----------------------------------------------------------------------
$out = run( "<link rel='stylesheet' id='gf-css' href='https://fonts.googleapis.com/css?family=Roboto%3A400%2C700&#038;ver=6.5' media='all' />" );
t_ok( false !== strpos( $out, "href='https://fonts.googleapis.com/css?family=Roboto%3A400%2C700&#038;ver=6.5&#038;display=swap'" ), "WordPress-printed link: appended with the same '&#038;' entity" );
t_ok( 0 === strpos( $out, $pre_api . "\n" . $pre_stat . "\n" ), 'both preconnect links are put first (gstatic with crossorigin)' );

$out = run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700">' );
t_ok( false !== strpos( $out, 'href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&amp;display=swap"' ), 'css2 URL with a bare "&" or none: appended with &amp;' );
$out = run( '<link rel="stylesheet" href="//fonts.googleapis.com/css?family=Lato">' );
t_ok( false !== strpos( $out, 'href="//fonts.googleapis.com/css?family=Lato&amp;display=swap"' ), 'protocol-relative URL handled' );
$out = run( '<link rel="stylesheet" href="http://fonts.googleapis.com/css?family=Lato|Open+Sans:400,700">' );
t_ok( false !== strpos( $out, 'Open+Sans:400,700&amp;display=swap"' ), 'old family=A|B syntax handled' );
foreach ( array( 'fallback', 'optional' ) as $d ) {
	t_ok( false !== strpos( run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">', $d ), "display=$d" ), "display=$d honoured" );
}
t_ok( false !== strpos( run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">', 'block' ), 'display=swap' ), 'an unknown value falls back to swap' );
t_ok( false !== strpos( run( "<link rel=\"preload\" as=\"style\" href=\"https://fonts.googleapis.com/css?family=A\" onload=\"this.rel='stylesheet'\"><noscript><link rel=\"stylesheet\" href=\"https://fonts.googleapis.com/css?family=A\"></noscript>" ), 'family=A&amp;display=swap" onload' ), 'preload links are rewritten too' );
t_ok( 2 === substr_count( run( "<link rel=\"preload\" as=\"style\" href=\"https://fonts.googleapis.com/css?family=A\"><noscript><link rel=\"stylesheet\" href=\"https://fonts.googleapis.com/css?family=A\"></noscript>" ), 'display=swap' ), '... and so is the noscript copy' );

// An existing display is respected (compared with what the page already had).
function count_display( $h ) { return substr_count( strtolower( $h ), 'display=' ); }
foreach ( array( 'display=swap', 'display=block', 'display=auto', 'display=optional', 'DISPLAY=swap' ) as $existing ) {
	$h   = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter&amp;' . $existing . '">';
	$out = run( $h );
	t_ok( 1 === count_display( $out ) && false !== strpos( $out, $h ), "existing $existing kept, nothing added" );
}
t_ok( 1 === count_display( run( '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A&#038;display=swap&#038;ver=1">' ) ), 'display in the middle of the query is detected' );

// Icon fonts and non-font URLs are left alone.
foreach ( array( 'https://fonts.googleapis.com/icon?family=Material+Icons', 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined', 'https://fonts.googleapis.com/css?family=Font+Awesome+5', 'https://fonts.googleapis.com/css?subset=latin', 'https://fonts.googleapis.com/earlyaccess/notosanshebrew.css' ) as $u ) {
	$h = '<link rel="stylesheet" href="' . $u . '">';
	t_ok( false === strpos( run( $h ), 'display=' ), "left alone: $u" );
}
// Other hosts and look-alikes.
foreach ( array( 'https://example.com/css?family=A', 'https://fonts.googleapis.com.evil.test/css?family=A', 'https://evil.test/https://fonts.googleapis.com/css?family=A', 'https://notfonts.googleapis.com/css?family=A' ) as $u ) {
	$h = '<link rel="stylesheet" href="' . $u . '">';
	t_ok( $h === run( $h ), "not touched: $u" );
}
// Only <link> tags: other attributes/elements with the URL are untouched.
$h = '<a href="https://fonts.googleapis.com/css?family=A">x</a><meta content="https://fonts.googleapis.com/css?family=A">';
t_ok( false === strpos( run( $h ), 'display=' ), 'only <link> href attributes are rewritten' );
$h = '<link rel="stylesheet" data-href="https://fonts.googleapis.com/css?family=A" href="/local.css">';
t_ok( false === strpos( run( $h ), 'display=' ), 'data-href is not href' );

// ---- <style> @import -------------------------------------------------------------------
$out = run( "<style id='x'>@import url('https://fonts.googleapis.com/css?family=Roboto:400,700&subset=latin');body{font-family:Roboto}</style>" );
t_ok( false !== strpos( $out, "subset=latin&display=swap');body" ), '@import url(...) inside <style>: plain "&", never an entity' );
t_ok( false === strpos( $out, '&amp;display' ), 'no HTML entity in CSS' );
$out = run( '<style>@import url(https://fonts.googleapis.com/css2?family=Inter);@import "https://fonts.googleapis.com/css?family=Lato";</style>' );
t_ok( false !== strpos( $out, 'css2?family=Inter&display=swap)' ) && false !== strpos( $out, 'family=Lato&display=swap"' ), 'unquoted url() and bare quoted @import handled' );
t_ok( 0 === strpos( $out, $pre_api ), 'preconnect also added for @import users' );

// ---- scripts and comments are never touched --------------------------------------------
$js = "<script>var l=document.createElement('link');l.href='https://fonts.googleapis.com/css?family=Roboto';</script>";
t_ok( $js === run( $js ), 'script content untouched, no preconnect for a URL that is only in JS' );
$cm = '<!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A"> -->';
t_ok( $cm === run( $cm ), 'comments untouched' );
$mixed = $js . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">' . $cm;
$out = run( $mixed );
t_ok( false !== strpos( $out, $js ) && false !== strpos( $out, $cm ) && false !== strpos( $out, 'family=A&amp;display=swap' ), 'markup rewritten around untouched script and comment' );

// ---- preconnect ------------------------------------------------------------------------
$both = "<link rel='preconnect' href='https://fonts.googleapis.com' /><link rel='preconnect' href='https://fonts.gstatic.com' crossorigin />";
$h = $both . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
t_ok( 0 === strpos( run( $h ), '<link rel=\'preconnect\'' ), 'existing preconnect links: none duplicated' );
t_ok( 2 === substr_count( run( $h ), 'rel=\'preconnect\'' ) && 0 === substr_count( run( $h ), 'rel="preconnect"' ), '... exactly the two that were there' );
$h = '<link href="https://fonts.gstatic.com" rel="preconnect" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
$out = run( $h );
t_ok( 1 === substr_count( $out, 'fonts.gstatic.com' ) && 1 === substr_count( $out, $pre_api ), 'attribute order does not matter; only the missing host is added' );
$h = '<link rel="dns-prefetch" href="//fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
t_ok( false !== strpos( run( $h ), $pre_api ), 'dns-prefetch is not a preconnect' );
$h = '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
t_ok( 1 === substr_count( run( run( $h ) ), 'display=swap' ) && 1 === substr_count( run( run( $h ) ), 'fonts.gstatic.com' ), 'running twice changes nothing more (idempotent)' );
t_ok( false !== strpos( run( '<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">' ), $pre_api ), 'icon-font pages still get preconnect (the CSS is fetched from Google)' );

// ---- pages without Google Fonts / robustness -------------------------------------------
$plain = '<meta charset="utf-8"><link rel="stylesheet" href="/a.css"><title>x</title>';
t_ok( $plain === run( $plain ), 'no Google Fonts: byte-identical, no preconnect' );
t_ok( '' === run( '' ) && null === run( null ), 'empty / non-string input returned as is' );
t_ok( 'x' === run( 'x' ), 'tiny input' );
$big = str_repeat( '<meta name="x" content="y">', 5000 ) . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
t_ok( false !== strpos( run( $big ), 'family=A&amp;display=swap' ), 'large head handled' );
t_ok( false !== strpos( run( "<LINK REL='STYLESHEET' HREF='https://FONTS.googleapis.com/css?family=A'>" ), 'display=swap' ), 'upper-case tags and host handled' );
t_ok( false !== strpos( run( "<link rel='stylesheet'\n  href=\"https://fonts.googleapis.com/css?family=A\"\n>" ), 'display=swap' ), 'attributes spread over lines handled' );
$unclosed = '<style>@import url(https://fonts.googleapis.com/css?family=A);';
t_ok( is_string( run( $unclosed ) ), 'unclosed <style> does not break' );

// ---- buffer hooks ----------------------------------------------------------------------
$G::init( array( 'google_fonts_mode' => 'off' ) );
t_ok( array() === $GLOBALS['hooks'], 'off registers nothing' );
$G::init( array() );
t_ok( array() === $GLOBALS['hooks'], 'missing option registers nothing' );
$G::init( array( 'google_fonts_mode' => 'optimize' ) );
t_ok( 0 === $GLOBALS['hooks']['wp_head'][0][1] && 10000 === $GLOBALS['hooks']['wp_head'][1][1], 'buffer brackets wp_head (0 .. 10000), outside the CSS module buffer (1 .. 9999)' );

// Real buffering round trip, nested with another buffer the way the CSS module nests.
$base = ob_get_level();
ob_start();
$G::buffer_start();
echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=A">';
ob_start();                    // an inner buffer of another module, closed before ours
echo '<meta name="inner">';
echo ob_get_clean();
$G::buffer_end();
$page = ob_get_clean();
t_ok( ob_get_level() === $base && false !== strpos( $page, 'display=swap' ) && false !== strpos( $page, '<meta name="inner">' ) && 0 === strpos( $page, $pre_api ), 'buffer round trip keeps nested output and rewrites the head' );

// A buffer opened on top of ours and never closed must not be consumed (level check).
ob_start();
$G::buffer_start();
echo 'x';
ob_start();                    // someone else's buffer still open at wp_head 10000
$G::buffer_end();              // must refuse
t_ok( ob_get_level() === $base + 3, 'does not close a buffer it did not open' );
ob_end_clean(); ob_end_clean(); ob_end_clean();

$GLOBALS['excluded'] = false;
echo "OK ($n assertions)\n";
