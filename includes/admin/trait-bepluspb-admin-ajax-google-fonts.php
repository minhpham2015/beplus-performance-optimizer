<?php
/**
 * Self-hosted Google Fonts: the AJAX handlers behind "Download now" and "Delete local fonts".
 *
 * Part of BEPLUSPB_Admin, split out of class-bepluspb-admin.php (see the other traits).
 *
 * Neither handler takes a URL: "Download now" runs the same queue the cron run works through (the
 * stylesheets the site's own pages use, validated in BEPLUSPB_Google_Fonts_Local), and the result
 * lists exactly which Google URLs were queued and why a download failed.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait BEPLUSPB_Admin_Ajax_Google_Fonts {

	/**
	 * AJAX: download what is queued, now (instead of waiting for WP-Cron).
	 */
	public static function handle_ajax_gf_download() {
		check_ajax_referer( 'bepluspb_gf_download', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged -- a download run may need longer than the default.
		}

		$had_queue = (bool) BEPLUSPB_Google_Fonts_Local::queue();
		$summary   = BEPLUSPB_Google_Fonts_Local::process_queue( BEPLUSPB_Google_Fonts_Local::TIME_BUDGET );
		$status    = self::gf_status_payload();

		if ( ! $had_queue ) {
			$message = $status['sheets']
				? __( 'Everything the site uses is already downloaded.', 'beplus-performance-booster' )
				: __( 'Nothing to download yet. Open a page of your site once in a private (logged-out) window: the plugin then sees which Google Fonts it uses and queues them. Then press this button again.', 'beplus-performance-booster' );
		} else {
			/* translators: 1: stylesheets downloaded, 2: stylesheets that failed, 3: stylesheets still waiting. */
			$message = sprintf( __( 'Downloaded: %1$d. Failed: %2$d. Still waiting: %3$d.', 'beplus-performance-booster' ), $summary['done'], $summary['failed'], $summary['pending'] );
		}

		wp_send_json_success( array_merge( $status, array( 'message' => $message ) ) );
	}

	/**
	 * AJAX: delete every local font and stylesheet (pages go back to the Google URLs).
	 */
	public static function handle_ajax_gf_clear() {
		check_ajax_referer( 'bepluspb_gf_clear', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$removed = BEPLUSPB_Google_Fonts_Local::clear();

		wp_send_json_success(
			array_merge(
				self::gf_status_payload(),
				array(
					/* translators: %d: number of files removed. */
					'message' => sprintf( __( 'Deleted %d local font files.', 'beplus-performance-booster' ), $removed ),
				)
			)
		);
	}

	/**
	 * What the settings screen shows about the local fonts.
	 *
	 * @return array { sheets: int, fonts: int, text: string, queue: array[] } where each queue item
	 *               has url, fails and error (plain text, shown with textContent/esc_html only).
	 */
	private static function gf_status_payload() {
		$status = BEPLUSPB_Google_Fonts_Local::status();
		return array(
			'sheets' => $status['sheets'],
			'fonts'  => $status['fonts'],
			/* translators: 1: number of local stylesheets, 2: number of font files, 3: size. */
			'text'   => sprintf( __( '%1$d stylesheets and %2$d font files stored here (%3$s).', 'beplus-performance-booster' ), $status['sheets'], $status['fonts'], size_format( $status['bytes'] ) ),
			'queue'  => $status['queue'],
		);
	}
}
