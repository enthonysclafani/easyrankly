<?php
/**
 * Special-page SEO settings integration. Exposes the per-context defaults through the native WordPress Site
 * Settings entity while preserving the existing storage model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API group for the special-page map. Must not be a core options.php group (`general`, `reading`, …)
 * and must not be `erankly`: both of those forms call update_option() for every registered name, and a missing
 * POST field arrives as null. REST exposure is independent of the group (`show_in_rest`).
 */
function erankly_special_meta_setting_group(): string {
	return 'erankly_special_meta';
}

/**
 * Registers the special-page map in the native wp/v2/settings endpoint. Core Data exposes settings from this
 * endpoint as the root/site entity. Editing this property therefore participates in the Site Editor's native
 * dirty-state and save flow without attaching SEO data to wp_template records.
 */
function erankly_register_special_meta_setting(): void {
	static $registered = false;

	erankly_load_content_helpers();
	erankly_load_default_helpers();

	if ( ! $registered ) {
		$registered = true;

		register_setting(
			erankly_special_meta_setting_group(),
			ERANKLY_SPECIAL_META_OPTION,
			array(
				'type'              => 'object',
				'label'             => __( 'Special pages and archives', 'easyrankly' ),
				'default'           => erankly_normalize_special_meta_map( erankly_default_global_special_meta() ),
				'sanitize_callback' => 'erankly_sanitize_special_meta_map',
				'show_in_rest'      => array(
					'schema' => erankly_get_special_meta_rest_schema(),
				),
			)
		);
	}

	// Re-attach if a previous registration's hooks were removed.
	if ( ! has_filter( 'rest_pre_get_setting', 'erankly_rest_pre_get_special_meta_setting' ) ) {
		add_filter( 'rest_pre_get_setting', 'erankly_rest_pre_get_special_meta_setting', 10, 3 );
	}

	if ( ! has_filter( 'rest_pre_update_setting', 'erankly_rest_pre_update_special_meta_setting' ) ) {
		add_filter( 'rest_pre_update_setting', 'erankly_rest_pre_update_special_meta_setting', 10, 4 );
	}

	if ( ! has_filter( 'sanitize_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_sanitize_special_meta_map' ) ) {
		add_filter( 'sanitize_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_sanitize_special_meta_map' );
	}
}

/**
 * Advanced robots keys stored by `erankly_sanitize_global_entity_directives()` and preserved by the PHP
 * settings form as hidden inputs. They are optional on each REST row: omit them when unset so an explicit
 * "off" is not written for a field that was inheriting the site default.
 *
 * @return array<int,string>
 */
function erankly_special_meta_advanced_robot_keys(): array {
	return array(
		'index_directive',
		'follow_directive',
		'archive_directive',
		'snippet_directive',
		'image_directive',
		'notranslate',
		'indexifembedded',
		'max_snippet',
		'max_video_preview',
		'max_image_preview',
	);
}

/**
 * JSON Schema for one special-page row in wp/v2/settings. additionalProperties is false, so every field the
 * sanitizer can persist must be declared here or Core Data GET/PUT will drop it (or fail validation and
 * present the whole setting as null).
 *
 * @return array<string,array<string,mixed>>
 */
function erankly_get_special_meta_rest_row_schema(): array {
	return array(
		'title'               => array( 'type' => 'string' ),
		'description'         => array( 'type' => 'string' ),
		'noindex'             => array( 'type' => 'boolean' ),
		'nofollow'            => array( 'type' => 'boolean' ),
		'noarchive'           => array( 'type' => 'boolean' ),
		'disable_sitemap'     => array( 'type' => 'boolean' ),
		'og_title'            => array( 'type' => 'string' ),
		'og_description'      => array( 'type' => 'string' ),
		'twitter_title'       => array( 'type' => 'string' ),
		'twitter_description' => array( 'type' => 'string' ),
		'social_image_url'    => array( 'type' => 'string' ),
		'og_image_id'         => array(
			'type'    => 'integer',
			'minimum' => 0,
		),
		'index_directive'     => array( 'type' => 'string' ),
		'follow_directive'    => array( 'type' => 'string' ),
		'archive_directive'   => array( 'type' => 'string' ),
		'snippet_directive'   => array( 'type' => 'string' ),
		'image_directive'     => array( 'type' => 'string' ),
		'notranslate'         => array( 'type' => 'boolean' ),
		'indexifembedded'     => array( 'type' => 'boolean' ),
		'max_snippet'         => array( 'type' => 'string' ),
		'max_video_preview'   => array( 'type' => 'string' ),
		'max_image_preview'   => array( 'type' => 'string' ),
	);
}

/** @return array<string,mixed> */
function erankly_get_special_meta_rest_schema(): array {
	$properties = array();
	$row_schema = erankly_get_special_meta_rest_row_schema();

	foreach ( array_keys( erankly_special_page_keys() ) as $context ) {
		$properties[ $context ] = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $row_schema,
		);
	}

	return array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'properties'           => $properties,
	);
}

/**
 * Normalizes the stored map for the REST API and Core Data. Every supported context is present so edits can
 * update one nested row without reconstructing missing defaults in JavaScript.
 *
 * @return array<string,array<string,mixed>>
 */
function erankly_get_special_meta_rest_value(): array {
	return erankly_normalize_special_meta_map(
		erankly_get_global_entity_meta_map( 'global_special_meta' )
	);
}

/**
 * @param array<string,mixed> $map Stored or default map.
 * @return array<string,array<string,mixed>>
 */
