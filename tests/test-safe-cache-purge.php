<?php
/**
 * Standalone contract tests for safe cache purge controls.
 *
 * @package Beplus_Performance_Booster
 */

$root = dirname( __DIR__ );
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
$admin  = bepluspb_admin_source(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
$minify = file_get_contents( $root . '/includes/class-bepluspb-minify.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
$object = file_get_contents( $root . '/includes/class-bepluspb-object-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
$js     = file_get_contents( $root . '/assets/js/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
$checks = array(
	'new all POST action'               => strpos( $admin, 'admin_post_bepluspb_purge_all_cache' ) !== false,
	'new object POST action'            => strpos( $admin, 'admin_post_bepluspb_purge_object_cache' ) !== false,
	'legacy destructive action absent'  => strpos( $admin, 'admin_post_bepluspb_clear_cache' ) === false,
	'POST method enforced'              => strpos( $admin, "'POST' !== ( isset( \$_SERVER['REQUEST_METHOD'] )" ) !== false,
	'Purge ALL label'                   => strpos( $admin, 'Purge ALL Cache' ) !== false,
	'truthful exclusions'               => strpos( $admin, 'Does not purge persistent Object Cache, WordPress transients, or third-party/server page caches.' ) !== false,
	'admin bar uses safe POST control'  => strpos( $admin, 'bepluspb-ab-purge-form' ) !== false && strpos( substr( $admin, strpos( $admin, 'function build_adminbar_panel' ), strpos( $admin, '// Quick-enable action handler' ) - strpos( $admin, 'function build_adminbar_panel' ) ), 'render_object_cache_purge_control' ) !== false,
	'disk structured result'            => strpos( $minify, "'matched'" ) !== false && strpos( $minify, "'failed'" ) !== false,
	'integration hook'                  => strpos( $admin, "do_action( 'bepluspb_after_local_cache_purge'" ) !== false,
	'object excluded from all'          => strpos( substr( $admin, strpos( $admin, 'function handle_purge_all_cache' ), strpos( $admin, 'function handle_purge_object_cache' ) - strpos( $admin, 'function handle_purge_all_cache' ) ), 'wp_cache_flush' ) === false,
	'object safety API'                 => strpos( $object, 'get_purge_availability' ) !== false,
	'explicit destructive confirmation' => strpos( $admin, 'Purge the persistent Object Cache? This can affect other sites or applications sharing its backend.' ) !== false,
	'object explicit confirmation'      => strpos( $admin, 'bepluspb_confirm_object_purge' ) !== false,
	'double submit JS'                  => strpos( $js, 'bepluspb-purge-form' ) !== false,
	'no global transient purge'         => strpos( $admin, 'delete_all_transients' ) === false && strpos( $admin, 'transient delete --all' ) === false,
);
$failed = array_keys(
	array_filter(
		$checks,
		static function ( $ok ) {
			return ! $ok;
		}
	)
);
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- standalone CLI test reports assertion failures to STDERR; WP_Filesystem is unavailable under php -n.
	exit( 1 ); }
echo 'PASS: ' . count( $checks ) . " safe cache purge contracts\n";
