<?php
/**
 * Sitemap noindex query regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/sitemap-query-smoke.php
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

$fixture_ids = array();
$cleanup     = static function () use ( &$fixture_ids ) {
	foreach ( $fixture_ids as $fixture_id ) {
		wp_delete_post( $fixture_id, true );
	}

	$fixture_ids = array();
};

register_shutdown_function( $cleanup );

foreach ( array( 'missing', 'index', 'noindex' ) as $visibility ) {
	$post_id = wp_insert_post(
		array(
			'post_status' => 'publish',
			'post_title'  => 'EasyRankly sitemap fixture ' . $visibility,
			'post_type'   => 'post',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}

	$fixture_ids[ $visibility ] = $post_id;
	update_post_meta( $post_id, 'erankly_sitemap_fixture', 'yes' );

	if ( 'missing' !== $visibility ) {
		update_post_meta( $post_id, 'erankly_visibility', $visibility );
	}
}

try {
	$meta_query = array(
		array(
			'key'   => 'erankly_sitemap_fixture',
			'value' => 'yes',
		),
	);
	$args       = array(
		'fields'           => 'ids',
		'meta_query'       => $meta_query,
		'no_found_rows'    => true,
		'post__in'         => array_values( $fixture_ids ),
		'post_status'      => 'publish',
		'post_type'        => 'post',
		'posts_per_page'   => -1,
		'suppress_filters' => true,
	);
	$filtered   = ERankly_Plugin::filter_sitemap_post_query_args( $args, 'post' );

	$assert( true === $filtered['_erankly_exclude_noindex'], 'Sitemap queries must carry the private noindex exclusion flag.' );
	$assert( false === $filtered['suppress_filters'], 'The scoped sitemap predicate must not be suppressed.' );
	$assert( $meta_query === $filtered['meta_query'], 'EasyRankly must preserve existing sitemap metadata constraints.' );

	$sitemap_query = new WP_Query( $filtered );
	$sitemap_ids   = array_map( 'intval', $sitemap_query->posts );

	$assert( in_array( $fixture_ids['missing'], $sitemap_ids, true ), 'Content without visibility metadata must remain in the sitemap.' );
	$assert( in_array( $fixture_ids['index'], $sitemap_ids, true ), 'Explicitly indexable content must remain in the sitemap.' );
	$assert( ! in_array( $fixture_ids['noindex'], $sitemap_ids, true ), 'Noindex content must be excluded from the sitemap.' );
	$assert( false !== strpos( $sitemap_query->request, 'NOT EXISTS' ), 'Sitemap exclusion must use an indexed anti-lookup.' );
	$assert( false !== strpos( $sitemap_query->request, 'erankly_visibility_meta' ), 'The sitemap SQL must contain the scoped visibility predicate.' );

	$ordinary_query = new WP_Query( $args );
	$ordinary_ids   = array_map( 'intval', $ordinary_query->posts );
	$assert( in_array( $fixture_ids['noindex'], $ordinary_ids, true ), 'EasyRankly must not alter ordinary WordPress queries.' );
} finally {
	$cleanup();
}

echo "EasyRankly sitemap query smoke test passed.\n";
