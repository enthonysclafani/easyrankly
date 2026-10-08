<?php
/**
 * Proposals: the only way the agent changes the site.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Stores proposals as the non-public post type `erankly_proposal` and applies them when a person accepts.
 *
 * One post per proposal:
 * - post_title: short summary; post_content: motivation (plain text);
 * - post_parent: the content the proposal changes;
 * - post_status: one of the statuses below (pending, accepted, rejected, superseded, failed, reverted);
 * - meta: ability and input, previous values (same shape as the input, used by "Undo"),
 *   fingerprint of the original values, evidence, confidence, note, deciding user.
 *
 * Accepting runs the ability through the Abilities API as the current user, so the input is
 * validated again on its schema and the permission check is the one of whoever accepts.
 */
final class Proposals {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'erankly_proposal';

	/**
	 * Status keys used by the REST API and the dashboard, mapped to the stored post status.
	 */
	public const STATUSES = array(
		'pending'    => 'erankly_pending',
		'accepted'   => 'erankly_accepted',
		'rejected'   => 'erankly_rejected',
		'superseded' => 'erankly_superseded',
		'failed'     => 'erankly_failed',
		'reverted'   => 'erankly_reverted',
	);

	/**
	 * Meta keys, without prefix, with their schema type.
	 */
	public const META = array(
		'ability'     => 'string',
		'input'       => 'object',
		'previous'    => 'object',
		'fingerprint' => 'string',
		'evidence'    => 'string',
		'confidence'  => 'number',
		'note'        => 'string',
		'user'        => 'integer',
	);

