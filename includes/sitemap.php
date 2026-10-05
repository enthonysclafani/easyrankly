<?php
/** Additional sitemaps, independent of the SEO module. */
defined( 'ABSPATH' ) || exit;

function erankly_sitemap_boot(): void {
	erankly_load_sitemap_helpers();
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'includes/sitemap/core.php';
	erankly_core_sitemap_boot();
	erankly_sitemap_module_boot();
}
