<?php
/**
 * admin-post handlers, master cache toggle, purge actions and admin notices.
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

trait BEPLUSPB_Admin_Actions {

	/**
	 * Handle POST to admin-post.php?action=bepluspb_quick_enable.
	 *
	 * Enables exactly one boolean option from the Dashboard recommended-settings
	 * panel — every other key in the saved option array is preserved verbatim.
	 *
	 * BUG-FIX (toggle-leak):
	 *   The previous implementation called bepluspb_get_options(), which merges the
	 *   raw DB row with bepluspb_default_options() *before* writing it back. That
	 *   merge would (1) introduce every default key into the DB row, and
	 *   (2) when defaults later changed, replay those new defaults as if the
	 *   user had explicitly opted-in. Reading the raw row via get_option() and
	 *   modifying only the targeted key guarantees no other toggle is affected.
	 */
	public static function handle_quick_enable() {
		$option = isset( $_POST['bepluspb_option'] ) ? sanitize_key( wp_unslash( $_POST['bepluspb_option'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below; the nonce action is keyed on this value.

		// Validate that this is an allowed option key (also required to derive the nonce action).
		$allowed = array( 'lazy_load', 'minify_css_files', 'minify_js_files', 'js_defer', 'remove_emoji', 'css_minify', 'cache_headers' );
		if ( ! in_array( $option, $allowed, true ) ) {
			wp_die( esc_html__( 'Invalid option key.', 'beplus-performance-booster' ) );
		}

		// Nonce BEFORE capability, per CLAUDE.md rule #5.
		check_admin_referer( 'bepluspb_quick_enable_' . $option, 'bepluspb_quick_enable_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'beplus-performance-booster' ) );
		}

		// Read RAW from DB (no defaults merged in) so we modify exactly one key
		// and leave every other previously-saved option untouched.
		$saved = get_option( BEPLUSPB_OPTIONS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved[ $option ] = 1;
		update_option( BEPLUSPB_OPTIONS_KEY, $saved );
		bepluspb_flush_options_cache();

		// FC-1: When the browser-cache option is quick-enabled via the Dashboard,
		// the Settings API sanitize_options() callback is not invoked, so we must
		// trigger .htaccess rule injection manually here.
		if ( 'cache_headers' === $option ) {
			BEPLUSPB_Htaccess::add_rules();
		}

		$redirect = add_query_arg(
			array(
				'page'                   => 'beplus-performance-booster',
				'bepluspb_quick_enabled' => $option,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle POST: enable all recommended options at once.
	 */
	public static function handle_enable_all_recommended() {
		check_admin_referer( 'bepluspb_enable_all_recommended' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'beplus-performance-booster' ) );
		}

		$htaccess_writable = BEPLUSPB_Htaccess::is_writable();
		$allowed           = array( 'lazy_load', 'minify_css_files', 'minify_js_files', 'js_defer', 'remove_emoji', 'cache_headers' );

		$saved = get_option( BEPLUSPB_OPTIONS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		foreach ( $allowed as $key ) {
			if ( 'cache_headers' === $key && ! $htaccess_writable ) {
				continue; // skip locked options.
			}
			$saved[ $key ] = 1;
		}
		update_option( BEPLUSPB_OPTIONS_KEY, $saved );
		bepluspb_flush_options_cache();

		// Trigger .htaccess rules if cache_headers was just enabled.
		if ( $htaccess_writable ) {
			BEPLUSPB_Htaccess::add_rules();
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=beplus-performance-booster&bepluspb_all_enabled=1' ) );
		exit;
	}

	/** Apply, disable, or one-time restore a recommendation plan atomically. */
	public static function handle_recommendation_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'beplus-performance-booster' ) ); }
		check_admin_referer( 'bepluspb_recommendation_action' );
		$operation      = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$posted_profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$profile        = BEPLUSPB_Recommendations::sanitize_profile( $posted_profile );
		if ( ! in_array( $operation, array( 'apply', 'disable', 'restore' ), true ) ) {
			wp_die( esc_html__( 'Invalid recommendation action.', 'beplus-performance-booster' ) ); }
		$saved = get_option( BEPLUSPB_OPTIONS_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( 'restore' === $operation ) {
			$snapshot = get_option( BEPLUSPB_Recommendations::SNAPSHOT_OPTION, array() );
			if ( ! is_array( $snapshot ) || ! empty( $snapshot['used'] ) || empty( $snapshot['values'] ) ) {
				wp_die( esc_html__( 'No unused previous-settings snapshot is available.', 'beplus-performance-booster' ) ); }
			$next                    = BEPLUSPB_Recommendations::restore( $saved, $snapshot );
			$snapshot['used']        = true;
			$snapshot['restored_at'] = time();
			$snapshot['restored_by'] = get_current_user_id();
			update_option( BEPLUSPB_Recommendations::SNAPSHOT_OPTION, $snapshot, false );
		} else {
			$plans = BEPLUSPB_Recommendations::plans( get_bloginfo( 'version' ) );
			$plan  = $plans[ $profile ];
			update_option( BEPLUSPB_Recommendations::SNAPSHOT_OPTION, BEPLUSPB_Recommendations::snapshot( $saved, $profile, get_current_user_id(), time() ), false );
			$next = 'apply' === $operation ? BEPLUSPB_Recommendations::apply_plan( $saved, $plan ) : BEPLUSPB_Recommendations::disable_plan( $saved, $plan );
		}
		update_option( BEPLUSPB_OPTIONS_KEY, $next );
		bepluspb_flush_options_cache();
		if ( ! empty( $next['cache_headers'] ) ) {
			BEPLUSPB_Htaccess::add_rules();
		} else {
			BEPLUSPB_Htaccess::remove_rules(); }
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                         => 'beplus-performance-booster',
					'bepluspb_profile'             => $profile,
					'bepluspb_recommendation_done' => $operation,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// =========================================================================
	// Master cache toggle AJAX handler
	// =========================================================================

	/**
	 * Handle wp_ajax_bepluspb_toggle_cache.
	 *
	 * Reads the raw saved option directly (not through bepluspb_get_options()) so
	 * that defaults are never merged back into the DB row. Only the
	 * cache_enabled key is modified; every other key remains exactly as it was
	 * last saved — toggling the master cache must never cascade-enable other
	 * features (lazy load, minify, defer, etc.).
	 *
	 * Expects POST fields: nonce, enabled (1 or 0).
	 * Returns JSON: { success: true, cache_enabled: 0|1 }.
	 */
	public static function handle_ajax_toggle_cache() {
		// Nonce check first (also verifies the user is logged in).
		check_ajax_referer( 'bepluspb_toggle_cache', 'nonce' );

		// Capability check after nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// Cast to 0 or 1: any truthy POST value → 1, anything else → 0.
		$enabled = isset( $_POST['enabled'] ) ? ( absint( wp_unslash( $_POST['enabled'] ) ) ? 1 : 0 ) : 0;

		// Read RAW saved row from DB — no defaults merged. Then mutate only the
		// single cache_enabled key. This guarantees no other toggle is touched.
		$saved = get_option( BEPLUSPB_OPTIONS_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved['cache_enabled'] = $enabled;
		update_option( BEPLUSPB_OPTIONS_KEY, $saved );

		// Invalidate in-memory cache so any code in this same request that calls
		// bepluspb_get_options() afterwards gets the freshly saved value.
		bepluspb_flush_options_cache();

		wp_send_json_success( array( 'cache_enabled' => $enabled ) );
	}

	// =========================================================================
	// Clear-cache action handler
	// =========================================================================

	/**
	 * Handle POST to admin-post.php?action=bepluspb_clear_cache.
	 */
	public static function handle_purge_all_cache() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Cache purge requires POST.', 'beplus-performance-booster' ), 405 );
		}
		check_admin_referer( 'bepluspb_purge_all_cache', 'bepluspb_purge_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'beplus-performance-booster' ) );
		}
		$user = get_current_user_id();
		$lock = 'bepluspb_purge_all_lock_' . $user;
		if ( get_transient( $lock ) ) {
			self::store_purge_result(
				array(
					'scope'   => 'all',
					'overall' => 'failed',
					'message' => __( 'A cache purge is already in progress. Try again shortly.', 'beplus-performance-booster' ),
					'layers'  => array(),
				)
			);
			self::redirect_after_purge();
		}
		set_transient( $lock, 1, 15 );
		$disk = BEPLUSPB_Minify::clear_cache();
		$cf   = array(
			'status'  => 'skipped',
			'message' => __( 'Cloudflare is not enabled.', 'beplus-performance-booster' ),
		);
		if ( ! empty( bepluspb_get_options()['cloudflare_enabled'] ) ) {
			$raw = BEPLUSPB_Cloudflare::purge_all();
			$cf  = array(
				'status'  => ! empty( $raw['success'] ) ? 'success' : 'failed',
				'message' => ! empty( $raw['success'] ) ? __( 'Cloudflare cache purged.', 'beplus-performance-booster' ) : __( 'Cloudflare purge failed; verify its configuration.', 'beplus-performance-booster' ),
			);
		}
		$overall = ( 'failed' === $disk['status'] && 'failed' === $cf['status'] ) ? 'failed' : ( in_array( 'failed', array( $disk['status'], $cf['status'] ), true ) ? 'partial' : 'success' );
		$report  = array(
			'scope'   => 'all',
			'overall' => $overall,
			'layers'  => array(
				'disk_assets'  => $disk,
				'cloudflare'   => $cf,
				'object_cache' => array(
					'status'  => 'skipped',
					'message' => __( 'Object Cache is excluded from Purge ALL.', 'beplus-performance-booster' ),
				),
			),
		);
		/** Fires after plugin-owned local disk artifacts are purged. Adapters may inspect the structured report; their caches are not claimed as purged. */
		do_action( 'bepluspb_after_local_cache_purge', $report );
		// Keep the short lock as a cooldown against rapid duplicate Cloudflare purges.
		self::store_purge_result( $report );
		self::redirect_after_purge();
	}

	/** Handle the separately confirmed Object Cache purge. */
	public static function handle_purge_object_cache() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'Cache purge requires POST.', 'beplus-performance-booster' ), 405 ); }
		check_admin_referer( 'bepluspb_purge_object_cache', 'bepluspb_object_purge_nonce' );
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $cap ) || empty( $_POST['bepluspb_confirm_object_purge'] ) ) {
			wp_die( esc_html__( 'Object-cache purge was not authorized and confirmed.', 'beplus-performance-booster' ) ); }
		$availability = BEPLUSPB_Object_Cache::get_purge_availability();
		if ( empty( $availability['available'] ) ) {
			self::store_purge_result(
				array(
					'scope'   => 'object',
					'overall' => 'failed',
					'layers'  => array(
						'object_cache' => array(
							'status'  => 'refused',
							'backend' => $availability['backend'],
							'scope'   => $availability['scope'],
							'message' => $availability['reason'],
						),
					),
				)
			);
			self::redirect_after_purge();
		}
		$ok = wp_cache_flush();
		self::store_purge_result(
			array(
				'scope'   => 'object',
				'overall' => $ok ? 'success' : 'failed',
				'layers'  => array(
					'object_cache' => array(
						'status'  => $ok ? 'success' : 'failed',
						'backend' => $availability['backend'],
						'scope'   => $availability['scope'],
						'message' => $ok ? __( 'Persistent Object Cache purged.', 'beplus-performance-booster' ) : __( 'Object Cache purge failed.', 'beplus-performance-booster' ),
					),
				),
			)
		);
		self::redirect_after_purge();
	}

	/** Store a bounded, per-user, one-time purge report.
	 *
	 * @param array $report Sanitized structured report.
	 */
	private static function store_purge_result( $report ) {
		set_transient( 'bepluspb_cache_cleared_' . get_current_user_id(), $report, 60 );
	}

	/** Redirect after a POST to prevent refresh from repeating it. */
	private static function redirect_after_purge() {
		wp_safe_redirect( admin_url( 'options-general.php?page=beplus-performance-booster' ) );
		exit;
	}

	/**
	 * Warn administrators when the enabled Object Cache backend is unavailable.
	 *
	 * Deliberately reports only the selected driver; connection settings and
	 * credentials are never included in admin output.
	 */
	public static function maybe_show_object_cache_warning() {
		$status = BEPLUSPB_Object_Cache::get_status();

		if ( empty( $status['enabled_in_settings'] ) || ! empty( $status['extension_available'] ) ) {
			return;
		}

		$driver = 'memcached' === $status['driver'] ? 'Memcached' : 'Redis';
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: Redis or Memcached. */
					esc_html__( 'Beplus Performance Booster: Object Cache is enabled, but the PHP %s extension is unavailable. Persistent object caching is not active; install/enable the extension or disable Object Cache.', 'beplus-performance-booster' ),
					esc_html( $driver )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display a success notice after the cache has been cleared.
	 *
	 * Reads the result from a short-lived per-user transient set by
	 * handle_clear_cache() so the notice is shown exactly once regardless
	 * of browser history or URL sharing.
	 */
	public static function maybe_show_cleared_notice() {
		$key    = 'bepluspb_cache_cleared_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return; }
		delete_transient( $key );
		$class = 'success' === $report['overall'] ? 'success' : ( 'partial' === $report['overall'] ? 'warning' : 'error' );
		echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p><strong>' . esc_html( sprintf( /* translators: %s: overall purge status. */ __( 'Cache purge result: %s.', 'beplus-performance-booster' ), $report['overall'] ) ) . '</strong></p><ul>';
		foreach ( $report['layers'] as $name => $layer ) {
			$message = isset( $layer['message'] ) ? $layer['message'] : sprintf( /* translators: 1: matched files, 2: deleted files, 3: failed files. */ __( '%1$d matched, %2$d deleted, %3$d failed.', 'beplus-performance-booster' ), $layer['matched'], $layer['deleted'], $layer['failed'] );
			echo '<li>' . esc_html( ucfirst( str_replace( '_', ' ', $name ) ) . ': ' . $layer['status'] . ' — ' . $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	// =========================================================================
	// Per-page cache-disable meta box
	// =========================================================================
}
