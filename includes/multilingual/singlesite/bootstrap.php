<?php
/** Opt-in native single-site multilingual implementation. */
defined( 'ABSPATH' ) || exit;

define( 'ERANKLY_MLSS_OPTION', 'erankly_multilingual_singlesite' );
define( 'ERANKLY_MLSS_META_KEY', '_erankly_mlss_translations' );

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/relationships.php';
require_once __DIR__ . '/class-erankly-mlss-provider.php';

function erankly_mlss_boot(): void {
	erankly_register_multilingual_provider( new ERankly_MLSS_Provider() );
}

/** Called only for the provider selected by the core registry. */
function erankly_mlss_register_hooks(): void {
	if ( ! erankly_mlss_install() ) {
		add_action( 'admin_notices', static function (): void {
			if ( current_user_can( 'manage_options' ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'Multilingual storage could not be initialized. Check the database permissions and retry.', 'easyrankly' ) . '</p></div>'; }
		} );
		return;
	}
	require_once __DIR__ . '/runtime.php';
	require_once __DIR__ . '/strings.php';
	erankly_mlss_runtime_boot();
	add_action( 'rest_api_init', static function (): void { require_once __DIR__ . '/rest.php'; erankly_mlss_register_content_rest(); } );
	add_action( 'erankly_multilingual_migrate', 'erankly_mlss_migrate_legacy' );
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		add_action( 'init', 'erankly_mlss_migrate_legacy', 30 );
	}
	add_action( 'rest_api_init', 'erankly_mlss_register_rest_routes' );
	add_action( 'before_delete_post', 'erankly_mlss_delete_post_links' );
	add_action( 'pre_delete_term', 'erankly_mlss_delete_term_links', 10, 2 );
	if ( is_admin() ) {
		require_once __DIR__ . '/admin.php';
		erankly_mlss_admin_boot();
	}
}
