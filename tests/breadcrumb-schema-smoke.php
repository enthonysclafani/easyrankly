<?php
/**
 * Breadcrumb schema resolver regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/breadcrumb-schema-smoke.php
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'ERankly_Plugin' ) ) {
	throw new RuntimeException( 'EasyRankly must be active before running this test.' );
}

require __DIR__ . '/bootstrap.php';

$post_type = 'erankly_crumb';
$taxonomy  = 'erankly_crumb_tax';
$parent_id = 0;
$child_id  = 0;
$term_id   = 0;

register_post_type(
	$post_type,
	array(
		'has_archive'   => true,
		'hierarchical'  => true,
		'label'         => 'Breadcrumb fixtures',
		'public'        => true,
		'show_in_rest'  => true,
		'supports'      => array( 'title', 'custom-fields', 'revisions' ),
	)
);
register_taxonomy(
	$taxonomy,
	$post_type,
	array(
		'hierarchical'       => true,
		'label'              => 'Breadcrumb fixture terms',
		'public'             => true,
		'publicly_queryable' => true,
		'show_in_rest'       => true,
	)
);

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$visibility        = 'index';
$home_translation  = static function ( $translation, $text, $domain ) {
	return 'Home' === $text && 'default' === $domain ? 'Core Home' : $translation;
};
$core_items_filter = static function ( $items ) {
	$items[0]['label'] = 'Filtered Home';

	return $items;
};

$metadata_filter = static function ( $value, $object_id, $meta_key ) use ( &$child_id, &$visibility ) {
	if ( $child_id === (int) $object_id && 'erankly_visibility' === $meta_key ) {
		return $visibility;
	}

	if ( $child_id === (int) $object_id && 'erankly_code' === $meta_key ) {
		return '';
	}

	return $value;
};

$render_graph = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_schema_graph();

	return ob_get_clean();
};

$find_breadcrumb = static function ( $graph ) {
	if ( ! is_array( $graph ) || empty( $graph['@graph'] ) || ! is_array( $graph['@graph'] ) ) {
		return null;
	}

	foreach ( $graph['@graph'] as $node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'] ) {
			return $node;
		}
	}

	return null;
};

$get_names = static function ( $schema ) {
	if ( ! is_array( $schema ) || empty( $schema['itemListElement'] ) ) {
		return array();
	}

	return array_values( wp_list_pluck( $schema['itemListElement'], 'name' ) );
};

add_filter( 'get_post_metadata', $metadata_filter, 10, 3 );
add_filter( 'pre_option_blog_public', '__return_true' );
add_filter( 'pre_option_erankly_global_code', '__return_empty_string' );
add_filter( 'gettext', $home_translation, 10, 3 );

try {
	$parent_id = wp_insert_post(
		array(
			'post_status' => 'publish',
			'post_title'  => 'Parent fixture',
			'post_type'   => $post_type,
		),
		true
	);
	$assert( ! is_wp_error( $parent_id ) && $parent_id > 0, 'Could not create the parent breadcrumb fixture.' );

	$child_id = wp_insert_post(
		array(
			'post_parent' => $parent_id,
			'post_status' => 'publish',
			'post_title'  => 'Child fixture',
			'post_type'   => $post_type,
		),
		true
	);
	$assert( ! is_wp_error( $child_id ) && $child_id > 0, 'Could not create the child breadcrumb fixture.' );

	$term = wp_insert_term( 'Taxonomy fixture', $taxonomy );
	$assert( ! is_wp_error( $term ), 'Could not create the breadcrumb taxonomy fixture.' );
	$term_id = (int) $term['term_id'];
	$assert( ! is_wp_error( wp_set_object_terms( $child_id, array( $term_id ), $taxonomy ) ), 'Could not assign the breadcrumb taxonomy fixture.' );

	$query = new WP_Query(
		array(
			'p'           => $child_id,
			'post_status' => 'publish',
			'post_type'   => $post_type,
		)
	);
	$query->is_404      = false;
	$query->is_page     = false;
	$query->is_preview  = false;
	$query->is_single   = true;
	$query->is_singular = true;
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = get_post( $child_id );
	setup_postdata( $GLOBALS['post'] );

	$hierarchical = $find_breadcrumb( $decode_graph( $render_graph() ) );
	$names        = $get_names( $hierarchical );
	$assert( 'Core Home' === $names[0], 'The breadcrumb Home label must reuse the WordPress core translation.' );
	$assert( in_array( 'Parent fixture', $names, true ), 'The default trail must contain the post ancestor.' );
	$assert( ! in_array( 'Taxonomy fixture', $names, true ), 'The default hierarchical trail must not contain its taxonomy term.' );

	add_filter( 'block_core_breadcrumbs_items', $core_items_filter );
	$filtered = $find_breadcrumb( $decode_graph( $render_graph() ) );
	$assert( 'Filtered Home' === $get_names( $filtered )[0], 'Structured breadcrumbs must honor the core visible-block items filter.' );
	remove_filter( 'block_core_breadcrumbs_items', $core_items_filter );

	$visibility = 'noindex';
	$assert( '' === $render_graph(), 'Noindex content must not emit breadcrumb schema.' );
} finally {
	remove_filter( 'get_post_metadata', $metadata_filter, 10 );
	remove_filter( 'pre_option_blog_public', '__return_true' );
	remove_filter( 'pre_option_erankly_global_code', '__return_empty_string' );
	remove_filter( 'gettext', $home_translation, 10 );
	remove_filter( 'block_core_breadcrumbs_items', $core_items_filter );

	if ( $child_id ) {
		wp_delete_post( $child_id, true );
	}
	if ( $parent_id ) {
		wp_delete_post( $parent_id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, $taxonomy );
	}

	unregister_taxonomy( $taxonomy );
	unregister_post_type( $post_type );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}
}

echo "EasyRankly breadcrumb schema smoke test passed.\n";
