<?php
/**
 * Admin bootstrap: settings menus for both single-site and Network Admin, the shared asset registry, and the
 * lazy require of each admin module (import/export, meta boxes) only for the requests that need it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current WordPress version exposes the unified editor slotfills needed by the Site
 * Editor special-page panels.
 *
 * @return bool True when the Site Editor panels can be used.
 */
function erankly_site_editor_special_page_panels_supported(): bool {
	global $wp_version;

	return version_compare( (string) $wp_version, '6.6', '>=' );
}

/**
 * Determines whether special-page SEO defaults should be edited in the Site Editor instead of the classic
 * EasyRankly settings fallback.
 *
 * @return bool True when the contextual Site Editor panels are available.
 */
function erankly_use_site_editor_special_page_panels(): bool {
	return wp_is_block_theme() && erankly_site_editor_special_page_panels_supported();
}

require_once ERANKLY_PATH . 'admin/settings/registry.php';

function erankly_admin_bootstrap(): void {
	if ( is_multisite() ) {
		add_action( 'network_admin_menu', 'erankly_admin_register_network_settings_page' );
		add_action( 'network_admin_edit_erankly_network_save', 'erankly_admin_save_network_settings' );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_network_plugin_action_links' );
		add_action( 'admin_menu', 'erankly_admin_register_site_settings_page' );
		// Per-site special-page metadata falls back to the subsite settings
		// page unless the Site Editor panels are available.
		if ( erankly_seo_enabled() ) {
			add_action( 'admin_post_erankly_save_site_special_meta', 'erankly_admin_save_site_special_meta' );
		}
	} else {
		add_action( 'admin_menu', 'erankly_admin_register_settings_page' );
		add_action( 'admin_init', 'erankly_admin_maybe_register_settings' );
		add_filter( 'plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_plugin_action_links' );
	}

	if ( erankly_seo_enabled() ) {
		add_action( 'add_meta_boxes', 'erankly_admin_register_meta_boxes' );
		add_action( 'admin_init', 'erankly_admin_maybe_register_taxonomy_fields' );
		add_action( 'save_post', 'erankly_admin_save_meta_box', 10, 2 );
	}
	add_action( 'admin_init', 'erankly_admin_maybe_handle_import_export' );
	add_action( 'wp_ajax_erankly_import_batch', 'erankly_admin_import_batch_ajax' );
	add_action( 'admin_enqueue_scripts', 'erankly_admin_enqueue_assets' );
}

function erankly_admin_load_settings_modules(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/settings-page.php';
	require_once ERANKLY_PATH . 'admin/settings/section-links.php';
	require_once ERANKLY_PATH . 'admin/settings/panels.php';
	require_once ERANKLY_PATH . 'admin/settings/page-renderer.php';
}

/** Loads the Import / Export controller and migration UI on demand. */
function erankly_admin_load_import_export_module(): void {
	require_once ERANKLY_PATH . 'includes/import-export.php';
}

/** Loads the settings sanitizer and the import module for one AJAX import batch. */
function erankly_admin_import_batch_ajax(): void {
	erankly_admin_load_settings_modules();
	erankly_admin_load_import_export_module();
	erankly_import_export_ajax_batch();
}

/** Loads database maintenance only on its own screen or authenticated AJAX requests. */
function erankly_admin_load_tools_module(): void {
	if ( ! erankly_tools_enabled() ) {
		return;
	}
	require_once ERANKLY_PATH . 'includes/class-erankly-database-tools.php';
	require_once ERANKLY_PATH . 'admin/settings/tools.php';
}

function erankly_admin_database_tools_ajax(): void {
	if ( ! erankly_tools_enabled() ) {
		wp_send_json_error( array( 'message' => __( 'The Tools module is disabled.', 'easyrankly' ) ), 403 );
	}
	require_once ERANKLY_PATH . 'includes/class-erankly-database-tools.php';
	$result = ERankly_Database_Tools::dispatch( wp_unslash( $_POST ), $_SERVER['REQUEST_METHOD'] ?? '' );
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		wp_send_json_error( array( 'message' => $result->get_error_message() ), is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 );
	}
	wp_send_json_success( $result );
}

