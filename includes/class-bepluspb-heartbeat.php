<?php
/**
 * Heartbeat control: slow down or switch off the WordPress Heartbeat API per location.
 *
 * The Heartbeat sends an admin-ajax.php request every 15-60 seconds from every open wp-admin
 * page, post editor and (when a plugin enqueues it) front-end page. Each request boots
 * WordPress, so many open tabs can keep a small server busy. Nothing here touches the database
 * or sends a request: it only changes the interval WordPress hands to heartbeat.js, or
 * deregisters that script.
 *
 * Locations:
 *  - `dashboard`: every wp-admin page except the editing screens below.
 *  - `editor`: screens that use post locking / autosave / block editors (post.php, post-new.php,
 *    site-editor.php, widgets.php, customize.php). Only "reduce" is offered here: the block editors
 *    list `heartbeat` as a script dependency, so deregistering it would stop them from loading.
 *  - `frontend`: pages visitors and logged-in users see.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Heartbeat
 */
class BEPLUSPB_Heartbeat {

	const MODES        = array( 'default', 'reduce', 'disable' );
	const MIN_INTERVAL = 15;  // heartbeat.js ignores anything outside 15-120 seconds.
	const MAX_INTERVAL = 120;

	/** Wp-admin screens that need the Heartbeat (post lock, autosave, session check) to keep working. */
	const EDITOR_SCREENS = array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php', 'customize.php' );

	/**
	 * Register the hooks for the locations that are not left at "default".
	 *
	 * @param array $opts Result of bepluspb_get_options().
	 */
	public static function init( $opts ) {
		$modes = array(
			'dashboard' => self::mode( $opts, 'heartbeat_dashboard', 'dashboard' ),
			'editor'    => self::mode( $opts, 'heartbeat_editor', 'editor' ),
			'frontend'  => self::mode( $opts, 'heartbeat_frontend', 'frontend' ),
		);
		if ( 'default' === $modes['dashboard'] && 'default' === $modes['editor'] && 'default' === $modes['frontend'] ) {
			return;
		}

		add_filter(
			'heartbeat_settings',
			static function ( $settings ) use ( $modes, $opts ) {
				return self::apply_interval( $settings, $modes[ self::location() ], self::interval( $opts ) );
			}
		);
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_deregister_frontend' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_deregister_admin' ), 1 );
	}

	/**
	 * Read one location's mode from the options, falling back to "default" for anything unknown.
	 *
	 * @param array  $opts     Options.
	 * @param string $key      Option key.
	 * @param string $location Location the mode is for (the editor cannot be disabled).
	 * @return string
	 */
	public static function mode( $opts, $key, $location ) {
		return self::sanitize_mode( isset( $opts[ $key ] ) ? $opts[ $key ] : 'default', 'editor' !== $location );
	}

	/**
	 * Clean a mode value.
	 *
	 * @param mixed $value         Raw value.
	 * @param bool  $allow_disable Whether "disable" is a valid choice for the location.
	 * @return string One of MODES ("disable" only when allowed).
	 */
	public static function sanitize_mode( $value, $allow_disable = true ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( ! in_array( $value, self::MODES, true ) || ( 'disable' === $value && ! $allow_disable ) ) {
			return 'default';
		}
		return $value;
	}

	/**
	 * Clean the interval used by "reduce".
	 *
	 * @param mixed $value Raw value.
	 * @return int Seconds between MIN_INTERVAL and MAX_INTERVAL.
	 */
	public static function sanitize_interval( $value ) {
		$value = is_numeric( $value ) ? (int) $value : 60;
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $value ) );
	}

	/**
	 * The configured "reduce" interval.
	 *
	 * @param array $opts Options.
	 * @return int
	 */
	private static function interval( $opts ) {
		return self::sanitize_interval( isset( $opts['heartbeat_interval'] ) ? $opts['heartbeat_interval'] : 120 );
	}

	/**
	 * Which location the current request belongs to.
	 *
	 * @return string dashboard|editor|frontend
	 */
	public static function location() {
		if ( ! is_admin() ) {
			return 'frontend';
		}
		global $pagenow;
		return is_string( $pagenow ) && in_array( $pagenow, self::EDITOR_SCREENS, true ) ? 'editor' : 'dashboard';
	}

	/**
	 * Slow the Heartbeat down (never speed it up: a longer interval already set stays).
	 *
	 * @param mixed  $settings heartbeat_settings value.
	 * @param string $mode     Mode of the current location.
	 * @param int    $interval Seconds.
	 * @return mixed
	 */
	public static function apply_interval( $settings, $mode, $interval ) {
		if ( 'reduce' !== $mode || ! is_array( $settings ) ) {
			return $settings;
		}
		$current              = isset( $settings['interval'] ) && is_numeric( $settings['interval'] ) ? (int) $settings['interval'] : 0;
		$settings['interval'] = max( $current, $interval );
		return $settings;
	}

	/**
	 * Remove heartbeat.js from front-end pages when "disable" is chosen.
	 * The Customizer preview keeps it (the Customizer talks to its preview through it).
	 */
	public static function maybe_deregister_frontend() {
		$opts = bepluspb_get_options();
		if ( 'disable' !== self::mode( $opts, 'heartbeat_frontend', 'frontend' ) ) {
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Remove heartbeat.js from the wp-admin pages of the "dashboard" location when "disable" is chosen.
	 */
	public static function maybe_deregister_admin() {
		$opts = bepluspb_get_options();
		if ( 'dashboard' !== self::location() || 'disable' !== self::mode( $opts, 'heartbeat_dashboard', 'dashboard' ) ) {
			return;
		}
		wp_deregister_script( 'heartbeat' );
	}
}
