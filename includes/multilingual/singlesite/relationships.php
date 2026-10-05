<?php
/** Native entities linked by indexed membership; authorize every affected object. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_object_subtype( string $kind, int $id ): string {
	if ( 'post' === $kind ) {
		$post = get_post( $id );
		$type = $post instanceof WP_Post ? get_post_type_object( $post->post_type ) : null;
		return $type && $type->public && 'attachment' !== $type->name ? $type->name : '';
	}
	if ( 'term' === $kind ) {
		$term = get_term( $id );
		$tax = $term instanceof WP_Term ? get_taxonomy( $term->taxonomy ) : null;
		return $tax && ( $tax->public || 'nav_menu' === $tax->name ) && 'post_format' !== $tax->name ? $tax->name : '';
	}
	return '';
}

function erankly_mlss_get_translations( string $kind, int $id ): array {
	global $wpdb;
	if ( ! in_array( $kind, array( 'post', 'term' ), true ) || $id < 1 ) {
		return array();
	}
	$row = erankly_mlss_membership( $kind, $id );
	if ( ! $row ) {
		$legacy = erankly_mlss_has_legacy() ? get_metadata( $kind, $id, ERANKLY_MLSS_META_KEY, true ) : false;
		if ( is_array( $legacy ) ) {
			$legacy = array_map( 'absint', $legacy );
			ksort( $legacy );
			return $legacy;
		}
		return '' !== erankly_mlss_object_subtype( $kind, $id ) ? array( erankly_mlss_get_settings()['default_language'] => $id ) : array();
	}
	$key = "$kind:group:{$row['group_id']}";
	$map = wp_cache_get( $key, 'erankly_languages' );
	if ( false === $map ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, language, group_id FROM ' . erankly_mlss_table() . ' WHERE kind = %s AND group_id = %s ORDER BY language', $kind, $row['group_id'] ), ARRAY_A );
		$map = array();
		foreach ( $rows as $member ) {
			$map[ $member['language'] ] = (int) $member['object_id'];
			wp_cache_set( "$kind:{$member['object_id']}", $member, 'erankly_languages' );
		}
		wp_cache_set( $key, $map, 'erankly_languages' );
	}
	return $map;
}

/** Complete replacement. Displaced members keep their original group and language. */
function erankly_mlss_set_translations( string $kind, int $id, array $translations ): bool|WP_Error {
	if ( is_multisite() || ! erankly_multilingual_enabled() ) {
		return new WP_Error( 'erankly_mlss_inactive', __( 'The single-site Multilingual module is inactive.', 'easyrankly' ), array( 'status' => 409 ) );
	}
	$subtype = erankly_mlss_object_subtype( $kind, $id );
	$allowed = erankly_mlss_get_settings()['languages'];
	$map = array();
	foreach ( $translations as $language => $target ) {
		$tag = erankly_mlss_language_tag( (string) $language );
		if ( ! in_array( $tag, $allowed, true ) || ! is_numeric( $target ) || (int) $target < 1 || (float) $target !== (float) (int) $target || isset( $map[ $tag ] ) ) {
			return new WP_Error( 'erankly_mlss_invalid_map', __( 'Choose one configured language and one existing object for each translation.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		$map[ $tag ] = (int) $target;
	}
	ksort( $map );
	if ( '' === $subtype || ! in_array( $id, $map, true ) || count( array_unique( $map ) ) !== count( $map ) ) {
		return new WP_Error( 'erankly_mlss_invalid_cluster', __( 'The cluster must include the source, with a different object for each language.', 'easyrankly' ), array( 'status' => 400 ) );
	}
	$affected = array_values( $map );
	foreach ( $map as $target ) {
		if ( erankly_mlss_object_subtype( $kind, $target ) !== $subtype ) {
			return new WP_Error( 'erankly_mlss_type_mismatch', __( 'Translations must share the same post type or taxonomy.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		$affected = array_merge( $affected, array_values( erankly_mlss_get_translations( $kind, $target ) ) );
	}
	if ( $map === erankly_mlss_get_translations( $kind, $id ) && erankly_mlss_membership( $kind, $id ) && current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $id ) ) {
		return true;
	}
	foreach ( array_unique( $affected ) as $target ) {
		if ( ! current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $target ) ) {
			return new WP_Error( 'erankly_mlss_forbidden', __( 'You cannot edit every object affected by these translation links.', 'easyrankly' ), array( 'status' => 403 ) );
		}
	}
	$keys = array();
	foreach ( $map as $target ) { $keys[] = erankly_mlss_group_lock_key( $kind, $target ); }
	$keys = array_values( array_unique( $keys ) ); sort( $keys );
	$locks = array();
	try {
		foreach ( $keys as $key ) {
			$token = erankly_mlss_lock( $key );
			if ( '' === $token ) { return new WP_Error( 'erankly_mlss_busy', __( 'These translations are being edited. Retry in a moment.', 'easyrankly' ), array( 'status' => 409 ) ); }
			$locks[ $key ] = $token;
		}
		foreach ( $map as $target ) {
			erankly_mlss_refresh_membership( $kind, $target );
			if ( ! in_array( erankly_mlss_group_lock_key( $kind, $target ), $keys, true ) ) {
				return new WP_Error( 'erankly_mlss_changed', __( 'The translation group changed. Reload the editor before saving.', 'easyrankly' ), array( 'status' => 409 ) );
			}
			foreach ( erankly_mlss_get_translations( $kind, $target ) as $member ) {
				if ( ! current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $member ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
			}
		}
		global $wpdb;
		$group = wp_generate_uuid4();
		$values = array();
		$args = array();
		$old_groups = array();
		foreach ( $map as $language => $target ) {
			$old = erankly_mlss_membership( $kind, $target );
			if ( $old ) {
				$old_groups[] = $old['group_id'];
			}
			$values[] = '(%s,%d,%s,%s)';
			array_push( $args, $kind, $target, $language, $group );
		}
		// One atomic InnoDB statement; never commits a caller's transaction.
		$sql = 'INSERT INTO ' . erankly_mlss_table() . ' (kind,object_id,language,group_id) VALUES ' . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE language=VALUES(language), group_id=VALUES(group_id)';
		if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) {
			return new WP_Error( 'erankly_mlss_write_failed', __( 'Translation links could not be saved.', 'easyrankly' ), array( 'status' => 500 ) );
		}
		foreach ( array_unique( $old_groups ) as $old_group ) {
			wp_cache_delete( "$kind:group:$old_group", 'erankly_languages' );
		}
		foreach ( $map as $target ) {
			wp_cache_delete( "$kind:$target", 'erankly_languages' );
			delete_metadata( $kind, $target, ERANKLY_MLSS_META_KEY );
		}
		if ( function_exists( 'erankly_flush_sitemap_cache' ) ) {
			erankly_flush_sitemap_cache();
		}
		do_action( 'erankly_multilingual_translations_updated', $kind, $id, $map, array_values( array_unique( $affected ) ) );
		return true;
	} finally {
		foreach ( array_reverse( $locks, true ) as $key => $token ) { erankly_mlss_unlock( $key, $token ); }
	}
}

function erankly_mlss_set_language( string $kind, int $id, string $language ): bool|WP_Error {
	$map = erankly_mlss_get_translations( $kind, $id );
	$old = array_search( $id, $map, true );
	if ( false !== $old ) {
		unset( $map[ $old ] );
	}
	$language = erankly_mlss_language_tag( $language );
	if ( isset( $map[ $language ] ) ) {
		return new WP_Error( 'erankly_mlss_language_exists', __( 'This group already has a translation in that language.', 'easyrankly' ), array( 'status' => 409 ) );
	}
	$map[ $language ] = $id;
	return erankly_mlss_set_translations( $kind, $id, $map );
}

function erankly_mlss_delete_links( string $kind, int $id ): void {
	global $wpdb;
	$row = erankly_mlss_membership( $kind, $id );
	$wpdb->delete( erankly_mlss_table(), array( 'kind' => $kind, 'object_id' => $id ), array( '%s', '%d' ) );
	wp_cache_delete( "$kind:$id", 'erankly_languages' );
	if ( $row ) {
		wp_cache_delete( "$kind:group:{$row['group_id']}", 'erankly_languages' );
	}
}
function erankly_mlss_delete_post_links( int $id ): void { erankly_mlss_delete_links( 'post', $id ); }
function erankly_mlss_delete_term_links( int $id, string $taxonomy ): void { erankly_mlss_delete_links( 'term', $id ); }

/** Public lazy creation API, also available to CLI/import integrations. */
function erankly_mlss_create_translation( string $kind, int $id, string $language ): int|WP_Error {
	require_once __DIR__ . '/editor-operations.php';
	return erankly_mlss_create_translation_impl( $kind, $id, $language );
}
