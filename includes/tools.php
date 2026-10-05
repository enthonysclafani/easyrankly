<?php
/** Optional site-scoped database tools; the implementation is loaded only on its screen or AJAX endpoint. */
defined( 'ABSPATH' ) || exit;

function erankly_tools_boot(): void {
	if ( erankly_tools_enabled() && is_admin() ) {
		add_action( 'wp_ajax_erankly_database_tools', 'erankly_admin_database_tools_ajax' );
	}
}
