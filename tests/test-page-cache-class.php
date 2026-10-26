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
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['hooks']      = array();
$GLOBALS['transients'] = array();
$GLOBALS['cron']       = array();
$GLOBALS['opts']       = array( 'cache_enabled' => 1, 'page_cache_enabled' => 1, 'page_cache_ttl' => 10, 'page_cache_exclude_cookies' => "my_cookie\nbad cookie!", 'cache_exclude_pages' => "/members/\n", 'cdn_webp_avif' => 0 );
$GLOBALS['posts']      = array();

class WP_Post { public $post_title = 'Title'; public $post_name = 'slug'; public $post_parent = 0; public $menu_order = 0; public $post_content = 'body'; public $slug = ''; public $autosave = false; public $ID; public $post_type = 'post'; public $post_status = 'publish'; public $post_author = 1; public $post_date = '2026-03-04 10:00:00'; }
class WP_Error {}
function __( $s ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function home_url( $p = '' ) { return 'https://' . ( $GLOBALS['site_host'] ?? 'example.com' ) . ( '' === $p ? '' : $p ); }
function site_url( $p = '' ) { return 'https://' . ( $GLOBALS['site_host'] ?? 'example.com' ) . $p; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_action( $tag, $cb, $pri = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $pri, $args ); }
function add_filter( $tag, $cb, $pri = 10, $args = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $cb, $pri, $args ); }
function post_type_exists( $t ) { return in_array( $t, array( 'post', 'page', 'attachment', 'nav_menu_item', 'wp_template_part', 'wp_template', 'wp_global_styles', 'wp_navigation', 'wp_block', 'elementor_library', 'wpcf7_contact_form', 'scheduled-action', 'shop_order', 'product' ), true ); }
function current_user_can( $cap ) { return ! empty( $GLOBALS['can_edit'] ); }
function get_queried_object_id() { return $GLOBALS['queried'] ?? 0; }
class WP_Query { public $main = false; public $vars = array(); public function is_main_query() { return $this->main; } public function get( $k ) { return $this->vars[ $k ] ?? ''; } }
function apply_filters( $tag, $v ) { return $v; }
function do_action( $tag, ...$a ) { $GLOBALS['did'][] = $tag; }
function get_transient( $k ) { $GLOBALS['transient_reads'] = ( $GLOBALS['transient_reads'] ?? 0 ) + 1; return $GLOBALS['transients'][ $k ] ?? false; }
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
function is_post_type_viewable( $t ) { return in_array( $t, array( 'post', 'page', 'attachment', 'product' ), true ); }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; return 'https://example.com/blog/' . ( is_object( $p ) && ! empty( $p->slug ) ? $p->slug : 'post-' . $id ) . '/'; }
function get_option( $k, $d = false ) { return $GLOBALS['wpopts'][ $k ] ?? ( 'page_for_posts' === $k ? 0 : $d ); }
function get_bloginfo( $k ) { return $GLOBALS['wp_version'] ?? '6.5'; }
function user_can( $id, $cap ) { return ! empty( $GLOBALS['user_caps'][ $id ] ); }
function wp_is_mobile() { $v = ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) === 'MOBILE-UA'; if ( empty( $GLOBALS['broken_observers'] ) ) { foreach ( $GLOBALS['hooks']['wp_is_mobile'] ?? array() as $h ) { $v = call_user_func( $h[0], $v ); } } return $v; }
function get_comments( $args ) { if ( empty( $GLOBALS['broken_observers'] ) ) { $o = new stdClass(); $o->query_vars = array_merge( array( 'post_id' => 0 ), $args ); foreach ( $GLOBALS['hooks']['pre_get_comments'] ?? array() as $h ) { call_user_func( $h[0], $o ); } } return array(); }
function get_post_type_archive_link( $t ) { return false; }
function get_object_taxonomies() { return array(); }
function get_author_posts_url( $id ) { return 'https://example.com/author/admin/'; }
function get_year_link( $y ) { return "https://example.com/$y/"; }
function get_month_link( $y, $m ) { return sprintf( 'https://example.com/%d/%02d/', $y, $m ); }
function get_day_link( $y, $m, $d ) { return sprintf( 'https://example.com/%d/%02d/%02d/', $y, $m, $d ); }
function wp_unslash( $v ) { return $v; }
function is_active_widget( $a, $b, $base ) { return in_array( $base, $GLOBALS['active_widgets'] ?? array(), true ); }
function wp_is_block_theme() { return ! empty( $GLOBALS['block_theme'] ); }
function get_stylesheet_directory() { return $GLOBALS['theme_dir']; }
function get_template_directory() { return $GLOBALS['theme_dir']; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function get_posts( $args ) { if ( empty( $GLOBALS['broken_observers'] ) ) { $q = new WP_Query(); $q->vars = $args; foreach ( $GLOBALS['hooks']['pre_get_posts'] ?? array() as $h ) { call_user_func( $h[0], $q ); } } return $GLOBALS['template_parts'] ?? array(); }
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

// ---- Settings choices ---------------------------------------------------------
t_ok( 'same' === $PC::sanitize_choice( 'same', array( 'same', 'separate' ), 'separate' ) && 'separate' === $PC::sanitize_choice( 'x', array( 'same', 'separate' ), 'separate' ) && 'separate' === $PC::sanitize_choice( array( 'same' ), array( 'same', 'separate' ), 'separate' ), 'select options accept only allowed values' );

// ---- Device and language/currency variants in the config ------------------------
$c0 = $PC::build_config( $GLOBALS['opts'] );
t_ok( 'auto' === $c0['mobile'] && array() === $c0['vary_cookies'], 'config defaults: automatic device handling, no vary cookies' );
t_ok( 'bypass' === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'bypass' ) ) )['mobile'], 'mobile mode follows the setting' );
t_ok( 'auto' === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'nonsense' ) ) )['mobile'] && 'separate' === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'separate' ) ) )['mobile'], 'invalid mobile mode falls back to automatic; separate is still selectable' );
t_ok( array( 'my_lang' ) === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_vary_cookies' => "my_lang\n" ) ) )['vary_cookies'], 'user-listed vary cookies reach the config' );
t_ok( array() === $PC::detected_vary_cookies(), 'nothing detected without WPML/Polylang/WCML' );
define( 'ICL_SITEPRESS_VERSION', '4.6' );
define( 'POLYLANG_VERSION', '3.6' );
define( 'WCML_VERSION', '5.3' );
$det = $PC::detected_vary_cookies();
t_ok( array( 'wp-wpml_current_language', 'pll_language', 'wcml_client_currency', 'wcml_client_currency_language' ) === $det, 'WPML, Polylang and WCML cookies are auto-detected' );
$c1 = $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_vary_cookies' => "pll_language\nextra_cookie\nb\nc\nd\ne" ) ) );
t_ok( 8 === count( $c1['vary_cookies'] ) && 4 === count( array_unique( array_intersect( $c1['vary_cookies'], $det ) ) ) && 'extra_cookie' === $c1['vary_cookies'][4], 'detected + user cookies are merged, de-duplicated and capped at 8' );
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) );
$E::reset();
$cc = $E::load_config();
t_ok( in_array( 'pll_language', $cc['vary_cookies'], true ) && 'auto' === $cc['mobile'], 'engine reads mobile mode and vary cookies from the config' );

