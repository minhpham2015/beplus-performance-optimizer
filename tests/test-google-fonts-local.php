<?php
/**
 * Google Fonts self-hosting: validation, the strict stylesheet parser, the downloader (HTTP stubbed and
 * every request recorded), storage, queue, failures, cleanup.
 * Run: php -n tests/test-google-fonts-local.php
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root = sys_get_temp_dir() . '/bepluspb-gf-' . bin2hex( random_bytes( 5 ) );
mkdir( "$root/uploads/bepluspb-cache", 0777, true );
register_shutdown_function( function () use ( $root ) { exec( 'rm -rf ' . escapeshellarg( $root ) ); } );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', "$root/uploads/bepluspb-cache/" );
$GLOBALS['db'] = array(); $GLOBALS['actions'] = array(); $GLOBALS['http'] = array(); $GLOBALS['requests'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['opts'] = array( 'google_fonts_mode' => 'selfhost' );
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_upload_dir() { return array( 'baseurl' => 'https://ex.com/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); } function wp_is_writable( $p ) { return is_writable( $p ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['db'][ $k ] ); return true; }
function do_action( $t, ...$a ) { $GLOBALS['actions'][] = $t; } function add_action( $t, $cb ) { $GLOBALS['hooked'][ $t ] = $cb; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; } function wp_schedule_single_event( $ts, $h ) { $GLOBALS['cron'][ $h ] = $ts; return true; }
function bepluspb_get_options() { return $GLOBALS['opts']; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['requests'][] = array( 'url' => $url, 'args' => $args );
	$r = $GLOBALS['http'][ $url ] ?? array( 'code' => 404, 'body' => 'nope' );
	if ( isset( $r['wp_error'] ) ) { return new WP_Error( 'http', $r['wp_error'] ); }
	return $r;
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php';

$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$L = 'BEPLUSPB_Google_Fonts_Local';
function woff2( $extra = 40 ) { return 'wOF2' . str_repeat( "\x01", $extra ); }
function font_url( $k ) { return 'https://fonts.gstatic.com/l/font?kit=' . $k . '&skey=abc123&v=v28'; }
function fake_css( $kits, $display = 'swap' ) {
	$out = '';
	foreach ( $kits as $i => $kit ) {
		$out .= "/* subset $i */\n@font-face {\n  font-family: 'Heebo';\n  font-style: normal;\n  font-weight: 400;\n" . ( $display ? "  font-display: $display;\n" : '' ) . "  src: url(" . font_url( $kit ) . ") format('woff2');\n  unicode-range: U+0590-05FF, U+200C-2010, U+20AA;\n}\n";
	}
	return $out;
}
function reset_world() { BEPLUSPB_Google_Fonts_Local::reset(); $GLOBALS['db'] = array(); $GLOBALS['actions'] = array(); $GLOBALS['http'] = array(); $GLOBALS['requests'] = array(); $GLOBALS['cron'] = array(); foreach ( glob( BEPLUSPB_CACHE_DIR . 'fonts/*' ) ?: array() as $f ) { unlink( $f ); } }
function serve_ok( $css_url, $kits, $display = 'swap' ) { $GLOBALS['http'][ $css_url ] = array( 'code' => 200, 'body' => fake_css( $kits, $display ) ); foreach ( $kits as $k ) { $GLOBALS['http'][ font_url( $k ) ] = array( 'code' => 200, 'body' => woff2() ); } }
$CSS = 'https://fonts.googleapis.com/css2?family=Heebo:wght@400&display=swap';

