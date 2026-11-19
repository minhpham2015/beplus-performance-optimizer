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
	/**
	 * Option names as the settings screens show them (key => label), so the preview never prints raw keys.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'cache_enabled'                 => __( 'Cache Optimizations', 'beplus-performance-booster' ),
			'lazy_load'                     => __( 'Enable Lazy Loading', 'beplus-performance-booster' ),
			'minify_css_files'              => __( 'Minify CSS Files', 'beplus-performance-booster' ),
			'minify_js_files'               => __( 'Minify JS Files', 'beplus-performance-booster' ),
			'js_defer'                      => __( 'Defer Non-Critical JS', 'beplus-performance-booster' ),
			'css_minify'                    => __( 'Minify Inline CSS', 'beplus-performance-booster' ),
			'remove_emoji'                  => __( 'Remove Emoji Scripts', 'beplus-performance-booster' ),
			'remove_embed'                  => __( 'Remove oEmbed / wp-embed', 'beplus-performance-booster' ),
			'html_minify'                   => __( 'Minify HTML Output', 'beplus-performance-booster' ),
			'cache_headers'                 => __( 'Enable Browser Caching', 'beplus-performance-booster' ),
			'predictive_navigation_enabled' => __( 'Enable Predictive Navigation', 'beplus-performance-booster' ),
			'predictive_navigation_mode'    => __( 'Predictive Navigation mode', 'beplus-performance-booster' ),
			'remove_woo_scripts'            => __( 'Disable WooCommerce Assets on Non-Shop Pages', 'beplus-performance-booster' ),
			'js_delay'                      => __( 'Delay JS Execution', 'beplus-performance-booster' ),
			'css_remove_unused'             => __( 'Remove Unused CSS', 'beplus-performance-booster' ),
			'css_inline_all'                => __( 'Inline All CSS', 'beplus-performance-booster' ),
			'css_non_blocking'              => __( 'Non-Render-Blocking CSS', 'beplus-performance-booster' ),
		);
	}

	/**
	 * The label of an option key (the key itself when it has none).
	 *
	 * @param  string $key Option key.
	 * @return string
	 */
	public static function label( $key ) {
		$labels = self::labels();
		return isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;
	}

	/**
	 * A non-boolean value as the settings screen names it (the Predictive Navigation modes), or null.
	 *
	 * @param  string $key   Option key.
	 * @param  mixed  $value Value.
	 * @return string|null
	 */
	public static function value_label( $key, $value ) {
		if ( 'predictive_navigation_mode' !== $key ) {
			return null;
		}
		$modes = array(
			'safe'     => __( 'Safe', 'beplus-performance-booster' ),
			'balanced' => __( 'Balanced', 'beplus-performance-booster' ),
			'fast'     => __( 'Fast', 'beplus-performance-booster' ),
		);
		return isset( $modes[ $value ] ) ? $modes[ $value ] : null;
	}

	/** Return valid profile identifiers. */
	public static function profiles() {
		return array( 'blog_business', 'woocommerce', 'membership_lms', 'high_traffic' ); }
	/** Sanitize a profile identifier. */
	public static function sanitize_profile( $profile ) {
		$profile = sanitize_key( $profile );
		return in_array( $profile, self::profiles(), true ) ? $profile : 'blog_business'; }
	/** Return the valid levels: how far the plan goes. "balanced" is the plan of the profile itself. */
	public static function levels() {
		return array( 'safe', 'balanced', 'strong' ); }
	/** Sanitize a level identifier. */
	public static function sanitize_level( $level ) {
		$level = is_string( $level ) ? sanitize_key( $level ) : '';
		return in_array( $level, self::levels(), true ) ? $level : 'balanced'; }
	/**
	 * Level names and what they change, as the settings screen shows them (level => label + description).
	 *
	 * @return array
	 */
	public static function level_labels() {
		return array(
			'safe'     => array(
				'label' => __( 'Safe', 'beplus-performance-booster' ),
				'desc'  => __( 'Only the changes that almost never break a site: caching, lazy load, CSS minify, emoji/embed cleanup. No JS minify or defer, no HTML minify.', 'beplus-performance-booster' ),
			),
			'balanced' => array(
				'label' => __( 'Balanced', 'beplus-performance-booster' ),
				'desc'  => __( 'The recommended plan for this kind of site: adds JS minify and defer, HTML minify and Predictive Navigation.', 'beplus-performance-booster' ),
			),
			'strong'   => array(
				'label' => __( 'Strong', 'beplus-performance-booster' ),
				'desc'  => __( 'Everything in Balanced plus Non-Render-Blocking CSS and Delay JS (shops and membership sites keep Delay JS off). Check the site afterwards; Restore Previous Settings undoes it.', 'beplus-performance-booster' ),
			),
		); }
	/**
	 * What a level changes on top of a profile's plan. Applied before the builder overrides, so a page
	 * builder's safety rules still win ("strong" never switches on what Elementor must keep off).
	 *
	 * @param string $level   Level.
	 * @param string $profile Profile (shops and membership sites never get Delay JS from a level).
	 * @return array
	 */
	public static function level_overlay( $level, $profile ) {
		switch ( self::sanitize_level( $level ) ) {
			case 'safe':
				return array(
					'minify_js_files'            => 0,
					'js_defer'                   => 0,
					'html_minify'                => 0,
					'predictive_navigation_mode' => 'safe',
				);
			case 'strong':
				$plan = array( 'css_non_blocking' => 1 );
				if ( in_array( self::sanitize_profile( $profile ), array( 'blog_business', 'high_traffic' ), true ) ) {
					$plan['js_delay'] = 1;
				}
				return $plan;
		}
		return array(); }
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
	/** The exact plan for a profile and level on this site: profile values, level overlay, then builder overrides. */
	public static function plan_for( $profile, $wp_version, $builders, $level = 'balanced' ) {
		$plans   = self::plans( (string) $wp_version );
		$profile = self::sanitize_profile( $profile );
		$plan    = array_merge( $plans[ $profile ], self::level_overlay( $level, $profile ) );
		return self::apply_builders( $plan, $builders ); }
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
	public static function snapshot( $saved, $profile, $user_id, $time, $level = 'balanced' ) {
		$values = array();
		foreach ( self::allowlist() as $key ) {
			$values[ $key ] = array_key_exists( $key, $saved ) ? array(
				'exists' => true,
				'value'  => $saved[ $key ],
			) : array( 'exists' => false );
		} return array(
			'profile' => self::sanitize_profile( $profile ),
			'level'   => self::sanitize_level( $level ),
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
