<?php
/**
 * Resource hints: validation of origins and the printed <link> tags.
 * Run: php -n tests/test-resource-hints.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function __( $t, $d = '' ) { return $t; }
function home_url() { return 'https://ex.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_url( $u ) { return str_replace( array( '"', "'", '<', '>' ), '', $u ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-resource-hints.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function norm( $v ) { return BEPLUSPB_Resource_Hints::normalize_origin( $v ); }

// Origins.
t_ok( 'https://cdn.example.net' === norm( 'https://cdn.example.net' ), 'plain https origin' );
t_ok( 'https://cdn.example.net' === norm( 'https://CDN.Example.NET/path/file.js?x=1#y' ), 'path, query and fragment dropped, host lower-cased' );
t_ok( 'https://cdn.example.net' === norm( '//cdn.example.net' ) && 'https://cdn.example.net' === norm( 'cdn.example.net' ), 'protocol-relative and bare hosts become https' );
t_ok( 'http://cdn.example.net' === norm( 'http://cdn.example.net' ), 'explicit http is respected' );
t_ok( 'https://cdn.example.net:8443' === norm( 'https://cdn.example.net:8443' ) && 'https://cdn.example.net' === norm( 'https://cdn.example.net:443' ) && 'http://cdn.example.net' === norm( 'http://cdn.example.net:80' ), 'ports kept unless they are the default' );
foreach ( array( '', 'javascript:alert(1)', 'data:text/html,x', 'ftp://cdn.example.net', 'file:///etc/passwd', 'https://user:pw@cdn.example.net', 'https://user@cdn.example.net', 'https://localhost', 'https://127.0.0.1', 'https://[::1]', 'https://exa mple.com', "https://a.example.net\nhttps://b.example.net", 'https://cdn.example.net:0', 'https://cdn.example.net:99999', 'https://-bad.example.net', 'https://bad-.example.net', 'https://a..example.net', 'https://example.123', '"><script>alert(1)</script>', 'https://ex.com"onload="x', "https://ex.com'" ) as $bad ) {
	t_ok( '' === norm( $bad ), 'rejected: ' . json_encode( $bad ) );
}
t_ok( '' === norm( 'https://' . str_repeat( 'a', 64 ) . '.example.net' ), 'a label over 63 characters is rejected' );

// Parsing.
$p = BEPLUSPB_Resource_Hints::parse( "https://a.example.net\n\nhttps://a.example.net/x\n b.example.net crossorigin\nnot a host\njavascript:x\nhttps://c.example.net crossorigin extra", 4 );
t_ok( array( 'https://a.example.net', 'https://b.example.net' ) === array_column( $p['entries'], 'origin' ), 'valid lines kept, duplicates (same origin) dropped' );
t_ok( array( false, true ) === array_column( $p['entries'], 'crossorigin' ), 'crossorigin flag parsed' );
t_ok( 3 === count( $p['errors'] ), 'one message per bad line' );
foreach ( $p['errors'] as $e ) {
	t_ok( false === strpos( $e, '<' ), 'messages carry no markup' );
}
$many = implode( "\n", array_map( static fn( $i ) => "https://h$i.example.net", range( 1, 6 ) ) );
$p    = BEPLUSPB_Resource_Hints::parse( $many, 4 );
t_ok( 4 === count( $p['entries'] ) && 1 === count( $p['errors'] ), 'the limit is enforced and reported once' );
t_ok( 4 === count( BEPLUSPB_Resource_Hints::entries( $many, 4 ) ) && 10 === count( BEPLUSPB_Resource_Hints::entries( implode( "\n", array_map( static fn( $i ) => "https://h$i.example.net", range( 1, 20 ) ) ), 10 ) ), 'entries() honours the limit' );
t_ok( array() === BEPLUSPB_Resource_Hints::entries( str_repeat( 'a', 400 ) . '.example.net', 4 ), 'overlong lines are rejected' );
t_ok( "https://a.example.net crossorigin\nhttps://b.example.net" === BEPLUSPB_Resource_Hints::sanitize( "A.example.net CROSSORIGIN\n<script>\nhttps://b.example.net/p", 4 ), 'sanitize() stores clean, normalised lines only' );
t_ok( '' === BEPLUSPB_Resource_Hints::sanitize( '', 4 ) && '' === BEPLUSPB_Resource_Hints::sanitize( null, 4 ), 'empty in, empty out' );

// Output.
$pre = BEPLUSPB_Resource_Hints::entries( "https://a.example.net\nhttps://fonts.example.net crossorigin\nhttps://ex.com", 4 );
$dns = BEPLUSPB_Resource_Hints::entries( "https://c.example.net\nhttps://a.example.net", 10 );
$out = BEPLUSPB_Resource_Hints::render( $pre, $dns, 'https://ex.com' );
t_ok( '<link rel="preconnect" href="https://a.example.net">' . "\n" . '<link rel="preconnect" href="https://fonts.example.net" crossorigin>' . "\n" . '<link rel="dns-prefetch" href="https://c.example.net">' . "\n" . '<link rel="dns-prefetch" href="https://a.example.net">' . "\n" === $out, 'exact tags, own origin skipped, crossorigin only on preconnect' );
t_ok( '' === BEPLUSPB_Resource_Hints::render( array(), array(), 'https://ex.com' ), 'nothing to print' );
t_ok( '' === BEPLUSPB_Resource_Hints::render( array( array( 'origin' => 'javascript:alert(1)', 'crossorigin' => false ), array( 'origin' => 'https://x.test"><script>', 'crossorigin' => true ) ), array(), 'https://ex.com' ), 'entries are re-validated at output time' );
t_ok( '' === BEPLUSPB_Resource_Hints::render( BEPLUSPB_Resource_Hints::entries( 'https://ex.com:443', 4 ), array(), 'https://ex.com:443/' ), 'own origin is compared normalised (default port)' );
t_ok( false !== strpos( BEPLUSPB_Resource_Hints::render( array( array( 'origin' => 'https://a.example.net', 'crossorigin' => true ) ), array(), 'http://other.test' ), 'crossorigin' ), 'crossorigin printed' );

// Boot.
$GLOBALS['hooks'] = array();
BEPLUSPB_Resource_Hints::init( array() );
t_ok( array() === $GLOBALS['hooks'], 'no entries registers nothing' );
BEPLUSPB_Resource_Hints::init( array( 'resource_hints_preconnect' => 'javascript:x', 'resource_hints_dns_prefetch' => '' ) );
t_ok( array() === $GLOBALS['hooks'], 'only invalid entries registers nothing' );
BEPLUSPB_Resource_Hints::init( array( 'resource_hints_preconnect' => 'https://a.example.net', 'resource_hints_dns_prefetch' => '' ) );
t_ok( 1 === $GLOBALS['hooks']['wp_head'][0][1], 'printed at wp_head priority 1' );
ob_start(); call_user_func( $GLOBALS['hooks']['wp_head'][0][0] ); $printed = ob_get_clean();
t_ok( '<link rel="preconnect" href="https://a.example.net">' . "\n" === $printed, 'the hook prints the tag' );

// Source guards: output only.
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-resource-hints.php' );
foreach ( array( 'wp_remote_', 'curl_', 'file_get_contents', 'fsockopen', '$wpdb', 'update_option' ) as $bad ) {
	t_ok( false === strpos( $src, $bad ), "resource hints class does not use $bad" );
}

echo "OK ($n assertions)\n";
