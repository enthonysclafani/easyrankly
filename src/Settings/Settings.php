<?php
/**
 * Plugin settings stored in the single autoloaded option.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings;

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
		$stored = get_option( self::OPTION, array() );

		return array_replace( self::defaults(), is_array( $stored ) ? array_intersect_key( $stored, self::defaults() ) : array() );
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