// ---- stylesheet URL validation ----------------------------------------------------------------
foreach ( array(
	'https://fonts.googleapis.com/css?family=Roboto:400,700'                         => 'https://fonts.googleapis.com/css?family=Roboto:400,700',
	'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap'       => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap',
	'//fonts.googleapis.com/css?family=Lato|Open+Sans:400'                           => 'https://fonts.googleapis.com/css?family=Lato|Open+Sans:400',
	'http://fonts.googleapis.com/css?family=Lato'                                    => 'https://fonts.googleapis.com/css?family=Lato',
	'https://fonts.googleapis.com/css2?family=Inter&#038;display=swap'               => 'https://fonts.googleapis.com/css2?family=Inter&display=swap',
	'https://fonts.googleapis.com/css2?family=Inter&amp;display=swap'                => 'https://fonts.googleapis.com/css2?family=Inter&display=swap',
	'https://FONTS.googleapis.com/css2?family=Inter'                                 => 'https://fonts.googleapis.com/css2?family=Inter',
	'https://fonts.googleapis.com/icon?family=Material+Icons'                        => 'https://fonts.googleapis.com/icon?family=Material+Icons',
	'  https://fonts.googleapis.com/css?family=Lato  '                               => 'https://fonts.googleapis.com/css?family=Lato',
) as $in => $expected ) {
	t_ok( $expected === $L::normalize_css_url( $in ), "normalised: $in" );
}
foreach ( array(
	'https://fonts.googleapis.com.evil.test/css?family=A', 'https://evil.test/css?family=A', 'https://evil.test/https://fonts.googleapis.com/css?family=A',
	'https://notfonts.googleapis.com/css?family=A', 'https://fonts.gstatic.com/css?family=A', 'https://fonts.googleapis.com@evil.test/css?family=A',
	'https://user:pw@fonts.googleapis.com/css?family=A', 'https://fonts.googleapis.com:8443/css?family=A', 'https://fonts.googleapis.com:443/css?family=A',
	'https://fonts.googleapis.com/css?family=A#frag', 'https://fonts.googleapis.com/other?family=A', 'https://fonts.googleapis.com/css/../x?family=A', 'https://fonts.googleapis.com/earlyaccess/x.css?family=A',
	'https://fonts.googleapis.com/css', 'https://fonts.googleapis.com/css?', 'https://fonts.googleapis.com/css?subset=latin', 'https://fonts.googleapis.com/css?family=A"onload="x',
	"https://fonts.googleapis.com/css?family=A\nB", 'https://fonts.googleapis.com/css?family=A B', 'https://fonts.googleapis.com/css?family=A<script>', 'https://fonts.googleapis.com/css?family=' . str_repeat( 'a', 2100 ),
	'ftp://fonts.googleapis.com/css?family=A', 'javascript:alert(1)', '', 'not a url', 'file:///etc/passwd', 'https://127.0.0.1/css?family=A', 'https://[::1]/css?family=A',
) as $bad ) {
	t_ok( '' === $L::normalize_css_url( $bad ), 'rejected stylesheet URL: ' . substr( json_encode( $bad ), 0, 70 ) );
}
t_ok( '' === $L::normalize_css_url( null ) && '' === $L::normalize_css_url( array( 'x' ) ) && '' === $L::normalize_css_url( 5 ), 'non-string input rejected' );

// ---- font URL validation ----------------------------------------------------------------------
foreach ( array( font_url( 'NGScv5_NC0k9P' ), 'https://fonts.gstatic.com/l/font', 'https://fonts.gstatic.com/s/inter/v13/UcC73FwrK3iLTeHuS_fvQtMwCp50KnMa1ZL7.woff2', 'https://fonts.gstatic.com/s/roboto/v30/KFOmCnqEu92Fr1Mu4mxK.woff2?x=1' ) as $ok ) { t_ok( $L::is_font_url( $ok ), "font URL accepted: $ok" ); }
foreach ( array( 'http://fonts.gstatic.com/l/font?kit=a', 'https://fonts.googleapis.com/l/font?kit=a', 'https://evil.test/l/font?kit=a', 'https://fonts.gstatic.com.evil.test/l/font?kit=a', 'https://fonts.gstatic.com:444/l/font?kit=a',
	'https://u:p@fonts.gstatic.com/l/font?kit=a', 'https://fonts.gstatic.com/l/font?kit=a#x', 'https://fonts.gstatic.com/l/other?kit=a', 'https://fonts.gstatic.com/s/a/../b/c', 'https://fonts.gstatic.com/s/a/b',
	'https://fonts.gstatic.com/l/font?kit=a b', 'https://fonts.gstatic.com/l/font?kit=a"', 'https://fonts.gstatic.com/l/font?kit=' . str_repeat( 'a', 500 ), '/l/font?kit=a', '//fonts.gstatic.com/l/font?kit=a', '', 'javascript:x' ) as $bad ) {
	t_ok( ! $L::is_font_url( $bad ), 'font URL rejected: ' . substr( $bad, 0, 70 ) );
}
t_ok( ! $L::is_font_url( null ) && ! $L::is_font_url( array() ), 'non-string font URL rejected' );

