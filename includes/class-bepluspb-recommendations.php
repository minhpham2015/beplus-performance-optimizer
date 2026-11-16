<?php
/**
 * Site-aware, local-only Recommended Settings plans.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Internal value-object helpers have self-explanatory signatures.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Recommendation inference and allowlisted mutations. */
class BEPLUSPB_Recommendations {
	const SNAPSHOT_OPTION = 'bepluspb_recommendation_snapshot';
	/** Return managed option keys. */
	public static function allowlist() {
		return array( 'cache_enabled', 'lazy_load', 'minify_css_files', 'minify_js_files', 'js_defer', 'css_minify', 'remove_emoji', 'remove_embed', 'html_minify', 'cache_headers', 'predictive_navigation_enabled', 'predictive_navigation_mode', 'remove_woo_scripts', 'js_delay', 'css_remove_unused', 'css_inline_all', 'css_non_blocking' );
	}
	/** Return valid profile identifiers. */
	public static function profiles() {
		return array( 'blog_business', 'woocommerce', 'membership_lms', 'high_traffic' ); }
	/** Sanitize a profile identifier. */
	public static function sanitize_profile( $profile ) {
		$profile = sanitize_key( $profile );
		return in_array( $profile, self::profiles(), true ) ? $profile : 'blog_business'; }
	/** Return plans for a WordPress version. */
	public static function plans( $wp_version = '6.8' ) {
		$predictive = version_compare( $wp_version, '6.8', '>=' ) ? 1 : 0;
		$base       = array(
			'cache_enabled'                 => 1,
			'lazy_load'                     => 1,
			'minify_css_files'              => 1,
			'minify_js_files'               => 1,
			'js_defer'                      => 1,
			'css_minify'                    => 1,
			'remove_emoji'                  => 1,
			'remove_embed'                  => 1,
			'html_minify'                   => 1,
			'cache_headers'                 => 1,
			'predictive_navigation_enabled' => $predictive,
			'predictive_navigation_mode'    => 'balanced',
			'remove_woo_scripts'            => 0,
			'js_delay'                      => 0,
			'css_remove_unused'             => 0,
			'css_inline_all'                => 0,
		);
		$commerce   = array_merge(
			$base,
			array(
				'minify_js_files'            => 0,
				'js_defer'                   => 0,
				'predictive_navigation_mode' => 'safe',
			)
		);
		$membership = array_merge(
			$base,
			array(
				'minify_js_files'            => 0,
				'js_defer'                   => 0,
				'predictive_navigation_mode' => 'safe',
			)
		);
		$advanced   = array_merge( $base, array( 'html_minify' => 0 ) );
		return array(
			'blog_business'  => $base,
			'woocommerce'    => $commerce,
			'membership_lms' => $membership,
			'high_traffic'   => $advanced,
		);
	}
	/** Return the page builders that get their own safety overrides. */
	public static function builders() {
		return array( 'elementor' ); }
	/** Detect page builders from the active plugin list. */
	public static function detect_builders( $signals ) {
		$plugins = isset( $signals['plugins'] ) ? (array) $signals['plugins'] : array();
		$found   = array();
		if ( in_array( 'elementor/elementor.php', $plugins, true ) ) {
			$found[] = 'elementor'; }
		return $found; }
	/**
	 * Per-builder overrides layered on top of ANY profile (an Elementor shop is still a shop).
	 *
	 * Only turns off features that rewrite how a builder's own CSS/JS loads; the builder
	 * already caches and optimises those, and a flash of unstyled layout is worse than a
	 * render-blocking stylesheet. Never switches anything on.
	 */
	public static function builder_overlays() {
		return array(
			'elementor' => array(
				'label'  => 'Elementor',
				'reason' => 'Elementor loads, caches and optimises its own CSS/JS: stylesheets stay render-blocking (no flash of unstyled layout), unused-CSS removal, inlining and Delay JS are not recommended.',
				'values' => array(
					'css_non_blocking'  => 0,
					'css_remove_unused' => 0,
					'css_inline_all'    => 0,
					'js_delay'          => 0,
				),
			),
		); }
	/** Layer the overrides of the detected builders onto a profile plan. */
	public static function apply_builders( $plan, $builders ) {
		$overlays = self::builder_overlays();
		foreach ( (array) $builders as $builder ) {
			if ( isset( $overlays[ $builder ] ) ) {
				$plan = array_merge( $plan, $overlays[ $builder ]['values'] );
			}
		} return $plan; }
	/** The exact plan for a profile on this site: profile values plus builder overrides. */
	public static function plan_for( $profile, $wp_version, $builders ) {
		$plans = self::plans( (string) $wp_version );
		return self::apply_builders( $plans[ self::sanitize_profile( $profile ) ], $builders ); }
	/** Infer a plan from local signals. */
	public static function infer_profile( $signals ) {
		$plugins    = isset( $signals['plugins'] ) ? $signals['plugins'] : array();
		$joined     = strtolower( implode( ' ', $plugins ) );
		$reasons    = array();
		$profile    = 'blog_business';
		$confidence = 'medium';
		if ( false !== strpos( $joined, 'woocommerce' ) ) {
			$profile    = 'woocommerce';
			$confidence = 'high';
			$reasons[]  = 'WooCommerce is active'; } elseif ( preg_match( '/memberpress|learndash|lifterlms|tutor|paid-memberships|restrict-content/', $joined ) ) {
			$profile    = 'membership_lms';
			$confidence = 'high';
			$reasons[]  = 'A membership or LMS plugin is active'; } elseif ( (int) ( $signals['post_count'] ?? 0 ) >= 1000 || (int) ( $signals['user_count'] ?? 0 ) >= 500 ) {
				$profile    = 'high_traffic';
				$confidence = 'medium';
				$reasons[]  = 'Local content or user counts indicate a large site'; } else {
				$reasons[] = 'No commerce or membership plugin was detected';
				$reasons[] = 'Local site scale fits a blog or business site'; }
				$builders = self::detect_builders( $signals );
				foreach ( $builders as $builder ) {
					$reasons[] = self::builder_overlays()[ $builder ]['label'] . ' is active: builder-safe overrides are included'; }
				return array(
					'profile'    => $profile,
					'confidence' => $confidence,
					'reasons'    => $reasons,
					'builders'   => $builders,
					'plan'       => self::plan_for( $profile, (string) ( $signals['wp_version'] ?? '0' ), $builders ),
				);
	}
	/** Collect local site signals. */
	public static function local_signals() {
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ); }
		$post_counts = wp_count_posts( 'post' );
		$users       = count_users();
		return array(
			'plugins'    => $plugins,
			'post_count' => isset( $post_counts->publish ) ? (int) $post_counts->publish : 0,
			'user_count' => (int) ( $users['total_users'] ?? 0 ),
			'wp_version' => get_bloginfo( 'version' ),
		);
	}
	/** Return exact allowlisted changes. */
	public static function diff( $saved, $plan ) {
		$out = array();
		foreach ( self::allowlist() as $key ) {
			if ( array_key_exists( $key, $plan ) ) {
				$old = $saved[ $key ] ?? null;
				if ( $old !== $plan[ $key ] ) {
					$out[ $key ] = array(
						'from' => $old,
						'to'   => $plan[ $key ],
					);
				}
			}
		} return $out; }
	/** Apply allowlisted plan values. */
	public static function apply_plan( $saved, $plan ) {
		foreach ( self::allowlist() as $key ) {
			if ( array_key_exists( $key, $plan ) ) {
				$saved[ $key ] = $plan[ $key ];
			}
		} return $saved; }
	/** Disable enabled plan booleans. */
	public static function disable_plan( $saved, $plan ) {
		foreach ( self::allowlist() as $key ) {
			if ( isset( $plan[ $key ] ) && ( 1 === $plan[ $key ] || '1' === $plan[ $key ] ) ) {
				$saved[ $key ] = 0;
			}
		} return $saved; }
	/** Create a secret-free audit snapshot. */
	public static function snapshot( $saved, $profile, $user_id, $time ) {
		$values = array();
		foreach ( self::allowlist() as $key ) {
			$values[ $key ] = array_key_exists( $key, $saved ) ? array(
				'exists' => true,
				'value'  => $saved[ $key ],
			) : array( 'exists' => false );
		} return array(
			'profile' => self::sanitize_profile( $profile ),
			'user_id' => (int) $user_id,
			'time'    => (int) $time,
			'used'    => false,
			'values'  => $values,
		); }
	/** Restore allowlisted snapshot values. */
	public static function restore( $saved, $snapshot, $require_unused = false ) {
		if ( $require_unused && ! empty( $snapshot['used'] ) ) {
			return array();
		} foreach ( ( $snapshot['values'] ?? array() ) as $key => $entry ) {
			if ( ! in_array( $key, self::allowlist(), true ) ) {
				continue;
			} if ( ! empty( $entry['exists'] ) ) {
				$saved[ $key ] = $entry['value'];
			} else {
				unset( $saved[ $key ] );
			}
		} return $saved; }
}
