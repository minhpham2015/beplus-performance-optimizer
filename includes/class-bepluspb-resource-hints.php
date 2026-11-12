<?php
/**
 * Resource hints (preconnect / dns-prefetch) and LCP image priority.
 *
 * Output-only: this class never makes an HTTP request and never touches the database.
 * Hints go through WordPress core's own `wp_resource_hints` filter (so core de-duplicates
 * and escapes them), and the LCP priority goes through the same loading-attributes filter
 * WordPress core uses for `fetchpriority`/`loading`.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Preconnect / DNS-prefetch hosts and fetchpriority for the LCP image. */
class BEPLUSPB_Resource_Hints {

	/** Browsers open only a few early connections usefully; more just wastes the handshake. */
	const MAX_PRECONNECT = 6;

	/** DNS lookups are cheap, but a long list is almost always a mistake. */
	const MAX_DNS_PREFETCH = 15;

	/** Most pages have one LCP element; two covers a separate mobile/desktop hero. */
	const MAX_LCP_PER_PAGE = 2;

	/** Longest list line or keyword that is accepted. */
	const MAX_LINE_LENGTH = 253;

	/**
	 * Number of images already given high priority on this request.
	 *
	 * @var int
	 */
	private static $lcp_count = 0;

	/**
	 * Register the filters that are enabled.
	 *
	 * @param array $opts Plugin options.
	 */
	public static function init( $opts ) {
		if ( ! empty( $opts['preconnect_hosts'] ) || ! empty( $opts['dns_prefetch_hosts'] ) ) {
			add_filter( 'wp_resource_hints', array( __CLASS__, 'filter_resource_hints' ), 10, 2 );
		}

		// Feeds, AJAX and REST responses are not pages with an LCP element (same scope as BEPLUSPB_Images).
		$is_page = ! is_feed() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		if ( $is_page && ! empty( $opts['lcp_image_keywords'] ) ) {
			// WordPress 6.1+ passes every core-rendered <img> through this filter.
			add_filter( 'wp_get_loading_optimization_attributes', array( __CLASS__, 'filter_lcp_attributes' ), 20, 4 );
		}
	}

	// -------------------------------------------------------------------------
	// Parsing / sanitising (also used when saving settings)
	// -------------------------------------------------------------------------

	/**
	 * Parse a newline list of hosts into validated entries.
	 *
	 * Accepted per line: `host`, `host:port`, or an http(s) URL (path/query are dropped),
	 * optionally followed by the word `crossorigin`. Credentials, other schemes, IP
	 * literals, single-label names and anything with unusual characters are rejected.
	 *
	 * @param string $raw   Raw textarea value.
	 * @param int    $limit Maximum number of entries.
	 * @return array[] Each: array( 'origin' => 'https://host[:port]', 'host' => 'host', 'crossorigin' => bool ).
	 */
	public static function parse_hosts( $raw, $limit ) {
		$entries = array();
		$seen    = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || strlen( $line ) > self::MAX_LINE_LENGTH ) {
				continue;
			}

			$crossorigin = false;
			if ( preg_match( '/^(\S+)\s+crossorigin$/i', $line, $m ) ) {
				$crossorigin = true;
				$line        = $m[1];
			} elseif ( preg_match( '/\s/', $line ) ) {
				continue;
			}

			$origin = self::to_origin( $line );
			if ( '' === $origin || isset( $seen[ $origin ] ) ) {
				continue;
			}
			$seen[ $origin ] = true;

