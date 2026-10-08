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

delete_option( 'easyrankly_settings' );

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
