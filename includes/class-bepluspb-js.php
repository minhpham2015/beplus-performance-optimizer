<?php
/**
 * JavaScript optimization: defer, simple delay, and advanced delay.
 *
 * Advanced mode uses a PHP output buffer to rewrite every <script> tag to
 * type="javascript/blocked", storing the real src in data-bepluspb-src before
 * the browser receives the page. The inlined delay-advanced.js runtime (injected
 * before the first <script>) uses a MutationObserver + event-queue interceptor
 * to unblock scripts only after the first user interaction (or after above-fold
 * images finish loading when rdelay > 0).
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_JS
 */
class BEPLUSPB_JS {

	/**
	 * The ob_get_level() value recorded immediately before advanced mode's
	 * ob_start() call.
	 *
	 * Delay JS (advanced) opens its output buffer LAST (template_redirect at
	 * PHP_INT_MAX, after UCSS/HTML/CDN have already opened theirs), so on
	 * shutdown it is the INNERMOST buffer on the stack and MUST close FIRST
	 * — otherwise an outer feature's buffer_end() (e.g. UCSS, which used to
	 * pop whatever buffer happened to be on top without verifying the exact
	 * level) can pop Delay JS's still-open buffer instead of its own,
	 * discarding the rewritten <script> output and ending the response
	 * before the outer feature ever runs its own buffer_end(). See
	 * advanced_buffer_end() below, hooked at the lowest shutdown priority so
	 * it always runs before UCSS/HTML/CDN's shutdown handlers.
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Register front-end hooks based on current settings.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( $opts['js_defer'] ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'defer_scripts' ), 10, 3 );
		}

		if ( $opts['js_delay'] && ! self::delay_disabled_for_request() ) {
			$mode = isset( $opts['js_delay_mode'] ) ? $opts['js_delay_mode'] : 'simple';

			if ( 'advanced' === $mode ) {
				self::init_advanced_delay( $opts );
			} else {
				// Simple mode: placeholder swap via script_loader_tag + delay.js loader.
				add_filter( 'script_loader_tag', array( __CLASS__, 'delay_scripts' ), 20, 3 );
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_delay_script' ) );
			}
		}

		if ( ! empty( $opts['remove_js_handles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_js_handles' ), 100 );
		}
	}

	/**
	 * Whether Delay JS is switched off for this request: `?bepluspb-nodelay` in the URL (to compare a page with and
	 * without it, or to find out whether Delay JS breaks something), or the `bepluspb_delay_js_enabled` filter
	 * (per page, e.g. `is_page( 'checkout' )`). Read-only switch that only turns an optimisation OFF; the page cache
	 * never stores or serves a URL with this parameter.
	 *
	 * @return bool
	 */
	public static function delay_disabled_for_request() {
		if ( isset( $_GET['bepluspb-nodelay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch, nothing is stored.
			return true;
		}
		return function_exists( 'apply_filters' ) && ! apply_filters( 'bepluspb_delay_js_enabled', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
	}

	// =========================================================================
	// Remove JS handles
	// =========================================================================

	/**
	 * Dequeue and deregister JS handles listed in the remove_js_handles option.
	 */
	public static function dequeue_js_handles() {
		$opts    = bepluspb_get_options();
		$handles = bepluspb_parse_exclude_list( $opts['remove_js_handles'] );

		foreach ( $handles as $handle ) {
			if ( ! empty( $handle ) ) {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}

	// =========================================================================
	// Defer
	// =========================================================================

	/**
	 * Add the `defer` attribute to script tags.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function defer_scripts( $tag, $handle, $src ) {
		$never_defer = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments', 'bepluspb-delay-js', 'bepluspb-lazy-fallback' );
		if ( in_array( $handle, $never_defer, true ) ) {
			return $tag;
		}

		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}

		// An inline script attached with wp_add_inline_script( ..., 'after' )
		// runs immediately; deferring the file it depends on would make it
		// throw "X is not defined".
		if ( function_exists( 'wp_scripts' ) && wp_scripts()->get_data( $handle, 'after' ) ) {
			return $tag;
		}

		// The same goes for that file's dependencies: a deferred dependency would
		// run after the non-deferred script that needs it.
		if ( in_array( $handle, self::inline_after_dependencies(), true ) ) {
			return $tag;
		}

		// Touch only the first <script ... src=> so inline before/after blocks
		// that happen to contain " src=" are left alone.
		$deferred = preg_replace( '/(<script\b[^>]*?)\ssrc=/i', '$1 defer src=', $tag, 1 );
		return is_string( $deferred ) ? $deferred : $tag;
	}

	// =========================================================================
	// Built-in exclusions and opt-out attributes (both delay modes)
	// =========================================================================

	/**
	 * Patterns (PCRE fragments, case-insensitive) for scripts that must never be delayed: matched
	 * against an inline script's content, a script URL, an id and (Simple mode) a handle.
	 *
	 * Delaying these makes a page flash, hides content until the first interaction, or breaks the
	 * consent banner. Same idea as WP Meteor's compatibility/GDPR lists; the Elementor entry is ours.
	 *
	 * @return string[]
	 */
	private static function builtin_exclude_fragments() {
		return array(
			// Elementor: puts .e-lazyloaded on containers; until it runs their background images stay hidden.
			'lazyloadRunObserver',
			// Scripts that swap "no-js" for "js": delayed, the page keeps its no-JS layout until the first touch.
			'classList\.(?:remove|replace|add)\(\s*["\'][^"\']*no-js',
			'\.replace\([^)]{0,40}no-js',
			'className\s*=\s*["\']js["\']',
			'woocommerce-no-js',
			'function et_core_page_resource_fallback\(',
			'fusionNavIsCollapsed',
			'window\.\$us === undefined',
			// Other lazy loaders and their configuration.
			'lazyLoadOptions',
			'lazySizesConfig',
			'lazyLoadThumb',
			'eio_lazy_vars',
			'ewww_webp_supported',
			'lazysizes(?:\.min)?\.js',
			'lazyload(?:\.min)?\.js',
			'wp-rocket/assets/js/lazyload',
			'rocket-lazy-load',
			'smush-lazy-load(?:-native)?(?:\.min)?\.js',
			'jetpack-lazy-images',
			'lazy-images\.js',
			'data-swift-image-lazyload',
			'js-agent\.newrelic\.com',
			// Cookie / consent banners: they must appear at once (consent is legally required first).
			'ap\.legalblink\.it',
			'cmplz-cookiebanner',
			'complianz',
			'cookie-notice',
			'\bcnArgs\b',
			'cookie-law-info',
			'cli_cookiebar',
			'cookieyes',
			'cookiebot',
			'cookielaw\.org',
			'onetrust',
			'optanon',
			'iubenda',
			'termly',
			'borlabs-cookie',
			'cookie-script\.com',
			'usercentrics',
			'cookie-information',
			'consentmanager',
			'cookieconsent',
			'moove_gdpr',
			'gdpr-cookie-compliance',
			'real-cookie-banner',
			'cookiehub',
			'didomi',
			'axeptio',
			'trustarc',
			'osano',
		);
	}

	/**
	 * Whether a script (its content, URL, id or handle) is on the built-in never-delay list.
	 *
	 * @param  string $haystack Inline content, URL, id or handle.
	 * @return bool
	 */
	public static function is_builtin_excluded( $haystack ) {
		static $regex = null;

		$haystack = (string) $haystack;
		if ( '' === $haystack ) {
			return false;
		}
		if ( null === $regex ) {
			$regex = '#(?:' . implode( '|', array_map( array( __CLASS__, 'escape_hash' ), self::builtin_exclude_fragments() ) ) . ')#i';
		}
		$hit = 1 === preg_match( $regex, $haystack );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters whether a script is left out of Delay JS by the built-in rules.
			 *
			 * @param bool   $hit      Whether the built-in list matched.
			 * @param string $haystack Inline content, URL, id or handle.
			 */
			$hit = (bool) apply_filters( 'bepluspb_delay_js_exclude', $hit, $haystack ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
		}
		return $hit;
	}

	/**
	 * Escape the regex delimiter.
	 *
	 * @param  string $fragment PCRE fragment.
	 * @return string
	 */
	private static function escape_hash( $fragment ) {
		return str_replace( '#', '\\#', $fragment );
	}

	/**
	 * Whether a <script> tag carries one of the conventional "do not optimise" attributes that other
	 * performance plugins use (WP Rocket, Autoptimize, Cloudflare, WP Meteor, SG Optimizer...).
	 *
	 * @param  string $tag Opening <script ...> tag.
	 * @return bool
	 */
	public static function has_opt_out_attr( $tag ) {
		return 1 === preg_match(
			'/\s(?:(?:data-no-optimize|data-no-defer|data-no-delay|data-pagespeed-no-defer|data-wpmeteor-nooptimize|nowprocket)(?=[\s=>\/])|data-cfasync\s*=\s*["\']?false\b)/i',
			(string) $tag
		);
	}

	// =========================================================================
	// Simple delay (existing behaviour)
	// =========================================================================

	/**
	 * Convert an enqueued <script src="…"> tag to a text/plain placeholder.
	 *
	 * @param  string $tag    The full <script> HTML tag.
	 * @param  string $handle Registered script handle.
	 * @param  string $src    Script source URL.
	 * @return string         Modified (or original) tag.
	 */
	public static function delay_scripts( $tag, $handle, $src ) {
		$never_delay = array( 'jquery', 'jquery-core', 'jquery-migrate', 'wc-cart-fragments', 'bepluspb-delay-js', 'bepluspb-lazy-fallback' );
		if ( in_array( $handle, $never_delay, true ) ) {
			return $tag;
		}

		if ( empty( $src ) || false === strpos( $tag, ' src=' ) ) {
			return $tag;
		}

		if ( false !== strpos( $tag, 'data-bepluspb-delay' ) ) {
			return $tag;
		}

		if ( self::has_opt_out_attr( $tag ) || self::is_builtin_excluded( $src ) || self::is_builtin_excluded( $handle ) ) {
			return $tag;
		}

		// An inline script attached with wp_add_inline_script( ..., 'after' ) runs
		// immediately (inline scripts are never delayed in Simple mode). Delaying
		// the file it relies on, or one of that file's dependencies, would make it
		// throw "X is not defined" and leave forms/AJAX widgets uninitialised.
		if ( in_array( $handle, self::inline_after_dependencies(), true ) ) {
			return $tag;
		}

		// The list above is built once per request; an 'after' block added later
		// (for example by a shortcode, for a footer script) is caught here.
		if ( function_exists( 'wp_scripts' ) && wp_scripts()->get_data( $handle, 'after' ) ) {
			return $tag;
		}

		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		foreach ( $exclude as $keyword ) {
			if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
				return $tag;
			}
		}

		$tag = preg_replace_callback(
			'/<script([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>/i',
			function ( $m ) use ( $handle ) {
				$before = $m[1];
				$src    = $m[3];
				$after  = $m[4];

				$original_type = null;
				if ( preg_match( '/\s+type=["\']([^"\']*)["\']/', $before . $after, $type_match ) ) {
					$original_type = $type_match[1];
				}

				$before = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $before );
				$after  = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $after );

				$type_data_attr = ( null !== $original_type && '' !== $original_type )
					? ' data-bepluspb-type="' . esc_attr( $original_type ) . '"'
					: '';

				return '<script' . $before . $after
					. ' type="text/plain"'
					. ' data-bepluspb-delay="1"'
					. ' data-bepluspb-handle="' . esc_attr( $handle ) . '"'
					. ' data-bepluspb-src="' . esc_url( $src ) . '"'
					. $type_data_attr
					. '>';
			},
			$tag
		);

		return $tag;
	}

	/**
	 * Handles that must not be delayed in Simple mode: every script carrying an
	 * inline 'after' block, plus all of their (recursive) dependencies.
	 *
	 * @return string[]
	 */
	private static function inline_after_dependencies() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		if ( ! function_exists( 'wp_scripts' ) ) {
			return $cache;
		}

		$scripts = wp_scripts();
		if ( empty( $scripts->registered ) || ! is_array( $scripts->registered ) ) {
			return $cache;
		}
		$keep  = array();
		$visit = function ( $handle ) use ( &$visit, &$keep, $scripts ) {
			if ( isset( $keep[ $handle ] ) || empty( $scripts->registered[ $handle ] ) ) {
				return;
			}
			$keep[ $handle ] = true;
			foreach ( (array) $scripts->registered[ $handle ]->deps as $dep ) {
				$visit( $dep );
			}
		};
		foreach ( array_keys( $scripts->registered ) as $handle ) {
			if ( $scripts->get_data( $handle, 'after' ) ) {
				$visit( $handle );
			}
		}
		$cache = array_keys( $keep );
		return $cache;
	}

	/**
	 * Enqueue the simple delay loader script with the exclude list.
	 */
	public static function enqueue_delay_script() {
		$opts    = bepluspb_get_options();
		$exclude = bepluspb_parse_exclude_list( $opts['js_exclude'] );

		wp_enqueue_script(
			'bepluspb-delay-js',
			BEPLUSPB_PLUGIN_URL . 'assets/js/delay.js',
			array(),
			BEPLUSPB_VERSION,
			true
		);

		wp_localize_script(
			'bepluspb-delay-js',
			'bepluspbDelayConfig',
			array( 'skip' => array_values( $exclude ) )
		);
	}

	// =========================================================================
	// Advanced delay — output-buffer approach
	// =========================================================================

	/**
	 * Start the output buffer for the advanced delay rewriter.
	 * Hooks into template_redirect at maximum priority so it runs last,
	 * wrapping all other output — meaning its buffer is innermost and must
	 * be closed FIRST on shutdown (see advanced_buffer_end()).
	 *
	 * @param array $opts Plugin options.
	 */
	public static function init_advanced_delay( $opts ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- kept for call-site symmetry with the other init_*($opts) registration methods; this one doesn't need $opts itself.
		add_action( 'template_redirect', array( __CLASS__, 'advanced_buffer_start' ), PHP_INT_MAX );
		// Lowest possible priority: this buffer is innermost, so it must
		// flush before any outer feature's own shutdown buffer_end() runs.
		add_action( 'shutdown', array( __CLASS__, 'advanced_buffer_end' ), -PHP_INT_MAX );
	}

	/**
	 * Open the output buffer.
	 * Bails for REST, JSON, login page, AMP, and page-builder preview contexts.
	 */
	public static function advanced_buffer_start() {
		self::$buffer_level = null;

		if ( BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}

		self::$buffer_level = ob_get_level();
		ob_start( array( __CLASS__, 'advanced_rewrite' ) );
	}

	/**
	 * Close the advanced-delay output buffer explicitly on shutdown, at the
	 * lowest possible priority so it always runs BEFORE any outer feature's
	 * own shutdown buffer_end() (UCSS, HTML, CDN all open their plain
	 * ob_start() earlier, during template_redirect, so this buffer is
	 * always innermost and must be the first one flushed — see the
	 * $buffer_level docblock above for the failure mode this prevents).
	 *
	 * Only closes the exact buffer level this class itself opened, mirroring
	 * the same-pattern guard used by BEPLUSPB_HTML/BEPLUSPB_CDN/BEPLUSPB_UCSS,
	 * so it never accidentally flushes a buffer opened by another plugin.
	 */
	public static function advanced_buffer_end() {
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			if ( null !== self::$buffer_level && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional WP_DEBUG_LOG diagnostic, not left-over debugging; helps diagnose a future conflict with another plugin's own output buffering (see CHANGELOG 1.0.10).
				error_log(
					sprintf(
						'[BEPLUSPB] Delay JS (advanced): expected to close output buffer at level %d but current level is %d — another plugin/theme may have opened or closed an output buffer unexpectedly; delayed scripts on this page may not have been rewritten.',
						(int) self::$buffer_level + 1,
						ob_get_level()
					)
				);
			}
			self::$buffer_level = null;
			return;
		}

		self::$buffer_level = null;

		// ob_end_flush() (not ob_get_clean()) so the advanced_rewrite()
		// callback's return value is what actually reaches the next buffer
		// level / the client, instead of being discarded.
		ob_end_flush();
	}

