<?php
/**
 * Admin asset enqueuer. Mirrors the layering of the host plugin's admin/assets/settings.php: the add-on's
 * stylesheets sit on top of EasyRankly's, never replace them, and are declared as dependents so the cascade
 * order is enforced by wp_enqueue_style() instead of by registration order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the add-on stylesheet on the three surfaces it renders on: the Multilingual settings tab, the classic
 * editor meta box and the term edit screen. The block editor panel is handled separately, from
 * enqueue_block_editor_assets.
 *
 * The dependency on 'erankly-admin-settings' is what scopes this: the host plugin registers that handle only on
 * the screens it styles, and a style whose dependency is missing is skipped by WP_Dependencies. So on any screen
 * EasyRankly does not dress, this stylesheet stays out of the page rather than loading unused rules.
 */
function erankly_mlms_enqueue_admin_assets( string $hook_suffix ): void {
	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	// The settings screen is registered under Settings on a single site and under the Network Admin's own
	// Settings menu on Multisite, which suffixes the hook.
	$is_settings = in_array( $hook_suffix, array( 'settings_page_erankly', 'settings_page_erankly-network' ), true )
		&& 'multilingual' === erankly_admin_requested_settings_tab();
	$is_editor   = in_array( $screen->post_type, erankly_mlms_get_settings()['post_types'], true ) && ! $screen->is_block_editor();
	$is_taxonomy = in_array( $screen->taxonomy, erankly_mlms_get_settings()['taxonomies'], true );

	if ( ! $is_settings && ! $is_editor && ! $is_taxonomy ) {
		return;
	}

	wp_enqueue_style(
		'erankly-mlms-admin',
		ERANKLY_MLMS_URL . 'css/admin.css',
		array( 'erankly-admin-settings' ),
		ERANKLY_MLMS_VERSION
	);
}

/**
 * Loads the block editor panel stylesheet. Kept out of erankly_mlms_enqueue_admin_assets() because the host plugin
 * takes an early return for block editor screens and never registers 'erankly-admin-settings' there; the panel's
 * chrome comes from 'erankly-editor' instead.
 */
function erankly_mlms_enqueue_editor_styles(): void {
	wp_enqueue_style(
		'erankly-mlms-editor',
		ERANKLY_MLMS_URL . 'css/editor.css',
		array( 'erankly-editor' ),
		ERANKLY_MLMS_VERSION
	);
}
