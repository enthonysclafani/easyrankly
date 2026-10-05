<?php
/** Block editor "Linked translations" document settings panel. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the React panel that mirrors the classic "Linked translations" meta box inside the block editor
 * sidebar, rendered with the same chrome as the EasyRankly "Search appearance" / "Search visibility" panels
 * (shared .erankly-panel classes and the shared panel-ordering logic). The panel edits the REST-registered
 * translation meta through the post meta API, so the links ride the normal Update save and the meta-write
 * hooks synchronize the counterpart sites.
 */
function erankly_mlms_enqueue_editor_panel(): void {
	$post = get_post();

	if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
		return;
	}

	$settings = erankly_mlms_get_settings();

	if ( ! in_array( $post->post_type, $settings['post_types'], true ) || ! erankly_mlms_is_active_for_linking() ) {
		return;
	}

	$own_blog    = get_current_blog_id();
	$map         = erankly_mlms_get_post_translations( $post->ID, $own_blog );
	$is_front    = (int) get_option( 'page_on_front' ) === (int) $post->ID && 'page' === $post->post_type;
	$assets_deps = array(
		'wp-components',
		'wp-data',
		'wp-editor',
		'wp-element',
		'wp-i18n',
		'wp-plugins',
	);

	// Keep the panel registered after EasyRankly's panels so it lands last in the shared panel order. The
	// dependency is optional on purpose: the panel degrades to registration order when the host assets are
	// not present for any reason.
	if ( wp_script_is( 'erankly-editor', 'registered' ) ) {
		$assets_deps[] = 'erankly-editor';
	}

	wp_enqueue_script(
		'erankly-mlms-editor-panel',
		ERANKLY_MLMS_URL . 'js/editor-panel.js',
		$assets_deps,
		ERANKLY_MLMS_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-mlms-editor-panel', 'easyrankly', ERANKLY_PATH . 'languages' );
	erankly_mlms_enqueue_editor_styles();

	$sites = array();

	foreach ( erankly_mlms_get_enabled_sites() as $site ) {
		if ( $site['blog_id'] === $own_blog ) {
			continue;
		}

		$linked = (int) ( $map[ $site['blog_id'] ] ?? 0 );

		$sites[] = array(
			'blogId'    => $site['blog_id'],
			'name'      => $site['name'],
			'hreflang'  => $site['hreflang'],
			'canEdit'   => erankly_mlms_user_can_edit_site( $site['blog_id'] ),
			'isLinked'  => $linked > 0,
			'choices'   => $linked > 0 || erankly_mlms_user_can_edit_site( $site['blog_id'] )
				? erankly_mlms_render_panel_choices( erankly_mlms_get_post_choices( $site['blog_id'], $post->post_type, $linked ) )
				: array(),
		);
	}

	wp_add_inline_script(
		'erankly-mlms-editor-panel',
		'window.eranklyMlmsEditorPanel = ' . wp_json_encode(
			array(
				'sites'       => $sites,
				'isFrontPage' => $is_front,
			)
		) . ';',
		'before'
	);
}

/** @return array<int,array{id:int,label:string}> */
function erankly_mlms_render_panel_choices( array $choices ): array {
	$out = array();

	foreach ( $choices as $choice_id => $label ) {
		$out[] = array(
			'id'    => (int) $choice_id,
			'label' => (string) $label,
		);
	}

	return $out;
}
