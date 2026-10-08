<?php
/**
 * Standalone Lazy Load v2 behavior test.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
$GLOBALS['hooks'] = array();
$GLOBALS['opts'] = array();
$GLOBALS['wp_version'] = '6.4';
function add_filter($tag,$callback,$priority=10,$args=1){$GLOBALS['hooks'][$tag][]=array($callback,$priority,$args);}
function is_admin(){return !empty($GLOBALS['ctx']['admin']);}
function is_feed(){return !empty($GLOBALS['ctx']['feed']);}
function wp_doing_ajax(){return !empty($GLOBALS['ctx']['ajax']);}
function wp_is_json_request(){return !empty($GLOBALS['ctx']['rest']);}
function bepluspb_get_options(){return $GLOBALS['opts'];}
function absint($v){return abs((int)$v);}
function ok($condition,$message){if(!$condition){throw new RuntimeException('FAIL: '.$message);}}
require_once dirname(__DIR__).'/includes/class-bepluspb-images.php';

$GLOBALS['opts']=array('lazy_load'=>1,'lazy_core_threshold'=>3,'lazy_skip_first_n'=>1,'lazy_exclude_class'=>'hero-image, no-lazy','lazy_exclude_id'=>'site-logo','lazy_exclude_filename'=>'hero.jpg');
BEPLUSPB_Images::init($GLOBALS['opts']);
ok(isset($GLOBALS['hooks']['wp_get_loading_optimization_attributes']),'modern Core attribute policy registered');
ok(isset($GLOBALS['hooks']['wp_omit_loading_attr_threshold']),'Core threshold registered');
foreach(array('render_block','the_content','post_thumbnail_html','widget_text','wp_enqueue_scripts') as $obsolete){ok(!isset($GLOBALS['hooks'][$obsolete]),'no transformer/fallback hook: '.$obsolete);}
$cb=$GLOBALS['hooks']['wp_get_loading_optimization_attributes'][0][0];
$attrs=array('loading'=>'lazy','decoding'=>'async');
$out=call_user_func($cb,$attrs,'img',array('class'=>'foo hero-image bar','src'=>'/x.jpg'),'the_content');
ok(!isset($out['loading']) && 'async'===$out['decoding'],'class exclusion removes only Core lazy');
$out=call_user_func($cb,$attrs,'img',array('class'=>'hero-image','src'=>'/x.jpg','loading'=>'lazy'),'the_content');
ok('lazy'===$out['loading'],'exclusion preserves an explicit author loading attribute');
$out=call_user_func($cb,$attrs,'img',array('id'=>'site-logo','src'=>'/x.jpg'),'the_content');
ok(!isset($out['loading']),'id exclusion works');
$out=call_user_func($cb,$attrs,'img',array('src'=>'/media/hero.jpg?v=1'),'the_content');
ok(!isset($out['loading']),'filename exclusion works');
$explicit=array('loading'=>'eager','fetchpriority'=>'high','decoding'=>'sync');
$out=call_user_func($cb,array('fetchpriority'=>'high','decoding'=>'async'),'img',$explicit,'the_content');
ok('eager'===$out['loading'] && 'high'===$out['fetchpriority'] && 'sync'===$out['decoding'] && 3===count($out),'explicit author optimization attrs preserved byte-for-value');
$out=call_user_func($cb,array('loading'=>'lazy','decoding'=>'async'),'img',array('src'=>'x','class'=>'ordinary','loading'=>'eager'),'the_content');
ok(array('loading'=>'eager','decoding'=>'async')===$out,'explicit attribute does not leak ordinary HTML attributes or discard other Core attributes');
$out=call_user_func($cb,array('loading'=>'lazy','fetchpriority'=>'high'),'img',array('src'=>'x'),'the_content');
ok(!isset($out['loading']) && 'high'===$out['fetchpriority'],'lazy plus high conflict prevented');
$html='<picture><source srcset="a.webp 1x"><img alt="a > b" srcset="a.jpg 1x"></picture>';
ok($html===BEPLUSPB_Images::process_html($html),'HTML/picture/srcset/malformed-compatible content is untouched');
ok(3===BEPLUSPB_Images::filter_threshold(3),'safe default leaves Core threshold unchanged');
$GLOBALS['opts']['lazy_core_threshold']=5;
ok(5===BEPLUSPB_Images::filter_threshold(3),'explicit expert threshold is applied');
foreach(array('admin','feed','ajax','rest') as $gate){$GLOBALS['hooks']=array();$GLOBALS['ctx']=array($gate=>1);BEPLUSPB_Images::init($GLOBALS['opts']);ok(array()===$GLOBALS['hooks'],'request gate '.$gate);}
$GLOBALS['ctx']=array(); $GLOBALS['hooks']=array(); $GLOBALS['wp_version']='6.2'; BEPLUSPB_Images::init($GLOBALS['opts']);
ok(isset($GLOBALS['hooks']['wp_lazy_loading_enabled']) && !isset($GLOBALS['hooks']['wp_get_loading_optimization_attributes']),'WP 5.5-6.2 uses documented Core toggle only');
$GLOBALS['hooks']=array(); $GLOBALS['wp_version']='6.3'; BEPLUSPB_Images::init($GLOBALS['opts']);
ok(isset($GLOBALS['hooks']['wp_lazy_loading_enabled']) && !isset($GLOBALS['hooks']['wp_get_loading_optimization_attributes']),'WP 6.3 retains Core behavior because the attributes filter is unavailable');
$GLOBALS['hooks']=array(); $GLOBALS['wp_version']='5.0'; BEPLUSPB_Images::init($GLOBALS['opts']); ok(array()===$GLOBALS['hooks'],'WP 5.0-5.4 fail open');
require_once __DIR__ . '/helpers/admin-source.php'; // phpcs:ignore
$admin=bepluspb_admin_source();
$readme=file_get_contents(dirname(__DIR__).'/readme.txt');
$ci=file_get_contents(dirname(__DIR__).'/.github/workflows/ci.yml');
ok(false!==strpos($admin,'WordPress 6.4 or newer'),'admin UI identifies the configurable compatibility floor');
ok(false!==strpos($admin,'WordPress 5.5 through 6.3 retains its native behavior'),'admin UI honestly describes the legacy path');
ok(false!==strpos($readme,'WordPress 6.4 or newer'),'readme identifies the configurable compatibility floor');
ok(false!==strpos($ci,'for test in tests/test-*.php'),'CI discovers every standalone PHP test');
echo "PASS: Lazy Load v2 behavior (27 assertions)\n";
