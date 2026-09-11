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
	public function test_has_visible_breadcrumbs_detects_the_block(): void {
		$post_id = $this->make_post( array(), '<!-- wp:easyrankly/breadcrumbs /-->' );
		$this->go_to( get_permalink( $post_id ) );

		remove_theme_support( 'erankly-breadcrumbs' );

		$this->assertTrue( erankly_has_visible_breadcrumbs() );
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

	public function test_register_integrations_wires_both_shortcodes_and_the_block(): void {
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
	}
}
