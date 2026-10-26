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

// ---- Device variants (wp_is_mobile parity) ------------------------------------
$IPHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
$ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36';
$DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
foreach ( array( $IPHONE, $ANDROID, 'Opera/9.80 (J2ME/MIDP; Opera Mini/9.80)', 'Mozilla/5.0 (Linux; U; Android 2.3) Silk/1.0', 'Kindle/3.0', 'BlackBerry9700/5.0' ) as $ua ) {
	t_ok( $E::is_mobile_request( array( 'HTTP_USER_AGENT' => $ua ) ), 'mobile UA detected: ' . substr( $ua, 0, 30 ) );
}
t_ok( ! $E::is_mobile_request( array( 'HTTP_USER_AGENT' => $DESKTOP ) ) && ! $E::is_mobile_request( array() ), 'desktop / missing UA is not mobile' );
t_ok( $E::is_mobile_request( array( 'HTTP_USER_AGENT' => $DESKTOP, 'HTTP_SEC_CH_UA_MOBILE' => '?1' ) ) && ! $E::is_mobile_request( array( 'HTTP_USER_AGENT' => $IPHONE, 'HTTP_SEC_CH_UA_MOBILE' => '?0' ) ), 'Sec-CH-UA-Mobile client hint wins, as in WordPress' );

t_ok( ctx( '/a/', array( 'HTTP_USER_AGENT' => $IPHONE ) )['file'] === ctx( '/a/', array( 'HTTP_USER_AGENT' => $DESKTOP ) )['file'], 'mobile mode "same" (and missing key) shares one copy' );
write_cfg( array( 'mobile' => 'separate' ) );
t_ok( ctx( '/a/', array( 'HTTP_USER_AGENT' => $IPHONE ) )['file'] === $E::cache_root() . '/example.com/a/index-m.html', 'separate: mobile gets its own copy' );
t_ok( ctx( '/a/', array( 'HTTP_USER_AGENT' => $DESKTOP ) )['file'] === $E::cache_root() . '/example.com/a/index.html', 'separate: desktop keeps the normal copy' );
t_ok( ctx( '/a/', array( 'HTTP_USER_AGENT' => $ANDROID, 'HTTPS' => 'on' ) )['file'] === $E::cache_root() . '/example.com/a/index-s-m.html', 'separate: scheme and device combine' );
write_cfg( array( 'mobile' => 'bypass' ) );
t_ok( null === ctx( '/a/', array( 'HTTP_USER_AGENT' => $IPHONE ) ) && null !== ctx( '/a/', array( 'HTTP_USER_AGENT' => $DESKTOP ) ), 'bypass: mobile is never served or stored, desktop is' );
write_cfg( array( 'mobile' => 'nonsense' ) );
t_ok( ctx( '/a/', array( 'HTTP_USER_AGENT' => $IPHONE ) )['file'] === ctx( '/a/' )['file'], 'unknown mobile mode falls back to "same"' );

// ---- Language / currency cookie variants -----------------------------------------
write_cfg( array( 'vary_cookies' => array( 'pll_language', 'wcml_client_currency' ) ) );
$base = ctx( '/a/' )['file'];
$en   = ctx( '/a/', array(), array( 'pll_language' => 'en' ) )['file'];
$vi   = ctx( '/a/', array(), array( 'pll_language' => 'vi' ) )['file'];
t_ok( $base === $E::cache_root() . '/example.com/a/index.html', 'no variant cookie => default copy' );
t_ok( $en !== $vi && $en !== $base && $vi !== $base, 'each language value gets its own copy' );
t_ok( 1 === preg_match( '/index-[0-9a-f]{8}\.html$/', $en ), 'variant name is a short hash, never the raw cookie value' );
t_ok( $en === ctx( '/a/', array(), array( 'pll_language' => 'EN' ) )['file'], 'cookie value is case-insensitive' );
t_ok( ctx( '/a/', array(), array( 'pll_language' => 'en', 'wcml_client_currency' => 'USD' ) )['file'] === ctx( '/a/', array(), array( 'wcml_client_currency' => 'usd', 'pll_language' => 'en' ) )['file'], 'cookie order does not matter' );
t_ok( ctx( '/a/', array(), array( 'pll_language' => 'en', 'wcml_client_currency' => 'USD' ) )['file'] !== $en, 'adding a currency changes the copy' );
t_ok( $base === ctx( '/a/', array(), array( 'pll_language' => '' ) )['file'], 'empty cookie value counts as absent' );
t_ok( $base === ctx( '/a/', array(), array( 'unrelated_cookie' => 'x' ) )['file'], 'cookies that are not configured never vary the copy' );
foreach ( array( '../../etc', 'a/b', 'a b', "a\nb", str_repeat( 'x', 33 ), 'vi;DROP', '<script>' ) as $bad ) {
	t_ok( null === ctx( '/a/', array(), array( 'pll_language' => $bad ) ), 'unusable cookie value is not cached: ' . json_encode( $bad ) );
}
t_ok( null === ctx( '/a/', array(), array( 'pll_language' => array( 'x' ) ) ), 'array-valued cookie is not cached' );
t_ok( ctx( '/a/', array( 'HTTPS' => 'on', 'HTTP_USER_AGENT' => $IPHONE ), array( 'pll_language' => 'en' ) )['file'] !== null, 'variant cookies combine with other variants' );
write_cfg();

