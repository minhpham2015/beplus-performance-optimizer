<?php
/**
 * HTML output optimization: whitespace minification and comment removal.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_HTML
 *
 * Buffers the entire page output (template_redirect → shutdown) and applies
 * one or more of the following transformations before sending to the browser:
 *
 *  1. Strip inline JS comments  — removes // and block comments from <script>.
 *  2. Strip inline CSS comments — removes block comments from <style>.
 *  3. Strip HTML comments       — removes <!-- ... --> from the markup.
 *  4. Minify HTML whitespace    — collapses redundant spaces/newlines between tags.
 *
 * Processing order is intentional: JS/CSS comment removal runs first so the
 * HTML comment stripper never misidentifies cleaned code, and whitespace
 * minification runs last on fully-cleaned markup.
 *
 * Protected tags — content inside <pre>, <textarea>, <script>, and <style>
 * is always preserved exactly as-is during whitespace minification.
 */
class SOB_HTML {

	/**
	 * Register a full-page output buffer when at least one HTML feature is active.
	 *
	 * @param array $opts Result of sob_get_options().
	 */
	public static function init( $opts ) {
		$any_active = $opts['html_minify']
		           || $opts['html_remove_comments']
		           || $opts['html_remove_js_comments']
		           || $opts['html_remove_css_comments'];

		if ( ! $any_active ) {
			return;
		}

		// Start buffering before the theme template is loaded.
		add_action( 'template_redirect', array( __CLASS__, 'buffer_start' ), 0 );

		// Flush + transform on shutdown (priority 0 = before WP's own shutdown work).
		add_action( 'shutdown', array( __CLASS__, 'buffer_end' ), 0 );
	}

	/**
	 * Open the output buffer.
	 *
	 * Bails early for REST API and JSON requests — wrapping their responses in
	 * an HTML output buffer would corrupt the JSON payload.
	 */
	public static function buffer_start() {
		// REST API requests must never be buffered.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// WordPress AJAX and any other JSON response.
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		ob_start();
	}

	/**
	 * Close the buffer, apply transforms in order, and echo the final HTML.
	 */
	public static function buffer_end() {
		if ( ob_get_level() === 0 ) {
			return;
		}

		$html = ob_get_clean();

		if ( empty( $html ) ) {
			return;
		}

		$opts = sob_get_options();

		if ( $opts['html_remove_js_comments'] ) {
			$html = self::strip_inline_js_comments( $html );
		}

		if ( $opts['html_remove_css_comments'] ) {
			$html = self::strip_inline_css_comments( $html );
		}

		if ( $opts['html_remove_comments'] ) {
			$html = self::strip_html_comments( $html );
		}

		if ( $opts['html_minify'] ) {
			$html = self::minify( $html );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	}

	// -------------------------------------------------------------------------
	// Transform: strip HTML comments
	// -------------------------------------------------------------------------

	/**
	 * Remove HTML comments from the page markup.
	 *
	 * <script> and <style> blocks are protected with unique tokens before
	 * stripping so their contents are never touched.
	 *
	 * Removes:
	 *  - Standard comments: <!-- ... -->
	 *  - IE conditional comments: <!--[if IE]> ... <![endif]-->
	 *  - WordPress generator / theme banner comments
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with comments removed.
	 */
	public static function strip_html_comments( $html ) {
		$placeholders = array();
		$index        = 0;

		// Protect script and style content from comment stripping.
		$html = preg_replace_callback(
			'/<(script|style)[^>]*>[\s\S]*?<\/\1>/i',
			function ( $m ) use ( &$placeholders, &$index ) {
				$token                  = 'SOBHC' . $index . 'END';
				$placeholders[ $token ] = $m[0];
				$index++;
				return $token;
			},
			$html
		);

		$html = preg_replace( '/<!--[\s\S]*?-->/', '', $html );

		foreach ( $placeholders as $token => $original ) {
			$html = str_replace( $token, $original, $html );
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Transform: strip inline JS comments
	// -------------------------------------------------------------------------

	/**
	 * Remove single-line and block comments from every inline <script> block.
	 *
	 * External scripts (those with a src= attribute) are skipped entirely.
	 * String literals (single-quoted, double-quoted, template literals) are
	 * walked character-by-character and never modified.
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with inline script comments removed.
	 */
	public static function strip_inline_js_comments( $html ) {
		return preg_replace_callback(
			'/<script([^>]*)>([\s\S]*?)<\/script>/i',
			function ( $matches ) {
				$attrs = $matches[1];
				$js    = $matches[2];

				// Leave external scripts untouched.
				if ( preg_match( '/\bsrc\s*=/i', $attrs ) ) {
					return $matches[0];
				}

				return '<script' . $attrs . '>' . self::remove_js_comments( $js ) . '</script>';
			},
			$html
		);
	}

	/**
	 * Walk a JS string and strip comments without touching string/regex content.
	 *
	 * Delegates to SOB_Utils::strip_js_comments() so that both the HTML
	 * transformer and the file minifier use identical, tested logic.
	 *
	 * @param  string $js Raw JavaScript source.
	 * @return string JS with comments removed.
	 */
	private static function remove_js_comments( $js ) {
		return SOB_Utils::strip_js_comments( $js, true );
	}

	// -------------------------------------------------------------------------
	// Transform: strip inline CSS comments
	// -------------------------------------------------------------------------

	/**
	 * Remove block comments from every inline <style> block.
	 *
	 * CSS only supports block comments; no line-comment syntax exists in CSS.
	 *
	 * @param  string $html Full page HTML.
	 * @return string HTML with inline CSS comments removed.
	 */
	public static function strip_inline_css_comments( $html ) {
		return preg_replace_callback(
			'/<style([^>]*)>([\s\S]*?)<\/style>/i',
			function ( $matches ) {
				$attrs = $matches[1];
				$css   = preg_replace( '/\/\*[\s\S]*?\*\//', '', $matches[2] );
				return '<style' . $attrs . '>' . $css . '</style>';
			},
			$html
		);
	}

	// -------------------------------------------------------------------------
	// Transform: minify HTML whitespace
	// -------------------------------------------------------------------------

	/**
	 * Collapse redundant whitespace in the HTML output.
	 *
	 * Rules applied:
	 *  - Whitespace sequences between tags reduced to a single space.
	 *  - Leading / trailing whitespace on each line trimmed.
	 *  - Consecutive blank lines collapsed to one newline.
	 *
	 * Content inside <pre>, <textarea>, <script>, and <style> is protected
	 * with unique tokens and restored after the whitespace pass.
	 *
	 * @param  string $html Full page HTML.
	 * @return string Minified HTML.
	 */
	public static function minify( $html ) {
		$protected    = array();
		$index        = 0;
		$protect_tags = 'pre|textarea|script|style';

		$html = preg_replace_callback(
			'/<(' . $protect_tags . ')([^>]*)>([\s\S]*?)<\/\1>/i',
			function ( $m ) use ( &$protected, &$index ) {
				$token               = 'SOBWS' . $index . 'END';
				$protected[ $token ] = $m[0];
				$index++;
				return $token;
			},
			$html
		);

		// Collapse whitespace between tags.
		$html = preg_replace( '/>\s+</', '> <', $html );

		// Trim each line.
		$html = preg_replace( '/^\s+/m', '', $html );
		$html = preg_replace( '/\s+$/m', '', $html );

		// Remove blank lines.
		$html = preg_replace( '/\n{2,}/', "\n", $html );

		foreach ( $protected as $token => $original ) {
			$html = str_replace( $token, $original, $html );
		}

		return $html;
	}
}
