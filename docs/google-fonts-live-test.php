<?php
/**
 * Live check of the Google Fonts self-hosting downloader against the real Google servers
 * (dev-only: lives in docs/, never shipped; needs network access to fonts.googleapis.com and
 * fonts.gstatic.com).
 *
 * It runs the plugin's real BEPLUSPB_Google_Fonts_Local class with a small wp_remote_get() shim
 * built on curl that honours the same arguments the plugin passes (timeout, no redirects, user
 * agent, response size limit), downloads a few real stylesheets into a temporary folder and
 * prints what was stored. Optional arguments: a folder to keep the result in, and a file to write a
 * sample page head to (the head after BEPLUSPB_Google_Fonts::process() in self-host mode), which
 * docs/google-fonts-browser-test.js loads in Chromium.
 *
 * Run: php docs/google-fonts-live-test.php [output-dir] [page.html]
 */

// phpcs:disable
$out = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : sys_get_temp_dir() . '/bepluspb-gf-live-' . bin2hex( random_bytes( 4 ) );
@mkdir( "$out/uploads/bepluspb-cache", 0777, true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'BEPLUSPB_CACHE_DIR', "$out/uploads/bepluspb-cache/" );
$GLOBALS['db'] = array(); $GLOBALS['requests'] = array();
class WP_Error { public $m; public function __construct( $c = '', $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_upload_dir() { return array( 'baseurl' => 'https://example.test/wp-content/uploads' ); }
function wp_mkdir_p( $p ) { return is_dir( $p ) || mkdir( $p, 0777, true ); } function wp_is_writable( $p ) { return is_writable( $p ); } function wp_delete_file( $f ) { @unlink( $f ); }
function get_option( $k, $d = false ) { return $GLOBALS['db'][ $k ] ?? $d; } function update_option( $k, $v, $a = null ) { $GLOBALS['db'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['db'][ $k ] ); return true; }
function do_action() {} function add_action() {} function wp_next_scheduled() { return false; } function wp_schedule_single_event() { return true; } function bepluspb_get_options() { return array(); }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['requests'][] = $url;
	$body = ''; $limit = (int) ( $args['limit_response_size'] ?? 0 ); $over = false;
	$ch = curl_init( $url );
	$ca = getenv( 'CURL_CA_BUNDLE' ) ?: getenv( 'SSL_CERT_FILE' ) ?: getenv( 'REQUESTS_CA_BUNDLE' );
	curl_setopt_array( $ch, array(
		CURLOPT_FOLLOWLOCATION => $args['redirection'] > 0, CURLOPT_TIMEOUT => $args['timeout'], CURLOPT_USERAGENT => $args['user-agent'],
		CURLOPT_HTTPHEADER => array( 'Accept: ' . ( $args['headers']['Accept'] ?? '*/*' ) ), CURLOPT_ENCODING => '',
		CURLOPT_WRITEFUNCTION => function ( $c, $chunk ) use ( &$body, $limit, &$over ) { $body .= $chunk; if ( $limit && strlen( $body ) > $limit ) { $over = true; return 0; } return strlen( $chunk ); },
	) + ( $ca ? array( CURLOPT_CAINFO => $ca ) : array() ) );
	$ok = curl_exec( $ch ); $code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); $err = curl_error( $ch ); curl_close( $ch );
	if ( ! $ok && ! $over ) { return new WP_Error( 'http', $err ); }
	return array( 'code' => $code, 'body' => $over ? substr( $body, 0, $limit ) : $body );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; } function wp_remote_retrieve_body( $r ) { return $r['body']; }
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts-local.php';
$L = 'BEPLUSPB_Google_Fonts_Local';

$urls = array(
	'https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&family=Inter:wght@400&display=swap',
	'https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap',
	'https://fonts.googleapis.com/icon?family=Material+Icons',
	'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,400;1,400&display=swap',
	'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0',
);
$failed = 0;
foreach ( $urls as $url ) {
	$t = microtime( true );
	$canonical = $L::normalize_css_url( $url );
	$r = $L::localize_stylesheet( $canonical );
	$ok = isset( $r['ok'] );
	printf( "%s %s\n      %s in %.1fs\n", $ok ? 'ok  ' : 'FAIL', $url, $ok ? $r['fonts'] . ' font files, ' . round( $r['bytes'] / 1024 ) . ' KB' : 'ERROR: ' . $r['error'], microtime( true ) - $t );
	if ( ! $ok ) { ++$failed; continue; }
	$css = file_get_contents( BEPLUSPB_CACHE_DIR . 'fonts/' . $L::id_for( $canonical ) . '.css' );
	if ( false !== strpos( $css, 'gstatic' ) || false !== strpos( $css, 'googleapis' ) ) { echo "FAIL the local stylesheet still points at Google\n"; ++$failed; }
}
$st = $L::status();
printf( "\nstored: %d stylesheets, %d font files, %.0f KB in %s\n", $st['sheets'], $st['fonts'], $st['bytes'] / 1024, BEPLUSPB_CACHE_DIR . 'fonts/' );
$hosts = array_unique( array_map( fn( $u ) => parse_url( $u, PHP_URL_HOST ), $GLOBALS['requests'] ) );
printf( "%d requests, hosts contacted: %s\n", count( $GLOBALS['requests'] ), implode( ', ', $hosts ) );
if ( array_diff( $hosts, array( 'fonts.googleapis.com', 'fonts.gstatic.com' ) ) ) { echo "FAIL contacted a host that is not allowed\n"; ++$failed; }

if ( ! $failed && isset( $argv[2] ) ) {
	require dirname( __DIR__ ) . '/includes/class-bepluspb-google-fonts.php';
	$head = '<meta charset="utf-8"><title>t</title>'
		. "<link rel='stylesheet' id='gf-css' href='https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&#038;family=Inter:wght@400&#038;display=swap' />"
		. "<link rel='stylesheet' href='https://fonts.googleapis.com/icon?family=Material+Icons'>"
		. '<style>@import url(https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap);</style>';
	file_put_contents( $argv[2], BEPLUSPB_Google_Fonts::process( $head, 'swap', array( $L, 'frontend_localize' ) ) );
	echo "sample head written to {$argv[2]}\n";
}
echo $failed ? "$failed FAILURE(S)\n" : "ALL OK\n";
exit( $failed ? 1 : 0 );
