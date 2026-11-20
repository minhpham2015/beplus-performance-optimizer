<?php
/**
 * Resource hints: `preconnect` and `dns-prefetch` links for third-party origins the administrator lists.
 *
 * Output only: the plugin never requests these hosts itself (the visitor's browser does, early).
 * Every line is validated like user input wherever it enters (settings save and render): http(s)
 * origin only (no credentials, path, query or fragment), a real DNS name, bounded list sizes.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Resource_Hints
 */
class BEPLUSPB_Resource_Hints {

	const MAX_PRECONNECT   = 4;  // Every open connection costs CPU/battery; browsers and Lighthouse advise a handful at most.
	const MAX_DNS_PREFETCH = 10;
	const MAX_LINE_LENGTH  = 300;

	/**
	 * Register the head output when at least one list has entries.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		$preconnect = self::entries( isset( $opts['resource_hints_preconnect'] ) ? $opts['resource_hints_preconnect'] : '', self::MAX_PRECONNECT );
		$prefetch   = self::entries( isset( $opts['resource_hints_dns_prefetch'] ) ? $opts['resource_hints_dns_prefetch'] : '', self::MAX_DNS_PREFETCH );
		if ( ! $preconnect && ! $prefetch ) {
			return;
		}
		add_action(
			'wp_head',
			static function () use ( $preconnect, $prefetch ) {
				echo self::render( $preconnect, $prefetch, home_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes every value.
			},
			1
		);
	}

	/**
	 * Valid entries of a textarea value.
	 *
	 * @param string $raw Raw value.
	 * @param int    $max Maximum number of entries.
	 * @return array List of array( 'origin' => string, 'crossorigin' => bool ).
	 */
	public static function entries( $raw, $max ) {
		$parsed = self::parse( $raw, $max );
		return $parsed['entries'];
	}

	/**
	 * Validate a textarea value, one origin per line. A line is `origin` or `origin crossorigin`
	 * (the second form is for fonts and other CORS requests: they use their own connection).
	 *
	 * @param string $raw Raw value.
	 * @param int    $max Maximum number of entries.
	 * @return array array( 'entries' => list, 'errors' => list of messages ).
	 */
	public static function parse( $raw, $max ) {
		$entries = array();
		$errors  = array();
		$seen    = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strlen( $line ) > self::MAX_LINE_LENGTH ) {
				$errors[] = __( 'A line is too long.', 'beplus-performance-booster' );
				continue;
			}
			$parts       = preg_split( '/\s+/', $line );
			$crossorigin = isset( $parts[1] ) && 'crossorigin' === strtolower( $parts[1] ) && 2 === count( $parts );
			if ( count( $parts ) > 1 && ! $crossorigin ) {
				/* translators: %s: the rejected line. */
				$errors[] = sprintf( __( '"%s": use one origin per line, optionally followed by the word crossorigin.', 'beplus-performance-booster' ), self::printable( $line ) );
				continue;
			}
			$origin = self::normalize_origin( $parts[0] );
			if ( '' === $origin ) {
				/* translators: %s: the rejected line. */
				$errors[] = sprintf( __( '"%s" is not a valid http(s) origin.', 'beplus-performance-booster' ), self::printable( $line ) );
				continue;
			}
			if ( isset( $seen[ $origin ] ) ) {
				continue;
			}
			if ( count( $entries ) >= $max ) {
				/* translators: %d: the limit. */
				$errors[] = sprintf( __( 'Only %d entries are kept; the rest were dropped.', 'beplus-performance-booster' ), $max );
				break;
			}
			$seen[ $origin ] = true;
			$entries[]       = array(
				'origin'      => $origin,
				'crossorigin' => $crossorigin,
			);
		}
		return array(
			'entries' => $entries,
			'errors'  => array_values( array_unique( $errors ) ),
		);
	}

	/**
	 * Clean a textarea value for storage: only valid lines, normalised, within the limit.
	 *
	 * @param string $raw Raw value.
	 * @param int    $max Maximum number of entries.
	 * @return string
	 */
	public static function sanitize( $raw, $max ) {
		$lines = array();
		foreach ( self::entries( $raw, $max ) as $entry ) {
			$lines[] = $entry['origin'] . ( $entry['crossorigin'] ? ' crossorigin' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Reduce a URL or host name to `scheme://host[:port]`.
	 *
	 * @param string $value URL, `//host` or bare host.
	 * @return string The origin, or an empty string when the value is not acceptable.
	 */
	public static function normalize_origin( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || preg_match( '/[\x00-\x20\x7F<>"\'`\\\\@]/', $value ) ) {
			return '';
		}
		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			$value = 'https://' . $value;
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = strtolower( $parts['host'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! self::is_dns_name( $host ) ) {
			return '';
		}
		$port = '';
		if ( isset( $parts['port'] ) ) {
			if ( $parts['port'] < 1 || $parts['port'] > 65535 ) {
				return '';
			}
			$default = 'https' === $scheme ? 443 : 80;
			$port    = (int) $parts['port'] === $default ? '' : ':' . (int) $parts['port'];
		}
		return $scheme . '://' . $host . $port;
	}

	/**
	 * Whether a host looks like a fully qualified DNS name (letters, digits, hyphens, at least two labels).
	 * IP addresses and single-label names (`localhost`, intranet hosts) are not useful third-party hints.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private static function is_dns_name( $host ) {
		return strlen( $host ) <= 253 && 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/D', $host );
	}

	/**
	 * Line text safe to show in a settings notice.
	 *
	 * @param string $line Raw line.
	 * @return string
	 */
	private static function printable( $line ) {
		return substr( preg_replace( '/[^\x20-\x7E]/', '', $line ), 0, 80 );
	}

	/**
	 * Build the `<link>` tags.
	 *
	 * @param array  $preconnect Entries from entries().
	 * @param array  $prefetch   Entries from entries().
	 * @param string $home       Site address: its own origin needs no hint.
	 * @return string
	 */
	public static function render( $preconnect, $prefetch, $home ) {
		$own = self::normalize_origin( $home );
		$out = '';
		foreach ( array(
			'preconnect'   => $preconnect,
			'dns-prefetch' => $prefetch,
		) as $rel => $list ) {
			foreach ( $list as $entry ) {
				if ( empty( $entry['origin'] ) || $entry['origin'] === $own ) {
					continue;
				}
				$origin = self::normalize_origin( $entry['origin'] ); // Re-validated at output time.
				if ( '' === $origin ) {
					continue;
				}
				$out .= '<link rel="' . $rel . '" href="' . esc_url( $origin ) . '"' . ( 'preconnect' === $rel && ! empty( $entry['crossorigin'] ) ? ' crossorigin' : '' ) . '>' . "\n";
			}
		}
		return $out;
	}
}
