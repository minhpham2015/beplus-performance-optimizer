<?php
/**
 * Heartbeat control: which location a request is, which hooks each mode registers, the interval
 * limits, and the admin save/render round trip.
 *
 * Run: php -n tests/test-heartbeat.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$root = dirname( __DIR__ );

$GLOBALS['hooks']      = array();
$GLOBALS['is_admin']   = false;
$GLOBALS['doing_cron'] = false;
$GLOBALS['deregistered'] = array();
$GLOBALS['opts']       = array( 'cache_headers' => 0, 'heartbeat_interval' => 60 );
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][] = array( 'action', $tag, $cb, $prio ); }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][] = array( 'filter', $tag, $cb, $prio ); }
function is_admin() { return $GLOBALS['is_admin']; }
function wp_doing_cron() { return $GLOBALS['doing_cron']; }
function wp_deregister_script( $h ) { $GLOBALS['deregistered'][] = $h; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function absint( $v ) { return abs( (int) $v ); }
function __( $s ) { return $s; }
function esc_html_e( $s ) { echo htmlspecialchars( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function selected( $a, $b = true ) { echo (string) $a === (string) $b ? ' selected="selected"' : ''; }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function esc_url_raw( $s ) { return $s; }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function get_option( $k, $d = false ) { return $d; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_settings_error() {}
class BEPLUSPB_Page_Cache {
	const MOBILE_MODES = array( 'auto' );
	public static function sanitize_choice( $v, $allowed, $d ) { return in_array( $v, $allowed, true ) ? $v : $d; }
	public static function sanitize_ttl_hours( $v ) { return (int) $v; }
	public static function sanitize_cookie_list( $v ) { return (string) $v; }
	public static function sanitize_option_names( $v ) { return (string) $v; }
}
class BEPLUSPB_Predictive_Navigation { public static function sanitize_excludes( $v ) { return (string) $v; } }
class BEPLUSPB_CDN { public static function default_file_types() { return 'jpg'; } }
class BEPLUSPB_Object_Cache { public static function write_config( $o ) { return true; } }
class BEPLUSPB_Htaccess { public static function add_rules() {} public static function remove_rules() {} }
class BEPLUSPB_Resource_Hints { const MAX_PRECONNECT = 6; const MAX_DNS_PREFETCH = 15; public static function sanitize_host_list( $v, $l ) { return (string) $v; } public static function sanitize_keyword_list( $v ) { return (string) $v; } }

require $root . '/includes/class-bepluspb-heartbeat.php';
require $root . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function run_init( $opts, $admin, $pagenow = 'index.php' ) {
	$GLOBALS['hooks'] = array(); $GLOBALS['is_admin'] = $admin; $GLOBALS['pagenow'] = $pagenow;
	BEPLUSPB_Heartbeat::init( $opts );
	return $GLOBALS['hooks'];
}

// --- location ---------------------------------------------------------------------
$GLOBALS['is_admin'] = false; $GLOBALS['pagenow'] = 'index.php';
t_ok( 'front' === BEPLUSPB_Heartbeat::location(), 'front-end request' );
$GLOBALS['is_admin'] = false; $GLOBALS['pagenow'] = 'post.php';
t_ok( 'front' === BEPLUSPB_Heartbeat::location(), 'a front-end request is never "editor", whatever $pagenow says' );
$GLOBALS['is_admin'] = true;
foreach ( array( 'index.php', 'plugins.php', 'options-general.php', 'admin-ajax.php', 'edit.php', 'site-editor.php' ) as $pg ) {
	$GLOBALS['pagenow'] = $pg;
	t_ok( 'admin' === BEPLUSPB_Heartbeat::location(), "$pg is an admin screen" );
}
foreach ( array( 'post.php', 'post-new.php' ) as $pg ) {
	$GLOBALS['pagenow'] = $pg;
	t_ok( 'editor' === BEPLUSPB_Heartbeat::location(), "$pg is the editor" );
}

// --- modes --------------------------------------------------------------------------
t_ok( 'default' === BEPLUSPB_Heartbeat::mode_for_location( array(), 'admin' ), 'missing option = default' );
t_ok( 'default' === BEPLUSPB_Heartbeat::mode_for_location( array( 'heartbeat_admin' => 'bogus' ), 'admin' ), 'unknown mode = default' );
t_ok( 'default' === BEPLUSPB_Heartbeat::mode_for_location( array( 'heartbeat_admin' => array( 'disable' ) ), 'admin' ), 'array value = default' );
t_ok( 'disable' === BEPLUSPB_Heartbeat::mode_for_location( array( 'heartbeat_front' => 'disable' ), 'front' ), 'front disable' );
t_ok( 'slow' === BEPLUSPB_Heartbeat::mode_for_location( array( 'heartbeat_editor' => 'slow' ), 'editor' ), 'editor slow' );
t_ok( 'default' === BEPLUSPB_Heartbeat::mode_for_location( array( 'heartbeat_front' => 'disable' ), 'admin' ), 'locations are independent' );

// --- interval limits --------------------------------------------------------------------
foreach ( array( array( 0, 60 ), array( '', 60 ), array( 'abc', 60 ), array( null, 60 ), array( 1, 15 ), array( 14, 15 ), array( 15, 15 ), array( '30', 30 ), array( 120, 120 ), array( 121, 120 ), array( 99999, 120 ), array( -45, 45 ) ) as list( $in, $out ) ) {
	t_ok( $out === BEPLUSPB_Heartbeat::sanitize_interval( $in ), 'interval ' . json_encode( $in ) . ' -> ' . $out );
}

// --- what each mode registers ---------------------------------------------------------------
$h = run_init( array( 'heartbeat_front' => 'default' ), false );
t_ok( array() === $h, 'default registers nothing' );
$h = run_init( array(), false );
t_ok( array() === $h, 'no options registers nothing' );
$h = run_init( array( 'heartbeat_front' => 'disable' ), false );
t_ok( 1 === count( $h ) && 'init' === $h[0][1] && 'disable_script' === $h[0][2][1] && 1 === $h[0][3], 'disable: deregister on init priority 1' );
$h = run_init( array( 'heartbeat_front' => 'disable', 'heartbeat_admin' => 'disable' ), true, 'index.php' );
t_ok( 1 === count( $h ) && 'disable_script' === $h[0][2][1], 'only the current location is acted on' );
$h = run_init( array( 'heartbeat_admin' => 'slow' ), true, 'index.php' );
t_ok( 1 === count( $h ) && 'heartbeat_settings' === $h[0][1] && 'filter' === $h[0][0] && $h[0][3] > 10, 'slow: heartbeat_settings filter after core\'s own' );
$h = run_init( array( 'heartbeat_admin' => 'disable', 'heartbeat_editor' => 'default' ), true, 'post.php' );
t_ok( array() === $h, 'admin disabled but editor default: the editor keeps Heartbeat' );
$h = run_init( array( 'heartbeat_admin' => 'default', 'heartbeat_editor' => 'disable' ), true, 'post-new.php' );
t_ok( 1 === count( $h ) && 'disable_script' === $h[0][2][1], 'editor disable applies on post-new.php' );
$GLOBALS['doing_cron'] = true;
t_ok( array() === run_init( array( 'heartbeat_front' => 'disable' ), false ), 'cron requests are left alone' );
$GLOBALS['doing_cron'] = false;

// --- callbacks ----------------------------------------------------------------------------------
BEPLUSPB_Heartbeat::disable_script();
t_ok( array( 'heartbeat' ) === $GLOBALS['deregistered'], 'disable_script deregisters only "heartbeat"' );
$GLOBALS['opts']['heartbeat_interval'] = 90;
$s = BEPLUSPB_Heartbeat::filter_settings( array( 'nonce' => 'abc', 'ajaxurl' => '/wp-admin/admin-ajax.php' ) );
t_ok( 90 === $s['interval'] && 'abc' === $s['nonce'] && '/wp-admin/admin-ajax.php' === $s['ajaxurl'], 'slow keeps core settings, sets interval' );
$GLOBALS['opts']['heartbeat_interval'] = 5000;
t_ok( 120 === BEPLUSPB_Heartbeat::filter_settings( array() )['interval'], 'stored out-of-range interval is clamped at render time' );
t_ok( 60 === ( function () { unset( $GLOBALS['opts']['heartbeat_interval'] ); return BEPLUSPB_Heartbeat::filter_settings( array() )['interval']; } )(), 'missing interval falls back to 60' );
t_ok( array( 'interval' => 60 ) === BEPLUSPB_Heartbeat::filter_settings( 'junk' ), 'non-array settings are replaced safely' );

// --- admin: save round trip + rendered card ------------------------------------------------------------
$s = BEPLUSPB_Admin::sanitize_options( array( 'heartbeat_admin' => 'disable', 'heartbeat_editor' => 'slow', 'heartbeat_front' => 'nonsense', 'heartbeat_interval' => '9' ) );
t_ok( 'disable' === $s['heartbeat_admin'] && 'slow' === $s['heartbeat_editor'], 'save: valid modes kept' );
t_ok( 'default' === $s['heartbeat_front'], 'save: invalid mode becomes default' );
t_ok( 15 === $s['heartbeat_interval'], 'save: interval clamped to 15' );
$s = BEPLUSPB_Admin::sanitize_options( array() );
t_ok( 'default' === $s['heartbeat_admin'] && 'default' === $s['heartbeat_editor'] && 'default' === $s['heartbeat_front'] && 60 === $s['heartbeat_interval'], 'save: missing fields = defaults (Heartbeat untouched)' );

$m = new ReflectionMethod( 'BEPLUSPB_Admin', 'render_section_heartbeat' ); $m->setAccessible( true );
$opts = array( 'heartbeat_admin' => 'disable', 'heartbeat_editor' => 'default', 'heartbeat_front' => 'slow', 'heartbeat_interval' => 45 );
ob_start(); $m->invoke( null, $opts ); $html = ob_get_clean();
foreach ( array( 'heartbeat_admin', 'heartbeat_editor', 'heartbeat_front', 'heartbeat_interval' ) as $f ) {
	t_ok( false !== strpos( $html, 'name="bepluspb_settings[' . $f . ']"' ), "card has field $f" );
}
t_ok( 1 === preg_match( '/heartbeat_admin\]">.*?<option value="disable"\s+selected="selected">/s', $html ), 'card preselects the saved mode (admin = disable)' );
t_ok( 1 === preg_match( '/heartbeat_front\]">.*?<option value="slow"\s+selected="selected">/s', $html ), 'card preselects the saved mode (front = slow)' );
t_ok( false !== strpos( $html, 'value="45"' ) && false !== strpos( $html, 'min="15"' ) && false !== strpos( $html, 'max="120"' ), 'interval input shows value and limits' );

echo "PASS: $n heartbeat assertions\n";