// ---- Cookie names PHP rewrites ("." " " "[" become "_") ------------------------------
t_ok( 'my_cookie' === $E::php_cookie_name( 'my.cookie' ) && 'a_b_c' === $E::php_cookie_name( 'a b[c' ) && 'wp-wpml_current_language' === $E::php_cookie_name( 'wp-wpml_current_language' ), 'php_cookie_name mirrors PHP' );
write_cfg( array( 'vary_cookies' => array( 'my.cookie' ), 'exclude_cookies' => array( 'track.me' ) ) );
t_ok( ctx( '/a/', array(), array( 'my_cookie' => 'en' ) )['file'] !== ctx( '/a/' )['file'], 'a configured "my.cookie" varies on the "my_cookie" PHP actually delivers' );
t_ok( null === ctx( '/a/', array(), array( 'track_me' => '1' ) ), 'a configured "track.me" bypasses on the "track_me" PHP actually delivers' );
write_cfg();

// ---- Header replay ---------------------------------------------------------------
$c = ctx( '/hdr/' );
$pages_before = $E::count_files()['files'];
t_ok( $E::store( $c, $html, array( 'X-Frame-Options: SAMEORIGIN', "Content-Security-Policy: default-src 'self'", 'X-Robots-Tag: noindex', 'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"', 'Set-Cookie: a=b', 'Location: https://evil.test/', 'Cache-Control: public', 'Content-Type: text/plain', "X-Frame-Options: DENY\r\nSet-Cookie: x=y", 'X-Custom: nope', 'not a header' ) ), 'store with headers' );
$stored = json_decode( file_get_contents( $E::header_file( $c['file'] ) ), true )['h'];
t_ok( array( 'X-Frame-Options: SAMEORIGIN', "Content-Security-Policy: default-src 'self'", 'X-Robots-Tag: noindex', 'Link: <https://example.com/wp-json/>; rel="https://api.w.org/"' ) === $stored, 'only allowlisted single-line headers are recorded (no cookies, redirects, CRLF injection)' );
t_ok( $stored === $E::read_headers( $c['file'] ), 'read_headers round-trips' );
t_ok( '.hdr' === substr( $E::header_file( $c['file'] ), -4 ), 'sidecar uses .hdr' );
t_ok( $E::count_files()['files'] === $pages_before + 1, 'sidecar is not counted as a page' );
// The CLI SAPI does not track headers, so replay is checked over real HTTP with the built-in server.
file_put_contents( $tmp . '/router.php', '<?php define("ABSPATH",' . var_export( $tmp . '/', true ) . '); define("WP_CONTENT_DIR",' . var_export( WP_CONTENT_DIR, true ) . '); require ' . var_export( $stub_engine, true ) . '; BEPLUSPB_Page_Cache_Engine::serve(); echo "MISS";' );
$port = 18300 + random_int( 0, 400 );
$srv  = proc_open( escapeshellarg( PHP_BINARY ) . ' -n -S 127.0.0.1:' . $port . ' ' . escapeshellarg( $tmp . '/router.php' ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
for ( $i = 0; $i < 40; $i++ ) { if ( @fsockopen( '127.0.0.1', $port ) ) { break; } usleep( 100000 ); }
$hdr_run = static function ( $uri, array $extra = array() ) use ( $port ) {
	$args = '';
	foreach ( $extra as $h ) { $args .= ' -H ' . escapeshellarg( $h ); }
	return (string) shell_exec( 'curl -si -m 10 -H ' . escapeshellarg( 'Host: example.com' ) . $args . ' ' . escapeshellarg( 'http://127.0.0.1:' . $port . $uri ) . ' 2>&1' );
};
t_ok( false !== strpos( $hdr_run( '/hdr/' ), 'HTTP/1.1 200' ), 'built-in server is up and serving the cached page' );
$out = $hdr_run( '/hdr/' );
t_ok( false !== stripos( $out, 'X-Frame-Options: SAMEORIGIN' ) && false !== stripos( $out, "Content-Security-Policy: default-src 'self'" ) && false !== stripos( $out, 'X-Robots-Tag: noindex' ) && false !== strpos( $out, 'rel="https://api.w.org/"' ), 'a HIT replays the headers recorded when the page was built' );
t_ok( false !== stripos( $out, 'X-Beplus-Page-Cache: HIT' ) && false === stripos( $out, 'Set-Cookie' ) && false === stripos( $out, 'evil.test' ), 'replay never carries cookies or redirects' );
t_ok( false === stripos( $out, 'Content-Length: 0' ), 'body present' );
// Fallback mode runs after other plugins: the replayed header must REPLACE theirs, never duplicate it.
file_put_contents( $tmp . '/router2.php', '<?php define("ABSPATH",' . var_export( $tmp . '/', true ) . '); define("WP_CONTENT_DIR",' . var_export( WP_CONTENT_DIR, true ) . '); require ' . var_export( $stub_engine, true ) . '; header("X-Frame-Options: DENY"); header("Link: <https://old.test/>; rel=old"); BEPLUSPB_Page_Cache_Engine::serve(); echo "MISS";' );
$port2 = $port + 500;
$srv2  = proc_open( escapeshellarg( PHP_BINARY ) . ' -n -S 127.0.0.1:' . $port2 . ' ' . escapeshellarg( $tmp . '/router2.php' ), array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes2 );
for ( $i = 0; $i < 40; $i++ ) { if ( @fsockopen( '127.0.0.1', $port2 ) ) { break; } usleep( 100000 ); }
$out2 = (string) shell_exec( 'curl -si -m 10 -H ' . escapeshellarg( 'Host: example.com' ) . ' ' . escapeshellarg( 'http://127.0.0.1:' . $port2 . '/hdr/' ) . ' 2>&1' );
t_ok( 1 === preg_match_all( '/^X-Frame-Options:/mi', $out2 ) && false !== strpos( $out2, 'X-Frame-Options: SAMEORIGIN' ) && false === strpos( $out2, 'X-Frame-Options: DENY' ), 'a replayed single-valued header replaces one an earlier plugin already sent (no conflicting duplicates)' );
proc_terminate( $srv2 );
proc_close( $srv2 );
$E::store( $c, $html, array( 'Link: <https://a.test/>; rel=a', 'Link: <https://b.test/>; rel=b', 'Content-Security-Policy: default-src none', 'Content-Security-Policy: img-src self' ) );
$out = $hdr_run( '/hdr/' );
t_ok( 2 === preg_match_all( '/^Link:/mi', $out ) && 2 === preg_match_all( '/^Content-Security-Policy:/mi', $out ), 'multi-valued headers (Link, CSP) keep every line' );

// Tampered sidecar: injection attempts are dropped at serve time too.
file_put_contents( $E::header_file( $c['file'] ), json_encode( array( 'X-Frame-Options: SAMEORIGIN', 'Set-Cookie: pwn=1', "Referrer-Policy: no-referrer\r\nSet-Cookie: pwn=2", 'Location: https://evil.test/' ) ) );
$out = $hdr_run( '/hdr/' );
t_ok( false !== strpos( $out, 'X-Frame-Options: SAMEORIGIN' ) && false === stripos( $out, 'pwn' ) && false === stripos( $out, 'evil.test' ) && false === strpos( $out, 'Referrer-Policy' ), 'a tampered sidecar cannot inject headers' );
file_put_contents( $E::header_file( $c['file'] ), '{not json' );
t_ok( false !== strpos( $hdr_run( '/hdr/' ), '</html>' ), 'a corrupt sidecar still serves the page' );
// Re-store without headers removes stale ones.
t_ok( $E::store( $c, $html ) && ! is_file( $E::header_file( $c['file'] ) ), 're-storing without headers removes the old sidecar' );
t_ok( false === strpos( $hdr_run( '/hdr/' ), 'X-Frame-Options' ), 'stale headers are never replayed' );
// Variants (https / mobile) keep their own sidecars.
write_cfg( array( 'mobile' => 'separate' ) );
$cm = ctx( '/hdr/', array( 'HTTP_USER_AGENT' => $IPHONE ) );
$E::store( $cm, $html, array( 'X-Frame-Options: DENY' ) );
t_ok( array( 'X-Frame-Options: DENY' ) === $E::read_headers( $cm['file'] ) && array() === $E::read_headers( $c['file'] ), 'each variant has its own header sidecar' );
t_ok( false !== strpos( $hdr_run( '/hdr/', array( 'User-Agent: ' . $IPHONE ) ), 'Vary: User-Agent' ), 'separate-mobile responses send Vary: User-Agent' );
t_ok( false === strpos( $hdr_run( '/hdr/', array( 'User-Agent: ' . $IPHONE ) ), 'Content-Length' ), 'still no Content-Length' );
// Purge and GC clean sidecars.
$E::purge_path( '/hdr/' );
t_ok( array() === glob( $E::cache_root() . '/example.com/hdr/index*' ), 'purge_path removes pages and header sidecars' );
$E::store( $cm, $html, array( 'X-Frame-Options: DENY' ) );
touch( $cm['file'], time() - 7200 );
t_ok( 1 === $E::gc() && array() === glob( dirname( $cm['file'] ) . '/index*' ), 'gc removes an expired page together with its sidecar' );
@mkdir( $E::cache_root() . '/example.com/hdr', 0755, true );
file_put_contents( $E::cache_root() . '/example.com/hdr/index-orphan.hdr', '["X-Frame-Options: DENY"]' );
t_ok( is_file( $E::cache_root() . '/example.com/hdr/index-orphan.hdr' ), 'orphan sidecar fixture exists' );
$E::gc();
t_ok( ! is_file( $E::cache_root() . '/example.com/hdr/index-orphan.hdr' ), 'gc removes orphaned sidecars' );
$E::store( $c, $html, array( 'X-Robots-Tag: noindex' ) );
$E::purge_all();
t_ok( array() === glob( $E::cache_root() . '/example.com/*' ), 'purge_all removes sidecars too' );
write_cfg();

// ---- Automatic device handling ------------------------------------------------------
write_cfg( array( 'mobile' => 'auto' ) );
$dc = ctx( '/auto/', array( 'HTTP_USER_AGENT' => $DESKTOP ) );
$mc = ctx( '/auto/', array( 'HTTP_USER_AGENT' => $IPHONE ) );
$rootd = $E::cache_root() . '/example.com/auto';
t_ok( $dc['file'] === $rootd . '/index.html' && $mc['file'] === $dc['file'], 'auto: both devices share one file by default' );
t_ok( array( $rootd . '/index-d.html', $rootd . '/index-m.html' ) === $dc['device_files'] && $dc['device_file'] === $rootd . '/index-d.html' && $mc['device_file'] === $rootd . '/index-m.html', 'auto: per-device files exist only as alternatives' );
t_ok( ctx( '/auto/', array( 'HTTPS' => 'on', 'HTTP_USER_AGENT' => $IPHONE ) )['device_file'] === $rootd . '/index-s-m.html', 'auto: scheme and device combine' );
t_ok( null === ctx( '/auto/', array( 'HTTP_USER_AGENT' => $IPHONE ), array( 'wp_x' => 1, 'wordpress_logged_in_x' => 1 ) ), 'auto: bypass rules still apply' );
write_cfg( array( 'mobile' => 'same' ) );
t_ok( null === ctx( '/auto/' )['device_file'] && array() === ctx( '/auto/' )['device_files'], 'other modes have no device files' );
write_cfg( array( 'mobile' => 'auto' ) );

$page_d = str_replace( 'xxx', 'DESKTOPCOPY', '<!doctype html><html><body>' . str_repeat( 'x', 100 ) . 'xxx' . str_repeat( 'x', 200 ) . '</body></html>' );
$page_m = str_replace( 'DESKTOPCOPY', 'MOBILECOPY', $page_d );
$get = static function ( $ua ) use ( $hdr_run ) { return $hdr_run( '/auto/', array( 'User-Agent: ' . $ua ) ); };

$E::store( $dc, $page_d );
t_ok( is_file( $dc['file'] ) && false !== strpos( $get( $DESKTOP ), 'DESKTOPCOPY' ) && false !== strpos( $get( $IPHONE ), 'DESKTOPCOPY' ), 'shared copy is served to both devices' );
t_ok( false === stripos( $get( $IPHONE ), 'Vary: User-Agent' ) && false === stripos( $get( $DESKTOP ), 'Vary: User-Agent' ), 'a device-independent page sends NO Vary: User-Agent (server caches in front keep working)' );

$E::store( $dc, $page_d, array(), array( 'device' => true ) );
t_ok( is_file( $dc['device_file'] ) && ! is_file( $dc['file'] ), 'device-aware page: desktop copy stored, shared copy removed' );
t_ok( false !== strpos( $get( $DESKTOP ), 'DESKTOPCOPY' ) && false !== stripos( $get( $DESKTOP ), 'Vary: User-Agent' ), 'desktop gets its copy with Vary: User-Agent' );
t_ok( false === strpos( $get( $IPHONE ), 'DESKTOPCOPY' ) && false !== strpos( $get( $IPHONE ), 'MISS' ), 'mobile is NOT served the desktop copy (miss => WordPress renders it)' );
$E::store( $mc, $page_m, array(), array( 'device' => true ) );
t_ok( false !== strpos( $get( $IPHONE ), 'MOBILECOPY' ) && false !== strpos( $get( $DESKTOP ), 'DESKTOPCOPY' ), 'each device gets its own copy' );
t_ok( false !== stripos( $get( $IPHONE ), 'Vary: User-Agent' ), 'mobile copy also announces Vary: User-Agent' );
$E::store( $dc, $page_d );
t_ok( is_file( $dc['file'] ) && ! is_file( $dc['device_files'][0] ) && ! is_file( $dc['device_files'][1] ), 'a page that is no longer device-aware drops both device copies' );
t_ok( false !== strpos( $get( $IPHONE ), 'DESKTOPCOPY' ), 'and the shared copy serves everyone again' );
$E::purge_path( '/auto/' );

// ---- "Lists other content" markers ----------------------------------------------------
write_cfg();
$pa = ctx( '/m1/' ); $pb = ctx( '/deep/m2/' ); $pc = ctx( '/m3/' );
$E::store( $pa, $html, array( 'X-Robots-Tag: noindex' ), array( 'lists_others' => true ) );
$E::store( $pb, $html, array(), array( 'lists_others' => true ) );
$E::store( $pc, $html );
t_ok( is_file( substr( $pa['file'], 0, -5 ) . '.lst' ) && ! is_file( substr( $pc['file'], 0, -5 ) . '.lst' ), 'marker only for pages that list other content' );
$E::store( $pb, $html );
t_ok( ! is_file( substr( $pb['file'], 0, -5 ) . '.lst' ), 're-storing without the flag removes the marker' );
$E::store( $pb, $html, array(), array( 'lists_others' => true ) );
t_ok( 2 === $E::purge_flagged(), 'purge_flagged removes exactly the flagged pages' );
t_ok( ! is_file( $pa['file'] ) && ! is_file( $pb['file'] ) && is_file( $pc['file'] ) && array() === glob( dirname( $pa['file'] ) . '/index*' ), 'flagged pages and their sidecars are gone; the plain page stays' );
t_ok( 0 === $E::purge_flagged(), 'nothing flagged => 0' );
$E::store( $pa, $html, array(), array( 'lists_others' => true ) );
$E::purge_path( '/m1/' );
t_ok( array() === glob( dirname( $pa['file'] ) . '/index*' ), 'purge_path removes markers too' );
@mkdir( dirname( $pa['file'] ), 0755, true );
file_put_contents( dirname( $pa['file'] ) . '/index-orphan.lst', '' );
$E::gc();
t_ok( ! is_file( dirname( $pa['file'] ) . '/index-orphan.lst' ), 'gc removes orphaned markers' );
$E::purge_all();

// ---- CSP nonces rotate on every HIT ---------------------------------------------------------
t_ok( array( 'abcdEFGH1234' ) === $E::extract_nonces( array( "Content-Security-Policy: script-src 'self' 'nonce-abcdEFGH1234'" ), 'x' ), 'nonce read from a CSP header' );
t_ok( array( 'abcdEFGH1234' ) === $E::extract_nonces( array( "Content-Security-Policy-Report-Only: default-src 'nonce-abcdEFGH1234'" ), 'x' ), '... and from Report-Only' );
t_ok( array( 'metaNONCE9999' ) === $E::extract_nonces( array(), '<head><meta http-equiv="Content-Security-Policy" content="script-src \'nonce-metaNONCE9999\'"></head>' ), '... and from a CSP meta tag' );
t_ok( array() === $E::extract_nonces( array( "Content-Security-Policy: script-src 'nonce-short'", 'X-Robots-Tag: noindex' ), '<script nonce="plain-attr-only-1234">' ), 'short nonces, non-CSP headers and bare nonce attributes are not treated as nonces' );
t_ok( 1 === count( $E::extract_nonces( array( "Content-Security-Policy: a 'nonce-abcdEFGH1234'", "Content-Security-Policy: b 'nonce-abcdEFGH1234'" ), '' ) ), 'nonces are de-duplicated' );

$nc = ctx( '/csp/' );
$NONCE = 'AbCdEf0123456789xyZ';
$csp_html = '<!doctype html><html><head><script nonce="' . $NONCE . '">var a=1;</script></head><body>' . str_repeat( 'x', 300 ) . '<script nonce="' . $NONCE . '">var b=2;</script></body></html>';
$E::store( $nc, $csp_html, array( "Content-Security-Policy: script-src 'self' 'nonce-" . $NONCE . "'" ) );
t_ok( array( $NONCE ) === $E::read_meta( $nc['file'] )['n'], 'the nonce is recorded in the sidecar' );
$r1 = $hdr_run( '/csp/' );
$r2 = $hdr_run( '/csp/' );
$parse = static function ( $r ) { preg_match( "/Content-Security-Policy:[^\r\n]*'nonce-([0-9a-f]{32})'/i", $r, $h ); preg_match_all( '/nonce="([0-9a-f]{32})"/', $r, $b ); return array( $h[1] ?? null, $b[1] ?? array() ); };
list( $h1, $b1 ) = $parse( $r1 );
list( $h2, $b2 ) = $parse( $r2 );
t_ok( $h1 && 2 === count( $b1 ) && $b1[0] === $h1 && $b1[1] === $h1, 'within one response the header nonce and every script nonce match' );
t_ok( $h2 && $h1 !== $h2 && $b2[0] === $h2, 'a different response gets a different nonce' );
t_ok( false === strpos( $r1, $NONCE ) && false === strpos( $r2, $NONCE ), 'the stored nonce never reaches a visitor' );
t_ok( false !== strpos( $r1, 'var a=1;' ) && false !== strpos( $r1, 'X-Beplus-Page-Cache: HIT' ), 'page content intact' );
t_ok( false === stripos( $r1, 'Last-Modified' ), 'no Last-Modified for nonce pages (the body changes every response)' );
$r3 = $hdr_run( '/csp/', array( 'If-Modified-Since: ' . gmdate( 'D, d M Y H:i:s', time() + 3600 ) . ' GMT' ) );
t_ok( false !== strpos( $r3, 'HTTP/1.1 200' ) && false !== strpos( $r3, 'var a=1;' ), 'a nonce page is never answered 304 (the browser would keep a stale nonce)' );
// A meta-tag CSP is rotated as well.
$meta_html = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="script-src \'nonce-' . $NONCE . '\'"><script nonce="' . $NONCE . '">1</script></head><body>' . str_repeat( 'x', 300 ) . '</body></html>';
$E::store( $nc, $meta_html );
$r4 = $hdr_run( '/csp/' );
t_ok( false === strpos( $r4, $NONCE ) && 1 === preg_match( "/nonce-([0-9a-f]{32})'.*nonce=\"\\1\"/s", $r4 ), 'a CSP meta tag and the scripts get the same fresh nonce' );
// Pages without nonces still revalidate normally.
$E::store( $nc, $html );
t_ok( false !== strpos( $hdr_run( '/csp/', array( 'If-Modified-Since: ' . gmdate( 'D, d M Y H:i:s', time() + 3600 ) . ' GMT' ) ), 'HTTP/1.1 304' ), 'pages without nonces keep Last-Modified / 304' );
// Tampered nonce list.
$E::store( $nc, $csp_html, array( "Content-Security-Policy: script-src 'nonce-" . $NONCE . "'" ) );
file_put_contents( $E::header_file( $nc['file'] ), json_encode( array( 'h' => array(), 'n' => array( 'x"><script>alert(1)</script>', 'short', str_repeat( 'a', 200 ) ) ) ) );
t_ok( array() === $E::read_meta( $nc['file'] )['n'], 'invalid nonce values in the sidecar are ignored' );
$E::purge_all();

// ---- Visitor-chosen cookie values cannot fill the disk ----------------------------------
write_cfg( array( 'vary_cookies' => array( 'pll_language' ) ) );
foreach ( array( 'en', 'vi', 'fil', 'USD', 'VND', 'en-US', 'pt_BR', 'zh-hant-tw', 'zh-Hant-TW', 'sr-Latn' ) as $good ) {
	t_ok( null !== ctx( '/vals/', array(), array( 'pll_language' => $good ) ), 'language/currency code accepted: ' . $good );
}
foreach ( array( 'x1', 'e', 'english', 'en-', '-en', 'en-US-extra-more', 'en-us-posix-x', '123', 'e n', 'en;', 'e%6e', str_repeat( 'a', 17 ), 'en-a', 'aa-bbbbbbb', "en\n", '../x' ) as $bad ) {
	t_ok( null === ctx( '/vals/', array(), array( 'pll_language' => $bad ) ), 'arbitrary value is not cached: ' . json_encode( $bad ) );
}
$va = static function ( $i ) { return chr( 97 + intdiv( $i, 26 ) ) . chr( 97 + $i % 26 ); };
$stored = 0;
for ( $i = 0; $i < 500; $i++ ) {
	$vc = ctx( '/vals/', array(), array( 'pll_language' => $va( $i % 676 ) . ( $i >= 676 ? 'x' : '' ) ) );
	if ( $vc && $E::store( $vc, $html ) ) { ++$stored; }
}
$vdir = $E::cache_root() . '/example.com/vals';
$vcount = count( glob( $vdir . '/index*.html' ) );
t_ok( $E::MAX_VARIANTS === $vcount && $E::MAX_VARIANTS === $stored, 'one URL never holds more than MAX_VARIANTS copies, however many cookie values are sent (' . $vcount . ')' );
$existing = ctx( '/vals/', array(), array( 'pll_language' => 'aa' ) );
t_ok( $E::store( $existing, $html . ' refreshed' ) && false !== strpos( file_get_contents( $existing['file'] ), 'refreshed' ), 'an existing variant can still be refreshed at the cap' );
$E::store( ctx( '/vals/', array(), array( 'pll_language' => 'zz' ) ), $html, array( 'X-Robots-Tag: noindex' ), array( 'lists_others' => true ) );
t_ok( $E::MAX_VARIANTS === count( glob( $vdir . '/index*.html' ) ) && ! is_file( $E::header_file( ctx( '/vals/', array(), array( 'pll_language' => 'zz' ) )['file'] ) ), 'a refused variant leaves no sidecars behind' );
$E::purge_path( '/vals/' );
// Sidecars (.hdr / .lst) are not variants: 95 pages with two sidecars each still leave room for exactly one more.
for ( $i = 0; $i < $E::MAX_VARIANTS - 1; $i++ ) {
	$E::store( ctx( '/vals/', array(), array( 'pll_language' => $va( $i ) ) ), $html, array( 'X-Robots-Tag: noindex' ), array( 'lists_others' => true ) );
}
t_ok( ( $E::MAX_VARIANTS - 1 ) * 3 === count( glob( $vdir . '/index*' ) ), 'fixture: pages with .hdr and .lst sidecars' );
t_ok( true === $E::store( ctx( '/vals/', array(), array( 'pll_language' => 'zy' ) ), $html ), 'sidecars are not counted: the last allowed variant is still accepted' );
t_ok( false === $E::store( ctx( '/vals/', array(), array( 'pll_language' => 'zx' ) ), $html ), 'and the next one is refused' );
$E::purge_path( '/vals/' );
t_ok( true === $E::store( ctx( '/vals/', array(), array( 'pll_language' => 'zz' ) ), $html ), 'after a purge the URL accepts variants again' );
$E::purge_path( '/vals/' );
write_cfg();

proc_terminate( $srv );
proc_close( $srv );

// ---- Cleanup ----------------------------------------------------------------
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isLink() || $f->isFile() ? unlink( $f->getPathname() ) : rmdir( $f->getPathname() ); }
rmdir( $tmp );

echo "PASS: $n page cache engine assertions\n";
