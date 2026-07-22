<?php
/**
 * Shared utility helpers for Beplus Performance Booster.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Utils
 *
 * Static utility methods shared across the plugin's feature classes.
 */
class BEPLUSPB_Utils {

	// -------------------------------------------------------------------------
	// JavaScript comment stripping
	// -------------------------------------------------------------------------

	/**
	 * Strip single-line (//) and multi-line (/* … * /) comments from a
	 * JavaScript source string while correctly handling:
	 *
	 *  - String literals  (single-quote, double-quote, template literal)
	 *  - Escape sequences inside strings
	 *  - RegExp literals
	 *  - URL protocol slashes  (`http://`, `https://`, `://` patterns)
	 *  - License / copyright blocks  (preserves `/*!` … `* /` when $preserve_license is true)
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
					} else {
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

	// -------------------------------------------------------------------------
	// Output-buffer context guards
	// -------------------------------------------------------------------------

	/**
	 * Whether the current request is one where full-page output buffering
	 * (URL rewriting, script delay, HTML minification, etc.) must be skipped:
	 * REST/JSON responses, the login page, page-builder editor/preview frames,
	 * and AMP endpoints.
	 *
	 * @return bool
	 */
	public static function is_buffer_excluded_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return true;
		}

		// Login page.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return true;
		}

		// Page-builder editor / preview frames.
		$builder_params = array(
			'bricks', 'brizy-edit-iframe', 'builder', 'ct_builder',
			'elementor-preview', 'et_fb', 'fb-edit', 'fl_builder',
			'preview', 'tb-preview', 'tve', 'uxb_iframe',
			'vc_action', 'vc_editable', 'vcv-action', 'wyp_mode',
			'wyp_page_type', 'zionbuilder-preview',
		);
		foreach ( $builder_params as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}

		// Elementor editor / preview.
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance ) {
			$editor  = \Elementor\Plugin::$instance->editor;
			$preview = \Elementor\Plugin::$instance->preview;
			if ( ( $editor && $editor->is_edit_mode() ) || ( $preview && $preview->is_preview_mode() ) ) {
				return true;
			}
		}

		// AMP.
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return true;
		}
		if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
			return true;
		}

		return false;
	}
}
