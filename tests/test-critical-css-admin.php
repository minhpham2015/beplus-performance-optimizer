<?php
/**
 * Critical CSS (admin side): when the generator script is loaded, what the AJAX handlers accept,
 * and safety properties of assets/js/critical-css-generator.js.
 * The script itself runs in real Chromium in docs/critical-css-browser-test.js.
 * Run: php -n tests/test-critical-css-admin.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_URL', 'https://ex.com/wp-content/plugins/beplus/' );
define( 'BEPLUSPB_VERSION', '9.9.9' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$GLOBALS['ctx'] = array(); $GLOBALS['log'] = array(); $GLOBALS['db'] = array(); $GLOBALS['opts'] = array( 'critical_css_enabled' => 1, 'css_non_blocking' => 1, 'css_inline_all' => 0 );
$GLOBALS['q'] = array( 'front' => true );
function __( $s ) { return $s; }
function add_action() {} function add_filter() {}
function is_admin() { return $GLOBALS['ctx']['admin']; } function is_admin_bar_showing() { return $GLOBALS['ctx']['bar']; }
function current_user_can( $c ) { $GLOBALS['log'][] = 'cap'; return $GLOBALS['ctx']['can']; }
function admin_url( $p = '' ) { return 'https://ex.com/wp-admin/' . $p; } function wp_create_nonce( $a ) { return 'nonce-for-' . $a; }
function wp_enqueue_script( ...$a ) { $GLOBALS['enqueued'] = $a; } function wp_localize_script( $h, $n, $d ) { $GLOBALS['localized'] = array( $h, $n, $d ); }
function wp_unslash( $v ) { return $v; } function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['log'][] = 'update'; $GLOBALS['db'][ $k ] = $v; return true; }
function is_rtl() { return false; } function is_front_page() { return $GLOBALS['q']['front']; } function is_home() { return false; } function is_singular() { return false; } function get_post_type() { return 'post'; } function is_search() { return false; } function is_archive() { return false; }
class Done extends Exception { public $payload; public $status; }
function check_ajax_referer( $a, $f ) { $GLOBALS['log'][] = 'nonce:' . $a; if ( ! $GLOBALS['ctx']['nonce_ok'] ) { $e = new Done( 'bad nonce' ); $e->status = 403; throw $e; } return 1; }
function wp_send_json_error( $d, $s = 200 ) { $e = new Done( 'error' ); $e->payload = $d; $e->status = $s; throw $e; }
function wp_send_json_success( $d = null ) { $e = new Done( 'success' ); $e->payload = $d; $e->status = 200; throw $e; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-critical-css.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-priority-images.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function reset_ctx( $over = array() ) { $GLOBALS['ctx'] = array_merge( array( 'admin' => false, 'bar' => true, 'can' => true, 'nonce_ok' => true ), $over ); $GLOBALS['log'] = array(); $GLOBALS['enqueued'] = null; $GLOBALS['localized'] = null; $GLOBALS['db'] = array(); $_POST = array(); BEPLUSPB_Critical_CSS::reset(); }
function call_h( $name ) { try { BEPLUSPB_Admin::$name(); } catch ( Done $e ) { return $e; } return null; }

// ---- generator script -----------------------------------------------------------------------
reset_ctx(); BEPLUSPB_Admin::enqueue_ccss_generator();
t_ok( 'bepluspb-ccss-generator' === $GLOBALS['enqueued'][0] && 'https://ex.com/wp-content/plugins/beplus/assets/js/critical-css-generator.js' === $GLOBALS['enqueued'][1] && true === $GLOBALS['enqueued'][4], 'administrator on the front end: generator enqueued in the footer' );
$c = $GLOBALS['localized'][2];
t_ok( 'bepluspbCcss' === $GLOBALS['localized'][1] && 'nonce-for-bepluspb_ccss_save' === $c['nonce'] && 'front' === $c['key'] && 'Front page' === $c['label'] && 0 === $c['storedBytes'] && true === $c['enabled'] && true === $c['nonBlocking'] && false === $c['inlineAll'] && 150000 === $c['maxBytes'], 'config: nonce, current page type, flags, limit' );
t_ok( ! empty( $c['i18n']['save'] ) && ! empty( $c['i18n']['needDefer'] ), 'texts provided' );
BEPLUSPB_Critical_CSS::save( 'front', 'a{b:c}' ); BEPLUSPB_Admin::enqueue_ccss_generator();
t_ok( 6 === $GLOBALS['localized'][2]['storedBytes'], 'existing entry size reported' );
$GLOBALS['opts']['critical_css_enabled'] = 0; $GLOBALS['opts']['css_non_blocking'] = 0; $GLOBALS['opts']['css_inline_all'] = 1; BEPLUSPB_Admin::enqueue_ccss_generator();
t_ok( false === $GLOBALS['localized'][2]['enabled'] && false === $GLOBALS['localized'][2]['nonBlocking'] && true === $GLOBALS['localized'][2]['inlineAll'], 'switch states reported so the panel can warn' );
$GLOBALS['opts'] = array( 'critical_css_enabled' => 1, 'css_non_blocking' => 1, 'css_inline_all' => 0 );
foreach ( array( array( 'admin' => true ), array( 'bar' => false ), array( 'can' => false ) ) as $over ) {
	reset_ctx( $over ); BEPLUSPB_Admin::enqueue_ccss_generator();
	t_ok( null === $GLOBALS['enqueued'], 'not loaded: ' . json_encode( $over ) );
}
reset_ctx(); $_GET['elementor-preview'] = '1'; BEPLUSPB_Admin::enqueue_ccss_generator(); unset( $_GET['elementor-preview'] );
t_ok( null === $GLOBALS['enqueued'], 'not inside a page-builder preview' );

// ---- save handler ---------------------------------------------------------------------------
reset_ctx(); $_POST = array( 'key' => 'front', 'css' => "body { margin: 0 }\n.hero{height:400px}", 'source' => 'generated' ); $e = call_h( 'handle_ajax_ccss_save' );
t_ok( 'success' === $e->getMessage() && 'front' === $e->payload['key'] && 'Front page' === $e->payload['label'] && 'generated' === $e->payload['source'], 'valid save: success' );
t_ok( array( 'nonce:bepluspb_ccss_save', 'cap', 'update' ) === $GLOBALS['log'], 'order: nonce, capability, write (' . implode( ',', $GLOBALS['log'] ) . ')' );
t_ok( 'body{margin:0}.hero{height:400px}' === BEPLUSPB_Critical_CSS::all()['front']['css'] && 'generated' === BEPLUSPB_Critical_CSS::all()['front']['source'], 'stored cleaned and minified, with its source' );
reset_ctx(); $_POST = array( 'key' => 'front', 'css' => 'a{b:c}', 'source' => 'whatever' ); call_h( 'handle_ajax_ccss_save' );
t_ok( 'manual' === BEPLUSPB_Critical_CSS::all()['front']['source'], 'an unknown source is stored as manual' );
reset_ctx( array( 'nonce_ok' => false ) ); $_POST = array( 'key' => 'front', 'css' => 'a{b:c}' ); $e = call_h( 'handle_ajax_ccss_save' );
t_ok( 403 === $e->status && array( 'nonce:bepluspb_ccss_save' ) === $GLOBALS['log'], 'bad nonce: stopped at once' );
reset_ctx( array( 'can' => false ) ); $_POST = array( 'key' => 'front', 'css' => 'a{b:c}' ); $e = call_h( 'handle_ajax_ccss_save' );
t_ok( 403 === $e->status && ! in_array( 'update', $GLOBALS['log'], true ) && array() === $GLOBALS['db'], 'no capability: 403, nothing stored' );
$bad = array(
	'unknown key'       => array( 'key' => 'nope', 'css' => 'a{b:c}' ),
	'key traversal'     => array( 'key' => '../../x', 'css' => 'a{b:c}' ),
	'missing key'       => array( 'css' => 'a{b:c}' ),
	'missing css'       => array( 'key' => 'front' ),
	'empty css'         => array( 'key' => 'front', 'css' => '' ),
	'array css'         => array( 'key' => 'front', 'css' => array( 'a{b:c}' ) ),
	'unbalanced css'    => array( 'key' => 'front', 'css' => 'a{b:c' ),
	'no rules'          => array( 'key' => 'front', 'css' => '/* x */ ' ),
	'huge css'          => array( 'key' => 'front', 'css' => str_repeat( '.x{color:red}', 13000 ) ),
	'absurd css'        => array( 'key' => 'front', 'css' => str_repeat( 'a', 460000 ) ),
);
foreach ( $bad as $why => $post ) {
	reset_ctx(); $_POST = $post; $e = call_h( 'handle_ajax_ccss_save' );
	t_ok( 400 === $e->status && array() === $GLOBALS['db'] && ! empty( $e->payload['message'] ), "save refused: $why" );
}
reset_ctx(); $_POST = array( 'key' => 'front', 'css' => 'a{b:c}</style><script>alert(1)</script>' ); call_h( 'handle_ajax_ccss_save' );
t_ok( false === strpos( BEPLUSPB_Critical_CSS::all()['front']['css'], '<' ), 'markup in the CSS can not be stored' );

