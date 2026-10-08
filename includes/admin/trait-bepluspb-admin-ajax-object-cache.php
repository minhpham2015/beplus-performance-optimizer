<?php
/**
 * Object Cache AJAX handlers (test, install, replace, restore, remove).
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

trait BEPLUSPB_Admin_Ajax_Object_Cache {

	/**
	 * AJAX: Test object cache connection.
	 */
	public static function handle_ajax_test_oc() {
		check_ajax_referer( 'bepluspb_test_oc', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ) );
		}

		$cfg = array(
			'driver'   => isset( $_POST['driver'] ) ? sanitize_text_field( wp_unslash( $_POST['driver'] ) ) : 'redis',
			'host'     => isset( $_POST['host'] ) ? sanitize_text_field( wp_unslash( $_POST['host'] ) ) : '127.0.0.1',
			'port'     => isset( $_POST['port'] ) ? absint( $_POST['port'] ) : 6379,
			'password' => isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '',
			'db'       => isset( $_POST['db'] ) ? absint( $_POST['db'] ) : 0,
		);

		$result = BEPLUSPB_Object_Cache::test_connection( $cfg );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Install object cache drop-in.
	 */
	public static function handle_ajax_install_oc() {
		check_ajax_referer( 'bepluspb_install_oc_dropin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$result = BEPLUSPB_Object_Cache::install_with_config( bepluspb_get_options() );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}


	/** Build the guarded drop-in workflow. */
	private static function dropin_workflow() {
		return new BEPLUSPB_Dropin_Workflow(
			WP_CONTENT_DIR,
			dirname( __DIR__ ) . '/lib/object-cache.php',
			array(
				'backend_test' => function () {
					return BEPLUSPB_Object_Cache::test_connection( bepluspb_get_options() );
				},
			)
		);
	}
	/**
	 * Authorize a destructive action.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function authorize_dropin_action( $nonce ) {
		check_ajax_referer( $nonce, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 ); }
	}
	/** Preflight foreign replacement. */
	public static function handle_ajax_preflight_oc_replace() {
		self::authorize_dropin_action( 'bepluspb_preflight_oc_replace' );
		$r = self::dropin_workflow()->preflight( 'replace' );
		$r['success'] ? wp_send_json_success( $r ) : wp_send_json_error( $r ); }
	/** Back up and replace foreign drop-in. */
	public static function handle_ajax_backup_replace_oc() {
		self::authorize_dropin_action( 'bepluspb_backup_replace_oc' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_dropin_action verified it.
		if ( empty( $_POST['acknowledge'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Explicit acknowledgement is required.', 'beplus-performance-booster' ) ), 400 );
		} $r = self::dropin_workflow()->replace();
		$r['success'] ? wp_send_json_success( $r ) : wp_send_json_error( $r ); }
	/** Restore verified previous drop-in. */
	public static function handle_ajax_restore_oc() {
		self::authorize_dropin_action( 'bepluspb_restore_oc' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_dropin_action verified it.
		if ( empty( $_POST['acknowledge'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Explicit acknowledgement is required.', 'beplus-performance-booster' ) ), 400 );
		} $r = self::dropin_workflow()->restore();
		$r['success'] ? wp_send_json_success( $r ) : wp_send_json_error( $r ); }

	/**
	 * AJAX: Remove object cache drop-in.
	 */
	public static function handle_ajax_remove_oc() {
		check_ajax_referer( 'bepluspb_remove_oc_dropin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$result = BEPLUSPB_Object_Cache::uninstall_dropin();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// =========================================================================
	// Cloudflare AJAX handlers
	// =========================================================================
}
