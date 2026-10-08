<?php
/**
 * Tests for the read-only abilities of the agent.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The abilities are registered, read the right data, check permissions and are
 * reachable through the core REST API.
 */
final class AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * A fresh REST server and an administrator.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Resets the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Runs an ability by name.
	 *
	 * @param string $name  Ability name without namespace.
	 * @param mixed  $input Input.
	 * @return mixed
	 */
	private function ability( string $name, $input = null ) {
		$ability = wp_get_ability( 'easyrankly/' . $name );
		$this->assertInstanceOf( \WP_Ability::class, $ability );

		return $ability->execute( $input );
	}

	/**
	 * The category and the read-only abilities exist, all read-only and shown in REST.
	 */
	public function test_abilities_are_registered_read_only(): void {
		$this->assertTrue( wp_has_ability_category( 'easyrankly' ) );

		foreach ( array( 'get-post-seo', 'get-site-context', 'get-memory', 'search-content' ) as $name ) {
			$ability = wp_get_ability( 'easyrankly/' . $name );
			$this->assertInstanceOf( \WP_Ability::class, $ability, $name );
			$this->assertSame( 'easyrankly', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'annotations' )['readonly'], $name );
			$this->assertFalse( $ability->get_meta_item( 'annotations' )['destructive'], $name );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ), $name );
		}
	}

	/**
	 * The SEO data of a post: stored fields typed by their schema, summary and, on request, the text.
	 */
	public function test_get_post_seo_returns_fields_and_content(): void {
		wp_set_current_user( $this->admin );
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Pasta & basil',
				'post_content' => '<!-- wp:paragraph --><p>Fresh <strong>basil</strong> pasta.</p><!-- /wp:paragraph -->[gallery]',
				'post_status'  => 'draft',
				'meta_input'   => array(
					'_easyrankly_title'    => 'Custom title',
					'_easyrankly_noindex'  => '1',
					'_easyrankly_og_image' => '12',
				),
			)
		);

		$data = $this->ability( 'get-post-seo', array( 'id' => $post ) );

		$this->assertIsArray( $data );
		$this->assertSame( $post, $data['id'] );
		$this->assertSame( 'Pasta & basil', $data['title'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( 'Custom title', $data['seo']['title'] );
		$this->assertSame( '', $data['seo']['description'] );
		$this->assertTrue( $data['seo']['noindex'] );
		$this->assertFalse( $data['seo']['nofollow'] );
		$this->assertSame( 12, $data['seo']['og_image'] );
		$this->assertArrayNotHasKey( 'content', $data );

		$data = $this->ability(
			'get-post-seo',
			array(
				'id'              => $post,
				'include_content' => true,
			)
		);
		$this->assertSame( 'Fresh basil pasta.', $data['content'] );
	}

	/**
	 * A user who cannot edit the post gets nothing; input outside the schema is refused.
	 */
	public function test_get_post_seo_checks_permission_and_schema(): void {
		$post = self::factory()->post->create( array( 'post_author' => $this->admin ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = $this->ability( 'get-post-seo', array( 'id' => $post ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 'ability_invalid_input', $this->ability( 'get-post-seo', array( 'id' => 'x' ) )->get_error_code() );
		$this->assertSame(
			'ability_invalid_input',
			$this->ability(
				'get-post-seo',
				array(
					'id'    => $post,
					'extra' => 1,
				)
			)->get_error_code()
		);
	}

	/**
	 * Attachments and non-viewable types are not content for the agent.
	 */
	public function test_get_post_seo_ignores_attachments(): void {
		wp_set_current_user( $this->admin );
		$attachment = self::factory()->attachment->create();

		$this->assertSame( 'easyrankly_not_found', $this->ability( 'get-post-seo', array( 'id' => $attachment ) )->get_error_code() );
	}

	/**
	 * The site context lists name, languages, identity and published counts.
	 */
	public function test_get_site_context(): void {
		update_option( 'blogname', 'Trattoria' );
		update_option(
			Settings::OPTION,
			array(
				'identity_type' => 'organization',
				'identity_name' => 'Trattoria Srl',
				'languages'     => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
				),
			)
		);
		self::factory()->post->create_many( 2 );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->ability( 'get-site-context' ) );

		wp_set_current_user( $this->admin );
		$data = $this->ability( 'get-site-context' );

		$this->assertSame( 'Trattoria', $data['name'] );
		$this->assertSame( 'Trattoria Srl', $data['identity']['name'] );
		$this->assertSame( array( 'it', 'en' ), array_column( $data['languages'], 'slug' ) );

		$types = array_column( $data['content_types'], 'published', 'name' );
		$this->assertSame( 2, $types['post'] );
		$this->assertArrayNotHasKey( 'attachment', $types );
	}

	/**
	 * Search finds published content only, without password-protected posts, and pages.
	 */
	public function test_search_content(): void {
		$match     = self::factory()->post->create( array( 'post_title' => 'Lemon cake' ) );
		$page      = self::factory()->post->create(
			array(
				'post_title' => 'Lemon trees',
				'post_type'  => 'page',
			)
		);
		$draft     = self::factory()->post->create(
			array(
				'post_title'  => 'Lemon draft',
				'post_status' => 'draft',
			)
		);
		$protected = self::factory()->post->create(
			array(
				'post_title'    => 'Lemon secret',
				'post_password' => 'x',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Orange cake' ) );

		wp_set_current_user( $this->admin );
		$data = $this->ability( 'search-content', array( 'search' => 'lemon' ) );

		$this->assertSame( 2, $data['total'] );
		$ids = array_column( $data['items'], 'id' );
		$this->assertEqualsCanonicalizing( array( $match, $page ), $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $protected, $ids );

		$data = $this->ability(
			'search-content',
			array(
				'search'    => 'lemon',
				'post_type' => 'page',
			)
		);
		$this->assertSame( array( $page ), array_column( $data['items'], 'id' ) );

		$data = $this->ability(
			'search-content',
			array(
				'search'    => 'lemon',
				'post_type' => 'attachment',
			)
		);
		$this->assertSame( 0, $data['total'] );

		$this->assertWPError(
			$this->ability(
				'search-content',
				array(
					'search'   => 'lemon',
					'per_page' => 500,
				)
			)
		);
	}

	/**
	 * The core REST API runs the abilities with the caller's permissions.
	 */
	public function test_rest_run(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Hello' ) );

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/easyrankly/get-post-seo/run' );
		$request->set_query_params( array( 'input' => array( 'id' => $post ) ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( $this->admin );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Hello', $response->get_data()['title'] );
	}
}
