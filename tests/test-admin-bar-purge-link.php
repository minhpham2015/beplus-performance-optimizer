<?php
/**
 * The admin bar "Purge ALL Cache" entry is a link to #bepluspb-cache-actions. admin.js must open the tab panel
 * that contains that anchor (instead of the last-used tab restored from sessionStorage) and scroll to it.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
require_once __DIR__ . '/helpers/admin-source.php';
$admin = bepluspb_admin_source();
$js    = file_get_contents( $root . '/assets/js/admin.js' );
$n     = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

$bar = file_get_contents( $root . '/includes/admin/trait-bepluspb-admin-admin-bar.php' );
t_ok( false !== strpos( $bar, 'class="bepluspb-ab-purge-form bepluspb-purge-form"' ) && false !== strpos( $bar, 'method="post"' ), 'admin bar purge is a POST form' );
t_ok( false !== strpos( $bar, "wp_nonce_field( 'bepluspb_purge_all_cache', 'bepluspb_purge_nonce'" ) && false !== strpos( $bar, 'name="action" value="bepluspb_purge_all_cache"' ), 'it carries the purge nonce and action' );
t_ok( substr_count( $bar, 'return_url_field()' ) >= 3, 'both admin bar forms send the current page back' );
$anchor = 'bepluspb-cache-actions';
t_ok( 1 === substr_count( $admin, 'id="' . $anchor . '"' ), 'the dashboard anchor still exists exactly once (deep links)' );
t_ok( false !== strpos( $js, "closest('[id^=\"bepluspb-tab-\"]')" ), 'admin.js finds the tab panel that contains a deep-linked anchor' );
$p_anchor = strpos( $js, 'else if (anchorPanelId)' );
$p_saved  = strpos( $js, 'else if (saved &&' );
t_ok( false !== $p_anchor && false !== $p_saved && $p_anchor < $p_saved, 'an anchor deep link wins over the sessionStorage tab' );
t_ok( false !== strpos( $js, 'anchorTarget.scrollIntoView()' ), 'the anchor is scrolled into view' );
t_ok( false !== strpos( $js, '/^#bepluspb-[a-z0-9_-]+$/i' ), 'only plugin-prefixed hashes are looked up' );

$bar_form = substr( $bar, strpos( $bar, 'class="bepluspb-ab-purge-form' ), 400 );
t_ok( false !== strpos( $bar_form, 'bepluspb-purge-form' ) && false !== strpos( $bar_form, 'data-confirm=' ), 'the admin bar purge-all form asks for confirmation' );
$cls = file_get_contents( $root . '/includes/class-bepluspb-admin.php' );
t_ok( false !== strpos( $cls, "assets/js/adminbar.js" ) && 2 === substr_count( $cls, 'self::enqueue_adminbar_script()' ), 'adminbar.js is loaded on the front end and on admin screens (not twice on the settings page)' );
t_ok( false !== strpos( $cls, "'settings_page_beplus-performance-booster' !== \$hook_suffix ) {\n\t\t\tself::enqueue_adminbar_script()" ), 'the settings page leaves it to admin.js' );
$ab_js = file_get_contents( $root . '/assets/js/adminbar.js' );
t_ok( false !== strpos( $ab_js, 'window.confirm(message)' ) && false !== strpos( $ab_js, 'preventDefault' ), 'adminbar.js cancels the submit when the dialog is dismissed' );

$css = file_get_contents( $root . '/assets/css/admin.css' );
$rule = substr( $css, strpos( $css, '#wpadminbar .bepluspb-ab-purge-form button {' ), 700 );
t_ok( false !== strpos( $rule, 'background: transparent !important' ) && false !== strpos( $rule, 'border: 0 !important' ) && false !== strpos( $rule, 'color: #f0f0f1 !important' ), 'the admin bar purge button is styled as a plain link (beats #wpadminbar button defaults)' );

echo "PASS: $n admin bar purge form / deep-link assertions\n";