// ---- Vary: User-Agent is announced (separate mode only) --------------------------
$GLOBALS['hooks'] = array();
$PC::write_config( $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'separate' ) ) ) ); $E::reset();
$PC::init( $GLOBALS['opts'] );
$prios = array_column( $GLOBALS['hooks']['template_redirect'] ?? array(), 1 );
t_ok( in_array( -100, $prios, true ) && in_array( -101, $prios, true ), 'separate mode (taken from the engine config) registers the capture buffer and the Vary header' );
$GLOBALS['hooks'] = array();
$PC::write_config( $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'same' ) ) ) ); $E::reset();
$PC::init( $GLOBALS['opts'] );
t_ok( array( -100 ) === array_column( $GLOBALS['hooks']['template_redirect'] ?? array(), 1 ), '"same" mode sends no Vary: User-Agent' );
t_ok( array() === priv( 'replayable_headers' ), 'replayable headers are read through the engine allowlist' );
$main_src = file_get_contents( BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php' );
t_ok( false !== strpos( $main_src, 'self::replayable_headers()' ), 'the buffer callback records headers when it stores a page' );

// ---- Plugin (de)activation re-syncs, but never undoes our own deactivation --------
$E::reset();
$PC::delete_config();
$PC::on_plugin_toggled( 'beplus-performance-optimizer/beplus-performance-booster.php' );
t_ok( ! file_exists( $E::config_file() ), 'deactivating Beplus itself does not re-create the config/stub' );
$PC::on_plugin_toggled( 'polylang/polylang.php' );
t_ok( is_file( $E::config_file() ) && in_array( 'pll_language', $E::load_config()['vary_cookies'], true ), 'another plugin toggled => config rebuilt with its cookies' );
t_ok( false !== strpos( $main_src, "'activated_plugin', array( __CLASS__, 'on_plugin_toggled' ), 20, 1" ), 'activated/deactivated_plugin pass the plugin basename' );

// ---- Site-wide content lists decide how much an edit purges -----------------------
$GLOBALS['theme_dir'] = $tmp . '/theme';
mkdir( $GLOBALS['theme_dir'] . '/parts', 0755, true );
$reset_scan = static function () { $GLOBALS['transients'] = array(); $GLOBALS['active_widgets'] = array(); $GLOBALS['wpopts'] = array(); $GLOBALS['block_theme'] = false; $GLOBALS['template_parts'] = array(); };
$reset_scan();
t_ok( false === $PC::has_sitewide_dynamic_content(), 'nothing dynamic => related-only purge' );
foreach ( array( 'recent-posts', 'recent-comments', 'archives', 'calendar', 'tag_cloud', 'categories' ) as $w ) {
	$reset_scan(); $GLOBALS['active_widgets'] = array( $w );
	t_ok( true === $PC::has_sitewide_dynamic_content(), "classic widget $w is detected" );
}
$reset_scan(); $GLOBALS['active_widgets'] = array( 'text', 'search', 'nav_menu' );
t_ok( false === $PC::has_sitewide_dynamic_content(), 'static widgets (text, search, menu) are not site-wide lists' );
foreach ( array( 'latest-posts', 'latest-comments', 'archives', 'calendar', 'tag-cloud', 'categories', 'query' ) as $b ) {
	$reset_scan(); $GLOBALS['wpopts']['widget_block'] = array( 2 => array( 'content' => '<!-- wp:' . $b . ' {"x":1} /-->' ), '_multiwidget' => 1 );
	t_ok( true === $PC::has_sitewide_dynamic_content(), "block widget wp:$b is detected" );
}
$reset_scan(); $GLOBALS['wpopts']['widget_block'] = array( 2 => array( 'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph --><!-- wp:query-title /--><!-- wp:search /-->' ), '_multiwidget' => 1 );
t_ok( false === $PC::has_sitewide_dynamic_content(), 'unrelated blocks (paragraph, query-title, search) are not detected' );
$reset_scan(); $GLOBALS['block_theme'] = true; file_put_contents( $GLOBALS['theme_dir'] . '/parts/footer.html', '<!-- wp:group --><!-- wp:latest-posts {"postsToShow":3} /--><!-- /wp:group -->' );
t_ok( true === $PC::has_sitewide_dynamic_content(), 'block theme template part with Latest Posts is detected' );
$reset_scan(); $GLOBALS['block_theme'] = true; file_put_contents( $GLOBALS['theme_dir'] . '/parts/footer.html', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
t_ok( false === $PC::has_sitewide_dynamic_content(), 'static template part is not detected' );
$part = (object) array( 'post_content' => '<!-- wp:query {"queryId":1} --><!-- wp:post-template /--><!-- /wp:query -->' );
$reset_scan(); $GLOBALS['block_theme'] = true; $GLOBALS['template_parts'] = array( $part );
t_ok( true === $PC::has_sitewide_dynamic_content(), 'a user-edited template part with a Query Loop is detected' );
$reset_scan(); $GLOBALS['block_theme'] = false; file_put_contents( $GLOBALS['theme_dir'] . '/parts/footer.html', '<!-- wp:latest-posts /-->' );
t_ok( false === $PC::has_sitewide_dynamic_content(), 'template files are only scanned for block themes' );

// The answer is cached, and re-scanned when widgets or templates change.
$reset_scan(); $GLOBALS['active_widgets'] = array( 'recent-posts' );
t_ok( true === $PC::has_sitewide_dynamic_content(), 'scan result (true)' );
$GLOBALS['active_widgets'] = array();
t_ok( true === $PC::has_sitewide_dynamic_content(), 'result is cached between calls (no re-scan per post save)' );
$PC::on_option_changed( 'widget_recent-posts' );
t_ok( false === $PC::has_sitewide_dynamic_content(), 'changing a widget triggers a re-scan' );
$GLOBALS['active_widgets'] = array( 'archives' );
$PC::on_option_changed( 'sidebars_widgets' );
t_ok( true === $PC::has_sitewide_dynamic_content(), 'changing sidebars_widgets triggers a re-scan' );
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( $GLOBALS['opts'] );
foreach ( array( 'switch_theme', 'customize_save_after', 'save_post_wp_template_part', 'save_post_wp_template' ) as $hook ) {
	t_ok( in_array( array( $PC, 'flush_sitewide_detection' ), array_map( static fn( $h ) => array( $h[0][0], $h[0][1] ), $GLOBALS['hooks'][ $hook ] ?? array() ), true ) || count( array_filter( $GLOBALS['hooks'][ $hook ] ?? array(), static fn( $h ) => 'flush_sitewide_detection' === $h[0][1] ) ) > 0, "re-scan hooked to $hook" );
}

// ---- Purge scope -------------------------------------------------------------------
$reset_scan();
$scope = static function ( $v ) use ( $PC ) { return $PC::purges_everything_on_post_change( array( 'page_cache_purge_scope' => $v ) ); };
t_ok( true === $scope( 'all' ) && false === $scope( 'related' ) && false === $scope( 'auto' ), 'scope: all / related / auto (nothing dynamic)' );
$GLOBALS['active_widgets'] = array( 'recent-comments' ); $GLOBALS['transients'] = array();
t_ok( true === $scope( 'all' ) && false === $scope( 'related' ) && true === $scope( 'auto' ), 'scope: auto purges everything when a site-wide list exists, "related" never does' );
t_ok( true === $PC::purges_everything_on_post_change( array() ), 'missing setting behaves as auto' );
t_ok( true === $PC::purges_everything_on_post_change( array( 'page_cache_purge_scope' => 'bogus' ) ), 'invalid setting behaves as auto' );

$GLOBALS['opts']['page_cache_purge_scope'] = 'auto';
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) );
$E::reset(); $f = $fill();
$GLOBALS['active_widgets'] = array( 'recent-posts' ); $GLOBALS['transients'] = array();
$PC::on_save_post( 7, $p );
t_ok( ! is_file( $f['other'] ) && ! is_file( $f['post'] ), 'editing a post with a Recent Posts widget purges EVERY page (the widget is on all of them)' );
$E::reset(); $f = $fill();
$GLOBALS['active_widgets'] = array(); $GLOBALS['transients'] = array();
$PC::on_save_post( 7, $p );
t_ok( ! is_file( $f['post'] ) && is_file( $f['other'] ), 'without site-wide lists only the related pages are purged' );
$GLOBALS['active_widgets'] = array( 'recent-comments' ); $GLOBALS['transients'] = array(); $E::reset(); $f = $fill();
$PC::on_comment_post( 5, 1 );
t_ok( ! is_file( $f['other'] ), 'an approved comment purges everything when Recent Comments is showing' );
$GLOBALS['opts']['page_cache_purge_scope'] = 'related';
$E::reset(); $f = $fill();
$PC::on_save_post( 7, $p );
t_ok( is_file( $f['other'] ), '"related" scope still purges related pages only, widgets or not' );
unset( $GLOBALS['opts']['page_cache_purge_scope'] );
$GLOBALS['active_widgets'] = array();

