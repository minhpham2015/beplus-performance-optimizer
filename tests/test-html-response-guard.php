<?php
/**
 * HTML buffer must only open for HTML documents (not feeds/robots/XML/sitemaps).
 * Run: php -n tests/test-html-response-guard.php
 */
// phpcs:disable
define( 'ABSPATH', __DIR__ );
$GLOBALS['q'] = array(); $GLOBALS['xml'] = false;
function is_feed() { return ! empty( $GLOBALS['q']['feed'] ); }
function is_robots() { return ! empty( $GLOBALS['q']['robots'] ); }
function is_trackback() { return false; }
function wp_is_xml_request() { return $GLOBALS['xml']; }
function get_query_var( $k ) { return isset( $GLOBALS['q'][ $k ] ) ? $GLOBALS['q'][ $k ] : ''; }
function wp_is_json_request() { return false; }
function add_action() {}
require dirname( __DIR__ ) . '/includes/class-bepluspb-html.php';

function run_case( $label, $q, $xml, $expect_buffer ) {
	$GLOBALS['q'] = $q; $GLOBALS['xml'] = $xml;
	$before = ob_get_level();
	BEPLUSPB_HTML::buffer_start();
	$buffered = ob_get_level() > $before;
	while ( ob_get_level() > $before ) { ob_end_clean(); }
	if ( $buffered !== $expect_buffer ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
run_case( 'normal page buffered', array(), false, true );
run_case( 'feed skipped', array( 'feed' => 1 ), false, false );
run_case( 'robots skipped', array( 'robots' => 1 ), false, false );
run_case( 'xml request skipped', array(), true, false );
run_case( 'sitemap skipped', array( 'sitemap' => 'posts' ), false, false );
run_case( 'sitemap stylesheet skipped', array( 'sitemap-stylesheet' => 'sitemap' ), false, false );
echo "PASS: HTML buffer guard (6 assertions)\n";
