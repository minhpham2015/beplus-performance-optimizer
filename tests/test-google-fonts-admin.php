<?php
/**
 * Self-hosted Google Fonts, admin side: "Download now" / "Delete local fonts" AJAX handlers.
 * Run: php -n tests/test-google-fonts-admin.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-gfa-' . bin2hex( random_bytes( 5 ) );
mkdir( "$root/uploads/bepluspb-cache", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', "$root/uploads/bepluspb-cache/" );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$GLOBALS['ctx'] = array(); $GLOBALS['log'] = array(); $GLOBALS['db'] = array(); $GLOBALS['http'] = array(); $GLOBALS['requests'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['actions'] = array();
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function __( $s ) { return $s; } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action() {} function add_filter() {} function do_action( $t ) { $GLOBALS['actions'][] = $t; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_upload_dir() { return array( 'baseurl' => 'https://ex.com/wp-content/uploads' ); } function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); } function wp_is_writable( $p ) { return is_writable( $p ); } function wp_delete_file( $f ) { @unlink( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; } function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['db'][ $k ] ); return true; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; } function wp_schedule_single_event( $ts, $h ) { $GLOBALS['cron'][ $h ] = $ts; return true; }
function size_format( $b ) { return round( $b / 1024, 1 ) . ' KB'; }
function current_user_can( $c ) { $GLOBALS['log'][] = 'cap'; return $GLOBALS['ctx']['can']; }
class Done extends Exception { public $payload; public $status; }
function check_ajax_referer( $a, $f ) { $GLOBALS['log'][] = 'nonce:' . $a; if ( ! $GLOBALS['ctx']['nonce_ok'] ) { $e = new Done( 'bad nonce' ); $e->status = 403; throw $e; } return 1; }
function wp_send_json_error( $d, $s = 200 ) { $e = new Done( 'error' ); $e->payload = $d; $e->status = $s; throw $e; }
function wp_send_json_success( $d = null ) { $e = new Done( 'success' ); $e->payload = $d; $e->status = 200; throw $e; }
function wp_remote_get( $url, $args = array() ) { $GLOBALS['requests'][] = $url; $r = $GLOBALS['http'][ $url ] ?? array( 'code' => 404, 'body' => 'nope' ); return isset( $r['wp_error'] ) ? new WP_Error( 'h', $r['wp_error'] ) : $r; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
function bepluspb_get_options() { return array( 'google_fonts_mode' => 'selfhost' ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$L = 'BEPLUSPB_Google_Fonts_Local';
function reset_ctx( $over = array() ) { global $L; $L::reset(); $GLOBALS['ctx'] = array_merge( array( 'can' => true, 'nonce_ok' => true ), $over ); $GLOBALS['log'] = array(); $GLOBALS['db'] = array(); $GLOBALS['http'] = array(); $GLOBALS['requests'] = array(); $GLOBALS['actions'] = array(); $GLOBALS['cron'] = array(); $_POST = array(); foreach ( glob( BEPLUSPB_CACHE_DIR . 'fonts/*' ) ?: array() as $f ) { unlink( $f ); } }
function call_h( $name ) { try { BEPLUSPB_Admin::$name(); } catch ( Done $e ) { return $e; } return null; }
function serve( $css_url ) { $f = 'https://fonts.gstatic.com/l/font?kit=abc&skey=s&v=v1'; $GLOBALS['http'][ $css_url ] = array( 'code' => 200, 'body' => "@font-face{font-family:'A';font-weight:400;font-display:swap;src:url($f) format('woff2')}" ); $GLOBALS['http'][ $f ] = array( 'code' => 200, 'body' => 'wOF2' . str_repeat( 'x', 100 ) ); }
$CSS = 'https://fonts.googleapis.com/css2?family=A:wght@400&display=swap';

// ---- download -------------------------------------------------------------------------------
reset_ctx(); $e = call_h( 'handle_ajax_gf_download' );
t_ok( 'success' === $e->getMessage() && false !== strpos( $e->payload['message'], 'Nothing to download yet' ) && array() === $GLOBALS['requests'], 'nothing queued, nothing local: explains how to start, makes no request' );
t_ok( array( 'nonce:bepluspb_gf_download', 'cap' ) === $GLOBALS['log'], 'order: nonce (its own action), then capability' );

reset_ctx(); $L::note_seen( $CSS ); serve( $CSS ); $e = call_h( 'handle_ajax_gf_download' );
t_ok( 'success' === $e->getMessage() && 'Downloaded: 1. Failed: 0. Still waiting: 0.' === $e->payload['message'], 'a queued stylesheet is downloaded' );
t_ok( 1 === $e->payload['sheets'] && 1 === $e->payload['fonts'] && array() === $e->payload['queue'] && false !== strpos( $e->payload['text'], '1 stylesheets and 1 font files' ), 'status payload: counts, empty queue, text' );
t_ok( in_array( 'bepluspb_google_fonts_localized', $GLOBALS['actions'], true ), 'the page cache is told' );
foreach ( $GLOBALS['requests'] as $u ) { t_ok( in_array( parse_url( $u, PHP_URL_HOST ), array( 'fonts.googleapis.com', 'fonts.gstatic.com' ), true ), "only allowed hosts: $u" ); }

$e = call_h( 'handle_ajax_gf_download' );
t_ok( false !== strpos( $e->payload['message'], 'already downloaded' ) && 1 === $e->payload['sheets'], 'queue empty but fonts present: says everything is already downloaded' );

reset_ctx(); $L::note_seen( $CSS ); $GLOBALS['http'][ $CSS ] = array( 'code' => 503, 'body' => 'x' ); $e = call_h( 'handle_ajax_gf_download' );
t_ok( 'Downloaded: 0. Failed: 1. Still waiting: 0.' === $e->payload['message'] && 1 === count( $e->payload['queue'] ), 'a failure is counted and listed' );
$item = $e->payload['queue'][0];
t_ok( $CSS === $item['url'] && 1 === $item['fails'] && false !== strpos( $item['error'], '503' ), 'the queue item shows which URL was requested and why it failed' );

// the handler takes no input
reset_ctx(); $_POST = array( 'url' => 'https://evil.test/css?family=A', 'css' => 'https://evil.test/css?family=B', 'host' => 'evil.test', 'family' => 'X' ); $e = call_h( 'handle_ajax_gf_download' );
t_ok( array() === $GLOBALS['requests'] && array() === $L::queue(), 'POST fields are ignored: no URL can be supplied through the handler' );

// access control
reset_ctx( array( 'nonce_ok' => false ) ); $L::note_seen( $CSS ); serve( $CSS ); $e = call_h( 'handle_ajax_gf_download' );
t_ok( 403 === $e->status && array( 'nonce:bepluspb_gf_download' ) === $GLOBALS['log'] && array() === $GLOBALS['requests'], 'bad nonce: stopped before the capability check, no request' );
reset_ctx( array( 'can' => false ) ); $L::note_seen( $CSS ); serve( $CSS ); $e = call_h( 'handle_ajax_gf_download' );
t_ok( 403 === $e->status && array( 'nonce:bepluspb_gf_download', 'cap' ) === $GLOBALS['log'] && array() === $GLOBALS['requests'], 'no capability: 403, no request' );

// ---- clear ------------------------------------------------------------------------------------
reset_ctx(); $L::note_seen( $CSS ); serve( $CSS ); call_h( 'handle_ajax_gf_download' ); $GLOBALS['actions'] = array(); $GLOBALS['log'] = array();
$e = call_h( 'handle_ajax_gf_clear' );
t_ok( 'success' === $e->getMessage() && 'Deleted 2 local font files.' === $e->payload['message'] && 0 === $e->payload['sheets'] && 0 === $e->payload['fonts'], 'clear: files removed, status refreshed' );
t_ok( array( 'nonce:bepluspb_gf_clear', 'cap' ) === $GLOBALS['log'] && in_array( 'bepluspb_assets_cache_cleared', $GLOBALS['actions'], true ), 'clear: its own nonce action, then capability; the page cache is told' );
reset_ctx( array( 'nonce_ok' => false ) ); $L::note_seen( $CSS ); serve( $CSS ); $L::localize_stylesheet( $CSS );
$e = call_h( 'handle_ajax_gf_clear' );
t_ok( 403 === $e->status && null !== $L::local_url( $CSS ), 'clear with a bad nonce deletes nothing' );
reset_ctx( array( 'can' => false ) ); $L::localize_stylesheet( $CSS ); serve( $CSS ); $L::localize_stylesheet( $CSS );
$e = call_h( 'handle_ajax_gf_clear' );
t_ok( 403 === $e->status && null !== $L::local_url( $CSS ), 'clear without the capability deletes nothing' );

// each action has its own nonce: a download nonce cannot clear
$src = file_get_contents( dirname( __DIR__ ) . '/includes/admin/trait-bepluspb-admin-ajax-google-fonts.php' );
t_ok( 1 === substr_count( $src, "check_ajax_referer( 'bepluspb_gf_download'" ) && 1 === substr_count( $src, "check_ajax_referer( 'bepluspb_gf_clear'" ), 'the two actions use different nonces' );
t_ok( false === strpos( $src, '$_POST[' ) || 0 === preg_match( '/\$_POST\[\s*[\'"](?:url|css|host|family)/', $src ), 'the handlers never read a URL from the request' );

echo "OK ($n assertions)\n";
