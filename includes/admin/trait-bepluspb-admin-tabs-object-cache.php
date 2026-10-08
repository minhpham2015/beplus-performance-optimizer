<?php
/**
 * Object Cache tab renderer.
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

trait BEPLUSPB_Admin_Tabs_Object_Cache {

	/**
	 * Render the "Object Cache" tab — Redis / Memcached persistent cache settings.
	 *
	 * The settings fields (driver, host, port, etc.) are inside the main
	 * settings form so they are saved with the global Save Settings button.
	 * The Install/Remove Drop-in buttons use nonce-signed admin-post.php URLs
	 * (GET-based, like WP core's activate/deactivate plugin links) so they
	 * work without nested <form> tags.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_object_cache( $opts ) {
		$status        = BEPLUSPB_Object_Cache::get_status();
		$dropin_active = $status['dropin_installed'];
		$driver        = isset( $opts['object_cache_driver'] ) ? $opts['object_cache_driver'] : 'redis';

		// Whether a different (non-Beplus) drop-in already exists.
		$alien_dropin = file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && ! $dropin_active;
		?>

		<!-- Settings card -->
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Object Cache', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Persist WordPress object cache to Redis or Memcached to reduce database queries and speed up dynamic pages.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Enable Object Cache -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_enabled"><?php esc_html_e( 'Enable Object Cache', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_object_cache_enabled"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_enabled]" value="1"
								<?php checked( $opts['object_cache_enabled'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Enable persistent object cache via Redis or Memcached.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'The drop-in must be installed (see below) for this to take effect.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Driver -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_driver"><?php esc_html_e( 'Driver', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<select id="bepluspb_object_cache_driver"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_driver]">
							<option value="redis" <?php selected( $driver, 'redis' ); ?>><?php esc_html_e( 'Redis', 'beplus-performance-booster' ); ?></option>
							<option value="memcached" <?php selected( $driver, 'memcached' ); ?>><?php esc_html_e( 'Memcached', 'beplus-performance-booster' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Requires the corresponding PHP extension (Redis or Memcached).', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Host -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_host"><?php esc_html_e( 'Host', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="text" id="bepluspb_object_cache_host"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_host]"
							value="<?php echo esc_attr( $opts['object_cache_host'] ); ?>"
							class="regular-text code"
							placeholder="127.0.0.1">
					</div>
				</div>

				<!-- Port -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_port"><?php esc_html_e( 'Port', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="number" id="bepluspb_object_cache_port"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_port]"
							value="<?php echo esc_attr( $opts['object_cache_port'] ); ?>"
							class="small-text"
							min="1" max="65535">
						<p class="description"><?php esc_html_e( 'Default: 6379 (Redis), 11211 (Memcached).', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Password — Redis only -->
				<div class="bepluspb-form-row" id="bepluspb-oc-password-row"<?php echo ( 'memcached' === $driver ) ? ' style="display:none;"' : ''; ?>>
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_password"><?php esc_html_e( 'Password', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'Redis AUTH only.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="password" id="bepluspb_object_cache_password"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_password]"
							value="<?php echo esc_attr( $opts['object_cache_password'] ); ?>"
							class="regular-text"
							autocomplete="new-password">
						<p class="description"><?php esc_html_e( 'Leave blank if your Redis server does not require authentication.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- DB Index — Redis only -->
				<div class="bepluspb-form-row" id="bepluspb-oc-db-row"<?php echo ( 'memcached' === $driver ) ? ' style="display:none;"' : ''; ?>>
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_db"><?php esc_html_e( 'DB Index', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'Redis only.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="number" id="bepluspb_object_cache_db"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_db]"
							value="<?php echo esc_attr( $opts['object_cache_db'] ); ?>"
							class="small-text"
							min="0" max="15">
						<p class="description"><?php esc_html_e( 'Redis database index (0–15). Default: 0.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Persistent Connection -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_persistent"><?php esc_html_e( 'Persistent Connection', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_object_cache_persistent"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_persistent]" value="1"
								<?php checked( $opts['object_cache_persistent'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Use a persistent connection (pconnect for Redis). Reuses the connection across PHP-FPM requests.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Global Groups -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_global_groups"><?php esc_html_e( 'Global Groups', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One group per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_object_cache_global_groups"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_global_groups]"
							rows="5" class="large-text code"><?php echo esc_textarea( $opts['object_cache_global_groups'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Groups shared across all blogs on a Multisite install — cached without a blog-ID prefix.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Non-Persistent Groups -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_object_cache_non_persistent_groups"><?php esc_html_e( 'Non-Persistent Groups', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One group per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_object_cache_non_persistent_groups"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[object_cache_non_persistent_groups]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['object_cache_non_persistent_groups'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Groups cached only in memory for the current request and never written to Redis/Memcached.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

			</div>
		</div>

		<!-- Connection Status & Drop-in card -->
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Connection Status &amp; Drop-in', 'beplus-performance-booster' ); ?></h2>
			</div>
			<div class="bepluspb-card-body">

				<!-- Drop-in status row -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<?php esc_html_e( 'Drop-in', 'beplus-performance-booster' ); ?>
					</div>
					<div class="bepluspb-form-row-field">
						<?php if ( $dropin_active ) : ?>
							<span class="bepluspb-status-badge bepluspb-status-ok"><?php esc_html_e( 'Installed', 'beplus-performance-booster' ); ?></span>
							<p class="description"><code><?php echo esc_html( WP_CONTENT_DIR . '/object-cache.php' ); ?></code></p>
						<?php elseif ( $alien_dropin ) : ?>
							<span class="bepluspb-status-badge bepluspb-status-warn"><?php esc_html_e( 'Different drop-in present', 'beplus-performance-booster' ); ?></span>
						<?php else : ?>
							<span class="bepluspb-status-badge bepluspb-status-error"><?php esc_html_e( 'Not installed', 'beplus-performance-booster' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<!-- PHP extension status row -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<?php esc_html_e( 'PHP Extension', 'beplus-performance-booster' ); ?>
					</div>
					<div class="bepluspb-form-row-field">
						<?php if ( $status['extension_available'] ) : ?>
							<span class="bepluspb-status-badge bepluspb-status-ok">
								<?php
								printf(
									/* translators: %s: driver name */
									esc_html__( '%s available', 'beplus-performance-booster' ),
									esc_html( ucfirst( $status['driver'] ) )
								);
								?>
							</span>
						<?php else : ?>
							<span class="bepluspb-status-badge bepluspb-status-error">
								<?php
								printf(
									/* translators: %s: driver name */
									esc_html__( 'PHP %s extension not found', 'beplus-performance-booster' ),
									esc_html( ucfirst( $status['driver'] ) )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				</div>

				<!-- Test Connection row -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<?php esc_html_e( 'Connection Test', 'beplus-performance-booster' ); ?>
					</div>
					<div class="bepluspb-form-row-field">
						<button type="button" class="button button-secondary" id="bepluspb-oc-test-btn">
							<?php esc_html_e( 'Test Connection', 'beplus-performance-booster' ); ?>
						</button>
						<div id="bepluspb-oc-test-result" style="margin-top:6px;display:none;"></div>
						<p class="description"><?php esc_html_e( 'Tests the current Host / Port / Password / DB values without saving.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Drop-in Install / Remove row -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<?php esc_html_e( 'Drop-in Actions', 'beplus-performance-booster' ); ?>
					</div>
					<div class="bepluspb-form-row-field">
						<?php if ( $alien_dropin ) : ?>
							<div class="notice notice-warning bepluspb-notice-warning inline" style="margin:0 0 10px;">
								<p><?php esc_html_e( 'A different object-cache drop-in is installed. Safety checks must pass before replacement; replacing a host-managed cache may break the site.', 'beplus-performance-booster' ); ?></p>
								<button type="button" class="button" id="bepluspb-oc-replace-btn"><?php esc_html_e( 'Back up and replace…', 'beplus-performance-booster' ); ?></button>
								<label><input type="checkbox" id="bepluspb-oc-replace-ack"> <?php esc_html_e( 'I understand this may cause downtime and require manual recovery.', 'beplus-performance-booster' ); ?></label>
								<span id="bepluspb-oc-dropin-result"></span>
							</div>
						<?php else : ?>
							<button type="button" class="button button-primary" id="bepluspb-oc-install-btn">
								<?php esc_html_e( 'Install Drop-in', 'beplus-performance-booster' ); ?>
							</button>
							&nbsp;
							<button type="button" class="button" id="bepluspb-oc-remove-btn">
								<?php esc_html_e( 'Remove Drop-in', 'beplus-performance-booster' ); ?>
							</button>
							<span id="bepluspb-oc-dropin-result" style="margin-left:10px;"></span>
							<p class="description" style="margin-top:6px;">
								<?php
								printf(
									/* translators: %s: target file path */
									esc_html__( 'Install copies the bundled drop-in to %s.', 'beplus-performance-booster' ),
									esc_html( WP_CONTENT_DIR . '/object-cache.php' )
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( is_file( WP_CONTENT_DIR . '/bepluspb-backups/restore-manifest.json' ) ) : ?>
						<p><button type="button" class="button" id="bepluspb-oc-restore-btn"><?php esc_html_e( 'Restore previous drop-in…', 'beplus-performance-booster' ); ?></button> <label><input type="checkbox" id="bepluspb-oc-restore-ack"> <?php esc_html_e( 'I understand restore may require manual recovery.', 'beplus-performance-booster' ); ?></label></p>
						<?php endif; ?>
					</div>
				</div>

			</div>
		</div>

		<script>
		(function(){
			// Values rendered directly by PHP so they are available immediately
			// (the external admin-js loads in the footer, after this inline script runs).
			var ajaxUrl      = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var testNonce    = '<?php echo esc_js( wp_create_nonce( 'bepluspb_test_oc' ) ); ?>';
			var installNonce = '<?php echo esc_js( wp_create_nonce( 'bepluspb_install_oc_dropin' ) ); ?>';
			var removeNonce  = '<?php echo esc_js( wp_create_nonce( 'bepluspb_remove_oc_dropin' ) ); ?>';
			var preflightNonce = '<?php echo esc_js( wp_create_nonce( 'bepluspb_preflight_oc_replace' ) ); ?>';
			var replaceNonce = '<?php echo esc_js( wp_create_nonce( 'bepluspb_backup_replace_oc' ) ); ?>';
			var restoreNonce = '<?php echo esc_js( wp_create_nonce( 'bepluspb_restore_oc' ) ); ?>';
			var confirmMsg   = '<?php echo esc_js( __( 'Remove the object-cache drop-in?', 'beplus-performance-booster' ) ); ?>';

			// Show/hide Redis-only rows when driver changes.
			var driverSel = document.getElementById('bepluspb_object_cache_driver');
			if ( driverSel ) {
				driverSel.addEventListener('change', function(){
					var isRedis = ('redis' === this.value);
					['bepluspb-oc-password-row', 'bepluspb-oc-db-row'].forEach(function(id){
						var el = document.getElementById(id);
						if ( el ) { el.style.display = isRedis ? '' : 'none'; }
					});
					var portField = document.getElementById('bepluspb_object_cache_port');
					if ( portField ) { portField.value = isRedis ? '6379' : '11211'; }
				});
			}

			// Test connection via AJAX.
			var testBtn    = document.getElementById('bepluspb-oc-test-btn');
			var testResult = document.getElementById('bepluspb-oc-test-result');
			if ( testBtn && testResult ) {
				testBtn.addEventListener('click', function(){
					testResult.style.display = '';
					testResult.style.color   = '';
					testResult.textContent   = 'Testing…';
					var data = new FormData();
					data.append('action',   'bepluspb_test_oc_connection');
					data.append('nonce',    testNonce);
					data.append('driver',   document.getElementById('bepluspb_object_cache_driver').value);
					data.append('host',     document.getElementById('bepluspb_object_cache_host').value);
					data.append('port',     document.getElementById('bepluspb_object_cache_port').value);
					var pwdEl = document.getElementById('bepluspb_object_cache_password');
					data.append('password', pwdEl ? pwdEl.value : '');
					var dbEl  = document.getElementById('bepluspb_object_cache_db');
					data.append('db',       dbEl ? dbEl.value : '0');
					fetch(ajaxUrl, { method: 'POST', body: data })
						.then(function(r){ return r.json(); })
						.then(function(res){
							testResult.style.color = res.success ? '#46b450' : '#dc3232';
							testResult.textContent = (res.data && res.data.message) ? res.data.message : '—';
						})
						.catch(function(){ testResult.textContent = 'Request failed.'; });
				});
			}

			// Helper: send a drop-in AJAX request.
			function dropinAction(action, nonce, label, resultEl, onSuccess, acknowledge) {
				resultEl.style.color  = '';
				resultEl.textContent  = label;
				var data = new FormData();
				data.append('action', action);
				data.append('nonce',  nonce);
				if ( acknowledge ) { data.append('acknowledge', '1'); }
				fetch(ajaxUrl, { method: 'POST', body: data })
					.then(function(r){ return r.json(); })
					.then(function(res){
						resultEl.style.color = res.success ? '#46b450' : '#dc3232';
						resultEl.textContent = (res.data && res.data.message) ? res.data.message : '—';
						if ( res.success && onSuccess ) { onSuccess(res); }
					})
					.catch(function(){ resultEl.textContent = 'Request failed.'; });
			}

			var dropinResult = document.getElementById('bepluspb-oc-dropin-result');
			var installBtn   = document.getElementById('bepluspb-oc-install-btn');
			var removeBtn    = document.getElementById('bepluspb-oc-remove-btn');

			if ( installBtn && dropinResult ) {
				installBtn.addEventListener('click', function(){
					dropinAction('bepluspb_install_oc_dropin', installNonce, 'Installing…', dropinResult);
				});
			}
			if ( removeBtn && dropinResult ) {
				removeBtn.addEventListener('click', function(){
					if ( ! confirm( confirmMsg ) ) { return; }
					dropinAction('bepluspb_remove_oc_dropin', removeNonce, 'Removing…', dropinResult);
				});
			}

			var replaceBtn = document.getElementById('bepluspb-oc-replace-btn');
			if ( replaceBtn && dropinResult ) { replaceBtn.addEventListener('click', function(){
				if ( ! document.getElementById('bepluspb-oc-replace-ack').checked ) { dropinResult.textContent='Explicit acknowledgement is required.'; return; }
				dropinAction('bepluspb_preflight_oc_replace', preflightNonce, 'Checking safety…', dropinResult, function(){
					if ( confirm('The existing drop-in will be backed up, then atomically replaced. A host-managed cache may break and manual recovery may be required. Continue?') ) { dropinAction('bepluspb_backup_replace_oc', replaceNonce, 'Backing up and replacing…', dropinResult, null, true); }
				});
			}); }
			var restoreBtn = document.getElementById('bepluspb-oc-restore-btn');
			if ( restoreBtn && dropinResult ) { restoreBtn.addEventListener('click', function(){
				if ( ! document.getElementById('bepluspb-oc-restore-ack').checked ) { dropinResult.textContent='Explicit acknowledgement is required.'; return; }
				if ( confirm('The current Beplus drop-in will be backed up before the verified previous file is restored. Manual recovery may still be required. Continue?') ) { dropinAction('bepluspb_restore_oc', restoreNonce, 'Restoring…', dropinResult, null, true); }
			}); }

		}());
		</script>
		<?php
	}

	// =========================================================================
	// Admin bar
	// =========================================================================
}
