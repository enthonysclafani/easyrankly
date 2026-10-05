<?php
/** Admin renderers: shared field renderers, settings field renderers and section wrappers. */

final class ERankly_Admin_Renderers_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ERANKLY_PATH . 'includes/admin.php';
		require_once ERANKLY_PATH . 'admin/field-renderers.php';
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
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
		$html = $this->capture( static function (): void {
			erankly_render_variable_picker( array( 'post_title' => 'Post title' ) );
			erankly_render_variable_picker( array( 'term_name' => 'Term name' ) );
			do_action( 'admin_footer' );
		} );
		$this->assertSame( 1, substr_count( $html, 'data-erankly-variable-menu' ) );
		$this->assertSame( 2, substr_count( $html, 'data-erankly-variable-preview' ) );

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

		$this->assertStringContainsString( 'data-erankly-variable-groups="[&quot;pagination&quot;]"', $html );
		$this->assertStringNotContainsString( 'data-erankly-variable-menu', $html );
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
			static fn() => erankly_render_media_url_field( 'f', 'n', 'https://example.com/img.png', 'aid', 7, true )
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
			static fn() => erankly_render_media_url_field( 'f', 'n', '{{site_icon_url}}', '', 0, true )
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'data-erankly-media-url-id', $html );

		$no_preview = $this->capture(
			static fn() => erankly_render_media_url_field( 'f', 'n', 'https://example.com/img.png', '', 0, false )
		);
		$this->assertStringNotContainsString( 'data-erankly-media-url-preview', $no_preview );
	}

	public function test_organization_details_render_the_legal_and_address_fields(): void {
		$html = $this->capture( static fn() => erankly_render_organization_details( erankly_get_settings() ) );

		$this->assertStringContainsString( 'class="erankly-settings-details"', $html );
		$this->assertStringContainsString( 'id="erankly-organization-details"', $html );
		foreach ( array( 'organization_legal_name', 'organization_vat_id', 'organization_tax_id', 'organization_street_address', 'organization_locality', 'organization_region', 'organization_postal_code', 'organization_country' ) as $key ) {
			$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[' . $key . ']"', $html );
		}
	}

	public function test_organization_details_use_address_heading_for_person_identity(): void {
		$settings                          = erankly_get_settings();
		$settings['schema_identity']       = 'person';
		$settings['enable_local_business'] = 1;

		$html = $this->capture( static fn() => erankly_render_organization_details( $settings ) );

		$this->assertStringContainsString( 'data-erankly-label-person="Address"', $html );
		$this->assertStringContainsString( 'data-erankly-organization-only hidden', $html );
		$this->assertMatchesRegularExpression( '/<details[^>]*\sopen/', $html );
	}

	public function test_local_business_incomplete_notice_links_to_the_address_fields(): void {
		require_once ERANKLY_PATH . 'admin/settings-page.php';

		$settings                          = erankly_get_settings();
		$settings['enable_local_business'] = 1;

		$html = $this->capture( static fn() => erankly_render_local_business_settings( $settings ) );

		$this->assertStringContainsString( 'Incomplete configuration', $html );
		$this->assertStringContainsString( 'erankly-notice-body', $html );
		$this->assertStringContainsString( esc_url( erankly_settings_tab_url( 'seo' ) ), $html );
		$this->assertStringContainsString( 'erankly_tab=seo', $html );
		$this->assertStringContainsString( '#erankly-organization-details', $html );
		$this->assertStringContainsString( 'Set the address in SEO', $html );
	}

	public function test_local_business_settings_hide_the_fieldset_until_enabled(): void {
		$settings                    = erankly_get_settings();
		$settings['enable_local_business'] = 0;

		$html = $this->capture( static fn() => erankly_render_local_business_settings( $settings ) );

		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[enable_local_business]" value="1"', $html );
		$this->assertStringContainsString( 'data-erankly-local-business-fields hidden', $html );
		$this->assertStringContainsString( 'data-erankly-local-business-site-list', $html );
		$this->assertStringContainsString( 'data-erankly-sites-initialized="0"', $html );
		$this->assertStringNotContainsString( 'data-erankly-local-business-site="', $html );
	}

	public function test_disabled_local_business_settings_preserve_stored_pages_as_hidden_fields(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$settings                          = erankly_get_settings();
		$settings['enable_local_business'] = 0;
		$settings['local_business_pages']  = array(
			get_current_blog_id() => $page_id,
			9999                  => 42,
		);

		$html = $this->capture( static fn() => erankly_render_local_business_settings( $settings ) );

		$this->assertStringContainsString(
			'name="' . ERANKLY_OPTION . '[local_business_pages][' . get_current_blog_id() . ']"',
			$html
		);
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[local_business_pages][9999]"', $html );
		$this->assertStringNotContainsString( 'data-erankly-local-business-site="', $html );
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
		$this->assertStringContainsString( 'data-erankly-local-business-page-search', $html );
	}

	public function test_opening_hours_fields_render_seven_rows_and_respect_closed_days(): void {
		$hours = erankly_default_opening_hours();
		$hours['monday']['closed'] = 1;

		$html = $this->capture( static fn() => erankly_render_opening_hours_fields( $hours ) );

		$this->assertSame( 7, substr_count( $html, 'data-erankly-opening-day' ) );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[local_business_hours][monday][intervals][0][opens]"', $html );
		$this->assertStringNotContainsString( 'erankly-opening-hours-row is-closed', $html );
		$this->assertStringContainsString( 'erankly-opening-hours-closed', $html );
		$this->assertMatchesRegularExpression( '/name="' . preg_quote( ERANKLY_OPTION, '/' ) . '\[local_business_hours\]\[monday\]\[closed\]"[^>]*checked/', $html );
		$this->assertStringContainsString( 'data-erankly-opening-intervals', $html );
		$this->assertStringContainsString( '<table class="erankly-opening-hours">', $html );
	}

	public function test_opening_hours_css_hides_intervals_from_the_checked_state(): void {
		$css = (string) file_get_contents( ERANKLY_PATH . 'assets/css/admin-settings.css' );

		$this->assertStringContainsString(
			'.erankly-opening-hours-row:has([data-erankly-day-closed]:checked) [data-erankly-opening-intervals]',
			$css
		);
		$this->assertStringContainsString(
			'.erankly-opening-hours-row:has([data-erankly-day-closed]:checked) [data-erankly-opening-intervals] input',
			$css
		);
		$this->assertStringContainsString(
			'.erankly-opening-hours-row.is-closed [data-erankly-opening-intervals] input',
			$css
		);
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

	public function test_special_page_defaults_render_tabs_and_social_fields(): void {
		$html = $this->capture( static fn() => erankly_render_special_page_defaults( erankly_special_page_keys(), erankly_get_settings() ) );

		$this->assertStringContainsString( 'erankly-tabs-group-pages', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][title]"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][og_title]"', $html );
		$this->assertStringContainsString( 'erankly-defaults-section', $html );
	}

	public function test_special_page_defaults_return_nothing_for_an_empty_entity_list(): void {
		$html = $this->capture( static fn() => erankly_render_special_page_defaults( array(), erankly_get_settings() ) );

		$this->assertSame( '', $html );
	}

	public function test_special_page_group_renders_the_tab_and_social_fields(): void {
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

	public function test_special_page_social_defaults_render_the_social_fields(): void {
		$row = array(
			'og_title'    => 'OG',
			'og_image_id' => 4,
		);

		$html = $this->capture( static fn() => erankly_render_special_page_social_defaults( 'global_special_meta', 'homepage', $row, 'erankly-global_special_meta-homepage' ) );
		$this->assertStringContainsString( 'erankly-defaults-section', $html );
		$this->assertStringContainsString( 'id="erankly-global_special_meta-homepage-og-title"', $html );
		$this->assertStringContainsString( 'name="' . ERANKLY_OPTION . '[global_special_meta][homepage][og_image_id]" value="4"', $html );
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

	public function test_section_open_and_close_emit_balanced_markup_without_doc_link(): void {
		$html = $this->capture(
			static function (): void {
				erankly_section_open( 'Search engines', array( 'doc' => 'search-engines' ) );
				erankly_section_close();
			}
		);

		$this->assert_balanced_divs( $html );
		$this->assertStringContainsString( 'class="erankly-settings-section"', $html );
		$this->assertStringContainsString( 'class="erankly-card"', $html );
		$this->assertStringNotContainsString( 'erankly-section-doc-link', $html );
		$this->assertStringNotContainsString( 'data-erankly-doc-section', $html );
		$this->assertStringNotContainsString( 'Learn more', $html );
	}

	public function test_section_open_honours_class_and_card_false(): void {
		$html = $this->capture(
			static function (): void {
				erankly_section_open( 'Probe section', array( 'class' => 'probe', 'card' => false ) );
				erankly_section_close();
			}
		);

		$this->assert_balanced_divs( $html );
		$this->assertStringContainsString( 'erankly-settings-section probe">', $html );
		$this->assertStringNotContainsString( 'class="erankly-card"', $html );
	}
}
