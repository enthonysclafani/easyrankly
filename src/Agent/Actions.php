<?php
/**
 * Abilities that change the site, run only when a person accepts a proposal.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;

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
	 * Ability that sets the alternative text of an image.
	 */
	public const IMAGE_ALT = Agent::CATEGORY . '/set-image-alt';

	/**
	 * Ability that creates or changes an internal redirect.
	 */
	public const REDIRECT = Agent::CATEGORY . '/create-redirect';

	/**
	 * Longest alternative text.
	 */
	public const ALT_LENGTH = 250;

	/**
	 * Annotations of every write ability: it overwrites values, the same input twice changes nothing more.
	 * Not destructive: the proposal keeps the values it replaced, and "Undo" puts them back.
	 * Allowlist::validate() refuses any action annotated as destructive, these included.
	 */
	private const WRITE = array(
		'readonly'    => false,
		'destructive' => false,
		'idempotent'  => true,
	);

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
					'annotations'  => self::WRITE,
					'show_in_rest' => false,
				),
			)
		);

		wp_register_ability(
			self::IMAGE_ALT,
			array(
				'label'               => __( 'Set the alternative text of an image', 'easyrankly' ),
				'description'         => __( 'Sets the alternative text of an image in the media library. An empty value removes it.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attachment ID of the image.', 'easyrankly' ),
						),
						'alt' => array(
							'type'        => 'string',
							'maxLength'   => self::ALT_LENGTH,
							'description' => self::labels()['alt'],
						),
					),
					'required'             => array( 'id', 'alt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( self::class, 'set_image_alt' ),
				'permission_callback' => array( Abilities::class, 'can_edit_post' ),
				'meta'                => array(
					'annotations'  => self::WRITE,
					'show_in_rest' => false,
				),
			)
		);

		wp_register_ability(
			self::REDIRECT,
			array(
				'label'               => __( 'Create an internal redirect', 'easyrankly' ),
				'description'         => __( 'Redirects an address of this site to another address of this site. An empty target turns the redirect off.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => Rule::MAX_LENGTH,
							'description' => self::labels()['source'],
						),
						'target' => array(
							'type'        => 'string',
							'maxLength'   => Rule::MAX_LENGTH,
							'description' => self::labels()['target'],
						),
						'code'   => array(
							'type'        => 'integer',
							'enum'        => array( 301, 302, 307 ),
							'default'     => 301,
							'description' => self::labels()['code'],
						),
					),
					'required'             => array( 'source', 'target' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( self::class, 'create_redirect' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'meta'                => array(
					'annotations'  => self::WRITE,
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
			'alt'            => __( 'Alternative text', 'easyrankly' ),
			'source'         => __( 'From', 'easyrankly' ),
			'target'         => __( 'To', 'easyrankly' ),
			'code'           => __( 'Redirect type', 'easyrankly' ),
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
	 * Sets the alternative text of an image. An empty value removes it.
	 *
	 * @param mixed $input Validated input.
	 * @return array{id: int}|\WP_Error
	 */
	public static function set_image_alt( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (int) ( $input['id'] ?? 0 );

		if ( ! self::is_image( $id ) ) {
			return new \WP_Error( 'easyrankly_not_found', __( 'No image with this ID.', 'easyrankly' ) );
		}

		$alt = sanitize_text_field( is_string( $input['alt'] ?? null ) ? $input['alt'] : '' );
		if ( '' === $alt ) {
			delete_post_meta( $id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return array( 'id' => $id );
	}

	/**
	 * Creates or changes the exact redirect of a source. An empty target turns it off (draft),
	 * which is how "Undo" removes a redirect a proposal created.
	 *
	 * @param mixed $input Validated input.
	 * @return array{id: int}|\WP_Error
	 */
	public static function create_redirect( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$source   = Rule::normalize( (string) ( $input['source'] ?? '' ) );
		$target   = (string) ( $input['target'] ?? '' );
		$code     = (int) ( $input['code'] ?? 301 );
		$existing = (int) Redirects::find_id( Rule::hash( $source ), array( 'publish', 'draft' ) );

		if ( '' === $target ) {
			if ( $existing > 0 ) {
				wp_update_post(
					array(
						'ID'          => $existing,
						'post_status' => 'draft',
					)
				);
			}
			return array( 'id' => $existing );
		}

		$valid = Redirects::check( $source, $target, $code, false, $existing );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$id = Redirects::save_exact( $valid['source'], $valid['target'], $code );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array( 'id' => $id );
	}

	/**
	 * Current values of the fields an input would change, in the same shape as the input.
	 *
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function snapshot( string $ability, array $input ) {
		$id = (int) ( $input['id'] ?? 0 );

		switch ( $ability ) {
			case self::POST_SEO:
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

			case self::IMAGE_ALT:
				if ( ! self::is_image( $id ) ) {
					return new \WP_Error( 'easyrankly_not_found', __( 'No image with this ID.', 'easyrankly' ) );
				}
				return array(
					'id'  => $id,
					'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);

			case self::REDIRECT:
				$source = Rule::normalize( (string) ( $input['source'] ?? '' ) );
				$active = Redirects::find_id( Rule::hash( $source ), array( 'publish' ) );
				$rule   = null !== $active ? Redirects::rule( $active ) : null;
				return array(
					'source' => $source,
					'target' => null !== $rule ? $rule['target'] : '',
					'code'   => null !== $rule && in_array( $rule['code'], array( 301, 302, 307 ), true ) ? $rule['code'] : 301,
				);
		}

		return new \WP_Error( 'easyrankly_unknown_action', __( 'This action is not supported.', 'easyrankly' ) );
	}

	/**
	 * ID of the object an input changes, stored as the proposal's post_parent. Redirects
	 * change no post: their proposals name the content they replace instead.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return int
	 */
	public static function object_id( array $input ): int {
		return (int) ( $input['id'] ?? 0 );
	}

	/**
	 * Whether an attachment is an image.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function is_image( int $id ): bool {
		return $id > 0 && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id );
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
