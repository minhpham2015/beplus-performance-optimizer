<?php
/**
 * Custom CDN (pull-zone) URL rewriting.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_CDN
 *
 * Rewrites local static-asset URLs (enqueued CSS/JS, media library images,
 * images/links inside post content, and matching URLs anywhere in the
 * rendered page) to a CDN host — e.g. a pull-zone domain from QUIC.cloud,
 * BunnyCDN, KeyCDN, or any other provider, or a custom domain CNAME'd to one.
 *
 * Only same-host URLs whose path ends in an allowed file extension are
 * rewritten; external URLs, admin requests, and already-CDN'd URLs are left
 * untouched.
 */
class BEPLUSPB_CDN {

	/**
	 * Normalised CDN base, e.g. "https://xxxxxxxx.quic.cloud" (no trailing slash).
	 *
	 * @var string
	 */
	private static $cdn_base = '';

	/**
	 * Host portion of home_url(), used to confirm a URL is local before rewriting.
	 *
	 * @var string
	 */
	private static $site_host = '';

	/**
	 * Pipe-separated, regex-escaped list of file extensions eligible for rewriting.
	 *
	 * @var string
	 */
	private static $ext_pattern = '';

	/**
	 * Pipe-separated, regex-escaped list of first-path-segment directories
	 * (normally "wp-content" and "wp-includes", but derived from content_url()/
	 * includes_url() so renamed content directories still match) eligible for
	 * root-relative rewriting.
	 *
	 * @var string
	 */
	private static $content_dir_pattern = '';

	/**
	 * URL keywords that skip rewriting when present in the URL.
	 *
	 * @var array
	 */
	private static $excludes = array();

	/**
	 * The ob_get_level() value recorded immediately before we call ob_start().
	 *
	 * Stored so buffer_end() can verify it is consuming only the buffer it
	 * opened. We compare against self::$buffer_level + 1 (the exact level of
	 * our buffer) rather than just > self::$buffer_level to avoid accidentally
	 * cleaning a buffer opened by another plugin on top of ours.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Default comma-separated list of static file extensions to serve from the CDN.
	 *
	 * @return string
	 */
	public static function default_file_types() {
		return 'css,js,jpg,jpeg,png,gif,webp,avif,svg,ico,woff,woff2,ttf,otf,eot,mp4,webm,pdf';
	}

	/**
	 * Register the URL-rewriting hooks when the CDN feature is enabled and configured.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( empty( $opts['cdn_enabled'] ) || empty( $opts['cdn_url'] ) ) {
			return;
		}

		$cdn_url = trim( $opts['cdn_url'] );
		$scheme  = wp_parse_url( $cdn_url, PHP_URL_SCHEME );
		$host    = wp_parse_url( $cdn_url, PHP_URL_HOST );

		// Allow users to enter a bare hostname (no scheme) as well as a full URL.
		if ( empty( $host ) ) {
			$host = preg_replace( '#^[a-z0-9.+-]*://#i', '', $cdn_url );
			$host = trim( strtok( $host, '/' ) );
		}
		if ( empty( $host ) ) {
			return;
		}

		// Respect an explicit scheme in the configured CDN URL; otherwise default
		// to https. CDN edge domains generally require TLS, and is_ssl() cannot be
		// trusted to reflect the real origin scheme behind proxies/load balancers.
		self::$cdn_base  = ( 'http' === $scheme ? 'http://' : 'https://' ) . $host;
		self::$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		self::$excludes  = bepluspb_parse_exclude_list( $opts['cdn_exclude'] );

		$types = ! empty( $opts['cdn_file_types'] ) ? $opts['cdn_file_types'] : self::default_file_types();
		$exts  = array_filter( array_map( 'trim', explode( ',', $types ) ) );
		if ( empty( $exts ) ) {
			return;
		}
		self::$ext_pattern = implode( '|', array_map( array( __CLASS__, 'quote_ext' ), $exts ) );

		$content_dir  = trim( (string) wp_parse_url( content_url(), PHP_URL_PATH ), '/' );
		$includes_dir = trim( (string) wp_parse_url( includes_url(), PHP_URL_PATH ), '/' );
		$dirs         = array_unique( array_filter( array( $content_dir, $includes_dir ) ) );
		if ( empty( $dirs ) ) {
			$dirs = array( 'wp-content', 'wp-includes' );
		}
		self::$content_dir_pattern = implode( '|', array_map( array( __CLASS__, 'quote_ext' ), $dirs ) );

		// Run after Minify's style_loader_src/script_loader_src rewrite (priority 10)
		// so cached/minified file URLs are CDN-ified too.
		add_filter( 'style_loader_src', array( __CLASS__, 'rewrite_url' ), 999 );
		add_filter( 'script_loader_src', array( __CLASS__, 'rewrite_url' ), 999 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'rewrite_url' ), 999 );

		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'rewrite_srcset' ), 999 );

		add_filter( 'the_content', array( __CLASS__, 'rewrite_html' ), 999 );
		add_filter( 'the_excerpt', array( __CLASS__, 'rewrite_html' ), 999 );
		add_filter( 'widget_text_content', array( __CLASS__, 'rewrite_html' ), 999 );

		// Full-page buffer: catches asset URLs written directly into theme
		// templates, page builder output, and inline `style="url(...)"`
		// attributes that never pass through the filters above.
		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 0 );
		add_action( 'shutdown', array( __CLASS__, 'buffer_end' ), 0 );
	}

	/**
	 * preg_quote() callback for array_map().
	 *
	 * @param  string $ext File extension or path segment.
	 * @return string
	 */
	private static function quote_ext( $ext ) {
		return preg_quote( ltrim( $ext, '.' ), '#' );
	}