// ---- strict parser ---------------------------------------------------------------------------
$p = $L::parse_css( fake_css( array( 'one', 'two' ) ) );
t_ok( 2 === count( $p['faces'] ) && 'Heebo' === $p['faces'][0]['family'] && '400' === $p['faces'][0]['weight'] && 'swap' === $p['faces'][0]['display'] && font_url( 'one' ) === $p['faces'][0]['src'], 'two faces parsed' );
t_ok( 'U+0590-05FF,U+200C-2010,U+20AA' === $p['faces'][0]['range'], 'unicode-range normalised' );
$p = $L::parse_css( "@font-face{font-family:\"Open Sans\";font-style:italic;font-weight:300 800;font-stretch:75% 125%;src:url('" . font_url( 'v' ) . "') format(\"woff2\")}" );
t_ok( isset( $p['faces'] ) && 'Open Sans' === $p['faces'][0]['family'] && 'italic' === $p['faces'][0]['style'] && '300 800' === $p['faces'][0]['weight'] && '75% 125%' === $p['faces'][0]['stretch'], 'variable-font descriptors and other quoting accepted' );
$good_block = "@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff2')}";
t_ok( isset( $L::parse_css( $good_block )['faces'] ), 'the minimal block is accepted' );
foreach ( array(
	''                                                                                               => 'empty',
	'body{color:red}'                                                                                => 'not @font-face',
	$good_block . 'a{b:c}'                                                                           => 'extra rule after',
	"@import url(https://evil.test/x.css);$good_block"                                               => '@import',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff2');color:red}"         => 'unknown property',
	"@font-face{font-family:'A';src:url(https://evil.test/a.woff2) format('woff2')}"                 => 'source on another host',
	"@font-face{font-family:'A';src:url(http://fonts.gstatic.com/l/font?kit=a) format('woff2')}"     => 'source over http',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff')}"                     => 'not woff2',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . "),url(https://evil.test/a) format('woff2')}" => 'two sources',
	"@font-face{font-family:'A';src:local('A'),url(" . font_url( 'x' ) . ") format('woff2')}"         => 'local() source',
	"@font-face{font-family:'A<b>';src:url(" . font_url( 'x' ) . ") format('woff2')}"                 => 'markup in the family',
	"@font-face{font-family:'A;}';src:url(" . font_url( 'x' ) . ") format('woff2')}"                  => 'brace in the family',
	"@font-face{font-family:A;src:url(" . font_url( 'x' ) . ") format('woff2')}"                      => 'unquoted family',
	"@font-face{font-family:'A';font-weight:bolder;src:url(" . font_url( 'x' ) . ") format('woff2')}" => 'bad weight',
	"@font-face{font-family:'A';font-display:eval(1);src:url(" . font_url( 'x' ) . ") format('woff2')}" => 'bad display',
	"@font-face{font-family:'A';unicode-range:U+0-FF;background:url(x);src:url(" . font_url( 'x' ) . ") format('woff2')}" => 'a url() in another property',
	"@font-face{font-family:'A';unicode-range:evil;src:url(" . font_url( 'x' ) . ") format('woff2')}" => 'bad unicode-range',
	"@font-face{src:url(" . font_url( 'x' ) . ") format('woff2')}"                                    => 'no family',
	"@font-face{font-family:'A'}"                                                                    => 'no source',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff2')"                     => 'unclosed block',
	"@font-face{font-family:'A' src:url(x)}"                                                         => 'malformed declaration',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff2')}<script>"           => 'markup',
	"@font-face{font-family:'A';src:url(" . font_url( 'x' ) . ") format('woff2');}/* c */ @media print{}" => 'at-rule after',
) as $css => $why ) {
	t_ok( isset( $L::parse_css( (string) $css )['error'] ), "parser refuses: $why" );
}
$many = ''; for ( $i = 0; $i < 81; $i++ ) { $many .= "@font-face{font-family:'A';src:url(" . font_url( 'k' . $i ) . ") format('woff2')}"; }
t_ok( isset( $L::parse_css( $many )['error'] ), 'more than 80 faces refused' );
t_ok( isset( $L::parse_css( null )['error'] ) && isset( $L::parse_css( array() )['error'] ), 'non-string input refused' );
t_ok( isset( $L::parse_css( "/* only a comment */" )['error'], $L::parse_css( "  \n " )['error'] ), 'comment-only / blank refused' );

