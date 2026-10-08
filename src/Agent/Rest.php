<?php
/**
 * REST routes of the agent dashboard.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Lists proposals and records decisions: accept (optionally with edited values), reject, undo.
 *
 * There is no route that creates a proposal: only the plugin's own code does, after validation.
 * Every route needs manage_options; accepting and undoing also run the ability's own
 * permission check as the current user.
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'easyrankly/v1';

	/**
	 * Most proposals per page.
	 */
	public const MAX_PER_PAGE = 100;

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
		$id         = array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
		$permission = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::NAMESPACE,
			'/proposals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => $permission,
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array_merge( array( 'all' ), array_keys( Proposals::STATUSES ) ),
						'default' => 'pending',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::MAX_PER_PAGE,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\d]+)/accept',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'      => $id,
					'changes' => array(
						'type'                 => 'object',
						'default'              => array(),
						'additionalProperties' => array( 'type' => array( 'string', 'integer', 'boolean' ) ),
						'description'          => __( 'New values for fields of the proposal ("Edit and accept").', 'easyrankly' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\d]+)/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'     => $id,
					'reason' => array(
						'type'              => 'string',
						'maxLength'         => 1000,
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/proposals/(?P<id>[\d]+)/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => $permission,
				'args'                => array( 'id' => $id ),
			)
		);
	}

	/**
	 * Lists proposals, newest first.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_items( \WP_REST_Request $request ): \WP_REST_Response {
		$status = (string) $request['status'];
		$query  = new \WP_Query(
			array(
				'post_type'              => Proposals::POST_TYPE,
				'post_status'            => 'all' === $status ? array_values( Proposals::STATUSES ) : Proposals::STATUSES[ $status ],
				'posts_per_page'         => (int) $request['per_page'],
				'paged'                  => (int) $request['page'],
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = self::prepare( $post );
			}
		}

		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * One proposal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		return self::respond( (int) $request['id'], true );
	}

	/**
	 * Accepts a proposal, optionally with edited values.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept( \WP_REST_Request $request ) {
		$changes = is_array( $request['changes'] ) ? $request['changes'] : array();

		return self::respond( (int) $request['id'], Proposals::accept( (int) $request['id'], $changes ) );
	}

	/**
	 * Rejects a proposal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reject( \WP_REST_Request $request ) {
		return self::respond( (int) $request['id'], Proposals::reject( (int) $request['id'], (string) $request['reason'] ) );
	}

	/**
	 * Undoes an accepted proposal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function undo( \WP_REST_Request $request ) {
		return self::respond( (int) $request['id'], Proposals::undo( (int) $request['id'] ) );
	}

	/**
	 * The proposal after an operation, or the operation's error.
	 *
	 * @param int            $id     Proposal ID.
	 * @param true|\WP_Error $result Result of the operation.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function respond( int $id, $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || Proposals::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'easyrankly_proposal_not_found', __( 'Proposal not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response( self::prepare( $post ) );
	}

	/**
	 * A proposal as the dashboard shows it, with the preview of the differences.
	 *
	 * @param \WP_Post $post Proposal post.
	 * @return array<string, mixed>
	 */
	public static function prepare( \WP_Post $post ): array {
		$input    = get_post_meta( $post->ID, Proposals::meta_key( 'input' ), true );
		$input    = is_array( $input ) ? $input : array();
		$previous = get_post_meta( $post->ID, Proposals::meta_key( 'previous' ), true );
		$previous = is_array( $previous ) ? $previous : array();
		$ability  = (string) get_post_meta( $post->ID, Proposals::meta_key( 'ability' ), true );
		$labels   = Actions::labels();
		$status   = (string) array_search( $post->post_status, Proposals::STATUSES, true );
		$user     = get_userdata( (int) get_post_meta( $post->ID, Proposals::meta_key( 'user' ), true ) );
		$object   = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;

		$target = null;
		if ( $object instanceof \WP_Post ) {
			$target = array(
				'id'       => $object->ID,
				'title'    => wp_strip_all_tags( get_the_title( $object ) ),
				'type'     => $object->post_type,
				'status'   => $object->post_status,
				'url'      => (string) get_permalink( $object ),
				'edit_url' => (string) get_edit_post_link( $object->ID, 'raw' ),
			);
		}

		$diff = array();
		foreach ( $input as $field => $value ) {
			if ( 'id' === $field ) {
				continue;
			}
			$diff[] = array(
				'field'  => (string) $field,
				'label'  => $labels[ $field ] ?? (string) $field,
				'before' => $previous[ $field ] ?? '',
				'after'  => $value,
			);
		}

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'motivation' => $post->post_content,
			'evidence'   => (string) get_post_meta( $post->ID, Proposals::meta_key( 'evidence' ), true ),
			'confidence' => (float) get_post_meta( $post->ID, Proposals::meta_key( 'confidence' ), true ),
			'status'     => $status,
			'ability'    => $ability,
			'input'      => $input,
			'diff'       => $diff,
			'note'       => (string) get_post_meta( $post->ID, Proposals::meta_key( 'note' ), true ),
			'decided_by' => $user instanceof \WP_User ? $user->display_name : '',
			'date'       => (string) get_post_time( 'c', true, $post ),
			'modified'   => (string) get_post_modified_time( 'c', true, $post ),
			'object'     => $target,
		);
	}
}
