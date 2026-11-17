<?php
/**
 * Priority Images: parsing, page scoping, preload output and the <img> priority filter.
 * Run: php -n tests/test-priority-images.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hooks'] = array();
$GLOBALS['wp_version'] = '6.5';
$GLOBALS['is_front'] = false;
$GLOBALS['excluded'] = false;
$GLOBALS['home'] = 'https://ex.com';
function __( $s ) { return $s; }
function add_action( $tag, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $prio ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_unslash( $v ) { return $v; }
function home_url() { return $GLOBALS['home']; }
function is_front_page() { return $GLOBALS['is_front']; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function esc_url( $u ) { return str_replace( array( '"', "'", '<', '>' ), '', $u ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
class BEPLUSPB_Utils { public static function is_buffer_excluded_request() { return $GLOBALS['excluded']; } }
require dirname( __DIR__ ) . '/includes/class-bepluspb-priority-images.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$P = 'BEPLUSPB_Priority_Images';
function boot( $raw, $uri = '/', $front = false, $version = '6.5' ) {
	$GLOBALS['hooks'] = array(); $GLOBALS['is_front'] = $front; $GLOBALS['wp_version'] = $version;
	$_SERVER['REQUEST_URI'] = $uri;
	BEPLUSPB_Priority_Images::init( array( 'priority_images' => $raw ) );
}
function head() { ob_start(); BEPLUSPB_Priority_Images::output_preload(); return ob_get_clean(); }
function filt( $src, $attrs = array( 'loading' => 'lazy', 'decoding' => 'async' ) ) {
	$cb = $GLOBALS['hooks']['wp_get_loading_optimization_attributes'][0][0] ?? null;
	return $cb ? call_user_func( $cb, $attrs, 'img', array( 'src' => $src ), 'the_content' ) : $attrs;
}

// ---- parsing ---------------------------------------------------------------------------
$r = $P::parse( "/wp-content/uploads/hero.jpg | home\nhttps://cdn.ex.com/a/b.webp?v=2 | /about/ | mobile\n\n  /x/y.PNG | all | desktop  " );
t_ok( 3 === count( $r['entries'] ) && array() === $r['errors'], 'three valid lines parse' );
t_ok( 'image/webp' === $r['entries'][1]['type'] && 'mobile' === $r['entries'][1]['device'] && '/about/' === $r['entries'][1]['scope'], 'type from extension, device and scope kept' );
t_ok( 'image/png' === $r['entries'][2]['type'] && '/x/y.png' === $r['entries'][2]['key'], 'extension is case-insensitive and the match key is lower-cased' );
t_ok( 'all' === $r['entries'][0]['device'], 'device defaults to all' );
t_ok( "/wp-content/uploads/hero.jpg | home\nhttps://cdn.ex.com/a/b.webp?v=2 | /about/ | mobile\n/x/y.PNG | all | desktop" === $P::sanitize( "/wp-content/uploads/hero.jpg|home\n https://cdn.ex.com/a/b.webp?v=2 |/about/| mobile\n/x/y.PNG|ALL|Desktop" ), 'sanitize() writes the canonical form' );

$bad = array(
	'/a.jpg'                                        => 'scope missing',
	'/a.jpg | nowhere'                              => 'scope must be home/all/path',
	'/a.jpg | /p/ | tablet'                         => 'unknown device',
	'/a.jpg | /p/ | mobile | extra'                 => 'too many fields',
	'a.jpg | home'                                  => 'relative URL',
	'//evil.com/a.jpg | home'                       => 'protocol-relative URL',
	'javascript:alert(1)//a.jpg | home'             => 'javascript scheme',
	'data:image/png;base64,AAAA | home'             => 'data URI',
	'ftp://ex.com/a.jpg | home'                     => 'non-http scheme',
	'https://user:pw@ex.com/a.jpg | home'           => 'credentials',
	'/a.jpg#frag | home'                            => 'fragment',
	'/a.php | home'                                 => 'extension not an image',
	'/a | home'                                     => 'no extension',
	'/a"onload="x.jpg | home'                       => 'quote in URL',
	'/a<b>.jpg | home'                              => 'angle bracket in URL',
	"/a b.jpg | home"                               => 'whitespace in URL',
	'/a.jpg | /p"x/'                                => 'quote in scope',
	'/a.jpg | /p?x=1'                               => 'query string in scope',
	'/a.jpg | p/'                                   => 'scope without leading slash',
	'/' . str_repeat( 'a', 2100 ) . '.jpg | home'   => 'URL too long',
);
foreach ( $bad as $line => $why ) {
	$r = $P::parse( $line );
	t_ok( array() === $r['entries'] && 1 === count( $r['errors'] ), "rejected: $why" );
}
$many = '';
for ( $i = 1; $i <= 7; $i++ ) { $many .= "/i$i.jpg | home\n"; }
$r = $P::parse( $many );
t_ok( 4 === count( $r['entries'] ) && 3 === count( $r['errors'] ), 'at most 4 entries, the rest reported' );
t_ok( array( 'entries' => array(), 'errors' => array() ) === $P::parse( '' ) && array( 'entries' => array(), 'errors' => array() ) === $P::parse( array( 'x' ) ), 'empty and non-string input are harmless' );

// ---- match key -------------------------------------------------------------------------
t_ok( '/wp-content/uploads/2026/hero.jpg' === $P::match_key( '/wp-content/uploads/2026/Hero-1024x683.jpg' ), 'size suffix removed' );
t_ok( '/u/hero.jpg' === $P::match_key( '/u/hero-scaled.jpg' ), '-scaled removed' );
t_ok( '/u/hero-2.jpg' === $P::match_key( '/u/hero-2.jpg' ), 'a plain "-2" is not a size' );
t_ok( '/u/שלום.jpg' === $P::match_key( '/u/%D7%A9%D7%9C%D7%95%D7%9D.jpg' ), 'percent-encoded (non-latin) names are decoded' );

// ---- scope -----------------------------------------------------------------------------
t_ok( $P::scope_matches( 'all', '/anything/', false ), 'all matches everywhere' );
t_ok( $P::scope_matches( 'home', '/', true ) && ! $P::scope_matches( 'home', '/', false ), 'home follows is_front_page()' );
t_ok( $P::scope_matches( '/about/', '/about/', false ) && $P::scope_matches( '/about', '/about/', false ) && $P::scope_matches( '/About/', '/about', false ), 'exact path, trailing slash and case ignored' );
t_ok( ! $P::scope_matches( '/about/', '/about/team/', false ), 'an exact path does not match children' );
t_ok( $P::scope_matches( '/shop/*', '/shop/hats/red/', false ) && $P::scope_matches( '/shop/*', '/shop/', false ) && ! $P::scope_matches( '/shop/*', '/shopping/', false ), 'prefix with *' );
t_ok( $P::scope_matches( '/*', '/whatever/', false ), '/* matches every path' );
t_ok( $P::scope_matches( '/אודות/', '/אודות/', false ) && $P::scope_matches( '/אודות/', rawurldecode( '/%D7%90%D7%95%D7%93%D7%95%D7%AA/' ), false ), 'non-latin page paths match' );

// ---- preload output --------------------------------------------------------------------
boot( "/wp-content/uploads/hero.jpg | home\n/wp-content/uploads/m.webp | home | mobile\n/wp-content/uploads/about.png | /about/", '/', true );
t_ok( isset( $GLOBALS['hooks']['wp_head'] ) && 1 === $GLOBALS['hooks']['wp_head'][0][1], 'preload printed at wp_head priority 1' );
$out = head();
t_ok( 2 === substr_count( $out, '<link rel="preload" as="image"' ) && 2 === substr_count( $out, 'fetchpriority="high"' ), 'home page: the two home entries, not the /about/ one' );
t_ok( false !== strpos( $out, 'href="/wp-content/uploads/hero.jpg" type="image/jpeg" fetchpriority="high" data-bepluspb-priority-image="1"' ), 'desktop+mobile entry has no media attribute' );
t_ok( false !== strpos( $out, 'type="image/webp" fetchpriority="high" media="(max-width: 767px)"' ), 'mobile entry carries a max-width media query' );
t_ok( false === strpos( $out, 'about.png' ), 'entries for other pages are not printed' );

boot( "/a.jpg | /about/ | desktop", '/about/' );
t_ok( false !== strpos( head(), 'media="(min-width: 768px)"' ), 'desktop entry carries a min-width media query' );
boot( "/a.jpg | /about/", '/about/?utm_source=x' );
t_ok( false !== strpos( head(), 'href="/a.jpg"' ), 'query string of the request is ignored when matching the path' );
boot( "/a.jpg | /about/", '/contact/' );
t_ok( '' === head(), 'nothing printed on other pages' );
boot( '/a.jpg | all', '/%D7%90/' );
t_ok( false !== strpos( head(), 'a.jpg' ), 'encoded request paths do not break matching' );

// Sub-directory install: scope is relative to the site root.
$GLOBALS['home'] = 'https://ex.com/blog';
boot( '/a.jpg | /about/', '/blog/about/' );
t_ok( false !== strpos( head(), 'a.jpg' ), 'sub-directory install: path is relative to the site root' );
$GLOBALS['home'] = 'https://ex.com';

// Escaping: a hostile-looking URL that slipped through can never break out of the attribute.
boot( "https://cdn.ex.com/a.jpg?x=1&y=2 | home", '/', true );
t_ok( false === strpos( head(), '"onload' ), 'output stays inside the href attribute' );

// ---- hooks gating ----------------------------------------------------------------------
boot( '', '/' );
t_ok( array() === $GLOBALS['hooks'], 'empty option registers nothing' );
$GLOBALS['excluded'] = true; boot( '/a.jpg | all', '/' ); $GLOBALS['excluded'] = false;
t_ok( array() === $GLOBALS['hooks'], 'builder/REST/preview requests register nothing' );
boot( '/a.jpg | all', '/', false, '6.3' );
t_ok( isset( $GLOBALS['hooks']['wp_head'] ) && ! isset( $GLOBALS['hooks']['wp_get_loading_optimization_attributes'] ), 'older core: preload only, no attributes filter' );
boot( '/a.jpg | all', '/', false, '6.4' );
t_ok( 20 === $GLOBALS['hooks']['wp_get_loading_optimization_attributes'][0][1], 'attributes filter runs after Lazy Load v2 (priority 20)' );

// ---- <img> filter ----------------------------------------------------------------------
boot( "/wp-content/uploads/2026/hero.jpg | home", '/', true );
$o = filt( 'https://ex.com/wp-content/uploads/2026/hero-1024x683.jpg?ver=2' );
t_ok( 'high' === $o['fetchpriority'] && ! isset( $o['loading'] ) && 'async' === $o['decoding'], 'matching image: fetchpriority=high, lazy removed, other attributes kept' );
$o = filt( 'https://cdn.other.net/wp-content/uploads/2026/hero.jpg' );
t_ok( 'high' === $o['fetchpriority'], 'matches on the path, so CDN-rewritten or resized copies are recognised' );
$o = filt( 'https://ex.com/wp-content/uploads/2026/other.jpg' );
t_ok( 'lazy' === $o['loading'] && ! isset( $o['fetchpriority'] ), 'other images untouched' );
t_ok( array( 'loading' => 'lazy' ) === filt( '', array( 'loading' => 'lazy' ) ), 'empty src is ignored' );
$cb = $GLOBALS['hooks']['wp_get_loading_optimization_attributes'][0][0];
t_ok( array( 'x' => 1 ) === call_user_func( $cb, array( 'x' => 1 ), 'iframe', array( 'src' => '/wp-content/uploads/2026/hero.jpg' ), 'x' ), 'only <img> is handled' );
t_ok( false === call_user_func( $cb, false, 'img', array( 'src' => '/wp-content/uploads/2026/hero.jpg' ), 'x' ), 'a non-array result from another filter is returned untouched' );
boot( "/wp-content/uploads/2026/hero.jpg | /about/", '/contact/' );
t_ok( 'lazy' === filt( '/wp-content/uploads/2026/hero.jpg' )['loading'], 'no priority boost on pages the entry does not apply to' );

echo "OK ($n assertions)\n";