/**
 * Returns the requested top-level settings tab. This deliberately performs only request routing. Availability
 * and capability checks remain in erankly_render_settings_page().
 */
function erankly_admin_requested_settings_tab(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	return isset( $_GET['erankly_tab'] )
		? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) )
		: 'features';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Resolves a requested settings tab to a panel that can exist in this context. Unknown slugs are preserved for
 * extension tabs registered through the public erankly_settings_tabs filter.
 */
function erankly_admin_resolve_settings_tab( string $requested_tab ): string {
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();
	// Keep bookmarked URLs for the former SEO tabs working.
	if ( in_array( $requested_tab, array( 'general', 'social', 'schema', 'advanced' ), true ) ) {
		$requested_tab = 'seo';
	}
	if ( $is_site_admin_on_network && 'seo' === $requested_tab ) {
		$requested_tab = 'special-pages';
	}

	if ( $is_site_admin_on_network ) {
		$site_tabs = array( 'features' );

		if ( erankly_seo_enabled() && ! erankly_use_site_editor_special_page_panels() ) {
			$site_tabs[] = 'special-pages';
		}
		if ( erankly_redirects_enabled() ) {
			$site_tabs[] = 'redirects';
		}
		if ( erankly_tools_enabled() ) {
			$site_tabs[] = 'tools';
		}

		/** Filters the per-site settings tabs available on Multisite. */
		$site_tabs = apply_filters( 'erankly_admin_site_settings_tabs', $site_tabs );
		$site_tabs = is_array( $site_tabs ) ? array_values( array_filter( $site_tabs, 'is_string' ) ) : array();

		return in_array( $requested_tab, $site_tabs, true )
			? $requested_tab
			: ( $site_tabs[0] ?? '' );
	}

	$unavailable = (
		( 'seo' === $requested_tab && ! erankly_seo_enabled() )
		|| ( 'sitemap' === $requested_tab && ! erankly_sitemap_enabled() )
		|| ( 'redirects' === $requested_tab && ( is_network_admin() || ! erankly_redirects_enabled() ) )
		|| ( 'custom-code' === $requested_tab && ! erankly_custom_code_enabled() )
		|| ( 'special-pages' === $requested_tab )
		|| ( 'tools' === $requested_tab && ( is_network_admin() || ! erankly_tools_enabled() ) )
	);

	return $unavailable ? 'features' : $requested_tab;
}

function erankly_admin_register_settings_page(): void {
	$hook = add_options_page(
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);

	erankly_admin_hook_settings_tab_canonicalization( $hook );
}

/**
 * Hooks the canonical tab redirect on a settings screen.
 *
 * @param string|false $hook Page hook suffix returned by the menu registration.
 */
function erankly_admin_hook_settings_tab_canonicalization( $hook ): void {
	if ( ! is_string( $hook ) || '' === $hook ) {
		return;
	}

	add_action( 'load-' . $hook, 'erankly_admin_canonicalize_settings_tab' );
}

/**
 * Sends the browser to the tab that will actually render.
 *
 * The resolver silently substitutes a tab that is not available here (a disabled module, Redirects in Network
 * Admin). Without this the address bar kept naming the requested tab
 * while a different panel was on screen, so bookmarks and copied links pointed at a page that never renders.
 */
function erankly_admin_canonicalize_settings_tab(): void {
	$requested = erankly_admin_requested_settings_tab();

	if ( '' === $requested ) {
		return;
	}

	$resolved = erankly_admin_resolve_settings_tab( $requested );

	// A resolved tab that would itself resolve elsewhere would bounce forever.
	if ( '' === $resolved || $resolved === $requested || erankly_admin_resolve_settings_tab( $resolved ) !== $resolved ) {
		return;
	}

	wp_safe_redirect( add_query_arg( 'erankly_tab', $resolved ) );
	exit;
}

/** Registers the Network Admin settings menu. */
function erankly_admin_register_network_settings_page(): void {
	$hook = add_submenu_page(
		'settings.php',
		__( 'EasyRankly', 'easyrankly' ),
		__( 'EasyRankly', 'easyrankly' ),
		'manage_network_options',
		'erankly',
		'erankly_admin_render_settings_page'
	);

	erankly_admin_hook_settings_tab_canonicalization( $hook );
}

