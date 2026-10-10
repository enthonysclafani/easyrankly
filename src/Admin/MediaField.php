<?php
/**
 * Image field shared by the settings pages and the term screens.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints an attachment ID input that the `media-field` script turns into "Choose image" and
 * "Remove" buttons with the media library modal. Without the script it stays a number input.
 */
final class MediaField {

	/**
	 * Media library modal and the script that opens it, on the screen being loaded.
	 */
	public static function enqueue(): void {
		wp_enqueue_media();
		Assets::enqueue( 'media-field' );
	}

	/**
	 * Prints the field.
	 *
	 * @param string $id            ID attribute of the input.
	 * @param string $name          Name attribute of the input.
	 * @param int    $attachment_id Current attachment ID, 0 for none.
	 */
	public static function render( string $id, string $name, int $attachment_id ): void {
		printf(
			'<div class="easyrankly-media-field" data-choose="%1$s" data-replace="%2$s" data-remove="%3$s">',
			esc_attr__( 'Choose image', 'easyrankly' ),
			esc_attr__( 'Replace image', 'easyrankly' ),
			esc_attr__( 'Remove', 'easyrankly' )
		);
		printf( '<p class="easyrankly-media-field__preview">%s</p>', $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, 'thumbnail' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by the core.
		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' )
		);
		echo '</div>';
	}
}
