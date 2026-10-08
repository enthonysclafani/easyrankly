<?php
/**
 * Tests for the SEO meta of posts and terms.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Meta;

use EasyRankly\Meta\Meta;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * SEO meta is registered with a schema, sanitized and writable only by users who can edit the object.
 */
final class MetaTest extends WP_UnitTestCase {

	/**
	 * Registers the meta again (the test case unregisters every meta key after each test)
	 * and starts from a clean REST server.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Every field is registered for posts and terms of any subtype.
	 */
	public function test_fields_are_registered_for_posts_and_terms(): void {
		foreach ( array_keys( Meta::fields() ) as $name ) {
			$this->assertTrue( registered_meta_key_exists( 'post', Meta::PREFIX . $name ), $name );
			$this->assertTrue( registered_meta_key_exists( 'term', Meta::PREFIX . $name ), $name );
		}
	}

	/**
	 * Public post types expose the meta in REST, so the editor panel can save it.
	 */
	public function test_public_post_types_support_custom_fields(): void {
		$this->assertTrue( post_type_supports( 'post', 'custom-fields' ) );
		$this->assertTrue( post_type_supports( 'page', 'custom-fields' ) );
	}

	/**
	 * The author saves the meta through the core endpoint, sanitized by type.
	 */
	public function test_author_saves_sanitized_meta_through_rest(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			array(
				'meta' => array(
					'_easyrankly_title'     => '<b>Title</b>',
					'_easyrankly_canonical' => 'javascript:alert(1)',
					'_easyrankly_noindex'   => true,
					'_easyrankly_og_image'  => 12,
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Title', Meta::post( $post_id, 'title' ) );
		$this->assertSame( '', Meta::post( $post_id, 'canonical' ) );
		$this->assertTrue( (bool) Meta::post( $post_id, 'noindex' ) );
		$this->assertSame( 12, (int) Meta::post( $post_id, 'og_image' ) );
	}

	/**
	 * A user who cannot edit the post cannot change its SEO meta.
	 */
	public function test_other_author_cannot_change_meta(): void {
		$post_id = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( '_easyrankly_title' => 'Hijacked' ) ) );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( '', Meta::post( $post_id, 'title' ) );
	}

	/**
	 * Term meta goes through the terms endpoint with edit_term.
	 */
	public function test_term_meta_requires_edit_term(): void {
		$term_id = self::factory()->category->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/categories/' . $term_id );
		$request->set_body_params( array( 'meta' => array( '_easyrankly_title' => 'Nope' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/categories/' . $term_id );
		$request->set_body_params( array( 'meta' => array( '_easyrankly_description' => "Line\nbreak <i>x</i>" ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'Line break x', Meta::term( $term_id, 'description' ) );
		$this->assertSame( '', Meta::term( $term_id, 'title' ) );
	}

	/**
	 * Defaults apply when nothing is stored.
	 */
	public function test_defaults_without_stored_values(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', Meta::post( $post_id, 'title' ) );
		$this->assertFalse( Meta::post( $post_id, 'noindex' ) );
		$this->assertSame( 0, Meta::post( $post_id, 'og_image' ) );
	}
}
