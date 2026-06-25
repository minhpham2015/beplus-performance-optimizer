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

		if ( $opts['js_delay'] ) {
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

		return str_replace( ' src=', ' defer src=', $tag );
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

				$before = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $before );
				$after  = preg_replace( '/\s*type=["\'][^"\']*["\']/', '', $after );

				return '<script' . $before . $after
					. ' type="text/plain"'
					. ' data-bepluspb-delay="1"'
					. ' data-bepluspb-handle="' . esc_attr( $handle ) . '"'
					. ' data-bepluspb-src="' . esc_url( $src ) . '"'
					. '>';
			},
			$tag
		);

		return $tag;
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
	 * wrapping all other output.
	 *
	 * @param array $opts Plugin options.
	 */
	public static function init_advanced_delay( $opts ) {
		add_action( 'template_redirect', array( __CLASS__, 'advanced_buffer_start' ), PHP_INT_MAX );
	}

	/**
	 * Open the output buffer.
	 * Bails for REST, JSON, login page, AMP, and page-builder preview contexts.
	 */
	public static function advanced_buffer_start() {
		// REST API / JSON responses must not be buffered.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		// Login page.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return;
		}

		// Page-builder editor / preview frames — don't delay inside the editor.
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
				return;
			}
		}

		// Elementor editor / preview.
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance ) {
			$editor  = \Elementor\Plugin::$instance->editor;
			$preview = \Elementor\Plugin::$instance->preview;
			if ( ( $editor && $editor->is_edit_mode() ) || ( $preview && $preview->is_preview_mode() ) ) {
				return;
			}
		}

		// AMP.
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return;
		}
		if ( function_exists( 'ampforwp_is_amp_endpoint' ) && ampforwp_is_amp_endpoint() ) {
			return;
		}

		ob_start( array( __CLASS__, 'advanced_rewrite' ) );
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
		$runtime_js = file_get_contents( $script_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// Build the _bepluspb config object passed to the runtime.
		$opts   = bepluspb_get_options();
		$rdelay = isset( $opts['js_delay_rdelay'] ) ? (int) $opts['js_delay_rdelay'] : 0;

		$config = array(
			'rdelay'     => $rdelay,
			'preconnect' => true,
			'v'          => BEPLUSPB_VERSION,
		);

		$config_script  = '<script data-bepluspb-nooptimize="true">var _bepluspb=' . wp_json_encode( $config ) . ';</script>';
		$runtime_script = '<script data-bepluspb-nooptimize="true">' . $runtime_js . '</script>';
		$inject         = $config_script . $runtime_script;

		// Inject before the very first <script> tag in the page.
		$buffer = preg_replace( '/(<script\b)/i', $inject . '$1', $buffer, 1 );

		// -----------------------------------------------------------------
		// Rewrite all <script> tags to block execution until first interaction.
		// -----------------------------------------------------------------

		$exclude   = bepluspb_parse_exclude_list( $opts['js_exclude'] );
		$delimiter = 'BEPLUSPB' . wp_generate_password( 16, false );
		$replacements = array();

		// ------------------------------------------------------------------
		// Pass 1 — walk every <script>…</script> block.
		//   • Inline scripts: stash their content behind a placeholder so the
		//     regex in Pass 2 doesn't accidentally mangle it.  Mark excluded
		//     inline scripts with data-bepluspb-nooptimize="true" so Pass 2
		//     leaves their opening tag alone.
		//   • External scripts (have src=): left for Pass 2 to handle.
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

			$has_src        = (bool) preg_match( '/\s+src=/i', $tag_str );
			$has_type       = (bool) preg_match( '/\s+type=/i', $tag_str );
			$is_js_type     = ! $has_type || (bool) preg_match(
				'/\s+type=([\'"])((application|text)\/(javascript|ecmascript|html|template)|module)\1/i',
				$tag_str
			);
			$is_nooptimize  = (bool) preg_match( '/data-bepluspb-nooptimize="true"/i', $tag_str );

			// Only stash inline JS blocks (no src, valid JS type).
			if ( $is_js_type && ! $has_src ) {
				$content_start  = $offset + strlen( $tag_str );
				$content_length = $end_matches[0][1] - $content_start;
				$content        = substr( $buffer, $content_start, $content_length );

				// If not already marked nooptimize, check if content matches an exclusion.
				if ( ! $is_nooptimize ) {
					foreach ( $exclude as $keyword ) {
						if ( ! empty( $keyword ) && false !== strpos( $content, $keyword ) ) {
							// Exclude: mark the opening tag so Pass 2 skips it.
							$tag_str      = preg_replace( '/^<script/i', '<script data-bepluspb-nooptimize="true"', $tag_str );
							$is_nooptimize = true;
							break;
						}
					}
				}

				$placeholder = $tag_str . $delimiter . '[' . count( $replacements ) . ']' . $delimiter . $closing_str;
				$replacements[] = $content;
				$buffer = substr_replace( $buffer, $placeholder, $offset, $block_len );
			}
		}

		// ------------------------------------------------------------------
		// Pass 2 — transform every <script …> opening tag.
		//   • Skip our own injected runtime (data-bepluspb-nooptimize="true").
		//   • Skip non-JS type attributes (type="text/template", etc.).
		//   • Rename src → data-bepluspb-src.
		//   • Change type → javascript/blocked, store original in data-bepluspb-type.
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
		// Pass 3 — restore stashed inline-script content.
		// ------------------------------------------------------------------
		$buffer = preg_replace_callback(
			'/' . preg_quote( $delimiter, '/' ) . '\[(\d+)\]' . preg_quote( $delimiter, '/' ) . '/',
			function ( $m ) use ( &$replacements ) {
				return $replacements[ (int) $m[1] ];
			},
			$buffer
		);

		// ------------------------------------------------------------------
		// Pass 4 — intercept onload / onerror inline handlers on html, body,
		// img, and iframe elements so the runtime can replay them correctly.
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

		return $buffer;
	}
}