// ---- Runtime observation: device-dependent pages and pages that list other content ----
$flag = static function ( $name, $value = null ) {
	$prop = new ReflectionProperty( 'BEPLUSPB_Page_Cache', $name );
	$prop->setAccessible( true );
	if ( null !== $value ) { $prop->setValue( null, $value ); }
	return $prop->getValue();
};
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( $GLOBALS['opts'] );
t_ok( ! empty( $GLOBALS['hooks']['wp_is_mobile'] ) && ! empty( $GLOBALS['hooks']['pre_get_posts'] ) && ! empty( $GLOBALS['hooks']['pre_get_comments'] ), 'observers are registered with the invalidation hooks (plugins_loaded, before themes boot)' );
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( array( 'cache_enabled' => 0, 'page_cache_enabled' => 1 ) );
t_ok( array() === $GLOBALS['hooks'], 'no observers while the cache is off' );

$flag( 'device_aware', false );
t_ok( true === $PC::observe_wp_is_mobile( true ) && false === $PC::observe_wp_is_mobile( false ) && true === $flag( 'device_aware' ), 'wp_is_mobile observer returns WordPress\' answer untouched and marks the page device-aware' );

$q = static function ( $types, $main = false ) { $o = new WP_Query(); $o->main = $main; if ( null !== $types ) { $o->vars['post_type'] = $types; } return $o; };
$check = static function ( $query ) use ( $PC, $flag ) { $flag( 'lists_others', false ); $PC::observe_posts_query( $query ); return $flag( 'lists_others' ); };
t_ok( false === $check( $q( 'post', true ) ), 'the main query never counts' );
t_ok( true === $check( $q( 'post' ) ) && true === $check( $q( null ) ) && true === $check( $q( '' ) ) && true === $check( $q( array( 'post', 'page' ) ) ) && true === $check( $q( 'product' ) ) && true === $check( $q( 'any' ) ), 'secondary queries for public content (default type, several types, any, custom) are flagged' );
t_ok( false === $check( $q( 'nav_menu_item' ) ) && false === $check( $q( 'wp_template_part' ) ) && false === $check( $q( 'attachment' ) ) && false === $check( $q( 'not_registered' ) ), 'menus, template parts, attachments and unknown types are not "other content"' );
t_ok( false === $check( 'not a query' ), 'non-query values are ignored' );

