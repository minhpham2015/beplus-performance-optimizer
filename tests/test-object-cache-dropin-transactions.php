<?php
/**
 * Transaction and integrity regressions for guarded drop-in replacement.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'BEPLUSPB_VERSION' ) ) { define( 'BEPLUSPB_VERSION', '1.1.12' ); }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-dropin-workflow.php';
function tx_ok( $v, $m ) { global $tx_n; ++$tx_n; if ( ! $v ) { throw new RuntimeException( "FAIL: $m" ); } }
function tx_fixture() {
	$r = sys_get_temp_dir() . '/bepluspb-tx-' . bin2hex( random_bytes( 5 ) ); mkdir( $r, 0700 ); mkdir( "$r/plugin", 0700 );
	$foreign = "<?php\n// Foreign object cache\n";
	$ours = "<?php\n// Beplus Performance Booster Object Cache Drop-in\ndefine('BEPLUSPB_DROPIN_BUILD_ID','" . BEPLUSPB_Dropin_Workflow::DROPIN_BUILD_ID . "');\n";
	file_put_contents( "$r/object-cache.php", $foreign ); file_put_contents( "$r/plugin/object-cache.php", $ours );
	return array( $r, "$r/plugin/object-cache.php", $foreign );
}
function tx_wf( $r, $s, $hooks = array() ) { return new BEPLUSPB_Dropin_Workflow( $r, $s, array_merge( array( 'backend_test'=>fn()=>array('success'=>true), 'health_probe'=>fn()=>true, 'manifest_key'=>fn()=>str_repeat('t',32) ), $hooks ) ); }
$tx_n=0;
// Manifest publication is part of the transaction: failure restores the exact original.
list($r,$s,$foreign)=tx_fixture(); $x=tx_wf($r,$s,array('manifest_write'=>fn()=>false))->replace();
tx_ok( empty($x['success']) && !empty($x['rolled_back']), 'manifest failure reports rollback' );
tx_ok( file_get_contents("$r/object-cache.php") === $foreign, 'manifest failure restores original target' );
// Lock contention fails closed without changing the target.
list($r,$s,$foreign)=tx_fixture(); $lock=fopen("$r/.bepluspb-dropin.lock",'c+'); flock($lock,LOCK_EX|LOCK_NB); $x=tx_wf($r,$s)->replace();
tx_ok( empty($x['success']) && file_get_contents("$r/object-cache.php") === $foreign, 'contended transaction lock fails closed' ); flock($lock,LOCK_UN); fclose($lock);
// Signed record authenticates restoration metadata, not merely payload fields.
list($r,$s)=tx_fixture(); $w=tx_wf($r,$s); $a=$w->replace(); $mf="$r/bepluspb-backups/restore-manifest.json"; $m=json_decode(file_get_contents($mf),true); $m['metadata']['mode']='0777'; file_put_contents($mf,json_encode($m));
tx_ok( empty($w->restore()['success']), 'tampered signed metadata rejected' );
echo "PASS: $tx_n assertions\n";
