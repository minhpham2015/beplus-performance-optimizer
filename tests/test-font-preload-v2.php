<?php
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
/**
 * Standalone Font Preload v2 behavior test.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if(!defined('ABSPATH')){define('ABSPATH',__DIR__);}
$GLOBALS['filters']=array(); $GLOBALS['ctx']=array();
function wp_unslash($v){return stripslashes($v);} function home_url($p=''){return ($GLOBALS['home']??'https://example.com').$p;}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function esc_url($u){return preg_match('#^(https?://|/)#',$u)&&!preg_match('/["<>\s]/',$u)?htmlspecialchars($u,ENT_QUOTES,'UTF-8'):'';}
function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');} function apply_filters($t,$v){return isset($GLOBALS['filters'][$t])?$GLOBALS['filters'][$t]($v):$v;} function is_admin(){return !empty($GLOBALS['ctx']['admin']);} function is_feed(){return !empty($GLOBALS['ctx']['feed']);} function wp_doing_ajax(){return !empty($GLOBALS['ctx']['ajax']);} function wp_is_json_request(){return !empty($GLOBALS['ctx']['rest']);}
function ok($c,$m){if(!$c){throw new RuntimeException('FAIL: '.$m);}}
require_once dirname(__DIR__).'/includes/class-bepluspb-font-preload.php';
$r=BEPLUSPB_Font_Preload::validate(" /fonts/HERO.WOFF2?v=1 \
https://example.com/fonts/HERO.WOFF2?v=1\nhttps://cdn.example.net/a.woff\nhttps://cdn.example.net/b.ttf\nhttps://cdn.example.net/c.otf\nhttps://cdn.example.net/d.eot",'https://example.com');
ok(4===count($r['valid']),'dedupe equivalent URLs and hard-cap four'); ok('/fonts/HERO.WOFF2?v=1'===$r['valid'][0]['url'],'query and case preserved'); ok('font/woff2'===$r['valid'][0]['type'],'case-insensitive WOFF2 MIME'); ok(!empty($r['valid'][1]['warnings']),'cross-origin and legacy warnings'); ok(!empty($r['errors']),'cap creates diagnostic');
$bad=array('javascript:alert(1)','data:font/woff2,x','blob:x','file:///x.woff2','//cdn.test/x.woff2','http://cdn.test/x.woff2','https://u:p@cdn.test/x.woff2','https://cdn.test/x.woff2#x','https://fonts.googleapis.com/css2?family=X','https://cdn.test/x.css','https://cdn.test/font','https://bad host/x.woff2');
foreach($bad as $u){$x=BEPLUSPB_Font_Preload::validate($u,'https://example.com');ok(empty($x['valid']),'reject '.$u);}
$GLOBALS['home']='http://example.com'; ok(1===count(BEPLUSPB_Font_Preload::validate('http://example.com/a.woff2','http://example.com')['valid']),'HTTP allowed only on HTTP home');
$html=BEPLUSPB_Font_Preload::render("/a.woff2?x=1&y=2\njavascript:x",'https://example.com'); ok(false!==strpos($html,'href="/a.woff2?x=1&amp;y=2"'),'escaped exact query'); ok(false!==strpos($html,'crossorigin="anonymous"'),'anonymous CORS'); ok(false===strpos($html,'href=""')&&false===strpos($html,'javascript'),'invalid never output');
ok('x<link rel="preload" as="font" href="/a.woff2">y'===BEPLUSPB_Font_Preload::protect_from_cdn('x<link rel="preload" as="font" href="/a.woff2">y'),'CDN protection helper preserves preload');
$ports=BEPLUSPB_Font_Preload::validate("/a.woff2\nhttps://example.com:8443/a.woff2\nhttps://example.com:443/a.woff2",'https://example.com'); ok(2===count($ports['valid']),'non-default port stays distinct while explicit HTTPS default port dedupes');
$httpports=BEPLUSPB_Font_Preload::validate("/a.woff2\nhttp://example.com:80/a.woff2",'http://example.com'); ok(1===count($httpports['valid']),'explicit HTTP default port dedupes with root-relative URL');
$GLOBALS['filters']['bepluspb_font_preload_entries']=function($entries){return array(array('url'=>'javascript:x','type'=>'font/woff2'),array('url'=>'/safe.woff2','type'=>'text/html'),'broken','/added.woff2','/added.woff2','/b.woff','/c.ttf','/d.otf','/e.eot');};
set_error_handler(function($severity,$message){throw new RuntimeException($message);}); $filtered=BEPLUSPB_Font_Preload::render('/original.woff2','https://example.com'); restore_error_handler(); ok(false===strpos($filtered,'javascript')&&false===strpos($filtered,'text/html'),'filtered values cannot bypass URL or MIME validation'); ok(1===substr_count($filtered,'/added.woff2'),'filtered duplicates removed'); ok(4===substr_count($filtered,'data-bepluspb-font-preload'),'filtered entries hard-capped'); unset($GLOBALS['filters']['bepluspb_font_preload_entries']);
foreach(array('admin','feed','ajax','rest') as $gate){$GLOBALS['ctx']=array($gate=>1);ok(!BEPLUSPB_Font_Preload::is_frontend_html_request(),'request gate '.$gate);} $GLOBALS['ctx']=array();ok(BEPLUSPB_Font_Preload::is_frontend_html_request(),'frontend allowed');
$admin=bepluspb_admin_source(); $main=file_get_contents(dirname(__DIR__).'/beplus-performance-booster.php'); $cdn=file_get_contents(dirname(__DIR__).'/includes/class-bepluspb-cdn.php'); $readme=file_get_contents(dirname(__DIR__).'/readme.txt'); $ci=file_get_contents(dirname(__DIR__).'/.github/workflows/ci.yml');
foreach(array('aria-describedby','aria-live="polite"','font-display','above-the-fold','DevTools','HTTP Link header','bepluspb_font_preload_entries') as $needle){ok(false!==strpos($admin.$main.$readme,$needle),'UI/docs contract '.$needle);} ok(false!==strpos($admin,'Invalid rows remain saved but are skipped when preload tags are rendered.'),'UI accurately describes render-time validation'); ok(false===strpos($admin,'Validation runs when settings are saved'),'UI does not claim save-time validation'); ok(false===strpos($admin,'Exact final preload tag preview'),'UI does not claim a missing preview'); ok(false!==strpos($cdn,'protect_preload_tags'),'CDN excludes preload tags from rewriting'); ok(false!==strpos($main,'bepluspb_font_preload_changed'),'change hook exists'); ok(false!==strpos($ci,'for test in tests/test-*.php'),'CI discovers test');
echo "PASS: Font Preload v2 behavior (35+ assertions)\n";
