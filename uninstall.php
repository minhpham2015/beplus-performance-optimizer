<?php
/**
 * Fired when the plugin is deleted (not just deactivated).
 *
 * Cleans up ALL data created by Beplus Performance Booster:
 *  - The `bepluspb_settings` option from the database.
 *  - Browser-caching rules from .htaccess.
 *  - All minified/cached CSS and JS files from the cache directory.
 *  - The `_bepluspb_disable_cache` post meta from every post/page.
 *
 * @package Beplus_Performance_Booster
 * @see https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// WordPress sets WP_UNINSTALL_PLUGIN before including this file.
// Bail immediately if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// 1. Remove plugin options
// ---------------------------------------------------------------------------

delete_option( 'bepluspb_settings' );

// Remove the cache-stats transient (60 s TTL, but clean up explicitly on uninstall).
delete_transient( 'bepluspb_cache_stats' );

// ---------------------------------------------------------------------------
// 2. Remove .htaccess rules
// ---------------------------------------------------------------------------

if ( ! defined( 'BEPLUSPB_PLUGIN_DIR' ) ) {
	define( 'BEPLUSPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

$bepluspb_htaccess_class = BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-htaccess.php';
if ( file_exists( $bepluspb_htaccess_class ) ) {
	require_once $bepluspb_htaccess_class;
	BEPLUSPB_Htaccess::remove_rules();
}

// ---------------------------------------------------------------------------
// 2b. Remove Object Cache drop-in and config file
// ---------------------------------------------------------------------------

$bepluspb_oc_class = BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-object-cache.php';
if ( file_exists( $bepluspb_oc_class ) ) {
	require_once $bepluspb_oc_class;
	BEPLUSPB_Object_Cache::uninstall_dropin();
	BEPLUSPB_Object_Cache::delete_config();
}

// ---------------------------------------------------------------------------
// 2c. Remove the Page Cache drop-in, config, schedule and stored pages
// ---------------------------------------------------------------------------

$bepluspb_pc_class = BEPLUSPB_PLUGIN_DIR . 'includes/class-bepluspb-page-cache.php';
if ( file_exists( $bepluspb_pc_class ) ) {
	if ( ! defined( 'BEPLUSPB_OPTIONS_KEY' ) ) {
		define( 'BEPLUSPB_OPTIONS_KEY', 'bepluspb_settings' );
	}
	require_once $bepluspb_pc_class;
	BEPLUSPB_Page_Cache::shutdown();
	// shutdown() empties the cache; remove the now-empty root and its protection files.
	$bepluspb_pc_root = BEPLUSPB_Page_Cache_Engine::cache_root();
	foreach ( array( 'index.php', '.htaccess', '.gc' ) as $bepluspb_pc_support ) {
		if ( file_exists( $bepluspb_pc_root . '/' . $bepluspb_pc_support ) ) {
			wp_delete_file( $bepluspb_pc_root . '/' . $bepluspb_pc_support );
		}
	}
	if ( is_dir( $bepluspb_pc_root ) ) {
		@rmdir( $bepluspb_pc_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only succeeds when empty.
	}
}

// ---------------------------------------------------------------------------
// 3. Clear the CSS/JS minification cache directory
// ---------------------------------------------------------------------------

if ( ! defined( 'BEPLUSPB_CACHE_DIR' ) ) {
	// Must match the define() in beplus-performance-booster.php exactly.
	define( 'BEPLUSPB_CACHE_DIR', wp_upload_dir()['basedir'] . '/bepluspb-cache/' );
}

$bepluspb_cache_dir = BEPLUSPB_CACHE_DIR;

if ( file_exists( $bepluspb_cache_dir ) && is_dir( $bepluspb_cache_dir ) ) {
	// Delete all cached CSS and JS files.
	$bepluspb_cached_files = glob( $bepluspb_cache_dir . '*.{css,js}', GLOB_BRACE );
	if ( is_array( $bepluspb_cached_files ) ) {
		foreach ( $bepluspb_cached_files as $bepluspb_file ) {
			if ( is_file( $bepluspb_file ) ) {
				wp_delete_file( $bepluspb_file );
			}
		}
	}

	// Remove plugin-created support files.
	foreach ( array( 'index.php', '.htaccess' ) as $bepluspb_support_file ) {
		$bepluspb_path = $bepluspb_cache_dir . $bepluspb_support_file;
		if ( file_exists( $bepluspb_path ) ) {
			wp_delete_file( $bepluspb_path );
		}
	}

	// Remove the directory itself if it is now empty.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->rmdir( $bepluspb_cache_dir );
}

// ---------------------------------------------------------------------------
// 4. Delete _bepluspb_disable_cache post meta from every post
// ---------------------------------------------------------------------------

// Use a direct DB query for efficiency — avoids loading every post into memory.
global $wpdb;

$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->postmeta,
	array( 'meta_key' => '_bepluspb_disable_cache' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup, no alternative
	array( '%s' )
);
