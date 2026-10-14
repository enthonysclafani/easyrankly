<?php
/**
 * Tests for the SEO meta box on the classic post edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Meta;

use EasyRankly\Meta\Admin\ClassicFields;
use EasyRankly\Meta\Meta;
use WP_UnitTestCase;

/**
 * The box appears only without the block editor and saves only the post the form was sent for,
 * with a valid nonce and edit_post.
 */
final class ClassicFieldsTest extends WP_UnitTestCase {

	/**
	 * Registers the meta again: the test case unregisters every meta key after each test.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
	}

	/**
	 * Clears the simulated form submission and the meta boxes.
	 */
	public function tear_down(): void {
		$_POST = array();
		unset( $GLOBALS['wp_meta_boxes'] );

		parent::tear_down();
	}

	/**
	 * Simulates the classic edit form of a post.
	 *
	 * @param int                   $post_id Post the form is for.
	 * @param array<string, string> $fields  Submitted SEO fields.
	 */
	private function submit( int $post_id, array $fields ): void {
		$_POST = array(
			'post_ID'             => (string) $post_id,
			'easyrankly_post_seo' => wp_create_nonce( 'easyrankly_post_seo' ),
			'easyrankly'          => $fields,
		);
	}

	/**
	 * Whether the SEO box is registered for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function has_box( string $post_type ): bool {
		global $wp_meta_boxes;

		return ! empty( $wp_meta_boxes[ $post_type ]['normal']['default']['easyrankly-seo'] );
	}

	/**
	 * Only posts edited without the block editor get the box; never attachments.
	 */
	public function test_box_only_without_block_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post       = get_post( self::factory()->post->create() );
		$attachment = get_post( self::factory()->attachment->create_object( 'a.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) ) );
		$fields     = new ClassicFields();

		$fields->add( 'post', $post );
		$this->assertFalse( $this->has_box( 'post' ) );

		add_filter( 'use_block_editor_for_post', '__return_false' );
		$fields->add( 'post', $post );
		$fields->add( 'attachment', $attachment );
		remove_filter( 'use_block_editor_for_post', '__return_false' );

		$this->assertTrue( $this->has_box( 'post' ) );
		$this->assertFalse( $this->has_box( 'attachment' ) );
	}

	/**
	 * An editor saves sanitized values; unchecked boxes and empty fields are deleted.
	 */
	public function test_editor_saves_and_empty_values_are_deleted(): void {
		$post_id = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_post_meta( $post_id, '_easyrankly_nofollow', true );
		update_post_meta( $post_id, '_easyrankly_og_title', 'Old' );

		$this->submit(
			$post_id,
			array(
				'title'     => ' <script>x</script>Shoes ',
				'canonical' => 'https://example.com/shoes/',
				'noindex'   => '1',
				'og_title'  => '  ',
			)
		);
		( new ClassicFields() )->save( (string) $post_id, get_post( $post_id ) );

		$this->assertSame( 'Shoes', Meta::post( $post_id, 'title' ) );
		$this->assertSame( 'https://example.com/shoes/', Meta::post( $post_id, 'canonical' ) );
		$this->assertTrue( (bool) Meta::post( $post_id, 'noindex' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_easyrankly_nofollow' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_easyrankly_og_title' ) );
	}

	/**
	 * An author cannot point the canonical of their own post to another site, as from the block editor.
	 */
	public function test_author_external_canonical_is_dropped(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );

		$this->submit( $post_id, array( 'canonical' => 'https://other.example/' ) );
		( new ClassicFields() )->save( $post_id, get_post( $post_id ) );

		$this->assertSame( '', Meta::post( $post_id, 'canonical' ) );
	}

	/**
	 * Nothing is saved with an invalid nonce, without edit_post, or for another post saved
	 * during the same request (a translation created from the form).
	 */
	public function test_nothing_saved_without_rights_or_for_other_posts(): void {
		$post_id = self::factory()->post->create();
		$other   = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->submit( $post_id, array( 'title' => 'Forged' ) );
		( new ClassicFields() )->save( $other, get_post( $other ) );
		$this->assertSame( '', Meta::post( $other, 'title' ) );

		$_POST['easyrankly_post_seo'] = 'invalid';
		( new ClassicFields() )->save( $post_id, get_post( $post_id ) );
		$this->assertSame( '', Meta::post( $post_id, 'title' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->submit( $post_id, array( 'title' => 'Forged' ) );
		( new ClassicFields() )->save( $post_id, get_post( $post_id ) );
		$this->assertSame( '', Meta::post( $post_id, 'title' ) );
	}

	/**
	 * The box shows escaped stored values, the nonce and the image picker.
	 */
	public function test_render_escapes_values(): void {
		$post_id  = self::factory()->post->create();
		$image_id = self::factory()->attachment->create_object( 'share.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $post_id, '_easyrankly_title', 'a"b&c' );
		update_post_meta( $post_id, '_easyrankly_og_image', $image_id );

		$html = get_echo( array( new ClassicFields(), 'render' ), array( get_post( $post_id ) ) );

		$this->assertStringContainsString( 'name="easyrankly_post_seo"', $html );
		$this->assertStringContainsString( 'value="a&quot;b&amp;c"', $html );
		$this->assertStringContainsString( 'name="easyrankly[og_image]" value="' . $image_id . '"', $html );
	}
}