$cq = static function ( $post_id ) { $o = new stdClass(); $o->query_vars = array( 'post_id' => $post_id ); return $o; };
$cc_check = static function ( $query ) use ( $PC, $flag ) { $flag( 'lists_others', false ); $PC::observe_comments_query( $query ); return $flag( 'lists_others' ); };
$GLOBALS['queried'] = 42;
t_ok( false === $cc_check( $cq( 42 ) ), 'a page loading its OWN comments is not flagged' );
t_ok( true === $cc_check( $cq( 0 ) ) && true === $cc_check( $cq( 7 ) ), 'a comments query for other posts (Recent Comments) is flagged' );
$GLOBALS['queried'] = 0;

// Query-loop markup: only a loop with its own query lists content.
t_ok( false === $PC::markup_lists_content( '<!-- wp:query {"queryId":0,"query":{"perPage":10,"inherit":true}} --><!-- wp:post-template /--><!-- /wp:query -->' ), 'an inherited Query Loop (the archive being viewed) is not a site-wide list' );
t_ok( true === $PC::markup_lists_content( '<!-- wp:query {"queryId":1,"query":{"perPage":3,"inherit":false}} --><!-- wp:post-template /--><!-- /wp:query -->' ), 'a Query Loop with its own query is' );
t_ok( true === $PC::markup_lists_content( '<!-- wp:query {"queryId":1,"query":{"perPage":3}} -->' ) && true === $PC::markup_lists_content( '<!-- wp:query -->' ), 'a Query Loop without "inherit" defaults to its own query' );
t_ok( true === $PC::markup_lists_content( '<!-- wp:latest-posts /-->' ) && false === $PC::markup_lists_content( '<!-- wp:query-title /--><!-- wp:search /-->' ), 'Latest Posts is a list; query-title is not' );

// Capture: auto mode stores device-aware pages per device and marks pages that list others.
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) );
$E::reset();
$PC::init( $GLOBALS['opts'] );
$mk = static function ( $ua ) use ( $E ) { return $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/obs/', 'HTTPS' => 'on', 'HTTP_USER_AGENT' => $ua ), array() ); };
$IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
$DESK   = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537.36';
$cx = $mk( $DESK );
t_ok( null !== $cx['device_file'] && $cx['device_file'] !== $cx['file'] && 4 === count( array_filter( array( $cx['file'], $cx['device_file'], $cx['device_files'][0], $cx['device_files'][1] ) ) ), 'auto mode: shared file plus one file per device' );
$flag( 'device_aware', false ); $flag( 'lists_others', false );
set_ctx( $cx );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cx['file'] ) && ! is_file( $cx['file'] . '.lst' ) && ! is_file( substr( $cx['file'], 0, -5 ) . '.lst' ) && ! is_file( $cx['device_file'] ), 'page that never asked about the device => ONE shared copy, not flagged' );
$flag( 'device_aware', true ); $flag( 'lists_others', true );
set_ctx( $cx );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cx['device_file'] ) && ! is_file( $cx['file'] ) && is_file( substr( $cx['device_file'], 0, -5 ) . '.lst' ), 'device-aware page => per-device copy, shared copy removed, "lists others" marker written' );
$cm = $mk( $IPHONE );
set_ctx( $cm );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cm['device_file'] ) && $cm['device_file'] !== $cx['device_file'], 'mobile gets its own copy' );
$flag( 'device_aware', false ); $flag( 'lists_others', false );
set_ctx( $cx );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cx['file'] ) && ! is_file( $cx['device_file'] ) && ! is_file( $cm['device_file'] ) && ! is_file( substr( $cx['file'], 0, -5 ) . '.lst' ), 'page that is no longer device-aware drops the per-device copies and the marker' );
$flag( 'device_aware', false ); $flag( 'lists_others', false ); set_ctx( null );

