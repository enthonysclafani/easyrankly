<?php
/**
 * Removes everything EasyRankly created on the site.
 *
 * Every option, meta key, post type and taxonomy listed in docs/data-model.md
 * must be deleted here, and tests/UninstallTest.php must prove it.
 *
 * @package EasyRankly
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Redirects (src/Redirects/Redirects.php): posts with their meta first, then the lists built from them.
do {
	$easyrankly_ids     = get_posts(
		array(
			'post_type'        => 'erankly_redirect',
			'post_status'      => array_keys( get_post_stati() ),
			'posts_per_page'   => 100,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	$easyrankly_deleted = 0;
	foreach ( $easyrankly_ids as $easyrankly_id ) {
		$easyrankly_deleted += wp_delete_post( (int) $easyrankly_id, true ) ? 1 : 0;
	}
} while ( $easyrankly_deleted > 0 );

delete_option( 'easyrankly_settings' );
delete_option( 'easyrankly_redirects_forced' );
delete_option( 'easyrankly_redirects_regex' );

// SEO meta of posts and terms (src/Meta/Meta.php).
foreach (
	array(
		'_easyrankly_title',
		'_easyrankly_description',
		'_easyrankly_canonical',
		'_easyrankly_noindex',
		'_easyrankly_nofollow',
		'_easyrankly_og_title',
		'_easyrankly_og_description',
		'_easyrankly_og_image',
	) as $easyrankly_key
) {
	delete_metadata( 'post', 0, $easyrankly_key, '', true );
	delete_metadata( 'term', 0, $easyrankly_key, '', true );
}
