<?php
/**
 * LCP Analyzer (server side): when the script is loaded, what the AJAX handler accepts and saves,
 * and a few safety properties of assets/js/lcp-analyzer.js.
 * The script itself is exercised in a real browser by docs/lcp-analyzer-browser-test.js.
 * Run: php -n tests/test-lcp-analyzer.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_URL', 'https://ex.com/wp-content/plugins/beplus/' );
define( 'BEPLUSPB_VERSION', '9.9.9' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$GLOBALS['ctx'] = array( 'admin' => false, 'bar' => true, 'can' => true, 'nonce_ok' => true, 'front' => true );
$GLOBALS['log'] = array();
$GLOBALS['opts'] = array( 'priority_images' => '', 'priority_images_hints' => array() );
function is_singular() { return true; }
function is_search() { return false; }
function is_home() { return false; }
function is_archive() { return false; }
function get_post_type() { return 'post'; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function __( $s ) { return $s; }
function add_action() {} function add_filter() {}
function is_admin() { return $GLOBALS['ctx']['admin']; }
function is_admin_bar_showing() { return $GLOBALS['ctx']['bar']; }
function current_user_can( $c ) { $GLOBALS['log'][] = 'cap'; return $GLOBALS['ctx']['can']; }
function is_front_page() { return $GLOBALS['ctx']['front']; }
function apply_filters( $t, $v ) { return $v; }
function admin_url( $p = '' ) { return 'https://ex.com/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce-for-' . $a; }
function wp_enqueue_script( ...$a ) { $GLOBALS['enqueued'] = $a; }
function wp_localize_script( $h, $n, $d ) { $GLOBALS['localized'] = array( $h, $n, $d ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_unslash( $v ) { return $v; }
function home_url() { return 'https://ex.com'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function esc_url( $u ) { return $u; } function esc_attr( $s ) { return $s; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function update_option( $k, $v ) { $GLOBALS['log'][] = 'update'; $GLOBALS['saved'] = array( $k, $v ); return true; }
class Done extends Exception { public $payload; public $status; }
function check_ajax_referer( $a, $f ) { $GLOBALS['log'][] = 'nonce'; if ( ! $GLOBALS['ctx']['nonce_ok'] ) { $e = new Done( 'bad nonce' ); $e->status = 403; throw $e; } return 1; }
function wp_send_json_error( $d, $s = 200 ) { $e = new Done( 'error' ); $e->payload = $d; $e->status = $s; throw $e; }
function wp_send_json_success( $d = null ) { $e = new Done( 'success' ); $e->payload = $d; $e->status = 200; throw $e; }
$_SERVER['REQUEST_URI'] = '/About-Us/?x=1';
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-priority-images.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function reset_ctx( $over = array() ) { $GLOBALS['ctx'] = array_merge( array( 'admin' => false, 'bar' => true, 'can' => true, 'nonce_ok' => true, 'front' => true ), $over ); $GLOBALS['log'] = array(); $GLOBALS['enqueued'] = null; $GLOBALS['localized'] = null; $GLOBALS['saved'] = null; $_POST = array(); }
function call_handler() { try { BEPLUSPB_Admin::handle_ajax_lcp_apply(); } catch ( Done $e ) { return $e; } return null; }

// ---- when the script is loaded --------------------------------------------------------------
reset_ctx(); BEPLUSPB_Admin::enqueue_lcp_analyzer();
t_ok( 'bepluspb-lcp-analyzer' === $GLOBALS['enqueued'][0] && 'https://ex.com/wp-content/plugins/beplus/assets/js/lcp-analyzer.js' === $GLOBALS['enqueued'][1] && true === $GLOBALS['enqueued'][4], 'administrator on a front-end page: script enqueued in the footer' );
$c = $GLOBALS['localized'][2];
t_ok( 'bepluspbLcp' === $GLOBALS['localized'][1] && 'nonce-for-bepluspb_lcp_apply' === $c['nonce'] && true === $c['isHome'] && '/about-us/' === $c['path'] && BEPLUSPB_Priority_Images::MAX_ENTRIES === $c['maxEntries'] && false === $c['optimized'], 'config: nonce for the apply action, home flag, normalised path, limit, optimisation flag' );
t_ok( ! empty( $c['i18n']['popupBlocked'] ) && ! empty( $c['i18n']['apply'] ) && ! empty( $c['i18n']['kindBg'] ), 'texts are provided for the panel' );
reset_ctx( array( 'admin' => true ) ); BEPLUSPB_Admin::enqueue_lcp_analyzer();
t_ok( null === $GLOBALS['enqueued'], 'never in wp-admin' );
reset_ctx( array( 'bar' => false ) ); BEPLUSPB_Admin::enqueue_lcp_analyzer();
t_ok( null === $GLOBALS['enqueued'], 'not without the admin bar (visitors)' );
reset_ctx( array( 'can' => false ) ); BEPLUSPB_Admin::enqueue_lcp_analyzer();
t_ok( null === $GLOBALS['enqueued'], 'not for users who cannot manage options' );
reset_ctx(); $_GET['elementor-preview'] = '1'; BEPLUSPB_Admin::enqueue_lcp_analyzer(); unset( $_GET['elementor-preview'] );
t_ok( null === $GLOBALS['enqueued'], 'not inside a page-builder preview' );

// ---- AJAX handler ---------------------------------------------------------------------------
$good = array( array( 'url' => '/h.jpg', 'scope' => 'home', 'device' => 'desktop', 'srcset' => '/h-1.jpg 1x, /h-2.jpg 2x', 'sizes' => '100vw' ), array( 'url' => '/m.jpg', 'scope' => 'home', 'device' => 'mobile', 'srcset' => '', 'sizes' => '' ) );

reset_ctx(); $_POST['entries'] = json_encode( $good ); $e = call_handler();
t_ok( 'success' === $e->getMessage() && 2 === $e->payload['count'], 'valid payload: success' );
t_ok( array( 'nonce', 'cap', 'update' ) === $GLOBALS['log'], 'order: nonce first, then capability, then the write (' . implode( ',', $GLOBALS['log'] ) . ')' );
t_ok( 'bepluspb_settings' === $GLOBALS['saved'][0] && "/h.jpg | home | desktop\n/m.jpg | home | mobile" === $GLOBALS['saved'][1]['priority_images'], 'saved to the settings option, in canonical form' );
$ids = array_keys( $GLOBALS['saved'][1]['priority_images_hints'] );
t_ok( 1 === count( $ids ) && '/h-1.jpg 1x, /h-2.jpg 2x' === $GLOBALS['saved'][1]['priority_images_hints'][ $ids[0] ]['srcset'], 'srcset hint stored for the desktop line only' );
t_ok( isset( $GLOBALS['saved'][1]['priority_images_hints'] ) && array_key_exists( 'priority_images', $GLOBALS['saved'][1] ), 'other options are written back unchanged' );

reset_ctx( array( 'nonce_ok' => false ) ); $_POST['entries'] = json_encode( $good ); $e = call_handler();
t_ok( 403 === $e->status && array( 'nonce' ) === $GLOBALS['log'], 'bad nonce: stopped before the capability check and the write' );
reset_ctx( array( 'can' => false ) ); $_POST['entries'] = json_encode( $good ); $e = call_handler();
t_ok( 403 === $e->status && array( 'nonce', 'cap' ) === $GLOBALS['log'] && null === $GLOBALS['saved'], 'no capability: 403, nothing saved' );

foreach ( array( '' => 'missing', 'not json' => 'not json', '"string"' => 'json string', '123' => 'json number', 'null' => 'json null' ) as $payload => $why ) {
	reset_ctx(); if ( '' !== $payload ) { $_POST['entries'] = $payload; } $e = call_handler();
	t_ok( 400 === $e->status && null === $GLOBALS['saved'], "payload refused: $why" );
}
reset_ctx(); $_POST['entries'] = json_encode( array( array( 'url' => str_repeat( 'a', 21000 ) . '.jpg', 'scope' => 'home' ) ) ); $e = call_handler();
t_ok( 400 === $e->status && null === $GLOBALS['saved'], 'oversized payload refused before decoding' );
reset_ctx(); $_POST['entries'] = array( 'x' ); $e = call_handler();
t_ok( 400 === $e->status && null === $GLOBALS['saved'], 'an array instead of a JSON string is refused' );
reset_ctx(); $_POST['entries'] = json_encode( array( array( 'url' => 'javascript:alert(1)//x.jpg', 'scope' => 'home' ) ) ); $e = call_handler();
t_ok( 400 === $e->status && null === $GLOBALS['saved'], 'an invalid image is refused, nothing saved' );
reset_ctx(); $full_list = array(); for ( $i = 0; $i < BEPLUSPB_Priority_Images::MAX_ENTRIES; $i++ ) { $full_list[] = "/f$i.jpg | /p$i/"; } $GLOBALS['opts']['priority_images'] = implode( "\n", $full_list ); $_POST['entries'] = json_encode( array( array( 'url' => '/e.jpg', 'scope' => '/e/' ) ) ); $e = call_handler();
t_ok( 400 === $e->status && false !== strpos( $e->payload['message'], 'full' ) && null === $GLOBALS['saved'], 'a full list is refused with an explanation, nothing saved' );
reset_ctx(); $GLOBALS['opts']['priority_images'] = "/a.jpg | /a/\n/old.jpg | home"; $_POST['entries'] = json_encode( array( array( 'url' => '/new.jpg', 'scope' => 'home' ) ) ); $e = call_handler();
t_ok( "/a.jpg | /a/\n/new.jpg | home" === $GLOBALS['saved'][1]['priority_images'], 're-analysing a page replaces its old line and keeps other pages' );
$GLOBALS['opts']['priority_images'] = '';

// ---- the script -----------------------------------------------------------------------------
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/lcp-analyzer.js' );
foreach ( array( 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'localStorage', 'sendBeacon', 'XMLHttpRequest' ) as $bad ) {
	t_ok( false === strpos( $js, $bad ), "script never uses $bad" );
}
t_ok( 1 === preg_match( '/event\.origin !== window\.location\.origin/', $js ) && 1 === preg_match( '/event\.source !== probe\.win/', $js ), 'messages are accepted only from the same origin and from the pop-up it opened' );
t_ok( 1 === preg_match( '/postMessage\([^)]*window\.location\.origin\)/', $js ), 'the pop-up posts to its own origin only, never "*"' );
t_ok( false === strpos( $js, "postMessage( { bepluspbLcp: token, result: result }, '*'" ), 'no wildcard target origin' );
t_ok( 1 === preg_match( '/var probe = openProbe\(other\);\s*render\(\);/', $js ), 'the pop-up is opened synchronously inside the click handler (before any async work)' );
t_ok( 1 === preg_match( '/credentials: \'same-origin\'/', $js ) && 1 === preg_match( '/fetch\(cfg\.ajaxUrl/', $js ), 'the only request goes to this site\'s admin-ajax.php' );
t_ok( 1 === substr_count( $js, 'fetch(' ), 'exactly one network call in the script' );

echo "OK ($n assertions)\n";
