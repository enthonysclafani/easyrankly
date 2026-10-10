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
	 * Every field is registered for the content that has a page of its own, and only for it:
	 * redirects, snippets, navigation menus and menu terms get no SEO fields.
	 */
	public function test_fields_are_registered_for_viewable_posts_and_terms(): void {
		foreach ( array_keys( Meta::fields() ) as $name ) {
			$key = Meta::PREFIX . $name;
			foreach ( array( 'post', 'page', 'attachment' ) as $post_type ) {
				$this->assertTrue( registered_meta_key_exists( 'post', $key, $post_type ), "$post_type $name" );
			}
			foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
				$this->assertTrue( registered_meta_key_exists( 'term', $key, $taxonomy ), "$taxonomy $name" );
			}
			foreach ( array( 'erankly_redirect', 'erankly_snippet', 'wp_navigation', 'wp_template' ) as $post_type ) {
				$this->assertFalse( registered_meta_key_exists( 'post', $key, $post_type ), "$post_type $name" );
			}
			$this->assertFalse( registered_meta_key_exists( 'post', $key ), "any post $name" );
			$this->assertFalse( registered_meta_key_exists( 'term', $key, 'nav_menu' ), "nav_menu $name" );
		}
	}

	/**
	 * Post types and taxonomies registered after the plugin get the fields too.
	 */
	public function test_fields_are_registered_for_late_types(): void {
		register_post_type(
			'late_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		register_taxonomy( 'late_genre', 'late_book', array( 'public' => true ) );
		register_post_type( 'late_private', array( 'public' => false ) );

		$this->assertTrue( registered_meta_key_exists( 'post', '_easyrankly_title', 'late_book' ) );
		$this->assertTrue( post_type_supports( 'late_book', 'custom-fields' ) );
		$this->assertTrue( registered_meta_key_exists( 'term', '_easyrankly_title', 'late_genre' ) );
		$this->assertFalse( registered_meta_key_exists( 'post', '_easyrankly_title', 'late_private' ) );

		unregister_taxonomy( 'late_genre' );
		unregister_post_type( 'late_book' );
		unregister_post_type( 'late_private' );
	}

	/**
	 * The REST response of a redirect carries no SEO fields.
	 */
	public function test_redirects_have_no_seo_fields_in_rest(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create( array( 'post_type' => 'erankly_redirect' ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/easyrankly-redirects/' . $id ) );
		$meta     = (array) ( $response->get_data()['meta'] ?? array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( Meta::PREFIX . 'title', $meta );
	}

	/**
	 * The fields of the page's own object: a post, the posts page, a term; nothing elsewhere.
	 */
	public function test_queried_reads_the_object_of_the_page(): void {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$term = self::factory()->category->create();
		update_post_meta( $post, Meta::PREFIX . 'title', 'Post' );
		update_post_meta( $page, Meta::PREFIX . 'title', 'Posts page' );
		update_term_meta( $term, Meta::PREFIX . 'title', 'Term' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_for_posts', $page );

		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'Post', Meta::queried( 'title' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( 'Posts page', Meta::queried( 'title' ) );
		$this->go_to( get_term_link( $term ) );
		$this->assertSame( 'Term', Meta::queried( 'title' ) );
		$this->go_to( home_url( '/?s=x' ) );
		$this->assertNull( Meta::queried( 'title' ) );
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
