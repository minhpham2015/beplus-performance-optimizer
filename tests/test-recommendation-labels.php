<?php
/**
 * Standalone test: the Recommended Settings preview names options as the settings screens do, never by raw key.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
function __( $t, $d = '' ) { return $t; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-recommendations.php';
$n  = 0;
$ok = function ( $c, $m ) use ( &$n ) { ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } };

$labels = BEPLUSPB_Recommendations::labels();
foreach ( BEPLUSPB_Recommendations::allowlist() as $key ) {
	$ok( isset( $labels[ $key ] ) && '' !== $labels[ $key ] && $labels[ $key ] !== $key && false === strpos( $labels[ $key ], '_' ), "managed option has a human label: $key" );
}
foreach ( BEPLUSPB_Recommendations::builder_overlays() as $overlay ) {
	foreach ( array_keys( $overlay['values'] ) as $key ) {
		$ok( isset( $labels[ $key ] ), "builder-overlay option has a label: $key" );
	}
}
$ok( 'Minify JS Files' === BEPLUSPB_Recommendations::label( 'minify_js_files' ) && 'Delay JS Execution' === BEPLUSPB_Recommendations::label( 'js_delay' ), 'labels match the settings screens' );
$ok( 'unknown_key' === BEPLUSPB_Recommendations::label( 'unknown_key' ), 'an unknown key falls back to itself' );
$ok( 'Balanced' === BEPLUSPB_Recommendations::value_label( 'predictive_navigation_mode', 'balanced' ) && null === BEPLUSPB_Recommendations::value_label( 'js_delay', 1 ) && null === BEPLUSPB_Recommendations::value_label( 'predictive_navigation_mode', 'x' ), 'predictive modes get their screen names, other values none' );
$src = file_get_contents( dirname( __DIR__ ) . '/includes/admin/trait-bepluspb-admin-tabs-basic.php' );
$ok( false === strpos( $src, '<code><?php echo esc_html( $key ); ?></code>' ) && false !== strpos( $src, 'BEPLUSPB_Recommendations::label( $key )' ), 'the preview table prints the label, not the key' );
$status = file_get_contents( dirname( __DIR__ ) . '/includes/admin/trait-bepluspb-admin-tabs-status.php' );
$ok( false === strpos( $status, "'JS Delay rdelay'" ) && false !== strpos( $status, "BEPLUSPB_Recommendations::label( 'js_delay' )" ) && false !== strpos( $status, "BEPLUSPB_Recommendations::label( 'cache_enabled' )" ), 'the Status tab uses the same option names as the settings screens' );

echo "OK ($n assertions)\n";
