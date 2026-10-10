<?php
/**
 * REST routes to read and change the translations of a post.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Routes used by the editor panel, under `easyrankly/v1/translations`:
 *
 * - GET  `/{id}`: language of the post and its translations;
 * - POST `/{id}`: sets the language and, with `translations`, the whole group;
 * - POST `/{id}/copy`: creates a draft translation in a language;
 * - GET  `/candidates`: posts that could become a translation.
 *
 * Every change needs `edit_post` on each post it touches: the post, the posts linked to it,
 * and the posts of the groups they leave.
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'easyrankly/v1';

	/**
	 * Hooks the route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$id = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array( 'id' => $id ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_update' ),
					'args'                => array(
						'id'           => $id,
						'language'     => self::language_arg(),
						'translations' => array(
							'description'          => __( 'The other translations of the post: language => post ID. Languages left out are unlinked.', 'easyrankly' ),
							'type'                 => 'object',
							'patternProperties'    => array(
								Languages::SLUG_PATTERN => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
							'additionalProperties' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>[\d]+)/copy',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_copy' ),
				'permission_callback' => array( $this, 'can_copy' ),
				'args'                => array(
					'id'       => $id,
					'language' => self::language_arg(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/translations/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_candidates' ),
				'permission_callback' => array( $this, 'can_list_candidates' ),
				'args'                => array(
					'post_type' => array(
						'type'     => 'string',
						'enum'     => Languages::post_types(),
						'required' => true,
					),
					'language'  => self::language_arg(),
					'search'    => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Language of the post and its translations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_item( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->response( (int) $request['id'] );
	}

	/**
	 * Sets the language of the post and, when `translations` is sent, its whole group.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$language = (string) $request['language'];

		if ( null === $request['translations'] ) {
			$result = Translations::set_language( $id, $language );
		} else {
			$map = (array) $request['translations'];
			unset( $map[ $language ] );
			$result = Translations::link( array( $language => $id ) + $map );
		}

		return is_wp_error( $result ) ? self::bad_request( $result ) : $this->response( $id );
	}

	/**
	 * Creates a draft translation and returns the updated group.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_copy( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = Translations::create_translation( $id, (string) $request['language'] );

		if ( is_wp_error( $result ) ) {
			return self::bad_request( $result );
		}

		$response = $this->response( $id );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Up to 20 posts of a type and language that the user can edit, matching a search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_candidates( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query(
			array(
				'post_type'      => (string) $request['post_type'],
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				's'              => (string) $request['search'],
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				'perm'           => 'editable',
				'tax_query'      => array( Translations::query_clause( (string) $request['language'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin search limited to 20 posts.
			)
		);

		$items = array();
		foreach ( $query->posts ?? array() as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$items[] = self::summary( $post );
			}
		}

		return rest_ensure_response( $items );
	}

	/**
	 * The user must be able to edit the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_edit_post( \WP_REST_Request $request ) {
		return self::can_edit_all( array( (int) $request['id'] ) );
	}

	/**
	 * The user must be able to edit the post, the posts linked to it and the posts of the groups they leave.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_update( \WP_REST_Request $request ) {
		$ids = array( (int) $request['id'] );
		if ( null !== $request['translations'] ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $request['translations'] ) );
		}

		return self::can_edit_all( self::with_groups( $ids ) );
	}

	/**
	 * The user must be able to edit the post's group and create posts of its type.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_copy( \WP_REST_Request $request ) {
		$type = get_post_type_object( (string) get_post_type( (int) $request['id'] ) );

		if ( null === $type || ! current_user_can( $type->cap->create_posts ) ) {
			return self::forbidden();
		}

		return self::can_edit_all( self::with_groups( array( (int) $request['id'] ) ) );
	}

	/**
	 * The user must be able to edit posts of the type searched.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function can_list_candidates( \WP_REST_Request $request ) {
		$type = get_post_type_object( (string) $request['post_type'] );

		return null !== $type && current_user_can( $type->cap->edit_posts ) ? true : self::forbidden();
	}

	/**
	 * Language of a post and its translations, as the editor panel reads them.
	 *
	 * A translation the user cannot read only marks its language as taken: no ID, title or status.
	 *
	 * @param int $id Post ID.
	 * @return \WP_REST_Response
	 */
	private function response( int $id ): \WP_REST_Response {
		$translations = array();
		foreach ( Translations::of( $id ) as $language => $post_id ) {
			$post = get_post( $post_id );
			if ( $post_id === $id || ! $post instanceof \WP_Post ) {
				continue;
			}

			$translations[ $language ] = current_user_can( 'read_post', $post_id ) ? self::summary( $post ) : array(
				'id'        => 0,
				'title'     => '',
				'status'    => '',
				'edit_link' => '',
			);
		}

		return rest_ensure_response(
			array(
				'language'     => Translations::language( $id ),
				'translations' => (object) $translations,
			)
		);
	}

	/**
	 * What the editor shows about a post the user can read.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{id: int, title: string, status: string, edit_link: string}
	 */
	private static function summary( \WP_Post $post ): array {
		return array(
			'id'        => $post->ID,
			'title'     => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'    => $post->post_status,
			'edit_link' => current_user_can( 'edit_post', $post->ID ) ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
		);
	}

	/**
	 * Posts plus every member of their current groups.
	 *
	 * @param int[] $ids Post IDs.
	 * @return list<int>
	 */
	private static function with_groups( array $ids ): array {
		$all = $ids;
		foreach ( $ids as $id ) {
			if ( get_post( $id ) instanceof \WP_Post ) {
				$all = array_merge( $all, array_values( Translations::of( $id ) ) );
			}
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * Allows the request only if the user can edit every post that exists among the IDs.
	 *
	 * Missing posts are reported by the callback with a clearer error.
	 *
	 * @param int[] $ids Post IDs.
	 * @return true|\WP_Error
	 */
	private static function can_edit_all( array $ids ) {
		foreach ( $ids as $id ) {
			if ( get_post( $id ) instanceof \WP_Post && ! current_user_can( 'edit_post', $id ) ) {
				return self::forbidden();
			}
		}

		return is_user_logged_in() ? true : self::forbidden();
	}

	/**
	 * Required argument with a configured language slug.
	 *
	 * @return array<string, mixed>
	 */
	private static function language_arg(): array {
		return array(
			'description' => __( 'Language slug.', 'easyrankly' ),
			'type'        => 'string',
			'enum'        => array_keys( Languages::all() ),
			'required'    => true,
		);
	}

	/**
	 * A data error as a 400 response.
	 *
	 * @param \WP_Error $error Error from Translations.
	 * @return \WP_Error
	 */
	private static function bad_request( \WP_Error $error ): \WP_Error {
		$error->add_data( array( 'status' => 400 ) );

		return $error;
	}

	/**
	 * Error for users who may not change these translations.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to change the translations of this content.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
