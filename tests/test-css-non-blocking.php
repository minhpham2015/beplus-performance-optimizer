<?php
/**
 * Non-Render-Blocking CSS: what gets converted, what must stay a blocking <link>.
 * Run: php -n tests/test-css-non-blocking.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
define( 'ABSPATH', __DIR__ . '/' );
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_bloginfo( $k ) { return 'stylesheet_url' === $k ? 'https://ex.com/wp-content/themes/child/style.css?ver=1' : ''; }
function get_template_directory_uri() { return 'https://ex.com/wp-content/themes/parent'; }
function get_stylesheet_directory_uri() { return $GLOBALS['stylesheet_dir'] ?? 'https://ex.com/wp-content/themes/child'; }
function wp_styles() {
	$o = new stdClass();
	$o->registered = array(
		'child-style'  => (object) array( 'src' => 'https://ex.com/wp-content/themes/child/style.css' ),
		'parent-style' => (object) array( 'src' => '/wp-content/themes/parent/style.css' ),
		'plugin-css'   => (object) array( 'src' => 'https://ex.com/wp-content/plugins/p/p.css' ),
		'child-main'   => (object) array( 'src' => 'https://ex.com/wp-content/themes/child/assets/css/main.min.css' ),
		'parent-theme' => (object) array( 'src' => '/wp-content/themes/parent/assets/css/theme.css' ),
		'parent-lookalike' => (object) array( 'src' => '/wp-content/themes/parent-evil/x.css' ),
		'no-src'       => (object) array( 'src' => false ),
	);
	return $o;
}
require dirname( __DIR__ ) . '/includes/class-bepluspb-css.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
function conv( $tag, $exclude = array() ) { return BEPLUSPB_CSS::make_non_blocking( $tag, $exclude ); }
function converted( $out ) { return false !== strpos( $out, 'rel="preload" as="style"' ) && false !== strpos( $out, '<noscript>' ); }

$link = "<link rel='stylesheet' id='plugin-css-css' href='https://ex.com/wp-content/plugins/p/p.css' media='all' />";
t_ok( converted( conv( $link ) ), 'ordinary stylesheet is converted, noscript fallback kept' );
t_ok( converted( conv( str_replace( "media='all'", "media='screen'", $link ) ) ), 'media=screen converted' );
t_ok( converted( conv( str_replace( " media='all'", '', $link ) ) ), 'no media attribute converted' );

// Theme stylesheet stays blocking, also after Minify/UCSS rewrote its href.
$theme_plain = "<link rel='stylesheet' id='child-style-css' href='https://ex.com/wp-content/themes/child/style.css?ver=1' media='all' />";
t_ok( ! converted( conv( $theme_plain ) ), 'theme stylesheet kept (by path)' );
$theme_min = "<link rel='stylesheet' id='child-style-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/child-style-abc123abc123.css' media='all' />";
t_ok( ! converted( conv( $theme_min ) ), 'theme stylesheet kept after Minify moved it (by handle)' );
$parent_min = "<link rel='stylesheet' id='parent-style-css' href='/wp-content/uploads/bepluspb-cache/ucss-x-parent-style-abc123abc1.css' />";
t_ok( ! converted( conv( $parent_min ) ), 'parent theme stylesheet kept (by handle)' );
$other_min = "<link rel='stylesheet' id='plugin-css-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/plugin-css-abc123abc123.css' />";
t_ok( converted( conv( $other_min ) ), 'unrelated minified stylesheet still converted' );

// Themes that keep their layout CSS outside style.css (Astra, Hello Elementor, GeneratePress...).
$sub_child = "<link rel='stylesheet' id='child-main-css' href='https://ex.com/wp-content/themes/child/assets/css/main.min.css?ver=3' />";
t_ok( ! converted( conv( $sub_child ) ), 'child theme CSS in a sub-directory stays blocking (by path)' );
$sub_parent = "<link rel='stylesheet' id='whatever-css' href='/wp-content/themes/parent/assets/css/theme.css' />";
t_ok( ! converted( conv( $sub_parent ) ), 'parent theme CSS in a sub-directory stays blocking (by path)' );
$sub_min = "<link rel='stylesheet' id='parent-theme-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/parent-theme-abc123abc123.css' />";
t_ok( ! converted( conv( $sub_min ) ), 'theme sub-directory CSS stays blocking after Minify moved it (by handle)' );
$sub_min2 = "<link rel='stylesheet' id='child-main-css' href='https://ex.com/wp-content/uploads/bepluspb-cache/ucss-x-child-main-abc123abc1.css' />";
t_ok( ! converted( conv( $sub_min2 ) ), 'same for the child theme after Remove Unused CSS' );
$lookalike = "<link rel='stylesheet' id='parent-lookalike-css' href='/wp-content/themes/parent-evil/x.css' />";
t_ok( converted( conv( $lookalike ) ), 'a theme directory with the same prefix is not mistaken for the active theme' );
t_ok( converted( conv( "<link rel='stylesheet' id='plugin-css-css' href='https://ex.com/wp-content/plugins/parent/p.css' />" ) ), 'plugin CSS is still deferred' );
// A theme directory that resolves to "/" must never turn the exclusion into "everything".
$GLOBALS['stylesheet_dir'] = 'https://ex.com/';
t_ok( converted( conv( $link ) ), 'a root-level theme URL does not exclude every stylesheet' );
unset( $GLOBALS['stylesheet_dir'] );

// With critical CSS covering the first paint, the theme's own CSS is deferred too (exclusions still win).
function conv_full( $tag, $exclude = array() ) { return BEPLUSPB_CSS::make_non_blocking( $tag, $exclude, true ); }
t_ok( converted( conv_full( $theme_plain ) ), 'defer_theme: style.css of the theme is deferred' );
t_ok( converted( conv_full( $sub_child ) ) && converted( conv_full( $sub_parent ) ), 'defer_theme: theme sub-directory CSS is deferred' );
t_ok( converted( conv_full( $theme_min ) ) && converted( conv_full( $sub_min ) ), 'defer_theme: theme CSS moved to the cache directory is deferred too' );
t_ok( ! converted( conv_full( $sub_child, array( 'assets/css/main.min.css' ) ) ), 'defer_theme: "Exclude CSS Files" still keeps a stylesheet blocking' );
t_ok( ! converted( conv_full( "<link rel='stylesheet' href='/a.css' media='print'>" ) ) && ! converted( conv_full( '<link rel="preload" as="style" href="/a.css">' ) ), 'defer_theme: print media and existing preloads are still left alone' );
t_ok( $link === conv_full( $link, array( 'p/p.css' ) ), 'defer_theme: keyword exclusion honoured' );
t_ok( ! converted( conv_full( $link ) ) === false, 'defer_theme: ordinary stylesheets are deferred as before' );

// Conditional media can never be swapped in by a preload.
foreach ( array( 'print', '(max-width:600px)', '(prefers-color-scheme: dark)', 'only screen and (min-width:1px)' ) as $m ) {
	t_ok( ! converted( conv( str_replace( "media='all'", "media='$m'", $link ) ) ), "media=$m left blocking" );
}

// Another async technique is already in place.
t_ok( ! converted( conv( "<link rel='stylesheet' href='/a.css' media='print' onload=\"this.media='all'\">" ) ), 'media-swap pattern untouched' );
t_ok( ! converted( conv( "<link rel='stylesheet' href='/a.css' onload=\"this.rel='stylesheet'\">" ) ), 'link with own onload untouched' );
t_ok( ! converted( conv( '<link rel="preload" as="style" href="/a.css">' ) ), 'existing preload untouched' );
t_ok( ! converted( conv( $link, array( 'p/p.css' ) ) ), 'URL keyword exclusion honoured' );

// CSP: inline handlers must be allowed or the whole feature is skipped.
$blocks = array( 'BEPLUSPB_CSS', 'csp_blocks_inline_handlers' );
t_ok( ! $blocks( array() ), 'no CSP: allowed' );
t_ok( ! $blocks( array( "img-src 'self'" ) ), 'CSP without script directives: allowed' );
t_ok( $blocks( array( "script-src 'self'" ) ), "script-src 'self' blocks inline handlers" );
t_ok( $blocks( array( "default-src 'self'" ) ), "default-src 'self' blocks inline handlers" );
t_ok( ! $blocks( array( "script-src 'self' 'unsafe-inline'" ) ), "'unsafe-inline' allows them" );
t_ok( $blocks( array( "script-src 'self' 'unsafe-inline' 'nonce-abc'" ) ), 'nonce makes browsers ignore unsafe-inline' );
t_ok( $blocks( array( "script-src 'unsafe-inline' 'strict-dynamic'" ) ), 'strict-dynamic ignores unsafe-inline' );
t_ok( $blocks( array( "script-src 'unsafe-inline'; script-src-attr 'none'" ) ), 'script-src-attr takes precedence' );
t_ok( $blocks( array( "img-src *", "script-src 'self'" ) ), 'any blocking policy wins' );
$meta = '<meta http-equiv="Content-Security-Policy" content="script-src \'self\'">' . $link;
t_ok( ! converted( conv( $meta ) ), 'CSP delivered via <meta> disables the transform' );

echo "OK ($n assertions)\n";
