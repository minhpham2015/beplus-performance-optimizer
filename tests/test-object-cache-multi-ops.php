<?php
/**
 * Native *_multiple / flush_group support in lib/object-cache.php against a
 * real Redis. Skips (exit 0) when redis-server or the phpredis extension is
 * missing. Run: php tests/test-object-cache-multi-ops.php
 */
// phpcs:disable
$bin = trim( (string) shell_exec( 'command -v redis-server 2>/dev/null' ) );
if ( '' === $bin || ! class_exists( 'Redis' ) ) { echo "SKIP: redis-server/phpredis not available\n"; exit( 0 ); }

$port = 20000 + random_int( 0, 9000 );
$dir  = sys_get_temp_dir() . '/bepluspb-multi-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir );
exec( escapeshellarg( $bin ) . ' --port ' . $port . ' --save "" --daemonize yes --dir ' . escapeshellarg( $dir ) . ' >/dev/null 2>&1' );
register_shutdown_function( function () use ( $port, $dir ) { exec( 'redis-cli -p ' . (int) $port . ' shutdown nosave >/dev/null 2>&1; rm -rf ' . escapeshellarg( $dir ) ); } );
$probe = new Redis(); $up = false;
for ( $i = 0; $i < 30 && ! $up; $i++ ) { try { $up = @$probe->connect( '127.0.0.1', $port ); } catch ( Exception $e ) { usleep( 100000 ); } }
if ( ! $up ) { echo "SKIP: could not start redis-server\n"; exit( 0 ); }

file_put_contents( "$dir/.bepluspb_oc.php", "<?php exit; ?>\n" . json_encode( array( 'enabled' => true, 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => $port, 'persistent' => false, 'global_groups' => array( 'site-options' ), 'non_persistent_groups' => array( 'users' ) ) ) );
define( 'WPINC', 'wp-includes' ); define( 'ABSPATH', '/tmp/x/' ); define( 'WP_CONTENT_DIR', $dir );
function is_multisite() { return false; }
function t_ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require dirname( __DIR__ ) . '/lib/object-cache.php';

t_ok( wp_cache_supports( 'get_multiple' ) && wp_cache_supports( 'flush_group' ) && ! wp_cache_supports( 'nonsense' ), 'supports()' );
t_ok( array( 'a' => true, 'b' => true ) === wp_cache_set_multiple( array( 'a' => 1, 'b' => array( 'x' => 2 ) ), 'grp', 60 ), 'set_multiple' );
wp_cache_flush_runtime();
t_ok( array( 'a' => 1, 'b' => array( 'x' => 2 ), 'nope' => false ) === wp_cache_get_multiple( array( 'a', 'b', 'nope' ), 'grp' ), 'get_multiple reads through Redis' );
$keys = $probe->keys( '*:grp:*' );
t_ok( 2 === count( $keys ) && $probe->ttl( $keys[0] ) > 0, 'keys stored with TTL' );
$d = wp_cache_delete_multiple( array( 'a', 'nope' ), 'grp' );
t_ok( true === $d['a'] && false === $d['nope'], 'delete_multiple per-key result' );
wp_cache_set( 'k', 'v', 'other' );
t_ok( wp_cache_flush_group( 'grp' ) && 0 === count( $probe->keys( '*:grp:*' ) ) && 1 === count( $probe->keys( '*:other:*' ) ), 'flush_group is scoped to the group' );
wp_cache_set_multiple( array( 'u' => 1 ), 'users' );
t_ok( 0 === count( $probe->keys( '*:users:*' ) ) && array( 'u' => 1 ) === wp_cache_get_multiple( array( 'u' ), 'users' ), 'non-persistent group never reaches Redis' );
// Tampered payload: objects must come back as incomplete class, never instantiated.
wp_cache_set( 'b', 'seed', 'grp' );
$bkey = $probe->keys( '*:grp:b' );
t_ok( 1 === count( $bkey ), 'seeded key present' );
$probe->set( $bkey[0], serialize( new ArrayObject( array( 1 ) ) ) );
$g = wp_cache_get_multiple( array( 'b' ), 'grp', true );
t_ok( is_object( $g['b'] ) && ! ( $g['b'] instanceof ArrayObject ), 'allowed_classes => false enforced in get_multiple' );
echo "PASS: object-cache multi-ops (10 assertions)\n";
