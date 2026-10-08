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

// Proposal statuses (src/Agent/Proposals.php): the plugin is not loaded here, and queries ignore
// unregistered statuses, so they are registered just to find the proposals.
foreach ( array( 'erankly_pending', 'erankly_accepted', 'erankly_rejected', 'erankly_superseded', 'erankly_failed', 'erankly_reverted' ) as $easyrankly_status ) {
	register_post_status( $easyrankly_status, array( 'internal' => true ) );
}

// Redirects (src/Redirects/Redirects.php), snippets (src/CustomCode/CustomCode.php), proposals
// (src/Agent/Proposals.php) and memory (src/Agent/Memory.php): posts with their meta and revisions
// first, then the caches built from them.
do {
	$easyrankly_ids     = get_posts(
		array(
			'post_type'        => array( 'erankly_redirect', 'erankly_snippet', 'erankly_proposal', 'erankly_memory' ),
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

// Languages and translation groups (src/Multilingual/): the plugin is not loaded here, so the
// taxonomies are registered (if missing) just to delete their terms, which removes the relationships too.
foreach ( array( 'erankly_language', 'erankly_translation' ) as $easyrankly_taxonomy ) {
	if ( ! taxonomy_exists( $easyrankly_taxonomy ) ) {
		register_taxonomy( $easyrankly_taxonomy, array() );
	}
	do {
		$easyrankly_terms   = get_terms(
			array(
				'taxonomy'   => $easyrankly_taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 100,
			)
		);
		$easyrankly_deleted = 0;
		foreach ( is_array( $easyrankly_terms ) ? $easyrankly_terms : array() as $easyrankly_term ) {
			$easyrankly_deleted += true === wp_delete_term( (int) $easyrankly_term, $easyrankly_taxonomy ) ? 1 : 0;
		}
	} while ( $easyrankly_deleted > 0 );
}

delete_option( 'easyrankly_settings' );
delete_option( 'easyrankly_redirects_forced' );
delete_option( 'easyrankly_redirects_regex' );
delete_option( 'easyrankly_snippets' );
delete_option( 'easyrankly_agent' );

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
