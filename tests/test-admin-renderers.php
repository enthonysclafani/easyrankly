<?php
/** Admin renderers: shared field renderers, settings field renderers, section wrappers and sidebar nav icons. */

final class ERankly_Admin_Renderers_Test extends WP_UnitTestCase {

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
		erankly_load_content_helpers();
	}

	public function tear_down(): void {
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

	/** Asserts that an HTML fragment opens and closes the same number of divs. */
	private function assert_balanced_divs( string $html ): void {
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'Rendered markup has unbalanced <div> tags.' );
	}

	public function test_variable_groups_expose_every_documented_group(): void {
		$groups = erankly_get_variable_groups();

		$this->assertSame( array( 'content', 'taxonomy', 'seo', 'pagination', 'site' ), array_keys( $groups ) );
		$this->assertArrayHasKey( 'post_title', $groups['content']['variables'] );
		$this->assertArrayHasKey( 'term_name', $groups['taxonomy']['variables'] );
		$this->assertArrayHasKey( 'page_number', $groups['pagination']['variables'] );
		$this->assertArrayHasKey( 'site_name', $groups['site']['variables'] );
	}

	public function test_variable_picker_renders_a_listbox_with_every_group_and_examples(): void {
		$html = $this->capture( static fn() => erankly_render_variable_picker( array( 'post_title' => 'Post title' ) ) );

		$this->assertStringContainsString( 'data-erankly-variable-menu', $html );
		$this->assertStringContainsString( 'role="listbox"', $html );
		$this->assertStringContainsString( 'data-erankly-variable-examples=', $html );
		$this->assertStringContainsString( 'data-erankly-variable="{{post_title}}"', $html );
		$this->assertStringContainsString( 'data-erankly-variable="{{term_name}}"', $html );
	}

	public function test_variable_picker_honours_the_allowed_groups_filter(): void {
		$html = $this->capture(
			static fn() => erankly_render_variable_picker(
				array(
					'post_title'  => 'Post title',
					'page_number' => 'Current page number',
				),
				array( 'pagination' )
			)
		);

		$this->assertStringContainsString( 'data-erankly-variable="{{page_number}}"', $html );
		$this->assertStringNotContainsString( '{{post_title}}', $html );
		// Examples are filtered to variables that exist in an allowed group.
		$this->assertStringContainsString( 'data-erankly-variable-examples=', $html );
		$this->assertStringContainsString( 'page_number', $html );
		$this->assertStringNotContainsString( 'post_title', $html );
	}

	public function test_schema_block_renders_the_custom_json_textarea_and_hidden_type(): void {
		$block = array(
			'enabled' => 1,
			'fields'  => array( 'custom_json' => '{"@type":"Thing"}' ),
		);

		$html = $this->capture( static fn() => erankly_render_schema_block( $block, '2', 'erankly_schema_blocks' ) );

		$this->assertStringContainsString( 'data-erankly-schema-block', $html );
		$this->assertStringContainsString( 'name="erankly_schema_blocks[2][type]" value="custom"', $html );
		$this->assertStringContainsString( 'name="erankly_schema_blocks[2][fields][custom_json]"', $html );
		$this->assertStringNotContainsString( 'erankly-schema-targeting', $html );
	}

	public function test_global_schema_block_includes_targeting_fields(): void {
		$html = $this->capture(
			static fn() => erankly_render_schema_block(
				array(
					'enabled'         => 1,
					'target_contexts' => array( 'singular' ),
				),
				'0',
				'erankly_settings[global_schema_blocks]',
				true
			)
		);

		$this->assertStringContainsString( 'erankly-schema-targeting', $html );
		$this->assertMatchesRegularExpression(
			'/value="singular"\s+checked=\'checked\' data-erankly-target-context="singular"/',
			$html
		);
	}

	public function test_schema_targeting_fields_render_context_post_type_and_include_exclude_inputs(): void {
		$html = $this->capture(
			static fn() => erankly_render_schema_targeting_fields(
				array(
					'target_contexts'   => array( 'singular' ),
					'target_post_types' => array( 'post' ),
					'include_items'     => '1,2',
					'exclude_items'     => '3',
				),
				'5',
				'p',
				true,
				'',
				true,
				'custom-code'
			)
		);

		$this->assertStringContainsString( 'erankly-code-targeting', $html );
		$this->assertMatchesRegularExpression( '/name="p\[5\]\[enabled\]" value="1"\s+checked=\'checked\'/', $html );
		// Archive contexts (including 404) are only available in the custom-code context.
		$this->assertStringContainsString( 'value="404"', $html );
		$this->assertMatchesRegularExpression( '/name="p\[5\]\[target_post_types\]\[\]" value="post"\s+checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'name="p[5][include_items]"', $html );
		$this->assertStringContainsString( 'name="p[5][exclude_items]"', $html );
	}

	public function test_custom_code_block_marks_the_textarea_readonly_without_unfiltered_html(): void {
		$rendered = $this->capture( static fn() => erankly_render_custom_code_block( array(), '__INDEX__', 'erankly_settings[head_code_blocks]', false ) );
		$this->assertStringContainsString( 'name="erankly_settings[head_code_blocks][__INDEX__][code]" readonly', $rendered );

		$writable = $this->capture( static fn() => erankly_render_custom_code_block( array( 'name' => 'Probe' ), '__INDEX__', 'erankly_settings[head_code_blocks]', true ) );
		$this->assertStringContainsString( 'name="erankly_settings[head_code_blocks][__INDEX__][code]"', $writable );
		$this->assertStringNotContainsString( 'readonly', $writable );
		$this->assertStringContainsString( 'Probe', $writable );
	}

	public function test_custom_code_block_preserves_the_legacy_migration_flag(): void {
		$html = $this->capture(
			static fn() => erankly_render_custom_code_block(
				array(
					'enabled'         => 1,
					'legacy_migrated' => 1,
				),
				'0',
				'p',
				true
			)
		);

		$this->assertStringContainsString( 'name="p[0][legacy_migrated]" value="1"', $html );
	}

	public function test_schema_textarea_field_wires_the_error_region(): void {
		$html = $this->capture(
			static fn() => erankly_render_schema_textarea_field( '0', 'erankly_schema_blocks', 'custom_json', 'JSON-LD code', '{}', 10 )
		);

		$this->assertStringContainsString( 'id="erankly-schema-0-custom_json"', $html );
		$this->assertStringContainsString( 'name="erankly_schema_blocks[0][fields][custom_json]"', $html );
		$this->assertStringContainsString( 'aria-describedby="erankly-schema-0-custom_json-error"', $html );
		$this->assertStringContainsString( 'rows="10"', $html );
		$this->assertStringContainsString( 'data-erankly-json-ld-error', $html );
	}

	public function test_media_url_field_renders_attachment_id_hidden_input_and_preview(): void {
		$html = $this->capture(
			static fn() => erankly_render_media_url_field( 'f', 'n', 'https://example.com/img.png', 'ph', 'aid', 7, true )
		);

		$this->assertStringContainsString( 'data-erankly-media-url-field', $html );
		$this->assertStringContainsString( 'id="f"', $html );
		$this->assertStringContainsString( 'name="n"', $html );
		$this->assertStringContainsString( 'value="https://example.com/img.png"', $html );
		$this->assertStringContainsString( 'data-erankly-media-url-id name="aid" value="7"', $html );
		$this->assertStringContainsString( '<img src="https://example.com/img.png"', $html );
	}

	public function test_media_url_field_skips_the_preview_for_template_values_and_optional_id_field(): void {
		$html = $this->capture(
			static fn() => erankly_render_media_url_field( 'f', 'n', '{{site_icon_url}}', '', '', 0, true )
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'data-erankly-media-url-id', $html );

		$no_preview = $this->capture(
			static fn() => erankly_render_media_url_field( 'f', 'n', 'https://example.com/img.png', '', '', 0, false )
		);
		$this->assertStringNotContainsString( 'data-erankly-media-url-preview', $no_preview );
	}

	public function test_organization_details_render_the_legal_and_address_fields(): void {
		$html = $this->capture( static fn() => erankly_render_organization_details( erankly_get_settings() ) );

		$this->assertStringContainsString( 'class="erankly-settings-details"', $html );
		foreach ( array( 'organization_legal_name', 'organization_vat_id', 'organization_tax_id', 'organization_street_address', 'organization_locality', 'organization_region', 'organization_postal_code', 'organization_country' ) as $key ) {
			$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[' . $key . ']"', $html );
		}
	}

	public function test_local_business_settings_hide_the_fieldset_until_enabled(): void {
		$settings                    = erankly_get_settings();
		$settings['enable_local_business'] = 0;

		$html = $this->capture( static fn() => erankly_render_local_business_settings( $settings ) );

		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_local_business]" value="1"', $html );
		$this->assertStringContainsString( 'data-erankly-local-business-fields hidden', $html );
	}

	public function test_local_business_settings_reveal_food_fields_for_a_food_business_type(): void {
		$settings                              = erankly_get_settings();
		$settings['enable_local_business']     = 1;
		$settings['local_business_type']       = 'Restaurant';
		$settings['local_business_hours']      = erankly_default_opening_hours();

		$html = $this->capture( static fn() => erankly_render_local_business_settings( $settings ) );

		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[local_business_type]"', $html );
		$this->assertStringNotContainsString( 'data-erankly-food-business-fields hidden', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[local_business_cuisine]"', $html );
	}

	public function test_opening_hours_fields_render_seven_rows_and_respect_closed_days(): void {
		$hours = erankly_default_opening_hours();
		$hours['monday']['closed'] = 1;

		$html = $this->capture( static fn() => erankly_render_opening_hours_fields( $hours ) );

		$this->assertSame( 7, substr_count( $html, 'data-erankly-opening-day' ) );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[local_business_hours][monday][intervals][0][opens]"', $html );
		$this->assertStringContainsString( 'data-erankly-opening-intervals hidden', $html );
	}

	public function test_global_meta_defaults_render_linked_tabs_and_field_names(): void {
		$settings                            = erankly_get_settings();
		$settings['global_post_type_meta_linked'] = 1;

		$html = $this->capture( static fn() => erankly_render_global_meta_defaults( 'global_post_type_meta', erankly_get_public_post_types(), $settings ) );

		$this->assertStringContainsString( 'data-erankly-tabs-root', $html );
		$this->assertStringContainsString( 'erankly-tabs-group-entity is-linked', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_post_type_meta][post][title]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_post_type_meta_linked]" value="1"', $html );
		$this->assertStringContainsString( 'data-erankly-linked-input', $html );
	}

	public function test_global_meta_defaults_unlink_and_empty_states(): void {
		$settings                                 = erankly_get_settings();
		$settings['global_post_type_meta_linked'] = 0;

		$unlinked = $this->capture( static fn() => erankly_render_global_meta_defaults( 'global_post_type_meta', erankly_get_public_post_types(), $settings ) );
		$this->assertStringNotContainsString( 'erankly-tabs-group-entity is-linked', $unlinked );

		$empty = $this->capture( static fn() => erankly_render_global_meta_defaults( 'global_post_type_meta', array(), $settings ) );
		$this->assertStringNotContainsString( 'data-erankly-tabs-root', $empty );
		$this->assertStringContainsString( 'class="description"', $empty );
	}

	public function test_post_type_schema_types_render_two_selects_per_public_type(): void {
		$html = $this->capture( static fn() => erankly_render_post_type_schema_types() );

		$this->assertStringContainsString( 'erankly-post-type-schema-row', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_post_type_schema][post][webpage_type]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_post_type_schema][post][article_type]"', $html );
	}

	public function test_schema_type_select_keeps_an_imported_unknown_value(): void {
		$html = $this->capture(
			static fn() => erankly_render_schema_type_select(
				'id',
				'name',
				array( 'WebPage' => 'WebPage' ),
				'ImportedType',
				array( 'id-label' )
			)
		);

		$this->assertMatchesRegularExpression( '/value="ImportedType"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'aria-labelledby="id-label"', $html );
	}

	public function test_social_meta_defaults_become_linked_when_templates_match(): void {
		$settings = erankly_get_settings();
		$settings['default_og_title']            = 'Same';
		$settings['default_twitter_title']       = 'Same';
		$settings['default_og_description']      = 'Same';
		$settings['default_twitter_description'] = 'Same';
		$settings['social_defaults_linked']      = 1;

		$html = $this->capture( static fn() => erankly_render_social_meta_defaults( $settings ) );

		$this->assertStringContainsString( 'erankly-tabs-group is-linked', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[default_og_title]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[social_defaults_linked]" value="1"', $html );
		$this->assertStringContainsString( 'data-erankly-linked-input', $html );
	}

	public function test_social_meta_defaults_are_unlinked_when_templates_differ(): void {
		$settings = erankly_get_settings();
		$settings['default_og_title']            = 'Open Graph';
		$settings['default_twitter_title']       = 'Twitter';
		$settings['default_og_description']      = 'A';
		$settings['default_twitter_description'] = 'B';
		$settings['social_defaults_linked']      = 1;

		$html = $this->capture( static fn() => erankly_render_social_meta_defaults( $settings ) );

		$this->assertStringNotContainsString( 'erankly-tabs-group is-linked', $html );
	}

	public function test_special_page_defaults_render_tabs_and_hidden_social_inputs_in_simplified_mode(): void {
		$settings                    = erankly_get_settings();
		$settings['simplified_mode'] = 1;

		$html = $this->capture( static fn() => erankly_render_special_page_defaults( erankly_special_page_keys(), $settings ) );

		$this->assertStringContainsString( 'erankly-tabs-group-pages', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][title]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][og_title]"', $html );
		$this->assertStringNotContainsString( 'erankly-defaults-section', $html );
	}

	public function test_special_page_defaults_return_nothing_for_an_empty_entity_list(): void {
		$html = $this->capture( static fn() => erankly_render_special_page_defaults( array(), erankly_get_settings() ) );

		$this->assertSame( '', $html );
	}

	public function test_special_page_group_renders_visible_social_fields_when_not_simplified(): void {
		$settings                    = erankly_get_settings();
		$settings['simplified_mode'] = 0;
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();

		$html = $this->capture(
			static fn() => erankly_render_special_page_defaults_group(
				array( 'homepage' => 'Homepage' ),
				array(),
				'global_special_meta',
				'all',
				'Default metadata by WordPress context'
			)
		);

		$this->assertStringContainsString( 'aria-label="Default metadata by WordPress context"', $html );
		$this->assertStringContainsString( 'data-erankly-tab="global_special_meta-all-homepage"', $html );
		$this->assertStringContainsString( 'erankly-defaults-section', $html );
	}

	public function test_special_page_social_defaults_render_hidden_inputs_in_simplified_mode(): void {
		$row = array(
			'og_title' => 'OG',
			'og_image_id' => 4,
		);

		$simple = $this->capture( static fn() => erankly_render_special_page_social_defaults( 'global_special_meta', 'homepage', $row, 'erankly-global_special_meta-homepage', true ) );
		$this->assertStringContainsString( 'type="hidden" name="' . ERANKLY_OPTION . '[global_special_meta][homepage][og_title]" value="OG"', $simple );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][og_image_id]" value="4"', $simple );

		$full = $this->capture( static fn() => erankly_render_special_page_social_defaults( 'global_special_meta', 'homepage', $row, 'erankly-global_special_meta-homepage', false ) );
		$this->assertStringContainsString( 'erankly-defaults-section', $full );
		$this->assertStringContainsString( 'id="erankly-global_special_meta-homepage-og-title"', $full );
	}

	public function test_global_advanced_robot_preservation_only_forwards_known_keys(): void {
		$row = array(
			'index_directive' => 'noindex',
			'max_snippet'     => '10',
			'unknown_key'     => 'ignored',
		);

		$html = $this->capture( static fn() => erankly_render_global_advanced_robot_preservation( 'global_post_type_meta', 'post', $row ) );

		$this->assertStringContainsString( 'type="hidden" name="' . ERANKLY_OPTION . '[global_post_type_meta][post][index_directive]" value="noindex"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_post_type_meta][post][max_snippet]" value="10"', $html );
		$this->assertStringNotContainsString( 'unknown_key', $html );
	}

	public function test_section_stack_pushes_and_pops_in_lifo_order(): void {
		$this->assertTrue( erankly_section_stack( true ) );
		$this->assertFalse( erankly_section_stack( false ) );
		$this->assertFalse( erankly_section_stack() );
		$this->assertTrue( erankly_section_stack() );
	}

	public function test_section_open_and_close_emit_balanced_markup_with_doc_link(): void {
		$html = $this->capture(
			static function (): void {
				erankly_section_open( 'Search engines', array( 'doc' => 'search-engines' ) );
				erankly_section_close();
			}
		);

		$this->assert_balanced_divs( $html );
		$this->assertStringContainsString( 'class="erankly-settings-section"', $html );
		$this->assertStringContainsString( 'class="erankly-card"', $html );
		$this->assertStringContainsString( 'class="erankly-section-doc-link"', $html );
		$this->assertStringContainsString( 'data-erankly-doc-section="search-engines"', $html );
	}

	public function test_section_open_honours_class_card_false_and_hidden_flags(): void {
		$html = $this->capture(
			static function (): void {
				erankly_section_open( 'Hidden section', array( 'class' => 'probe', 'card' => false, 'hidden' => true ) );
				erankly_section_close();
			}
		);

		$this->assert_balanced_divs( $html );
		$this->assertStringContainsString( 'erankly-settings-section probe" hidden', $html );
		$this->assertStringNotContainsString( 'class="erankly-card"', $html );
	}

	public function test_section_doc_link_is_omitted_for_unknown_sections(): void {
		$this->assertSame( '', $this->capture( static fn() => erankly_render_section_doc_link( 'term-meta' ) ) );
		$this->assertStringContainsString( 'erankly-section-doc-link', $this->capture( static fn() => erankly_render_section_doc_link( 'xml-sitemap' ) ) );
	}

	public function test_section_doc_links_are_filterable(): void {
		$filter = static function ( array $links ): array {
			$links['probe-section'] = 'https://example.com/docs';
			return $links;
		};

		add_filter( 'erankly_section_doc_links', $filter );

		try {
			$this->assertSame( 'https://example.com/docs', erankly_section_doc_links()['probe-section'] );
		} finally {
			remove_filter( 'erankly_section_doc_links', $filter );
		}
	}

	public function test_nav_icons_alias_special_pages_to_general_and_fallback_in_nav_icon(): void {
		$icons = erankly_nav_icons();

		$this->assertArrayHasKey( 'general', $icons );
		$this->assertSame( $icons['general'], $icons['special-pages'] );
		$this->assertStringContainsString( '<path', $icons['general'] );

		$this->assertStringContainsString( '<svg class="erankly-nav-icon"', erankly_nav_icon( 'general' ) );
		$this->assertStringContainsString( 'M3 11.99', erankly_nav_icon( 'general' ) );
		$this->assertStringContainsString( 'M7 3v18', erankly_nav_icon( 'no-such-slug' ) );
	}

	public function test_nav_icon_allowed_html_permits_svg_and_path(): void {
		$allowed = erankly_nav_icon_allowed_html();

		$this->assertArrayHasKey( 'svg', $allowed );
		$this->assertArrayHasKey( 'path', $allowed );
		$this->assertArrayHasKey( 'viewbox', $allowed['svg'] );
		$this->assertArrayHasKey( 'd', $allowed['path'] );
	}
}
