<?php
/**
 * Metadata ownership and anti-duplication smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/metadata-ownership-smoke.php
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

$global_head_code = '';
$head_filter      = static function () use ( &$global_head_code ) {
	return $global_head_code;
};
$tagline_filter = static function () {
	return 'A WordPress-owned archive description';
};
$identity_filter = static function () {
	return array(
		'person_user_id' => 0,
		'type'           => 'organization',
	);
};
$show_on_front_filter = static function () {
	return 'posts';
};
$blog_public_filter = static function () {
	return '1';
};

$render_graph = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_schema_graph();

	return ob_get_clean();
};

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

add_filter( 'pre_option_erankly_global_code', $head_filter );
add_filter( 'pre_option_blogdescription', $tagline_filter );
add_filter( 'pre_option_erankly_site_identity', $identity_filter );
add_filter( 'pre_option_show_on_front', $show_on_front_filter );
add_filter( 'pre_option_blog_public', $blog_public_filter );

try {
	$query              = new WP_Query();
	$query->is_404      = false;
	$query->is_home     = true;
	$query->is_page     = false;
	$query->is_singular = false;
	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = null;

	$graph    = $decode_graph( $render_graph() );
	$websites = $find_nodes( $graph, 'WebSite' );
	$identity = $find_nodes( $graph, 'Organization' );

	$assert( 1 === count( $websites ), 'The front page must contain exactly one automatic WebSite node.' );
	$assert( 1 === count( $identity ), 'The front page must contain exactly one site identity node.' );
	$assert( home_url( '/#website' ) === $websites[0]['@id'], 'WebSite must use a stable ID.' );
	$assert( array( '@id' => home_url( '/#identity' ) ) === $websites[0]['publisher'], 'WebSite must reference the identity node.' );
	$assert( ! isset( $websites[0]['potentialAction'] ), 'Minimal WebSite schema must not invent SearchAction.' );
	$assert_unique_schema_ids( $graph );

	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	$description = ob_get_clean();
	$assert( false !== strpos( $description, 'A WordPress-owned archive description' ), 'Latest-posts home must use the WordPress tagline.' );

	$global_head_code = '<script type="application/ld+json">{"@type":"WebSite","@id":"https://example.com/#manual-site"}</script>';
	$assert( '' === $render_graph(), 'Manual WebSite JSON-LD must fully replace automatic WebSite output.' );

	$global_head_code = '<script type="application/ld+json">{"@type":"Organization","@id":"https://example.com/#manual-publisher"}</script>';
	$graph            = $decode_graph( $render_graph() );
	$websites         = $find_nodes( $graph, 'WebSite' );
	$assert( 1 === count( $websites ), 'Manual identity must not suppress the distinct WebSite node.' );
	$assert( array( '@id' => 'https://example.com/#manual-publisher' ) === $websites[0]['publisher'], 'Automatic schema must reference the manual identity ID.' );
	$assert( 0 === count( $find_nodes( $graph, 'Organization' ) ), 'Manual Organization must suppress automatic Organization output.' );

	$global_head_code = '<script type="application/ld+json">{"@type":"Organization","name":"Manual publisher"}</script>';
	$graph            = $decode_graph( $render_graph() );
	$websites         = $find_nodes( $graph, 'WebSite' );
	$assert( ! isset( $websites[0]['publisher'] ), 'A manual identity without an ID must suppress the automatic publisher reference.' );

	$global_head_code = '<script type="application/ld+json">{"@type":"Thing","author":{"@type":"Person","@id":"https://example.com/#nested-author"}}</script>';
	$graph            = $decode_graph( $render_graph() );
	$websites         = $find_nodes( $graph, 'WebSite' );
	$identity         = $find_nodes( $graph, 'Organization' );
	$assert( 1 === count( $identity ), 'A nested Person must not claim top-level site identity ownership.' );
	$assert( array( '@id' => home_url( '/#identity' ) ) === $websites[0]['publisher'], 'A nested schema ID must not replace the site identity reference.' );
	$assert_unique_schema_ids( $graph );

	$global_head_code = '<script type="application/ld+json">{"@graph":[{"@type":"Organization","@id":"https://example.com/#graph-publisher"}]}</script>';
	$graph            = $decode_graph( $render_graph() );
	$websites         = $find_nodes( $graph, 'WebSite' );
	$assert( array( '@id' => 'https://example.com/#graph-publisher' ) === $websites[0]['publisher'], 'A top-level @graph node must retain ownership.' );
	$assert( 0 === count( $find_nodes( $graph, 'Organization' ) ), 'A top-level @graph identity must suppress the automatic identity.' );

	$global_head_code = '<meta name="description" content="Manual archive description">';
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	ERankly_Plugin::print_head_code();
	$head = ob_get_clean();
	$assert( 1 === preg_match_all( '/<meta name="description" content=/', $head ), 'Manual non-singular description must print exactly once.' );

	$global_head_code = '<meta name="description" content="{{description}}">';
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	ERankly_Plugin::print_head_code();
	$head = ob_get_clean();
	$assert( 1 === preg_match_all( '/<meta name="description" content=/', $head ), 'A templated non-singular description must print exactly once.' );
	$assert( false !== strpos( $head, 'A WordPress-owned archive description' ), 'A templated non-singular description must resolve the archive value.' );

	$global_head_code = '<meta name="description" content="{{searchQuery}}">';
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	ERankly_Plugin::print_head_code();
	$head = ob_get_clean();
	$assert( 1 === preg_match_all( '/<meta name="description" content=/', $head ), 'An emptied description template must hand ownership back exactly once.' );
	$assert( false !== strpos( $head, 'A WordPress-owned archive description' ), 'An emptied description template must restore the automatic description.' );

} finally {
	remove_filter( 'pre_option_erankly_global_code', $head_filter );
	remove_filter( 'pre_option_blogdescription', $tagline_filter );
	remove_filter( 'pre_option_erankly_site_identity', $identity_filter );
	remove_filter( 'pre_option_show_on_front', $show_on_front_filter );
	remove_filter( 'pre_option_blog_public', $blog_public_filter );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}
}

echo "EasyRankly metadata ownership smoke test passed.\n";
