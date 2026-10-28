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
t_ok( false !== strpos( $bar, 'class="bepluspb-ab-purge-form"' ) && false !== strpos( $bar, 'method="post"' ), 'admin bar purge is a POST form' );
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

echo "PASS: $n admin bar purge form / deep-link assertions\n";
