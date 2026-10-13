<?php
/**
 * Regression tests: .htaccess header rules, Cleanup (block themes, oEmbed
 * sanitization), and CSS head-buffer exclusions.
 *
 * Run: php -n tests/test-delivery-hardening.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array(); $GLOBALS['removed'] = array(); $GLOBALS['block_theme'] = false; $GLOBALS['excluded'] = false;
function add_action( $h, $cb = null, $p = 10 ) { $GLOBALS['hooks'][] = $h . ':' . ( is_array( $cb ) ? $cb[1] : $cb ); }
function add_filter( $h, $cb = null, $p = 10 ) { $GLOBALS['hooks'][] = $h . ':' . ( is_array( $cb ) ? $cb[1] : $cb ); }
function remove_action( $h, $cb, $p = 10 ) { $GLOBALS['removed'][] = "$h:$cb"; }
function remove_filter( $h, $cb, $p = 10 ) { $GLOBALS['removed'][] = "$h:$cb"; }
function wp_is_block_theme() { return $GLOBALS['block_theme']; }
function is_customize_preview() { return false; }
function t_ok( $c, $m ) { if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-htaccess.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-cleanup.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';

// --- .htaccess rules ---------------------------------------------------------
$m = new ReflectionMethod( BEPLUSPB_Htaccess::class, 'get_rules' ); $m->setAccessible( true );
$rules = implode( "\n", $m->invoke( null ) );
t_ok( false !== strpos( $rules, 'Header setIfEmpty Cache-Control "no-cache, must-revalidate"' ) && false === strpos( $rules, 'Header set Cache-Control "no-cache, must-revalidate"' ), 'PHP/HTML Cache-Control never replaces an app-set header' );
t_ok( false !== strpos( $rules, 'RewriteRule \.(css|js)$ - [E=BEPLUSPB_VERSIONED:1]' ) && false !== strpos( $rules, '(^|&)(ver|v|version)=' ), 'versioned CSS/JS detection present' );
t_ok( false !== strpos( $rules, 'max-age=31536000, public, immutable" env=BEPLUSPB_VERSIONED' ) && false !== strpos( $rules, 'max-age=2592000, public" env=!BEPLUSPB_VERSIONED' ), 'immutable only for versioned CSS/JS, 30 days otherwise' );
t_ok( 1 === preg_match( '/<FilesMatch "\\\\\.\(css\|js\)\$">(?:(?!<\/FilesMatch>).)*max-age=31536000, public, immutable" env=BEPLUSPB_VERSIONED/s', $rules ), 'css/js block uses env condition for immutable' );
foreach ( array( 'application/rss+xml', 'application/atom+xml', 'text/plain' ) as $type ) {
	t_ok( 1 === preg_match( '#ExpiresByType\s+' . preg_quote( $type, '#' ) . '\s+"access plus 0 seconds"#', $rules ), "$type is not cached by ExpiresDefault" );
}
t_ok( 1 === preg_match( '#ExpiresByType text/css\s+"access plus 1 month"#', $rules ), 'unversioned-safe CSS Expires (1 month)' );
// Every IfModule block opened is closed.
t_ok( substr_count( $rules, '<IfModule' ) === substr_count( $rules, '</IfModule>' ) && substr_count( $rules, '<FilesMatch' ) === substr_count( $rules, '</FilesMatch>' ), 'balanced IfModule/FilesMatch blocks' );

// --- Cleanup -------------------------------------------------------------------
$call = function ( $method ) { $GLOBALS['hooks'] = array(); $GLOBALS['removed'] = array(); $r = new ReflectionMethod( BEPLUSPB_Cleanup::class, $method ); $r->setAccessible( true ); $r->invoke( null ); };
$GLOBALS['block_theme'] = false; $call( 'remove_block_css' );
t_ok( in_array( 'wp_enqueue_scripts:dequeue_block_styles', $GLOBALS['hooks'], true ), 'classic theme: block CSS dequeue registered' );
$GLOBALS['block_theme'] = true; $call( 'remove_block_css' );
t_ok( array() === $GLOBALS['hooks'], 'block theme: block CSS / global-styles left alone' );
$call( 'remove_embed_late' );
t_ok( ! in_array( 'oembed_dataparse:wp_filter_oembed_result', $GLOBALS['removed'], true ), 'oEmbed result sanitization is not removed' );

// --- CSS head buffer honours the shared exclusion -------------------------------
$_GET['elementor-preview'] = '1';
$before = ob_get_level(); BEPLUSPB_CSS::buffer_start(); $opened = ob_get_level() > $before; while ( ob_get_level() > $before ) { ob_end_clean(); }
t_ok( ! $opened, 'CSS wp_head buffer not opened inside a page-builder preview' );
unset( $_GET['elementor-preview'] );
BEPLUSPB_CSS::buffer_start(); $opened = ob_get_level() > $before; while ( ob_get_level() > $before ) { ob_end_clean(); }
t_ok( $opened, 'CSS wp_head buffer opened on a normal request' );

echo "PASS: delivery hardening\n";
