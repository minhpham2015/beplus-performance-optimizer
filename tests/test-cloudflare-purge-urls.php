<?php
/**
 * Cloudflare: purge the edited post's URLs (opt-in).
 * Run: php -n tests/test-cloudflare-purge-urls.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'WEEK_IN_SECONDS', 604800 );
$GLOBALS['st'] = array();
function reset_state( $opts = null ) {
	$GLOBALS['st'] = array(
		'opts' => $opts ?? array( 'cloudflare_enabled' => 1, 'cloudflare_purge_on_edit' => 1, 'cloudflare_zone_id' => 'zone123', 'cloudflare_api_token' => 'TOKEN-SECRET' ),
		'hooks' => array(), 'admin' => true, 'cron' => false, 'calls' => array(), 'api' => array( 'success' => true, 'result' => array() ),
		'transients' => array(), 'revision' => false, 'viewable' => true, 'posts' => array(),
	);
}
function __( $t, $d = '' ) { return $t; }
function bepluspb_get_options() { return $GLOBALS['st']['opts']; }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) { $GLOBALS['st']['hooks'][ $tag ][] = array( $cb, $prio, $args ); }
function is_admin() { return $GLOBALS['st']['admin']; }
function wp_doing_cron() { return $GLOBALS['st']['cron']; }
function wp_is_post_revision( $id ) { return $GLOBALS['st']['revision']; }
function wp_is_post_autosave( $id ) { return false; }
function is_post_type_viewable( $t ) { return $GLOBALS['st']['viewable']; }
function home_url( $p = '' ) { return 'https://ex.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return 'page_for_posts' === $k ? 9 : $d; }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; $o = is_object( $p ) ? $p : ( $GLOBALS['st']['posts'][ $id ] ?? null ); return $o ? 'https://ex.com/' . $o->post_name . '/' : false; }
function get_post( $id ) { return $GLOBALS['st']['posts'][ $id ] ?? null; }
function get_post_type_archive_link( $t ) { return 'book' === $t ? 'https://ex.com/books/' : false; }
function get_object_taxonomies( $t, $o ) { return array( (object) array( 'name' => 'category', 'public' => true ), (object) array( 'name' => 'hidden', 'public' => false ) ); }
function get_the_terms( $p, $tax ) { return 'category' === $tax ? array( 'news', 'tips' ) : false; }
function get_term_link( $t ) { return 'tips' === $t ? new stdClass() : 'https://ex.com/category/' . $t . '/'; }
function get_author_posts_url( $id ) { return 'https://ex.com/author/' . $id . '/'; }
function get_feed_link() { return 'https://ex.com/feed/'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $r ) { return $r instanceof WP_Error; }
class WP_Error { function get_error_message() { return 'boom'; } }
function wp_remote_request( $url, $args ) { $GLOBALS['st']['calls'][] = array( $url, $args ); return $GLOBALS['st']['api']['wp_error'] ?? array( 'body' => json_encode( $GLOBALS['st']['api'] ) ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function get_transient( $k ) { return $GLOBALS['st']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['st']['transients'][ $k ] = $v; return true; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-cloudflare.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function post( $id, $name, $type = 'post', $author = 3 ) { $p = (object) array( 'ID' => $id, 'post_name' => $name, 'post_type' => $type, 'post_author' => $author, 'post_status' => 'publish', 'post_parent' => 0, 'post_date' => '2026-01-01' ); $GLOBALS['st']['posts'][ $id ] = $p; return $p; }
function fire( $tag, ...$args ) { foreach ( $GLOBALS['st']['hooks'][ $tag ] ?? array() as $h ) { call_user_func_array( $h[0], array_slice( $args, 0, $h[2] ) ); } }
function reset_class() { $r = new ReflectionClass( 'BEPLUSPB_Cloudflare' ); foreach ( array( 'queue' => array( 'posts' => array(), 'urls' => array() ), 'flush_hooked' => false ) as $prop => $val ) { $p = $r->getProperty( $prop ); $p->setAccessible( true ); $p->setValue( null, $val ); } }
function boot( $opts = null ) { reset_state( $opts ); reset_class(); BEPLUSPB_Cloudflare::register_hooks(); }
function bodies() { return array_map( static fn( $c ) => json_decode( $c[1]['body'], true ), $GLOBALS['st']['calls'] ); }
$C = 'BEPLUSPB_Cloudflare';

// ---- Nothing registered unless on and configured --------------------------------
foreach ( array( array( 'cloudflare_purge_on_edit' => 1, 'cloudflare_zone_id' => 'z', 'cloudflare_api_token' => 't' ), array( 'cloudflare_enabled' => 1, 'cloudflare_zone_id' => 'z', 'cloudflare_api_token' => 't' ), array( 'cloudflare_enabled' => 1, 'cloudflare_purge_on_edit' => 1, 'cloudflare_api_token' => 't' ), array( 'cloudflare_enabled' => 1, 'cloudflare_purge_on_edit' => 1, 'cloudflare_zone_id' => 'z' ) ) as $i => $o ) {
	boot( $o );
	t_ok( array() === $GLOBALS['st']['hooks'], "case $i: not enabled / no zone / no token registers nothing" );
}
boot();
t_ok( isset( $GLOBALS['st']['hooks']['transition_post_status'], $GLOBALS['st']['hooks']['post_updated'] ), 'hooks registered when on and configured' );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'the shutdown flush is only added once something is queued' );

// ---- Publishing / updating a post purges its own addresses once ------------------
$p = post( 5, 'hello' );
fire( 'transition_post_status', 'publish', 'publish', $p ); fire( 'transition_post_status', 'publish', 'publish', $p ); fire( 'post_updated', 5, $p, $p );
t_ok( 1 === count( $GLOBALS['st']['hooks']['shutdown'] ), 'one shutdown hook however many saves' );
BEPLUSPB_Cloudflare::flush_queue();
$b = bodies();
t_ok( 1 === count( $b ), 'one API call for the request' );
t_ok( 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache' === $GLOBALS['st']['calls'][0][0] && 'POST' === $GLOBALS['st']['calls'][0][1]['method'], 'POST /zones/{id}/purge_cache' );
t_ok( 'Bearer TOKEN-SECRET' === $GLOBALS['st']['calls'][0][1]['headers']['Authorization'] && 8 === $GLOBALS['st']['calls'][0][1]['timeout'], 'Bearer token, short timeout' );
foreach ( array( 'https://ex.com/hello/', 'https://ex.com/', 'https://ex.com/category/news/', 'https://ex.com/author/3/', 'https://ex.com/feed/' ) as $u ) {
	t_ok( in_array( $u, $b[0]['files'], true ), "purges $u" );
}
t_ok( 5 === count( $b[0]['files'] ) && ! in_array( 'https://ex.com/category/tips/', $b[0]['files'], true ), 'exactly the five addresses; a term whose link is an error is skipped, and a missing posts page adds nothing' );
$last = $C::last_auto_purge();
t_ok( true === $last['success'] && 'files' === $last['mode'] && $last['count'] === count( $b[0]['files'] ) && false === strpos( json_encode( $last ), 'TOKEN-SECRET' ) && false === strpos( json_encode( $last ), 'ex.com' ), 'the stored result has no token and no URLs' );
BEPLUSPB_Cloudflare::flush_queue();
t_ok( 1 === count( $GLOBALS['st']['calls'] ), 'a second flush has nothing to do' );

// Custom post type: its archive, no posts page.
boot(); $b1 = post( 6, 'moby', 'book' ); fire( 'transition_post_status', 'draft', 'publish', $b1 ); BEPLUSPB_Cloudflare::flush_queue();
$files = bodies()[0]['files'];
t_ok( in_array( 'https://ex.com/books/', $files, true ) && ! in_array( 'https://ex.com/?p=9', $files, true ), 'custom post type: its archive is purged' );

// Trashed: the address visitors had, not the "__trashed" one.
boot(); $t = post( 7, 'gone__trashed' ); fire( 'transition_post_status', 'trash', 'publish', $t ); BEPLUSPB_Cloudflare::flush_queue();
$files = bodies()[0]['files'];
t_ok( in_array( 'https://ex.com/gone/', $files, true ) && ! in_array( 'https://ex.com/gone__trashed/', $files, true ), 'trashed post: the original address is purged' );

// New draft / draft saves never purge.
boot(); $d = post( 8, 'draft' );
fire( 'transition_post_status', 'draft', 'draft', $d ); fire( 'transition_post_status', 'draft', 'auto-draft', $d ); fire( 'transition_post_status', 'pending', 'draft', $d );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'drafts and pending posts do not purge' );

// Revisions, non-viewable types.
boot(); $GLOBALS['st']['revision'] = true; fire( 'transition_post_status', 'publish', 'publish', post( 10, 'rev' ) );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'revisions are ignored' );
boot(); $GLOBALS['st']['viewable'] = false; fire( 'transition_post_status', 'publish', 'publish', post( 11, 'tpl', 'wp_template' ) );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'non-public post types are ignored' );

// A changed slug also purges the OLD address.
boot(); $before = post( 12, 'old-slug' ); $after = clone $before; $after->post_name = 'new-slug'; $GLOBALS['st']['posts'][12] = $after;
fire( 'post_updated', 12, $after, $before ); fire( 'transition_post_status', 'publish', 'publish', $after ); BEPLUSPB_Cloudflare::flush_queue();
$files = bodies()[0]['files'];
t_ok( in_array( 'https://ex.com/old-slug/', $files, true ) && in_array( 'https://ex.com/new-slug/', $files, true ), 'old and new address are purged after a slug change' );
boot(); $same = post( 13, 'same' ); fire( 'post_updated', 13, $same, clone $same );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'no old address when nothing about the address changed' );
boot(); $dr = post( 14, 'was-draft' ); $dr->post_status = 'draft'; $af = clone $dr; $af->post_name = 'x-draft'; fire( 'post_updated', 14, $af, $dr );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'an unpublished post has no cached address to purge' );

// ---- Where it may run: never a visitor's page load ---------------------------------
boot(); $GLOBALS['st']['admin'] = false; fire( 'transition_post_status', 'publish', 'publish', post( 20, 'front' ) );
t_ok( ! isset( $GLOBALS['st']['hooks']['shutdown'] ), 'a front-end request never queues a purge' );
boot(); $GLOBALS['st']['admin'] = false; $GLOBALS['st']['cron'] = true; fire( 'transition_post_status', 'future', 'publish', post( 21, 'sched' ) );
t_ok( isset( $GLOBALS['st']['hooks']['shutdown'] ), 'WP-Cron (scheduled post) does' );

// ---- Failure handling and limits --------------------------------------------------
boot(); $GLOBALS['st']['api'] = array( 'success' => false, 'errors' => array( array( 'message' => 'Rate <b>limited</b>' ) ) );
fire( 'transition_post_status', 'publish', 'publish', post( 30, 'fail' ) ); BEPLUSPB_Cloudflare::flush_queue();
$last = $C::last_auto_purge();
t_ok( false === $last['success'] && 'Rate limited' === $last['message'], 'a Cloudflare error is stored (sanitised) and does not throw' );
boot(); $GLOBALS['st']['api'] = array( 'wp_error' => new WP_Error() ); fire( 'transition_post_status', 'publish', 'publish', post( 31, 'neterr' ) );
BEPLUSPB_Cloudflare::flush_queue(); t_ok( false === $C::last_auto_purge()['success'], 'a network error is handled' );

// Chunks of 30, and a bulk edit purges the zone once with a cooldown.
reset_state(); $urls = array(); for ( $i = 0; $i < 45; $i++ ) { $urls[] = "https://ex.com/p$i/"; }
$r = $C::purge_urls( $urls );
t_ok( true === $r['success'] && 'files' === $r['mode'] && array( 30, 15 ) === array_map( static fn( $b ) => count( $b['files'] ), bodies() ), '45 URLs are sent as 30 + 15' );
reset_state(); $urls = array(); for ( $i = 0; $i < 61; $i++ ) { $urls[] = "https://ex.com/p$i/"; }
$r = $C::purge_urls( $urls );
t_ok( 'all' === $r['mode'] && array( array( 'purge_everything' => true ) ) === bodies() && 'DELETE' === $GLOBALS['st']['calls'][0][1]['method'], 'over 60 URLs: one purge-everything call' );
$GLOBALS['st']['calls'] = array(); $r = $C::purge_urls( $urls );
t_ok( array() === $GLOBALS['st']['calls'] && false === $r['success'], 'a second bulk purge inside the cooldown is skipped' );

// ---- URL hygiene -------------------------------------------------------------------
$clean = $C::clean_urls( array( 'https://ex.com/a/', 'https://ex.com/a/', 'https://EX.com/b/?x=1', 'http://ex.com/c/', 'https://other.com/d/', 'https://ex.com:8443/e/', 'https://u:p@ex.com/f/', 'https://ex.com/g/#frag', "https://ex.com/h\n/", 'https://ex.com/"i', '//ex.com/j/', 'javascript:alert(1)', '', 5, null, str_repeat( 'a', 2100 ) ), 'https://ex.com/' );
t_ok( array( 'https://ex.com/a/', 'https://EX.com/b/?x=1' ) === $clean, 'only this site, same scheme and port, no credentials/fragments/control characters, once each' );
t_ok( array() === $C::clean_urls( array( 'https://ex.com/a/' ), '' ) && array() === $C::clean_urls( array( 'https://ex.com/a/' ), 'not a url' ), 'unusable home URL: nothing' );
t_ok( array( 'https://ex.com:8443/e/' ) === $C::clean_urls( array( 'https://ex.com:8443/e/', 'https://ex.com/x/' ), 'https://ex.com:8443/' ), 'a site on a custom port keeps only that port' );

// ---- The existing purge-everything button is untouched ------------------------------
reset_state(); $r = $C::purge_all();
t_ok( true === $r['success'] && 'DELETE' === $GLOBALS['st']['calls'][0][1]['method'], 'purge_all() still purges everything' );

// ---- Source guards -------------------------------------------------------------------
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-cloudflare.php' );
t_ok( 1 === substr_count( $src, 'wp_remote_request(' ), 'a single outbound call site' );
t_ok( false === strpos( $src, 'error_log' ) && false === strpos( $src, '$_GET' ) && false === strpos( $src, '$_POST' ) && false === strpos( $src, '$_REQUEST' ), 'nothing is logged and no request data is read: the URLs come from the post only' );
$boot = file_get_contents( dirname( __DIR__ ) . '/beplus-performance-booster.php' );
t_ok( 1 === preg_match( "/'cloudflare_purge_on_edit'\s*=> 0,/", $boot ) && false !== strpos( $boot, "array( 'BEPLUSPB_Cloudflare', 'register_hooks' )" ), 'default off, registered from plugins_loaded' );

echo "OK ($n assertions)\n";
