<?php
/**
 * Revision metadata sanitization and authorization smoke test.
 *
 * Run with: studio wp eval-file wp-content/plugins/easyrankly/tests/revision-meta-smoke.php
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

$original_user_id = get_current_user_id();
$user_id          = 0;
$post_id          = 0;
$revision_id      = 0;

try {
	$user_id = wp_insert_user(
		array(
			'display_name' => 'EasyRankly revision fixture',
			'role'         => 'author',
			'user_email'   => 'erankly-revision-' . wp_rand( 1000, 999999 ) . '@example.com',
			'user_login'   => 'erankly_revision_' . wp_rand( 1000, 999999 ),
			'user_pass'    => wp_generate_password( 24, true, true ),
		)
	);
	$assert( ! is_wp_error( $user_id ) && $user_id > 0, 'Could not create the revision permission fixture.' );

	$post_id = wp_insert_post(
		array(
			'post_author'  => $user_id,
			'post_content' => 'Original revision fixture.',
			'post_status'  => 'publish',
			'post_title'   => 'Revision fixture',
			'post_type'    => 'post',
		),
		true
	);
	$assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create the revision post fixture.' );

	$revision_id = _wp_put_post_revision( $post_id );
	$assert( ! is_wp_error( $revision_id ) && $revision_id > 0, 'Could not create the revision fixture.' );

	$raw_code = " \0<script>window.eranklyRevision = true;</script> ";
	update_metadata( 'post', $revision_id, 'erankly_code', wp_slash( $raw_code ) );
	$assert(
		'<script>window.eranklyRevision = true;</script>' === get_post_meta( $revision_id, 'erankly_code', true ),
		'Revision Head code must use the revision sanitizer.'
	);

	update_metadata( 'post', $revision_id, 'erankly_meta_description', " <b>Revision description</b>\n" );
	$assert(
		'Revision description' === get_post_meta( $revision_id, 'erankly_meta_description', true ),
		'Revision descriptions must use the registered text sanitizer.'
	);

	update_metadata( 'post', $revision_id, 'erankly_visibility', 'unsupported' );
	$assert( 'index' === get_post_meta( $revision_id, 'erankly_visibility', true ), 'Revision visibility must reject unsupported values.' );

	wp_set_current_user( $user_id );
	$assert( current_user_can( 'edit_post', $post_id ), 'The author fixture must be able to edit its post.' );
	$assert( ! current_user_can( 'unfiltered_html' ), 'The author fixture must not have unfiltered HTML access.' );
	$assert(
		! current_user_can( 'edit_post_meta', $revision_id, 'erankly_code' ),
		'Authors without unfiltered HTML must not be authorized for raw code on revisions.'
	);
	$assert(
		current_user_can( 'edit_post_meta', $revision_id, 'erankly_meta_description' ),
		'Authors must remain authorized for ordinary SEO metadata on revisions.'
	);

	delete_post_meta( $post_id, 'erankly_code' );
	_wp_copy_post_meta( $revision_id, $post_id, 'erankly_code' );
	$assert(
		get_post_meta( $revision_id, 'erankly_code', true ) === get_post_meta( $post_id, 'erankly_code', true ),
		'Restoring revision meta must preserve the sanitized value.'
	);
} finally {
	wp_set_current_user( $original_user_id );

	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}

	if ( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}

echo "EasyRankly revision metadata smoke test passed.\n";
