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

// ---- srcset / sizes hints --------------------------------------------------------------
$h = $P::sanitize_hint( '/u/h-480.jpg 480w,/u/h-960.jpg 960w, https://cdn.ex.com/u/h-1440.jpg 1440w', '(max-width: 600px) 100vw, 50vw' );
t_ok( '/u/h-480.jpg 480w, /u/h-960.jpg 960w, https://cdn.ex.com/u/h-1440.jpg 1440w' === $h['srcset'] && '(max-width: 600px) 100vw, 50vw' === $h['sizes'], 'srcset is normalised, sizes kept' );
$h = $P::sanitize_hint( "/u/a.jpg 1x, /u/a-2x.jpg 2x,\n/u/a-3x.jpg 3x", '' );
t_ok( '/u/a.jpg 1x, /u/a-2x.jpg 2x, /u/a-3x.jpg 3x' === $h['srcset'] && '' === $h['sizes'], 'density descriptors and newlines accepted' );
t_ok( '/u/a.jpg' === $P::sanitize_hint( '/u/a.jpg', '' )['srcset'], 'a candidate without descriptor is valid' );
t_ok( '/u/w,200.jpg 200w' === $P::sanitize_hint( '/u/w,200.jpg 200w', '' )['srcset'], 'a comma inside the URL is not a separator (browser tokenising)' );
foreach ( array(
	'/u/a.jpg 480w, javascript:alert(1) 960w' => 'one bad candidate',
	'/u/a.php 480w'                           => 'non-image candidate',
	'/u/a.jpg 480'                            => 'descriptor without unit',
	'/u/a.jpg 480w 2x'                        => 'two descriptors',
	'/u/a.jpg 12345678w'                      => 'huge descriptor',
	'/u/a".jpg 480w'                          => 'quote in candidate',
	'//evil.test/a.jpg 480w'                  => 'protocol-relative candidate',
	str_repeat( '/u/a.jpg 1x, ', 13 )         => 'more than 12 candidates',
	str_repeat( 'a', 5000 )                   => 'oversized',
	''                                        => 'empty',
) as $bad_srcset => $why ) {
	t_ok( '' === $P::sanitize_hint( (string) $bad_srcset, '100vw' )['srcset'], "srcset rejected: $why" );
}
t_ok( '' === $P::sanitize_hint( '/u/a.jpg 480w', '100vw" onload="x' )['sizes'] && '' === $P::sanitize_hint( '/u/a.jpg 480w', '<b>' )['sizes'] && '' === $P::sanitize_hint( '/u/a.jpg 480w', str_repeat( '1', 301 ) )['sizes'], 'sizes: quotes, tags and oversize rejected' );
t_ok( '' === $P::sanitize_hint( array( 'x' ), null )['srcset'], 'non-string input is harmless' );
t_ok( '' === $P::sanitize_hint( '', '100vw' )['sizes'], 'sizes without a usable srcset is dropped' );

// entry id changes with the line
$e1 = $P::parse( '/a.jpg | home' )['entries'][0]; $e2 = $P::parse( '/a.jpg | home | mobile' )['entries'][0]; $e3 = $P::parse( '/a.jpg | /about/' )['entries'][0];
t_ok( 3 === count( array_unique( array( $P::entry_id( $e1 ), $P::entry_id( $e2 ), $P::entry_id( $e3 ) ) ) ) && 16 === strlen( $P::entry_id( $e1 ) ), 'entry id depends on url, scope and device' );

// output with a hint attached through the option
$id = $P::entry_id( $e1 );
$_SERVER['REQUEST_URI'] = '/'; $GLOBALS['hooks'] = array(); $GLOBALS['is_front'] = true;
BEPLUSPB_Priority_Images::init( array( 'priority_images' => '/a.jpg | home', 'priority_images_hints' => array( $id => array( 'srcset' => '/a-480.jpg 480w, /a-960.jpg 960w', 'sizes' => '100vw' ) ) ) );
$out = head();
t_ok( false !== strpos( $out, 'href="/a.jpg" imagesrcset="/a-480.jpg 480w, /a-960.jpg 960w" imagesizes="100vw" type="image/jpeg" fetchpriority="high"' ), 'preload carries imagesrcset and imagesizes next to the fallback href' );
BEPLUSPB_Priority_Images::init( array( 'priority_images' => '/a.jpg | home', 'priority_images_hints' => array( $id => array( 'srcset' => '/a-480.jpg 480w, "><script>', 'sizes' => '100vw' ) ) ) );
t_ok( false === strpos( head(), 'imagesrcset' ) && false === strpos( head(), '<script>' ), 'a tampered stored hint is re-validated and not printed' );
BEPLUSPB_Priority_Images::init( array( 'priority_images' => '/a.jpg | home', 'priority_images_hints' => array( 'other-id' => array( 'srcset' => '/x.jpg 1x' ) ) ) );
t_ok( false === strpos( head(), 'imagesrcset' ), 'a hint of another line is not applied' );
BEPLUSPB_Priority_Images::init( array( 'priority_images' => '/a.jpg | home', 'priority_images_hints' => 'garbage' ) );
t_ok( false !== strpos( head(), 'href="/a.jpg"' ), 'non-array hints option is ignored' );

