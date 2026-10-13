<?php
/**
 * Beplus Performance Booster Object Cache Drop-in.
 * WordPress Object Cache Drop-in — powered by Beplus Performance Booster.
 *
 * Loaded automatically by WordPress when placed at wp-content/object-cache.php.
 * Connects to Redis or Memcached and fulfils the WP persistent cache API.
 * Falls back silently to the default in-memory cache when the backend is
 * unavailable so the site never breaks.
 *
 * @package Beplus_Performance_Booster
 * @since   1.1.0
 */

defined( 'WPINC' ) || exit;

/** Runtime identity used by the isolated post-install probe. */
if ( ! defined( 'BEPLUSPB_DROPIN_BUILD_ID' ) ) {
	define( 'BEPLUSPB_DROPIN_BUILD_ID', 'bepluspb-1.1.13-20261013' );
}

// ---------------------------------------------------------------------------
// Load configuration written by the plugin on settings save.
// ---------------------------------------------------------------------------
//
// IMPORTANT: WordPress core loads this file via `require_once` from
// *inside* wp_start_object_cache() (wp-includes/load.php), so top-level
// variables here are local to that function's scope by default — NOT
// real PHP globals, even though wp_cache_init() below does
// `global $_bepluspb_oc_cfg;`. Without the `global` declaration up
// front, wp_cache_init() would see an uninitialized global (null),
// silently breaking the Redis/Memcached connection while everything
// else (Test Connection, Install) reports success. See CLAUDE.md
// "Known bugs" (2026-09-15) for the full trace of how this was found.
global $_bepluspb_oc_cfg;

$_bepluspb_oc_cfg = array(
	'enabled'               => false,
	'driver'                => 'redis',
	'host'                  => '127.0.0.1',
	'port'                  => 6379,
	'password'              => '',
	'db'                    => 0,
	'persistent'            => true,
	'global_groups'         => array( 'userlogins', 'useremail', 'user_meta', 'site-options' ),
	'non_persistent_groups' => array( 'users', 'site-transient', 'comment', 'counts', 'plugins' ),
);

$_bepluspb_oc_config_file = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/.bepluspb_oc.php' : '';
$_bepluspb_oc_guard       = "<?php exit; ?>\n";

