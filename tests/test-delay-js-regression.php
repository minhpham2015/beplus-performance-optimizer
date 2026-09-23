<?php
/**
 * Standalone Delay JS regression tests.
 *
 * Run: php -n tests/test-delay-js-regression.php
 *
 * @package Beplus_Performance_Booster
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_PLUGIN_URL', 'https://example.test/plugin/' );
define( 'BEPLUSPB_VERSION', 'test' );

$GLOBALS['bepluspb_test_options'] = array(
	'js_exclude'      => '',
	'js_delay_rdelay' => 0,
);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
/**
 * Escape a test exception message.
 *
 * @param string $message Test message.
 * @return string
 */
function esc_html( $message ) {
	return $message;
}

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}
function bepluspb_get_options() {
	return $GLOBALS['bepluspb_test_options'];
}
function bepluspb_parse_exclude_list( $value ) {
	return array_filter( array_map( 'trim', explode( "\n", $value ) ) );
}
function esc_attr( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $value ) {
	return $value;
}
function wp_json_encode( $value ) {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test stub replicating the real wp_json_encode() signature.
}
function wp_generate_password( $length ) {
	return str_repeat( 'x', $length );
}
function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['bepluspb_actions'][] = array( $hook, $callback, $priority );
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['bepluspb_filters'][] = array( $hook, $callback, $priority, $accepted_args );
}
function wp_enqueue_script() {}
function wp_localize_script() {}
function wp_dequeue_script() {}
function wp_deregister_script() {}

require_once dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';

$level = ob_get_level();
BEPLUSPB_JS::init_advanced_delay( array() );
$registered_template_redirect = null;
foreach ( $GLOBALS['bepluspb_actions'] as $action ) {
	if ( 'template_redirect' === $action[0] ) {
		$registered_template_redirect = $action[1];
	}
}
assert_true( null !== $registered_template_redirect, 'Advanced Delay must register a template_redirect callback' );
call_user_func( $registered_template_redirect );
assert_true( ob_get_level() === $level + 1, 'Advanced Delay must start buffering once its template_redirect callback runs' );
ob_end_clean();

// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- fixture string being passed through delay_scripts(), never actually output/enqueued.
$tag = '<script type="module" nomodule integrity="sha256-test" crossorigin="anonymous" nonce="abc" referrerpolicy="no-referrer" src="/app.js"></script>';
$out = BEPLUSPB_JS::delay_scripts( $tag, 'app', '/app.js' );
assert_true( false !== strpos( $out, 'data-bepluspb-type="module"' ), 'Simple Delay must preserve module type' );
assert_true( false !== strpos( $out, 'integrity="sha256-test"' ), 'Simple Delay must preserve SRI' );
assert_true( false !== strpos( $out, 'crossorigin="anonymous"' ), 'Simple Delay must preserve crossorigin' );
assert_true( false !== strpos( $out, 'nonce="abc"' ), 'Simple Delay must preserve nonce' );
assert_true( false !== strpos( $out, 'referrerpolicy="no-referrer"' ), 'Simple Delay must preserve referrer policy' );

echo "PASS: Delay JS regression assertions\n";
