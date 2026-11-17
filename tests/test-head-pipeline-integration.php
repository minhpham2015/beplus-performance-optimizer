<?php
/**
 * The head buffers working TOGETHER with the real classes: Google Fonts (outer, priority 0 … 10000) around
 * the CSS module (inner, 1 … 9999) with Critical CSS and self-hosted fonts. Each feature has its own tests;
 * this pins how they combine.
 * Run: php -n tests/test-head-pipeline-integration.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-pipe-' . bin2hex( random_bytes( 5 ) );
mkdir( "$root/uploads/bepluspb-cache/fonts", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', "$root/uploads/bepluspb-cache/" );
define( 'WP_CONTENT_URL', 'https://ex.com/wp-content' ); define( 'WP_CONTENT_DIR', '/nonexistent/wp-content' );
$GLOBALS['db'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['opts'] = array(); $GLOBALS['page_q'] = array( 'front' => true );
function __( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; } function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['db'][ $k ] ); return true; }
function add_action( $t, $cb, $p = 10 ) { $GLOBALS['hooks'][ $t ][] = array( $cb, $p ); }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; } function wp_schedule_single_event( $ts, $h ) { $GLOBALS['cron'][ $h ] = $ts; return true; }
function is_rtl() { return false; } function is_front_page() { return ! empty( $GLOBALS['page_q']['front'] ); } function is_home() { return false; } function is_singular() { return false; } function get_post_type() { return 'post'; } function is_search() { return false; } function is_archive() { return false; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; } function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_normalize_path( $p ) { return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $p ) ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_upload_dir() { return array( 'baseurl' => 'https://ex.com/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); } function wp_is_writable( $p ) { return is_writable( $p ); } function wp_delete_file( $f ) { @unlink( $f ); }
function site_url() { return 'https://ex.com'; } function home_url() { return 'https://ex.com'; }
function get_bloginfo( $k ) { return 'stylesheet_url' === $k ? 'https://ex.com/wp-content/themes/t/style.css' : ''; }
function get_template_directory_uri() { return 'https://ex.com/wp-content/themes/t'; } function get_stylesheet_directory_uri() { return 'https://ex.com/wp-content/themes/t'; }
function wp_styles() { $o = new stdClass(); $o->registered = array( 'theme-main' => (object) array( 'src' => 'https://ex.com/wp-content/themes/t/assets/main.css' ) ); return $o; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
function wp_remote_get() { $GLOBALS['http'] = ( $GLOBALS['http'] ?? 0 ) + 1; return null; } function is_wp_error() { return true; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-critical-css.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$L = 'BEPLUSPB_Google_Fonts_Local';
$GOOGLE = 'https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap';
$id = $L::id_for( $GOOGLE );
$head = "<meta name='x'>\n"
	. "<link rel='stylesheet' id='theme-main-css' href='https://ex.com/wp-content/themes/t/assets/main.css' media='all' />\n"
	. "<link rel='stylesheet' id='plug-css' href='https://ex.com/wp-content/plugins/p/p.css' media='all' />\n"
	. "<link rel='stylesheet' id='gf-css' href='https://fonts.googleapis.com/css2?family=Heebo:wght@400&#038;display=swap' media='all' />\n"
	. "<style id='x-inline'>@import url('https://fonts.googleapis.com/css2?family=Heebo:wght@400');.a{b:c}</style>\n";

/** Run wp_head the way WordPress does: every module's callbacks in priority order, both buffers real. The head's own output (priority 8 and friends) sits between the buffer starts and ends. */
function render( $html, $over = array() ) {
	$GLOBALS['opts'] = array_merge( array( 'css_minify' => 0, 'css_non_blocking' => 1, 'css_inline_all' => 0, 'css_exclude' => '', 'css_inline_skip_elementor' => 0, 'critical_css_enabled' => 1, 'google_fonts_mode' => 'selfhost', 'google_fonts_display' => 'swap' ), $over );
	BEPLUSPB_Critical_CSS::reset(); BEPLUSPB_Google_Fonts_Local::reset(); $GLOBALS['hooks'] = array();
	// Registered CSS first, Google Fonts second, but they must RUN in priority order (GF 0 … 10000 around CSS 1 … 9999).
	BEPLUSPB_CSS::init( $GLOBALS['opts'] ); BEPLUSPB_Google_Fonts::init( $GLOBALS['opts'] );
	$cbs = array(); foreach ( $GLOBALS['hooks']['wp_head'] ?? array() as $i => $h ) { $cbs[] = array( 'prio' => $h[1], 'seq' => $i, 'cb' => $h[0] ); }
	usort( $cbs, fn( $a, $b ) => array( $a['prio'], $a['seq'] ) <=> array( $b['prio'], $b['seq'] ) );
	$base = ob_get_level(); ob_start();
	$printed = false;
	foreach ( $cbs as $c ) {
		if ( ! $printed && $c['prio'] > 1 ) { echo $html; $printed = true; } // the head's own output (priority 8 and friends) sits between the buffer starts and ends
		call_user_func( $c['cb'] );
	}
	if ( ! $printed ) { echo $html; }
	$out = ob_get_clean();
	if ( ob_get_level() !== $base ) { fwrite( STDERR, "buffer leaked\n" ); exit( 1 ); }
	return $out;
}
function deferred( $out, $idattr ) { return 1 === preg_match( '/<link[^>]*rel="preload" as="style"[^>]*id=\'' . preg_quote( $idattr, '/' ) . '\'/', $out ); }
BEPLUSPB_Critical_CSS::save( 'front', 'body{margin:0}.hero{height:400px}' );

