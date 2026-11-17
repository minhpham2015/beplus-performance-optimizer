<?php
/**
 * Google Fonts "Optimize" mode: font-display and preconnect.
 *
 * Works on the buffered <head> output (the same approach as the CSS module), so it covers
 * stylesheets enqueued by WordPress, hardcoded `<link>` tags and `@import` rules inside
 * inline `<style>` blocks:
 *
 *  - adds `display=swap` (or fallback/optional) to fonts.googleapis.com/css and /css2 URLs
 *    that have no `display` parameter yet, so text is drawn with a fallback font instead of
 *    staying invisible until the font arrives (improves FCP/LCP when the LCP is text);
 *  - adds `preconnect` hints for fonts.googleapis.com and fonts.gstatic.com when the page
 *    uses Google Fonts and does not already have them.
 *
 * Nothing is downloaded or stored by the plugin and no request is made: only URLs already in
 * the page are rewritten. Scripts and comments are never touched. A `display` the site
 * already sets is respected, and icon fonts (Material Icons/Symbols, Font Awesome...) are left
 * alone because a swap would flash their ligature names as text.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Google_Fonts
 */
class BEPLUSPB_Google_Fonts {

	/** Accepted `font-display` values. */
	const DISPLAY_VALUES = array( 'swap', 'fallback', 'optional' );

	/** Matches a Google Fonts stylesheet URL (protocol-relative allowed; /icon serves icon fonts). */
	const URL_PATTERN = '#^(?:https?:)?//fonts\.googleapis\.com/(css2?|icon)\?(.+)$#i';

	/**
	 * The ob_get_level() value recorded right before our ob_start().
	 *
	 * @var int|null
	 */
	private static $buffer_level = null;

	/**
	 * Register the head buffer when the mode is "optimize".
	 *
	 * Starts before everything else in wp_head (priority 0) and ends after everything
	 * (10000), so it wraps the CSS module's buffer (priority 1 .. 9999) like a bracket.
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		if ( ! isset( $opts['google_fonts_mode'] ) || 'optimize' !== $opts['google_fonts_mode'] ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'buffer_end' ), 10000 );
	}

	/**
	 * Open the buffer (skipped for editors, previews, REST, login).
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
	 * Close exactly the buffer we opened, transform it and print it.
	 */
	public static function buffer_end() {
		if ( null === self::$buffer_level || ob_get_level() !== self::$buffer_level + 1 ) {
			return;
		}
		$html               = ob_get_clean();
		self::$buffer_level = null;
		if ( false === $html ) {
			return;
		}

		$opts    = bepluspb_get_options();
		$display = isset( $opts['google_fonts_display'] ) ? $opts['google_fonts_display'] : 'swap';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the buffered head, only font URLs/preconnect links changed.
		echo BEPLUSPB_Utils::guard_transform( self::process( $html, $display ), $html );
	}

	/**
	 * Apply font-display and preconnect to a chunk of head HTML.
	 *
	 * @param  string $html    Buffered HTML.
	 * @param  string $display swap, fallback or optional (anything else = swap).
	 * @return string
	 */
	public static function process( $html, $display = 'swap' ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}
		$display = in_array( $display, self::DISPLAY_VALUES, true ) ? $display : 'swap';

		// Scripts and comments are never edited (a loader may build the URL in a string, where
		// HTML entities would be wrong); <style> content is CSS, everything else is markup.
		$parts = preg_split( '#(<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>|<!--.*?-->)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html; // PCRE limit hit on a huge page: leave it alone.
		}

