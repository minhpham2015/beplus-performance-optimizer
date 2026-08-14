<?php
/**
 * Object Cache manager class.
 *
 * Handles installing/uninstalling the WP object-cache drop-in,
 * writing the JSON config file used by the drop-in, and testing
 * connections to Redis or Memcached.
 *
 * @package Beplus_Performance_Booster
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BEPLUSPB_Object_Cache
 */
class BEPLUSPB_Object_Cache {

	/**
	 * Drop-in signature — a comment we embed in the drop-in file so we can
	 * confirm it was installed by this plugin before deleting it.
	 */
	const DROPIN_SIGNATURE = '// Beplus Performance Booster Object Cache Drop-in';

	/**
	 * Source drop-in file bundled with the plugin.
	 *
	 * @return string Absolute path.
	 */
	private static function source_file() {
		return BEPLUSPB_PLUGIN_DIR . 'lib/object-cache.php';
	}

	/**
	 * Target path in wp-content/ where WordPress loads the drop-in.
	 *
	 * @return string Absolute path.
	 */
	private static function target_file() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	/**
	 * Path to the JSON config file read by the drop-in at bootstrap time.
	 *
	 * @return string Absolute path.
	 */
	private static function config_file() {
		return WP_CONTENT_DIR . '/.bepluspb_oc.json';
	}

	// -------------------------------------------------------------------------
	// Drop-in management
	// -------------------------------------------------------------------------

