<?php
/**
 * Safe Font Preload v2 parser and renderer.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable Squiz.Commenting -- Compact standalone value-object API is documented inline.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
/** Safe font preload validation and output. */
class BEPLUSPB_Font_Preload {
	const HARD_CAP   = 4;
	const MAX_LENGTH = 2048;
	/** Registered request URLs. @var array */
	private static $registry = array();
	/** Validate newline entries. @return array */
	public static function validate( $raw, $home = '' ) {
		$home        = $home ? $home : home_url();
		$result      = array(
			'valid'    => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		$seen        = array();
		$home_parts  = wp_parse_url( $home );
		$home_scheme = strtolower( $home_parts['scheme'] ?? 'https' );
		$home_host   = strtolower( $home_parts['host'] ?? '' );
		$home_port   = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : ( 'https' === $home_scheme ? 443 : 80 );
		foreach ( preg_split( '/\R/', wp_unslash( (string) $raw ) ) as $index => $line ) {
			$url = trim( $line );
			if ( '' === $url ) {
				continue;
			} $error = '';
			if ( strlen( $url ) > self::MAX_LENGTH ) {
				$error = 'URL exceeds the 2048-character limit.';
			} elseif ( 0 === strpos( $url, '//' ) ) {
				$error = 'Protocol-relative URLs are not accepted; use the exact HTTPS URL.';
			} elseif ( false !== strpos( $url, '#' ) ) {
				$error = 'Fragments are not valid font fetch identities.';
			} elseif ( preg_match( '#^(javascript|data|blob|file):#i', $url ) ) {
				$error = 'Unsupported URL scheme.';}
			$parts = $error ? false : wp_parse_url( $url );
			if ( ! $error && false === $parts ) {
				$error = 'Malformed URL.';}
			$absolute = is_array( $parts ) && isset( $parts['scheme'] );
			if ( ! $error && ! $absolute && ( empty( $parts['path'] ) || '/' !== substr( $parts['path'], 0, 1 ) ) ) {
				$error = 'Use an HTTPS absolute or root-relative URL.';}
			if ( ! $error && $absolute ) {
				$scheme = strtolower( $parts['scheme'] );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					$error = 'Unsupported URL scheme.';
				} elseif ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
					$error = 'Credentials are not allowed in font URLs.';
				} elseif ( 'http' === $scheme && 'http' !== $home_scheme ) {
					$error = 'HTTP fonts are blocked on an HTTPS site.';
				} elseif ( empty( $parts['host'] ) ) {
					$error = 'Malformed absolute URL.';}
			}
			$host = $absolute ? strtolower( $parts['host'] ?? '' ) : '';
			$path = $parts['path'] ?? '';
			if ( ! $error && ( 'fonts.googleapis.com' === $host || 'css' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) ) {
				$error = 'This is a stylesheet/CSS endpoint, not a font binary URL.';}
			$ext   = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$types = array(
				'woff2' => 'font/woff2',
				'woff'  => 'font/woff',
				'ttf'   => 'font/ttf',
				'otf'   => 'font/otf',
				'eot'   => 'application/vnd.ms-fontobject',
			);
			if ( ! $error && ! isset( $types[ $ext ] ) ) {
				$error = 'A supported font extension is required; no MIME fallback is used.';}
			$escaped = $error ? '' : esc_url( $url );
			if ( ! $error && '' === $escaped ) {
				$error = 'The URL is unsafe after WordPress URL validation.';}
			if ( $error ) {
				$result['errors'][] = array(
					'row'     => $index + 1,
					'url'     => $url,
					'message' => $error,
				);
				continue;}
			$key  = $url;
			$port = $absolute ? ( isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ) ) : $home_port;
			if ( ! $absolute ) {
				$key = $home_scheme . '://' . $home_host . ':' . $home_port . $url;
			} elseif ( $host === $home_host && $port === $home_port && strtolower( $parts['scheme'] ) === $home_scheme ) {
				$key = $home_scheme . '://' . $home_host . ':' . $home_port . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			} if ( count( $result['valid'] ) >= self::HARD_CAP ) {
				$result['errors'][] = array(
					'row'     => $index + 1,
					'url'     => $url,
					'message' => 'Only four valid font preloads are allowed.',
				);
				continue;
			} $seen[ $key ] = 1;
			$warnings       = array();
			if ( 'woff2' !== $ext ) {
				$warnings[] = 'Legacy font format; WOFF2 is recommended.';
			} if ( $absolute && $host !== $home_host ) {
				$warnings[] = 'Cross-origin font: configure Access-Control-Allow-Origin; credentials mode is unsupported.';
			} if ( count( $result['valid'] ) >= 2 ) {
				$warnings[] = 'More than two preloads may compete for bandwidth.';}
			$result['valid'][]  = array(
				'url'      => $url,
				'type'     => $types[ $ext ],
				'warnings' => $warnings,
			);
			$result['warnings'] = array_merge( $result['warnings'], $warnings );
		} return $result;
	}
	/** Register a URL for request deduplication. */
	public static function register( $url ) {
		self::$registry[ $url ] = true; }
	/** Render safe preload tags. @return string */
	public static function render( $raw, $home = '' ) {
		if ( ! self::is_frontend_html_request() ) {
			return '';
		} $filtered = apply_filters( 'bepluspb_font_preload_entries', self::validate( $raw, $home )['valid'] );
		$urls       = array();
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $candidate ) {
				if ( is_string( $candidate ) ) {
					$urls[] = $candidate;
				} elseif ( is_array( $candidate ) && isset( $candidate['url'] ) && is_string( $candidate['url'] ) ) {
					$urls[] = $candidate['url'];
				}
			}
		}
		$entries = self::validate( implode( "\n", $urls ), $home )['valid'];
		$out     = '';
		foreach ( $entries as $entry ) {
			if ( isset( self::$registry[ $entry['url'] ] ) ) {
				continue;
			} self::register( $entry['url'] );
			$href = esc_url( $entry['url'] );
			if ( '' === $href ) {
				continue;
			} $out .= '<link data-bepluspb-font-preload="1" rel="preload" as="font" type="' . esc_attr( $entry['type'] ) . '" href="' . $href . '" crossorigin="anonymous">' . "\n";
		} return $out; }
	/** Whether this is a frontend HTML request. @return bool */
	public static function is_frontend_html_request() {
		return ! is_admin() && ! is_feed() && ! wp_doing_ajax() && ! wp_is_json_request();}
	/** Return markup unchanged for conservative CDN handling. @return string */
	public static function protect_from_cdn( $html ) {
		return $html;}
}
