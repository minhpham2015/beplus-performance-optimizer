<?php
/**
 * Automatic exclusions for popular plugins, themes and third-party scripts.
 *
 * A static list that ships with the plugin (no network access, nothing downloaded): when a listed plugin or
 * theme is active, its scripts/stylesheets are kept out of Minify, Defer, Delay JS and CSS deferral, and its
 * checkout-style pages out of the page cache, exactly as if the administrator had typed the same keywords into
 * the "Exclude JS Files", "Exclude CSS Files" and "Exclude Pages from Cache" boxes. The rules only ever turn an
 * optimisation OFF for the listed files; they never enable anything and never touch the saved settings.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Compat
 */
class BEPLUSPB_Compat {

	const KINDS       = array( 'js', 'css', 'pages' );
	const MIN_KEYWORD = 4;   // A shorter keyword matches almost every URL.
	const MAX_KEYWORD = 200;

	/**
	 * Memoised detection result for the request.
	 *
	 * @var array|null
	 */
	private static $active = null;

	/**
	 * The built-in rules. `plugins` are plugin files (dir/file.php, any one active is enough), `themes` are
	 * theme folder names (parent or child), `always` rules apply without detection (third-party scripts).
	 * `js`/`css` keywords are matched as plain substrings of the file URL, `pages` of the request path.
	 *
	 * @return array id => rule
	 */
	public static function registry() {
		$rules = array(
			'woocommerce'  => array(
				'label'   => 'WooCommerce',
				'plugins' => array( 'woocommerce/woocommerce.php' ),
				'js'      => array( 'woocommerce/assets/js/' ),
				'css'     => array( 'woocommerce/assets/css/' ),
				'pages'   => array( __CLASS__, 'woocommerce_pages' ),
				'note'    => __( 'Cart, checkout and add-to-cart scripts run at once and keep their original styles; the cart, checkout and account pages (under your own page addresses) are never stored by the page cache.', 'beplus-performance-booster' ),
			),
			'elementor'    => array(
				'label'   => 'Elementor',
				'plugins' => array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ),
				'js'      => array( 'elementor/assets/js/', 'elementor-pro/assets/js/' ),
				'css'     => array( 'elementor/assets/css/', 'elementor-pro/assets/css/', '/uploads/elementor/' ),
				'note'    => __( 'Elementor loads, orders and caches its own scripts and stylesheets, so they stay out of minify, defer, delay and CSS deferral.', 'beplus-performance-booster' ),
			),
			'divi'         => array(
				'label'   => 'Divi / Extra / Divi Builder',
				'plugins' => array( 'divi-builder/divi-builder.php' ),
				'themes'  => array( 'divi', 'extra' ),
				'js'      => array( 'themes/Divi/', 'themes/Extra/', 'divi-builder/' ),
				'css'     => array( 'themes/Divi/', 'themes/Extra/', 'divi-builder/', '/et-cache/' ),
				'note'    => __( 'The Divi framework scripts and its generated static CSS depend on their load order and are left as they are.', 'beplus-performance-booster' ),
			),
			'revslider'    => array(
				'label'   => 'Slider Revolution',
				'plugins' => array( 'revslider/revslider.php' ),
				'js'      => array( 'revslider/', 'rs-plugin' ),
				'css'     => array( 'revslider/', 'rs-plugin' ),
				'note'    => __( 'Slider Revolution starts from inline code that expects its scripts to be loaded at once.', 'beplus-performance-booster' ),
			),
			'gravityforms' => array(
				'label'   => 'Gravity Forms',
				'plugins' => array( 'gravityforms/gravityforms.php' ),
				'js'      => array( 'gravityforms/js/', 'gravityforms/assets/js/' ),
				'note'    => __( 'Gravity Forms prints inline code that calls its scripts; delaying or deferring them breaks the form.', 'beplus-performance-booster' ),
			),
			'checkout_js'  => array(
				'label'  => 'Payment and CAPTCHA scripts (Stripe, PayPal, reCAPTCHA, hCaptcha, Turnstile)',
				'always' => true,
				'js'     => array( 'js.stripe.com', 'paypal.com/sdk', 'paypalobjects.com', 'recaptcha', 'hcaptcha.com', 'challenges.cloudflare.com' ),
				'note'   => __( 'Payment forms and CAPTCHA widgets must be ready when the visitor submits, so they are never delayed or deferred.', 'beplus-performance-booster' ),
			),
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the built-in automatic exclusion rules (id => rule). Every keyword is re-validated.
			 *
			 * @param array $rules Rules.
			 */
			$filtered = apply_filters( 'bepluspb_compat_registry', $rules ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
			$rules    = is_array( $filtered ) ? $filtered : $rules;
		}
		$clean = array();
		foreach ( $rules as $id => $rule ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/D', $id ) && is_array( $rule ) ) {
				$clean[ $id ] = $rule;
			}
		}
		return $clean;
	}

	/**
	 * Ids of the rules that apply on this site (detected plugin/theme, or "always").
	 *
	 * @return array
	 */
	public static function detected_ids() {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$plugins = array();
		if ( function_exists( 'get_option' ) ) {
			$plugins = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
				$plugins = array_merge( $plugins, array_map( 'strval', array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) );
			}
		}
		$themes = array();
		foreach ( array( 'get_template', 'get_stylesheet' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$themes[] = strtolower( (string) $fn() );
			}
		}
		$found = array();
		foreach ( self::registry() as $id => $rule ) {
			if ( ! empty( $rule['always'] )
				|| array_intersect( isset( $rule['plugins'] ) ? (array) $rule['plugins'] : array(), $plugins )
				|| array_intersect( isset( $rule['themes'] ) ? array_map( 'strtolower', (array) $rule['themes'] ) : array(), $themes ) ) {
				$found[] = $id;
			}
		}
		self::$active = $found;
		return $found;
	}

	/** Forget the memoised detection (tests, and plugin/theme changes within one request). */
	public static function reset() {
		self::$active = null;
	}

	/**
	 * Ids the administrator switched off ("Skip" boxes).
	 *
	 * @param array $opts Options.
	 * @return array
	 */
	public static function skipped_ids( $opts ) {
		$raw = isset( $opts['auto_exclude_off'] ) ? $opts['auto_exclude_off'] : '';
		return array_values( array_filter( array_map( 'trim', is_array( $raw ) ? $raw : preg_split( '/\R/', (string) $raw ) ) ) );
	}

	/**
	 * Clean the "Skip" checkboxes of the settings form.
	 *
	 * @param mixed $posted Posted value: id => 1 (checkbox names) or a list of ids.
	 * @return string Newline-separated ids of known rules.
	 */
	public static function sanitize_skipped( $posted ) {
		$ids = array();
		if ( is_array( $posted ) ) {
			foreach ( $posted as $key => $value ) {
				$ids[] = is_string( $key ) && ! is_numeric( $key ) ? ( $value ? $key : '' ) : ( is_string( $value ) ? $value : '' );
			}
		}
		$known = array_keys( self::registry() );
		return implode( "\n", array_values( array_unique( array_intersect( array_map( 'sanitize_key', $ids ), $known ) ) ) );
	}

	/**
	 * Whether automatic exclusions are switched on.
	 *
	 * @param array $opts Options.
	 * @return bool
	 */
	public static function is_enabled( $opts ) {
		return ! empty( $opts['auto_exclude'] );
	}

	/**
	 * The rules that are in force: switched on, detected, not skipped.
	 *
	 * @param array $opts Options.
	 * @return array id => rule
	 */
	public static function active_rules( $opts ) {
		if ( ! self::is_enabled( $opts ) ) {
			return array();
		}
		$skipped  = self::skipped_ids( $opts );
		$registry = self::registry();
		$rules    = array();
		foreach ( self::detected_ids() as $id ) {
			if ( isset( $registry[ $id ] ) && ! in_array( $id, $skipped, true ) ) {
				$rules[ $id ] = $registry[ $id ];
			}
		}
		return $rules;
	}

	/**
	 * Keywords the built-in rules add for one kind.
	 *
	 * @param array  $opts Options.
	 * @param string $kind js|css|pages.
	 * @return array
	 */
	public static function automatic( $opts, $kind ) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return array();
		}
		$out = array();
		foreach ( self::active_rules( $opts ) as $rule ) {
			$list = isset( $rule[ $kind ] ) ? $rule[ $kind ] : array();
			if ( is_callable( $list ) ) {
				$list = call_user_func( $list );
			}
			foreach ( (array) $list as $keyword ) {
				$keyword = self::clean_keyword( $keyword, 'pages' === $kind );
				if ( '' !== $keyword ) {
					$out[] = $keyword;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The effective exclusion list: what the administrator typed, then the automatic keywords.
	 *
	 * @param array  $opts Options.
	 * @param string $kind js|css|pages.
	 * @return array
	 */
	public static function exclusions( $opts, $kind ) {
		$key = array(
			'js'    => 'js_exclude',
			'css'   => 'css_exclude',
			'pages' => 'cache_exclude_pages',
		);
		$own = isset( $key[ $kind ], $opts[ $key[ $kind ] ] ) ? bepluspb_parse_exclude_list( $opts[ $key[ $kind ] ] ) : array();
		return array_values( array_unique( array_merge( $own, self::automatic( $opts, $kind ) ) ) );
	}

	/**
	 * One keyword, or an empty string when it is not acceptable. Page keywords must be real paths: a bare "/"
	 * (a shop page set as the front page) would match every URL.
	 *
	 * @param mixed $keyword Raw keyword.
	 * @param bool  $is_path Whether it is a URL path.
	 * @return string
	 */
	public static function clean_keyword( $keyword, $is_path ) {
		if ( ! is_string( $keyword ) ) {
			return '';
		}
		$keyword = trim( $keyword );
		if ( strlen( $keyword ) < self::MIN_KEYWORD || strlen( $keyword ) > self::MAX_KEYWORD || preg_match( '/[\x00-\x1F\x7F<>"\'`\\\\]/', $keyword ) ) {
			return '';
		}
		if ( $is_path && ( '/' !== $keyword[0] || 0 === strpos( $keyword, '//' ) ) ) {
			return '';
		}
		return $keyword;
	}

	/**
	 * The cart, checkout and account pages under the site's own (possibly translated) addresses.
	 *
	 * @return array Paths such as "/gio-hang/".
	 */
	public static function woocommerce_pages() {
		$paths = array();
		if ( function_exists( 'wc_get_page_permalink' ) && function_exists( 'wp_parse_url' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$path = wp_parse_url( (string) call_user_func( 'wc_get_page_permalink', $page ), PHP_URL_PATH );
				if ( is_string( $path ) && '' !== $path ) {
					$paths[] = trailingslashit( $path );
				}
			}
		}
		return $paths;
	}

	/**
	 * What the settings screen shows: every rule with whether it applies here.
	 *
	 * @param array $opts Options.
	 * @return array List of array( id, label, note, detected, skipped, counts ).
	 */
	public static function overview( $opts ) {
		$detected = self::detected_ids();
		$skipped  = self::skipped_ids( $opts );
		$out      = array();
		foreach ( self::registry() as $id => $rule ) {
			$counts = array();
			foreach ( self::KINDS as $kind ) {
				$list = isset( $rule[ $kind ] ) ? $rule[ $kind ] : array();
				if ( is_callable( $list ) ) {
					$list = call_user_func( $list );
				}
				$counts[ $kind ] = count( array_filter( array_map( static fn( $k ) => self::clean_keyword( $k, 'pages' === $kind ), (array) $list ) ) );
			}
			$out[] = array(
				'id'       => $id,
				'label'    => isset( $rule['label'] ) ? (string) $rule['label'] : $id,
				'note'     => isset( $rule['note'] ) ? (string) $rule['note'] : '',
				'detected' => in_array( $id, $detected, true ),
				'skipped'  => in_array( $id, $skipped, true ),
				'counts'   => $counts,
			);
		}
		return $out;
	}
}
