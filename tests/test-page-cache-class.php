<?php
/**
 * Standalone tests for BEPLUSPB_Page_Cache (WordPress side) with WordPress stubbed.
 *
 * Covers the advanced-cache.php stub lifecycle and fail-open behaviour, the
 * guarded config, capture rules, and — per CLAUDE.md hard rule #7 — that every
 * content-changing event is wired to a purge.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/bepluspb-pcc-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/wp-content', 0755, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
// Work on a COPY of the plugin files: the test swaps the engine for a broken one.
mkdir( $tmp . '/plugin/lib', 0755, true );
mkdir( $tmp . '/plugin/includes', 0755, true );
copy( $root . '/lib/class-bepluspb-page-cache-engine.php', $tmp . '/plugin/lib/class-bepluspb-page-cache-engine.php' );
copy( $root . '/includes/class-bepluspb-page-cache.php', $tmp . '/plugin/includes/class-bepluspb-page-cache.php' );
define( 'BEPLUSPB_PLUGIN_DIR', $tmp . '/plugin/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['hooks']      = array();
$GLOBALS['transients'] = array();
$GLOBALS['cron']       = array();
$GLOBALS['opts']       = array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_ttl' => 10, 'page_cache_exclude_cookies' => "my_cookie\nbad cookie!", 'cache_exclude_pages' => "/members/\n", 'cdn_webp_avif' => 0 );
$GLOBALS['posts']      = array();

class WP_Post { public $slug = ''; public $autosave = false; public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_author = 1; public $post_date = '2026-03-04 10:00:00'; }
class WP_Error {}
function __( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function home_url( $p = '' ) { return 'https://example.com' . ( '' === $p ? '' : $p ); }
function site_url( $p = '' ) { return 'https://example.com' . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_action( $tag, $cb, $pri = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $pri, $args ); }
function apply_filters( $tag, $v ) { return $v; }
function do_action( $tag, ...$a ) { $GLOBALS['did'][] = $tag; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function bepluspb_default_options() { return array( 'cache_enabled' => 0, 'page_cache_enabled' => 0 ); }
function bepluspb_parse_exclude_list( $t ) { return $t ? array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ) : array(); }
function is_admin() { return false; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h ) { $GLOBALS['cron'][ $h ] = $t; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['cron'][ $h ] ); }
function is_user_logged_in() { return ! empty( $GLOBALS['logged_in'] ); }
function wp_is_post_autosave( $id ) { return ! empty( $GLOBALS['posts'][ $id ]->autosave ); }
function wp_is_post_revision( $id ) { return false; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function is_post_type_viewable( $t ) { return in_array( $t, array( 'post', 'page' ), true ); }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; return 'https://example.com/blog/' . ( is_object( $p ) && ! empty( $p->slug ) ? $p->slug : 'post-' . $id ) . '/'; }
function get_option( $k ) { return 'page_for_posts' === $k ? 0 : false; }
function get_post_type_archive_link( $t ) { return false; }
function get_object_taxonomies() { return array(); }
function get_author_posts_url( $id ) { return 'https://example.com/author/admin/'; }
function get_year_link( $y ) { return "https://example.com/$y/"; }
function get_month_link( $y, $m ) { return sprintf( 'https://example.com/%d/%02d/', $y, $m ); }
function get_day_link( $y, $m, $d ) { return sprintf( 'https://example.com/%d/%02d/%02d/', $y, $m, $d ); }
function wp_unslash( $v ) { return $v; }
function is_404() { return ! empty( $GLOBALS['q']['404'] ); }
function is_search() { return false; } function is_feed() { return false; } function is_preview() { return false; }
function is_customize_preview() { return false; } function is_embed() { return false; } function is_trackback() { return false; } function is_robots() { return false; }
function get_query_var( $v ) { return ''; }
function is_singular() { return ! empty( $GLOBALS['q']['singular'] ); }
function post_password_required() { return ! empty( $GLOBALS['q']['password'] ); }
class BEPLUSPB_Utils { public static function is_buffer_excluded_request() { return false; } }
class BEPLUSPB_Minify { public static function is_cache_disabled_for_page() { return ! empty( $GLOBALS['q']['disabled'] ); } }
function get_comment( $id ) { return (object) array( 'comment_post_ID' => 7 ); }

require BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function priv( $method, ...$args ) { $m = new ReflectionMethod( 'BEPLUSPB_Page_Cache', $method ); $m->setAccessible( true ); return $m->invoke( null, ...$args ); }
function set_ctx( $ctx ) { $p = new ReflectionProperty( 'BEPLUSPB_Page_Cache', 'ctx' ); $p->setAccessible( true ); $p->setValue( null, $ctx ); }
$PC = 'BEPLUSPB_Page_Cache';
$E  = 'BEPLUSPB_Page_Cache_Engine';
$html = '<!doctype html><html><body>' . str_repeat( 'x', 300 ) . '</body></html>';

// ---- Settings sanitisers ----------------------------------------------------
t_ok( 10 === $PC::sanitize_ttl_hours( 0 ) && 10 === $PC::sanitize_ttl_hours( 'abc' ) && 5 === $PC::sanitize_ttl_hours( '5' ) && 72 === $PC::sanitize_ttl_hours( 9999 ), 'TTL is clamped to 1..72 hours (default 10)' );
t_ok( "my_cookie\nother-1.x" === $PC::sanitize_cookie_list( "my_cookie\nbad cookie!\n../etc\nother-1.x\nmy_cookie" ), 'cookie list keeps only safe, unique names' );
t_ok( '' === $PC::sanitize_cookie_list( array( 'x' ) ), 'non-string cookie list is rejected' );

// ---- Enabled only with BOTH toggles ----------------------------------------
t_ok( $PC::is_enabled( array( 'cache_enabled' => 1, 'page_cache_enabled' => 1 ) ), 'enabled with both toggles' );
t_ok( ! $PC::is_enabled( array( 'cache_enabled' => 0, 'page_cache_enabled' => 1 ) ), 'master toggle off disables the page cache' );
t_ok( ! $PC::is_enabled( array( 'cache_enabled' => 1, 'page_cache_enabled' => 0 ) ), 'page cache toggle off disables it' );

// ---- Config -----------------------------------------------------------------
$cfg = $PC::build_config( $GLOBALS['opts'] );
t_ok( true === $cfg['enabled'] && 36000 === $cfg['ttl'] && array( 'example.com' ) === $cfg['hosts'], 'config: enabled, TTL seconds, host allowlist' );
t_ok( array( '/members/' ) === $cfg['exclude_paths'] && array( 'my_cookie', 'bad cookie!' ) === $cfg['exclude_cookies'], 'config carries exclusions' );
t_ok( false === $cfg['accept_variants'], 'no Accept variants unless WebP/AVIF swap is on' );
t_ok( true === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'cdn_webp_avif' => 1 ) ) )['accept_variants'], 'Accept variants follow the WebP/AVIF option' );
t_ok( $PC::write_config( $cfg ), 'config written and verified' );
$raw = file_get_contents( $E::config_file() );
t_ok( 0 === strpos( $raw, "<?php exit; ?>\n" ), 'config starts with the executable exit guard' );
t_ok( '' === (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $E::config_file() ) ), 'executing the config as PHP prints nothing' );
t_ok( 0 === ( fileperms( $E::config_file() ) & 0002 ), 'config is not world-writable' );
t_ok( $E::load_config() && 36000 === $E::load_config()['ttl'], 'engine reads the config the class wrote' );
$PC::delete_config();
t_ok( ! file_exists( $E::config_file() ), 'config removed' );

// ---- advanced-cache.php stub ------------------------------------------------
$stub = $PC::dropin_contents();
$stub_file = $tmp . '/stub-lint.php';
file_put_contents( $stub_file, $stub );
t_ok( false !== strpos( shell_exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $stub_file ) . ' 2>&1' ), 'No syntax errors' ), 'stub is valid PHP' );
t_ok( false !== strpos( $stub, $PC::DROPIN_MARKER ) && $PC::is_our_dropin( $stub_file ), 'stub carries the ownership marker' );
t_ok( false !== strpos( $stub, "defined( 'ABSPATH' )" ), 'stub refuses direct access' );

$dst = $PC::dropin_file();
file_put_contents( $dst, "<?php // another plugin's advanced-cache\n" );
$r = $PC::install_dropin();
t_ok( ! $r['success'] && "<?php // another plugin's advanced-cache\n" === file_get_contents( $dst ), 'a foreign advanced-cache.php is NOT overwritten' );
t_ok( true === $PC::uninstall_dropin() && file_exists( $dst ), 'uninstall leaves a foreign advanced-cache.php alone' );
unlink( $dst );
t_ok( $PC::install_dropin()['success'] && $stub === file_get_contents( $dst ), 'stub installed' );
$before = filemtime( $dst ); clearstatcache();
t_ok( $PC::install_dropin()['success'], 'install is idempotent' );
t_ok( 'ours' === $PC::get_status()['dropin'], 'status recognises our stub' );

// Run the real installed stub in a subprocess: HIT, fail-open when engine missing, fail-open on a throwing engine.
$ctxSrv = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/hit/', 'HTTPS' => 'on' );
$PC::write_config( $cfg );
$E::reset();
$E::store( $E::request_context( $ctxSrv, array() ), $html );
$prepend = $tmp . '/prepend.php';
file_put_contents( $prepend, '<?php $_SERVER=array_merge($_SERVER,json_decode(getenv("T_SERVER"),true));$_COOKIE=array();' );
$run = $tmp . '/run-stub.php';
file_put_contents( $run, '<?php define("ABSPATH",' . var_export( ABSPATH, true ) . ');define("WP_CONTENT_DIR",' . var_export( WP_CONTENT_DIR, true ) . ');require ' . var_export( $dst, true ) . ';echo "FELLTHROUGH:".(defined("BEPLUSPB_PC_DROPIN")?"marked":"unmarked");' );
$exec = static function () use ( $run, $prepend, &$ctxSrv ) {
	return (string) shell_exec( 'T_SERVER=' . escapeshellarg( json_encode( $ctxSrv ) ) . ' ' . escapeshellarg( PHP_BINARY ) . ' -n -d auto_prepend_file=' . escapeshellarg( $prepend ) . ' ' . escapeshellarg( $run ) . ' 2>&1' );
};
$out = $exec();
t_ok( 0 === strpos( $out, '<!doctype html>' ) && false === strpos( $out, 'FELLTHROUGH' ), 'installed stub serves a cached page and stops' );
$ctxSrv['REQUEST_URI'] = '/miss/';
t_ok( 'FELLTHROUGH:marked' === $exec(), 'stub falls through on a miss and sets BEPLUSPB_PC_DROPIN' );
$ctxSrv['REQUEST_URI'] = '/hit/';
rename( $PC::engine_file(), $PC::engine_file() . '.bak' );
$out = $exec();
rename( $PC::engine_file() . '.bak', $PC::engine_file() );
t_ok( 'FELLTHROUGH:marked' === $out, 'stub fails open when the engine file is gone (plugin deleted/moved)' );
// Engine that throws.
$real = file_get_contents( $PC::engine_file() );
file_put_contents( $PC::engine_file(), "<?php\nthrow new RuntimeException('boom');\n" );
$out = $exec();
file_put_contents( $PC::engine_file(), $real );
t_ok( 'FELLTHROUGH:marked' === $out, 'stub fails open when the engine throws' );
$out = $exec();
t_ok( 0 === strpos( $out, '<!doctype html>' ), 'engine restored and serving again' );

t_ok( true === $PC::uninstall_dropin() && ! file_exists( $dst ), 'our stub is removed on uninstall' );

// ---- Fallback serving is skipped once the drop-in ran -----------------------
// (maybe_serve_fallback() returns immediately when BEPLUSPB_PC_DROPIN is defined.)
$src = file_get_contents( $root . '/includes/class-bepluspb-page-cache.php' );
t_ok( false !== strpos( $src, "defined( 'BEPLUSPB_PC_DROPIN' ) || ! is_file(" ), 'fallback only runs when the drop-in did not' );

// ---- Capture rules ----------------------------------------------------------
$E::reset();
$ctx = $E::request_context( $ctxSrv, array() );
set_ctx( $ctx );
@unlink( $ctx['file'] );
$START_FINAL = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL;
t_ok( $html === $PC::handle_buffer( $html, $START_FINAL ), 'buffer callback returns the output untouched' );
t_ok( is_file( $ctx['file'] ) && 0 === strpos( file_get_contents( $ctx['file'] ), $html ), 'complete page is stored' );
t_ok( false !== strpos( file_get_contents( $ctx['file'] ), '<!-- Page cached by Beplus Performance Booster on ' ), 'stored copy is stamped' );
unlink( $ctx['file'] );
$PC::handle_buffer( $html, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FLUSH );
$PC::handle_buffer( $html, PHP_OUTPUT_HANDLER_FINAL );
$PC::handle_buffer( $html, $START_FINAL | PHP_OUTPUT_HANDLER_CLEAN );
t_ok( ! file_exists( $ctx['file'] ), 'partial/flushed/discarded output is never stored' );
$PC::handle_buffer( '<html>tiny</html>', $START_FINAL );
$PC::handle_buffer( str_repeat( 'x', 400 ), $START_FINAL );
$PC::handle_buffer( '<html>' . str_repeat( 'x', 400 ), $START_FINAL );
t_ok( ! file_exists( $ctx['file'] ), 'fragments and non-documents are never stored' );
$GLOBALS['logged_in'] = true;
$PC::handle_buffer( $html, $START_FINAL );
t_ok( ! file_exists( $ctx['file'] ), 'a response generated for a logged-in user is never stored' );
$GLOBALS['logged_in'] = false;
define( 'DONOTCACHEPAGE', true );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( ! file_exists( $ctx['file'] ), 'DONOTCACHEPAGE is honoured' );
set_ctx( null );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( ! file_exists( $ctx['file'] ), 'no context => nothing stored' );

// ---- Invalidation wiring (hard rule #7) -------------------------------------
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( array( 'cache_enabled' => 0, 'page_cache_enabled' => 1 ) );
t_ok( array() === $GLOBALS['hooks'], 'no invalidation hooks while disabled' );
$PC::register_invalidation_hooks( $GLOBALS['opts'] );
foreach ( array( 'save_post', 'transition_post_status', 'deleted_post', 'comment_post', 'edit_comment', 'delete_comment', 'wp_set_comment_status', 'switch_theme', 'after_switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'edited_term', 'created_term', 'delete_term', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'updated_option', 'added_option', 'bepluspb_assets_cache_cleared', 'woocommerce_product_set_stock' ) as $hook ) {
	t_ok( ! empty( $GLOBALS['hooks'][ $hook ] ), "invalidation registered for $hook" );
}
$GLOBALS['hooks'] = array();
$PC::register_core_hooks();
t_ok( ! empty( $GLOBALS['hooks']['update_option_bepluspb_settings'] ) && ! empty( $GLOBALS['hooks']['add_option_bepluspb_settings'] ), 'every settings save re-syncs and purges (output may have changed)' );
t_ok( ! empty( $GLOBALS['hooks'][ $PC::CRON_HOOK ] ), 'GC cron callback registered' );

// Behaviour of the handlers.
$PC::write_config( $cfg );
$E::reset();
$page = static function ( $uri ) use ( $E, $html ) { $c = $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => $uri, 'HTTPS' => 'on' ), array() ); $E::store( $c, $html ); return $c['file']; };
$fill = static function () use ( $page ) { return array( 'post' => $page( '/blog/post-7/' ), 'other' => $page( '/blog/post-8/' ), 'home' => $page( '/' ), 'p2' => $page( '/page/2/' ), 'author' => $page( '/author/admin/' ), 'month' => $page( '/2026/03/' ) ); };

$p = new WP_Post(); $p->ID = 7; $GLOBALS['posts'][7] = $p;
$f = $fill();
$PC::on_save_post( 7, $p );
t_ok( ! is_file( $f['post'] ) && ! is_file( $f['home'] ) && ! is_file( $f['p2'] ) && ! is_file( $f['author'] ) && ! is_file( $f['month'] ), 'editing a published post purges it, home (+pagination), author and date archives' );
t_ok( is_file( $f['other'] ), 'an unrelated page stays cached' );

$f = $fill(); $E::reset();
$d = new WP_Post(); $d->ID = 9; $d->post_status = 'draft'; $GLOBALS['posts'][9] = $d;
$PC::on_save_post( 9, $d );
t_ok( is_file( $f['post'] ) && is_file( $f['home'] ), 'saving a draft purges nothing' );
$a = new WP_Post(); $a->ID = 10; $a->autosave = true; $GLOBALS['posts'][10] = $a;
$PC::on_save_post( 10, $a );
t_ok( is_file( $f['post'] ), 'autosaves purge nothing' );

$PC::on_transition_post_status( 'publish', 'publish', $p );
t_ok( is_file( $f['other'] ), 'publish -> publish transition does not purge everything' );
$PC::on_transition_post_status( 'draft', 'pending', $p );
t_ok( is_file( $f['other'] ), 'non-visible transitions do not purge' );
$E::reset();
$PC::on_transition_post_status( 'publish', 'draft', $p );
t_ok( ! is_file( $f['other'] ) && ! is_file( $f['home'] ), 'publishing purges every page (menus, widgets, listings)' );

$f = $fill(); $E::reset();
$PC::on_transition_post_status( 'trash', 'publish', $p );
t_ok( ! is_file( $f['other'] ), 'unpublishing/trashing purges every page' );

$f = $fill(); $E::reset();
$PC::on_comment_post( 5, 0 );
$PC::on_comment_post( 5, 'spam' );
t_ok( is_file( $f['post'] ), 'unapproved comments purge nothing' );
$PC::on_comment_post( 5, 1 );
t_ok( ! is_file( $f['post'] ) && is_file( $f['other'] ), 'an approved comment purges only its post (+listings)' );

$f = $fill(); $E::reset();
$PC::on_option_changed( 'widget_recent-posts' );
t_ok( ! is_file( $f['other'] ), 'widget changes purge everything' );
$f = $fill(); $E::reset();
$PC::on_option_changed( 'theme_mods_twentytwentyfive' );
t_ok( ! is_file( $f['other'] ), 'Customizer theme mods purge everything' );
$f = $fill(); $E::reset();
$PC::on_option_changed( '_transient_doing_cron' );
$PC::on_option_changed( 'active_plugins' );
t_ok( is_file( $f['other'] ), 'unrelated options (transients, cron) do not purge' );

// ---- sync() lifecycle -------------------------------------------------------
$E::reset();
$PC::sync( $GLOBALS['opts'] );
t_ok( is_file( $E::config_file() ) && file_exists( $PC::dropin_file() ) && isset( $GLOBALS['cron'][ $PC::CRON_HOOK ] ), 'sync(enabled) writes config, installs the stub and schedules GC' );
t_ok( $E::cache_root() && 0 === $E::count_files()['files'], 'sync purges stored pages (settings change the HTML)' );
$f = $fill(); $E::reset();
$PC::sync( array_merge( $GLOBALS['opts'], array( 'page_cache_enabled' => 0 ) ) );
t_ok( ! file_exists( $E::config_file() ) && ! file_exists( $PC::dropin_file() ) && ! isset( $GLOBALS['cron'][ $PC::CRON_HOOK ] ) && 0 === $E::count_files()['files'], 'sync(disabled) removes config, stub, schedule and pages' );
file_put_contents( $PC::dropin_file(), "<?php // foreign\n" );
$PC::sync( array_merge( $GLOBALS['opts'], array( 'page_cache_enabled' => 0 ) ) );
t_ok( "<?php // foreign\n" === file_get_contents( $PC::dropin_file() ), 'disabling never deletes a foreign advanced-cache.php' );
$E::reset();
$PC::sync( $GLOBALS['opts'] );
t_ok( ! empty( $GLOBALS['transients']['bepluspb_page_cache_error'] ) && "<?php // foreign\n" === file_get_contents( $PC::dropin_file() ), 'enabling next to a foreign drop-in reports it and falls back to plugin-level serving' );
t_ok( 'plugin' === $PC::get_status()['mode'], 'status reports plugin-level mode when the drop-in did not run' );

// ---- storable_context(): what may enter the cache ----------------------------
$PC::write_config( $cfg );
$base_server = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/about/' );
$_SERVER     = array_merge( $_SERVER, $base_server );
$_COOKIE     = array();
$GLOBALS['q'] = array();
$E::reset();
t_ok( is_array( priv( 'storable_context' ) ), 'a plain anonymous page is storable' );
$_SERVER['REQUEST_URI'] = '/about/?utm_source=x';
$E::reset();
t_ok( null !== $E::request_context( $_SERVER, array() ) && null === priv( 'storable_context' ), 'a tracking-parameter request may be served but is NEVER stored (URL-reflection poisoning)' );
$_SERVER['REQUEST_URI'] = '/about/?';
t_ok( null === priv( 'storable_context' ), 'even an empty query string is not stored' );
$_SERVER['REQUEST_URI'] = '/about/';
$GLOBALS['q'] = array( '404' => true );
t_ok( null === priv( 'storable_context' ), '404 is not stored' );
$GLOBALS['q'] = array( 'singular' => true, 'password' => true );
t_ok( null === priv( 'storable_context' ), 'password-protected post is not stored' );
$GLOBALS['q'] = array( 'disabled' => true );
t_ok( null === priv( 'storable_context' ), 'per-post disable / exclusion pattern is honoured' );
$GLOBALS['q'] = array();
$GLOBALS['logged_in'] = true;
t_ok( null === priv( 'storable_context' ), 'logged-in user is not stored' );
$GLOBALS['logged_in'] = false;
$_COOKIE = array( 'woocommerce_items_in_cart' => '1' );
t_ok( null === priv( 'storable_context' ), 'cart cookie is not stored' );
$_COOKIE = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
t_ok( null === priv( 'storable_context' ), 'POST is not stored' );
$_SERVER['REQUEST_METHOD'] = 'GET';
t_ok( is_array( priv( 'storable_context' ) ), 'and a plain page is storable again' );

// ---- Content that changes after save_post, removed terms, renamed slug ------
$E::reset();
$f = $fill();
$PC::on_set_object_terms( 7, array( 3 ), array( 3 ), 'category', false, array( 3 ) );
t_ok( is_file( $f['other'] ), 'unchanged terms purge nothing' );
$PC::on_set_object_terms( 7, array( 4 ), array( 4 ), 'category', false, array( 3 ) );
t_ok( ! is_file( $f['other'] ), 'moving a published post between categories purges every archive it left' );
$GLOBALS['posts'][11] = ( function () { $d = new WP_Post(); $d->ID = 11; $d->post_status = 'draft'; return $d; } )();
$E::reset(); $f = $fill();
$PC::on_set_object_terms( 11, array( 4 ), array( 4 ), 'category', false, array( 3 ) );
t_ok( is_file( $f['other'] ), 'term changes on a draft purge nothing' );

$E::reset(); $f = $fill();
$old = new WP_Post(); $old->ID = 7; $old->slug = 'post-7'; $new = new WP_Post(); $new->ID = 7; $new->slug = 'renamed';
$GLOBALS['posts'][7] = $new;
$PC::on_after_insert_post( 7, $new, true, $old );
t_ok( ! is_file( $f['post'] ), 'wp_after_insert_post purges the post (REST saves set terms/meta after save_post)' );
$E::reset(); $f = $fill();
$GLOBALS['posts'][7] = $p;
$p->slug = 'post-7';
$PC::on_after_insert_post( 7, $p, true, $p );
t_ok( is_file( $f['other'] ), 'post_before identical => no extra purge of other pages' );
$page( '/blog/old-slug/' );
$old2 = new WP_Post(); $old2->ID = 7; $old2->slug = 'old-slug'; $new2 = new WP_Post(); $new2->ID = 7; $new2->slug = 'post-7';
$PC::on_after_insert_post( 7, $new2, true, $old2 );
t_ok( ! is_file( $E::cache_root() . '/example.com/blog/old-slug/index-s.html' ), 'a renamed slug purges the OLD URL as well' );
$dr = new WP_Post(); $dr->ID = 12; $dr->post_status = 'draft'; $GLOBALS['posts'][12] = $dr;
$E::reset(); $f = $fill();
$PC::on_after_insert_post( 12, $dr, false, null );
t_ok( is_file( $f['post'] ), 'wp_after_insert_post on a draft purges nothing' );

// ---- Repeated purges report truthfully (Purge ALL runs two in a row) --------
$E::reset(); $f = $fill();
$r1 = $E::purge_all();
$r2 = $E::purge_all();
t_ok( $r1['deleted'] >= 4 && 'success' === $r1['status'] && 0 === $r2['matched'] && 'success' === $r2['status'], 'second purge is a real (empty) pass, not a cached "success"' );
$f = $fill();
$r3 = $E::purge_all();
t_ok( $r3['deleted'] >= 4 && ! is_file( $f['post'] ), 'a later purge in the same request still deletes newly stored pages' );
t_ok( false === strpos( file_get_contents( $root . '/lib/class-bepluspb-page-cache-engine.php' ), 'Content-Length' ), 'no Content-Length (zlib.output_compression / gzip would corrupt it)' );

// ---- Wiring in the main plugin file and other classes -----------------------
$main = file_get_contents( $root . '/beplus-performance-booster.php' );
t_ok( false !== strpos( $main, "add_action( 'plugins_loaded', 'bepluspb_register_invalidation_hooks'" ), 'invalidation hooks are registered from plugins_loaded (not the front-end boot)' );
t_ok( false !== strpos( $main, 'BEPLUSPB_Page_Cache::register_invalidation_hooks( $opts )' ), 'page cache invalidation registered' );
t_ok( strpos( $main, 'BEPLUSPB_Page_Cache::init( $opts )' ) < strpos( $main, 'optimize_admins' ), 'capture buffer registered before any optimiser buffer' );
t_ok( false !== strpos( $main, 'BEPLUSPB_Page_Cache::shutdown()' ), 'deactivation removes the drop-in' );
t_ok( false !== strpos( $src, "'template_redirect', array( __CLASS__, 'start_buffer' ), -100" ), 'capture buffer opens before the priority-0 optimiser buffers' );
$min = file_get_contents( $root . '/includes/class-bepluspb-minify.php' );
t_ok( substr_count( $min, "do_action( 'bepluspb_assets_cache_cleared' )" ) >= 2, 'asset purge and stale-variant deletion purge stored pages (they link those files)' );
t_ok( false !== strpos( file_get_contents( $root . '/includes/class-bepluspb-ucss.php' ), "do_action( 'bepluspb_assets_cache_cleared' )" ), 'UCSS purge also purges stored pages' );
t_ok( false !== strpos( file_get_contents( $root . '/uninstall.php' ), 'BEPLUSPB_Page_Cache::shutdown()' ), 'uninstall removes drop-in, config and pages' );
t_ok( false !== strpos( file_get_contents( $root . '/includes/admin/trait-bepluspb-admin-actions.php' ), "'page_cache'   => \$page" ), 'Purge ALL includes the page cache layer' );

// ---- Cleanup ----------------------------------------------------------------
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $file ) { $file->isLink() || $file->isFile() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() ); }
rmdir( $tmp );

echo "PASS: $n page cache class assertions\n";
