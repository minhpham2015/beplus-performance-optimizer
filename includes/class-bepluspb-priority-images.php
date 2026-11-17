<?php
/**
 * Priority Images: tell the browser early which image is the Largest Contentful Paint.
 *
 * For every configured image that applies to the current page this prints a
 * `<link rel="preload" as="image" fetchpriority="high">` at the very start of wp_head and,
 * for `<img>` tags that WordPress core renders, replaces `loading="lazy"` with
 * `fetchpriority="high"`. Nothing is fetched by the plugin itself: the option only decides
 * which URLs the visitor's browser is told about, so it is not an SSRF surface.
 *
 * Option format (one entry per line): `image URL | where | device`
 *   - image URL: absolute http(s) URL or root-relative path (/wp-content/uploads/hero.jpg).
 *   - where:     `home`, `all`, or a path of this site (`/about/`, `/shop/*` = prefix).
 *   - device:    optional `mobile`, `desktop` or `all` (default). Only sets the preload `media`.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Priority_Images
 */
class BEPLUSPB_Priority_Images {

	/** Preloading more than a handful of images competes with the LCP image itself. */
	const MAX_ENTRIES = 4;

	/** Longest accepted image URL. */
	const MAX_URL_LENGTH = 2048;

	/** Longest accepted page path. */
	const MAX_SCOPE_LENGTH = 200;

	/** Preload `media` values per device. */
	const MEDIA = array(
		'mobile'  => '(max-width: 767px)',
		'desktop' => '(min-width: 768px)',
	);

