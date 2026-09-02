<?php
/**
 * Removes only data owned by EasyRankly.
 *
 * @package EasyRankly
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes EasyRankly site options and post metadata in the current site.
 *
 * @return void
 */
function erankly_uninstall_current_site() {
	$options = array(
		'erankly_data_version',
		'erankly_global_code',
		'erankly_global_body_start_code',
		'erankly_global_body_end_code',
		'erankly_social_settings',
		'erankly_site_identity',
		'erankly_business_settings',
		'erankly_title_format',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$post_meta = array(
		'erankly_code',
		'erankly_body_start_code',
		'erankly_body_end_code',
		'erankly_visibility',
		'erankly_meta_description',
		'erankly_meta_title',
		// Retired EasyRankly Zero fields.
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

	foreach ( $post_meta as $meta_key ) {
		delete_metadata( 'post', 0, $meta_key, '', true );
	}
}

if ( is_multisite() ) {
	$erankly_offset = 0;
	$erankly_limit  = 100;

	do {
		$erankly_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $erankly_limit,
				'offset' => $erankly_offset,
			)
		);

		foreach ( $erankly_site_ids as $erankly_site_id ) {
			switch_to_blog( (int) $erankly_site_id );
			erankly_uninstall_current_site();
			restore_current_blog();
		}

		$erankly_offset += $erankly_limit;
	} while ( count( $erankly_site_ids ) === $erankly_limit );
} else {
	erankly_uninstall_current_site();
}

delete_metadata( 'user', 0, 'erankly_twitter_handle', '', true );
