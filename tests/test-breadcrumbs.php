<?php
/**
 * Breadcrumbs: trail building, rendering, JSON-LD mirror and the visibility gate.
 *
 * erankly_get_breadcrumb_items() memoises per request, so trail-shape tests run
 * in separate processes to get a fresh resolution for each query context.
 */

final class ERankly_Breadcrumbs_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
		erankly_reset_breadcrumb_runtime_state();
	}

	/** @param array<string,string> $meta Meta without the plugin prefix. */
	private function make_post( array $meta = array(), string $content = '', string $title = 'Articolo' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		$this->assertIsInt( $post_id );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_erankly_' . $key, $value );
		}

		return (int) $post_id;
	}

	private function require_core_breadcrumbs(): void {
		if ( ! function_exists( 'render_block_core_breadcrumbs' ) ) {
			$this->markTestSkipped( 'core/breadcrumbs is not available in this WordPress test suite.' );
		}
	}

	/** @param array<string,mixed> $overrides Block attribute overrides. */
	private function core_breadcrumb_attributes( array $overrides = array() ): array {
		return array_merge( erankly_core_breadcrumbs_default_attributes(), $overrides );
	}

	private function core_breadcrumb_block( int $post_id, string $post_type = 'page' ): object {
		return (object) array(
			'context' => array(
				'postId'   => $post_id,
				'postType' => $post_type,
			),
		);
	}

	/** @return array<int,array<string,string>> */
	private function trail_from_core_html( string $html ): array {
		if ( ! preg_match_all( '/<li>(.*?)<\/li>/s', $html, $matches ) ) {
			return array();
		}

		$items = array();

		foreach ( $matches[1] as $li ) {
			if ( preg_match( '/<a href="([^"]*)">(.*?)<\/a>/s', $li, $anchor ) ) {
				$items[] = array(
					'name' => wp_strip_all_tags( html_entity_decode( $anchor[2] ) ),
					'url'  => html_entity_decode( $anchor[1] ),
				);
				continue;
			}

			if ( preg_match( '/<span[^>]*>(.*?)<\/span>/s', $li, $span ) ) {
				$items[] = array(
					'name' => wp_strip_all_tags( html_entity_decode( $span[1] ) ),
					'url'  => '',
				);
			}
		}

		return $items;
	}

	/** @param array<int,array<string,string>> $visible Visible native items. */
	private function assert_schema_matches_visible_trail( array $visible, array $schema ): void {
		if ( count( $visible ) < 2 ) {
			$this->assertSame( array(), $schema );
			return;
		}

		$this->assertSame( 'BreadcrumbList', $schema['@type'] ?? '' );
		$this->assertArrayHasKey( 'itemListElement', $schema );
		$elements = $schema['itemListElement'];
		$this->assertCount( count( $visible ), $elements );

		foreach ( $visible as $index => $item ) {
			$this->assertSame( $item['name'], $elements[ $index ]['name'] );
			$this->assertSame( $index + 1, $elements[ $index ]['position'] );
			$expected_url = '' !== $item['url'] ? $item['url'] : erankly_get_canonical();
			$this->assertSame( $expected_url, $elements[ $index ]['item'] );
		}
	}

	public function test_post_breadcrumb_name_prefers_the_dedicated_meta(): void {
		$post_id = $this->make_post( array( 'breadcrumb_name' => 'Etichetta briciola' ) );

		$this->assertSame( 'Etichetta briciola', erankly_get_post_breadcrumb_name( $post_id ) );
	}

	public function test_post_breadcrumb_name_reuses_the_title_meta_in_simplified_mode(): void {
		erankly_tests_set_settings( array( 'simplified_mode' => 1 ) );
		erankly_clear_settings_cache();

		$post_id = $this->make_post( array( 'title' => 'Titolo SEO' ) );

		$this->assertSame( 'Titolo SEO', erankly_get_post_breadcrumb_name( $post_id ) );
	}

	public function test_post_breadcrumb_name_ignores_the_title_meta_outside_simplified_mode(): void {
		erankly_tests_set_settings( array( 'simplified_mode' => 0 ) );
		erankly_clear_settings_cache();

		$post_id = $this->make_post( array( 'title' => 'Titolo SEO' ), '', 'Titolo del post' );

		$this->assertSame( 'Titolo del post', erankly_get_post_breadcrumb_name( $post_id ) );
	}

	public function test_post_breadcrumb_name_falls_back_to_the_post_title(): void {
		$post_id = $this->make_post( array(), '', 'Titolo del post' );

		$this->assertSame( 'Titolo del post', erankly_get_post_breadcrumb_name( $post_id ) );
	}

	public function test_post_breadcrumb_name_is_filterable(): void {
		$post_id = $this->make_post( array(), '', 'Titolo del post' );

		$filter = static function ( string $name, int $id ): string {
			return $name . ' (#' . $id . ')';
		};

		add_filter( 'erankly_post_breadcrumb_name', $filter, 10, 2 );

		try {
			$this->assertSame( 'Titolo del post (#' . $post_id . ')', erankly_get_post_breadcrumb_name( $post_id ) );
		} finally {
			remove_filter( 'erankly_post_breadcrumb_name', $filter, 10 );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_start_with_home_and_end_with_the_post(): void {
		$post_id = $this->make_post( array(), '', 'Articolo singolo' );
		$this->go_to( get_permalink( $post_id ) );

		$items = erankly_get_breadcrumb_items();

		$this->assertGreaterThanOrEqual( 2, count( $items ) );
		$this->assertSame( home_url( '/' ), $items[0]['url'] );
		$this->assertSame( 'Articolo singolo', $items[ count( $items ) - 1 ]['name'] );
		$this->assertSame( '', $items[ count( $items ) - 1 ]['url'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_include_the_category_for_a_post(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Categoria prova',
			)
		);
		$this->assertIsInt( $term_id );

		$post_id = $this->make_post();
		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$this->go_to( get_permalink( $post_id ) );

		$names = wp_list_pluck( erankly_get_breadcrumb_items(), 'name' );

		$this->assertContains( 'Categoria prova', $names );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_collapse_on_the_static_front_page(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Home pubblica',
			)
		);

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->go_to( home_url( '/' ) );

		// A static front page is the same resource as the leading Home crumb.
		$this->assertCount( 1, erankly_get_breadcrumb_items() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_end_with_the_term_name(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Solo categoria',
			)
		);

		$this->go_to( get_term_link( $term_id ) );

		$items = erankly_get_breadcrumb_items();

		$this->assertSame( 'Solo categoria', $items[ count( $items ) - 1 ]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_carry_the_search_query(): void {
		$this->go_to( home_url( '/?s=parola-cercata' ) );

		$items = erankly_get_breadcrumb_items();

		$this->assertSame( 'parola-cercata', $items[ count( $items ) - 1 ]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumb_items_carry_the_not_found_label(): void {
		$this->go_to( home_url( '/?p=99999999' ) );

		$items = erankly_get_breadcrumb_items();

		$this->assertSame( 'Page not found', $items[ count( $items ) - 1 ]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumbs_render_markup_without_echoing_when_disabled(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		$html = erankly_breadcrumbs( array( 'echo' => false ) );
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertStringContainsString( '<nav class="erankly-breadcrumbs"', $html );
		$this->assertStringContainsString( '<ol>', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumbs_echo_when_requested(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		$returned = erankly_breadcrumbs( array( 'echo' => true ) );
		$printed  = (string) ob_get_clean();

		$this->assertSame( $returned, $printed );
		$this->assertNotSame( '', $printed );
	}

	public function test_breadcrumbs_return_empty_when_the_setting_is_off(): void {
		erankly_tests_set_settings( array( 'enable_breadcrumbs' => 0 ) );
		erankly_clear_settings_cache();

		ob_start();
		$html = erankly_breadcrumbs( array( 'echo' => false ) );
		ob_end_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumbs_return_empty_for_a_single_item_trail(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		$this->go_to( home_url( '/' ) );

		$this->assertLessThan( 2, count( erankly_get_breadcrumb_items() ) );
		$this->assertSame( '', erankly_breadcrumbs( array( 'echo' => false ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_breadcrumbs_html_is_filterable(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function (): string {
			return '<nav class="erankly-breadcrumbs"><ol><li>Filtro</li></ol></nav>';
		};

		add_filter( 'erankly_breadcrumbs_html', $filter );

		try {
			$html = erankly_breadcrumbs( array( 'echo' => false ) );

			$this->assertStringContainsString( 'Filtro', $html );
		} finally {
			remove_filter( 'erankly_breadcrumbs_html', $filter );
		}
	}

	public function test_legacy_alias_delegates_to_erankly_breadcrumbs(): void {
		$this->assertTrue( function_exists( 'easyrankly_breadcrumbs' ) );
		$this->assertSame(
			erankly_breadcrumbs( array( 'echo' => false ) ),
			easyrankly_breadcrumbs( array( 'echo' => false ) )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_breadcrumb_list_positions_are_sequential(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$schema = erankly_schema_breadcrumb_list();

		$this->assertSame( 'BreadcrumbList', $schema['@type'] );
		$this->assertStringEndsWith( '#breadcrumb', $schema['@id'] );
		$this->assertNotEmpty( $schema['itemListElement'] );

		$positions = wp_list_pluck( $schema['itemListElement'], 'position' );
		$this->assertSame( range( 1, count( $positions ) ), $positions );

		foreach ( $schema['itemListElement'] as $element ) {
			$this->assertSame( 'ListItem', $element['@type'] );
			$this->assertArrayHasKey( 'item', $element );
		}
	}

	public function test_schema_breadcrumb_list_is_empty_for_a_single_item_trail(): void {
		// Non-singular context with no trail keeps only the Home crumb.
		$this->go_to( home_url( '/' ) );

		$this->assertSame( array(), erankly_schema_breadcrumb_list() );
	}

	public function test_should_emit_schema_is_false_when_breadcrumbs_are_disabled(): void {
		erankly_tests_set_settings( array( 'enable_breadcrumbs' => 0 ) );
		erankly_clear_settings_cache();

		$this->assertFalse( erankly_should_emit_breadcrumb_schema() );
	}

	public function test_should_emit_schema_is_false_in_off_mode(): void {
		erankly_tests_set_settings(
			array(
				'enable_breadcrumbs'      => 1,
				'breadcrumb_jsonld_mode'  => 'off',
			)
		);
		erankly_clear_settings_cache();

		$this->assertFalse( erankly_should_emit_breadcrumb_schema() );
	}

	public function test_should_emit_schema_is_true_in_always_mode(): void {
		erankly_tests_set_settings(
			array(
				'enable_breadcrumbs'     => 1,
				'breadcrumb_jsonld_mode' => 'always',
			)
		);
		erankly_clear_settings_cache();

		$this->assertTrue( erankly_should_emit_breadcrumb_schema() );
	}

	public function test_should_emit_schema_follows_visibility_in_when_visible_mode(): void {
		erankly_tests_set_settings(
			array(
				'enable_breadcrumbs'     => 1,
				'breadcrumb_jsonld_mode' => 'when_visible',
			)
		);
		erankly_clear_settings_cache();

		$this->assertSame( erankly_has_visible_breadcrumbs(), erankly_should_emit_breadcrumb_schema() );
	}

	public function test_has_visible_breadcrumbs_honours_theme_support(): void {
		add_theme_support( 'erankly-breadcrumbs' );

		try {
			$this->assertTrue( erankly_has_visible_breadcrumbs() );
		} finally {
			remove_theme_support( 'erankly-breadcrumbs' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_has_visible_breadcrumbs_detects_the_shortcode(): void {
		$post_id = $this->make_post( array(), 'Testo [erankly_breadcrumbs] fine' );
		$this->go_to( get_permalink( $post_id ) );

		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_has_visible_breadcrumbs_detects_the_legacy_shortcode(): void {
		$post_id = $this->make_post( array(), 'Testo [easyrankly_breadcrumbs] fine' );
		$this->go_to( get_permalink( $post_id ) );

		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_has_visible_breadcrumbs_detects_the_legacy_block(): void {
		$post_id = $this->make_post( array(), '<!-- wp:easyrankly/breadcrumbs /-->' );
		$this->go_to( get_permalink( $post_id ) );

		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_has_visible_breadcrumbs_detects_the_core_block(): void {
		$post_id = $this->make_post( array(), '<!-- wp:core/breadcrumbs /-->' );
		$this->go_to( get_permalink( $post_id ) );

		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
	}

	public function test_has_visible_breadcrumbs_detects_a_rendered_core_trail(): void {
		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertFalse( erankly_has_visible_breadcrumbs() );

		erankly_mark_rendered_core_breadcrumbs( '<nav class="wp-block-breadcrumbs"><ol><li>Home</li></ol></nav>' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
	}

	public function test_core_breadcrumb_render_is_ignored_when_breadcrumbs_are_disabled(): void {
		erankly_tests_set_settings( array( 'enable_breadcrumbs' => 0 ) );
		erankly_clear_settings_cache();
		remove_theme_support( 'erankly-breadcrumbs' );

		erankly_mark_rendered_core_breadcrumbs( '<nav class="wp-block-breadcrumbs"><ol><li>Home</li></ol></nav>' );

		$this->assertFalse( erankly_has_visible_breadcrumbs() );
	}

	public function test_core_breadcrumbs_block_availability_follows_the_registry_after_init(): void {
		$original = $GLOBALS['wp_version'];
		$registry = WP_Block_Type_Registry::get_instance();
		$block    = $registry->is_registered( 'core/breadcrumbs' ) ? $registry->get_registered( 'core/breadcrumbs' ) : null;
		$dummy    = null;

		try {
			if ( $block ) {
				$registry->unregister( 'core/breadcrumbs' );
			}

			$GLOBALS['wp_version'] = '7.0';
			$this->assertFalse( erankly_core_breadcrumbs_block_available() );

			$GLOBALS['wp_version'] = '6.9';
			$this->assertFalse( erankly_core_breadcrumbs_block_available() );

			$dummy = new WP_Block_Type( 'core/breadcrumbs', array( 'title' => 'Breadcrumbs' ) );
			$registry->register( $dummy );
			$this->assertTrue( erankly_core_breadcrumbs_block_available() );
		} finally {
			$GLOBALS['wp_version'] = $original;
			if ( $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->unregister( 'core/breadcrumbs' );
			}
			if ( $block && ! $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->register( $block );
			}
		}
	}

	public function test_breadcrumb_settings_help_explains_the_native_block_and_older_wordpress(): void {
		$available = erankly_breadcrumb_settings_help_text( true );
		$missing   = erankly_breadcrumb_settings_help_text( false );

		$this->assertStringContainsString( 'WordPress 7.0', $available );
		$this->assertStringContainsString( '[erankly_breadcrumbs]', $available );
		$this->assertStringContainsString( 'does not add a separate Gutenberg block to the inserter', $available );

		$this->assertStringContainsString( 'WordPress 7.0', $missing );
		$this->assertStringContainsString( '[erankly_breadcrumbs]', $missing );
		$this->assertStringContainsString( "EasyRankly's Breadcrumbs block", $missing );
	}

	public function test_breadcrumb_settings_help_escapes_apostrophes_in_both_availability_states(): void {
		foreach ( array( true, false ) as $available ) {
			$help    = erankly_breadcrumb_settings_help_text( $available );
			$escaped = esc_html( $help );

			$this->assertNotSame( '', $escaped );
			if ( str_contains( $help, "'" ) ) {
				$this->assertNotSame( $help, $escaped );
				$this->assertStringContainsString( '&#039;', $escaped );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_breadcrumb_items_receive_the_easyrankly_name(): void {
		$post_id = $this->make_post( array( 'breadcrumb_name' => 'Etichetta briciola' ), '', 'Titolo del post' );
		$this->go_to( get_permalink( $post_id ) );

		$filtered = erankly_filter_core_breadcrumb_items(
			array(
				array(
					'label' => 'Home',
					'url'   => home_url( '/' ),
				),
				array(
					'label' => 'Titolo del post',
				),
			)
		);

		$this->assertSame( 'Etichetta briciola', $filtered[1]['label'] );
	}

	public function test_core_breadcrumb_items_are_unchanged_when_breadcrumbs_are_disabled(): void {
		erankly_tests_set_settings( array( 'enable_breadcrumbs' => 0 ) );
		erankly_clear_settings_cache();

		$items = array(
			array(
				'label' => 'Home',
				'url'   => home_url( '/' ),
			),
		);

		$this->assertSame( $items, erankly_filter_core_breadcrumb_items( $items ) );
	}

	public function test_has_visible_breadcrumbs_is_filterable(): void {
		$filter = static function (): bool {
			return true;
		};

		add_filter( 'erankly_has_visible_breadcrumbs', $filter );

		try {
			$this->assertTrue( erankly_has_visible_breadcrumbs() );
		} finally {
			remove_filter( 'erankly_has_visible_breadcrumbs', $filter );
		}
	}

	public function test_shortcode_callback_returns_markup_without_echoing(): void {
		ob_start();
		$html = erankly_breadcrumbs_shortcode( array( 'echo' => 'yes' ) );
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertIsString( $html );
	}

	public function test_block_render_callback_returns_markup_without_echoing(): void {
		ob_start();
		$html = erankly_render_breadcrumbs_block( array( 'className' => 'ignored' ), '<p>ignored</p>' );
		$printed = (string) ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertIsString( $html );
	}

	public function test_register_integrations_wires_both_shortcodes_and_the_legacy_block(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		// The plugin bootstrap already registers the block; unregister so the call
		// under test performs the registration instead of tripping the duplicate notice.
		if ( $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
			$registry->unregister( 'easyrankly/breadcrumbs' );
		}

		remove_shortcode( 'erankly_breadcrumbs' );
		remove_shortcode( 'easyrankly_breadcrumbs' );

		erankly_register_breadcrumb_integrations();

		$this->assertSame( 'erankly_breadcrumbs_shortcode', $GLOBALS['shortcode_tags']['erankly_breadcrumbs'] ?? null );
		$this->assertSame( 'erankly_breadcrumbs_shortcode', $GLOBALS['shortcode_tags']['easyrankly_breadcrumbs'] ?? null );
		$this->assertTrue( $registry->is_registered( 'easyrankly/breadcrumbs' ) );
		$this->assertSame(
			! erankly_core_breadcrumbs_block_available(),
			(bool) ( $registry->get_registered( 'easyrankly/breadcrumbs' )->supports['inserter'] ?? false )
		);
		$this->assertSame( 10, has_filter( 'block_core_breadcrumbs_items', 'erankly_filter_core_breadcrumb_items' ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'block_core_breadcrumbs_items', 'erankly_capture_core_breadcrumb_items' ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'render_block_core/breadcrumbs', 'erankly_confirm_rendered_core_breadcrumbs' ) );
	}

	public function test_legacy_block_stays_in_the_inserter_when_core_breadcrumbs_are_unavailable(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$core     = $registry->is_registered( 'core/breadcrumbs' ) ? $registry->get_registered( 'core/breadcrumbs' ) : null;

		try {
			if ( $core ) {
				$registry->unregister( 'core/breadcrumbs' );
			}
			if ( $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
				$registry->unregister( 'easyrankly/breadcrumbs' );
			}

			$this->assertFalse( erankly_core_breadcrumbs_block_available() );
			erankly_register_breadcrumb_integrations();
			$this->assertTrue( $registry->is_registered( 'easyrankly/breadcrumbs' ) );
			$this->assertTrue( (bool) ( $registry->get_registered( 'easyrankly/breadcrumbs' )->supports['inserter'] ?? false ) );
		} finally {
			if ( $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
				$registry->unregister( 'easyrankly/breadcrumbs' );
			}
			if ( $core && ! $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->register( $core );
			}
			erankly_register_breadcrumb_integrations();
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_return_only_breadcrumbs_do_not_mark_the_trail_visible(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );
		remove_theme_support( 'erankly-breadcrumbs' );
		erankly_reset_breadcrumb_runtime_state();

		ob_start();
		$returned = erankly_breadcrumbs( array( 'echo' => false ) );
		$printed  = (string) ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertNotSame( '', $returned );
		$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );
		$this->assertFalse( erankly_has_visible_breadcrumbs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_echoed_breadcrumbs_and_integrated_renderers_mark_the_trail_visible(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );
		remove_theme_support( 'erankly-breadcrumbs' );

		erankly_reset_breadcrumb_runtime_state();
		ob_start();
		erankly_breadcrumbs( array( 'echo' => true ) );
		ob_end_clean();
		$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );

		erankly_reset_breadcrumb_runtime_state();
		erankly_breadcrumbs_shortcode();
		$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );

		erankly_reset_breadcrumb_runtime_state();
		erankly_render_breadcrumbs_block();
		$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_breadcrumb_items_receive_the_easyrankly_name_when_the_title_is_empty(): void {
		$post_id = $this->make_post( array( 'breadcrumb_name' => 'Etichetta briciola' ), '', '' );
		$this->go_to( get_permalink( $post_id ) );

		$untitled = erankly_core_breadcrumbs_untitled_label( $post_id );
		$filtered = erankly_filter_core_breadcrumb_items(
			array(
				array(
					'label' => 'Home',
					'url'   => home_url( '/' ),
				),
				array(
					'label' => $untitled,
				),
			)
		);

		$this->assertSame( 'Etichetta briciola', $filtered[1]['label'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unregistered_core_breadcrumbs_in_content_are_not_treated_as_visible(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$block    = $registry->is_registered( 'core/breadcrumbs' ) ? $registry->get_registered( 'core/breadcrumbs' ) : null;
		$post_id  = $this->make_post( array(), '<!-- wp:core/breadcrumbs /-->' );

		try {
			if ( $block ) {
				$registry->unregister( 'core/breadcrumbs' );
			}

			$this->go_to( get_permalink( $post_id ) );
			remove_theme_support( 'erankly-breadcrumbs' );
			erankly_reset_breadcrumb_runtime_state();

			$this->assertFalse( erankly_core_breadcrumbs_block_available() );
			$this->assertFalse( erankly_has_visible_breadcrumbs() );
		} finally {
			if ( $block && ! $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->register( $block );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_matches_native_block_without_home_on_a_child_page(): void {
		$this->require_core_breadcrumbs();

		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Genitore',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Pagina figlia',
				'post_parent'  => $parent_id,
				'post_content' => '<!-- wp:core/breadcrumbs {"showHomeItem":false} /-->',
			)
		);

		$this->go_to( get_permalink( $child_id ) );
		remove_theme_support( 'erankly-breadcrumbs' );
		erankly_reset_breadcrumb_runtime_state();

		$html   = do_blocks( '<!-- wp:core/breadcrumbs {"showHomeItem":false} /-->' );
		$schema = erankly_schema_breadcrumb_list();

		$this->assertStringNotContainsString( '>Home<', $html );
		$this->assert_schema_matches_visible_trail( $this->trail_from_core_html( $html ), $schema );
		$names = wp_list_pluck( $schema['itemListElement'], 'name' );
		$this->assertContains( 'Genitore', $names );
		$this->assertContains( 'Pagina figlia', $names );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_at_wp_head_matches_native_block_in_post_content_before_render(): void {
		$this->require_core_breadcrumbs();

		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Antenato',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Corrente',
				'post_parent'  => $parent_id,
				'post_content' => '<!-- wp:core/breadcrumbs {"showHomeItem":false,"showCurrentItem":true} /-->',
			)
		);

		$this->go_to( get_permalink( $child_id ) );
		remove_theme_support( 'erankly-breadcrumbs' );
		erankly_reset_breadcrumb_runtime_state();

		$schema_at_head = erankly_schema_breadcrumb_list();
		$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );

		$html = do_blocks( '<!-- wp:core/breadcrumbs {"showHomeItem":false,"showCurrentItem":true} /-->' );

		$this->assert_schema_matches_visible_trail( $this->trail_from_core_html( $html ), $schema_at_head );
		$this->assertNotContains( 'Home', wp_list_pluck( $schema_at_head['itemListElement'], 'name' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_does_not_invent_home_when_native_block_hides_it_on_a_root_page(): void {
		$this->require_core_breadcrumbs();

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Pagina corrente',
				'post_content' => '<!-- wp:core/breadcrumbs {"showHomeItem":false} /-->',
			)
		);

		$this->go_to( get_permalink( $page_id ) );
		erankly_reset_breadcrumb_runtime_state();

		$schema  = erankly_schema_breadcrumb_list();
		$html    = do_blocks( '<!-- wp:core/breadcrumbs {"showHomeItem":false} /-->' );
		$visible = $this->trail_from_core_html( $html );

		$this->assertCount( 1, $visible );
		$this->assertSame( array(), $schema );
		$this->assertNotContains( 'Home', wp_list_pluck( $visible, 'name' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_omits_the_current_item_when_the_native_block_hides_it(): void {
		$this->require_core_breadcrumbs();

		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Genitore',
			)
		);
		$child_id  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Pagina figlia',
				'post_parent'  => $parent_id,
				'post_content' => '<!-- wp:core/breadcrumbs {"showCurrentItem":false} /-->',
			)
		);

		$this->go_to( get_permalink( $child_id ) );
		erankly_reset_breadcrumb_runtime_state();

		$html   = do_blocks( '<!-- wp:core/breadcrumbs {"showCurrentItem":false} /-->' );
		$schema = erankly_schema_breadcrumb_list();

		$this->assert_schema_matches_visible_trail( $this->trail_from_core_html( $html ), $schema );
		$this->assertNotContains( 'Pagina figlia', wp_list_pluck( $schema['itemListElement'], 'name' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_follows_the_native_term_not_the_easyrankly_primary_category(): void {
		$this->require_core_breadcrumbs();

		$core_term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Categoria nativa',
			)
		);
		$primary_id   = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Categoria primaria EasyRankly',
			)
		);
		$post_id      = $this->make_post(
			array(),
			'<!-- wp:core/breadcrumbs {"prefersTaxonomy":true} /-->',
			'Articolo categorie'
		);

		wp_set_post_terms( $post_id, array( $core_term_id, $primary_id ), 'category' );
		update_post_meta( $post_id, '_erankly_primary_terms', array( 'category' => $primary_id ) );

		$this->go_to( get_permalink( $post_id ) );
		erankly_reset_breadcrumb_runtime_state();

		$html   = do_blocks( '<!-- wp:core/breadcrumbs {"prefersTaxonomy":true} /-->' );
		$schema = erankly_schema_breadcrumb_list();
		$easy   = wp_list_pluck( erankly_get_breadcrumb_items(), 'name' );

		$this->assert_schema_matches_visible_trail( $this->trail_from_core_html( $html ), $schema );
		$schema_names = wp_list_pluck( $schema['itemListElement'], 'name' );
		$this->assertNotSame( $easy, $schema_names );
		$this->assertContains( 'Categoria primaria EasyRankly', $easy );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_matches_native_archive_and_pagination_crumbs(): void {
		$this->require_core_breadcrumbs();

		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Archivio paged',
			)
		);

		update_option( 'posts_per_page', 1 );

		for ( $i = 0; $i < 3; $i++ ) {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Paged ' . $i,
				)
			);
			wp_set_post_terms( $post_id, array( $term_id ), 'category' );
		}

		$this->go_to( add_query_arg( 'paged', 2, get_term_link( $term_id ) ) );
		erankly_reset_breadcrumb_runtime_state();

		$html   = do_blocks( '<!-- wp:breadcrumbs /-->' );
		$schema = erankly_schema_breadcrumb_list();

		$this->assert_schema_matches_visible_trail( $this->trail_from_core_html( $html ), $schema );
		$names = wp_list_pluck( $schema['itemListElement'], 'name' );
		$this->assertContains( 'Archivio paged', $names );
		$this->assertNotEmpty( preg_grep( '/Page /', $names ) );
	}

	public function test_current_hook_priority_reads_the_running_callback(): void {
		$priority = null;

		$listener = static function () use ( &$priority ): void {
			$priority = erankly_current_hook_priority( 'erankly_test_hook_priority' );
		};

		add_action( 'erankly_test_hook_priority', $listener, 17 );
		do_action( 'erankly_test_hook_priority' );
		remove_action( 'erankly_test_hook_priority', $listener, 17 );

		$this->assertSame( 17, $priority );
	}

	public function test_block_registration_window_has_passed_after_init(): void {
		$this->assertFalse( doing_action( 'init' ) );
		$this->assertTrue( erankly_block_registration_window_has_passed() );
	}

	public function test_legacy_inserter_syncs_when_core_is_removed_after_registration(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$core     = $registry->is_registered( 'core/breadcrumbs' ) ? $registry->get_registered( 'core/breadcrumbs' ) : null;

		if ( ! $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
			erankly_register_breadcrumb_integrations();
		}

		try {
			if ( $core ) {
				$registry->unregister( 'core/breadcrumbs' );
			}

			$this->assertFalse( erankly_core_breadcrumbs_block_available() );
			erankly_sync_legacy_breadcrumbs_availability();
			$legacy = $registry->get_registered( 'easyrankly/breadcrumbs' );
			$this->assertTrue( (bool) ( $legacy->supports['inserter'] ?? false ) );
			$this->assertFalse( $legacy->supports['html'] );
			$this->assertSame( array( 'wide', 'full' ), $legacy->supports['align'] );
		} finally {
			if ( $core && ! $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->register( $core );
			}
			erankly_sync_legacy_breadcrumbs_availability();
		}
	}

	public function test_legacy_inserter_syncs_when_core_registers_after_the_plugin(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$core     = $registry->is_registered( 'core/breadcrumbs' ) ? $registry->get_registered( 'core/breadcrumbs' ) : null;

		try {
			if ( $core ) {
				$registry->unregister( 'core/breadcrumbs' );
			}
			if ( $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
				$registry->unregister( 'easyrankly/breadcrumbs' );
			}

			erankly_register_breadcrumb_integrations();
			$this->assertTrue( (bool) ( $registry->get_registered( 'easyrankly/breadcrumbs' )->supports['inserter'] ?? false ) );

			$dummy = new WP_Block_Type( 'core/breadcrumbs', array( 'title' => 'Breadcrumbs' ) );
			$registry->register( $dummy );
			erankly_sync_legacy_breadcrumbs_availability();

			$legacy = $registry->get_registered( 'easyrankly/breadcrumbs' );
			$this->assertFalse( (bool) ( $legacy->supports['inserter'] ?? true ) );
			$this->assertFalse( $legacy->supports['html'] );
			$this->assertSame( array( 'wide', 'full' ), $legacy->supports['align'] );
		} finally {
			if ( $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->unregister( 'core/breadcrumbs' );
			}
			if ( $core && ! $registry->is_registered( 'core/breadcrumbs' ) ) {
				$registry->register( $core );
			}
			if ( $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
				$registry->unregister( 'easyrankly/breadcrumbs' );
			}
			erankly_register_breadcrumb_integrations();
		}
	}
}