// Purge: editing a post removes the pages that listed other content, and only those.
$E::reset(); $E::purge_all();
$plain  = $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/plain-page/', 'HTTPS' => 'on' ), array() );
$lister = $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/blog/has-related/', 'HTTPS' => 'on' ), array() );
$lister2 = $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/deep/er/lists-too/', 'HTTPS' => 'on' ), array() );
$refill = static function () use ( $E, $html, $plain, $lister, $lister2 ) { $E::store( $plain, $html ); $E::store( $lister, $html, array(), array( 'lists_others' => true ) ); $E::store( $lister2, $html, array(), array( 'lists_others' => true ) ); };
$GLOBALS['opts']['page_cache_purge_scope'] = 'auto';
$GLOBALS['active_widgets'] = array(); $GLOBALS['transients'] = array();
$PC::purge_all(); $refill();
$GLOBALS['posts'][7] = $p; $p->slug = 'post-7';
$PC::on_save_post( 7, $p );
t_ok( ! is_file( $lister['file'] ) && ! is_file( $lister2['file'] ) && is_file( $plain['file'] ), 'auto: pages that listed other content are purged wherever they live; a plain page is kept' );
t_ok( array() === glob( dirname( $lister['file'] ) . '/index*' ), 'their sidecars go with them' );
$GLOBALS['opts']['page_cache_purge_scope'] = 'related';
$PC::purge_all(); $refill();
$PC::on_save_post( 7, $p );
t_ok( is_file( $lister['file'] ) && is_file( $plain['file'] ), '"related" scope leaves them alone (the user opted out)' );
$GLOBALS['opts']['page_cache_purge_scope'] = 'auto';
$PC::purge_all(); $refill();
$PC::on_comment_post( 5, 1 );
t_ok( ! is_file( $lister['file'] ) && is_file( $plain['file'] ), 'an approved comment also drops pages that list other content' );
unset( $GLOBALS['opts']['page_cache_purge_scope'] );
$PC::purge_all();

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

// ---- #2: title / slug / parent / order of a published post is shown on OTHER pages --------
$E::reset(); $E::purge_all();
$mkpost = static function ( $over = array() ) { $x = new WP_Post(); $x->ID = 77; foreach ( $over as $k => $v ) { $x->$k = $v; } return $x; };
$cases = array(
	'title'      => array( 'post_title' => 'New title' ),
	'slug'       => array( 'post_name' => 'new-slug' ),
	'parent'     => array( 'post_parent' => 5 ),
	'menu order' => array( 'menu_order' => 3 ),
);
foreach ( $cases as $label => $change ) {
	$f = $fill(); $E::reset();
	$PC::on_post_updated( 77, $mkpost( $change ), $mkpost() );
	t_ok( ! is_file( $f['other'] ) && ! is_file( $f['home'] ), "changing a published post's $label purges every page (menus, breadcrumbs, page lists, children)" );
}
$f = $fill(); $E::reset();
$PC::on_post_updated( 77, $mkpost( array( 'post_content' => 'edited body' ) ), $mkpost() );
t_ok( is_file( $f['other'] ), 'a content-only edit does not purge everything (the targeted purge handles it)' );
$PC::on_post_updated( 77, $mkpost( array( 'post_title' => 'x', 'post_status' => 'draft' ) ), $mkpost( array( 'post_status' => 'draft' ) ) );
t_ok( is_file( $f['other'] ), 'renaming a draft purges nothing (it is not on any page)' );
$PC::on_post_updated( 77, $mkpost( array( 'post_title' => 'x', 'post_type' => 'wp_template_part' ) ), $mkpost( array( 'post_type' => 'wp_template_part' ) ) );
t_ok( is_file( $f['other'] ), 'non-viewable types are handled by the structural rule, not this one' );
$PC::on_post_updated( 77, $mkpost( array( 'post_title' => 'x', 'post_type' => 'nav_menu_item' ) ), $mkpost( array( 'post_type' => 'nav_menu_item' ) ) );
$PC::on_post_updated( 77, 'x', 'y' );
$PC::on_post_updated( 77, null, null );
t_ok( is_file( $f['other'] ), 'internal types and malformed arguments are ignored' );
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( $GLOBALS['opts'] );
t_ok( ! empty( $GLOBALS['hooks']['post_updated'] ), 'post_updated is registered with the invalidation hooks' );

