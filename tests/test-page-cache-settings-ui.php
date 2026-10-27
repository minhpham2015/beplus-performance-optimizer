<?php
/**
 * Page Cache settings card: the status lives under the "Enable Page Cache" checkbox (no separate
 * "Status" row, nothing while the cache is off), and the removed "Enable Cache for Logged-In Users"
 * option really is gone. Renders the real card with WordPress stubbed.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
$root = dirname( __DIR__ );

function add_action() {} function add_filter() {}
function __( $s ) { return $s; }
function esc_html_e( $s ) { echo htmlspecialchars( $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function checked( $a, $b = true ) { echo (string) $a === (string) $b ? ' checked="checked"' : ''; }
function selected( $a, $b = true ) { echo (string) $a === (string) $b ? ' selected="selected"' : ''; }
function number_format_i18n( $n ) { return number_format( (int) $n ); }

class BEPLUSPB_Minify { public static function human_filesize( $b ) { return $b . ' B'; } }
class BEPLUSPB_Page_Cache {
	const MOBILE_MODES = array( 'auto', 'separate', 'same', 'bypass' );
	public static $status = array();
	public static $enabled = true;
	public static $stats_requested = null;
	public static function get_status( $with_stats = true ) { self::$stats_requested = $with_stats; return self::$status; }
	public static function is_enabled( $opts = null ) { return self::$enabled; }
	public static function sanitize_ttl_hours( $h ) { return (int) $h; }
	public static function sanitize_choice( $v, $allowed, $d ) { return in_array( $v, $allowed, true ) ? $v : $d; }
	public static function detected_vary_cookies() { return array(); }
	public static function purges_everything_on_post_change( $o = null ) { return false; }
}
require $root . '/includes/class-bepluspb-admin.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function healthy( array $over = array() ) {
	return array_merge(
		array(
			'enabled'   => true, 'wp_cache' => true, 'dropin' => 'ours', 'running' => true, 'config_ok' => true, 'hosts_ok' => true,
			'observers' => true, 'bypassed' => array(), 'mode' => 'dropin', 'root_exists' => true,
			'stats'     => array( 'files' => 124, 'bytes' => 3200000, 'truncated' => false ), 'errors' => array(),
		),
		$over
	);
}
function render( array $status, $enabled, array $opts_over = array() ) {
	BEPLUSPB_Page_Cache::$status  = $status;
	BEPLUSPB_Page_Cache::$enabled = $enabled;
	$opts = array_merge( array( 'page_cache_enabled' => $enabled ? 1 : 0, 'cache_enabled' => 1, 'page_cache_ttl' => 10, 'page_cache_exclude_cookies' => '', 'page_cache_mobile' => 'auto', 'page_cache_vary_cookies' => '', 'page_cache_purge_scope' => 'auto', 'page_cache_purge_options' => '' ), $opts_over );
	$m = new ReflectionMethod( 'BEPLUSPB_Admin', 'render_section_page_cache' );
	$m->setAccessible( true );
	ob_start();
	$m->invoke( null, $opts );
	return ob_get_clean();
}

// ---- Off: nothing to report, in particular no "Status" row and no "Off." ----------------------
$html = render( healthy( array( 'enabled' => false, 'mode' => 'off', 'dropin' => 'missing', 'config_ok' => false, 'hosts_ok' => false, 'observers' => false, 'bypassed' => array( 'Wordfence' ), 'errors' => array( 'stale error' ) ) ), false );
t_ok( false === strpos( $html, '>Status<' ) && false === strpos( $html, 'Off.' ), 'no "Status" row and no "Off." while the cache is off' );
t_ok( false === strpos( $html, 'bepluspb-page-cache-status' ) && false === strpos( $html, ' cached pages (' ) && false === strpos( $html, 'bepluspb-warn' ) && false === strpos( $html, 'Wordfence' ), 'nothing at all is reported while it is off, not even leftovers from an earlier run' );
t_ok( false !== strpos( $html, 'id="bepluspb_page_cache_enabled"' ) && false !== strpos( $html, 'bepluspb_page_cache_ttl' ), 'the settings themselves are still there' );
t_ok( false === BEPLUSPB_Page_Cache::$stats_requested, 'the cache directory is not walked while the cache is off' );

// ---- Switched on, but the master toggle is off --------------------------------------------------
$html = render( healthy( array( 'enabled' => false, 'mode' => 'off' ) ), false, array( 'page_cache_enabled' => 1, 'cache_enabled' => 0 ) );
t_ok( false !== strpos( $html, 'master Cache toggle on the Dashboard is off' ), 'ticked Page Cache with the master toggle off explains why nothing happens' );
t_ok( false === strpos( $html, 'Off.' ) && false === strpos( $html, ' cached pages (' ), '... without a status line' );
$html = render( healthy( array( 'enabled' => false, 'mode' => 'off' ) ), false, array( 'page_cache_enabled' => 0, 'cache_enabled' => 0 ) );
t_ok( false === strpos( $html, 'master Cache toggle on the Dashboard is off' ), 'unticked: no master-toggle warning' );

// ---- On and healthy: one status line directly under the checkbox ----------------------------------
$html = render( healthy(), true );
t_ok( true === BEPLUSPB_Page_Cache::$stats_requested, 'page count is computed only when the cache is on' );
$p_enable = strpos( $html, 'id="bepluspb_page_cache_enabled"' );
$p_status = strpos( $html, 'bepluspb-page-cache-status' );
$p_ttl    = strpos( $html, 'id="bepluspb_page_cache_ttl"' );
t_ok( false !== $p_status && $p_enable < $p_status && $p_status < $p_ttl, 'the status line sits between the "Enable Page Cache" checkbox and the next setting' );
t_ok( false !== strpos( $html, 'Active — served by the advanced-cache.php drop-in (fastest).' ) && false !== strpos( $html, '124 cached pages (3200000 B).' ), 'it names the mode and the page count / size' );
foreach ( array( 'bepluspb-warn', 'define( \'WP_CACHE\'', 'Another plugin already owns', 'Not run for pages served', 'Compatibility check' ) as $quiet ) {
	t_ok( false === strpos( $html, $quiet ), "a healthy cache shows no \"$quiet\" notice" );
}
t_ok( 1 === substr_count( $html, 'Enable Page Cache' ) && 0 === substr_count( $html, 'Status' ), 'one "Enable Page Cache" label and no "Status" label' );

// ---- Each notice appears only when it applies --------------------------------------------------------
$html = render( healthy( array( 'mode' => 'plugin', 'running' => false, 'wp_cache' => false, 'dropin' => 'missing' ) ), true );
t_ok( false !== strpos( $html, 'Active — served from the plugin' ) && false !== strpos( $html, 'define( \'WP_CACHE\', true );' ) && false !== strpos( $html, 'never edits wp-config.php' ), 'without WP_CACHE it shows the line to add (and says the plugin never edits wp-config.php)' );
$html = render( healthy( array( 'mode' => 'plugin', 'dropin' => 'foreign' ) ), true );
t_ok( false !== strpos( $html, 'Another plugin already owns wp-content/advanced-cache.php' ) && false === strpos( $html, 'define( \'WP_CACHE\'' ), 'a foreign drop-in is explained' );
$html = render( healthy( array( 'observers' => false ) ), true );
t_ok( false !== strpos( $html, 'Compatibility check' ), 'failed compatibility check is shown' );
$html = render( healthy( array( 'bypassed' => array( 'Wordfence (firewall / blocking)', 'Age Gate' ) ) ), true );
t_ok( false !== strpos( $html, 'Not run for pages served from the cache:' ) && false !== strpos( $html, 'Wordfence (firewall / blocking); Age Gate' ), 'plugins whose code is skipped are listed' );
$html = render( healthy( array( 'errors' => array( 'wp-content/cache is not writable' ) ) ), true );
t_ok( false !== strpos( $html, 'bepluspb-warn' ) && false !== strpos( $html, 'wp-content/cache is not writable' ), 'write errors are shown' );
$html = render( healthy( array( 'hosts_ok' => false ) ), true );
t_ok( false !== strpos( $html, 'site address changed' ), 'a stale host list is reported' );
$html = render( healthy( array( 'config_ok' => false, 'hosts_ok' => false ) ), true );
t_ok( false !== strpos( $html, 'missing or unreadable' ) && false === strpos( $html, 'site address changed' ), 'a missing config is reported instead of the host warning' );

// ---- The removed option is gone from every layer ------------------------------------------------------
$sources = array( 'main' => file_get_contents( $root . '/beplus-performance-booster.php' ), 'minify' => file_get_contents( $root . '/includes/class-bepluspb-minify.php' ) );
require_once __DIR__ . '/helpers/admin-source.php';
$sources['admin'] = bepluspb_admin_source();
foreach ( $sources as $name => $src ) {
	t_ok( false === strpos( $src, 'cache_for_logged_in' ), "$name no longer mentions cache_for_logged_in" );
}
t_ok( false === strpos( $sources['admin'], 'Enable Cache for Logged-In Users' ) && false === strpos( $sources['admin'], 'User-Based Exclusions' ), 'the checkbox and its card are gone from the admin UI' );

// ---- Boot order: administrators see the untouched page, everyone else is optimised ---------------------
$main      = $sources['main'];
$boot      = substr( $main, strpos( $main, 'function bepluspb_boot_frontend' ) );
$p_gate    = strpos( $boot, "current_user_can( 'manage_options' )" );
$p_minify  = strpos( $boot, 'BEPLUSPB_Minify::init( $opts )' );
$p_page    = strpos( $boot, 'BEPLUSPB_Page_Cache::init( $opts )' );
t_ok( false !== $p_gate && false !== $p_minify && $p_gate < $p_minify, 'Minify is initialised after the administrator gate (admins keep the original CSS/JS)' );
t_ok( false !== $p_page && $p_page < $p_gate, 'the Page Cache capture is still registered before the gate' );
t_ok( strpos( $boot, 'BEPLUSPB_JS::init( $opts )' ) > $p_gate && strpos( $boot, 'BEPLUSPB_HTML::init( $opts )' ) > $p_gate, 'every optimiser sits behind the same gate' );
t_ok( 1 === substr_count( $boot, 'BEPLUSPB_Minify::init' ), 'Minify is initialised exactly once' );
t_ok( 0 === substr_count( $sources['minify'], 'is_user_logged_in' ), 'Minify makes no login decision of its own' );
t_ok( 0 === substr_count( $sources['minify'], 'page_cache_disabled' ), 'the duplicate page-exclusion cache is gone' );

echo "PASS: $n page cache settings UI / logged-in gate assertions\n";
