<?php
/**
 * Admin bar menu and Object Cache purge control.
 *
 * Part of BEPLUSPB_Admin, split out of class-bepluspb-admin.php. A trait is used
 * (rather than a separate class) so every method keeps its exact `self::`/`static`
 * semantics, visibility and hook callbacks; behaviour is unchanged.
 *
 * @package Beplus_Performance_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait BEPLUSPB_Admin_Admin_Bar {

	/**
	 * Add the "Beplus Performance Booster" menu to the WordPress admin bar.
	 *
	 * Renders a parent node with a green dot indicator and a single child
	 * panel containing an SVG donut ring, cache size/file stats, and a
	 * "Clear CSS / JS Cache" button.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public static function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts      = bepluspb_get_options();
		$dot_color = ! empty( $opts['cache_enabled'] ) ? '#00a32a' : '#dc3232';
		$stats     = BEPLUSPB_Minify::get_cache_stats();

		// Parent node — colour-coded dot (green = on, red = off) + "Beplus Performance Booster".
		$wp_admin_bar->add_node(
			array(
				'id'    => 'bepluspb-cache',
				'title' => '<span class="bepluspb-ab-dot" aria-hidden="true" style="background:' . esc_attr( $dot_color ) . '"></span>'
					. esc_html__( 'Beplus Performance Booster', 'beplus-performance-booster' ),
				'href'  => admin_url( 'options-general.php?page=beplus-performance-booster' ),
				'meta'  => array( 'class' => 'bepluspb-adminbar-root' ),
			)
		);

		// Single child panel node with full cache info + clear button.
		$wp_admin_bar->add_node(
			array(
				'id'     => 'bepluspb-cache-panel',
				'parent' => 'bepluspb-cache',
				'title'  => self::build_adminbar_panel( $stats, $dot_color ),
				'href'   => false,
				'meta'   => array( 'class' => 'bepluspb-adminbar-panel-node' ),
			)
		);
	}

	/**
	 * Build the HTML for the admin bar cache-info panel.
	 *
	 * Renders a CSS-only SVG donut ring showing cache usage as a percentage
	 * of a 10 MB reference maximum, plus size/file stats and a clear button.
	 *
	 * Ring colours:
	 *  - Grey   : 0 % (empty cache)
	 *  - Green  : 1 – 49 %
	 *  - Orange : 50 – 74 %
	 *  - Red    : 75 – 100 %
	 *
	 * The ring uses r = 15.9155 so the circumference ≈ 100, making the
	 * stroke-dasharray value equal to the percentage directly.
	 * stroke-dashoffset = 25 rotates the arc start to 12 o'clock.
	 *
	 * @param  array  $stats           Result of BEPLUSPB_Minify::get_cache_stats().
	 * @param  string $dot_color       Hex color for the status dot in the panel header.
	 * @return string HTML markup (output raw as WP_Admin_Bar node title).
	 */
	private static function build_adminbar_panel( $stats, $dot_color = '#00a32a' ) {
		$max_bytes = 10 * 1024 * 1024; // 10 MB reference maximum.
		$pct       = $stats['size'] > 0
			? min( 100, (int) round( ( $stats['size'] / $max_bytes ) * 100 ) )
			: 0;

		// Progress arc colour: green ≤50%, orange ≤80%, red >80%.
		// When cache is empty the arc is omitted; only the dark track shows.
		if ( $pct <= 50 ) {
			$ring_color = '#00a32a'; // Green.
		} elseif ( $pct <= 80 ) {
			$ring_color = '#dba617'; // Orange.
		} else {
			$ring_color = '#d63638'; // Red.
		}

		$size_label = $stats['size'] > 0
			? esc_html( BEPLUSPB_Minify::human_filesize( $stats['size'] ) )
			: '0 B';
		$file_count = (int) $stats['count'];

		// SVG donut ring — 52 × 52px display, viewBox 36 × 36 (scale ≈ 1.44×).
		// stroke-width 3 SVG units ≈ 4.3px rendered.
		// font-size   7 SVG units ≈ 10px rendered.
		// dominant-baseline="central" + y="18" vertically centres the label.
		$svg  = '<svg viewBox="0 0 36 36" class="bepluspb-ring-svg" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		$svg .= '<circle cx="18" cy="18" r="15.9155" fill="none" stroke="#3c434a" stroke-width="3"/>';
		if ( $pct > 0 ) {
			$svg .= '<circle cx="18" cy="18" r="15.9155" fill="none"'
				. ' stroke="' . esc_attr( $ring_color ) . '"'
				. ' stroke-width="3"'
				. ' stroke-dasharray="' . esc_attr( $pct . ' ' . ( 100 - $pct ) ) . '"'
				. ' stroke-dashoffset="25"'
				. ' stroke-linecap="round"/>';
		}
		$svg .= '<text x="18" y="18" text-anchor="middle" dominant-baseline="central" class="bepluspb-ring-pct">'
			. esc_html( $pct . '%' )
			. '</text>';
		$svg .= '</svg>';

		// ── Panel HTML ───────────────────────────────────────────────────────
		// Structure:
		// .bepluspb-ab-panel
		// .bepluspb-ab-info          ← padded area (header + ring/stats row)
		// .bepluspb-ab-header      ← green dot + "CSS / JS Cache Info"
		// .bepluspb-ab-content     ← flex row: ring left, stats right
		// .bepluspb-ab-sep           ← 1px separator
		// a.bepluspb-ab-clear-btn    ← full-width flush dark button

		$html = '<div class="bepluspb-ab-panel">';

		// Padded info area.
		$html .= '<div class="bepluspb-ab-info">';

		// Header: small colour-coded dot + uppercase label.
		$html .= '<div class="bepluspb-ab-header">'
			. '<span class="bepluspb-ab-header-dot" aria-hidden="true" style="background:' . esc_attr( $dot_color ) . '"></span>'
			. '<span class="bepluspb-ab-header-text">'
			. esc_html__( 'CSS / JS Cache Info', 'beplus-performance-booster' )
			. '</span>'
			. '</div>';

		// Content: ring (left) + stats (right), vertically centred.
		$html .= '<div class="bepluspb-ab-content">';
		$html .= '<div class="bepluspb-ab-ring-wrap">' . $svg . '</div>';
		$html .= '<div class="bepluspb-ab-stats">';
		$html .= '<p class="bepluspb-ab-stat-row">'
			. '<span class="bepluspb-ab-stat-label">' . esc_html__( 'Size:', 'beplus-performance-booster' ) . '</span>'
			. '<span class="bepluspb-ab-stat-size">' . $size_label . '</span>'
			. '</p>';
		$html .= '<p class="bepluspb-ab-stat-row">'
			. '<span class="bepluspb-ab-stat-label">' . esc_html__( 'Files:', 'beplus-performance-booster' ) . '</span>'
			. '<span class="bepluspb-ab-stat-files">' . esc_html( $file_count ) . '</span>'
			. '</p>';
		$html .= '</div>'; // .bepluspb-ab-stats
		$html .= '</div>'; // .bepluspb-ab-content

		$html .= '</div>'; // .bepluspb-ab-info

		// 1px separator.
		$html .= '<div class="bepluspb-ab-sep" aria-hidden="true"></div>';

		// Full-width flush clear button.
		$html .= '<div class="bepluspb-ab-purge-form"><a href="' . esc_url( admin_url( 'options-general.php?page=beplus-performance-booster#bepluspb-cache-actions' ) ) . '">' . esc_html__( 'Purge ALL Cache', 'beplus-performance-booster' ) . '</a></div>';
		$html .= self::render_object_cache_purge_control( 'admin-bar' );

		$html .= '</div>'; // .bepluspb-ab-panel

		return $html;
	}


	/**
	 * Render the shared, POST-only Object Cache purge control.
	 *
	 * @param string $context Dashboard or admin-bar presentation context.
	 * @return string Safe HTML markup.
	 */
	private static function render_object_cache_purge_control( $context = 'dashboard' ) {
		$purge     = BEPLUSPB_Object_Cache::get_purge_availability();
		$available = ! empty( $purge['available'] );

		$reason = isset( $purge['reason'] ) ? (string) $purge['reason'] : __( 'Object Cache purge is unavailable.', 'beplus-performance-booster' );

		if ( 'admin-bar' === $context && ! $available ) {
			return '';
		}

		$html = '<div class="bepluspb-object-purge-control bepluspb-object-purge-control--' . esc_attr( $context ) . '">';
		if ( 'dashboard' === $context ) {
			$html .= '<div class="bepluspb-cache-action-row"><div>';
			$html .= '<span class="bepluspb-status-badge ' . ( $available ? 'active' : 'inactive' ) . ' bepluspb-object-cache-status" role="status">' . ( $available ? esc_html__( 'Available', 'beplus-performance-booster' ) : esc_html__( 'Unavailable', 'beplus-performance-booster' ) ) . '</span>';
			$html .= '<p class="description">' . esc_html__( 'Clears the dedicated persistent Object Cache only. Confirmation is required.', 'beplus-performance-booster' ) . '</p>';
			if ( ! $available ) {
				$html .= '<p class="description">' . esc_html( $reason ) . ' <a class="bepluspb-object-cache-settings-link" href="' . esc_url( admin_url( 'options-general.php?page=beplus-performance-booster#bepluspb-tab-object_cache' ) ) . '">' . esc_html__( 'Review Object Cache settings', 'beplus-performance-booster' ) . '</a></p>';
			}
			$html .= '</div>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bepluspb-purge-form" data-confirm="' . esc_attr__( 'Purge the persistent Object Cache? This can affect other sites or applications sharing its backend.', 'beplus-performance-booster' ) . '">';
		$html .= '<input type="hidden" name="action" value="bepluspb_purge_object_cache">';
		$html .= wp_nonce_field( 'bepluspb_purge_object_cache', 'bepluspb_object_purge_nonce', true, false );
		$html .= '<input type="hidden" name="bepluspb_confirm_object_purge" value="1">';
		$html .= '<button type="submit" class="button" aria-label="' . esc_attr__( 'Purge persistent Object Cache', 'beplus-performance-booster' ) . '"' . disabled( $available, false, false ) . '>' . esc_html__( 'Purge Object Cache', 'beplus-performance-booster' ) . '</button>';
		$html .= '</form>';
		if ( 'dashboard' === $context ) {
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	// =========================================================================
	// Quick-enable action handler
	// =========================================================================
}
