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
		// The same image the script shows after a choice: the thumbnail with its alternative text.
		$thumbnail = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : false;
		echo '<p class="easyrankly-media-field__preview">';
		if ( is_string( $thumbnail ) ) {
			printf( '<img src="%1$s" alt="%2$s" />', esc_url( $thumbnail ), esc_attr( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );
		}
		echo '</p>';
		printf(
			'<input type="number" min="0" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' )
		);
		echo '</div>';
	}
}
