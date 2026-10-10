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

// Redirects (src/Redirects/Redirects.php) and snippets (src/CustomCode/CustomCode.php): posts with
// their meta and revisions first, then the caches built from them. Each post is tried once, so
// one that cannot be deleted does not stop the others.
$easyrankly_tried = array();
do {
	$easyrankly_ids = get_posts(
		array(
			'post_type'        => array( 'erankly_redirect', 'erankly_snippet' ),
			'post_status'      => array_keys( get_post_stati() ),
			'post__not_in'     => $easyrankly_tried,
			'posts_per_page'   => 100,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	foreach ( $easyrankly_ids as $easyrankly_id ) {
		$easyrankly_tried[] = (int) $easyrankly_id;
		wp_delete_post( (int) $easyrankly_id, true );
	}
} while ( array() !== $easyrankly_ids );

// Languages and translation groups (src/Multilingual/): the plugin is not loaded here, so the
// taxonomies are registered (if missing) just to delete their terms, which removes the relationships too.
foreach ( array( 'erankly_language', 'erankly_translation' ) as $easyrankly_taxonomy ) {
	if ( ! taxonomy_exists( $easyrankly_taxonomy ) ) {
		register_taxonomy( $easyrankly_taxonomy, array() );
	}
	$easyrankly_tried = array();
	do {
		$easyrankly_terms = get_terms(
			array(
				'taxonomy'   => $easyrankly_taxonomy,
				'hide_empty' => false,
				'exclude'    => $easyrankly_tried,
				'fields'     => 'ids',
				'number'     => 100,
			)
		);
		$easyrankly_terms = is_array( $easyrankly_terms ) ? $easyrankly_terms : array();
		foreach ( $easyrankly_terms as $easyrankly_term ) {
			$easyrankly_tried[] = (int) $easyrankly_term;
			wp_delete_term( (int) $easyrankly_term, $easyrankly_taxonomy );
		}
	} while ( array() !== $easyrankly_terms );
}

// Language menu locations (src/Multilingual/Menus.php): the core stores the menus assigned to them in
// the theme mods of each theme, next to those of the theme locations.
foreach ( array_unique( array_merge( array( get_stylesheet() ), array_keys( wp_get_themes() ) ) ) as $easyrankly_theme ) {
	$easyrankly_mods = get_option( 'theme_mods_' . $easyrankly_theme );
	if ( ! is_array( $easyrankly_mods ) || ! is_array( $easyrankly_mods['nav_menu_locations'] ?? null ) ) {
		continue;
	}
	$easyrankly_locations = array_filter(
		$easyrankly_mods['nav_menu_locations'],
		static fn( $location ): bool => ! str_contains( (string) $location, '__erankly_' ),
		ARRAY_FILTER_USE_KEY
	);
	if ( count( $easyrankly_locations ) !== count( $easyrankly_mods['nav_menu_locations'] ) ) {
		$easyrankly_mods['nav_menu_locations'] = $easyrankly_locations;
		update_option( 'theme_mods_' . $easyrankly_theme, $easyrankly_mods );
	}
}

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
