<?php
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
/**
 * Contract tests for Object Cache purge control locations and safety.
 *
 * @package Beplus_Performance_Booster
 */

$root  = dirname( __DIR__ );
$admin = bepluspb_admin_source(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.

$dashboard_start = strpos( $admin, 'function render_section_dashboard' );
$dashboard_end   = strpos( $admin, 'function render_section_cache_files', $dashboard_start );
$dashboard       = substr( $admin, $dashboard_start, $dashboard_end - $dashboard_start );
$object_start    = strpos( $admin, 'function render_section_object_cache' );
$object_end      = strpos( $admin, 'function add_admin_bar_menu', $object_start );
$object_tab      = substr( $admin, $object_start, $object_end - $object_start );
$bar_start       = strpos( $admin, 'function build_adminbar_panel' );
$bar_end         = strpos( $admin, '// Quick-enable action handler', $bar_start );
$admin_bar       = substr( $admin, $bar_start, $bar_end - $bar_start );
$helper_start    = strpos( $admin, 'function render_object_cache_purge_control' );

$checks = array(
	'dashboard anchor'                 => strpos( $dashboard, 'id="bepluspb-cache-actions"' ) !== false,
	'dashboard shared helper'          => substr_count( $dashboard, 'render_object_cache_purge_control' ) === 1,
	'object tab control removed'       => strpos( $object_tab, 'render_object_cache_purge_control' ) === false && strpos( $object_tab, 'bepluspb_object_purge_nonce' ) === false,
	'shared availability contract'     => false !== $helper_start && false !== strpos( substr( $admin, $helper_start, 5000 ), 'BEPLUSPB_Object_Cache::get_purge_availability()' ),
	'POST-only form'                   => false !== strpos( substr( $admin, $helper_start, 5000 ), 'method="post"' ) && false !== strpos( substr( $admin, $helper_start, 5000 ), 'bepluspb_purge_object_cache' ),
	'nonce and confirmation'           => false !== strpos( substr( $admin, $helper_start, 5000 ), 'bepluspb_object_purge_nonce' ) && false !== strpos( substr( $admin, $helper_start, 5000 ), 'bepluspb_confirm_object_purge' ),
	'dashboard omits alarming warning' => strpos( $dashboard, 'Warning: this invokes Redis FLUSHDB or clears the Memcached entire pool and may affect other sites/apps sharing that backend.' ) === false,
	'dashboard disabled reason'        => strpos( substr( $admin, $helper_start, 5000 ), '$purge[\'reason\']' ) !== false,
	'admin-bar shared helper'          => strpos( $admin_bar, 'render_object_cache_purge_control' ) !== false,
	'admin-bar no destructive GET'     => strpos( $admin_bar, 'action=bepluspb_purge_object_cache' ) === false,
	'admin-bar unavailable hidden'     => false !== strpos( substr( $admin, $helper_start, 5000 ), "'admin-bar' === \$context && ! \$available" ) && false !== strpos( substr( $admin, $helper_start, 5000 ), "return '';" ),
	'master-off independent'           => strpos( substr( $admin, $helper_start, 5000 ), 'cache_enabled' ) === false,
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
	exit( 1 );
}
echo 'PASS: ' . count( $checks ) . " object-cache purge location contracts\n";
