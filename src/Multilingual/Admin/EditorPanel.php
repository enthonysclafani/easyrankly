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
 * Loads the panel that sets the language of the post and links its translations, and
 * gives the language switcher block the languages for its editor preview.
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
	 * Enqueues the panel when the site has two languages and the post type has languages, and
	 * prints the languages once, before the first script that reads them.
	 *
	 * The switcher block reads them only when it renders, after every editor script ran: in
	 * editors with the panel, the panel's copy serves both.
	 */
	public function enqueue(): void {
		if ( $this->enqueue_panel() ) {
			wp_add_inline_script( 'easyrankly-languages', self::languages_script(), 'before' );
			return;
		}

		// Any other editor (site editor included): the switcher block previews the languages.
		$handle = generate_block_asset_handle( 'easyrankly/language-switcher', 'editorScript' );
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_add_inline_script( $handle, self::languages_script(), 'before' );
		}
	}

	/**
	 * Enqueues the panel on the editor of content with languages, when the site has two.
	 *
	 * @return bool Whether the panel is enqueued.
	 */
	private function enqueue_panel(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base || '' === $screen->post_type || ! Languages::enabled() ) {
			return false;
		}

		return is_object_in_taxonomy( $screen->post_type, Translations::LANGUAGE ) && Assets::enqueue( 'languages' );
	}

	/**
	 * Script that exposes the configured languages (slug and name) to the editor scripts.
	 *
	 * @return string
	 */
	private static function languages_script(): string {
		$languages = array();
		foreach ( Languages::all() as $slug => $language ) {
			$languages[] = array(
				'slug' => $slug,
				'name' => $language['name'],
			);
		}

		return 'window.easyrankly = Object.assign( window.easyrankly || {}, ' . wp_json_encode( array( 'languages' => $languages ) ) . ' );';
	}
}