	/**
	 * Check a URL/path against the configured exclude-keyword list.
	 *
	 * @param  string $url_or_path URL or path to check.
	 * @return bool True if the URL should be left on the origin.
	 */
	private static function is_excluded( $url_or_path ) {
		foreach ( self::$excludes as $keyword ) {
			if ( '' !== $keyword && false !== strpos( $url_or_path, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrite a single URL to the CDN host if it is local and matches an allowed extension.
	 *
	 * @param  string $url Original URL.
	 * @return string Rewritten (or original) URL.
	 */
	public static function rewrite_url( $url ) {
		if ( empty( $url ) || 0 === strpos( $url, 'data:' ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		if ( ! empty( $parsed['host'] ) && $parsed['host'] !== self::$site_host ) {
			return $url; // Already external — leave it alone.
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		if ( ! preg_match( '#\.(?:' . self::$ext_pattern . ')$#i', $path ) ) {
			return $url;
		}

		if ( self::is_excluded( $url ) ) {
			return $url;
		}

		$rewritten = self::$cdn_base . $path;
		if ( ! empty( $parsed['query'] ) ) {
			$rewritten .= '?' . $parsed['query'];
		}
		return $rewritten;
	}

	/**
	 * Rewrite every URL inside a `wp_calculate_image_srcset` sources array.
	 *
	 * @param  array $sources Width-keyed srcset source definitions.
	 * @return array
	 */
	public static function rewrite_srcset( $sources ) {
		if ( empty( $sources ) || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$sources[ $width ]['url'] = self::rewrite_url( $source['url'] );
			}
		}
		return $sources;
	}

	/**
	 * Rewrite local asset URLs found inside a block of HTML (post content, excerpts,
	 * widgets, or a full rendered page).
	 *
	 * Matches both absolute/protocol-relative URLs on the site's own host and
	 * root-relative paths (e.g. `/wp-content/uploads/...`) whose path ends in an
	 * allowed extension — covers `src`/`href`/`srcset` attributes and CSS `url()`
	 * values without needing a full HTML parse.
	 *
	 * @param  string $html Content HTML.
	 * @return string
	 */
	public static function rewrite_html( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		if ( false !== strpos( $html, self::$site_host ) ) {
			$html = self::rewrite_absolute_urls( $html );
		}

		$html = self::rewrite_relative_urls( $html );

		return $html;
	}

	/**
	 * Rewrite absolute/protocol-relative URLs on the site's own host.
	 *
	 * @param  string $html Content HTML.
	 * @return string
	 */
	private static function rewrite_absolute_urls( $html ) {
		$pattern = '#(https?:)?//' . preg_quote( self::$site_host, '#' )
			. '(/[^\s"\'\)>]+\.(?:' . self::$ext_pattern . '))((?:\?[^\s"\'\)>]*)?)#i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$path_and_query = $matches[2] . $matches[3];
				if ( self::is_excluded( $path_and_query ) ) {
					return $matches[0];
				}
				return self::$cdn_base . $path_and_query;
			},
			$html
		);
	}

	/**
	 * Rewrite root-relative asset paths (no scheme/host), such as those written
	 * directly into theme templates or generated inline by page builders, e.g.
	 * `style="background-image:url(/wp-content/uploads/2024/hero.jpg)"`.
	 *
	 * The negative lookbehind excludes matches where the leading slash is
	 * actually part of a longer path (e.g. a foreign domain's URL that happens
	 * to share the `/wp-content/...` structure), since a real root-relative
	 * reference is always preceded by a quote, whitespace, or an opening
	 * paren/bracket rather than a host character.
	 *
	 * @param  string $html Content HTML.
	 * @return string
	 */
	private static function rewrite_relative_urls( $html ) {
		if ( empty( self::$content_dir_pattern ) ) {
			return $html;
		}

		$pattern = '#(?<![:/\w.-])(/(?:' . self::$content_dir_pattern . ')/[^\s"\'\)>]+\.(?:' . self::$ext_pattern . '))((?:\?[^\s"\'\)>]*)?)#i';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$path_and_query = $matches[1] . $matches[2];
				if ( self::is_excluded( $path_and_query ) ) {
					return $matches[0];
				}
				return self::$cdn_base . $path_and_query;
			},
			$html
		);
	}

	/**
	 * Open the full-page output buffer.
	 *
	 * Bails early for REST/JSON responses, the login page, and page-builder
	 * editor/preview contexts (rewriting asset URLs to a possibly-unwarmed CDN
	 * host inside a live editor iframe would show broken images/styles).
	 * Records ob_get_level() before calling ob_start() so buffer_end() can
	 * verify it is closing exactly the buffer it opened.
	 */
	public static function buffer_start() {
		self::$buffer_level = null;

		if ( BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}

		self::$buffer_level = ob_get_level();
		ob_start();
	}

	/**
	 * Close the buffer, rewrite matching URLs, and echo the final HTML.
	 */
	public static function buffer_end() {
		// Only close the exact buffer level we opened (level + 1).
		// Using !== instead of <= prevents us from accidentally closing a buffer
		// opened by another plugin on top of ours.
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			return;
		}

		$html = ob_get_clean();
		self::$buffer_level = null;

		if ( ! empty( $html ) ) {
			$html = self::rewrite_html( $html );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}
}
