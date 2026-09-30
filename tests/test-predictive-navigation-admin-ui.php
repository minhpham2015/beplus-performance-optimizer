<?php
/**
 * Standalone contract tests for the Predictive Navigation admin UI.
 *
 * Run: php -n tests/test-predictive-navigation-admin-ui.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Standalone source contract test intentionally uses native file access and exception messages.

$root   = dirname( __DIR__ );
$admin  = file_get_contents( $root . '/includes/class-bepluspb-admin.php' );
$css    = file_get_contents( $root . '/assets/css/admin.css' );
$script = file_get_contents( $root . '/assets/js/admin.js' );

function assert_contains( $needle, $haystack, $message ) {
	if ( false === strpos( $haystack, $needle ) ) {
		throw new RuntimeException( 'FAIL: ' . $message . "\nMissing: " . $needle );
	}
}

function assert_not_contains( $needle, $haystack, $message ) {
	if ( false !== strpos( $haystack, $needle ) ) {
		throw new RuntimeException( 'FAIL: ' . $message . "\nUnexpected: " . $needle );
	}
}

// Semantic, accessible enable control and explicit progressive state.
assert_contains( 'class="bepluspb-predictive-hero', $admin, 'status and benefit intro exists' );
assert_contains( 'id="bepluspb-predictive-enabled"', $admin, 'master toggle has a stable id' );
assert_contains( 'aria-describedby="bepluspb-predictive-toggle-help bepluspb-predictive-status"', $admin, 'toggle has accessible help and state' );
assert_contains( 'id="bepluspb-predictive-status"', $admin, 'enabled or disabled status is exposed' );
assert_contains( 'data-predictive-controls', $admin, 'dependent controls have a progressive-state target' );
assert_contains( 'aria-disabled="<?php echo $enabled ? \'false\' : \'true\'; ?>"', $admin, 'dependent state is exposed to assistive technology' );

// Mode selection uses native keyboard-accessible radios with preserved values.
assert_contains( '<fieldset class="bepluspb-predictive-mode-fieldset"', $admin, 'mode group is a fieldset' );
assert_contains( '<legend>', $admin, 'mode group has a legend' );
assert_not_contains( '<select id="bepluspb-predictive-mode"', $admin, 'legacy mode select is removed' );
assert_contains( "'safe'     => array(", $admin, 'safe option value is preserved' );
assert_contains( "'balanced' => array(", $admin, 'balanced option value is preserved' );
assert_contains( "'fast'     => array(", $admin, 'fast option value is preserved' );
assert_contains( 'value="<?php echo esc_attr( $value ); ?>"', $admin, 'radio value is escaped from the fixed mode keys' );
assert_contains( 'bepluspb-predictive-badge', $admin, 'Safe mode has a recommended badge' );
assert_contains( 'Speed', $admin, 'mode cards describe speed' );
assert_contains( 'Resources', $admin, 'mode cards describe resources' );

// Exclusions and guidance are separate, concise sections.
assert_contains( 'bepluspb-predictive-exclusions', $admin, 'exclusions have a separate card' );
assert_contains( '<code>/members/*</code>', $admin, 'exclusion help includes an example' );
assert_contains( 'bepluspb-predictive-callouts', $admin, 'compatibility and security callouts exist' );
assert_contains( 'bepluspb-predictive-save-note', $admin, 'save action remains visibly signposted' );

// CSS covers interaction, responsive layout, disabled state, and reduced motion.
assert_contains( '.bepluspb-predictive-mode-input:focus-visible + .bepluspb-predictive-mode-card', $css, 'radio cards have focus-visible styling' );
assert_contains( '.bepluspb-predictive-controls[aria-disabled="true"]', $css, 'disabled controls are visually distinct' );
assert_contains( '@media ( max-width: 782px )', $css, 'mobile breakpoint is present' );
assert_contains( '.bepluspb-predictive-mode-grid', $css, 'mode grid has responsive rules' );
assert_contains( '@media ( prefers-reduced-motion: reduce )', $css, 'reduced motion is respected' );

// Progressive enhancement is external and updates disabled/ARIA state.
assert_contains( "getElementById('bepluspb-predictive-enabled')", $script, 'external admin script owns toggle behavior' );
assert_contains( "setAttribute('aria-disabled'", $script, 'script updates accessible disabled state' );

// No new remote assets or telemetry in the UI implementation.
assert_not_contains( 'fonts.googleapis.com', $css, 'no external font is used' );
assert_not_contains( 'dataLayer', $script, 'no telemetry hook is added' );

echo "PASS: Predictive Navigation admin UI contract\n";