// ---- everything on, the Google stylesheet already downloaded ------------------------------------------------------
file_put_contents( BEPLUSPB_CACHE_DIR . "fonts/$id.css", "@font-face{font-family:'Heebo';src:url(/f/h.woff2) format('woff2')}" );
$out = render( $head );
t_ok( 0 === strpos( $out, '<style id="bepluspb-critical-css">body{margin:0}.hero{height:400px}</style>' ), 'critical CSS is the very first thing (nothing is printed before it: no Google preconnect is needed)' );
t_ok( false === strpos( $out, 'googleapis' ) && false === strpos( $out, 'gstatic' ) && false === strpos( $out, 'preconnect' ), 'no mention of Google left: links, @import and preconnect are all gone' );
t_ok( 3 === preg_match_all( '#/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+#', $out ), 'the local stylesheet replaces the link, its <noscript> copy AND the @import (3 places)' );
t_ok( deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'every stylesheet is deferred (critical CSS covers the first paint), the theme\'s included' );
t_ok( 1 === preg_match( '#<link rel="preload" as="style"[^>]*id=\'gf-css\'[^>]*href=\'/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+\'#', $out ) || 1 === preg_match( '#<link rel="preload" as="style"[^>]*href=\'/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+\'[^>]*id=\'gf-css\'#', $out ), 'the font stylesheet is deferred like the others and points at the local copy' );
t_ok( 1 === preg_match( '#<noscript><link rel=\'stylesheet\' id=\'gf-css\' href=\'/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+\'#', $out ), 'the <noscript> fallback of the font stylesheet is localized too' );
t_ok( 3 === substr_count( $out, '<noscript>' ), 'one <noscript> per deferred stylesheet: theme, plugin, fonts' );
t_ok( 1 === substr_count( $out, 'bepluspb-critical-css' ) && false !== strpos( $out, ".a{b:c}" ), 'the critical <style> is printed once; the site\'s own inline style is intact' );
t_ok( 0 === ( $GLOBALS['http'] ?? 0 ) && array() === $L::queue(), 'the page view made no outgoing request and queued nothing' );

