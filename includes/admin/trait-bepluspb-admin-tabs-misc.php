<?php
/**
 * AI Optimizer, Predictive Navigation and Exclusions tab renderers.
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

trait BEPLUSPB_Admin_Tabs_Misc {

	/**
	 * Render the "AI Optimizer" tab — coming-soon placeholder.
	 *
	 * Displayed outside the settings <form> (no fields to save).
	 * Describes planned v2.0 AI-powered features that are not yet available.
	 */
	private static function render_section_ai_optimizer() {
		?>
		<div class="bepluspb-card bepluspb-ai-card">
			<div class="bepluspb-card-body bepluspb-ai-body">

				<div class="bepluspb-ai-icon" aria-hidden="true">🤖</div>

				<h2 class="bepluspb-ai-title"><?php esc_html_e( 'AI Optimizer', 'beplus-performance-booster' ); ?></h2>
				<p class="bepluspb-ai-subtitle"><?php esc_html_e( 'Intelligent, automatic performance optimization — powered by AI.', 'beplus-performance-booster' ); ?></p>

				<hr class="bepluspb-ai-divider">

				<ul class="bepluspb-ai-features" aria-label="<?php esc_attr_e( 'Upcoming features', 'beplus-performance-booster' ); ?>">
					<li>
						<span class="bepluspb-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'beplus-performance-booster' ); ?>">🔒</span>
						<span><?php esc_html_e( 'Smart image compression &amp; next-gen format conversion', 'beplus-performance-booster' ); ?></span>
					</li>
					<li>
						<span class="bepluspb-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'beplus-performance-booster' ); ?>">🔒</span>
						<span><?php esc_html_e( 'AI-powered critical CSS extraction', 'beplus-performance-booster' ); ?></span>
					</li>
					<li>
						<span class="bepluspb-ai-lock" aria-label="<?php esc_attr_e( 'Locked', 'beplus-performance-booster' ); ?>">🔒</span>
						<span><?php esc_html_e( 'Automated performance scoring &amp; fix suggestions', 'beplus-performance-booster' ); ?></span>
					</li>
				</ul>

				<hr class="bepluspb-ai-divider">

				<div class="notice notice-info inline bepluspb-ai-notice">
					<p><?php esc_html_e( 'This feature is currently in development and will be available in a future release of Beplus Performance Booster. Stay tuned for updates!', 'beplus-performance-booster' ); ?></p>
				</div>

				<div class="bepluspb-ai-version-badge">
					<?php esc_html_e( 'Coming in v2.0', 'beplus-performance-booster' ); ?>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cache Exclusions" tab — page exclusions, user exclusions, browser cache.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_predictive_navigation( $opts ) {
		$supported = version_compare( get_bloginfo( 'version' ), '6.8', '>=' );
		$enabled   = $supported && ! empty( $opts['predictive_navigation_enabled'] );
		$mode      = isset( $opts['predictive_navigation_mode'] ) ? $opts['predictive_navigation_mode'] : 'safe';
		$modes     = array(
			'safe'     => array(
				'label'     => __( 'Safe', 'beplus-performance-booster' ),
				'behavior'  => __( 'Prefetches only after a visitor shows clear intent to follow a link.', 'beplus-performance-booster' ),
				'speed'     => __( 'Measured', 'beplus-performance-booster' ),
				'resources' => __( 'Low', 'beplus-performance-booster' ),
			),
			'balanced' => array(
				'label'     => __( 'Balanced', 'beplus-performance-booster' ),
				'behavior'  => __( 'Prefetches likely destinations earlier for a more responsive feel.', 'beplus-performance-booster' ),
				'speed'     => __( 'Faster', 'beplus-performance-booster' ),
				'resources' => __( 'Moderate', 'beplus-performance-booster' ),
			),
			'fast'     => array(
				'label'     => __( 'Fast', 'beplus-performance-booster' ),
				'behavior'  => __( 'Prerenders likely destinations, including page execution before navigation.', 'beplus-performance-booster' ),
				'speed'     => __( 'Fastest', 'beplus-performance-booster' ),
				'resources' => __( 'High', 'beplus-performance-booster' ),
			),
		);
		?>
		<div class="bepluspb-predictive-hero <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>">
			<div class="bepluspb-predictive-hero-icon" aria-hidden="true"><span class="dashicons dashicons-controls-forward"></span></div>
			<div class="bepluspb-predictive-hero-copy">
				<div class="bepluspb-predictive-title-row">
					<h2><?php esc_html_e( 'Predictive Navigation', 'beplus-performance-booster' ); ?></h2>
					<span id="bepluspb-predictive-status" class="bepluspb-predictive-status" role="status">
						<?php echo $enabled ? esc_html__( 'Enabled', 'beplus-performance-booster' ) : esc_html__( 'Disabled', 'beplus-performance-booster' ); ?>
					</span>
				</div>
				<p><?php esc_html_e( 'Make likely next pages feel instant with WordPress Core speculation rules. Your site stays in control: no external service, telemetry, polyfill, or duplicate script.', 'beplus-performance-booster' ); ?></p>
			</div>
		</div>

		<div class="bepluspb-card bepluspb-predictive-enable-card">
			<div class="bepluspb-card-body">
				<div class="bepluspb-predictive-enable-row">
					<div>
						<label for="bepluspb-predictive-enabled" class="bepluspb-predictive-enable-label"><?php esc_html_e( 'Enable Predictive Navigation', 'beplus-performance-booster' ); ?></label>
						<p id="bepluspb-predictive-toggle-help"><?php esc_html_e( 'Improves perceived navigation speed for eligible public visitors. Disabled by default.', 'beplus-performance-booster' ); ?></p>
					</div>
					<label class="bepluspb-predictive-switch">
						<input type="checkbox" id="bepluspb-predictive-enabled" name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[predictive_navigation_enabled]" value="1" aria-describedby="bepluspb-predictive-toggle-help bepluspb-predictive-status" <?php checked( $enabled ); ?> <?php disabled( ! $supported ); ?>>
						<span class="bepluspb-predictive-switch-track" aria-hidden="true"></span>
					</label>
				</div>
			</div>
		</div>

		<?php if ( ! $supported ) : ?>
			<div class="notice notice-warning inline bepluspb-predictive-version-notice"><p><?php esc_html_e( 'Predictive Navigation requires WordPress 6.8 or newer. It remains inactive on this site.', 'beplus-performance-booster' ); ?></p></div>
		<?php endif; ?>

		<div class="bepluspb-predictive-controls" data-predictive-controls aria-disabled="<?php echo $enabled ? 'false' : 'true'; ?>">
			<div class="bepluspb-card">
				<div class="bepluspb-card-header">
					<h2><?php esc_html_e( 'Choose a navigation mode', 'beplus-performance-booster' ); ?></h2>
					<p><?php esc_html_e( 'Start with Safe. Move up only after checking analytics, server load, and important visitor flows.', 'beplus-performance-booster' ); ?></p>
				</div>
				<div class="bepluspb-card-body">
					<fieldset class="bepluspb-predictive-mode-fieldset">
						<legend><?php esc_html_e( 'Navigation mode', 'beplus-performance-booster' ); ?></legend>
						<div class="bepluspb-predictive-mode-grid">
							<?php foreach ( $modes as $value => $details ) : ?>
							<label class="bepluspb-predictive-mode-option">
								<input class="bepluspb-predictive-mode-input" type="radio" name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[predictive_navigation_mode]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?>>
								<span class="bepluspb-predictive-mode-card">
									<span class="bepluspb-predictive-mode-heading">
										<strong><?php echo esc_html( $details['label'] ); ?></strong>
										<?php if ( 'safe' === $value ) : ?>
											<span class="bepluspb-predictive-badge"><?php esc_html_e( 'Recommended', 'beplus-performance-booster' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="bepluspb-predictive-mode-behavior"><?php echo esc_html( $details['behavior'] ); ?></span>
									<span class="bepluspb-predictive-mode-meta"><span><b><?php esc_html_e( 'Speed', 'beplus-performance-booster' ); ?></b> <?php echo esc_html( $details['speed'] ); ?></span><span><b><?php esc_html_e( 'Resources', 'beplus-performance-booster' ); ?></b> <?php echo esc_html( $details['resources'] ); ?></span></span>
								</span>
							</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				</div>
			</div>

			<div class="bepluspb-card bepluspb-predictive-exclusions">
				<div class="bepluspb-card-header"><h2><?php esc_html_e( 'Additional exclusions', 'beplus-performance-booster' ); ?></h2><p><?php esc_html_e( 'Keep private, personalized, or action-oriented destinations out of speculative loading.', 'beplus-performance-booster' ); ?></p></div>
				<div class="bepluspb-card-body">
					<label for="bepluspb-predictive-excludes" class="bepluspb-predictive-field-label"><?php esc_html_e( 'Excluded path patterns', 'beplus-performance-booster' ); ?></label>
					<textarea id="bepluspb-predictive-excludes" name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[predictive_navigation_excludes]" rows="6" class="large-text code" aria-describedby="bepluspb-predictive-excludes-help" placeholder="/members/*"><?php echo esc_textarea( $opts['predictive_navigation_excludes'] ); ?></textarea>
					<p id="bepluspb-predictive-excludes-help" class="description"><?php esc_html_e( 'Enter one same-origin path pattern per line. Wildcards are supported.', 'beplus-performance-booster' ); ?> <?php esc_html_e( 'Examples:', 'beplus-performance-booster' ); ?> <code>/members/*</code> <code>/downloads/private/*</code></p>
					<p class="bepluspb-predictive-protected"><span class="dashicons dashicons-shield" aria-hidden="true"></span><?php esc_html_e( 'Always protected: cart, checkout, account, search, preview, action, login, admin, REST, and detected WooCommerce URLs.', 'beplus-performance-booster' ); ?></p>
				</div>
			</div>
		</div>

		<div class="bepluspb-predictive-callouts">
			<div class="bepluspb-predictive-callout"><span class="dashicons dashicons-wordpress" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Core compatibility', 'beplus-performance-booster' ); ?></strong><p><?php esc_html_e( 'Uses the native WordPress 6.8+ API. Core skips logged-in visitors and sites without pretty permalinks.', 'beplus-performance-booster' ); ?></p></div></div>
			<div class="bepluspb-predictive-callout"><span class="dashicons dashicons-lock" aria-hidden="true"></span><div><strong><?php esc_html_e( 'Privacy and safety', 'beplus-performance-booster' ); ?></strong><p><?php esc_html_e( 'No visitor data leaves your site. Fast mode can execute destination pages early, so test forms, checkout, and custom actions before using it.', 'beplus-performance-booster' ); ?></p></div></div>
		</div>
		<p class="bepluspb-predictive-save-note"><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php esc_html_e( 'Use Save Settings below to apply these changes.', 'beplus-performance-booster' ); ?></p>
		<?php
	}

	/**
	 * Render cache exclusions.
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_exclusions( $opts ) {
		$htaccess_writable = BEPLUSPB_Htaccess::is_writable();

		// ---- Page Exclusions card -------------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Page Exclusions', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Fine-tune which pages bypass caching and optimization. Use URL patterns here for global rules, or the per-post meta box for individual pages.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cache_exclude_pages"><?php esc_html_e( 'Exclude Pages from Cache', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One URL or path per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_cache_exclude_pages"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cache_exclude_pages]"
							rows="6" class="large-text code"><?php echo esc_textarea( $opts['cache_exclude_pages'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Pages whose URL contains any of these strings will be served original (un-cached) CSS/JS files.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Examples:', 'beplus-performance-booster' ); ?>
							<code>/checkout/</code> &nbsp; <code>/my-account/</code> &nbsp; <code>/cart/</code>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- User-Based Exclusions card -------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'User-Based Exclusions', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Control whether optimized CSS and JS files are served to logged-in users, or bypassed to ensure user-specific pages render correctly.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cache_for_logged_in"><?php esc_html_e( 'Enable Cache for Logged-In Users', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_cache_for_logged_in"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cache_for_logged_in]" value="1"
								<?php checked( $opts['cache_for_logged_in'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Serve cached/minified CSS and JS to logged-in users.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'By default, cached CSS/JS is skipped for logged-in users because pages may contain user-specific content. Enable this only if your pages look the same regardless of login state (e.g. a blog with no member-only content).', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- Browser Cache card ---------------------------------------------
		// IMP-3: Detect Nginx and show copy-paste config instead of the
		// .htaccess toggle, which has no effect on Nginx servers.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
		$is_nginx        = ( false !== stripos( $server_software, 'nginx' ) );
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Browser Cache', 'beplus-performance-booster' ); ?></h2>
				<p>
					<?php if ( $is_nginx ) : ?>
						<?php esc_html_e( 'Nginx detected. Copy the configuration block below into your server\'s nginx.conf or site virtual host to add long-lived browser cache headers.', 'beplus-performance-booster' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Add long-lived browser cache headers and gzip/brotli compression to your .htaccess file, so returning visitors load your site faster from their local cache.', 'beplus-performance-booster' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<div class="bepluspb-card-body">

				<?php if ( $is_nginx ) : ?>
				<div class="notice notice-info bepluspb-notice-warning inline">
					<p><strong><?php esc_html_e( 'Nginx server detected.', 'beplus-performance-booster' ); ?></strong> <?php esc_html_e( 'The .htaccess option below has no effect on Nginx. Ask your hosting provider or server administrator to add the following block to your nginx.conf or site configuration:', 'beplus-performance-booster' ); ?></p>
				</div>
				<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px 16px;overflow-x:auto;font-size:12px;margin:12px 0;border-radius:4px;">
					<?php
					echo esc_html(
						'# Browser cache — static assets (1 year)
location ~* \.(ico|jpg|jpeg|png|gif|webp|avif|svg|woff|woff2|ttf|otf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

location ~* \.(css|js)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}

# HTML — always revalidate
location ~* \.(html|htm)$ {
    add_header Cache-Control "no-cache, must-revalidate";
}

# Gzip compression
gzip on;
gzip_types text/plain text/css text/javascript application/javascript
           application/json image/svg+xml font/woff2;
gzip_min_length 1024;'
					);
					?>
				</pre>
				<?php else : ?>

					<?php if ( ! $htaccess_writable ) : ?>
				<div class="notice notice-warning bepluspb-notice-warning inline">
					<p><?php esc_html_e( 'Your .htaccess file is not writable. Browser caching rules cannot be injected automatically. Set the file to 644 permissions (or ask your host) and try again, or add the rules manually.', 'beplus-performance-booster' ); ?></p>
				</div>
				<?php endif; ?>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_cache_headers"><?php esc_html_e( 'Enable Browser Caching', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_cache_headers"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[cache_headers]" value="1"
								<?php checked( $opts['cache_headers'], 1 ); ?>
								<?php disabled( ! $htaccess_writable, true ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Inject browser caching rules and gzip/brotli compression directives into .htaccess. Rules are wrapped in a clearly labelled block and are never duplicated.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Requires Apache with mod_expires, mod_headers, mod_deflate. Disabling this option removes the injected rules automatically.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<?php endif; ?>

			</div>
		</div>
		<?php
	}
}