function erankly_normalize_special_meta_map( array $map ): array {
	$value = array();

	foreach ( array_keys( erankly_special_page_keys() ) as $context ) {
		$row               = isset( $map[ $context ] ) && is_array( $map[ $context ] ) ? $map[ $context ] : array();
		$value[ $context ] = erankly_special_meta_row_defaults( $row );
	}

	return $value;
}

/** @param mixed               $result Preempted value, or null. */
function erankly_rest_pre_get_special_meta_setting( mixed $result, string $name, array $args ): mixed {
	unset( $args );

	if ( ERANKLY_SPECIAL_META_OPTION !== $name ) {
		return $result;
	}

	return erankly_get_special_meta_rest_value();
}

/** @param bool                $updated Whether another callback handled the update. */
function erankly_rest_pre_update_special_meta_setting( bool $updated, string $name, mixed $value, array $args ): bool {
	unset( $args );

	if ( ERANKLY_SPECIAL_META_OPTION !== $name ) {
		return $updated;
	}

	$incoming = is_array( $value ) ? $value : array();

	erankly_update_special_meta_map( erankly_merge_special_meta_rest_update( $incoming ) );

	return true;
}

/**
 * Merges a REST/Core Data payload onto the stored special-page map. Site Editor saves replace the setting
 * object; rows that omit advanced robots (or a client that PATCHes one context) must not wipe stored fields
 * the schema or UI did not send.
 *
 * @param array<string,mixed> $incoming Map from the REST request.
 * @return array<string,mixed>
 */
function erankly_merge_special_meta_rest_update( array $incoming ): array {
	$stored = erankly_get_global_entity_meta_map( 'global_special_meta' );

	if ( array() === $incoming ) {
		return is_array( $stored ) ? $stored : array();
	}

	foreach ( $stored as $context => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		if ( ! isset( $incoming[ $context ] ) || ! is_array( $incoming[ $context ] ) ) {
			$incoming[ $context ] = $row;
			continue;
		}

		foreach ( $row as $field => $value ) {
			if ( ! array_key_exists( $field, $incoming[ $context ] ) ) {
				$incoming[ $context ][ $field ] = $value;
			}
		}
	}

	return $incoming;
}

/**
 * Sanitizes the full special-page map.
 *
 * @return array<string,array<string,string|int>>
 */
function erankly_sanitize_special_meta_map( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		// options.php posts registered settings that are absent from $_POST as null. Never treat that as
		// an empty map: it would wipe per-site special-page titles and robots, especially on Multisite
		// where this option is the source of truth.
		$value = erankly_get_global_entity_meta_map( 'global_special_meta' );
	}

	return erankly_sanitize_global_entity_meta(
		$value,
		array_keys( erankly_special_page_keys() ),
		false,
		true
	);
}

/** @return array<string,mixed> */
function erankly_special_meta_row_defaults( array $row ): array {
	$value = array(
		'title'               => (string) ( $row['title'] ?? '' ),
		'description'         => (string) ( $row['description'] ?? '' ),
		'noindex'             => ! empty( $row['noindex'] ),
		'nofollow'            => ! empty( $row['nofollow'] ),
		'noarchive'           => ! empty( $row['noarchive'] ),
		'disable_sitemap'     => ! empty( $row['disable_sitemap'] ),
		'og_title'            => (string) ( $row['og_title'] ?? '' ),
		'og_description'      => (string) ( $row['og_description'] ?? '' ),
		'twitter_title'       => (string) ( $row['twitter_title'] ?? '' ),
		'twitter_description' => (string) ( $row['twitter_description'] ?? '' ),
		'social_image_url'    => (string) ( $row['social_image_url'] ?? '' ),
		'og_image_id'         => isset( $row['og_image_id'] ) ? absint( $row['og_image_id'] ) : 0,
	);

	foreach ( array( 'notranslate', 'indexifembedded' ) as $key ) {
		if ( array_key_exists( $key, $row ) ) {
			$value[ $key ] = ! empty( $row[ $key ] );
		}
	}

	foreach ( array( 'index_directive', 'follow_directive', 'archive_directive', 'snippet_directive', 'image_directive', 'max_image_preview' ) as $key ) {
		if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) && '' !== $row[ $key ] ) {
			$value[ $key ] = $row[ $key ];
		}
	}

	foreach ( array( 'max_snippet', 'max_video_preview' ) as $key ) {
		if ( ! array_key_exists( $key, $row ) || ! is_scalar( $row[ $key ] ) ) {
			continue;
		}

		$raw = trim( (string) $row[ $key ] );

		if ( '' !== $raw ) {
			$value[ $key ] = $raw;
		}
	}

	return $value;
}

/**
 * Writes the full special-page metadata map to its storage. Per site on Multisite (a dedicated site option);
 * nested in the shared settings array on single site, so both contexts read it back through the same getter.
 *
 * @return array<string,array<string,string|int>> Sanitized map that was stored.
 */
function erankly_update_special_meta_map( array $map ): array {
	$map = erankly_sanitize_special_meta_map( $map );

	if ( is_multisite() ) {
		// Autoloaded to match the settings form and import writers: the option is read
		// while rendering the document head on special pages.
		update_option( ERANKLY_SPECIAL_META_OPTION, $map, true );

		return $map;
	}

	$settings = get_option( ERANKLY_OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();

	$settings['global_special_meta'] = $map;

	erankly_update_plugin_option( ERANKLY_OPTION, $settings );

	return $map;
}
