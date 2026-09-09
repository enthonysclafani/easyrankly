<?php
/** Open Graph attachment resolution and social-image migration regressions. */

final class ERankly_Opengraph_Migrations_Test extends WP_UnitTestCase {

	/**
	 * Loads the Open Graph module. It is required lazily from
	 * erankly_bootstrap_frontend_modules() on the `wp` action, which never fires under PHPUnit,
	 * so the attachment helpers would otherwise be undefined.
	 */
	public function set_up(): void {
		parent::set_up();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/opengraph.php';
	}

	public function test_legacy_shared_image_is_migrated_to_both_network_fields(): void {
		$post_id = self::factory()->post->create();
		$url     = 'https://example.test/legacy-social.jpg';

		update_post_meta( $post_id, '_erankly_social_image_url', $url );
		erankly_migrate_legacy_social_image_for_object( 'post', $post_id );

		$this->assertSame( $url, get_post_meta( $post_id, '_erankly_og_image_url', true ) );
		$this->assertSame( $url, get_post_meta( $post_id, '_erankly_twitter_image_url', true ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_erankly_social_image_url' ) );
	}

	public function test_legacy_migration_preserves_existing_network_override(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		update_term_meta( $term_id, '_erankly_social_image_url', 'https://example.test/shared.jpg' );
		update_term_meta( $term_id, '_erankly_og_image_url', 'https://example.test/og.jpg' );
		erankly_migrate_legacy_social_image_for_object( 'term', $term_id );

		$this->assertSame( 'https://example.test/og.jpg', get_term_meta( $term_id, '_erankly_og_image_url', true ) );
		$this->assertSame( 'https://example.test/shared.jpg', get_term_meta( $term_id, '_erankly_twitter_image_url', true ) );
		$this->assertFalse( metadata_exists( 'term', $term_id, '_erankly_social_image_url' ) );
	}

	public function test_cdn_intermediate_image_resolves_attachment_alt_and_dimensions(): void {
		$filename      = '2026/09/erankly-social-probe-' . wp_generate_password( 8, false ) . '.jpg';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Social image probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			),
			$filename
		);
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );

		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => $filename,
				'width'  => 1200,
				'height' => 800,
				'sizes'  => array(
					'medium' => array(
						'file'   => wp_basename( $filename, '.jpg' ) . '-300x200.jpg',
						'width'  => 300,
						'height' => 200,
					),
				),
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Probe alternative text' );

		$image = 'https://cdn.example.test/wp-content/uploads/2026/09/' . wp_basename( $filename, '.jpg' ) . '-300x200.jpg';
		$data  = erankly_get_social_image_attachment_data( $image );

		$this->assertSame( $attachment_id, $data['id'] );
		$this->assertSame( 300, $data['width'] );
		$this->assertSame( 200, $data['height'] );
		$this->assertSame( 'Probe alternative text', erankly_get_social_image_attachment_alt( $image ) );
	}

	public function test_cdn_intermediate_prefers_original_upload_path_when_names_collide(): void {
		$basename        = 'erankly-social-collision-' . wp_generate_password( 8, false ) . '.jpg';
		$original_file   = '2026/01/' . $basename;
		$newer_file      = '2026/02/' . $basename;
		$original_id     = wp_insert_attachment(
			array(
				'post_title'     => 'January social image',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			),
			$original_file
		);
		$newer_id        = wp_insert_attachment(
			array(
				'post_title'     => 'February social image',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			),
			$newer_file
		);
		$this->assertIsInt( $original_id );
		$this->assertIsInt( $newer_id );
		$this->assertGreaterThan( $original_id, $newer_id );

		update_attached_file( $original_id, $original_file );
		update_attached_file( $newer_id, $newer_file );
		wp_update_attachment_metadata(
			$original_id,
			array(
				'file'   => $original_file,
				'width'  => 1200,
				'height' => 630,
				'sizes'  => array(
					'medium' => array(
						'file'   => wp_basename( $basename, '.jpg' ) . '-300x200.jpg',
						'width'  => 300,
						'height' => 200,
					),
				),
			)
		);
		wp_update_attachment_metadata(
			$newer_id,
			array(
				'file'   => $newer_file,
				'width'  => 900,
				'height' => 500,
			)
		);
		update_post_meta( $original_id, '_wp_attachment_image_alt', 'January alternative text' );
		update_post_meta( $newer_id, '_wp_attachment_image_alt', 'February alternative text' );

		$image = 'https://cdn.example.test/wp-content/uploads/2026/01/' . wp_basename( $basename, '.jpg' ) . '-300x200.jpg';
		$data  = erankly_get_social_image_attachment_data( $image );

		$this->assertSame( $original_id, $data['id'] );
		$this->assertSame( 300, $data['width'] );
		$this->assertSame( 200, $data['height'] );
		$this->assertSame( 'January alternative text', erankly_get_social_image_attachment_alt( $image ) );
	}
}
