<?php
/**
 * Resource hints (preconnect / dns-prefetch) and LCP image priority: host validation, the
 * hints handed to core, which images are boosted, and the admin save/render round trip.
 *
 * Run: php -n tests/test-resource-hints.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$root = dirname( __DIR__ );

$GLOBALS['opts']    = array( 'cache_headers' => 0, 'preconnect_hosts' => '', 'dns_prefetch_hosts' => '', 'lcp_image_keywords' => '' );
$GLOBALS['filters'] = array();
function add_action() {}
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][] = array( $tag, $cb, $prio, $args ); }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function __( $s ) { return $s; }
function esc_html_e( $s ) { echo htmlspecialchars( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function selected( $a, $b = true ) { echo (string) $a === (string) $b ? ' selected="selected"' : ''; }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function esc_url_raw( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function get_option( $k, $d = false ) { return $d; }

class BEPLUSPB_Page_Cache {
	const MOBILE_MODES = array( 'auto' );
	public static function sanitize_choice( $v, $allowed, $d ) { return in_array( $v, $allowed, true ) ? $v : $d; }
	public static function sanitize_ttl_hours( $v ) { return (int) $v; }
	public static function sanitize_cookie_list( $v ) { return (string) $v; }
	public static function sanitize_option_names( $v ) { return (string) $v; }
}
class BEPLUSPB_Predictive_Navigation { public static function sanitize_excludes( $v ) { return (string) $v; } }
class BEPLUSPB_Object_Cache { public static function write_config( $o ) { return true; } }
class BEPLUSPB_Htaccess { public static function add_rules() {} public static function remove_rules() {} }
function add_settings_error() {}
class BEPLUSPB_CDN { public static function default_file_types() { return 'jpg'; } }

require $root . '/includes/class-bepluspb-resource-hints.php';
require $root . '/includes/class-bepluspb-heartbeat.php';
require $root . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function origins( $raw, $limit = 20 ) { return array_column( BEPLUSPB_Resource_Hints::parse_hosts( $raw, $limit ), 'origin' ); }
function reset_lcp() { $p = new ReflectionProperty( 'BEPLUSPB_Resource_Hints', 'lcp_count' ); $p->setAccessible( true ); $p->setValue( null, 0 ); }

// --- host parsing: accepted forms ------------------------------------------
t_ok( array( 'https://cdn.example.com' ) === origins( 'cdn.example.com' ), 'bare host becomes https origin' );
t_ok( array( 'https://cdn.example.com' ) === origins( "  CDN.Example.COM.  " ), 'host is lower-cased, trimmed, trailing dot removed' );
t_ok( array( 'https://cdn.example.com' ) === origins( 'https://cdn.example.com/a/b.css?x=1#y' ), 'path, query and fragment are dropped' );
t_ok( array( 'http://static.example.org' ) === origins( 'http://static.example.org' ), 'http is kept' );
t_ok( array( 'https://cdn.example.com:8443' ) === origins( 'cdn.example.com:8443' ), 'bare host:port keeps the port' );
t_ok( array( 'https://cdn.example.com' ) === origins( '//cdn.example.com/x' ), 'protocol-relative gets https' );
t_ok( array( 'https://xn--bcher-kva.example' ) === origins( 'xn--bcher-kva.example' ), 'punycode host accepted' );
t_ok( array( 'https://a.example.com', 'https://b.example.com' ) === origins( "a.example.com\r\nb.example.com\n\n" ), 'CRLF and blank lines handled' );
t_ok( array( 'https://a.example.com' ) === origins( "a.example.com\nhttps://A.example.com/other\n" ), 'duplicates collapse' );
t_ok( 2 === count( origins( "a.example.com\nb.example.com\nc.example.com", 2 ) ), 'limit enforced' );
$e = BEPLUSPB_Resource_Hints::parse_hosts( "fonts.gstatic.com crossorigin\nplain.example.com\nB.example.com CrossOrigin", 10 );
t_ok( true === $e[0]['crossorigin'] && false === $e[1]['crossorigin'] && true === $e[2]['crossorigin'], 'crossorigin flag parsed (case-insensitive)' );
t_ok( 'fonts.gstatic.com' === $e[0]['host'], 'host field is the bare host' );

// --- host parsing: rejected -------------------------------------------------
foreach ( array(
	'javascript:alert(1)', 'data:text/html,x', 'JaVaScRiPt://x.example.com', 'ftp://files.example.com', 'file:///etc/passwd',
	'https://user:pw@cdn.example.com', 'user@cdn.example.com', 'localhost', 'intranet', '127.0.0.1', 'https://10.0.0.5', '999.999.999.999',
	'[::1]', 'https://[2001:db8::1]', 'exa_mple.com', 'exa mple.com', 'cdn.example.com extra', 'cdn.example.com crossorigin extra', '-bad.example.com', 'bad-.example.com',
	'.example.com', 'example..com', 'cdn.example.com:99999', 'cdn.example.com:0', 'https://cdn.example.com"onload=x', 'cdn.example.com<script>',
	str_repeat( 'a', 64 ) . '.example.com', str_repeat( 'a.', 130 ) . 'com', "cdn.exa\x00mple.com", 'ex%41mple.com', 'https://', '://x.example.com',
) as $bad ) {
	t_ok( array() === origins( $bad ), 'rejected: ' . json_encode( $bad ) . ' -> ' . json_encode( origins( $bad ) ) );
}

t_ok( array( 'https://cdn.example.com' ) === origins( "cdn.example.com\x00" ), 'a trailing NUL is stripped by trim(), never passed on' );

// --- storage format -----------------------------------------------------------
$saved = BEPLUSPB_Resource_Hints::sanitize_host_list( "https://CDN.example.com/x\nbad host\njavascript:1\nfonts.gstatic.com crossorigin", 6 );
t_ok( "https://cdn.example.com\nhttps://fonts.gstatic.com crossorigin" === $saved, 'sanitize_host_list keeps only valid entries: ' . json_encode( $saved ) );
t_ok( $saved === BEPLUSPB_Resource_Hints::sanitize_host_list( $saved, 6 ), 'sanitize_host_list is idempotent' );
t_ok( '' === BEPLUSPB_Resource_Hints::sanitize_host_list( '', 6 ), 'empty stays empty' );

// --- what core receives -----------------------------------------------------------
$GLOBALS['opts']['preconnect_hosts']   = "cdn.example.com\nfonts.gstatic.com crossorigin";
$GLOBALS['opts']['dns_prefetch_hosts'] = "www.google-analytics.com\ncdn.example.com";
$pre = BEPLUSPB_Resource_Hints::filter_resource_hints( array( 'https://existing.example.net' ), 'preconnect' );
t_ok( 'https://existing.example.net' === $pre[0], 'existing hints kept' );
t_ok( 'https://cdn.example.com' === $pre[1], 'plain preconnect is a string' );
t_ok( array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' ) === $pre[2], 'crossorigin preconnect is an array' );
$dns = BEPLUSPB_Resource_Hints::filter_resource_hints( array(), 'dns-prefetch' );
t_ok( array( 'https://www.google-analytics.com', 'https://cdn.example.com' ) === $dns, 'dns-prefetch list' );
t_ok( array( 'x' ) === BEPLUSPB_Resource_Hints::filter_resource_hints( array( 'x' ), 'prefetch' ), 'other relation types untouched' );
t_ok( array( 'x' ) === BEPLUSPB_Resource_Hints::filter_resource_hints( array( 'x' ), 'prerender' ), 'prerender untouched' );
// A hand-edited / filtered database value is re-validated at render time, never trusted.
$GLOBALS['opts']['preconnect_hosts'] = "javascript:alert(1)\nhttps://u:p@evil.example.com\ngood.example.com";
t_ok( array( 'https://good.example.com' ) === BEPLUSPB_Resource_Hints::filter_resource_hints( array(), 'preconnect' ), 'unsafe stored values are dropped at render time' );
$GLOBALS['opts']['preconnect_hosts'] = implode( "\n", array_map( fn( $i ) => "h$i.example.com", range( 1, 20 ) ) );
t_ok( BEPLUSPB_Resource_Hints::MAX_PRECONNECT === count( BEPLUSPB_Resource_Hints::filter_resource_hints( array(), 'preconnect' ) ), 'preconnect capped at render time too' );

// --- init registers only what is configured ------------------------------------------
$GLOBALS['filters'] = array();
BEPLUSPB_Resource_Hints::init( array( 'preconnect_hosts' => '', 'dns_prefetch_hosts' => '', 'lcp_image_keywords' => '' ) );
t_ok( array() === $GLOBALS['filters'], 'nothing registered when nothing is configured' );
BEPLUSPB_Resource_Hints::init( array( 'preconnect_hosts' => 'a.example.com', 'dns_prefetch_hosts' => '', 'lcp_image_keywords' => '' ) );
t_ok( 1 === count( $GLOBALS['filters'] ) && 'wp_resource_hints' === $GLOBALS['filters'][0][0] && 2 === $GLOBALS['filters'][0][3], 'wp_resource_hints registered with 2 args' );
$GLOBALS['filters'] = array();
BEPLUSPB_Resource_Hints::init( array( 'preconnect_hosts' => '', 'dns_prefetch_hosts' => '', 'lcp_image_keywords' => 'hero' ) );
t_ok( 1 === count( $GLOBALS['filters'] ) && 'wp_get_loading_optimization_attributes' === $GLOBALS['filters'][0][0] && 4 === $GLOBALS['filters'][0][3], 'loading-attributes filter registered with 4 args' );

// --- LCP image priority ----------------------------------------------------------------
$GLOBALS['opts']['lcp_image_keywords'] = "hero-banner\n\n  \nslider-first";
$core = array( 'loading' => 'lazy', 'decoding' => 'async' );
reset_lcp();
$out = BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'https://x.test/uploads/hero-banner-1200.jpg' ), 'the_content' );
t_ok( 'high' === $out['fetchpriority'] && ! isset( $out['loading'] ) && 'async' === $out['decoding'], 'src match: fetchpriority high, lazy removed, other attrs kept' );
reset_lcp();
$out = BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'a.jpg', 'class' => 'wp-image-9 slider-first' ), 'x' );
t_ok( 'high' === $out['fetchpriority'], 'class match' );
reset_lcp();
$out = BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'a.jpg', 'id' => 'hero-banner' ), 'x' );
t_ok( 'high' === $out['fetchpriority'], 'id match' );
reset_lcp();
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'other.jpg', 'class' => 'x' ), 'x' ), 'no match: untouched' );
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'iframe', array( 'src' => 'hero-banner' ), 'x' ), 'only <img> is considered' );
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'hero-banner.jpg', 'fetchpriority' => 'low' ), 'x' ), 'explicit fetchpriority in markup wins' );
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'hero-banner.jpg', 'loading' => 'lazy' ), 'x' ), 'explicit loading in markup wins' );
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', 'not-an-array', 'x' ), 'non-array attributes are ignored' );
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => array( 'hero-banner' ) ), 'x' ), 'non-scalar attribute values are ignored' );
$GLOBALS['opts']['lcp_image_keywords'] = "\n \n";
t_ok( $core === BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => 'anything.jpg' ), 'x' ), 'blank keywords never match everything' );
$GLOBALS['opts']['lcp_image_keywords'] = 'hero';
reset_lcp();
$boosted = 0;
for ( $i = 0; $i < 5; $i++ ) {
	$o = BEPLUSPB_Resource_Hints::filter_lcp_attributes( $core, 'img', array( 'src' => "hero-$i.jpg" ), 'x' );
	$boosted += isset( $o['fetchpriority'] ) ? 1 : 0;
}
t_ok( BEPLUSPB_Resource_Hints::MAX_LCP_PER_PAGE === $boosted, 'at most ' . BEPLUSPB_Resource_Hints::MAX_LCP_PER_PAGE . ' images are boosted per page (got ' . $boosted . ')' );
reset_lcp();
$o = BEPLUSPB_Resource_Hints::filter_lcp_attributes( array( 'loading' => 'lazy', 'fetchpriority' => 'low' ), 'img', array( 'src' => 'hero.jpg' ), 'x' );
t_ok( 'high' === $o['fetchpriority'] && ! isset( $o['loading'] ), 'core-chosen fetchpriority/loading are replaced for a matched image' );

// --- keyword storage ---------------------------------------------------------------------
t_ok( "hero-banner\nslider" === BEPLUSPB_Resource_Hints::sanitize_keyword_list( "hero-banner\r\n\r\nhero-banner\n<b>x</b>\nsl\"ider\nslider" ), 'keywords: trimmed, de-duplicated, markup/quote lines dropped' );
t_ok( 10 === count( explode( "\n", BEPLUSPB_Resource_Hints::sanitize_keyword_list( implode( "\n", range( 1, 30 ) ) ) ) ), 'keywords capped at 10' );
t_ok( '' === BEPLUSPB_Resource_Hints::sanitize_keyword_list( str_repeat( 'x', 300 ) ), 'over-long keyword dropped' );

// --- admin: save round trip + rendered card ----------------------------------------------------
$s = BEPLUSPB_Admin::sanitize_options( array(
	'preconnect_hosts'   => "https://CDN.example.com/path\njavascript:alert(1)\nfonts.gstatic.com crossorigin",
	'dns_prefetch_hosts' => "www.google-analytics.com\n127.0.0.1",
	'lcp_image_keywords' => "hero-banner\n<script>",
) );
t_ok( "https://cdn.example.com\nhttps://fonts.gstatic.com crossorigin" === $s['preconnect_hosts'], 'save: preconnect sanitised' );
t_ok( 'https://www.google-analytics.com' === $s['dns_prefetch_hosts'], 'save: dns-prefetch sanitised' );
t_ok( 'hero-banner' === $s['lcp_image_keywords'], 'save: keywords sanitised' );
$s = BEPLUSPB_Admin::sanitize_options( array() );
t_ok( '' === $s['preconnect_hosts'] && '' === $s['dns_prefetch_hosts'] && '' === $s['lcp_image_keywords'], 'save: missing fields become empty' );

$m = new ReflectionMethod( 'BEPLUSPB_Admin', 'render_section_resource_hints' ); $m->setAccessible( true );
$GLOBALS['opts'] = array( 'preconnect_hosts' => "https://cdn.example.com\nhttps://fonts.gstatic.com crossorigin", 'dns_prefetch_hosts' => 'https://www.google-analytics.com', 'lcp_image_keywords' => 'hero-banner' );
ob_start(); $m->invoke( null, $GLOBALS['opts'] ); $html = ob_get_clean();
foreach ( array( 'preconnect_hosts', 'dns_prefetch_hosts', 'lcp_image_keywords' ) as $f ) {
	t_ok( false !== strpos( $html, 'name="bepluspb_settings[' . $f . ']"' ), "card has field $f" );
}
t_ok( false !== strpos( $html, 'https://fonts.gstatic.com crossorigin</textarea>' ) && false !== strpos( $html, '>hero-banner</textarea>' ), 'card shows saved values' );

echo "PASS: $n resource hints assertions\n";
