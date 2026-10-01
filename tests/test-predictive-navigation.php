<?php
/**
 * Standalone regression tests for Predictive Navigation.
 *
 * Run: php -n tests/test-predictive-navigation.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Standalone test intentionally defines compact WordPress stubs.
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );

$GLOBALS['bepluspb_test_filters'] = array();
$GLOBALS['bepluspb_test_options'] = array();
$GLOBALS['wp_version']            = '6.8';

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['bepluspb_test_filters'][ $hook ][] = array( $callback, $priority, $accepted_args );
}
function get_option( $key, $default = false ) {
	if ( 'permalink_structure' === $key ) {
		return '/%postname%/';
	}
	return $GLOBALS['bepluspb_test_options'];
}
function bepluspb_get_options() {
	return $GLOBALS['bepluspb_test_options'];
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function wc_get_cart_url() {
	return 'https://example.test/basket/';
}
function wc_get_checkout_url() {
	return 'https://example.test/pay/';
}
function wc_get_page_permalink( $page ) {
	return 'myaccount' === $page ? 'https://example.test/profile/' : '';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function trailingslashit( $path ) {
	return rtrim( $path, '/' ) . '/';
}
function bepluspb_parse_exclude_list( $textarea ) {
	return array_values( array_filter( array_map( 'trim', explode( "\n", $textarea ) ) ) );
}

require_once dirname( __DIR__ ) . '/includes/class-bepluspb-predictive-navigation.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'FAIL: ' . $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
	}
}
function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
}

// Disabled by default: do not touch Core filters or emit a competing script.
$GLOBALS['bepluspb_test_options'] = array( 'predictive_navigation_enabled' => 0 );
BEPLUSPB_Predictive_Navigation::init();
assert_same( array(), $GLOBALS['bepluspb_test_filters'], 'disabled mode must register no filters' );

// Modes map exactly to the approved native Core configuration.
assert_same(
	array(
		'mode'      => 'prefetch',
		'eagerness' => 'conservative',
	),
	BEPLUSPB_Predictive_Navigation::configuration( array(), 'safe' ),
	'safe mapping'
);
assert_same(
	array(
		'mode'      => 'prefetch',
		'eagerness' => 'moderate',
	),
	BEPLUSPB_Predictive_Navigation::configuration( array(), 'balanced' ),
	'balanced mapping'
);
assert_same(
	array(
		'mode'      => 'prerender',
		'eagerness' => 'moderate',
	),
	BEPLUSPB_Predictive_Navigation::configuration( array(), 'fast' ),
	'fast mapping'
);

// Exclusions include safe defaults, actual WooCommerce routes, and sanitized custom paths.
$GLOBALS['bepluspb_test_options'] = array(
	'predictive_navigation_mode'     => 'safe',
	'predictive_navigation_excludes' => " /private/* \njavascript:alert(1)\n/private/*\nhttps://evil.test/x\n?preview=true",
);
$paths                            = BEPLUSPB_Predictive_Navigation::exclude_paths( array( '/existing/*' ) );
foreach ( array( '/existing/*', '/cart/*', '/checkout/*', '/my-account/*', '/basket/*', '/pay/*', '/profile/*', '/private/*', '/*?s=*', '/*?preview=*', '/*?action=*', '/wp-login.php*', '/wp-admin/*' ) as $path ) {
	assert_true( in_array( $path, $paths, true ), 'missing exclusion ' . $path );
}
assert_true( ! in_array( 'javascript:alert(1)', $paths, true ), 'unsafe scheme must be rejected' );
assert_true( ! in_array( 'https://evil.test/x', $paths, true ), 'absolute custom URL must be rejected' );
assert_same( count( $paths ), count( array_unique( $paths ) ), 'exclusions must be deduplicated' );

echo "PASS: Predictive Navigation assertions\n";
