<?php
/** Native Multisite module. Storage names are retained from the 1.3.0 add-on. */
defined( 'ABSPATH' ) || exit;

define( 'ERANKLY_MLMS_VERSION', ERANKLY_VERSION );
define( 'ERANKLY_MLMS_PATH', __DIR__ . '/' );
define( 'ERANKLY_MLMS_URL', ERANKLY_URL . 'assets/multilingual/multisite/' );
define( 'ERANKLY_MLMS_OPTION', 'erml_ms_settings' );
define( 'ERANKLY_MLMS_PROVIDER_ID', 'multisite' );
define( 'ERANKLY_MLMS_POST_META_KEY', '_erankly_mlms_translations' );
define( 'ERANKLY_MLMS_TERM_META_KEY', '_erankly_mlms_term_translations' );

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/relationships.php';
require_once __DIR__ . '/class-erankly-mlms-provider.php';

function erankly_mlms_boot(): void {
	erankly_register_multilingual_provider( new ERANKLY_MLMS_Provider() );
}

/** Hooks are installed only after the core registry selects this provider. */
function erankly_mlms_register_hooks(): void {
	// Deletion cleanup runs in every context (admin, REST, cron, WP-CLI).
	add_action( 'before_delete_post', 'erankly_mlms_handle_post_deletion' );
	add_action( 'deleted_post', 'erankly_mlms_finish_post_deletion' );
	add_action( 'deleted_term', 'erankly_mlms_finish_term_deletion' );
	add_filter( 'debug_information', 'erankly_mlms_add_debug_information' );
	add_action( 'pre_delete_term', 'erankly_mlms_handle_term_deletion', 10, 2 );

	// The relationship maps are writable outside the editor screens (the block editor "Linked translations"
	// panel goes through the post meta API, and code/WP-CLI can write either map directly), so every external
	// write is synchronized to the counterpart sites from these hooks. The update_*_meta/delete_*_meta actions
	// fire before the write and snapshot the previous map for the diff.
	add_action( 'update_post_meta', 'erankly_mlms_snapshot_post_meta_before_write', 10, 4 );
	add_action( 'delete_post_meta', 'erankly_mlms_snapshot_post_meta_before_write', 10, 3 );
	add_action( 'added_post_meta', 'erankly_mlms_handle_post_meta_write', 10, 4 );
	add_action( 'updated_post_meta', 'erankly_mlms_handle_post_meta_write', 10, 4 );
	add_action( 'deleted_post_meta', 'erankly_mlms_handle_post_meta_delete', 10, 3 );

	// Terms are handled symmetrically. The term map is not REST-registered, but wp_update_term() callers,
	// WP-CLI and other plugins reach the same meta rows, and a one-sided write would leave the relationship
	// graph inconsistent exactly the way an unsynchronized post write would.
	add_action( 'update_term_meta', 'erankly_mlms_snapshot_term_meta_before_write', 10, 4 );
	add_action( 'delete_term_meta', 'erankly_mlms_snapshot_term_meta_before_write', 10, 3 );
	add_action( 'added_term_meta', 'erankly_mlms_handle_term_meta_write', 10, 4 );
	add_action( 'updated_term_meta', 'erankly_mlms_handle_term_meta_write', 10, 4 );
	add_action( 'deleted_term_meta', 'erankly_mlms_handle_term_meta_delete', 10, 3 );

	// Registers the relationship map with the REST API so the block editor panel can read and write it.
	add_action( 'init', 'erankly_mlms_register_rest_meta' );

	// Autosave endpoint for the Multilingual settings tab. Outside the is_admin() branch below: REST requests
	// don't run in an admin context.
	add_action( 'rest_api_init', 'erankly_mlms_register_settings_rest_route' );

	if ( is_admin() ) {
		require_once ERANKLY_MLMS_PATH . 'admin/assets.php';
		require_once ERANKLY_MLMS_PATH . 'admin/settings-tab.php';
		require_once ERANKLY_MLMS_PATH . 'admin/editor-panel.php';
		require_once ERANKLY_MLMS_PATH . 'admin/meta-box.php';
		require_once ERANKLY_MLMS_PATH . 'admin/term-fields.php';
		erankly_mlms_admin_bootstrap();
	}
}

function erankly_mlms_requirements_met(): bool {
	return is_multisite() && erankly_multilingual_enabled();
}

function erankly_mlms_register_rest_meta(): void {
	if ( ! erankly_mlms_requirements_met() ) {
		return;
	}

	require_once ERANKLY_MLMS_PATH . 'settings.php';

	foreach ( erankly_mlms_get_settings()['post_types'] as $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			continue;
		}

		register_post_meta(
			$post_type,
			ERANKLY_MLMS_POST_META_KEY,
			array(
				'type'         => 'object',
				'single'       => true,
				'show_in_rest' => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'integer' ),
					),
				),
				'default'      => array(),
				'auth_callback' => static fn( $allowed, $meta_key, $object_id ): bool => current_user_can( 'edit_post', (int) $object_id ),
			)
		);
	}
}

function erankly_mlms_add_debug_information( array $info ): array {
	$sites = erankly_mlms_get_enabled_sites();

	$info['easyrankly_multilingual_multisite'] = array(
		'label'  => __( 'EasyRankly Multilingual for Multisite', 'easyrankly' ),
		'fields' => array(
			'version'         => array(
				'label' => __( 'Version', 'easyrankly' ),
				'value' => ERANKLY_MLMS_VERSION,
			),
			'provider_id'     => array(
				'label' => __( 'Provider ID', 'easyrankly' ),
				'value' => ERANKLY_MLMS_PROVIDER_ID,
			),
			'cluster'         => array(
				'label' => __( 'Participating sites', 'easyrankly' ),
				'value' => (string) count( $sites ),
			),
			'hreflangs'       => array(
				'label' => __( 'Resolved hreflang tags', 'easyrankly' ),
				'value' => $sites ? implode( ', ', wp_list_pluck( $sites, 'hreflang' ) ) : __( 'none', 'easyrankly' ),
			),
			'x_default'       => array(
				'label' => __( 'x-default site', 'easyrankly' ),
				'value' => (string) erankly_mlms_get_settings()['x_default_blog'],
			),
			'linkable_types'  => array(
				'label' => __( 'Linkable post types', 'easyrankly' ),
				'value' => implode( ', ', erankly_mlms_get_settings()['post_types'] ),
			),
			'linkable_taxes'  => array(
				'label' => __( 'Linkable taxonomies', 'easyrankly' ),
				'value' => implode( ', ', erankly_mlms_get_settings()['taxonomies'] ),
			),
		),
	);

	return $info;
}

