<?php
/**
 * Critical CSS in the CSS module: printed only when it can bridge a deferral, never with Inline All CSS
 * or a CSP that blocks inline styles; theme CSS is deferred only then. Runs the real wp_head buffer.
 * Run: php -n tests/test-critical-css-injection.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
require_once __DIR__ . "/helpers/exclude-list.php";
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['db'] = array(); $GLOBALS['opts'] = array(); $GLOBALS['q'] = array( 'front' => true );
function __( $s ) { return $s; }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; return true; }
function is_rtl() { return false; } function is_front_page() { return ! empty( $GLOBALS['q']['front'] ); } function is_home() { return false; }
function is_singular() { return false; } function get_post_type() { return 'post'; } function is_search() { return false; } function is_archive() { return false; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function site_url() { return 'https://ex.com'; } function home_url() { return 'https://ex.com'; }
function wp_normalize_path( $p ) { return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $p ) ); }
define( 'WP_CONTENT_URL', 'https://ex.com/wp-content' ); define( 'WP_CONTENT_DIR', '/nonexistent/wp-content' );
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_bloginfo( $k ) { return 'stylesheet_url' === $k ? 'https://ex.com/wp-content/themes/t/style.css' : ''; }
function get_template_directory_uri() { return 'https://ex.com/wp-content/themes/t'; }
function get_stylesheet_directory_uri() { return 'https://ex.com/wp-content/themes/t'; }
function wp_styles() { $o = new stdClass(); $o->registered = array( 'theme-main' => (object) array( 'src' => 'https://ex.com/wp-content/themes/t/assets/main.css' ) ); return $o; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = $h . ':' . ( is_array( $cb ) ? $cb[1] : $cb ); }
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['hooks'][] = $h . ':' . ( is_array( $cb ) ? $cb[1] : $cb ); }
function apply_filters( $tag, $value ) { return 'bepluspb_late_css_defer_handles' === $tag && isset( $GLOBALS['late_handles'] ) ? $GLOBALS['late_handles'] : $value; }
function did_action( $h ) { return ! empty( $GLOBALS['late_ctx']['did'] ) ? 1 : 0; }
function doing_action( $h ) { return ! empty( $GLOBALS['late_ctx']['doing'] ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-critical-css.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$defaults = array( 'css_minify' => 0, 'css_non_blocking' => 1, 'css_inline_all' => 0, 'css_exclude' => '', 'critical_css_enabled' => 1, 'css_inline_skip_elementor' => 0 );
$head = "<meta name='x'>\n<link rel='stylesheet' id='theme-main-css' href='https://ex.com/wp-content/themes/t/assets/main.css' media='all' />\n<link rel='stylesheet' id='plug-css' href='https://ex.com/wp-content/plugins/p/p.css' media='all' />\n";
function run_head( $html, $over = array() ) {
	global $defaults; $GLOBALS['opts'] = array_merge( $defaults, $over ); BEPLUSPB_Critical_CSS::reset();
	$base = ob_get_level(); ob_start(); BEPLUSPB_CSS::buffer_start(); echo $html; BEPLUSPB_CSS::buffer_end(); $out = ob_get_clean();
	if ( ob_get_level() !== $base ) { fwrite( STDERR, "buffer level leaked\n" ); exit( 1 ); }
	return $out;
}
function deferred( $out, $id ) { return 1 === preg_match( '/<link[^>]*rel="preload" as="style"[^>]*id=\'' . preg_quote( $id, '/' ) . '\'/', $out ); }
BEPLUSPB_Critical_CSS::save( 'front', 'body{margin:0}.hero{height:400px}' );

$out = run_head( $head );
t_ok( 0 === strpos( $out, '<style id="bepluspb-critical-css">body{margin:0}.hero{height:400px}</style>' . "\n" ), 'critical CSS is the very first thing of the head buffer' );
t_ok( deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'with critical CSS the theme stylesheet is deferred as well' );
t_ok( 1 === substr_count( $out, 'bepluspb-critical-css' ), 'printed once' );

$out = run_head( $head, array( 'critical_css_enabled' => 0 ) );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'feature off: no critical CSS, theme CSS stays blocking (as before)' );
$out = run_head( $head, array( 'css_non_blocking' => 0 ) );
t_ok( 1 === substr_count( $out, 'bepluspb-critical-css' ) && deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'independent of Non-Render-Blocking: entry + switch off -> critical printed, all CSS (theme too) deferred' );
$out = run_head( $head, array( 'css_non_blocking' => 0, 'css_minify' => 1 ) );
t_ok( 1 === substr_count( $out, 'bepluspb-critical-css' ) && deferred( $out, 'theme-main-css' ), 'also with Minify Inline CSS alone on' );
$out = run_head( $head, array( 'css_inline_all' => 1 ) );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ), 'with Inline All CSS it is pointless and not printed' );

$GLOBALS['q'] = array( 'front' => false );
$out = run_head( $head );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'theme-main-css' ), 'page type without an entry (and no default): nothing printed, theme CSS blocking' );
$out = run_head( $head, array( 'css_non_blocking' => 0 ) );
t_ok( $out === $head, 'no entry + Non-Render-Blocking off: head byte-for-byte unchanged' );
$base = ob_get_level(); BEPLUSPB_CSS::buffer_start(); $opened = ob_get_level() > $base; while ( ob_get_level() > $base ) { ob_end_clean(); }
t_ok( ! $opened, 'no entry + only Critical CSS on: no buffer opened' );
$out = run_head( $head, array( 'css_non_blocking' => 1 ) );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'no entry + switch on: partial deferral as before' );
BEPLUSPB_Critical_CSS::save( 'default', '.d{color:red}' );
$out = run_head( $head );
t_ok( 0 === strpos( $out, '<style id="bepluspb-critical-css">.d{color:red}</style>' ) && deferred( $out, 'theme-main-css' ), 'default entry used for other page types' );
$GLOBALS['q'] = array( 'front' => true );

// CSP
$csp_style = "<meta http-equiv=\"Content-Security-Policy\" content=\"style-src 'self'\">\n";
$out = run_head( $csp_style . $head );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ), "a CSP that blocks inline <style> (meta): critical CSS is not printed" );
t_ok( ! deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), 'and the theme stylesheet is not deferred without critical CSS' );
$csp_ok = "<meta http-equiv=\"Content-Security-Policy\" content=\"style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'\">\n";
$out = run_head( $csp_ok . $head );
t_ok( false !== strpos( $out, 'bepluspb-critical-css' ), "'unsafe-inline' for both styles and scripts: printed" );
$csp_script = "<meta http-equiv=\"Content-Security-Policy\" content=\"script-src 'self'\">\n";
$out = run_head( $csp_script . $head );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ) && ! deferred( $out, 'plug-css' ), 'a CSP that blocks the inline onload: nothing is deferred, so no critical CSS is printed' );

// exclusions still win
$out = run_head( $head, array( 'css_exclude' => 'assets/main.css' ) );
t_ok( 0 === strpos( $out, '<style id="bepluspb-critical-css">' ) && ! deferred( $out, 'theme-main-css' ) && deferred( $out, 'plug-css' ), '"Exclude CSS Files" keeps a stylesheet blocking even with critical CSS' );
// nothing to defer
$out = run_head( "<meta name='x'>\n" );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ), 'a head without stylesheets gets no critical CSS' );
$out = run_head( "<link rel='stylesheet' href='/a.css' media='print'>\n" );
t_ok( false === strpos( $out, 'bepluspb-critical-css' ), 'only print stylesheets: nothing deferred, nothing printed' );

// a tampered option (HTML in the stored CSS) is never printed
$GLOBALS['db'][ BEPLUSPB_Critical_CSS::OPTION ]['front'] = array( 'css' => 'a{b:c}</style><script>alert(1)</script>' );
$out = run_head( $head );
t_ok( false === strpos( $out, '<script>' ) && 1 === substr_count( $out, '</style>' ) && 0 === strpos( $out, '<style id="bepluspb-critical-css">.d{color:red}</style>' ), 'a stored entry containing "<" is ignored (the valid default entry is used instead), never printed' );

// ---- stylesheets printed after wp_head (footer/body): the popup stylesheet of Elementor Pro ---------------------------------
$popup = "<link rel='stylesheet' id='e-popup-css' href='https://ex.com/wp-content/plugins/elementor-pro/assets/css/conditionals/popup.min.css?ver=4.4.1' media='all' />\n";
$other = "<link rel='stylesheet' id='widget-x-css' href='https://ex.com/wp-content/plugins/p/x.css' media='all' />\n";
$late  = function ( $over, $tag = null, $handle = 'e-popup', $state = array( 'did' => 1 ) ) use ( $defaults, $popup ) {
	$GLOBALS['opts'] = array_merge( $defaults, array( 'css_non_blocking' => 0, 'critical_css_enabled' => 0 ), $over ); $GLOBALS['late_ctx'] = $state; BEPLUSPB_Critical_CSS::reset();
	return BEPLUSPB_CSS::defer_late_style( null === $tag ? $popup : $tag, $handle, 'x', 'all' );
};
$swapped = function ( $out ) { return 1 === preg_match( '/<link rel="preload" as="style" onload="[^"]*"[^>]*id=\'e-popup-css\'/', $out ) && false !== strpos( $out, "<noscript><link rel='stylesheet' id='e-popup-css'" ); };
t_ok( $swapped( $late( array( 'css_non_blocking' => 1 ) ) ), 'Non-Render-Blocking on: the popup stylesheet printed after wp_head is deferred (preload + swap + noscript)' );
t_ok( $popup === $late( array( 'css_non_blocking' => 1 ), null, 'e-popup', array( 'did' => 1, 'doing' => 1 ) ), 'a stylesheet printed BY wp_head is left to the head buffer' );
t_ok( $popup === $late( array( 'css_non_blocking' => 1 ), null, 'e-popup', array( 'did' => 0 ) ), 'a stylesheet printed before wp_head is left alone' );
t_ok( $other === $late( array( 'css_non_blocking' => 1 ), $other, 'widget-x' ), 'only the listed handles are deferred' );
$GLOBALS['late_handles'] = array( 'e-popup', 'widget-x' );
t_ok( false !== strpos( $late( array( 'css_non_blocking' => 1 ), $other, 'widget-x' ), 'rel="preload"' ), 'the bepluspb_late_css_defer_handles filter extends the list' );
unset( $GLOBALS['late_handles'] );
BEPLUSPB_Critical_CSS::save( 'front', 'body{margin:0}' );
$GLOBALS['q'] = array( 'front' => true );
t_ok( $swapped( $late( array( 'critical_css_enabled' => 1 ) ) ), 'Critical CSS alone, page has an entry: deferred' );
$GLOBALS['q'] = array( 'front' => false ); BEPLUSPB_Critical_CSS::delete( 'default' );
t_ok( $popup === $late( array( 'critical_css_enabled' => 1 ) ), 'Critical CSS alone, page has no entry: untouched' );
$GLOBALS['q'] = array( 'front' => true );
t_ok( $popup === $late( array() ), 'both switches off: untouched even though an entry exists' );
t_ok( $popup === $late( array( 'css_non_blocking' => 1, 'css_inline_all' => 1 ) ), 'Inline All CSS: untouched' );
$meta = "<meta http-equiv=\"Content-Security-Policy\" content=\"script-src 'self' 'nonce-abc'\">";
run_head( "<meta name='x'>\n$meta\n" . $other );
t_ok( $popup === $late( array( 'css_non_blocking' => 1 ) ), 'a Content-Security-Policy seen in the head blocks the late swap too' );
run_head( "<meta name='x'>\n" . $other );
t_ok( $swapped( $late( array( 'css_non_blocking' => 1 ) ) ), 'without that policy it is deferred again' );
$GLOBALS['hooks'] = array(); BEPLUSPB_CSS::init( array_merge( $defaults, array( 'css_non_blocking' => 1 ) ) );
t_ok( in_array( 'style_loader_tag:defer_late_style', $GLOBALS['hooks'], true ), 'init registers the late-style filter with Non-Render-Blocking on' );
$GLOBALS['hooks'] = array(); BEPLUSPB_CSS::init( array_merge( $defaults, array( 'css_non_blocking' => 0, 'critical_css_enabled' => 0, 'css_minify' => 1 ) ) );
t_ok( ! in_array( 'style_loader_tag:defer_late_style', $GLOBALS['hooks'], true ), 'not registered when neither deferral feature is on' );

echo "OK ($n assertions)\n";
