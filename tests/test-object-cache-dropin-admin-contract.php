<?php
/**
 * Admin contract for guarded drop-in workflow.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$s = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php' );
$c = array(
	'actions registered'       => false !== strpos( $s, 'wp_ajax_bepluspb_preflight_oc_replace' ) && false !== strpos( $s, 'wp_ajax_bepluspb_backup_replace_oc' ) && false !== strpos( $s, 'wp_ajax_bepluspb_restore_oc' ),
	'distinct nonces'          => false !== strpos( $s, "bepluspb_preflight_oc_replace' )" ) && false !== strpos( $s, "bepluspb_backup_replace_oc' )" ) && false !== strpos( $s, "bepluspb_restore_oc' )" ),
	'capability'               => substr_count( $s, "current_user_can( 'manage_options' )" ) >= 3,
	'labels'                   => false !== strpos( $s, 'Back up and replace…' ) && false !== strpos( $s, 'Restore previous drop-in…' ),
	'acknowledgement'          => false !== strpos( $s, 'replace-ack' ) && false !== strpos( $s, 'restore-ack' ) && false !== strpos( $s, "empty( \$_POST['acknowledge'] )" ),
	'preflight before replace' => false !== strpos( $s, "dropinAction('bepluspb_preflight_oc_replace'" ) && false !== strpos( $s, "dropinAction('bepluspb_backup_replace_oc'" ),
	'restore action wired'     => false !== strpos( $s, "dropinAction('bepluspb_restore_oc'" ),
	'config/install transaction' => false !== strpos( $s, 'BEPLUSPB_Object_Cache::install_with_config( bepluspb_get_options() )' ),
);

// ---------------------------------------------------------------------
// Precise onSuccess-wiring simulation (HIGH finding 1 regression guard).
//
// The above substring/count checks would NOT have caught "onSuccess is
// accepted but never invoked" — dropinAction('bepluspb_preflight_oc_replace', ...)
// being present as a call site says nothing about whether the success
// callback inside dropinAction() actually fires. Instead of asserting on
// raw text, this actually EXECUTES dropinAction() (extracted verbatim
// from the admin file, scoped between its own 'function dropinAction('
// and the very next 'var dropinResult' marker) inside a minimal Node-like
// DOM/fetch shim and observes whether onSuccess is invoked when the
// simulated server responds success:true, and NOT invoked on failure.
// ---------------------------------------------------------------------
$click_flow_ok    = false;
$click_flow_error = '';
$node             = trim( shell_exec( 'command -v node 2>/dev/null' ) );
if ( $node ) {
	$start = strpos( $s, 'function dropinAction(' );
	$end   = false !== $start ? strpos( $s, 'var dropinResult', $start ) : false;
	if ( false === $start || false === $end ) {
		$click_flow_error = 'could not locate dropinAction() function body in admin.php';
	} else {
		$fn_src = substr( $s, $start, $end - $start );
		$harness = <<<JS
var ajaxUrl = 'http://example.test/wp-admin/admin-ajax.php';

{$fn_src}

var calls = [];
function FormData(){ this.append = function(){}; }
global.FormData = FormData;

function fakeFetch(success) {
	return function(url, opts) {
		return Promise.resolve({
			json: function () { return Promise.resolve({ success: success, data: { message: success ? 'ok' : 'bad' } }); }
		});
	};
}

function run(success) {
	return new Promise(function (resolve) {
		global.fetch = fakeFetch(success);
		var resultEl = { style: {}, textContent: '' };
		var onSuccessCalled = false;
		dropinAction('action', 'nonce', 'label', resultEl, function () { onSuccessCalled = true; }, true);
		setTimeout(function () { resolve(onSuccessCalled); }, 20);
	});
}

(async function () {
	var calledOnSuccess = await run(true);
	var calledOnFailure = await run(false);
	console.log(JSON.stringify({ calledOnSuccess: calledOnSuccess, calledOnFailure: calledOnFailure }));
})();
JS;
		$tmp = tempnam( sys_get_temp_dir(), 'bepluspb-clickflow-' ) . '.js';
		file_put_contents( $tmp, $harness );
		$out = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
		unlink( $tmp );
		$decoded = json_decode( trim( (string) $out ), true );
		if ( ! is_array( $decoded ) ) {
			$click_flow_error = 'harness did not return JSON: ' . $out;
		} elseif ( empty( $decoded['calledOnSuccess'] ) ) {
			$click_flow_error = 'onSuccess was NOT invoked on a successful AJAX response (this is exactly HIGH finding 1)';
		} elseif ( ! empty( $decoded['calledOnFailure'] ) ) {
			$click_flow_error = 'onSuccess was invoked even on a FAILED AJAX response (should only fire on success)';
		} else {
			$click_flow_ok = true;
		}
	}
} else {
	// No node available: fall back to the scoped-string check (still far
	// more precise than a file-wide substring check) rather than skipping
	// silently.
	$start = strpos( $s, 'function dropinAction(' );
	$end   = false !== $start ? strpos( $s, 'var dropinResult', $start ) : false;
	$scope = ( false !== $start && false !== $end ) ? substr( $s, $start, $end - $start ) : '';
	$click_flow_ok = false !== strpos( $scope, 'onSuccess(res)' ) && false !== strpos( $scope, 'res.success && onSuccess' );
	if ( ! $click_flow_ok ) {
		$click_flow_error = 'onSuccess(res) not conditionally invoked inside dropinAction() scope (node unavailable, used scoped-string fallback)';
	}
}
$c['onSuccess actually invoked on success (click-flow simulation)'] = $click_flow_ok;

$f = array_keys( array_filter( $c, fn( $v )=> ! $v ) );
if ( $f ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $f ) . ( $click_flow_error ? " ($click_flow_error)" : '' ) . PHP_EOL );
	exit( 1 );
}echo 'PASS: ' . count( $c ) . " assertions\n";