// ---- icon fonts: helper class rules ---------------------------------------------------------------
$icons = "/* fallback */\n@font-face {\n  font-family: 'Material Icons';\n  font-style: normal;\n  font-weight: 400;\n  src: url(https://fonts.gstatic.com/s/materialicons/v145/flUhRq6tzZclQEJ-Vdg-IuiaDsNc.woff2) format('woff2');\n}\n\n.material-icons {\n  font-family: 'Material Icons';\n  font-weight: normal;\n  font-style: normal;\n  font-size: 24px;\n  line-height: 1;\n  letter-spacing: normal;\n  text-transform: none;\n  display: inline-block;\n  white-space: nowrap;\n  word-wrap: normal;\n  direction: ltr;\n  -webkit-font-feature-settings: 'liga';\n  -webkit-font-smoothing: antialiased;\n}\n";
$p = $L::parse_css( $icons );
t_ok( isset( $p['faces'] ) && 1 === count( $p['faces'] ) && 1 === count( $p['rules'] ) && 'material-icons' === $p['rules'][0]['class'] && 13 === count( $p['rules'][0]['decls'] ), 'the real Material Icons stylesheet is accepted (font face + helper rule)' );
t_ok( 0 === count( $L::parse_css( fake_css( array( 'a' ) ) )['rules'] ), 'ordinary stylesheets have no helper rules' );
$ok_rule = function ( $decl ) use ( $L, $good_block ) { return isset( $L::parse_css( $good_block . '.x{' . $decl . '}' )['faces'] ); };
foreach ( array( "font-feature-settings:'liga' 1,'kern' off", "-webkit-font-feature-settings:'liga'", "font-variation-settings:'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24", 'font-size:1.5em', 'line-height:24px', 'letter-spacing:-0.5px', '-moz-osx-font-smoothing:grayscale', 'display:block', 'direction:rtl', "font-family:'Material Symbols Outlined'" ) as $good ) { t_ok( $ok_rule( $good ), "helper rule property accepted: $good" ); }
foreach ( array( 'background:url(https://evil.test/x.png)', 'color:red', 'content:"x"', 'display:none', 'position:fixed', 'font-size:calc(1px + 2px)', 'font-size:expression(1)', "font-family:'A';color:red", "font-feature-settings:url(x)", 'direction:ltr!important', 'behavior:url(x)', '-webkit-font-smoothing:antialiased;x:y', '' ) as $bad ) { t_ok( ! $ok_rule( $bad ), 'helper rule refused: ' . ( $bad ?: '(empty rule)' ) ); }
foreach ( array( 'a{display:block}' => 'element selector', '#x{display:block}' => 'id selector', '.a .b{display:block}' => 'descendant selector', '.a,.b{display:block}' => 'selector list', '.a:hover{display:block}' => 'pseudo-class', '.a>b{display:block}' => 'combinator', '*{display:block}' => 'universal selector', '@media print{.a{display:block}}' => 'at-rule', '.a{display:block}.b{' => 'unclosed rule' ) as $rule => $why ) {
	t_ok( isset( $L::parse_css( $good_block . $rule )['error'] ), "refused: $why" );
}
$many_rules = $good_block; for ( $i = 0; $i < 21; $i++ ) { $many_rules .= ".r$i{display:block}"; }
t_ok( isset( $L::parse_css( $many_rules )['error'] ), 'more than 20 helper rules refused' );
t_ok( isset( $L::parse_css( '.x{display:block}' )['error'] ), 'helper rules without any @font-face are refused' );

// ---- rebuilt stylesheet -----------------------------------------------------------------------
$p = $L::parse_css( fake_css( array( 'one' ) ) );
$built = $L::build_css( $p['faces'], array( font_url( 'one' ) => '/wp-content/uploads/bepluspb-cache/fonts/abc.woff2' ) );
t_ok( "@font-face{font-family:'Heebo';font-style:normal;font-weight:400;font-display:swap;src:url(/wp-content/uploads/bepluspb-cache/fonts/abc.woff2) format('woff2');unicode-range:U+0590-05FF,U+200C-2010,U+20AA}\n" === $built, 'local stylesheet is rebuilt from the parsed values only' );
t_ok( false === strpos( $built, 'gstatic' ) && false === strpos( $built, 'subset' ), 'no remote URL and no copied comment' );

