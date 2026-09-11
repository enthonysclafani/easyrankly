<?php
/** Classic meta box and taxonomy form fields, plus the post and term save handlers. */

final class ERankly_Admin_Meta_Box_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ERANKLY_PATH . 'includes/admin.php';
		require_once ERANKLY_PATH . 'admin/meta-box.php';
		erankly_load_content_helpers();
	}

	public function tear_down(): void {
		$_POST = array();
		$_GET  = array();
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

	/** Persists a partial settings map and clears the request cache. */
	private function store_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	public function test_register_meta_box_adds_a_box_for_public_post_types(): void {
		$GLOBALS['wp_meta_boxes'] = array();
		set_current_screen( 'post' );
		// The classic meta box is deliberately skipped on block-editor screens.
		get_current_screen()->is_block_editor( false );

		erankly_register_meta_box();

		$this->assertArrayHasKey( 'erankly', $GLOBALS['wp_meta_boxes']['post']['normal']['default'] );
		$this->assertSame( 'EasyRankly', $GLOBALS['wp_meta_boxes']['post']['normal']['default']['erankly']['title'] );
	}

	public function test_register_taxonomy_fields_hooks_the_add_edit_and_save_actions(): void {
		erankly_register_taxonomy_fields();

		$this->assertNotFalse( has_action( 'category_add_form_fields', 'erankly_render_add_term_fields' ) );
		$this->assertNotFalse( has_action( 'category_edit_form_fields', 'erankly_render_edit_term_fields' ) );
		$this->assertNotFalse( has_action( 'created_category', 'erankly_save_term_fields' ) );
		$this->assertNotFalse( has_action( 'edited_category', 'erankly_save_term_fields' ) );
		$this->assertNotFalse( has_action( 'post_tag_add_form_fields', 'erankly_render_add_term_fields' ) );
	}

	public function test_post_global_meta_placeholder_expands_and_trims_the_stored_template(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title' => 'Original title',
				'import_id'  => 900101,
			)
		);

		$this->store_settings(
			array(
				'global_post_type_meta' => array(
					'post' => array( 'title' => '{{post_title}}' ),
				),
			)
		);

		$this->assertSame( 'Original title', erankly_get_post_global_meta_placeholder( $post, 'title', 70 ) );
		$this->assertSame( 'Or', erankly_get_post_global_meta_placeholder( $post, 'title', 3 ) );

		$this->store_settings( array( 'global_post_type_meta' => array() ) );
		$this->assertSame( '', erankly_get_post_global_meta_placeholder( $post, 'title', 70 ) );
	}

	public function test_post_global_social_placeholder_expands_the_template(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title' => 'Social title',
				'import_id'  => 900102,
			)
		);

		$this->store_settings( array( 'default_og_title' => '{{post_title}}' ) );

		$this->assertSame( 'Social title', erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 ) );
		$this->assertSame( 'Soc', erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 4 ) );

		$this->store_settings( array( 'default_og_title' => '' ) );
		$this->assertSame( '', erankly_get_post_global_social_placeholder( $post->ID, 'default_og_title', 60 ) );
	}

	public function test_term_global_meta_placeholder_reads_the_taxonomy_template(): void {
		$this->store_settings(
			array(
				'global_taxonomy_meta' => array(
					'category' => array( 'title' => 'Category template' ),
				),
			)
		);

		$this->assertSame( 'Category template', erankly_get_term_global_meta_placeholder( 'category', 'title' ) );
	}

	public function test_post_general_fields_hide_advanced_fields_in_simplified_mode(): void {
		$post = self::factory()->post->create_and_get();

		$html = $this->capture( static fn() => erankly_render_post_general_fields( $post ) );

		$this->assertStringContainsString( 'name="erankly_title"', $html );
		$this->assertStringContainsString( 'name="erankly_description"', $html );
		$this->assertStringNotContainsString( 'name="erankly_canonical"', $html );

		$this->store_settings(
			array(
				'simplified_mode'    => 0,
				'enable_breadcrumbs' => 1,
			)
		);

		$full = $this->capture( static fn() => erankly_render_post_general_fields( $post ) );

		$this->assertStringContainsString( 'name="erankly_canonical"', $full );
		$this->assertStringContainsString( 'name="erankly_breadcrumb_name"', $full );
	}

	public function test_post_social_fields_render_the_social_inputs_and_media_field(): void {
		$post = self::factory()->post->create_and_get();

		$html = $this->capture( static fn() => erankly_render_post_social_fields( $post ) );

		$this->assertStringContainsString( 'name="erankly_og_title"', $html );
		$this->assertStringContainsString( 'name="erankly_twitter_title"', $html );
		$this->assertStringContainsString( 'name="erankly_twitter_card_type"', $html );
		$this->assertStringContainsString( 'name="erankly_og_image_url"', $html );
		$this->assertStringContainsString( 'data-erankly-media-url-field', $html );
	}

	public function test_post_visibility_fields_switch_between_simplified_and_advanced_modes(): void {
		$post = self::factory()->post->create_and_get();

		$simplified = $this->capture( static fn() => erankly_render_post_visibility_fields( $post ) );
		$this->assertStringContainsString( 'name="erankly_hide_from_search_results"', $simplified );
		$this->assertStringNotContainsString( 'name="erankly_index_directive"', $simplified );

		$this->store_settings( array( 'simplified_mode' => 0 ) );

		$advanced = $this->capture( static fn() => erankly_render_post_visibility_fields( $post ) );
		$this->assertStringContainsString( 'name="erankly_index_directive"', $advanced );
		$this->assertStringContainsString( 'name="erankly_exclude_search"', $advanced );
	}

	public function test_robots_directive_select_maps_axes_to_their_tokens(): void {
		$html = $this->capture(
			static fn() => erankly_render_robots_directive_select( 'erankly_index_directive', 'noindex', 'Indexing', 'Index', 'Noindex' )
		);

		$this->assertStringContainsString( 'id="erankly-index-directive"', $html );
		$this->assertStringContainsString( 'value="inherit"', $html );
		$this->assertMatchesRegularExpression( '/value="noindex"\s+selected=\'selected\'/', $html );

		$image = $this->capture(
			static fn() => erankly_render_robots_directive_select( 'erankly_image_directive', 'inherit', 'Image indexing', 'Allow image indexing', 'Noimageindex' )
		);

		$this->assertStringContainsString( 'id="erankly-image-directive"', $image );
		$this->assertStringContainsString( 'value="imageindex"', $image );
		$this->assertStringContainsString( 'value="noimageindex"', $image );
	}

	public function test_advanced_robots_fields_render_directives_and_limits(): void {
		$html = $this->capture(
			static fn() => erankly_render_advanced_robots_fields(
				array(
					'index_directive'   => 'noindex',
					'follow_directive'  => 'inherit',
					'archive_directive' => 'inherit',
					'snippet_directive' => 'inherit',
					'image_directive'   => 'inherit',
					'max_snippet'       => '10',
					'max_video_preview' => '',
					'max_image_preview' => 'standard',
					'indexifembedded'   => 1,
					'disable_sitemap'   => 0,
				)
			)
		);

		$this->assertStringContainsString( 'name="erankly_index_directive"', $html );
		$this->assertStringContainsString( 'name="erankly_max_snippet" value="10"', $html );
		$this->assertStringContainsString( 'name="erankly_max_image_preview"', $html );
		$this->assertStringContainsString( 'name="erankly_indexifembedded" value="1"', $html );
		$this->assertStringContainsString( 'name="erankly_disable_sitemap" value="1"', $html );
	}

	public function test_post_schema_fields_seed_the_builder_index_with_the_block_count(): void {
		$post = self::factory()->post->create_and_get();

		$empty = $this->capture( static fn() => erankly_render_post_schema_fields( $post ) );
		$this->assertStringContainsString( 'data-erankly-next-index="0"', $empty );
		$this->assertStringContainsString( 'data-erankly-schema-mode="default"', $empty );
		$this->assertStringContainsString( 'name="erankly_schema_mode"', $empty );

		update_post_meta(
			$post->ID,
			'_erankly_schema_blocks',
			array(
				array(
					'type'    => 'custom',
					'enabled' => 1,
					'fields'  => array( 'custom_json' => '{"@type":"Thing"}' ),
				),
			)
		);

		$stored = $this->capture( static fn() => erankly_render_post_schema_fields( get_post( $post->ID ) ) );
		$this->assertStringContainsString( 'data-erankly-next-index="1"', $stored );
		$this->assertStringContainsString( 'data-erankly-schema-notice="default-custom"', $stored );
	}

	public function test_render_meta_box_includes_social_section_only_outside_simplified_mode(): void {
		$post = self::factory()->post->create_and_get();

		$simplified = $this->capture( static fn() => erankly_render_meta_box( $post ) );

		$this->assertStringContainsString( 'name="erankly_meta_box_nonce"', $simplified );
		$this->assertStringContainsString( 'class="erankly-meta-box-section-title"', $simplified );
		$this->assertStringContainsString( 'Search visibility', $simplified );
		$this->assertStringNotContainsString( 'Social sharing', $simplified );

		$this->store_settings( array( 'simplified_mode' => 0 ) );

		$full = $this->capture( static fn() => erankly_render_meta_box( get_post( $post->ID ) ) );
		$this->assertStringContainsString( 'Social sharing', $full );
		$this->assertStringContainsString( 'data-erankly-post-schema', $full );
	}

	public function test_render_add_term_fields_emits_the_nonce_and_wrapper(): void {
		$html = $this->capture( static fn() => erankly_render_add_term_fields( 'category' ) );

		$this->assertStringContainsString( 'class="form-field term-erankly-wrap"', $html );
		$this->assertStringContainsString( 'name="erankly_term_fields_nonce"', $html );
		$this->assertStringContainsString( 'name="erankly_title"', $html );
	}

	public function test_render_edit_term_fields_uses_the_term_id_as_the_field_suffix(): void {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category' ) );

		$html = $this->capture( static fn() => erankly_render_edit_term_fields( $term ) );

		$this->assertStringContainsString( '<tr class="form-field term-erankly-wrap">', $html );
		$this->assertStringContainsString( 'id="erankly-term-title-' . $term->term_id . '"', $html );
		$this->assertStringContainsString( 'name="erankly_term_fields_nonce"', $html );
	}

	public function test_render_term_meta_fields_uses_the_taxonomy_key_for_new_terms(): void {
		$html = $this->capture( static fn() => erankly_render_term_meta_fields( 0, 'category' ) );

		$this->assertStringContainsString( 'id="erankly-term-title-category"', $html );
		$this->assertStringContainsString( 'name="erankly_title"', $html );
		$this->assertStringContainsString( 'name="erankly_term_fields_nonce"', $html );
	}

	public function test_save_meta_box_persists_a_posted_title_and_clears_it_when_empty(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post = self::factory()->post->create_and_get();

		$_POST['erankly_meta_box_nonce'] = wp_create_nonce( 'erankly_save_meta_box' );
		$_POST['erankly_title']          = 'Saved title';

		erankly_save_meta_box( $post->ID, $post );

		$this->assertSame( 'Saved title', get_post_meta( $post->ID, '_erankly_title', true ) );

		$_POST['erankly_title'] = '';
		erankly_save_meta_box( $post->ID, $post );
		$this->assertSame( '', get_post_meta( $post->ID, '_erankly_title', true ) );
	}

	public function test_save_meta_box_ignores_a_request_without_a_valid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post = self::factory()->post->create_and_get();

		$_POST['erankly_meta_box_nonce'] = 'not-a-nonce';
		$_POST['erankly_title']          = 'Untrusted title';

		erankly_save_meta_box( $post->ID, $post );

		$this->assertSame( '', get_post_meta( $post->ID, '_erankly_title', true ) );
	}

	public function test_save_term_fields_persists_and_guards_on_the_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category' ) );

		$_POST['erankly_term_fields_nonce'] = wp_create_nonce( 'erankly_save_term_fields' );
		$_POST['erankly_title']             = 'Term title';

		erankly_save_term_fields( $term->term_id );

		$this->assertSame( 'Term title', get_term_meta( $term->term_id, '_erankly_title', true ) );

		$_POST['erankly_term_fields_nonce'] = 'not-a-nonce';
		$_POST['erankly_title']             = 'Untrusted';
		erankly_save_term_fields( $term->term_id );

		$this->assertSame( 'Term title', get_term_meta( $term->term_id, '_erankly_title', true ) );
	}
}
