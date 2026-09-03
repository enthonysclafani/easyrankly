<?php
/**
 * Taxonomy term noindex smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/taxonomy-noindex-smoke.php
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

$registered = get_registered_meta_keys( 'term', 'category' );
$assert( isset( $registered['erankly_visibility'] ), 'erankly_visibility must be registered for category terms.' );

$fixture_term_ids = array();
$cleanup          = static function () use ( &$fixture_term_ids ) {
	foreach ( $fixture_term_ids as $fixture_term_id ) {
		wp_delete_term( $fixture_term_id, 'category' );
	}

	$fixture_term_ids = array();
};

register_shutdown_function( $cleanup );

$suffix    = wp_generate_uuid4();
$indexable = wp_insert_term( 'EasyRankly indexable term ' . $suffix, 'category' );

if ( is_wp_error( $indexable ) ) {
	throw new RuntimeException( $indexable->get_error_message() );
}

$indexable_id       = (int) $indexable['term_id'];
$fixture_term_ids[] = $indexable_id;
$noindex            = wp_insert_term( 'EasyRankly noindex term ' . $suffix, 'category' );

if ( is_wp_error( $noindex ) ) {
	throw new RuntimeException( $noindex->get_error_message() );
}

$noindex_id         = (int) $noindex['term_id'];
$fixture_term_ids[] = $noindex_id;
update_term_meta( $noindex_id, 'erankly_visibility', 'noindex' );

$indexable_term    = get_term( $indexable_id, 'category' );
$noindex_term      = get_term( $noindex_id, 'category' );
$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$global_head_code  = '';
$head_filter       = static function () use ( &$global_head_code ) {
	return $global_head_code;
};
$prepare_term_query = static function ( $term ) use ( $reset_request_caches ) {
	$query                      = new WP_Query();
	$query->is_404              = false;
	$query->is_archive          = true;
	$query->is_category         = true;
	$query->is_home             = false;
	$query->is_singular         = false;
	$query->queried_object      = $term;
	$query->queried_object_id   = (int) $term->term_id;
	$GLOBALS['wp_query']        = $query;
	$GLOBALS['post']            = null;
	$reset_request_caches();
};

add_filter( 'pre_option_erankly_global_code', $head_filter );

try {
	$prepare_term_query( $noindex_term );
	$robots = ERankly_Plugin::filter_robots( array( 'index' => true ) );
	$assert( array( 'noindex' => true ) === $robots, 'Noindex term archives must replace index with noindex.' );
	$headers = ERankly_Plugin::filter_robots_headers( array() );
	$assert( isset( $headers['X-Robots-Tag'] ) && false !== strpos( $headers['X-Robots-Tag'], 'noindex' ), 'Noindex term archives must emit an X-Robots-Tag header containing noindex.' );

	$global_head_code = '<meta name="robots" content="index,follow">';
	$reset_request_caches();
	$input = array( 'index' => true );
	$assert( $input === ERankly_Plugin::filter_robots( $input ), 'A manual robots meta tag must retain ownership of term archive robots output.' );

	$global_head_code = '';
	$prepare_term_query( $indexable_term );
	$input = array( 'index' => true );
	$assert( $input === ERankly_Plugin::filter_robots( $input ), 'Indexable term archives must leave robots directives unchanged.' );
	$headers = ERankly_Plugin::filter_robots_headers( array() );
	$assert( ! isset( $headers['X-Robots-Tag'] ), 'Indexable term archives must not add an X-Robots-Tag header.' );

	$args = ERankly_Plugin::filter_sitemap_taxonomy_query_args(
		array(
			'taxonomy' => 'category',
		),
		'category'
	);
	$assert( ! empty( $args['_erankly_exclude_noindex'] ), 'Taxonomy sitemap queries must carry the private noindex exclusion flag.' );

	$args['fields']     = 'ids';
	$args['hide_empty'] = false;
	$args['include']    = array( $indexable_id, $noindex_id );
	$query              = new WP_Term_Query( $args );
	$ids                = array_map( 'intval', (array) $query->terms );

	$assert( in_array( $indexable_id, $ids, true ), 'Indexable terms must remain in the taxonomy sitemap.' );
	$assert( ! in_array( $noindex_id, $ids, true ), 'Noindex terms must be excluded from the taxonomy sitemap.' );
	$assert( false !== strpos( $query->request, 'NOT EXISTS' ), 'Taxonomy sitemap exclusion must use an indexed anti-lookup.' );
	$assert( false !== strpos( $query->request, 'erankly_visibility_termmeta' ), 'The taxonomy sitemap SQL must contain the scoped visibility predicate.' );

	$ordinary_query = new WP_Term_Query(
		array(
			'fields'     => 'ids',
			'hide_empty' => false,
			'include'    => array( $indexable_id, $noindex_id ),
			'taxonomy'   => 'category',
		)
	);
	$ordinary_ids = array_map( 'intval', (array) $ordinary_query->terms );
	$assert( in_array( $indexable_id, $ordinary_ids, true ), 'EasyRankly must not exclude indexable terms from ordinary term queries.' );
	$assert( in_array( $noindex_id, $ordinary_ids, true ), 'EasyRankly must not alter ordinary WordPress term queries.' );
} finally {
	remove_filter( 'pre_option_erankly_global_code', $head_filter );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;
	$cleanup();
}

echo "EasyRankly taxonomy noindex smoke test passed.\n";