	/**
	 * Hooks the registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Registers the post type, its statuses and its meta. Only administrators can reach proposals.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'           => array(
					'name'          => __( 'Proposals', 'easyrankly' ),
					'singular_name' => __( 'Proposal', 'easyrankly' ),
				),
				'public'           => false,
				'show_ui'          => false,
				'show_in_rest'     => false,
				'supports'         => array( 'title', 'editor', 'custom-fields' ),
				'rewrite'          => false,
				'query_var'        => false,
				'can_export'       => true,
				'delete_with_user' => false,
				'map_meta_cap'     => false,
				'capabilities'     => array_fill_keys(
					array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ),
					'manage_options'
				),
			)
		);

		foreach ( self::status_labels() as $key => $label ) {
			register_post_status(
				self::STATUSES[ $key ],
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				)
			);
		}

		foreach ( self::META as $name => $type ) {
			register_post_meta(
				self::POST_TYPE,
				self::meta_key( $name ),
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				)
			);
		}
	}

	/**
	 * Translated labels of the statuses, by key.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'pending'    => _x( 'Pending', 'proposal status', 'easyrankly' ),
			'accepted'   => _x( 'Accepted', 'proposal status', 'easyrankly' ),
			'rejected'   => _x( 'Rejected', 'proposal status', 'easyrankly' ),
			'superseded' => _x( 'Superseded', 'proposal status', 'easyrankly' ),
			'failed'     => _x( 'Failed', 'proposal status', 'easyrankly' ),
			'reverted'   => _x( 'Undone', 'proposal status', 'easyrankly' ),
		);
	}

	/**
	 * Full meta key of a proposal field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function meta_key( string $name ): string {
		return '_easyrankly_proposal_' . $name;
	}

	/**
	 * Creates a pending proposal after validating its input on the ability schema.
	 *
	 * @param array{ability: string, input: array<string, mixed>, title: string, motivation?: string, evidence?: string, confidence?: float|int} $args Proposal.
	 * @return int|\WP_Error Proposal ID.
	 */
	public static function create( array $args ) {
		$name  = $args['ability'];
		$input = self::validate( $name, $args['input'] );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$previous = Actions::snapshot( $name, $input );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$title = sanitize_text_field( $args['title'] );
		if ( '' === $title ) {
			return new \WP_Error( 'easyrankly_proposal_title', __( 'A proposal needs a summary.', 'easyrankly' ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => self::STATUSES['pending'],
				'post_title'   => $title,
				'post_content' => sanitize_textarea_field( $args['motivation'] ?? '' ),
				'post_parent'  => Actions::object_id( $input ),
				'post_author'  => get_current_user_id(),
				'meta_input'   => array(
					self::meta_key( 'ability' )     => $name,
					self::meta_key( 'input' )       => $input,
					self::meta_key( 'previous' )    => $previous,
					self::meta_key( 'fingerprint' ) => self::fingerprint( $previous ),
					self::meta_key( 'evidence' )    => sanitize_textarea_field( $args['evidence'] ?? '' ),
					self::meta_key( 'confidence' )  => max( 0.0, min( 1.0, (float) ( $args['confidence'] ?? 0 ) ) ),
				),
			),
			true
		);

		return $id;
	}

	/**
	 * Applies a pending proposal as the current user, optionally with edited values.
	 *
	 * @param int                  $id      Proposal ID.
	 * @param array<string, mixed> $changes New values for fields already in the proposal ("Edit and accept").
	 * @return true|\WP_Error
	 */
	public static function accept( int $id, array $changes = array() ) {
		$proposal = self::get( $id, 'pending' );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$input = $proposal['input'];
		foreach ( $changes as $field => $value ) {
			if ( 'id' === $field || ! array_key_exists( $field, $input ) ) {
				return new \WP_Error( 'easyrankly_proposal_field', __( 'Only the values of the proposal can be edited.', 'easyrankly' ), array( 'status' => 400 ) );
			}
			$input[ $field ] = $value;
		}

		$input = self::validate( $proposal['ability'], $input );
		if ( is_wp_error( $input ) ) {
			$input->add_data( array( 'status' => 400 ) );
			return $input;
		}

		$result = self::run( $proposal['ability'], $input );
		if ( is_wp_error( $result ) ) {
			// Permission and input problems leave the proposal pending for someone else, or for a fix.
			$data = $result->get_error_data();
			if ( 500 === ( is_array( $data ) ? ( $data['status'] ?? 500 ) : 500 ) ) {
				self::decide( $id, 'failed', $result->get_error_message() );
			}
			return $result;
		}

		update_post_meta( $id, self::meta_key( 'input' ), $input );
		self::decide( $id, 'accepted' );

		return true;
	}

	/**
	 * Rejects a pending proposal, with an optional reason.
	 *
	 * @param int    $id     Proposal ID.
	 * @param string $reason Why the proposal was rejected.
	 * @return true|\WP_Error
	 */
	public static function reject( int $id, string $reason = '' ) {
		$proposal = self::get( $id, 'pending' );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		self::decide( $id, 'rejected', sanitize_textarea_field( $reason ) );

		return true;
	}

	/**
	 * Restores the values an accepted proposal replaced.
	 *
	 * @param int $id Proposal ID.
	 * @return true|\WP_Error
	 */
	public static function undo( int $id ) {
		$proposal = self::get( $id, 'accepted' );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$result = self::run( $proposal['ability'], $proposal['previous'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::decide( $id, 'reverted' );

		return true;
	}

	/**
	 * A proposal as plain data, optionally only in a given status.
	 *
	 * @param int    $id     Proposal ID.
	 * @param string $status Status key the proposal must have, or empty for any.
	 * @return array{id: int, ability: string, input: array<string, mixed>, previous: array<string, mixed>, status: string, object: int}|\WP_Error
	 */
	public static function get( int $id, string $status = '' ) {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'easyrankly_proposal_not_found', __( 'Proposal not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		$key = (string) array_search( $post->post_status, self::STATUSES, true );
		if ( '' !== $status && $status !== $key ) {
			return new \WP_Error( 'easyrankly_proposal_status', __( 'This proposal can no longer be changed this way.', 'easyrankly' ), array( 'status' => 409 ) );
		}

		$input    = get_post_meta( $id, self::meta_key( 'input' ), true );
		$previous = get_post_meta( $id, self::meta_key( 'previous' ), true );

		return array(
			'id'       => $id,
			'ability'  => (string) get_post_meta( $id, self::meta_key( 'ability' ), true ),
			'input'    => is_array( $input ) ? $input : array(),
			'previous' => is_array( $previous ) ? $previous : array(),
			'status'   => $key,
			'object'   => (int) $post->post_parent,
		);
	}

	/**
	 * Validates an input on the schema of an agent action.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Raw input.
	 * @return array<string, mixed>|\WP_Error Normalized input.
	 */
	private static function validate( string $name, $input ) {
		$ability = Actions::POST_SEO === $name ? wp_get_ability( $name ) : null;
		if ( null === $ability ) {
			return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ) );
		}

		$input = $ability->normalize_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$valid = $ability->validate_input( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Runs an action as the current user. A failure other than a permission or input problem
	 * marks nothing here: the caller decides the status.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Input.
	 * @return mixed|\WP_Error
	 */
	private static function run( string $name, array $input ) {
		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			$status = match ( $result->get_error_code() ) {
				'ability_invalid_permissions' => 403,
				'ability_invalid_input' => 400,
				default => 500,
			};
			$result->add_data( array( 'status' => $status ) );
		}

		return $result;
	}

	/**
	 * Stores a decision: new status, who took it and an optional note.
	 *
	 * @param int    $id     Proposal ID.
	 * @param string $status Status key.
	 * @param string $note   Reason or error.
	 */
	private static function decide( int $id, string $status, string $note = '' ): void {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => self::STATUSES[ $status ],
			)
		);
		update_post_meta( $id, self::meta_key( 'user' ), get_current_user_id() );

		if ( '' !== $note ) {
			update_post_meta( $id, self::meta_key( 'note' ), $note );
		}
	}

	/**
	 * Fingerprint of the values a proposal would change.
	 *
	 * @param array<string, mixed> $values Snapshot.
	 * @return string
	 */
	public static function fingerprint( array $values ): string {
		return md5( (string) wp_json_encode( $values ) );
	}
}
