<?php
/**
 * SEO data stored on each post and term.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the SEO meta keys for every viewable post type and taxonomy, and reads them.
 *
 * The same fields exist on posts and terms. Empty means "use the default":
 * templates, settings and core behavior decide, never a value copied here.
 */
final class Meta {

	/**
	 * Prefix of every meta key. The leading underscore keeps them out of the Custom Fields box.
	 */
	public const PREFIX = '_easyrankly_';

	/**
	 * Registers the meta keys after post types and taxonomies exist.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 99 );
	}

	/**
	 * Fields stored per object: name (without prefix) and schema.
	 *
	 * @return array<string, array{type: string, description: string, default: mixed}>
	 */
	public static function fields(): array {
		return array(
			'title'          => array(
				'type'        => 'string',
				'description' => __( 'SEO title. Overrides the title template.', 'easyrankly' ),
				'default'     => '',
			),
			'description'    => array(
				'type'        => 'string',
				'description' => __( 'Meta description. Overrides the description template.', 'easyrankly' ),
				'default'     => '',
			),
			'canonical'      => array(
				'type'        => 'string',
				'description' => __( 'Canonical URL. Empty uses the URL WordPress builds.', 'easyrankly' ),
				'default'     => '',
			),
			'noindex'        => array(
				'type'        => 'boolean',
				'description' => __( 'Ask search engines not to index this content.', 'easyrankly' ),
				'default'     => false,
			),
			'nofollow'       => array(
				'type'        => 'boolean',
				'description' => __( 'Ask search engines not to follow links in this content.', 'easyrankly' ),
				'default'     => false,
			),
			'og_title'       => array(
				'type'        => 'string',
				'description' => __( 'Title for social sharing. Empty uses the SEO title.', 'easyrankly' ),
				'default'     => '',
			),
			'og_description' => array(
				'type'        => 'string',
				'description' => __( 'Description for social sharing. Empty uses the meta description.', 'easyrankly' ),
				'default'     => '',
			),
			'og_image'       => array(
				'type'        => 'integer',
				'description' => __( 'Attachment ID of the image for social sharing.', 'easyrankly' ),
				'default'     => 0,
			),
		);
	}

	/**
	 * Registers every field for the viewable post types and taxonomies: those whose content
	 * has a page of its own. Private types (redirects, snippets, navigation menus, templates)
	 * and those of other plugins do not get SEO fields in their REST responses.
	 *
	 * Types registered later (after init at priority 99) get the fields when they are registered.
	 */
	public function register_meta(): void {
		foreach ( get_post_types() as $post_type ) {
			$this->register_post_type_meta( $post_type );
		}
		foreach ( get_taxonomies() as $taxonomy ) {
			$this->register_taxonomy_meta( $taxonomy );
		}

		add_action( 'registered_post_type', array( $this, 'register_post_type_meta' ) );
		add_action( 'registered_taxonomy', array( $this, 'register_taxonomy_meta' ) );
	}

	/**
	 * Registers the fields of a viewable post type.
	 *
	 * Post meta reaches the REST API (and the block editor) only on post types that support
	 * custom fields, so that support is added where the SEO panel of the editor needs it.
	 *
	 * @param mixed $post_type Post type name.
	 */
	public function register_post_type_meta( $post_type ): void {
		$post_type = (string) $post_type;
		if ( ! is_post_type_viewable( $post_type ) ) {
			return;
		}

		foreach ( self::fields() as $name => $field ) {
			register_post_meta( $post_type, self::PREFIX . $name, self::args( $name, $field ) + array( 'auth_callback' => array( self::class, 'can_edit_post' ) ) );
		}

		$object = get_post_type_object( $post_type );
		if ( null !== $object && $object->show_in_rest && 'attachment' !== $post_type ) {
			add_post_type_support( $post_type, 'custom-fields' );
		}
	}

