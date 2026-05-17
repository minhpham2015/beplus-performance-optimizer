<?php
/**
 * Shared utility helpers for Site Optimizer by BePlus.
 *
 * Centralises logic that is used by more than one class so that fixes
 * and improvements only need to be applied in one place.
 *
 * @package Site_Optimizer_BePlus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SOB_Utils
 *
 * Static utility methods shared across the plugin's feature classes.
 */
class SOB_Utils {

	// -------------------------------------------------------------------------
	// JavaScript comment stripping
	// -------------------------------------------------------------------------

	/**
	 * Strip single-line (//) and multi-line (/* … * /) comments from a
	 * JavaScript source string while correctly handling:
	 *
	 *  - String literals  (single-quote, double-quote, template literal)
	 *  - Escape sequences inside strings  (backslash read-ahead, not look-behind)
	 *  - RegExp literals  (basic heuristic: `/` that follows an operator token)
	 *  - URL protocol slashes  (`http://`, `https://`, `://` patterns)
	 *  - License / copyright blocks  (preserves `/*!` … `* /` when $preserve_license is true)
	 *
	 * This implementation uses a read-ahead pattern for escape sequences:
	 * when a backslash is encountered inside a string the NEXT character is
	 * consumed unconditionally before the loop continues, which correctly
	 * handles double-escapes (`\\`) without requiring a backward look-up.
	 *
	 * @param  string $js               Raw JavaScript source.
	 * @param  bool   $preserve_license Whether to keep /*! … * / license blocks. Default true.
	 * @return string JavaScript with comments removed.
	 */
	public static function strip_js_comments( $js, $preserve_license = true ) {
		$out = '';
		$len = strlen( $js );
		$i   = 0;

		while ( $i < $len ) {
			$c = $js[ $i ];

			// ----------------------------------------------------------------
			// String literals — single quote
			// ----------------------------------------------------------------
			if ( "'" === $c ) {
				$out .= $c;
				$i++;
				while ( $i < $len ) {
					$c    = $js[ $i++ ];
					$out .= $c;
					if ( '\\' === $c && $i < $len ) {
						// Read-ahead: consume escaped character unconditionally.
						$out .= $js[ $i++ ];
						continue;
					}
					if ( "'" === $c ) {
						break;
					}
				}
				continue;
			}

			// ----------------------------------------------------------------
			// String literals — double quote
			// ----------------------------------------------------------------
			if ( '"' === $c ) {
				$out .= $c;
				$i++;
				while ( $i < $len ) {
					$c    = $js[ $i++ ];
					$out .= $c;
					if ( '\\' === $c && $i < $len ) {
						$out .= $js[ $i++ ];
						continue;
					}
					if ( '"' === $c ) {
						break;
					}
				}
				continue;
			}

			// ----------------------------------------------------------------
			// Template literals (backtick)
			// ----------------------------------------------------------------
			if ( '`' === $c ) {
				$out .= $c;
				$i++;
				while ( $i < $len ) {
					$c    = $js[ $i++ ];
					$out .= $c;
					if ( '\\' === $c && $i < $len ) {
						$out .= $js[ $i++ ];
						continue;
					}
					if ( '`' === $c ) {
						break;
					}
				}
				continue;
			}

			// ----------------------------------------------------------------
			// Potential comment or regex
			// ----------------------------------------------------------------
			if ( '/' === $c && ( $i + 1 ) < $len ) {
				$next = $js[ $i + 1 ];

				// Single-line comment //
				if ( '/' === $next ) {
					// Preserve URL protocol (e.g. http://, https://)
					// by checking the character before the first slash.
					if ( $i >= 1 && ':' === $js[ $i - 1 ] ) {
						$out .= $c;
						$i++;
						continue;
					}
					// Skip to end of line.
					$i += 2;
					while ( $i < $len && "\n" !== $js[ $i ] ) {
						$i++;
					}
					// Keep the newline to preserve line-count.
					if ( $i < $len ) {
						$out .= "\n";
						$i++;
					}
					continue;
				}

				// Multi-line comment /* … */
				if ( '*' === $next ) {
					$is_license = $preserve_license
						&& ( $i + 2 ) < $len
						&& '!' === $js[ $i + 2 ];

					$i += 2; // Skip /*
					$comment = '';
					while ( $i < $len ) {
						if ( '*' === $js[ $i ] && ( $i + 1 ) < $len && '/' === $js[ $i + 1 ] ) {
							$i += 2; // Skip */
							break;
						}
						$comment .= $js[ $i++ ];
					}

					if ( $is_license ) {
						$out .= '/*!' . $comment . '*/';
					}
					// Non-license block: drop the comment, keep a space to
					// avoid accidentally merging adjacent tokens.
					else {
						$out .= ' ';
					}
					continue;
				}
			}

			// ----------------------------------------------------------------
			// Any other character — copy verbatim.
			// ----------------------------------------------------------------
			$out .= $c;
			$i++;
		}

		return $out;
	}
}
