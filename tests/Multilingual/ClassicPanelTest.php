<?php
/**
 * Tests for the language meta box on the classic post edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Meta\Admin\ClassicFields;
use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Admin\ClassicPanel;
use EasyRankly\Multilingual\Translations;
use WP_UnitTestCase;

/**
 * The classic box changes language and translations with the same checks as the REST routes.
 */
final class ClassicPanelTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Italian (default), English and French, meta registered, an editor.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en', 'fr' );
		( new Meta() )->register_meta();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Clears the simulated form, the meta boxes and the redirect filters.
	 */
	public function tear_down(): void {
		$_POST = array();
		unset( $GLOBALS['wp_meta_boxes'] );
		remove_all_filters( 'redirect_post_location' );

		parent::tear_down();
	}

	/**
	 * Simulates the classic edit form and runs the save of the box.
	 *
	 * @param int                  $post_id Post the form is for.
	 * @param array<string, mixed> $fields  Submitted fields besides the nonce.
	 */
	private function save( int $post_id, array $fields ): void {
		$_POST = array(
			'post_ID'                  => (string) $post_id,
			'easyrankly_post_language' => wp_create_nonce( 'easyrankly_post_language' ),
		) + $fields;

		( new ClassicPanel() )->save( $post_id, get_post( $post_id ) );
	}

	/**
	 * Where the post form goes after saving.
	 *
	 * @param int $post_id Post saved.
	 * @return string
	 */
	private function redirect( int $post_id ): string {
		return (string) apply_filters( 'redirect_post_location', 'post.php?post=' . $post_id . '&action=edit', $post_id );
	}

	/**
	 * The box appears only without the block editor and with two languages.
	 */
	public function test_box_only_without_block_editor(): void {
		global $wp_meta_boxes;
		$post = get_post( self::factory()->post->create() );

		( new ClassicPanel() )->add( 'post', $post );
		$this->assertTrue( empty( $wp_meta_boxes['post']['side']['default']['easyrankly-language'] ) );

		add_filter( 'use_block_editor_for_post', '__return_false' );
		( new ClassicPanel() )->add( 'post', $post );
		remove_filter( 'use_block_editor_for_post', '__return_false' );

		$this->assertFalse( empty( $wp_meta_boxes['post']['side']['default']['easyrankly-language'] ) );
	}

	/**
	 * The language select changes the language; a language already taken is refused.
	 */
	public function test_changes_language(): void {
		$post_id = self::factory()->post->create();
		$this->save( $post_id, array( 'easyrankly_language' => 'en' ) );
		$this->assertSame( 'en', Translations::language( $post_id ) );

		$french = self::factory()->post->create();
		Translations::link(
			array(
				'en' => $post_id,
				'fr' => $french,
			)
		);
		$this->save( $post_id, array( 'easyrankly_language' => 'fr' ) );

		$this->assertSame( 'en', Translations::language( $post_id ) );
		$this->assertStringContainsString( 'easyrankly_language_error=easyrankly_language_taken', $this->redirect( $post_id ) );
	}

	/**
	 * "Create draft" opens a new draft in that language, without the SEO fields of the source
	 * even though the source's form is still being processed.
	 */
	public function test_creates_translation_and_opens_it(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Ciao' ) );
		( new ClassicFields() )->register();
		$fields = array(
			'easyrankly_language'  => 'it',
			'easyrankly_translate' => 'en',
			'easyrankly_post_seo'  => wp_create_nonce( 'easyrankly_post_seo' ),
			'easyrankly'           => array( 'title' => 'Titolo SEO' ),
		);

		$this->save( $post_id, $fields );
		$copy = Translations::of( $post_id )['en'] ?? 0;

		$this->assertGreaterThan( 0, $copy );
		$this->assertSame( 'en', Translations::language( $copy ) );
		$this->assertSame( '', Meta::post( $copy, 'title' ) );
		$this->assertSame( get_edit_post_link( $copy, 'raw' ), $this->redirect( $post_id ) );
	}

	/**
	 * Existing content is linked from the select and unlinked with its button.
	 */
	public function test_links_and_unlinks(): void {
		$post_id = self::factory()->post->create();
		$english = self::factory()->post->create();

		$this->save(
			$post_id,
			array(
				'easyrankly_language' => 'it',
				'easyrankly_link'     => array(
					'en' => (string) $english,
					'fr' => '0',
				),
			)
		);
		$this->assertSame(
			array(
				'it' => $post_id,
				'en' => $english,
			),
			Translations::of( $post_id )
		);

		$html = get_echo( array( new ClassicPanel(), 'render' ), array( get_post( $post_id ) ) );
		$this->assertStringContainsString( 'name="easyrankly_unlink" value="en"', $html );
		$this->assertStringContainsString( 'name="easyrankly_translate" value="fr"', $html );

		$this->save(
			$post_id,
			array(
				'easyrankly_language' => 'it',
				'easyrankly_unlink'   => 'en',
			)
		);
		$this->assertSame( array( 'it' => $post_id ), Translations::of( $post_id ) );
	}

	/**
	 * An author cannot link a post they cannot edit, nor translate without editing the group.
	 */
	public function test_author_cannot_link_others_posts(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );
		$others  = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		wp_set_current_user( $author );

		$this->save(
			$post_id,
			array(
				'easyrankly_language' => 'it',
				'easyrankly_link'     => array( 'en' => (string) $others ),
			)
		);

		$this->assertSame( array( 'it' => $post_id ), Translations::of( $post_id ) );
		$this->assertStringContainsString( 'easyrankly_language_error=easyrankly_forbidden', $this->redirect( $post_id ) );
	}
}
