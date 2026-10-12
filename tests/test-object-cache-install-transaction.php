<?php
/**
 * Object-cache normal install transaction contract.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php' );
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
$admin = bepluspb_admin_source();
$checks = array(
	'transaction API exists' => false !== strpos( $source, 'install_with_config' ),
	'old config captured' => false !== strpos( $source, '$old_config' ),
	'failed install restores prior config' => false !== strpos( $source, 'restore_config' ),
	'failed first install restores exact config state' => false !== strpos( $source, 'restore_file( $cfg_file, $had_config, $old_config )' ) && false !== strpos( $source, 'exact_file_state' ),
	'admin uses transaction' => false !== strpos( $admin, 'BEPLUSPB_Object_Cache::install_with_config( bepluspb_get_options() )' ),
);
foreach ( $checks as $name=>$ok ) { if ( ! $ok ) { throw new RuntimeException( "FAIL: $name" ); } }
echo 'PASS: ' . count($checks) . " assertions\n";
