<?php
/**
 * Behavioral tests for Minify (url_to_path, minify_css/js, filter output) and CDN rewriting.
 *
 * Unlike the contract tests, these execute the code against a real temporary
 * filesystem tree. Run: php -n tests/test-minify-cdn-behavior.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-behavior-' . bin2hex( random_bytes( 5 ) );
mkdir( "$root/wp/wp-content/themes/t", 0777, true ); mkdir( "$root/wp/wp-content/uploads/2026", 0777, true ); mkdir( "$root/wp/wp-includes", 0777, true ); mkdir( "$root/outside", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', "$root/wp/" );
define( 'WP_CONTENT_DIR', "$root/wp/wp-content" );
define( 'WP_CONTENT_URL', 'http://example.test/wp-content' );
define( 'BEPLUSPB_CACHE_DIR', "$root/wp/wp-content/uploads/bepluspb-cache/" );

// --- WordPress stubs -------------------------------------------------------
function wp_normalize_path( $p ) { return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $p ) ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }
function site_url() { return 'http://example.test'; }
function home_url() { return 'http://example.test'; }
function content_url() { return 'http://example.test/wp-content'; }
function includes_url() { return 'http://example.test/wp-includes/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_filter() {} function add_action() {}
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $n ); }
function wp_upload_dir() { return array( 'baseurl' => 'http://example.test/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); }
function wp_is_writable( $p ) { return is_writable( $p ); }
function is_user_logged_in() { return false; }
function is_admin() { return false; }
function get_queried_object_id() { return 0; } function get_post_meta() { return ''; } function sanitize_text_field( $s ) { return trim( (string) $s ); } function wp_unslash( $s ) { return $s; }
function bepluspb_get_options() { return array( 'cache_for_logged_in' => 1, 'cache_enabled' => 1, 'cache_exclude_pages' => '', 'css_exclude' => '' ); }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }

require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-cdn.php';

// --- Fixtures --------------------------------------------------------------
file_put_contents( "$root/wp/wp-config.php", "<?php define('DB_PASSWORD','TOP-SECRET');" );
file_put_contents( "$root/outside/secret.css", 'body{--k:OUTSIDE-SECRET}' );
file_put_contents( "$root/wp/wp-content/themes/t/style.css", "/*! keep-license */\n/* drop me */\nbody {\n  color : red ;\n}\n.a   >   .b { margin: 0 ; }\n" );
file_put_contents( "$root/wp/wp-content/themes/t/app.js", "// line comment\nvar u = \"http://x.test/a\"; // trailing\n/* block */\nvar r = /\\/\\/not-comment/; var t = `//tpl \${1}`;\n" );
file_put_contents( "$root/wp/wp-content/themes/t/ok.min.css", 'a{b:c}' );
file_put_contents( "$root/wp/wp-content/uploads/2026/photo.jpg", 'j' ); file_put_contents( "$root/wp/wp-content/uploads/2026/photo.webp", 'w' ); file_put_contents( "$root/wp/wp-content/uploads/2026/photo.avif", 'a' );
file_put_contents( "$root/wp/wp-content/uploads/2026/lonely.jpg", 'j' );
@symlink( "$root/wp/wp-config.php", "$root/wp/wp-content/themes/t/evil.css" );   // in-ABSPATH target, .css name
@symlink( "$root/outside/secret.css", "$root/wp/wp-content/themes/t/escape.css" ); // outside ABSPATH
$u = 'http://example.test/wp-content/themes/t/';

// --- url_to_path -----------------------------------------------------------
t_ok( realpath( "$root/wp/wp-content/themes/t/style.css" ) === BEPLUSPB_Minify::url_to_path( $u . 'style.css?ver=1' ), 'valid css resolves, query stripped' );
t_ok( false === BEPLUSPB_Minify::url_to_path( 'http://example.test/wp-config.php' ), 'wp-config.php rejected (extension allowlist)' );
t_ok( false === BEPLUSPB_Minify::url_to_path( 'http://example.test/wp-content/../wp-config.php.js' ), 'traversal to non-existent rejected' );
t_ok( false === BEPLUSPB_Minify::url_to_path( 'http://example.test/wp-content/themes/t/../../../../outside/secret.css' ), 'traversal outside ABSPATH rejected' );
t_ok( false === BEPLUSPB_Minify::url_to_path( $u . 'escape.css' ), 'symlink escaping ABSPATH rejected' );
t_ok( false === BEPLUSPB_Minify::url_to_path( $u . 'evil.css' ), 'symlink named .css that resolves to wp-config.php rejected' );
t_ok( false === BEPLUSPB_Minify::url_to_path( 'http://other.test/wp-content/themes/t/style.css' ), 'foreign host rejected' );

