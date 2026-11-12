<?php
/**
 * The Object Cache drop-in must be loadable when its config is disabled, missing or unusable.
 *
 * WordPress core loads wp-content/object-cache.php from wp_start_object_cache() only while no
 * wp_cache_init() exists. A drop-in that declared global wp_cache_*() functions at the top level
 * AND required core's cache.php when disabled died with "Cannot redeclare wp_cache_init()" on
 * every request, wp-admin included (PHP declares top-level functions at compile time, before the
 * early return runs). Also covers the plugin side: an outdated drop-in is never left behind a
 * disabled config.
 *
 * Run: php -n tests/test-object-cache-dropin-disabled-load.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
$dir  = sys_get_temp_dir() . '/bepluspb-dl-' . bin2hex( random_bytes( 5 ) );
mkdir( $dir . '/wordpress/wp-includes', 0700, true );
mkdir( $dir . '/content', 0700, true );
register_shutdown_function( function () use ( $dir ) { exec( 'rm -rf ' . escapeshellarg( $dir ) ); } );
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

$dropin_src = file_get_contents( $root . '/lib/object-cache.php' );

// --- A stand-in for core's wp-includes/cache.php: SAME unconditional global function names as the drop-in.
preg_match_all( '/^\s*function (wp_cache_\w+)\(/m', $dropin_src, $m );
$names = array_unique( $m[1] );
t_ok( count( $names ) >= 15 && in_array( 'wp_cache_init', $names, true ), 'the drop-in defines the wp_cache_* API (' . count( $names ) . ' functions)' );
$core = "<?php\n";
foreach ( $names as $fn ) { $core .= "function $fn() { return '$fn:core'; }\n"; }
file_put_contents( "$dir/wordpress/wp-includes/cache.php", $core );
copy( $root . '/lib/object-cache.php', "$dir/content/object-cache.php" );

// --- Mimic wp_start_object_cache() in a separate PHP process.
file_put_contents( "$dir/boot.php", '<?php
define( "ABSPATH", ' . var_export( "$dir/wordpress/", true ) . ' ); define( "WPINC", "wp-includes" ); define( "WP_CONTENT_DIR", ' . var_export( "$dir/content", true ) . ' );
function is_multisite() { return false; }
$pre = "1" === getenv( "PRELOAD_CORE" );
if ( $pre ) { require ABSPATH . WPINC . "/cache.php"; }            // e.g. an advanced-cache.php that already loaded core cache functions
$ext = false;
if ( ! function_exists( "wp_cache_init" ) ) {
	require_once WP_CONTENT_DIR . "/object-cache.php";
	if ( function_exists( "wp_cache_init" ) ) { $ext = true; }      // exactly what core does after loading the drop-in
}
if ( ! $ext ) { require_once ABSPATH . WPINC . "/cache.php"; }       // core falls back to its own cache
$owner = basename( ( new ReflectionFunction( "wp_cache_init" ) )->getFileName() );
$probe = null;
if ( "object-cache.php" === $owner ) { wp_cache_init(); wp_cache_set( "k", "v", "g" ); $probe = wp_cache_get( "k", "g" ); }
echo json_encode( array( "ext" => $ext, "owner" => $owner, "probe" => $probe, "build" => defined( "BEPLUSPB_DROPIN_BUILD_ID" ) ) );
' );
function boot( $config, $preload = false ) {
	global $dir;
	$cfg = "$dir/content/.bepluspb_oc.php";
	@unlink( $cfg );
	if ( null !== $config ) { file_put_contents( $cfg, $config ); }
	$out = (string) shell_exec( 'PRELOAD_CORE=' . ( $preload ? '1' : '0' ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( "$dir/boot.php" ) . ' 2>&1' );
	return array( 'raw' => $out, 'json' => json_decode( $out, true ) );
}
$guard = "<?php exit; ?>\n";

foreach ( array(
	'no config file at all'                  => null,
	'config with enabled=false'              => $guard . '{"enabled":false}',
	'config without the exit guard'          => '{"enabled":true}',
	'config with invalid JSON'               => $guard . '{"enabled":tru',
	'empty config file'                      => '',
) as $label => $config ) {
	$r = boot( $config );
	t_ok( is_array( $r['json'] ) && false === stripos( $r['raw'], 'Fatal' ), "$label: the site boots (no 'Cannot redeclare'): " . trim( substr( $r['raw'], 0, 160 ) ) );
	t_ok( false === $r['json']['ext'], "$label: WordPress is NOT told an external object cache exists (so transients stay in the database)" );
	t_ok( 'cache.php' === $r['json']['owner'], "$label: WordPress core's own cache is used" );
}

$r = boot( $guard . '{"enabled":true,"driver":"redis","host":"127.0.0.1","port":6379}' );
t_ok( is_array( $r['json'] ) && false === stripos( $r['raw'], 'Fatal' ), 'enabled config boots: ' . trim( substr( $r['raw'], 0, 160 ) ) );
t_ok( true === $r['json']['ext'] && 'object-cache.php' === $r['json']['owner'] && true === $r['json']['build'], 'enabled config: the drop-in provides the cache and exposes its build id' );
t_ok( 'v' === $r['json']['probe'], 'enabled config without the redis extension still caches in memory (never breaks the site)' );

$r = boot( $guard . '{"enabled":true}', true );
t_ok( is_array( $r['json'] ) && false === stripos( $r['raw'], 'Fatal' ), 'core cache functions already loaded earlier (advanced-cache.php): no redeclare fatal' );
$r = boot( null, true );
t_ok( is_array( $r['json'] ) && false === stripos( $r['raw'], 'Fatal' ), 'core cache functions already loaded and no config: no fatal' );

// --- Structural guard (no tokenizer under `php -n`): global functions live inside a conditional block, so
// none starts at column 0, and the block that holds them is the `function_exists( 'wp_cache_init' )` guard.
$unindented = array();
foreach ( explode( "\n", $dropin_src ) as $line ) {
	if ( preg_match( '/^function\s+(\w+)\s*\(/', $line, $mm ) ) { $unindented[] = $mm[1]; }
}
t_ok( array() === $unindented, 'no global function is declared unconditionally (PHP would hoist it): ' . implode( ', ', $unindented ) );
t_ok( 1 === preg_match( "/^if \( ! function_exists\( 'wp_cache_init' \) \) \{\n\tglobal \\\$wp_object_cache/m", $dropin_src ), 'the wp_cache_*() API sits inside the function_exists( wp_cache_init ) guard' );
t_ok( false === strpos( $dropin_src, "require_once ABSPATH . WPINC . '/cache.php'" ), 'the drop-in never requires core cache.php itself' );

// --- Plugin side: an outdated drop-in must not stay behind a disabled config.
define( 'ABSPATH', "$dir/wordpress/" );
define( 'WP_CONTENT_DIR', "$dir/content" );
define( 'BEPLUSPB_PLUGIN_DIR', $root . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
function __( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
$GLOBALS['delete_works'] = true;
function wp_delete_file( $f ) { if ( $GLOBALS['delete_works'] ) { @unlink( $f ); } }
function bepluspb_get_options() { return array(); }
require $root . '/includes/class-bepluspb-object-cache.php';
$OC     = 'BEPLUSPB_Object_Cache';
$target = WP_CONTENT_DIR . '/object-cache.php';
$cfgf   = WP_CONTENT_DIR . '/.bepluspb_oc.php';
$cur    = $OC::DROPIN_BUILD_ID;
$mk     = function ( $id ) { return "<?php\n// Beplus Performance Booster Object Cache Drop-in\ndefine( 'BEPLUSPB_DROPIN_BUILD_ID', '$id' );\n"; };
$reset  = function () use ( $target, $cfgf ) { @unlink( $target ); @unlink( $cfgf ); @unlink( WP_CONTENT_DIR . '/.bepluspb_oc.json' ); $GLOBALS['delete_works'] = true; };

t_ok( 'bepluspb-1.2.0-20261112' === $cur && false !== strpos( $dropin_src, "'$cur'" ), 'the bundled drop-in carries the new build id' );
t_ok( in_array( 'bepluspb-1.1.13-20261014', $OC::KNOWN_DROPIN_BUILD_IDS, true ) && $cur === $OC::KNOWN_DROPIN_BUILD_IDS[0], 'the previous build id is still known (so it can be recognised, updated and removed)' );

$reset(); file_put_contents( $target, $mk( 'bepluspb-1.1.13-20261014' ) );
t_ok( true === $OC::is_outdated_dropin_installed() && true === $OC::get_status()['dropin_outdated'], 'an older Beplus build is reported as outdated' );
$reset(); file_put_contents( $target, $mk( $cur ) );
t_ok( false === $OC::is_outdated_dropin_installed() && false === $OC::get_status()['dropin_outdated'], 'the current build is not outdated' );
$reset(); file_put_contents( $target, "<?php\n// Redis Object Cache Drop-In by someone else\n" );
t_ok( false === $OC::is_outdated_dropin_installed() && false === $OC::get_status()['dropin_installed'], 'a foreign drop-in is never "ours", so never outdated' );
$reset();
t_ok( false === $OC::is_outdated_dropin_installed(), 'no drop-in: nothing outdated' );

$disabled = array( 'object_cache_enabled' => 0 );
$enabled  = array( 'object_cache_enabled' => 1 );
$reset(); file_put_contents( $target, $mk( 'bepluspb-1.1.13-20261014' ) );
t_ok( true === $OC::write_config( $disabled ), 'disabling with an outdated drop-in succeeds' );
t_ok( ! file_exists( $target ), 'the outdated drop-in is removed BEFORE the disabled config is written (it would fatal behind it)' );
$written = is_file( $cfgf ) ? json_decode( substr( file_get_contents( $cfgf ), strlen( "<?php exit; ?>\n" ) ), true ) : null;
t_ok( is_array( $written ) && false === $written['enabled'], 'the disabled config is written once the drop-in is gone' );

$reset(); file_put_contents( $target, $mk( 'bepluspb-1.1.13-20261014' ) ); $GLOBALS['delete_works'] = false;
t_ok( false === $OC::write_config( $disabled ), 'if the outdated drop-in cannot be removed, the disabled config is NOT written' );
t_ok( file_exists( $target ) && ! file_exists( $cfgf ), 'drop-in kept and no disabled config left behind it' );
$GLOBALS['delete_works'] = true;

$reset(); file_put_contents( $target, $mk( 'bepluspb-1.1.13-20261014' ) );
t_ok( true === $OC::write_config( $enabled ) && file_exists( $target ), 'enabling never removes the drop-in' );
$reset(); file_put_contents( $target, $mk( $cur ) );
t_ok( true === $OC::write_config( $disabled ) && file_exists( $target ), 'the current build stays installed when disabled (it is inert now)' );
$reset(); file_put_contents( $target, "<?php\n// foreign cache\n" );
t_ok( true === $OC::write_config( $disabled ) && file_exists( $target ), 'a foreign drop-in is never deleted' );
$reset();
t_ok( true === $OC::write_config( $disabled ) && is_file( $cfgf ), 'no drop-in: disabled config is written as before' );
$reset();

// --- uninstall.php keeps the config when a Beplus drop-in could not be removed.
$un = file_get_contents( $root . '/uninstall.php' );
t_ok( 1 === preg_match( '/uninstall_dropin\(\);\s*\/\/[^\n]*\n\s*if \( ! BEPLUSPB_Object_Cache::is_dropin_installed\(\) \) \{\s*BEPLUSPB_Object_Cache::delete_config\(\);/', $un ), 'uninstall deletes the config only when no Beplus drop-in remains' );

echo "PASS: $n object cache drop-in disabled-load assertions\n";