// ---- delete handler -------------------------------------------------------------------------
reset_ctx(); BEPLUSPB_Critical_CSS::save( 'front', 'a{b:c}' ); BEPLUSPB_Critical_CSS::save( 'blog', 'b{c:d}' ); $GLOBALS['log'] = array();
$_POST = array( 'key' => 'front' ); $e = call_h( 'handle_ajax_ccss_delete' );
t_ok( 'success' === $e->getMessage() && array( 'blog' ) === array_keys( BEPLUSPB_Critical_CSS::all() ), 'delete removes only that entry' );
t_ok( array( 'nonce:bepluspb_ccss_delete', 'cap', 'update' ) === $GLOBALS['log'], 'delete: nonce (its own action), capability, write' );
$_POST = array( 'key' => 'front' ); $e = call_h( 'handle_ajax_ccss_delete' );
t_ok( 404 === $e->status, 'deleting a missing entry: 404' );
$_POST = array( 'key' => '../x' ); $e = call_h( 'handle_ajax_ccss_delete' );
t_ok( 404 === $e->status && array( 'blog' ) === array_keys( BEPLUSPB_Critical_CSS::all() ), 'an invalid key deletes nothing' );
reset_ctx( array( 'nonce_ok' => false ) ); $_POST = array( 'key' => 'blog' ); $e = call_h( 'handle_ajax_ccss_delete' );
t_ok( 403 === $e->status && array( 'nonce:bepluspb_ccss_delete' ) === $GLOBALS['log'], 'delete: bad nonce stops first' );
reset_ctx( array( 'can' => false ) ); BEPLUSPB_Critical_CSS::save( 'blog', 'b{c:d}' ); $GLOBALS['log'] = array(); $_POST = array( 'key' => 'blog' ); $e = call_h( 'handle_ajax_ccss_delete' );
t_ok( 403 === $e->status && array( 'blog' ) === array_keys( BEPLUSPB_Critical_CSS::all() ), 'delete: no capability, nothing removed' );

