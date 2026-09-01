<?php
/**
 * Article dates and JSON-LD regression smoke test.
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

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$published_posts = get_posts(
	array(
		'fields'           => 'ids',
		'numberposts'      => 1,
		'order'            => 'ASC',
		'orderby'          => 'ID',
		'post_status'      => 'publish',
		'post_type'        => 'post',
		'suppress_filters' => false,
	)
);

if ( empty( $published_posts ) ) {
	throw new RuntimeException( 'A published post is required for the Article schema smoke test.' );
}

$post_id        = (int) $published_posts[0];
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

$render_schema = static function () {
	ob_start();
	ERankly_Plugin::print_article_schema();

	return ob_get_clean();
};

$decode_schema = static function ( $markup ) {
	$matches = array();

	if ( 1 !== preg_match( '~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~is', $markup, $matches ) ) {
		return null;
	}

	$data = json_decode( trim( $matches[1] ), true );

	return is_array( $data ) ? $data : null;
};

add_filter( 'get_post_metadata', $metadata_filter, 10, 4 );
add_filter( 'pre_option_erankly_global_code', '__return_empty_string' );

try {
	$prepare_singular( $post_id );
	$post               = get_post( $post_id );
	$published_datetime = get_post_datetime( $post, 'date', 'local' );
	$modified_datetime  = get_post_datetime( $post, 'modified', 'local' );
	$published           = $published_datetime->format( DATE_W3C );
	$modified            = $modified_datetime->getTimestamp() > $published_datetime->getTimestamp()
		? $modified_datetime->format( DATE_W3C )
		: '';
	$markup              = $render_schema();
	$schema              = $decode_schema( $markup );

	$assert( is_array( $schema ), 'Automatic Article JSON-LD is missing or invalid.' );
	$assert( 'BlogPosting' === $schema['@type'], 'Posts must use the BlogPosting type.' );
	$assert( $published === $schema['datePublished'], 'datePublished must use the WordPress publication timestamp.' );
	$assert( (bool) preg_match( '/[+-][0-9]{2}:[0-9]{2}$/', $schema['datePublished'] ), 'datePublished must include a timezone offset.' );

	if ( '' !== $modified ) {
		$assert( isset( $schema['dateModified'] ) && $modified === $schema['dateModified'], 'dateModified must use the WordPress modification timestamp.' );
	} else {
		$assert( ! isset( $schema['dateModified'] ), 'dateModified must be omitted until a post is updated.' );
	}

	$parsed_block = array(
		'attrs'        => array(),
		'blockName'    => 'core/post-content',
		'innerBlocks'  => array(),
		'innerContent' => array(),
		'innerHTML'    => '',
	);
	$block = new WP_Block(
		$parsed_block,
		array(
			'postId'   => $post_id,
			'postType' => 'post',
		)
	);
	$body = ERankly_Plugin::prepend_article_dates( '<div>Article body</div>', $parsed_block, $block );

	$assert( false !== strpos( $body, 'datetime="' . esc_attr( $published ) . '"' ), 'The visible publication date must share the JSON-LD ISO value.' );

	if ( '' !== $modified ) {
		$assert( false !== strpos( $body, 'datetime="' . esc_attr( $modified ) . '"' ), 'The visible update date must share the JSON-LD ISO value.' );
	}

	$meta_overrides['erankly_code'] = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"https://schema.org/BlogPosting"}]}</script>';
	$assert( '' === $render_schema(), 'Valid manual Article JSON-LD must suppress the automatic schema.' );

	$meta_overrides['erankly_code'] = '<script type="application/ld+json">{"@type":"BlogPosting"</script>';
	$assert( '' !== $render_schema(), 'Invalid manual JSON-LD must not suppress the automatic schema.' );

	$meta_overrides['erankly_code']       = '';
	$meta_overrides['erankly_visibility'] = 'noindex';
	$assert( '' === $render_schema(), 'Noindex posts must not emit automatic Article schema.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
	$assert( 'noindex' === $headers['X-Robots-Tag'], 'Noindex posts must emit an X-Robots-Tag HTTP header.' );
	$headers = ERankly_Plugin::filter_robots_headers( array( 'x-robots-tag' => 'noarchive' ) );
	$assert( 'noarchive, noindex' === $headers['X-Robots-Tag'], 'The X-Robots-Tag header must retain existing directives.' );

	$meta_overrides['erankly_visibility'] = 'index';
	$headers                                    = ERankly_Plugin::filter_robots_headers( array( 'Content-Type' => 'text/html; charset=UTF-8' ) );
	$assert( ! isset( $headers['X-Robots-Tag'] ), 'Index posts must not add an X-Robots-Tag HTTP header.' );
	add_filter( 'erankly_article_schema_enabled', '__return_false' );
	$assert( '' === $render_schema(), 'The Article schema ownership filter must disable automatic output.' );
	remove_filter( 'erankly_article_schema_enabled', '__return_false' );
} finally {
	remove_filter( 'get_post_metadata', $metadata_filter, 10 );
	remove_filter( 'erankly_article_schema_enabled', '__return_false' );
	remove_filter( 'pre_option_erankly_global_code', '__return_empty_string' );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}
}

echo "EasyRankly Article schema smoke test passed.\n";
