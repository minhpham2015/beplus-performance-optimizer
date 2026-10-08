<?php
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
/**
 * Standalone contract tests: Fonts + CDN + Cache Exclusions merged into one
 * "Advanced" tab.
 *
 * Run: php -n tests/test-advanced-tab-merge.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Standalone source contract test intentionally uses native file access and exception messages.

$root   = dirname( __DIR__ );
$admin  = bepluspb_admin_source();
$script = file_get_contents( $root . '/assets/js/admin.js' );

function assert_true( $cond, $message ) {
	if ( ! $cond ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
}

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

function count_occurrences( $needle, $haystack ) {
	return substr_count( $haystack, $needle );
}

// ---------------------------------------------------------------------------
// 1. Tab nav: old ids gone, single 'advanced' tab present, labelled Advanced.
// ---------------------------------------------------------------------------

assert_not_contains( "'fonts'        =>", $admin, 'fonts tab entry removed from $tabs array' );
assert_not_contains( "'cdn'          =>", $admin, 'cdn tab entry removed from $tabs array' );
assert_not_contains( "'exclusions'   =>", $admin, 'exclusions tab entry removed from $tabs array' );
assert_contains( "'advanced'", $admin, 'advanced tab entry present in $tabs array' );
assert_contains( "__( 'Advanced', 'beplus-performance-booster' )", $admin, 'advanced tab labelled Advanced' );

// ---------------------------------------------------------------------------
// 2. Exactly one Advanced panel; old panel ids are gone from markup.
// ---------------------------------------------------------------------------

assert_true(
	1 === count_occurrences( 'id="bepluspb-tab-advanced"', $admin ),
	'exactly one bepluspb-tab-advanced panel exists (found ' . count_occurrences( 'id="bepluspb-tab-advanced"', $admin ) . ')'
);
assert_not_contains( 'id="bepluspb-tab-fonts"', $admin, 'old bepluspb-tab-fonts panel removed' );
assert_not_contains( 'id="bepluspb-tab-cdn"', $admin, 'old bepluspb-tab-cdn panel removed' );
assert_not_contains( 'id="bepluspb-tab-exclusions"', $admin, 'old bepluspb-tab-exclusions panel removed' );

// ---------------------------------------------------------------------------
// 3. Each render_section_* is still defined exactly once (unchanged
//    backend/render logic) and each is CALLED exactly once, all three
//    calls living inside the Advanced panel, in the required order:
//    Fonts -> CDN -> Cache Exclusions.
// ---------------------------------------------------------------------------

foreach ( array( 'render_section_fonts', 'render_section_cdn', 'render_section_exclusions' ) as $fn ) {
	assert_true(
		1 === count_occurrences( "private static function {$fn}(", $admin ),
		"{$fn}() is still defined exactly once"
	);
	assert_true(
		1 === count_occurrences( "self::{$fn}(", $admin ),
		"self::{$fn}() is called exactly once (found " . count_occurrences( "self::{$fn}(", $admin ) . ')'
	);
}

$panel_start = strpos( $admin, 'id="bepluspb-tab-advanced"' );
assert_true( false !== $panel_start, 'advanced panel start marker found' );
$panel_end = strpos( $admin, 'id="bepluspb-tab-predictive"', $panel_start );
assert_true( false !== $panel_end, 'predictive panel found after advanced panel (bounds the panel)' );
$panel = substr( $admin, $panel_start, $panel_end - $panel_start );

$pos_fonts      = strpos( $panel, 'self::render_section_fonts(' );
$pos_cdn        = strpos( $panel, 'self::render_section_cdn(' );
$pos_exclusions = strpos( $panel, 'self::render_section_exclusions(' );

assert_true( false !== $pos_fonts, 'render_section_fonts() call lives inside the Advanced panel' );
assert_true( false !== $pos_cdn, 'render_section_cdn() call lives inside the Advanced panel' );
assert_true( false !== $pos_exclusions, 'render_section_exclusions() call lives inside the Advanced panel' );
assert_true( $pos_fonts < $pos_cdn, 'Fonts section renders before CDN section' );
assert_true( $pos_cdn < $pos_exclusions, 'CDN section renders before Cache Exclusions section' );

// ---------------------------------------------------------------------------
// 4. Accessibility: panel role/label, section anchors/headings present.
// ---------------------------------------------------------------------------

assert_contains( 'id="bepluspb-tab-advanced" class="bepluspb-tab-panel" role="tabpanel"', $admin, 'advanced panel has tabpanel role' );
assert_contains( 'aria-labelledby="bepluspb-tab-btn-advanced"', $admin, 'advanced panel is labelled by its tab button' );
assert_contains( 'id="bepluspb-tab-btn-<?php echo esc_attr( $id ); ?>"', $admin, 'tab buttons render a stable id (bepluspb-tab-btn-{id}) per tab, including advanced' );
assert_contains( 'id="bepluspb-advanced-section-fonts"', $panel, 'font optimization section has a stable anchor id' );
assert_contains( 'id="bepluspb-advanced-section-cdn"', $panel, 'CDN section has a stable anchor id' );
assert_contains( 'id="bepluspb-advanced-section-exclusions"', $panel, 'cache exclusions section has a stable anchor id' );

// ---------------------------------------------------------------------------
// 5. Every existing option field name/id used by fonts/cdn/exclusions is
//    preserved exactly once (no duplication, no omission).
// ---------------------------------------------------------------------------

$field_names = array(
	'[font_preload]',
	'[cdn_enabled]',
	'[cdn_url]',
	'[cdn_file_types]',
	'[cdn_exclude]',
	'[cdn_webp_avif]',
	'[cache_exclude_pages]',
	'[cache_for_logged_in]',
	'[cache_headers]',
);
foreach ( $field_names as $name ) {
	assert_true(
		1 === count_occurrences( $name, $admin ),
		"option field {$name} appears exactly once in the whole file (found " . count_occurrences( $name, $admin ) . ')'
	);
}

$field_ids = array(
	'id="bepluspb_font_preload"',
	'id="bepluspb_cdn_enabled"',
	'id="bepluspb_cdn_url"',
	'id="bepluspb_cdn_file_types"',
	'id="bepluspb_cdn_exclude"',
	'id="bepluspb_cdn_webp_avif"',
	'id="bepluspb_cache_exclude_pages"',
	'id="bepluspb_cache_for_logged_in"',
	'id="bepluspb_cache_headers"',
);
foreach ( $field_ids as $id ) {
	assert_true(
		1 === count_occurrences( $id, $admin ),
		"field {$id} appears exactly once (found " . count_occurrences( $id, $admin ) . ')'
	);
}

// ---------------------------------------------------------------------------
// 6. Save bar remains exactly once, inside the single settings <form>.
// ---------------------------------------------------------------------------

assert_true(
	1 === count_occurrences( 'id="bepluspb-save-bar"', $admin ),
	'save bar exists exactly once'
);

// ---------------------------------------------------------------------------
// 7. Cloudflare tab is unchanged/separate: still its own tab entry, its own
//    panel, and render_section_cloudflare() still called exactly once
//    OUTSIDE the Advanced panel.
// ---------------------------------------------------------------------------

assert_contains( "'cloudflare'   =>", $admin, 'Cloudflare tab entry unchanged in $tabs array' );
assert_contains( 'id="bepluspb-tab-cloudflare"', $admin, 'Cloudflare panel still present' );
assert_true(
	1 === count_occurrences( 'self::render_section_cloudflare(', $admin ),
	'render_section_cloudflare() still called exactly once'
);
assert_true(
	false === strpos( $panel, 'render_section_cloudflare(' ),
	'Cloudflare is NOT rendered inside the Advanced panel'
);

// ---------------------------------------------------------------------------
// 8. Predictive Navigation and Object Cache were not moved.
// ---------------------------------------------------------------------------

assert_contains( "'predictive'   =>", $admin, 'Predictive Navigation tab entry unchanged' );
assert_contains( 'id="bepluspb-tab-predictive"', $admin, 'Predictive Navigation panel still present' );
assert_true(
	1 === count_occurrences( 'self::render_section_predictive_navigation(', $admin ),
	'render_section_predictive_navigation() still called exactly once'
);
assert_true(
	false === strpos( $panel, 'render_section_predictive_navigation(' ),
	'Predictive Navigation is NOT rendered inside the Advanced panel'
);

assert_contains( "'object_cache' =>", $admin, 'Object Cache tab entry unchanged' );
assert_contains( 'id="bepluspb-tab-object_cache"', $admin, 'Object Cache panel still present' );
assert_true(
	1 === count_occurrences( 'self::render_section_object_cache(', $admin ),
	'render_section_object_cache() still called exactly once'
);
assert_true(
	false === strpos( $panel, 'render_section_object_cache(' ),
	'Object Cache is NOT rendered inside the Advanced panel'
);

// ---------------------------------------------------------------------------
// 9. Other existing tabs (Dashboard, Cache Files, Cleanup, Status,
//    AI Optimizer) are unaffected: entries + panels still present.
// ---------------------------------------------------------------------------

foreach (
	array(
		'dashboard'    => 'bepluspb-tab-dashboard',
		'cache_files'  => 'bepluspb-tab-cache_files',
		'cleanup'      => 'bepluspb-tab-cleanup',
		'status'       => 'bepluspb-tab-status',
		'ai_optimizer' => 'bepluspb-tab-ai_optimizer',
	) as $tab_id => $panel_id
) {
	assert_contains( "'{$tab_id}'", $admin, "tab entry '{$tab_id}' still present" );
	assert_contains( 'id="' . $panel_id . '"', $admin, "panel #{$panel_id} still present" );
}

// ---------------------------------------------------------------------------
// 10. No duplicate top-level <h2> hierarchy introduced: the Advanced panel
//     should not add its own competing <h2> above the three existing
//     card <h2> headings (i.e. no new bare "<h2>Advanced" heading).
// ---------------------------------------------------------------------------

assert_not_contains( '<h2>' . "\n" . '<?php esc_html_e( \'Advanced\'', $admin, 'no duplicate h2 "Advanced" heading added (avoid duplicate h2 hierarchy)' );

// ---------------------------------------------------------------------------
// 11. JS: backward-compatible aliasing of old hash/deep-link tab ids to
//     'advanced', without unsafe redirects (no location.href/replace/assign
//     writes — client-side activate() only).
// ---------------------------------------------------------------------------

assert_contains( 'advanced', $script, 'admin.js references the advanced tab id' );
assert_true(
	strpos( $script, "fonts: 'advanced'" ) !== false || strpos( $script, "fonts:'advanced'" ) !== false || preg_match( "/fonts\\s*:\\s*'advanced'/", $script ),
	'admin.js aliases the old "fonts" tab id to "advanced"'
);
assert_true(
	preg_match( "/cdn\\s*:\\s*'advanced'/", $script ) === 1,
	'admin.js aliases the old "cdn" tab id to "advanced"'
);
assert_true(
	preg_match( "/exclusions\\s*:\\s*'advanced'/", $script ) === 1,
	'admin.js aliases the old "exclusions" tab id to "advanced"'
);
assert_not_contains( 'location.href', $script, 'no unsafe location.href redirect used for tab aliasing' );
assert_not_contains( 'location.replace', $script, 'no unsafe location.replace redirect used for tab aliasing' );
assert_not_contains( 'location.assign', $script, 'no unsafe location.assign redirect used for tab aliasing' );

// ---------------------------------------------------------------------------
// 12. Internal deep-links that previously pointed at 'exclusions' now point
//     at 'advanced' (Status tab recommendation links via $tab_link()).
// ---------------------------------------------------------------------------

assert_not_contains( "\$tab_link( 'exclusions'", $admin, 'internal deep-links no longer point at removed exclusions tab id' );
assert_contains( "\$tab_link( 'advanced'", $admin, 'internal deep-links updated to point at advanced tab id' );

echo "PASS: Advanced tab merge (Fonts + CDN + Cache Exclusions) contract\n";
