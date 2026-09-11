<?php
/** Settings page panels, the settings-page presentation helpers and the settings page orchestrator. */

final class ERankly_Admin_Settings_Panels_Test extends WP_UnitTestCase {

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
		erankly_load_content_helpers();
		erankly_load_sitemap_helpers();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['current_screen'] );
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

	public function test_features_panel_renders_each_module_checkbox(): void {
		$html = $this->capture( static fn() => erankly_render_settings_panel_features( erankly_get_settings(), false, true, false ) );

		$this->assertStringContainsString( 'id="erankly-settings-panel-features"', $html );
		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-features"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_redirects]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_sitemap]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_custom_code]"', $html );
	}

	public function test_custom_code_panel_renders_three_writable_builders(): void {
		// On Multisite only super admins hold unfiltered_html, so the administrator created in
		// set_up() would otherwise get the read-only fallback. Promote them for this test so the
		// writable-builder contract is exercised, then restore.
		$granted_super = is_multisite()
			&& ! is_super_admin( $this->admin_id )
			&& grant_super_admin( $this->admin_id );

		try {
			$html = $this->capture(
				static fn() => erankly_render_settings_panel_custom_code(
					array(),
					ERANKLY_OPTION . '[head_code_blocks]',
					array(),
					ERANKLY_OPTION . '[body_open_code_blocks]',
					array(),
					ERANKLY_OPTION . '[body_close_code_blocks]'
				)
			);

			$this->assertSame( 3, substr_count( $html, 'data-erankly-code-builder' ) );
			$this->assertSame( 3, substr_count( $html, 'data-erankly-max-blocks' ) );
			$this->assertStringContainsString( 'data-erankly-collection="' . ERANKLY_OPTION . '[head_code_blocks]"', $html );
			$this->assertStringNotContainsString( 'readonly', $html );
		} finally {
			if ( $granted_super ) {
				revoke_super_admin( $this->admin_id );
			}
		}
	}

	public function test_custom_code_panel_locks_fields_without_unfiltered_html(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author_id );

		$html = $this->capture(
			static fn() => erankly_render_settings_panel_custom_code(
				array(),
				ERANKLY_OPTION . '[head_code_blocks]',
				array(),
				ERANKLY_OPTION . '[body_open_code_blocks]',
				array(),
				ERANKLY_OPTION . '[body_close_code_blocks]'
			)
		);

		$this->assertStringContainsString( 'readonly', $html );
	}

	public function test_custom_code_panel_warns_about_an_unmigrated_legacy_snippet(): void {
		$this->store_settings( array( 'head_code' => '<meta name="legacy">' ) );

		$html = $this->capture(
			static fn() => erankly_render_settings_panel_custom_code(
				array(),
				ERANKLY_OPTION . '[head_code_blocks]',
				array(),
				ERANKLY_OPTION . '[body_open_code_blocks]',
				array(),
				ERANKLY_OPTION . '[body_close_code_blocks]'
			)
		);

		$this->assertStringContainsString( 'notice notice-warning inline', $html );
	}

	public function test_custom_code_builder_seeds_the_next_index_with_the_block_count(): void {
		$html = $this->capture(
			static fn() => erankly_render_custom_code_builder(
				'HEAD code',
				array( array( 'enabled' => 1, 'code' => '<meta>' ) ),
				ERANKLY_OPTION . '[head_code_blocks]',
				true
			)
		);

		$this->assertStringContainsString( 'data-erankly-next-index="1"', $html );
		$this->assertStringContainsString( 'data-erankly-code-template', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[head_code_blocks][0][code]"', $html );
	}

	public function test_general_panel_renders_identity_and_organization_fields(): void {
		$settings = erankly_get_settings();

		$html = $this->capture( static fn() => erankly_render_settings_panel_general( $settings, 0, false, true ) );

		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-general"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[organization_name]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[schema_identity]"', $html );
		$this->assertStringContainsString( 'data-erankly-person-reference-field', $html );
		$this->assertStringContainsString( 'data-erankly-organization-only', $html );
		$this->assertStringContainsString( 'data-erankly-tabs-root', $html );
	}

	public function test_general_panel_hides_organization_fields_for_person_identity(): void {
		$settings                    = erankly_get_settings();
		$settings['schema_identity'] = 'person';

		$html = $this->capture( static fn() => erankly_render_settings_panel_general( $settings, 0, false, false ) );

		$this->assertStringContainsString( 'data-erankly-organization-only hidden', $html );
	}

	public function test_social_panel_renders_media_and_profile_fields(): void {
		$html = $this->capture( static fn() => erankly_render_settings_panel_social( erankly_get_settings() ) );

		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-social"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[organization_logo_url]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[default_social_image_url]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[twitter_site]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[social_profiles]"', $html );
		$this->assertStringContainsString( 'data-erankly-tabs-root', $html );
	}

	public function test_schema_panel_renders_breadcrumbs_local_business_and_custom_blocks(): void {
		$html = $this->capture(
			static fn() => erankly_render_settings_panel_schema(
				erankly_get_settings(),
				array(
					array(
						'enabled' => 1,
						'fields'  => array( 'custom_json' => '{"@type":"Thing"}' ),
					),
				),
				ERANKLY_OPTION . '[global_schema_blocks]'
			)
		);

		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_breadcrumbs]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[breadcrumb_jsonld_mode]"', $html );
		$this->assertStringContainsString( 'data-erankly-local-business', $html );
		$this->assertStringContainsString( 'data-erankly-schema-builder', $html );
		$this->assertStringContainsString( 'data-erankly-collection="' . ERANKLY_OPTION . '[global_schema_blocks]"', $html );
		$this->assertStringContainsString( 'data-erankly-next-index="1"', $html );
		$this->assertStringContainsString( 'erankly-schema-targeting', $html );
	}

	public function test_sitemap_panel_renders_each_specialised_toggle(): void {
		$html = $this->capture( static fn() => erankly_render_settings_panel_sitemap( erankly_get_settings(), erankly_get_sitemap_url( '/wp-sitemap.xml' ) ) );

		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-sitemap"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_news_sitemap]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_image_sitemap]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_video_sitemap]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[news_sitemap_post_types][]"', $html );
	}

	public function test_settings_panel_renders_preferences_checkboxes(): void {
		$html = $this->capture( static fn() => erankly_render_settings_panel_settings( erankly_get_settings() ) );

		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-settings"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[simplified_mode]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[resolve_placeholders]"', $html );
	}

	public function test_advanced_panel_renders_robots_and_pagination_fields(): void {
		$html = $this->capture( static fn() => erankly_render_settings_panel_advanced( erankly_get_settings() ) );

		$this->assertStringContainsString( 'data-erankly-settings-panel="settings-advanced"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[robots_max_image_preview]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[robots_max_snippet]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[robots_txt_extra]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[noindex_paginated]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[attachment_redirect]"', $html );
	}

	public function test_register_settings_registers_the_sanitized_option(): void {
		erankly_register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( ERANKLY_OPTION, $registered );
		$this->assertSame( 'erankly_sanitize_settings', $registered[ ERANKLY_OPTION ]['sanitize_callback'] );
	}

	public function test_normalize_settings_tabs_filters_reserved_slugs_scopes_and_orders_by_position(): void {
		$tabs = array(
			'general'  => array( 'label' => 'Reserved' ),
			'custom-a' => array(
				'label'    => 'A',
				'scope'    => 'site',
				'position' => 50,
			),
			'custom-b' => array(
				'label' => 'B',
				'scope' => 'network',
			),
			'custom-c' => array( 'label' => '' ),
			'custom-d' => array(
				'label'      => 'D',
				'scope'      => 'site',
				'position'   => 10,
				'capability' => 'manage_options',
				'group'      => 'feature_modules',
			),
		);

		$clean = erankly_normalize_settings_tabs( $tabs, array( 'scope' => 'site' ) );

		$this->assertSame( array( 'custom-d', 'custom-a' ), array_keys( $clean ) );
		$this->assertSame( 'feature_modules', $clean['custom-d']['group'] );
		$this->assertSame( 50, $clean['custom-a']['position'] );
	}

	public function test_normalize_settings_tabs_rejects_non_arrays(): void {
		$this->assertSame( array(), erankly_normalize_settings_tabs( 'nope', array( 'scope' => 'site' ) ) );
	}

	public function test_global_meta_nav_subtabs_build_the_expected_slugs(): void {
		$objects = array(
			'post' => new stdClass(),
			'page' => new stdClass(),
		);

		$this->assertSame(
			array(
				array(
					'subtab'   => 'global_post_type_meta-post',
					'disabled' => true,
				),
				array(
					'subtab'   => 'global_post_type_meta-page',
					'disabled' => true,
				),
			),
			erankly_get_global_meta_nav_subtabs( 'global_post_type_meta', $objects, true )
		);
	}

	public function test_special_page_nav_subtabs_use_the_all_group_key(): void {
		$this->assertSame(
			array(
				array(
					'subtab'   => 'global_special_meta-all-homepage',
					'disabled' => false,
				),
				array(
					'subtab'   => 'global_special_meta-all-404',
					'disabled' => false,
				),
			),
			erankly_get_special_page_nav_subtabs(
				array(
					'homepage' => 'Homepage',
					404        => '404 page',
				)
			)
		);
	}

	public function test_social_nav_subtabs_disable_the_subtabs_only_when_linked(): void {
		$linked = array(
			'default_og_title'            => 'T',
			'default_twitter_title'       => 'T',
			'default_og_description'      => 'D',
			'default_twitter_description' => 'D',
		);

		$this->assertSame(
			array(
				array(
					'subtab'   => 'social-defaults-og',
					'disabled' => true,
				),
				array(
					'subtab'   => 'social-defaults-twitter',
					'disabled' => true,
				),
			),
			erankly_get_social_nav_subtabs( $linked )
		);

		$divergent                    = $linked;
		$divergent['default_og_title'] = 'Different';

		$items = erankly_get_social_nav_subtabs( $divergent );
		$this->assertFalse( $items[0]['disabled'] );
		$this->assertFalse( $items[1]['disabled'] );

		$explicitly_unlinked                    = $linked;
		$explicitly_unlinked['social_defaults_linked'] = 0;

		$items = erankly_get_social_nav_subtabs( $explicitly_unlinked );
		$this->assertFalse( $items[0]['disabled'] );
	}

	public function test_settings_tab_url_uses_the_settings_page_query_args(): void {
		$url = erankly_settings_tab_url( 'schema' );

		$this->assertStringContainsString( 'options-general.php', $url );
		$this->assertStringContainsString( 'page=erankly', $url );
		$this->assertStringContainsString( 'erankly_tab=schema', $url );
	}

	public function test_settings_nav_link_marks_the_active_panel_and_hidden_state(): void {
		$active = $this->capture( static fn() => erankly_render_settings_nav_link( 'general', 'General', 'settings-general' ) );

		$this->assertStringContainsString( 'class="erankly-settings-nav-item is-active"', $active );
		$this->assertStringContainsString( 'id="erankly-settings-tab-general"', $active );
		$this->assertStringContainsString( 'data-erankly-tab="settings-general"', $active );
		$this->assertStringContainsString( 'aria-current="page"', $active );
		$this->assertStringContainsString( 'erankly-nav-icon', $active );

		$inactive = $this->capture( static fn() => erankly_render_settings_nav_link( 'general', 'General', 'settings-social' ) );

		$this->assertStringNotContainsString( 'is-active', $inactive );
		$this->assertStringNotContainsString( 'aria-current="page"', $inactive );

		$hidden = $this->capture( static fn() => erankly_render_settings_nav_link( 'advanced', 'Advanced', 'settings-social', true ) );
		$this->assertMatchesRegularExpression( '/\shidden>/', $hidden );
	}

	public function test_save_network_settings_requires_a_valid_nonce(): void {
		unset( $_REQUEST['_wpnonce'] );

		$this->expectException( WPDieException::class );

		erankly_save_network_settings();
	}

	public function test_save_network_settings_requires_the_network_capability(): void {
		// Single-site administrators do not hold manage_network_options.
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'erankly_network_settings' );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Permission denied' );

		erankly_save_network_settings();
	}

	public function test_save_site_special_meta_requires_the_capability(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'erankly_site_special_meta' );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Permission denied' );

		erankly_save_site_special_meta();
	}

	public function test_settings_page_renders_nothing_without_capability(): void {
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->capture( static fn() => erankly_render_settings_page() ) );
	}

	public function test_settings_page_renders_the_requested_general_panel_for_an_admin(): void {
		set_current_screen( 'settings_page_erankly' );

		$html = $this->capture( static fn() => erankly_render_settings_page() );

		$this->assertStringContainsString( 'class="wrap erankly-settings"', $html );
		$this->assertStringContainsString( 'data-erankly-settings-tablist', $html );

		if ( is_multisite() ) {
			// The per-site screen on Multisite only holds the special-pages panel; the global
			// General panel is shown in Network Admin instead.
			$this->assertStringContainsString( 'data-erankly-active-panel="settings-special-pages"', $html );
			$this->assertStringContainsString( 'id="erankly-settings-panel-special-pages"', $html );
			return;
		}

		$this->assertStringContainsString( 'data-erankly-active-panel="settings-general"', $html );
		$this->assertStringContainsString( 'id="erankly-settings-panel-general"', $html );
	}

	public function test_settings_page_resolves_advanced_to_the_settings_panel_in_simplified_mode(): void {
		set_current_screen( 'settings_page_erankly' );
		$_GET['erankly_tab'] = 'advanced';

		$html = $this->capture( static fn() => erankly_render_settings_page() );

		if ( is_multisite() ) {
			// There is no Advanced or Settings tab on the per-site screen, so 'advanced'
			// resolves to the special-pages panel there.
			$this->assertStringContainsString( 'data-erankly-active-panel="settings-special-pages"', $html );
			$this->assertStringContainsString( 'id="erankly-settings-panel-special-pages"', $html );
			return;
		}

		$this->assertStringContainsString( 'data-erankly-active-panel="settings-settings"', $html );
		$this->assertStringContainsString( 'id="erankly-settings-panel-settings"', $html );
	}
}
