<?php
/**
 * Object-cache validation must use a verified PHP CLI binary.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$dir = sys_get_temp_dir() . '/bepluspb-cli-' . bin2hex( random_bytes( 5 ) );
mkdir( $dir, 0700, true );
define( 'ABSPATH', $dir . '/wordpress/' );
mkdir( ABSPATH, 0700, true );
define( 'WP_CONTENT_DIR', $dir . '/content' );
mkdir( WP_CONTENT_DIR, 0700, true );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
function __( $s ) { return $s; }
function wp_delete_file( $f ) { @unlink( $f ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';

$n = 0;
function t_ok( $condition, $message ) {
	global $n;
	++$n;
	if ( ! $condition ) { throw new RuntimeException( "FAIL: $message" ); }
}
function invoke_private( $name, $args = array() ) {
	$method = new ReflectionMethod( 'BEPLUSPB_Object_Cache', $name );
	$method->setAccessible( true );
	return $method->invokeArgs( null, $args );
}
function executable_probe( $path, $version, $body ) {
	file_put_contents( $path, "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg( $version ) . "\n" . $body . "\n" );
	chmod( $path, 0700 );
}

$fpm = $dir . '/php-fpm';
$cli = $dir . '/php';
$log = $dir . '/calls.log';
executable_probe( $fpm, 'PHP 8.4 (fpm-fcgi)', 'exit 0' );
executable_probe( $cli, 'PHP 8.4 (cli)', 'printf "%s\\n" "$*" >> ' . escapeshellarg( $log) . '; exit 0' );
file_put_contents( ABSPATH . 'wp-load.php', "<?php\n" );
$sample = $dir . '/sample.php';
file_put_contents( $sample, "<?php return true;\n" );

BEPLUSPB_Object_Cache::set_filesystem_hooks( array(
	'php_cli_candidates' => static function () use ( $fpm, $cli ) { return array( $fpm, $cli ); },
) );
t_ok( invoke_private( 'syntax_valid', array( $sample ) ), 'syntax validation succeeds through verified CLI' );
t_ok( invoke_private( 'runtime_compatible', array( $sample ) ), 'runtime probe succeeds through verified CLI' );
$calls = file_get_contents( $log );
t_ok( false !== strpos( $calls, '-n -l ' ), 'syntax path invokes selected CLI' );
t_ok( false !== strpos( $calls, '-d display_errors=0 -r ' ), 'runtime path invokes selected CLI' );
t_ok( false === strpos( $calls, 'fpm' ), 'validation paths never invoke rejected FPM candidate' );

BEPLUSPB_Object_Cache::set_filesystem_hooks( array(
	'php_cli_candidates' => static function () use ( $fpm ) { return array( $fpm ); },
) );
t_ok( false === invoke_private( 'syntax_valid', array( $sample ) ), 'syntax validation fails closed without CLI' );
t_ok( false === invoke_private( 'runtime_compatible', array( $sample ) ), 'runtime validation fails closed without CLI' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php' );
t_ok( false === strpos( $source, "escapeshellarg( PHP_BINARY ) . ' -n -l'" ), 'syntax path does not blindly execute PHP_BINARY' );
t_ok( false === strpos( $source, "escapeshellarg( PHP_BINARY ) . ' -d display_errors=0'" ), 'runtime path does not blindly execute PHP_BINARY' );

BEPLUSPB_Object_Cache::set_filesystem_hooks();
@unlink( $sample ); @unlink( ABSPATH . 'wp-load.php' ); @unlink( $log ); @unlink( $fpm ); @unlink( $cli );
@rmdir( ABSPATH ); @rmdir( WP_CONTENT_DIR ); @rmdir( $dir );
echo "PASS: verified PHP CLI selection ($n assertions)\n";
