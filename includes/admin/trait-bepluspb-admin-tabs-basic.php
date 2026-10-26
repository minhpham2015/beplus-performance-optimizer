<?php
/**
 * Dashboard and CSS/JS cache-files tab renderers.
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

trait BEPLUSPB_Admin_Tabs_Basic {

	/**
	 * Render the Dashboard tab: cache overview + recommended settings.
	 *
	 * @param array $opts               Current option values.
	 * @param bool  $cache_dir_writable Whether the cache directory is writable.
	 */
	private static function render_section_dashboard( $opts, $cache_dir_writable = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kept for call-site symmetry with render_section_cache_files() and to match the tab-rendering pattern; not currently read here.
		$stats             = BEPLUSPB_Minify::get_cache_stats();
		$settings_url      = admin_url( 'options-general.php?page=beplus-performance-booster' );
		$htaccess_writable = BEPLUSPB_Htaccess::is_writable();

		// Recommended features for the quick-enable table.
		$recommended = array(
			'lazy_load'        => __( 'Lazy Load Images', 'beplus-performance-booster' ),
			'minify_css_files' => __( 'Minify CSS Files', 'beplus-performance-booster' ),
			'minify_js_files'  => __( 'Minify JS Files', 'beplus-performance-booster' ),
			'js_defer'         => __( 'Defer Non-Critical JS', 'beplus-performance-booster' ),
			'remove_emoji'     => __( 'Remove Emoji Scripts', 'beplus-performance-booster' ),
			'cache_headers'    => __( 'Browser Cache (.htaccess)', 'beplus-performance-booster' ),
		);
		?>

		<!-- ── Row 1: Stat cards ──────────────────────────────────────────── -->
		<div class="bepluspb-stat-grid">

			<div class="bepluspb-stat-card bepluspb-stat-card--blue">
				<span class="bepluspb-stat-icon dashicons dashicons-media-default"></span>
				<div class="bepluspb-stat-body">
					<span class="bepluspb-stat-value"><?php echo esc_html( $stats['count'] ); ?></span>
					<span class="bepluspb-stat-label"><?php esc_html_e( 'Cached Files', 'beplus-performance-booster' ); ?></span>
				</div>
			</div>

			<div class="bepluspb-stat-card bepluspb-stat-card--green">
				<span class="bepluspb-stat-icon dashicons dashicons-database"></span>
				<div class="bepluspb-stat-body">
					<span class="bepluspb-stat-value">
						<?php echo $stats['size'] > 0 ? esc_html( BEPLUSPB_Minify::human_filesize( $stats['size'] ) ) : '—'; ?>
					</span>
					<span class="bepluspb-stat-label"><?php esc_html_e( 'Total Size', 'beplus-performance-booster' ); ?></span>
				</div>
			</div>

			<div class="bepluspb-stat-card bepluspb-stat-card--grey">
				<span class="bepluspb-stat-icon dashicons dashicons-clock"></span>
				<div class="bepluspb-stat-body">
					<span class="bepluspb-stat-value bepluspb-stat-value--sm">
						<?php echo $stats['newest'] ? esc_html( date_i18n( get_option( 'date_format' ), $stats['newest'] ) ) : '—'; ?>
					</span>
					<span class="bepluspb-stat-label"><?php esc_html_e( 'Last Cached', 'beplus-performance-booster' ); ?></span>
				</div>
			</div>

			<div class="bepluspb-stat-card <?php echo esc_attr( $stats['writable'] ? 'bepluspb-stat-card--teal' : 'bepluspb-stat-card--orange' ); ?>">
				<span class="bepluspb-stat-icon dashicons <?php echo esc_attr( $stats['writable'] ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span>
				<div class="bepluspb-stat-body">
					<span class="bepluspb-stat-value bepluspb-stat-value--sm">
						<?php echo $stats['writable'] ? esc_html__( 'Writable', 'beplus-performance-booster' ) : esc_html__( 'Not writable', 'beplus-performance-booster' ); ?>
					</span>
					<span class="bepluspb-stat-label"><?php esc_html_e( 'Cache Directory', 'beplus-performance-booster' ); ?></span>
				</div>
			</div>

		</div>

		<?php if ( ! $stats['writable'] ) : ?>
		<div class="notice notice-warning bepluspb-notice-warning inline" style="margin-top:12px;">
			<p>
				<?php esc_html_e( 'Cache directory is not writable:', 'beplus-performance-booster' ); ?>
				<code><?php echo esc_html( $stats['dir'] ); ?></code><br>
				<?php esc_html_e( 'Please check directory permissions (755 recommended).', 'beplus-performance-booster' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- ── Row 2: Actions | Recommended ──────────────────────────────── -->
		<div class="bepluspb-two-col">

			<!-- Cache Actions card -->
			<div class="bepluspb-card bepluspb-actions-card" id="bepluspb-cache-actions">
				<div class="bepluspb-card-header">
					<h2><?php esc_html_e( 'Cache Actions', 'beplus-performance-booster' ); ?></h2>
				</div>
				<div class="bepluspb-card-body">

					<?php $cache_on = ! empty( $opts['cache_enabled'] ); ?>
					<section class="bepluspb-cache-action-section" aria-labelledby="bepluspb-cache-optimizations-title">
						<div class="bepluspb-cache-action-row">
							<div>
								<h3 id="bepluspb-cache-optimizations-title"><?php esc_html_e( 'Cache Optimizations', 'beplus-performance-booster' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Enable or disable CSS/JS minification and caching globally.', 'beplus-performance-booster' ); ?></p>
							</div>
							<label class="bepluspb-toggle" for="bepluspb-cache-enabled-toggle" aria-label="<?php esc_attr_e( 'Cache Optimizations', 'beplus-performance-booster' ); ?>">
								<input type="checkbox" id="bepluspb-cache-enabled-toggle" <?php checked( $cache_on ); ?>>
								<span class="bepluspb-toggle-slider"></span>
							</label>
						</div>
						<?php if ( ! $cache_on ) : ?>
						<div class="notice notice-warning inline bepluspb-cache-disabled-notice" id="bepluspb-cache-disabled-notice"><p>&#9888; <?php esc_html_e( 'All performance optimizations are currently disabled. Your site is running without any caching, minification, lazy loading, or cleanup features.', 'beplus-performance-booster' ); ?></p></div>
						<?php else : ?>
						<div class="notice notice-warning inline bepluspb-cache-disabled-notice" id="bepluspb-cache-disabled-notice" style="display:none;"></div>
						<?php endif; ?>
					</section>

					<section class="bepluspb-cache-action-section" aria-labelledby="bepluspb-generated-cache-title">
						<h3 id="bepluspb-generated-cache-title"><?php esc_html_e( 'Generated Cache / Disk Cache', 'beplus-performance-booster' ); ?></h3>
						<div class="bepluspb-cache-stats-row">
							<dl class="bepluspb-cache-metrics" aria-label="<?php esc_attr_e( 'Generated cache statistics', 'beplus-performance-booster' ); ?>">
								<div class="bepluspb-cache-metric"><dt><?php esc_html_e( 'Files', 'beplus-performance-booster' ); ?></dt><dd><?php echo esc_html( $stats['count'] ); ?></dd></div>
								<div class="bepluspb-cache-metric"><dt><?php esc_html_e( 'Size', 'beplus-performance-booster' ); ?></dt><dd><?php echo esc_html( $stats['size'] > 0 ? BEPLUSPB_Minify::human_filesize( $stats['size'] ) : '0 B' ); ?></dd></div>
							</dl>
							<a href="<?php echo esc_url( $settings_url ); ?>" class="bepluspb-refresh-link"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Refresh Stats', 'beplus-performance-booster' ); ?></a>
						</div>
						<?php if ( 0 === (int) $stats['count'] ) : ?>
							<p class="bepluspb-cache-empty" role="status"><?php esc_html_e( 'Cache is empty', 'beplus-performance-booster' ); ?></p>
						<?php endif; ?>
						<div class="bepluspb-cache-action-row">
							<p class="description"><?php esc_html_e( 'Includes plugin-managed CSS/JS/UCSS disk artifacts and Cloudflare when enabled. Does not purge persistent Object Cache, WordPress transients, or third-party/server page caches.', 'beplus-performance-booster' ); ?></p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bepluspb-purge-form bepluspb-cache-actions-row"><input type="hidden" name="action" value="bepluspb_purge_all_cache"><?php wp_nonce_field( 'bepluspb_purge_all_cache', 'bepluspb_purge_nonce' ); ?><button type="submit" id="bepluspb-clear-cache-btn" class="button button-secondary bepluspb-clear-btn"><?php esc_html_e( 'Purge ALL Cache', 'beplus-performance-booster' ); ?></button></form>
						</div>
					</section>

					<section class="bepluspb-cache-action-section" aria-labelledby="bepluspb-object-cache-title">
						<h3 id="bepluspb-object-cache-title"><?php esc_html_e( 'Object Cache', 'beplus-performance-booster' ); ?></h3>
						<?php echo self::render_object_cache_purge_control( 'dashboard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes all dynamic output. ?>
					</section>
				</div>
			</div>

			<!-- Recommended Settings v2 -->
			<?php
			$detected          = BEPLUSPB_Recommendations::infer_profile( BEPLUSPB_Recommendations::local_signals() );
			$requested_profile = isset( $_GET['bepluspb_profile'] ) ? sanitize_key( wp_unslash( $_GET['bepluspb_profile'] ) ) : $detected['profile']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display override.
			$profile           = BEPLUSPB_Recommendations::sanitize_profile( $requested_profile );
			$plans             = BEPLUSPB_Recommendations::plans( get_bloginfo( 'version' ) );
			$plan              = $plans[ $profile ];
			$diff              = BEPLUSPB_Recommendations::diff( (array) get_option( BEPLUSPB_OPTIONS_KEY, array() ), $plan );
			$profile_labels    = array(
				'blog_business'  => __( 'Blog / Business', 'beplus-performance-booster' ),
				'woocommerce'    => __( 'WooCommerce', 'beplus-performance-booster' ),
				'membership_lms' => __( 'Membership / LMS', 'beplus-performance-booster' ),
				'high_traffic'   => __( 'High-traffic / Advanced', 'beplus-performance-booster' ),
			);
			$snapshot          = get_option( BEPLUSPB_Recommendations::SNAPSHOT_OPTION, array() );
			?>
			<section class="bepluspb-card bepluspb-recommendations" aria-labelledby="bepluspb-rec-title">
				<div class="bepluspb-card-header"><h2 id="bepluspb-rec-title"><?php esc_html_e( 'Recommended Settings', 'beplus-performance-booster' ); ?></h2><p><?php esc_html_e( 'A local-only plan based on this site. No telemetry or external AI is used.', 'beplus-performance-booster' ); ?></p></div>
				<div class="bepluspb-card-body">
					<div class="bepluspb-recommendation-grid">
						<div class="bepluspb-recommendation-summary"><span class="bepluspb-status-badge active"><?php echo esc_html( $profile_labels[ $detected['profile'] ] ); ?></span><h3><?php esc_html_e( 'Detected signals', 'beplus-performance-booster' ); ?></h3><p><strong><?php esc_html_e( 'Confidence:', 'beplus-performance-booster' ); ?></strong> <?php echo esc_html( ucfirst( $detected['confidence'] ) ); ?></p><ul>
						<?php
						foreach ( $detected['reasons'] as $reason ) :
							?>
							<li><?php echo esc_html( $reason ); ?></li><?php endforeach; ?></ul></div>
						<form method="get" class="bepluspb-profile-form"><input type="hidden" name="page" value="beplus-performance-booster"><label for="bepluspb-recommendation-profile"><strong><?php esc_html_e( 'Plan override', 'beplus-performance-booster' ); ?></strong></label><select id="bepluspb-recommendation-profile" name="bepluspb_profile">
						<?php
						foreach ( $profile_labels as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $profile, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><button class="button" type="submit"><?php esc_html_e( 'Preview plan', 'beplus-performance-booster' ); ?></button></form>
					</div>
					<div class="bepluspb-recommendation-preview"><h3><?php esc_html_e( 'Exact changes before save', 'beplus-performance-booster' ); ?></h3>
					<?php
					if ( $diff ) :
						?>
						<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Setting', 'beplus-performance-booster' ); ?></th><th><?php esc_html_e( 'Current', 'beplus-performance-booster' ); ?></th><th><?php esc_html_e( 'Recommended', 'beplus-performance-booster' ); ?></th></tr></thead><tbody>
						<?php
						foreach ( $diff as $key => $change ) :
							?>
						<tr><th scope="row"><code><?php echo esc_html( $key ); ?></code></th><td><?php echo esc_html( null === $change['from'] ? 'Not saved' : self::format_recommendation_value( $change['from'] ) ); ?></td><td><?php echo esc_html( self::format_recommendation_value( $change['to'] ) ); ?></td></tr><?php endforeach; ?></tbody></table>
						<?php
else :
	?>
	<p><?php esc_html_e( 'This plan is already applied.', 'beplus-performance-booster' ); ?></p><?php endif; ?></div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bepluspb-recommendation-actions"><input type="hidden" name="action" value="bepluspb_recommendation_action"><input type="hidden" name="profile" value="<?php echo esc_attr( $profile ); ?>"><?php wp_nonce_field( 'bepluspb_recommendation_action' ); ?><button class="button button-primary" name="operation" value="apply" data-recommendation-confirm="<?php esc_attr_e( 'Apply exactly the previewed changes?', 'beplus-performance-booster' ); ?>"><?php esc_html_e( 'Apply Recommended', 'beplus-performance-booster' ); ?></button><button class="button" name="operation" value="disable" data-recommendation-confirm="<?php esc_attr_e( 'Turn off only features managed by this plan?', 'beplus-performance-booster' ); ?>"><?php esc_html_e( 'Disable Recommended', 'beplus-performance-booster' ); ?></button>
					<?php
					if ( is_array( $snapshot ) && empty( $snapshot['used'] ) ) :
						?>
						<button class="button" name="operation" value="restore" data-recommendation-confirm="<?php esc_attr_e( 'Restore the previous managed settings?', 'beplus-performance-booster' ); ?>"><?php esc_html_e( 'Restore Previous Settings', 'beplus-performance-booster' ); ?></button><?php endif; ?><p class="description"><?php esc_html_e( 'Disable Recommended does not deactivate the plugin. It only turns off boolean features managed by the selected plan; credentials, endpoints, exclusions and manual fields stay unchanged.', 'beplus-performance-booster' ); ?></p></form>
				</div>
			</section>

		</div>

		<!-- ── Row 3: Plugin Info strip ───────────────────────────────────── -->
		<div class="bepluspb-info-strip">
			<div class="bepluspb-info-strip-left">
				<strong><?php esc_html_e( 'Beplus Performance Booster', 'beplus-performance-booster' ); ?></strong>
				<span class="bepluspb-info-version">v<?php echo esc_html( BEPLUSPB_VERSION ); ?></span>
				<span class="bepluspb-info-sep">·</span>
				<span><?php esc_html_e( 'A complete performance toolkit for WordPress — cache, minify, lazy load, and clean up your site with ease.', 'beplus-performance-booster' ); ?></span>
			</div>
			<div class="bepluspb-info-strip-links">
				<a href="https://beplusthemes.com/plugins/beplus-performance-booster/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Documentation', 'beplus-performance-booster' ); ?>
				</a>
				<span class="bepluspb-info-sep">·</span>
				<a href="https://beplusthemes.com/contact/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Support', 'beplus-performance-booster' ); ?>
				</a>
				<span class="bepluspb-info-sep">·</span>
				<a href="https://wordpress.org/plugins/beplus-performance-booster/#reviews" target="_blank" rel="noopener noreferrer">
					★★★★★ <?php esc_html_e( 'Rate this plugin', 'beplus-performance-booster' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Page Cache card (top of the Cache Files tab).
	 *
	 * @param array $opts Current option values.
	 */
	private static function render_section_page_cache( $opts ) {
		$status = BEPLUSPB_Page_Cache::get_status();
		$name   = esc_attr( BEPLUSPB_OPTIONS_KEY );
		?>
		<div class="bepluspb-card" id="bepluspb-page-cache-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Page Cache', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Save each public page as a static HTML file and serve it to anonymous visitors without loading the theme or querying the database.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_enabled"><?php esc_html_e( 'Enable Page Cache', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_page_cache_enabled"
								name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_enabled]" value="1"
								<?php checked( $opts['page_cache_enabled'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Cache pages for visitors who are not logged in.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description">
							<?php esc_html_e( 'Never cached: logged-in users, carts and checkouts, password-protected posts, commenters, search, 404s, feeds, previews, and any URL with a query string (tracking parameters such as utm_* are ignored). Also requires the master Cache toggle on the Dashboard.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_ttl"><?php esc_html_e( 'Cache Lifespan (hours)', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="number" id="bepluspb_page_cache_ttl" min="1" max="72" step="1" class="small-text"
							name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_ttl]"
							value="<?php echo esc_attr( (string) BEPLUSPB_Page_Cache::sanitize_ttl_hours( $opts['page_cache_ttl'] ) ); ?>">
						<p class="description">
							<?php esc_html_e( 'Pages are also removed automatically when you edit content, change menus, widgets or the theme, or save these settings. Keep this under 12 hours so forms on cached pages do not outlive their WordPress security tokens.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_exclude_cookies"><?php esc_html_e( 'Bypass Cookies', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One cookie name (or prefix) per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_page_cache_exclude_cookies" rows="3" class="large-text code"
							name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_exclude_cookies]"><?php echo esc_textarea( $opts['page_cache_exclude_cookies'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Visitors carrying any of these cookies are never served a cached page. WordPress login, WooCommerce cart/session and comment-author cookies are always bypassed. Pages to exclude by URL are set under Advanced → Page Exclusions.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_mobile"><?php esc_html_e( 'Mobile Devices', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<?php $mobile_mode = BEPLUSPB_Page_Cache::sanitize_choice( $opts['page_cache_mobile'], array( 'same', 'separate', 'bypass' ), 'separate' ); ?>
						<select id="bepluspb_page_cache_mobile"
							name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_mobile]">
							<option value="separate" <?php selected( $mobile_mode, 'separate' ); ?>><?php esc_html_e( 'Separate cache for mobile (safest)', 'beplus-performance-booster' ); ?></option>
							<option value="same" <?php selected( $mobile_mode, 'same' ); ?>><?php esc_html_e( 'Same cache for all devices', 'beplus-performance-booster' ); ?></option>
							<option value="bypass" <?php selected( $mobile_mode, 'bypass' ); ?>><?php esc_html_e( 'Do not cache mobile devices', 'beplus-performance-booster' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Some themes and plugins send different HTML to phones (wp_is_mobile()). "Separate" stores a mobile copy using the same device test as WordPress. Choose "Same" only if your theme is purely responsive (CSS only).', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_vary_cookies"><?php esc_html_e( 'Language / Currency Cookies', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One cookie name per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_page_cache_vary_cookies" rows="3" class="large-text code"
							name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_vary_cookies]"><?php echo esc_textarea( $opts['page_cache_vary_cookies'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Each different value of these cookies gets its own cached copy, so visitors keep the right language or currency while the page stays cached.', 'beplus-performance-booster' ); ?>
							<?php
							$detected = BEPLUSPB_Page_Cache::detected_vary_cookies();
							if ( $detected ) :
								?>
								<br><strong><?php esc_html_e( 'Detected automatically:', 'beplus-performance-booster' ); ?></strong> <code><?php echo esc_html( implode( ', ', $detected ) ); ?></code>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_page_cache_purge_scope"><?php esc_html_e( 'When a Post Is Edited', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<?php $purge_scope = BEPLUSPB_Page_Cache::sanitize_choice( $opts['page_cache_purge_scope'], array( 'auto', 'related', 'all' ), 'auto' ); ?>
						<select id="bepluspb_page_cache_purge_scope"
							name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>[page_cache_purge_scope]">
							<option value="auto" <?php selected( $purge_scope, 'auto' ); ?>><?php esc_html_e( 'Automatic (recommended)', 'beplus-performance-booster' ); ?></option>
							<option value="related" <?php selected( $purge_scope, 'related' ); ?>><?php esc_html_e( 'Only the post and its related pages', 'beplus-performance-booster' ); ?></option>
							<option value="all" <?php selected( $purge_scope, 'all' ); ?>><?php esc_html_e( 'Every cached page', 'beplus-performance-booster' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Related pages: the post, home, posts page, its archives, author and date pages. Automatic clears everything when Recent Posts, Recent Comments, Archives, Calendar, Tag Cloud, Categories or a Query Loop in a template part is in use (these show other posts on every page), and only related pages otherwise.', 'beplus-performance-booster' ); ?>
							<?php if ( 'auto' === $purge_scope ) : ?>
								<br><strong><?php echo esc_html( BEPLUSPB_Page_Cache::purges_everything_on_post_change( $opts ) ? __( 'Currently detected: site-wide post lists — an edit clears every page.', 'beplus-performance-booster' ) : __( 'Currently detected: no site-wide post lists — an edit clears related pages only.', 'beplus-performance-booster' ) ); ?></strong>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<?php
				$mode_labels = array(
					'dropin' => __( 'Active — served by the advanced-cache.php drop-in (fastest).', 'beplus-performance-booster' ),
					'plugin' => __( 'Active — served from the plugin (works, but a little slower than the drop-in).', 'beplus-performance-booster' ),
					'off'    => __( 'Off.', 'beplus-performance-booster' ),
				);
				?>
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label"><label><?php esc_html_e( 'Status', 'beplus-performance-booster' ); ?></label></div>
					<div class="bepluspb-form-row-field">
						<p><strong><?php echo esc_html( $mode_labels[ $status['mode'] ] ); ?></strong></p>
						<?php if ( $status['enabled'] ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of cached pages, 2: human-readable size. */
									__( '%1$s cached pages (%2$s).', 'beplus-performance-booster' ),
									number_format_i18n( $status['stats']['files'] ) . ( $status['stats']['truncated'] ? '+' : '' ),
									BEPLUSPB_Minify::human_filesize( $status['stats']['bytes'] )
								)
							);
							?>
						</p>
							<?php if ( ! $status['wp_cache'] || 'foreign' === $status['dropin'] ) : ?>
						<p class="description">
								<?php if ( 'foreign' === $status['dropin'] ) : ?>
									<?php esc_html_e( 'Another plugin already owns wp-content/advanced-cache.php, so Beplus serves cached pages from the plugin instead. Remove the other page-cache plugin to use the faster drop-in.', 'beplus-performance-booster' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'For the fastest delivery, add this line to wp-config.php above the "That\'s all, stop editing!" comment. Beplus never edits wp-config.php for you:', 'beplus-performance-booster' ); ?>
									<br><code>define( 'WP_CACHE', true );</code>
								<?php endif; ?>
						</p>
						<?php endif; ?>
						<?php endif; ?>
						<?php foreach ( $status['errors'] as $error ) : ?>
						<p class="bepluspb-warn"><?php echo esc_html( $error ); ?></p>
						<?php endforeach; ?>
						<?php if ( $status['enabled'] && ! $status['config_ok'] ) : ?>
						<p class="bepluspb-warn"><?php esc_html_e( 'The page cache configuration is missing or unreadable. Save these settings again to rebuild it.', 'beplus-performance-booster' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the "Cache Files" tab — JavaScript, CSS, and Media merged.
	 *
	 * @param array $opts               Current option values.
	 * @param bool  $cache_dir_writable Whether the cache directory is writable.
	 */
	private static function render_section_cache_files( $opts, $cache_dir_writable = true ) {

		// ---- JavaScript card ------------------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'JavaScript', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Control how JavaScript files are loaded and processed to reduce render-blocking and improve page speed.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Minify JS Files -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_minify_js_files"><?php esc_html_e( 'Minify JS Files', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_minify_js_files"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[minify_js_files]" value="1"
								<?php checked( $opts['minify_js_files'], 1 ); ?>
								<?php disabled( ! $cache_dir_writable, true ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Minify all enqueued JS files (strip comments, trim whitespace) and serve cached versions. Already-minified *.min.js and external CDN scripts are skipped automatically.', 'beplus-performance-booster' ); ?></span>
						</label>
						<?php if ( ! $cache_dir_writable ) : ?>
						<p class="bepluspb-warn"><?php esc_html_e( 'Cache directory is not writable — minification disabled.', 'beplus-performance-booster' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Defer Non-Critical JS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_js_defer"><?php esc_html_e( 'Defer Non-Critical JS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_js_defer"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_defer]" value="1"
								<?php checked( $opts['js_defer'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Add the defer attribute to non-excluded script tags so they are fetched in parallel and executed after HTML parsing. jQuery and jquery-migrate are always protected.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Delay JS Execution -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_js_delay"><?php esc_html_e( 'Delay JS Execution', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_js_delay"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_delay]" value="1"
								<?php checked( $opts['js_delay'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Delay all non-excluded scripts until the first user interaction (mousemove, click, scroll, keydown, touch) or JS Release Delay (ms)', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Delay Mode -->
				<div class="bepluspb-form-row" id="bepluspb-delay-mode-row" style="<?php echo $opts['js_delay'] ? '' : 'display:none;'; ?>">
					<div class="bepluspb-form-row-label">
						<label><?php esc_html_e( 'Delay Mode', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'Only applies when Delay JS is enabled.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<?php $delay_mode = isset( $opts['js_delay_mode'] ) ? $opts['js_delay_mode'] : 'simple'; ?>

						<label class="bepluspb-check-label" style="margin-bottom:6px;">
							<input type="radio"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_delay_mode]"
								value="simple"
								<?php checked( $delay_mode, 'simple' ); ?>>
							<strong><?php esc_html_e( 'Simple', 'beplus-performance-booster' ); ?></strong>
						</label>
						<p class="description" style="margin:0 0 10px 20px;">
							<?php esc_html_e( 'Converts WordPress-enqueued external scripts to text/plain placeholders. Replays them on first user interaction via a lightweight loader. Low risk, no event-queue replay.', 'beplus-performance-booster' ); ?>
						</p>

						<label class="bepluspb-check-label" style="margin-bottom:6px;">
							<input type="radio"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_delay_mode]"
								value="advanced"
								<?php checked( $delay_mode, 'advanced' ); ?>>
							<strong><?php esc_html_e( 'Advanced', 'beplus-performance-booster' ); ?></strong>
						</label>
						<p class="description" style="margin:0 0 10px 20px;">
							<?php esc_html_e( 'Output-buffer approach: intercepts ALL scripts (including hardcoded theme scripts and dynamically injected ones) via MutationObserver. Replays DOMContentLoaded and window.load event queues in correct order. Spoofs document.readyState, intercepts document.createElement, and adds preconnect hints for external domains.', 'beplus-performance-booster' ); ?>
						</p>
						<div class="notice notice-warning bepluspb-notice-warning inline" style="margin:4px 0 0 20px;">
							<p><?php esc_html_e( 'Advanced mode rewrites every <script> tag on the page. Test thoroughly — especially carousels, forms, and checkout flows — before enabling on production.', 'beplus-performance-booster' ); ?></p>
						</div>
					</div>
				</div>

				<!-- JS Release Delay (rdelay) — Advanced mode only -->
				<div class="bepluspb-form-row" id="bepluspb-rdelay-row" style="<?php echo $opts['js_delay'] ? '' : 'display:none;'; ?>">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_js_delay_rdelay"><?php esc_html_e( 'JS Release Delay (ms)', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'Advanced mode only.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="number" id="bepluspb_js_delay_rdelay"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_delay_rdelay]"
							value="<?php echo esc_attr( isset( $opts['js_delay_rdelay'] ) ? $opts['js_delay_rdelay'] : 0 ); ?>"
							min="0" max="10000" step="100" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Fallback timer (ms) that releases delayed scripts after above-fold images and fonts finish loading, without requiring user interaction. 0 = disabled — only user interaction releases delayed scripts.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Exclude JS Files -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_js_exclude"><?php esc_html_e( 'Exclude JS Files', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_js_exclude"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[js_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['js_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Scripts whose src contains any of these strings are excluded from delay, defer, and minify.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Example: jquery, woocommerce, my-critical-script', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Remove JS Handles -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_remove_js_handles"><?php esc_html_e( 'Remove JS Handles', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One WordPress script handle per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_remove_js_handles"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[remove_js_handles]"
							rows="5" class="large-text code"
							placeholder="jquery-migrate"><?php echo esc_textarea( $opts['remove_js_handles'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Completely dequeue specific JavaScript files by handle. Use this to remove scripts that are loaded by WordPress or plugins but are not needed on your site.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Example: jquery-migrate, wp-embed, my-plugin-script', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- CSS card -------------------------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'CSS', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Minify and optimize your stylesheets to reduce file size, eliminate render-blocking requests, and improve load times.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Minify CSS Files -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_minify_css_files"><?php esc_html_e( 'Minify CSS Files', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_minify_css_files"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[minify_css_files]" value="1"
								<?php checked( $opts['minify_css_files'], 1 ); ?>
								<?php disabled( ! $cache_dir_writable, true ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Minify all enqueued CSS files (strip comments, collapse whitespace) and serve cached versions. Already-minified *.min.css and external CDN stylesheets are skipped.', 'beplus-performance-booster' ); ?></span>
						</label>
						<?php if ( ! $cache_dir_writable ) : ?>
						<p class="bepluspb-warn"><?php esc_html_e( 'Cache directory is not writable — minification disabled.', 'beplus-performance-booster' ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Minify Inline CSS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_minify"><?php esc_html_e( 'Minify Inline CSS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_css_minify"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_minify]" value="1"
								<?php checked( $opts['css_minify'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Strip whitespace and comments from inline &lt;style&gt; blocks in &lt;head&gt;. License comments (/*! … */) are preserved.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- Non-Render-Blocking CSS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_non_blocking"><?php esc_html_e( 'Non-Render-Blocking CSS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_css_non_blocking"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_non_blocking]" value="1"
								<?php checked( $opts['css_non_blocking'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Convert stylesheet links to preload + onload swap so they do not block rendering. A &lt;noscript&gt; fallback is included.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Tip: Exclude your theme\'s main stylesheet if you see a flash of unstyled content (FOUC).', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Inline All CSS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_inline_all"><?php esc_html_e( 'Inline All CSS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_css_inline_all"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_inline_all]" value="1"
								<?php checked( $opts['css_inline_all'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Read every local enqueued stylesheet and output its (minified) content as an inline &lt;style&gt; block. Eliminates render-blocking HTTP requests. External CDN stylesheets are kept as links.', 'beplus-performance-booster' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Tip: Use "Exclude CSS Files" to keep large stylesheets as external links.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Exclude CSS Files -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_exclude"><?php esc_html_e( 'Exclude CSS Files', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_css_exclude"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Stylesheets whose href contains any of these strings are excluded from Non-Blocking, Inline All, Minify CSS Files, and Remove Unused CSS.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Remove CSS Handles -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_remove_handles"><?php esc_html_e( 'Remove CSS Handles', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One WordPress style handle per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_css_remove_handles"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_remove_handles]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_remove_handles'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'These stylesheets will be completely dequeued on every front-end page.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Example: wp-block-library, dashicons, woocommerce-layout', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Remove Unused CSS -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_remove_unused"><?php esc_html_e( 'Remove Unused CSS', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_css_remove_unused"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_remove_unused]" value="1"
								<?php checked( $opts['css_remove_unused'], 1 ); ?>
								<?php disabled( ! $cache_dir_writable, true ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Scan the rendered page and strip CSS rules whose selectors do not appear anywhere on that page, cached per URL. This is a static text match, not a real browser render — classes added dynamically by JavaScript are not detected, so use the safelist below for those.', 'beplus-performance-booster' ); ?></span>
						</label>
						<?php if ( ! $cache_dir_writable ) : ?>
						<p class="bepluspb-warn"><?php esc_html_e( 'Cache directory is not writable — unused CSS removal disabled.', 'beplus-performance-booster' ); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'The first visit to each URL generates the trimmed stylesheet in the background; that page continues to load the original CSS while it does. Subsequent visits to the same URL serve the trimmed version.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Unused CSS Selector Safelist -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_unused_safelist"><?php esc_html_e( 'Unused CSS Selector Safelist', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One selector or keyword per line. Trailing * wildcard supported.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_css_unused_safelist"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_unused_safelist]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_unused_safelist'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Rules matching these selectors are always kept, even if not detected as used. Use this for classes toggled by JavaScript (menus, modals, tabs, accordions, sliders) and page-builder/WooCommerce dynamic states.', 'beplus-performance-booster' ); ?><br>
							<?php esc_html_e( 'Example: .active, .is-open*, .woocommerce-*', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Unused CSS URL Excludes -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_css_unused_exclude"><?php esc_html_e( 'Unused CSS URL Excludes', 'beplus-performance-booster' ); ?></label>
						<p class="bepluspb-row-desc"><?php esc_html_e( 'One URL keyword per line.', 'beplus-performance-booster' ); ?></p>
					</div>
					<div class="bepluspb-form-row-field">
						<textarea id="bepluspb_css_unused_exclude"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[css_unused_exclude]"
							rows="4" class="large-text code"><?php echo esc_textarea( $opts['css_unused_exclude'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Pages whose URL contains any of these strings will never have unused CSS removed. Recommended for checkout/cart pages and any page with heavy dynamic/JS-driven markup.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

			</div>
		</div>

		<?php
		// ---- Media card -----------------------------------------------------
		?>
		<div class="bepluspb-card">
			<div class="bepluspb-card-header">
				<h2><?php esc_html_e( 'Media', 'beplus-performance-booster' ); ?></h2>
				<p><?php esc_html_e( 'Defer off-screen images and control lazy loading behavior to speed up initial page rendering and protect your Core Web Vitals score.', 'beplus-performance-booster' ); ?></p>
			</div>
			<div class="bepluspb-card-body">

				<!-- Enable Lazy Loading -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_lazy_load"><?php esc_html_e( 'Enable Lazy Loading', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<label class="bepluspb-check-label">
							<input type="checkbox" id="bepluspb_lazy_load"
								name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[lazy_load]" value="1"
								<?php checked( $opts['lazy_load'], 1 ); ?>>
							<span class="bepluspb-check-text"><?php esc_html_e( 'Use WordPress Core to manage image loading. On WordPress 6.4 or newer, the settings below can adjust Core-generated loading attributes. WordPress 5.5 through 6.3 retains its native behavior. Existing attributes from themes and page builders are preserved; no HTML rewriting or JavaScript fallback is used.', 'beplus-performance-booster' ); ?></span>
						</label>
					</div>
				</div>

				<!-- WordPress Core threshold -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_lazy_core_threshold"><?php esc_html_e( 'Core Media Omission Threshold', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="number" id="bepluspb_lazy_core_threshold"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[lazy_core_threshold]"
							value="<?php echo esc_attr( $opts['lazy_core_threshold'] ); ?>"
							min="0" max="20" step="1" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Expert setting for WordPress 6.4 or newer. Default: 3, aligned with WordPress Core. Core decides which initial media omit loading="lazy"; this plugin does not identify or promise an LCP image. Attachment dimensions remain the responsibility of WordPress, the theme, or the page builder.', 'beplus-performance-booster' ); ?>
						</p>
					</div>
				</div>

				<!-- Exclude by CSS Class -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_lazy_exclude_class"><?php esc_html_e( 'Exclude by CSS Class', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="text" id="bepluspb_lazy_exclude_class"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[lazy_exclude_class]"
							value="<?php echo esc_attr( $opts['lazy_exclude_class'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. hero-image, no-lazy, skip-lazy', 'beplus-performance-booster' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated CSS class names. Images with any of these classes are loaded normally.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Exclude by Element ID -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_lazy_exclude_id"><?php esc_html_e( 'Exclude by Element ID', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="text" id="bepluspb_lazy_exclude_id"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[lazy_exclude_id]"
							value="<?php echo esc_attr( $opts['lazy_exclude_id'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. hero-banner, site-logo', 'beplus-performance-booster' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated element IDs. Images with these IDs are loaded normally.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

				<!-- Exclude by Filename -->
				<div class="bepluspb-form-row">
					<div class="bepluspb-form-row-label">
						<label for="bepluspb_lazy_exclude_filename"><?php esc_html_e( 'Exclude by Filename', 'beplus-performance-booster' ); ?></label>
					</div>
					<div class="bepluspb-form-row-field">
						<input type="text" id="bepluspb_lazy_exclude_filename"
							name="<?php echo esc_attr( BEPLUSPB_OPTIONS_KEY ); ?>[lazy_exclude_filename]"
							value="<?php echo esc_attr( $opts['lazy_exclude_filename'] ); ?>"
							class="large-text"
							placeholder="<?php esc_attr_e( 'e.g. logo, hero, banner', 'beplus-performance-booster' ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated partial filename strings. Images whose src URL contains any of these strings are loaded normally.', 'beplus-performance-booster' ); ?></p>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
