<?php
/**
 * Block and Site Editor assets: shared editor bundle + per-editor script with wp_localize data, plus the
 * special-page panels for block themes on WP 6.6+ (erankly_site_editor_special_page_panels_supported()).
 */
defined( 'ABSPATH' ) || exit;
function erankly_admin_enqueue_block_editor_assets(): void {
	$post = get_post();
	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_enqueue_editor_shared_assets();
	wp_enqueue_script(
		'erankly-schema-jsonld',
		ERANKLY_URL . 'assets/js/schema-jsonld.js',
		array( 'wp-i18n' ),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-schema-jsonld', 'easyrankly', ERANKLY_PATH . 'languages' );
	$editor_deps = array(
		'erankly-schema-jsonld',
		'erankly-editor-shared',
		'wp-api-fetch',
		'wp-block-editor',
		'wp-components',
		'wp-data',
		'wp-date',
		'wp-edit-post',
		'wp-editor',
		'wp-element',
		'wp-hooks',
		'wp-i18n',
		'wp-plugins',
	);
	wp_enqueue_script(
		'erankly-editor',
		ERANKLY_URL . 'assets/js/editor.js',
		$editor_deps,
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_localize_script(
		'erankly-editor',
		'eranklyEditor',
		array(
			'breadcrumbsEnabled'    => (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ),
			'newsSitemapEnabled'    => (bool) erankly_get_setting( 'enable_news_sitemap', 0 ),
			'siteDescription'       => get_bloginfo( 'description' ),
			'siteName'              => get_bloginfo( 'name' ),
			'variableExamples'      => erankly_get_admin_variable_examples( $post ),
			'variables'             => erankly_get_variable_groups(),
			'schemaTypeSuggestions' => function_exists( 'erankly_get_schema_type_suggestions_for_post' )
				? erankly_get_schema_type_suggestions_for_post( (int) $post->ID )
				: array(),
			'serp'                  => erankly_get_editor_serp_context( $post ),
		)
	);
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_editor'       => true,
			'is_taxonomy'     => false,
			'is_block_editor' => true,
			'is_site_editor'  => false,
			'settings_tab'    => '',
		)
	);
}
/**
 * Data the editor's SERP preview needs to mirror erankly_get_title() / erankly_get_description() for this post:
 * which context the front end will treat it as, and the fallback templates that context reads.
 *
 * The static front page and the posts page ignore the post's own fields and use the Homepage / Blog special-page
 * templates instead, exactly like the front end does.
 *
 * @return array<string,mixed>
 */
function erankly_get_editor_serp_context( WP_Post $post ): array {
	$context = 'singular';

	if ( 'page' === get_option( 'show_on_front' ) ) {
		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			$context = 'front';
		} elseif ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
			$context = 'blog';
		}
	}

	if ( 'singular' === $context ) {
		$title_template       = erankly_get_global_post_type_meta( $post->post_type, 'title' );
		$description_template = erankly_get_global_post_type_meta( $post->post_type, 'description' );
	} else {
		$special_key          = 'front' === $context ? 'homepage' : 'blog';
		$title_template       = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'title' );
		$description_template = erankly_get_global_entity_meta( 'global_special_meta', $special_key, 'description' );
	}

	return array(
		'context'             => $context,
		'descriptionTemplate' => $description_template,
		'showDate'            => 'post' === $post->post_type,
		'siteIcon'            => get_site_icon_url( 32 ),
		'titleTemplate'       => $title_template,
	);
}
/**
 * Enqueues the stylesheets of the block editor surfaces: the shared tokens plus editor.css.
 *
 * Split from erankly_enqueue_editor_shared_assets() so a screen that only borrows the plugin's editor
 * look — the Contact form settings panel, which is built from the same @wordpress/components controls —
 * does not have to pull editor-shared.js in as well. That script does nothing until a panel module
 * calls usePanelsAfterDefaults(), and the forms panel has no variables and no sibling panels.
 */
function erankly_enqueue_editor_styles(): void {
	erankly_enqueue_shared_styles();
	wp_enqueue_style(
		'erankly-editor',
		ERANKLY_URL . 'assets/css/editor.css',
		array( 'erankly-shared', 'wp-components' ),
		ERANKLY_VERSION
	);
}
function erankly_enqueue_editor_shared_assets(): void {
	require_once ERANKLY_PATH . 'admin/settings/section-links.php';
	erankly_enqueue_editor_styles();
	wp_enqueue_script(
		'erankly-editor-shared',
		ERANKLY_URL . 'assets/js/editor-shared.js',
		array(
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-editor-shared', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_localize_script(
		'erankly-editor-shared',
		'eranklyEditorShared',
		array(
			'panelOrder' => array_values(
				array_filter(
					(array) apply_filters(
						'erankly_editor_panel_order',
						array(
							'erankly-panel--appearance',
							'erankly-panel--social',
							'erankly-panel--schema',
							'erankly-panel--visibility',
							'erankly-panel--translations',
						)
					),
					'is_string'
				)
			),
		)
	);
}
function erankly_admin_enqueue_site_editor_assets(): void {
	if ( ! erankly_site_editor_special_page_panels_supported() ) {
		return;
	}
	if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	require_once ERANKLY_PATH . 'admin/field-renderers.php';
	erankly_enqueue_editor_shared_assets();
	wp_enqueue_script(
		'erankly-site-editor',
		ERANKLY_URL . 'assets/js/site-editor.js',
		array(
			'erankly-editor-shared',
			'wp-api-fetch',
			'wp-block-editor',
			'wp-components',
			'wp-core-data',
			'wp-data',
			'wp-editor',
			'wp-element',
			'wp-hooks',
			'wp-i18n',
			'wp-plugins',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-site-editor', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_localize_script(
		'erankly-site-editor',
		'eranklySiteEditor',
		array(
			'contextLabels'      => erankly_special_page_keys(),
			'siteDescription'    => get_bloginfo( 'description' ),
			'siteName'           => get_bloginfo( 'name' ),
			'specialMetaSetting' => ERANKLY_SPECIAL_META_OPTION,
			'variableExamples'   => erankly_get_admin_variable_examples(),
			'variables'          => erankly_get_variable_groups(),
		)
	);
	do_action(
		'erankly_admin_enqueue_assets',
		array(
			'hook_suffix'     => '',
			'screen'          => get_current_screen(),
			'is_settings'     => false,
			'is_editor'       => false,
			'is_taxonomy'     => false,
			'is_block_editor' => false,
			'is_site_editor'  => true,
			'settings_tab'    => '',
		)
	);
}
