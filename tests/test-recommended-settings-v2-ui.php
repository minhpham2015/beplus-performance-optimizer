<?php
/**
 * Standalone Recommended Settings v2 contract test.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
$root=dirname(__DIR__); $admin=file_get_contents($root.'/includes/class-bepluspb-admin.php'); $css=file_get_contents($root.'/assets/css/admin.css'); $js=file_get_contents($root.'/assets/js/admin.js'); $boot=file_get_contents($root.'/beplus-performance-booster.php');
function has($n,$h,$m){if(false===strpos($h,$n)){throw new RuntimeException('FAIL: '.$m);}}
function lacks($n,$h,$m){if(false!==strpos($h,$n)){throw new RuntimeException('FAIL: '.$m);}}
has("class-bepluspb-recommendations.php",$boot,'engine loaded');
has("admin_post_bepluspb_recommendation_action",$admin,'secured endpoint registered');
has("check_admin_referer( 'bepluspb_recommendation_action'",$admin,'nonce checked');
has("current_user_can( 'manage_options' )",$admin,'capability checked');
has('bepluspb-recommendation-profile',$admin,'profile override exists');
has('Detected signals',$admin,'signals displayed'); has('Confidence',$admin,'confidence displayed');
has('Exact changes before save',$admin,'diff preview exists');
has('Apply Recommended',$admin,'apply action exists'); has('Disable Recommended',$admin,'disable action exists'); has('Restore Previous Settings',$admin,'restore exists');
has('does not deactivate the plugin',$admin,'disable scope explained');
has('data-recommendation-confirm',$admin,'confirmation contract');
has('.bepluspb-recommendation-grid',$css,'responsive cards styled'); has('@media ( max-width: 782px )',$css,'responsive breakpoint');
has("querySelectorAll('[data-recommendation-confirm]')",$js,'confirmation behavior external');
lacks('dataLayer',$js,'no telemetry'); echo "PASS: Recommended Settings v2 UI contract\n";
