<?php
/**
 * Standalone tests: scripts needed by an inline 'after' block (and their
 * dependencies) are neither delayed (Simple mode) nor deferred, and the
 * delay.js loader holds form submits safely.
 *
 * Run: php -n tests/test-delay-js-inline-after-deps.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );

function add_action() {} function add_filter() {}
function bepluspb_get_options() { return array( 'js_exclude' => '', 'js_delay_rdelay' => 0 ); }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
function esc_attr( $s ) { return $s; } function esc_url( $s ) { return $s; }

class WPS_Dep { public $deps; function __construct( $deps ) { $this->deps = $deps; } }
class WPS_Stub {
	public $registered = array();
	public $after      = array();
	function get_data( $h, $k ) { return 'after' === $k && isset( $this->after[ $h ] ) ? $this->after[ $h ] : false; }
}
$GLOBALS['wps'] = new WPS_Stub();
function wp_scripts() { return $GLOBALS['wps']; }
function t_ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

// widget (inline after) -> lib -> base; loop-a <-> loop-b is a dependency cycle;
// "plain" and "late" have no inline code when the list is first built.
$GLOBALS['wps']->registered = array(
	'widget' => new WPS_Dep( array( 'lib', 'missing-handle' ) ),
	'lib'    => new WPS_Dep( array( 'base' ) ),
	'base'   => new WPS_Dep( array() ),
	'loop-a' => new WPS_Dep( array( 'loop-b' ) ),
	'loop-b' => new WPS_Dep( array( 'loop-a' ) ),
	'plain'  => new WPS_Dep( array() ),
	'late'   => new WPS_Dep( array() ),
);
$GLOBALS['wps']->after = array( 'widget' => array( 'init();' ), 'loop-a' => array( 'x();' ) );

require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';

$tag = '<script src="http://example.test/a.js"></script>';
$src = 'http://example.test/a.js';

foreach ( array( 'widget', 'lib', 'base', 'loop-a', 'loop-b' ) as $h ) {
	t_ok( $tag === BEPLUSPB_JS::delay_scripts( $tag, $h, $src ), "$h must not be delayed" );
	t_ok( $tag === BEPLUSPB_JS::defer_scripts( $tag, $h, $src ), "$h must not be deferred" );
}
t_ok( false !== strpos( BEPLUSPB_JS::delay_scripts( $tag, 'plain', $src ), 'data-bepluspb-delay="1"' ), 'unrelated script is still delayed' );
t_ok( false !== strpos( BEPLUSPB_JS::defer_scripts( $tag, 'plain', $src ), ' defer src=' ), 'unrelated script is still deferred' );

// An 'after' block added after the list was built (shortcode, footer script).
$GLOBALS['wps']->after['late'] = array( 'late();' );
t_ok( $tag === BEPLUSPB_JS::delay_scripts( $tag, 'late', $src ), 'late inline "after" handle must not be delayed' );
t_ok( $tag === BEPLUSPB_JS::defer_scripts( $tag, 'late', $src ), 'late inline "after" handle must not be deferred' );

// delay.js loader contract.
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/delay.js' );
t_ok( false !== strpos( $js, 'HTMLFormElement.prototype.submit.call' ), 'native submit must not rely on form.submit (a field named "submit" shadows it)' );
t_ok( false === strpos( $js, 'item.form.submit()' ) && false === strpos( $js, 'item.form.requestSubmit(' ), 'no shadowable form method calls' );
t_ok( false !== strpos( $js, '_bepluspbPendingForms[ i ].form === event.target' ), 'a held form is queued once' );
t_ok( 1 === preg_match( '/setTimeout\(\s*_bepluspbFlushForms,\s*\d+\s*\)/', $js ), 'held forms are released even when a script stalls' );

echo "PASS: Delay JS inline-after dependency assertions\n";
