<?php
/**
 * Native WordPress 6.8+ Speculation Rules integration.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Predictive Navigation powered exclusively by WordPress Core. */
class BEPLUSPB_Predictive_Navigation {

	/** Register Core filters when supported and explicitly enabled. */
	public static function init() {
		global $wp_version;

		$options = bepluspb_get_options();
		if ( empty( $options['predictive_navigation_enabled'] )
			|| version_compare( $wp_version, '6.8', '<' )
			|| ! get_option( 'permalink_structure' ) ) {
			return;
		}

		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'filter_configuration' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'exclude_paths' ) );
	}

	/**
	 * Apply the selected mode to Core's one native rule.
	 *
	 * @param array|null $configuration Core configuration.
	 * @return array|null
	 */
	public static function filter_configuration( $configuration ) {
		if ( ! is_array( $configuration ) ) {
			return $configuration;
		}
		$options = bepluspb_get_options();
		return self::configuration( $configuration, $options['predictive_navigation_mode'] ?? 'safe' );
	}

	/**
	 * Map a UI mode to Core configuration.
	 *
	 * @param array  $configuration Core configuration.
	 * @param string $mode          Selected mode.
	 * @return array
	 */
	public static function configuration( $configuration, $mode ) {
		$modes = array(
			'safe'     => array(
				'mode'      => 'prefetch',
				'eagerness' => 'conservative',
			),
			'balanced' => array(
				'mode'      => 'prefetch',
				'eagerness' => 'moderate',
			),
			'fast'     => array(
				'mode'      => 'prerender',
				'eagerness' => 'moderate',
			),
		);
		return array_merge( $configuration, $modes[ $mode ] ?? $modes['safe'] );
	}

	/**
	 * Add commerce, sensitive-action, authentication, and custom exclusions.
	 *
	 * @param array $paths Core exclusion paths.
	 * @return array
	 */
	public static function exclude_paths( $paths ) {
		$paths = array_merge(
			$paths,
			array(
				'/cart/*',
				'/checkout/*',
				'/my-account/*',
				'/*?s=*',
				'/*?preview=*',
				'/*?action=*',
				'/wp-login.php*',
				'/wp-admin/*',
				'/wp-json/*',
			)
		);

		if ( function_exists( 'wc_get_cart_url' ) && function_exists( 'wc_get_checkout_url' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$cart_url       = call_user_func( 'wc_get_cart_url' );
			$checkout_url   = call_user_func( 'wc_get_checkout_url' );
			$my_account_url = call_user_func( 'wc_get_page_permalink', 'myaccount' );
			foreach ( array( $cart_url, $checkout_url, $my_account_url ) as $url ) {
				$path = $url ? wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( $path ) {
					$paths[] = trailingslashit( $path ) . '*';
				}
			}
		}

		$options = bepluspb_get_options();
		$custom  = self::sanitize_excludes( $options['predictive_navigation_excludes'] ?? '' );
		return array_values( array_unique( array_merge( $paths, bepluspb_parse_exclude_list( $custom ) ) ) );
	}

	/**
	 * Sanitize newline-separated, same-origin path patterns.
	 *
	 * @param string $value Raw setting value.
	 * @return string
	 */
	public static function sanitize_excludes( $value ) {
		$clean = array();
		foreach ( preg_split( '/\R/', (string) $value ) as $path ) {
			$path = trim( $path );
			if ( '' === $path || '/' !== $path[0] || str_starts_with( $path, '//' ) || preg_match( '/[\x00-\x1F\x7F<>"\']/', $path ) ) {
				continue;
			}
			$clean[] = $path;
		}
		return implode( "\n", array_unique( $clean ) );
	}
}
