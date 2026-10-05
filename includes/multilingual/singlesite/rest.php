<?php
/** Editor API and native wp/v2 integration, without exposing unpublished translations. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_register_content_rest(): void {
	register_rest_route( 'erankly/v1', '/multilingual/singlesite/editor/(?P<kind>post|term)/(?P<id>\d+)', array(
		'methods' => WP_REST_Server::READABLE,
		'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'post' === $request['kind'] ? 'edit_post' : 'edit_term', (int) $request['id'] ),
		'callback' => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$subtype = erankly_mlss_object_subtype( $request['kind'], (int) $request['id'] );
			if ( '' === $subtype ) { return new WP_Error( 'erankly_mlss_invalid', __( 'Unsupported object.', 'easyrankly' ), array( 'status' => 400 ) ); }
			require_once __DIR__ . '/editor.php';
			ob_start();
			erankly_mlss_render_translation_fields( $request['kind'], (int) $request['id'], $subtype );
			return new WP_REST_Response( array( 'html' => ob_get_clean(), 'language' => erankly_mlss_get_language( $request['kind'], (int) $request['id'] ) ) );
		},
	) );
	register_rest_route( 'erankly/v1', '/multilingual/singlesite/objects', array(
		'methods' => WP_REST_Server::READABLE,
		'permission_callback' => static fn(): bool => is_user_logged_in(),
		'callback' => 'erankly_mlss_search_objects',
		'args' => array(
			'kind' => array( 'type' => 'string', 'enum' => array( 'post', 'term' ), 'required' => true ),
			'subtype' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			'search' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'language' => array( 'type' => 'string', 'required' => true ),
		),
	) );
	register_rest_route( 'erankly/v1', '/multilingual/singlesite/create/(?P<kind>post|term)/(?P<id>\d+)', array(
		'methods' => WP_REST_Server::CREATABLE,
		'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'post' === $request['kind'] ? 'edit_post' : 'edit_term', (int) $request['id'] ),
		'callback' => static function ( WP_REST_Request $request ): WP_REST_Response|WP_Error {
			$result = erankly_mlss_create_translation( $request['kind'], (int) $request['id'], $request['language'] );
			return is_wp_error( $result ) ? $result : new WP_REST_Response( erankly_mlss_object_payload( $request['kind'], $result ), 201 );
		},
		'args' => array( 'language' => array( 'type' => 'string', 'required' => true ) ),
	) );
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( 'attachment' === $type ) { continue; }
		register_rest_field( $type, 'erankly_language', array(
			'get_callback' => static fn( array $object ): string => erankly_mlss_get_language( 'post', (int) $object['id'] ),
			'update_callback' => static fn( string $language, WP_Post $post ): bool|WP_Error => erankly_mlss_set_language( 'post', $post->ID, $language ),
			'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
		) );
		add_filter( "rest_{$type}_query", 'erankly_mlss_rest_query', 10, 2 );
	}
	foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
		register_rest_field( $taxonomy, 'erankly_language', array(
			'get_callback' => static fn( array $object ): string => erankly_mlss_get_language( 'term', (int) $object['id'] ),
			'update_callback' => static fn( string $language, WP_Term $term ): bool|WP_Error => erankly_mlss_set_language( 'term', $term->term_id, $language ),
			'schema' => array( 'type' => 'string', 'context' => array( 'view', 'edit' ) ),
		) );
		add_filter( "rest_{$taxonomy}_query", 'erankly_mlss_rest_query', 10, 2 );
	}
}

function erankly_mlss_rest_query( array $args, WP_REST_Request $request ): array {
	if ( null !== $request->get_param( 'erankly_lang' ) ) { $args['erankly_lang'] = $request['erankly_lang']; }
	return $args;
}

function erankly_mlss_object_payload( string $kind, int $id ): array {
	$object = 'post' === $kind ? get_post( $id ) : get_term( $id );
	return array( 'id' => $id, 'title' => 'post' === $kind ? $object->post_title : $object->name, 'language' => erankly_mlss_get_language( $kind, $id ), 'edit_url' => 'post' === $kind ? get_edit_post_link( $id, 'raw' ) : get_edit_term_link( $id, $object->taxonomy ) );
}

function erankly_mlss_search_objects( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$kind = $request['kind'];
	$subtype = $request['subtype'];
	$language = $request['language'];
	if ( ! in_array( $language, erankly_mlss_get_settings()['languages'], true ) ) { return new WP_Error( 'erankly_mlss_language', __( 'Choose a configured language.', 'easyrankly' ), array( 'status' => 400 ) ); }
	if ( 'post' === $kind ) {
		$type = get_post_type_object( $subtype );
		if ( ! $type || ! $type->public || 'attachment' === $subtype || ! current_user_can( $type->cap->edit_posts ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
		$args = array( 'post_type' => $subtype, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 20, 's' => $request['search'], 'erankly_lang' => $language, 'no_found_rows' => true );
		if ( ! current_user_can( $type->cap->edit_others_posts ) ) { $args['author'] = get_current_user_id(); }
		$ids = wp_list_pluck( ( new WP_Query( $args ) )->posts, 'ID' );
	} else {
		$tax = get_taxonomy( $subtype );
		if ( ! $tax || ( ! $tax->public && 'nav_menu' !== $subtype ) || ! current_user_can( $tax->cap->edit_terms ) ) { return new WP_Error( 'erankly_mlss_forbidden', __( 'Permission denied.', 'easyrankly' ), array( 'status' => 403 ) ); }
		$ids = get_terms( array( 'taxonomy' => $subtype, 'hide_empty' => false, 'number' => 20, 'search' => $request['search'], 'erankly_lang' => $language, 'fields' => 'ids' ) );
		$ids = is_wp_error( $ids ) ? array() : $ids;
	}
	$results = array();
	foreach ( $ids as $id ) {
		if ( current_user_can( 'post' === $kind ? 'edit_post' : 'edit_term', $id ) && erankly_mlss_get_language( $kind, (int) $id ) === $language ) { $results[] = erankly_mlss_object_payload( $kind, (int) $id ); }
	}
	return new WP_REST_Response( $results );
}

