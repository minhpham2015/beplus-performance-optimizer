<?php
/**
 * Guarded Object Cache config must be private, atomic, and fail closed.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$dir = sys_get_temp_dir() . '/bepluspb-cfg-' . bin2hex( random_bytes( 5 ) );
mkdir( $dir, 0700, true );
define( 'ABSPATH', $dir . '/wordpress/' );
mkdir( ABSPATH, 0700, true );
define( 'WP_CONTENT_DIR', $dir . '/content' );
mkdir( WP_CONTENT_DIR, 0700, true );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
function __( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_delete_file( $f ) { @unlink( $f ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function clean_cfg() { @unlink( WP_CONTENT_DIR . '/.bepluspb_oc.php' ); @unlink( WP_CONTENT_DIR . '/.bepluspb_oc.json' ); }
$cfg = WP_CONTENT_DIR . '/.bepluspb_oc.php';
$legacy = WP_CONTENT_DIR . '/.bepluspb_oc.json';
$guard = "<?php exit; ?>\n";

// Parent of ABSPATH is deliberately irrelevant/unwritable in production: config belongs in WP_CONTENT_DIR.
$ok = BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 'sentinel-password' ) );
t_ok( $ok && is_file( $cfg ), 'guarded config written inside WP_CONTENT_DIR' );
$raw = file_get_contents( $cfg );
t_ok( 0 === strpos( $raw, $guard ), 'config starts with exact PHP exit guard' );
t_ok( '0600' === substr( sprintf( '%o', fileperms( $cfg ) ), -4 ), 'config is mode 0600' );
t_ok( is_array( json_decode( substr( $raw, strlen( $guard ) ), true ) ), 'guard payload is valid JSON' );
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $cfg );
exec( $command, $included, $include_status );
t_ok( 0 === $include_status && array() === $included, 'direct PHP execution emits no credential bytes' );
clean_cfg();

// Valid legacy config migrates through the guarded writer and is removed only after verification.
file_put_contents( $legacy, json_encode( array( 'enabled' => true, 'password' => 'legacy-secret' ) ) );
$result = BEPLUSPB_Object_Cache::install_dropin();
t_ok( ! is_file( $legacy ) && is_file( $cfg ), 'valid legacy config migrated and removed before install' );
t_ok( false !== strpos( file_get_contents( $cfg ), 'legacy-secret' ), 'migration preserves legacy settings' );
clean_cfg(); @unlink( WP_CONTENT_DIR . '/object-cache.php' );

// Invalid exposed legacy is removed and prevents installing a new drop-in.
file_put_contents( $legacy, '{invalid' );
$result = BEPLUSPB_Object_Cache::install_dropin();
t_ok( empty( $result['success'] ) && ! is_file( $legacy ) && ! is_file( WP_CONTENT_DIR . '/object-cache.php' ), 'invalid legacy is removed and blocks drop-in install' );
clean_cfg();

// If a verified guarded target exists, stale exposed legacy credentials are removed.
t_ok( BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 'current-secret' ) ), 'create verified guarded target' );
file_put_contents( $legacy, json_encode( array( 'enabled' => true, 'password' => 'stale-secret' ) ) );
t_ok( BEPLUSPB_Object_Cache::write_config( array( 'object_cache_enabled' => 1, 'object_cache_password' => 'next-secret' ) ) && ! is_file( $legacy ), 'valid target causes stale legacy removal' );

// Uninstall cleanup removes and verifies both the guarded config and any legacy plaintext config.
file_put_contents( $legacy, json_encode( array( 'enabled' => true, 'password' => 'legacy-uninstall-secret' ) ) );
t_ok( BEPLUSPB_Object_Cache::delete_config(), 'delete_config reports complete guarded and legacy cleanup' );
t_ok( ! is_file( $cfg ) && ! is_file( $legacy ), 'delete_config removes guarded and legacy config files' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php' );
t_ok( false !== strpos( $source, 'rename(' ), 'writer uses same-directory atomic rename' );
t_ok( false !== strpos( $source, 'tempnam( WP_CONTENT_DIR' ), 'temporary file is created in target directory' );
t_ok( false !== strpos( $source, 'read_config_file' ), 'writer verifies config by reading it back' );

clean_cfg(); @rmdir( ABSPATH ); @rmdir( WP_CONTENT_DIR ); @rmdir( $dir );
echo "PASS: $n guarded object-cache config assertions\n";
