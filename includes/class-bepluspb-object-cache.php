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
	 * Testable filesystem operation seams.
	 *
	 * @var array<string,callable>
	 */
	private static $filesystem_hooks = array();

	/**
	 * Set deterministic filesystem hooks.
	 *
	 * @param array<string,callable> $hooks Filesystem hooks.
	 */
	public static function set_filesystem_hooks( $hooks = array() ) {
		self::$filesystem_hooks = $hooks;
	}

	/**
	 * Rename a file through the test seam.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	private static function fs_rename( $from, $to ) {
		return isset( self::$filesystem_hooks['rename'] ) ? (bool) call_user_func( self::$filesystem_hooks['rename'], $from, $to ) : rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory replacement is required.
	}
	/**
	 * Invoke an optional deterministic filesystem hook.
	 *
	 * @param string $name Hook name.
	 * @param mixed  $fallback Default result.
	 * @param mixed  ...$args Hook arguments.
	 * @return mixed
	 */
	private static function hook( $name, $fallback, ...$args ) {
		return isset( self::$filesystem_hooks[ $name ] ) ? call_user_func( self::$filesystem_hooks[ $name ], ...$args ) : $fallback;
	}
	/**
	 * Verify an optional file snapshot by exact SHA-256 bytes.
	 *
	 * @param string       $path File path.
	 * @param bool         $existed Original existence.
	 * @param string|false $bytes Original bytes.
	 * @return bool
	 */
	private static function exact_file_state( $path, $existed, $bytes ) {
		if ( ! $existed ) {
			return ! file_exists( $path ); }
		$actual = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $bytes && false !== $actual && hash_equals( hash( 'sha256', $bytes ), hash( 'sha256', $actual ) );
	}

	/** Exact machine-readable identity of the bundled drop-in. */
	const DROPIN_BUILD_ID = 'bepluspb-1.1.13-20261008';

	/**
	 * Every build id this plugin has ever shipped in lib/object-cache.php.
	 * Must stay in exact sync with
	 * BEPLUSPB_Dropin_Workflow::KNOWN_DROPIN_BUILD_IDS (enforced by
	 * tests/test-object-cache-dropin-identity.php) — duplicated here rather
	 * than referenced cross-class because this file is loaded standalone
	 * from uninstall.php, which never loads class-bepluspb-dropin-workflow.php;
	 * referencing that class's constant here would fatal during uninstall.
	 * Append new ids on future releases; never remove old ones.
	 */
	const KNOWN_DROPIN_BUILD_IDS = array( self::DROPIN_BUILD_ID, 'bepluspb-1.1.12-20261007', 'bepluspb-1.1.12-20260930' );

	/** Exact hashes of historical Beplus drop-ins shipped before build IDs. */
	const KNOWN_LEGACY_DROPIN_HASHES = array(
		'c63608062a5a62de5c170206106459f5ef90825dd99551a643a9f8ac38d7f15b',
		'36ca640f3f758241f46603edc25b3f752fe39a891e58497856f0c94fbebf08ea',
		'e5a6ffc74d53aa4056782dd14850dcbb5e11e338bbbc756ef9b082de74c2038f',
		'ddb15433750cd3440e17b5f7e60fea623ead7c3bb38cd744158c7fc6bb55d9e8',
	);

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
	 * Path to the guarded config file read by the drop-in at bootstrap time.
	 *
	 * @return string Absolute path.
	 */
	private static function config_file() {
		return WP_CONTENT_DIR . '/.bepluspb_oc.php';
	}

	/** Exact executable guard before the JSON payload. */
	private const CONFIG_GUARD = "<?php exit; ?>\n";

	/**
	 * Read and validate a guarded config file.
	 *
	 * @param string $path Config path.
	 * @return array|false Parsed config or false.
	 */
	private static function read_config_file( $path ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || 0 !== strpos( $raw, self::CONFIG_GUARD ) ) {
			return false;
		}
		$cfg = json_decode( substr( $raw, strlen( self::CONFIG_GUARD ) ), true );
		return is_array( $cfg ) ? $cfg : false;
	}

	/**
	 * Atomically write and verify a guarded config.
	 *
	 * @param array $cfg Config values.
	 * @return bool Whether the verified write succeeded.
	 */
	private static function write_config_file( $cfg ) {
		$json = wp_json_encode( $cfg, JSON_PRETTY_PRINT );
		if ( ! $json ) {
			return false;
		}
		$tmp = tempnam( WP_CONTENT_DIR, '.bepluspb_oc-' );
		if ( false === $tmp ) {
			return false;
		}
		$bytes = self::CONFIG_GUARD . $json;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		$ok = false !== file_put_contents( $tmp, $bytes, LOCK_EX );
		$ok = $ok && chmod( $tmp, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$ok = $ok && self::fs_rename( $tmp, self::config_file() );
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		$verified = self::read_config_file( self::config_file() );
		return is_array( $verified ) && $verified === $cfg;
	}

	/** Move the former exposed JSON config into the guarded config. */
	private static function migrate_legacy_config() {
		$legacy = WP_CONTENT_DIR . '/.bepluspb_oc.json';
		$target = self::config_file();
		if ( ! is_file( $legacy ) ) {
			return true;
		}
		if ( is_file( $target ) && false !== self::read_config_file( $target ) ) {
			wp_delete_file( $legacy );
			return ! is_file( $legacy );
		}
		$raw = file_get_contents( $legacy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cfg = false !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
			wp_delete_file( $legacy );
			return false;
		}
		if ( ! self::write_config_file( $cfg ) ) {
			return false;
		}
		wp_delete_file( $legacy );
		return ! is_file( $legacy );
	}

	// -------------------------------------------------------------------------
	// Drop-in management
	// -------------------------------------------------------------------------

	/**
	 * Atomically install config plus drop-in, restoring prior config on failure.
	 *
	 * @param array $opts Object-cache options.
	 * @return array Operation result.
	 */
	public static function install_with_config( $opts ) {
		$preflight = self::preflight_dropin_target();
		if ( empty( $preflight['success'] ) ) {
			return $preflight;
		}
		$cfg_file   = self::config_file();
		$had_config = is_file( $cfg_file );
		$old_config = $had_config ? file_get_contents( $cfg_file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$legacy     = WP_CONTENT_DIR . '/.bepluspb_oc.json';
		$had_legacy = is_file( $legacy );
		$old_legacy = $had_legacy ? file_get_contents( $legacy ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! self::write_config_file( self::config_from_options( $opts ) ) ) {
			return array(
				'success' => false,
				'message' => __( 'Configuration write failed; installation was not attempted.', 'beplus-performance-booster' ),
			);
		}
		$result = self::install_dropin();
		if ( empty( $result['success'] ) ) {
			$config_restored = self::restore_config( $cfg_file, $had_config, $old_config );
			$legacy_restored = self::restore_file( $legacy, $had_legacy, $old_legacy );
			if ( ! $config_restored || ! $legacy_restored ) {
				$result['critical']    = true;
				$result['uncertain']   = true;
				$result['rolled_back'] = false;
			}
		}
		return $result;
	}

	/**
	 * Restore config state after a failed combined install.
	 *
	 * @param string       $cfg_file Config path.
	 * @param bool         $had_config Whether a prior config existed.
	 * @param string|false $old_config Prior config bytes.
	 * @return bool Whether restoration succeeded.
	 */
	private static function restore_config( $cfg_file, $had_config, $old_config ) {
		return self::restore_file( $cfg_file, $had_config, $old_config );
	}

	/**
	 * Restore an exact optional file snapshot.
	 *
	 * @param string       $path    File path.
	 * @param bool         $existed Whether the file existed.
	 * @param string|false $bytes   Original bytes.
	 * @return bool
	 */
	private static function restore_file( $path, $existed, $bytes ) {
		if ( ! self::hook( 'restore', true, $path ) ) {
			return false; }
		if ( $existed && false !== $bytes ) {
			file_put_contents( $path, $bytes, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			return self::exact_file_state( $path, true, $bytes );
		}
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		return self::exact_file_state( $path, false, false );
	}

	/**
	 * Copy the bundled drop-in to wp-content/object-cache.php.
	 *
	 * @return array {
	 *     @type bool   $success
	 *     @type string $message Human-readable result.
	 * }
	 */
	public static function install_dropin() {
		$src       = self::source_file();
		$dst       = self::target_file();
		$preflight = self::preflight_dropin_target();
		if ( empty( $preflight['success'] ) ) {
			return $preflight;
		}
		$legacy     = WP_CONTENT_DIR . '/.bepluspb_oc.json';
		$had_legacy = is_file( $legacy );
		$old_legacy = $had_legacy ? file_get_contents( $legacy ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cfg_file   = self::config_file();
		$had_config = is_file( $cfg_file );
		$old_config = $had_config ? file_get_contents( $cfg_file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! self::migrate_legacy_config_without_delete() ) {
			return array(
				'success' => false,
				'message' => __( 'Object Cache configuration migration failed.', 'beplus-performance-booster' ),
			);
		}
		$cfg = is_file( $cfg_file ) ? self::read_config_file( $cfg_file ) : false;
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
			self::restore_config( $cfg_file, $had_config, $old_config );
			return array(
				'success' => false,
				'message' => __( 'Object Cache configuration is not enabled. Save your settings with Object Cache turned on first, then install.', 'beplus-performance-booster' ),
			);
		}
		$had_dropin      = is_file( $dst );
		$old_dropin      = $had_dropin ? file_get_contents( $dst ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$old_dropin_hash = $had_dropin && false !== $old_dropin ? hash( 'sha256', $old_dropin ) : false;
		$tmp             = tempnam( WP_CONTENT_DIR, '.bepluspb-dropin-' );
		$ok              = false !== $tmp && copy( $src, $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy
		$rollback        = $dst . '.bepluspb-rollback';
		$source_hash     = is_file( $src ) ? hash_file( 'sha256', $src ) : false;
		$ok              = $ok && is_string( $source_hash ) && hash_equals( $source_hash, (string) hash_file( 'sha256', $tmp ) ) && self::is_current_dropin( $tmp ) && self::syntax_valid( $tmp );
		$ok              = $ok && self::hook( 'before_target_activation', true );
		$ok              = $ok && ( $had_dropin ? ( is_file( $dst ) && hash_equals( $old_dropin_hash, (string) hash_file( 'sha256', $dst ) ) ) : ! file_exists( $dst ) );
		$ok              = $ok && self::hook( 'after_target_hash_check', true );
		if ( $ok && file_exists( $dst ) ) {
			$ok = self::fs_rename( $dst, $rollback );
			$ok = $ok && is_file( $rollback ) && hash_equals( $old_dropin_hash, (string) hash_file( 'sha256', $rollback ) ); }
		if ( $ok ) {
			$ok = self::fs_rename( $tmp, $dst ); }
		if ( ! $ok ) {
			$moved_bytes        = is_file( $rollback ) ? file_get_contents( $rollback ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$moved_substituted  = false !== $moved_bytes && $had_dropin && ! hash_equals( $old_dropin_hash, hash( 'sha256', $moved_bytes ) );
			$target_substituted = $had_dropin && is_file( $dst ) && ! hash_equals( $old_dropin_hash, (string) hash_file( 'sha256', $dst ) );
			if ( $moved_substituted ) {
				$dropin_restored = self::restore_file( $dst, true, $moved_bytes );
			} else {
				$dropin_restored = $target_substituted ? true : self::restore_file( $dst, $had_dropin, $old_dropin );
			}
			$dropin_restored = $dropin_restored && ( $moved_substituted || $target_substituted || ! $had_dropin || ( is_file( $dst ) && hash_equals( hash( 'sha256', $old_dropin ), hash_file( 'sha256', $dst ) ) ) );
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp ); }
			if ( file_exists( $rollback ) ) {
				wp_delete_file( $rollback ); }
			$config_restored = self::restore_config( $cfg_file, $had_config, $old_config );
			if ( ! $dropin_restored || ! $config_restored ) {
				return array(
					'success'   => false,
					'critical'  => true,
					'uncertain' => true,
					'message'   => __( 'Drop-in replacement failed and rollback could not be verified. Object Cache state is uncertain; inspect object-cache.php and its configuration immediately.', 'beplus-performance-booster' ),
				);
			}
			return array(
				'success'     => false,
				'rolled_back' => true,
				'message'     => __( 'Could not replace drop-in file. The previous drop-in and configuration were restored and verified.', 'beplus-performance-booster' ),
			);
		}
		$installed_ok   = is_file( $dst ) && is_string( $source_hash ) && hash_equals( $source_hash, (string) hash_file( 'sha256', $dst ) ) && self::is_current_dropin( $dst ) && self::syntax_valid( $dst ) && self::runtime_compatible( $dst );
		$legacy_deleted = true;
		if ( $installed_ok && file_exists( $legacy ) ) {
			wp_delete_file( $legacy );
			$legacy_deleted = ! file_exists( $legacy );
		}
		if ( ! $installed_ok || ! $legacy_deleted ) {
			$dropin_restored = self::restore_file( $dst, $had_dropin, $old_dropin );
			$config_restored = self::restore_config( $cfg_file, $had_config, $old_config );
			$legacy_restored = self::restore_file( $legacy, $had_legacy, $old_legacy );
			if ( file_exists( $rollback ) ) {
				wp_delete_file( $rollback ); }
			$verified = $dropin_restored && $config_restored && $legacy_restored && ( ! $had_dropin || hash_equals( $old_dropin_hash, (string) hash_file( 'sha256', $dst ) ) );
			return array(
				'success'     => false,
				'rolled_back' => $verified,
				'critical'    => ! $verified,
				'uncertain'   => ! $verified,
				'message'     => __( 'Activation or legacy configuration cleanup failed; the prior compatible state was restored and verified where possible.', 'beplus-performance-booster' ),
			);
		}
		if ( file_exists( $rollback ) ) {
			wp_delete_file( $rollback );
			if ( file_exists( $rollback ) ) {
				return array(
					'success'   => false,
					'critical'  => true,
					'uncertain' => true,
					'message'   => __( 'Drop-in activated, but rollback cleanup could not be verified.', 'beplus-performance-booster' ),
				);
			}
		}
		return array(
			'success' => true,
			'message' => __( 'Drop-in installed successfully.', 'beplus-performance-booster' ),
		);
	}


	/** Validate replacement safety without changing config or target. */
	private static function preflight_dropin_target() {
		$src = self::source_file();
		$dst = self::target_file();
		if ( ! file_exists( $src ) ) {
			return array(
				'success' => false,
				'message' => __( 'Source drop-in file not found in plugin directory.', 'beplus-performance-booster' ),
			); }
		if ( file_exists( $dst ) && ! self::is_our_dropin( $dst ) ) {
			return array(
				'success' => false,
				'message' => __( 'A different object-cache drop-in is already installed. Remove it manually before proceeding.', 'beplus-performance-booster' ),
			); }
		if ( ( file_exists( $dst ) && ! is_writable( $dst ) ) || ! is_writable( WP_CONTENT_DIR ) ) {
			return array(
				'success' => false,
				'message' => __( 'The existing object-cache.php or wp-content directory is not writable by PHP. Ask the server administrator to upgrade the Beplus drop-in; configuration was not changed.', 'beplus-performance-booster' ),
			); }
		return array( 'success' => true );
	}

	/**
	 * Verify the exact current bundled drop-in build identity.
	 *
	 * @param string $path Drop-in path.
	 * @return bool
	 */
	private static function is_current_dropin( $path ) {
		$head = file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== $head && preg_match( "/define\\(\\s*'BEPLUSPB_DROPIN_BUILD_ID'\\s*,\\s*'([^']+)'/", $head, $matches ) && hash_equals( self::DROPIN_BUILD_ID, $matches[1] );
	}
	/**
	 * Locate and verify a PHP CLI binary for isolated validation commands.
	 *
	 * @return string|false Verified CLI path, or false when none is usable.
	 */
	private static function php_cli() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$candidates = 'cli' === PHP_SAPI ? array( PHP_BINARY ) : array();
		$dirs       = array_unique( array_filter( array( PHP_BINDIR, dirname( (string) PHP_BINARY ) ) ) );
		foreach ( $dirs as $dir ) {
			$candidates[] = $dir . '/php' . $version;
			$candidates[] = $dir . '/php';
		}
		$candidates = self::hook( 'php_cli_candidates', $candidates );
		if ( ! is_array( $candidates ) ) {
			return false;
		}
		foreach ( array_unique( $candidates ) as $binary ) {
			if ( ! is_string( $binary ) || ! @is_file( $binary ) || ! @is_executable( $binary ) ) {
				continue;
			}
			$output = array();
			$status = 1;
			@exec( escapeshellarg( $binary ) . ' -n -v 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Required to verify the candidate is CLI, not PHP-FPM/CGI.
			if ( 0 === $status && false !== stripos( implode( "\n", $output ), '(cli)' ) ) {
				return $binary;
			}
		}
		return false;
	}

	/**
	 * Validate PHP syntax without executing the staged drop-in.
	 *
	 * @param string $path Drop-in path.
	 * @return bool
	 */
	private static function syntax_valid( $path ) {
		if ( isset( self::$filesystem_hooks['syntax_valid'] ) ) {
			return (bool) self::hook( 'syntax_valid', false, $path ); }
		$php = self::php_cli();
		if ( ! $php ) {
			return false;
		}
		$output = array();
		$code   = 1;
		exec( escapeshellarg( $php ) . ' -n -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		return 0 === $code;
	}

	/**
	 * Prove the installed drop-in can bootstrap WordPress and serve cache IO.
	 *
	 * @param string $path Installed drop-in path.
	 * @return bool
	 */
	private static function runtime_compatible( $path ) {
		if ( isset( self::$filesystem_hooks['runtime_compatible'] ) ) {
			return (bool) self::hook( 'runtime_compatible', false, $path ); }
		$bootstrap = rtrim( ABSPATH, '/\\' ) . '/wp-load.php';
		$php       = self::php_cli();
		if ( ! is_file( $bootstrap ) || ! $php ) {
			return false; }
		$key   = 'bepluspb-runtime-' . bin2hex( random_bytes( 8 ) );
		$value = bin2hex( random_bytes( 16 ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Safely quote fixed paths and random probe values for isolated CLI execution.
		$script = 'require ' . var_export( $bootstrap, true ) . '; if(!defined("BEPLUSPB_DROPIN_BUILD_ID")||!hash_equals(' . var_export( self::DROPIN_BUILD_ID, true ) . ',BEPLUSPB_DROPIN_BUILD_ID)||!function_exists("wp_cache_set")||!wp_cache_set(' . var_export( $key, true ) . ',' . var_export( $value, true ) . ',"bepluspb-runtime",30)||wp_cache_get(' . var_export( $key, true ) . ',"bepluspb-runtime")!==' . var_export( $value, true ) . '||!wp_cache_delete(' . var_export( $key, true ) . ',"bepluspb-runtime")){exit(23);}';
		$output = array();
		$status = 1;
		exec( escapeshellarg( $php ) . ' -d display_errors=0 -r ' . escapeshellarg( $script ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		return 0 === $status;
	}

	/** Stage legacy config without deleting the file required by an old active drop-in. */
	private static function migrate_legacy_config_without_delete() {
		$legacy = WP_CONTENT_DIR . '/.bepluspb_oc.json';
		if ( ! is_file( $legacy ) ) {
			return true; }
		if ( is_file( self::config_file() ) && false !== self::read_config_file( self::config_file() ) ) {
			return true; }
		$raw = file_get_contents( $legacy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cfg = false !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
			// With no active target, remove unusable exposed credentials. An
			// active legacy target is preserved so a failed upgrade cannot alter
			// its inputs.
			if ( ! file_exists( self::target_file() ) ) {
				wp_delete_file( $legacy ); }
			return false;
		}
		return self::write_config_file( $cfg );
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
	 * Parse and compare the exact machine-readable build identity.
	 *
	 * Accepts ANY build id this plugin has ever shipped (see
	 * BEPLUSPB_Dropin_Workflow::KNOWN_DROPIN_BUILD_IDS), not just the
	 * current one — this check runs against an ALREADY-INSTALLED target
	 * (uninstall, install-over-existing, "is it installed" status), so a
	 * version bump must not make a site's earlier Beplus-installed
	 * drop-in look foreign and un-removable/un-restorable.
	 *
	 * @param  string $path File path.
	 * @return bool
	 */
	private static function is_our_dropin( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = file_get_contents( $path, false, null, 0, 4096 );
		if ( false === $head ) {
			return false; }
		if ( ! preg_match( "/define\(\s*'BEPLUSPB_DROPIN_BUILD_ID'\s*,\s*'([^']+)'/", $head, $matches ) ) {
			$hash = hash_file( 'sha256', $path );
			return is_string( $hash ) && in_array( $hash, self::KNOWN_LEGACY_DROPIN_HASHES, true );
		}
		foreach ( self::KNOWN_DROPIN_BUILD_IDS as $known ) {
			if ( hash_equals( $known, $matches[1] ) ) {
				return true;
			}
		}
		return false;
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
		if ( ! self::migrate_legacy_config() ) {
			return false;
		}
		$cfg = self::config_from_options( $opts );

		$written = self::write_config_file( $cfg );
		if ( $written && is_file( WP_CONTENT_DIR . '/.bepluspb_oc.json' ) ) {
			wp_delete_file( WP_CONTENT_DIR . '/.bepluspb_oc.json' );
			return ! is_file( WP_CONTENT_DIR . '/.bepluspb_oc.json' );
		}
		return $written;
	}

	/**
	 * Convert plugin options to the drop-in config payload.
	 *
	 * @param array $opts Plugin options.
	 * @return array
	 */
	private static function config_from_options( $opts ) {
		return array(
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
	}

	/**
	 * Delete guarded and legacy config files.
	 *
	 * @return bool
	 */
	public static function delete_config() {
		$files = array(
			self::config_file(),
			WP_CONTENT_DIR . '/.bepluspb_oc.json',
		);
		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				return false;
			}
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Connection test
	// -------------------------------------------------------------------------

	/**
	 * Test a connection to Redis or Memcached with the given config.
	 *
	 * @param  array $cfg {.
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

	/**
	 * Determine whether a backend-wide purge can be offered safely.
	 *
	 * The bundled drop-in uses Redis FLUSHDB or Memcached flush. Therefore it
	 * fails closed unless an operator/integration explicitly attests that the
	 * selected backend scope is dedicated to this WordPress installation.
	 *
	 * @return array{available:bool,backend:string,scope:string,reason:string}
	 */
	public static function get_purge_availability() {
		$opts    = bepluspb_get_options();
		$backend = 'memcached' === ( $opts['object_cache_driver'] ?? '' ) ? 'memcached' : 'redis';
		$scope   = 'redis' === $backend ? 'database' : 'pool';
		$reason  = __( 'Disabled: exclusive ownership of the configured backend scope cannot be proven.', 'beplus-performance-booster' );
		if ( empty( $opts['object_cache_enabled'] ) || ! wp_using_ext_object_cache() || ! self::is_dropin_installed() ) {
			return array(
				'available' => false,
				'backend'   => $backend,
				'scope'     => $scope,
				'reason'    => __( 'Disabled: settings, runtime external cache, and the bundled drop-in do not all match.', 'beplus-performance-booster' ),
			);
		}
		$cfg_raw = file_exists( self::config_file() ) ? file_get_contents( self::config_file() ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cfg     = $cfg_raw ? json_decode( $cfg_raw, true ) : null;
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || ( $cfg['driver'] ?? '' ) !== $backend ) {
			return array(
				'available' => false,
				'backend'   => $backend,
				'scope'     => $scope,
				'reason'    => __( 'Disabled: the effective Object Cache configuration does not match saved settings.', 'beplus-performance-booster' ),
			);
		}
		$health = self::test_connection( $cfg );
		if ( empty( $health['success'] ) ) {
			return array(
				'available' => false,
				'backend'   => $backend,
				'scope'     => $scope,
				'reason'    => __( 'Disabled: the persistent Object Cache backend is disconnected or unhealthy.', 'beplus-performance-booster' ),
			);
		}
		$isolated = (bool) apply_filters( 'bepluspb_object_cache_scope_is_dedicated', false, $backend, $scope );
		return array(
			'available' => $isolated,
			'backend'   => $backend,
			'scope'     => $scope,
			'reason'    => $isolated ? '' : $reason,
		);
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
