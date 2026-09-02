<?php
/**
 * Custom code variables smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/custom-code-variables-smoke.php
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

$post_title    = 'Fixture variabili EasyRankly';
$post_excerpt  = 'Riassunto "critico" & <b>pericoloso</b> "> fine.';
$clean_excerpt = 'Riassunto "critico" & pericoloso "> fine.';

$post_id = wp_insert_post(
	array(
		'post_excerpt' => $post_excerpt,
		'post_status'  => 'publish',
		'post_title'   => $post_title,
		'post_type'    => 'post',
	),
	true
);

if ( is_wp_error( $post_id ) ) {
	throw new RuntimeException( $post_id->get_error_message() );
}

$fixture_post_ids[] = $post_id;
$bare_id            = wp_insert_post(
	array(
		'post_excerpt' => '',
		'post_status'  => 'publish',
		'post_title'   => 'Fixture senza riassunto',
		'post_type'    => 'post',
	),
	true
);

if ( is_wp_error( $bare_id ) ) {
	throw new RuntimeException( $bare_id->get_error_message() );
}

$fixture_post_ids[] = $bare_id;

$global_head_code       = '';
$global_body_start_code = '';
$tagline                = 'Motto del sito di prova';
$meta_overrides         = array();

$global_head_filter = static function () use ( &$global_head_code ) {
	return $global_head_code;
};

$global_body_start_filter = static function () use ( &$global_body_start_code ) {
	return $global_body_start_code;
};

$tagline_filter = static function () use ( &$tagline ) {
	return $tagline;
};

$blog_public_filter = static function () {
	return '1';
};

$metadata_filter = static function ( $value, $object_id, $meta_key ) use ( &$meta_overrides ) {
	$key = (int) $object_id . '|' . $meta_key;

	return array_key_exists( $key, $meta_overrides ) ? $meta_overrides[ $key ] : $value;
};

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

$prepare_singular = static function ( $id ) use ( $reset_request_caches ) {
	$post = get_post( $id );

	if ( ! $post ) {
		throw new RuntimeException( 'The requested test post no longer exists.' );
	}

	$query = new WP_Query(
		array(
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'p'                   => $id,
			'post_status'         => 'publish',
			'post_type'           => $post->post_type,
		)
	);

	$query->is_404      = false;
	$query->is_page     = false;
	$query->is_preview  = false;
	$query->is_single   = true;
	$query->is_singular = true;
	$query->set( 'page', 0 );
	$query->set( 'paged', 0 );

	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = $post;
	setup_postdata( $post );
	$reset_request_caches();
};

$render_head = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_head_code();

	return ob_get_clean();
};

$render_description_and_head = static function () use ( $reset_request_caches ) {
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	ERankly_Plugin::print_head_code();

	return ob_get_clean();
};

$get_meta_content = static function ( $markup, $key ) {
	$processor = new WP_HTML_Tag_Processor( $markup );

	while ( $processor->next_tag( array( 'tag_name' => 'META' ) ) ) {
		$name = $processor->get_attribute( 'name' );

		if ( ! is_string( $name ) ) {
			$name = $processor->get_attribute( 'property' );
		}

		if ( is_string( $name ) && strtolower( $name ) === $key ) {
			$content = $processor->get_attribute( 'content' );

			return is_string( $content ) ? $content : '';
		}
	}

	return null;
};

$get_title_text = static function ( $markup ) {
	$processor = new WP_HTML_Tag_Processor( $markup );

	while ( $processor->next_tag( array( 'tag_name' => 'TITLE' ) ) ) {
		return $processor->get_modifiable_text();
	}

	return null;
};

$count_tags = static function ( $markup, $tag ) {
	$processor = new WP_HTML_Tag_Processor( $markup );
	$found     = 0;

	while ( $processor->next_tag( array( 'tag_name' => $tag ) ) ) {
		++$found;
	}

	return $found;
};

add_filter( 'pre_option_erankly_global_code', $global_head_filter );
add_filter( 'pre_option_erankly_global_body_start_code', $global_body_start_filter );
add_filter( 'pre_option_blogdescription', $tagline_filter );
add_filter( 'pre_option_blog_public', $blog_public_filter );
add_filter( 'get_post_metadata', $metadata_filter, 10, 3 );

$normalize = static function ( $value ) {
	$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value, true ) ) );

	return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
};

try {
	$site_name   = $normalize( get_bloginfo( 'name' ) );
	$clean_title = $normalize( get_the_title( $post_id ) );

	$assert( $clean_excerpt === $normalize( $post_excerpt ), 'The excerpt fixture must normalize to the expected plain text.' );

	// Substitution in attributes, with context-aware escaping.
	$global_head_code = '<meta name="erankly-test-title" content="{{title}} - {{siteName}}">';
	$prepare_singular( $post_id );
	$head = $render_head();

	$assert(
		$clean_title . ' - ' . $site_name === $get_meta_content( $head, 'erankly-test-title' ),
		'An attribute variable must resolve to the content title and the site name.'
	);
	$assert(
		false === strpos( $head, '{{' ),
		'A resolved template must leave no token behind.'
	);

	// Escaping: the excerpt is stored raw, so it reaches the head unfiltered.
	$global_head_code = '<meta name="erankly-test-escape" content="{{excerpt}}">';
	$prepare_singular( $post_id );
	$head = $render_head();

	$assert(
		$clean_excerpt === $get_meta_content( $head, 'erankly-test-escape' ),
		'A hostile value must survive escaping and decode back to itself.'
	);
	$assert(
		false !== strpos( $head, '&quot;critico&quot;' )
		&& false !== strpos( $head, '&amp;' )
		&& false !== strpos( $head, '&quot;&gt;' ),
		'Quotes, ampersands and brackets must be escaped inside the attribute.'
	);
	$assert(
		1 === $count_tags( $head, 'META' )
		&& 0 === $count_tags( $head, 'SCRIPT' )
		&& 0 === $count_tags( $head, 'B' ),
		'Content values must never introduce markup or break out of their tag.'
	);

	// Titles claim ownership after resolution.
	$global_head_code = '<title>{{title}} - {{siteName}}</title>';
	$prepare_singular( $post_id );
	$reset_request_caches();
	add_action( 'wp_head', '_wp_render_title_tag', 1 );
	ERankly_Plugin::prepare_request_ownership();

	$assert(
		false === has_action( 'wp_head', '_wp_render_title_tag' ),
		'A templated title must take ownership of the document title.'
	);

	// Block themes swap core's renderer while locating the template.
	add_action( 'wp_head', '_block_template_render_title_tag', 1 );
	ERankly_Plugin::claim_title_ownership();

	$assert(
		false === has_action( 'wp_head', '_block_template_render_title_tag' ),
		'A templated title must also take ownership of the block theme title tag.'
	);

	$head = $render_head();

	$assert(
		$clean_title . ' - ' . $site_name === $get_title_text( $head ),
		'A templated title must print the resolved value.'
	);

	// JSON-LD blocks resolve with JSON string escaping.
	$global_head_code = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Thing","name":"{{title}}","description":"{{excerpt}}"}</script>';
	$prepare_singular( $post_id );
	$head = $render_head();
	$data = $decode_graph( $head );

	$assert( is_array( $data ), 'A templated JSON-LD block must stay valid JSON.' );
	$assert(
		isset( $data['name'] ) && $clean_title === $data['name'],
		'A JSON-LD variable must decode back to the original value.'
	);
	$assert(
		isset( $data['description'] ) && $clean_excerpt === $data['description'],
		'A hostile JSON-LD value must decode back to the original value.'
	);
	$assert(
		1 === $count_tags( $head, 'SCRIPT' ),
		'A JSON-LD value must not break out of its script element.'
	);

	// Fallback chains.
	$global_head_code = '<meta name="erankly-test-fallback" content="{{excerpt|siteDescription|&quot;Testo fisso&quot;}}">';
	$prepare_singular( $post_id );

	$assert(
		$clean_excerpt === $get_meta_content( $render_head(), 'erankly-test-fallback' ),
		'A fallback chain must use the first value that is not empty.'
	);

	$prepare_singular( $bare_id );

	$assert(
		$tagline === $get_meta_content( $render_head(), 'erankly-test-fallback' ),
		'A fallback chain must fall through to the next variable.'
	);

	$tagline = '';
	$prepare_singular( $bare_id );

	$assert(
		'Testo fisso' === $get_meta_content( $render_head(), 'erankly-test-fallback' ),
		'A fallback chain must end on its literal value.'
	);

	$tagline = 'Motto del sito di prova';

	// An exhausted chain drops the tag and hands the request back to EasyRankly.
	$meta_overrides   = array( $bare_id . '|erankly_meta_description' => 'Descrizione automatica EasyRankly' );
	$global_head_code = '<meta name="description" content="{{excerpt}}">';
	$prepare_singular( $bare_id );
	$head = $render_description_and_head();

	$assert(
		false === strpos( $head, 'erankly-omit-' ),
		'The omission sentinel must never reach the front end.'
	);
	$assert(
		1 === $count_tags( $head, 'META' ),
		'An emptied template tag must be dropped instead of printed empty.'
	);
	$assert(
		'Descrizione automatica EasyRankly' === $get_meta_content( $head, 'description' ),
		'A dropped template tag must give the automatic description back its ownership.'
	);

	$prepare_singular( $post_id );
	$head = $render_description_and_head();

	$assert(
		1 === $count_tags( $head, 'META' ) && $clean_excerpt === $get_meta_content( $head, 'description' ),
		'A resolved description template must still own the description.'
	);

	$meta_overrides = array();

	// Unknown tokens stay literal.
	$global_head_code = '<meta name="erankly-test-unknown" content="{{nonEsiste}}">';
	$prepare_singular( $post_id );

	$assert(
		'{{nonEsiste}}' === $get_meta_content( $render_head(), 'erankly-test-unknown' ),
		'An unrecognized token must be left untouched.'
	);

	// Per-content code resolves too.
	$global_head_code = '';
	$meta_overrides   = array( $post_id . '|erankly_code' => '<meta name="erankly-test-post" content="{{title}}">' );
	$prepare_singular( $post_id );

	$assert(
		$clean_title === $get_meta_content( $render_head(), 'erankly-test-post' ),
		'Variables must resolve in per-content code as well.'
	);

	$meta_overrides = array();

	// Body code resolves through the same engine.
	$global_body_start_code = '<span data-erankly-test="{{title}}"></span>';
	$prepare_singular( $post_id );
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_body_start_code();
	$body = ob_get_clean();

	$assert(
		false !== strpos( $body, 'data-erankly-test="' ) && false === strpos( $body, '{{' ),
		'Body start code must resolve its variables.'
	);

	$global_body_start_code = '';

	// Code without a token is returned untouched.
	$global_head_code = "<meta name=\"erankly-test-static\" content=\"A &amp; B\">\n<!-- commento -->";
	$prepare_singular( $post_id );
	$head = $render_head();

	$assert(
		trim( $head ) === $global_head_code,
		'Code without a variable must be printed byte for byte.'
	);
} finally {
	remove_filter( 'pre_option_erankly_global_code', $global_head_filter );
	remove_filter( 'pre_option_erankly_global_body_start_code', $global_body_start_filter );
	remove_filter( 'pre_option_blogdescription', $tagline_filter );
	remove_filter( 'pre_option_blog_public', $blog_public_filter );
	remove_filter( 'get_post_metadata', $metadata_filter, 10 );
	add_action( 'wp_head', '_wp_render_title_tag', 1 );

	$GLOBALS['post']     = $original_post;
	$GLOBALS['wp_query'] = $original_wp_query;
	wp_reset_postdata();
	$reset_request_caches();
	$cleanup_fixtures();
}

echo "Custom code variables smoke test passed.\n";
