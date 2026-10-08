<?php
/**
 * Cloudflare AJAX handlers.
 *
 * Part of BEPLUSPB_Admin, split out of class-bepluspb-admin.php. A trait is used
 * (rather than a separate class) so every method keeps its exact `self::`/`static`
 * semantics, visibility and hook callbacks; behaviour is unchanged.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait BEPLUSPB_Admin_Ajax_Cloudflare {

	/**
	 * AJAX: Test the Cloudflare API Token and, on success, persist the
	 * matched zone_id/zone_name into the plugin options.
	 *
	 * Accepts an api_token from the POST body rather than always reading
	 * the saved option, so a user can test a new token before saving the
	 * settings form (mirrors handle_ajax_test_oc()'s pattern of testing
	 * unsaved connection details).
	 */
	public static function handle_ajax_cf_test_connection() {
		check_ajax_referer( 'bepluspb_cf_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';

		$result = BEPLUSPB_Cloudflare::test_connection_and_fetch_zone( $api_token );

		if ( $result['success'] ) {
			// Persist the matched zone + token together so "Test Connection"
			// alone is enough to configure Cloudflare, without requiring a
			// separate full-form submit.
			$opts                         = bepluspb_get_options();
			$opts['cloudflare_api_token'] = $api_token;
			$opts['cloudflare_zone_id']   = $result['zone_id'];
			$opts['cloudflare_zone_name'] = $result['zone_name'];
			update_option( BEPLUSPB_OPTIONS_KEY, $opts );

			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Purge the entire Cloudflare cache on demand (separate from the
	 * automatic purge-on-clear-cache integration in handle_clear_cache()).
	 *
	 * Rate-limited: Cloudflare's own API enforces per-endpoint rate limits,
	 * and an admin double-clicking (or a stuck browser tab retrying) could
	 * trip Cloudflare's IP-level throttling. A short per-user cooldown here
	 * is cheap insurance against that, independent of the eventual
	 * Cloudflare-side response.
	 */
	public static function handle_ajax_cf_purge() {
		check_ajax_referer( 'bepluspb_cf_purge', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$rate_limit_error = self::check_cloudflare_rate_limit( 'purge' );
		if ( null !== $rate_limit_error ) {
			wp_send_json_error( array( 'message' => $rate_limit_error ), 429 );
		}

		$result = BEPLUSPB_Cloudflare::purge_all();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Enforce a short per-user, per-action cooldown before hitting the
	 * Cloudflare API. Returns null when the call is allowed, or a
	 * user-facing error string (with seconds remaining) when still cooling
	 * down. Shared by the purge and dev-mode AJAX handlers.
	 *
	 * @param string $action Short action key, e.g. 'purge' or 'devmode'.
	 * @return string|null
	 */
	private static function check_cloudflare_rate_limit( $action ) {
		$cooldown_seconds = 10;
		$transient_key    = 'bepluspb_cf_' . $action . '_lock_' . get_current_user_id();

		if ( false !== get_transient( $transient_key ) ) {
			return sprintf(
				/* translators: %d: seconds remaining before the action can be retried. */
				__( 'Please wait %d seconds before trying again (Cloudflare rate limit protection).', 'beplus-performance-booster' ),
				$cooldown_seconds
			);
		}

		set_transient( $transient_key, 1, $cooldown_seconds );

		return null;
	}

	/**
	 * AJAX: Get, turn on, or turn off Cloudflare Development Mode.
	 * Expects $_POST['dev_action'] to be one of 'status' | 'on' | 'off'.
	 *
	 * Rate-limited on 'on'/'off' only — those are the calls that mutate
	 * the zone's setting via the Cloudflare API. A plain 'status' check is
	 * read-only and left unthrottled so the UI can poll it freely.
	 */
	public static function handle_ajax_cf_devmode() {
		check_ajax_referer( 'bepluspb_cf_devmode', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$dev_action = isset( $_POST['dev_action'] ) ? sanitize_key( wp_unslash( $_POST['dev_action'] ) ) : 'status';

		if ( in_array( $dev_action, array( 'on', 'off' ), true ) ) {
			$rate_limit_error = self::check_cloudflare_rate_limit( 'devmode' );
			if ( null !== $rate_limit_error ) {
				wp_send_json_error( array( 'message' => $rate_limit_error ), 429 );
			}
		}

		if ( 'on' === $dev_action ) {
			$result = BEPLUSPB_Cloudflare::set_development_mode( true );
		} elseif ( 'off' === $dev_action ) {
			$result = BEPLUSPB_Cloudflare::set_development_mode( false );
		} else {
			$result = BEPLUSPB_Cloudflare::get_development_mode();
		}

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}
}
