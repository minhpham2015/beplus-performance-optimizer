<?php
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
/**
 * Dashboard Cache Actions UI contract tests.
 *
 * @package Beplus_Performance_Booster
 */

$root  = dirname( __DIR__ );
$admin = bepluspb_admin_source(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.
$css   = file_get_contents( $root . '/assets/css/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- standalone CLI test harness (php -n, no WordPress loaded); reads this plugin's own local source file, not a remote URL, so wp_remote_get() is not applicable.

$dashboard_start = strpos( $admin, 'id="bepluspb-cache-actions"' );
$dashboard_end   = strpos( $admin, '<!-- Recommended Settings v2 -->', $dashboard_start );
$dashboard       = substr( $admin, $dashboard_start, $dashboard_end - $dashboard_start );

$checks = array(
	'three named sections'       => 3 === substr_count( $dashboard, 'class="bepluspb-cache-action-section' ),
	'accessibile h3 headings'    => strpos( $dashboard, '<h3 id="bepluspb-cache-optimizations-title">' ) !== false
		&& strpos( $dashboard, '<h3 id="bepluspb-generated-cache-title">' ) !== false
		&& strpos( $dashboard, '<h3 id="bepluspb-object-cache-title">' ) !== false,
	'sections labelled'          => substr_count( $dashboard, 'aria-labelledby="bepluspb-' ) >= 3,
	'stats semantically grouped' => strpos( $dashboard, 'class="bepluspb-cache-metrics" aria-label=' ) !== false
		&& strpos( $dashboard, 'class="bepluspb-cache-metric"' ) !== false,
	'empty state scoped to disk' => strpos( $dashboard, 'class="bepluspb-cache-empty" role="status"' ) !== false,
	'refresh beside metrics'     => strpos( $dashboard, 'class="bepluspb-cache-stats-row"' ) !== false
		&& strpos( $dashboard, 'class="bepluspb-refresh-link"' ) !== false,
	'object status semantics'    => strpos( $admin, 'bepluspb-object-cache-status' ) !== false
		&& strpos( $admin, 'role="status"' ) !== false
		&& strpos( $admin, "'Available'" ) !== false
		&& strpos( $admin, "'Unavailable'" ) !== false,
	'object settings link'       => strpos( $admin, 'bepluspb-object-cache-settings-link' ) !== false,
	'desktop action rows'        => strpos( $css, '.bepluspb-cache-action-row' ) !== false
		&& strpos( $css, 'grid-template-columns: minmax( 0, 1fr ) auto;' ) !== false,
	'mobile stacking'            => strpos( $css, '@media ( max-width: 782px )' ) !== false
		&& strpos( $css, '.bepluspb-cache-action-row' ) !== false
		&& strpos( $css, 'min-height: 44px' ) !== false
		&& strpos( $css, 'overflow-wrap: anywhere' ) !== false,
	'native compact danger'      => strpos( $dashboard, 'class="button button-secondary bepluspb-clear-btn"' ) !== false,
	'exact purge label'          => strpos( $dashboard, 'Purge ALL Cache' ) !== false,
	'handlers unchanged'         => strpos( $admin, 'admin_post_bepluspb_purge_all_cache' ) !== false
		&& strpos( $admin, 'admin_post_bepluspb_purge_object_cache' ) !== false
		&& strpos( $dashboard, 'value="bepluspb_purge_all_cache"' ) !== false
		&& strpos( $admin, 'value="bepluspb_purge_object_cache"' ) !== false,
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
echo 'PASS: ' . count( $checks ) . " dashboard cache action UI contracts\n";
