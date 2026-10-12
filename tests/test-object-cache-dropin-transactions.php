<?php
/**
 * Transaction and integrity regressions for guarded drop-in replacement.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'BEPLUSPB_VERSION' ) ) { define( 'BEPLUSPB_VERSION', '1.1.13' ); }
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
// A failed staged rename must restore and verify the exact original target.
list($r,$s,$foreign)=tx_fixture();
$rename_calls=0;
$x=tx_wf($r,$s,array('fs_rename'=>function($from,$to) use (&$rename_calls) { ++$rename_calls; if (2===$rename_calls) { return false; } return rename($from,$to); }))->replace();
tx_ok( empty($x['success']) && !empty($x['rolled_back']), 'staged rename failure reports verified rollback' );
tx_ok( $foreign === file_get_contents("$r/object-cache.php"), 'staged rename failure restores exact target bytes' );
// If both activation and rollback renames fail, copy restoration is attempted
// and the ordinary rollback result is allowed only after hash verification.
list($r,$s,$foreign)=tx_fixture();
$rename_calls=0;
$copy_calls=0;
$x=tx_wf($r,$s,array(
	'fs_rename'=>function($from,$to) use (&$rename_calls) { ++$rename_calls; if ($rename_calls >= 2) { return false; } return rename($from,$to); },
	'fs_copy'=>function($from,$to) use (&$copy_calls) { ++$copy_calls; return copy($from,$to); },
))->replace();
tx_ok( empty($x['success']) && !empty($x['rolled_back']) && $copy_calls > 0, 'rollback rename failure uses verified copy restoration' );
tx_ok( $foreign === file_get_contents("$r/object-cache.php"), 'copy fallback restores exact target bytes' );
// If no restoration path can recreate and verify the target, return an
// explicit critical/uncertain result rather than an ordinary failure.
list($r,$s,$foreign)=tx_fixture();
$rename_calls=0;
$x=tx_wf($r,$s,array(
	'fs_rename'=>function($from,$to) use (&$rename_calls) { ++$rename_calls; if ($rename_calls >= 2) { return false; } return rename($from,$to); },
	'fs_copy'=>fn()=>false,
))->replace();
tx_ok( empty($x['success']) && !empty($x['critical']) && !empty($x['uncertain']) && empty($x['rolled_back']), 'unverifiable restoration returns explicit critical uncertainty' );
// Restore activation failure after moving the active target must invoke exact
// verified rollback, not infer rollback merely because some target exists.
list($r,$s,$foreign)=tx_fixture(); $w=tx_wf($r,$s); tx_ok(!empty($w->replace()['success']), 'restore setup replacement succeeds');
$active=file_get_contents("$r/object-cache.php"); $rename_calls=0;
$x=tx_wf($r,$s,array('fs_rename'=>function($from,$to) use (&$rename_calls) { ++$rename_calls; if (2===$rename_calls) { return false; } return rename($from,$to); }))->restore();
tx_ok(empty($x['success']) && !empty($x['rolled_back']), 'restore staged rename failure reports exact verified rollback');
tx_ok($active === file_get_contents("$r/object-cache.php"), 'restore staged rename failure restores exact active bytes');
// An impossible restore after activation failure must be critical and uncertain.
list($r,$s)=tx_fixture(); $w=tx_wf($r,$s); tx_ok(!empty($w->replace()['success']), 'critical restore setup succeeds'); $rename_calls=0;
$x=tx_wf($r,$s,array(
    'fs_rename'=>function($from,$to) use (&$rename_calls) { ++$rename_calls; if ($rename_calls >= 2) { return false; } return rename($from,$to); },
    'fs_copy'=>fn()=>false,
))->restore();
tx_ok(empty($x['success']) && !empty($x['critical']) && !empty($x['uncertain']) && empty($x['rolled_back']), 'impossible restore rollback is critical and uncertain');
// Rollback-artifact cleanup is transactional on both successful paths.
list($r,$s,$foreign)=tx_fixture();
$x=tx_wf($r,$s,array('fs_unlink'=>fn($path)=>false))->replace();
tx_ok(empty($x['success']) && !empty($x['rolled_back']) && $foreign === file_get_contents("$r/object-cache.php"), 'replace cleanup failure restores and verifies original');
list($r,$s)=tx_fixture(); $w=tx_wf($r,$s); tx_ok(!empty($w->replace()['success']), 'restore cleanup setup succeeds'); $active=file_get_contents("$r/object-cache.php");
$x=tx_wf($r,$s,array('fs_unlink'=>fn($path)=>false))->restore();
tx_ok(empty($x['success']) && !empty($x['rolled_back']) && $active === file_get_contents("$r/object-cache.php"), 'restore cleanup failure restores and verifies active target');
echo "PASS: $tx_n assertions\n";
