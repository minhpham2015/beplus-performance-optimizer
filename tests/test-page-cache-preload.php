<?php
/**
 * Standalone tests for BEPLUSPB_Page_Cache_Preload with WordPress stubbed.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/bepluspb-pre-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/wp-content', 0755, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
define( 'BEPLUSPB_PLUGIN_DIR', $root . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['opts']       = array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_preload' => 1 );
$GLOBALS['transients'] = array();
$GLOBALS['cron']       = array();
$GLOBALS['hooks']      = array();
$GLOBALS['requests']   = array();
$GLOBALS['fail']       = false;
$GLOBALS['permalinks'] = array();
$GLOBALS['query_args'] = null;

class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_action( $tag, $cb, $pri = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = $cb; }
function apply_filters( $tag, $v ) { return $GLOBALS['filters'][ $tag ] ?? $v; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; }
function wp_schedule_single_event( $t, $h ) { $GLOBALS['cron'][ $h ] = $t; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['cron'][ $h ] ); }
function get_post_types( $a ) { return array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ); }
function get_posts( $args ) { $GLOBALS['query_args'] = $args; return array_keys( $GLOBALS['permalinks'] ); }
function get_permalink( $id ) { return $GLOBALS['permalinks'][ $id ] ?? false; }
function wp_remote_get( $url, $args ) { $GLOBALS['requests'][] = array( $url, $args ); return $GLOBALS['fail'] ? new WP_Error() : array( 'code' => 200 ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
class BEPLUSPB_Page_Cache { public static function is_enabled( $o ) { return ! empty( $o['cache_enabled'] ) && ! empty( $o['page_cache_enabled'] ); } }
class BEPLUSPB_Page_Cache_Engine {
	public static function load_config() { return array( 'exclude_paths' => array( '/members/' ) ); }
	public static function path_segments( $p ) { return preg_match( '#^/[a-z0-9/_-]*$#', $p ) ? array_values( array_filter( explode( '/', $p ) ) ) : null; }
	public static function is_excluded_path( $cfg, $path, $q ) { foreach ( $cfg['exclude_paths'] as $x ) { if ( false !== strpos( $path, $x ) ) { return true; } } return (bool) preg_match( '#/(cart|checkout)/#', $path ); }
}
require $root . '/includes/class-bepluspb-page-cache-preload.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$P = 'BEPLUSPB_Page_Cache_Preload';

// ---- Enabled only with preload AND page cache ------------------------------
t_ok( $P::is_enabled(), 'enabled when both options are on' );
t_ok( ! $P::is_enabled( array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_preload' => 0 ) ), 'off when preload option is off' );
t_ok( ! $P::is_enabled( array( 'cache_enabled' => 0, 'page_cache_enabled' => 1, 'page_cache_preload' => 1 ) ), 'off when master cache toggle is off' );
t_ok( ! $P::is_enabled( array( 'cache_enabled' => 1, 'page_cache_enabled' => 0, 'page_cache_preload' => 1 ) ), 'off when page cache is off' );

// ---- is_own_url: same host only ---------------------------------------------
t_ok( $P::is_own_url( 'https://example.com/a/' ) && $P::is_own_url( 'https://EXAMPLE.com/a/' ), 'own host accepted (case-insensitive)' );
t_ok( ! $P::is_own_url( 'https://evil.com/a/' ), 'other host rejected' );
t_ok( ! $P::is_own_url( 'https://example.com.evil.com/' ), 'look-alike host rejected' );
t_ok( ! $P::is_own_url( 'https://example.com@evil.com/' ), 'userinfo trick rejected' );
t_ok( ! $P::is_own_url( 'https://user:pw@example.com/' ), 'credentials in URL rejected' );
t_ok( ! $P::is_own_url( 'https://example.com:8443/' ), 'different port rejected' );
t_ok( ! $P::is_own_url( 'ftp://example.com/' ) && ! $P::is_own_url( 'file:///etc/passwd' ) && ! $P::is_own_url( '//example.com/x' ), 'non-http(s) schemes rejected' );
t_ok( ! $P::is_own_url( 'https://example.com/?s=1' ), 'query-string URLs rejected' );
t_ok( ! $P::is_own_url( '' ) && ! $P::is_own_url( 'not a url' ), 'junk rejected' );

// ---- collect_urls ------------------------------------------------------------
$GLOBALS['permalinks'] = array(
	1 => 'https://example.com/hello/',
	2 => 'https://example.com/members/area/',   // excluded path
	3 => 'https://example.com/cart/',            // bypass path
	4 => 'https://other.test/foreign/',          // foreign host
	5 => 'https://example.com/?p=5',             // plain permalink: query string
	6 => 'https://example.com/hello/',           // duplicate
	7 => 'https://example.com/Upper-Case/',      // path_segments rejects upper case
	8 => 'https://example.com/about/',
);
$urls = $P::collect_urls();
t_ok( array( 'https://example.com/', 'https://example.com/hello/', 'https://example.com/about/' ) === $urls, 'home first; excluded, foreign, query, duplicate and invalid URLs dropped' );
t_ok( 'publish' === $GLOBALS['query_args']['post_status'] && false === $GLOBALS['query_args']['has_password'] && 500 === $GLOBALS['query_args']['posts_per_page'], 'only published, non-password-protected posts, default limit 500' );
t_ok( ! in_array( 'attachment', $GLOBALS['query_args']['post_type'], true ), 'attachments are not preloaded' );
$GLOBALS['filters']['bepluspb_page_cache_preload_max_urls'] = 999999;
$P::collect_urls();
t_ok( 2000 === $GLOBALS['query_args']['posts_per_page'], 'limit is capped at the hard maximum' );
$GLOBALS['filters']['bepluspb_page_cache_preload_max_urls'] = 2;
t_ok( 2 === count( $P::collect_urls() ), 'limit trims the list' );
$GLOBALS['filters']['bepluspb_page_cache_preload_max_urls'] = 0;
t_ok( 1 === count( $P::collect_urls() ), 'limit never drops below 1' );
unset( $GLOBALS['filters']['bepluspb_page_cache_preload_max_urls'] );
$GLOBALS['filters']['bepluspb_page_cache_preload_urls'] = array( 'https://example.com/x/', 'https://attacker.test/ssrf', 'http://127.0.0.1:2375/', 'https://example.com/x/', 42 );
t_ok( array( 'https://example.com/x/' ) === $P::collect_urls(), 'filter output is re-validated: foreign hosts and junk are dropped' );
unset( $GLOBALS['filters']['bepluspb_page_cache_preload_urls'] );

// ---- schedule / cancel -------------------------------------------------------
$P::schedule();
t_ok( isset( $GLOBALS['cron'][ $P::START_HOOK ] ), 'schedule queues a start event' );
$first = $GLOBALS['cron'][ $P::START_HOOK ];
$P::schedule();
t_ok( $first === $GLOBALS['cron'][ $P::START_HOOK ], 'repeated purges do not push the start event further out' );
$GLOBALS['transients'][ $P::STATE_TRANSIENT ] = array( 'state' => 'running' );
$GLOBALS['cron'][ $P::TICK_HOOK ] = 1;
$P::schedule();
t_ok( ! isset( $GLOBALS['transients'][ $P::STATE_TRANSIENT ] ) && ! isset( $GLOBALS['cron'][ $P::TICK_HOOK ] ), 'a new purge drops the stale run and its tick' );
$P::cancel();
t_ok( ! $GLOBALS['cron'] && ! $GLOBALS['transients'], 'cancel clears events and state' );
$GLOBALS['opts']['page_cache_preload'] = 0;
$P::schedule();
t_ok( ! isset( $GLOBALS['cron'][ $P::START_HOOK ] ), 'nothing is queued when preload is off' );
$P::schedule( array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_preload' => 1 ) );
t_ok( isset( $GLOBALS['cron'][ $P::START_HOOK ] ), 'explicit options win over the saved ones (settings-save path)' );
$P::cancel();
$P::start();
t_ok( ! $GLOBALS['transients'] && ! $GLOBALS['cron'], 'start does nothing when preload is off' );
$GLOBALS['opts']['page_cache_preload'] = 1;

// ---- Full run in batches -------------------------------------------------------
$GLOBALS['permalinks'] = array();
for ( $i = 1; $i <= 11; $i++ ) { $GLOBALS['permalinks'][ $i ] = "https://example.com/p$i/"; }
$P::start();
$st = $P::get_status();
t_ok( 'running' === $st['state'] && 12 === $st['total'] && 0 === $st['done'], 'start builds the queue (home + 11 posts)' );
t_ok( isset( $GLOBALS['cron'][ $P::TICK_HOOK ] ), 'start schedules the first tick' );
unset( $GLOBALS['cron'][ $P::TICK_HOOK ] );
$GLOBALS['requests'] = array();
$P::tick();
t_ok( 5 === count( $GLOBALS['requests'] ) && 5 === $P::get_status()['done'], 'a tick warms one batch of 5' );
t_ok( 0 === $GLOBALS['requests'][0][1]['redirection'] && array() === $GLOBALS['requests'][0][1]['cookies'], 'requests follow no redirects and send no cookies' );
t_ok( 'https://example.com/' === $GLOBALS['requests'][0][0], 'home page is warmed first' );
t_ok( isset( $GLOBALS['cron'][ $P::TICK_HOOK ] ) && ! isset( $GLOBALS['transients'][ $P::LOCK_TRANSIENT ] ), 'next tick queued, lock released' );
$P::tick(); $P::tick();
$st = $P::get_status();
t_ok( 'done' === $st['state'] && 12 === $st['done'] && 0 === $st['failed'], 'run finishes after 3 ticks' );
unset( $GLOBALS['cron'][ $P::TICK_HOOK ] );
$GLOBALS['requests'] = array();
$P::tick();
t_ok( ! $GLOBALS['requests'] && ! isset( $GLOBALS['cron'][ $P::TICK_HOOK ] ), 'finished run does nothing more' );

// ---- Lock prevents overlap ----------------------------------------------------
$P::start();
$GLOBALS['transients'][ $P::LOCK_TRANSIENT ] = 1;
$GLOBALS['requests'] = array();
$P::tick();
t_ok( ! $GLOBALS['requests'], 'a locked tick does not run' );
unset( $GLOBALS['transients'][ $P::LOCK_TRANSIENT ] );

// ---- Failure streak aborts ------------------------------------------------------
$P::cancel();
$P::start();
$GLOBALS['fail']     = true;
$GLOBALS['requests'] = array();
unset( $GLOBALS['cron'][ $P::TICK_HOOK ] );
$P::tick();
$st = $P::get_status();
t_ok( 'failed' === $st['state'] && 5 === count( $GLOBALS['requests'] ) && 5 === $st['failed'], 'five failures in a row abandon the run' );
t_ok( ! isset( $GLOBALS['cron'][ $P::TICK_HOOK ] ), 'no further tick after abandoning' );
$GLOBALS['fail'] = false;

// ---- Turning the feature off mid-run --------------------------------------------
$P::cancel();
$P::start();
$GLOBALS['opts']['page_cache_preload'] = 0;
$GLOBALS['requests'] = array();
$P::tick();
t_ok( ! $GLOBALS['requests'] && null === $P::get_status(), 'switching preload off stops a running queue and clears its state' );
$GLOBALS['opts']['page_cache_preload'] = 1;

// ---- Hooks -------------------------------------------------------------------------
$P::register_core_hooks();
t_ok( isset( $GLOBALS['hooks'][ $P::START_HOOK ], $GLOBALS['hooks'][ $P::TICK_HOOK ] ), 'cron callbacks are registered' );
$GLOBALS['hooks'] = array();
$P::register_invalidation_hooks( array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_preload' => 0 ) );
t_ok( empty( $GLOBALS['hooks']['bepluspb_page_cache_purged_all'] ), 'no purge listener when preload is off' );
$P::register_invalidation_hooks( $GLOBALS['opts'] );
t_ok( ! empty( $GLOBALS['hooks']['bepluspb_page_cache_purged_all'] ), 'purge listener registered when preload is on' );

// ---- Source guards (Hard Rules) ---------------------------------------------------
$src = file_get_contents( $root . '/includes/class-bepluspb-page-cache-preload.php' );
t_ok( false === strpos( $src, '$_GET' ) && false === strpos( $src, '$_POST' ) && false === strpos( $src, '$_REQUEST' ), 'preload never reads request input' );
t_ok( false === strpos( $src, '$wpdb' ), 'preload makes no direct database queries' );

echo "PASS: $n page cache preload assertions\n";
