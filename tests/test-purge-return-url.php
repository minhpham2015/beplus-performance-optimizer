<?php
/**
 * Tests that redirect_after_purge() honours the return URL: the admin bar sends the current page in `bepluspb_return`; the handler goes back
 * there when it is a same-site URL and falls back to the settings page otherwise (no open redirect).
 * wp_validate_redirect() is stubbed with WordPress's rule: only the site's own host is allowed.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
class Redirected extends Exception {}
function add_action() {} function add_filter() {}
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function wp_unslash( $s ) { return $s; }
function esc_url_raw( $u ) { return preg_match( '#^https?://#i', $u ) ? $u : ''; }
function wp_validate_redirect( $l, $d = '' ) { $h = parse_url( $l, PHP_URL_HOST ); return 'example.com' === $h ? $l : $d; }
function wp_safe_redirect( $u ) { throw new Redirected( $u ); }
class BEPLUSPB_Minify {} class BEPLUSPB_Page_Cache {} class BEPLUSPB_Object_Cache {}
require dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function go( $post ) {
	$_POST = $post;
	$m = new ReflectionMethod( 'BEPLUSPB_Admin', 'redirect_after_purge' );
	$m->setAccessible( true );
	try { $m->invoke( null ); } catch ( Redirected $r ) { return $r->getMessage(); }
	return null;
}
$settings = 'https://example.com/wp-admin/options-general.php?page=beplus-performance-booster';

t_ok( $settings === go( array() ), 'no return field: settings page (dashboard button)' );
t_ok( 'https://example.com/blog/post/?a=1' === go( array( 'bepluspb_return' => 'https://example.com/blog/post/?a=1' ) ), 'front-end page: back to the same page, query kept' );
t_ok( 'https://example.com/wp-admin/edit.php?post_type=page' === go( array( 'bepluspb_return' => 'https://example.com/wp-admin/edit.php?post_type=page' ) ), 'another admin page: back there' );
t_ok( $settings === go( array( 'bepluspb_return' => 'https://evil.test/phish' ) ), 'foreign host: settings page' );
t_ok( $settings === go( array( 'bepluspb_return' => '//evil.test/x' ) ), 'protocol-relative foreign URL: settings page' );
t_ok( $settings === go( array( 'bepluspb_return' => 'javascript:alert(1)' ) ), 'javascript: URL: settings page' );
t_ok( $settings === go( array( 'bepluspb_return' => array( 'https://example.com/' ) ) ), 'array value: settings page' );
t_ok( $settings === go( array( 'bepluspb_return' => '' ) ), 'empty value: settings page' );

echo "PASS: $n purge return URL assertions\n";
