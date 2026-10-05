<?php
/** Bounded single-site language configuration. Stored separately and never autoloaded. */
defined( 'ABSPATH' ) || exit;

/** Returns a valid locale-derived language tag, excluding the reserved x-default alias. */
function erankly_mlss_language_tag( string $language ): string {
	$tag = strtolower( str_replace( '_', '-', trim( $language ) ) );
	return 'x-default' !== $tag && erankly_is_valid_hreflang_tag( $tag ) ? $tag : '';
}

/** A single path segment; accept the /it/ notation shown in settings. */
function erankly_mlss_url_slug( mixed $value ): string {
	if ( ! is_string( $value ) ) { return ''; }
	$slug = strtolower( trim( trim( $value ), '/' ) );
	$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', rest_get_url_prefix() );
	return preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) && strlen( $slug ) <= 64 && ! in_array( $slug, $reserved, true ) ? $slug : '';
}

function erankly_mlss_normalize_settings( array $input ): array {
	$languages = $input['languages'] ?? array( get_option( 'WPLANG' ) ?: 'en_US' );
	$languages = is_string( $languages ) ? preg_split( '/[\s,]+/', $languages ) : $languages;
	$clean     = array();
	foreach ( array_slice( (array) $languages, 0, 32 ) as $language ) {
		$tag = is_string( $language ) ? erankly_mlss_language_tag( $language ) : '';
		if ( '' !== $tag ) {
			$clean[ $tag ] = $tag;
		}
	}
	$clean   = array_values( $clean );
	$default = erankly_mlss_language_tag( is_string( $input['default_language'] ?? null ) ? $input['default_language'] : '' );
	$default = in_array( $default, $clean, true ) ? $default : ( $clean[0] ?? '' );
	$x_default = erankly_mlss_language_tag( is_string( $input['x_default_language'] ?? null ) ? $input['x_default_language'] : '' );
	$slugs = array();
	foreach ( $clean as $language ) {
		$slug = erankly_mlss_url_slug( $input['url_slugs'][ $language ] ?? '' );
		$slugs[ $language ] = '' !== $slug ? $slug : $language;
	}
	return array(
		'languages'         => $clean,
		'url_slugs'         => $slugs,
		'default_language'  => $default,
		'x_default_language' => in_array( $x_default, $clean, true ) ? $x_default : '',
		'prefix_default' => ! empty( $input['prefix_default'] ) ? 1 : 0,
	);
}

function erankly_mlss_get_settings(): array {
	if ( ! isset( $GLOBALS['erankly_mlss_settings'] ) ) {
		$stored = get_option( ERANKLY_MLSS_OPTION, array() );
		$GLOBALS['erankly_mlss_settings'] = erankly_mlss_normalize_settings( is_array( $stored ) ? $stored : array() );
	}
	return $GLOBALS['erankly_mlss_settings'];
}

/** @return true|WP_Error */
function erankly_mlss_save_settings( array $input ): bool|WP_Error {
	$settings = erankly_mlss_normalize_settings( $input );
	if ( ! $settings['languages'] ) {
		return new WP_Error( 'erankly_mlss_no_languages', __( 'Configure at least one language.', 'easyrankly' ), array( 'status' => 400 ) );
	}
	foreach ( $settings['languages'] as $language ) {
		$raw = $input['url_slugs'][ $language ] ?? '';
		if ( '' !== $raw && ( ! is_string( $raw ) || ( '' !== trim( $raw ) && '' === erankly_mlss_url_slug( $raw ) ) ) ) {
			return new WP_Error( 'erankly_mlss_invalid_url_slug', __( 'Use a single URL prefix with letters, numbers or hyphens, for example /it/. WordPress service paths are reserved.', 'easyrankly' ), array( 'status' => 400 ) );
		}
	}
	if ( count( array_unique( $settings['url_slugs'] ) ) !== count( $settings['url_slugs'] ) ) {
		return new WP_Error( 'erankly_mlss_duplicate_url_slug', __( 'Each language must have a different URL prefix.', 'easyrankly' ), array( 'status' => 400 ) );
	}
	$previous = erankly_mlss_get_settings();
	if ( 1 === (int) get_option( 'erankly_multilingual_schema' ) ) {
		global $wpdb;
		$used = $wpdb->get_col( 'SELECT DISTINCT language FROM ' . erankly_mlss_table() );
		if ( array_diff( $used, $settings['languages'] ) ) {
			return new WP_Error( 'erankly_mlss_language_in_use', __( 'A language assigned to content cannot be removed. Reassign that content first.', 'easyrankly' ), array( 'status' => 409 ) );
		}
	}
	if ( isset( $input['strings'] ) && is_array( $input['strings'] ) ) {
		$result = erankly_mlss_save_strings( $input['strings'], $settings['languages'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}
	if ( isset( $input['menus'] ) && is_array( $input['menus'] ) ) {
		$menus = array();
		foreach ( $settings['languages'] as $language ) {
			if ( ! empty( $input['menus'][ $language ] ) ) { $menus[ $language ] = absint( $input['menus'][ $language ] ); }
		}
		if ( $menus ) {
			$result = erankly_mlss_set_translations( 'term', reset( $menus ), $menus );
			if ( is_wp_error( $result ) ) { return $result; }
		}
	}
	$updated  = update_option( ERANKLY_MLSS_OPTION, $settings, false );
	unset( $GLOBALS['erankly_mlss_settings'] );
	if ( $previous !== $settings ) {
		delete_option( 'rewrite_rules' );
		if ( function_exists( 'erankly_flush_sitemap_cache' ) ) { erankly_flush_sitemap_cache(); }
	}
	return $updated || get_option( ERANKLY_MLSS_OPTION ) === $settings
		? true : new WP_Error( 'erankly_mlss_save_failed', __( 'Multilingual settings could not be saved.', 'easyrankly' ), array( 'status' => 500 ) );
}

function erankly_mlss_register_rest_routes(): void {
	register_rest_route( 'erankly/v1', '/multilingual/singlesite/settings', array(
		'methods' => WP_REST_Server::EDITABLE,
		'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
		'callback' => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$result = erankly_mlss_save_settings( (array) $request->get_param( 'settings' ) );
			return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'saved' => true, 'warnings' => array() ) );
		},
		'args' => array( 'settings' => array( 'type' => 'object', 'required' => true ) ),
	) );
	register_rest_route( 'erankly/v1', '/multilingual/singlesite/translations/(?P<kind>post|term)/(?P<id>\d+)', array(
		'methods' => WP_REST_Server::EDITABLE,
		'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'post' === $request['kind'] ? 'edit_post' : 'edit_term', (int) $request['id'] ),
		'callback' => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$result = erankly_mlss_set_translations( $request['kind'], (int) $request['id'], (array) $request['translations'] );
			return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'saved' => true, 'translations' => erankly_mlss_get_translations( $request['kind'], (int) $request['id'] ) ) );
		},
		'args' => array( 'translations' => array( 'type' => 'object', 'required' => true, 'additionalProperties' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
	) );
}
