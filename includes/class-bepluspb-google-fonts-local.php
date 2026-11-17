<?php
/**
 * Google Fonts self-hosting: download the stylesheet and the font files once, serve them from this site.
 *
 * This is the only part of the plugin that makes requests to another server, so it is deliberately
 * narrow:
 *
 *  - Only two hosts, matched exactly: `fonts.googleapis.com` (the stylesheet) and `fonts.gstatic.com`
 *    (the font files named inside that stylesheet). No URL is ever typed in: the stylesheet URLs are
 *    the ones the site's own pages already contain, and each is validated (host, path, query charset)
 *    before it is queued or fetched.
 *  - HTTPS only, redirects off, no cookies, WordPress's own unsafe-URL check on, a generic browser
 *    User-Agent (Google serves woff2 to it), size limits per stylesheet and per font file, a limit on
 *    the number of files and on the whole folder.
 *  - The stylesheet is parsed strictly: only `@font-face` blocks with a fixed set of properties (and,
 *    for icon fonts, `.class{…}` helper rules limited to typography properties) are accepted and the
 *    local stylesheet is REBUILT from the parsed values, never copied. Anything
 *    unexpected makes the whole stylesheet fail, and a failure keeps the original Google link.
 *  - Font files must start with the woff2 signature; file names are hashes of their source URL.
 *  - Requests never happen while a visitor waits: the front end only records which stylesheets it
 *    saw; WP-Cron (or the admin's "Download now") fetches them. The local stylesheet is written last,
 *    so a half-finished download is never referenced.
 *
 * Files live in `uploads/bepluspb-cache/fonts/` (`<id>.css`, `<hash>.woff2`); the CSS/JS cache purge
 * does not touch them (it only removes the folder's top-level css/js files).
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Google_Fonts_Local
 */
class BEPLUSPB_Google_Fonts_Local {

	/** Non-autoloaded option: stylesheets seen on the site and not downloaded yet. */
	const QUEUE_OPTION = 'bepluspb_gf_queue';

	/** WP-Cron hook that downloads the queue. */
	const CRON_HOOK = 'bepluspb_gf_fetch';

	/** The only host a stylesheet may come from. */
	const CSS_HOST = 'fonts.googleapis.com';

	/** The only host a font file may come from. */
	const FONT_HOST = 'fonts.gstatic.com';

	/** A generic desktop browser: Google serves woff2 files (with unicode-range subsets) to it. */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/** Stylesheets that can wait in the queue. */
	const MAX_QUEUE = 20;

	/** Largest accepted stylesheet response (bytes). */
	const MAX_CSS_BYTES = 262144;

	/** Largest accepted font file (bytes). */
	const MAX_FONT_BYTES = 1048576;

	/** Most @font-face blocks per stylesheet. */
	const MAX_FACES = 80;

	/** Most helper class rules (icon fonts) per stylesheet. */
	const MAX_RULES = 20;

	/** Most bytes the whole fonts folder may hold. */
	const MAX_TOTAL_BYTES = 52428800;

	/** Failed attempts after which a stylesheet is only retried after RETRY_AFTER. */
	const MAX_FAILS = 3;

	/** Seconds before a stylesheet that failed MAX_FAILS times is tried again. */
	const RETRY_AFTER = 21600;

	/** Seconds one run may spend downloading (checked between requests). */
	const TIME_BUDGET = 20;

	/** Longest stylesheet URL query accepted. */
	const MAX_QUERY = 2000;

	/**
	 * Stylesheet ids already recorded during this request.
	 *
	 * @var array
	 */
	private static $noted = array();

	/**
	 * Forget what this request already recorded.
	 */
	public static function reset() {
		self::$noted = array();
	}

	// -------------------------------------------------------------------------
	// Locations
	// -------------------------------------------------------------------------

	/**
	 * Absolute folder with a trailing slash.
	 *
	 * @return string
	 */
	public static function dir() {
		return BEPLUSPB_CACHE_DIR . 'fonts/';
	}

	/**
	 * Root-relative URL path of the folder (works on every domain of a multi-domain site).
	 *
	 * @return string With leading and trailing slash.
	 */
	public static function url_path() {
		$path = (string) wp_parse_url( BEPLUSPB_Minify::cache_url(), PHP_URL_PATH );
		return trailingslashit( '/' . ltrim( $path, '/' ) ) . 'fonts/';
	}

