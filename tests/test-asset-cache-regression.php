<?php
/**
 * Standalone asset-cache regression tests.
 *
 * Run: php -n tests/test-asset-cache-regression.php
 *
 * @package Beplus_Performance_Booster
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', sys_get_temp_dir() . '/bepluspb-cache-test-' . getmypid() . '/' );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

/**
 * Escape a test exception message.
 *
 * @param string $message Test message.
 * @return string
 */
function esc_html( $message ) {
	return $message;
}

/**
 * Assert a test condition.
 *
 * @param bool   $condition Test condition.
 * @param string $message   Failure message.
 * @throws RuntimeException When the assertion fails.
 */
function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
}

function wp_delete_file( $file ) {
	return unlink( $file );
}

function delete_transient( $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return true;
}

function wp_is_writable( $path ) {
	return is_writable( $path );
}

function wp_mkdir_p( $path ) {
	return mkdir( $path, 0777, true );
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function get_transient( $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return false;
}

function set_transient( $key, $value, $ttl ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require_once dirname( __DIR__ ) . '/includes/class-bepluspb-ucss.php';

mkdir( BEPLUSPB_CACHE_DIR, 0777, true );

$css     = "/*! license */\n/* remove */\n.foo { color: red; margin: 0  10px; }";
$css_min = BEPLUSPB_Minify::minify_css( $css );
assert_true( false !== strpos( $css_min, '/*! license */' ), 'CSS license comment must be preserved' );
assert_true( false === strpos( $css_min, 'remove' ), 'ordinary CSS comment must be removed' );
assert_true( false !== strpos( $css_min, '.foo{color:red;margin:0 10px}' ), 'CSS whitespace must be minified' );

$js     = "/*! license */\n// remove\nwindow.url = 'https://example.com/a//b';\nwindow.ok = true;";
$js_min = BEPLUSPB_Minify::minify_js( $js );
assert_true( false === strpos( $js_min, '// remove' ), 'ordinary JS comment must be removed' );
assert_true( false !== strpos( $js_min, 'https://example.com/a//b' ), 'URL-like JS string must survive minification' );

$a = BEPLUSPB_CACHE_DIR . 'ucss-page-a-style-deadbeef00.css';
$b = BEPLUSPB_CACHE_DIR . 'ucss-page-b-style-deadbeef00.css';
$c = BEPLUSPB_CACHE_DIR . 'ucss-page-c-style-deadbeef00.css';
$d = BEPLUSPB_CACHE_DIR . 'ucss-page-d-style-deadbeef00.css';

assert_true( BEPLUSPB_UCSS::write_deduplicated_cache( $a, '.same{color:red}' ), 'first UCSS write must succeed' );
assert_true( BEPLUSPB_UCSS::write_deduplicated_cache( $b, '.same{color:red}' ), 'duplicate UCSS write must succeed' );
assert_true( fileinode( $a ) === fileinode( $b ), 'identical UCSS must share one inode through a hard link' );
assert_true( BEPLUSPB_UCSS::write_deduplicated_cache( $c, '.different{color:blue}' ), 'different UCSS write must succeed' );
assert_true( fileinode( $a ) !== fileinode( $c ), 'different UCSS must not share an inode' );

$old = BEPLUSPB_CACHE_DIR . 'ucss-page-d-style-oldhash000.css';
file_put_contents( $old, '.old{}' );
assert_true( BEPLUSPB_UCSS::write_deduplicated_cache( $d, '.new{}' ), 'new generation write must succeed' );
assert_true( ! file_exists( $old ), 'obsolete generation for the same URL and handle must be removed' );
assert_true( file_exists( $d ), 'current generation must remain' );

$cleanup_files = array_merge(
	glob( BEPLUSPB_CACHE_DIR . '*' ),
	glob( BEPLUSPB_CACHE_DIR . '.ucss-lock-*' )
);
foreach ( array_unique( $cleanup_files ) as $file ) {
	unlink( $file );
}
rmdir( BEPLUSPB_CACHE_DIR );

echo "PASS: 13 asset-cache assertions\n";
