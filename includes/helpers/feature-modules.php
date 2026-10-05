<?php
/**
 * Feature module manifest and toggles. Implementation files are required only
 * from erankly_bootstrap() when the matching toggle is on.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** One manifest for module switches and bootstrapping. No feature depends on another module being active. */
function erankly_feature_modules(): array {
	return array(
		'seo'         => array( 'setting' => 'enable_seo', 'default' => 1, 'file' => 'includes/seo.php', 'boot' => 'erankly_seo_boot' ),
		'redirects'   => array( 'setting' => 'enable_redirects', 'default' => 0, 'file' => 'includes/redirects.php', 'boot' => 'erankly_redirects_boot' ),
		'sitemap'     => array( 'setting' => 'enable_sitemap', 'default' => 0, 'file' => 'includes/sitemap.php', 'boot' => 'erankly_sitemap_boot' ),
		'custom-code' => array( 'setting' => 'enable_custom_code', 'default' => 0, 'file' => 'includes/custom-code.php', 'boot' => 'erankly_custom_code_boot' ),
		'tools'       => array( 'setting' => 'enable_tools', 'default' => 1, 'file' => 'includes/tools.php', 'boot' => 'erankly_tools_boot' ),
		'forms'       => array( 'setting' => 'enable_forms', 'default' => 0, 'file' => 'includes/forms.php', 'boot' => 'erankly_forms_boot' ),
		'multilingual' => array( 'setting' => 'enable_multilingual', 'default' => 0, 'file' => 'includes/multilingual.php', 'boot' => 'erankly_multilingual_boot' ),
	);
}

/** Labels are translated only when rendering the manager, after WordPress initializes translations. */
function erankly_feature_module_labels(): array {
	return array(
		'seo'         => __( 'Search Engine Optimization', 'easyrankly' ),
		'redirects'   => __( 'Redirects', 'easyrankly' ),
		'sitemap'     => __( 'Sitemaps', 'easyrankly' ),
		'custom-code' => __( 'Custom code', 'easyrankly' ),
		'tools'       => __( 'Tools', 'easyrankly' ),
		'forms'       => __( 'Forms', 'easyrankly' ),
		'multilingual' => __( 'Multilingual', 'easyrankly' ),
	);
}

function erankly_feature_module_enabled( string $slug ): bool {
	$module = erankly_feature_modules()[ $slug ] ?? null;
	return is_array( $module ) && ! empty( erankly_get_setting( $module['setting'], $module['default'] ) );
}

function erankly_boot_feature_modules(): void {
	foreach ( erankly_feature_modules() as $slug => $module ) {
		if ( ! erankly_feature_module_enabled( $slug ) ) {
			continue;
		}
		require_once ERANKLY_PATH . $module['file'];
		call_user_func( $module['boot'] );
	}
}

/** Preserve the former always-on behavior for existing installs that have no explicit switch yet. */
function erankly_seo_enabled(): bool {
	return erankly_feature_module_enabled( 'seo' );
}

function erankly_tools_enabled(): bool {
	return erankly_feature_module_enabled( 'tools' );
}

/** Keep theme integrations callable when SEO is disabled, without loading its implementation. */
function erankly_breadcrumbs( array $args = array() ): string {
	if ( ! erankly_seo_enabled() || ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		return '';
	}
	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
	return erankly_render_breadcrumbs( $args );
}

function erankly_redirects_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_redirects' ) );
}

function erankly_sitemap_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_sitemap', 0 ) );
}

function erankly_custom_code_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_custom_code' ) );
}

function erankly_forms_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_forms', 0 ) );
}

function erankly_multilingual_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_multilingual', 0 ) );
}

/** Detects topology without inspecting plugins, querying sites or reading module settings. */
function erankly_multilingual_mode(): string {
	return is_multisite() ? 'multisite' : 'singlesite';
}

/** The native feature flag owns activation, including when the former add-on is still active. */
function erankly_suspend_legacy_multilingual_addon(): void {
	if ( ! function_exists( 'erml_ms_bootstrap' ) ) {
		return;
	}
	remove_action( 'erankly_bootstrap', 'erml_ms_bootstrap' );
	remove_action( 'init', 'erml_ms_load_textdomain' );
	remove_action( 'admin_notices', 'erml_ms_render_requirements_notice' );
	remove_action( 'network_admin_notices', 'erml_ms_render_requirements_notice' );
}