// sanitize_hints keeps only hints of lines that remain
$text  = $P::sanitize( "/a.jpg | home\n/b.jpg | /about/" );
$ida   = $P::entry_id( $P::parse( $text )['entries'][0] );
$kept  = $P::sanitize_hints( array( $ida => array( 'srcset' => '/a-1.jpg 1x', 'sizes' => '' ), 'gone' => array( 'srcset' => '/z.jpg 1x' ), 'bad' => 'x' ), $text );
t_ok( array_keys( $kept ) === array( $ida ), 'stale and malformed hints are pruned' );
t_ok( array() === $P::sanitize_hints( 'x', $text ), 'non-array hints give an empty set' );

// ---- apply_entries (what the LCP Analyzer saves) ----------------------------------------
$r = $P::apply_entries( '', array(), array( array( 'url' => '/h.jpg', 'scope' => 'home', 'device' => 'all', 'srcset' => '/h-1.jpg 1x', 'sizes' => '' ) ) );
t_ok( '/h.jpg | home' === $r['text'] && 1 === $r['count'] && 1 === count( $r['hints'] ), 'first analysis is added, with its hint' );
$r2 = $P::apply_entries( $r['text'], $r['hints'], array(
	array( 'url' => '/m.jpg', 'scope' => 'home', 'device' => 'mobile' ),
	array( 'url' => '/d.jpg', 'scope' => 'home', 'device' => 'desktop', 'srcset' => '/d-1.jpg 1x' ),
) );
t_ok( "/m.jpg | home | mobile\n/d.jpg | home | desktop" === $r2['text'], 're-analysing the same page replaces its previous lines' );
t_ok( 1 === count( $r2['hints'] ) && ! isset( $r2['hints'][ $P::entry_id( $P::parse( '/h.jpg | home' )['entries'][0] ) ] ), 'the replaced line loses its hint, the new one has one' );
$r3 = $P::apply_entries( $r2['text'], $r2['hints'], array( array( 'url' => '/about.jpg', 'scope' => '/about/', 'device' => 'all' ) ) );
t_ok( "/m.jpg | home | mobile\n/d.jpg | home | desktop\n/about.jpg | /about/" === $r3['text'] && 1 === count( $r3['hints'] ), 'another page is added, other pages and their hints are kept' );
$r4 = $P::apply_entries( $r3['text'], $r3['hints'], array( array( 'url' => '/x.jpg', 'scope' => '/x/', 'device' => 'all' ), array( 'url' => '/y.jpg', 'scope' => '/x/', 'device' => 'mobile' ) ) );
t_ok( isset( $r4['error'] ), 'a result that would exceed 4 lines is refused, nothing is half-saved' );
t_ok( isset( $P::apply_entries( '', array(), array() )['error'] ) && isset( $P::apply_entries( '', array(), 'x' )['error'] ), 'empty / non-array payload refused' );
t_ok( isset( $P::apply_entries( '', array(), array( 'x' ) )['error'] ), 'non-array row refused' );
$many = array(); for ( $i = 0; $i < 5; $i++ ) { $many[] = array( 'url' => "/i$i.jpg", 'scope' => 'all', 'device' => 'all' ); }
t_ok( isset( $P::apply_entries( '', array(), $many )['error'] ), 'more than 4 incoming rows refused' );
$bad_rows = array(
	'newline in url'   => array( 'url' => "/a.jpg\n/evil.jpg", 'scope' => 'home' ),
	'pipe in url'      => array( 'url' => '/a.jpg | all', 'scope' => 'home' ),
	'newline in scope' => array( 'url' => '/a.jpg', 'scope' => "home\n/b.jpg | all" ),
	'pipe in scope'    => array( 'url' => '/a.jpg', 'scope' => 'home | mobile' ),
	'bad scheme'       => array( 'url' => 'javascript:alert(1)//a.jpg', 'scope' => 'home' ),
	'not an image'     => array( 'url' => '/a.php', 'scope' => 'home' ),
	'bad scope'        => array( 'url' => '/a.jpg', 'scope' => 'nowhere' ),
	'bad device'       => array( 'url' => '/a.jpg', 'scope' => 'home', 'device' => 'tablet' ),
	'empty url'        => array( 'url' => '', 'scope' => 'home' ),
	'missing url'      => array( 'scope' => 'home' ),
	'array url'        => array( 'url' => array( '/a.jpg' ), 'scope' => 'home' ),
);
foreach ( $bad_rows as $why => $row ) {
	$res = $P::apply_entries( '/keep.jpg | /keep/', array(), array( $row ) );
	t_ok( isset( $res['error'] ) && ! isset( $res['text'] ), "apply refused: $why" );
}
$res = $P::apply_entries( '/keep.jpg | /keep/', array(), array( array( 'url' => '/a.jpg', 'scope' => 'home' ), array( 'url' => '/a.jpg', 'scope' => 'home' ) ) );
t_ok( "/keep.jpg | /keep/\n/a.jpg | home" === $res['text'], 'an identical row sent twice is stored once' );
$res = $P::apply_entries( '/keep.jpg | /Keep/', array(), array( array( 'url' => '/n.jpg', 'scope' => '/keep/' ) ) );
t_ok( '/n.jpg | /keep/' === $res['text'], 'scopes are compared case-insensitively when replacing' );
$_SERVER['REQUEST_URI'] = '/Shop/Hats/?x=1';
t_ok( '/shop/hats/' === $P::current_path(), 'current_path() is public: decoded, lower-cased, without the query' );

echo "OK ($n assertions)\n";
