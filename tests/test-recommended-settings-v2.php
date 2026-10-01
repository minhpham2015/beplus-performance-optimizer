<?php
/**
 * Standalone Recommended Settings v2 contract test.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-recommendations.php';
function ok( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); } }

$blog = BEPLUSPB_Recommendations::infer_profile( array( 'plugins' => array(), 'post_count' => 20, 'user_count' => 3, 'wp_version' => '6.8' ) );
ok( 'blog_business' === $blog['profile'], 'ordinary site inferred' );
ok( 'balanced' === $blog['plan']['predictive_navigation_mode'], 'ordinary sites use Balanced predictive navigation' );
$woo = BEPLUSPB_Recommendations::infer_profile( array( 'plugins' => array( 'woocommerce/woocommerce.php' ), 'post_count' => 5, 'user_count' => 4, 'wp_version' => '6.8' ) );
ok( 'woocommerce' === $woo['profile'], 'WooCommerce inferred' );
ok( 'safe' === $woo['plan']['predictive_navigation_mode'], 'commerce uses Safe predictive navigation' );
ok( 0 === $woo['plan']['remove_woo_scripts'], 'Woo scripts are never blindly removed' );
$lms = BEPLUSPB_Recommendations::infer_profile( array( 'plugins' => array( 'memberpress/memberpress.php' ), 'post_count' => 2, 'user_count' => 30, 'wp_version' => '6.8' ) );
ok( 'membership_lms' === $lms['profile'], 'membership inferred' );
$high = BEPLUSPB_Recommendations::infer_profile( array( 'plugins' => array(), 'post_count' => 3000, 'user_count' => 600, 'wp_version' => '6.8' ) );
ok( 'high_traffic' === $high['profile'], 'high traffic inferred from local scale signals' );
$old = BEPLUSPB_Recommendations::infer_profile( array( 'plugins' => array(), 'post_count' => 2, 'user_count' => 1, 'wp_version' => '6.7' ) );
ok( 0 === $old['plan']['predictive_navigation_enabled'], 'predictive navigation remains off before WP 6.8' );

$saved = array( 'lazy_load' => 0, 'cloudflare_api_token' => 'secret', 'js_exclude' => 'checkout.js', 'custom_unknown' => 'keep' );
$diff = BEPLUSPB_Recommendations::diff( $saved, $blog['plan'] );
ok( isset( $diff['lazy_load'] ), 'allowlisted setting appears in diff' );
ok( ! isset( $diff['cloudflare_api_token'] ), 'credential excluded from diff' );
$applied = BEPLUSPB_Recommendations::apply_plan( $saved, $blog['plan'] );
ok( 'secret' === $applied['cloudflare_api_token'] && 'checkout.js' === $applied['js_exclude'] && 'keep' === $applied['custom_unknown'], 'credentials, manual fields and unrelated options preserved' );
$disabled = BEPLUSPB_Recommendations::disable_plan( $applied, $blog['plan'] );
ok( 0 === $disabled['lazy_load'] && 'secret' === $disabled['cloudflare_api_token'], 'disable only turns managed features off' );
$snapshot = BEPLUSPB_Recommendations::snapshot( $saved, 'blog_business', 7, 123 );
$restored = BEPLUSPB_Recommendations::restore( $disabled, $snapshot );
ok( 0 === $restored['lazy_load'] && 'secret' === $restored['cloudflare_api_token'], 'restore reinstates exact allowlisted values and preserves secrets' );
ok( ! isset( $snapshot['values']['cloudflare_api_token'] ) && 7 === $snapshot['user_id'], 'audit snapshot has user metadata and no secrets' );
$used_snapshot = $snapshot; $used_snapshot['used'] = true;
ok( array() === BEPLUSPB_Recommendations::restore( array(), $used_snapshot, true ), 'snapshot is single-use' );
ok( 'blog_business' === BEPLUSPB_Recommendations::sanitize_profile( 'bad<script>' ), 'invalid profile safely falls back' );
echo "PASS: Recommended Settings v2 behavior\n";
