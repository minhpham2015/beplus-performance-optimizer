<?php
/**
 * Standalone tests for the page cache engine (lib/class-bepluspb-page-cache-engine.php) and
 * the advanced-cache.php stub. No WordPress: the engine must work before it loads.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/bepluspb-pc-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/wp-content', 0755, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
require $root . '/lib/class-bepluspb-page-cache-engine.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function write_cfg( array $over = array() ) {
	$cfg = array_merge( array( 'enabled' => true, 'ttl' => 3600, 'hosts' => array( 'example.com', 'example.com:8080' ), 'exclude_paths' => array( '/members/', 'https://example.com/private-area' ), 'exclude_cookies' => array( 'my_session' ), 'accept_variants' => false ), $over );
	file_put_contents( WP_CONTENT_DIR . '/.bepluspb_pc.php', BEPLUSPB_Page_Cache_Engine::CONFIG_GUARD . json_encode( $cfg ) );
	BEPLUSPB_Page_Cache_Engine::reset();
}
function srv( $uri, array $extra = array() ) {
	return array_merge( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => $uri ), $extra );
}
function ctx( $uri, array $extra = array(), array $cookies = array() ) {
	return BEPLUSPB_Page_Cache_Engine::request_context( srv( $uri, $extra ), $cookies );
}
$E = 'BEPLUSPB_Page_Cache_Engine';

// ---- Config: fail closed ----------------------------------------------------
t_ok( null === ctx( '/' ), 'no config file => bypass' );
file_put_contents( WP_CONTENT_DIR . '/.bepluspb_pc.php', '{"enabled":true,"hosts":["example.com"]}' ); $E::reset();
t_ok( null === ctx( '/' ), 'config without exit guard is rejected' );
file_put_contents( WP_CONTENT_DIR . '/.bepluspb_pc.php', $E::CONFIG_GUARD . '{not json' ); $E::reset();
t_ok( null === ctx( '/' ), 'corrupt JSON => bypass' );
write_cfg( array( 'enabled' => false ) );
t_ok( null === ctx( '/' ), 'disabled config => bypass' );
write_cfg( array( 'hosts' => array() ) );
t_ok( null === ctx( '/' ), 'empty host allowlist => bypass' );
write_cfg();

// ---- Normal pages map to a confined file ------------------------------------
$c = ctx( '/' );
t_ok( $c && $c['file'] === $E::cache_root() . '/example.com/index.html', 'home page file' );
$c = ctx( '/blog/hello-world/' );
t_ok( $c && $c['file'] === $E::cache_root() . '/example.com/blog/hello-world/index.html', 'nested page file' );
t_ok( ctx( '/a/', array( 'HTTPS' => 'on' ) )['file'] === $E::cache_root() . '/example.com/a/index-s.html', 'https variant' );
t_ok( ctx( '/a/', array( 'SERVER_PORT' => '443' ) )['file'] === $E::cache_root() . '/example.com/a/index-s.html', 'port 443 is https' );
t_ok( ctx( '/a/', array( 'HTTP_HOST' => 'example.com:8080' ) )['dir'] === $E::cache_root() . '/example.com_8080/a', 'host with port uses a safe dir name' );
write_cfg( array( 'accept_variants' => true ) );
t_ok( ctx( '/a/', array( 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'image/avif,image/webp,*/*' ) )['file'] === $E::cache_root() . '/example.com/a/index-s-avif.html', 'avif variant preferred' );
t_ok( ctx( '/a/', array( 'HTTP_ACCEPT' => 'text/html,image/WEBP' ) )['file'] === $E::cache_root() . '/example.com/a/index-webp.html', 'webp variant' );
t_ok( ctx( '/a/', array( 'HTTP_ACCEPT' => 'text/html' ) )['file'] === $E::cache_root() . '/example.com/a/index.html', 'no image variant' );
write_cfg();
t_ok( ctx( '/a/', array( 'HTTP_ACCEPT' => 'image/avif' ) )['file'] === $E::cache_root() . '/example.com/a/index.html', 'Accept ignored when variants are off' );
t_ok( ctx( '/b%C3%A0i-vi%E1%BA%BFt/' )['dir'] === $E::cache_root() . '/example.com/b%c3%a0i-vi%e1%ba%bft', 'percent-encoded (Vietnamese) slugs are cacheable and hex is canonicalised' );
t_ok( ctx( '/b%c3%a0i/' )['file'] === ctx( '/b%C3%A0i/' )['file'], 'hex case does not create duplicate files' );

