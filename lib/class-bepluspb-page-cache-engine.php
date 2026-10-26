<?php
/**
 * Beplus Performance Booster — page cache engine.
 *
 * Loaded EXTREMELY early: either from the tiny wp-content/advanced-cache.php
 * stub (before plugins, the theme, and most of WordPress exist) or from the
 * plugin file itself as a fallback. It therefore uses only plain PHP — no
 * WordPress functions — and every failure path must fall through to a normal
 * WordPress render ("fail open"). It never trusts request data: the Host is
 * checked against an allowlist, the URL path is reduced to a strict character
 * set before it becomes a filesystem path, and every delete is confined to the
 * cache root (CLAUDE.md hard rule #3 applies to this class too).
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable WordPress.Security, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors, WordPress.NamingConventions.PrefixAllGlobals

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BEPLUSPB_Page_Cache_Engine', false ) ) {

	/** Static-HTML page cache: request matching, serving, writing and purging. */
	class BEPLUSPB_Page_Cache_Engine {

		/** Exact executable guard before the JSON payload of the config file. */
		const CONFIG_GUARD = "<?php exit; ?>\n";

		/** Config file name inside wp-content/. */
		const CONFIG_FILE = '.bepluspb_pc.php';

		/** Cache root, relative to wp-content/. */
		const CACHE_SUBDIR = '/cache/bepluspb-pages';

		/** Never cache a body larger than this. */
		const MAX_BYTES = 5242880;

		/**
		 * Cookie-name prefixes that mean "this visitor sees personalised output".
		 * Matched against the START of the cookie name.
		 */
		const BYPASS_COOKIES = array(
			'wordpress_logged_in_',
			'wordpress_sec_',
			'wp-postpass_',
			'comment_author_',
			'wp_woocommerce_session_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			'woocommerce_recently_viewed',
			'edd_items_in_cart',
			'wordpress_no_cache',
			'bepluspb_nocache',
		);

		/** Path fragments that are never cached, whatever the settings say. */
		const BYPASS_PATHS = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/wp-cron.php',
			'/xmlrpc.php',
			'/wp-comments-post.php',
			'/wp-trackback.php',
			'/wp-sitemap',
			'/feed/',
			'/cart/',
			'/checkout/',
			'/my-account/',
			'/wc-api/',
		);

		/**
		 * Response headers PHP may have set that a cached copy must replay, because
		 * on a HIT neither the theme nor any plugin runs. Lower-case names only;
		 * Set-Cookie, Location, Cache-Control, Content-Type etc. are deliberately absent.
		 */
		const REPLAY_HEADERS = array(
			'x-frame-options',
			'content-security-policy',
			'content-security-policy-report-only',
			'x-content-type-options',
			'referrer-policy',
			'permissions-policy',
			'strict-transport-security',
			'cross-origin-opener-policy',
			'cross-origin-embedder-policy',
			'cross-origin-resource-policy',
			'x-robots-tag',
			'x-ua-compatible',
			'content-language',
			'x-pingback',
			'link',
		);

		/** User-Agent fragments WordPress wp_is_mobile() treats as mobile. */
		const MOBILE_AGENTS = array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' );

		/** Query parameters that do not change the rendered page. */
		const IGNORED_PARAMS = array( 'fbclid', 'gclid', 'msclkid', 'dclid', 'yclid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'gad_source' );

		/** Request-level memo of the loaded config. */
		private static $config = null;

		/**
		 * Path to the guarded config file.
		 *
		 * @return string
		 */
		public static function config_file() {
			return self::content_dir() . '/' . self::CONFIG_FILE;
		}

		/**
		 * Absolute cache root (no trailing slash).
		 *
		 * @return string
		 */
		public static function cache_root() {
			return self::content_dir() . self::CACHE_SUBDIR;
		}

		/**
		 * The wp-content directory without a trailing slash.
		 *
		 * @return string
		 */
		private static function content_dir() {
			return rtrim( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content', '/\\' );
		}

		/**
		 * Read and validate the guarded config. Fails closed.
		 *
		 * @return array|null Config, or null when missing/invalid/disabled.
		 */
		public static function load_config() {
			if ( false === self::$config ) {
				return null;
			}
			if ( is_array( self::$config ) ) {
				return self::$config;
			}
			self::$config = false;

			$raw = @file_get_contents( self::config_file() );
			if ( false === $raw || 0 !== strpos( $raw, self::CONFIG_GUARD ) ) {
				return null;
			}
			$cfg = json_decode( substr( $raw, strlen( self::CONFIG_GUARD ) ), true );
			if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || empty( $cfg['hosts'] ) || ! is_array( $cfg['hosts'] ) ) {
				return null;
			}

			self::$config = array(
				'ttl'             => max( 60, (int) ( $cfg['ttl'] ?? 36000 ) ),
				'hosts'           => array_values( array_filter( array_map( 'strtolower', array_map( 'strval', $cfg['hosts'] ) ) ) ),
				'exclude_paths'   => isset( $cfg['exclude_paths'] ) && is_array( $cfg['exclude_paths'] ) ? array_map( 'strval', $cfg['exclude_paths'] ) : array(),
				'exclude_cookies' => isset( $cfg['exclude_cookies'] ) && is_array( $cfg['exclude_cookies'] ) ? array_map( array( __CLASS__, 'php_cookie_name' ), $cfg['exclude_cookies'] ) : array(),
				'accept_variants' => ! empty( $cfg['accept_variants'] ),
				'mobile'          => isset( $cfg['mobile'] ) && in_array( $cfg['mobile'], array( 'same', 'separate', 'bypass' ), true ) ? $cfg['mobile'] : 'same',
				'vary_cookies'    => isset( $cfg['vary_cookies'] ) && is_array( $cfg['vary_cookies'] ) ? array_slice( array_values( array_filter( array_map( array( __CLASS__, 'php_cookie_name' ), $cfg['vary_cookies'] ), 'strlen' ) ), 0, 8 ) : array(),
			);
			return self::$config;
		}

		/**
		 * The name PHP will use in $_COOKIE for a configured cookie: it rewrites
		 * ".", " " and "[" to "_" in incoming names, so "my.cookie" arrives as "my_cookie".
		 *
		 * @param mixed $name Configured cookie name.
		 * @return string
		 */
		public static function php_cookie_name( $name ) {
			return strtr( (string) $name, ' .[', '___' );
		}

		/**
		 * Forget the memoised config (tests, config rewrites in the same request).
		 */
		public static function reset() {
			self::$config = null;
		}

		/**
		 * Whether the request is HTTPS, using the same signals as WordPress is_ssl().
		 *
		 * @param array $server $_SERVER.
		 * @return bool
		 */
		public static function is_https( array $server ) {
			if ( isset( $server['HTTPS'] ) && ( 'on' === strtolower( (string) $server['HTTPS'] ) || '1' === (string) $server['HTTPS'] ) ) {
				return true;
			}
			return isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'];
		}

		/**
		 * Reduce a request path to a safe, canonical set of directory segments.
		 *
		 * @param string $path Request path (no query string), starting with "/".
		 * @return array|null List of segments ('' for the home page), or null to bypass.
		 */
		public static function path_segments( $path ) {
			if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] || strlen( $path ) > 400 ) {
				return null;
			}
			if ( false !== strpos( $path, '//' ) || false !== strpos( $path, '\\' ) || preg_match( '/[\x00-\x20\x7f]/', $path ) ) {
				return null;
			}
			// Encoded dot, slash, backslash or NUL would be decoded by the web server / WP.
			if ( preg_match( '/%(2e|2f|5c|00)/i', $path ) || preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) ) {
				return null;
			}
			// WordPress resolves slugs case-insensitively; refuse mixed-case URLs so one
			// post cannot be stored under unbounded case permutations. Percent-encoded
			// hex digits (%C3) are legitimately upper-case and are ignored here.
			if ( preg_match( '/[A-Z]/', preg_replace( '/%[0-9A-Fa-f]{2}/', '', $path ) ) ) {
				return null;
			}

			$segments = array();
			foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
				if ( '' === $segment ) {
					continue;
				}
				if ( strlen( $segment ) > 200 || '.' === $segment[0] || ! preg_match( '/^[A-Za-z0-9_.~%+-]+$/', $segment ) ) {
					return null;
				}
				// WordPress emits lower-case hex, browsers send upper-case: one canonical form.
				$segments[] = preg_replace_callback(
					'/%[0-9A-Fa-f]{2}/',
					static function ( $m ) {
						return strtolower( $m[0] );
					},
					$segment
				);
			}
			return count( $segments ) > 12 ? null : $segments;
		}

		/**
		 * Whether the query string only carries parameters that never change the page.
		 *
		 * @param string $query Raw query string.
		 * @return bool
		 */
		public static function query_is_ignorable( $query ) {
			if ( '' === $query ) {
				return true;
			}
			foreach ( explode( '&', $query ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$name = strtolower( urldecode( explode( '=', $pair, 2 )[0] ) );
				if ( 0 === strpos( $name, 'utm_' ) || in_array( $name, self::IGNORED_PARAMS, true ) ) {
					continue;
				}
				return false;
			}
			return true;
		}

		/**
		 * Whether the request comes from a device wp_is_mobile() would call mobile.
		 *
		 * @param array $server $_SERVER.
		 * @return bool
		 */
		public static function is_mobile_request( array $server ) {
			if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
				return '?1' === $server['HTTP_SEC_CH_UA_MOBILE'];
			}
			$agent = (string) ( $server['HTTP_USER_AGENT'] ?? '' );
			if ( '' === $agent ) {
				return false;
			}
			foreach ( self::MOBILE_AGENTS as $needle ) {
				if ( false !== strpos( $agent, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * File-name variant for the request: scheme, device, language/currency
		 * cookies and Accept-driven image formats each get their own copy.
		 *
		 * @param array $cfg     Loaded config.
		 * @param array $server  $_SERVER.
		 * @param array $cookies $_COOKIE.
		 * @return string|null e.g. "index-s-m-1a2b3c4d-avif.html", or null when a
		 *                     variant cookie has an unusable value (do not cache).
		 */
		public static function variant_file( array $cfg, array $server, array $cookies = array() ) {
			$parts = array();
			if ( self::is_https( $server ) ) {
				$parts[] = 's';
			}
			if ( 'separate' === $cfg['mobile'] && self::is_mobile_request( $server ) ) {
				$parts[] = 'm';
			}
			$pairs = array();
			foreach ( $cfg['vary_cookies'] as $name ) {
				if ( ! isset( $cookies[ $name ] ) || '' === $cookies[ $name ] ) {
					continue;
				}
				// A cookie value becomes part of a file name: refuse anything that is not a plain token.
				if ( ! is_string( $cookies[ $name ] ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,32}$/', $cookies[ $name ] ) ) {
					return null;
				}
				$pairs[] = $name . '=' . strtolower( $cookies[ $name ] );
			}
			if ( $pairs ) {
				sort( $pairs );
				$parts[] = substr( md5( implode( '&', $pairs ) ), 0, 8 );
			}
			if ( ! empty( $cfg['accept_variants'] ) ) {
				$accept = isset( $server['HTTP_ACCEPT'] ) ? strtolower( (string) $server['HTTP_ACCEPT'] ) : '';
				if ( false !== strpos( $accept, 'image/avif' ) ) {
					$parts[] = 'avif';
				} elseif ( false !== strpos( $accept, 'image/webp' ) ) {
					$parts[] = 'webp';
				}
			}
			return 'index' . ( $parts ? '-' . implode( '-', $parts ) : '' ) . '.html';
		}

		/**
		 * Whether any cookie marks this visitor as personalised.
		 *
		 * @param array $cfg     Loaded config.
		 * @param array $cookies $_COOKIE.
		 * @return bool
		 */
		public static function has_bypass_cookie( array $cfg, array $cookies ) {
			$prefixes = array_merge( self::BYPASS_COOKIES, $cfg['exclude_cookies'] );
			foreach ( array_keys( $cookies ) as $name ) {
				$name = (string) $name;
				foreach ( $prefixes as $prefix ) {
					if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Whether the path matches a built-in or configured exclusion.
		 *
		 * @param array  $cfg   Loaded config.
		 * @param string $path  Request path.
		 * @param string $query Raw query string.
		 * @return bool
		 */
		public static function is_excluded_path( array $cfg, $path, $query ) {
			$lower = strtolower( $path );
			if ( preg_match( '/\.(php|xml|txt|json|xsl)$/', $lower ) ) {
				return true;
			}
			foreach ( self::BYPASS_PATHS as $fragment ) {
				if ( false !== strpos( $lower . '/', $fragment ) ) {
					return true;
				}
			}
			$full = $path . ( '' !== $query ? '?' . $query : '' );
			foreach ( $cfg['exclude_paths'] as $pattern ) {
				// Patterns may be pasted as full URLs; only the path part matters here.
				$pattern = preg_replace( '#^https?://[^/]+#i', '', trim( $pattern ) );
				if ( '' !== $pattern && false !== strpos( $full, $pattern ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Decide whether this request may use the page cache and where its file lives.
		 *
		 * @param array|null $server  $_SERVER (defaults to the real one).
		 * @param array|null $cookies $_COOKIE (defaults to the real one).
		 * @return array|null {dir, file, path} or null when the request must bypass.
		 */
		public static function request_context( ?array $server = null, ?array $cookies = null ) {
			$server  = $server ?? $_SERVER;
			$cookies = $cookies ?? $_COOKIE;
			$cfg     = self::load_config();
			if ( ! $cfg ) {
				return null;
			}

			if ( 'GET' !== ( $server['REQUEST_METHOD'] ?? '' ) ) {
				return null;
			}
			if ( isset( $server['HTTP_AUTHORIZATION'] ) || isset( $server['PHP_AUTH_USER'] ) || isset( $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				return null;
			}
			if ( 'xmlhttprequest' === strtolower( (string) ( $server['HTTP_X_REQUESTED_WITH'] ?? '' ) ) ) {
				return null;
			}
			if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
				return null;
			}

			$host = strtolower( (string) ( $server['HTTP_HOST'] ?? '' ) );
			if ( ! preg_match( '/^[a-z0-9.-]+(:[0-9]{1,5})?$/', $host ) || ! in_array( $host, $cfg['hosts'], true ) ) {
				return null;
			}

			$uri = (string) ( $server['REQUEST_URI'] ?? '' );
			if ( '' === $uri || '/' !== $uri[0] ) {
				return null;
			}
			$parts = explode( '?', $uri, 2 );
			$path  = $parts[0];
			$query = $parts[1] ?? '';
			if ( false !== strpos( $query, '#' ) || ! self::query_is_ignorable( $query ) ) {
				return null;
			}

			$segments = self::path_segments( $path );
			if ( null === $segments || self::is_excluded_path( $cfg, $path, $query ) || self::has_bypass_cookie( $cfg, $cookies ) ) {
				return null;
			}

			if ( 'bypass' === $cfg['mobile'] && self::is_mobile_request( $server ) ) {
				return null;
			}
			$variant = self::variant_file( $cfg, $server, $cookies );
			if ( null === $variant ) {
				return null;
			}

			$dir = self::cache_root() . '/' . str_replace( ':', '_', $host ) . ( $segments ? '/' . implode( '/', $segments ) : '' );
			return array(
				'dir'  => $dir,
				'file' => $dir . '/' . $variant,
				'path' => $path,
			);
		}

		/**
		 * Serve a fresh cached copy and stop, or return so WordPress renders normally.
		 */
		public static function serve() {
			if ( headers_sent() ) {
				return;
			}
			$ctx = self::request_context();
			if ( ! $ctx ) {
				return;
			}
			$cfg   = self::load_config();
			$mtime = is_file( $ctx['file'] ) && ! is_link( $ctx['file'] ) ? @filemtime( $ctx['file'] ) : false;
			if ( false === $mtime || ( time() - $mtime ) > $cfg['ttl'] ) {
				return;
			}
			$body = @file_get_contents( $ctx['file'] );
			if ( false === $body || '' === $body ) {
				return;
			}

			if ( $cfg['accept_variants'] ) {
				header( 'Vary: Accept', false );
			}
			if ( 'separate' === $cfg['mobile'] ) {
				header( 'Vary: User-Agent', false );
			}
			// The theme and plugins never ran for this response: replay what they set when the page was built.
			// The first line of each name REPLACES anything already sent (fallback mode runs after
			// other plugins loaded; two different X-Frame-Options values would be invalid); further
			// lines of the same name (Link, CSP) are appended.
			$sent = array();
			foreach ( self::read_headers( $ctx['file'] ) as $line ) {
				$name = strtolower( strstr( $line, ':', true ) );
				header( $line, ! isset( $sent[ $name ] ) );
				$sent[ $name ] = true;
			}
			header( 'Cache-Control: max-age=0, must-revalidate' );
			header( 'X-Beplus-Page-Cache: HIT' );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );

			$since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : false;
			if ( false !== $since && $since >= $mtime ) {
				header( 'HTTP/1.1 304 Not Modified', true, 304 );
				exit;
			}

			header( 'Content-Type: text/html; charset=UTF-8' );
			echo $body;
			exit;
		}

		/**
		 * Path of the header sidecar belonging to a cached page.
		 *
		 * @param string $file Path of the index*.html file.
		 * @return string
		 */
		public static function header_file( $file ) {
			return substr( $file, 0, -5 ) . '.hdr';
		}

		/**
		 * Keep only allowlisted, single-line, printable headers.
		 *
		 * Applied when storing AND when replaying, so a tampered sidecar can never
		 * inject a header (no CR/LF, no Set-Cookie, no Location).
		 *
		 * @param array $lines Header lines such as "X-Frame-Options: SAMEORIGIN".
		 * @return string[]
		 */
		public static function sanitize_headers( array $lines ) {
			$out = array();
			foreach ( $lines as $line ) {
				if ( ! is_string( $line ) || ! preg_match( '/^([A-Za-z][A-Za-z0-9-]{0,60}):[ \t]*([\x20-\x7e]{1,2000})$/', $line, $m ) ) {
					continue;
				}
				if ( in_array( strtolower( $m[1] ), self::REPLAY_HEADERS, true ) ) {
					$out[] = $m[1] . ': ' . trim( $m[2] );
				}
				if ( count( $out ) >= 20 ) {
					break;
				}
			}
			return $out;
		}

		/**
		 * Headers recorded for a cached page.
		 *
		 * @param string $file Path of the index*.html file.
		 * @return string[]
		 */
		public static function read_headers( $file ) {
			$path = self::header_file( $file );
			if ( ! is_file( $path ) || is_link( $path ) ) {
				return array();
			}
			$raw  = @file_get_contents( $path );
			$list = false === $raw ? null : json_decode( $raw, true );
			return is_array( $list ) ? self::sanitize_headers( $list ) : array();
		}

		/**
		 * Atomically store a rendered page.
		 *
		 * @param array  $ctx     Result of request_context().
		 * @param string $html    Final HTML.
		 * @param array  $headers Response headers to replay on a HIT (filtered here).
		 * @return bool
		 */
		public static function store( array $ctx, $html, array $headers = array() ) {
			if ( ! is_string( $html ) || '' === $html || strlen( $html ) > self::MAX_BYTES ) {
				return false;
			}
			$root = self::cache_root();
			if ( ! self::ensure_root( $root ) ) {
				return false;
			}
			$dir = $ctx['dir'];
			// The context was built from validated segments, but re-assert confinement.
			if ( 0 !== strpos( $dir . '/', $root . '/' ) || false !== strpos( $dir, '..' ) ) {
				return false;
			}
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}

			// Headers first: a HIT may then miss a header only if this write fails, never see stale ones.
			$replay = self::sanitize_headers( $headers );
			$hdr    = self::header_file( $ctx['file'] );
			if ( $replay ) {
				if ( ! self::write_atomic( $dir, $hdr, (string) json_encode( $replay ) ) ) {
					return false;
				}
			} elseif ( is_file( $hdr ) ) {
				@unlink( $hdr );
			}
			return self::write_atomic( $dir, $ctx['file'], $html );
		}

		/**
		 * Write a file via temp file + rename.
		 *
		 * @param string $dir  Directory (already confined).
		 * @param string $dest Destination path inside $dir.
		 * @param string $data Bytes.
		 * @return bool
		 */
		private static function write_atomic( $dir, $dest, $data ) {
			$tmp = @tempnam( $dir, '.tmp-' );
			if ( false === $tmp ) {
				return false;
			}
			if ( false === @file_put_contents( $tmp, $data ) || ! @chmod( $tmp, 0644 ) || ! @rename( $tmp, $dest ) ) {
				@unlink( $tmp );
				return false;
			}
			return true;
		}

		/**
		 * Create the cache root and its web-protection files.
		 *
		 * @param string $root Cache root.
		 * @return bool
		 */
		public static function ensure_root( $root ) {
			if ( ! is_dir( $root ) ) {
				if ( ! @mkdir( $root, 0755, true ) && ! is_dir( $root ) ) {
					return false;
				}
			}
			if ( ! is_file( $root . '/index.php' ) ) {
				@file_put_contents( $root . '/index.php', "<?php // Silence is golden.\n" );
			}
			if ( ! is_file( $root . '/.htaccess' ) ) {
				// Cached pages are public HTML, but never list or execute anything here.
				@file_put_contents( $root . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|cgi|sh)$\">\n  Deny from all\n</FilesMatch>\n" );
			}
			return is_dir( $root ) && is_writable( $root );
		}

		/**
		 * Delete the cached variants of one URL path, optionally with /page/N/ below it.
		 *
		 * @param string $path       URL path, e.g. "/blog/post/".
		 * @param array  $hosts      Hosts to purge (defaults to the configured ones).
		 * @param bool   $pagination Also remove the "page" sub-directory (archives, home).
		 * @return int Number of files removed.
		 */
		public static function purge_path( $path, ?array $hosts = null, $pagination = false ) {
			$cfg = self::load_config();
			if ( null === $hosts ) {
				$hosts = $cfg ? $cfg['hosts'] : array();
			}
			$segments = self::path_segments( $path );
			if ( null === $segments ) {
				return 0;
			}
			$root    = self::cache_root();
			$removed = 0;
			foreach ( $hosts as $host ) {
				$dir = $root . '/' . str_replace( ':', '_', strtolower( $host ) ) . ( $segments ? '/' . implode( '/', $segments ) : '' );
				if ( ! is_dir( $dir ) || is_link( $dir ) ) {
					continue;
				}
				$files = glob( $dir . '/index*.html' );
				foreach ( is_array( $files ) ? $files : array() as $file ) {
					if ( is_file( $file ) && @unlink( $file ) ) {
						++$removed;
					}
				}
				foreach ( glob( $dir . '/index*.hdr' ) ?: array() as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file );
					}
				}
				if ( $pagination ) {
					// Archive pagination (page/N) and comment pagination (comment-page-N).
					$subdirs = array_merge( array( $dir . '/page' ), glob( $dir . '/comment-page-*', GLOB_ONLYDIR ) ?: array() );
					foreach ( $subdirs as $subdir ) {
						if ( is_dir( $subdir ) && ! is_link( $subdir ) ) {
							$removed += self::remove_tree( $subdir, $root, true );
						}
					}
				}
			}
			return $removed;
		}

		/**
		 * Remove every cached page. Keeps the root and its protection files.
		 *
		 * @return array{status:string,matched:int,deleted:int,failed:int}
		 */
		public static function purge_all() {
			$result = array(
				'status'  => 'skipped',
				'matched' => 0,
				'deleted' => 0,
				'failed'  => 0,
			);
			$root   = self::cache_root();
			if ( ! is_dir( $root ) || is_link( $root ) ) {
				return $result;
			}
			$before = self::count_files( $root );
			foreach ( glob( $root . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) ?: array() as $host_dir ) {
				if ( is_link( $host_dir ) ) {
					@unlink( $host_dir );
					continue;
				}
				self::remove_tree( $host_dir, $root, true );
			}
			$after             = self::count_files( $root );
			$result['matched'] = $before['files'];
			$result['failed']  = $after['files'];
			$result['deleted'] = max( 0, $before['files'] - $after['files'] );
			if ( 0 === $result['failed'] ) {
				$result['status'] = 'success';
			} else {
				$result['status'] = 0 === $result['deleted'] ? 'failed' : 'partial';
			}
			return $result;
		}

		/**
		 * Delete files older than the TTL (and orphaned temp files); prune empty dirs.
		 *
		 * @return int Files removed.
		 */
		public static function gc() {
			$cfg  = self::load_config();
			$ttl  = $cfg ? $cfg['ttl'] : 36000;
			$root = self::cache_root();
			if ( ! is_dir( $root ) || is_link( $root ) ) {
				return 0;
			}
			$removed  = 0;
			$cutoff   = time() - $ttl;
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $entry ) {
				$path = $entry->getPathname();
				if ( $entry->isLink() ) {
					continue;
				}
				if ( $entry->isFile() ) {
					$name = $entry->getFilename();
					if ( 0 === strpos( $name, 'index' ) && '.hdr' === substr( $name, -4 ) ) {
						// Orphaned header sidecar (its page was purged or expired).
						if ( ! is_file( substr( $path, 0, -4 ) . '.html' ) ) {
							@unlink( $path );
						}
					} elseif ( ( 0 === strpos( $name, 'index' ) && '.html' === substr( $name, -5 ) && $entry->getMTime() < $cutoff ) || ( 0 === strpos( $name, '.tmp-' ) && $entry->getMTime() < time() - 3600 ) ) {
						if ( @unlink( $path ) ) {
							++$removed;
							@unlink( self::header_file( $path ) );
						}
					}
				} elseif ( $entry->isDir() && dirname( $path ) !== $root ) {
					@rmdir( $path ); // Only succeeds when empty.
				}
			}
			return $removed;
		}

		/**
		 * Count cached pages and bytes (bounded so a huge cache cannot stall the admin).
		 *
		 * @param string|null $root Cache root.
		 * @return array{files:int,bytes:int,truncated:bool}
		 */
		public static function count_files( $root = null ) {
			$root  = $root ?? self::cache_root();
			$stats = array(
				'files'     => 0,
				'bytes'     => 0,
				'truncated' => false,
			);
			if ( ! is_dir( $root ) ) {
				return $stats;
			}
			$seen     = 0;
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $entry ) {
				if ( ++$seen > 50000 ) {
					$stats['truncated'] = true;
					break;
				}
				if ( $entry->isFile() && ! $entry->isLink() && 0 === strpos( $entry->getFilename(), 'index' ) && '.html' === substr( $entry->getFilename(), -5 ) ) {
					++$stats['files'];
					$stats['bytes'] += $entry->getSize();
				}
			}
			return $stats;
		}

		/**
		 * Recursively delete a directory tree confined to the cache root. Symlinks
		 * are unlinked, never followed.
		 *
		 * @param string $dir         Directory to delete.
		 * @param string $root        Cache root the directory must live in.
		 * @param bool   $remove_self Remove $dir itself as well.
		 * @return int Files removed.
		 */
		private static function remove_tree( $dir, $root, $remove_self ) {
			$real_root = realpath( $root );
			$real_dir  = realpath( $dir );
			if ( false === $real_root || false === $real_dir || 0 !== strpos( $real_dir . '/', $real_root . '/' ) || $real_dir === $real_root ) {
				return 0;
			}
			$removed  = 0;
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $iterator as $entry ) {
				if ( $entry->isLink() || $entry->isFile() ) {
					if ( @unlink( $entry->getPathname() ) ) {
						++$removed;
					}
				} elseif ( $entry->isDir() ) {
					@rmdir( $entry->getPathname() );
				}
			}
			if ( $remove_self ) {
				@rmdir( $real_dir );
			}
			return $removed;
		}
	}
}
