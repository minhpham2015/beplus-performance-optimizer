<?php
/**
 * Test helper: the full source of BEPLUSPB_Admin (main class file plus its traits).
 *
 * Contract tests grep the admin UI/handler source; since the class was split into
 * traits under includes/admin/, they must read all parts.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable
if ( ! function_exists( 'bepluspb_admin_source' ) ) {
	/**
	 * Concatenate the admin class file and every includes/admin trait.
	 *
	 * @return string
	 */
	function bepluspb_admin_source() {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge( array( $root . '/includes/class-bepluspb-admin.php' ), glob( $root . '/includes/admin/*.php' ) );
		$out   = '';
		foreach ( $files as $f ) {
			$out .= file_get_contents( $f ) . "\n";
		}
		return $out;
	}
}
