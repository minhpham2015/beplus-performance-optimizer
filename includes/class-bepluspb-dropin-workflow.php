<?php
/**
 * Safe foreign object-cache drop-in backup/replace/restore workflow.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Atomic filesystem operations and isolated process execution require direct PHP primitives.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class BEPLUSPB_Dropin_Workflow {
	const DROPIN_BUILD_ID = 'bepluspb-1.1.12-20260930';
	/**
	 * Every build id this plugin has ever shipped in lib/object-cache.php.
	 * Ownership checks on an ALREADY-INSTALLED target (uninstall, restore,
	 * ".. is this drop-in ours") must recognize any of these, not just the
	 * current one, or a version bump breaks upgrade/uninstall/restore for
	 * every site that installed an earlier version's drop-in. Only the
	 * BUNDLED SOURCE file about to be installed (source_valid_path()) is
	 * required to match the CURRENT id exactly. Append new ids here on
	 * future releases; never remove old ones.
	 */
	const KNOWN_DROPIN_BUILD_IDS = array( self::DROPIN_BUILD_ID );
	private $content_dir;
	private $source;
	private $hooks;
	public function __construct( $content_dir, $source, $hooks = array() ) {
		$this->content_dir = rtrim( $content_dir, '/\\' );
		$this->source      = $source;
		$this->hooks       = $hooks;
	}
	private function hook( $name, $default = true, ...$args ) {
		return isset( $this->hooks[ $name ] ) ? call_user_func( $this->hooks[ $name ], ...$args ) : $default;
	}
	private function target() { return $this->content_dir . '/object-cache.php'; }
	private function lock_path() { return $this->content_dir . '/.bepluspb-dropin.lock'; }
	private function acquire_lock() {
		$p = $this->lock_path();
		if ( is_link( $p ) || ! $this->canonical_inside( $p, true ) ) { return false; }
		$fp = @fopen( $p, 'c+');
		if ( ! $fp || is_link( $p ) || ! $this->canonical_inside( $p ) || ! @flock( $fp, LOCK_EX | LOCK_NB ) ) { if ( $fp ) { @fclose( $fp ); } return false; }
		return $fp;
	}
	private function release_lock( $fp ) { @flock( $fp, LOCK_UN ); @fclose( $fp ); }
	private function backup_dir() { return $this->content_dir . '/bepluspb-backups'; }
	private function fail( $message, $extra = array() ) { return array_merge( array( 'success' => false, 'message' => $message ), $extra ); }
	private function canonical_inside( $path, $allow_missing = false ) {
		$root = realpath( $this->content_dir );
		$real = realpath( $path );
		if ( false === $root || ( false === $real && ! $allow_missing ) ) { return false; }
		if ( false === $real ) {
			$parent = realpath( dirname( $path ) );
			if ( false === $parent ) { return false; }
			$real = $parent . DIRECTORY_SEPARATOR . basename( $path );
		}
		return $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR );
	}
	private function readable( $path ) { return $this->hook( 'readable', is_readable( $path ), $path ); }
	private function identity( $path ) {
		$st = @lstat( $path );
		return $st && is_file( $path ) && ! is_link( $path ) ? array( $st['dev'], $st['ino'], $st['size'], $st['mtime'] ) : false;
	}
	private function same_identity( $path, $identity ) { return $identity && $identity === $this->identity( $path ); }
	private function source_valid_path( $path ) {
		$build_id = $this->build_id( $path );
		return is_string( $build_id ) && hash_equals( self::DROPIN_BUILD_ID, $build_id );
	}
	/**
	 * Whether a path carries ANY build id this plugin has ever shipped.
	 * Used to recognize an already-installed Beplus drop-in (e.g. for
	 * restore's precondition on the active target) across version
	 * upgrades. Installation of the bundled SOURCE must still use the
	 * strict current-only source_valid_path() above.
	 *
	 * @param  string $path File path.
	 * @return bool
	 */
	private function is_known_dropin_path( $path ) {
		$build_id = $this->build_id( $path );
		if ( ! is_string( $build_id ) ) { return false; }
		foreach ( self::KNOWN_DROPIN_BUILD_IDS as $known ) {
			if ( hash_equals( $known, $build_id ) ) { return true; }
		}
		return false;
	}
	private function syntax_valid( $path ) {
		if ( ! function_exists( 'exec' ) || ! is_executable( PHP_BINARY ) ) { return false; }
		$out = array(); $rc = 1;
		@exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $rc );
		return 0 === $rc;
	}
	private function source_valid() {
		return $this->identity( $this->source ) && $this->readable( $this->source ) && $this->source_valid_path( $this->source );
	}
	public function provider( $path ) {
		$h = @file_get_contents( $path, false, null, 0, 4096 );
		if ( false === stripos( (string) $h, 'redis' ) ) { return false === stripos( (string) $h, 'memcached' ) ? 'Unknown' : 'Memcached'; }
		return 'Redis Object Cache';
	}
	public function preflight( $operation = 'replace' ) {
		$t = $this->target();
		if ( false === realpath( $this->content_dir ) || ! $this->canonical_inside( $t, true ) ) { return $this->fail( 'The drop-in path is outside the canonical wp-content directory.' ); }
		if ( is_link( $t ) ) { return $this->fail( 'The existing drop-in is a symbolic link and cannot be changed safely.' ); }
		if ( file_exists( $t ) && ( ! $this->identity( $t ) || ! $this->readable( $t ) ) ) { return $this->fail( 'The existing drop-in is not a readable regular file.' ); }
		if ( file_exists( $t ) && ! $this->hook( 'target_writable', is_writable( $t ), $t ) ) { return $this->fail( 'The existing drop-in is not writable by PHP (it may be host-managed). Ask the host to replace it manually.' ); }
		if ( ! $this->hook( 'directory_writable', is_writable( $this->content_dir ), $this->content_dir ) ) { return $this->fail( 'The wp-content directory is not writable by PHP. Ask the host to replace the file manually.' ); }
		if ( ! $this->source_valid() || ! $this->syntax_valid( $this->source ) ) { return $this->fail( 'The bundled Beplus drop-in is unreadable or invalid.' ); }
		$need = @filesize( $this->source ) + ( @filesize( $t ) ?: 0 ) + 8192;
		$free = @disk_free_space( $this->content_dir );
		if ( false !== $free && $free < $need ) { return $this->fail( 'Insufficient free space for a safe backup and atomic replacement.' ); }
		$tmp = @tempnam( $this->content_dir, '.bepluspb-check-' );
		if ( false === $tmp ) { return $this->fail( 'A same-directory temporary file cannot be created.' ); }
		@unlink( $tmp );
		$backend = $this->hook( 'backend_test', array( 'success' => false ) );
		if ( empty( $backend['success'] ) ) { return $this->fail( 'The configured object-cache backend connection test failed.' ); }
		return array( 'success' => true, 'message' => 'Safety checks passed; this does not guarantee replacement will succeed.', 'provider' => file_exists( $t ) ? $this->provider( $t ) : 'None' );
	}
	private function ensure_backup_dir() {
		$d = $this->backup_dir();
		if ( file_exists( $d ) || is_link( $d ) ) {
			if ( is_link( $d ) || ! is_dir( $d ) || ! $this->canonical_inside( $d ) ) { return false; }
		} else {
			if ( ! $this->canonical_inside( $d, true ) || ! @mkdir( $d, 0700, false ) ) { return false; }
			clearstatcache( true, $d );
			if ( is_link( $d ) || ! $this->canonical_inside( $d ) ) { @rmdir( $d ); return false; }
		}
		@chmod( $d, 0700 );
		return ! is_link( $d ) && $this->canonical_inside( $d );
	}
	private function token() { return (string) $this->hook( 'unique_token', gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) ); }
	private function manifest_key() {
		$key = $this->hook( 'manifest_key', null );
		if ( is_string( $key ) && strlen( $key ) >= 32 ) { return $key; }
		if ( function_exists( 'wp_salt' ) ) { $key = wp_salt( 'auth' ); }
		return is_string( $key ) && strlen( $key ) >= 32 ? $key : false;
	}
	private function manifest_mac( $data ) {
		$key = $this->manifest_key();
		if ( ! $key ) { return false; }
		$authenticated = $data; unset( $authenticated['mac'] );
		return hash_hmac( 'sha256', wp_json_encode( $authenticated ), $key );
	}
	public function backup_file( $source, $kind = 'foreign', $protect = null ) {
		$source_id = $this->identity( $source );
		if ( ! $this->canonical_inside( $source ) || ! $source_id || ! $this->readable( $source ) || ! $this->ensure_backup_dir() ) { return $this->fail( 'Backup source or directory is unsafe.' ); }
		$base = preg_replace( '/[^a-z0-9_-]/i', '', $kind ) . '-' . $this->token();
		$path = $this->backup_dir() . '/' . $base . '.bak'; $i = 0;
		while ( file_exists( $path ) ) { $path = $this->backup_dir() . '/' . $base . '-' . ( ++$i ) . '.bak'; }
		$bytes = @file_get_contents( $source );
		if ( false === $bytes || ! $this->same_identity( $source, $source_id ) ) { return $this->fail( 'Backup source changed while it was being read.' ); }
		$encoded = base64_encode( $bytes );
		$out = @fopen( $path, 'x+b' );
		if ( ! $out ) { return $this->fail( 'Could not create the backup.' ); }
		@chmod( $path, 0600 );
		$ok = strlen( $encoded ) === fwrite( $out, $encoded ); @fflush( $out ); if ( function_exists( 'fsync' ) ) { @fsync( $out ); } fclose( $out );
		$this->hook( 'after_backup_copy', true, $path );
		$hash = hash( 'sha256', $bytes );
		$decoded = base64_decode( (string) @file_get_contents( $path ), true );
		if ( ! $ok || false === $decoded || ! hash_equals( $hash, hash( 'sha256', $decoded ) ) ) { @unlink( $path ); return $this->fail( 'Backup checksum verification failed.' ); }
		$st = @stat( $source );
		$meta = array( 'source_basename' => basename( $source ), 'sha256' => $hash, 'size' => strlen( $bytes ), 'mode' => $st ? sprintf( '%04o', $st['mode'] & 0777 ) : null, 'uid' => $st ? $st['uid'] : null, 'gid' => $st ? $st['gid'] : null, 'mtime' => $st ? $st['mtime'] : null, 'provider' => $this->provider( $source ), 'plugin_version' => defined( 'BEPLUSPB_VERSION' ) ? BEPLUSPB_VERSION : 'unknown', 'created_at' => gmdate( 'c' ), 'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'kind' => $kind, 'encoding' => 'base64' );
		if ( false === @file_put_contents( $path . '.json', wp_json_encode( $meta, JSON_PRETTY_PRINT ), LOCK_EX ) ) { @unlink( $path ); return $this->fail( 'Could not write backup metadata.' ); }
		@chmod( $path . '.json', 0600 );
		$this->retain( $protect );
		return array( 'success' => true, 'path' => $path, 'sha256' => $hash );
	}
	private function retain( $protect = null ) {
		$f = glob( $this->backup_dir() . '/*.bak' ); if ( ! $f ) { return; }
		usort( $f, fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
		$kept = 0;
		foreach ( $f as $p ) {
			if ( $protect && realpath( $p ) === realpath( $protect ) ) { continue; }
			if ( ++$kept > 3 ) { @unlink( $p ); @unlink( $p . '.json' ); }
		}
	}
	private function write_temp( $bytes, $prefix ) {
		$tmp = tempnam( $this->content_dir, $prefix ); if ( false === $tmp ) { return false; }
		$fp = fopen( $tmp, 'wb' ); if ( ! $fp ) { @unlink( $tmp ); return false; }
		$ok = strlen( $bytes ) === fwrite( $fp, $bytes ); fflush( $fp ); if ( function_exists( 'fsync' ) ) { @fsync( $fp ); } fclose( $fp ); @chmod( $tmp, 0600 );
		return $ok ? $tmp : false;
	}
	private function preserve( $path, $st, $required = false ) {
		if ( ! $st ) { return ! $required; }
		$ok = @chmod( $path, $st['mode'] & 0777 );
		// Ownership changes are best-effort for non-root portability. Mode and mtime are required.
		if ( isset( $st['uid'] ) && function_exists( 'chown' ) ) { @chown( $path, $st['uid'] ); }
		if ( isset( $st['gid'] ) && function_exists( 'chgrp' ) ) { @chgrp( $path, $st['gid'] ); }
		if ( isset( $st['mtime'] ) ) { $ok = @touch( $path, $st['mtime'] ) && $ok; }
		return $ok || ! $required;
	}
	private function build_id( $path ) {
		$bytes = @file_get_contents( $path );
		return false !== $bytes && preg_match( "/define\(\s*'BEPLUSPB_DROPIN_BUILD_ID'\s*,\s*'([^']+)'/", $bytes, $m ) ? $m[1] : false;
	}
	private function health_probe( $require_identity = true ) {
		if ( isset( $this->hooks['health_probe'] ) ) { return (bool) $this->hook( 'health_probe', false, $this->target(), $require_identity ); }
		if ( ! function_exists( 'exec' ) || ! is_executable( PHP_BINARY ) || ! defined( 'ABSPATH' ) ) { return false; }
		$bootstrap = rtrim( ABSPATH, '/\\' ) . '/wp-load.php';
		if ( ! is_file( $bootstrap ) ) { return false; }
		$expected = $this->source_valid_path( $this->source ) ? self::DROPIN_BUILD_ID : false;
		if ( $require_identity && ! $expected ) { return false; }
		$identity_check = $require_identity ? '!defined("BEPLUSPB_DROPIN_BUILD_ID")||!hash_equals(' . var_export( $expected, true ) . ',BEPLUSPB_DROPIN_BUILD_ID)||' : '';
		$code = 'require ' . var_export( $bootstrap, true ) . '; $k="bepluspb-health-".bin2hex(random_bytes(8)); $v=bin2hex(random_bytes(16)); if(' . $identity_check . '!function_exists("wp_cache_set")||!wp_cache_set($k,$v,"bepluspb-health",30)||wp_cache_get($k,"bepluspb-health")!==$v||!wp_cache_delete($k,"bepluspb-health")){exit(23);}';
		$out = array(); $rc = 1; @exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -r ' . escapeshellarg( $code ) . ' 2>&1', $out, $rc );
		return 0 === $rc;
	}
	private function activate( $tmp, $rollback, $before_hook ) {
		$t = $this->target(); $target_id = file_exists( $t ) ? $this->identity( $t ) : null;
		if ( file_exists( $t ) && ! $target_id ) { return false; }
		if ( ! $this->hook( $before_hook, true ) || ( $target_id && ! $this->same_identity( $t, $target_id ) ) ) { return false; }
		if ( file_exists( $t ) && ! @rename( $t, $rollback ) ) { return false; }
		if ( ! @rename( $tmp, $t ) ) { if ( file_exists( $rollback ) ) { @rename( $rollback, $t ); } return false; }
		return true;
	}
	private function rollback( $rollback ) {
		$t = $this->target(); @unlink( $t );
		return file_exists( $rollback ) && @rename( $rollback, $t );
	}
	public function replace() {
		$lock = $this->acquire_lock(); if ( ! $lock ) { return $this->fail( 'Another drop-in transaction is active or the lock file is unsafe.' ); }
		try {
			$p = $this->preflight( 'replace' ); if ( empty( $p['success'] ) ) { return $p; }
			$t = $this->target(); $original_id = file_exists( $t ) ? $this->identity( $t ) : null; $backup = null; $backup_hash = null; $backup_meta = null; $st = @stat( $t );
			if ( file_exists( $t ) ) { $b = $this->backup_file( $t, 'foreign' ); if ( empty( $b['success'] ) || ! $this->same_identity( $t, $original_id ) ) { return $this->fail( 'Target changed before activation.' ); } $backup = $b['path']; $backup_hash = $b['sha256']; $backup_meta = json_decode( (string) @file_get_contents( $backup . '.json' ), true ); }
			$source_id = $this->identity( $this->source ); $bytes = @file_get_contents( $this->source );
			if ( false === $bytes || ! $this->same_identity( $this->source, $source_id ) ) { return $this->fail( 'Bundled source changed during replacement.' ); }
			$tmp = $this->write_temp( $bytes, '.bepluspb-new-' );
			if ( ! $tmp || ! $this->syntax_valid( $tmp ) || ! $this->build_id( $tmp ) || ! hash_equals( hash( 'sha256', $bytes ), (string) hash_file( 'sha256', $tmp ) ) ) { if ( $tmp ) { @unlink( $tmp ); } return $this->fail( 'Replacement validation failed.' ); }
			$this->preserve( $tmp, $st ); $rollback = $this->content_dir . '/.bepluspb-rollback-' . bin2hex( random_bytes( 4 ) );
			if ( ! $this->activate( $tmp, $rollback, 'before_activate' ) ) { @unlink( $tmp ); $rb = ! file_exists( $rollback ) || @rename( $rollback, $t ); return $this->fail( 'Replacement failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) ); }
			$hash = hash( 'sha256', $bytes );
			if ( ! $this->identity( $t ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $t ) ) || ! $this->health_probe() ) { $rb = $this->rollback( $rollback ); return $this->fail( 'Post-activation health verification failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) ); }
			if ( $backup ) {
				$manifest = array( 'backup' => basename( $backup ), 'sha256' => $backup_hash, 'created_at' => gmdate( 'c' ), 'metadata' => $backup_meta ); $manifest['mac'] = $this->manifest_mac( $manifest );
				$manifest_path = $this->backup_dir() . '/restore-manifest.json'; $json = wp_json_encode( $manifest, JSON_PRETTY_PRINT );
				$written = isset( $this->hooks['manifest_write'] ) ? $this->hook( 'manifest_write', false, $manifest_path, $json ) : $this->write_temp_manifest( $manifest_path, $json );
				if ( ! $manifest['mac'] || ! $written ) { $rb = $this->rollback( $rollback ); return $this->fail( 'Restore manifest publication failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) ); }
			}
			@unlink( $rollback ); $this->retain( $backup );
			return array( 'success' => true, 'message' => 'The previous drop-in was backed up, Beplus was installed, and the isolated cache health probe passed.', 'backup' => $backup );
		} finally { $this->release_lock( $lock ); }
	}
	private function write_temp_manifest( $path, $json ) {
		$tmp = $this->write_temp( $json, '.bepluspb-manifest-' ); if ( ! $tmp ) { return false; }
		if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); return false; } @chmod( $path, 0600 );
		$fp = @fopen( $path, 'rb' ); if ( ! $fp ) { return false; } $ok = @fflush( $fp ); if ( function_exists( 'fsync' ) ) { $ok = @fsync( $fp ) && $ok; } fclose( $fp ); return $ok;
	}

	public function restore() {
		$lock = $this->acquire_lock(); if ( ! $lock ) { return $this->fail( 'Another drop-in transaction is active or the lock file is unsafe.' ); }
		try {
		$t = $this->target(); if ( ! $this->identity( $t ) || ! $this->is_known_dropin_path( $t ) ) { return $this->fail( 'Restore requires the active signed Beplus drop-in.' ); }
		$mf = $this->backup_dir() . '/restore-manifest.json'; $m = json_decode( (string) @file_get_contents( $mf ), true );
		if ( ! is_array( $m ) || empty( $m['backup'] ) || basename( $m['backup'] ) !== $m['backup'] || empty( $m['mac'] ) || ! hash_equals( (string) $m['mac'], (string) $this->manifest_mac( $m ) ) ) { return $this->fail( 'Restore manifest is invalid or unauthenticated.' ); }
		$b = $this->backup_dir() . '/' . $m['backup']; $bid = $this->identity( $b );
		$encoded = $bid ? @file_get_contents( $b ) : false; $bytes = false === $encoded ? false : base64_decode( $encoded, true );
		if ( ! $this->canonical_inside( $b ) || ! $bid || false === $bytes || ! $this->same_identity( $b, $bid ) || ! hash_equals( (string) $m['sha256'], hash( 'sha256', $bytes ) ) ) { return $this->fail( 'Restore backup failed verification.' ); }
		$current = $this->backup_file( $t, 'beplus-current', $b ); if ( empty( $current['success'] ) ) { return $this->fail( 'Could not back up the current Beplus drop-in; restore was not attempted.' ); }
		$meta = isset( $m['metadata'] ) && is_array( $m['metadata'] ) ? $m['metadata'] : false; $tmp = $this->write_temp( $bytes, '.bepluspb-restore-' );
		if ( ! $tmp || ! is_array( $meta ) || ! isset( $meta['mode'], $meta['mtime'] ) || ! preg_match( '/^0[0-7]{3}$/', (string) $meta['mode'] ) || ! is_numeric( $meta['mtime'] ) || ! $this->preserve( $tmp, array( 'mode' => octdec( (string) $meta['mode'] ), 'uid' => $meta['uid'] ?? null, 'gid' => $meta['gid'] ?? null, 'mtime' => (int) $meta['mtime'] ), true ) ) { if ( $tmp ) { @unlink( $tmp ); } return $this->fail( 'Authenticated backup metadata is invalid or could not be restored.' ); }
		$rollback = $this->content_dir . '/.bepluspb-restore-rollback-' . bin2hex( random_bytes( 4 ) );
		if ( ! $tmp || ! $this->syntax_valid( $tmp ) ) { if ( $tmp ) { @unlink( $tmp ); } return $this->fail( 'Restore validation failed before activation; the active drop-in was unchanged.', array( 'rolled_back' => true ) ); }
		if ( ! $this->activate( $tmp, $rollback, 'before_restore_activate' ) ) { @unlink( $tmp ); return $this->fail( 'Restore activation failed; the active drop-in was unchanged or rolled back.', array( 'rolled_back' => is_file( $t ) ) ); }
		if ( ! hash_equals( (string) $m['sha256'], (string) hash_file( 'sha256', $t ) ) || ! $this->health_probe( false ) ) { $rb = $this->rollback( $rollback ); return $this->fail( 'Restore health verification failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) ); }
		@unlink( $rollback );
		return array( 'success' => true, 'message' => 'The authenticated previous drop-in was restored and the isolated cache health probe passed.', 'current_backup' => $current['path'] );
		} finally { $this->release_lock( $lock ); }
	}
}
