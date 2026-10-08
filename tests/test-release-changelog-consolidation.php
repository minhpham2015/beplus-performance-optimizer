<?php
/**
 * Ensure the WordPress.org jump from 1.0.10 to 1.1.13 has one consolidated log (1.1.12 plus the 1.1.13 release on top).
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$root      = dirname( __DIR__ );
$readme    = file_get_contents( $root . '/readme.txt' );
$changelog = file_get_contents( $root . '/CHANGELOG.md' );
// Headings are counted inside the Changelog section only (Upgrade Notice legitimately repeats version headings).
$readme_log = substr( $readme, (int) strpos( $readme, '== Changelog ==' ), strpos( $readme, '== Upgrade Notice ==' ) - (int) strpos( $readme, '== Changelog ==' ) );

/**
 * Assert a changelog condition.
 *
 * @param bool   $condition Condition to assert.
 * @param string $message   Failure description.
 * @return void
 * @throws RuntimeException When the changelog contract is violated.
 */
function bepluspb_changelog_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only regression test output.
	}
}

bepluspb_changelog_assert( 1 === preg_match_all( '/^= 1\.1\.13 =$/m', $readme_log ), 'readme has one 1.1.13 heading' );
bepluspb_changelog_assert( 1 === preg_match_all( '/^## \[1\.1\.13\] - 2026-10-08$/m', $changelog ), 'developer changelog has one 1.1.13 heading' );
bepluspb_changelog_assert( 1 === preg_match_all( '/^= 1\.1\.12 =$/m', $readme_log ), 'readme has one 1.1.12 heading' );
bepluspb_changelog_assert( 1 === preg_match_all( '/^## \[1\.1\.12\] - 2026-10-01$/m', $changelog ), 'developer changelog has one 1.1.12 heading' );

$intermediate = array( '1.0.11', '1.1.0', '1.1.1', '1.1.2', '1.1.3', '1.1.4', '1.1.5', '1.1.6', '1.1.7', '1.1.8', '1.1.9', '1.1.10', '1.1.11' );
foreach ( $intermediate as $version ) {
	bepluspb_changelog_assert( false === strpos( $readme, "= {$version} =" ), "readme omits intermediate {$version} heading" );
	bepluspb_changelog_assert( false === strpos( $changelog, "## [{$version}]" ), "developer changelog omits intermediate {$version} heading" );
}

bepluspb_changelog_assert( false !== strpos( $readme, '= 1.0.10 =' ), 'readme retains the currently published 1.0.10 boundary' );
bepluspb_changelog_assert( false !== strpos( $changelog, '## [1.0.10]' ), 'developer changelog retains the currently published 1.0.10 boundary' );
bepluspb_changelog_assert( false !== strpos( $readme, 'Predictive Navigation' ), 'consolidated readme keeps feature history' );
bepluspb_changelog_assert( false !== strpos( $readme, 'WP_DEBUG_LOG' ), 'consolidated readme keeps 1.0.11 fix history' );

echo "PASS: 1.0.11 through 1.1.12 changelog is consolidated under 1.1.12; 1.1.13 sits on top\n";