$p_icons = $L::parse_css( $icons );
$built_icons = $L::build_css( $p_icons['faces'], array( 'https://fonts.gstatic.com/s/materialicons/v145/flUhRq6tzZclQEJ-Vdg-IuiaDsNc.woff2' => '/f/m.woff2' ), $p_icons['rules'] );
t_ok( "@font-face{font-family:'Material Icons';font-style:normal;font-weight:400;src:url(/f/m.woff2) format('woff2')}\n.material-icons{font-family:'Material Icons';font-weight:normal;font-style:normal;font-size:24px;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-feature-settings:'liga';-webkit-font-smoothing:antialiased;}\n" === $built_icons, 'helper rules are rebuilt from the parsed declarations' );

// ---- download ---------------------------------------------------------------------------------
reset_world(); serve_ok( $CSS, array( 'k1', 'k2', 'k3' ) );
$r = $L::localize_stylesheet( $CSS );
t_ok( true === $r['ok'] && 3 === $r['fonts'] && 3 * 44 === $r['bytes'], 'three fonts downloaded' );
t_ok( 4 === count( $GLOBALS['requests'] ), 'one request for the stylesheet and one per font (' . count( $GLOBALS['requests'] ) . ')' );
foreach ( $GLOBALS['requests'] as $rq ) {
	$host = parse_url( $rq['url'], PHP_URL_HOST );
	t_ok( in_array( $host, array( 'fonts.googleapis.com', 'fonts.gstatic.com' ), true ) && 'https' === parse_url( $rq['url'], PHP_URL_SCHEME ), 'request only to an allowed host over https: ' . $rq['url'] );
	t_ok( 0 === $rq['args']['redirection'] && true === $rq['args']['reject_unsafe_urls'] && 10 === $rq['args']['timeout'] && array() === $rq['args']['cookies'], 'redirects off, unsafe URLs rejected, timeout, no cookies' );
	t_ok( false !== strpos( $rq['args']['user-agent'], 'Chrome' ) && false === stripos( $rq['args']['user-agent'], 'wordpress' ) && false === strpos( $rq['args']['user-agent'], 'ex.com' ), 'generic browser User-Agent, nothing about this site' );
	t_ok( is_int( $rq['args']['limit_response_size'] ) && $rq['args']['limit_response_size'] > 0, 'a response size limit is set' );
}
$id = $L::id_for( $L::normalize_css_url( $CSS ) );
$css_file = BEPLUSPB_CACHE_DIR . "fonts/$id.css";
t_ok( is_file( $css_file ), 'local stylesheet written' );
$local = file_get_contents( $css_file );
t_ok( 3 === substr_count( $local, '@font-face' ) && 0 === substr_count( $local, 'gstatic' ) && 3 === substr_count( $local, '/wp-content/uploads/bepluspb-cache/fonts/' ), 'every font URL points to this site' );
$woffs = array_values( array_filter( scandir( BEPLUSPB_CACHE_DIR . 'fonts' ), fn( $f ) => '.woff2' === substr( $f, -6 ) ) );
t_ok( 3 === count( $woffs ) && preg_match( '/^[a-f0-9]{20}\.woff2$/', $woffs[0] ) && 'wOF2' === substr( file_get_contents( BEPLUSPB_CACHE_DIR . 'fonts/' . $woffs[0] ), 0, 4 ), 'font files are hash-named and start with the woff2 signature' );
t_ok( 0 === preg_match( '/\.bepluspb-tmp-/', implode( ' ', scandir( BEPLUSPB_CACHE_DIR . 'fonts' ) ) ), 'no temp files left behind' );
$u = $L::local_url( $L::normalize_css_url( $CSS ) );
t_ok( 1 === preg_match( '#^/wp-content/uploads/bepluspb-cache/fonts/' . $id . '\.css\?ver=\d+$#', $u ), 'local URL: root-relative, with a version' );
t_ok( null === $L::local_url( $L::normalize_css_url( 'https://fonts.googleapis.com/css?family=Other' ) ), 'no local URL for a stylesheet that is not downloaded' );

// the same font shared by a second stylesheet is not fetched again
$CSS2 = 'https://fonts.googleapis.com/css2?family=Heebo:wght@700&display=swap';
serve_ok( $CSS2, array( 'k1', 'k4' ) ); $GLOBALS['requests'] = array();
$r = $L::localize_stylesheet( $CSS2 );
t_ok( true === $r['ok'] && 1 === count( array_filter( $GLOBALS['requests'], fn( $q ) => false !== strpos( $q['url'], 'fonts.gstatic.com' ) ) ), 'a font that is already here is not downloaded again (only the new one is requested)' );
t_ok( 4 === count( array_filter( scandir( BEPLUSPB_CACHE_DIR . 'fonts' ), fn( $f ) => '.woff2' === substr( $f, -6 ) ) ), 'and is shared by both stylesheets (4 files for 5 faces)' );

