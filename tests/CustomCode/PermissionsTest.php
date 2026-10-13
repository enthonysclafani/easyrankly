<?php
/**
 * Permission matrix of custom code snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\CustomCode;

use EasyRankly\CustomCode\CustomCode;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Who may do what with an HTML and a PHP snippet through REST, the channel every screen uses
 * (docs/sicurezza.md, CC-01). HTML needs manage_options and unfiltered_html, PHP also
 * edit_plugins; on multisite both belong to super admins only.
 */
final class PermissionsTest extends WP_UnitTestCase {

	/**
	 * Roles of the matrix, plus two custom ones with manage_options but without some capability.
	 */
	private const USERS = array( 'anonymous', 'subscriber', 'author', 'editor', 'administrator', 'super_admin', 'no_php', 'no_html' );

	/**
	 * Operations, each on an HTML and a PHP snippet.
	 */
	private const OPERATIONS = array( 'create', 'read', 'list', 'update', 'deactivate', 'delete' );

	/**
	 * Post type and meta registered, custom roles added.
	 */
	public function set_up(): void {
		parent::set_up();
		( new CustomCode() )->register_post_type();
		add_role(
			'no_php',
			'No PHP',
			array(
				'read'            => true,
				'manage_options'  => true,
				'unfiltered_html' => true,
			)
		);
		add_role(
			'no_html',
			'No HTML',
			array(
				'read'           => true,
				'manage_options' => true,
				'edit_plugins'   => true,
			)
		);
	}

	/**
	 * Custom roles removed.
	 */
	public function tear_down(): void {
		remove_role( 'no_php' );
		remove_role( 'no_html' );
		parent::tear_down();
	}

	/**
	 * Every user, type and operation.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function cases(): array {
		$cases = array();
		foreach ( self::USERS as $user ) {
			foreach ( array( 'html', 'php' ) as $type ) {
				foreach ( self::OPERATIONS as $operation ) {
					$cases[ "$user $operation $type" ] = array( $user, $type, $operation );
				}
			}
		}

		return $cases;
	}

	/**
	 * The user may do the operation only if the rule above allows it.
	 *
	 * @dataProvider cases
	 *
	 * @param string $user      User from USERS.
	 * @param string $type      html or php.
	 * @param string $operation Operation from OPERATIONS.
	 */
	public function test_matrix( string $user, string $type, string $operation ): void {
		wp_set_current_user( $this->user( 'super_admin' ) );
		$id = $this->rest( 'POST', '', $this->body( $type ) )->get_data()['id'];

		wp_set_current_user( $this->user( $user ) );
		$allowed = self::allowed( $user, $type );

		switch ( $operation ) {
			case 'create':
				$this->assert_outcome( $allowed, $this->rest( 'POST', '', $this->body( $type ) ), 201 );
				break;
			case 'read':
				$this->assert_outcome( $allowed, $this->rest( 'GET', '/' . $id ), 200 );
				break;
			case 'list':
				$response = $this->rest( 'GET', '' );
				$listed   = 200 === $response->get_status() && in_array( $id, wp_list_pluck( (array) $response->get_data(), 'id' ), true );
				$this->assertSame( $allowed, $listed );
				break;
			case 'update':
				$this->assert_outcome( $allowed, $this->rest( 'POST', '/' . $id, array( 'content' => 'php' === $type ? 'echo 2;' : '<i>2</i>' ) ), 200 );
				break;
			case 'deactivate':
				$this->assert_outcome( $allowed, $this->rest( 'POST', '/' . $id, array( 'status' => 'draft' ) ), 200 );
				break;
			case 'delete':
				$this->assert_outcome( $allowed, $this->rest( 'DELETE', '/' . $id, array(), array( 'force' => true ) ), 200 );
				break;
		}

		if ( ! $allowed && 'create' !== $operation ) {
			$post = get_post( $id );
			$this->assertInstanceOf( \WP_Post::class, $post );
			$this->assertSame( 'publish', $post->post_status );
			$this->assertSame( 'php' === $type ? 'echo 1;' : '<i>1</i>', $post->post_content );
		}
	}

	/**
	 * Whether the rule allows a user to touch a snippet of a type.
	 *
	 * @param string $user User from USERS.
	 * @param string $type html or php.
	 * @return bool
	 */
	private static function allowed( string $user, string $type ): bool {
		if ( is_multisite() ) {
			return 'super_admin' === $user;
		}

		return 'php' === $type ? in_array( $user, array( 'administrator', 'super_admin' ), true ) : in_array( $user, array( 'administrator', 'super_admin', 'no_php' ), true );
	}

	/**
	 * A user of the matrix; super admin on multisite, a plain administrator otherwise.
	 *
	 * @param string $user User from USERS.
	 * @return int User ID, 0 for anonymous.
	 */
	private function user( string $user ): int {
		if ( 'anonymous' === $user ) {
			return 0;
		}

		$id = self::factory()->user->create( array( 'role' => 'super_admin' === $user ? 'administrator' : $user ) );
		if ( 'super_admin' === $user && is_multisite() ) {
			grant_super_admin( $id );
		}

		return $id;
	}

	/**
	 * Asserts success or refusal of a request.
	 *
	 * @param bool              $allowed  Whether it should succeed.
	 * @param \WP_REST_Response $response Response.
	 * @param int               $success  Status of a success.
	 */
	private function assert_outcome( bool $allowed, \WP_REST_Response $response, int $success ): void {
		if ( $allowed ) {
			$this->assertSame( $success, $response->get_status() );
		} else {
			$this->assertContains( $response->get_status(), array( 401, 403 ) );
		}
	}

	/**
	 * Sends a REST request to the snippets endpoint.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route below /wp/v2/easyrankly-snippets.
	 * @param array<string, mixed> $body   JSON body.
	 * @param array<string, mixed> $query  Query parameters.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, string $route, array $body = array(), array $query = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/easyrankly-snippets' . $route );
		$request->set_query_params( $query );
		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Body of an active snippet in the head.
	 *
	 * @param string $type html or php.
	 * @return array<string, mixed>
	 */
	private function body( string $type ): array {
		return array(
			'title'   => $type,
			'content' => 'php' === $type ? 'echo 1;' : '<i>1</i>',
			'status'  => 'publish',
			'meta'    => array(
				CustomCode::meta_key( 'type' )     => $type,
				CustomCode::meta_key( 'position' ) => 'head',
			),
		);
	}
}
