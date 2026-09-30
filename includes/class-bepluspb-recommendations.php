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
		return array( 'cache_enabled', 'lazy_load', 'minify_css_files', 'minify_js_files', 'js_defer', 'css_minify', 'remove_emoji', 'remove_embed', 'html_minify', 'cache_headers', 'predictive_navigation_enabled', 'predictive_navigation_mode', 'remove_woo_scripts', 'js_delay', 'css_remove_unused', 'css_inline_all' );
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
				$plans = self::plans( (string) ( $signals['wp_version'] ?? '0' ) );
				return array(
					'profile'    => $profile,
					'confidence' => $confidence,
					'reasons'    => $reasons,
					'plan'       => $plans[ $profile ],
				);
	}
	/** Collect local site signals. */
	public static function local_signals() {
		$plugins     = (array) get_option( 'active_plugins', array() );
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