			$parts = wp_parse_url( $origin );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$entries[] = array(
				'origin'      => $origin,
				'host'        => $parts['host'],
				'crossorigin' => $crossorigin,
			);
			if ( count( $entries ) >= $limit ) {
				break;
			}
		}
		return $entries;
	}

	/**
	 * Reduce a hostname or URL to a validated `scheme://host[:port]` origin, or '' if unsafe.
	 *
	 * @param string $value Hostname or URL.
	 * @return string
	 */
	private static function to_origin( $value ) {
		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
			if ( preg_match( '#^[a-z0-9.-]+:[a-z]#i', $value ) ) {
				return ''; // "javascript:…", "data:…" and similar.
			}
			$value = 'https://' . $value;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		// At least two DNS labels, letters/digits/hyphens only (IDN must be given as punycode).
		if ( strlen( $host ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host ) ) {
			return '';
		}
		// A numeric last label means an IPv4 literal; hints for those are pointless.
		if ( preg_match( '/\.\d+$/D', $host ) ) {
			return '';
		}

		$origin = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( $port < 1 || $port > 65535 ) {
				return '';
			}
			$origin .= ':' . $port;
		}
		return $origin;
	}

	/**
	 * Normalise a host list for storage: one valid entry per line, capped.
	 *
	 * @param string $raw   Raw textarea value.
	 * @param int    $limit Maximum entries.
	 * @return string
	 */
	public static function sanitize_host_list( $raw, $limit ) {
		$lines = array();
		foreach ( self::parse_hosts( $raw, $limit ) as $entry ) {
			$lines[] = $entry['origin'] . ( $entry['crossorigin'] ? ' crossorigin' : '' );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Normalise the LCP keyword list: one keyword per line, plain printable text, capped.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string
	 */
	public static function sanitize_keyword_list( $raw ) {
		$keywords = array();
		foreach ( preg_split( '/\R/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || strlen( $line ) > self::MAX_LINE_LENGTH || preg_match( '/[\x00-\x1f\x7f<>"\']/', $line ) ) {
				continue;
			}
			$keywords[ $line ] = true;
			if ( count( $keywords ) >= 10 ) {
				break;
			}
		}
		return implode( "\n", array_keys( $keywords ) );
	}

	// -------------------------------------------------------------------------
	// Preconnect / DNS-prefetch
	// -------------------------------------------------------------------------

	/**
	 * Add the configured hosts to core's resource hints.
	 *
	 * Core turns each entry into a <link>, escapes it and drops duplicates.
	 *
	 * @param array  $urls          Hints already queued for this relation.
	 * @param string $relation_type Hint type.
	 * @return array
	 */
	public static function filter_resource_hints( $urls, $relation_type ) {
		$opts = bepluspb_get_options();

		if ( 'preconnect' === $relation_type ) {
			foreach ( self::parse_hosts( $opts['preconnect_hosts'], self::MAX_PRECONNECT ) as $entry ) {
				$urls[] = $entry['crossorigin']
					? array(
						'href'        => $entry['origin'],
						'crossorigin' => 'anonymous',
					)
					: $entry['origin'];
			}
		} elseif ( 'dns-prefetch' === $relation_type ) {
			foreach ( self::parse_hosts( $opts['dns_prefetch_hosts'], self::MAX_DNS_PREFETCH ) as $entry ) {
				$urls[] = $entry['origin'];
			}
		}
		return $urls;
	}

	// -------------------------------------------------------------------------
	// LCP image priority
	// -------------------------------------------------------------------------

	/**
	 * Give configured images `fetchpriority="high"` and make sure they are not lazy-loaded.
	 *
	 * Attributes written in the image's own markup are never overridden (core and the
	 * page builder decide those); this only changes what WordPress core would add.
	 *
	 * @param array  $loading_attrs Attributes core wants to add.
	 * @param string $tag_name      Element name.
	 * @param array  $attr          Attributes already on the element.
	 * @param string $context       Rendering context.
	 * @return array
	 */
	public static function filter_lcp_attributes( $loading_attrs, $tag_name, $attr, $context ) {
		unset( $context );
		if ( 'img' !== strtolower( (string) $tag_name ) || self::$lcp_count >= self::MAX_LCP_PER_PAGE || ! is_array( $attr ) ) {
			return $loading_attrs;
		}
		if ( array_key_exists( 'fetchpriority', $attr ) || array_key_exists( 'loading', $attr ) ) {
			return $loading_attrs;
		}

		$opts = bepluspb_get_options();
		if ( ! self::matches( $attr, preg_split( '/\R/', (string) $opts['lcp_image_keywords'] ) ) ) {
			return $loading_attrs;
		}

		++self::$lcp_count;
		$loading_attrs['fetchpriority'] = 'high';
		unset( $loading_attrs['loading'] );
		return $loading_attrs;
	}

	/**
	 * True when a keyword appears in the image URL, class list or id.
	 *
	 * @param array    $attr     Element attributes.
	 * @param string[] $keywords Keywords.
	 * @return bool
	 */
	private static function matches( $attr, $keywords ) {
		$haystacks = array();
		foreach ( array( 'src', 'class', 'id' ) as $name ) {
			if ( isset( $attr[ $name ] ) && is_scalar( $attr[ $name ] ) ) {
				$haystacks[] = (string) $attr[ $name ];
			}
		}
		foreach ( $keywords as $keyword ) {
			$keyword = trim( $keyword );
			if ( '' === $keyword ) {
				continue;
			}
			foreach ( $haystacks as $haystack ) {
				if ( false !== strpos( $haystack, $keyword ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