	/**
	 * Output-buffer callback: inject the runtime and rewrite all <script> tags.
	 *
	 * Injects the config + runtime before the first <script>, then does four
	 * passes over the HTML to block and later restore every script tag.
	 *
	 * @param  string $buffer The full HTML page output.
	 * @return string Rewritten HTML (or original if not applicable).
	 */
	public static function advanced_rewrite( $buffer ) {
		return BEPLUSPB_Utils::guard_transform( self::rewrite_scripts( $buffer ), $buffer );
	}

	/**
	 * Do the actual rewrite for advanced_rewrite() (which guards the result).
	 *
	 * @param  string $buffer The full HTML page output.
	 * @return string
	 */
	private static function rewrite_scripts( $buffer ) {
		// Only rewrite text/html responses.
		foreach ( headers_list() as $header ) {
			if ( preg_match( '/^content-type/i', $header )
				&& ! preg_match( '/^content-type\s*:\s*text\/html/i', $header ) ) {
				return $buffer;
			}
		}

		// Must look like an HTML page.
		if ( empty( $buffer ) || false === stripos( $buffer, '<html' ) ) {
			return $buffer;
		}

		// Load the runtime JS.
		$script_path = BEPLUSPB_PLUGIN_DIR . 'assets/js/delay-advanced.js';
		if ( ! file_exists( $script_path ) ) {
			return $buffer;
		}

		// Scripts inside <noscript>, <template>, <textarea>, comments (conditional comments for old IE) and
		// attribute values are not scripts of this page: rewriting them blocked templates that are cloned later,
		// changed what a textarea shows, and could even put the runtime inside a comment.
		$inert  = array();
		$buffer = self::protect_inert_markup( $buffer, $inert );

		$runtime_js = file_get_contents( $script_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Build the _bepluspb config object passed to the runtime.
		$opts   = bepluspb_get_options();
		$rdelay = isset( $opts['js_delay_rdelay'] ) ? (int) $opts['js_delay_rdelay'] : 0;

		$config = array(
			'rdelay'     => $rdelay,
			'preconnect' => true,
			'v'          => BEPLUSPB_VERSION,
		);

		// Elementor starts entrance animations from its own (delayed) script, so first-screen elements would stay
		// hidden until the first interaction: the runtime plays them on load instead (WP Meteor does the same).
		$elementor = defined( 'ELEMENTOR_VERSION' );
		if ( function_exists( 'apply_filters' ) ) {
			$elementor = (bool) apply_filters( 'bepluspb_delay_js_elementor_animations', $elementor );
		}
		if ( $elementor ) {
			$config['elementor-animations'] = true;
		}

		$config_script  = '<script data-bepluspb-nooptimize="true">var _bepluspb=' . wp_json_encode( $config ) . ';</script>';
		$runtime_script = '<script data-bepluspb-nooptimize="true">' . $runtime_js . '</script>';
		$inject         = $config_script . $runtime_script;

		// Inject before the very first <script> tag in the page.
		$buffer = preg_replace( '/(<script\b)/i', $inject . '$1', $buffer, 1 );

		// -----------------------------------------------------------------
		// Rewrite all <script> tags to block execution until first interaction.
		// -----------------------------------------------------------------

		$exclude      = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		$delimiter    = 'BEPLUSPB' . wp_generate_password( 16, false );
		$replacements = array();

		// ------------------------------------------------------------------
		// Pass 1 — walk every <script>…</script> block.
		// • Inline scripts: stash their content behind a placeholder so the
		// regex in Pass 2 doesn't accidentally mangle it.  Mark excluded
		// inline scripts with data-bepluspb-nooptimize="true" so Pass 2
		// leaves their opening tag alone.
		// • External scripts (have src=): left for Pass 2 to handle.
		// ------------------------------------------------------------------
		$search_offset = 0;
		while ( preg_match( '/<script\b[^>]*?>/is', $buffer, $matches, PREG_OFFSET_CAPTURE, $search_offset ) ) {
			$offset        = $matches[0][1];
			$search_offset = $offset + 1;

			if ( ! preg_match( '/<\/\s*script>/is', $buffer, $end_matches, PREG_OFFSET_CAPTURE, $offset ) ) {
				continue;
			}

			$tag_str     = $matches[0][0];
			$closing_str = $end_matches[0][0];
			$block_len   = $end_matches[0][1] - $offset + strlen( $closing_str );

			$has_src       = (bool) preg_match( '/\s+src=/i', $tag_str );
			$has_type      = (bool) preg_match( '/\s+type=/i', $tag_str );
			$is_js_type    = ! $has_type || (bool) preg_match(
				'/\s+type=([\'"])((application|text)\/(javascript|ecmascript|html|template)|module)\1/i',
				$tag_str
			);
			$is_nooptimize = (bool) preg_match( '/data-bepluspb-nooptimize="true"/i', $tag_str );

			// Only stash inline JS blocks (no src, valid JS type).
			if ( $is_js_type && ! $has_src ) {
				$content_start  = $offset + strlen( $tag_str );
				$content_length = $end_matches[0][1] - $content_start;
				$content        = substr( $buffer, $content_start, $content_length );

				// Conventional opt-out attributes and the built-in never-delay list.
				if ( ! $is_nooptimize && ( self::has_opt_out_attr( $tag_str ) || self::is_builtin_excluded( $content ) ) ) {
					$tag_str       = preg_replace( '/^<script/i', '<script data-bepluspb-nooptimize="true"', $tag_str );
					$is_nooptimize = true;
				}

				// If not already marked nooptimize, check if content matches an exclusion.
				if ( ! $is_nooptimize ) {
					foreach ( $exclude as $keyword ) {
						if ( ! empty( $keyword ) && false !== strpos( $content, $keyword ) ) {
							// Exclude: mark the opening tag so Pass 2 skips it.
							$tag_str       = preg_replace( '/^<script/i', '<script data-bepluspb-nooptimize="true"', $tag_str );
							$is_nooptimize = true;
							break;
						}
					}
				}

				$placeholder    = $tag_str . $delimiter . '[' . count( $replacements ) . ']' . $delimiter . $closing_str;
				$replacements[] = $content;
				$buffer         = substr_replace( $buffer, $placeholder, $offset, $block_len );
			}
		}

		// ------------------------------------------------------------------
		// Pass 2 — transform every <script …> opening tag.
		// • Skip our own injected runtime (data-bepluspb-nooptimize="true").
		// • Skip non-JS type attributes (type="text/template", etc.).
		// • Rename src → data-bepluspb-src.
		// • Change type → javascript/blocked, store original in data-bepluspb-type.
		// ------------------------------------------------------------------
		$buffer = preg_replace_callback(
			'/<script\b[^>]*?>/is',
			function ( $m ) use ( $exclude ) {
				$tag = $m[0];

				// Our own runtime scripts — leave untouched.
				if ( preg_match( '/data-bepluspb-nooptimize="true"/i', $tag ) ) {
					return $tag;
				}

				// Already transformed.
				if ( preg_match( '/data-bepluspb-src=/i', $tag )
					|| preg_match( '/data-bepluspb-type=/i', $tag ) ) {
					return $tag;
				}

				// Other plugins' opt-out attributes, and a built-in never-delay id (consent banners...).
				if ( self::has_opt_out_attr( $tag ) ) {
					return $tag;
				}
				if ( preg_match( '/\sid=([\'"])(.*?)\1/i', $tag, $idm ) && self::is_builtin_excluded( $idm[2] ) ) {
					return $tag;
				}

				// Detect type attribute and whether it is JS.
				$has_type   = (bool) preg_match( '/\s+type=/i', $tag );
				$is_js_type = ! $has_type
					|| (bool) preg_match( '/\s+type=([\'"])((application|text)\/(javascript|ecmascript)|module)\1/i', $tag )
					|| (bool) preg_match( '/\s+type=((application|text)\/(javascript|ecmascript)|module)/i', $tag );

				if ( ! $is_js_type ) {
					return $tag;
				}

				// Extract src (with or without quotes).
				$src = null;
				if ( preg_match( '/\s+src=([\'"])(.*?)\1/i', $tag, $sm ) ) {
					$src = $sm[2];
				} elseif ( preg_match( '/\s+src=([\S]+)/i', $tag, $sm ) ) {
					$src = $sm[1];
				}

				// Check exclusions for external scripts.
				if ( $src ) {
					foreach ( $exclude as $keyword ) {
						if ( ! empty( $keyword ) && false !== strpos( $src, $keyword ) ) {
							return $tag;
						}
					}
					if ( self::is_builtin_excluded( $src ) ) {
						return $tag;
					}
					// Rename src → data-bepluspb-src.
					$tag = preg_replace( '/(\s+)src=/i', '$1data-bepluspb-src=', $tag );
				}

				// Change type to javascript/blocked, preserving original in data-bepluspb-type.
				if ( $has_type ) {
					$tag = preg_replace(
						'/\s+type=([\'"])module\1/i',
						' type="javascript/blocked" data-bepluspb-type="module" ',
						$tag
					);
					$tag = preg_replace(
						'/\s+type=module\b/i',
						' type="javascript/blocked" data-bepluspb-type="module" ',
						$tag
					);
					$tag = preg_replace(
						'/\s+type=([\'"])(application|text)\/(javascript|ecmascript)\1/i',
						' type="javascript/blocked" data-bepluspb-type="$2/$3" ',
						$tag
					);
					$tag = preg_replace(
						'/\s+type=(application|text)\/(javascript|ecmascript)\b/i',
						' type="javascript/blocked" data-bepluspb-type="$1/$2" ',
						$tag
					);
				} else {
					$tag = preg_replace(
						'/^<script/i',
						'<script type="javascript/blocked" data-bepluspb-type="text/javascript" ',
						$tag
					);
				}

				return $tag;
			},
			$buffer
		);

		// ------------------------------------------------------------------
		// Pass 3 — intercept onload / onerror inline handlers on html, body,
		// img, and iframe elements so the runtime can replay them correctly.
		// Runs while inline-script bodies are still stashed so markup inside JS
		// strings/templates is never rewritten.
		// ------------------------------------------------------------------
		$event_name = 'fpo:element-loaded';
		$buffer     = preg_replace_callback(
			'/<(html|body|img|iframe)\b[^>]*?>/is',
			function ( $m ) use ( $event_name ) {
				$result = $m[0];

				// Avoid double-processing on repeated buffer calls.
				if ( preg_match( '/data-bepluspb-onload=/', $result ) ) {
					return $result;
				}

				$dispatch = 'window.dispatchEvent(new CustomEvent(\'' . $event_name . '\','
					. '{detail:{event:event,target:this}}))';

				$result = preg_replace(
					'/\s+onload=/i',
					' onload="' . $dispatch . '" data-bepluspb-onload=',
					$result
				);
				$result = preg_replace(
					'/\s+onerror=/i',
					' onerror="' . $dispatch . '" data-bepluspb-onerror=',
					$result
				);

				return $result;
			},
			$buffer
		);

		// ------------------------------------------------------------------
		// Pass 4 — restore stashed inline-script content.
		// ------------------------------------------------------------------
		$buffer = preg_replace_callback(
			'/' . preg_quote( $delimiter, '/' ) . '\[(\d+)\]' . preg_quote( $delimiter, '/' ) . '/',
			function ( $m ) use ( &$replacements ) {
				return $replacements[ (int) $m[1] ];
			},
			$buffer
		);

		return $inert ? strtr( $buffer, $inert ) : $buffer;
	}

	/**
	 * Hide markup that merely contains script tags: noscript/template/textarea blocks, comments, and tags whose
	 * attribute values hold markup. Real <script>/<style> blocks are matched first, so a "<!--" inside a script
	 * never starts a "comment". Restore with strtr() (tokens nested inside a protected tag are put back first).
	 *
	 * @param  string $buffer Page.
	 * @param  array  $map    Token => original (by reference).
	 * @return string
	 */
	private static function protect_inert_markup( $buffer, &$map ) {
		if ( false !== strpos( $buffer, "\x1a" ) ) {
			return $buffer; // Our token byte is in the page: leave it as it was.
		}
		$out = preg_replace_callback(
			'#<(script|style|noscript|template|textarea)\b[^>]*+>.*?</\1\s*>|<!--.*?-->#is',
			function ( $m ) use ( &$map ) {
				if ( isset( $m[1] ) && in_array( strtolower( $m[1] ), array( 'script', 'style' ), true ) ) {
					return $m[0];
				}
				$token         = "\x1aBPJ" . count( $map ) . "\x1a";
				$map[ $token ] = $m[0];
				return $token;
			},
			$buffer
		);
		if ( null === $out ) {
			return $buffer;
		}
		$tagged = preg_replace_callback(
			'#<(?!script\b|style\b)[a-z][a-z0-9-]*+\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#i',
			function ( $m ) use ( &$map ) {
				$original = strtr( $m[0], $map );
				if ( false === strpos( $original, '<', 1 ) ) {
					return $m[0];
				}
				$token         = "\x1aBPJ" . count( $map ) . "\x1a";
				$map[ $token ] = $original;
				return $token;
			},
			$out
		);
		return null === $tagged ? $out : $tagged;
	}
}
