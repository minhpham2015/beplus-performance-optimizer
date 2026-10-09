<?php
/**
 * BEPLUSPB_Admin was split into traits: every hook callback must still resolve and the public surface must be intact.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function add_action( $tag, $cb, $priority = 10, $accepted_args = 1 ) { $GLOBALS['hooks'][] = array( 'action', $tag, $cb, $priority, $accepted_args ); }
function add_filter( $tag, $cb, $priority = 10, $accepted_args = 1 ) { $GLOBALS['hooks'][] = array( 'filter', $tag, $cb, $priority, $accepted_args ); }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

BEPLUSPB_Admin::init();
t_ok( 24 === count( $GLOBALS['hooks'] ), 'init() registers hooks (' . count( $GLOBALS['hooks'] ) . ')' );
foreach ( $GLOBALS['hooks'] as list( $type, $tag, $cb, $priority, $accepted_args ) ) {
	t_ok( is_array( $cb ) && 'BEPLUSPB_Admin' === $cb[0] && method_exists( 'BEPLUSPB_Admin', $cb[1] ), "$tag callback {$cb[1]} exists" );
	t_ok( ( new ReflectionMethod( 'BEPLUSPB_Admin', $cb[1] ) )->isPublic(), "$tag callback {$cb[1]} is public" );
	t_ok( in_array( $type, array( 'action', 'filter' ), true ) && is_int( $priority ) && is_int( $accepted_args ), "$tag complete hook tuple captured" );
}
$save_post = array_values( array_filter( $GLOBALS['hooks'], static fn( $hook ) => 'save_post' === $hook[1] ) );
t_ok( array( 'action', 'save_post', array( 'BEPLUSPB_Admin', 'save_meta_box' ), 10, 2 ) === $save_post[0], 'save_post exact hook tuple preserved' );
$admin_bar = array_values( array_filter( $GLOBALS['hooks'], static fn( $hook ) => 'admin_bar_menu' === $hook[1] ) );
t_ok( array( 'action', 'admin_bar_menu', array( 'BEPLUSPB_Admin', 'add_admin_bar_menu' ), 100, 1 ) === $admin_bar[0], 'admin_bar exact hook tuple preserved' );

// Every AJAX/admin-post handler still present, and nonce-before-capability order preserved in AJAX handlers.
$ref = new ReflectionClass( 'BEPLUSPB_Admin' );
$expected_traits = 10;
t_ok( $expected_traits === count( $ref->getTraitNames() ), 'class uses all ' . $expected_traits . ' traits' );
$src = '';
foreach ( glob( dirname( __DIR__ ) . '/includes/admin/*.php' ) as $f ) { $src .= file_get_contents( $f ); }
t_ok( count( glob( dirname( __DIR__ ) . '/includes/admin/*.php' ) ) === $expected_traits, 'one file per trait' );
foreach ( array( 'handle_ajax_cf_test_connection', 'handle_ajax_cf_purge', 'handle_ajax_cf_devmode', 'handle_ajax_test_oc', 'handle_ajax_install_oc', 'handle_ajax_remove_oc', 'handle_ajax_toggle_cache' ) as $m ) {
	$fn = $ref->getMethod( $m );
	$body = implode( '', array_slice( file( $fn->getFileName() ), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1 ) );
	$p_nonce = strpos( $body, 'check_ajax_referer' ); $p_cap = strpos( $body, 'current_user_can' );
	t_ok( false !== $p_nonce && false !== $p_cap && $p_nonce < $p_cap, "$m checks nonce before capability" );
}

// Method inventory identical to the pre-split class (snapshot).
$expected = file( __DIR__ . '/helpers/admin-methods.txt', FILE_IGNORE_NEW_LINES );
$actual   = array_map( fn( $m ) => $m->getName(), $ref->getMethods() );
sort( $expected ); sort( $actual );
t_ok( $expected === $actual, 'method inventory unchanged: ' . implode( ',', array_merge( array_diff( $expected, $actual ), array_diff( $actual, $expected ) ) ) );

// Configuration-write failures describe the guarded config path without obsolete web-server instructions.
$admin_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php' );
$config_error = 'Object Cache configuration was not written because the guarded configuration file could not be created, verified, or migrated. Check wp-content permissions and save again.';
t_ok( false !== strpos( $admin_source, $config_error ), 'guarded config write failure has accurate actionable copy' );
t_ok( false === strpos( $admin_source, 'nginx: deny .bepluspb_oc.json manually' ), 'config failure copy omits obsolete legacy JSON instructions' );

echo "PASS: $n admin class split assertions\n";