/**
 * Registers per-site settings on Multisite. The manager remains available with every module disabled;
 * module switches and Import/Export stay network-admin-only.
 */
function erankly_admin_register_site_settings_page(): void {
	erankly_admin_register_settings_page();
}

function erankly_admin_render_settings_page(): void {
	erankly_admin_load_settings_modules();

	$tab          = erankly_admin_requested_settings_tab();
	$resolved_tab = erankly_admin_resolve_settings_tab( $tab );

	// Load against the *resolved* tab: assets already do. Keying off the raw
	// query value meant a bookmark to a tab that resolves elsewhere rendered
	// the panel without its module, while the module scripts were enqueued anyway.
	if ( 'import-export' === $resolved_tab ) {
		erankly_admin_load_import_export_module();
	} elseif ( 'tools' === $resolved_tab ) {
		erankly_admin_load_tools_module();
	}

	erankly_render_settings_page();
}

/** Loads the settings registration callback only for relevant requests. */
function erankly_admin_maybe_register_settings(): void {
	global $pagenow;

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.

	if ( 'options.php' !== $pagenow && 'erankly' !== $page ) {
		return;
	}

	erankly_admin_load_settings_modules();
	erankly_register_settings();
}

/** Loads and handles the Network Admin save action. */
function erankly_admin_save_network_settings(): void {
	erankly_admin_load_settings_modules();
	erankly_save_network_settings();
}

function erankly_admin_save_site_special_meta(): void {
	erankly_admin_load_settings_modules();
	erankly_save_site_special_meta();
}

/** Loads post editor code only when WordPress registers meta boxes. */
function erankly_admin_register_meta_boxes(): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_meta_box();
}

/** Loads taxonomy editor code only on taxonomy screens. */
function erankly_admin_maybe_register_taxonomy_fields(): void {
	global $pagenow;

	if ( ! in_array( $pagenow, array( 'edit-tags.php', 'term.php' ), true ) ) {
		return;
	}

	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_register_taxonomy_fields();
}

/** Loads post meta saving code only when a post is actually saved. */
function erankly_admin_save_meta_box( int $post_id, WP_Post $post ): void {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'admin/meta-box.php';
	erankly_save_meta_box( $post_id, $post );
}

/** Loads import/export code only for its settings request. */
function erankly_admin_maybe_handle_import_export(): void {
	$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
	$tab        = erankly_admin_requested_settings_tab();
	$has_action = isset( $_GET['erankly_io_action'] ) || isset( $_POST['erankly_io_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- The module verifies the action-specific nonce before mutation.

	if ( ! $has_action && ( 'erankly' !== $page || 'import-export' !== $tab ) ) {
		return;
	}
	$required_cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $required_cap ) ) {
		return;
	}

	// Load the full settings modules, not just import-export.php: a JSON import
	// restores settings through erankly_sanitize_settings(), which lives in
	// settings-page.php. On Multisite no other admin_init callback loads it, so
	// requiring only the import module would silently skip the settings restore.
	erankly_admin_load_settings_modules();
	erankly_admin_load_import_export_module();
	erankly_import_export_handle_actions();
}

/** @return array<int,string> */
function erankly_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, admin_url( 'options-general.php?page=erankly' ) );
}

/**
 * Adds plugin action links in the Network Admin plugins list.
 *
 * @return array<int,string>
 */
function erankly_network_plugin_action_links( array $links ): array {
	return erankly_add_plugin_action_links( $links, network_admin_url( 'settings.php?page=erankly' ) );
}

/**
 * @param string            $settings_url Settings page URL for the current context.
 * @return array<int,string>
 */
