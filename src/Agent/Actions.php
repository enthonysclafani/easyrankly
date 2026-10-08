<?php
/**
 * Abilities that change the site, run only when a person accepts a proposal.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

use EasyRankly\Meta\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Write abilities applied by accepted proposals, and the snapshot of what they would change.
 *
 * They are not shown in REST and never handed to the AI: the agent only creates proposals,
 * and Proposals::accept() runs the ability with the permissions of the person who accepts.
 * Every input is also a valid "undo" input: the snapshot of the fields before the change
 * has the same shape, so running the ability with it restores the previous values.
 */
final class Actions {

	/**
	 * Ability that changes the SEO and social texts of a post.
	 */
	public const POST_SEO = Agent::CATEGORY . '/update-post-seo';

	/**
	 * Text fields of update-post-seo, with their longest accepted value.
	 */
	public const POST_SEO_FIELDS = array(
		'title'          => 200,
		'description'    => 400,
		'og_title'       => 200,
		'og_description' => 400,
	);

	/**
	 * Registers the write abilities. Runs on `wp_abilities_api_init`.
	 */
	public static function register_abilities(): void {
		$properties = array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post ID.', 'easyrankly' ),
			),
		);
		foreach ( self::POST_SEO_FIELDS as $field => $length ) {
			$properties[ $field ] = array(
				'type'        => 'string',
				'maxLength'   => $length,
				'description' => self::labels()[ $field ],
			);
		}

		wp_register_ability(
			self::POST_SEO,
			array(
				'label'               => __( 'Update the SEO texts of a content', 'easyrankly' ),
				'description'         => __( 'Sets the SEO title, meta description, social title or social description of a post. An empty value goes back to the site templates.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => array( 'id' ),
					'minProperties'        => 2,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'updated' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( self::class, 'update_post_seo' ),
				'permission_callback' => array( Abilities::class, 'can_edit_post' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	/**
	 * Labels of the fields an action changes, for the preview of the differences.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'title'          => __( 'SEO title', 'easyrankly' ),
			'description'    => __( 'Meta description', 'easyrankly' ),
			'og_title'       => __( 'Social title', 'easyrankly' ),
			'og_description' => __( 'Social description', 'easyrankly' ),
		);
	}

	/**
	 * Writes the SEO texts of a post. An empty value removes the override.
	 *
	 * @param mixed $input Validated input.
	 * @return array{id: int, updated: list<string>}|\WP_Error
	 */
	public static function update_post_seo( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (int) ( $input['id'] ?? 0 );

		if ( null === self::post( $id ) ) {
			return new \WP_Error( 'easyrankly_not_found', __( 'No content with this ID.', 'easyrankly' ) );
		}

		$updated = array();
		foreach ( array_keys( self::POST_SEO_FIELDS ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = Meta::sanitize_string( $input[ $field ] );
			if ( '' === $value ) {
				delete_post_meta( $id, Meta::PREFIX . $field );
			} else {
				update_post_meta( $id, Meta::PREFIX . $field, $value );
			}
			$updated[] = $field;
		}

		return array(
			'id'      => $id,
			'updated' => $updated,
		);
	}

	/**
	 * Current values of the fields an input would change, in the same shape as the input.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function snapshot( string $ability, array $input ) {
		if ( self::POST_SEO !== $ability ) {
			return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ) );
		}

		$id = (int) ( $input['id'] ?? 0 );
		if ( null === self::post( $id ) ) {
			return new \WP_Error( 'easyrankly_not_found', __( 'No content with this ID.', 'easyrankly' ) );
		}

		$snapshot = array( 'id' => $id );
		foreach ( array_keys( self::POST_SEO_FIELDS ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$snapshot[ $field ] = (string) Meta::post( $id, $field );
			}
		}

		return $snapshot;
	}

	/**
	 * ID of the object an input changes, stored as the proposal's post_parent.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return int
	 */
	public static function object_id( array $input ): int {
		return (int) ( $input['id'] ?? 0 );
	}

	/**
	 * A post the agent may work on: any viewable type except attachments.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|null
	 */
	private static function post( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post && 'attachment' !== $post->post_type && is_post_type_viewable( $post->post_type ) ? $post : null;
	}
}
