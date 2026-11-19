<?php
/**
 * Safe testing: `?bepluspb_off` per request and the "administrators only" mode.
 * Runs the real bepluspb_boot_frontend() (extracted from the bootstrap file) against stub modules.
 * Run: php -n tests/test-safe-testing.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
require_once __DIR__ . '/helpers/admin-source.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

$boot_src = file_get_contents( dirname( __DIR__ ) . '/beplus-performance-booster.php' );
foreach ( array( 'bepluspb_boot_frontend', 'bepluspb_is_bypass_request' ) as $fn ) {
	t_ok( 1 === preg_match( '/^function ' . $fn . '\(\) \{.*?^\}\n/ms', $boot_src, $m ), "found $fn in the bootstrap" );
	eval( $m[0] );
}

$GLOBALS['calls'] = array(); $GLOBALS['state'] = array();
foreach ( array( 'Minify', 'JS', 'Cleanup', 'CSS', 'UCSS', 'Images', 'Lazy_HTML', 'Priority_Images', 'Resource_Hints', 'Google_Fonts', 'HTML', 'CDN', 'Page_Cache' ) as $c ) {
	eval( "class BEPLUSPB_$c { public static function init( \$o ) { \$GLOBALS['calls'][] = '$c'; } }" );
}
function is_admin() { return $GLOBALS['state']['is_admin']; }
function is_user_logged_in() { return $GLOBALS['state']['logged_in']; }
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['state']['admin']; }
function apply_filters( $tag, $value ) { return 'bepluspb_optimize_for_admins' === $tag ? $GLOBALS['state']['filter'] : $value; }
function bepluspb_get_options() { return $GLOBALS['state']['opts']; }
function nocache_headers() { $GLOBALS['state']['nocache'] = true; }

function run( $opts, $who = 'visitor', $get = array(), $is_admin_screen = false, $filter = false ) {
	$GLOBALS['calls'] = array();
	$GLOBALS['state'] = array( 'opts' => $opts, 'is_admin' => $is_admin_screen, 'logged_in' => 'visitor' !== $who, 'admin' => 'admin' === $who, 'filter' => $filter, 'nocache' => false );
	$_GET = $get;
	bepluspb_boot_frontend();
	return $GLOBALS['calls'];
}
$all  = array( 'Page_Cache', 'Minify', 'JS', 'Cleanup', 'CSS', 'UCSS', 'Images', 'Lazy_HTML', 'Priority_Images', 'Resource_Hints', 'Google_Fonts', 'HTML', 'CDN' );
$on   = array( 'cache_enabled' => 1 );
$mode = array( 'cache_enabled' => 1, 'safe_mode_admins_only' => 1 );

// Normal behaviour is unchanged.
t_ok( $all === run( $on ), 'visitor: every module starts' );
t_ok( $all === run( $on, 'member' ), 'other logged-in users are optimised like visitors' );
t_ok( array( 'Page_Cache' ) === run( $on, 'admin' ), 'administrator sees the untouched page (page cache set up only)' );
t_ok( $all === run( $on, 'admin', array(), false, true ), 'the bepluspb_optimize_for_admins filter still works' );
t_ok( array() === run( array( 'cache_enabled' => 0 ) ), 'master switch off: nothing' );
t_ok( array() === run( $on, 'visitor', array(), true ), 'wp-admin: nothing' );

// ?bepluspb_off: nothing starts, for everyone, with or without the master switch, and the response is uncacheable.
foreach ( array( 'visitor', 'member', 'admin' ) as $who ) {
	t_ok( array() === run( $on, $who, array( 'bepluspb_off' => '1' ) ), "?bepluspb_off: $who gets nothing" );
	t_ok( true === $GLOBALS['state']['nocache'], "?bepluspb_off: $who response is marked uncacheable" );
}
t_ok( array() === run( $on, 'visitor', array( 'bepluspb_off' => '' ) ), '?bepluspb_off with an empty value counts too' );
t_ok( array() === run( $on, 'admin', array( 'bepluspb_off' => '1' ), false, true ), '?bepluspb_off beats the admin filter' );
t_ok( array() === run( $mode, 'admin', array( 'bepluspb_off' => '1' ) ), '?bepluspb_off beats safe testing, so an administrator can compare' );
t_ok( $all === run( $on, 'visitor', array( 'bepluspb_offx' => '1', 'other' => '1' ) ), 'other query parameters do not switch it off' );
t_ok( false === $GLOBALS['state']['nocache'], 'no uncacheable header without the parameter' );
t_ok( array() === run( $on, 'visitor', array( 'bepluspb_off' => '1' ), true ), 'wp-admin untouched' );

// Safe testing: administrators optimised, everyone else untouched, Page Cache not even started for visitors.
t_ok( array() === run( $mode ), 'safe testing: visitors get nothing' );
t_ok( array() === run( $mode, 'member' ), 'safe testing: other logged-in users get nothing' );
t_ok( $all === run( $mode, 'admin' ), 'safe testing: administrators get every module' );
t_ok( array() === run( array( 'cache_enabled' => 0, 'safe_mode_admins_only' => 1 ), 'admin' ), 'safe testing does not override the master switch' );
t_ok( $all === run( array( 'cache_enabled' => 1, 'safe_mode_admins_only' => 0 ) ), 'safe testing off: back to normal' );

// The query parameter is never ignorable for the page cache (never served, never stored).
require_once dirname( __DIR__ ) . '/lib/class-bepluspb-page-cache-engine.php';
t_ok( false === BEPLUSPB_Page_Cache_Engine::query_is_ignorable( 'bepluspb_off=1' ) && false === BEPLUSPB_Page_Cache_Engine::query_is_ignorable( 'utm_source=a&bepluspb_off' ), 'the page cache never serves a URL with ?bepluspb_off' );

// Page Cache pauses while safe testing is on.
$pc = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-page-cache.php' );
t_ok( 1 === preg_match( '/public static function is_enabled\( \$opts = null \) \{.*?\n\t\}\n/s', $pc, $m ), 'found Page_Cache::is_enabled' );
t_ok( false !== strpos( $m[0], "empty( \$opts['safe_mode_admins_only'] )" ), 'Page_Cache::is_enabled() is false while safe testing is on (config and stub get removed by sync())' );
t_ok( false !== strpos( $m[0], "! empty( \$opts['cache_enabled'] ) && ! empty( \$opts['page_cache_enabled'] )" ), 'master toggle and page cache toggle still required' );

// Settings: boolean, default off, UI present, admin bar link.
$admin = bepluspb_admin_source();
t_ok( false !== strpos( $admin, "'safe_mode_admins_only',\n" ), 'saved as a checkbox' );
t_ok( 1 === preg_match( "/'safe_mode_admins_only'\s*=> 0,/", $boot_src ), 'default off' );
foreach ( array( 'bepluspb_safe_mode_admins_only', 'render_card_safe_mode( $opts )', '?bepluspb_off=1', "'bepluspb-off'", 'bepluspb_is_bypass_request()' ) as $needle ) {
	t_ok( false !== strpos( $admin, $needle ), "admin source contains $needle" );
}
// No capability gate needed for the read-only switch, but it must never write anything.
t_ok( 1 === preg_match( '/^function bepluspb_is_bypass_request\(\) \{(.*?)^\}/ms', $boot_src, $m ) && false === strpos( $m[1], 'update_option' ) && false === strpos( $m[1], 'file_put_contents' ), 'the switch reads $_GET only' );

echo "OK ($n assertions)\n";