// ---- #3: templates, template parts, global styles, navigation, blocks, builder libraries, forms ---
foreach ( array( 'wp_template_part', 'wp_template', 'wp_global_styles', 'wp_navigation', 'wp_block', 'elementor_library', 'wpcf7_contact_form' ) as $type ) {
	t_ok( true === $PC::is_structural_post_type( $type ), "$type counts as structural" );
	$GLOBALS['can_edit'] = true;
	$f = $fill(); $E::reset();
	$sp = $mkpost( array( 'post_type' => $type ) );
	$PC::on_save_post( 77, $sp );
	t_ok( ! is_file( $f['other'] ) && ! is_file( $f['home'] ), "an editor saving a $type purges every page" );
	$GLOBALS['can_edit'] = false;
	$f = $fill(); $E::reset();
	$PC::on_save_post( 77, $sp );
	t_ok( is_file( $f['other'] ), "a visitor/cron/queue creating a $type (no edit capability) purges nothing" );
}
$GLOBALS['can_edit'] = true;
$f = $fill(); $E::reset();
$PC::on_save_post( 77, $mkpost( array( 'post_type' => 'wp_template_part', 'post_status' => 'draft' ) ) );
$PC::on_save_post( 77, $mkpost( array( 'post_type' => 'wp_template_part', 'post_status' => 'auto-draft' ) ) );
t_ok( is_file( $f['other'] ), 'unpublished structural posts purge nothing' );
foreach ( array( 'scheduled-action', 'shop_order', 'nav_menu_item', 'revision', 'oembed_cache' ) as $noisy ) {
	t_ok( false === $PC::is_structural_post_type( $noisy ), "$noisy is ignored (logs, queues, orders, menu items)" );
	$PC::on_save_post( 77, $mkpost( array( 'post_type' => $noisy ) ) );
}
t_ok( is_file( $f['other'] ), 'noisy internal post types never purge, even for an editor' );
t_ok( false === $PC::is_structural_post_type( 'post' ) && false === $PC::is_structural_post_type( 'page' ) && false === $PC::is_structural_post_type( 'attachment' ) && false === $PC::is_structural_post_type( 'not_registered' ) && false === $PC::is_structural_post_type( array( 'x' ) ), 'public types and unknown types are not structural' );
$E::reset();
$PC::on_transition_post_status( 'draft', 'publish', $mkpost( array( 'post_type' => 'wp_template_part' ) ) );
t_ok( ! is_file( $f['other'] ), 'unpublishing a template part purges every page' );
$f = $fill(); $E::reset();
$GLOBALS['can_edit'] = false;
$PC::on_transition_post_status( 'draft', 'publish', $mkpost( array( 'post_type' => 'wp_template_part' ) ) );
t_ok( is_file( $f['other'] ), '... but not when no editor is involved' );
$GLOBALS['can_edit'] = true;
$PC::on_deleted_post( 77, $mkpost( array( 'post_type' => 'wp_block' ) ) );
t_ok( ! is_file( $f['other'] ), 'deleting a published reusable block purges every page' );
$f = $fill(); $E::reset();
$PC::on_deleted_post( 77, $mkpost( array( 'post_type' => 'scheduled-action' ) ) );
t_ok( is_file( $f['other'] ), 'deleting a queue record purges nothing' );
$PC::on_save_post( 77, $mkpost() ); // an ordinary post still takes the targeted path
t_ok( is_file( $f['other'] ), 'an ordinary post save keeps the targeted purge' );
$GLOBALS['can_edit'] = false;

