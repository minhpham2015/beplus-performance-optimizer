<?php
/**
 * Recommended Settings: one on/off switch per managed option, saved by AJAX.
 * Run: php -n tests/test-recommended-toggles.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
require_once __DIR__ . '/helpers/admin-source.php';

class Stop extends Exception { public $payload; public $code2; }
$GLOBALS['st'] = array();
function reset_state( $post = array(), $opts = array(), $over = array() ) {
	$_POST = $post;
	$GLOBALS['st'] = array_merge( array( 'opts' => $opts, 'can' => true, 'nonce_ok' => true, 'order' => array(), 'updates' => array(), 'htaccess' => array(), 'writable' => true, 'flushed' => 0, 'gets' => 0 ), $over );
}
function __( $t, $d = '' ) { return $t; }
function add_action() {} function add_filter() {}
function sanitize_key( $v ) { return is_scalar( $v ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) : ''; }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function check_ajax_referer( $action, $field ) { $GLOBALS['st']['order'][] = "nonce:$action:$field"; if ( ! $GLOBALS['st']['nonce_ok'] ) { throw new Stop( 'bad nonce' ); } return true; }
function current_user_can( $c ) { $GLOBALS['st']['order'][] = "cap:$c"; return $GLOBALS['st']['can']; }
function get_option( $k, $d = false ) { ++$GLOBALS['st']['gets']; return $GLOBALS['st']['opts']; }
function update_option( $k, $v ) { $GLOBALS['st']['updates'][] = array( $k, $v ); $GLOBALS['st']['opts'] = $v; return true; }
function bepluspb_flush_options_cache() { ++$GLOBALS['st']['flushed']; }
function wp_send_json_success( $d = null ) { $e = new Stop( 'success' ); $e->payload = $d; throw $e; }
function wp_send_json_error( $d = null, $c = 0 ) { $e = new Stop( 'error' ); $e->payload = $d; $e->code2 = $c; throw $e; }
class BEPLUSPB_Htaccess {
	public static function is_writable() { return $GLOBALS['st']['writable']; }
	public static function add_rules() { $GLOBALS['st']['htaccess'][] = 'add'; return true; }
	public static function remove_rules() { $GLOBALS['st']['htaccess'][] = 'remove'; return true; }
}
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-recommendations.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function call() { try { BEPLUSPB_Admin::handle_ajax_toggle_recommended(); } catch ( Stop $e ) { return $e; } return null; }
$R = 'BEPLUSPB_Recommendations';

// ---- Which options can be switched --------------------------------------------
$tog = $R::toggleable();
t_ok( ! in_array( 'cache_enabled', $tog, true ) && ! in_array( 'predictive_navigation_mode', $tog, true ), 'the master switch and the Predictive mode (a choice) are not switches' );
t_ok( array() === array_diff( $tog, $R::allowlist() ) && 15 === count( $tog ), 'every switch is a managed option (15 of them)' );
foreach ( array( 'js_delay', 'css_non_blocking', 'html_minify', 'predictive_navigation_enabled', 'remove_woo_scripts', 'cache_headers', 'lazy_load' ) as $k ) { t_ok( in_array( $k, $tog, true ), "$k is a switch" ); }
foreach ( array_keys( $R::toggle_notes() ) as $k ) { t_ok( in_array( $k, $tog, true ), "a note exists only for a switch: $k" ); }
foreach ( array( 'js_delay', 'css_non_blocking', 'css_remove_unused' ) as $k ) { t_ok( isset( $R::toggle_notes()[ $k ] ), "the risky option $k carries a warning" ); }

// ---- Security order -----------------------------------------------------------
reset_state( array( 'option' => 'lazy_load', 'enabled' => '1' ), array(), array( 'nonce_ok' => false ) );
$e = call();
t_ok( 'bad nonce' === $e->getMessage() && array( 'nonce:bepluspb_toggle_recommended:nonce' ) === $GLOBALS['st']['order'] && array() === $GLOBALS['st']['updates'], 'a bad nonce stops everything, before the capability is even checked' );
reset_state( array( 'option' => 'lazy_load', 'enabled' => '1' ), array(), array( 'can' => false ) );
$e = call();
t_ok( 'error' === $e->getMessage() && 403 === $e->code2 && array( 'nonce:bepluspb_toggle_recommended:nonce', 'cap:manage_options' ) === $GLOBALS['st']['order'] && array() === $GLOBALS['st']['updates'], 'nonce first, then manage_options; a user without it saves nothing' );

// ---- Only managed on/off keys -------------------------------------------------
foreach ( array( 'cloudflare_api_token', 'cache_enabled', 'predictive_navigation_mode', 'js_exclude', 'object_cache_password', 'safe_mode_admins_only', '', '../x', 'LAZY_LOAD<script>' ) as $bad ) {
	reset_state( array( 'option' => $bad, 'enabled' => '1' ) );
	$e = call();
	t_ok( 'error' === $e->getMessage() && 400 === $e->code2 && array() === $GLOBALS['st']['updates'] && 0 === $GLOBALS['st']['gets'], 'rejected key writes nothing: ' . json_encode( $bad ) );
}
reset_state( array( 'enabled' => '1' ) ); t_ok( 'error' === call()->getMessage(), 'missing key is an error' );
reset_state( array( 'option' => array( 'lazy_load' ), 'enabled' => '1' ) ); t_ok( 'error' === call()->getMessage(), 'an array instead of a key is an error, not a crash' );

// ---- It flips exactly one key of the RAW saved row ----------------------------
$saved = array( 'js_delay' => 0, 'cloudflare_api_token' => 'secret', 'js_exclude' => 'checkout.js', 'custom_unknown' => 'keep' );
reset_state( array( 'option' => 'js_delay', 'enabled' => '1' ), $saved );
$e = call();
t_ok( 'success' === $e->getMessage() && array( 'option' => 'js_delay', 'enabled' => 1 ) === $e->payload, 'success payload' );
t_ok( 1 === count( $GLOBALS['st']['updates'] ) && 'bepluspb_settings' === $GLOBALS['st']['updates'][0][0], 'one write to the settings option' );
t_ok( array_merge( $saved, array( 'js_delay' => 1 ) ) === $GLOBALS['st']['updates'][0][1], 'only that key changed; secrets, manual fields and unknown keys kept, no defaults merged in' );
t_ok( 1 === $GLOBALS['st']['flushed'], 'the options cache is flushed' );
t_ok( array() === $GLOBALS['st']['htaccess'], 'other options never touch .htaccess' );
reset_state( array( 'option' => 'js_delay', 'enabled' => '0' ), array( 'js_delay' => 1, 'lazy_load' => 1 ) ); call();
t_ok( array( 'js_delay' => 0, 'lazy_load' => 1 ) === $GLOBALS['st']['updates'][0][1], 'switching off sets 0 and leaves the others' );
foreach ( array( '', 'abc', '0', '-0', array() ) as $v ) {
	reset_state( array( 'option' => 'lazy_load', 'enabled' => $v ), array( 'lazy_load' => 1 ) ); $e = call();
	t_ok( 0 === $e->payload['enabled'] && 0 === $GLOBALS['st']['updates'][0][1]['lazy_load'], 'anything that is not a positive number means off: ' . json_encode( $v ) );
}
reset_state( array( 'option' => 'lazy_load' ), array( 'lazy_load' => 1 ) ); $e = call();
t_ok( 0 === $e->payload['enabled'], 'a missing "enabled" means off' );
reset_state( array( 'option' => 'lazy_load', 'enabled' => '7' ), array() ); $e = call();
t_ok( 1 === $e->payload['enabled'], 'any positive number means on' );
reset_state( array( 'option' => 'lazy_load', 'enabled' => '1' ), 'not-an-array' ); call();
t_ok( array( 'lazy_load' => 1 ) === $GLOBALS['st']['updates'][0][1], 'a corrupt saved row is replaced by an array holding the one key' );

// ---- .htaccess ------------------------------------------------------------------
reset_state( array( 'option' => 'cache_headers', 'enabled' => '1' ), array() ); $e = call();
t_ok( 'success' === $e->getMessage() && array( 'add' ) === $GLOBALS['st']['htaccess'], 'browser cache on: the rules are added by hand (the settings sanitiser does not run here)' );
reset_state( array( 'option' => 'cache_headers', 'enabled' => '0' ), array( 'cache_headers' => 1 ) ); $e = call();
t_ok( 'success' === $e->getMessage() && array( 'remove' ) === $GLOBALS['st']['htaccess'], 'browser cache off: the rules are removed' );
reset_state( array( 'option' => 'cache_headers', 'enabled' => '1' ), array(), array( 'writable' => false ) ); $e = call();
t_ok( 'error' === $e->getMessage() && 409 === $e->code2 && array() === $GLOBALS['st']['updates'] && array() === $GLOBALS['st']['htaccess'], 'unwritable .htaccess: refuses to say "active", saves nothing' );
reset_state( array( 'option' => 'cache_headers', 'enabled' => '0' ), array( 'cache_headers' => 1 ), array( 'writable' => false ) ); $e = call();
t_ok( 'success' === $e->getMessage(), 'switching it off never needs a writable .htaccess to be saved' );

// ---- Wiring: hook, nonce, script, markup ---------------------------------------
$src = bepluspb_admin_source();
foreach ( array( "add_action( 'wp_ajax_bepluspb_toggle_recommended', array( __CLASS__, 'handle_ajax_toggle_recommended' ) )", "'recToggleNonce' => wp_create_nonce( 'bepluspb_toggle_recommended' )", 'class="bepluspb-rec-switch"', 'bepluspb-rec-table', 'data-recommended=', '$has_switch = in_array( $key, $toggle_keys, true ) && ( 0 === $change[\'to\'] || 1 === $change[\'to\'] )', 'role="switch"', 'data-option=', 'BEPLUSPB_Recommendations::toggleable()' ) as $needle ) {
	t_ok( false !== strpos( $src, $needle ), "admin source contains: $needle" );
}
t_ok( false === strpos( $src, 'wp_ajax_nopriv_bepluspb_toggle_recommended' ), 'no logged-out endpoint' );
// The switches live inside "Exact changes before save", one per planned change, not in a list of their own.
t_ok( false === strpos( $src, 'render_recommended_toggles' ) && false === strpos( $src, 'bepluspb-rec-toggle-list' ), 'there is no separate list of switches any more' );
$table_start = strpos( $src, 'bepluspb-rec-table' ); $table_end = strpos( $src, '</tbody></table>', $table_start );
$table = substr( $src, $table_start, $table_end - $table_start );
t_ok( false !== strpos( $table, 'foreach ( $diff as $key => $change )' ) && false !== strpos( $table, 'class="bepluspb-rec-switch"' ), 'the switches are rendered inside the loop over the plan\'s changes (only options the plan and level change get one)' );
t_ok( false !== strpos( $table, 'Applied with "Apply Recommended"' ), 'a change that is not an on/off value (the Predictive Navigation mode) has no switch' );
t_ok( false !== strpos( $table, 'data-current-for=' ) && 1 === substr_count( $table, 'checked( $is_on )' ), 'the switch starts from the real current value' );
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );
foreach ( array( "'bepluspb_toggle_recommended'", 'bepluspbAdmin.recToggleNonce', '.bepluspb-rec-switch', 'input.checked = !input.checked', 'bepluspb-rec-row--done', 'data-current-for' ) as $needle ) {
	t_ok( false !== strpos( $js, $needle ), "admin.js contains: $needle (reverts on failure)" );
}
$a = strpos( $js, 'Recommended Settings: a switch on each row' ); $b = strpos( $js, 'Update the toggle label, warning notice' );
t_ok( $a && $b > $a && false === strpos( substr( $js, $a, $b - $a ), 'innerHTML' ), 'the switch code writes text only, never HTML' );

echo "OK ($n assertions)\n";
