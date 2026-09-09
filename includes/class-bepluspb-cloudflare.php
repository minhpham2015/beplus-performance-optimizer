<?php
/**
 * Cloudflare API integration: cache purge, development mode, and zone
 * lookup by API Token. Admin-triggered only (no front-end/outbound calls
 * on regular page loads) — mirrors the "no outbound requests from the
 * front-end path" principle already followed by class-bepluspb-cdn.php.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Cloudflare
 */
class BEPLUSPB_Cloudflare {

	const API_BASE = 'https://api.cloudflare.com/client/v4';

	/**
	 * Purge everything on the configured Cloudflare zone.
	 *
	 * Safe to call unconditionally — no-ops (returns a wp_error-like array)
	 * when Cloudflare integration is disabled or not configured, so callers
	 * (e.g. handle_clear_cache()) never need their own enabled-check.
	 *
	 * @return array{success:bool,message:string}
	 */
	public static function purge_all() {
		$opts = bepluspb_get_options();

		if ( empty( $opts['cloudflare_enabled'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Cloudflare integration is turned off.', 'beplus-performance-booster' ),
			);
		}

		$zone_id = $opts['cloudflare_zone_id'];
		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured. Test the connection first.', 'beplus-performance-booster' ),
			);
		}

		$result = self::call(
			$opts['cloudflare_api_token'],
			'DELETE',
			"/zones/{$zone_id}/purge_cache",
			array( 'purge_everything' => true )
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to purge Cloudflare cache: ', 'beplus-performance-booster' ) . $result['message'],
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Cloudflare cache purged successfully.', 'beplus-performance-booster' ),
		);
	}

	/**
	 * Look up the Cloudflare zone matching the current site's domain and
	 * persist its zone_id into the plugin options.
	 *
	 * Called from the "Test Connection" button — never runs automatically
	 * on a page load, only on explicit admin action.
	 *
	 * @param string $api_token API Token to test/use (not necessarily the
	 *                          one already saved — lets "Test Connection"
	 *                          validate a token before the settings form is
	 *                          submitted).
	 * @return array{success:bool,message:string,zone_id:string,zone_name:string}
	 */
	public static function test_connection_and_fetch_zone( $api_token ) {
		if ( ! $api_token ) {
			return array(
				'success'   => false,
				'message'   => __( 'No API Token provided.', 'beplus-performance-booster' ),
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		$domain = self::current_site_domain();

		$result = self::call( $api_token, 'GET', '/zones?status=active&name=' . rawurlencode( $domain ) );

		if ( ! $result['success'] ) {
			return array(
				'success'   => false,
				'message'   => $result['message'],
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		if ( empty( $result['data'] ) ) {
			return array(
				'success'   => false,
				/* translators: %s: site domain that was looked up on Cloudflare. */
				'message'   => sprintf( __( 'No Cloudflare zone found for domain "%s". Confirm the domain is added to your Cloudflare account.', 'beplus-performance-booster' ), $domain ),
				'zone_id'   => '',
				'zone_name' => '',
			);
		}

		$zone = $result['data'][0];

		return array(
			'success'   => true,
			/* translators: %s: matched Cloudflare zone name (domain). */
			'message'   => sprintf( __( 'Connected. Matched zone: %s', 'beplus-performance-booster' ), $zone['name'] ),
			'zone_id'   => $zone['id'],
			'zone_name' => $zone['name'],
		);
	}

	/**
	 * Get the current Cloudflare development-mode status for the
	 * configured zone.
	 *
	 * @return array{success:bool,message:string,enabled:bool}
	 */
	public static function get_development_mode() {
		$opts    = bepluspb_get_options();
		$zone_id = $opts['cloudflare_zone_id'];

		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured.', 'beplus-performance-booster' ),
				'enabled' => false,
			);
		}

		$result = self::call( $opts['cloudflare_api_token'], 'GET', "/zones/{$zone_id}/settings/development_mode" );

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => $result['message'],
				'enabled' => false,
			);
		}

		return array(
			'success' => true,
			'message' => '',
			'enabled' => 'on' === $result['data']['value'],
		);
	}

	/**
	 * Turn Cloudflare Development Mode on or off for the configured zone.
	 *
	 * Development Mode auto-expires after 3 hours — that expiry is a
	 * Cloudflare-side behavior, not something this plugin tracks or relies
	 * on; "Check Status" always re-queries the live value.
	 *
	 * @param bool $on True to turn on, false to turn off.
	 * @return array{success:bool,message:string}
	 */
	public static function set_development_mode( $on ) {
		$opts    = bepluspb_get_options();
		$zone_id = $opts['cloudflare_zone_id'];

		if ( ! $zone_id ) {
			return array(
				'success' => false,
				'message' => __( 'No Cloudflare zone configured.', 'beplus-performance-booster' ),
			);
		}

		$result = self::call(
			$opts['cloudflare_api_token'],
			'PATCH',
			"/zones/{$zone_id}/settings/development_mode",
			array( 'value' => $on ? 'on' : 'off' )
		);

		if ( ! $result['success'] ) {
			return array(
				'success' => false,
				'message' => $result['message'],
			);
		}

		return array(
			'success' => true,
			'message' => $on
				? __( 'Development Mode turned ON. It will automatically turn off after 3 hours.', 'beplus-performance-booster' )
				: __( 'Development Mode turned OFF.', 'beplus-performance-booster' ),
		);
	}

	/**
	 * Low-level Cloudflare API call, API-Token auth only.
	 *
	 * Never logs the token or the raw request/response — Cloudflare API
	 * Tokens are as sensitive as the Redis AUTH password this plugin
	 * already protects (see class-bepluspb-object-cache.php Hard Rule #1);
	 * they are stored in wp_options (DB-only, never a static file), and
	 * intentionally never written to any debug log.
	 *
	 * @param string      $api_token API Token.
	 * @param string      $method    HTTP method.
	 * @param string      $path      API path, starting with '/'.
	 * @param array|false $body      Request body to JSON-encode, or false for none.
	 * @return array{success:bool,message:string,data:mixed}
	 */
	private static function call( $api_token, $method, $path, $body = false ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( false !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'data'    => null,
			);
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $json ) || empty( $json['success'] ) ) {
			$error_message = __( 'Unexpected response from Cloudflare.', 'beplus-performance-booster' );
			if ( is_array( $json ) && ! empty( $json['errors'][0]['message'] ) ) {
				$error_message = sanitize_text_field( $json['errors'][0]['message'] );
			}
			return array(
				'success' => false,
				'message' => $error_message,
				'data'    => null,
			);
		}

		return array(
			'success' => true,
			'message' => '',
			'data'    => $json['result'],
		);
	}

	/**
	 * Get the current site's bare domain (no scheme, no www, no path) for
	 * matching against Cloudflare zone names.
	 *
	 * @return string
	 */
	private static function current_site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = (string) $host;
		return preg_replace( '/^www\./', '', $host );
	}
}