// --- minify_css ------------------------------------------------------------
$css = BEPLUSPB_Minify::minify_css( file_get_contents( "$root/wp/wp-content/themes/t/style.css" ) );
t_ok( false !== strpos( $css, 'keep-license' ), 'license comment preserved' );
t_ok( false === strpos( $css, 'drop me' ), 'normal comment removed' );
t_ok( false !== strpos( $css, 'body{color:red}' ), 'declarations compacted: ' . $css );
t_ok( false !== strpos( $css, '.a>.b{margin:0}' ), 'child combinator compacted' );
// Descendant combinator before a pseudo-class must keep its meaning (".a :hover" != ".a:hover").
t_ok( false !== strpos( BEPLUSPB_Minify::minify_css( '.a :hover { x: y }' ), '.a :hover' ), 'descendant-pseudo selector space preserved: ' . BEPLUSPB_Minify::minify_css( '.a :hover { x: y }' ) );
t_ok( false !== strpos( BEPLUSPB_Minify::minify_css( 'a::after { content: "a ; b" }' ), '"a ; b"' ), 'string contents untouched: ' . BEPLUSPB_Minify::minify_css( 'a::after { content: "a ; b" }' ) );
t_ok( false !== strpos( BEPLUSPB_Minify::minify_css( '.x { width: calc( 1px + 2px ) }' ), 'calc(1px + 2px)' ) || false !== strpos( BEPLUSPB_Minify::minify_css( '.x { width: calc( 1px + 2px ) }' ), 'calc( 1px + 2px )' ), 'calc() operators keep spaces' );

// --- minify_js -------------------------------------------------------------
$js = BEPLUSPB_Minify::minify_js( file_get_contents( "$root/wp/wp-content/themes/t/app.js" ) );
t_ok( false === strpos( $js, 'line comment' ) && false === strpos( $js, 'block' ) && false === strpos( $js, 'trailing' ), 'JS comments stripped: ' . $js );
t_ok( false !== strpos( $js, '"http://x.test/a"' ), 'URL inside string preserved' );
t_ok( false !== strpos( $js, '/\\/\\/not-comment/' ), 'regex literal with // preserved: ' . $js );
t_ok( false !== strpos( $js, '`//tpl ${1}`' ), 'template literal with // preserved' );

// --- filters end to end ----------------------------------------------------
$out = BEPLUSPB_Minify::maybe_minify_css( $u . 'style.css?ver=9', 'theme-style' );
t_ok( 0 === strpos( $out, 'http://example.test/wp-content/uploads/bepluspb-cache/theme-style-' ) && '?ver=9' === substr( $out, -6 ), 'css src rewritten to cache URL keeping query: ' . $out );
$cached = BEPLUSPB_CACHE_DIR . basename( strtok( $out, '?' ) );
t_ok( is_file( $cached ) && false === strpos( file_get_contents( $cached ), 'drop me' ), 'cache file written and minified' );
t_ok( $u . 'ok.min.css' === BEPLUSPB_Minify::maybe_minify_css( $u . 'ok.min.css', 'm' ), '.min.css skipped' );
t_ok( 'http://example.test/wp-config.php' === BEPLUSPB_Minify::maybe_minify_css( 'http://example.test/wp-config.php', 'x' ), 'wp-config.php never minified/cached' );
t_ok( $u . 'evil.css' === BEPLUSPB_Minify::maybe_minify_css( $u . 'evil.css', 'evil' ), 'evil symlink src returned unchanged' );
foreach ( glob( BEPLUSPB_CACHE_DIR . '*' ) as $f ) { t_ok( false === strpos( (string) file_get_contents( $f ), 'TOP-SECRET' ), 'no secret leaked into cache dir' ); }
$jout = BEPLUSPB_Minify::maybe_minify_js( $u . 'app.js', 'theme-app' );
t_ok( false !== strpos( $jout, '/bepluspb-cache/theme-app-' ), 'js src rewritten' );