	/**
	 * Registers the fields of a viewable taxonomy.
	 *
	 * @param mixed $taxonomy Taxonomy name.
	 */
	public function register_taxonomy_meta( $taxonomy ): void {
		$taxonomy = (string) $taxonomy;
		if ( ! is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}

		foreach ( self::fields() as $name => $field ) {
			register_term_meta( $taxonomy, self::PREFIX . $name, self::args( $name, $field ) + array( 'auth_callback' => array( self::class, 'can_edit_term' ) ) );
		}
	}

	/**
	 * Registration arguments of a field.
	 *
	 * @param string                                                   $name  Field name without prefix.
	 * @param array{type: string, description: string, default: mixed} $field Field schema.
	 * @return array<string, mixed>
	 */
	private static function args( string $name, array $field ): array {
		return array(
			'type'              => $field['type'],
			'description'       => $field['description'],
			'default'           => $field['default'],
			'single'            => true,
			'sanitize_callback' => match ( true ) {
				'canonical' === $name => array( self::class, 'sanitize_url' ),
				'boolean' === $field['type'] => array( self::class, 'sanitize_boolean' ),
				'integer' === $field['type'] => array( self::class, 'sanitize_integer' ),
				default => array( self::class, 'sanitize_string' ),
			},
			'show_in_rest'      => true,
		);
	}

	/**
	 * Reads a field of a post.
	 *
	 * Post meta is primed with the queried object, so this costs no query on the frontend.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $name    Field name without prefix.
	 * @return mixed
	 */
	public static function post( int $post_id, string $name ): mixed {
		return get_post_meta( $post_id, self::PREFIX . $name, true );
	}

	/**
	 * Reads a field of the object the page is about: the post of a single page or of the
	 * posts page, or the term of a term archive.
	 *
	 * @param string $name Field name without prefix.
	 * @return mixed Null when the page has no such object (other archives, search, 404).
	 */
	public static function queried( string $name ): mixed {
		$object = get_queried_object();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() ) ) {
			return self::post( $object->ID, $name );
		}
		if ( $object instanceof \WP_Term ) {
			return self::term( $object->term_id, $name );
		}

		return null;
	}

	/**
	 * Full-size image of an attachment with its alternative text, as pages share it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{url: string, width: int, height: int, alt: string}|null Null when it is not an image.
	 */
	public static function image( int $attachment_id ): ?array {
		$source = $attachment_id > 0 ? wp_get_attachment_image_src( $attachment_id, 'full' ) : false;
		if ( false === $source ) {
			return null;
		}

		return array(
			'url'    => (string) $source[0],
			'width'  => (int) $source[1],
			'height' => (int) $source[2],
			'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ),
		);
	}

	/**
	 * Reads a field of a term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    Field name without prefix.
	 * @return mixed
	 */
	public static function term( int $term_id, string $name ): mixed {
		return get_term_meta( $term_id, self::PREFIX . $name, true );
	}

	/**
	 * Only users who can edit the post may change its SEO data.
	 *
	 * @param mixed $allowed   Whether the user can edit the meta key (unused, protected keys start false).
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $object_id Post ID.
	 * @return bool
	 */
	public static function can_edit_post( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Only users who can edit the term may change its SEO data.
	 *
	 * @param mixed $allowed   Whether the user can edit the meta key (unused).
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $object_id Term ID.
	 * @return bool
	 */
	public static function can_edit_term( $allowed, $meta_key, $object_id ): bool {
		return current_user_can( 'edit_term', (int) $object_id );
	}

	/**
	 * Single-line text without markup.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_string( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Absolute http(s) URL, or empty.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_url( $value ): string {
		return is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
	}

	/**
	 * Boolean flag.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_boolean( $value ): bool {
		return is_bool( $value ) ? $value : ( is_scalar( $value ) && rest_sanitize_boolean( (string) $value ) );
	}

	/**
	 * Non-negative integer (attachment ID).
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_integer( $value ): int {
		return absint( $value );
	}
}
