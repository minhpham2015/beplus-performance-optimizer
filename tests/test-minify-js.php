<?php
/**
 * Standalone test of the JavaScript minifier: regex literals, comments, division, bundler output.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
require_once __DIR__ . "/helpers/exclude-list.php";
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
require dirname( __DIR__ ) . '/includes/class-bepluspb-utils.php';
require dirname( __DIR__ ) . '/includes/class-bepluspb-minify.php';
$n = 0;
function t_ok( $c, $m ) { global $n; ++$n; if ( ! $c ) { fwrite( STDERR, "FAIL: $m\n" ); exit( 1 ); } }
$has_node = '' !== trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
function syntax_ok( $js ) { $f = tempnam( sys_get_temp_dir(), 'bpbjs' ); file_put_contents( $f, $js ); exec( 'node --check ' . escapeshellarg( $f ) . ' 2>&1', $o, $rc ); unlink( $f ); return 0 === $rc; }
$strip = function ( $js ) { return BEPLUSPB_Utils::strip_js_comments( $js, true ); };

// ---- regex literals are copied verbatim (the Contact Form 7 bug: `/^\//` ended in "//" = a comment) ----------------
foreach ( array(
	'a=b.replace(/^\//,"");c=1;',
	'a=n.replace(/^\/|\/$/g,"");',
	'x=/https?:\/\//.test(u);',
	'x=/[/]/.test(s);y=/[/*]/g;',
	'x=/\/*/.test(s);',
	'if(/^\//.test(a)){b()}',
	'return /^\//.test(a)',
	'var r=[/a\//,/b\//];',
	'f(a,/^\//,b)',
	'q=a?/x\//:/y\//;',
) as $case ) {
	$out = $strip( $case . "\n" );
	t_ok( trim( $out ) === $case, 'regex kept intact: ' . $case . ' -> ' . trim( $out ) );
}

// ---- comments still go -------------------------------------------------------------------------------------------
t_ok( "a=1;\nb=2;" === trim( $strip( "a=1; // one\nb=2; /* two */" ) ) || 'a=1; ' . "\n" . 'b=2;  ' === $strip( "a=1; // one\nb=2; /* two */" ), 'line and block comments removed' );
t_ok( false === strpos( $strip( 'x=a / b; // half\ny=1' ), 'half' ), 'division is not a regex: the comment after it is still removed' );
t_ok( false === strpos( $strip( 'x=(a+b)/2; // half\ny=1' ), 'half' ), 'division after ")" then a comment' );
t_ok( false === strpos( $strip( "x=a++ / 2; // note\ny=1" ), 'note' ), 'a++ / 2 is a division' );
t_ok( false !== strpos( $strip( '/*! keep */a=1' ), '/*! keep */' ), 'license comment kept' );
t_ok( false !== strpos( $strip( 'u="http://x.y/z";v=1' ), 'http://x.y/z' ), 'URL inside a string kept' );
t_ok( false !== strpos( $strip( 'u=a//c\nb' ), 'u=a' ) && false === strpos( $strip( "u=a //c\nb" ), 'c' ), 'a comment right after code goes' );
t_ok( false === strpos( $strip( "x = /re/.test(s); // trailing\ny=2" ), 'trailing' ) && false !== strpos( $strip( "x = /re/.test(s); // trailing\ny=2" ), '/re/.test(s);' ), 'comment after a regex goes, the regex stays' );

// ---- bundler output (one long line) is left alone ------------------------------------------------------------------
$bundle = '(()=>{"use strict";' . str_repeat( 'a=a.replace(/^\//,"");', 80 ) . '})();';
t_ok( $bundle === BEPLUSPB_Minify::minify_js( $bundle ), 'a single-line (already minified) file is returned unchanged' );
$normal = "/* c */\nfunction a() {\n\t// x\n\treturn 1;\n}\n";
t_ok( false === strpos( BEPLUSPB_Minify::minify_js( $normal ), 'x' ) && false !== strpos( BEPLUSPB_Minify::minify_js( $normal ), 'return 1;' ), 'ordinary source is still minified' );

// ---- "Exclude JS Files" also keeps a script out of minify -------------------------------------------------------------------
function bepluspb_get_options() { return array( 'js_exclude' => "contact-form-7\nmy-critical" ); }
function bepluspb_parse_exclude_list( $t ) { return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $t ) ) ) ); }
foreach ( array( 'https://ex.com/wp-content/plugins/contact-form-7/includes/js/index.js?ver=6', 'https://ex.com/js/my-critical.js' ) as $url ) {
	t_ok( $url === BEPLUSPB_Minify::maybe_minify_js( $url, 'h' ), 'excluded script is returned untouched by minify: ' . $url );
}

// ---- real code through node ---------------------------------------------------------------------------------------------
if ( $has_node ) {
	$sample = "'use strict';\n/* license */\nconst a = (s) => s.replace(/^\\//, '').replace(/\\/\$/, ''); // trim\nconst b = 10 / 2 / 5; // math\nconst c = /[/*]+/.test('x') ? 1 : 2;\nmodule.exports = { a, b, c };\n";
	$min    = BEPLUSPB_Minify::minify_js( $sample );
	t_ok( syntax_ok( $sample ) && syntax_ok( $min ), 'node accepts the sample before and after minifying' );
	$run    = function ( $js ) { $f = tempnam( sys_get_temp_dir(), 'bpbjs' ); file_put_contents( $f, $js ); $r = trim( (string) shell_exec( 'node -e ' . escapeshellarg( "const m=require('$f');console.log(m.a('/x/'),m.b,m.c)" ) ) ); unlink( $f ); return $r; };
	t_ok( $run( $sample ) === $run( $min ) && '' !== $run( $min ), 'same result before and after minifying (' . $run( $min ) . ')' );
} else {
	fwrite( STDERR, "(node not found: runtime checks skipped)\n" );
}

echo "OK ($n assertions)\n";
