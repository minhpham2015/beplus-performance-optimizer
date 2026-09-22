<?php
/**
 * Standalone regression tests for Object Cache admin warnings.
 *
 * Run: php tests/test-object-cache-admin-warning.php
 *
 * @package Beplus_Performance_Booster
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_options' );

$GLOBALS['bepluspb_test_options'] = array();

/**
 * Translation stub.
 *
 * @param string $text Text.
 */
function __( $text ) {
	return $text;
}

/**
 * Escaped translation stub.
 *
 * @param string $text Text.
 */
function esc_html__( $text ) {
	return $text;
}

/**
 * HTML escaping stub.
 *
 * @param string $text Text.
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Text sanitization stub.
 *
 * @param string $text Text.
 */
function sanitize_text_field( $text ) {
	return (string) $text;
}

/** Return test options. */
function bepluspb_get_options() {
	return $GLOBALS['bepluspb_test_options'];
}

require_once dirname( __DIR__ ) . '/includes/class-bepluspb-object-cache.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

/**
 * Assert a test condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 */
function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

// Disabled configuration must stay quiet.
$GLOBALS['bepluspb_test_options'] = array(
	'object_cache_enabled' => 0,
	'object_cache_driver'  => 'memcached',
);
ob_start();
BEPLUSPB_Admin::maybe_show_object_cache_warning();
$disabled_output = ob_get_clean();
assert_true( '' === $disabled_output, 'disabled Object Cache should not render a warning' );

// Enabled but unavailable backend must warn without exposing connection details.
$GLOBALS['bepluspb_test_options'] = array(
	'object_cache_enabled'  => 1,
	'object_cache_driver'   => 'memcached',
	'object_cache_host'     => 'secret.internal.example',
	'object_cache_port'     => 11211,
	'object_cache_password' => 'super-secret-password',
);
ob_start();
BEPLUSPB_Admin::maybe_show_object_cache_warning();
$warning_output = ob_get_clean();
assert_true( false !== strpos( $warning_output, 'Object Cache' ), 'warning should identify Object Cache' );
assert_true( false !== strpos( $warning_output, 'Memcached' ), 'warning should identify the configured driver' );
assert_true( false === strpos( $warning_output, 'secret.internal.example' ), 'warning must not expose the configured host' );
assert_true( false === strpos( $warning_output, 'super-secret-password' ), 'warning must not expose credentials' );

fwrite( STDOUT, "PASS: 6 assertions\n" );
