<?php
/**
 * Tests for the redirects post type and its REST endpoint.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Redirects;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Only administrators manage redirects, and every rule is validated on save.
 */
final class RedirectsTest extends WP_UnitTestCase {

	/**
	 * Meta re-registered (the test case unregisters it after each test), admin logged in.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Redirects() )->register_post_type();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
	}

	/**
	 * Sends a REST request to the redirects endpoint.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $body   JSON body.
	 * @param string               $route  Route below /wp/v2/easyrankly-redirects.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, array $body = array(), string $route = '' ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/easyrankly-redirects' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Body of a redirect.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 * @param array  $meta   Extra meta (without prefix).
	 * @return array<string, mixed>
	 */
	private function body( string $source, string $target, array $meta = array() ): array {
		$fields = array_merge(
			array(
				'target' => $target,
				'code'   => 301,
				'regex'  => false,
				'forced' => false,
			),
			$meta
		);

		$prefixed = array();
		foreach ( $fields as $name => $value ) {
			$prefixed[ Redirects::meta_key( $name ) ] = $value;
		}

		return array(
			'title'  => $source,
			'status' => 'publish',
			'meta'   => $prefixed,
		);
	}

	/**
	 * A valid redirect is stored normalized, with its hash in post_name.
	 */
	public function test_rest_creates_normalized_redirect(): void {
		$response = $this->rest( 'POST', $this->body( '/Old-Page/', '/new-page' ) );

		$this->assertSame( 201, $response->get_status() );
		$post = get_post( $response->get_data()['id'] );
		$this->assertSame( '/old-page', $post->post_title );
		$this->assertSame( md5( '/old-page' ), $post->post_name );
		$this->assertSame( '/new-page', get_post_meta( $post->ID, Redirects::meta_key( 'target' ), true ) );
	}

	/**
	 * Invalid rules and duplicate sources answer 400 and store nothing.
	 */
	public function test_rest_rejects_invalid_and_duplicate(): void {
		$this->assertSame( 400, $this->rest( 'POST', $this->body( '/a', 'javascript:alert(1)' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', $this->body( '/a', '/b', array( 'code' => 200 ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', $this->body( '^/(x', '/b', array( 'regex' => true ) ) )->get_status() );

		$this->assertSame( 201, $this->rest( 'POST', $this->body( '/a', '/b' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', $this->body( '/A/', '/c' ) )->get_status() );

		$this->assertCount( 1, get_posts( array( 'post_type' => Redirects::POST_TYPE ) ) );
	}

	/**
	 * Updating keeps the same source without tripping the duplicate check.
	 */
	public function test_rest_update_keeps_source(): void {
		$id       = $this->rest( 'POST', $this->body( '/a', '/b' ) )->get_data()['id'];
		$response = $this->rest( 'POST', array( 'meta' => array( Redirects::meta_key( 'target' ) => '/c' ) ), '/' . $id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '/a', get_post( $id )->post_title );
		$this->assertSame( '/c', get_post_meta( $id, Redirects::meta_key( 'target' ), true ) );
	}

	/**
	 * Users without manage_options can neither read nor write redirects.
	 */
	public function test_rest_requires_manage_options(): void {
		$id = $this->rest( 'POST', $this->body( '/a', '/b' ) )->get_data()['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertContains( $this->rest( 'POST', $this->body( '/x', '/y' ) )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'GET', array(), '/' . $id )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'DELETE', array(), '/' . $id )->get_status(), array( 401, 403 ) );

		$this->assertContains( $this->rest( 'GET' )->get_status(), array( 401, 403 ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET' )->get_status() );
		$this->assertSame( 401, $this->rest( 'GET', array(), '/' . $id )->get_status() );
	}

	/**
	 * Regex and forced rules land in their options; deleting a rule rebuilds them.
	 */
	public function test_lists_follow_changes(): void {
		$regex  = $this->rest( 'POST', $this->body( '^/blog/(.*)$', '/news/$1', array( 'regex' => true ) ) )->get_data()['id'];
		$forced = $this->rest( 'POST', $this->body( '/live', '/elsewhere', array( 'forced' => true ) ) )->get_data()['id'];
		$this->rest( 'POST', $this->body( '/plain', '/x' ) );

		$this->assertSame( array( '^/blog/(.*)$' ), array_column( get_option( Redirects::REGEX_OPTION ), 'source' ) );
		$this->assertSame( array( '/live' ), array_column( get_option( Redirects::FORCED_OPTION ), 'source' ) );

		$this->rest( 'POST', array( 'status' => 'draft' ), '/' . $forced );
		$this->assertSame( array(), get_option( Redirects::FORCED_OPTION ) );

		wp_delete_post( $regex, true );
		$this->assertSame( array(), get_option( Redirects::REGEX_OPTION ) );
	}

	/**
	 * A rule that would bounce with an existing one is rejected.
	 */
	public function test_rest_rejects_loop_between_two_rules(): void {
		$this->assertSame( 201, $this->rest( 'POST', $this->body( '/a', '/b' ) )->get_status() );

		$response = $this->rest( 'POST', $this->body( '/b', '/a/' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'easyrankly_redirect_loop', $response->get_data()['code'] );
	}

	/**
	 * Redirects are deleted, never trashed, and a trash done elsewhere rebuilds the lists.
	 */
	public function test_trash(): void {
		$id = $this->rest( 'POST', $this->body( '/live', '/x', array( 'forced' => true ) ) )->get_data()['id'];

		$this->assertSame( 501, $this->rest( 'DELETE', array(), '/' . $id )->get_status() );

		wp_trash_post( $id );
		$this->assertSame( array(), get_option( Redirects::FORCED_OPTION ) );

		wp_untrash_post( $id );
		wp_publish_post( $id );
		$this->assertSame( array( '/live' ), array_column( get_option( Redirects::FORCED_OPTION ), 'source' ) );
	}
}
