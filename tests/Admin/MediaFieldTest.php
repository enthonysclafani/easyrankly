<?php
/**
 * Tests for the image field of the settings and term screens.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Admin;

use EasyRankly\Admin\MediaField;
use WP_UnitTestCase;

/**
 * The preview shows the thumbnail, escaped, as the script does after a choice.
 */
final class MediaFieldTest extends WP_UnitTestCase {

	/**
	 * The preview is the thumbnail with its alternative text, both escaped.
	 */
	public function test_preview_shows_the_escaped_thumbnail(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image, '_wp_attachment_image_alt', 'A "yellow" field' );

		$html = get_echo( array( MediaField::class, 'render' ), array( 'field', 'name', $image ) );

		$this->assertStringContainsString(
			'<p class="easyrankly-media-field__preview"><img src="' . esc_url( (string) wp_get_attachment_image_url( $image, 'thumbnail' ) ) . '" alt="A &quot;yellow&quot; field" /></p>',
			$html
		);
		$this->assertStringContainsString( '-150x150.jpg', $html );
	}

	/**
	 * Without an image the preview is empty and the field holds no ID.
	 */
	public function test_no_image_has_an_empty_preview(): void {
		$html = get_echo( array( MediaField::class, 'render' ), array( 'field', 'name', 0 ) );

		$this->assertStringContainsString( '<p class="easyrankly-media-field__preview"></p>', $html );
		$this->assertStringContainsString( 'name="name" value=""', $html );
	}
}
