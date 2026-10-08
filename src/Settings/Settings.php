<?php
/**
 * Plugin settings stored in the single autoloaded option.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings;

use EasyRankly\Context\Context;

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
	 * Registers the setting once post types and taxonomies exist.
	 */
	public function register(): void {
		// Late priority: later sections of the schema list post types and taxonomies.
		add_action( 'init', array( $this, 'register_setting' ), 99 );
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
			'title_separator' => array(
				'description' => __( 'Separator used by the {{sep}} template variable.', 'easyrankly' ),
				'type'        => 'string',
				'format'      => 'text-field',
				'maxLength'   => 10,
				'default'     => '-',
			),
			'social_image'    => array(
				'description' => __( 'Attachment ID of the image shared when a page has no image of its own.', 'easyrankly' ),
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => 0,
			),
			'x_username'      => array(
				'description' => __( 'X (Twitter) username of the site, without @.', 'easyrankly' ),
				'type'        => 'string',
				'pattern'     => '^[A-Za-z0-9_]{0,15}$',
				'default'     => '',
			),
			'noindex'         => array(
				'description' => __( 'Contexts whose pages ask search engines not to index them (same keys as the templates).', 'easyrankly' ),
				'type'        => 'array',
				'items'       => array(
					'type'    => 'string',
					'pattern' => Context::KEY_PATTERN,
				),
				'uniqueItems' => true,
				'default'     => array(),
			),
			'templates'       => array(
				'description'          => __( 'Title and description templates per context. Keys: home, single, archive, term, author, date, search, 404, or single-{post type}, archive-{post type}, term-{taxonomy}.', 'easyrankly' ),
				'type'                 => 'object',
				'patternProperties'    => array(
					Context::KEY_PATTERN => array(
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
					),
				),
				'additionalProperties' => false,
				'default'              => array(
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
			),
		);
	}

	/**
	 * Default value of every setting, read from the schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map(
			static fn( array $property ): mixed => $property['default'],
			self::properties()
		);
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
	 * Keeps only valid properties; anything invalid falls back to its current value or default.
	 *
	 * @param mixed $value Raw value, from REST, options.php or update_option().
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$clean = self::get();

		foreach ( self::properties() as $key => $property ) {
			if ( ! array_key_exists( $key, $value ) || true !== rest_validate_value_from_schema( $value[ $key ], $property, $key ) ) {
				continue;
			}

			$sanitized = rest_sanitize_value_from_schema( $value[ $key ], $property, $key );
			if ( ! is_wp_error( $sanitized ) ) {
				$clean[ $key ] = $sanitized;
			}
		}

		return $clean;
	}
}
