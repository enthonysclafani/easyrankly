<?php
/** Admin bootstrap, settings routing, meta box wrappers, plugin action links and the admin asset enqueuers. */

final class ERankly_Admin_Menu_Test extends WP_UnitTestCase {

	/** @var int */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ERANKLY_PATH . 'includes/admin.php';
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		require_once ERANKLY_PATH . 'admin/settings/nav-icons.php';
		require_once ERANKLY_PATH . 'admin/settings/renderers.php';
		require_once ERANKLY_PATH . 'admin/settings/panels.php';
		require_once ERANKLY_PATH . 'admin/settings-page.php';
		require_once ERANKLY_PATH . 'admin/settings/page-renderer.php';
		require_once ERANKLY_PATH . 'admin/meta-box.php';
		erankly_load_content_helpers();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['post'], $GLOBALS['current_screen'] );
		erankly_clear_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Captures a render callback's output so it never reaches the test runner. */
	private function capture( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/** Persists a full settings map and clears the request cache. */
	private function store_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	/**
	 * Returns the first load-* hook carrying the tab canonicalizer.
	 *
	 * The exact hook suffix depends on the admin menu registry, which is empty under PHPUnit
	 * (get_plugin_page_hookname() falls back to the generic "admin_page_" prefix), so the tests
	 * assert that the callback is registered on a load screen without pinning the prefix.
	 */
	private function canonicalization_hook(): ?string {
		foreach ( array_keys( $GLOBALS['wp_filter'] ) as $hook ) {
			if ( is_string( $hook ) && str_starts_with( $hook, 'load-' ) && false !== has_action( $hook, 'erankly_admin_canonicalize_settings_tab' ) ) {
				return $hook;
			}
		}

		return null;
	}

	public function test_site_editor_panels_support_tracks_the_wordpress_version(): void {
		$original = $GLOBALS['wp_version'];

		try {
			$GLOBALS['wp_version'] = '6.5';
			$this->assertFalse( erankly_site_editor_special_page_panels_supported() );

			$GLOBALS['wp_version'] = '6.6';
			$this->assertTrue( erankly_site_editor_special_page_panels_supported() );
		} finally {
			$GLOBALS['wp_version'] = $original;
		}
	}

	public function test_use_site_editor_panels_requires_a_block_theme(): void {
		// The PHPUnit environment runs a classic theme, so the Site Editor panels are unavailable.
		$this->assertFalse( wp_is_block_theme() );
		$this->assertFalse( erankly_use_site_editor_special_page_panels() );
	}

	public function test_admin_bootstrap_registers_the_single_site_hooks(): void {
		erankly_admin_bootstrap();

		if ( is_multisite() ) {
			// Multisite registers the Network Admin save screen plus the per-site fallback page
			// instead of the single-site Settings-menu page and its admin_init registrars.
			$this->assertNotFalse( has_action( 'network_admin_menu', 'erankly_admin_register_network_settings_page' ) );
			$this->assertNotFalse( has_action( 'network_admin_edit_erankly_network_save', 'erankly_admin_save_network_settings' ) );
			$this->assertNotFalse( has_action( 'admin_menu', 'erankly_admin_register_site_settings_page' ) );
			$this->assertNotFalse( has_action( 'admin_post_erankly_save_site_special_meta', 'erankly_admin_save_site_special_meta' ) );
			$this->assertNotFalse( has_filter( 'network_admin_plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_network_plugin_action_links' ) );
		} else {
			$this->assertNotFalse( has_action( 'admin_menu', 'erankly_admin_register_settings_page' ) );
			$this->assertNotFalse( has_action( 'admin_init', 'erankly_admin_maybe_register_settings' ) );
			$this->assertNotFalse( has_filter( 'plugin_action_links_' . plugin_basename( ERANKLY_FILE ), 'erankly_plugin_action_links' ) );
		}

		// Hooks shared by both contexts.
		$this->assertNotFalse( has_action( 'admin_init', 'erankly_admin_maybe_register_taxonomy_fields' ) );
		$this->assertNotFalse( has_action( 'admin_init', 'erankly_admin_maybe_handle_import_export' ) );
		$this->assertNotFalse( has_action( 'admin_init', 'erankly_admin_maybe_handle_reset' ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', 'erankly_admin_register_meta_boxes' ) );
		$this->assertNotFalse( has_action( 'save_post', 'erankly_admin_save_meta_box' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', 'erankly_admin_enqueue_assets' ) );
	}

	public function test_admin_load_modules_pulls_in_the_renderers(): void {
		erankly_admin_load_settings_modules();

		$this->assertTrue( function_exists( 'erankly_render_settings_page' ) );
		$this->assertTrue( function_exists( 'erankly_render_settings_nav_link' ) );
		$this->assertTrue( function_exists( 'erankly_render_settings_panel_general' ) );
	}

	public function test_admin_load_import_export_and_reset_modules(): void {
		erankly_admin_load_import_export_module();
		$this->assertTrue( function_exists( 'erankly_import_export_render_panel' ) );

		erankly_admin_load_reset_module();
		$this->assertTrue( function_exists( 'erankly_reset_render_panel' ) );
	}

	public function test_requested_settings_tab_defaults_and_sanitizes(): void {
		$this->assertSame( 'general', erankly_admin_requested_settings_tab() );

		$_GET['erankly_tab'] = 'Schema Tab!';
		$this->assertSame( 'schematab', erankly_admin_requested_settings_tab() );
	}

	public function test_resolve_settings_tab_substitutes_unavailable_tabs(): void {
		$this->store_settings( array( 'simplified_mode' => 1 ) );

		if ( is_multisite() ) {
			// The per-site screen on Multisite exposes only the site-scoped tabs. With the
			// classic theme that is "special-pages" alone, so every other slug collapses onto it.
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'advanced' ) );
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'sitemap' ) );
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'special-pages' ) );
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'my-addon' ) );
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'general' ) );
			return;
		}

		// Advanced collapses to Settings while Simplified mode is on.
		$this->assertSame( 'settings', erankly_admin_resolve_settings_tab( 'advanced' ) );
		// Sitemap is disabled by default.
		$this->assertSame( 'features', erankly_admin_resolve_settings_tab( 'sitemap' ) );
		// Special-pages has no top-level tab on single site.
		$this->assertSame( 'features', erankly_admin_resolve_settings_tab( 'special-pages' ) );
		// Unknown extension slugs are preserved.
		$this->assertSame( 'my-addon', erankly_admin_resolve_settings_tab( 'my-addon' ) );
		$this->assertSame( 'general', erankly_admin_resolve_settings_tab( 'general' ) );
	}

	public function test_resolve_settings_tab_keeps_advanced_when_simplified_mode_is_off(): void {
		$this->store_settings( array( 'simplified_mode' => 0 ) );

		if ( is_multisite() ) {
			// The per-site screen has no Advanced tab, so it collapses onto the first site tab.
			$this->assertSame( 'special-pages', erankly_admin_resolve_settings_tab( 'advanced' ) );
			return;
		}

		$this->assertSame( 'advanced', erankly_admin_resolve_settings_tab( 'advanced' ) );
	}

	public function test_register_settings_page_adds_the_canonicalization_hook(): void {
		erankly_admin_register_settings_page();

		$this->assertNotNull( $this->canonicalization_hook() );
	}

	public function test_hook_settings_tab_canonicalization_ignores_an_empty_hook(): void {
		// Passing a non-string/empty hook must not register a session-less "load-" action.
		erankly_admin_hook_settings_tab_canonicalization( false );
		$this->assertFalse( has_action( 'load-', 'erankly_admin_canonicalize_settings_tab' ) );

		erankly_admin_hook_settings_tab_canonicalization( 'options-general.php' );
		$this->assertNotFalse( has_action( 'load-options-general.php', 'erankly_admin_canonicalize_settings_tab' ) );
	}

	public function test_register_network_settings_page_adds_the_canonicalization_hook(): void {
		// add_submenu_page() bails without the network capability, which single-site roles never get.
		$grant = static function ( array $allcaps ): array {
			$allcaps['manage_network_options'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant );

		try {
			erankly_admin_register_network_settings_page();

			$this->assertNotNull( $this->canonicalization_hook() );
		} finally {
			remove_filter( 'user_has_cap', $grant );
		}
	}

	public function test_register_site_settings_page_registers_the_classic_menu(): void {
		// Classic theme + single site: the classic settings page is registered.
		erankly_admin_register_site_settings_page();

		$this->assertNotNull( $this->canonicalization_hook() );
	}

	public function test_canonicalize_settings_tab_does_not_redirect_a_canonical_tab(): void {
		$redirects = array();
		$spy       = static function ( $location ) use ( &$redirects ) {
			$redirects[] = $location;
			return $location;
		};
		add_filter( 'wp_redirect', $spy );

		// The canonical (default) tab differs per install: single site defaults to "general",
		// while the Multisite per-site screen's only tab is "special-pages". On Multisite a bare
		// request defaults to "general", which is NOT canonical there, so it legitimately redirects
		// (and wp_safe_redirect() would raise "headers already sent" in the test bootstrap).
		$canonical = is_multisite() ? 'special-pages' : 'general';

		try {
			$_GET['erankly_tab'] = $canonical;
			erankly_admin_canonicalize_settings_tab();

			if ( ! is_multisite() ) {
				unset( $_GET['erankly_tab'] );
				erankly_admin_canonicalize_settings_tab();
			}
		} finally {
			remove_filter( 'wp_redirect', $spy );
		}

		$this->assertSame( array(), $redirects );
	}

	public function test_admin_render_settings_page_renders_for_an_admin(): void {
		set_current_screen( 'settings_page_erankly' );

		$html = $this->capture( static fn() => erankly_admin_render_settings_page() );

		$this->assertStringContainsString( 'class="wrap erankly-settings"', $html );

		if ( is_multisite() ) {
			// The per-site screen only holds the special-pages panel and points global SEO
			// settings at Network Admin instead of rendering the single-site General panel.
			$this->assertStringContainsString( 'id="erankly-settings-panel-special-pages"', $html );
			$this->assertStringContainsString( 'data-erankly-settings-panel="settings-special-pages"', $html );
			return;
		}

		$this->assertStringContainsString( 'id="erankly-settings-panel-general"', $html );
	}

	public function test_admin_save_wrappers_delegate_to_the_guarded_savers(): void {
		// Both wrappers load their modules and then reach the guarded saver, which dies on the capability check.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'erankly_network_settings' );

		try {
			erankly_admin_save_network_settings();
			$this->fail( 'Expected the network settings saver to reject a single-site administrator.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'Permission denied', $exception->getMessage() );
		}

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'erankly_site_special_meta' );

		$this->expectException( WPDieException::class );
		erankly_admin_save_site_special_meta();
	}

	public function test_maybe_register_settings_registers_on_a_settings_request(): void {
		unregister_setting( 'erankly', ERANKLY_OPTION );
		$GLOBALS['pagenow'] = 'options.php';

		erankly_admin_maybe_register_settings();

		$this->assertArrayHasKey( ERANKLY_OPTION, get_registered_settings() );
	}

	public function test_maybe_register_settings_skips_unrelated_requests(): void {
		unregister_setting( 'erankly', ERANKLY_OPTION );
		$GLOBALS['pagenow'] = 'index.php';
		$_GET['page']       = 'edit';

		erankly_admin_maybe_register_settings();

		$this->assertArrayNotHasKey( ERANKLY_OPTION, get_registered_settings() );
	}

	public function test_maybe_register_taxonomy_fields_only_on_taxonomy_screens(): void {
		remove_action( 'category_add_form_fields', 'erankly_render_add_term_fields' );
		$GLOBALS['pagenow'] = 'edit-tags.php';

		erankly_admin_maybe_register_taxonomy_fields();
		$this->assertNotFalse( has_action( 'category_add_form_fields', 'erankly_render_add_term_fields' ) );

		remove_action( 'category_add_form_fields', 'erankly_render_add_term_fields' );
		$GLOBALS['pagenow'] = 'index.php';

		erankly_admin_maybe_register_taxonomy_fields();
		$this->assertFalse( has_action( 'category_add_form_fields', 'erankly_render_add_term_fields' ) );
	}

	public function test_admin_register_meta_boxes_registers_the_classic_box(): void {
		$GLOBALS['wp_meta_boxes'] = array();
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor( false );

		erankly_admin_register_meta_boxes();

		$this->assertArrayHasKey( 'erankly', $GLOBALS['wp_meta_boxes']['post']['normal']['default'] );
	}

	public function test_admin_save_meta_box_wrapper_persists_sanitized_meta(): void {
		$post = self::factory()->post->create_and_get();

		$_POST['erankly_meta_box_nonce'] = wp_create_nonce( 'erankly_save_meta_box' );
		$_POST['erankly_title']          = 'Wrapped title';

		erankly_admin_save_meta_box( $post->ID, $post );

		$this->assertSame( 'Wrapped title', get_post_meta( $post->ID, '_erankly_title', true ) );
	}

	public function test_maybe_handle_import_export_loads_the_module_on_its_tab(): void {
		$_GET['page']        = 'erankly';
		$_GET['erankly_tab'] = 'import-export';

		erankly_admin_maybe_handle_import_export();

		$this->assertTrue( function_exists( 'erankly_import_export_render_panel' ) );
	}

	public function test_maybe_handle_reset_dispatches_the_authenticated_action(): void {
		$seen = array();
		$spy  = static function ( $action ) use ( &$seen ): void {
			$seen[] = $action;
		};
		add_action( 'erankly_reset_action', $spy );

		// The reset submission gate requires manage_network_options on Multisite, which a
		// single-site administrator never holds; grant it so the dispatcher is actually reached.
		$grant = null;

		if ( is_multisite() ) {
			$grant = static function ( array $allcaps ): array {
				$allcaps['manage_network_options'] = true;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $grant );
		}

		try {
			$_GET['page']                   = 'erankly';
			$_POST['erankly_reset_action']  = 'noop_probe';
			$nonce                          = wp_create_nonce( 'erankly_noop_probe' );
			$_POST['_wpnonce']              = $nonce;
			$_REQUEST['_wpnonce']           = $nonce;

			erankly_admin_maybe_handle_reset();

			$this->assertSame( array( 'noop_probe' ), $seen );

			// A non-reset page must short-circuit before the dispatcher runs.
			$seen             = array();
			$_GET['page']     = 'dashboard';
			erankly_admin_maybe_handle_reset();
			$this->assertSame( array(), $seen );
		} finally {
			remove_action( 'erankly_reset_action', $spy );

			if ( null !== $grant ) {
				remove_filter( 'user_has_cap', $grant );
			}
		}
	}

	public function test_plugin_action_links_prepend_a_settings_link(): void {
		$links = erankly_plugin_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertStringContainsString( 'options-general.php?page=erankly', $links[0] );
		$this->assertCount( 2, $links );
		$this->assertArrayHasKey( 'deactivate', $links );

		$network = erankly_network_plugin_action_links( array() );
		$this->assertStringContainsString( 'settings.php?page=erankly', $network[0] );
	}

	public function test_add_plugin_action_links_prepends_the_given_url(): void {
		$links = erankly_add_plugin_action_links( array( 'existing' ), 'https://example.com/settings' );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'href="https://example.com/settings"', $links[0] );
		$this->assertSame( 'existing', $links[1] );
	}

	public function test_panel_expand_toggle_targets_the_section(): void {
		$html = $this->capture( static fn() => erankly_admin_render_panel_expand_toggle( 'my-table' ) );

		$this->assertStringContainsString( 'class="button erankly-panel-expand-toggle"', $html );
		$this->assertStringContainsString( 'aria-controls="my-table"', $html );
		$this->assertStringContainsString( 'data-erankly-expand-toggle', $html );
		$this->assertSame( 2, substr_count( $html, '<svg' ) );
	}

	public function test_enqueue_shared_styles_registers_the_shared_stylesheet(): void {
		erankly_enqueue_shared_styles();

		$this->assertTrue( wp_style_is( 'erankly-shared', 'enqueued' ) );
		$this->assertStringContainsString( 'shared.css', (string) wp_styles()->registered['erankly-shared']->src );
	}

	public function test_admin_enqueue_scripts_enqueues_the_selected_modules_and_bootstrap(): void {
		$handle = erankly_admin_enqueue_scripts( array( 'tabs', 'schema', 'not-a-module' ) );

		$this->assertSame( 'erankly-admin', $handle );
		$this->assertTrue( wp_script_is( 'erankly-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-admin-tabs', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-admin-schema', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-schema-jsonld', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'erankly-admin-fields', 'enqueued' ) );
	}

	public function test_admin_asset_modules_resolves_surfaces(): void {
		$this->assertSame( array( 'tabs', 'fields' ), erankly_admin_asset_modules( 'settings:import-export' ) );
		$this->assertContains( 'variables', erankly_admin_asset_modules( 'settings:general' ) );
		$this->assertSame( array( 'media', 'fields', 'variables', 'schema', 'panels' ), erankly_admin_asset_modules( 'classic-editor' ) );
		$this->assertSame( array(), erankly_admin_asset_modules( 'no-such-surface' ) );
		// Unknown extension tabs keep receiving the complete bundle.
		$this->assertContains( 'panels', erankly_admin_asset_modules( 'settings:brand-new-addon' ) );
	}

	public function test_admin_asset_modules_is_filterable(): void {
		$filter = static function ( array $modules ): array {
			$modules[] = 'probe-module';
			return $modules;
		};

		add_filter( 'erankly_admin_asset_modules', $filter );

		try {
			$this->assertContains( 'probe-module', erankly_admin_asset_modules( 'classic-editor' ) );
		} finally {
			remove_filter( 'erankly_admin_asset_modules', $filter );
		}
	}

	public function test_admin_enqueue_assets_enqueues_the_general_settings_bundle(): void {
		set_current_screen( 'settings_page_erankly' );
		$_GET['erankly_tab'] = 'general';

		erankly_admin_enqueue_assets( 'settings_page_erankly' );

		$this->assertTrue( wp_style_is( 'erankly-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-admin', 'enqueued' ) );

		if ( is_multisite() ) {
			// The per-site screen has no General tab, so 'general' resolves to special-pages and
			// the General-only user-search localization is (correctly) not emitted.
			$this->assertStringNotContainsString( 'eranklyUserSearch', (string) wp_scripts()->get_data( 'erankly-admin', 'data' ) );
			return;
		}

		$this->assertStringContainsString( 'eranklyUserSearch', (string) wp_scripts()->get_data( 'erankly-admin', 'data' ) );
	}

	public function test_admin_enqueue_assets_handles_import_export_and_settings_tabs(): void {
		set_current_screen( 'settings_page_erankly' );

		if ( is_multisite() ) {
			// Import / Export and Settings are Network-Admin-only surfaces on Multisite; a per-site
			// request for either collapses onto the special-pages bundle, so neither the migration
			// nor the reset stylesheet is enqueued here.
			$_GET['erankly_tab'] = 'import-export';
			erankly_admin_enqueue_assets( 'settings_page_erankly' );
			$this->assertFalse( wp_style_is( 'erankly-migration', 'enqueued' ) );
			$this->assertFalse( wp_script_is( 'erankly-admin-import-export', 'enqueued' ) );

			$_GET['erankly_tab'] = 'settings';
			erankly_admin_enqueue_assets( 'settings_page_erankly' );
			$this->assertFalse( wp_style_is( 'erankly-reset', 'enqueued' ) );
			return;
		}

		$_GET['erankly_tab'] = 'import-export';
		erankly_admin_enqueue_assets( 'settings_page_erankly' );
		$this->assertTrue( wp_style_is( 'erankly-migration', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-admin-import-export', 'enqueued' ) );

		$_GET['erankly_tab'] = 'settings';
		erankly_admin_enqueue_assets( 'settings_page_erankly' );
		$this->assertTrue( wp_style_is( 'erankly-reset', 'enqueued' ) );
	}

	public function test_enqueue_accordion_faq_schema_assets_registers_the_editor_bundle(): void {
		erankly_enqueue_accordion_faq_schema_assets();

		$this->assertTrue( wp_script_is( 'erankly-accordion-faq-schema', 'enqueued' ) );
	}

	public function test_enqueue_editor_shared_assets_registers_style_and_script(): void {
		erankly_enqueue_editor_shared_assets();

		$this->assertTrue( wp_style_is( 'erankly-editor', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'erankly-editor-shared', 'enqueued' ) );
		$this->assertStringContainsString( 'eranklyEditorShared', (string) wp_scripts()->get_data( 'erankly-editor-shared', 'data' ) );
	}

	public function test_block_editor_assets_enqueue_for_an_editable_post(): void {
		$post          = self::factory()->post->create_and_get();
		$GLOBALS['post'] = $post;
		set_current_screen( 'post' );

		erankly_admin_enqueue_block_editor_assets();

		$this->assertTrue( wp_script_is( 'erankly-editor', 'enqueued' ) );
		$this->assertStringContainsString( 'eranklyEditor', (string) wp_scripts()->get_data( 'erankly-editor', 'data' ) );
	}

	public function test_site_editor_assets_enqueue_for_an_administrator(): void {
		$original = $GLOBALS['wp_version'];

		try {
			$GLOBALS['wp_version'] = '6.7';
			set_current_screen( 'site-editor' );

			erankly_admin_enqueue_site_editor_assets();

			$this->assertTrue( wp_script_is( 'erankly-site-editor', 'enqueued' ) );
			$this->assertStringContainsString( 'eranklySiteEditor', (string) wp_scripts()->get_data( 'erankly-site-editor', 'data' ) );
		} finally {
			$GLOBALS['wp_version'] = $original;
		}
	}
}
