<?php
/**
 * Safe foreign object-cache drop-in backup/replace/restore workflow.
 *
 * @package Beplus_Performance_Booster
 */

// phpcs:disable -- Atomic same-directory rename, fsync, exclusive creation and metadata preservation require direct filesystem primitives.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
class BEPLUSPB_Dropin_Workflow {
	const SIGNATURE = '// Beplus Performance Booster Object Cache Drop-in';
	private $content_dir;
	private $source;
	private $hooks;
	public function __construct( $content_dir, $source, $hooks = array() ) {
		$this->content_dir = rtrim( $content_dir, '/\\' );
		$this->source      = $source;
		$this->hooks       = $hooks; }
	private function hook( $name, $default = true, ...$args ) {
		return isset( $this->hooks[ $name ] ) ? call_user_func( $this->hooks[ $name ], ...$args ) : $default; }
	private function target() {
		return $this->content_dir . '/object-cache.php'; }
	private function backup_dir() {
		return $this->content_dir . '/bepluspb-backups'; }
	private function fail( $message, $extra = array() ) {
		return array_merge(
			array(
				'success' => false,
				'message' => $message,
			),
			$extra
		); }
	private function canonical_inside( $path, $allow_missing = false ) {
		$root = realpath( $this->content_dir );
		$real = realpath( $path );
		if ( false === $root || ( false === $real && ! $allow_missing ) ) {
			return false;
		}
		if ( false === $real ) {
			$real = realpath( dirname( $path ) ) . '/' . basename( $path );
		}
		return $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR );
	}
	private function readable( $path ) {
		return $this->hook( 'readable', is_readable( $path ), $path ); }
	private function source_valid() {
		if ( ! is_file( $this->source ) || is_link( $this->source ) || ! $this->readable( $this->source ) ) {
			return false;
		} $h = file_get_contents( $this->source, false, null, 0, 512 );
		return false !== $h && false !== strpos( $h, self::SIGNATURE ); }
	private function syntax_valid( $path ) {
		if ( ! function_exists( 'exec' ) ) {
			return true;
		} $out = array();
		$rc    = 0;
		@exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $out, $rc );
		return 0 === $rc;
	}
	public function provider( $path ) {
		$h = @file_get_contents( $path, false, null, 0, 4096 );
		if ( false === stripos( (string) $h, 'redis' ) ) {
			return false === stripos( (string) $h, 'memcached' ) ? 'Unknown' : 'Memcached';
		} return 'Redis Object Cache'; }
	public function preflight( $operation = 'replace' ) {
		$t    = $this->target();
		$root = realpath( $this->content_dir );
		if ( false === $root || ! $this->canonical_inside( $t, true ) ) {
			return $this->fail( 'The drop-in path is outside the canonical wp-content directory.' );
		}
		if ( is_link( $t ) ) {
			return $this->fail( 'The existing drop-in is a symbolic link and cannot be changed safely.' );
		}
		if ( file_exists( $t ) && ( ! is_file( $t ) || ! $this->readable( $t ) ) ) {
			return $this->fail( 'The existing drop-in is not a readable regular file.' );
		}
		if ( file_exists( $t ) && ! $this->hook( 'target_writable', is_writable( $t ), $t ) ) {
			return $this->fail( 'The existing drop-in is not writable by PHP (it may be host-managed). Ask the host to replace it manually.' );
		}
		if ( ! $this->hook( 'directory_writable', is_writable( $this->content_dir ), $this->content_dir ) ) {
			return $this->fail( 'The wp-content directory is not writable by PHP. Ask the host to replace the file manually.' );
		}
		if ( ! $this->source_valid() || ! $this->syntax_valid( $this->source ) ) {
			return $this->fail( 'The bundled Beplus drop-in is unreadable or invalid.' );
		}
		$need = @filesize( $this->source ) + ( @filesize( $t ) ?: 0 ) + 8192;
		$free = @disk_free_space( $this->content_dir );
		if ( false !== $free && $free < $need ) {
			return $this->fail( 'Insufficient free space for a safe backup and atomic replacement.' );
		}
		$tmp = @tempnam( $this->content_dir, '.bepluspb-check-' );
		if ( false === $tmp ) {
			return $this->fail( 'A same-directory temporary file cannot be created.' );
		} @unlink( $tmp );
		$backend = $this->hook( 'backend_test', array( 'success' => false ) );
		if ( empty( $backend['success'] ) ) {
			return $this->fail( 'The configured object-cache backend connection test failed.' );
		}
		return array(
			'success'  => true,
			'message'  => 'Safety checks passed; this does not guarantee replacement will succeed.',
			'provider' => file_exists( $t ) ? $this->provider( $t ) : 'None',
		);
	}
	private function ensure_backup_dir() {
		$d = $this->backup_dir();
		if ( ! is_dir( $d ) && ! @mkdir( $d, 0700, true ) ) {
			return false;
		} @chmod( $d, 0700 );
		@file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" );
		@file_put_contents( $d . '/index.php', "<?php http_response_code(404); exit;\n" );
		return $this->canonical_inside( $d ); }
	private function token() {
		return (string) $this->hook( 'unique_token', gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) ); }
	public function backup_file( $source, $kind = 'foreign' ) {
		if ( ! $this->canonical_inside( $source ) || is_link( $source ) || ! is_file( $source ) || ! $this->readable( $source ) || ! $this->ensure_backup_dir() ) {
			return $this->fail( 'Backup source or directory is unsafe.' );
		}
		$base = preg_replace( '/[^a-z0-9_-]/i', '', $kind ) . '-' . $this->token();
		$path = $this->backup_dir() . '/' . $base . '.php';
		$i    = 0;
		while ( file_exists( $path ) ) {
			$path = $this->backup_dir() . '/' . $base . '-' . ( ++$i ) . '.php'; }
		$in  = @fopen( $source, 'rb' );
		$out = @fopen( $path, 'x+b' );
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}if ( $out ) {
				fclose( $out );
			}return $this->fail( 'Could not create the backup.' ); }
		@chmod( $path, 0600 );
		$ok = false !== stream_copy_to_stream( $in, $out );
		@fflush( $out );
		if ( function_exists( 'fsync' ) ) {
			@fsync( $out );
		} fclose( $in );
		fclose( $out );
		$this->hook( 'after_backup_copy', true, $path );
		$hash = hash_file( 'sha256', $source );
		if ( ! $ok || ! hash_equals( $hash, (string) hash_file( 'sha256', $path ) ) ) {
			@unlink( $path );
			return $this->fail( 'Backup checksum verification failed.' );}
		$st   = @stat( $source );
		$meta = array(
			'source_basename' => basename( $source ),
			'sha256'          => $hash,
			'size'            => filesize( $source ),
			'mode'            => $st ? sprintf( '%04o', $st['mode'] & 0777 ) : null,
			'mtime'           => $st ? $st['mtime'] : null,
			'provider'        => $this->provider( $source ),
			'plugin_version'  => defined( 'BEPLUSPB_VERSION' ) ? BEPLUSPB_VERSION : 'unknown',
			'created_at'      => gmdate( 'c' ),
			'user_id'         => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'kind'            => $kind,
		);
		@file_put_contents( $path . '.json', wp_json_encode( $meta, JSON_PRETTY_PRINT ), LOCK_EX );
		@chmod( $path . '.json', 0600 );
		$this->retain();
		return array(
			'success' => true,
			'path'    => $path,
			'sha256'  => $hash,
		);
	}
	private function retain() {
		$f = glob( $this->backup_dir() . '/*.php' );
		if ( ! $f ) {
			return;
		} usort( $f, fn( $a, $b )=>filemtime( $b ) <=> filemtime( $a ) );
		foreach ( array_slice( $f, 3 ) as $p ) {
			@unlink( $p );
			@unlink( $p . '.json' );} }
	private function write_temp( $bytes, $prefix ) {
		$tmp = tempnam( $this->content_dir, $prefix );
		if ( false === $tmp ) {
			return false;
		} $fp = fopen( $tmp, 'wb' );
		if ( ! $fp ) {
			return false;
		}$ok = strlen( $bytes ) === fwrite( $fp, $bytes );
		fflush( $fp );
		if ( function_exists( 'fsync' ) ) {
			@fsync( $fp );
		}fclose( $fp );
		@chmod( $tmp, 0600 );
		return $ok ? $tmp : false; }
	private function preserve( $path, $st ) {
		if ( ! $st ) {
			return;
		} @chmod( $path, $st['mode'] & 0777 );
		if ( function_exists( 'chown' ) && function_exists( 'posix_geteuid' ) && fileowner( $path ) === posix_geteuid() ) {
			@chown( $path, $st['uid'] );
			@chgrp( $path, $st['gid'] );} }
	public function replace() {
		$p = $this->preflight( 'replace' );
		if ( empty( $p['success'] ) ) {
			return $p;
		}$t     = $this->target();
		$backup = null;
		$st     = @stat( $t );
		if ( file_exists( $t ) ) {
			$b = $this->backup_file( $t, 'foreign' );
			if ( empty( $b['success'] ) ) {
				return $b;
			}$backup = $b['path']; }
		$bytes = file_get_contents( $this->source );
		$tmp   = $this->write_temp( $bytes, '.bepluspb-new-' );
		if ( ! $tmp || ! $this->syntax_valid( $tmp ) || hash_file( 'sha256', $tmp ) !== hash( 'sha256', $bytes ) ) {
			if ( $tmp ) {
				@unlink( $tmp );
			}return $this->fail( 'Replacement validation failed.' );}
		$this->preserve( $tmp, $st );
		$rollback = $this->content_dir . '/.bepluspb-rollback-' . bin2hex( random_bytes( 4 ) );
		$moved    = ! file_exists( $t ) || @rename( $t, $rollback );
		if ( ! $moved || ! $this->hook( 'before_activate', true ) || ! @rename( $tmp, $t ) ) {
			@unlink( $tmp );
			$rb = ! file_exists( $rollback ) || @rename( $rollback, $t );
			return $this->fail( 'Replacement failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) );}
		$hash = hash( 'sha256', $bytes );
		if ( ! is_file( $t ) || ! hash_equals( $hash, (string) hash_file( 'sha256', $t ) ) || ! $this->source_valid_path( $t ) ) {
			@unlink( $t );
			$rb = ! file_exists( $rollback ) || @rename( $rollback, $t );
			return $this->fail( 'Post-replacement verification failed; rollback ' . ( $rb ? 'succeeded.' : 'failed.' ), array( 'rolled_back' => $rb ) );}
		@unlink( $rollback );
		if ( $backup ) {
			$manifest = array(
				'backup'     => basename( $backup ),
				'sha256'     => hash_file( 'sha256', $backup ),
				'created_at' => gmdate( 'c' ),
			);
			@file_put_contents( $this->backup_dir() . '/restore-manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ), LOCK_EX );
			@chmod( $this->backup_dir() . '/restore-manifest.json', 0600 );
		}return array(
			'success' => true,
			'message' => 'The previous drop-in was backed up and Beplus was installed. Verify site health; success is not guaranteed.',
			'backup'  => $backup,
		);
	}
	private function source_valid_path( $p ) {
		$h = @file_get_contents( $p, false, null, 0, 512 );
		return false !== $h && false !== strpos( $h, self::SIGNATURE );}
	public function restore() {
		$t = $this->target();
		if ( is_link( $t ) || ! is_file( $t ) || ! $this->source_valid_path( $t ) ) {
			return $this->fail( 'Restore requires the active signed Beplus drop-in.' );
		}$mf = $this->backup_dir() . '/restore-manifest.json';
		$m   = json_decode( (string) @file_get_contents( $mf ), true );
		if ( ! is_array( $m ) || empty( $m['backup'] ) || basename( $m['backup'] ) !== $m['backup'] ) {
			return $this->fail( 'Restore manifest is invalid.' );
		}$b = $this->backup_dir() . '/' . $m['backup'];
		if ( ! $this->canonical_inside( $b ) || ! is_file( $b ) || is_link( $b ) || ! hash_equals( (string) $m['sha256'], (string) hash_file( 'sha256', $b ) ) ) {
			return $this->fail( 'Restore backup failed verification.' );
		}
		$current = $this->backup_file( $t, 'beplus-current' );
		if ( empty( $current['success'] ) ) {
			return $this->fail( 'Could not back up the current Beplus drop-in; restore was not attempted.' );
		}$bytes   = file_get_contents( $b );
		$tmp      = $this->write_temp( $bytes, '.bepluspb-restore-' );
		$rollback = $this->content_dir . '/.bepluspb-restore-rollback-' . bin2hex( random_bytes( 4 ) );
		$moved    = $tmp && @rename( $t, $rollback );
		if ( ! $moved || ! $this->hook( 'before_restore_activate', true ) || ! @rename( $tmp, $t ) || ! hash_equals( (string) $m['sha256'], (string) hash_file( 'sha256', $t ) ) ) {
			if ( $tmp ) {
				@unlink( $tmp );
			}@unlink( $t );
			$rb = file_exists( $rollback ) && @rename( $rollback, $t );
			return $this->fail( 'Restore failed; rollback ' . ( $rb ? 'succeeded.' : 'failed and manual recovery is required.' ), array( 'rolled_back' => $rb ) );
		}@unlink( $rollback );
		return array(
			'success'        => true,
			'message'        => 'The verified previous drop-in was restored. Verify site health; success is not guaranteed.',
			'current_backup' => $current['path'],
		);
	}
}
