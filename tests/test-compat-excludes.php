<?php
/**
 * Automatic exclusions for popular plugins/themes (BEPLUSPB_Compat) and how the optimisers use them.
 * Run: php -n tests/test-compat-excludes.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'BEPLUSPB_VERSION', 'test' );
require_once __DIR__ . '/helpers/admin-source.php';

$GLOBALS['st'] = array( 'plugins' => array(), 'template' => 'twentytwentyfive', 'stylesheet' => 'twentytwentyfive', 'opts' => array(), 'woo' => null, 'filter' => null );
function add_action() {} function add_filter() {}
function __( $t, $d = '' ) { return $t; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function get_option( $k, $d = false ) { return 'active_plugins' === $k ? $GLOBALS['st']['plugins'] : $d; }
function get_template() { return $GLOBALS['st']['template']; }
function get_stylesheet() { return $GLOBALS['st']['stylesheet']; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function apply_filters( $tag, $value ) { return 'bepluspb_compat_registry' === $tag && is_callable( $GLOBALS['st']['filter'] ) ? call_user_func( $GLOBALS['st']['filter'], $value ) : $value; }
function bepluspb_parse_exclude_list( $t ) { return empty( $t ) ? array() : array_values( array_filter( array_map( 'trim', explode( "\n", $t ) ) ) ); }
function bepluspb_get_options() { return $GLOBALS['st']['opts']; }
function esc_attr( $s ) { return $s; } function esc_url( $s ) { return $s; }
function bepluspb_exclude_list( $opts, $kind ) { return BEPLUSPB_Compat::exclusions( $opts, $kind ); }
function wc_get_page_permalink( $page ) { $m = $GLOBALS['st']['woo']; return isset( $m[ $page ] ) ? $m[ $page ] : 'https://ex.com'; }
class WPS_Stub { function get_data( $h, $k ) { return false; } public $registered = array(); }
function wp_scripts() { return new WPS_Stub(); }

require dirname( __DIR__ ) . '/includes/class-bepluspb-compat.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-js.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function site( $plugins = array(), $template = 'twentytwentyfive', $stylesheet = null, $woo = null ) {
	$GLOBALS['st'] = array( 'plugins' => $plugins, 'template' => $template, 'stylesheet' => $stylesheet ?? $template, 'opts' => array(), 'woo' => $woo, 'filter' => null );
	BEPLUSPB_Compat::reset();
}
$on = array( 'auto_exclude' => 1, 'js_exclude' => '', 'css_exclude' => '', 'cache_exclude_pages' => '' );
$C  = 'BEPLUSPB_Compat';

// ---- The shipped registry is well formed ------------------------------------
$reg = $C::registry();
t_ok( count( $reg ) >= 6, 'rules ship with the plugin' );
foreach ( $reg as $id => $rule ) {
	t_ok( 1 === preg_match( '/^[a-z0-9_]{1,40}$/D', $id ) && ! empty( $rule['label'] ) && ! empty( $rule['note'] ), "$id has an id, a label and a note" );
	t_ok( ! empty( $rule['always'] ) || ! empty( $rule['plugins'] ) || ! empty( $rule['themes'] ), "$id says when it applies" );
	foreach ( $C::KINDS as $kind ) {
		$list = $rule[ $kind ] ?? array();
		if ( is_callable( $list ) ) { continue; }
		foreach ( $list as $kw ) {
			t_ok( $kw === $C::clean_keyword( $kw, 'pages' === $kind ) && strlen( $kw ) >= 4, "$id/$kind keyword is clean and specific: $kw" );
		}
	}
	t_ok( ! empty( $rule['js'] ) || ! empty( $rule['css'] ) || ! empty( $rule['pages'] ), "$id excludes something" );
}
// A keyword that is a bare word like "stripe" or "js" would match unrelated files.
foreach ( $reg as $id => $rule ) { foreach ( (array) ( $rule['js'] ?? array() ) as $kw ) { t_ok( false !== strpbrk( $kw, './-' ) || 'recaptcha' === $kw, "$id js keyword is not a bare word: $kw" ); } } // "recaptcha" is deliberately broad: it only ever appears in CAPTCHA scripts.

// ---- Detection ---------------------------------------------------------------
site();
t_ok( array( 'checkout_js' ) === $C::detected_ids(), 'a bare site only gets the always-on third-party script rule' );
site( array( 'woocommerce/woocommerce.php', 'akismet/akismet.php' ) );
t_ok( in_array( 'woocommerce', $C::detected_ids(), true ) && ! in_array( 'elementor', $C::detected_ids(), true ), 'plugin detected by its main file' );
site( array( 'elementor-pro/elementor-pro.php' ) );
t_ok( in_array( 'elementor', $C::detected_ids(), true ), 'any one of a rule\'s plugin files is enough' );
site( array(), 'Divi' );
t_ok( in_array( 'divi', $C::detected_ids(), true ), 'theme detected by folder name, case-insensitively' );
site( array(), 'divi', 'my-child' );
t_ok( in_array( 'divi', $C::detected_ids(), true ), 'a child theme of Divi is detected through the parent' );
site( array(), 'twentytwentyfive', 'divi' );
t_ok( in_array( 'divi', $C::detected_ids(), true ), '... and through the stylesheet folder' );
site( array( 'woocommerce-fake/woocommerce.php' ) );
t_ok( ! in_array( 'woocommerce', $C::detected_ids(), true ), 'a look-alike folder is not detected' );

// ---- Effective lists ---------------------------------------------------------
site( array( 'woocommerce/woocommerce.php' ), 'twentytwentyfive', null, array( 'cart' => 'https://ex.com/gio-hang/', 'checkout' => 'https://ex.com/thanh-toan', 'myaccount' => 'https://ex.com/tai-khoan/' ) );
$js = $C::exclusions( $on, 'js' );
t_ok( in_array( 'woocommerce/assets/js/', $js, true ) && in_array( 'js.stripe.com', $js, true ), 'js: Woo scripts and payment scripts' );
t_ok( array( 'woocommerce/assets/css/' ) === $C::exclusions( $on, 'css' ), 'css: Woo stylesheets' );
t_ok( array( '/gio-hang/', '/thanh-toan/', '/tai-khoan/' ) === $C::exclusions( $on, 'pages' ), 'pages: cart, checkout and account under the site\'s own (translated) addresses' );
t_ok( array() === $C::exclusions( $on, 'nonsense' ) && array() === $C::automatic( $on, 'nonsense' ), 'unknown kind: nothing' );

$mine = array_merge( $on, array( 'js_exclude' => "my-slider\nwoocommerce/assets/js/\n", 'cache_exclude_pages' => "/members/\n" ) );
$js   = $C::exclusions( $mine, 'js' );
t_ok( 'my-slider' === $js[0] && 1 === count( array_keys( $js, 'woocommerce/assets/js/', true ) ), 'the administrator\'s entries come first, duplicates are removed' );
t_ok( '/members/' === $C::exclusions( $mine, 'pages' )[0], 'own page exclusions come first' );

// Switched off / skipped.
t_ok( array( 'my-slider' ) === $C::exclusions( array_merge( $mine, array( 'auto_exclude' => 0, 'js_exclude' => 'my-slider' ) ), 'js' ), 'switch off: only the administrator\'s own list' );
t_ok( array() === $C::exclusions( array( 'js_exclude' => '' ), 'js' ), 'option missing: nothing automatic' );
$skip = array_merge( $on, array( 'auto_exclude_off' => "woocommerce\n" ) );
t_ok( ! in_array( 'woocommerce/assets/js/', $C::exclusions( $skip, 'js' ), true ) && in_array( 'js.stripe.com', $C::exclusions( $skip, 'js' ), true ), 'a skipped rule drops only its own keywords' );
t_ok( array() === $C::exclusions( $skip, 'pages' ), '... including its pages' );

// A shop page set as the front page (or no shop page at all) must not exclude every URL.
site( array( 'woocommerce/woocommerce.php' ), 'twentytwentyfive', null, array( 'cart' => 'https://ex.com/', 'checkout' => 'https://ex.com', 'myaccount' => 'https://ex.com/a/' ) );
t_ok( array() === $C::exclusions( $on, 'pages' ), 'root and one-letter paths are never used as page exclusions' );
site( array( 'woocommerce/woocommerce.php' ) );
t_ok( array() === $C::exclusions( $on, 'pages' ), 'WooCommerce pages not configured: no page rule' );

// ---- Keyword validation ------------------------------------------------------
foreach ( array( '', '  ', 'a"b', "a'b", 'a<b', "a\nb", 'a\\b', 'a`b', str_repeat( 'x', 201 ), 12, null, array( 'x' ) ) as $bad ) {
	t_ok( '' === $C::clean_keyword( $bad, false ), 'rejected keyword: ' . json_encode( $bad ) );
}
foreach ( array( '/', '//', '/a', 'cart/', '//evil.example/x', '/ok-path/' ) as $p ) {
	$ok = in_array( $p, array( '/ok-path/' ), true );
	t_ok( ( $ok ? $p : '' ) === $C::clean_keyword( $p, true ), "page keyword rule for $p" );
}

// A filter cannot smuggle in bad entries or bad ids.
site();
$GLOBALS['st']['filter'] = static function ( $rules ) {
	$rules['evil one']  = array( 'always' => true, 'js' => array( 'x' ) );
	$rules['ok_rule']   = array( 'label' => 'X', 'always' => true, 'js' => array( 'good/path.js', '"><script>', '', "a\nb", '/', 5 ), 'pages' => array( '/', '/pay/' ) );
	$rules['not_array'] = 'string';
	return $rules;
};
t_ok( array( 'ok_rule' ) === array_values( array_diff( array_keys( $C::registry() ), array_keys( array( 'woocommerce' => 1, 'elementor' => 1, 'divi' => 1, 'revslider' => 1, 'gravityforms' => 1, 'checkout_js' => 1 ) ) ) ), 'invalid rule ids and non-array rules are dropped' );
BEPLUSPB_Compat::reset();
t_ok( in_array( 'good/path.js', $C::automatic( $on, 'js' ), true ), 'filter keywords are used' );
$auto_js = $C::automatic( $on, 'js' );
t_ok( ! in_array( '"><script>', $auto_js, true ) && ! in_array( '', $auto_js, true ) && ! in_array( '/', $auto_js, true ), 'bad filter keywords are dropped' );
t_ok( array( '/pay/' ) === $C::automatic( $on, 'pages' ), 'filter page keywords go through the path rules' );
$GLOBALS['st']['filter'] = null; BEPLUSPB_Compat::reset();

// ---- Settings form -----------------------------------------------------------
t_ok( "woocommerce\nelementor" === $C::sanitize_skipped( array( 'woocommerce' => '1', 'elementor' => '1', 'divi' => '', 'nope' => '1', '<b>' => '1' ) ), 'checked boxes of known rules are kept, the rest dropped' );
t_ok( "woocommerce" === $C::sanitize_skipped( array( 'woocommerce', 'woocommerce', 'bogus' ) ), 'a plain list of ids works and is de-duplicated' );
t_ok( '' === $C::sanitize_skipped( 'woocommerce' ) && '' === $C::sanitize_skipped( null ), 'non-array input: nothing skipped' );
t_ok( array( 'a', 'b' ) === $C::skipped_ids( array( 'auto_exclude_off' => "a\n\nb\n" ) ), 'skipped ids parsed from the stored text' );

// ---- The overview the settings screen prints -----------------------------------
site( array( 'elementor/elementor.php' ) );
$ov = array_column( $C::overview( array_merge( $on, array( 'auto_exclude_off' => 'elementor' ) ) ), null, 'id' );
t_ok( $ov['elementor']['detected'] && $ov['elementor']['skipped'] && ! $ov['woocommerce']['detected'], 'overview: detected / skipped flags' );
t_ok( $ov['elementor']['counts']['js'] >= 1 && $ov['elementor']['counts']['css'] >= 1 && 0 === $ov['elementor']['counts']['pages'], 'overview: what each rule keeps out' );

// ---- The optimisers really use it ----------------------------------------------
$woo_tag = '<script src="https://ex.com/wp-content/plugins/woocommerce/assets/js/frontend/cart.min.js"></script>';
$woo_src = 'https://ex.com/wp-content/plugins/woocommerce/assets/js/frontend/cart.min.js';
$own_tag = '<script src="https://ex.com/wp-content/themes/t/app.js"></script>';
$own_src = 'https://ex.com/wp-content/themes/t/app.js';
site( array( 'woocommerce/woocommerce.php' ) );
$GLOBALS['st']['opts'] = $on;
t_ok( $woo_tag === BEPLUSPB_JS::defer_scripts( $woo_tag, 'wc-cart', $woo_src ), 'Defer leaves a WooCommerce script alone' );
t_ok( $woo_tag === BEPLUSPB_JS::delay_scripts( $woo_tag, 'wc-cart', $woo_src ), 'Delay (Simple) leaves a WooCommerce script alone' );
t_ok( false !== strpos( BEPLUSPB_JS::defer_scripts( $own_tag, 'app', $own_src ), ' defer src=' ), 'other scripts are still deferred' );
t_ok( false !== strpos( BEPLUSPB_JS::delay_scripts( $own_tag, 'app', $own_src ), 'data-bepluspb-delay="1"' ), 'other scripts are still delayed' );
$GLOBALS['st']['opts'] = array_merge( $on, array( 'auto_exclude' => 0 ) );
t_ok( false !== strpos( BEPLUSPB_JS::defer_scripts( $woo_tag, 'wc-cart', $woo_src ), ' defer src=' ), 'with automatic exclusions off it is deferred as before' );
site();
$GLOBALS['st']['opts'] = $on;
t_ok( false !== strpos( BEPLUSPB_JS::defer_scripts( $woo_tag, 'wc-cart', $woo_src ), ' defer src=' ), 'WooCommerce not active: the rule does not apply' );
$stripe = '<script src="https://js.stripe.com/v3/"></script>';
t_ok( $stripe === BEPLUSPB_JS::defer_scripts( $stripe, 'stripe', 'https://js.stripe.com/v3/' ), 'Stripe is never deferred, with or without a payment plugin' );

// ---- Wiring and safety ----------------------------------------------------------
$root = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'class-bepluspb-js.php' => 4, 'class-bepluspb-css.php' => 3, 'class-bepluspb-minify.php' => 3, 'class-bepluspb-page-cache.php' => 1 ) as $file => $count ) {
	$src = file_get_contents( $root . $file );
	t_ok( $count === substr_count( $src, 'bepluspb_exclude_list(' ), "$file reads the effective list ($count places)" );
	t_ok( 0 === preg_match( "/bepluspb_parse_exclude_list\(\s*\\\$opts\[\s*'(js_exclude|css_exclude|cache_exclude_pages)'/", $src ), "$file no longer reads the raw Exclude setting" );
}
$compat = file_get_contents( $root . 'class-bepluspb-compat.php' );
foreach ( array( 'wp_remote_', 'curl_', 'file_get_contents', 'file_put_contents', 'update_option', '$wpdb', 'exec(' ) as $bad ) {
	t_ok( false === strpos( $compat, $bad ), "the rules class does not use $bad (static list, no network, no writes)" );
}
$admin = bepluspb_admin_source();
foreach ( array( "'auto_exclude',", 'BEPLUSPB_Compat::sanitize_skipped(', 'render_card_auto_exclude( $opts )', '[auto_exclude_off][' ) as $needle ) {
	t_ok( false !== strpos( $admin, $needle ), "admin source contains $needle" );
}
$boot = file_get_contents( dirname( __DIR__ ) . '/beplus-performance-booster.php' );
t_ok( 1 === preg_match( "/'auto_exclude'\s*=> 1,/", $boot ) && 1 === preg_match( "/'auto_exclude_off'\s*=> '',/", $boot ), 'defaults: on, nothing skipped' );

echo "OK ($n assertions)\n";
