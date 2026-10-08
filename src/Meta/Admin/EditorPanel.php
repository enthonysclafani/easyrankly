<?php
/**
 * SEO panel in the block editor.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Meta\Admin;

use EasyRankly\Admin\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the editor panel that edits the SEO meta through the post's REST `meta` field.
 *
 * Saving goes through the core post endpoint, so capabilities and sanitization are
 * the ones registered in Meta: there is no endpoint of our own.
 */
final class EditorPanel {

	/**
	 * Hooks the editor assets.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel only when editing a public post type whose meta reaches REST.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base || '' === $screen->post_type ) {
			return;
		}

		if ( ! is_post_type_viewable( $screen->post_type ) || ! post_type_supports( $screen->post_type, 'custom-fields' ) ) {
			return;
		}

		Assets::enqueue( 'editor' );
	}
}
