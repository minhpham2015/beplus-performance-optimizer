<?php
/**
 * Test double of the bootstrap helper bepluspb_exclude_list(): the saved "Exclude ..." setting only
 * (the automatic exclusions of BEPLUSPB_Compat have their own tests).
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! function_exists( 'bepluspb_exclude_list' ) ) {
	function bepluspb_exclude_list( $opts, $kind ) {
		$key = array( 'js' => 'js_exclude', 'css' => 'css_exclude', 'pages' => 'cache_exclude_pages' );
		return isset( $key[ $kind ], $opts[ $key[ $kind ] ] ) ? bepluspb_parse_exclude_list( $opts[ $key[ $kind ] ] ) : array();
	}
}
