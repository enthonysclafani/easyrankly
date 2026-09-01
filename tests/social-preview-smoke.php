<?php
/**
 * Social metadata regression smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/social-preview-smoke.php
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

$assert( '' === ERankly_Plugin::sanitize_social_url( '/relative-image.jpg' ), 'Relative social image URLs must be rejected.' );
$assert( '' === ERankly_Plugin::sanitize_social_url( 'ftp://example.com/image.jpg' ), 'Non-HTTP(S) social image URLs must be rejected.' );
$assert( '' === ERankly_Plugin::sanitize_social_url( 'mailto:test@example.com' ), 'Non-web social image URLs must be rejected.' );
$assert( 'https://example.com/image.jpg' === ERankly_Plugin::sanitize_social_url( 'https://example.com/image.jpg' ), 'Absolute HTTPS social image URLs must be retained.' );
$assert( '<meta name="description" content="body">' === ERankly_Plugin::sanitize_raw_code( " \0<meta name=\"description\" content=\"body\"> " ), 'Body code must preserve intentional trusted markup while removing NUL bytes.' );
$assert( '' === ERankly_Plugin::sanitize_head_code( '<meta name="description" content="managed">' ), 'Head code must still defer description metadata to the managed field.' );
$assert( 0 === has_action( 'wp_body_open', array( 'ERankly_Plugin', 'print_body_start_code' ) ), 'Body start code must use wp_body_open at the earliest standard priority.' );
$assert( 100 === has_action( 'wp_footer', array( 'ERankly_Plugin', 'print_body_end_code' ) ), 'Body end code must run after WordPress footer scripts.' );

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
	throw new RuntimeException( 'A published post is required for the social preview smoke test.' );
}

$image_attachments = get_posts(
	array(
		'fields'         => 'ids',
		'numberposts'    => 1,
		'order'          => 'ASC',
		'orderby'        => 'ID',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'post_type'      => 'attachment',
	)
);

if ( empty( $image_attachments ) ) {
	throw new RuntimeException( 'A Media Library image is required for the social preview smoke test.' );
}

$post_id           = (int) $published_posts[0];
$active_post_id    = $post_id;
$default_image_id  = (int) $image_attachments[0];
$default_image_url = wp_get_attachment_image_url( $default_image_id, 'full' );
$default_image_alt = 'Default image alt';

if ( ! is_string( $default_image_url ) || '' === $default_image_url ) {
	throw new RuntimeException( 'The test Media Library image must have a full-size URL.' );
}

$base_meta = array(
	'erankly_code'                => '',
	'erankly_body_end_code'       => '',
	'erankly_body_start_code'     => '',
	'erankly_meta_description'    => 'Automatic WordPress description',
	'erankly_social_description'  => 'Ignored legacy social description',
	'erankly_social_image'        => 'https://example.com/ignored-generic-image.jpg',
	'erankly_social_image_alt'    => 'Ignored legacy generic alt',
	'erankly_social_title'        => 'Ignored legacy social title',
	'erankly_twitter_card'        => 'summary_large_image',
	'erankly_twitter_description' => 'Ignored legacy X description',
	'erankly_twitter_image'       => 'https://example.com/ignored-x-image.jpg',
	'erankly_twitter_image_alt'   => 'Ignored legacy X alt',
	'erankly_twitter_title'       => 'Ignored legacy X title',
	'_thumbnail_id'                     => 0,
);
$meta_overrides         = $base_meta;
$social_settings        = array( 'default_image_id' => 0 );
$global_head_code       = '';
$global_body_end_code   = '';
$global_body_start_code = '';

$metadata_filter = static function ( $value, $object_id, $meta_key, $single ) use ( &$active_post_id, &$default_image_alt, $default_image_id, &$meta_overrides ) {
	if ( $active_post_id === (int) $object_id && array_key_exists( $meta_key, $meta_overrides ) ) {
		return $meta_overrides[ $meta_key ];
	}

	if ( $default_image_id === (int) $object_id && '_wp_attachment_image_alt' === $meta_key ) {
		return $default_image_alt;
	}

	return $value;
};

$original_post     = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

$prepare_singular = static function ( $id, $status, $page = 1 ) {
	$post = get_post( $id );

	if ( ! $post ) {
		throw new RuntimeException( 'The requested test post no longer exists.' );
	}

	$query = new WP_Query(
		array(
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'p'                   => $id,
			'post_status'         => $status,
			'post_type'           => $post->post_type,
		)
	);

	$query->is_404      = false;
	$query->is_page     = 'page' === $post->post_type;
	$query->is_preview  = false;
	$query->is_single   = 'post' === $post->post_type;
	$query->is_singular = true;
	$query->set( 'page', $page );
	$query->set( 'paged', 0 );

	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = $post;
	setup_postdata( $post );
};

$render_social = static function () {
	ob_start();
	ERankly_Plugin::print_social_preview();

	return ob_get_clean();
};

$render_social_and_head = static function () {
	ob_start();
	ERankly_Plugin::print_social_preview();
	ERankly_Plugin::print_head_code();

	return ob_get_clean();
};

$get_meta_content = static function ( $markup, $attribute, $key ) {
	$pattern = sprintf(
		'/<meta %s="%s" content="([^"]*)">/',
		preg_quote( $attribute, '/' ),
		preg_quote( $key, '/' )
	);
	$matches = array();

	if ( 1 !== preg_match( $pattern, $markup, $matches ) ) {
		return null;
	}

	return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
};

$social_settings_filter = static function () use ( &$social_settings ) {
	return $social_settings;
};

$global_head_filter = static function () use ( &$global_head_code ) {
	return $global_head_code;
};

$global_body_start_filter = static function () use ( &$global_body_start_code ) {
	return $global_body_start_code;
};

$global_body_end_filter = static function () use ( &$global_body_end_code ) {
	return $global_body_end_code;
};

add_filter( 'get_post_metadata', $metadata_filter, 10, 4 );
add_filter( 'pre_option_erankly_global_code', $global_head_filter );
add_filter( 'pre_option_erankly_global_body_start_code', $global_body_start_filter );
add_filter( 'pre_option_erankly_global_body_end_code', $global_body_end_filter );
add_filter( 'pre_option_erankly_social_settings', $social_settings_filter );

try {
	$prepare_singular( $post_id, 'publish', 2 );
	$canonical      = wp_get_canonical_url( $post_id );
	$preview        = $render_social();
	$matches        = array();
	$expected_title = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( get_the_title( $post_id ), true ) ) );

	$assert( is_string( $canonical ) && '' !== $canonical, 'A paginated canonical URL must be available.' );
	$assert( 1 === preg_match( '/<meta property="og:url" content="([^"]+)">/', $preview, $matches ), 'The Open Graph URL tag is missing.' );
	$assert( $canonical === html_entity_decode( $matches[1], ENT_QUOTES, get_bloginfo( 'charset' ) ), 'The Open Graph URL must match WordPress canonical pagination.' );
	$assert( $expected_title === $get_meta_content( $preview, 'property', 'og:title' ), 'Open Graph title must use the WordPress content title.' );
	$assert( $expected_title === $get_meta_content( $preview, 'name', 'twitter:title' ), 'X title must use the WordPress content title.' );
	$assert( 'Automatic WordPress description' === $get_meta_content( $preview, 'property', 'og:description' ), 'Open Graph description must use the WordPress description field.' );
	$assert( 'Automatic WordPress description' === $get_meta_content( $preview, 'name', 'twitter:description' ), 'X description must use the WordPress description field.' );
	$assert( null === $get_meta_content( $preview, 'property', 'og:image' ), 'Legacy social image meta must no longer override WordPress data.' );
	$assert( null === $get_meta_content( $preview, 'name', 'twitter:image' ), 'Legacy X image meta must no longer override WordPress data.' );
	$assert( 'summary' === $get_meta_content( $preview, 'name', 'twitter:card' ), 'X must use Summary when no image exists.' );
	$assert( false === strpos( $preview, 'Ignored legacy' ), 'Legacy social text meta must be ignored.' );
	$assert( null === $get_meta_content( $preview, 'property', 'fb:app_id' ), 'A Facebook app ID must not be emitted.' );
	$assert( null === $get_meta_content( $preview, 'name', 'twitter:site' ), 'A site-wide X account must not be emitted.' );

	$registered_meta = get_registered_meta_keys( 'post', get_post_type( $post_id ) );
	$legacy_keys     = array(
		'erankly_social_title',
		'erankly_social_description',
		'erankly_social_image',
		'erankly_social_image_alt',
		'erankly_twitter_title',
		'erankly_twitter_description',
		'erankly_twitter_image',
		'erankly_twitter_image_alt',
		'erankly_twitter_card',
	);

	foreach ( $legacy_keys as $legacy_key ) {
		$assert( ! isset( $registered_meta[ $legacy_key ] ), sprintf( 'Legacy social field %s must not be registered.', $legacy_key ) );
	}

	$assert( isset( $registered_meta['erankly_body_start_code'] ), 'Body start code must be registered as post meta.' );
	$assert( isset( $registered_meta['erankly_body_end_code'] ), 'Body end code must be registered as post meta.' );

	$assert(
		array( 'default_image_id' => $default_image_id ) === ERankly_Plugin::sanitize_social_settings( array( 'default_image_id' => (string) $default_image_id ) ),
		'The default image setting must retain a valid image attachment ID.'
	);
	$assert(
		array( 'default_image_id' => 0 ) === ERankly_Plugin::sanitize_social_settings( array( 'default_image_id' => $post_id ) ),
		'The default image setting must reject a non-image post ID.'
	);
	$assert(
		array( 'default_image_id' => $default_image_id ) === ERankly_Plugin::sanitize_social_settings( array( 'default_image_url' => $default_image_url ) ),
		'A legacy local image URL must migrate to its attachment ID.'
	);

	$social_settings = array( 'default_image_id' => $default_image_id );
	$prepare_singular( $post_id, 'publish' );
	$preview = $render_social();

	$assert( $default_image_url === $get_meta_content( $preview, 'property', 'og:image' ), 'Open Graph must use the resolved fallback image.' );
	$assert( $default_image_url === $get_meta_content( $preview, 'name', 'twitter:image' ), 'X must use the same resolved fallback image.' );
	$assert( 'Default image alt' === $get_meta_content( $preview, 'property', 'og:image:alt' ), 'Open Graph must use the resolved image alt text.' );
	$assert( 'Default image alt' === $get_meta_content( $preview, 'name', 'twitter:image:alt' ), 'X must use the same resolved image alt text.' );
	$assert( 'summary_large_image' === $get_meta_content( $preview, 'name', 'twitter:card' ), 'X must use Summary with large image when an image exists.' );

	$default_image_alt = 'Updated Media Library alt';
	$preview           = $render_social();
	$assert( 'Updated Media Library alt' === $get_meta_content( $preview, 'property', 'og:image:alt' ), 'Open Graph alt text must follow Media Library changes.' );
	$assert( 'Updated Media Library alt' === $get_meta_content( $preview, 'name', 'twitter:image:alt' ), 'X alt text must follow Media Library changes.' );

	$meta_overrides = array_merge(
		$base_meta,
		array(
			'erankly_code' => "<meta property=\"og:title\" content=\"Manual Open Graph title\">\n<meta name=\"twitter:card\" content=\"summary\">",
		)
	);
	$prepare_singular( $post_id, 'publish' );
	$preview = $render_social_and_head();

	$assert( 1 === preg_match_all( '/<meta property="og:title" content=/', $preview, $matches ), 'A manual Open Graph title must replace the automatic one exactly once.' );
	$assert( 'Manual Open Graph title' === $get_meta_content( $preview, 'property', 'og:title' ), 'The EasyRankly Custom code Open Graph title must win.' );
	$assert( 1 === preg_match_all( '/<meta name="twitter:card" content=/', $preview, $matches ), 'A manual X card must replace the automatic one exactly once.' );
	$assert( 'summary' === $get_meta_content( $preview, 'name', 'twitter:card' ), 'The EasyRankly Custom code X card must win.' );

	$global_head_code = '<meta property="og:title" content="Global Open Graph title">';
	$meta_overrides   = $base_meta;
	$prepare_singular( $post_id, 'publish' );
	$preview = $render_social_and_head();

	$assert( 1 === preg_match_all( '/<meta property="og:title" content=/', $preview, $matches ), 'A global Open Graph title must replace the automatic one exactly once.' );
	$assert( 'Global Open Graph title' === $get_meta_content( $preview, 'property', 'og:title' ), 'The global EasyRankly Custom code Open Graph title must win.' );

	$meta_overrides['erankly_code'] = '<meta name="per-content-only" content="yes">';
	$prepare_singular( $post_id, 'publish' );

	ob_start();
	ERankly_Plugin::print_head_code();
	$head            = ob_get_clean();
	$global_position = strpos( $head, 'Global Open Graph title' );
	$post_position   = strpos( $head, 'per-content-only' );

	$assert( false !== $global_position && false !== $post_position && $global_position < $post_position, 'Global Head code must print before per-content code.' );

	$global_body_start_code                          = '<div id="global-body-start"></div>';
	$global_body_end_code                            = '<script id="global-body-end"></script>';
	$meta_overrides['erankly_body_start_code'] = '<div id="post-body-start"></div>';
	$meta_overrides['erankly_body_end_code']   = '<script id="post-body-end"></script>';
	$body_locations = array(
		array(
			'global' => 'global-body-start',
			'method' => 'print_body_start_code',
			'post'   => 'post-body-start',
		),
		array(
			'global' => 'global-body-end',
			'method' => 'print_body_end_code',
			'post'   => 'post-body-end',
		),
	);

	foreach ( $body_locations as $location ) {
		ob_start();
		call_user_func( array( 'ERankly_Plugin', $location['method'] ) );
		$body            = ob_get_clean();
		$global_position = strpos( $body, $location['global'] );
		$post_position   = strpos( $body, $location['post'] );

		$assert( false !== $global_position && false !== $post_position && $global_position < $post_position, 'Global body code must print before per-content code.' );
	}

	$query                                = new WP_Query();
	$query->is_404                        = false;
	$query->is_home                       = true;
	$query->is_singular                   = false;
	$GLOBALS['wp_query']                  = $query;

	ob_start();
	ERankly_Plugin::print_head_code();
	$head = ob_get_clean();

	$assert( false !== strpos( $head, 'Global Open Graph title' ), 'Global Head code must print on non-singular front-end views.' );
	$assert( false === strpos( $head, 'per-content-only' ), 'Per-content Head code must remain limited to singular views.' );

	foreach ( $body_locations as $location ) {
		ob_start();
		call_user_func( array( 'ERankly_Plugin', $location['method'] ) );
		$body = ob_get_clean();

		$assert( false !== strpos( $body, $location['global'] ), 'Global body code must print on non-singular front-end views.' );
		$assert( false === strpos( $body, $location['post'] ), 'Per-content body code must remain limited to singular views.' );
	}

	$global_head_code = '';

	$draft_posts = get_posts(
		array(
			'fields'           => 'ids',
			'numberposts'      => 1,
			'post_status'      => 'draft',
			'post_type'        => 'any',
			'suppress_filters' => false,
		)
	);

	if ( ! empty( $draft_posts ) ) {
		$active_post_id = (int) $draft_posts[0];
		$meta_overrides = $base_meta;
		$prepare_singular( $active_post_id, 'draft' );
		$assert( '' === $render_social(), 'Draft content must not emit social metadata.' );
	}
} finally {
	remove_filter( 'get_post_metadata', $metadata_filter, 10 );
	remove_filter( 'pre_option_erankly_global_code', $global_head_filter );
	remove_filter( 'pre_option_erankly_global_body_start_code', $global_body_start_filter );
	remove_filter( 'pre_option_erankly_global_body_end_code', $global_body_end_filter );
	remove_filter( 'pre_option_erankly_social_settings', $social_settings_filter );
	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;

	if ( $original_wp_query instanceof WP_Query ) {
		$original_wp_query->reset_postdata();
	}
}

echo "EasyRankly social smoke test passed.\n";
