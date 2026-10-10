<?php
/**
 * Tests for the translations REST routes and the editor panel loader.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Admin\EditorPanel;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Changing translations needs the rights to edit every post involved.
 */
final class RestTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private int $editor;

	/**
	 * Author user.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * Italian (default) and English, a fresh REST server and two users.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en', 'fr' );

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Resets the screen and the queue.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'easyrankly-languages' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Sends a request to a translations route.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path after the namespace.
	 * @param array<string, mixed> $params Parameters.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $path, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/easyrankly/v1/translations' . $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The editor reads the language and translations; visitors and subscribers cannot.
	 */
	public function test_get_requires_edit_rights(): void {
		$it = self::factory()->post->create( array( 'post_title' => 'Ciao' ) );
		$en = self::factory()->post->create( array( 'post_title' => 'Hello &amp; welcome' ) );
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);

		$this->assertSame( 401, $this->request( 'GET', '/' . $it )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/' . $it )->get_status() );

		wp_set_current_user( $this->editor );
		$data = $this->request( 'GET', '/' . $it )->get_data();
		$this->assertSame( 'it', $data['language'] );
		$this->assertSame( $en, $data['translations']->en['id'] );
		$this->assertSame( 'Hello & welcome', $data['translations']->en['title'] );
		$this->assertObjectNotHasProperty( 'it', $data['translations'] );
	}

	/**
	 * A translation the author cannot read only marks its language as taken.
	 */
	public function test_unreadable_translation_shows_only_its_language(): void {
		$own     = self::factory()->post->create( array( 'post_author' => $this->author ) );
		$private = self::factory()->post->create(
			array(
				'post_author' => $this->editor,
				'post_status' => 'private',
				'post_title'  => 'Secret',
			)
		);
		Translations::link(
			array(
				'it' => $own,
				'en' => $private,
			)
		);

		wp_set_current_user( $this->author );
		$data = $this->request( 'GET', '/' . $own )->get_data();

		$this->assertSame(
			array(
				'id'        => 0,
				'title'     => '',
				'status'    => '',
				'edit_link' => '',
			),
			$data['translations']->en
		);
	}

	/**
	 * The language can be set alone; a language taken by a translation is refused.
	 */
	public function test_set_language(): void {
		wp_set_current_user( $this->editor );
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);

		$response = $this->request( 'POST', '/' . $it, array( 'language' => 'fr' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fr', Translations::language( $it ) );

		$response = $this->request( 'POST', '/' . $it, array( 'language' => 'en' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'easyrankly_language_taken', $response->get_data()['code'] );

		$this->assertSame( 400, $this->request( 'POST', '/' . $it, array( 'language' => 'de' ) )->get_status() );
	}

	/**
	 * Sending translations links and unlinks the group.
	 */
	public function test_link_and_unlink(): void {
		wp_set_current_user( $this->editor );
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();

		$response = $this->request(
			'POST',
			'/' . $it,
			array(
				'language'     => 'it',
				'translations' => array( 'en' => $en ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'it' => $it,
				'en' => $en,
			),
			Translations::of( $en )
		);

		$this->request(
			'POST',
			'/' . $it,
			array(
				'language'     => 'it',
				'translations' => new \stdClass(),
			)
		);
		$this->assertNull( Translations::group( $it ) );
		$this->assertNull( Translations::group( $en ) );
	}

	/**
	 * An author cannot link, or unlink from, a post they cannot edit.
	 */
	public function test_author_cannot_touch_others_posts(): void {
		$own    = self::factory()->post->create( array( 'post_author' => $this->author ) );
		$others = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		$mine2  = self::factory()->post->create( array( 'post_author' => $this->author ) );
		wp_set_current_user( $this->author );

		$response = $this->request(
			'POST',
			'/' . $own,
			array(
				'language'     => 'it',
				'translations' => array( 'en' => $others ),
			)
		);
		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( Translations::group( $own ) );

		// A group that includes someone else's post cannot be changed either.
		Translations::link(
			array(
				'it' => $mine2,
				'en' => $others,
			)
		);
		$response = $this->request(
			'POST',
			'/' . $own,
			array(
				'language'     => 'it',
				'translations' => array( 'fr' => $mine2 ),
			)
		);
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'en', Translations::language( $others ) );
		$this->assertNotNull( Translations::group( $others ) );
	}

	/**
	 * Copying creates a linked draft with the content, terms, template and translated parent.
	 */
	public function test_copy_creates_a_linked_draft(): void {
		wp_set_current_user( $this->editor );
		$parent    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$parent_en = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Translations::link(
			array(
				'it' => $parent,
				'en' => $parent_en,
			)
		);
		$source = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Chi siamo',
				'post_content' => '<!-- wp:paragraph --><p>Testo</p><!-- /wp:paragraph -->',
				'post_parent'  => $parent,
				'meta_input'   => array( '_easyrankly_title' => 'SEO' ),
			)
		);
		$image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		set_post_thumbnail( $source, $image );

		$response = $this->request( 'POST', '/' . $source . '/copy', array( 'language' => 'en' ) );
		$this->assertSame( 201, $response->get_status() );

		$copy = get_post( Translations::of( $source )['en'] );
		$this->assertInstanceOf( \WP_Post::class, $copy );
		$this->assertSame( 'draft', $copy->post_status );
		$this->assertSame( 'Chi siamo', $copy->post_title );
		$this->assertSame( '<!-- wp:paragraph --><p>Testo</p><!-- /wp:paragraph -->', $copy->post_content );
		$this->assertSame( $parent_en, $copy->post_parent );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $copy ) );
		$this->assertSame( '', get_post_meta( $copy->ID, '_easyrankly_title', true ) );
		$this->assertSame( 'en', Translations::language( $copy->ID ) );
		$this->assertSame( $copy->ID, $response->get_data()['translations']->en['id'] );

		$this->assertSame( 400, $this->request( 'POST', '/' . $source . '/copy', array( 'language' => 'en' ) )->get_status() );
	}

	/**
	 * Copies keep the categories, which all languages share.
	 */
	public function test_copy_keeps_terms(): void {
		wp_set_current_user( $this->editor );
		$category = self::factory()->category->create();
		$source   = self::factory()->post->create( array( 'post_category' => array( $category ) ) );

		$this->request( 'POST', '/' . $source . '/copy', array( 'language' => 'fr' ) );

		$copy = Translations::of( $source )['fr'];
		$this->assertSame( array( $category ), wp_get_post_categories( $copy ) );
	}

	/**
	 * A contributor cannot copy someone else's post.
	 */
	public function test_copy_requires_edit_rights(): void {
		$source = self::factory()->post->create( array( 'post_author' => $this->editor ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$this->assertSame( 403, $this->request( 'POST', '/' . $source . '/copy', array( 'language' => 'en' ) )->get_status() );
		$this->assertSame( array( 'it' => $source ), Translations::of( $source ) );
	}

	/**
	 * Candidates are editable posts of the type and language searched.
	 */
	public function test_candidates(): void {
		$own_en    = self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_title'  => 'Apple',
			)
		);
		$others_en = self::factory()->post->create(
			array(
				'post_author' => $this->editor,
				'post_title'  => 'Apple pie',
			)
		);
		$own_it    = self::factory()->post->create(
			array(
				'post_author' => $this->author,
				'post_title'  => 'Apple it',
			)
		);
		Translations::set_language( $own_en, 'en' );
		Translations::set_language( $others_en, 'en' );

		wp_set_current_user( $this->author );
		$params = array(
			'post_type' => 'post',
			'language'  => 'en',
			'search'    => 'apple',
		);
		$this->assertSame( array( $own_en ), wp_list_pluck( $this->request( 'GET', '/candidates', $params )->get_data(), 'id' ) );

		wp_set_current_user( $this->editor );
		$this->assertEqualsCanonicalizing( array( $own_en, $others_en ), wp_list_pluck( $this->request( 'GET', '/candidates', $params )->get_data(), 'id' ) );
		$params['language'] = 'it';
		$this->assertSame( array( $own_it ), wp_list_pluck( $this->request( 'GET', '/candidates', $params )->get_data(), 'id' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/candidates', $params )->get_status() );
	}

	/**
	 * The panel loads in the post editor only with two languages.
	 */
	public function test_panel_loads_with_two_languages(): void {
		set_current_screen( 'post' );
		( new EditorPanel() )->enqueue();

		if ( is_readable( dirname( __DIR__, 2 ) . '/build/languages.asset.php' ) ) {
			$this->assertTrue( wp_script_is( 'easyrankly-languages', 'enqueued' ) );
			$this->assertStringContainsString( '"slug":"en"', (string) wp_scripts()->get_data( 'easyrankly-languages', 'before' )[1] );
			$switcher = generate_block_asset_handle( 'easyrankly/language-switcher', 'editorScript' );
			$this->assertFalse( wp_scripts()->get_data( $switcher, 'before' ), 'The languages are printed once.' );
		} else {
			$this->assertSame( 10, has_action( 'admin_notices', array( \EasyRankly\Admin\Assets::class, 'missing_build_notice' ) ) );
		}

		wp_dequeue_script( 'easyrankly-languages' );
		update_option( Settings::OPTION, array( 'languages' => array() ) );
		( new EditorPanel() )->enqueue();
		$this->assertFalse( wp_script_is( 'easyrankly-languages', 'enqueued' ) );
	}
}
