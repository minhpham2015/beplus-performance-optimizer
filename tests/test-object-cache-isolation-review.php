<?php
/**
 * Security/isolation regression contracts for 1.1.13 review.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
$manager = file_get_contents( $root . '/includes/class-bepluspb-object-cache.php' );
$dropin = file_get_contents( $root . '/lib/object-cache.php' );
$basic = file_get_contents( $root . '/includes/admin/trait-bepluspb-admin-tabs-basic.php' );
$checks = array(
	'manager stores guarded PHP config' => false !== strpos( $manager, "WP_CONTENT_DIR . '/.bepluspb_oc.php'" ),
	'dropin reads guarded PHP config' => false !== strpos( $dropin, "WP_CONTENT_DIR . '/.bepluspb_oc.php'" ),
	'legacy config migration exists' => false !== strpos( $manager, '.bepluspb_oc.json' ) && false !== strpos( $manager, 'migrate_legacy_config' ),
	'dropin never reads legacy web config' => false === strpos( $dropin, "WP_CONTENT_DIR . '/.bepluspb_oc.json'" ),
	'default namespace installation-specific' => false !== strpos( $dropin, "hash( 'sha256',") && false !== strpos( $dropin, 'realpath( ABSPATH )' ),
	'explicit salt preserved' => false !== strpos( $dropin, "defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT" ),
	'memcached persistent client isolated' => false !== strpos( $dropin, "'bepluspb-' . substr( hash( 'sha256', \$this->salt ), 0, 16 )" ),
	'support URL exact' => false !== strpos( $basic, 'https://beplusthemes.com/contact/' ) && false === strpos( $basic, 'https://beplusthemes.com/support/' ),
);
$failed = array_keys( array_filter( $checks, static fn( $ok ) => ! $ok ) );
if ( $failed ) { fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . PHP_EOL ); exit( 1 ); }
echo 'PASS: ' . count( $checks ) . " review isolation contracts\n";
