<?php
/**
 * Settings page orchestrator: server-side tab/subtab routing (network vs per-site scoping on Multisite),
 * panel data preparation, panel dispatch. Loads renderer files only for the active panel; the noscript
 * submit button is the no-JS save path and must not be hidden on autosave panels.
 */
defined( 'ABSPATH' ) || exit;
function erankly_render_settings_page(): void {
	$required_cap = is_network_admin() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $required_cap ) ) {
		return;
	}
	$settings = erankly_get_settings();
	$page     = erankly_resolve_settings_page_state( $settings );
	if ( in_array( $page['active_panel'], array( 'settings-seo', 'settings-special-pages', 'settings-custom-code' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
	}
	if ( in_array( $page['active_panel'], array( 'settings-seo', 'settings-special-pages' ), true ) ) {
		require_once ERANKLY_PATH . 'admin/settings/renderers.php';
	}
	?>
	<div class="wrap erankly-settings"<?php echo ! empty( $settings['enable_local_business'] ) ? ' data-erankly-local-business-enabled="1"' : ''; ?>>
		<?php erankly_render_settings_page_notices( $page['is_site_admin_on_network'] ); ?>
		<div class="erankly-settings-layout">
			<?php erankly_render_settings_sidebar( $page ); ?>
			<div class="erankly-settings-content">
				<?php erankly_render_settings_panels( $settings, $page ); ?>
			</div>
		</div>
	</div>
	<?php
}
/**
 * Resolves which tabs this screen shows and which panel/subtab is active. Multisite site admins only see the
 * site-scoped panels; unavailable modules fall back to Features.
 *
 * @return array<string,mixed>
 */
function erankly_resolve_settings_page_state( array $settings ): array {
	$is_site_admin_on_network = is_multisite() && ! is_network_admin();
	$page                     = array(
		'is_site_admin_on_network' => $is_site_admin_on_network,
		'redirects_enabled'        => erankly_redirects_enabled(),
		'sitemap_enabled'          => erankly_sitemap_enabled(),
		'custom_code_enabled'      => erankly_custom_code_enabled(),
		'show_site_special_tab'    => $is_site_admin_on_network && erankly_seo_enabled() && ! erankly_use_site_editor_special_page_panels(),
		'show_import_export_tab'   => ! is_multisite() || is_network_admin(),
		'show_tools_tab'           => erankly_tools_enabled() && ! is_network_admin(),
		'feature_module_tabs'      => array(),
		'additional_tabs'          => array(),
	);
	$page['show_redirects_tab']   = $page['redirects_enabled'] && ! is_network_admin();
	$page['show_seo_tab']         = erankly_seo_enabled() && ( ! $is_site_admin_on_network || $page['show_site_special_tab'] );
	$page['show_sitemap_tab']     = ! $is_site_admin_on_network && $page['sitemap_enabled'];
	$page['show_custom_code_tab'] = ! $is_site_admin_on_network && $page['custom_code_enabled'];

	$site_panels = array( 'settings-features' );
	if ( $page['show_site_special_tab'] ) {
		$site_panels[] = 'settings-special-pages';
	}
	if ( $page['show_redirects_tab'] ) {
		$site_panels[] = 'settings-redirects';
	}
	if ( $page['show_tools_tab'] ) {
		$site_panels[] = 'settings-tools';
	}
	$requested_tab    = isset( $_GET['erankly_tab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing for display; no state change (capability checked by the caller).
	$requested_subtab = isset( $_GET['erankly_subtab'] ) ? sanitize_key( wp_unslash( $_GET['erankly_subtab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only subtab routing for display; no state change.
	$screen           = get_current_screen();

	$page['screen_context'] = array(
		'screen_id'   => $screen instanceof WP_Screen ? $screen->id : '',
		'scope'       => is_network_admin() ? 'network' : 'site',
		'current_tab' => $requested_tab,
	);
	if ( '' !== $requested_tab ) {
		$requested_tab = erankly_admin_resolve_settings_tab( $requested_tab );
	}
	$page['extra_tabs'] = erankly_normalize_settings_tabs(
		apply_filters( 'erankly_settings_tabs', array(), $page['screen_context'] ),
		$page['screen_context']
	);
	$tab_panel_map = array();
	foreach ( erankly_admin_settings_registry() as $slug => $panel ) {
		$tab_panel_map[ $slug ] = 'settings-' . $slug;
	}
	foreach ( $page['extra_tabs'] as $extra_slug => $extra_tab ) {
		$group                         = 'feature_modules' === ( $extra_tab['group'] ?? '' ) ? 'feature_module_tabs' : 'additional_tabs';
		$page[ $group ][ $extra_slug ] = $extra_tab;
		$tab_panel_map[ $extra_slug ]  = 'settings-' . $extra_slug;
		if ( $is_site_admin_on_network ) {
			$site_panels[] = 'settings-' . $extra_slug;
		}
	}
	$page['show_feature_modules_nav'] = $page['show_seo_tab'] || $page['show_redirects_tab'] || $page['show_sitemap_tab'] || $page['show_custom_code_tab'] || $page['show_tools_tab'] || ! empty( $page['feature_module_tabs'] );

	$default_panel = 'settings-features';
	$active_panel  = $default_panel;
	$active_subtab = '';
	if ( '' !== $requested_tab && isset( $tab_panel_map[ $requested_tab ] ) ) {
		$candidate = $tab_panel_map[ $requested_tab ];
		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel = $candidate;
		}
	}
	$subtab_panel_map = '' !== $requested_subtab ? erankly_settings_subtab_panel_map( $settings, $is_site_admin_on_network, $page['show_site_special_tab'] ) : array();
	if ( isset( $subtab_panel_map[ $requested_subtab ] ) ) {
		$candidate = $subtab_panel_map[ $requested_subtab ];
		if ( ! $is_site_admin_on_network || in_array( $candidate, $site_panels, true ) ) {
			$active_panel  = $candidate;
			$active_subtab = $requested_subtab;
		}
	}
	$unavailable = ( 'settings-seo' === $active_panel && ! $page['show_seo_tab'] )
		|| ( 'settings-tools' === $active_panel && ! $page['show_tools_tab'] )
		|| ( 'settings-sitemap' === $active_panel && ! $page['show_sitemap_tab'] )
		|| ( 'settings-redirects' === $active_panel && ! $page['show_redirects_tab'] )
		|| ( 'settings-custom-code' === $active_panel && ! $page['show_custom_code_tab'] );
	if ( $unavailable ) {
		$active_panel  = $is_site_admin_on_network ? ( $site_panels[0] ?? '' ) : 'settings-features';
		$active_subtab = '';
	}
	if ( $is_site_admin_on_network && ! in_array( $active_panel, $site_panels, true ) ) {
		$active_panel  = $site_panels[0] ?? '';
		$active_subtab = '';
	}
	$page['active_panel']  = $active_panel;
	$page['active_subtab'] = $active_subtab;

	$standalone_panels = array( 'settings-import-export', 'settings-redirects', 'settings-special-pages', 'settings-tools' );
	foreach ( array_keys( $page['extra_tabs'] ) as $extra_slug ) {
		$standalone_panels[] = 'settings-' . $extra_slug;
	}
	$page['show_settings_submit'] = ! $is_site_admin_on_network && ! in_array( $active_panel, $standalone_panels, true );

	return $page;
}
/**
 * Maps every reachable subtab (post type, taxonomy and special page rows) to its panel.
 *
 * @return array<string,string> Subtab slug => panel.
 */
function erankly_settings_subtab_panel_map( array $settings, bool $is_site_admin_on_network, bool $show_site_special_tab ): array {
	$special_page_items = erankly_special_page_keys();
	$groups             = array(
		'settings-special-pages' => $show_site_special_tab ? erankly_get_special_page_nav_subtabs( $special_page_items ) : array(),
	);
	if ( erankly_seo_enabled() && ! $is_site_admin_on_network ) {
		$general_subtabs = array_merge(
			erankly_get_global_meta_nav_subtabs(
				'global_post_type_meta',
				erankly_get_public_post_types(),
				! array_key_exists( 'global_post_type_meta_linked', $settings ) || ! empty( $settings['global_post_type_meta_linked'] )
			),
			erankly_get_global_meta_nav_subtabs(
				'global_taxonomy_meta',
				erankly_get_public_taxonomies(),
				! array_key_exists( 'global_taxonomy_meta_linked', $settings ) || ! empty( $settings['global_taxonomy_meta_linked'] )
			)
		);
		if ( ! is_multisite() && ! erankly_use_site_editor_special_page_panels() ) {
			$general_subtabs = array_merge( $general_subtabs, erankly_get_special_page_nav_subtabs( $special_page_items ) );
		}
		$groups['settings-seo'] = $general_subtabs;
	}
	$map = array();
	foreach ( $groups as $panel => $items ) {
		foreach ( $items as $item ) {
			if ( empty( $item['disabled'] ) ) {
				$map[ $item['subtab'] ] = $panel;
			}
		}
	}

	return $map;
}
/** Prints the queued save notices and the network scope notice. */
function erankly_render_settings_page_notices( bool $is_site_admin_on_network ): void {
	$user_id        = get_current_user_id();
	$stored_notices = $user_id > 0 ? get_transient( 'erankly_settings_notices_' . $user_id ) : false;
	if ( is_array( $stored_notices ) ) {
		delete_transient( 'erankly_settings_notices_' . $user_id );
		foreach ( $stored_notices as $notice ) {
			if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
				continue;
			}
			$type = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'error' : 'warning';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( (string) $notice['message'] )
			);
		}
	}
	if ( is_network_admin() ) {
		if ( isset( $_GET['erankly_settings_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for incomplete-save notice.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Settings were saved, but the configuration is incomplete.', 'easyrankly' ) . '</p></div>';
		} elseif ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for the save confirmation notice.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
		}
	} else {
		if ( $is_site_admin_on_network && isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag for the save confirmation notice.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'easyrankly' ) . '</p></div>';
		}
		settings_errors( ERANKLY_OPTION );
	}
	?>
	<?php if ( $is_site_admin_on_network ) : ?>
	<div class="notice notice-info">
		<p>
			<?php
			printf(
				/* translators: %s: Network Admin settings URL. */
				esc_html__( 'Feature modules and global settings are managed from the %s.', 'easyrankly' ),
				'<a href="' . esc_url( network_admin_url( 'settings.php?page=erankly' ) ) . '">' . esc_html__( 'Network Admin', 'easyrankly' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php endif; ?>
	<?php
}
/**
 * Prints the sidebar navigation.
 *
 * @param array<string,mixed> $page State from erankly_resolve_settings_page_state().
 */
function erankly_render_settings_sidebar( array $page ): void {
	$active_panel             = $page['active_panel'];
	$is_site_admin_on_network = $page['is_site_admin_on_network'];
	?>
	<div class="erankly-settings-sidebar-nav" data-erankly-sidebar-nav>
		<h1><?php esc_html_e( 'EasyRankly', 'easyrankly' ); ?></h1>
		<button type="button" class="erankly-settings-sidebar-toggle" aria-expanded="false" data-erankly-sidebar-toggle>
			<span data-erankly-sidebar-toggle-label></span>
		</button>
		<nav class="erankly-settings-nav-tablist" aria-label="<?php esc_attr_e( 'Plugin settings', 'easyrankly' ); ?>" data-erankly-settings-tablist data-erankly-server-tabs data-erankly-active-panel="<?php echo esc_attr( $active_panel ); ?>" data-erankly-active-subtab="<?php echo esc_attr( $page['active_subtab'] ); ?>">
			<?php erankly_render_settings_nav_link( 'features', __( 'Features', 'easyrankly' ), $active_panel ); ?>
		<?php if ( $page['show_import_export_tab'] ) : ?>
			<?php erankly_render_settings_nav_link( 'import-export', __( 'Import / Export', 'easyrankly' ), $active_panel ); ?>
		<?php endif; ?>
		<?php if ( $page['show_feature_modules_nav'] ) : ?>
		<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-feature-modules">
			<span class="erankly-settings-nav-heading" id="erankly-settings-nav-feature-modules"><?php esc_html_e( 'Feature modules', 'easyrankly' ); ?></span>
			<?php if ( $page['show_seo_tab'] ) : ?>
				<?php erankly_render_settings_nav_link( $page['show_site_special_tab'] ? 'special-pages' : 'seo', __( 'SEO', 'easyrankly' ), $active_panel ); ?>
			<?php endif; ?>
			<?php if ( $page['show_redirects_tab'] ) : ?>
				<?php erankly_render_settings_nav_link( 'redirects', __( 'Redirects', 'easyrankly' ), $active_panel ); ?>
			<?php endif; ?>
			<?php if ( $page['show_sitemap_tab'] ) : ?>
				<?php erankly_render_settings_nav_link( 'sitemap', __( 'Sitemap', 'easyrankly' ), $active_panel ); ?>
			<?php endif; ?>
			<?php if ( $page['show_custom_code_tab'] ) : ?>
				<?php erankly_render_settings_nav_link( 'custom-code', __( 'Custom code', 'easyrankly' ), $active_panel ); ?>
			<?php endif; ?>
			<?php if ( $page['show_tools_tab'] ) : ?>
				<?php erankly_render_settings_nav_link( 'tools', __( 'Tools', 'easyrankly' ), $active_panel ); ?>
			<?php endif; ?>
			<?php foreach ( $page['feature_module_tabs'] as $extra_slug => $extra_tab ) : ?>
				<?php erankly_render_settings_nav_link( $extra_slug, $extra_tab['label'], $active_panel ); ?>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $page['additional_tabs'] ) ) : ?>
		<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-modules">
			<span class="erankly-settings-nav-heading" id="erankly-settings-nav-modules"><?php esc_html_e( 'Additional Modules', 'easyrankly' ); ?></span>
			<?php foreach ( $page['additional_tabs'] as $extra_slug => $extra_tab ) : ?>
				<?php erankly_render_settings_nav_link( $extra_slug, $extra_tab['label'], $active_panel ); ?>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="erankly-settings-nav-section" role="group" aria-labelledby="erankly-settings-nav-useful-resources">
			<span class="erankly-settings-nav-heading" id="erankly-settings-nav-useful-resources"><?php esc_html_e( 'Useful resources', 'easyrankly' ); ?></span>
			<a class="erankly-settings-nav-item" href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-settings-nav', 'https://docs.easyrankly.com/' ) ); ?>" target="_blank" rel="noopener noreferrer"><span class="erankly-settings-nav-label"><?php esc_html_e( 'Documentation', 'easyrankly' ); ?></span></a>
			<a class="erankly-settings-nav-item" href="<?php echo esc_url( add_query_arg( 'utm_source', 'easyrankly-settings-nav', 'https://easyrankly.com/help/' ) ); ?>" target="_blank" rel="noopener noreferrer"><span class="erankly-settings-nav-label"><?php esc_html_e( 'Need help?', 'easyrankly' ); ?></span></a>
		</div>
		</nav>
		<span class="erankly-autosave-status" data-erankly-autosave-status aria-live="polite"></span>
	</div>
	<?php
}
/**
 * Prints the settings form with the active panel, and the standalone panels that live outside it.
 *
 * @param array<string,mixed> $page State from erankly_resolve_settings_page_state().
 */
function erankly_render_settings_panels( array $settings, array $page ): void {
	$active_panel             = $page['active_panel'];
	$is_site_admin_on_network = $page['is_site_admin_on_network'];
	?>
	<?php if ( is_network_admin() ) : ?>
	<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=erankly_network_save' ) ); ?>">
		<?php wp_nonce_field( 'erankly_network_settings' ); ?>
	<?php elseif ( ! $is_site_admin_on_network ) : ?>
	<form method="post" action="options.php">
		<?php settings_fields( 'erankly' ); ?>
	<?php endif; ?>
		<input type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[erankly_settings_panel]" value="<?php echo esc_attr( erankly_active_panel_submission_slug( $active_panel ) ); ?>">
		<?php erankly_render_active_settings_form_panel( $settings, $page ); ?>
		<?php if ( $page['show_settings_submit'] ) : ?>
		<noscript>
			<div class="erankly-settings-submit">
				<?php submit_button(); ?>
			</div>
		</noscript>
		<?php endif; ?>
	<?php if ( ! $is_site_admin_on_network ) : ?>
	</form>
	<?php endif; ?>
	<?php if ( $page['show_site_special_tab'] && 'settings-special-pages' === $active_panel ) : ?>
	<div class="erankly-settings-panel is-active" id="erankly-settings-panel-special-pages" role="region" aria-labelledby="erankly-settings-tab-special-pages" data-erankly-settings-panel="settings-special-pages">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'erankly_site_special_meta' ); ?>
			<input type="hidden" name="action" value="erankly_save_site_special_meta">
			<?php erankly_section_open( __( 'Special pages and archives', 'easyrankly' ), array( 'doc' => 'special-pages', 'card' => false ) ); ?>
				<?php erankly_render_special_page_defaults( erankly_special_page_keys(), array( 'global_special_meta' => erankly_get_site_special_meta() ) ); ?>
			<?php erankly_section_close(); ?>
		</form>
	</div>
	<?php endif; ?>
	<?php if ( $page['show_import_export_tab'] && 'settings-import-export' === $active_panel && function_exists( 'erankly_import_export_render_panel' ) ) : ?>
	<div class="erankly-settings-panel is-active" id="erankly-settings-panel-import-export" role="region" aria-labelledby="erankly-settings-tab-import-export" data-erankly-settings-panel="settings-import-export">
		<?php erankly_import_export_render_panel(); ?>
	</div>
	<?php endif; ?>
	<?php if ( $page['show_redirects_tab'] && 'settings-redirects' === $active_panel && function_exists( 'erankly_redirects_render_panel' ) ) : ?>
	<div class="erankly-settings-panel is-active" role="region" aria-labelledby="erankly-settings-tab-redirects" data-erankly-settings-panel="settings-redirects">
		<?php erankly_redirects_render_panel(); ?>
	</div>
	<?php endif; ?>
	<?php if ( $page['show_tools_tab'] && 'settings-tools' === $active_panel && function_exists( 'erankly_tools_render_panel' ) ) : ?>
	<div class="erankly-settings-panel is-active" id="erankly-settings-panel-tools" role="region" aria-labelledby="erankly-settings-tab-tools" data-erankly-settings-panel="settings-tools">
		<?php erankly_tools_render_panel(); ?>
	</div>
	<?php endif; ?>
	<?php
	foreach ( $page['extra_tabs'] as $extra_slug => $extra_tab ) :
		$extra_panel = 'settings-' . $extra_slug;
		if ( $extra_panel !== $active_panel || ! current_user_can( $extra_tab['capability'] ) ) {
			continue;
		}
		?>
	<div class="erankly-settings-panel is-active" id="erankly-settings-panel-<?php echo esc_attr( $extra_slug ); ?>" role="region" aria-labelledby="erankly-settings-tab-<?php echo esc_attr( $extra_slug ); ?>" data-erankly-settings-panel="<?php echo esc_attr( $extra_panel ); ?>">
		<?php do_action( 'erankly_render_settings_tab_' . $extra_slug, $page['screen_context'] ); ?>
	</div>
	<?php endforeach; ?>
	<?php
}
/**
 * Renders the active panel that saves through the main settings form.
 *
 * @param array<string,mixed> $page State from erankly_resolve_settings_page_state().
 */
function erankly_render_active_settings_form_panel( array $settings, array $page ): void {
	switch ( $page['active_panel'] ) {
		case 'settings-features':
			erankly_render_settings_panel_features( $settings, $page['redirects_enabled'], $page['sitemap_enabled'], $page['custom_code_enabled'] );
			break;
		case 'settings-seo':
			erankly_render_settings_panel_seo( $settings );
			break;
		case 'settings-sitemap':
			if ( $page['sitemap_enabled'] ) {
				erankly_load_sitemap_helpers();
				erankly_render_settings_panel_sitemap( $settings );
			}
			break;
		case 'settings-custom-code':
			if ( $page['custom_code_enabled'] ) {
				$blocks = array();
				foreach ( array( 'head_code_blocks', 'body_open_code_blocks', 'body_close_code_blocks' ) as $key ) {
					$blocks[] = is_array( $settings[ $key ] ?? null ) ? array_values( $settings[ $key ] ) : array();
					$blocks[] = ERANKLY_OPTION . '[' . $key . ']';
				}
				erankly_render_settings_panel_custom_code( ...$blocks );
			}
			break;
	}
}