	// -------------------------------------------------------------------------
	// Validation (pure)
	// -------------------------------------------------------------------------

	/**
	 * Canonical form of a Google Fonts stylesheet URL, or '' when it is not exactly one.
	 *
	 * Accepts what a page may contain (HTML entities, protocol-relative, http) and returns
	 * `https://fonts.googleapis.com/{css|css2|icon}?{query}`.
	 *
	 * @param  mixed $url URL as found in a page.
	 * @return string
	 */
	public static function normalize_css_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) || strlen( $url ) > self::MAX_QUERY + 100 ) {
			return '';
		}
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === stripos( $url, 'http://' ) ) {
			$url = 'https://' . substr( $url, 7 );
		}
		if ( preg_match( '/[\x00-\x20\x7f<>"\'`\\\\#]/', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'], $parts['query'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return '';
		}
		if ( strtolower( $parts['host'] ) !== self::CSS_HOST || self::has_extras( $parts ) ) {
			return '';
		}
		if ( ! in_array( $parts['path'], array( '/css', '/css2', '/icon' ), true ) ) {
			return '';
		}
		$query = $parts['query'];
		if ( strlen( $query ) > self::MAX_QUERY || ! preg_match( '/^[A-Za-z0-9%:;,@=&+|._~\-]+$/D', $query ) || ! preg_match( '/(?:^|&)family=/', $query ) ) {
			return '';
		}
		return 'https://' . self::CSS_HOST . $parts['path'] . '?' . $query;
	}

	/**
	 * Whether a URL is a font file on the Google font host (path and query checked, not the extension:
	 * current files look like /l/font?kit=…&skey=…&v=v28).
	 *
	 * @param  mixed $url URL taken from a validated stylesheet.
	 * @return bool
	 */
	public static function is_font_url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 600 || preg_match( '/[\x00-\x20\x7f<>"\'`\\\\#]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( strtolower( $parts['host'] ) !== self::FONT_HOST || self::has_extras( $parts ) ) {
			return false;
		}
		if ( false !== strpos( $parts['path'], '..' ) || ! preg_match( '#^/(?:l/font|s/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)$#D', $parts['path'] ) ) {
			return false;
		}
		return ! isset( $parts['query'] ) || 1 === preg_match( '/^[A-Za-z0-9=&_.\-%]{0,400}$/D', $parts['query'] );
	}

	/**
	 * Whether a parsed URL carries a port, credentials or a fragment (none is acceptable).
	 *
	 * @param  array $parts Result of wp_parse_url().
	 * @return bool
	 */
	private static function has_extras( $parts ) {
		return isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] );
	}

	/**
	 * Stable id of a stylesheet (names its local file and its queue entry).
	 *
	 * @param  string $canonical Canonical stylesheet URL.
	 * @return string
	 */
	public static function id_for( $canonical ) {
		return substr( sha1( $canonical ), 0, 16 );
	}

	// -------------------------------------------------------------------------
	// Strict parser
	// -------------------------------------------------------------------------

	/**
	 * Parse a Google Fonts stylesheet into validated @font-face descriptors.
	 *
	 * @param  string $css Response body.
	 * @return array { faces: array[] } or { error: string }. A face has family, style, weight,
	 *               stretch, display, range (each '' when absent) and src (a gstatic URL).
	 */
	public static function parse_css( $css ) {
		if ( ! is_string( $css ) || '' === trim( $css ) ) {
			return array( 'error' => 'empty stylesheet' );
		}
		if ( preg_match( '/[\x00<>]|@import|expression\s*\(|javascript\s*:/i', $css ) ) {
			return array( 'error' => 'unexpected content in the stylesheet' );
		}
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		// What is left once the @font-face blocks are gone may only be helper class rules
		// (icon fonts ship `.material-icons{…}`): `.name{allowed declarations}`.
		$rules = array();
		$rest  = trim( (string) preg_replace( '/@font-face\s*\{[^{}]*\}/', '', $css ) );
		while ( '' !== $rest ) {
			if ( count( $rules ) >= self::MAX_RULES || ! preg_match( '/^\.([A-Za-z0-9_-]{1,60})\s*\{([^{}]*)\}\s*/', $rest, $m ) ) {
				return array( 'error' => 'the stylesheet holds more than @font-face rules' );
			}
			$decls = array();
			foreach ( explode( ';', $m[2] ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$colon = strpos( $declaration, ':' );
				$name  = false === $colon ? '' : strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value = false === $colon ? '' : trim( substr( $declaration, $colon + 1 ) );
				if ( ! self::accept_rule_declaration( $name, $value ) ) {
					return array( 'error' => 'unsupported helper-rule property: ' . substr( preg_replace( '/[^a-z-]/', '', $name ), 0, 40 ) );
				}
				$decls[] = array( $name, $value );
			}
			if ( ! $decls ) {
				return array( 'error' => 'an empty helper rule' );
			}
			$rules[] = array(
				'class' => $m[1],
				'decls' => $decls,
			);
			$rest    = substr( $rest, strlen( $m[0] ) );
		}
		if ( ! preg_match_all( '/@font-face\s*\{([^{}]*)\}/', $css, $blocks ) ) {
			return array( 'error' => 'no @font-face rule' );
		}
		if ( count( $blocks[1] ) > self::MAX_FACES ) {
			return array( 'error' => 'too many @font-face rules' );
		}

		$faces = array();
		foreach ( $blocks[1] as $body ) {
			$face = array(
				'family'  => '',
				'style'   => '',
				'weight'  => '',
				'stretch' => '',
				'display' => '',
				'range'   => '',
				'src'     => '',
			);
			foreach ( explode( ';', $body ) as $declaration ) {
				$declaration = trim( $declaration );
				if ( '' === $declaration ) {
					continue;
				}
				$colon = strpos( $declaration, ':' );
				if ( false === $colon ) {
					return array( 'error' => 'malformed declaration' );
				}
				$name  = strtolower( trim( substr( $declaration, 0, $colon ) ) );
				$value = trim( substr( $declaration, $colon + 1 ) );
				if ( ! self::accept_declaration( $name, $value, $face ) ) {
					return array( 'error' => 'unsupported @font-face property: ' . substr( preg_replace( '/[^a-z-]/', '', $name ), 0, 40 ) );
				}
			}
			if ( '' === $face['family'] || '' === $face['src'] ) {
				return array( 'error' => 'an @font-face rule has no family or source' );
			}
			$faces[] = $face;
		}
		return array(
			'faces' => $faces,
			'rules' => $rules,
		);
	}

	/**
	 * Whether a declaration of a helper class rule is one we accept (typography of an icon font only).
	 *
	 * @param  string $name  Lower-case property.
	 * @param  string $value Raw value.
	 * @return bool
	 */
	private static function accept_rule_declaration( $name, $value ) {
		$length = '(?:-?\d{1,3}(?:\.\d{1,3})?(?:px|em|rem))';
		$tag    = "'[A-Za-z0-9]{4}'";
		$map    = array(
			'font-family'                => '/^([\'"])[A-Za-z0-9 _.\-]{1,80}\1$/D',
			'font-weight'                => '/^(?:normal|bold|\d{1,4})$/D',
			'font-style'                 => '/^(?:normal|italic)$/D',
			'font-size'                  => '/^\d{1,3}(?:\.\d{1,3})?(?:px|em|rem)$/D',
			'line-height'                => '/^(?:normal|\d(?:\.\d{1,3})?|' . $length . ')$/D',
			'letter-spacing'             => '/^(?:normal|' . $length . ')$/D',
			'text-transform'             => '/^(?:none|uppercase|lowercase|capitalize)$/D',
			'display'                    => '/^(?:inline-block|inline|block)$/D',
			'white-space'                => '/^(?:nowrap|normal|pre)$/D',
			'word-wrap'                  => '/^(?:normal|break-word)$/D',
			'direction'                  => '/^(?:ltr|rtl)$/D',
			'font-feature-settings'      => '/^' . $tag . '(?: (?:on|off|\d))?(?:\s*,\s*' . $tag . '(?: (?:on|off|\d))?)*$/D',
			'-webkit-font-feature-settings' => '/^' . $tag . '(?: (?:on|off|\d))?(?:\s*,\s*' . $tag . '(?: (?:on|off|\d))?)*$/D',
			'-webkit-font-smoothing'     => '/^(?:antialiased|subpixel-antialiased|auto)$/D',
			'-moz-osx-font-smoothing'    => '/^(?:grayscale|auto)$/D',
			'font-variation-settings'    => '/^' . $tag . '\s+-?\d{1,4}(?:\.\d{1,3})?(?:\s*,\s*' . $tag . '\s+-?\d{1,4}(?:\.\d{1,3})?)*$/D',
		);
		return isset( $map[ $name ] ) && 1 === preg_match( $map[ $name ], $value );
	}

	/**
	 * Validate one declaration of an @font-face block and store it in $face.
	 *
	 * @param  string $name  Lower-case property name.
	 * @param  string $value Raw value.
	 * @param  array  $face  Descriptor being built.
	 * @return bool False when the property or its value is not accepted.
	 */
	private static function accept_declaration( $name, $value, &$face ) {
		switch ( $name ) {
			case 'font-family':
				if ( ! preg_match( '/^([\'"])([A-Za-z0-9 _.\-]{1,80})\1$/D', $value, $m ) ) {
					return false;
				}
				$face['family'] = $m[2];
				return true;
			case 'font-style':
				if ( ! preg_match( '/^(?:normal|italic|oblique(?: -?\d{1,3}(?:\.\d{1,3})?deg)?)$/D', $value ) ) {
					return false;
				}
				$face['style'] = $value;
				return true;
			case 'font-weight':
				if ( ! preg_match( '/^(?:normal|bold|\d{1,4}(?: \d{1,4})?)$/D', $value ) ) {
					return false;
				}
				$face['weight'] = $value;
				return true;
			case 'font-stretch':
				if ( ! preg_match( '/^(?:normal|\d{1,3}(?:\.\d{1,3})?%(?: \d{1,3}(?:\.\d{1,3})?%)?)$/D', $value ) ) {
					return false;
				}
				$face['stretch'] = $value;
				return true;
			case 'font-display':
				if ( ! preg_match( '/^(?:auto|block|swap|fallback|optional)$/D', $value ) ) {
					return false;
				}
				$face['display'] = $value;
				return true;
			case 'unicode-range':
				if ( strlen( $value ) > 6000 || ! preg_match( '/^U\+[0-9A-Fa-f?\-]{1,13}(?:\s*,\s*U\+[0-9A-Fa-f?\-]{1,13})*$/D', $value ) ) {
					return false;
				}
				$face['range'] = (string) preg_replace( '/\s*,\s*/', ',', $value );
				return true;
			case 'src':
				if ( ! preg_match( '/^url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)\s*format\(\s*([\'"])woff2\3\s*\)$/D', $value, $m ) || ! self::is_font_url( $m[2] ) ) {
					return false;
				}
				$face['src'] = $m[2];
				return true;
		}
		return false;
	}

	/**
	 * The local stylesheet, rebuilt from validated descriptors.
	 *
	 * @param  array    $faces  Descriptors from parse_css().
	 * @param  string[] $locals Font URL => local URL path.
	 * @param  array    $rules  Helper class rules from parse_css().
	 * @return string
	 */
	public static function build_css( $faces, $locals, $rules = array() ) {
		$css = '';
		foreach ( $faces as $face ) {
			$css .= "@font-face{font-family:'" . $face['family'] . "';";
			foreach ( array( 'style' => 'font-style', 'weight' => 'font-weight', 'stretch' => 'font-stretch', 'display' => 'font-display' ) as $key => $property ) {
				if ( '' !== $face[ $key ] ) {
					$css .= $property . ':' . $face[ $key ] . ';';
				}
			}
			$css .= 'src:url(' . $locals[ $face['src'] ] . ") format('woff2')";
			if ( '' !== $face['range'] ) {
				$css .= ';unicode-range:' . $face['range'];
			}
			$css .= "}\n";
		}
		foreach ( $rules as $rule ) {
			$css .= '.' . $rule['class'] . '{';
			foreach ( $rule['decls'] as $decl ) {
				$css .= $decl[0] . ':' . $decl[1] . ';';
			}
			$css .= "}\n";
		}
		return $css;
	}

	// -------------------------------------------------------------------------
	// Front end (cheap: file checks only; never a request to another server)
	// -------------------------------------------------------------------------

	/**
	 * URL of the local stylesheet for a canonical URL, or null when it is not downloaded (yet).
	 *
	 * @param  string $canonical Canonical stylesheet URL.
	 * @return string|null Root-relative URL with a version, e.g. /wp-content/uploads/…/fonts/ab12.css?ver=1700000000.
	 */
	public static function local_url( $canonical ) {
		$file = self::dir() . self::id_for( $canonical ) . '.css';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$mtime = (int) filemtime( $file );
		return self::url_path() . self::id_for( $canonical ) . '.css?ver=' . $mtime;
	}

	/**
	 * Callback for BEPLUSPB_Google_Fonts: the local stylesheet for a URL found in the page, or null
	 * (and the URL is queued for the next download run).
	 *
	 * @param  string $url URL as found in the page.
	 * @return string|null
	 */
	public static function frontend_localize( $url ) {
		$canonical = self::normalize_css_url( $url );
		if ( '' === $canonical ) {
			return null;
		}
		$local = self::local_url( $canonical );
		if ( null === $local ) {
			self::note_seen( $canonical );
		}
		return $local;
	}

	/**
	 * Record a stylesheet the site uses and schedule the download. Writes to the database only
	 * the first time a stylesheet is seen (at most MAX_QUEUE of them).
	 *
	 * @param string $canonical Canonical stylesheet URL.
	 */
	public static function note_seen( $canonical ) {
		$id = self::id_for( $canonical );
		if ( isset( self::$noted[ $id ] ) ) {
			return;
		}
		self::$noted[ $id ] = true;

		$queue = self::queue();
		if ( isset( $queue[ $id ] ) || count( $queue ) >= self::MAX_QUEUE ) {
			return;
		}
		$queue[ $id ] = array(
			'url'   => $canonical,
			'fails' => 0,
			'last'  => 0,
			'added' => time(),
			'error' => '',
		);
		update_option( self::QUEUE_OPTION, $queue, false );
		self::schedule( 20 );
	}

	/**
	 * Schedule one download run (if none is pending).
	 *
	 * @param int $delay Seconds from now.
	 */
	public static function schedule( $delay ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK );
		}
	}

	/**
	 * The queue, with every entry re-validated (the option is data).
	 *
	 * @return array id => { url, fails, last, added, error }
	 */
	public static function queue() {
		$stored = get_option( self::QUEUE_OPTION, array() );
		$queue  = array();
		if ( ! is_array( $stored ) ) {
			return $queue;
		}
		foreach ( $stored as $id => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['url'] ) ) {
				continue;
			}
			$canonical = self::normalize_css_url( $entry['url'] );
			if ( '' === $canonical || self::id_for( $canonical ) !== $id || count( $queue ) >= self::MAX_QUEUE ) {
				continue;
			}
			$queue[ $id ] = array(
				'url'   => $canonical,
				'fails' => isset( $entry['fails'] ) ? max( 0, (int) $entry['fails'] ) : 0,
				'last'  => isset( $entry['last'] ) ? max( 0, (int) $entry['last'] ) : 0,
				'added' => isset( $entry['added'] ) ? max( 0, (int) $entry['added'] ) : 0,
				'error' => isset( $entry['error'] ) && is_string( $entry['error'] ) ? substr( preg_replace( '/[^\x20-\x7e]/', '', $entry['error'] ), 0, 200 ) : '',
			);
		}
		return $queue;
	}

	// -------------------------------------------------------------------------
	// Download (cron, or the administrator's "Download now")
	// -------------------------------------------------------------------------

	/**
	 * WP-Cron callback: download what is queued while the mode is still self-host.
	 */
	public static function run_cron() {
		$opts = bepluspb_get_options();
		if ( empty( $opts['google_fonts_mode'] ) || 'selfhost' !== $opts['google_fonts_mode'] ) {
			return;
		}
		$summary = self::process_queue( self::TIME_BUDGET );
		if ( $summary['retry'] > 0 ) {
			self::schedule( 300 );
		}
	}

	/**
	 * Download the queued stylesheets that are due.
	 *
	 * @param  float|int $budget Seconds this run may spend (checked between requests; 0 = none left).
	 * @return array { done: int, failed: int, pending: int, retry: int, errors: string[] }
	 */
	public static function process_queue( $budget = self::TIME_BUDGET ) {
		$deadline = microtime( true ) + max( 0, (float) $budget );
		$queue    = self::queue();
		$summary  = array(
			'done'    => 0,
			'failed'  => 0,
			'pending' => 0,
			'retry'   => 0,
			'errors'  => array(),
		);
		$now = time();

		foreach ( $queue as $id => $entry ) {
			if ( $entry['fails'] >= self::MAX_FAILS && $now - $entry['last'] < self::RETRY_AFTER ) {
				++$summary['failed'];
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				++$summary['pending'];
				continue;
			}

			$result = self::localize_stylesheet( $entry['url'], $deadline );
			if ( isset( $result['ok'] ) ) {
				unset( $queue[ $id ] );
				++$summary['done'];
				continue;
			}

			// A run that ran out of time is not the stylesheet's fault: do not count it.
			if ( ! empty( $result['timeout'] ) ) {
				++$summary['pending'];
				++$summary['retry'];
				continue;
			}
			$queue[ $id ]['fails'] = $entry['fails'] + 1;
			$queue[ $id ]['last']  = time();
			$queue[ $id ]['error'] = substr( preg_replace( '/[^\x20-\x7e]/', '', (string) $result['error'] ), 0, 200 );
			++$summary['failed'];
			$summary['errors'][] = $queue[ $id ]['error'];
			if ( $queue[ $id ]['fails'] < self::MAX_FAILS ) {
				++$summary['retry'];
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );
		if ( $summary['done'] > 0 ) {
			/** Fires after stylesheets were downloaded: stored pages still link the Google URLs. */
			do_action( 'bepluspb_google_fonts_localized' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		}
		return $summary;
	}

	/**
	 * Download one stylesheet and its font files and write the local stylesheet.
	 *
	 * @param  string $canonical Canonical stylesheet URL (validated again here).
	 * @param  float  $deadline  microtime() after which no new request may start.
	 * @return array { ok: true, fonts: int, bytes: int } or { error: string, timeout?: true }
	 */
	public static function localize_stylesheet( $canonical, $deadline = 0.0 ) {
		$canonical = self::normalize_css_url( $canonical );
		if ( '' === $canonical ) {
			return array( 'error' => 'not a Google Fonts stylesheet URL' );
		}
		if ( ! BEPLUSPB_Minify::ensure_cache_dir() || ! self::ensure_dir() ) {
			return array( 'error' => 'the fonts folder is not writable' );
		}

		$body = self::fetch( $canonical, self::MAX_CSS_BYTES );
		if ( isset( $body['error'] ) ) {
			return $body;
		}
		$parsed = self::parse_css( $body['body'] );
		if ( isset( $parsed['error'] ) ) {
			return $parsed;
		}

		$locals = array();
		$bytes  = 0;
		foreach ( $parsed['faces'] as $face ) {
			$src = $face['src'];
			if ( isset( $locals[ $src ] ) ) {
				continue;
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				return array(
					'error'   => 'time budget used up, will continue',
					'timeout' => true,
				);
			}
			$file = self::fetch_font( $src );
			if ( isset( $file['error'] ) ) {
				return $file;
			}
			$locals[ $src ] = self::url_path() . $file['name'];
			$bytes         += $file['bytes'];
		}

		$id = self::id_for( $canonical );
		if ( ! BEPLUSPB_Minify::write_cache_file_atomic( self::dir() . $id . '.css', self::build_css( $parsed['faces'], $locals, $parsed['rules'] ) ) ) {
			return array( 'error' => 'could not write the local stylesheet' );
		}
		return array(
			'ok'    => true,
			'fonts' => count( $locals ),
			'bytes' => $bytes,
		);
	}

	/**
	 * One GET to an allowed host, under every limit.
	 *
	 * @param  string $url Validated URL.
	 * @param  int    $max Largest body accepted (bytes).
	 * @return array { body: string } or { error: string }
	 */
	private static function fetch( $url, $max ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'user-agent'          => self::USER_AGENT,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => $max + 1,
				'headers'             => array( 'Accept' => '*/*' ),
				'cookies'             => array(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'request failed: ' . substr( $response->get_error_message(), 0, 120 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array( 'error' => 'unexpected HTTP status ' . $code );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return array( 'error' => 'empty response' );
		}
		if ( strlen( $body ) > $max ) {
			return array( 'error' => 'response larger than allowed' );
		}
		return array( 'body' => $body );
	}

	/**
	 * Download one font file (unless it is already here) and store it under a hash name.
	 *
	 * @param  string $url Validated gstatic URL.
	 * @return array { name: string, bytes: int } or { error: string }
	 */
	private static function fetch_font( $url ) {
		$name = substr( sha1( $url ), 0, 20 ) . '.woff2';
		$path = self::dir() . $name;
		if ( is_file( $path ) ) {
			return array(
				'name'  => $name,
				'bytes' => (int) filesize( $path ),
			);
		}
		if ( self::disk_usage() >= self::MAX_TOTAL_BYTES ) {
			return array( 'error' => 'the fonts folder is full' );
		}
		$response = self::fetch( $url, self::MAX_FONT_BYTES );
		if ( isset( $response['error'] ) ) {
			return $response;
		}
		if ( 'wOF2' !== substr( $response['body'], 0, 4 ) ) {
			return array( 'error' => 'a font file is not woff2' );
		}
		if ( ! BEPLUSPB_Minify::write_cache_file_atomic( $path, $response['body'] ) ) {
			return array( 'error' => 'could not write a font file' );
		}
		return array(
			'name'  => $name,
			'bytes' => strlen( $response['body'] ),
		);
	}

	// -------------------------------------------------------------------------
	// Folder, status, cleanup
	// -------------------------------------------------------------------------

	/**
	 * Create the fonts folder (inside the already protected cache folder).
	 *
	 * @return bool
	 */
	private static function ensure_dir() {
		$dir = self::dir();
		return is_dir( $dir ) || wp_mkdir_p( $dir );
	}

	/**
	 * Names of the files in the fonts folder (no glob: the path may hold glob characters).
	 *
	 * @return string[]
	 */
	private static function files() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( preg_match( '/^[a-f0-9]{16,20}\.(?:css|woff2)$/D', $name ) && is_file( $dir . $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Bytes held by the fonts folder.
	 *
	 * @return int
	 */
	public static function disk_usage() {
		$total = 0;
		foreach ( self::files() as $name ) {
			$total += (int) filesize( self::dir() . $name );
		}
		return $total;
	}

	/**
	 * Numbers for the settings screen.
	 *
	 * @return array { sheets, fonts, bytes, queue: array[] } where each queue item has url, fails, error.
	 */
	public static function status() {
		$sheets = 0;
		$fonts  = 0;
		$bytes  = 0;
		foreach ( self::files() as $name ) {
			$bytes += (int) filesize( self::dir() . $name );
			if ( '.css' === substr( $name, -4 ) ) {
				++$sheets;
			} else {
				++$fonts;
			}
		}
		$queue = array();
		foreach ( self::queue() as $entry ) {
			$queue[] = array(
				'url'   => $entry['url'],
				'fails' => $entry['fails'],
				'error' => $entry['error'],
			);
		}
		return array(
			'sheets' => $sheets,
			'fonts'  => $fonts,
			'bytes'  => $bytes,
			'queue'  => $queue,
		);
	}

	/**
	 * Delete every local font and stylesheet and forget the queue.
	 *
	 * @return int Files removed.
	 */
	public static function clear() {
		$removed = 0;
		foreach ( self::files() as $name ) {
			wp_delete_file( self::dir() . $name );
			if ( ! file_exists( self::dir() . $name ) ) {
				++$removed;
			}
		}
		delete_option( self::QUEUE_OPTION );
		self::$noted = array();
		// Stored pages link the files that were just removed (same hook as the CSS/JS cache purge).
		do_action( 'bepluspb_assets_cache_cleared' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		return $removed;
	}

	/**
	 * Register the cron callback (must exist whether or not the feature is on: an event may be pending).
	 */
	public static function register_core_hooks() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}
}
