<?php
/**
 * Object Cache "Review Object Cache settings" link tab-switch wiring.
 *
 * RED-first: asserts the shared click-handler's closest() selector in
 * assets/js/admin.js covers BOTH `a.bepluspb-rec-link` and
 * `a.bepluspb-object-cache-settings-link` inside the SAME handler (no
 * duplicated handler), so the link actually switches tabs instead of
 * falling through to native anchor navigation.
 *
 * @package Beplus_Performance_Booster
 */

require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore

// phpcs:disable
$js = file_get_contents( dirname( __DIR__ ) . '/assets/js/admin.js' );

$checks = array(
	'admin.js exists'                       => false !== $js,
	// Exactly one addEventListener('click', ...) block drives tab-link
	// deep-linking (not a second duplicated handler for the new class).
	'single click handler for rec links'    => 1 === substr_count( $js, "document.addEventListener('click'" ),
	// That handler's closest() selector matches BOTH classes together.
	'closest() covers both link classes'    => (bool) preg_match(
		"/closest\\(\\s*'a\\.bepluspb-rec-link,\\s*a\\.bepluspb-object-cache-settings-link'\\s*\\)/",
		$js
	),
	// The admin PHP still emits the link with this exact class so the
	// selector above has something to match at runtime.
	'admin.php emits the settings link class' => (function () {
		$admin = bepluspb_admin_source();
		return false !== strpos( $admin, 'bepluspb-object-cache-settings-link' )
			&& false !== strpos( $admin, '#bepluspb-tab-object_cache' );
	} )(),
);

$failed = array_keys( array_filter( $checks, fn( $v ) => ! $v ) );
if ( $failed ) {
	fwrite( STDERR, 'FAIL: ' . implode( ', ', $failed ) . PHP_EOL );
	exit( 1 );
}
echo 'PASS: ' . count( $checks ) . " assertions\n";
