<?php
/**
 * WordPress Core loading-optimization hook compatibility contract.
 *
 * The fixtures are exact excerpts from the tagged WordPress 6.3.6 and 6.4.5
 * media.php sources. They keep routine CI deterministic and offline.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
function ok($condition, $message) {
	if (!$condition) {
		throw new RuntimeException('FAIL: ' . $message);
	}
}
function fixture($version) {
	$path = __DIR__ . '/fixtures/wordpress-' . str_replace( '.', '-', $version ) . '-media.txt';
	ok(is_file($path), 'Core source fixture exists for WordPress ' . $version);
	return file_get_contents($path);
}
function function_body($source, $name) {
	$needle = 'function ' . $name . '(';
	$start = strpos($source, $needle);
	ok(false !== $start, $name . ' exists in Core source');
	$brace = strpos($source, '{', $start);
	$depth = 0;
	for ($i = $brace, $length = strlen($source); $i < $length; $i++) {
		if ('{' === $source[$i]) { $depth++; }
		if ('}' === $source[$i] && 0 === --$depth) { return substr($source, $brace, $i - $brace + 1); }
	}
	throw new RuntimeException('FAIL: could not parse ' . $name);
}

$wp63 = function_body(fixture('6.3.6'), 'wp_get_loading_optimization_attributes');
$wp64 = function_body(fixture('6.4.5'), 'wp_get_loading_optimization_attributes');
ok(false === strpos($wp63, "apply_filters( 'wp_get_loading_optimization_attributes'"), 'WordPress 6.3 does not expose the attributes filter');
ok(false !== strpos($wp64, "apply_filters( 'wp_get_loading_optimization_attributes'"), 'WordPress 6.4 exposes the attributes filter');
ok(false !== strpos($wp64, '$loading_attrs, $tag_name, $attr, $context'), 'WordPress 6.4 filter signature remains four arguments in documented order');
echo "PASS: WordPress loading hook compatibility contract (7 assertions)\n";
