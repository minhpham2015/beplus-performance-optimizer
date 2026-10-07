<?php
/**
 * Regression tests for guarded object-cache drop-in security invariants.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'BEPLUSPB_VERSION' ) ) { define( 'BEPLUSPB_VERSION', 'test' ); }
if ( ! function_exists( '__' ) ) { function __( $s ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-dropin-workflow.php';
function sec_ok( $value, $message ) { global $sec_n; ++$sec_n; if ( ! $value ) { throw new RuntimeException( "FAIL: $message" ); } }
function sec_fixture() {
	$root = sys_get_temp_dir() . '/bepluspb-sec-' . bin2hex( random_bytes( 5 ) );
	mkdir( $root, 0700, true ); mkdir( "$root/plugin", 0700 );
	$foreign = "<?php\n// Redis Object Cache by Vendor\n";
	$ours = "<?php\n// Beplus Performance Booster Object Cache Drop-in\ndefine( 'BEPLUSPB_DROPIN_BUILD_ID', '" . BEPLUSPB_Dropin_Workflow::DROPIN_BUILD_ID . "' );\n";
	file_put_contents( "$root/object-cache.php", $foreign ); file_put_contents( "$root/plugin/object-cache.php", $ours );
	return array( $root, "$root/plugin/object-cache.php", $foreign, $ours );
}
function sec_wf( $root, $source, $hooks = array() ) {
	return new BEPLUSPB_Dropin_Workflow( $root, $source, array_merge( array( 'backend_test' => fn() => array( 'success' => true ), 'health_probe' => fn() => true, 'manifest_key' => fn() => str_repeat( 's', 32 ) ), $hooks ) );
}
$sec_n = 0;

// A pre-existing backup-directory symlink must be rejected before any write.
list($r, $s) = sec_fixture(); $outside = sys_get_temp_dir() . '/bepluspb-out-' . bin2hex( random_bytes( 4 ) ); mkdir( $outside, 0700 ); symlink( $outside, "$r/bepluspb-backups" );
$x = sec_wf( $r, $s )->backup_file( "$r/object-cache.php" );
sec_ok( empty( $x['success'] ), 'symlinked backup directory rejected' );
sec_ok( array() === array_values( array_diff( scandir( $outside ), array( '.', '..' ) ) ), 'no write followed backup-directory symlink' );

// Backup directory must deny web access and use unguessable names.
list($r, $s, $foreign) = sec_fixture(); $x = sec_wf( $r, $s )->backup_file( "$r/object-cache.php" );
sec_ok( ! empty( $x['success'] ), 'backup succeeds' );
sec_ok( false !== strpos( (string) @file_get_contents( "$r/bepluspb-backups/.htaccess" ), 'Require all denied' ), 'backup dir has deny-all .htaccess' );
sec_ok( is_file( "$r/bepluspb-backups/index.php" ), 'backup dir has index.php' );
sec_ok( 1 === preg_match( '/-[0-9a-f]{32}\\.bak$/', $x['path'] ), 'backup name has 128-bit random token' );

// Backups are non-PHP base64 .bak payloads and restore byte-perfectly.
list($r, $s, $foreign) = sec_fixture(); $w = sec_wf( $r, $s ); $a = $w->replace();
sec_ok( ! empty( $a['success'] ) && 'bak' === pathinfo( $a['backup'], PATHINFO_EXTENSION ), 'backup uses .bak extension' );
$stored = file_get_contents( $a['backup'] );
sec_ok( 0 !== strpos( ltrim( $stored ), '<?php' ) && base64_decode( $stored, true ) === $foreign, 'backup is encoded and byte-perfect' );
sec_ok( $w->restore()['success'] && file_get_contents( "$r/object-cache.php" ) === $foreign, 'encoded backup restores byte-perfectly' );

// Retention must not evict the selected manifest backup during restore.
list($r, $s) = sec_fixture(); $w = sec_wf( $r, $s ); $a = $w->replace(); $selected = $a['backup']; touch( $selected, time() - 10000 );
for ( $i = 0; $i < 2; ++$i ) { file_put_contents( "$r/object-cache.php", "<?php // filler $i" ); $w->backup_file( "$r/object-cache.php" ); }
file_put_contents( "$r/object-cache.php", "<?php\n// Beplus Performance Booster Object Cache Drop-in\ndefine( 'BEPLUSPB_DROPIN_BUILD_ID', '" . BEPLUSPB_Dropin_Workflow::DROPIN_BUILD_ID . "' );\n" );
$z = $w->restore();
sec_ok( $z['success'] && is_file( $selected ), 'restore retains selected backup' );

// A failed post-activation probe must rollback and report failure truthfully.
list($r, $s, $foreign) = sec_fixture(); $x = sec_wf( $r, $s, array( 'health_probe' => fn() => false ) )->replace();
sec_ok( empty( $x['success'] ) && ! empty( $x['rolled_back'] ), 'failed health probe reports rollback' );
sec_ok( file_get_contents( "$r/object-cache.php" ) === $foreign, 'failed health probe restores original bytes' );

// Substituting both payload and unhashed metadata cannot bypass authenticated manifest.
list($r, $s) = sec_fixture(); $w = sec_wf( $r, $s ); $a = $w->replace(); $mf = "$r/bepluspb-backups/restore-manifest.json"; $m = json_decode( file_get_contents( $mf ), true );
$evil = base64_encode( "<?php // attacker\n" ); file_put_contents( $a['backup'], $evil ); $m['sha256'] = hash( 'sha256', base64_decode( $evil ) ); file_put_contents( $mf, json_encode( $m ) );
sec_ok( ! $w->restore()['success'], 'manifest and payload substitution rejected by MAC' );

echo "PASS: $sec_n assertions\n";