// ---- #4: the host allowlist follows the site address --------------------------------------
$GLOBALS['site_host'] = 'example.com';
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) );
$E::reset();
t_ok( true === $PC::hosts_in_sync() && true === $PC::get_status( false )['hosts_ok'], 'in sync after the config is written' );
$GLOBALS['site_host'] = 'new-domain.test';
t_ok( false === $PC::hosts_in_sync() && false === $PC::get_status( false )['hosts_ok'], 'a changed site address is detected (wp-config constant, WP-CLI search-replace, SQL: no hook fires)' );
$GLOBALS['transients'] = array(); $GLOBALS['transient_reads'] = 0;
$f = $fill(); $E::reset();
$PC::init( $GLOBALS['opts'] );
t_ok( true === $PC::hosts_in_sync() && array( 'new-domain.test' ) === $E::load_config()['hosts'], 'the next front-end request rewrites the config for the new address' );
t_ok( ! is_file( $f['other'] ), '... and drops pages stored for the old one' );
$E::store( $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'new-domain.test', 'REQUEST_URI' => '/probe/' ), array() ), $html );
$GLOBALS['transient_reads'] = 0;
$PC::init( $GLOBALS['opts'] );
t_ok( 0 === $GLOBALS['transient_reads'], 'a healthy request reads no transient (no extra database query per page view)' );
t_ok( 1 === $E::count_files()['files'], 'and repairs nothing again' );
$GLOBALS['site_host'] = 'third.test';
$PC::init( $GLOBALS['opts'] );
t_ok( array( 'third.test' ) === $E::load_config()['hosts'], 'a second address change is repaired immediately (throttle is per address, not per 12 hours)' );
$GLOBALS['site_host'] = 'fourth.test';
$PC::write_config( array_merge( $PC::build_config( $GLOBALS['opts'] ), array( 'hosts' => array( 'third.test' ) ) ) );
$GLOBALS['transients'] = array();
$PC::on_option_changed( 'home' );
t_ok( array( 'fourth.test' ) === $E::load_config()['hosts'], 'saving the "home" option re-syncs the config' );
$GLOBALS['site_host'] = 'fifth.test';
$PC::on_option_changed( 'siteurl' );
t_ok( array( 'fifth.test' ) === $E::load_config()['hosts'], '... and so does "siteurl"' );
$E::reset(); $PC::delete_config();
t_ok( false === $PC::hosts_in_sync(), 'a missing config counts as out of sync' );
$GLOBALS['transients'] = array();
$PC::init( $GLOBALS['opts'] );
t_ok( is_file( $E::config_file() ) && array( 'fifth.test' ) === $E::load_config()['hosts'], 'a missing config is rebuilt on the next request' );
$GLOBALS['site_host'] = 'example.com';
$PC::on_option_changed( 'home' );
t_ok( array( 'example.com' ) === $E::load_config()['hosts'], 'back to the original address' );
$src_ui = file_get_contents( BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php' );
t_ok( false !== strpos( $src_ui, "'hosts_ok'" ), 'status exposes hosts_ok for the settings screen' );
$E::purge_all();

// ---- #5: nothing in the plugin depends on glob() --------------------------------------
$GLOBALS['theme_dir'] = $tmp . '/the[me]*?{x}';
mkdir( $GLOBALS['theme_dir'] . '/parts', 0755, true );
file_put_contents( $GLOBALS['theme_dir'] . '/parts/footer.html', '<!-- wp:latest-posts /-->' );
$GLOBALS['transients'] = array(); $GLOBALS['active_widgets'] = array(); $GLOBALS['wpopts'] = array(); $GLOBALS['template_parts'] = array(); $GLOBALS['block_theme'] = true;
t_ok( true === $PC::has_sitewide_dynamic_content(), 'block-theme template parts are found even when the theme path contains [ ] * ? { }' );
$GLOBALS['block_theme'] = false; $GLOBALS['transients'] = array();
t_ok( 0 === preg_match( '/[^_a-z]glob\(/', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php' ) ) ), 'the class calls glob() nowhere' );
t_ok( 0 === preg_match( '/[^_a-z]glob\(/', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( BEPLUSPB_PLUGIN_DIR . 'lib/class-bepluspb-page-cache-engine.php' ) ) ), 'the engine calls glob() nowhere' );

// ---- #7: the observation hooks are verified on the live WordPress ----------------------
$GLOBALS['broken_observers'] = false; $GLOBALS['transients'] = array(); $GLOBALS['wp_version'] = '6.5';
$flag( 'device_aware', true ); $flag( 'lists_others', true ); $flag( 'observed_mobile', true );
t_ok( true === $PC::observers_ok(), 'live check passes when device checks, post queries and comment queries are all reported' );
t_ok( true === $flag( 'device_aware' ) && true === $flag( 'lists_others' ) && true === $flag( 'observed_mobile' ) && false === $flag( 'self_test' ), 'the check restores the request state it borrowed' );
t_ok( is_array( $GLOBALS['transients'][ $PC::OBSERVER_TRANSIENT ] ) && true === $GLOBALS['transients'][ $PC::OBSERVER_TRANSIENT ]['ok'], 'the result is cached' );
$GLOBALS['broken_observers'] = true;
t_ok( true === $PC::observers_ok(), 'a cached result is not re-tested on every call' );
$GLOBALS['wp_version'] = '6.6';
t_ok( false === $PC::observers_ok(), 'a WordPress update triggers a new check (hooks silent => false)' );
$GLOBALS['wp_version'] = '6.5'; $GLOBALS['transients'] = array();
t_ok( false === $PC::observers_ok(), 'silent hooks are detected' );
$cfg_ok  = $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'auto' ) ) );
t_ok( 'separate' === $cfg_ok['mobile'], 'automatic mode falls back to a separate mobile copy when the hooks are silent' );
t_ok( 'same' === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'same' ) ) )['mobile'] && 'bypass' === $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'bypass' ) ) )['mobile'], 'explicit choices are never changed' );
t_ok( true === $PC::purges_everything_on_post_change( array( 'page_cache_purge_scope' => 'auto' ) ) && false === $PC::purges_everything_on_post_change( array( 'page_cache_purge_scope' => 'related' ) ), 'automatic purge scope clears everything when the hooks are silent; "related" is the user\'s explicit choice' );
t_ok( false === $PC::get_status( false )['observers'], 'status reports the failed check' );
$GLOBALS['transients']['x'] = 1;
$GLOBALS['transients'][ $PC::OBSERVER_TRANSIENT ] = array( 'wp' => '6.5', 'ok' => false );
$GLOBALS['broken_observers'] = false;
$PC::sync( $GLOBALS['opts'] );
t_ok( true === $PC::observers_ok() && 'auto' === $E::load_config()['mobile'], 'saving settings re-runs the check; once the hooks work again automatic mode returns' );
$GLOBALS['transients'] = array();
t_ok( true === $PC::get_status( false )['observers'], 'status reports a passing check' );

// A page answered by WordPress for another kind of device than the cache keyed it under is not stored.
$GLOBALS['hooks'] = array();
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) ); $E::reset();
$PC::init( $GLOBALS['opts'] );
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120';
$cdk = $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/mismatch/', 'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ), array() );
$E::purge_all();
$flag( 'device_aware', true ); $flag( 'observed_mobile', true ); set_ctx( $cdk );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( ! is_file( $cdk['file'] ) && ! is_file( $cdk['device_file'] ), 'WordPress said "mobile" for a visitor the cache classed as desktop: not stored' );
$flag( 'observed_mobile', false ); set_ctx( $cdk );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cdk['device_file'] ), 'agreement => stored' );
$E::purge_all();
$flag( 'device_aware', false ); $flag( 'observed_mobile', null ); set_ctx( $cdk );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $cdk['file'] ), 'a page that never asked is stored regardless' );
$E::purge_all();
$GLOBALS['hooks'] = array();
$PC::write_config( $PC::build_config( array_merge( $GLOBALS['opts'], array( 'page_cache_mobile' => 'same' ) ) ) ); $E::reset();
$PC::init( $GLOBALS['opts'] );
$flag( 'device_aware', true ); $flag( 'observed_mobile', true ); set_ctx( $E::request_context( array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'REQUEST_URI' => '/mismatch/' ), array() ) );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( is_file( $E::cache_root() . '/example.com/mismatch/index.html' ), '"same" mode makes no device check (the user chose one copy for everybody)' );
$E::purge_all(); $flag( 'device_aware', false ); $flag( 'observed_mobile', null ); set_ctx( null );
unset( $_SERVER['HTTP_USER_AGENT'] );
$PC::write_config( $PC::build_config( $GLOBALS['opts'] ) ); $E::reset();

