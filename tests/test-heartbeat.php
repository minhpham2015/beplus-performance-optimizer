<?php
/**
 * Heartbeat control: modes, interval, locations, deregistering.
 * Run: php -n tests/test-heartbeat.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks']        = array();
$GLOBALS['deregistered'] = array();
$GLOBALS['is_admin']     = false;
$GLOBALS['customize']    = false;
$GLOBALS['opts']         = array();
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function is_admin() { return $GLOBALS['is_admin']; }
function is_customize_preview() { return $GLOBALS['customize']; }
function wp_deregister_script( $h ) { $GLOBALS['deregistered'][] = $h; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-heartbeat.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function boot( $o, $admin = false, $page = 'index.php' ) {
	global $pagenow;
	$GLOBALS['hooks'] = array(); $GLOBALS['deregistered'] = array(); $GLOBALS['is_admin'] = $admin; $pagenow = $page; $GLOBALS['opts'] = $o;
	BEPLUSPB_Heartbeat::init( $o );
}
function settings( $in = array() ) { $cb = $GLOBALS['hooks']['heartbeat_settings'][0][0] ?? null; return $cb ? call_user_func( $cb, $in ) : $in; }
function run( $hook ) { foreach ( $GLOBALS['hooks'][ $hook ] ?? array() as $h ) { call_user_func( $h[0] ); } }

// Nothing configured: nothing registered.
boot( array() );
t_ok( array() === $GLOBALS['hooks'], 'all "default" registers no hook' );
boot( array( 'heartbeat_dashboard' => 'nonsense', 'heartbeat_editor' => 'disable' ) );
t_ok( array() === $GLOBALS['hooks'], 'unknown values and "disable" for the editor fall back to default' );

// Sanitising.
t_ok( 'disable' === BEPLUSPB_Heartbeat::sanitize_mode( 'disable' ) && 'reduce' === BEPLUSPB_Heartbeat::sanitize_mode( ' Reduce ' ), 'modes are trimmed and lower-cased' );
t_ok( 'default' === BEPLUSPB_Heartbeat::sanitize_mode( 'disable', false ), 'the editor cannot be disabled' );
t_ok( 'default' === BEPLUSPB_Heartbeat::sanitize_mode( array( 'disable' ) ) && 'default' === BEPLUSPB_Heartbeat::sanitize_mode( null ), 'non-strings are rejected' );
t_ok( 15 === BEPLUSPB_Heartbeat::sanitize_interval( 1 ) && 120 === BEPLUSPB_Heartbeat::sanitize_interval( 9999 ) && 60 === BEPLUSPB_Heartbeat::sanitize_interval( 'abc' ) && 90 === BEPLUSPB_Heartbeat::sanitize_interval( '90' ), 'interval is clamped to 15-120' );

// Locations.
$GLOBALS['is_admin'] = false;
t_ok( 'frontend' === BEPLUSPB_Heartbeat::location(), 'front end' );
foreach ( array( 'index.php' => 'dashboard', 'plugins.php' => 'dashboard', 'edit.php' => 'dashboard', 'post.php' => 'editor', 'post-new.php' => 'editor', 'site-editor.php' => 'editor', 'widgets.php' => 'editor', 'customize.php' => 'editor' ) as $page => $loc ) {
	$GLOBALS['is_admin'] = true; $pagenow = $page;
	t_ok( $loc === BEPLUSPB_Heartbeat::location(), "$page is $loc" );
}

// Reduce only slows the pulse down, per location.
$o = array( 'heartbeat_dashboard' => 'reduce', 'heartbeat_editor' => 'reduce', 'heartbeat_frontend' => 'default', 'heartbeat_interval' => 90 );
boot( $o, true, 'index.php' );
t_ok( 90 === settings( array() )['interval'], 'dashboard: interval set' );
t_ok( 120 === settings( array( 'interval' => 120 ) )['interval'], 'a longer interval already set is kept (never speeds up)' );
t_ok( 90 === settings( array( 'interval' => 15 ) )['interval'], 'a shorter interval is raised' );
t_ok( 'x' === settings( array( 'foo' => 'x' ) )['foo'], 'other settings are kept' );
boot( $o, true, 'post.php' );
t_ok( 90 === settings( array() )['interval'], 'editor: interval set' );
boot( $o, false );
t_ok( array() === settings( array() ), 'front end left at default is untouched' );
boot( array_merge( $o, array( 'heartbeat_interval' => 5 ) ), true, 'index.php' );
t_ok( 15 === settings( array() )['interval'], 'a too-small stored interval is clamped when used' );
t_ok( 'str' === BEPLUSPB_Heartbeat::apply_interval( 'str', 'reduce', 60 ), 'a non-array settings value is returned unchanged' );

// Disable: dashboard.
$o = array( 'heartbeat_dashboard' => 'disable' );
boot( $o, true, 'index.php' ); run( 'admin_enqueue_scripts' );
t_ok( array( 'heartbeat' ) === $GLOBALS['deregistered'], 'dashboard disable deregisters the script' );
t_ok( 1 === $GLOBALS['hooks']['admin_enqueue_scripts'][0][1] && 1 === $GLOBALS['hooks']['wp_enqueue_scripts'][0][1], 'deregistered before other scripts are enqueued (priority 1)' );
foreach ( array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php', 'customize.php' ) as $page ) {
	boot( $o, true, $page ); run( 'admin_enqueue_scripts' );
	t_ok( array() === $GLOBALS['deregistered'], "$page keeps the script" );
}
boot( $o, false ); run( 'wp_enqueue_scripts' );
t_ok( array() === $GLOBALS['deregistered'], 'dashboard disable leaves the front end alone' );

// Disable: front end.
$o = array( 'heartbeat_frontend' => 'disable' );
boot( $o, false ); run( 'wp_enqueue_scripts' );
t_ok( array( 'heartbeat' ) === $GLOBALS['deregistered'], 'front-end disable deregisters the script' );
$GLOBALS['customize'] = true; $GLOBALS['deregistered'] = array(); run( 'wp_enqueue_scripts' );
t_ok( array() === $GLOBALS['deregistered'], 'the Customizer preview keeps the script' );
$GLOBALS['customize'] = false;
boot( $o, true, 'index.php' ); run( 'admin_enqueue_scripts' );
t_ok( array() === $GLOBALS['deregistered'], 'front-end disable leaves wp-admin alone' );

// Source guards: no network, no database, no file access.
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-heartbeat.php' );
foreach ( array( 'wp_remote_', '$wpdb', 'file_put_contents', 'update_option', 'exec(' ) as $bad ) {
	t_ok( false === strpos( $src, $bad ), "heartbeat class does not use $bad" );
}

echo "OK ($n assertions)\n";
