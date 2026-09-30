<?php
/**
 * Object-cache backup/replace/restore filesystem integration tests.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_VERSION', '1.1.10' );
function __( $s ) {
	return $s; }
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v, $f ); }
function get_current_user_id() {
	return 7; }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-dropin-workflow.php';
function ok( $v, $m ) {
	global $n;
	++$n;
	if ( ! $v ) {
		throw new RuntimeException( "FAIL: $m" ); } }
function fixture() {
	$root = sys_get_temp_dir() . '/bepluspb-' . bin2hex( random_bytes( 5 ) );
	mkdir( $root, 0700, true );
	mkdir( "$root/plugin", 0700 );
	$foreign = "<?php\n// Redis Object Cache by Vendor\n";
	$ours    = "<?php\n// Beplus Performance Booster Object Cache Drop-in\ndefine( 'BEPLUSPB_DROPIN_BUILD_ID', 'fixture-build' );\n";
	file_put_contents( "$root/object-cache.php", $foreign );
	file_put_contents( "$root/plugin/object-cache.php", $ours );
	return array( $root, "$root/plugin/object-cache.php", $foreign, $ours );
}
function wf( $r, $s, $hooks = array() ) {
	return new BEPLUSPB_Dropin_Workflow( $r, $s, array_merge( array( 'backend_test' => fn() => array( 'success' => true ), 'health_probe' => fn() => true, 'manifest_key' => fn() => str_repeat( 'k', 32 ) ), $hooks ) ); }
$n                            = 0;
list($r, $s, $foreign, $ours) = fixture();
$w                            = wf( $r, $s );
$p                            = $w->preflight( 'replace' );
ok( $p['success'], 'valid preflight' );
ok( 'Redis Object Cache' === $p['provider'], 'provider' );
unlink( "$r/object-cache.php" );
symlink( "$r/plugin/object-cache.php", "$r/object-cache.php" );
ok( ! wf( $r, $s )->preflight( 'replace' )['success'], 'symlink rejected' );
list($r, $s) = fixture();
ok( ! wf( $r, $s, array( 'directory_writable' => fn()=>false ) )->preflight( 'replace' )['success'], 'unwritable directory simulated' );
ok( ! wf( $r, $s, array( 'target_writable' => fn()=>false ) )->preflight( 'replace' )['success'], 'root-owned unwritable target simulated' );
ok( ! wf( $r, $s, array( 'readable' => fn( $p )=>false ) )->preflight( 'replace' )['success'], 'unreadable simulated' );
list($r, $s, $foreign, $ours) = fixture();
$x                            = wf( $r, $s, array( 'after_backup_copy' => fn( $p )=>file_put_contents( $p, 'tampered' ) ) )->replace();
ok( ! $x['success'], 'backup verify fail' );
ok( $foreign === file_get_contents( "$r/object-cache.php" ), 'backup failure leaves target' );
list($r, $s, $foreign, $ours) = fixture();
$w                            = wf( $r, $s );
$a                            = $w->replace();
ok( $a['success'], 'atomic replacement' );
ok( $ours === file_get_contents( "$r/object-cache.php" ), 'replacement bytes' );
ok( is_file( $a['backup'] ), 'backup exists' );
ok( hash( 'sha256', base64_decode( file_get_contents( $a['backup'] ), true ) ) === hash( 'sha256', $foreign ), 'backup checksum' );
ok( is_file( "$r/bepluspb-backups/restore-manifest.json" ), 'manifest durable' );
$meta = json_decode( file_get_contents( $a['backup'] . '.json' ), true );
ok( ! isset( $meta['password'] ) && false === strpos( json_encode( $meta ), 'secret' ), 'metadata no secrets' );
list($r, $s) = fixture();
$w           = wf( $r, $s, array( 'before_activate' => fn()=>false ) );
$x           = $w->replace();
ok( ! $x['success'] && ! empty( $x['rolled_back'] ), 'replacement rollback reported' );
ok( false !== strpos( file_get_contents( "$r/object-cache.php" ), 'Vendor' ), 'replacement rolled back' );
list($r, $s, $foreign, $ours) = fixture();
$w                            = wf( $r, $s );
$a                            = $w->replace();
$z                            = $w->restore();
ok( $z['success'], 'restore succeeds' );
ok( $foreign === file_get_contents( "$r/object-cache.php" ), 'foreign restored' );
ok( ! empty( $z['current_backup'] ) && is_file( $z['current_backup'] ), 'current Beplus backed up first' );
list($r, $s, $foreign) = fixture();
$w                     = wf( $r, $s );
$a                     = $w->replace();
$z                     = wf( $r, $s, array( 'before_restore_activate' => fn()=>false ) )->restore();
ok( ! $z['success'] && ! empty( $z['rolled_back'] ), 'restore rollback' );
ok( false !== strpos( file_get_contents( "$r/object-cache.php" ), 'Beplus' ), 'restore rolled back current' );
list($r, $s) = fixture();
$w           = wf( $r, $s );
$a           = $w->replace();
$m           = "$r/bepluspb-backups/restore-manifest.json";
file_put_contents(
	$m,
	json_encode(
		array(
			'backup' => '../../etc/passwd',
			'sha256' => str_repeat( '0', 64 ),
		)
	)
);
ok( ! $w->restore()['success'], 'manifest traversal rejected' );
list($r, $s) = fixture();
$w           = wf( $r, $s );
$a           = $w->replace();
$m           = "$r/bepluspb-backups/restore-manifest.json";
$j           = json_decode( file_get_contents( $m ), true );
$j['sha256'] = str_repeat( 'f', 64 );
file_put_contents( $m, json_encode( $j ) );
ok( ! $w->restore()['success'], 'manifest tamper rejected' );
list($r, $s) = fixture();
$w           = wf( $r, $s, array( 'unique_token' => fn()=> 'same' ) );
$b1          = $w->backup_file( "$r/object-cache.php", 'foreign' );
$b2          = $w->backup_file( "$r/object-cache.php", 'foreign' );
ok( $b1['success'] && $b2['success'] && $b1['path'] !== $b2['path'], 'duplicate names avoided' );
list($r, $s) = fixture();
$w           = wf( $r, $s ); for ( $i = 0;$i < 6;$i++ ) {
	file_put_contents( "$r/object-cache.php", "<?php // foreign $i" );
	$w->backup_file( "$r/object-cache.php", 'foreign' );
} $files = glob( "$r/bepluspb-backups/*.bak" );
ok( count( $files ) <= 3, 'retention bounded' );
list($r, $s) = fixture();
file_put_contents( $s, '<?php // invalid' );
ok( ! wf( $r, $s )->preflight( 'replace' )['success'], 'replacement signature validated' );
list($r, $s) = fixture();
$x           = wf( $r, $s, array( 'backend_test' => fn()=>array( 'success' => false ) ) )->preflight( 'replace' );
ok( ! $x['success'], 'backend health required' );
echo "PASS: $n assertions\n";
