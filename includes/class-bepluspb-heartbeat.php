<?php
/**
 * WordPress Heartbeat control.
 *
 * Heartbeat is the browser-to-`admin-ajax.php` poll that powers autosave, post locking,
 * the "session expired" login prompt and a few plugins' live data. Each tick is a full
 * WordPress request, so on busy or small servers it is a common source of load.
 *
 * This class only changes the script's settings or removes the script; it never reads
 * or writes the database and adds no endpoint.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Slow down or switch off Heartbeat per location. */
class BEPLUSPB_Heartbeat {

	/** Allowed modes for every location. */
	const MODES = array( 'default', 'slow', 'disable' );

	/** Interval limits WordPress itself accepts for the `heartbeat_settings` filter (seconds). */
	const MIN_INTERVAL = 15;
	const MAX_INTERVAL = 120;

	/** Interval used when "slow" is chosen. */
	const DEFAULT_INTERVAL = 60;

	/** Screens where editing a post happens (autosave and post locking live here). */
	const EDITOR_SCREENS = array( 'post.php', 'post-new.php' );

	/**
	 * Register the hooks for the current location, if its mode is not "default".
	 *
	 * @param array $opts Plugin options.
	 */
	public static function init( $opts ) {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$mode = self::mode_for_location( $opts, self::location() );
		if ( 'disable' === $mode ) {
			// Priority 1 on init: before anything enqueues the script.
			add_action( 'init', array( __CLASS__, 'disable_script' ), 1 );
		} elseif ( 'slow' === $mode ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'filter_settings' ), 20 );
		}
	}

	/**
	 * Where the current request is: 'editor', 'admin' or 'front'.
	 *
	 * @return string
	 */
	public static function location() {
		if ( ! is_admin() ) {
			return 'front';
		}
		global $pagenow;
		return in_array( (string) $pagenow, self::EDITOR_SCREENS, true ) ? 'editor' : 'admin';
	}

	/**
	 * The configured mode for a location.
	 *
	 * @param array  $opts     Plugin options.
	 * @param string $location 'editor', 'admin' or 'front'.
	 * @return string One of self::MODES.
	 */
	public static function mode_for_location( $opts, $location ) {
		$key  = 'heartbeat_' . $location;
		$mode = isset( $opts[ $key ] ) && is_string( $opts[ $key ] ) ? $opts[ $key ] : 'default';
		return in_array( $mode, self::MODES, true ) ? $mode : 'default';
	}

	/**
	 * Clamp a saved interval to what WordPress accepts.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_interval( $value ) {
		$value = absint( $value );
		if ( 0 === $value ) {
			return self::DEFAULT_INTERVAL;
		}
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, $value ) );
	}

	/**
	 * Remove the Heartbeat script so no polling happens on this screen.
	 *
	 * Scripts that list `heartbeat` as a dependency (autosave, the login-expired prompt)
	 * are dropped by WordPress along with it, which is why the post editor defaults to
	 * leaving Heartbeat alone.
	 */
	public static function disable_script() {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Lengthen the polling interval.
	 *
	 * @param array $settings Heartbeat settings passed to the script.
	 * @return array
	 */
	public static function filter_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$opts     = bepluspb_get_options();

		$settings['interval'] = self::sanitize_interval( $opts['heartbeat_interval'] ?? self::DEFAULT_INTERVAL );
		return $settings;
	}
}