// ---- Request classes that must bypass ---------------------------------------
t_ok( null === ctx( '/', array( 'REQUEST_METHOD' => 'POST' ) ), 'POST bypass' );
t_ok( null === ctx( '/', array( 'REQUEST_METHOD' => 'HEAD' ) ), 'HEAD bypass' );
t_ok( null === ctx( '/', array( 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest' ) ), 'AJAX bypass' );
t_ok( null === ctx( '/', array( 'HTTP_AUTHORIZATION' => 'Basic abc' ) ), 'HTTP auth bypass' );
t_ok( null === ctx( '/', array( 'PHP_AUTH_USER' => 'u' ) ), 'PHP_AUTH_USER bypass' );
t_ok( null === ctx( '/', array( 'HTTP_HOST' => 'evil.test' ) ), 'unknown Host (cache poisoning / disk fill) bypass' );
t_ok( null === ctx( '/', array( 'HTTP_HOST' => 'example.com/../../x' ) ), 'malformed Host bypass' );
t_ok( null === ctx( '/', array( 'HTTP_HOST' => 'EXAMPLE.com.evil.test' ) ), 'look-alike Host bypass' );
t_ok( null !== ctx( '/', array( 'HTTP_HOST' => 'EXAMPLE.COM' ) ), 'Host is matched case-insensitively' );
t_ok( null === ctx( '/?s=test' ), 'search query bypass' );
t_ok( null === ctx( '/?p=1' ), 'query string bypass' );
t_ok( null === ctx( '/?utm_source=x&p=1' ), 'ignorable + real param still bypasses' );
t_ok( null !== ctx( '/?utm_source=x&utm_campaign=y&fbclid=abc' ), 'tracking-only query is served from the clean page' );
t_ok( ctx( '/x/?utm_source=a' )['file'] === ctx( '/x/' )['file'], 'tracking params share one cache file' );
foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/xmlrpc.php', '/wp-cron.php', '/feed/', '/blog/feed/', '/robots.txt', '/sitemap.xml', '/cart/', '/checkout/', '/my-account/orders/', '/members/area/', '/private-area/x/' ) as $p ) {
	t_ok( null === ctx( $p ), "bypass path $p" );
}
t_ok( null !== ctx( '/feedback-form/' ), '/feedback-form/ is not mistaken for a feed' );
foreach ( array( 'wordpress_logged_in_abc', 'wordpress_sec_abc', 'wp-postpass_abc', 'comment_author_abc', 'wp_woocommerce_session_abc', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'my_session_id' ) as $cookie ) {
	t_ok( null === ctx( '/', array(), array( $cookie => '1' ) ), "cookie $cookie bypasses" );
}
t_ok( null !== ctx( '/', array(), array( '_ga' => '1', 'PHPSESSID_not_ours' => 'x' ) ), 'analytics cookies do not bypass' );

// ---- Hostile paths never reach the filesystem -------------------------------
foreach ( array( '/../etc/passwd', '/a/../../b/', '/a/%2e%2e/b/', '/a/%2E%2E/', '/a%2fb/', '/a%5cb/', '/a%00b/', '/a//b/', '/a\\b/', '/.hidden/', '/a/.git/', '/Hello-World/', '/a/B/', '/a b/', "/a\nb/", '/a%zz/', '/' . str_repeat( 'a', 201 ) . '/', '/a/b/c/d/e/f/g/h/i/j/k/l/m/', '/a;b/', '/a:b/', '/a*/', 'relative', '' ) as $p ) {
	t_ok( null === $E::path_segments( $p ), 'rejects hostile path ' . json_encode( $p ) );
}
t_ok( array() === $E::path_segments( '/' ), 'root path has no segments' );
t_ok( array( 'a', 'b.html', 'c-d_e~f+g' ) === $E::path_segments( '/a/b.html/c-d_e~f+g/' ), 'safe segments pass' );
$long = $E::path_segments( '/' . str_repeat( 'a', 200 ) . '/' );
t_ok( null !== $long, '200-char segment is the allowed maximum' );

// ---- Store: atomic, confined, no leftovers ----------------------------------
$html = '<!doctype html><html><body>' . str_repeat( 'x', 300 ) . '</body></html>';
$c    = ctx( '/blog/post/' );
t_ok( $E::store( $c, $html ), 'store succeeds' );
t_ok( file_get_contents( $c['file'] ) === $html, 'stored bytes identical' );
t_ok( is_file( $E::cache_root() . '/index.php' ) && is_file( $E::cache_root() . '/.htaccess' ), 'root has index.php and .htaccess protection' );
t_ok( false !== strpos( file_get_contents( $E::cache_root() . '/.htaccess' ), 'Options -Indexes' ), '.htaccess denies listing' );
t_ok( array() === glob( dirname( $c['file'] ) . '/.tmp-*' ), 'no temp files left behind' );
t_ok( ! $E::store( $c, '' ), 'empty body is not stored' );
t_ok( ! $E::store( $c, str_repeat( 'x', $E::MAX_BYTES + 1 ) ), 'oversized body is not stored' );
$escape = array( 'dir' => $E::cache_root() . '/../outside', 'file' => $E::cache_root() . '/../outside/index.html', 'path' => '/' );
t_ok( ! $E::store( $escape, $html ) && ! is_dir( WP_CONTENT_DIR . '/cache/outside' ), 'store refuses a context outside the cache root' );

// ---- Serve (subprocess: serve() exits) --------------------------------------
$stub_engine = $root . '/lib/class-bepluspb-page-cache-engine.php';
$runner      = $tmp . '/serve.php';
file_put_contents( $runner, '<?php define("ABSPATH", ' . var_export( $tmp . '/', true ) . '); define("WP_CONTENT_DIR", ' . var_export( WP_CONTENT_DIR, true ) . '); require ' . var_export( $stub_engine, true ) . '; BEPLUSPB_Page_Cache_Engine::serve(); echo "MISS";' );
function run_serve( $runner, array $env ) {
	$envs = '';
	foreach ( $env as $k => $v ) { $envs .= $k . '=' . escapeshellarg( $v ) . ' '; }
	// php -n has no $_SERVER seeding from env, so pass it through a tiny bootstrap.
	$boot = '$_SERVER=array_merge($_SERVER,json_decode(getenv("T_SERVER"),true));$_COOKIE=json_decode(getenv("T_COOKIE"),true);';
	return shell_exec( 'T_SERVER=' . escapeshellarg( $env['server'] ) . ' T_COOKIE=' . escapeshellarg( $env['cookie'] ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -n -d auto_prepend_file=' . escapeshellarg( $GLOBALS['prepend'] ) . ' ' . escapeshellarg( $runner ) . ' 2>&1' );
}
$prepend = $tmp . '/prepend.php';
file_put_contents( $prepend, '<?php $_SERVER=array_merge($_SERVER,json_decode(getenv("T_SERVER"),true));$_COOKIE=json_decode(getenv("T_COOKIE"),true);' );
$srv_json = json_encode( srv( '/blog/post/' ) );
$out      = run_serve( $runner, array( 'server' => $srv_json, 'cookie' => '{}' ) );
t_ok( 0 === strpos( $out, '<!doctype html>' ) && false !== strpos( $out, '</html>' ) && false === strpos( $out, 'MISS' ), 'fresh cached page is served and the script stops' );
$out = run_serve( $runner, array( 'server' => json_encode( srv( '/never-cached/' ) ), 'cookie' => '{}' ) );
t_ok( 'MISS' === $out, 'uncached URL falls through to WordPress' );
$out = run_serve( $runner, array( 'server' => $srv_json, 'cookie' => '{"wordpress_logged_in_x":"1"}' ) );
t_ok( 'MISS' === $out, 'logged-in cookie falls through even when a copy exists' );
touch( $c['file'], time() - 7200 );
$out = run_serve( $runner, array( 'server' => $srv_json, 'cookie' => '{}' ) );
t_ok( 'MISS' === $out, 'page older than the TTL is not served' );
touch( $c['file'], time() );
$out = run_serve( $runner, array( 'server' => json_encode( srv( '/blog/post/', array( 'HTTP_IF_MODIFIED_SINCE' => gmdate( 'D, d M Y H:i:s', time() + 5 ) . ' GMT' ) ) ), 'cookie' => '{}' ) );
t_ok( null === $out || '' === $out, 'If-Modified-Since newer than the file => 304 with no body' );

// ---- advanced-cache.php stub (generated by BEPLUSPB_Page_Cache) -------------
// Reproduce the stub template without WordPress by lifting it from the class source.
$class_src = file_get_contents( $root . '/includes/class-bepluspb-page-cache.php' );
t_ok( false !== strpos( $class_src, 'catch ( \\\\Throwable' ), 'stub fails open on Throwable' );
t_ok( false !== strpos( $class_src, "is_file( \\\$bepluspb_pc_engine )" ), 'stub checks the engine exists before requiring it' );
t_ok( false !== strpos( $class_src, "class_exists( 'BEPLUSPB_Page_Cache_Engine', false )" ), 'stub checks the class before calling it' );
t_ok( false !== strpos( $class_src, 'is_our_dropin( $dst )' ) && false !== strpos( $class_src, "'foreign'" ), 'a foreign advanced-cache.php is never overwritten' );

// ---- Purge ------------------------------------------------------------------
$E::store( ctx( '/' ), $html );
$E::store( ctx( '/', array( 'HTTPS' => 'on' ) ), $html );
$E::store( ctx( '/page/2/' ), $html );
$E::store( ctx( '/page/3/' ), $html );
$E::store( ctx( '/blog/post/comment-page-2/' ), $html );
t_ok( 2 === $E::purge_path( '/' ), 'purge_path removes every variant of one URL' );
t_ok( ! is_file( $E::cache_root() . '/example.com/index.html' ) && is_file( $E::cache_root() . '/example.com/page/2/index.html' ), 'pagination kept without the flag' );
$E::store( ctx( '/' ), $html );
t_ok( $E::purge_path( '/', null, true ) >= 3, 'pagination purge removes /page/N/' );
t_ok( ! is_dir( $E::cache_root() . '/example.com/page' ), 'page directory removed' );
$E::purge_path( '/blog/post/', null, true );
t_ok( ! is_dir( $E::cache_root() . '/example.com/blog/post/comment-page-2' ) && ! is_file( $c['file'] ), 'comment pagination purged with the post' );
t_ok( 0 === $E::purge_path( '/../../etc/', null, true ), 'hostile purge path is ignored' );

$E::store( ctx( '/a/' ), $html );
$E::store( ctx( '/b/c/' ), $html );
$E::store( ctx( '/x/', array( 'HTTP_HOST' => 'example.com:8080' ) ), $html );
// Symlink escape: a link inside the cache pointing outside must be unlinked, never followed.
mkdir( $tmp . '/outside', 0755, true );
file_put_contents( $tmp . '/outside/keep.txt', 'keep' );
symlink( $tmp . '/outside', $E::cache_root() . '/example.com/a/link' );
symlink( $tmp . '/outside', $E::cache_root() . '/evil-host-link' );
$stats = $E::count_files();
t_ok( $stats['files'] >= 3 && $stats['bytes'] > 0, 'count_files counts pages' );
$res = $E::purge_all();
t_ok( 'success' === $res['status'] && 0 === $res['failed'] && $res['deleted'] >= 3, 'purge_all reports success' );
t_ok( is_file( $tmp . '/outside/keep.txt' ), 'purge_all never follows symlinks out of the cache root' );
t_ok( ! file_exists( $E::cache_root() . '/evil-host-link' ) && 0 === $E::count_files()['files'], 'symlinks unlinked, cache empty' );
t_ok( is_file( $E::cache_root() . '/index.php' ) && is_file( $E::cache_root() . '/.htaccess' ), 'protection files survive purge_all' );

// ---- GC ---------------------------------------------------------------------
$E::reset();
$old = ctx( '/old/' ); $new = ctx( '/new/' );
$E::store( $old, $html ); $E::store( $new, $html );
touch( $old['file'], time() - 7200 );
file_put_contents( dirname( $new['file'] ) . '/.tmp-stale', 'x' ); touch( dirname( $new['file'] ) . '/.tmp-stale', time() - 7200 );
t_ok( 2 === $E::gc(), 'gc removes the expired page and the stale temp file' );
t_ok( ! is_file( $old['file'] ) && ! is_dir( dirname( $old['file'] ) ) && is_file( $new['file'] ), 'gc keeps fresh pages and prunes empty directories' );

// ---- Cleanup ----------------------------------------------------------------
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isLink() || $f->isFile() ? unlink( $f->getPathname() ) : rmdir( $f->getPathname() ); }
rmdir( $tmp );

echo "PASS: $n page cache engine assertions\n";
