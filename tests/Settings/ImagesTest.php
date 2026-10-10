<?php
/**
 * Tests for the copies of the logo and the default social image.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use WP_UnitTestCase;

/**
 * Pages show the logo and the default image without loading their attachments, and the
 * copies follow the attachments when they change or go away.
 */
final class ImagesTest extends WP_UnitTestCase {

	/**
	 * Logo attachment.
	 *
	 * @var int
	 */
	private int $logo;

	/**
	 * Default social image attachment.
	 *
	 * @var int
	 */
	private int $default;

	/**
	 * Two images, set as logo and default image.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '' );
		update_option( 'blog_public', '1' );

		$this->logo    = $this->image( 'logo.png', 600, 60, 'Logo' );
		$this->default = $this->image( 'share.jpg', 1200, 630, 'Shared' );
		update_option(
			Settings::OPTION,
			array(
				'identity_logo' => $this->logo,
				'social_image'  => $this->default,
			)
		);
	}

	/**
	 * Creates an image attachment with size metadata and alt text.
	 *
	 * @param string $file   File name.
	 * @param int    $width  Width.
	 * @param int    $height Height.
	 * @param string $alt    Alt text.
	 * @return int Attachment ID.
	 */
	private function image( string $file, int $width, int $height, string $alt ): int {
		$id = self::factory()->attachment->create_object( $file, 0, array( 'post_mime_type' => 'image/png' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => $width,
				'height' => $height,
				'file'   => $file,
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		return $id;
	}

	/**
	 * Saving the settings copies address, size and alt text of both images.
	 */
	public function test_saving_copies_the_images(): void {
		$logo = Settings::image( 'identity_logo' );

		$this->assertStringEndsWith( 'logo.png', $logo['url'] ?? '' );
		$this->assertSame( array( 600, 60, 'Logo' ), array( $logo['width'], $logo['height'], $logo['alt'] ) );
		$this->assertSame( $this->default, get_option( Settings::OPTION )['images']['social_image']['id'] );
	}

	/**
	 * A page shows both images with no query for their attachments.
	 */
	public function test_page_reads_images_without_queries(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		$this->go_to( get_permalink( $post ) );
		wp_cache_delete( $this->logo, 'posts' );
		wp_cache_delete( $this->default, 'posts' );
		wp_cache_delete( $this->logo, 'post_meta' );
		wp_cache_delete( $this->default, 'post_meta' );

		global $wpdb;
		$before = $wpdb->num_queries;
		$social = get_echo( array( new Social(), 'print_tags' ) );
		$schema = get_echo( array( new Schema(), 'print_graph' ) );

		$this->assertSame( $before, $wpdb->num_queries );
		$this->assertStringContainsString( 'share.jpg" />', $social );
		$this->assertStringContainsString( '<meta property="og:image:alt" content="Shared" />', $social );
		$this->assertStringContainsString( 'logo.png","width":600,"height":60', $schema );
	}

	/**
	 * A new alt text or file is copied again; a deleted image disappears from the pages.
	 */
	public function test_copies_follow_the_attachments(): void {
		update_post_meta( $this->default, '_wp_attachment_image_alt', 'New text' );
		$this->assertSame( 'New text', Settings::image( 'social_image' )['alt'] ?? '' );

		wp_update_attachment_metadata(
			$this->logo,
			array(
				'width'  => 300,
				'height' => 30,
				'file'   => 'logo.png',
			)
		);
		$this->assertSame( 300, Settings::image( 'identity_logo' )['width'] ?? 0 );

		wp_delete_attachment( $this->logo, true );
		$this->assertNull( Settings::image( 'identity_logo' ) );
		$this->assertSame( $this->logo, Settings::value( 'identity_logo' ) );

		$post = self::factory()->post->create();
		$this->go_to( get_permalink( $post ) );
		$this->assertStringNotContainsString( 'logo.png', get_echo( array( new Schema(), 'print_graph' ) ) );
	}

	/**
	 * Images in the uploads folder follow a new uploads address (new host, CDN path) without a new copy.
	 */
	public function test_copies_follow_the_uploads_address(): void {
		update_option( 'upload_url_path', 'https://cdn.example/uploads' );
		wp_upload_dir( null, false, true ); // A new request would build it again.
		$url = Settings::image( 'social_image' )['url'] ?? '';
		update_option( 'upload_url_path', '' );
		wp_upload_dir( null, false, true );

		$this->assertSame( 'https://cdn.example/uploads/share.jpg', $url );
		$this->assertStringEndsWith( '/wp-content/uploads/share.jpg', Settings::image( 'social_image' )['url'] ?? '' );
	}

	/**
	 * The copies cannot be written from outside: they always come from the attachments.
	 */
	public function test_copies_are_read_only(): void {
		$settings           = Settings::get();
		$settings['images'] = array(
			'identity_logo' => array(
				'id'     => $this->logo,
				'url'    => 'https://evil.example/x.png',
				'width'  => 1,
				'height' => 1,
				'alt'    => '',
			),
		);
		update_option( Settings::OPTION, $settings );

		$this->assertStringEndsWith( 'logo.png', Settings::image( 'identity_logo' )['url'] ?? '' );
	}

	/**
	 * Settings saved before the copies existed still show the images, loaded from the attachments.
	 */
	public function test_settings_without_copies_load_the_attachment(): void {
		$stored = get_option( Settings::OPTION );
		unset( $stored['images'] );
		// Straight to the database, as an older version saved it.
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $stored ) ), array( 'option_name' => Settings::OPTION ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( Settings::OPTION, 'options' );

		$this->assertStringEndsWith( 'share.jpg', Settings::image( 'social_image' )['url'] ?? '' );
	}
}
