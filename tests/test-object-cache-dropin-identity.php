<?php
/**
 * Exact drop-in build identity regression tests.
 *
 * Run: php tests/test-object-cache-dropin-identity.php
 *
 * @package Beplus_Performance_Booster
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );

/**
 * Minimal assertion helper for this standalone CLI test.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Failure message.
 * @return void
 */
function identity_ok( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- standalone CLI test harness (php -n, no WordPress loaded); reports to STDERR, WP_Filesystem is unavailable under php -n.
		exit( 1 );
	}
}
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-dropin-workflow.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';
$shipped = dirname( __DIR__ ) . '/lib/object-cache.php';
$foreign = tempnam( sys_get_temp_dir(), 'bepluspb-foreign-' );
file_put_contents( $foreign, "<?php\n// Beplus Performance Booster Object Cache Drop-in\n" );
$crafted = tempnam( sys_get_temp_dir(), 'bepluspb-crafted-' );
file_put_contents( $crafted, "<?php\ndefine( 'BEPLUSPB_DROPIN_BUILD_ID', 'foreign-build' );\n" );
$workflow_method = new ReflectionMethod( BEPLUSPB_Dropin_Workflow::class, 'source_valid_path' );
$workflow_method->setAccessible( true );
$workflow       = new BEPLUSPB_Dropin_Workflow( sys_get_temp_dir(), $shipped );
$manager_method = new ReflectionMethod( BEPLUSPB_Object_Cache::class, 'is_our_dropin' );
$manager_method->setAccessible( true );
identity_ok( $workflow_method->invoke( $workflow, $shipped ), 'workflow recognizes shipped drop-in' );
identity_ok( $manager_method->invoke( null, $shipped ), 'manager recognizes shipped drop-in' );
identity_ok( ! $workflow_method->invoke( $workflow, $foreign ), 'workflow rejects generic product phrase' );
identity_ok( ! $manager_method->invoke( null, $foreign ), 'manager rejects generic product phrase' );
identity_ok( ! $workflow_method->invoke( $workflow, $crafted ), 'workflow rejects wrong build identity' );
identity_ok( ! $manager_method->invoke( null, $crafted ), 'manager rejects wrong build identity' );
$historical = __DIR__ . '/fixtures/object-cache-legacy-f4149be.fixture';
identity_ok( is_file( $historical ), 'historical f4149be fixture exists' );
identity_ok( 'c63608062a5a62de5c170206106459f5ef90825dd99551a643a9f8ac38d7f15b' === hash_file( 'sha256', $historical ), 'historical f4149be fixture has the exact reviewed hash' );
identity_ok( $manager_method->invoke( null, $historical ), 'manager recognizes exact historical f4149be drop-in' );
identity_ok( BEPLUSPB_Dropin_Workflow::DROPIN_BUILD_ID === BEPLUSPB_Object_Cache::DROPIN_BUILD_ID, 'both owners share exact build identity' );
identity_ok(
	BEPLUSPB_Dropin_Workflow::KNOWN_DROPIN_BUILD_IDS === BEPLUSPB_Object_Cache::KNOWN_DROPIN_BUILD_IDS,
	'both owners share the identical known-build-ids list (duplicated, not cross-referenced, so uninstall.php — which never loads the workflow class — does not fatal)'
);
$workflow_is_known = new ReflectionMethod( BEPLUSPB_Dropin_Workflow::class, 'is_known_dropin_path' );
$workflow_is_known->setAccessible( true );
identity_ok( $workflow_is_known->invoke( $workflow, $shipped ), 'workflow recognizes shipped drop-in as a known build id (not just the current one)' );
identity_ok( ! $workflow_is_known->invoke( $workflow, $crafted ), 'workflow known-ids check still rejects a wrong/foreign build id' );
unlink( $foreign );
unlink( $crafted );
echo "PASS: exact drop-in build identity (13 assertions)\n";
