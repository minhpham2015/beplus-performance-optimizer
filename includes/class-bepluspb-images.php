<?php
/**
 * Core-managed media loading policy.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Configure WordPress Core loading optimization without rewriting HTML. */
class BEPLUSPB_Images {
	/**
	 * Register narrowly scoped Core filters.
	 *
	 * @param array $opts Plugin options.
	 */
	public static function init( $opts ) {
		global $wp_version;
		if ( empty( $opts['lazy_load'] ) || ! self::is_frontend_html_request() ) {
			return;
		}

		if ( version_compare( (string) $wp_version, '6.3', '>=' ) ) {
			add_filter( 'wp_get_loading_optimization_attributes', array( __CLASS__, 'filter_attributes' ), 10, 4 );
			add_filter( 'wp_omit_loading_attr_threshold', array( __CLASS__, 'filter_threshold' ) );
			return;
		}

		// WordPress 5.5–6.2 already provides native image lazy loading. Do not
		// transform its HTML; retain this documented hook as a fail-open adapter.
		if ( version_compare( (string) $wp_version, '5.5', '>=' ) ) {
			add_filter( 'wp_lazy_loading_enabled', array( __CLASS__, 'legacy_enabled' ), 10, 3 );
		}
	}

	/** True only for ordinary front-end document requests. */
	private static function is_frontend_html_request() {
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Preserve Core's legacy decision; intentionally performs no HTML rewrite.
	 *
	 * @param bool   $enabled  Core's decision.
	 * @param string $tag_name Element name.
	 * @param string $context  Rendering context.
	 * @return bool
	 */
	public static function legacy_enabled( $enabled, $tag_name, $context ) {
		unset( $tag_name, $context );
		return $enabled;
	}

	/**
	 * Remove Core-generated lazy loading for configured media exclusions.
	 * Explicit author/theme/page-builder attributes always win unchanged.
	 *
	 * @param array  $loading_attrs Core-generated attributes.
	 * @param string $tag_name      Element name.
	 * @param array  $attr          Existing element attributes.
	 * @param string $context       Rendering context.
	 * @return array
	 */
	public static function filter_attributes( $loading_attrs, $tag_name, $attr, $context ) {
		unset( $context );
		if ( 'img' !== strtolower( (string) $tag_name ) ) {
			return $loading_attrs;
		}

		$explicit_loading = array_key_exists( 'loading', $attr );
		foreach ( array( 'loading', 'fetchpriority', 'decoding' ) as $explicit ) {
			if ( array_key_exists( $explicit, $attr ) ) {
				$loading_attrs[ $explicit ] = $attr[ $explicit ];
			}
		}

		// Core must never emit these mutually exclusive values together.
		if ( isset( $loading_attrs['loading'], $loading_attrs['fetchpriority'] )
			&& 'lazy' === strtolower( (string) $loading_attrs['loading'] )
			&& 'high' === strtolower( (string) $loading_attrs['fetchpriority'] ) ) {
			unset( $loading_attrs['loading'] );
		}

		if ( ! $explicit_loading && self::is_excluded( $attr ) ) {
			unset( $loading_attrs['loading'] );
		}
		return $loading_attrs;
	}

	/**
	 * Apply an explicit expert override; 3 means WordPress Core's policy.
	 *
	 * @param int $threshold Core threshold.
	 * @return int
	 */
	public static function filter_threshold( $threshold ) {
		$opts  = bepluspb_get_options();
		$value = isset( $opts['lazy_core_threshold'] ) ? absint( $opts['lazy_core_threshold'] ) : 3;
		return 3 === $value ? $threshold : min( 20, $value );
	}

	/**
	 * Test class, id and filename exclusions against parsed Core attributes.
	 *
	 * @param array $attr Existing element attributes.
	 * @return bool
	 */
	private static function is_excluded( $attr ) {
		$opts    = bepluspb_get_options();
		$classes = preg_split( '/\s+/', trim( isset( $attr['class'] ) ? (string) $attr['class'] : '' ) );
		foreach ( self::parse_list( $opts['lazy_exclude_class'] ) as $value ) {
			if ( in_array( $value, $classes, true ) ) {
				return true;
			}
		}
		$id = isset( $attr['id'] ) ? (string) $attr['id'] : '';
		if ( in_array( $id, self::parse_list( $opts['lazy_exclude_id'] ), true ) ) {
			return true;
		}
		$src = isset( $attr['src'] ) ? (string) $attr['src'] : '';
		foreach ( self::parse_list( $opts['lazy_exclude_filename'] ) as $value ) {
			if ( '' !== $value && false !== strpos( $src, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse a comma-separated exclusion list.
	 *
	 * @param string $value Raw list.
	 * @return string[]
	 */
	private static function parse_list( $value ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );
	}

	/**
	 * Backward-compatible no-op: v2 never parses or rebuilds HTML.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	public static function process_html( $content ) {
		return $content;
	}
}
