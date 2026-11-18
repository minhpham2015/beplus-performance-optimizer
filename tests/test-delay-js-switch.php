<?php
/**
 * Standalone test: Delay JS can be switched off per request (?bepluspb-nodelay) or per page (filter), and the
 * page cache never stores/serves a URL carrying the switch.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
$GLOBALS['hooks']   = array();
$GLOBALS['enabled'] = true;
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function apply_filters( $tag, $value ) { return 'bepluspb_delay_js_enabled' === $tag ? $GLOBALS['enabled'] : $value; }
function wp_json_encode( $v ) { return json_encode( $v ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';
require dirname( __DIR__ ) . '/lib/class-bepluspb-page-cache-engine.php';
$n  = 0;
$ok = function ( $c, $m ) use ( &$n ) { ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } };

foreach ( array( 'simple' => 'script_loader_tag', 'advanced' => 'template_redirect' ) as $mode => $hook ) {
	$opts = array( 'js_defer' => 0, 'js_delay' => 1, 'js_delay_mode' => $mode, 'remove_js_handles' => '' );

	$GLOBALS['hooks'] = array(); $_GET = array(); $GLOBALS['enabled'] = true;
	BEPLUSPB_JS::init( $opts );
	$ok( ! empty( $GLOBALS['hooks'][ $hook ] ), "$mode: Delay JS is registered normally" );

	$GLOBALS['hooks'] = array(); $_GET = array( 'bepluspb-nodelay' => '' );
	BEPLUSPB_JS::init( $opts );
	$ok( empty( $GLOBALS['hooks'][ $hook ] ), "$mode: ?bepluspb-nodelay switches it off for that request" );

	$GLOBALS['hooks'] = array(); $_GET = array(); $GLOBALS['enabled'] = false;
	BEPLUSPB_JS::init( $opts );
	$ok( empty( $GLOBALS['hooks'][ $hook ] ), "$mode: the bepluspb_delay_js_enabled filter switches it off per page" );
}
$_GET = array(); $GLOBALS['enabled'] = true;
$GLOBALS['hooks'] = array();
BEPLUSPB_JS::init( array( 'js_defer' => 1, 'js_delay' => 0, 'remove_js_handles' => '' ) );
$ok( ! empty( $GLOBALS['hooks']['script_loader_tag'] ), 'the switch does not touch Defer JS' );

$ok( false === BEPLUSPB_Page_Cache_Engine::query_is_ignorable( 'bepluspb-nodelay' ) && false === BEPLUSPB_Page_Cache_Engine::query_is_ignorable( 'utm_source=a&bepluspb-nodelay=1' ), 'the page cache never stores or serves a URL with ?bepluspb-nodelay' );

echo "OK ($n assertions)\n";