// ---- #6: more settings and people that change every page -------------------------------
$GLOBALS['opts']['page_cache_purge_options'] = "my_theme_options\noptions_*\n";
foreach ( array( 'site_icon', 'site_logo', 'blogname', 'show_avatars', 'sticky_posts', 'start_of_week', 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_enable_reviews', 'wpmm_settings', 'widget_text', 'theme_mods_x', 'my_theme_options', 'options_header_logo', 'options_' ) as $o ) {
	t_ok( true === $PC::option_clears_cache( $o ), "option $o clears the cache" );
}
foreach ( array( 'active_plugins', 'cron', 'woocommerce_queue_flush_rewrite_rules', 'woocommerce_admin_notices', 'my_theme_options_extra', 'option_header', '_transient_doing_cron', '_site_transient_update_core', '_transient_woocommerce_cache', 'rewrite_rules' ) as $o ) {
	t_ok( false === $PC::option_clears_cache( $o ), "option $o does not (runtime bookkeeping)" );
}
t_ok( false === $PC::option_clears_cache( array( 'x' ) ) && false === $PC::option_clears_cache( null ), 'non-string option names are ignored' );
$GLOBALS['opts']['page_cache_purge_options'] = "_transient_my*\n_site_transient_*";
t_ok( false === $PC::option_clears_cache( '_transient_my_cache' ) && false === $PC::option_clears_cache( '_site_transient_update_core' ), 'transients never clear the cache, even when a user prefix would match them (they change on page views)' );
unset( $GLOBALS['opts']['page_cache_purge_options'] );
t_ok( false === $PC::option_clears_cache( 'my_theme_options' ) && false === $PC::option_clears_cache( 'options_header_logo' ), 'user entries apply only while listed' );
t_ok( "my_theme_options\noptions_*\nredux.v2:x" === $PC::sanitize_option_names( "my_theme_options\n options_* \nbad name\n*\n<script>\nmy_theme_options\nredux.v2:x,\n../etc\n" ), 'option-name list keeps only safe, unique names and prefixes' );
t_ok( '' === $PC::sanitize_option_names( array( 'x' ) ) && 50 === count( explode( "\n", $PC::sanitize_option_names( implode( "\n", array_map( static fn( $i ) => 'opt_' . $i, range( 1, 80 ) ) ) ) ) ), 'non-strings are rejected and the list is capped at 50' );
$E::reset(); $f = $fill();
$PC::on_option_changed( 'site_icon' );
t_ok( ! is_file( $f['other'] ), 'changing the site icon purges every page' );
$f = $fill(); $E::reset();
$GLOBALS['user_caps'] = array( 5 => true, 6 => false );
$PC::on_user_changed( 6 );
t_ok( is_file( $f['other'] ), 'a subscriber or customer editing their account purges nothing' );
$PC::on_user_changed( 5 );
t_ok( ! is_file( $f['other'] ), 'an author or editor changing their name, bio or avatar purges every page (bylines, author boxes)' );
$GLOBALS['hooks'] = array();
$PC::register_invalidation_hooks( $GLOBALS['opts'] );
t_ok( ! empty( $GLOBALS['hooks']['profile_update'] ) && ! empty( $GLOBALS['hooks']['deleted_user'] ), 'profile_update and deleted_user are registered' );

// ---- #8: say what a stored page skips ------------------------------------------------------
$GLOBALS['wpopts']['active_plugins'] = array( 'akismet/akismet.php', 'wordfence/wordfence.php', 'wp-statistics/wp-statistics.php' );
$byp = $PC::bypassed_plugins();
t_ok( 2 === count( $byp ) && false !== strpos( $byp[0], 'Wordfence' ) && false !== strpos( $byp[1], 'WP Statistics' ), 'active security / counting plugins are listed' );
$GLOBALS['wpopts']['active_plugins'] = array( 'akismet/akismet.php', 'wordfence-fake-prefix/x.php' );
t_ok( array() === $PC::bypassed_plugins(), 'only exact plugin folders match' );
$GLOBALS['wpopts'] = array();
t_ok( array() === $PC::bypassed_plugins() && array() === $PC::get_status( false )['bypassed'], 'nothing listed when none is active' );
t_ok( false !== strpos( file_get_contents( $root . '/uninstall.php' ), "'.gc'" ), 'uninstall removes the daily-cleanup marker' );

// WP-CLI imports have no logged-in user but still edit the site (constants cannot be undefined: kept last).
$GLOBALS['can_edit'] = false;
$f = $fill(); $E::reset();
define( 'WP_CLI', true );
$PC::on_save_post( 77, $mkpost( array( 'post_type' => 'wp_template_part' ) ) );
t_ok( ! is_file( $f['other'] ), 'under WP-CLI a template save purges every page even without a logged-in user' );

// DONOTCACHEPAGE is a constant (cannot be undefined), so it is exercised last.
$E::reset(); $E::purge_all();
$cn = $mk( $DESK );
set_ctx( $cn );
define( 'DONOTCACHEPAGE', true );
$PC::handle_buffer( $html, $START_FINAL );
t_ok( ! is_file( $cn['file'] ) && ! is_file( $cn['device_file'] ), 'DONOTCACHEPAGE is honoured' );
set_ctx( null );

// ---- Cleanup ----------------------------------------------------------------
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $file ) { $file->isLink() || $file->isFile() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() ); }
rmdir( $tmp );

echo "PASS: $n page cache class assertions\n";
