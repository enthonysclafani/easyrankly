<?php
/**
 * Classic meta box and taxonomy form save handlers. The request is wp_unslash()ed once after the nonce check,
 * each field is sanitized by erankly_sanitize_registered_meta(), and text values are wp_slash()ed before
 * update_metadata() so literal backslashes (JSON-LD) survive.
 */
defined( 'ABSPATH' ) || exit;
function erankly_save_meta_box( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['erankly_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_meta_box_nonce'] ) ), 'erankly_save_meta_box' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; each field is sanitized by erankly_sanitize_registered_meta().
	$fields = erankly_meta_form_text_fields();
	if ( (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		$fields['_erankly_breadcrumb_name'] = 'erankly_breadcrumb_name';
	}
	erankly_save_meta_form_text_fields( 'post', $post_id, $fields, $input );
	// The attachment id is the frontend's fallback for an empty URL. The classic form has no id field, so a
	// cleared URL has to drop the companion id too: otherwise "remove the image" left an imported id behind
	// and the picture kept being emitted.
	foreach ( array( '_erankly_og_image_url' => '_erankly_og_image_id', '_erankly_twitter_image_url' => '_erankly_twitter_image_id' ) as $url_key => $id_key ) {
		if ( '' === (string) get_post_meta( $post_id, $url_key, true ) ) {
			delete_post_meta( $post_id, $id_key );
		}
	}
	erankly_save_meta_form_robots( 'post', $post_id, $input );
	erankly_save_meta_form_flags(
		'post',
		$post_id,
		array(
			'_erankly_disable_sitemap'   => 'erankly_disable_sitemap',
			'_erankly_exclude_search'    => 'erankly_exclude_search',
			'_erankly_exclude_archive'   => 'erankly_exclude_archive',
			'_erankly_exclude_from_news' => 'erankly_exclude_from_news',
		),
		$input
	);
	erankly_save_meta_box_schema( $post_id, $input );
	do_action( 'erankly_save_meta_box', $post_id, $post );
}
function erankly_save_term_fields( int $term_id ): void {
	if ( ! isset( $_POST['erankly_term_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_term_fields_nonce'] ) ), 'erankly_save_term_fields' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; each field is sanitized by erankly_sanitize_registered_meta().
	erankly_save_meta_form_text_fields( 'term', $term_id, erankly_meta_form_text_fields(), $input );
	erankly_save_meta_form_robots( 'term', $term_id, $input );
	erankly_save_meta_form_flags( 'term', $term_id, array( '_erankly_disable_sitemap' => 'erankly_disable_sitemap' ), $input );
}
/**
 * Text fields shared by the post meta box and the taxonomy forms.
 *
 * @return array<string,string> Meta key => form field name.
 */
function erankly_meta_form_text_fields(): array {
	return array(
		'_erankly_title'               => 'erankly_title',
		'_erankly_description'         => 'erankly_description',
		'_erankly_canonical'           => 'erankly_canonical',
		'_erankly_og_title'            => 'erankly_og_title',
		'_erankly_og_description'      => 'erankly_og_description',
		'_erankly_twitter_title'       => 'erankly_twitter_title',
		'_erankly_twitter_description' => 'erankly_twitter_description',
		'_erankly_twitter_card_type'   => 'erankly_twitter_card_type',
		'_erankly_og_image_url'        => 'erankly_og_image_url',
		'_erankly_og_image_alt'        => 'erankly_og_image_alt',
		'_erankly_twitter_image_url'   => 'erankly_twitter_image_url',
		'_erankly_twitter_image_alt'   => 'erankly_twitter_image_alt',
	);
}
/**
 * Stores or deletes each text field; an empty value deletes the row.
 *
 * @param array<string,string> $fields Meta key => form field name.
 * @param array<string,mixed>  $input  Unslashed request.
 */
function erankly_save_meta_form_text_fields( string $object_type, int $object_id, array $fields, array $input ): void {
	foreach ( $fields as $key => $field ) {
		$value = erankly_sanitize_registered_meta( $input[ $field ] ?? '', $key );
		if ( '' === $value || 0 === $value ) {
			delete_metadata( $object_type, $object_id, $key );
		} else {
			update_metadata( $object_type, $object_id, $key, wp_slash( $value ) );
		}
	}
}
/**
 * Stores the tri-state robots directives and preview limits; `inherit` deletes the row.
 *
 * @param array<string,mixed> $input Unslashed request.
 */
function erankly_save_meta_form_robots( string $object_type, int $object_id, array $input ): void {
	foreach ( array( 'index_directive', 'follow_directive', 'archive_directive', 'snippet_directive', 'image_directive', 'max_snippet', 'max_video_preview', 'max_image_preview' ) as $key ) {
		$value = erankly_sanitize_registered_meta( $input[ 'erankly_' . $key ] ?? '', '_erankly_' . $key );
		if ( '' === $value || 'inherit' === $value ) {
			delete_metadata( $object_type, $object_id, '_erankly_' . $key );
		} else {
			update_metadata( $object_type, $object_id, '_erankly_' . $key, $value );
		}
	}
	erankly_save_meta_form_flags( $object_type, $object_id, array( '_erankly_indexifembedded' => 'erankly_indexifembedded' ), $input );
}
/**
 * Stores checkbox flags as '1' and deletes unchecked ones.
 *
 * @param array<string,string> $flags Meta key => form field name.
 * @param array<string,mixed>  $input Unslashed request.
 */
function erankly_save_meta_form_flags( string $object_type, int $object_id, array $flags, array $input ): void {
	foreach ( $flags as $key => $field ) {
		if ( isset( $input[ $field ] ) ) {
			update_metadata( $object_type, $object_id, $key, '1' );
		} else {
			delete_metadata( $object_type, $object_id, $key );
		}
	}
}
/**
 * Stores primary terms, custom schema blocks, suppressed schema types and the schema mode.
 *
 * @param array<string,mixed> $input Unslashed request.
 */
function erankly_save_meta_box_schema( int $post_id, array $input ): void {
	$GLOBALS['erankly_schema_blocks_previous'] = get_post_meta( $post_id, '_erankly_schema_blocks', true );
	$disabled_types = $input['erankly_schema_disabled_types'] ?? array();
	if ( is_string( $disabled_types ) ) {
		$disabled_types = preg_split( '/[\r\n,]+/', $disabled_types );
	}
	$disabled_types = is_array( $disabled_types ) ? $disabled_types : array();
	$extra_disabled = $input['erankly_schema_disabled_types_extra'] ?? '';
	if ( is_string( $extra_disabled ) && '' !== trim( $extra_disabled ) ) {
		$disabled_types = array_merge( $disabled_types, preg_split( '/[\r\n,]+/', $extra_disabled ) );
	}
	$complex_fields = array(
		'_erankly_primary_terms'         => $input['erankly_primary_terms'] ?? array(),
		'_erankly_schema_blocks'         => $input['erankly_schema_blocks'] ?? array(),
		'_erankly_schema_disabled_types' => $disabled_types,
	);
	foreach ( $complex_fields as $key => $raw_value ) {
		$value = erankly_sanitize_registered_meta( $raw_value, $key );
		if ( empty( $value ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}
	$schema_mode = erankly_sanitize_registered_meta( $input['erankly_schema_mode'] ?? '', '_erankly_schema_mode' );
	if ( '' === $schema_mode || 'default' === $schema_mode ) {
		delete_post_meta( $post_id, '_erankly_schema_mode' );
	} else {
		update_post_meta( $post_id, '_erankly_schema_mode', $schema_mode );
	}
}
