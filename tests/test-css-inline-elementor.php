<?php
/**
 * Inline All CSS: Elementor stylesheets stay links when "Skip Elementor CSS" is on.
 * Run: php -n tests/test-css-inline-elementor.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
$tmp = sys_get_temp_dir() . '/inl-el-' . getmypid() . '/';
mkdir( $tmp );
define( 'BEPLUSPB_CACHE_DIR', $tmp );
file_put_contents( $tmp . 'a.css', '.a{color:red}' );
function apply_filters( $t, $v ) { return $v; }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_styles() {
	$o = new stdClass();
	$o->registered = array(
		'elementor-frontend' => (object) array( 'src' => 'https://ex.com/wp-content/plugins/elementor/assets/css/frontend.min.css' ),
		'elementor-post-7'   => (object) array( 'src' => 'https://ex.com/wp-content/uploads/elementor/css/post-7.css' ),
		'theme-css'          => (object) array( 'src' => 'https://ex.com/wp-content/themes/t/a.css' ),
	);
	return $o;
}
// Minimal stand-in: every href resolves to the same small file.
class BEPLUSPB_Minify {
	public static function url_to_path( $u ) { return BEPLUSPB_CACHE_DIR . 'a.css'; }
	public static function minify_css( $c ) { return trim( $c ); }
	public static function absolutize_css_urls( $c, $b ) { return $c; }
	public static function ensure_cache_dir() { return true; }
	public static function write_cache_file_atomic( $f, $c ) { return false !== file_put_contents( $f, $c ); }
	public static function delete_stale_variants() { return 0; }
}
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function inlined( $o ) { return 0 === strpos( $o, '<style' ); }

$plugin   = "<link rel='stylesheet' id='elementor-frontend-css' href='https://ex.com/wp-content/plugins/elementor/assets/css/frontend.min.css?ver=3' media='all' />";
$post     = "<link rel='stylesheet' id='elementor-post-7-css' href='https://ex.com/wp-content/uploads/elementor/css/post-7.css?ver=1' media='all' />";
$pro      = "<link rel='stylesheet' href='https://ex.com/wp-content/plugins/elementor-pro/assets/css/frontend.min.css' />";
$moved    = "<link rel='stylesheet' id='elementor-frontend-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/elementor-frontend-abc123abc123.css' media='all' />";
$ucss     = "<link rel='stylesheet' id='elementor-post-7-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/ucss-x-elementor-post-7-abc123abc1.css' />";
$other    = "<link rel='stylesheet' id='theme-css-css' href='https://ex.com/wp-content/themes/t/a.css' media='all' />";

foreach ( array( 'plugin' => $plugin, 'uploads post css' => $post, 'elementor pro' => $pro, 'moved by Minify (handle)' => $moved, 'moved by UCSS (handle)' => $ucss ) as $label => $tag ) {
	t_ok( $tag === BEPLUSPB_CSS::inline_all_css( $tag, array(), true ), "skip on: $label stays a link" );
	t_ok( inlined( BEPLUSPB_CSS::inline_all_css( $tag, array(), false ) ), "skip off: $label is inlined as before" );
}
t_ok( inlined( BEPLUSPB_CSS::inline_all_css( $other, array(), true ) ), 'skip on: non-Elementor stylesheet is still inlined' );
t_ok( inlined( BEPLUSPB_CSS::inline_all_css( $other ) ), 'default argument keeps the old behaviour' );
$mixed = BEPLUSPB_CSS::inline_all_css( $plugin . $other, array(), true );
t_ok( 0 === strpos( $mixed, $plugin ) && false !== strpos( $mixed, '<style' ), 'mixed head: Elementor link kept, the rest inlined' );

// Option wiring.
$main = file_get_contents( dirname( __DIR__ ) . '/beplus-performance-booster.php' );
t_ok( preg_match( "/'css_inline_skip_elementor'\s*=>\s*1,/", $main ), 'option defaults to enabled' );
t_ok( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-admin.php' ), "'css_inline_skip_elementor'," ), 'option is a saved boolean (unchecked = 0)' );

array_map( 'unlink', glob( $tmp . '*' ) );
rmdir( $tmp );
echo "OK ($n assertions)\n";
