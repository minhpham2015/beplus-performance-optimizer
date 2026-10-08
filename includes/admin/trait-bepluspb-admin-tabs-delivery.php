<?php
/**
 * Fonts, Cloudflare, CDN and Cleanup tab renderers.
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

trait BEPLUSPB_Admin_Tabs_Delivery {

	/**
	 * Render the "Fonts" tab — font preload.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_fonts( $opts ) {
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Font Preload', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Global font preload hints can help only when the exact font is critical above-the-fold. They do not guarantee a performance improvement.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_font_preload"><?php esc_html_e( 'Font URLs to Preload', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One font URL per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_font_preload" aria-describedby="bepluspb-font-help bepluspb-font-status"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[font_preload]"
							rows="6" class="large-text code"><?php echo esc_textarea( $opts['font_preload'] ); ?></textarea>
						<p id="bepluspb-font-status" aria-live="polite"><?php esc_html_e( 'Invalid rows remain saved but are skipped when preload tags are rendered.', 'beplus-performance-booster' ); ?></p>
						<p id="bepluspb-font-help" class="description">
							<?php esc_html_e( 'Each URL will be output as a &lt;link rel="preload" as="font" crossorigin="anonymous"&gt; tag near the top of &lt;head&gt;.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Supports woff2, woff, ttf, otf, eot. Example:', 'beplus-performance-booster' ); ?><br>
							<code>/wp-content/themes/my-theme/fonts/myfont.woff2</code><br>
							<?php esc_html_e( 'Use the exact final @font-face URL. Prefer WOFF2 and font-display; preload only one or two measured above-the-fold fonts. Check DevTools for unused preload warnings and configure anonymous CORS for CDN fonts. Google Fonts CSS is a stylesheet, not a font URL.', 'beplus-performance-booster' ); ?><br>

							<?php esc_html_e( 'This registry can deduplicate plugin entries, but cannot detect theme output or an HTTP Link header. Developers may use the bepluspb_font_preload_entries filter for per-request scope.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cloudflare" tab — API-triggered cache purge, zone lookup,
	 * and development mode toggle.
	 *
	 * Follows the same inline-<script>+fetch()+FormData AJAX pattern used
	 * by the Object Cache tab's "Test Connection"/drop-in buttons, rather
	 * than the external admin.js file, for consistency within this file.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_cloudflare( $opts ) {
		$has_token = ! empty( $opts['cloudflare_api_token'] );
		$has_zone  = ! empty( $opts['cloudflare_zone_id'] );
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Cloudflare', 'beplus-performance-booster' ); ?></h2>
				<p>
					<?php
					esc_html_e( 'Keep Cloudflare\'s edge cache in sync with this plugin\'s own cache, and control Cloudflare Development Mode, without leaving wp-admin. This does not set up Cloudflare as a CDN/DNS proxy for you — it only talks to a Cloudflare zone you have already added your domain to.', 'beplus-performance-booster' );
					?>
				</p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Enable Cloudflare -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cloudflare_enabled"><?php esc_html_e( 'Enable Cloudflare Integration', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_cloudflare_enabled"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cloudflare_enabled]" value="1"
								<?php checked( $opts['cloudflare_enabled'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'When on, the existing "Clear Cache" button also purges Cloudflare\'s cache for this zone.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- API Token -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cloudflare_api_token"><?php esc_html_e( 'API Token', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="password" id="bepluspb_cloudflare_api_token"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cloudflare_api_token]"
							value="<?php echo esc_attr( $opts['cloudflare_api_token'] ); ?>"
							class="regular-text code"
							autocomplete="off"
							placeholder="<?php esc_attr_e( 'Cloudflare API Token', 'beplus-performance-booster' ); ?>">
						<button type="button" id="bepluspb-cf-test-btn" class="button">
							<?php esc_html_e( 'Test Connection', 'beplus-performance-booster' ); ?>
						</button>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to Cloudflare's API token dashboard. */
								esc_html__( 'Create a token at %s. Legacy Global API Keys are not supported — use an API Token scoped to Zone > Cache Purge and Zone > Zone Settings.', 'beplus-performance-booster' ),
								'<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">dash.cloudflare.com/profile/api-tokens</a>'
							);
							?>
						</p>
						<p id="bepluspb-cf-test-result" style="display:<?php echo $has_token ? '' : 'none'; ?>;"></p>
					</div>
				</div>

				<!-- Matched zone (read-only, auto-populated by Test Connection) -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label><?php esc_html_e( 'Matched Zone', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<code id="bepluspb-cf-zone-display">
							<?php
							echo $has_zone
								? esc_html( $opts['cloudflare_zone_name'] . ' (' . $opts['cloudflare_zone_id'] . ')' )
								: esc_html__( 'Not configured — click "Test Connection" above.', 'beplus-performance-booster' );
							?>
						</code>
					</div>
				</div>

			</div>
		</div>

		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Cloudflare Cache', 'beplus-performance-booster' ); ?></h2>
			</div>
			<div class="bepluspb-card-body">
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-field">
						<button type="button" id="bepluspb-cf-purge-btn" class="button button-secondary" <?php disabled( ! $has_zone ); ?>>
							<?php esc_html_e( 'Purge Cloudflare Now', 'beplus-performance-booster' ); ?>
						</button>
						<p id="bepluspb-cf-purge-result"></p>
					</div>
				</div>
			</div>
		</div>

		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Development Mode', 'beplus-performance-booster' ); ?></h2>
				<p>
					<?php esc_html_e( 'Temporarily bypasses Cloudflare\'s cache so origin changes show up immediately. Cloudflare automatically turns this off after 3 hours.', 'beplus-performance-booster' ); ?>
				</p>
			</div>
			<div class="bepluspb-card-body">
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-field">
						<button type="button" id="bepluspb-cf-devmode-on-btn" class="button" <?php disabled( ! $has_zone ); ?>>
							<?php esc_html_e( 'Turn ON', 'beplus-performance-booster' ); ?>
						</button>
						<button type="button" id="bepluspb-cf-devmode-off-btn" class="button" <?php disabled( ! $has_zone ); ?>>
							<?php esc_html_e( 'Turn OFF', 'beplus-performance-booster' ); ?>
						</button>
						<button type="button" id="bepluspb-cf-devmode-status-btn" class="button button-secondary" <?php disabled( ! $has_zone ); ?>>
							<?php esc_html_e( 'Check Status', 'beplus-performance-booster' ); ?>
						</button>
						<p id="bepluspb-cf-devmode-result"></p>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function(){
			var ajaxUrl        = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
			var cfTestNonce    = '<?php echo esc_js( wp_create_nonce( 'bepluspb_cf_test_connection' ) ); ?>';
			var cfPurgeNonce   = '<?php echo esc_js( wp_create_nonce( 'bepluspb_cf_purge' ) ); ?>';
			var cfDevmodeNonce = '<?php echo esc_js( wp_create_nonce( 'bepluspb_cf_devmode' ) ); ?>';

			function cfAjax(action, nonce, extra, resultEl, busyLabel, btn, cooldownMs) {
				resultEl.style.color = '';
				resultEl.style.display = '';
				resultEl.textContent = busyLabel;
				if (btn) { btn.disabled = true; }
				var data = new FormData();
				data.append('action', action);
				data.append('nonce', nonce);
				for (var key in extra) {
					if (Object.prototype.hasOwnProperty.call(extra, key)) {
						data.append(key, extra[key]);
					}
				}
				fetch(ajaxUrl, { method: 'POST', body: data })
					.then(function(r){ return r.json(); })
					.then(function(res){
						resultEl.style.color = res.success ? '#46b450' : '#dc3232';
						resultEl.textContent = (res.data && res.data.message) ? res.data.message : '—';
						if ( res.success && onSuccess ) { onSuccess(res); }
						// Keep the button disabled for the cooldown window on success
						// (mirrors the server-side per-user rate limit) so a second
						// click can't queue up while Cloudflare is still processing.
						// On failure (including a 429 from the rate limit itself),
						// re-enable right away so the admin isn't stuck waiting on
						// top of an already-failed attempt.
						if (btn) {
							if (res.success && cooldownMs) {
								setTimeout(function(){ btn.disabled = false; }, cooldownMs);
							} else {
								btn.disabled = false;
							}
						}
						return res;
					})
					.catch(function(){
						resultEl.textContent = 'Request failed.';
						if (btn) { btn.disabled = false; }
					});
			}

			var testBtn = document.getElementById('bepluspb-cf-test-btn');
			var testResult = document.getElementById('bepluspb-cf-test-result');
			if (testBtn && testResult) {
				testBtn.addEventListener('click', function(){
					var tokenEl = document.getElementById('bepluspb_cloudflare_api_token');
					cfAjax('bepluspb_cf_test_connection', cfTestNonce, { api_token: tokenEl.value }, testResult, 'Testing…')
						.then(function(res){
							if (res && res.success) {
								var zoneDisplay = document.getElementById('bepluspb-cf-zone-display');
								if (zoneDisplay) { zoneDisplay.textContent = res.data.zone_name + ' (' + res.data.zone_id + ')'; }
								['bepluspb-cf-purge-btn', 'bepluspb-cf-devmode-on-btn', 'bepluspb-cf-devmode-off-btn', 'bepluspb-cf-devmode-status-btn'].forEach(function(id){
									var el = document.getElementById(id);
									if (el) { el.disabled = false; }
								});
							}
						});
				});
			}

			var purgeBtn = document.getElementById('bepluspb-cf-purge-btn');
			var purgeResult = document.getElementById('bepluspb-cf-purge-result');
			if (purgeBtn && purgeResult) {
				purgeBtn.addEventListener('click', function(){
					cfAjax('bepluspb_cf_purge', cfPurgeNonce, {}, purgeResult, 'Purging…', purgeBtn, 10000);
				});
			}

			var devResult = document.getElementById('bepluspb-cf-devmode-result');
			var onBtn     = document.getElementById('bepluspb-cf-devmode-on-btn');
			var offBtn    = document.getElementById('bepluspb-cf-devmode-off-btn');
			var statusBtn = document.getElementById('bepluspb-cf-devmode-status-btn');
			if (onBtn && devResult) {
				onBtn.addEventListener('click', function(){
					cfAjax('bepluspb_cf_devmode', cfDevmodeNonce, { dev_action: 'on' }, devResult, 'Turning ON…', onBtn, 10000);
				});
			}
			if (offBtn && devResult) {
				offBtn.addEventListener('click', function(){
					cfAjax('bepluspb_cf_devmode', cfDevmodeNonce, { dev_action: 'off' }, devResult, 'Turning OFF…', offBtn, 10000);
				});
			}
			if (statusBtn && devResult) {
				statusBtn.addEventListener('click', function(){
					// Read-only status check — not rate-limited server-side, so no
					// button/cooldown args here either.
					cfAjax('bepluspb_cf_devmode', cfDevmodeNonce, { dev_action: 'status' }, devResult, 'Checking…');
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Render the "CDN" tab — custom pull-zone static-asset offloading.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_cdn( $opts ) {
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Custom CDN (Pull Zone)', 'beplus-performance-booster' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to quic.cloud */
						esc_html__( 'Serve static files (CSS, JS, images, fonts) from a CDN instead of your own server. Sign up for a free CDN zone with a provider such as %s, point it at this site, and paste the domain it gives you below. This is a generic pull-zone rewriter — it is not an official QUIC.cloud integration and works with any CDN provider.', 'beplus-performance-booster' ),
						'<a href="https://quic.cloud/" target="_blank" rel="noopener noreferrer">QUIC.cloud</a>'
					);
					?>
				</p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Enable CDN -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cdn_enabled"><?php esc_html_e( 'Enable CDN', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_cdn_enabled"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cdn_enabled]" value="1"
								<?php checked( $opts['cdn_enabled'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Rewrite matching static-asset URLs on this site to the CDN domain below.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- CDN URL -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cdn_url"><?php esc_html_e( 'CDN URL', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="url" id="bepluspb_cdn_url"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cdn_url]"
							value="<?php echo esc_attr( $opts['cdn_url'] ); ?>"
							class="large-text code"
							placeholder="https://xxxxxxxx.quic.cloud">
						<p class="description">
							<?php esc_html_e( 'Your CDN pull-zone domain (e.g. from QUIC.cloud, BunnyCDN, KeyCDN, etc.), or any custom domain CNAME\'d to one.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- File Types -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cdn_file_types"><?php esc_html_e( 'File Types', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'Comma-separated file extensions.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="text" id="bepluspb_cdn_file_types"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cdn_file_types]"
							value="<?php echo esc_attr( $opts['cdn_file_types'] ); ?>"
							class="large-text code">
						<p class="description"><?php esc_html_e( 'Only URLs ending in one of these extensions are rewritten to the CDN.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Exclude from CDN -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cdn_exclude"><?php esc_html_e( 'Exclude from CDN', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_cdn_exclude"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cdn_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['cdn_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'URLs containing any of these strings are left on your own domain instead of being rewritten.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Example: /wp-admin/, custom-uploads-dir', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Serve WebP/AVIF -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cdn_webp_avif"><?php esc_html_e( 'Serve WebP/AVIF Images', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_cdn_webp_avif"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cdn_webp_avif]" value="1"
								<?php checked( $opts['cdn_webp_avif'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'For JPG/PNG images, serve a same-named .avif or .webp file instead when one already exists next to it on disk and the visitor\'s browser supports that format (checked via the Accept header). Does not create or convert any images — only rewrites the URL when a matching file is already present, e.g. one generated by WordPress core, your theme, or another plugin.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'This plugin never generates AVIF/WebP files itself. Enable this only if something else on your site (WordPress 6.5+ core, an image-optimization service, a build step, etc.) already creates .avif/.webp siblings for your uploads.', 'beplus-performance-booster' ); ?>
							<br>
							<strong><?php esc_html_e( 'Full-page cache caution:', 'beplus-performance-booster' ); ?></strong>
							<?php esc_html_e( 'The chosen image URL is baked into the page HTML at render time, based on the visitor who triggered that render. If a separate full-page caching plugin or proxy caches this HTML, later visitors with a different browser could receive a cached page referencing a format their browser lacks. Safe with this plugin\'s own browser-cache headers (HTML is never long-cached here) — but check compatibility if you also run a full-page cache plugin.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<div class="notice notice-info bepluspb-notice-warning inline">
					<p><?php esc_html_e( 'Applies to enqueued CSS/JS, media library images (including responsive srcset), matching URLs inside post content and widgets, and any other matching URL in the rendered page (including root-relative paths written directly into theme or page-builder markup). It is skipped for logged-in administrators, same as other front-end optimisations.', 'beplus-performance-booster' ); ?></p>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cleanup" tab — Remove Unused Assets + HTML Optimization merged.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_cleanup_all( $opts ) {

		// ---- Remove Unused Assets card --------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Remove Unused Assets', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Remove unnecessary WordPress features and unused assets that add overhead without benefit — fewer requests, faster pages.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Remove Emoji Scripts -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_remove_emoji"><?php esc_html_e( 'Remove Emoji Scripts', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_remove_emoji"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[remove_emoji]" value="1"
								<?php checked( $opts['remove_emoji'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Remove the WordPress emoji detection script, inline style, and DNS prefetch. Safe if you use real Unicode emoji in your content.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove oEmbed -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_remove_embed"><?php esc_html_e( 'Remove oEmbed / wp-embed', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_remove_embed"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[remove_embed]" value="1"
								<?php checked( $opts['remove_embed'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Remove the wp-embed script and oEmbed discovery links. Disable only if you embed WordPress posts inside other sites.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Block / Gutenberg CSS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_remove_block_css"><?php esc_html_e( 'Remove Block / Gutenberg CSS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_remove_block_css"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[remove_block_css]" value="1"
								<?php checked( $opts['remove_block_css'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Dequeue wp-block-library, wp-block-library-theme, and global-styles on the front-end. Disable if your theme or content relies on Gutenberg block styles.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Disable WooCommerce Assets -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_remove_woo_scripts"><?php esc_html_e( 'Disable WooCommerce Assets on Non-Shop Pages', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_remove_woo_scripts"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[remove_woo_scripts]" value="1"
								<?php checked( $opts['remove_woo_scripts'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Dequeue WooCommerce scripts and styles on pages unrelated to the shop, cart, checkout, or account. Requires WooCommerce to be active.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'Note: wc-cart-fragments is always preserved regardless of this setting. It maintains live cart counts across all pages via AJAX — removing it breaks the mini-cart widget in most themes.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- HTML Optimization card -----------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'HTML Optimization', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Compress your HTML output and strip developer comments to reduce the page size delivered to every visitor.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Minify HTML Output -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_html_minify"><?php esc_html_e( 'Minify HTML Output', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_html_minify"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[html_minify]" value="1"
								<?php checked( $opts['html_minify'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Collapse redundant whitespace between HTML tags in the full page output. Reduces page size without altering visible content.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Content inside &lt;pre&gt;, &lt;textarea&gt;, &lt;script&gt;, and &lt;style&gt; tags is always preserved exactly as-is.', 'beplus-performance-booster' ); ?></p>
						<div class="notice notice-warning bepluspb-notice-warning inline">
							<p><strong><?php esc_html_e( 'Compatibility note:', 'beplus-performance-booster' ); ?></strong> <?php esc_html_e( 'Collapsing whitespace between tags can affect inline elements — a space between adjacent &lt;a&gt;, &lt;span&gt;, or &lt;img&gt; tags may disappear, potentially shifting layout. Test thoroughly on your theme before enabling in production.', 'beplus-performance-booster' ); ?></p>
						</div>
					</div>
				</div>

				<!-- Remove HTML Comments -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_html_remove_comments"><?php esc_html_e( 'Remove HTML Comments', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_html_remove_comments"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[html_remove_comments]" value="1"
								<?php checked( $opts['html_remove_comments'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Strip HTML comments (e.g. theme generator tags, plugin banners, conditional IE comments) from page output.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Inline JS Comments -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_html_remove_js_comments"><?php esc_html_e( 'Remove Inline JS Comments', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_html_remove_js_comments"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[html_remove_js_comments]" value="1"
								<?php checked( $opts['html_remove_js_comments'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Remove // single-line and /* block */ comments from inline &lt;script&gt; blocks in the HTML output.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Remove Inline CSS Comments -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_html_remove_css_comments"><?php esc_html_e( 'Remove Inline CSS Comments', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_html_remove_css_comments"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[html_remove_css_comments]" value="1"
								<?php checked( $opts['html_remove_css_comments'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Remove block comments from inline &lt;style&gt; blocks in the HTML output.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
