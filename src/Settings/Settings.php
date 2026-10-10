<?php
/**
 * Plugin settings stored in the single autoloaded option.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings;

use EasyRankly\Context\Context;
use EasyRankly\Multilingual\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `easyrankly_settings` and reads it with defaults filled in.
 *
 * The schema below is the only description of the settings: REST validation,
 * sanitization and defaults all derive from it.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'easyrankly_settings';

	/**
	 * Settings whose attachment is copied into `images`: see image().
	 */
	public const IMAGES = array( 'identity_logo', 'social_image' );

	/**
	 * Attachment meta that changes what a copied image shows.
	 */
	private const IMAGE_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' );

	/**
	 * Default of every setting, the same as in the schema.
	 *
	 * Kept apart from the schema so reading the settings never builds its translated
	 * descriptions: get() runs many times on every page, and inside the locale filter.
	 */
	public const DEFAULTS = array(
		'title_separator'       => '-',
		'social_image'          => 0,
		'x_username'            => '',
		'identity_type'         => 'organization',
		'identity_name'         => '',
		'identity_logo'         => 0,
		'same_as'               => array(),
		'breadcrumb_home_label' => '',
		'breadcrumb_taxonomies' => array(),
		'robots_txt'            => '',
		'noindex'               => array(),
		'languages'             => array(),
		'navigation_menus'      => array(),
		'templates'             => array(
			'home'    => array(
				'title'       => '{{site_name}} {{sep}} {{tagline}}',
				'description' => '{{tagline}}',
			),
			'single'  => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '{{excerpt}}',
			),
			'archive' => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '',
			),
			'term'    => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '{{term_description}}',
			),
			'author'  => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '',
			),
			'date'    => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '',
			),
			'search'  => array(
				'title'       => '{{title}} {{page}} {{sep}} {{site_name}}',
				'description' => '',
			),
			'404'     => array(
				'title'       => '{{title}} {{sep}} {{site_name}}',
				'description' => '',
			),
		),
		'language_templates'    => array(),
		'images'                => array(),
	);

	/**
	 * Registers the setting once post types and taxonomies exist, and keeps the copied
	 * images in step with their attachments.
	 */
	public function register(): void {
		// Late priority: later sections of the schema list post types and taxonomies.
		add_action( 'init', array( $this, 'register_setting' ), 99 );
		add_action( 'added_post_meta', array( $this, 'on_attachment_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_attachment_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_attachment_meta' ), 10, 3 );
		// wp_delete_attachment() fires deleted_post, never after_delete_post.
		add_action( 'deleted_post', array( $this, 'on_attachment_deleted' ) );
	}

	/**
	 * Registers the option with its schema, so `/wp/v2/settings` can read and write it.
	 */
	public function register_setting(): void {
		register_setting(
			'easyrankly',
			self::OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'EasyRankly settings', 'easyrankly' ),
				'description'       => __( 'Settings of the EasyRankly SEO plugin.', 'easyrankly' ),
				'default'           => self::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => array( 'schema' => self::schema() ),
			)
		);
	}

	/**
	 * JSON schema of the option. Every property has a default.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => self::properties(),
		);
	}

	/**
	 * Schema of each setting, keyed by property name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function properties(): array {
		return array(
			'title_separator'       => array(
				'description' => __( 'Separator used by the {{sep}} template variable.', 'easyrankly' ),
				'type'        => 'string',
				'format'      => 'text-field',
				'maxLength'   => 10,
				'default'     => self::DEFAULTS['title_separator'],
			),
			'social_image'          => array(
				'description' => __( 'Attachment ID of the image shared when a page has no image of its own.', 'easyrankly' ),
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => self::DEFAULTS['social_image'],
			),
			'x_username'            => array(
				'description' => __( 'X (Twitter) username of the site, without @.', 'easyrankly' ),
				'type'        => 'string',
				'pattern'     => '^[A-Za-z0-9_]{0,15}$',
				'default'     => self::DEFAULTS['x_username'],
			),
			'identity_type'         => array(
				'description' => __( 'Whether the site represents an organization or a person (schema.org).', 'easyrankly' ),
				'type'        => 'string',
				'enum'        => array( 'organization', 'person' ),
				'default'     => self::DEFAULTS['identity_type'],
			),
			'identity_name'         => array(
				'description' => __( 'Name of the organization or person. Empty uses the site title.', 'easyrankly' ),
				'type'        => 'string',
				'format'      => 'text-field',
				'maxLength'   => 200,
				'default'     => self::DEFAULTS['identity_name'],
			),
			'identity_logo'         => array(
				'description' => __( 'Attachment ID of the logo (organization) or photo (person).', 'easyrankly' ),
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => self::DEFAULTS['identity_logo'],
			),
			'same_as'               => array(
				'description' => __( 'Profiles of the organization or person on other sites (schema.org sameAs).', 'easyrankly' ),
				'type'        => 'array',
				'items'       => array(
					'type'    => 'string',
					'format'  => 'uri',
					'pattern' => '^https?://[^\\s]+$',
				),
				'maxItems'    => 20,
				'uniqueItems' => true,
				'default'     => self::DEFAULTS['same_as'],
			),
			'breadcrumb_home_label' => array(
				'description' => __( 'Label of the first item of the breadcrumb block. Empty keeps the WordPress label.', 'easyrankly' ),
				'type'        => 'string',
				'format'      => 'text-field',
				'maxLength'   => 60,
				'default'     => self::DEFAULTS['breadcrumb_home_label'],
			),
			'breadcrumb_taxonomies' => array(
				'description'          => __( 'Taxonomy used in the breadcrumb of each post type (post type => taxonomy).', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array(
					'^[a-z0-9_-]{1,20}$' => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,32}$',
					),
				),
				'additionalProperties' => false,
				'default'              => self::DEFAULTS['breadcrumb_taxonomies'],
			),
			'robots_txt'            => array(
				'description' => __( 'Extra rules appended to the robots.txt that WordPress generates.', 'easyrankly' ),
				'type'        => 'string',
				'format'      => 'textarea-field',
				'maxLength'   => 5000,
				'default'     => self::DEFAULTS['robots_txt'],
			),
			'noindex'               => array(
				'description' => __( 'Contexts whose pages ask search engines not to index them (same keys as the templates).', 'easyrankly' ),
				'type'        => 'array',
				'items'       => array(
					'type'    => 'string',
					'pattern' => Context::KEY_PATTERN,
				),
				'uniqueItems' => true,
				'default'     => self::DEFAULTS['noindex'],
			),
			'languages'             => array(
				'description'          => __( 'Languages of the content, keyed by URL prefix, in display order. The first one is the default: its URLs have no prefix. Multilingual features start with two languages.', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array(
					Languages::SLUG_PATTERN => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'locale', 'name' ),
						'properties'           => array(
							'locale'     => array(
								'description' => __( 'WordPress locale of the language, e.g. en_US.', 'easyrankly' ),
								'type'        => 'string',
								'pattern'     => Languages::LOCALE_PATTERN,
							),
							'name'       => array(
								'description' => __( 'Name of the language shown to visitors.', 'easyrankly' ),
								'type'        => 'string',
								'format'      => 'text-field',
								'minLength'   => 1,
								'maxLength'   => 50,
							),
							'site_title' => array(
								'description' => __( 'Site title on the pages of the language. Empty uses the site title in Settings > General.', 'easyrankly' ),
								'type'        => 'string',
								'format'      => 'text-field',
								'maxLength'   => 200,
							),
							'tagline'    => array(
								'description' => __( 'Tagline on the pages of the language. Empty uses the tagline in Settings > General.', 'easyrankly' ),
								'type'        => 'string',
								'format'      => 'text-field',
								'maxLength'   => 400,
							),
						),
					),
				),
				'additionalProperties' => false,
				'maxProperties'        => 20,
				'default'              => self::DEFAULTS['languages'],
			),
			'navigation_menus'      => array(
				'description'          => __( 'Navigation menus of block themes shown on the pages of one language (language slug => navigation menu ID => ID of the navigation menu shown in its place).', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array(
					Languages::SLUG_PATTERN => array(
						'type'                 => 'object',
						'patternProperties'    => array(
							'^[1-9][0-9]*$' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'additionalProperties' => false,
						'maxProperties'        => 50,
					),
				),
				'additionalProperties' => false,
				'maxProperties'        => 20,
				'default'              => self::DEFAULTS['navigation_menus'],
			),
			'templates'             => array(
				'description'          => __( 'Title and description templates per context. Keys: home, single, archive, term, author, date, search, 404, or single-{post type}, archive-{post type}, term-{taxonomy}.', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array( Context::KEY_PATTERN => self::template_schema() ),
				'additionalProperties' => false,
				'default'              => self::DEFAULTS['templates'],
			),
			'language_templates'    => array(
				'description'          => __( 'Title and description templates of one language (language slug => context => template). Empty fields use the templates of all languages.', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array(
					Languages::SLUG_PATTERN => array(
						'type'                 => 'object',
						'patternProperties'    => array( Context::KEY_PATTERN => self::template_schema() ),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
				'maxProperties'        => 20,
				'default'              => self::DEFAULTS['language_templates'],
			),
			'images'                => array(
				'description'          => __( 'Copy of the logo and of the default social image (address, size, alternative text), refreshed when they or their attachments change, so pages show them without loading the attachments. Read only.', 'easyrankly' ),
				'type'                 => 'object',
				'readonly'             => true,
				'properties'           => array_fill_keys( self::IMAGES, self::image_schema() ),
				'additionalProperties' => false,
				'default'              => self::DEFAULTS['images'],
			),
		);
	}

	/**
	 * Schema of the title and description templates of one context.
	 *
	 * @return array<string, mixed>
	 */
	private static function template_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title'       => array(
					'type'      => 'string',
					'format'    => 'text-field',
					'maxLength' => 200,
				),
				'description' => array(
					'type'      => 'string',
					'format'    => 'text-field',
					'maxLength' => 400,
				),
			),
		);
	}

	/**
	 * Schema of one copied image.
	 *
	 * @return array<string, mixed>
	 */
	private static function image_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'url'    => array( 'type' => 'string' ),
				'width'  => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'height' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'alt'    => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Current settings with defaults for anything missing.
	 *
	 * The option is autoloaded, so this costs no query.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$settings = array_replace( $defaults, is_array( $stored ) ? array_intersect_key( $stored, $defaults ) : array() );

		// Object settings merge one level deep, so a stored map only overrides the keys it has.
		foreach ( $defaults as $key => $default ) {
			if ( is_array( $default ) && is_array( $settings[ $key ] ) ) {
				$settings[ $key ] = array_replace( $default, $settings[ $key ] );
			}
		}

		return $settings;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key Property name from the schema.
	 * @return mixed
	 */
	public static function value( string $key ): mixed {
		return self::get()[ $key ] ?? null;
	}

	/**
	 * Whether a value is valid for one setting, with the same check as REST and sanitize().
	 *
	 * @param string $key   Property name from the schema.
	 * @param mixed  $value Value to check.
	 * @return bool
	 */
	public static function is_valid( string $key, mixed $value ): bool {
		$properties = self::properties();

		return isset( $properties[ $key ] ) && true === rest_validate_value_from_schema( $value, $properties[ $key ], $key );
	}

	/**
	 * Keeps only valid properties; anything invalid falls back to its current value or default.
	 *
	 * @param mixed $value Raw value, from REST, options.php or update_option().
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = self::get();

		foreach ( self::properties() as $key => $property ) {
			if ( ! empty( $property['readonly'] ) || ! array_key_exists( $key, $value ) || true !== rest_validate_value_from_schema( $value[ $key ], $property, $key ) ) {
				continue;
			}

			$sanitized = rest_sanitize_value_from_schema( $value[ $key ], $property, $key );
			if ( ! is_wp_error( $sanitized ) ) {
				$clean[ $key ] = $sanitized;
			}
		}

		$clean['images'] = self::copy_images( $clean );

		return $clean;
	}

	/**
	 * Image of a setting in IMAGES, without loading its attachment on the page.
	 *
	 * Reads the copy saved with the settings. Settings saved before the copy existed have
	 * none: the attachment is loaded then, until the settings are saved again.
	 *
	 * @param string $key Setting in IMAGES.
	 * @return array{url: string, width: int, height: int, alt: string}|null Null without an image.
	 */
	public static function image( string $key ): ?array {
		$id = (int) self::value( $key );
		if ( $id <= 0 ) {
			return null;
		}

		$images = self::value( 'images' );
		$stored = is_array( $images ) && is_array( $images[ $key ] ?? null ) ? $images[ $key ] : array();
		$copy   = (int) ( $stored['id'] ?? 0 ) === $id ? $stored : self::copy_image( $id );

		if ( '' === (string) ( $copy['url'] ?? '' ) ) {
			return null;
		}

		return array(
			'url'    => (string) $copy['url'],
			'width'  => (int) ( $copy['width'] ?? 0 ),
			'height' => (int) ( $copy['height'] ?? 0 ),
			'alt'    => (string) ( $copy['alt'] ?? '' ),
		);
	}

	/**
	 * Copies of the images the settings point to.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, array{id: int, url: string, width: int, height: int, alt: string}>
	 */
	private static function copy_images( array $settings ): array {
		$images = array();

		foreach ( self::IMAGES as $key ) {
			$id = (int) ( $settings[ $key ] ?? 0 );
			if ( $id > 0 ) {
				$images[ $key ] = self::copy_image( $id );
			}
		}

		return $images;
	}

	/**
	 * Address, full size and alternative text of an attachment; an empty address when it is
	 * not an image (anymore).
	 *
	 * @param int $id Attachment ID.
	 * @return array{id: int, url: string, width: int, height: int, alt: string}
	 */
	private static function copy_image( int $id ): array {
		$source = $id > 0 ? wp_get_attachment_image_src( $id, 'full' ) : false;

		return array(
			'id'     => $id,
			'url'    => false === $source ? '' : (string) $source[0],
			'width'  => false === $source ? 0 : (int) $source[1],
			'height' => false === $source ? 0 : (int) $source[2],
			'alt'    => false === $source ? '' : trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
		);
	}

	/**
	 * Refreshes the copied images when the file, size or alternative text of one of them changes.
	 *
	 * @param mixed $meta_id   Meta ID(s).
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 */
	public function on_attachment_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::IMAGE_META, true ) && self::uses_image( (int) $object_id ) ) {
			self::refresh_images();
		}
	}

	/**
	 * Copies a deleted image as "no image".
	 *
	 * @param mixed $post_id Deleted post ID.
	 */
	public function on_attachment_deleted( $post_id ): void {
		if ( self::uses_image( (int) $post_id ) ) {
			// The row is gone but still cached until core cleans it, right after this action.
			clean_post_cache( (int) $post_id );
			self::refresh_images();
		}
	}

	/**
	 * Whether an attachment is one of the images of the settings.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function uses_image( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$settings = self::get();
		foreach ( self::IMAGES as $key ) {
			if ( $id === (int) $settings[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Saves the settings again with fresh copies of the images.
	 *
	 * Copied here too, not only in sanitize(): that runs only once the setting is registered.
	 */
	private static function refresh_images(): void {
		$settings           = self::get();
		$settings['images'] = self::copy_images( $settings );

		update_option( self::OPTION, $settings );
	}
}