// ---- failures keep the original link (nothing partial is referenced) --------------------------------------
function fresh( $case ) { reset_world(); $GLOBALS['http'] = array(); $case(); global $CSS, $L; $r = $L::localize_stylesheet( $CSS ); return $r; }
$r = fresh( function () { global $CSS; serve_ok( $CSS, array( 'a', 'b' ) ); $GLOBALS['http'][ font_url( 'b' ) ] = array( 'code' => 404, 'body' => 'x' ); } );
t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], '404' ) && ! is_file( BEPLUSPB_CACHE_DIR . 'fonts/' . $L::id_for( $L::normalize_css_url( $CSS ) ) . '.css' ), 'a font that fails: no local stylesheet is written' );
$r = fresh( function () { global $CSS; serve_ok( $CSS, array( 'a' ) ); $GLOBALS['http'][ font_url( 'a' ) ] = array( 'code' => 200, 'body' => 'NOTAFONT' . str_repeat( 'x', 50 ) ); } );
t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], 'woff2' ) && 0 === count( array_filter( scandir( BEPLUSPB_CACHE_DIR . 'fonts' ), fn( $f ) => '.woff2' === substr( $f, -6 ) ) ), 'a file without the woff2 signature is not stored' );
$r = fresh( function () { global $CSS; serve_ok( $CSS, array( 'a' ) ); $GLOBALS['http'][ font_url( 'a' ) ] = array( 'code' => 200, 'body' => 'wOF2' . str_repeat( 'x', BEPLUSPB_Google_Fonts_Local::MAX_FONT_BYTES ) ); } );
t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], 'larger' ), 'an oversized font is refused' );
$r = fresh( function () { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'code' => 200, 'body' => str_repeat( ' ', BEPLUSPB_Google_Fonts_Local::MAX_CSS_BYTES + 1 ) ); } );
t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], 'larger' ), 'an oversized stylesheet is refused' );
foreach ( array( 301, 302, 307, 403, 404, 500, 503, 204 ) as $code ) {
	$r = fresh( function () use ( $code ) { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'code' => $code, 'body' => fake_css( array( 'a' ) ) ); } );
	t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], (string) $code ) && 1 === count( $GLOBALS['requests'] ), "HTTP $code: refused, redirects are never followed" );
}
$r = fresh( function () { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'wp_error' => 'cURL error 28: timeout' ); } );
t_ok( isset( $r['error'] ) && false !== strpos( $r['error'], 'timeout' ), 'a transport error is reported' );
$r = fresh( function () { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'code' => 200, 'body' => '' ); } );
t_ok( isset( $r['error'] ), 'an empty response is refused' );
$r = fresh( function () { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'code' => 200, 'body' => "@font-face{font-family:'A';src:url(https://evil.test/a.woff2) format('woff2')}" ); } );
t_ok( isset( $r['error'] ) && 1 === count( $GLOBALS['requests'] ), 'a stylesheet naming a font on another host: refused before any font request' );
$r = fresh( function () { global $CSS; $GLOBALS['http'][ $CSS ] = array( 'code' => 200, 'body' => "@import url(//evil.test/x.css);" . fake_css( array( 'a' ) ) ); } );
t_ok( isset( $r['error'] ) && 1 === count( $GLOBALS['requests'] ), 'an @import in the stylesheet: refused' );
reset_world();
t_ok( isset( $L::localize_stylesheet( 'https://evil.test/css?family=A' )['error'] ) && 0 === count( $GLOBALS['requests'] ), 'a URL that is not a Google Fonts stylesheet is never requested' );
t_ok( isset( $L::localize_stylesheet( 'https://fonts.googleapis.com/css' )['error'] ) && 0 === count( $GLOBALS['requests'] ), '... also when only the host looks right' );

