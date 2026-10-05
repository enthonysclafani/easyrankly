<?php
/** Search Engine Optimization module. Runtime hooks belong to this module, not the manager. */
defined( 'ABSPATH' ) || exit;

function erankly_seo_boot(): void {
	if ( ! erankly_seo_enabled() ) {
		return;
	}
	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
	add_action( 'admin_notices', 'erankly_render_invalid_json_ld_notice' );
	add_action( 'init', 'erankly_register_meta' );
	add_action( 'rest_api_init', 'erankly_register_schema_blocks_rest_guards' );
	add_action( 'init', 'erankly_register_breadcrumb_integrations', 11 );
	add_action( 'wp_loaded', 'erankly_sync_legacy_breadcrumbs_availability' );
	add_action( 'enqueue_block_editor_assets', 'erankly_register_breadcrumbs_block_script', 0 );
	add_action( 'enqueue_block_editor_assets', 'erankly_sync_legacy_breadcrumbs_availability', 1 );
	add_action( 'rest_api_init', 'erankly_register_user_search_route' );
	add_action( 'rest_api_init', 'erankly_register_local_business_routes' );
	add_action( 'rest_api_init', 'erankly_register_special_pages_autosave_route' );
	add_action( 'rest_api_init', 'erankly_register_special_meta_setting', 5 );
	add_filter( 'robots_txt', 'erankly_filter_robots_txt', 20, 2 );
	add_action( 'parse_request', 'erankly_force_robots_txt_request' );
	add_action( 'template_redirect', 'erankly_send_feed_robots_header', 1 );
	add_action( 'pre_get_posts', 'erankly_filter_visibility_queries' );
	add_action( 'added_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'updated_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	add_action( 'deleted_post_meta', 'erankly_invalidate_visibility_exclusion_cache', 10, 3 );
	if ( ! erankly_sitemap_enabled() ) {
		// SEO aligns WordPress's native sitemap even without the extra Sitemap package.
		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		erankly_core_sitemap_boot();
	}
	if ( erankly_is_frontend_html_request() ) {
		add_action( 'wp', 'erankly_bootstrap_frontend_modules', 1 );
	}
}

/**
 * Loads frontend-only modules after WordPress has resolved an HTML request. REST requests normally terminate
 * before the wp hook, so they do not parse the canonical, social, schema, or breadcrumb implementations.
 */
function erankly_bootstrap_frontend_modules(): void {
	if ( ! erankly_seo_enabled() ) {
		return;
	}

	// robots.txt, the favicon and native XML sitemaps have no HTML head to describe.
	if ( is_robots() || is_favicon() || '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
		return;
	}

	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'includes/meta-render.php';

	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';

	// template_redirect runs after this wp:1 action, so the callback is defined in time.
	if ( 'none' !== (string) erankly_get_setting( 'attachment_redirect', 'none' ) ) {
		add_action( 'template_redirect', 'erankly_redirect_attachment' );
	}

	if ( ! erankly_should_output_head() ) {
		return;
	}

	require_once ERANKLY_PATH . 'includes/canonical.php';
	require_once ERANKLY_PATH . 'includes/opengraph.php';
	require_once ERANKLY_PATH . 'includes/schema.php';

	remove_action( 'wp_head', 'rel_canonical' );
	add_filter( 'pre_get_document_title', 'erankly_filter_document_title', 20 );
	add_filter( 'document_title_parts', 'erankly_filter_document_title_parts', 20 );
	add_action( 'wp_head', 'erankly_render_head', 1 );
	add_filter( 'wp_robots', 'erankly_filter_wp_robots', 20 );
}
