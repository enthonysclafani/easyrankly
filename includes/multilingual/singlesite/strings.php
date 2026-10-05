<?php
/** Explicit shared strings; load only the requested language dictionary, no HTML parsing. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_register_string( string $key, string $source, string $group = '' ): void {
	$key = sanitize_key( $key );
	if ( '' !== $key ) {
		$GLOBALS['erankly_mlss_strings_registry'][ $key ] = array( 'source' => $source, 'group' => $group );
	}
}

function erankly_mlss_translate_string( string $key, string $source, string $language = '' ): string {
	$language = $language ?: erankly_mlss_current_language();
	if ( ! in_array( $language, erankly_mlss_get_settings()['languages'], true ) || $language === erankly_mlss_get_settings()['default_language'] ) {
		return $source;
	}
	if ( ! isset( $GLOBALS['erankly_mlss_strings'][ $language ] ) ) {
		$GLOBALS['erankly_mlss_strings'][ $language ] = (array) get_option( 'erankly_multilingual_strings_' . $language, array() );
	}
	$value = $GLOBALS['erankly_mlss_strings'][ $language ][ sanitize_key( $key ) ] ?? '';
	return is_string( $value ) && '' !== $value ? $value : $source;
}

function erankly_mlss_save_strings( array $input, array $languages ): bool|WP_Error {
	foreach ( $languages as $language ) {
		if ( ! isset( $input[ $language ] ) || ! is_array( $input[ $language ] ) ) {
			continue;
		}
		$dictionary = (array) get_option( 'erankly_multilingual_strings_' . $language, array() );
		foreach ( array_slice( $input[ $language ], 0, 256, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' !== $key && is_string( $value ) ) {
				$dictionary[ $key ] = sanitize_textarea_field( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 5000 ) : substr( $value, 0, 5000 ) );
			}
		}
		$option = 'erankly_multilingual_strings_' . $language;
		if ( ! update_option( $option, $dictionary, false ) && get_option( $option ) !== $dictionary ) {
			return new WP_Error( 'erankly_mlss_strings_failed', __( 'Shared strings could not be saved.', 'easyrankly' ), array( 'status' => 500 ) );
		}
	}
	unset( $GLOBALS['erankly_mlss_strings'] );
	return true;
}

function erankly_mlss_blogname( string $value ): string {
	return erankly_mlss_frontend_request() ? erankly_mlss_translate_string( 'site_title', $value ) : $value;
}
function erankly_mlss_blogdescription( string $value ): string {
	return erankly_mlss_frontend_request() ? erankly_mlss_translate_string( 'site_tagline', $value ) : $value;
}
