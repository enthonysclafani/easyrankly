<?php
/**
 * Language panel in the block editor.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual\Admin;

use EasyRankly\Admin\Assets;
use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the panel that sets the language of the post and links its translations.
 *
 * The panel talks to the `easyrankly/v1/translations` routes, which check the
 * capabilities on every post involved.
 */
final class EditorPanel {

	/**
	 * Hooks the editor assets.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the panel when the site has two languages and the post type has languages.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base || '' === $screen->post_type || ! Languages::enabled() ) {
			return;
		}

		if ( ! is_object_in_taxonomy( $screen->post_type, Translations::LANGUAGE ) || ! Assets::enqueue( 'languages' ) ) {
			return;
		}

		$languages = array();
		foreach ( Languages::all() as $slug => $language ) {
			$languages[] = array(
				'slug' => $slug,
				'name' => $language['name'],
			);
		}

		wp_add_inline_script( 'easyrankly-languages', 'window.easyrankly = Object.assign( window.easyrankly || {}, ' . wp_json_encode( array( 'languages' => $languages ) ) . ' );', 'before' );
	}
}