		$seen = false;
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( 0 === stripos( $part, '<script' ) || 0 === strpos( $part, '<!--' ) ) {
				continue;
			}
			if ( 0 === stripos( $part, '<style' ) ) {
				$parts[ $i ] = self::process_style( $part, $display, $seen );
			} else {
				$parts[ $i ] = self::process_markup( $part, $display, $seen );
			}
		}
		$out = implode( '', $parts );

		return $seen ? self::preconnect_tags( $out ) . $out : $out;
	}

	/**
	 * Rewrite Google Fonts URLs inside a <style> block (CSS context: plain "&").
	 *
	 * @param  string $block   The whole <style>…</style>.
	 * @param  string $display font-display value.
	 * @param  bool   $seen    Set to true when a Google Fonts CSS URL is found.
	 * @return string
	 */
	private static function process_style( $block, $display, &$seen ) {
		$result = preg_replace_callback(
			'#(?:https?:)?//fonts\.googleapis\.com/(?:css2?|icon)\?[^\s"\'<>)]*#i',
			static function ( $m ) use ( $display, &$seen ) {
				return self::rewrite_url( $m[0], '&', $display, $seen );
			},
			$block
		);
		return is_string( $result ) ? $result : $block;
	}

	/**
	 * Rewrite Google Fonts URLs in the href of <link> tags (HTML attribute context).
	 *
	 * @param  string $markup  Markup between scripts/styles/comments.
	 * @param  string $display font-display value.
	 * @param  bool   $seen    Set to true when a Google Fonts CSS URL is found.
	 * @return string
	 */
	private static function process_markup( $markup, $display, &$seen ) {
		$result = preg_replace_callback(
			'#<link\b[^>]*>#i',
			static function ( $tag ) use ( $display, &$seen ) {
				return preg_replace_callback(
					'#(\shref\s*=\s*)(["\'])(.*?)\2#is',
					static function ( $m ) use ( $display, &$seen ) {
						// HTML attributes: the separator must be an entity; mimic what the tag already uses.
						$sep = false !== strpos( $m[3], '&#038;' ) ? '&#038;' : '&amp;';
						return $m[1] . $m[2] . self::rewrite_url( $m[3], $sep, $display, $seen ) . $m[2];
					},
					$tag[0]
				);
			},
			$markup
		);
		return is_string( $result ) ? $result : $markup;
	}

	/**
	 * Add `display=` to one Google Fonts CSS URL when it is safe and missing.
	 *
	 * @param  string $url     URL as written in the page.
	 * @param  string $sep     Separator to append with ("&" in CSS, an entity in HTML).
	 * @param  string $display font-display value.
	 * @param  bool   $seen    Set to true when it is a Google Fonts CSS URL.
	 * @return string
	 */
	public static function rewrite_url( $url, $sep, $display, &$seen ) {
		if ( ! preg_match( self::URL_PATTERN, trim( $url ), $m ) ) {
			return $url;
		}
		$seen  = true;
		$query = $m[2];

		// Respect a display the site already chose (also after "&#038;" / "&amp;").
		if ( preg_match( '/(?:^|[&;])display=/i', $query ) ) {
			return $url;
		}
		// Icon fonts render ligature names as text while they load: never swap those.
		if ( preg_match( '/icon|symbol|awesome|glyph/i', $query ) ) {
			return $url;
		}
		// Needs a font family to be a font request at all.
		if ( ! preg_match( '/(?:^|[&;])family=/i', $query ) ) {
			return $url;
		}

		$tail = preg_match( '/(?:&|&amp;|&#038;)$/', $url ) ? '' : $sep;
		return $url . $tail . 'display=' . $display;
	}

	/**
	 * Preconnect links for the Google Fonts hosts the head does not already preconnect to.
	 *
	 * @param  string $html Final head HTML.
	 * @return string Zero, one or two <link> tags (with trailing newline), or ''.
	 */
	private static function preconnect_tags( $html ) {
		$tags = '';
		if ( ! self::has_preconnect( $html, 'fonts.googleapis.com' ) ) {
			$tags .= '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
		}
		if ( ! self::has_preconnect( $html, 'fonts.gstatic.com' ) ) {
			// Font files are fetched with CORS: the connection has to be a CORS one.
			$tags .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
		}
		return $tags;
	}

	/**
	 * Whether a <link rel="preconnect"> for the host already exists (any attribute order/quoting).
	 *
	 * @param  string $html Head HTML.
	 * @param  string $host Host name.
	 * @return bool
	 */
	private static function has_preconnect( $html, $host ) {
		return 1 === preg_match( '#<link\b(?=[^>]*\brel\s*=\s*["\']?preconnect)[^>]*\bhref\s*=\s*["\']?(?:https?:)?//' . preg_quote( $host, '#' ) . '#i', $html );
	}
}
