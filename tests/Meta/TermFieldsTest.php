<?php
/**
 * Tests for the SEO fields on the term edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Meta;

use EasyRankly\Admin\MediaField;
use EasyRankly\Meta\Admin\TermFields;
use EasyRankly\Meta\Meta;
use WP_UnitTestCase;

/**
 * The term form saves only with a valid nonce and edit_term, and deletes empty values.
 */
final class TermFieldsTest extends WP_UnitTestCase {

	/**
	 * Registers the meta again: the test case unregisters every meta key after each test.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
	}

	/**
	 * Clears the simulated form submission.
	 */
	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Simulates the term edit form.
	 *
	 * @param array<string, string> $fields Submitted SEO fields.
	 */
	private function submit( array $fields ): void {
		$_POST = array(
			'easyrankly_term_seo' => wp_create_nonce( 'easyrankly_term_seo' ),
			'easyrankly'          => $fields,
		);
	}

	/**
	 * An editor saves sanitized values; unchecked boxes and empty fields are deleted.
	 */
	public function test_editor_saves_and_empty_values_are_deleted(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_term_meta( $term_id, '_easyrankly_nofollow', true );

		$this->submit(
			array(
				'title'     => ' <script>x</script>Shoes ',
				'canonical' => 'https://example.com/shoes/',
				'noindex'   => '1',
			)
		);
		( new TermFields() )->save( (string) $term_id );

		$this->assertSame( 'Shoes', Meta::term( $term_id, 'title' ) );
		$this->assertSame( 'https://example.com/shoes/', Meta::term( $term_id, 'canonical' ) );
		$this->assertTrue( (bool) Meta::term( $term_id, 'noindex' ) );
		$this->assertFalse( metadata_exists( 'term', $term_id, '_easyrankly_nofollow' ) );
		$this->assertFalse( metadata_exists( 'term', $term_id, '_easyrankly_description' ) );
	}

	/**
	 * Values that sanitize to empty, and the image removed with the picker, delete their meta.
	 */
	public function test_values_empty_after_sanitizing_are_deleted(): void {
		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, '_easyrankly_title', 'Old' );
		update_term_meta( $term_id, '_easyrankly_og_image', 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->submit(
			array(
				'title'     => '   ',
				'canonical' => 'javascript:alert(1)',
				'og_image'  => '0',
			)
		);
		( new TermFields() )->save( $term_id );

		foreach ( array( 'title', 'canonical', 'og_image' ) as $name ) {
			$this->assertFalse( metadata_exists( 'term', $term_id, '_easyrankly_' . $name ), $name );
		}
	}

	/**
	 * Without a valid nonce nothing is saved.
	 */
	public function test_invalid_nonce_saves_nothing(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST = array(
			'easyrankly_term_seo' => 'invalid',
			'easyrankly'          => array( 'title' => 'Forged' ),
		);
		( new TermFields() )->save( $term_id );

		$this->assertSame( '', Meta::term( $term_id, 'title' ) );
	}

	/**
	 * A user without edit_term saves nothing even with a valid nonce.
	 */
	public function test_user_without_edit_term_saves_nothing(): void {
		$term_id = self::factory()->category->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->submit( array( 'title' => 'Forged' ) );
		( new TermFields() )->save( $term_id );

		$this->assertSame( '', Meta::term( $term_id, 'title' ) );
	}

	/**
	 * The form shows escaped stored values and the nonce, only to users who can edit the term.
	 */
	public function test_render_escapes_values_and_checks_capability(): void {
		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, '_easyrankly_title', 'a"b&c' );
		$term = get_term( $term_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( '', get_echo( array( new TermFields(), 'render' ), array( $term ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$html = get_echo( array( new TermFields(), 'render' ), array( $term ) );

		$this->assertStringContainsString( 'name="easyrankly_term_seo"', $html );
		$this->assertStringContainsString( 'value="a&quot;b&amp;c"', $html );
		$this->assertStringContainsString( 'name="easyrankly[canonical]" value="" placeholder="Empty uses the term archive URL"', $html );
	}

	/**
	 * The social image is chosen from the media library, like the images of the settings.
	 */
	public function test_social_image_uses_the_media_picker(): void {
		$term_id  = self::factory()->category->create();
		$image_id = self::factory()->attachment->create_object( 'share.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_term_meta( $term_id, '_easyrankly_og_image', $image_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$html = get_echo( array( new TermFields(), 'render' ), array( get_term( $term_id ) ) );

		$this->assertStringContainsString( 'class="easyrankly-media-field"', $html );
		$this->assertStringContainsString( 'name="easyrankly[og_image]" value="' . $image_id . '"', $html );

		$fields   = new TermFields();
		$expected = array(
			'category' => 10,
			'nav_menu' => false,
		);
		foreach ( $expected as $taxonomy => $hooked ) {
			$_REQUEST['taxonomy'] = $taxonomy;
			$fields->load();
			$this->assertSame( $hooked, has_action( 'admin_enqueue_scripts', array( MediaField::class, 'enqueue' ) ), $taxonomy );
			remove_action( 'admin_enqueue_scripts', array( MediaField::class, 'enqueue' ) );
		}
		unset( $_REQUEST['taxonomy'] );
	}

	/**
	 * The fields are hooked on public taxonomies only.
	 */
	public function test_hooks_only_public_taxonomies(): void {
		$fields = new TermFields();
		$fields->hook_taxonomies();

		$this->assertSame( 10, has_action( 'category_edit_form_fields', array( $fields, 'render' ) ) );
		$this->assertSame( 10, has_action( 'edited_post_tag', array( $fields, 'save' ) ) );
		$this->assertFalse( has_action( 'nav_menu_edit_form_fields', array( $fields, 'render' ) ) );
	}
}