if ( $_bepluspb_oc_config_file && file_exists( $_bepluspb_oc_config_file ) ) {
	$_bepluspb_oc_json = file_get_contents( $_bepluspb_oc_config_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( $_bepluspb_oc_json && 0 === strpos( $_bepluspb_oc_json, $_bepluspb_oc_guard ) ) {
		$_bepluspb_oc_parsed = json_decode( substr( $_bepluspb_oc_json, strlen( $_bepluspb_oc_guard ) ), true );
		if ( is_array( $_bepluspb_oc_parsed ) ) {
			$_bepluspb_oc_cfg = array_merge( $_bepluspb_oc_cfg, $_bepluspb_oc_parsed );
		}
	}
}

// If Object Cache is disabled in config, fall back to WP default.
if ( empty( $_bepluspb_oc_cfg['enabled'] ) ) {
	require_once ABSPATH . WPINC . '/cache.php';
	return;
}

// ---------------------------------------------------------------------------
// WP_Object_Cache class.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Object_Cache' ) ) :

	/**
	 * Core class that implements an object cache backed by Redis or Memcached.
	 *
	 * @since 1.1.0
	 */
	class WP_Object_Cache {

		/**
		 * Backend client (Redis|Memcached|null).
		 *
		 * @var object|null
		 */
		private $client = null;

		/**
		 * Driver name: 'redis' or 'memcached'.
		 *
		 * @var string
		 */
		private $driver;

		/**
		 * Whether the backend connection is alive.
		 *
		 * @var bool
		 */
		private $connected = false;

		/**
		 * In-memory cache for the current request.
		 *
		 * @var array
		 */
		private $cache = array();

		/**
		 * Groups stored globally across blog IDs (Multisite).
		 *
		 * @var array
		 */
		private $global_groups = array();

		/**
		 * Groups that are never persisted to the backend.
		 *
		 * @var array
		 */
		private $non_persistent_groups = array();

		/**
		 * Current blog ID prefix for cache keys.
		 *
		 * @var string
		 */
		private $blog_prefix;

		/**
		 * Site-wide cache key salt (from WP_CACHE_KEY_SALT constant if defined).
		 *
		 * @var string
		 */
		private $salt;

		/**
		 * Cache hits counter.
		 *
		 * @var int
		 */
		public $cache_hits = 0;

		/**
		 * Memcached flush generation (part of every key; bumping it orphans old keys).
		 *
		 * @var string
		 */
		private $flush_gen = '0';

		/**
		 * Cache misses counter.
		 *
		 * @var int
		 */
		public $cache_misses = 0;

		/**
		 * Constructor.
		 *
		 * @param array $cfg Configuration from .bepluspb_oc.json.
		 */
		public function __construct( $cfg ) {
			global $blog_id;

			$this->driver                = isset( $cfg['driver'] ) ? $cfg['driver'] : 'redis';
			$this->blog_prefix           = is_multisite() ? (int) $blog_id . ':' : '';
			$site_root                   = realpath( ABSPATH );
			$site_root                   = false !== $site_root ? $site_root : ABSPATH;
			$this->salt                  = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : 'bepluspb-' . substr( hash( 'sha256', $site_root ), 0, 20 );
			$this->global_groups         = ! empty( $cfg['global_groups'] ) ? (array) $cfg['global_groups'] : array();
			$this->non_persistent_groups = ! empty( $cfg['non_persistent_groups'] ) ? (array) $cfg['non_persistent_groups'] : array();

			$this->_connect( $cfg );
		}

		// -------------------------------------------------------------------------
		// Connection
		// -------------------------------------------------------------------------

		/**
		 * Establish connection to Redis or Memcached.
		 *
		 * @param array $cfg Configuration array.
		 */
		private function _connect( $cfg ) {
			$host       = isset( $cfg['host'] ) ? $cfg['host'] : '127.0.0.1';
			$port       = isset( $cfg['port'] ) ? (int) $cfg['port'] : 6379;
			$password   = isset( $cfg['password'] ) ? $cfg['password'] : '';
			$db         = isset( $cfg['db'] ) ? (int) $cfg['db'] : 0;
			$persistent = ! empty( $cfg['persistent'] );

			try {
				if ( 'redis' === $this->driver ) {
					if ( ! class_exists( 'Redis' ) ) {
						return;
					}
					$this->client = new Redis();
					if ( $persistent ) {
						$this->client->pconnect( $host, $port );
					} else {
						$this->client->connect( $host, $port );
					}
					if ( $password ) {
						$this->client->auth( $password );
					}
					if ( $db ) {
						$this->client->select( $db );
					}
					$this->client->ping();
					$this->connected = true;

				} elseif ( 'memcached' === $this->driver ) {
					if ( ! class_exists( 'Memcached' ) ) {
						return;
					}
					$pid          = $persistent ? 'bepluspb-' . substr( hash( 'sha256', $this->salt ), 0, 16 ) : null;
					$this->client = new Memcached( $pid );
					if ( empty( $this->client->getServerList() ) ) {
						$this->client->addServer( $host, $port );
					}
					$this->connected = true;
					$gen             = $this->client->get( $this->salt . ':__flush_gen' );
					$this->flush_gen = $gen ? (string) $gen : '0';
				}
			} catch ( Exception $e ) {
				$this->connected = false;
				$this->client    = null;
			}
		}

		// -------------------------------------------------------------------------
		// Key helpers
		// -------------------------------------------------------------------------

		/**
		 * Build a backend cache key.
		 *
		 * @param  string $key   Cache key.
		 * @param  string $group Cache group.
		 * @return string
		 */
		private function _key( $key, $group ) {
			$prefix = $this->_is_global( $group ) ? '' : $this->blog_prefix;
			$gen    = 'memcached' === $this->driver ? 'g' . $this->flush_gen . ':' : '';
			return $this->salt . ':' . $gen . $prefix . $group . ':' . $key;
		}

		/**
		 * Whether a group should not be persisted.
		 *
		 * @param  string $group Group name.
		 * @return bool
		 */
		private function _is_non_persistent( $group ) {
			return in_array( $group, $this->non_persistent_groups, true );
		}

		/**
		 * Whether a group is global (not blog-prefixed).
		 *
		 * @param  string $group Group name.
		 * @return bool
		 */
		private function _is_global( $group ) {
			return in_array( $group, $this->global_groups, true );
		}

		// -------------------------------------------------------------------------
		// Cache API
		// -------------------------------------------------------------------------

		/**
		 * Adds data to the cache if the key doesn't already exist.
		 *
		 * @param  string $key    Cache key.
		 * @param  mixed  $data   Data to cache.
		 * @param  string $group  Cache group.
		 * @param  int    $expire Expiry in seconds (0 = no expiry).
		 * @return bool
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			if ( $this->_get_from_memory( $key, $group ) !== false ) {
				return false;
			}
			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Sets data in the cache.
		 *
		 * @param  string $key    Cache key.
		 * @param  mixed  $data   Data to cache.
		 * @param  string $group  Cache group.
		 * @param  int    $expire Expiry in seconds (0 = no expiry).
		 * @return bool
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			$this->_store_in_memory( $key, $data, $group );

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				return true;
			}

			$backend_key = $this->_key( $key, $group );
			$payload     = serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

			try {
				if ( 'redis' === $this->driver ) {
					if ( $expire ) {
						return (bool) $this->client->setEx( $backend_key, $expire, $payload );
					}
					return (bool) $this->client->set( $backend_key, $payload );
				} else {
					return (bool) $this->client->set( $backend_key, $payload, $expire );
				}
			} catch ( Exception $e ) {
				return true; // Return true — in-memory set succeeded.
			}
		}

		/**
		 * Retrieves data from the cache.
		 *
		 * @param  string $key    Cache key.
		 * @param  string $group  Cache group.
		 * @param  bool   $force  Whether to force an update of the local cache.
		 * @param  bool   &$found  Whether the key was found.
		 * @return mixed|false Data on success, false on failure.
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			if ( ! $force ) {
				$from_memory = $this->_get_from_memory( $key, $group );
				if ( false !== $from_memory ) {
					++$this->cache_hits;
					$found = true;
					return $from_memory;
				}
			}

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				++$this->cache_misses;
				$found = false;
				return false;
			}

			try {
				$backend_key = $this->_key( $key, $group );
				$raw         = ( 'redis' === $this->driver )
				? $this->client->get( $backend_key )
				: $this->client->get( $backend_key );

				if ( false === $raw || null === $raw ) {
					++$this->cache_misses;
					$found = false;
					return false;
				}

				// Restrict unserialize() to scalars/arrays only (allowed_classes => false).
				// This blocks PHP Object Injection if a stored value is ever tampered with
				// (e.g. Redis reachable by another tenant, or credentials leaked) — the cost
				// is that any previously-cached PHP object comes back as an
				// __PHP_Incomplete_Class stub instead of a real object. WordPress core only
				// caches arrays/scalars in the groups this drop-in treats specially, so this
				// is safe for normal WP usage; a plugin caching objects directly should
				// serialize them to an array itself before calling wp_cache_set().
				$data = unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$this->_store_in_memory( $key, $data, $group );
				++$this->cache_hits;
				$found = true;
				return $data;
			} catch ( Exception $e ) {
				++$this->cache_misses;
				$found = false;
				return false;
			}
		}

		/**
		 * Deletes data from the cache.
		 *
		 * @param  string $key   Cache key.
		 * @param  string $group Cache group.
		 * @return bool
		 */
		public function delete( $key, $group = 'default' ) {
			$this->_delete_from_memory( $key, $group );

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				return true;
			}

			try {
				$backend_key = $this->_key( $key, $group );
				if ( 'redis' === $this->driver ) {
					return (bool) $this->client->del( $backend_key );
				} else {
					return (bool) $this->client->delete( $backend_key );
				}
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Increments a numeric item's value.
		 *
		 * @param  string $key    Cache key.
		 * @param  int    $offset Increment amount.
		 * @param  string $group  Cache group.
		 * @return int|false
		 */
		public function incr( $key, $offset = 1, $group = 'default' ) {
			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				return false;
			}
			try {
				$backend_key = $this->_key( $key, $group );
				if ( 'redis' === $this->driver ) {
					return $this->client->incrBy( $backend_key, $offset );
				} else {
					return $this->client->increment( $backend_key, $offset );
				}
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Decrements a numeric item's value.
		 *
		 * @param  string $key    Cache key.
		 * @param  int    $offset Decrement amount.
		 * @param  string $group  Cache group.
		 * @return int|false
		 */
		public function decr( $key, $offset = 1, $group = 'default' ) {
			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				return false;
			}
			try {
				$backend_key = $this->_key( $key, $group );
				if ( 'redis' === $this->driver ) {
					return $this->client->decrBy( $backend_key, $offset );
				} else {
					return $this->client->decrement( $backend_key, $offset );
				}
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Retrieves several items in one backend round trip (MGET / getMulti).
		 *
		 * @param  array  $keys  Cache keys.
		 * @param  string $group Cache group.
		 * @param  bool   $force Skip the local copy and re-read from the backend.
		 * @return array key => value (false for misses).
		 */
		public function get_multiple( $keys, $group = 'default', $force = false ) {
			$values  = array();
			$missing = array();

			foreach ( $keys as $key ) {
				if ( ! $force ) {
					$from_memory = $this->_get_from_memory( $key, $group );
					if ( false !== $from_memory ) {
						++$this->cache_hits;
						$values[ $key ] = $from_memory;
						continue;
					}
				}
				$values[ $key ] = false;
				$missing[]      = $key;
			}

			if ( ! $missing ) {
				return $values;
			}

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				$this->cache_misses += count( $missing );
				return $values;
			}

			try {
				$backend_keys = array();
				foreach ( $missing as $key ) {
					$backend_keys[] = $this->_key( $key, $group );
				}

				if ( 'redis' === $this->driver ) {
					$raws = $this->client->mGet( $backend_keys );
					$raws = is_array( $raws ) ? array_values( $raws ) : array();
				} else {
					$found = $this->client->getMulti( $backend_keys );
					$raws  = array();
					foreach ( $backend_keys as $i => $backend_key ) {
						$raws[ $i ] = is_array( $found ) && isset( $found[ $backend_key ] ) ? $found[ $backend_key ] : false;
					}
				}

				foreach ( $missing as $i => $key ) {
					$raw = isset( $raws[ $i ] ) ? $raws[ $i ] : false;
					if ( false === $raw || null === $raw ) {
						++$this->cache_misses;
						continue;
					}
					// Same object-injection restriction as get() — see the note there.
					$data = unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					$this->_store_in_memory( $key, $data, $group );
					++$this->cache_hits;
					$values[ $key ] = $data;
				}
			} catch ( Exception $e ) {
				$this->cache_misses += count( $missing );
			}

			return $values;
		}

		/**
		 * Sets several items; Redis writes are pipelined into one round trip.
		 *
		 * @param  array  $data   key => value.
		 * @param  string $group  Cache group.
		 * @param  int    $expire Expiry in seconds (0 = none).
		 * @return array key => bool.
		 */
		public function set_multiple( $data, $group = 'default', $expire = 0 ) {
			if ( ! $this->connected || $this->_is_non_persistent( $group ) || 'redis' !== $this->driver ) {
				$results = array();
				foreach ( $data as $key => $value ) {
					$results[ $key ] = $this->set( $key, $value, $group, $expire );
				}
				return $results;
			}

			$results = array();
			try {
				$pipe = $this->client->pipeline();
				foreach ( $data as $key => $value ) {
					$this->_store_in_memory( $key, $value, $group );
					$payload = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					if ( $expire ) {
						$pipe->setEx( $this->_key( $key, $group ), $expire, $payload );
					} else {
						$pipe->set( $this->_key( $key, $group ), $payload );
					}
				}
				$replies = $pipe->exec();
				$i       = 0;
				foreach ( $data as $key => $value ) {
					$results[ $key ] = ! is_array( $replies ) || ! empty( $replies[ $i ] );
					++$i;
				}
			} catch ( Exception $e ) {
				foreach ( $data as $key => $value ) {
					$this->_store_in_memory( $key, $value, $group );
					$results[ $key ] = true; // In-memory copy succeeded, as in set().
				}
			}

			return $results;
		}

		/**
		 * Adds several items that don't already exist.
		 *
		 * @param  array  $data   key => value.
		 * @param  string $group  Cache group.
		 * @param  int    $expire Expiry in seconds (0 = none).
		 * @return array key => bool.
		 */
		public function add_multiple( $data, $group = 'default', $expire = 0 ) {
			$results = array();
			foreach ( $data as $key => $value ) {
				$results[ $key ] = $this->add( $key, $value, $group, $expire );
			}
			return $results;
		}

		/**
		 * Deletes several items; Redis deletes are pipelined into one round trip.
		 *
		 * @param  array  $keys  Cache keys.
		 * @param  string $group Cache group.
		 * @return array key => bool.
		 */
		public function delete_multiple( $keys, $group = 'default' ) {
			$results = array();

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				foreach ( $keys as $key ) {
					$this->_delete_from_memory( $key, $group );
					$results[ $key ] = true;
				}
				return $results;
			}

			try {
				if ( 'redis' === $this->driver ) {
					$pipe = $this->client->pipeline();
					foreach ( $keys as $key ) {
						$this->_delete_from_memory( $key, $group );
						$pipe->del( $this->_key( $key, $group ) );
					}
					$replies = $pipe->exec();
					$i       = 0;
					foreach ( $keys as $key ) {
						$results[ $key ] = ! is_array( $replies ) || ! empty( $replies[ $i ] );
						++$i;
					}
				} else {
					$backend_keys = array();
					foreach ( $keys as $key ) {
						$this->_delete_from_memory( $key, $group );
						$backend_keys[ $key ] = $this->_key( $key, $group );
					}
					$replies = $this->client->deleteMulti( array_values( $backend_keys ) );
					foreach ( $backend_keys as $key => $backend_key ) {
						$results[ $key ] = is_array( $replies ) && isset( $replies[ $backend_key ] ) && true === $replies[ $backend_key ];
					}
				}
			} catch ( Exception $e ) {
				foreach ( $keys as $key ) {
					$results[ $key ] = false;
				}
			}

			return $results;
		}

		/**
		 * Whether a cache API feature is available with the current backend.
		 *
		 * @param  string $feature Feature name.
		 * @return bool
		 */
		public function supports( $feature ) {
			switch ( $feature ) {
				case 'add_multiple':
				case 'set_multiple':
				case 'get_multiple':
				case 'delete_multiple':
				case 'flush_runtime':
					return true;
				case 'flush_group':
					return 'redis' === $this->driver;
				default:
					return false;
			}
		}

		/**
		 * Clears only the per-request in-memory copy; the backend is untouched.
		 *
		 * @return bool
		 */
		public function flush_runtime() {
			$this->cache = array();
			return true;
		}

		/**
		 * Flushes one group. Redis only (SCAN of this site's keys in the group);
		 * Memcached cannot enumerate keys, so it reports false.
		 *
		 * @param  string $group Cache group.
		 * @return bool
		 */
		public function flush_group( $group ) {
			unset( $this->cache[ $group ] );

			if ( ! $this->connected || $this->_is_non_persistent( $group ) ) {
				return true;
			}
			if ( 'redis' !== $this->driver ) {
				return false;
			}

			try {
				$pattern = preg_replace( '/([\\\\*?\\[\\]])/', '\\\\$1', $this->_key( '', $group ) ) . '*';
				$it      = null;
				do {
					$keys = $this->client->scan( $it, $pattern, 500 );
					if ( is_array( $keys ) && $keys ) {
						if ( method_exists( $this->client, 'unlink' ) ) {
							$this->client->unlink( $keys );
						} else {
							$this->client->del( $keys );
						}
					}
				} while ( $it > 0 );
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Flushes the entire object cache.
		 *
		 * @return bool
		 */
		public function flush() {
			$this->cache = array();

			if ( ! $this->connected ) {
				return true;
			}

			// Scope the flush to THIS site's key namespace so other sites/apps sharing the
			// backend are untouched (core calls wp_cache_flush() on theme switch, imports, etc.).
			try {
				if ( 'redis' === $this->driver ) {
					$pattern = preg_replace( '/([\\\\*?\\[\\]])/', '\\\\$1', $this->salt ) . ':*';
					$it      = null;
					do {
						$keys = $this->client->scan( $it, $pattern, 500 );
						if ( is_array( $keys ) && $keys ) {
							if ( method_exists( $this->client, 'unlink' ) ) {
								$this->client->unlink( $keys );
							} else {
								$this->client->del( $keys );
							}
						}
					} while ( $it > 0 );
					return true;
				}
				$gk = $this->salt . ':__flush_gen';
				$n  = $this->client->increment( $gk );
				if ( false === $n ) {
					$n = $this->client->add( $gk, 1 ) ? 1 : $this->client->increment( $gk );
				}
				if ( false === $n ) {
					return false;
				}
				$this->flush_gen = (string) $n;
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Replaces data in the cache only if the key already exists.
		 *
		 * @param  string $key    Cache key.
		 * @param  mixed  $data   Data to cache.
		 * @param  string $group  Cache group.
		 * @param  int    $expire Expiry in seconds.
		 * @return bool
		 */
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			if ( false === $this->get( $key, $group ) ) {
				return false;
			}
			return $this->set( $key, $data, $group, $expire );
		}

		/**
		 * Adds one or more groups to the list of global groups.
		 *
		 * @param string|array $groups Group name(s).
		 */
		public function add_global_groups( $groups ) {
			$this->global_groups = array_unique( array_merge( $this->global_groups, (array) $groups ) );
		}

		/**
		 * Adds one or more groups to the list of non-persistent groups.
		 *
		 * @param string|array $groups Group name(s).
		 */
		public function add_non_persistent_groups( $groups ) {
			$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, (array) $groups ) );
		}

		/**
		 * Switches internal blog prefix on Multisite.
		 *
		 * @param int $blog_id New blog ID.
		 */
		public function switch_to_blog( $blog_id ) {
			$this->blog_prefix = is_multisite() ? (int) $blog_id . ':' : '';
		}

		/**
		 * Returns cache stats for display.
		 *
		 * @return array
		 */
		public function get_stats() {
			return array(
				'connected' => $this->connected,
				'driver'    => $this->driver,
				'hits'      => $this->cache_hits,
				'misses'    => $this->cache_misses,
			);
		}

		// -------------------------------------------------------------------------
		// In-memory helpers
		// -------------------------------------------------------------------------

		/**
		 * Store value in local in-memory cache.
		 *
		 * @param string $key   Cache key.
		 * @param mixed  $value Value.
		 * @param string $group Group.
		 */
		private function _store_in_memory( $key, $value, $group ) {
			if ( ! isset( $this->cache[ $group ] ) ) {
				$this->cache[ $group ] = array();
			}
			$this->cache[ $group ][ $key ] = is_object( $value ) ? clone $value : $value;
		}

		/**
		 * Retrieve value from local in-memory cache.
		 *
		 * @param  string $key   Cache key.
		 * @param  string $group Group.
		 * @return mixed|false
		 */
		private function _get_from_memory( $key, $group ) {
			if ( isset( $this->cache[ $group ][ $key ] ) ) {
				$val = $this->cache[ $group ][ $key ];
				return is_object( $val ) ? clone $val : $val;
			}
			return false;
		}

		/**
		 * Delete value from local in-memory cache.
		 *
		 * @param string $key   Cache key.
		 * @param string $group Group.
		 */
		private function _delete_from_memory( $key, $group ) {
			unset( $this->cache[ $group ][ $key ] );
		}
	}

endif; // class_exists( 'WP_Object_Cache' ). phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- referencing the guard condition by name, not commented-out code.

// ---------------------------------------------------------------------------
// Global WP cache functions (required API surface).
// ---------------------------------------------------------------------------

global $wp_object_cache, $_bepluspb_oc_cfg;

/**
 * Initialise the object cache.
 */
function wp_cache_init() {
	global $wp_object_cache, $_bepluspb_oc_cfg;
	$wp_object_cache = new WP_Object_Cache( $_bepluspb_oc_cfg );
}

/**
 * Add data to the cache if it doesn't already exist.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group. Default 'default'.
 * @param int        $expire Expire time in seconds. Default 0.
 * @return bool
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group ? $group : 'default', (int) $expire );
}

/**
 * Replace a value in the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group.
 * @param int        $expire Expire time in seconds.
 * @return bool
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $data, $group ? $group : 'default', (int) $expire );
}

/**
 * Save data to the cache.
 *
 * @param int|string $key    Cache key.
 * @param mixed      $data   Cache data.
 * @param string     $group  Cache group. Default 'default'.
 * @param int        $expire Expire time in seconds. Default 0.
 * @return bool
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group ? $group : 'default', (int) $expire );
}

/**
 * Retrieve the cache contents from the cache by key and group.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group. Default 'default'.
 * @param bool       $force Optional. Whether to force an update of the local cache. Default false.
 * @param bool       &$found Optional. Whether the key was found in the cache. Default null.
 * @return mixed|false
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group ? $group : 'default', $force, $found );
}

/**
 * Remove the cache contents matching key and group.
 *
 * @param int|string $key   Cache key.
 * @param string     $group Cache group. Default 'default'.
 * @param int        $deprecated Deprecated. Not used.
 * @return bool
 */
function wp_cache_delete( $key, $group = '', $deprecated = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the wp_cache_delete() core API signature.
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group ? $group : 'default' );
}

/**
 * Increment numeric cache item's value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset The amount by which to increment the item's value.
 * @param string     $group  Cache group. Default 'default'.
 * @return int|false
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group ? $group : 'default' );
}

/**
 * Decrement numeric cache item's value.
 *
 * @param int|string $key    Cache key.
 * @param int        $offset The amount by which to decrement the item's value.
 * @param string     $group  Cache group.
 * @return int|false
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group ? $group : 'default' );
}

/**
 * Remove all cache items.
 *
 * @return bool
 */
function wp_cache_flush() {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

/**
 * Retrieve several cache items at once.
 *
 * @param array  $keys  Cache keys.
 * @param string $group Cache group.
 * @param bool   $force Whether to bypass the local copy.
 * @return array
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group ? $group : 'default', $force );
}

/**
 * Save several cache items at once.
 *
 * @param array  $data   key => value.
 * @param string $group  Cache group.
 * @param int    $expire Expire time in seconds.
 * @return array
 */
function wp_cache_set_multiple( $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group ? $group : 'default', (int) $expire );
}

/**
 * Add several cache items at once.
 *
 * @param array  $data   key => value.
 * @param string $group  Cache group.
 * @param int    $expire Expire time in seconds.
 * @return array
 */
function wp_cache_add_multiple( $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group ? $group : 'default', (int) $expire );
}

/**
 * Delete several cache items at once.
 *
 * @param array  $keys  Cache keys.
 * @param string $group Cache group.
 * @return array
 */
function wp_cache_delete_multiple( $keys, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group ? $group : 'default' );
}

/**
 * Clear the per-request in-memory cache only.
 *
 * @return bool
 */
function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}

/**
 * Remove all items in one cache group.
 *
 * @param string $group Cache group.
 * @return bool
 */
function wp_cache_flush_group( $group ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}

/**
 * Whether this cache implementation supports a feature.
 *
 * @param string $feature Feature name.
 * @return bool
 */
function wp_cache_supports( $feature ) {
	global $wp_object_cache;
	return $wp_object_cache->supports( $feature );
}

/**
 * Closes the cache.
 *
 * @return bool
 */
function wp_cache_close() {
	return true;
}

/**
 * Add a group or set of groups to the list of global groups.
 *
 * @param string|array $groups Group name(s).
 */
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

/**
 * Add a group or set of groups to the list of non-persistent groups.
 *
 * @param string|array $groups Group name(s).
 */
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

/**
 * Switch the internal blog ID. Used in Multisite.
 *
 * @param int $blog_id Blog ID.
 */
function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

// Initialise immediately.
wp_cache_init();
