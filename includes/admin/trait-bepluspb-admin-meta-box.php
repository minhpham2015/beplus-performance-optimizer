<?php
/**
 * Per-page cache-disable meta box.
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

trait BEPLUSPB_Admin_Meta_Box {

	/**
	 * Register the "Beplus Performance Booster" meta box on posts and pages only.
	 *
	 * IMP-6: Restricting to core post types avoids cluttering CPT edit screens
	 * (products, events, etc.) where the cache-disable option is rarely useful
	 * and the meta key would create noise in third-party post type queries.
	 */
	public static function register_meta_box() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'bepluspb-page-settings',
				__( 'Beplus Performance Booster', 'beplus-performance-booster' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'side',
				'low'
			);
		}
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'bepluspb_meta_box_save', 'bepluspb_meta_box_nonce' );

		$is_disabled = (bool) get_post_meta( $post->ID, '_bepluspb_disable_cache', true );
		?>
		<p>
			<label>
				<input type="checkbox" name="bepluspb_disable_cache" value="1"
					<?php checked( $is_disabled ); ?>>
				<?php esc_html_e( 'Disable CSS/JS cache optimizations for this page/post.', 'beplus-performance-booster' ); ?>
			</label>
		</p>
		<p class="description" style="font-size:11px;margin-top:4px;">
			<?php esc_html_e( 'When checked, minified files are not served for this page. Useful for debugging or if a specific page has conflicts.', 'beplus-performance-booster' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the meta box value when a post is saved.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_box( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- required by the save_post hook signature.
		// Skip autosaves, revisions, and posts without our nonce field.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['bepluspb_meta_box_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['bepluspb_meta_box_nonce'] ) ),
			'bepluspb_meta_box_save'
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['bepluspb_disable_cache'] ) ) {
			update_post_meta( $post_id, '_bepluspb_disable_cache', 1 );
		} else {
			delete_post_meta( $post_id, '_bepluspb_disable_cache' );
		}
	}

	// =========================================================================
	// Object Cache AJAX / POST handlers
	// =========================================================================
}
