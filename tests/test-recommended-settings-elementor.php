<?php
/**
 * Recommended Settings: Elementor override layer.
 * Run: php -n tests/test-recommended-settings-elementor.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ );
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-recommendations.php';
function ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

$sig = static fn( $plugins, $posts = 10 ) => array( 'plugins' => $plugins, 'post_count' => $posts, 'user_count' => 3, 'wp_version' => '6.8' );

// Detection is exact: the plugin's own main file, not any path that mentions elementor.
ok( array() === BEPLUSPB_Recommendations::detect_builders( $sig( array() ) ), 'no builder on a plain site' );
ok( array( 'elementor' ) === BEPLUSPB_Recommendations::detect_builders( $sig( array( 'elementor/elementor.php' ) ) ), 'Elementor detected' );
ok( array() === BEPLUSPB_Recommendations::detect_builders( $sig( array( 'some-elementor-helper/helper.php', 'elementor-pro/elementor-pro.php' ) ) ), 'look-alike paths and Pro alone do not count' );

// Layered on every profile, never replacing it.
$el  = BEPLUSPB_Recommendations::infer_profile( $sig( array( 'elementor/elementor.php' ) ) );
ok( 'blog_business' === $el['profile'] && array( 'elementor' ) === $el['builders'], 'blog profile kept, builder reported' );
ok( 0 === $el['plan']['css_non_blocking'] && 0 === $el['plan']['css_remove_unused'] && 0 === $el['plan']['css_inline_all'] && 0 === $el['plan']['js_delay'], 'Elementor overrides applied' );
ok( 1 === $el['plan']['js_defer'] && 1 === $el['plan']['lazy_load'] && 1 === $el['plan']['html_minify'] && 1 === $el['plan']['cache_headers'], 'unrelated recommendations untouched' );
ok( false !== strpos( implode( ' ', $el['reasons'] ), 'Elementor is active' ), 'reason shown to the user' );
$shop = BEPLUSPB_Recommendations::infer_profile( $sig( array( 'woocommerce/woocommerce.php', 'elementor/elementor.php' ) ) );
ok( 'woocommerce' === $shop['profile'] && 'safe' === $shop['plan']['predictive_navigation_mode'] && 0 === $shop['plan']['js_defer'], 'Elementor shop keeps the WooCommerce plan' );
ok( 0 === $shop['plan']['css_non_blocking'], '... plus the Elementor overrides' );

// Non-Elementor sites do not manage css_non_blocking at all.
$plain = BEPLUSPB_Recommendations::infer_profile( $sig( array() ) );
ok( ! array_key_exists( 'css_non_blocking', $plain['plan'] ), 'plain plan does not touch css_non_blocking' );
ok( ! isset( BEPLUSPB_Recommendations::diff( array(), $plain['plan'] )['css_non_blocking'] ), 'and does not list it in the diff' );

// The override never switches anything ON.
foreach ( BEPLUSPB_Recommendations::builder_overlays() as $overlay ) {
	foreach ( $overlay['values'] as $key => $value ) {
		ok( 0 === $value, "override $key only turns features off" );
		ok( in_array( $key, BEPLUSPB_Recommendations::allowlist(), true ), "override $key is an allowlisted (restorable) setting" );
	}
}

// Diff / apply / restore with a site that had the risky features on.
$saved = array( 'css_non_blocking' => 1, 'css_remove_unused' => 1, 'cloudflare_api_token' => 'secret' );
$diff  = BEPLUSPB_Recommendations::diff( $saved, $el['plan'] );
ok( 1 === $diff['css_non_blocking']['from'] && 0 === $diff['css_non_blocking']['to'], 'diff shows non-blocking CSS being turned off' );
$applied = BEPLUSPB_Recommendations::apply_plan( $saved, $el['plan'] );
ok( 0 === $applied['css_non_blocking'] && 0 === $applied['css_remove_unused'] && 'secret' === $applied['cloudflare_api_token'], 'apply turns them off, keeps secrets' );
$snapshot = BEPLUSPB_Recommendations::snapshot( $saved, 'blog_business', 1, 1 );
$restored = BEPLUSPB_Recommendations::restore( $applied, $snapshot );
ok( 1 === $restored['css_non_blocking'] && 1 === $restored['css_remove_unused'], 'restore brings the previous values back' );

// plan_for() is the single source for preview and apply.
ok( BEPLUSPB_Recommendations::plan_for( 'woocommerce', '6.8', array( 'elementor' ) ) === $shop['plan'], 'plan_for matches the inferred plan' );
ok( BEPLUSPB_Recommendations::plan_for( 'bad<profile>', '6.8', array() ) === BEPLUSPB_Recommendations::plan_for( 'blog_business', '6.8', array() ), 'invalid profile falls back safely' );
ok( BEPLUSPB_Recommendations::plan_for( 'blog_business', '6.8', array( 'unknown-builder' ) ) === BEPLUSPB_Recommendations::plan_for( 'blog_business', '6.8', array() ), 'unknown builder ids are ignored' );

echo "PASS: Recommended Settings Elementor overrides\n";
