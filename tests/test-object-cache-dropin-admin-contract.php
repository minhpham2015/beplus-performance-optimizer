<?php
/**
 * Admin contract for guarded drop-in workflow.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$s = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php' );
$c = array(
	'actions registered'       => false !== strpos( $s, 'wp_ajax_bepluspb_preflight_oc_replace' ) && false !== strpos( $s, 'wp_ajax_bepluspb_backup_replace_oc' ) && false !== strpos( $s, 'wp_ajax_bepluspb_restore_oc' ),
	'distinct nonces'          => false !== strpos( $s, "bepluspb_preflight_oc_replace' )" ) && false !== strpos( $s, "bepluspb_backup_replace_oc' )" ) && false !== strpos( $s, "bepluspb_restore_oc' )" ),
	'capability'               => substr_count( $s, "current_user_can( 'manage_options' )" ) >= 3,
	'labels'                   => false !== strpos( $s, 'Back up and replace…' ) && false !== strpos( $s, 'Restore previous drop-in…' ),
	'acknowledgement'          => false !== strpos( $s, 'replace-ack' ) && false !== strpos( $s, 'restore-ack' ) && false !== strpos( $s, "empty( \$_POST['acknowledge'] )" ),
	'preflight before replace' => false !== strpos( $s, "dropinAction('bepluspb_preflight_oc_replace'" ) && false !== strpos( $s, "dropinAction('bepluspb_backup_replace_oc'" ),
	'restore action wired'     => false !== strpos( $s, "dropinAction('bepluspb_restore_oc'" ),
	'config after install'     => strpos( $s, 'BEPLUSPB_Object_Cache::install_dropin()' ) < strpos( $s, 'BEPLUSPB_Object_Cache::write_config( bepluspb_get_options() )' ),
);
$f = array_keys( array_filter( $c, fn( $v )=> ! $v ) );
if ( $f ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $f ) . PHP_EOL );
	exit( 1 );
}echo 'PASS: ' . count( $c ) . " assertions\n";