// ---- time budget ------------------------------------------------------------------------------
reset_world(); serve_ok( $CSS, array( 'a', 'b', 'c' ) );
$r = $L::localize_stylesheet( $CSS, microtime( true ) - 1 );
t_ok( ! empty( $r['timeout'] ) && 1 === count( $GLOBALS['requests'] ), 'an expired budget stops before the first font request' );
t_ok( ! is_file( BEPLUSPB_CACHE_DIR . 'fonts/' . $L::id_for( $L::normalize_css_url( $CSS ) ) . '.css' ), '... and writes no stylesheet' );

// ---- queue / front end --------------------------------------------------------------------------
reset_world(); $L::register_core_hooks();
t_ok( isset( $GLOBALS['hooked']['bepluspb_gf_fetch'] ), 'cron callback registered' );
t_ok( null === $L::frontend_localize( 'https://evil.test/css?family=A' ) && array() === $L::queue() && array() === $GLOBALS['cron'], 'an invalid URL is neither queued nor scheduled' );
t_ok( null === $L::frontend_localize( 'https://fonts.googleapis.com/css2?family=Heebo:wght@400&amp;display=swap' ), 'not downloaded: the page keeps the Google URL' );
$q = $L::queue(); $e = reset( $q );
t_ok( 1 === count( $q ) && $CSS === $e['url'] && 0 === $e['fails'] && isset( $GLOBALS['cron']['bepluspb_gf_fetch'] ), 'the stylesheet is queued (entities decoded) and a cron run is scheduled' );
$writes = 0; $before = $GLOBALS['db'][ $L::QUEUE_OPTION ];
$L::frontend_localize( $CSS ); $L::frontend_localize( $CSS );
t_ok( $before === $GLOBALS['db'][ $L::QUEUE_OPTION ], 'seeing it again changes nothing (no database write per request)' );
for ( $i = 0; $i < 30; $i++ ) { $L::note_seen( 'https://fonts.googleapis.com/css?family=F' . $i ); }
t_ok( $L::MAX_QUEUE === count( $L::queue() ), 'the queue is capped at ' . $L::MAX_QUEUE );

// tampered option
$GLOBALS['db'][ $L::QUEUE_OPTION ] = array(
	'aaaaaaaaaaaaaaaa'                       => array( 'url' => 'https://fonts.googleapis.com/css?family=A' ),
	$L::id_for( 'https://evil.test/x' )      => array( 'url' => 'https://evil.test/x' ),
	'bbbbbbbbbbbbbbbb'                       => 'string',
	$L::id_for( $CSS )                       => array( 'url' => $CSS, 'fails' => '2', 'error' => "bad\x00\nline" . str_repeat( 'x', 300 ), 'last' => 5 ),
);
$q = $L::queue();
t_ok( array( $L::id_for( $CSS ) ) === array_keys( $q ) && 2 === $q[ $L::id_for( $CSS ) ]['fails'] && strlen( $q[ $L::id_for( $CSS ) ]['error'] ) <= 200 && false === strpos( $q[ $L::id_for( $CSS ) ]['error'], "\n" ), 'a tampered queue is re-validated: wrong ids, foreign hosts, junk dropped; fields normalised' );
$GLOBALS['db'][ $L::QUEUE_OPTION ] = 'garbage'; t_ok( array() === $L::queue(), 'a non-array queue option is ignored' );

// ---- processing the queue ------------------------------------------------------------------------
reset_world(); $L::note_seen( $CSS ); serve_ok( $CSS, array( 'a', 'b' ) );
$s = $L::process_queue( 20 );
t_ok( 1 === $s['done'] && 0 === $s['failed'] && array() === $L::queue(), 'success: the stylesheet leaves the queue' );
t_ok( 1 === count( array_keys( $GLOBALS['actions'], 'bepluspb_google_fonts_localized', true ) ), 'the "localized" action fires once (stored pages must be dropped)' );
t_ok( null !== $L::local_url( $CSS ), 'and it is now served locally' );