	/** Accepted extensions => MIME type for the preload `type` hint. */
	const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'svg'  => 'image/svg+xml',
	);

	/**
	 * Parsed entries for this request (null = not loaded yet).
	 *
	 * @var array|null
	 */
	private static $entries = null;

	/**
	 * Entries that apply to this page (null = not computed yet).
	 *
	 * @var array|null
	 */
	private static $active = null;

	/**
	 * Register the hooks when at least one valid entry is configured.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		global $wp_version;

		self::$entries = null;
		self::$active  = null;

		$parsed = self::parse( isset( $opts['priority_images'] ) ? $opts['priority_images'] : '' );
		if ( empty( $parsed['entries'] ) || BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}
		self::$entries = $parsed['entries'];

		add_action( 'wp_head', array( __CLASS__, 'output_preload' ), 1 );

		// Same floor as BEPLUSPB_Images: older cores have no loading-attributes filter.
		if ( version_compare( (string) $wp_version, '6.4', '>=' ) ) {
			add_filter( 'wp_get_loading_optimization_attributes', array( __CLASS__, 'filter_attributes' ), 20, 4 );
		}
	}

	// -------------------------------------------------------------------------
	// Parsing / sanitising (pure: no WordPress state)
	// -------------------------------------------------------------------------

	/**
	 * Parse the textarea into validated entries.
	 *
	 * @param  mixed $raw Textarea value.
	 * @return array { entries: array[], errors: string[] }. An entry has url, scope, device,
	 *               key (normalised path used to match <img> tags) and type.
	 */
	public static function parse( $raw ) {
		$result = array(
			'entries' => array(),
			'errors'  => array(),
		);
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $result;
		}

		foreach ( preg_split( '/\R/', $raw ) as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$number = $index + 1;
			if ( count( $result['entries'] ) >= self::MAX_ENTRIES ) {
				/* translators: 1: line number, 2: maximum number of entries. */
				$result['errors'][] = sprintf( __( 'Line %1$d ignored: at most %2$d priority images are allowed.', 'beplus-performance-booster' ), $number, self::MAX_ENTRIES );
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 || count( $parts ) > 3 ) {
				/* translators: %d: line number. */
				$result['errors'][] = sprintf( __( 'Line %d ignored: use "image URL | home, all or /page-path/ | mobile, desktop or all".', 'beplus-performance-booster' ), $number );
				continue;
			}

			$type = '';
			$key  = '';
			$url  = self::clean_url( $parts[0], $type, $key );
			if ( '' === $url ) {
				/* translators: %d: line number. */
				$result['errors'][] = sprintf( __( 'Line %d ignored: the image must be an http(s) URL or a /path ending in jpg, jpeg, png, gif, webp, avif or svg.', 'beplus-performance-booster' ), $number );
				continue;
			}

			$scope = self::clean_scope( $parts[1] );
			if ( '' === $scope ) {
				/* translators: %d: line number. */
				$result['errors'][] = sprintf( __( 'Line %d ignored: the second field must be home, all, or a page path starting with / (end it with * to match everything below).', 'beplus-performance-booster' ), $number );
				continue;
			}

			$device = isset( $parts[2] ) && '' !== $parts[2] ? strtolower( $parts[2] ) : 'all';
			if ( ! in_array( $device, array( 'all', 'mobile', 'desktop' ), true ) ) {
				/* translators: %d: line number. */
				$result['errors'][] = sprintf( __( 'Line %d ignored: the device must be mobile, desktop or all.', 'beplus-performance-booster' ), $number );
				continue;
			}

			$result['entries'][] = array(
				'url'    => $url,
				'scope'  => $scope,
				'device' => $device,
				'key'    => $key,
				'type'   => $type,
			);
		}

		return $result;
	}

	/**
	 * Normalised textarea value: only the valid lines, in canonical form.
	 *
	 * @param  mixed $raw Textarea value.
	 * @return string
	 */
	public static function sanitize( $raw ) {
		$lines = array();
		foreach ( self::parse( $raw )['entries'] as $entry ) {
			$lines[] = $entry['url'] . ' | ' . $entry['scope'] . ( 'all' === $entry['device'] ? '' : ' | ' . $entry['device'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Validate an image URL.
	 *
	 * @param  string $value Raw value.
	 * @param  string $type  Out: MIME type from the extension.
	 * @param  string $key   Out: normalised path for matching <img> tags.
	 * @return string The URL, or '' when invalid.
	 */
	private static function clean_url( $value, &$type, &$key ) {
		if ( '' === $value || strlen( $value ) > self::MAX_URL_LENGTH || preg_match( '/[\x00-\x20\x7f"\'<>\\\\`#]/', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $parts['path'] ) ) {
			return '';
		}
		if ( isset( $parts['scheme'] ) ) {
			if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
				return '';
			}
		} elseif ( 0 !== strpos( $value, '/' ) || 0 === strpos( $value, '//' ) ) {
			return ''; // Neither absolute nor root-relative (also rejects protocol-relative).
		}
		$ext = strtolower( pathinfo( $parts['path'], PATHINFO_EXTENSION ) );
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return '';
		}
		$type = self::TYPES[ $ext ];
		$key  = self::match_key( $parts['path'] );
		return $value;
	}

	/**
	 * Validate the "where" field.
	 *
	 * @param  string $value Raw value.
	 * @return string `home`, `all`, a path, or '' when invalid.
	 */
	private static function clean_scope( $value ) {
		$lower = strtolower( $value );
		if ( 'home' === $lower || 'all' === $lower ) {
			return $lower;
		}
		if ( 0 !== strpos( $value, '/' ) || strlen( $value ) > self::MAX_SCOPE_LENGTH || preg_match( '/[\x00-\x1f\x7f|"\'<>\\\\`?#]/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Path used to recognise one image across hosts, resized copies and CDN rewrites:
	 * lower-cased, without `-123x456` / `-scaled` before the extension.
	 *
	 * @param  string $path URL path.
	 * @return string
	 */
	public static function match_key( $path ) {
		$path = strtolower( rawurldecode( (string) $path ) );
		return (string) preg_replace( '/(?:-\d{1,5}x\d{1,5}|-scaled)(\.[a-z0-9]+)$/', '$1', $path );
	}

	// -------------------------------------------------------------------------
	// Request matching
	// -------------------------------------------------------------------------

	/**
	 * Whether a scope applies to the page being rendered.
	 *
	 * @param  string $scope  `home`, `all` or a path (optionally ending in `*`).
	 * @param  string $path   Decoded, lower-cased request path relative to the site root.
	 * @param  bool   $is_home Whether this is the front page.
	 * @return bool
	 */
	public static function scope_matches( $scope, $path, $is_home ) {
		if ( 'all' === $scope ) {
			return true;
		}
		if ( 'home' === $scope ) {
			return $is_home;
		}
		$wanted = strtolower( rawurldecode( $scope ) );
		if ( '*' === substr( $wanted, -1 ) ) {
			$prefix = substr( $wanted, 0, -1 );
			return '' === $prefix || 0 === strpos( $path, $prefix );
		}
		return untrailingslashit( $path ) === untrailingslashit( $wanted );
	}

	/**
	 * Request path relative to the site root (handles sub-directory installs).
	 *
	 * @return string Decoded, lower-cased, starting with "/".
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared, never printed.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		$path = strtolower( rawurldecode( $path ) );
		return 0 === strpos( $path, '/' ) ? $path : '/' . $path;
	}

	/**
	 * Entries that apply to this page.
	 *
	 * @return array[]
	 */
	private static function active_entries() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		self::$active = array();
		if ( empty( self::$entries ) ) {
			return self::$active;
		}
		$path    = self::request_path();
		$is_home = is_front_page();
		foreach ( self::$entries as $entry ) {
			if ( self::scope_matches( $entry['scope'], $path, $is_home ) ) {
				self::$active[] = $entry;
			}
		}
		return self::$active;
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	/**
	 * Print the preload links at the start of wp_head.
	 */
	public static function output_preload() {
		foreach ( self::active_entries() as $entry ) {
			$media = 'all' !== $entry['device'] ? ' media="' . esc_attr( self::MEDIA[ $entry['device'] ] ) . '"' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped above/below.
			echo '<link rel="preload" as="image" href="' . esc_url( $entry['url'] ) . '" type="' . esc_attr( $entry['type'] ) . '" fetchpriority="high"' . $media . ' data-bepluspb-priority-image="1">' . "\n";
		}
	}

	/**
	 * Give a matching core-rendered image high priority instead of lazy loading.
	 *
	 * The administrator listed the image on purpose, so this also replaces a lazy
	 * `loading` attribute written explicitly by a theme.
	 *
	 * @param  array  $loading_attrs Loading attributes core produced.
	 * @param  string $tag_name      Element name.
	 * @param  array  $attr          Attributes of the element.
	 * @param  string $context       Rendering context.
	 * @return array
	 */
	public static function filter_attributes( $loading_attrs, $tag_name, $attr, $context ) {
		unset( $context );
		if ( 'img' !== strtolower( (string) $tag_name ) || ! is_array( $loading_attrs ) || empty( $attr['src'] ) || ! is_string( $attr['src'] ) ) {
			return $loading_attrs;
		}
		$path = wp_parse_url( $attr['src'], PHP_URL_PATH );
		if ( ! $path ) {
			return $loading_attrs;
		}
		$key = self::match_key( $path );
		foreach ( self::active_entries() as $entry ) {
			if ( $entry['key'] === $key ) {
				$loading_attrs['fetchpriority'] = 'high';
				unset( $loading_attrs['loading'] );
				break;
			}
		}
		return $loading_attrs;
	}
}
