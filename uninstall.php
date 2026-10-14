<?php
/**
 * Removes everything EasyRankly created on the site, or on every site of the network on multisite.
 *
 * Every option, meta key, post type and taxonomy listed in docs/data-model.md
 * must be deleted here, and tests/UninstallTest.php must prove it.
 *
 * @package EasyRankly
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Everything EasyRankly stores lives in the tables of a single site, so the cleanup runs once per site.
$easyrankly_cleanup = static function (): void {
	// Redirects (src/Redirects/Redirects.php) and snippets (src/CustomCode/CustomCode.php): posts with
	// their meta and revisions first, then the caches built from them. Each post is tried once, so
	// one that cannot be deleted does not stop the others: in ID order, the ones left behind are always
	// the first ones, and the offset skips them.
	$easyrankly_failed = 0;
	do {
		$easyrankly_ids = get_posts(
			array(
				'post_type'      => array( 'erankly_redirect', 'erankly_snippet' ),
				'post_status'    => array_keys( get_post_stati() ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'offset'         => $easyrankly_failed,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $easyrankly_ids as $easyrankly_id ) {
			if ( ! wp_delete_post( (int) $easyrankly_id, true ) ) {
				++$easyrankly_failed;
			}
		}
	} while ( array() !== $easyrankly_ids );

	// Languages and translation groups (src/Multilingual/): the plugin is not loaded here, so the
	// taxonomies are registered (if missing) just to delete their terms, which removes the relationships too.
	foreach ( array( 'erankly_language', 'erankly_translation' ) as $easyrankly_taxonomy ) {
		if ( ! taxonomy_exists( $easyrankly_taxonomy ) ) {
			register_taxonomy( $easyrankly_taxonomy, array() );
		}
		$easyrankly_failed = 0;
		do {
			$easyrankly_terms = get_terms(
				array(
					'taxonomy'   => $easyrankly_taxonomy,
					'hide_empty' => false,
					'orderby'    => 'term_id',
					'order'      => 'ASC',
					'offset'     => $easyrankly_failed,
					'fields'     => 'ids',
					'number'     => 100,
				)
			);
			$easyrankly_terms = is_array( $easyrankly_terms ) ? $easyrankly_terms : array();
			foreach ( $easyrankly_terms as $easyrankly_term ) {
				if ( true !== wp_delete_term( (int) $easyrankly_term, $easyrankly_taxonomy ) ) {
					++$easyrankly_failed;
				}
			}
		} while ( array() !== $easyrankly_terms );
	}

	// Notices about redirects not created after an address change (src/Redirects/SlugChanges.php): one
	// transient per user, only for users whose role can change addresses. Others, if any, expire in a day
	// and the core removes them with the other expired transients.
	$easyrankly_offset = 0;
	do {
		$easyrankly_users = get_users(
			array(
				'capability__in' => array( 'edit_pages', 'edit_posts', 'manage_categories' ),
				'fields'         => 'ID',
				'number'         => 500,
				'offset'         => $easyrankly_offset,
				'orderby'        => 'ID',
				'count_total'    => false,
			)
		);
		foreach ( $easyrankly_users as $easyrankly_user ) {
			delete_transient( 'easyrankly_redirect_notice_' . (int) $easyrankly_user );
		}
		$easyrankly_offset += 500;
	} while ( array() !== $easyrankly_users );

	delete_option( 'easyrankly_settings' );
	delete_option( 'easyrankly_redirects_forced' );
	delete_option( 'easyrankly_redirects_regex' );
	delete_option( 'easyrankly_snippets' );

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
};

// On multisite the plugin can be active on any site of any network, and WordPress runs uninstall.php once, on the
// site where it is deleted: every site is cleaned, in batches so large networks do not load all sites at once.
if ( is_multisite() ) {
	$easyrankly_offset = 0;
	do {
		$easyrankly_sites = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 100,
				'offset'                 => $easyrankly_offset,
				'orderby'                => 'id',
				'update_site_meta_cache' => false,
			)
		);
		foreach ( $easyrankly_sites as $easyrankly_site ) {
			switch_to_blog( (int) $easyrankly_site );
			$easyrankly_cleanup();
			restore_current_blog();
		}
		$easyrankly_offset += 100;
	} while ( array() !== $easyrankly_sites );
} else {
	$easyrankly_cleanup();
}
