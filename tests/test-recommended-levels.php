<?php
/**
 * Recommended Settings levels (Safe / Balanced / Strong) on top of the site profiles.
 * Run: php -n tests/test-recommended-levels.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function __( $t, $d = '' ) { return $t; }
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-recommendations.php';
require_once __DIR__ . '/helpers/admin-source.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$R = 'BEPLUSPB_Recommendations';

// Identifiers.
t_ok( array( 'safe', 'balanced', 'strong' ) === $R::levels(), 'three levels' );
t_ok( 'balanced' === $R::sanitize_level( '' ) && 'balanced' === $R::sanitize_level( 'bad<script>' ) && 'balanced' === $R::sanitize_level( null ) && 'balanced' === $R::sanitize_level( array( 'strong' ) ) && 'strong' === $R::sanitize_level( 'strong' ), 'unknown levels fall back to balanced' );
t_ok( array_keys( $R::level_labels() ) === $R::levels(), 'every level has a label and a description' );

// Balanced is exactly the old plan: nothing changed for existing callers.
foreach ( $R::profiles() as $profile ) {
	$plans = $R::plans( '6.8' );
	t_ok( $plans[ $profile ] === $R::plan_for( $profile, '6.8', array() ), "$profile: default level is the unchanged plan" );
	t_ok( $plans[ $profile ] === $R::plan_for( $profile, '6.8', array(), 'balanced' ), "$profile: balanced equals the profile plan" );
}

// Safe: never more aggressive than balanced, and the risky features are off.
foreach ( $R::profiles() as $profile ) {
	$safe = $R::plan_for( $profile, '6.8', array(), 'safe' );
	t_ok( 0 === $safe['minify_js_files'] && 0 === $safe['js_defer'] && 0 === $safe['html_minify'] && 'safe' === $safe['predictive_navigation_mode'], "$profile safe: no JS minify/defer, no HTML minify, safe predictive mode" );
	t_ok( 0 === $safe['js_delay'] && 0 === $safe['css_remove_unused'] && 0 === $safe['css_inline_all'], "$profile safe: delay/unused CSS/inline CSS off" );
	t_ok( ! isset( $safe['css_non_blocking'] ) || 0 === $safe['css_non_blocking'], "$profile safe: CSS stays render-blocking" );
	t_ok( 1 === $safe['cache_enabled'] && 1 === $safe['lazy_load'] && 1 === $safe['css_minify'] && 1 === $safe['minify_css_files'], "$profile safe: the low-risk optimisations stay on" );
}

// Strong: adds non-blocking CSS and Delay JS, except Delay JS on shops/membership sites.
$strong = $R::plan_for( 'blog_business', '6.8', array(), 'strong' );
t_ok( 1 === $strong['css_non_blocking'] && 1 === $strong['js_delay'], 'blog strong: non-blocking CSS and Delay JS' );
t_ok( 1 === $strong['minify_js_files'] && 1 === $strong['js_defer'], 'blog strong keeps everything balanced has' );
t_ok( 1 === $R::plan_for( 'high_traffic', '6.8', array(), 'strong' )['js_delay'], 'high-traffic strong: Delay JS' );
foreach ( array( 'woocommerce', 'membership_lms' ) as $profile ) {
	$p = $R::plan_for( $profile, '6.8', array(), 'strong' );
	t_ok( 1 === $p['css_non_blocking'] && 0 === $p['js_delay'] && 0 === $p['js_defer'] && 0 === $p['minify_js_files'], "$profile strong: no Delay/Defer/JS minify" );
}
t_ok( 0 === $R::plan_for( 'blog_business', '6.8', array(), 'strong' )['css_remove_unused'], 'Remove Unused CSS is never switched on by a level' );

// Builder overrides still win over a level (Elementor keeps CSS blocking and Delay JS off).
$elementor = $R::plan_for( 'blog_business', '6.8', array( 'elementor' ), 'strong' );
t_ok( 0 === $elementor['css_non_blocking'] && 0 === $elementor['js_delay'] && 0 === $elementor['css_remove_unused'] && 0 === $elementor['css_inline_all'], 'Elementor overrides beat the Strong level' );

// Old WordPress: no Predictive Navigation at any level.
foreach ( $R::levels() as $level ) {
	t_ok( 0 === $R::plan_for( 'blog_business', '6.7', array(), $level )['predictive_navigation_enabled'], "$level: Predictive Navigation stays off before WP 6.8" );
}

// Only allowlisted keys can appear in a plan, so credentials and manual fields are never touched.
foreach ( $R::levels() as $level ) {
	foreach ( $R::profiles() as $profile ) {
		t_ok( array() === array_diff( array_keys( $R::plan_for( $profile, '6.8', array( 'elementor' ), $level ) ), $R::allowlist() ), "$profile/$level: plan keys are all allowlisted" );
	}
}
$saved = array( 'cloudflare_api_token' => 'secret', 'js_exclude' => 'checkout.js', 'js_delay' => 0 );
$next  = $R::apply_plan( $saved, $R::plan_for( 'blog_business', '6.8', array(), 'strong' ) );
t_ok( 'secret' === $next['cloudflare_api_token'] && 'checkout.js' === $next['js_exclude'] && 1 === $next['js_delay'], 'apply keeps secrets and manual fields' );

// The preview diff reflects the level.
$d_safe   = $R::diff( array(), $R::plan_for( 'blog_business', '6.8', array(), 'safe' ) );
$d_strong = $R::diff( array(), $R::plan_for( 'blog_business', '6.8', array(), 'strong' ) );
t_ok( 1 === $d_strong['js_delay']['to'] && 0 === $d_safe['js_delay']['to'], 'diff differs by level' );

// Undo: the snapshot remembers the level and restores the exact previous values.
$before = array( 'js_delay' => 0, 'css_non_blocking' => 0, 'lazy_load' => 0, 'cloudflare_api_token' => 'secret' );
$snap   = $R::snapshot( $before, 'blog_business', 5, 100, 'strong' );
t_ok( 'strong' === $snap['level'] && 'balanced' === $R::snapshot( $before, 'blog_business', 5, 100 )['level'] && 'balanced' === $R::snapshot( $before, 'blog_business', 5, 100, 'x' )['level'], 'snapshot stores a valid level' );
$applied  = $R::apply_plan( $before, $R::plan_for( 'blog_business', '6.8', array(), 'strong' ) );
$restored = $R::restore( $applied, $snap );
t_ok( 0 === $restored['js_delay'] && 0 === $restored['css_non_blocking'] && 0 === $restored['lazy_load'] && 'secret' === $restored['cloudflare_api_token'], 'restore brings back the values from before the Strong plan' );
t_ok( array() === $R::restore( array(), array_merge( $snap, array( 'used' => true ) ), true ), 'a snapshot can be restored once' );

// Admin wiring: the level is read from the request, sanitised, and shared by preview and apply.
$src = bepluspb_admin_source();
foreach ( array( 'sanitize_level( isset( $_POST[\'level\'] )', "plan_for( \$profile, get_bloginfo( 'version' ), BEPLUSPB_Recommendations::detect_builders( BEPLUSPB_Recommendations::local_signals() ), \$level )", 'snapshot( $saved, $profile, get_current_user_id(), time(), $level )', 'name="bepluspb_level"', 'name="level"', "plan_for( \$profile, get_bloginfo( 'version' ), \$detected['builders'], \$level )" ) as $needle ) {
	t_ok( false !== strpos( $src, $needle ), "admin source contains: $needle" );
}

echo "OK ($n assertions)\n";
