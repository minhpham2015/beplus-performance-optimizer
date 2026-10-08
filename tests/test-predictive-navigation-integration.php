<?php
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
/**
 * Source-level integration regression tests for Predictive Navigation.
 *
 * Run: php -n tests/test-predictive-navigation-integration.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Standalone source regression test uses local filesystem reads.
$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/beplus-performance-booster.php' );
$admin     = bepluspb_admin_source();
$readme    = file_get_contents( $root . '/readme.txt' );
$ci        = file_get_contents( $root . '/.github/workflows/ci.yml' );

function assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
}

assert_contains( "'predictive_navigation_enabled'", $bootstrap, 'bootstrap defaults must include disabled toggle' );
assert_contains( "'predictive_navigation_mode'", $bootstrap, 'bootstrap defaults must include mode' );
assert_contains( 'class-bepluspb-predictive-navigation.php', $bootstrap, 'bootstrap must load class' );
assert_contains( "array( 'BEPLUSPB_Predictive_Navigation', 'init' )", $bootstrap, 'bootstrap must initialize native integration independently of master cache' );
assert_contains( "'predictive_navigation_enabled'", $admin, 'admin must sanitize toggle' );
assert_contains( "'predictive_navigation_mode'", $admin, 'admin must sanitize mode' );
assert_contains( "'predictive_navigation_excludes'", $admin, 'admin must sanitize custom paths' );
assert_contains( 'WordPress 6.8', $admin, 'UI must explain compatibility' );
assert_contains( 'resource', strtolower( $admin ), 'UI must explain resource impact' );
assert_contains( 'risk', strtolower( $admin ), 'UI must explain risk' );
assert_contains( 'Predictive Navigation', $readme, 'readme must document feature' );
assert_contains( 'test-predictive-navigation.php', $ci, 'CI must run class regression test' );
assert_contains( 'test-predictive-navigation-integration.php', $ci, 'CI must run integration regression test' );

echo "PASS: Predictive Navigation integration assertions\n";
