<?php
/**
 * Critical CSS: the CSS an above-the-fold render needs, stored per page type.
 *
 * With Non-Render-Blocking CSS the full stylesheets load after the first paint; the critical
 * CSS printed in the <head> is what draws the page meanwhile. This class owns the data only:
 * which page type the current request is, what is stored for it, and what may be stored. It
 * never fetches anything. The CSS comes from the administrator (typed in, or generated in the
 * administrator's browser by assets/js/critical-css-generator.js) and is cleaned before it is
 * stored AND is printed as inert text inside a <style> element.
 *
 * Storage: one non-autoloaded option (`bepluspb_critical_css`): key => { css, bytes, updated, source }.
 * It is user data, so purging the CSS/JS cache never removes it.
 *
 * Keys are page types, not URLs, so one entry serves every page of that type and every language:
 * `front`, `blog`, `single-{post type}`, `archive`, `search`, `other`, or `default` (the fallback
 * for any type without its own entry). Right-to-left sites get their own set (`…:rtl`): the RTL
 * stylesheet differs from the LTR one.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Critical_CSS
 */
class BEPLUSPB_Critical_CSS {

	/** Option holding every entry. */
	const OPTION = 'bepluspb_critical_css';

	/** Largest CSS accepted per entry (bytes). Critical CSS is normally 10-40 KB. */
	const MAX_BYTES = 150000;

	/** Most entries stored. */
	const MAX_ENTRIES = 40;

	/** Valid keys. */
	const KEY_PATTERN = '/^(?:default|front|blog|search|archive|other|single-[a-z0-9_-]{1,40})(?::rtl)?$/D';

	/**
	 * Per-request copy of the stored entries (null = not loaded).
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Forget the per-request copy.
	 */
	public static function reset() {
		self::$cache = null;
	}

	/**
	 * Whether $key is an allowed entry key.
	 *
	 * @param  mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( self::KEY_PATTERN, $key );
	}

	/**
	 * Page-type key of the request being rendered.
	 *
	 * @return string
	 */
	public static function current_key() {
		$dir = is_rtl() ? ':rtl' : '';
		if ( is_front_page() ) {
			$type = 'front';
		} elseif ( is_home() ) {
			$type = 'blog';
		} elseif ( is_singular() ) {
			$type = 'single-' . substr( sanitize_key( (string) get_post_type() ), 0, 40 );
		} elseif ( is_search() ) {
			$type = 'search';
		} elseif ( is_archive() ) {
			$type = 'archive';
		} else {
			$type = 'other';
		}
		$key = $type . $dir;
		return self::is_valid_key( $key ) ? $key : 'other' . $dir;
	}

	/**
	 * Human-readable name of a key.
	 *
	 * @param  string $key Entry key.
	 * @return string
	 */
	public static function key_label( $key ) {
		$rtl  = ' (RTL)';
		$base = preg_replace( '/:rtl$/', '', (string) $key );
		$map  = array(
			'default' => __( 'Default (every page type without its own entry)', 'beplus-performance-booster' ),
			'front'   => __( 'Front page', 'beplus-performance-booster' ),
			'blog'    => __( 'Blog index', 'beplus-performance-booster' ),
			'search'  => __( 'Search results', 'beplus-performance-booster' ),
			'archive' => __( 'Archives (category, tag, taxonomy, date, author)', 'beplus-performance-booster' ),
			'other'   => __( 'Other pages', 'beplus-performance-booster' ),
		);
		if ( isset( $map[ $base ] ) ) {
			$label = $map[ $base ];
		} elseif ( 0 === strpos( $base, 'single-' ) ) {
			/* translators: %s: post type name. */
			$label = sprintf( __( 'Single: %s', 'beplus-performance-booster' ), substr( $base, 7 ) );
		} else {
			$label = $base;
		}
		return $label . ( ':rtl' === substr( (string) $key, -4 ) ? $rtl : '' );
	}

	/**
	 * Every stored entry (validated shape).
	 *
	 * @return array key => { css, bytes, updated, source }
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored      = get_option( self::OPTION, array() );
		self::$cache = array();
		if ( ! is_array( $stored ) ) {
			return self::$cache;
		}
		foreach ( $stored as $key => $entry ) {
			// sanitize_css() never lets a "<" through: one here means the option was edited behind our back.
			if ( self::is_valid_key( $key ) && is_array( $entry ) && isset( $entry['css'] ) && is_string( $entry['css'] ) && '' !== $entry['css'] && false === strpos( $entry['css'], '<' ) ) {
				self::$cache[ $key ] = array(
					'css'     => $entry['css'],
					'bytes'   => strlen( $entry['css'] ),
					'updated' => isset( $entry['updated'] ) ? (int) $entry['updated'] : 0,
					'source'  => isset( $entry['source'] ) && 'generated' === $entry['source'] ? 'generated' : 'manual',
				);
			}
		}
		return self::$cache;
	}

	/**
	 * CSS to print for the current request: its page type's entry, else the default one.
	 *
	 * @return string '' when nothing is stored.
	 */
	public static function css_for_request() {
		$entries = self::all();
		if ( ! $entries ) {
			return '';
		}
		$key = self::current_key();
		$dir = ':rtl' === substr( $key, -4 ) ? ':rtl' : '';
		foreach ( array( $key, 'default' . $dir ) as $candidate ) {
			if ( isset( $entries[ $candidate ] ) ) {
				return $entries[ $candidate ]['css'];
			}
		}
		return '';
	}