reset_world(); $L::note_seen( $CSS ); $GLOBALS['http'][ $CSS ] = array( 'code' => 500, 'body' => 'x' );
$s = $L::process_queue( 20 ); $q = $L::queue(); $e = reset( $q );
t_ok( 0 === $s['done'] && 1 === $s['failed'] && 1 === $s['retry'] && 1 === $e['fails'] && false !== strpos( $e['error'], '500' ) && ! in_array( 'bepluspb_google_fonts_localized', $GLOBALS['actions'], true ), 'failure: counted, the error is kept for the administrator, nothing announced' );
$L::process_queue( 20 ); $s = $L::process_queue( 20 ); $q = $L::queue(); $e = reset( $q );
t_ok( 3 === $e['fails'] && 0 === $s['retry'], 'after ' . $L::MAX_FAILS . ' failures it is no longer retried straight away' );
$GLOBALS['requests'] = []; $s = $L::process_queue( 20 );
t_ok( 0 === count( $GLOBALS['requests'] ) && 1 === $s['failed'], 'a stylesheet that keeps failing is left alone until the retry delay is over' );
$GLOBALS['db'][ $L::QUEUE_OPTION ][ $L::id_for( $CSS ) ]['last'] = time() - $L::RETRY_AFTER - 5; serve_ok( $CSS, array( 'a' ) );
$s = $L::process_queue( 20 );
t_ok( 1 === $s['done'], '... then it is tried again, and can succeed' );

reset_world(); $L::note_seen( $CSS ); serve_ok( $CSS, array( 'a', 'b' ) );
$s = $L::process_queue( 0 );
t_ok( 0 === $s['done'] && 1 === $s['pending'] && 0 === count( $GLOBALS['requests'] ) && 1 === count( $L::queue() ), 'no time left: nothing requested, still queued' );

// ---- cron callback ---------------------------------------------------------------------------------
reset_world(); $GLOBALS['opts']['google_fonts_mode'] = 'optimize'; $L::note_seen( $CSS ); serve_ok( $CSS, array( 'a' ) ); $GLOBALS['requests'] = [];
$L::run_cron();
t_ok( 0 === count( $GLOBALS['requests'] ), 'the cron run does nothing unless the mode is still "self-host"' );
$GLOBALS['opts']['google_fonts_mode'] = 'selfhost'; $L::run_cron();
t_ok( null !== $L::local_url( $CSS ), 'with the mode on it downloads' );

// ---- status and clear -------------------------------------------------------------------------------
$st = $L::status();
t_ok( 1 === $st['sheets'] && 1 === $st['fonts'] && $st['bytes'] > 0 && array() === $st['queue'], 'status: counts and bytes' );
file_put_contents( BEPLUSPB_CACHE_DIR . 'fonts/keep-me.txt', 'x' ); file_put_contents( BEPLUSPB_CACHE_DIR . 'index.php', 'x' ); file_put_contents( BEPLUSPB_CACHE_DIR . 'other-style.css', 'x' );
$L::note_seen( 'https://fonts.googleapis.com/css?family=Pending' ); $GLOBALS['actions'] = [];
$removed = $L::clear();
t_ok( 2 === $removed && null === $L::local_url( $CSS ) && array() === $L::queue() && ! isset( $GLOBALS['db'][ $L::QUEUE_OPTION ] ), 'clear removes the local fonts and the queue' );
t_ok( is_file( BEPLUSPB_CACHE_DIR . 'fonts/keep-me.txt' ) && is_file( BEPLUSPB_CACHE_DIR . 'index.php' ) && is_file( BEPLUSPB_CACHE_DIR . 'other-style.css' ), 'files that are not ours are never touched' );
t_ok( in_array( 'bepluspb_assets_cache_cleared', $GLOBALS['actions'], true ), 'clear tells the page cache (stored pages link the removed files)' );

// ---- the code itself --------------------------------------------------------------------------------
$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php' );
preg_match_all( '#https?://[A-Za-z0-9.\-]+#', preg_replace( '#/\*.*?\*/#s', '', preg_replace( '#^\s*//.*$#m', '', $src ) ), $m );
t_ok( array() === array_diff( array_unique( $m[0] ), array( 'https://fonts.googleapis.com', 'https://fonts.gstatic.com' ) ), 'the only absolute URLs in the code are the two Google hosts: ' . implode( ' ', $m[0] ) );
t_ok( 1 === substr_count( $src, 'wp_remote_get(' ) && false === strpos( $src, 'wp_remote_post' ) && false === strpos( $src, 'curl_' ) && false === strpos( $src, 'file_get_contents( \'http' ), 'one HTTP call site, GET only, no curl' );
t_ok( 1 === preg_match( "/'redirection'\s*=>\s*0/", $src ) && 1 === preg_match( "/'reject_unsafe_urls'\s*=>\s*true/", $src ), 'redirects off and unsafe URLs rejected in the only call site' );
t_ok( false === strpos( $src, 'glob(' ), 'no glob() on the cache path' );

echo "OK ($n assertions)\n";
