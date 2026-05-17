<?php
/**
 * Fired when the plugin is deleted (not just deactivated).
 *
 * Cleans up ALL data created by Site Optimizer by BePlus:
 *  - The `sob_settings` option from the database.
 *  - Browser-caching rules from .htaccess.
 *  - All minified/cached CSS and JS files from the cache directory.
 *  - The `_sob_disable_cache` post meta from every post/page.
 *
 * @package Site_Optimizer_BePlus
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

delete_option( 'sob_settings' );

// Remove the cache-stats transient (60 s TTL, but clean up explicitly on uninstall).
delete_transient( 'sob_cache_stats' );

// ---------------------------------------------------------------------------
// 2. Remove .htaccess rules
// ---------------------------------------------------------------------------

if ( ! defined( 'SOB_PLUGIN_DIR' ) ) {
	define( 'SOB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

$htaccess_class = SOB_PLUGIN_DIR . 'includes/class-sob-htaccess.php';
if ( file_exists( $htaccess_class ) ) {
	require_once $htaccess_class;
	SOB_Htaccess::remove_rules();
}

// ---------------------------------------------------------------------------
// 3. Clear the CSS/JS minification cache directory
// ---------------------------------------------------------------------------

if ( ! defined( 'SOB_CACHE_DIR' ) ) {
	// Must match the define() in site-optimizer-by-beplus.php exactly.
	define( 'SOB_CACHE_DIR', WP_CONTENT_DIR . '/cache/sob-cache/' );
}

$cache_dir = SOB_CACHE_DIR;

if ( file_exists( $cache_dir ) && is_dir( $cache_dir ) ) {
	// Delete all cached CSS and JS files.
	$cached_files = glob( $cache_dir . '*.{css,js}', GLOB_BRACE );
	if ( is_array( $cached_files ) ) {
		foreach ( $cached_files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	// Remove plugin-created support files.
	foreach ( array( 'index.php', '.htaccess' ) as $support_file ) {
		$path = $cache_dir . $support_file;
		if ( file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// Remove the directory itself if it is now empty.
	@rmdir( $cache_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

// ---------------------------------------------------------------------------
// 4. Delete _sob_disable_cache post meta from every post
// ---------------------------------------------------------------------------

// Use a direct DB query for efficiency — avoids loading every post into memory.
global $wpdb;

$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->postmeta,
	array( 'meta_key' => '_sob_disable_cache' ),
	array( '%s' )
);