	/**
	 * Clean CSS for storage and for printing inside <style>.
	 *
	 * Removes `<` (nothing can close the element or open a tag), `@import`/`@charset`,
	 * script-ish constructs, minifies, and requires balanced braces and a size within limits.
	 *
	 * @param  mixed $raw CSS typed in or generated.
	 * @return array { css: string } or { error: string }
	 */
	public static function sanitize_css( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES * 3 ) {
			return array( 'error' => __( 'The CSS is missing or far too large.', 'beplus-performance-booster' ) );
		}
		$css = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $raw );
		$css = str_replace( '<', '', $css );
		$css = (string) preg_replace( '/@(?:import|charset)\b[^;{}]*;?/i', '', $css );
		$css = (string) preg_replace( '/expression\s*\(|javascript\s*:|vbscript\s*:|-moz-binding|behavior\s*:/i', '', $css );
		if ( class_exists( 'BEPLUSPB_Minify' ) ) {
			$css = BEPLUSPB_Minify::minify_css( $css );
		}
		$css = trim( $css );

		if ( '' === $css ) {
			return array( 'error' => __( 'The CSS is empty.', 'beplus-performance-booster' ) );
		}
		if ( false === strpos( $css, '{' ) ) {
			return array( 'error' => __( 'The CSS holds no rules.', 'beplus-performance-booster' ) );
		}
		if ( ! self::braces_balanced( $css ) ) {
			return array( 'error' => __( 'The CSS has unbalanced braces.', 'beplus-performance-booster' ) );
		}
		if ( strlen( $css ) > self::MAX_BYTES ) {
			/* translators: %d: maximum size in kilobytes. */
			return array( 'error' => sprintf( __( 'The CSS is larger than %d KB. Critical CSS should only hold what the first screen needs.', 'beplus-performance-booster' ), (int) ( self::MAX_BYTES / 1000 ) ) );
		}
		return array( 'css' => $css );
	}

	/**
	 * Whether every `{` has its `}` (strings and escapes are skipped).
	 *
	 * @param  string $css CSS.
	 * @return bool
	 */
	private static function braces_balanced( $css ) {
		$depth  = 0;
		$quote  = '';
		$length = strlen( $css );
		for ( $i = 0; $i < $length; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
				continue;
			}
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				if ( --$depth < 0 ) {
					return false;
				}
			}
		}
		return 0 === $depth && '' === $quote;
	}

	/**
	 * Store (or replace) the entry of a page type.
	 *
	 * @param  mixed  $key    Entry key.
	 * @param  mixed  $css    CSS.
	 * @param  string $source 'manual' or 'generated'.
	 * @return array { ok: true, bytes: int } or { error: string }
	 */
	public static function save( $key, $css, $source = 'manual' ) {
		if ( ! self::is_valid_key( $key ) ) {
			return array( 'error' => __( 'Unknown page type.', 'beplus-performance-booster' ) );
		}
		$clean = self::sanitize_css( $css );
		if ( isset( $clean['error'] ) ) {
			return $clean;
		}
		self::reset();
		$entries = self::all();
		if ( ! isset( $entries[ $key ] ) && count( $entries ) >= self::MAX_ENTRIES ) {
			return array( 'error' => __( 'Too many Critical CSS entries. Delete one first.', 'beplus-performance-booster' ) );
		}
		$entries[ $key ] = array(
			'css'     => $clean['css'],
			'bytes'   => strlen( $clean['css'] ),
			'updated' => time(),
			'source'  => 'generated' === $source ? 'generated' : 'manual',
		);
		// Autoload off: up to MAX_ENTRIES x 150 KB must never be read on every request.
		update_option( self::OPTION, $entries, false );
		self::reset();
		return array(
			'ok'    => true,
			'bytes' => strlen( $clean['css'] ),
		);
	}

	/**
	 * Remove the entry of a page type.
	 *
	 * @param  mixed $key Entry key.
	 * @return bool Whether an entry was removed.
	 */
	public static function delete( $key ) {
		self::reset();
		$entries = self::all();
		if ( ! self::is_valid_key( $key ) || ! isset( $entries[ $key ] ) ) {
			return false;
		}
		unset( $entries[ $key ] );
		update_option( self::OPTION, $entries, false );
		self::reset();
		return true;
	}

	/**
	 * Whether any enforced policy stops a plain inline <style> element from applying.
	 *
	 * Governed by style-src-elem, else style-src, else default-src; blocked unless that directive
	 * allows 'unsafe-inline' without a nonce/hash (which make browsers ignore 'unsafe-inline').
	 * No directive = not blocked.
	 *
	 * @param  string[] $policies Policy strings.
	 * @return bool
	 */
	public static function csp_blocks_inline_styles( $policies ) {
		foreach ( (array) $policies as $policy ) {
			$directives = array();
			foreach ( explode( ';', (string) $policy ) as $part ) {
				$tokens = preg_split( '/\s+/', trim( $part ) );
				if ( $tokens && '' !== $tokens[0] ) {
					$name = strtolower( array_shift( $tokens ) );
					if ( ! isset( $directives[ $name ] ) ) {
						$directives[ $name ] = array_map( 'strtolower', $tokens );
					}
				}
			}
			$governing = null;
			foreach ( array( 'style-src-elem', 'style-src', 'default-src' ) as $name ) {
				if ( isset( $directives[ $name ] ) ) {
					$governing = $directives[ $name ];
					break;
				}
			}
			if ( null === $governing ) {
				continue;
			}
			$value = ' ' . implode( ' ', $governing ) . ' ';
			if ( false === strpos( $value, "'unsafe-inline'" ) || preg_match( "/'nonce-|'sha(?:256|384|512)-/", $value ) ) {
				return true;
			}
		}
		return false;
	}
}
