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
 * Every input passes Allowlist::validate() when the proposal is created and again when it is
 * accepted. Accepting runs the ability through the Abilities API as the current user, so the
 * permission check is the one of whoever accepts; if the content changed since the proposal
 * was made (fingerprint of the values it replaces), the proposal is superseded instead.
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
	 * Creates a pending proposal after the allowlist checks, superseding older ones on the same content.
	 *
	 * @param array{ability: string, input: array<string, mixed>, title: string, motivation?: string, evidence?: string, confidence?: float|int} $args Proposal.
	 * @return int|\WP_Error Proposal ID.
	 */
	public static function create( array $args ) {
		$name  = $args['ability'];
		$input = Allowlist::validate( $name, $args['input'] );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		if ( Allowlist::daily_limit_reached() ) {
			return new \WP_Error( 'easyrankly_daily_limit', __( 'The agent reached the daily limit of proposals.', 'easyrankly' ), array( 'status' => 429 ) );
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

		if ( ! is_wp_error( $id ) ) {
			self::supersede_older( $id, $name, Actions::object_id( $input ) );
		}

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

		$input = Allowlist::validate( $proposal['ability'], $input );
		if ( is_wp_error( $input ) ) {
			$input->add_data( array( 'status' => 400 ) );
			return $input;
		}

		// The content changed since the proposal was made: applying it would overwrite newer work.
		$current = Actions::snapshot( $proposal['ability'], $input );
		if ( is_wp_error( $current ) || self::fingerprint( $current ) !== $proposal['fingerprint'] ) {
			self::decide( $id, 'superseded', __( 'The content changed after this proposal was made.', 'easyrankly' ) );
			return new \WP_Error( 'easyrankly_proposal_superseded', __( 'The content changed after this proposal was made, so it was not applied.', 'easyrankly' ), array( 'status' => 409 ) );
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

		// From now on the fingerprint is the one of the applied values: "Undo" checks it.
		$applied = Actions::snapshot( $proposal['ability'], $input );
		update_post_meta( $id, self::meta_key( 'input' ), $input );
		update_post_meta( $id, self::meta_key( 'fingerprint' ), is_wp_error( $applied ) ? '' : self::fingerprint( $applied ) );
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

		$current = Actions::snapshot( $proposal['ability'], $proposal['input'] );
		if ( is_wp_error( $current ) || self::fingerprint( $current ) !== $proposal['fingerprint'] ) {
			return new \WP_Error( 'easyrankly_proposal_changed', __( 'The content changed after this proposal was applied: undoing it would overwrite newer changes.', 'easyrankly' ), array( 'status' => 409 ) );
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
	 * @return array{id: int, ability: string, input: array<string, mixed>, previous: array<string, mixed>, fingerprint: string, status: string, object: int}|\WP_Error
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
			'id'          => $id,
			'ability'     => (string) get_post_meta( $id, self::meta_key( 'ability' ), true ),
			'input'       => is_array( $input ) ? $input : array(),
			'previous'    => is_array( $previous ) ? $previous : array(),
			'fingerprint' => (string) get_post_meta( $id, self::meta_key( 'fingerprint' ), true ),
			'status'      => $key,
			'object'      => (int) $post->post_parent,
		);
	}

	/**
	 * Marks as superseded the older pending proposals of the same action on the same content.
	 *
	 * @param int    $id      The new proposal.
	 * @param string $ability Ability name.
	 * @param int    $content Content ID.
	 */
	private static function supersede_older( int $id, string $ability, int $content ): void {
		$older = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => self::STATUSES['pending'],
				'post_parent'            => $content,
				'post__not_in'           => array( $id ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs only when a proposal is created, never on the frontend.
					array(
						'key'   => self::meta_key( 'ability' ),
						'value' => $ability,
					),
				),
			)
		);

		foreach ( $older as $older_id ) {
			self::decide( (int) $older_id, 'superseded', __( 'A newer proposal replaced this one.', 'easyrankly' ) );
		}
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