// --- CDN -------------------------------------------------------------------
BEPLUSPB_CDN::init( array( 'cdn_enabled' => 1, 'cdn_url' => 'cdn.example.net', 'cdn_exclude' => "skip-me\n", 'cdn_file_types' => '', 'cdn_webp_avif' => 0 ) );
t_ok( 'https://cdn.example.net/wp-content/uploads/2026/photo.jpg?x=1' === BEPLUSPB_CDN::rewrite_url( 'http://example.test/wp-content/uploads/2026/photo.jpg?x=1' ), 'local asset rewritten to CDN, query kept' );
t_ok( 'http://example.test/page.php' === BEPLUSPB_CDN::rewrite_url( 'http://example.test/page.php' ), 'non-asset extension untouched' );
t_ok( 'http://evil.test/a.jpg' === BEPLUSPB_CDN::rewrite_url( 'http://evil.test/a.jpg' ), 'external host untouched' );
t_ok( 'http://example.test/wp-content/skip-me/a.jpg' === BEPLUSPB_CDN::rewrite_url( 'http://example.test/wp-content/skip-me/a.jpg' ), 'excluded keyword untouched' );
t_ok( 'data:image/png;base64,AAA' === BEPLUSPB_CDN::rewrite_url( 'data:image/png;base64,AAA' ), 'data: URI untouched' );
$h = BEPLUSPB_CDN::rewrite_html( '<img src="http://example.test/wp-content/uploads/2026/photo.jpg"><a href="http://example.test/post/">p</a>' );
t_ok( false !== strpos( $h, 'https://cdn.example.net/wp-content/uploads/2026/photo.jpg' ) && false !== strpos( $h, 'href="http://example.test/post/"' ), 'HTML rewrite touches assets, not links: ' . $h );
$s = BEPLUSPB_CDN::rewrite_srcset( array( 300 => array( 'url' => 'http://example.test/wp-content/uploads/2026/photo.jpg', 'descriptor' => 'w' ) ) );
t_ok( 0 === strpos( $s[300]['url'], 'https://cdn.example.net/' ), 'srcset rewritten' );

// WebP/AVIF swap (class caches Accept per request via init() reset).
function cdn_with_accept( $accept ) { $_SERVER['HTTP_ACCEPT'] = $accept; BEPLUSPB_CDN::init( array( 'cdn_enabled' => 1, 'cdn_url' => 'cdn.example.net', 'cdn_exclude' => '', 'cdn_file_types' => '', 'cdn_webp_avif' => 1 ) ); }
$pj = 'http://example.test/wp-content/uploads/2026/photo.jpg'; $lj = 'http://example.test/wp-content/uploads/2026/lonely.jpg';
cdn_with_accept( 'image/avif,image/webp,*/*' ); t_ok( substr( BEPLUSPB_CDN::rewrite_url( $pj ), -10 ) === 'photo.avif', 'AVIF preferred when accepted + sibling exists' );
cdn_with_accept( 'image/webp,*/*' );             t_ok( substr( BEPLUSPB_CDN::rewrite_url( $pj ), -10 ) === 'photo.webp', 'WebP when only WebP accepted' );
cdn_with_accept( 'text/html' );                  t_ok( substr( BEPLUSPB_CDN::rewrite_url( $pj ), -9 ) === 'photo.jpg', 'no swap when browser does not accept modern formats' );
cdn_with_accept( 'image/avif,image/webp' );      t_ok( substr( BEPLUSPB_CDN::rewrite_url( $lj ), -10 ) === 'lonely.jpg', 'no swap when sibling file does not exist' );

echo "PASS: $n minify/CDN behavior assertions\n";
