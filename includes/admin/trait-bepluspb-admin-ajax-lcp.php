<?php
/**
 * LCP Analyzer: front-end script for administrators and the AJAX handler that saves its result.
 *
 * Part of BEPLUSPB_Admin, split out of class-bepluspb-admin.php (see the other traits).
 *
 * The analyzer measures the Largest Contentful Paint element in the administrator's own
 * browser (admin bar → "Analyze LCP") and offers to add the image to "Priority Images". The
 * plugin never fetches anything: the browser reports a URL, the handler validates it like a
 * hand-typed line and stores it.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait BEPLUSPB_Admin_Ajax_Lcp {

	/**
	 * Load the analyzer on front-end pages for administrators (admin bar visible).
	 *
	 * The script does nothing until the admin bar item is clicked, except inside its own
	 * measuring frame (see assets/js/lcp-analyzer.js).
	 */
	public static function enqueue_lcp_analyzer() {
		if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Editors, previews, REST and login are not pages worth measuring.
		if ( BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}

		wp_enqueue_script( 'bepluspb-lcp-analyzer', BEPLUSPB_PLUGIN_URL . 'assets/js/lcp-analyzer.js', array(), BEPLUSPB_VERSION, true );
		wp_localize_script(
			'bepluspb-lcp-analyzer',
			'bepluspbLcp',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bepluspb_lcp_apply' ),
				'isHome'      => (bool) is_front_page(),
				'path'        => BEPLUSPB_Priority_Images::current_path(),
				'pageType'    => BEPLUSPB_Priority_Images::primary_type(),
				'settingsUrl' => admin_url( 'options-general.php?page=beplus-performance-booster' ),
				// Administrators normally see the page WITHOUT the plugin's optimisations.
				'optimized'   => (bool) apply_filters( 'bepluspb_optimize_for_admins', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- prefixed.
				'maxEntries'  => BEPLUSPB_Priority_Images::MAX_ENTRIES,
				'i18n'        => self::lcp_analyzer_strings(),
			)
		);
	}

	/**
	 * AJAX: add the analysed image(s) of one page to Priority Images.
	 *
	 * Replaces the list's lines for that page, keeps every other page's lines, refuses a
	 * list over the limit. Saving goes through update_option(), so the settings sanitizer
	 * re-validates every line and the Page Cache is purged like after any settings save.
	 */
	public static function handle_ajax_lcp_apply() {
		check_ajax_referer( 'bepluspb_lcp_apply', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$raw     = isset( $_POST['entries'] ) ? wp_unslash( $_POST['entries'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON string, decoded and validated field by field below.
		$entries = is_string( $raw ) && strlen( $raw ) <= 20000 ? json_decode( $raw, true ) : null;
		if ( ! is_array( $entries ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing valid to apply.', 'beplus-performance-booster' ) ), 400 );
		}

		$opts   = bepluspb_get_options();
		$result = BEPLUSPB_Priority_Images::apply_entries( $opts['priority_images'], $opts['priority_images_hints'], $entries );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 400 );
		}

		$opts['priority_images']       = $result['text'];
		$opts['priority_images_hints'] = $result['hints'];
		update_option( BEPLUSPB_OPTIONS_KEY, $opts );

		wp_send_json_success(
			array(
				'count'   => $result['count'],
				'message' => __( 'Saved to Priority Images.', 'beplus-performance-booster' ),
			)
		);
	}

	/**
	 * Texts of the analyzer panel.
	 *
	 * @return string[]
	 */
	private static function lcp_analyzer_strings() {
		return array(
			'title'             => __( 'LCP Analyzer', 'beplus-performance-booster' ),
			'measuring'         => __( 'Measuring…', 'beplus-performance-booster' ),
			'measuredOriginal'  => __( 'Measured on the original page: the plugin\'s optimisations are off for administrators, so times are slower than visitors see. The LCP element is the same.', 'beplus-performance-booster' ),
			'measuredOptimized' => __( 'Measured with the plugin\'s optimisations on.', 'beplus-performance-booster' ),
			'mobile'            => __( 'Mobile', 'beplus-performance-booster' ),
			'desktop'           => __( 'Desktop', 'beplus-performance-booster' ),
			'notMeasured'       => __( 'Could not measure this screen size (the measuring window was closed, or it took too long).', 'beplus-performance-booster' ),
			'popupBlocked'      => __( 'The browser blocked the small measuring window. Allow pop-ups for this site and press "Measure again" to measure the other screen size.', 'beplus-performance-booster' ),
			'unsupported'       => __( 'This browser does not report the Largest Contentful Paint. Use Chrome, Edge or another Chromium browser.', 'beplus-performance-booster' ),
			'kindImg'           => __( 'Image', 'beplus-performance-booster' ),
			'kindBg'            => __( 'CSS background image', 'beplus-performance-booster' ),
			'kindVideo'         => __( 'Video poster', 'beplus-performance-booster' ),
			'kindText'          => __( 'Text', 'beplus-performance-booster' ),
			'kindOther'         => __( 'Other', 'beplus-performance-booster' ),
			'element'           => __( 'Element', 'beplus-performance-booster' ),
			'image'             => __( 'Image', 'beplus-performance-booster' ),
			'time'              => __( 'Time', 'beplus-performance-booster' ),
			'lazy'              => __( 'It is lazy-loaded, so the browser delays the most important image.', 'beplus-performance-booster' ),
			'noPriority'        => __( 'It has no fetchpriority="high".', 'beplus-performance-booster' ),
			'lateBg'            => __( 'It is set in CSS, so the browser only finds it after the stylesheet has loaded: a preload helps.', 'beplus-performance-booster' ),
			'textLcp'           => __( 'The LCP is text, so Priority Images will not help. Check fonts (Google Fonts), render-blocking CSS and the server response time.', 'beplus-performance-booster' ),
			'slow'              => __( 'Slower than 2.5 s.', 'beplus-performance-booster' ),
			'prioritized'       => __( 'Already prioritized by Priority Images.', 'beplus-performance-booster' ),
			'noImage'           => __( 'There is no image to prioritize.', 'beplus-performance-booster' ),
			'scopeThis'         => __( 'This page only', 'beplus-performance-booster' ),
			'scopeHome'         => __( 'Home page only', 'beplus-performance-booster' ),
			'scopeType'         => __( 'Every page of this type', 'beplus-performance-booster' ),
			'scopeAll'          => __( 'Whole site', 'beplus-performance-booster' ),
			'apply'             => __( 'Add to Priority Images', 'beplus-performance-booster' ),
			'applying'          => __( 'Saving…', 'beplus-performance-booster' ),
			'applied'           => __( 'Saved. Reload the page to check the preload.', 'beplus-performance-booster' ),
			'error'             => __( 'Could not save:', 'beplus-performance-booster' ),
			'settings'          => __( 'Open settings', 'beplus-performance-booster' ),
			'close'             => __( 'Close', 'beplus-performance-booster' ),
			'again'             => __( 'Measure again', 'beplus-performance-booster' ),
			'replaceNote'       => __( 'This replaces the Priority Images lines of this page.', 'beplus-performance-booster' ),
		);
	}
}
