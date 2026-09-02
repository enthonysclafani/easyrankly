<?php
/**
 * Article JSON-LD ownership and duplication regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/article-schema-smoke.php
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

$fixture_post_ids = array();

$cleanup_fixtures = static function () use ( &$fixture_post_ids ) {
	foreach ( array_reverse( $fixture_post_ids ) as $fixture_post_id ) {
		wp_delete_post( $fixture_post_id, true );
	}

	$fixture_post_ids = array();
};

register_shutdown_function( $cleanup_fixtures );

$post_id = wp_insert_post(
	array(
		'post_status' => 'publish',
		'post_title'  => 'EasyRankly article schema fixture',
		'post_type'   => 'post',
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}

$fixture_post_ids[] = $post_id;
$meta_overrides = array(
	'erankly_code'       => '',
	'erankly_visibility' => 'index',
);

$metadata_filter = static function ( $value, $object_id, $meta_key, $single ) use ( $post_id, &$meta_overrides ) {
	if ( $post_id === (int) $object_id && array_key_exists( $meta_key, $meta_overrides ) ) {
		return $meta_overrides[ $meta_key ];
	}

	return $value;
};

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

$prepare_singular = static function ( $id ) {
	$post = get_post( $id );
	$query = new WP_Query(
		array(
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'p'                   => $id,
			'post_status'         => 'publish',
			'post_type'           => 'post',
		)
	);

	$query->is_404      = false;
	$query->is_page     = false;
	$query->is_preview  = false;
	$query->is_single   = true;
	$query->is_singular = true;

	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = $post;
	setup_postdata( $post );
};

$render_schema = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_schema_graph();

	return ob_get_clean();
};

add_filter( 'get_post_metadata', $metadata_filter, 10, 4 );
add_filter( 'pre_option_erankly_global_code', '__return_empty_string' );
add_filter( 'pre_option_erankly_business_settings', '__return_empty_array' );
add_filter(
	'pre_option_erankly_site_identity',
	static function () {
		return array(
			'person_user_id' => 0,
			'type'           => 'organization',
		);
	}
);
add_filter(
	'pre_option_erankly_social_settings',
	static function () {
		return array(
			'default_image_id' => 0,
			'profiles'         => array( 'https://x.com/EasyRankly' ),
		);
	}
);

try {
	$prepare_singular( $post_id );
	$post               = get_post( $post_id );
	$published_datetime = get_post_datetime( $post, 'date', 'local' );
	$modified_datetime  = get_post_datetime( $post, 'modified', 'local' );
	$published           = $published_datetime->format( DATE_W3C );
	$modified            = $modified_datetime->getTimestamp() > $published_datetime->getTimestamp()
		? $modified_datetime->format( DATE_W3C )
		: '';
	$modified_timestamp  = $modified_datetime->getTimestamp() > $published_datetime->getTimestamp()
		? $modified_datetime->getTimestamp()
		: 0;
	$markup              = $render_schema();
	$graph               = $decode_graph( $markup );
	$schema              = $find_node( $graph, 'BlogPosting' );
	$identity            = $find_node( $graph, 'Organization' );

	$assert( is_array( $schema ), 'Automatic Article JSON-LD is missing or invalid.' );
	$assert( 'BlogPosting' === $schema['@type'], 'Posts must use the BlogPosting type.' );
	$assert( array( '@id' => home_url( '/#identity' ) ) === $schema['publisher'], 'Article publisher must reference the stable site identity node.' );
	$assert( is_array( $identity ), 'The graph must contain the referenced site Organization.' );
	$assert( get_bloginfo( 'name' ) === $identity['name'], 'The publisher name must reuse the WordPress Site Title.' );
	$assert( array( 'https://x.com/EasyRankly' ) === $identity['sameAs'], 'The publisher must reuse site profile URLs.' );
	$assert( $published === $schema['datePublished'], 'datePublished must use the WordPress publication timestamp.' );
	$assert( (bool) preg_match( '/[+-][0-9]{2}:[0-9]{2}$/', $schema['datePublished'] ), 'datePublished must include a timezone offset.' );
	if ( '' !== $modified ) {
		$assert( isset( $schema['dateModified'] ) && $modified === $schema['dateModified'], 'dateModified must use the WordPress modification timestamp.' );
	} else {
		$assert( ! isset( $schema['dateModified'] ), 'dateModified must be omitted until a post is updated.' );
	}

	$assert( ! method_exists( ERankly_Plugin::class, 'prepend_article_dates' ), 'Themes and core blocks must own visible dates.' );

	add_filter( 'pre_option_blogname', '__return_empty_string' );
	$nameless_graph   = $decode_graph( $render_schema() );
	$nameless_article = $find_node( $nameless_graph, 'BlogPosting' );
	remove_filter( 'pre_option_blogname', '__return_empty_string' );
	$assert( is_array( $nameless_article ) && ! isset( $nameless_article['publisher'] ), 'Schema must not reference an identity node that was not emitted.' );
	$assert( null === $find_node( $nameless_graph, 'Organization' ), 'An empty site identity must not emit an Organization node.' );

	$meta_overrides['erankly_code'] = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"https://schema.org/BlogPosting"}]}</script>';
	$manual_graph = $decode_graph( $render_schema() );
	$assert( null === $find_node( $manual_graph, 'BlogPosting' ), 'Valid manual Article JSON-LD must suppress the automatic Article node.' );

	$meta_overrides['erankly_code'] = '<script type="application/ld+json">{"@type":"BlogPosting"</script>';
	$invalid_graph = $decode_graph( $render_schema() );
	$assert( null !== $find_node( $invalid_graph, 'BlogPosting' ), 'Invalid manual JSON-LD must not suppress the automatic Article node.' );

	$meta_overrides['erankly_code']       = '';
	$meta_overrides['erankly_visibility'] = 'noindex';
	$assert( '' === $render_schema(), 'Noindex posts must not emit automatic Article schema.' );
	$robots = ERankly_Plugin::filter_robots( array( 'follow' => true, 'index' => true ) );
	$assert( ! isset( $robots['index'] ) && ! empty( $robots['noindex'] ) && ! empty( $robots['follow'] ), 'Noindex posts must replace index while preserving other robots directives.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
	$assert( 'noindex' === $headers['X-Robots-Tag'], 'Noindex posts must emit an X-Robots-Tag HTTP header.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'x-robots-tag' => 'noarchive' ) );
	$assert( 'noarchive, noindex' === $headers['X-Robots-Tag'], 'The X-Robots-Tag header must retain existing directives.' );
	$headers = ERankly_Plugin::filter_robots_headers(
		array(
			'x-robots-tag' => 'noarchive',
			'X-ROBOTS-TAG' => 'nofollow',
		)
	);
	$assert( array( 'X-Robots-Tag' => 'noarchive, nofollow, noindex' ) === $headers, 'X-Robots-Tag variants must merge into one canonical header.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'x-robots-tag' => 'NoIndex, noarchive' ) );
	$assert( 'NoIndex, noarchive' === $headers['X-Robots-Tag'], 'An existing noindex directive must not be duplicated.' );

	$meta_overrides['erankly_visibility'] = 'index';
	$robots = array( 'follow' => true, 'index' => true );
	$assert( $robots === ERankly_Plugin::filter_robots( $robots ), 'Index posts must leave existing robots directives unchanged.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
	$assert( ! isset( $headers['X-Robots-Tag'] ), 'Index posts must not add an X-Robots-Tag HTTP header.' );
} finally {
	remove_filter( 'get_post_metadata', $metadata_filter, 10 );
	remove_filter( 'pre_option_erankly_global_code', '__return_empty_string' );
	remove_filter( 'pre_option_erankly_business_settings', '__return_empty_array' );
	remove_filter( 'pre_option_blogname', '__return_empty_string' );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}

	$cleanup_fixtures();
}

echo "EasyRankly Article schema smoke test passed.\n";