// ---- the script -----------------------------------------------------------------------------
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/critical-css-generator.js' );
foreach ( array( 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'localStorage', 'sendBeacon', 'XMLHttpRequest' ) as $bad_api ) {
	t_ok( false === strpos( $js, $bad_api ), "script never uses $bad_api" );
}
t_ok( 2 === substr_count( $js, 'fetch(' ) && 1 === preg_match( '/fetch\(cfg\.ajaxUrl/', $js ) && 1 === preg_match( '/fetch\(window\.location\.href\.split\(\'#\'\)\[0\]/', $js ), 'exactly two network calls, both to this site: admin-ajax.php (save) and a diagnostic GET of this very page after a failure' );
t_ok( 2 === substr_count( $js, "credentials: 'same-origin'" ), 'both requests carry only this site\'s cookies' );
t_ok( 1 === preg_match( "/redirect: 'manual'/", $js ) && 1 === preg_match( '/controller\.abort\(\); \} \/\/ headers are enough/', $js ), 'the diagnostic GET follows no redirect and is aborted once the headers are in' );
$diag_src = substr( $js, strpos( $js, 'function diagnose(' ), strpos( $js, '// Merge the two analyses' ) - strpos( $js, 'function diagnose(' ) );
t_ok( '' !== $diag_src && 0 === preg_match( '/\.(?:text|json|blob|arrayBuffer|formData)\(/', $diag_src ), 'the diagnostic request never reads the response body, only its status and headers' );
t_ok( 1 === preg_match( '/event\.origin !== window\.location\.origin/', $js ) && 1 === preg_match( '/event\.source !== frame\.contentWindow/', $js ), 'frame messages accepted only from the same origin and from the frame it created' );
t_ok( 1 === preg_match( '/postMessage\([^)]*window\.location\.origin\)/', $js ) && 0 === preg_match( '/postMessage\([^)]*[\'"]\*[\'"]\s*\)/', $js ), 'the frame posts to its own origin only (never "*")' );

echo "OK ($n assertions)\n";