	/**
	 * Copy the bundled drop-in to wp-content/object-cache.php.
	 *
	 * @return array {
	 *     @type bool   $success
	 *     @type string $message Human-readable result.
	 * }
	 */
	public static function install_dropin() {
		$src = self::source_file();
		$dst = self::target_file();

		if ( ! file_exists( $src ) ) {
			return array(
				'success' => false,
				'message' => __( 'Source drop-in file not found in plugin directory.', 'beplus-performance-booster' ),
			);
		}

		// If a drop-in already exists and was NOT installed by us, refuse to overwrite.
		if ( file_exists( $dst ) && ! self::is_our_dropin( $dst ) ) {
			return array(
				'success' => false,
				'message' => __( 'A different object-cache drop-in is already installed. Remove it manually before proceeding.', 'beplus-performance-booster' ),
			);
		}

		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			return array(
				'success' => false,
				'message' => __( 'wp-content directory is not writable. Check file permissions.', 'beplus-performance-booster' ),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy
		$ok = copy( $src, $dst );

		if ( $ok ) {
			return array(
				'success' => true,
				'message' => __( 'Drop-in installed successfully.', 'beplus-performance-booster' ),
			);
		}

		return array(
			'success' => false,
			'message' => __( 'Could not copy drop-in file. Check file permissions.', 'beplus-performance-booster' ),
		);
	}

	/**
	 * Remove the drop-in from wp-content/ — only if it was installed by us.
	 *
	 * @return array {
	 *     @type bool   $success
	 *     @type string $message
	 * }
	 */
	public static function uninstall_dropin() {
		$dst = self::target_file();

		if ( ! file_exists( $dst ) ) {
			return array(
				'success' => true,
				'message' => __( 'Drop-in was not installed.', 'beplus-performance-booster' ),
			);
		}

		if ( ! self::is_our_dropin( $dst ) ) {
			return array(
				'success' => false,
				'message' => __( 'Drop-in was not installed by Beplus Performance Booster. Skipping removal.', 'beplus-performance-booster' ),
			);
		}

		if ( wp_delete_file( $dst ) || ! file_exists( $dst ) ) {
			// wp_delete_file() doesn't return a meaningful bool — check existence.
			if ( ! file_exists( $dst ) ) {
				return array(
					'success' => true,
					'message' => __( 'Drop-in removed successfully.', 'beplus-performance-booster' ),
				);
			}
		}

		return array(
			'success' => false,
			'message' => __( 'Could not remove drop-in. Check file permissions.', 'beplus-performance-booster' ),
		);
	}

	/**
	 * Whether the Beplus drop-in is currently installed.
	 *
	 * @return bool
	 */
	public static function is_dropin_installed() {
		$dst = self::target_file();
		return file_exists( $dst ) && self::is_our_dropin( $dst );
	}

	/**
	 * Read the first 512 bytes of a file and check for our signature.
	 *
	 * @param  string $path File path.
	 * @return bool
	 */
	private static function is_our_dropin( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = file_get_contents( $path, false, null, 0, 512 );
		return $head && false !== strpos( $head, self::DROPIN_SIGNATURE );
	}

	// -------------------------------------------------------------------------
	// Config file
	// -------------------------------------------------------------------------

	/**
	 * Write the JSON config that the drop-in reads at bootstrap time.
	 *
	 * @param  array $opts Full plugin options from bepluspb_get_options().
	 * @return bool        True on success.
	 */
	public static function write_config( $opts ) {
		$cfg = array(
			'enabled'               => ! empty( $opts['object_cache_enabled'] ),
			'driver'                => ( 'memcached' === ( $opts['object_cache_driver'] ?? 'redis' ) ) ? 'memcached' : 'redis',
			'host'                  => sanitize_text_field( $opts['object_cache_host'] ?? '127.0.0.1' ),
			'port'                  => (int) ( $opts['object_cache_port'] ?? 6379 ),
			'password'              => $opts['object_cache_password'] ?? '',
			'db'                    => (int) ( $opts['object_cache_db'] ?? 0 ),
			'persistent'            => ! empty( $opts['object_cache_persistent'] ),
			'global_groups'         => array_values( array_filter( array_map( 'trim', explode( "\n", $opts['object_cache_global_groups'] ?? '' ) ) ) ),
			'non_persistent_groups' => array_values( array_filter( array_map( 'trim', explode( "\n", $opts['object_cache_non_persistent_groups'] ?? '' ) ) ) ),
		);

		$json = wp_json_encode( $cfg, JSON_PRETTY_PRINT );
		if ( ! $json ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$written = file_put_contents( self::config_file(), $json );
		return false !== $written;
	}

	/**
	 * Delete the JSON config file.
	 *
	 * @return bool
	 */
	public static function delete_config() {
		$cfg = self::config_file();
		if ( file_exists( $cfg ) ) {
			wp_delete_file( $cfg );
		}
		return ! file_exists( $cfg );
	}

	// -------------------------------------------------------------------------
	// Connection test
	// -------------------------------------------------------------------------

	/**
	 * Test a connection to Redis or Memcached with the given config.
	 *
	 * @param  array $cfg {
	 *     @type string $driver   'redis' or 'memcached'
	 *     @type string $host
	 *     @type int    $port
	 *     @type string $password (Redis only)
	 *     @type int    $db       (Redis only)
	 * }
	 * @return array {
	 *     @type bool   $success
	 *     @type string $message
	 *     @type int    $ping_ms Milliseconds for the round-trip (0 if unavailable).
	 * }
	 */
	public static function test_connection( $cfg ) {
		$driver   = ( 'memcached' === ( $cfg['driver'] ?? '' ) ) ? 'memcached' : 'redis';
		$host     = sanitize_text_field( $cfg['host'] ?? '127.0.0.1' );
		$port     = (int) ( $cfg['port'] ?? ( 'redis' === $driver ? 6379 : 11211 ) );
		$password = $cfg['password'] ?? '';
		$db       = (int) ( $cfg['db'] ?? 0 );

		$start = microtime( true );

		try {
			if ( 'redis' === $driver ) {
				if ( ! class_exists( 'Redis' ) ) {
					return array(
						'success' => false,
						'message' => __( 'PHP Redis extension is not installed on this server.', 'beplus-performance-booster' ),
						'ping_ms' => 0,
					);
				}
				$redis = new Redis();
				$redis->connect( $host, $port, 2 ); // 2 s timeout
				if ( $password ) {
					$redis->auth( $password );
				}
				if ( $db ) {
					$redis->select( $db );
				}
				$pong = $redis->ping();
				$redis->close();

				$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

				// phpredis returns '+PONG' string or true depending on version.
				if ( true === $pong || 'PONG' === ltrim( (string) $pong, '+' ) ) {
					return array(
						'success' => true,
						/* translators: %d: round-trip time in milliseconds */
						'message' => sprintf( __( 'Connected to Redis at %1$s:%2$d — ping %3$d ms.', 'beplus-performance-booster' ), esc_html( $host ), $port, $ms ),
						'ping_ms' => $ms,
					);
				}
				return array(
					'success' => false,
					'message' => __( 'Redis connected but PING failed. Check server logs.', 'beplus-performance-booster' ),
					'ping_ms' => 0,
				);

			} else {
				// Memcached.
				if ( ! class_exists( 'Memcached' ) ) {
					return array(
						'success' => false,
						'message' => __( 'PHP Memcached extension is not installed on this server.', 'beplus-performance-booster' ),
						'ping_ms' => 0,
					);
				}
				$mc = new Memcached();
				$mc->addServer( $host, $port );

				// A cheap round-trip: set and immediately get a test key.
				$test_key = 'bepluspb_test_' . wp_generate_password( 8, false );
				$ok       = $mc->set( $test_key, 'ok', 5 );
				$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

				if ( $ok ) {
					$mc->delete( $test_key );
					return array(
						'success' => true,
						/* translators: %d: round-trip time in ms */
						'message' => sprintf( __( 'Connected to Memcached at %1$s:%2$d — round-trip %3$d ms.', 'beplus-performance-booster' ), esc_html( $host ), $port, $ms ),
						'ping_ms' => $ms,
					);
				}
				return array(
					'success' => false,
					'message' => __( 'Could not write to Memcached. Verify host/port and that the server is running.', 'beplus-performance-booster' ),
					'ping_ms' => 0,
				);
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'message' => esc_html( $e->getMessage() ),
				'ping_ms' => 0,
			);
		}
	}

	// -------------------------------------------------------------------------
	// Status
	// -------------------------------------------------------------------------

	/**
	 * Return current object cache status for display on the settings page.
	 *
	 * @return array {
	 *     @type bool   $dropin_installed
	 *     @type bool   $enabled_in_settings
	 *     @type string $driver
	 *     @type string $host
	 *     @type int    $port
	 *     @type bool   $extension_available  Whether the PHP extension is loaded.
	 * }
	 */
	public static function get_status() {
		$opts      = bepluspb_get_options();
		$driver    = $opts['object_cache_driver'] ?? 'redis';
		$extension = ( 'redis' === $driver ) ? class_exists( 'Redis' ) : class_exists( 'Memcached' );

		return array(
			'dropin_installed'    => self::is_dropin_installed(),
			'enabled_in_settings' => ! empty( $opts['object_cache_enabled'] ),
			'driver'              => $driver,
			'host'                => $opts['object_cache_host'] ?? '127.0.0.1',
			'port'                => (int) ( $opts['object_cache_port'] ?? 6379 ),
			'extension_available' => $extension,
		);
	}
}
