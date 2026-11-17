<?php
/**
 * Critical CSS: generator script for administrators and the AJAX handlers behind the settings card.
 *
 * Part of BEPLUSPB_Admin, split out of class-bepluspb-admin.php (see the other traits).
 *
 * The generator runs in the administrator's own browser (admin bar → "Generate Critical CSS"):
 * it works out which rules the first screen needs, on a phone-sized and a desktop-sized copy
 * of the page, and offers to save the result for the current page type. The plugin never
 * fetches anything; the handlers store what the administrator sends after validating it
 * (BEPLUSPB_Critical_CSS::sanitize_css()).
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait BEPLUSPB_Admin_Ajax_Critical_Css {

	/**
	 * Load the generator on front-end pages for administrators (admin bar visible).
	 *
	 * Idle until the admin bar item is clicked, except inside its own measuring frames.
	 */
	public static function enqueue_ccss_generator() {
		if ( is_admin() || ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( BEPLUSPB_Utils::is_buffer_excluded_request() ) {
			return;
		}

		$opts    = bepluspb_get_options();
		$key     = BEPLUSPB_Critical_CSS::current_key();
		$entries = BEPLUSPB_Critical_CSS::all();

		wp_enqueue_script( 'bepluspb-ccss-generator', BEPLUSPB_PLUGIN_URL . 'assets/js/critical-css-generator.js', array(), BEPLUSPB_VERSION, true );
		wp_localize_script(
			'bepluspb-ccss-generator',
			'bepluspbCcss',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bepluspb_ccss_save' ),
				'key'         => $key,
				'label'       => BEPLUSPB_Critical_CSS::key_label( $key ),
				'storedBytes' => isset( $entries[ $key ] ) ? (int) $entries[ $key ]['bytes'] : 0,
				'enabled'     => ! empty( $opts['critical_css_enabled'] ),
				'nonBlocking' => ! empty( $opts['css_non_blocking'] ),
				'inlineAll'   => ! empty( $opts['css_inline_all'] ),
				'maxBytes'    => BEPLUSPB_Critical_CSS::MAX_BYTES,
				'settingsUrl' => admin_url( 'options-general.php?page=beplus-performance-booster' ),
				'i18n'        => self::ccss_generator_strings(),
			)
		);
	}

	/**
	 * AJAX: store the critical CSS of a page type (generated in the browser, or typed in).
	 */
	public static function handle_ajax_ccss_save() {
		check_ajax_referer( 'bepluspb_ccss_save', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$css    = isset( $_POST['css'] ) ? wp_unslash( $_POST['css'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CSS: validated and cleaned by BEPLUSPB_Critical_CSS::sanitize_css().
		$source = isset( $_POST['source'] ) && 'generated' === $_POST['source'] ? 'generated' : 'manual';

		if ( ! is_string( $css ) ) {
			wp_send_json_error( array( 'message' => __( 'The CSS is missing.', 'beplus-performance-booster' ) ), 400 );
		}

		$result = BEPLUSPB_Critical_CSS::save( $key, $css, $source );
		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ), 400 );
		}

		wp_send_json_success(
			array(
				'key'     => $key,
				'label'   => BEPLUSPB_Critical_CSS::key_label( $key ),
				'bytes'   => $result['bytes'],
				'source'  => $source,
				'message' => __( 'Critical CSS saved.', 'beplus-performance-booster' ),
			)
		);
	}

	/**
	 * AJAX: delete the critical CSS of a page type.
	 */
	public static function handle_ajax_ccss_delete() {
		check_ajax_referer( 'bepluspb_ccss_delete', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beplus-performance-booster' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! BEPLUSPB_Critical_CSS::delete( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'There is no such entry.', 'beplus-performance-booster' ) ), 404 );
		}

		wp_send_json_success( array( 'message' => __( 'Critical CSS deleted.', 'beplus-performance-booster' ) ) );
	}

	/**
	 * Page types an entry can be stored for (LTR and RTL sets), for the manual form.
	 *
	 * @return array key => label
	 */
	private static function ccss_page_type_options() {
		$bases = array( 'default', 'front', 'blog', 'archive', 'search', 'other' );
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$candidate = 'single-' . $type;
			if ( 'attachment' !== $type && BEPLUSPB_Critical_CSS::is_valid_key( $candidate ) ) {
				$bases[] = $candidate;
			}
		}
		$options = array();
		foreach ( array( '', ':rtl' ) as $suffix ) {
			foreach ( $bases as $base ) {
				$options[ $base . $suffix ] = BEPLUSPB_Critical_CSS::key_label( $base . $suffix );
			}
		}
		return $options;
	}

	/**
	 * Texts of the generator panel.
	 *
	 * @return string[]
	 */
	private static function ccss_generator_strings() {
		return array(
			'title'              => __( 'Critical CSS Generator', 'beplus-performance-booster' ),
			'working'            => __( 'Analysing the page on a phone-sized and a desktop-sized copy…', 'beplus-performance-booster' ),
			'mobile'             => __( 'Mobile', 'beplus-performance-booster' ),
			'desktop'            => __( 'Desktop', 'beplus-performance-booster' ),
			'failed'             => __( 'Could not analyse this screen size.', 'beplus-performance-booster' ),
			'nothing'            => __( 'Nothing could be analysed. The technical details below say why.', 'beplus-performance-booster' ),
			/* translators: %d: seconds waited. */
			'failNotLoaded'      => __( 'The page did not finish loading in the measuring frame within %d s: the server or the connection is slow, or the page never stops loading. If your browser\'s DevTools has network throttling on, turn it off and try again.', 'beplus-performance-booster' ),
			/* translators: %d: seconds waited. */
			'failSlow'           => __( 'The page started loading in the measuring frame but had not finished after %d s. A slow connection, or a resource that never finishes (a video, chat widget or tracker), is the usual cause. If DevTools network throttling is on, turn it off and try again.', 'beplus-performance-booster' ),
			'failUnreadable'     => __( 'The measuring frame shows a page this tool cannot read: either the page redirected to another domain (for example a language or www redirect), or the site refuses to be shown in a frame (X-Frame-Options or Content-Security-Policy frame-ancestors). See the technical details.', 'beplus-performance-booster' ),
			'failNoHello'        => __( 'The page loaded in the measuring frame but the generator did not run there. Another plugin or script may have blocked or delayed it, or reset window.name.', 'beplus-performance-booster' ),
			'failError'          => __( 'The analysis failed inside the page:', 'beplus-performance-booster' ),
			/* translators: %d: seconds waited. */
			'failAnalysisSlow'   => __( 'The page loaded but the analysis did not finish within %d s.', 'beplus-performance-booster' ),
			'details'            => __( 'Technical details (copy them if you ask for help)', 'beplus-performance-booster' ),
			'copy'               => __( 'Copy', 'beplus-performance-booster' ),
			'copied'             => __( 'Copied', 'beplus-performance-booster' ),
			'diagFramingRefused' => __( 'Your site tells browsers not to show its pages in a frame (X-Frame-Options or frame-ancestors), so this tool cannot work until that header allows the same site (SAMEORIGIN or \'self\').', 'beplus-performance-booster' ),
			'diagRedirect'       => __( 'Opening this page address answers with a redirect. The measuring frame follows it and may end up on another domain: open the page at its final address and try again.', 'beplus-performance-booster' ),
			'rules'              => __( 'rules', 'beplus-performance-booster' ),
			'skipped'            => __( 'stylesheets skipped (served from another domain, so the browser does not let the page read them)', 'beplus-performance-booster' ),
			'total'              => __( 'Critical CSS', 'beplus-performance-booster' ),
			'target'             => __( 'Saves for', 'beplus-performance-booster' ),
			'stored'             => __( 'Replaces the saved entry of this page type.', 'beplus-performance-booster' ),
			'big'                => __( 'This is large for critical CSS (over 60 KB): the page may have a very tall first screen or heavy CSS.', 'beplus-performance-booster' ),
			'tooBig'             => __( 'This is over the size limit and cannot be saved.', 'beplus-performance-booster' ),
			'optimizedNote'      => __( 'Generated from the original stylesheets (the plugin\'s optimisations are off for administrators).', 'beplus-performance-booster' ),
			'needEnable'         => __( 'The "Critical CSS" switch is off in Settings: nothing is printed until you turn it on.', 'beplus-performance-booster' ),
			'needDefer'          => __( 'Critical CSS is only used together with "Non-Render-Blocking CSS" (Settings), which is off.', 'beplus-performance-booster' ),
			'inlineAll'          => __( 'Critical CSS is not used while "Inline All CSS" is on.', 'beplus-performance-booster' ),
			'save'               => __( 'Save Critical CSS', 'beplus-performance-booster' ),
			'saving'             => __( 'Saving…', 'beplus-performance-booster' ),
			'saved'              => __( 'Saved. Reload the page (logged out, or with "bepluspb_optimize_for_admins") to see it work.', 'beplus-performance-booster' ),
			'error'              => __( 'Could not save:', 'beplus-performance-booster' ),
			'preview'            => __( 'Show the CSS', 'beplus-performance-booster' ),
			'recovered'          => __( 'The script inside the measuring frame did not report (or the page had not finished loading), so this window analysed the frame itself: the page\'s CSS and first screen were ready, so the result is usable.', 'beplus-performance-booster' ),
			'again'              => __( 'Analyse again', 'beplus-performance-booster' ),
			'here'               => __( 'Analyse this window only (no frames)', 'beplus-performance-booster' ),
			/* translators: %s: Mobile or Desktop. */
			'hereDone'           => __( 'Analysed this window only (%s size). To cover the other size, resize the window (or use the browser\'s device mode) and press "Analyse this window only" again: the results are merged.', 'beplus-performance-booster' ),
			'settings'           => __( 'Open settings', 'beplus-performance-booster' ),
			'close'              => __( 'Close', 'beplus-performance-booster' ),
		);
	}
}
