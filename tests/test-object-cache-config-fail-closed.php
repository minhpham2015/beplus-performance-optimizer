<?php
/**
 * write_config() must never persist a plaintext password unless the deny rule is in place (Hard Rule #1).
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$dir = sys_get_temp_dir() . '/bepluspb-cfg-' . bin2hex( random_bytes( 5 ) );
mkdir( $dir, 0700, true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', $dir );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
function __( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_delete_file( $f ) { @unlink( $f ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$cfg = "$dir/.bepluspb_oc.json";

// 1. Normal path: rule appended, config written 0600.
$ok = BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 's3cret' ) );
t_ok( $ok && is_file( $cfg ), 'config written when protection succeeds' );
t_ok( false !== strpos( file_get_contents( "$dir/.htaccess" ), '.bepluspb_oc.json' ), 'deny rule present' );
t_ok( '0600' === substr( sprintf( '%o', fileperms( $cfg ) ), -4 ), 'config is 0600' );
BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 'x' ) );
t_ok( 1 === substr_count( file_get_contents( "$dir/.htaccess" ), 'Require all denied' ), 'rule not duplicated' );
unlink( $cfg );

// 2. Unwritable .htaccess (a directory in its place): password config must NOT be written.
unlink( "$dir/.htaccess" ); mkdir( "$dir/.htaccess" );
$ok = @BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 's3cret' ) );
t_ok( false === $ok && ! file_exists( $cfg ), 'fails closed: no password written without protection' );

echo "PASS: $n object-cache config fail-closed assertions\n";
