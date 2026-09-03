<?php
/**
 * Block template description smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/template-description-smoke.php
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
$fixture_term_ids = array();
$cleanup          = static function () use ( &$fixture_post_ids, &$fixture_term_ids ) {
	foreach ( $fixture_post_ids as $fixture_post_id ) {
		wp_delete_post( $fixture_post_id, true );
	}

	foreach ( $fixture_term_ids as $fixture_term_id ) {
		wp_delete_term( $fixture_term_id, 'category' );
	}

	$fixture_post_ids = array();
	$fixture_term_ids = array();
};

register_shutdown_function( $cleanup );

$suffix = wp_generate_uuid4();
$slug   = 'erankly-template-desc-' . $suffix;
$id     = wp_insert_post(
	array(
		'post_name'   => $slug,
		'post_status' => 'publish',
		'post_title'  => 'EasyRankly template description fixture',
		'post_type'   => 'wp_template',
	),
	true
);

if ( is_wp_error( $id ) ) {
	throw new RuntimeException( $id->get_error_message() );
}

$fixture_post_ids[] = $id;
wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
update_post_meta( $id, 'erankly_meta_description', 'Template-owned description' );

$term = wp_insert_term(
	'EasyRankly template desc term ' . $suffix,
	'category',
	array(
		'description' => 'Term-owned description',
	)
);

if ( is_wp_error( $term ) ) {
	throw new RuntimeException( $term->get_error_message() );
}

$term_id            = (int) $term['term_id'];
$fixture_term_ids[] = $term_id;
$term_object        = get_term( $term_id, 'category' );

$original_post        = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
$original_wp_query    = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
$had_template_id      = array_key_exists( '_wp_current_template_id', $GLOBALS );
$original_template_id = $had_template_id ? $GLOBALS['_wp_current_template_id'] : null;
$template_id          = get_stylesheet() . '//' . $slug;
$GLOBALS['_wp_current_template_id'] = $template_id;

$capability_filter = static function ( $allcaps ) {
	$allcaps['edit_theme_options'] = true;

	return $allcaps;
};

$prepare_query = static function ( $flags, $term = null ) use ( $reset_request_caches ) {
	$query              = new WP_Query();
	$query->is_404      = false;
	$query->is_archive  = ! empty( $flags['is_archive'] );
	$query->is_author   = false;
	$query->is_category = ! empty( $flags['is_category'] );
	$query->is_date     = ! empty( $flags['is_date'] );
	$query->is_home     = false;
	$query->is_search   = false;
	$query->is_singular = false;
	$query->is_tag      = false;
	$query->is_tax      = false;

	if ( $term instanceof WP_Term ) {
		$query->queried_object    = $term;
		$query->queried_object_id = (int) $term->term_id;
	}

	$GLOBALS['wp_query'] = $query;
	$GLOBALS['post']     = null;
	$reset_request_caches();
};

try {
	ERankly_Plugin::register_template_description_field();
	$assert(
		isset( $GLOBALS['wp_rest_additional_fields']['wp_template']['erankly_meta_description'] ),
		'The template description must be registered as a REST field.'
	);
	$assert(
		'Template-owned description' === ERankly_Plugin::get_template_description_field( array( 'wp_id' => $id ) ),
		'The template description field must return the stored value.'
	);
	$assert( '' === ERankly_Plugin::get_template_description_field( array() ), 'A template without a wp_id must return an empty description.' );

	add_filter( 'user_has_cap', $capability_filter );
	$template_object = get_block_template( $template_id, 'wp_template' );
	$assert( $template_object instanceof WP_Block_Template, 'The fixture template must be resolvable as a block template.' );

	ERankly_Plugin::update_template_description_field( 'Updated template description', $template_object );
	$assert(
		'Updated template description' === get_post_meta( $id, 'erankly_meta_description', true ),
		'Updating the template description field must store the new value.'
	);

	ERankly_Plugin::update_template_description_field( '', $template_object );
	$assert( '' === (string) get_post_meta( $id, 'erankly_meta_description', true ), 'An empty template description must delete the stored meta.' );

	update_post_meta( $id, 'erankly_meta_description', 'Template-owned description' );
	remove_filter( 'user_has_cap', $capability_filter );

	$prepare_query(
		array(
			'is_archive' => true,
			'is_date'    => true,
		)
	);
	ob_start();
	ERankly_Plugin::print_meta_description();
	$date_description = ob_get_clean();
	$assert( false !== strpos( $date_description, 'Template-owned description' ), 'A date archive must use the template description when no more specific source exists.' );

	$prepare_query(
		array(
			'is_archive'  => true,
			'is_category' => true,
		),
		$term_object
	);
	ob_start();
	ERankly_Plugin::print_meta_description();
	$term_description = ob_get_clean();
	$assert( false !== strpos( $term_description, 'Term-owned description' ), 'A term description must win over the template description.' );
	$assert( false === strpos( $term_description, 'Template-owned description' ), 'The template description must not print when a term description exists.' );

	$prepare_query(
		array(
			'is_archive' => true,
			'is_date'    => true,
		)
	);
	$GLOBALS['_wp_current_template_id'] = '';
	$reset_request_caches();
	ob_start();
	ERankly_Plugin::print_meta_description();
	$empty_template = ob_get_clean();
	$assert( '' === $empty_template, 'A missing current template id must not print a template description.' );
} finally {
	remove_filter( 'user_has_cap', $capability_filter );

	if ( $had_template_id ) {
		$GLOBALS['_wp_current_template_id'] = $original_template_id;
	} else {
		unset( $GLOBALS['_wp_current_template_id'] );
	}

	$GLOBALS['wp_query'] = $original_wp_query;
	$GLOBALS['post']     = $original_post;
	$cleanup();
}

echo "EasyRankly template description smoke test passed.\n";
