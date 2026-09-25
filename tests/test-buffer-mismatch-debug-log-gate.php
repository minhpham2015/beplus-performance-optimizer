<?php
/**
 * Standalone regression test: the buffer-mismatch diagnostic error_log()
 * in BEPLUSPB_UCSS::buffer_end() and BEPLUSPB_JS::advanced_buffer_end()
 * must only fire when WP_DEBUG_LOG is actually enabled — it must stay
 * silent on a healthy production site where WP_DEBUG/WP_DEBUG_LOG are
 * unset, even though PHP's own error_log() function always exists.
 *
 * Run: php -n tests/test-buffer-mismatch-debug-log-gate.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- standalone CLI test harness (php -n, no WordPress loaded); message is a fixed developer-facing assertion string, never rendered as HTML.
	}
}

/**
 * Extract the exact guard condition used before calling error_log() in the
 * given buffer_end()-style method, so we can assert its semantics without
 * needing a real WordPress environment or output-buffer plumbing.
 *
 * @param string $file   Absolute path to the source file.
 * @param string $needle A short substring uniquely identifying the guard
 *                        line (e.g. the error_log( call that follows it).
 * @return string The trimmed condition inside the nearest preceding `if (`.
 */
function extract_guard_condition( $file, $needle ) {
	$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
	$pos    = strpos( $source, $needle );
	assert_true( false !== $pos, "fixture error: needle not found in {$file}" );

	$if_pos = strrpos( substr( $source, 0, $pos ), 'if (' );
	assert_true( false !== $if_pos, "fixture error: no preceding 'if (' found in {$file}" );

	$close_pos = strpos( $source, ')', $if_pos );
	return trim( substr( $source, $if_pos + 4, $close_pos - ( $if_pos + 4 ) ) );
}

$ucss_file = dirname( __DIR__ ) . '/includes/class-bepluspb-ucss.php';
$js_file   = dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';

foreach (
	array(
		'BEPLUSPB_UCSS::buffer_end()'        => $ucss_file,
		'BEPLUSPB_JS::advanced_buffer_end()' => $js_file,
	) as $label => $file
) {
	$condition = extract_guard_condition( $file, "error_log(\n" );

	assert_true(
		false === strpos( $condition, "function_exists( 'error_log' )" ),
		"{$label}: guard must not rely solely on function_exists('error_log') — that builtin always exists, so this never actually gates anything and the diagnostic would log unconditionally on production"
	);

	assert_true(
		false !== strpos( $condition, 'WP_DEBUG_LOG' ),
		"{$label}: guard must check WP_DEBUG_LOG so the diagnostic stays silent by default and only logs when the site owner explicitly enabled debug logging"
	);
}

echo "PASS: buffer-mismatch diagnostics are gated by WP_DEBUG_LOG, not by function_exists('error_log')\n";
