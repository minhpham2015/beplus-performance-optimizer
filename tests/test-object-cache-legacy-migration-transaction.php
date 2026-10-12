<?php
/**
 * Legacy Beplus drop-in/config migration transaction regression test.
 *
 * Run: php -n tests/test-object-cache-legacy-migration-transaction.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- isolated filesystem regression harness.
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
$root = sys_get_temp_dir() . '/bepluspb-legacy-transaction-' . bin2hex( random_bytes( 6 ) );
mkdir( $root, 0700, true );
define( 'WP_CONTENT_DIR', $root );
function __( $text ) {
	return $text; }
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) {
	return trim( (string) $value ); }
function wp_delete_file( $path ) {
	global $legacy_delete_fail;
	if ( ! empty( $legacy_delete_fail ) && basename( $path ) === '.bepluspb_oc.json' ) {
		return false;
	}
	return unlink( $path ); }
function legacy_transaction_ok( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}
function legacy_transaction_reset( $dropin, $json ) {
	foreach ( glob( WP_CONTENT_DIR . '/*' ) ?: array() as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path ); }
	}
	foreach ( glob( WP_CONTENT_DIR . '/.*' ) ?: array() as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path ); }
	}
	file_put_contents( WP_CONTENT_DIR . '/object-cache.php', $dropin );
	file_put_contents( WP_CONTENT_DIR . '/.bepluspb_oc.json', $json );
}
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';
$legacy_dropin = file_get_contents( __DIR__ . '/fixtures/object-cache-legacy-1.1.13.fixture' );
$legacy_json   = json_encode(
	array(
		'enabled' => true,
		'driver'  => 'redis',
		'host'    => '127.0.0.1',
		'port'    => 6379,
	)
);

// A foreign target must be refused before either legacy config file is touched.
$foreign = "<?php\n// Redis Object Cache by another vendor.\n";
legacy_transaction_reset( $foreign, $legacy_json );
$result = BEPLUSPB_Object_Cache::install_dropin();
legacy_transaction_ok( empty( $result['success'] ), 'foreign drop-in is refused' );
legacy_transaction_ok( $foreign === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'foreign drop-in remains byte-for-byte unchanged' );
legacy_transaction_ok( $legacy_json === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'legacy JSON remains byte-for-byte unchanged after refusal' );
legacy_transaction_ok( ! file_exists( WP_CONTENT_DIR . '/.bepluspb_oc.php' ), 'guarded PHP config is not created after refusal' );

// The combined settings/install API must perform the same preflight before
// write_config() can migrate or delete the legacy file.
legacy_transaction_reset( $foreign, $legacy_json );
$result = BEPLUSPB_Object_Cache::install_with_config( array( 'object_cache_enabled' => 1 ) );
legacy_transaction_ok( empty( $result['success'] ), 'combined install refuses a foreign drop-in' );
legacy_transaction_ok( $legacy_json === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'combined refusal leaves legacy JSON unchanged' );
legacy_transaction_ok( ! file_exists( WP_CONTENT_DIR . '/.bepluspb_oc.php' ), 'combined refusal creates no guarded config' );

// A genuine historical Beplus target is upgradeable, then its config is migrated.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
BEPLUSPB_Object_Cache::set_filesystem_hooks( array( 'runtime_compatible' => static function () { return true; } ) );
$result = BEPLUSPB_Object_Cache::install_dropin();
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( ! empty( $result['success'] ), 'historical Beplus drop-in upgrades successfully' );
legacy_transaction_ok( file_get_contents( BEPLUSPB_PLUGIN_DIR . 'lib/object-cache.php' ) === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'target contains the current bundled drop-in' );
legacy_transaction_ok( ! file_exists( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'legacy JSON is removed after successful migration' );
$guarded = file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php' );
legacy_transaction_ok( 0 === strpos( $guarded, "<?php exit; ?>\n" ), 'guarded PHP config is created' );
legacy_transaction_ok( json_decode( substr( $guarded, strlen( "<?php exit; ?>\n" ) ), true )['enabled'] === true, 'migrated guarded payload is valid and enabled' );

// The combined transaction must not remove the legacy JSON while the active
// historical drop-in still depends on it. A staged activation failure must
// restore every pre-transaction byte.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
$prior_guarded = "<?php exit; ?>\n" . json_encode( array( 'enabled' => true, 'host' => 'old-host' ) );
file_put_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php', $prior_guarded );
$saw_legacy_during_activation = false;
BEPLUSPB_Object_Cache::set_filesystem_hooks(
	array(
		'rename' => static function ( $from, $to ) use ( &$saw_legacy_during_activation ) {
			if ( basename( $from ) !== 'object-cache.php' && basename( $to ) === 'object-cache.php' ) {
				$saw_legacy_during_activation = is_file( WP_CONTENT_DIR . '/.bepluspb_oc.json' );
				return false;
			}
			return rename( $from, $to );
		},
	)
);
$result = BEPLUSPB_Object_Cache::install_with_config( array( 'object_cache_enabled' => 1, 'object_cache_host' => 'new-host' ) );
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( empty( $result['success'] ), 'combined active-legacy install reports staged activation failure' );
legacy_transaction_ok( $saw_legacy_during_activation, 'legacy JSON remains available until drop-in activation succeeds' );
legacy_transaction_ok( $legacy_json === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'failed combined install restores exact legacy JSON' );
legacy_transaction_ok( $prior_guarded === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php' ), 'failed combined install restores exact guarded config' );
legacy_transaction_ok( $legacy_dropin === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'failed combined install restores exact active legacy drop-in' );

// Outer rollback must not hide an inability to restore either config snapshot.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
file_put_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php', $prior_guarded );
BEPLUSPB_Object_Cache::set_filesystem_hooks(
	array(
		'rename' => static function ( $from, $to ) {
			if ( basename( $from ) !== 'object-cache.php' && basename( $to ) === 'object-cache.php' ) { return false; }
			return rename( $from, $to );
		},
		'restore' => static function ( $path ) { return basename( $path ) !== '.bepluspb_oc.php'; },
	)
);
$result = BEPLUSPB_Object_Cache::install_with_config( array( 'object_cache_enabled' => 1 ) );
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( ! empty( $result['critical'] ) && ! empty( $result['uncertain'] ), 'guarded-config restoration failure is critical and uncertain' );

legacy_transaction_reset( $legacy_dropin, $legacy_json );
BEPLUSPB_Object_Cache::set_filesystem_hooks(
	array(
		'rename' => static function ( $from, $to ) {
			if ( basename( $from ) !== 'object-cache.php' && basename( $to ) === 'object-cache.php' ) { return false; }
			return rename( $from, $to );
		},
		'restore' => static function ( $path ) { return basename( $path ) !== '.bepluspb_oc.json'; },
	)
);
$result = BEPLUSPB_Object_Cache::install_with_config( array( 'object_cache_enabled' => 1 ) );
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( ! empty( $result['critical'] ) && ! empty( $result['uncertain'] ), 'legacy-JSON restoration failure is critical and uncertain' );

// A production runtime probe must fail closed; compatibility is never default-true.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
$result = BEPLUSPB_Object_Cache::install_dropin();
legacy_transaction_ok( empty( $result['success'] ), 'missing real WordPress runtime fails compatibility closed' );
legacy_transaction_ok( is_file( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'legacy JSON is retained when runtime compatibility cannot be proved' );

// A target substituted after preflight must never be overwritten.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
$substitute = "<?php\n// foreign target substituted during transaction\n";
BEPLUSPB_Object_Cache::set_filesystem_hooks(
	array(
		'before_target_activation' => static function () use ( $substitute ) {
			file_put_contents( WP_CONTENT_DIR . '/object-cache.php', $substitute );
			return true;
		},
	)
);
$result = BEPLUSPB_Object_Cache::install_dropin();
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( empty( $result['success'] ), 'post-preflight target substitution aborts activation' );
legacy_transaction_ok( $substitute === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'post-preflight foreign target remains exact and is not overwritten' );

// Fault injection in the exact final hash-check-to-rename window must be caught
// by read-back verification of the artifact actually moved to rollback.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
BEPLUSPB_Object_Cache::set_filesystem_hooks( array(
	'after_target_hash_check' => static function () use ( $substitute ) { file_put_contents( WP_CONTENT_DIR . '/object-cache.php', $substitute ); return true; },
	'runtime_compatible' => static function () { return true; },
) );
$result = BEPLUSPB_Object_Cache::install_dropin();
BEPLUSPB_Object_Cache::set_filesystem_hooks();
legacy_transaction_ok( empty( $result['success'] ), 'exact-window target substitution aborts before activation' );
legacy_transaction_ok( $substitute === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'exact-window moved rollback artifact is verified and substitute restored exactly' );

// Failure to delete legacy JSON after activation cannot report success with an
// ambiguous pairing: the exact old drop-in/config/JSON transaction is restored.
legacy_transaction_reset( $legacy_dropin, $legacy_json );
file_put_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php', $prior_guarded );
$legacy_delete_fail = true;
BEPLUSPB_Object_Cache::set_filesystem_hooks( array( 'runtime_compatible' => static function () { return true; } ) );
$result = BEPLUSPB_Object_Cache::install_dropin();
BEPLUSPB_Object_Cache::set_filesystem_hooks();
$legacy_delete_fail = false;
legacy_transaction_ok( empty( $result['success'] ) && ! empty( $result['rolled_back'] ), 'legacy deletion failure reports verified rollback' );
legacy_transaction_ok( $legacy_dropin === file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ), 'legacy deletion failure restores exact drop-in' );
legacy_transaction_ok( $prior_guarded === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.php' ), 'legacy deletion failure restores exact guarded config' );
legacy_transaction_ok( $legacy_json === file_get_contents( WP_CONTENT_DIR . '/.bepluspb_oc.json' ), 'legacy deletion failure preserves exact legacy JSON' );

echo "PASS: legacy Beplus drop-in/config migration transaction (31 assertions)\n";
