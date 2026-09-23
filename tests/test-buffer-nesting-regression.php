<?php
/**
 * Standalone regression test: nested output buffers must close in LIFO
 * order so Delay JS (advanced mode)'s rewritten output is not silently
 * discarded by another feature's buffer_end() (e.g. Remove Unused CSS).
 *
 * Run: php -n tests/test-buffer-nesting-regression.php
 *
 * @package Beplus_Performance_Booster
 */

define( 'ABSPATH', __DIR__ . '/' );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing
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

/**
 * Simulate the plugin's real buffer nesting: an outer plain buffer
 * (representing UCSS/HTML/CDN's own ob_start()) wrapping an inner buffer
 * with a rewrite callback (representing Delay JS advanced mode).
 *
 * @param callable $outer_close   Callback simulating the outer feature's
 *                                 buffer_end() logic under test.
 * @param int      $inner_close_priority_before_outer Whether the inner
 *                                 (Delay JS) buffer is closed before the
 *                                 outer one, as required for correctness.
 * @return string The content that ultimately reaches "the client".
 */
function simulate_nested_buffers( $outer_close, $inner_close_priority_before_outer ) {
	$outer_level = ob_get_level();
	ob_start(); // Capture whatever ultimately reaches "the client" for this simulated request.

	ob_start(); // Outer feature's plain buffer (level N+2).

	ob_start(
		function ( $buffer ) {
			return str_replace( 'ORIGINAL', 'REWRITTEN', $buffer );
		}
	); // Delay JS's buffer (level N+3), simulating advanced_rewrite().

	echo 'ORIGINAL';

	if ( $inner_close_priority_before_outer ) {
		// Correct LIFO order: innermost (Delay JS) closes first.
		ob_end_flush(); // Invokes the rewrite callback, flushes into outer buffer.
	}

	// Outer feature's buffer_end() logic under test.
	$outer_close( $outer_level + 1 );

	return ob_get_clean(); // Whatever ultimately reached "the client".
}

// ---------------------------------------------------------------------
// RED case: outer feature (bug reproduction of BEPLUSPB_UCSS::buffer_end())
// closes with a weak "< 1" check instead of an exact level match, and does
// so BEFORE the inner (Delay JS) buffer has been explicitly closed.
// ---------------------------------------------------------------------
$buggy_result = simulate_nested_buffers(
	function ( $outer_level_before ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		// Weak check: only verifies "some buffer is open", not the exact
		// level this feature itself opened. Mirrors the pre-fix UCSS bug.
		if ( ob_get_level() < 1 ) {
			return;
		}
		$html = ob_get_clean(); // Wrongly grabs the INNER (Delay JS) buffer.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- simulated raw HTML passthrough, mirrors the real buffer_end() pattern under test.
	},
	false // Inner buffer NOT explicitly closed first — reproduces the bug.
);

assert_true(
	false === strpos( $buggy_result, 'REWRITTEN' ),
	'sanity: the buggy simulation must reproduce lost Delay JS rewrite output'
);

// ---------------------------------------------------------------------
// GREEN case: correct LIFO order — inner (Delay JS) buffer closes first,
// then the outer feature closes its own buffer using an exact-level check.
// ---------------------------------------------------------------------
$fixed_result = simulate_nested_buffers(
	function ( $outer_level_before ) {
		if ( ob_get_level() !== $outer_level_before + 1 ) {
			return;
		}
		$html = ob_get_clean();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- simulated raw HTML passthrough, mirrors the real buffer_end() pattern under test.
	},
	true // Inner buffer explicitly closed first — correct LIFO order.
);

assert_true(
	false !== strpos( $fixed_result, 'REWRITTEN' ),
	'Delay JS rewrite output must survive when buffers close in correct LIFO order'
);

echo "PASS: buffer-nesting regression assertions\n";
