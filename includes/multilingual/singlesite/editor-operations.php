<?php
/** Editorial copies are loaded only for explicit creation requests. */
defined( 'ABSPATH' ) || exit;

/** Editorial copies are drafts; share media IDs and copy only explicitly safe metadata. */
function erankly_mlss_create_translation_impl( string $kind, int $id, string $language ): int|WP_Error {
	if ( ! erankly_multilingual_enabled() || is_multisite() ) { return new WP_Error( 'erankly_mlss_inactive', __( 'The single-site Multilingual module is inactive.', 'easyrankly' ), array( 'status' => 409 ) ); }
	if ( ! current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $id ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
	$key = erankly_mlss_group_lock_key( $kind, $id );
	$token = erankly_mlss_lock( $key );
	if ( '' === $token ) { return new WP_Error( 'erankly_mlss_busy', __( 'These translations are being edited. Retry in a moment.', 'easyrankly' ), array( 'status' => 409 ) ); }
	try {
		erankly_mlss_refresh_membership( $kind, $id );
		if ( $key !== erankly_mlss_group_lock_key( $kind, $id ) ) { return new WP_Error( 'erankly_mlss_changed', __( 'The translation group changed. Reload the editor before saving.', 'easyrankly' ), array( 'status' => 409 ) ); }
		return erankly_mlss_create_translation_locked( $kind, $id, $language );
	} finally { erankly_mlss_unlock( $key, $token ); }
}

function erankly_mlss_create_translation_locked( string $kind, int $id, string $language ): int|WP_Error {
	$language = erankly_mlss_language_tag( $language );
	$map = erankly_mlss_get_translations( $kind, $id );
	$subtype = erankly_mlss_object_subtype( $kind, $id );
	if ( '' === $subtype || ! in_array( $language, erankly_mlss_get_settings()['languages'], true ) ) { return new WP_Error( 'erankly_mlss_invalid', __( 'Choose a configured language and a supported object.', 'easyrankly' ), array( 'status' => 400 ) ); }
	if ( isset( $map[ $language ] ) ) { return new WP_Error( 'erankly_mlss_exists', __( 'A translation already exists in that language.', 'easyrankly' ), array( 'status' => 409 ) ); }
	foreach ( $map as $target ) {
		if ( ! current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $target ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
	}
	if ( 'post' === $kind ) {
		$type = get_post_type_object( $subtype );
		if ( ! current_user_can( $type->cap->create_posts ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
		$source = get_post( $id );
		$parent = $source->post_parent ? ( erankly_mlss_get_translations( 'post', $source->post_parent )[ $language ] ?? 0 ) : 0;
		$new_id = wp_insert_post( wp_slash( array( 'post_type' => $subtype, 'post_status' => 'draft', 'post_title' => $source->post_title, 'post_content' => $source->post_content, 'post_excerpt' => $source->post_excerpt, 'post_author' => get_current_user_id(), 'post_parent' => $parent, 'menu_order' => $source->menu_order, 'comment_status' => $source->comment_status, 'ping_status' => $source->ping_status ) ), true );
	} else {
		$tax = get_taxonomy( $subtype );
		if ( 'nav_menu' === $subtype || ! current_user_can( $tax->cap->manage_terms ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Create navigation menus in the WordPress menu editor.', 'easyrankly' ), array( 'status' => 403 ) ); }
		$source = get_term( $id );
		$parent = $source->parent ? ( erankly_mlss_get_translations( 'term', $source->parent )[ $language ] ?? 0 ) : 0;
		$inserted = wp_insert_term( $source->name . ' (' . $language . ')', $subtype, array( 'description' => $source->description, 'parent' => $parent, 'slug' => $source->slug . '-' . $language ) );
		$new_id = is_wp_error( $inserted ) ? $inserted : (int) $inserted['term_id'];
	}
	if ( is_wp_error( $new_id ) ) { return $new_id; }
	$map[ $language ] = $new_id;
	$result = erankly_mlss_set_translations( $kind, $id, $map );
	if ( is_wp_error( $result ) ) {
		'post' === $kind ? wp_delete_post( $new_id, true ) : wp_delete_term( $new_id, $subtype );
		return $result;
	}
	if ( 'post' === $kind ) {
		$keys = (array) apply_filters( 'erankly_multilingual_copy_meta_keys', array( '_thumbnail_id', '_wp_page_template' ), $source, $language );
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && ! in_array( $key, array( '_erankly_canonical', ERANKLY_MLSS_META_KEY, '_edit_lock', '_edit_last' ), true ) && metadata_exists( 'post', $id, $key ) ) { update_post_meta( $new_id, $key, wp_slash( get_post_meta( $id, $key, true ) ) ); }
		}
		foreach ( get_object_taxonomies( $subtype ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids', 'erankly_lang' => 'all' ) );
			if ( is_wp_error( $terms ) ) { continue; }
			$translated = array();
			foreach ( $terms as $term_id ) {
				$target = erankly_mlss_get_translations( 'term', (int) $term_id )[ $language ] ?? 0;
				if ( $target ) { $translated[] = $target; }
			}
			wp_set_object_terms( $new_id, $translated, $taxonomy );
		}
	}
	return (int) $new_id;
}