// ---- the same page before the download: Google stays, preconnect goes in front, the stylesheet is queued ------------------
unlink( BEPLUSPB_CACHE_DIR . "fonts/$id.css" );
$out = render( $head );
t_ok( 0 === strpos( $out, '<link rel="preconnect" href="https://fonts.googleapis.com">' ), 'not downloaded yet: preconnect is the first thing (outer buffer), as in Optimize mode' );
t_ok( false !== strpos( $out, '<style id="bepluspb-critical-css">' ) && strpos( $out, 'preconnect' ) < strpos( $out, 'bepluspb-critical-css' ), 'and the critical CSS follows it' );
t_ok( 1 === preg_match( '#id=\'gf-css\'[^>]*href=\'https://fonts.googleapis.com/css2\?family=Heebo:wght@400&\#038;display=swap\'#', $out ) || false !== strpos( $out, 'fonts.googleapis.com/css2?family=Heebo:wght@400&#038;display=swap' ), 'the Google stylesheet keeps its URL (still deferred by the CSS module)' );
$queued = $L::queue(); $e = reset( $queued );
t_ok( 1 === count( $queued ) && $GOOGLE === $e['url'] && isset( $GLOBALS['cron']['bepluspb_gf_fetch'] ) && 0 === ( $GLOBALS['http'] ?? 0 ), 'both spellings (link entity form, @import without display) collapse to ONE queued stylesheet; still no outgoing request' );

// ---- Optimize mode with the local file present: it must not use it -----------------------------------------------------
file_put_contents( BEPLUSPB_CACHE_DIR . "fonts/$id.css", 'x' );
$out = render( $head, array( 'google_fonts_mode' => 'optimize' ) );
t_ok( false !== strpos( $out, 'fonts.googleapis.com' ) && false === strpos( $out, '/fonts/' . $id ) && 0 === strpos( $out, '<link rel="preconnect"' ), 'Optimize mode ignores local copies and keeps Google + preconnect' );

// ---- Critical CSS off: the pipeline still works, theme CSS stays blocking ------------------------------------------------
$out = render( $head, array( 'critical_css_enabled' => 0 ) );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ) && 1 <= preg_match_all( '#/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css#', $out ), 'critical CSS off: theme CSS blocking as before, fonts still localized' );

// ---- Non-Render-Blocking off: fonts still handled, critical independent -----------------------------------------------
$out = render( $head, array( 'css_non_blocking' => 0 ) );
t_ok( 1 === substr_count( $out, 'bepluspb-critical-css' ) && deferred( $out, 'theme-main-css' ) && false === strpos( $out, 'googleapis' ), 'Non-Render-Blocking off but an entry exists: critical printed, everything deferred, fonts localized' );

// ---- Minify Inline CSS on: the critical style is printed as stored (not re-minified into something else) -----------------------
$out = render( $head, array( 'css_minify' => 1 ) );
t_ok( 0 === strpos( $out, '<style id="bepluspb-critical-css">body{margin:0}.hero{height:400px}</style>' ), 'Minify Inline CSS on: critical CSS is printed exactly as stored' );

// ---- Inline All CSS on: critical skipped, fonts untouched by the deferral ---------------------------------------------------------
$out = render( $head, array( 'css_inline_all' => 1 ) );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ), 'Inline All CSS: no critical CSS' );

// ---- a CSP that blocks inline styles: no critical CSS, theme stays blocking; fonts still localized -------------------------------
$csp = "<meta http-equiv=\"Content-Security-Policy\" content=\"style-src 'self'; script-src 'self' 'unsafe-inline'\">\n";
$out = render( $csp . $head );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'theme-main-css' ) && false === strpos( $out, 'googleapis' ), 'a CSP that forbids inline <style>: no critical CSS, theme CSS blocking, fonts still localized' );

// ---- excluded request (REST, login, builders): no buffer, nothing changes -----------------------------------------------------------
define( 'REST_REQUEST', true );
$out = render( $head );
t_ok( $out === $head, 'REST / builder / login requests: the head is returned byte for byte' );

echo "OK ($n assertions)\n";