function erankly_add_plugin_action_links( array $links, string $settings_url ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'easyrankly' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Renders the shared expand/collapse toggle button for an expandable table panel (see .erankly-panel-* in
 * admin-core.css and bindExpandablePanel() in admin.js). Used by the Redirects table.
 *
 * @param string $target_id ID of the [data-erankly-expandable] section it controls.
 */
function erankly_admin_render_panel_expand_toggle( string $target_id ): void {
	?>
	<button type="button" class="button erankly-panel-expand-toggle" data-erankly-expand-toggle aria-pressed="false" aria-controls="<?php echo esc_attr( $target_id ); ?>" title="<?php esc_attr_e( 'Expand table', 'easyrankly' ); ?>">
		<svg class="erankly-panel-expand-icon-expand" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M15.5 21C16.8956 21 17.5933 21 18.1611 20.8278C19.4395 20.44 20.44 19.4395 20.8278 18.1611C21 17.5933 21 16.8956 21 15.5M21 8.5C21 7.10444 21 6.40666 20.8278 5.83886C20.44 4.56046 19.4395 3.56004 18.1611 3.17224C17.5933 3 16.8956 3 15.5 3M8.5 21C7.10444 21 6.40666 21 5.83886 20.8278C4.56046 20.44 3.56004 19.4395 3.17224 18.1611C3 17.5933 3 16.8956 3 15.5M3 8.5C3 7.10444 3 6.40666 3.17224 5.83886C3.56004 4.56046 4.56046 3.56004 5.83886 3.17224C6.40666 3 7.10444 3 8.5 3" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/></svg>
		<svg class="erankly-panel-expand-icon-collapse" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M11.4333 16.0659L8.6912 15.9658C8.28365 15.951 7.96094 15.6163 7.96094 15.2084L7.96094 12.5936M13.4609 10.5659L8.41716 15.5843" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path d="M22 7C22 8.8856 22 9.8284 21.4142 10.4142C20.8284 11 19.8856 11 18 11H17C15.1144 11 14.1716 11 13.5858 10.4142C13 9.8284 13 8.8856 13 7L13 6C13 4.1144 13 3.1716 13.5858 2.5858C14.1716 2 15.1144 2 17 2L18 2C19.8856 2 20.8284 2 21.4142 2.5858C22 3.1716 22 4.1144 22 6V7Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"/><path d="M22 15.5V13.5M10 22H14M2 10L2 14M10.5 2L8.5 2M21.9401 18.5C21.7861 19.5656 21.4865 20.321 20.9037 20.9038C20.321 21.4865 19.5656 21.7861 18.5 21.9401M5.5 21.9401C4.4344 21.7861 3.679 21.4865 3.0963 20.9037C2.5135 20.321 2.2139 19.5656 2.0599 18.5M2.0599 5.5C2.2139 4.4344 2.5135 3.679 3.0963 3.0963C3.679 2.5135 4.4344 2.2139 5.5 2.0599" stroke="currentColor" stroke-linecap="round" stroke-width="1.5"/></svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Expand table', 'easyrankly' ); ?></span>
	</button>
	<?php
}

/** Enqueues shared design tokens and cross-surface components. */
function erankly_enqueue_shared_styles(): void {
	wp_enqueue_style(
		'erankly-shared',
		ERANKLY_URL . 'assets/css/shared.css',
		array(),
		ERANKLY_VERSION
	);
}

/**
 * Enqueues the modular admin script bundle. Modules attach helpers to `window.ERanklyAdmin`; admin.js bootstraps
 * them on DOMContentLoaded. Returns the bootstrap handle for localize_script().
 */
function erankly_admin_enqueue_scripts( array $requested_modules ): string {
	$registry = array(
		'media'     => array( 'erankly-admin-media', 'admin-media.js' ),
		'tabs'      => array( 'erankly-admin-tabs', 'admin-tabs.js' ),
		'fields'    => array( 'erankly-admin-fields', 'admin-fields.js' ),
		'variables' => array( 'erankly-admin-variables', 'admin-variables.js' ),
		'schema'    => array( 'erankly-admin-schema', 'admin-schema.js' ),
		'schema-builder' => array( 'erankly-admin-schema', 'admin-schema.js' ),
		'code-builder' => array( 'erankly-admin-schema', 'admin-schema.js' ),
		'blocks'    => array( 'erankly-admin-schema', 'admin-schema.js' ),
		'widgets'   => array( 'erankly-admin-widgets', 'admin-widgets.js' ),
		'identity'  => array( 'erankly-admin-identity', 'admin-identity.js' ),
		'user-search' => array( 'erankly-admin-user-search', 'admin-user-search.js' ),
		'local-business' => array( 'erankly-admin-widgets', 'admin-widgets.js' ),
		'settings'  => array( 'erankly-admin-settings', 'admin-settings.js' ),
		'panels'    => array( 'erankly-admin-panels', 'admin-panels.js' ),
		'tools'     => array( 'erankly-admin-tools', 'admin-tools.js' ),
	);
	$deps     = array();
	$selected = array_values( array_unique( $requested_modules ) );

	foreach ( $selected as $module ) {
		if ( ! isset( $registry[ $module ] ) ) {
			continue;
		}

		list( $handle, $file ) = $registry[ $module ];
		$module_deps = array();

		// Preserve complete legacy modules for editor and extension callers.
		if ( in_array( $module, array( 'schema', 'blocks', 'widgets' ), true ) ) {
			$module_deps[] = erankly_admin_enqueue_module_dependency( 'schema' === $module || 'blocks' === $module ? 'identity' : 'user-search' );
		}

		if ( in_array( $module, array( 'schema', 'blocks', 'schema-builder' ), true ) ) {
			wp_enqueue_script(
				'erankly-schema-jsonld',
				ERANKLY_URL . 'assets/js/schema-jsonld.js',
				array( 'wp-i18n' ),
				ERANKLY_VERSION,
				true
			);
			wp_set_script_translations( 'erankly-schema-jsonld', 'easyrankly', ERANKLY_PATH . 'languages' );
			$module_deps[] = 'wp-i18n';
			$module_deps[] = 'erankly-schema-jsonld';
		}

		wp_enqueue_script(
			$handle,
			ERANKLY_URL . 'assets/js/' . $file,
			$module_deps,
			ERANKLY_VERSION,
			true
		);
		if ( 'erankly-admin-schema' === $handle ) {
			wp_set_script_translations( 'erankly-admin-schema', 'easyrankly', ERANKLY_PATH . 'languages' );
		}
		$deps[] = $handle;
	}

	wp_enqueue_script(
		'erankly-admin',
		ERANKLY_URL . 'assets/js/admin.js',
		$deps,
		ERANKLY_VERSION,
		true
	);

	return 'erankly-admin';
}

/** Enqueues the extracted part of a legacy module, retaining its public helpers. */
function erankly_admin_enqueue_module_dependency( string $module ): string {
	$handle = 'erankly-admin-' . $module;
	wp_enqueue_script( $handle, ERANKLY_URL . 'assets/js/admin-' . $module . '.js', array(), ERANKLY_VERSION, true );
	return $handle;
}

/** @return array<int,string> */
function erankly_admin_asset_modules( string $surface ): array {
	$settings_panels = erankly_admin_settings_registry();

	if ( str_starts_with( $surface, 'settings:' ) ) {
		$tab = substr( $surface, strlen( 'settings:' ) );

		// Add-on tabs historically received the complete bundle. Keep that public
		// compatibility surface while core tabs use the strict manifest above.
		$modules = $settings_panels[ $tab ]['modules'] ?? array_keys(
			array(
				'media'     => true,
				'tabs'      => true,
				'fields'    => true,
				'variables' => true,
				'schema'    => true,
				'widgets'   => true,
				'settings'  => true,
				'panels'    => true,
			)
		);
	} else {
		$surfaces = array(
			'classic-editor' => array( 'media', 'fields', 'variables', 'schema', 'panels' ),
			'taxonomy'       => array( 'media', 'fields', 'variables', 'schema', 'panels' ),
		);

		$modules = $surfaces[ $surface ] ?? array();
	}

	/** @param string            $surface Surface identifier such as "settings:general". */
	$modules = apply_filters( 'erankly_admin_asset_modules', $modules, $surface );

	return is_array( $modules ) ? array_values( array_filter( $modules, 'is_string' ) ) : array();
}

require_once ERANKLY_PATH . 'admin/assets/settings.php';
require_once ERANKLY_PATH . 'admin/assets/editor.php';
