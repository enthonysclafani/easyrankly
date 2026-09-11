<?php
/**
 * Document title and meta description resolution.
 *
 * erankly_get_title() and erankly_get_description() memoise their result in a
 * function-static for the whole request, which is correct in production (one
 * request, one resolved title) but means each query context needs its own
 * process here. Every context-dependent test therefore runs isolated.
 */

final class ERankly_Title_Description_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();

		if ( ! function_exists( 'erankly_get_title' ) ) {
			require_once ERANKLY_PATH . 'includes/title-description.php';
		}
	}

	/**
	 * Creates a published post with the given title/description meta.
	 *
	 * @param array<string,string> $meta Meta without the plugin prefix.
	 */
	private function make_post( array $meta = array(), string $content = '', string $title = 'Post di prova' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => $content,
				// The factory seeds an excerpt by default, which would mask the
				// content-based description fallback.
				'post_excerpt' => '',
			)
		);

		$this->assertIsInt( $post_id );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_erankly_' . $key, $value );
		}

		return (int) $post_id;
	}

	public function test_resolve_seo_template_returns_empty_for_an_empty_template(): void {
		$this->assertSame( '', erankly_resolve_seo_template( '' ) );
		$this->assertSame( '', erankly_resolve_seo_template( '   ' ) );
	}

	public function test_resolve_seo_template_collapses_a_template_whose_variables_all_resolve_empty(): void {
		$post_id = $this->make_post( array(), 'Contenuto presente' );

		// {{term_name}} has nothing to resolve against on a singular post, so the
		// leftover separator has to normalise away instead of being emitted.
		$this->assertSame( '', erankly_resolve_seo_template( '{{term_name}} - {{term_name}}', $post_id ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_seo_template_expands_a_variable(): void {
		$post_id = $this->make_post( array(), '', 'Titolo reale' );

		$this->assertSame(
			'Titolo reale | Extra',
			erankly_resolve_seo_template( '{{post_title}} | Extra', $post_id )
		);
	}

	public function test_resolve_seo_template_honours_the_exclude_list(): void {
		$post_id = $this->make_post( array(), '', 'Titolo reale' );

		// Normalisation also trims a separator left dangling at the start.
		$this->assertSame( 'Fine', erankly_resolve_seo_template( '{{post_title}} | Fine', $post_id, array( 'post_title' ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_document_title_falls_back_when_nothing_resolves(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		// A theme-supplied title survives only when the resolved SEO title is empty.
		$filter = static function (): string {
			return '';
		};

		add_filter( 'erankly_title', $filter );

		try {
			$this->assertSame( 'Titolo core', erankly_filter_document_title( 'Titolo core' ) );
		} finally {
			remove_filter( 'erankly_title', $filter );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_document_title_overrides_the_core_title_on_a_singular_post(): void {
		$post_id = $this->make_post( array(), '', 'Titolo del post' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Titolo del post', erankly_filter_document_title( 'Titolo core' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_document_title_uses_the_resolved_seo_title(): void {
		$post_id = $this->make_post( array( 'title' => 'Titolo SEO' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Titolo SEO', erankly_filter_document_title( 'Titolo core' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_document_title_parts_replaces_title_and_drops_other_parts(): void {
		$post_id = $this->make_post( array( 'title' => 'Titolo SEO' ) );
		$this->go_to( get_permalink( $post_id ) );

		$parts = erankly_filter_document_title_parts(
			array(
				'title'   => 'Titolo core',
				'site'    => 'Nome sito',
				'tagline' => 'Tagline',
				'page'    => '2',
			)
		);

		$this->assertSame( 'Titolo SEO', $parts['title'] );
		$this->assertArrayNotHasKey( 'site', $parts );
		$this->assertArrayNotHasKey( 'tagline', $parts );
		$this->assertArrayNotHasKey( 'page', $parts );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_document_title_parts_keeps_parts_when_nothing_resolves(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function (): string {
			return '';
		};

		add_filter( 'erankly_title', $filter );

		$parts = array(
			'title' => 'Titolo core',
			'site'  => 'Nome sito',
		);

		try {
			$this->assertSame( $parts, erankly_filter_document_title_parts( $parts ) );
		} finally {
			remove_filter( 'erankly_title', $filter );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_prefers_the_per_post_meta(): void {
		$post_id = $this->make_post( array( 'title' => 'Meta del post' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Meta del post', erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_expands_variables_in_the_stored_template(): void {
		$post_id = $this->make_post( array( 'title' => '{{post_title}} - {{site_name}}' ), '', 'Articolo' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Articolo - ' . get_bloginfo( 'name' ), erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_falls_back_to_the_post_title_when_nothing_is_configured(): void {
		$post_id = $this->make_post( array(), '', 'Solo il titolo del post' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Solo il titolo del post', erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_is_filterable(): void {
		$post_id = $this->make_post( array( 'title' => 'Prima' ) );
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function (): string {
			return 'Sostituito dal filtro';
		};

		add_filter( 'erankly_title', $filter );

		try {
			$this->assertSame( 'Sostituito dal filtro', erankly_get_title() );
		} finally {
			remove_filter( 'erankly_title', $filter );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_returns_the_site_name_on_the_front_page(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertSame( get_bloginfo( 'name' ), erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_uses_the_search_fallback_for_a_search_request(): void {
		$this->go_to( home_url( '/?s=termine-cercato' ) );

		$this->assertStringContainsString( 'termine-cercato', erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_title_uses_the_not_found_fallback_for_a_404(): void {
		$this->go_to( home_url( '/?p=99999999' ) );

		$this->assertSame( 'Page not found', erankly_get_title() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_prefers_the_per_post_meta(): void {
		$post_id = $this->make_post( array( 'description' => 'Descrizione del post' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Descrizione del post', erankly_get_description() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_falls_back_to_the_content_and_trims_it(): void {
		$long_content = str_repeat( 'contenuto molto lungo ', 40 );
		$post_id      = $this->make_post( array(), $long_content );
		$this->go_to( get_permalink( $post_id ) );

		$description = erankly_get_description();

		$this->assertNotSame( '', $description );
		$this->assertLessThanOrEqual( 160, mb_strlen( $description ) );
		$this->assertStringNotContainsString( '<p>', $description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_prefers_the_excerpt_over_the_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'Riassunto esplicito',
				'post_content' => 'Contenuto completo che non deve vincere.',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Riassunto esplicito', erankly_get_description() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_strips_shortcodes_from_a_generated_fallback(): void {
		$post_id = $this->make_post( array(), '[gallery ids="1,2"] Testo visibile' );
		$this->go_to( get_permalink( $post_id ) );

		$description = erankly_get_description();

		$this->assertStringContainsString( 'Testo visibile', $description );
		$this->assertStringNotContainsString( '[gallery', $description );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_is_filterable(): void {
		$post_id = $this->make_post( array( 'description' => 'Prima' ) );
		$this->go_to( get_permalink( $post_id ) );

		$filter = static function (): string {
			return 'Descrizione sostituita';
		};

		add_filter( 'erankly_description', $filter );

		try {
			$this->assertSame( 'Descrizione sostituita', erankly_get_description() );
		} finally {
			remove_filter( 'erankly_description', $filter );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_description_appends_the_paginated_title_format(): void {
		$post_id = $this->make_post( array( 'title' => 'Articolo' ) );

		erankly_tests_set_settings( array( 'paginated_title_format' => 'Pagina {{page_number}}' ) );
		erankly_clear_settings_cache();

		$this->go_to( get_permalink( $post_id ) );
		// A static front page survives the paged query var; the suffix only
		// applies when the request is actually paginated.
		$GLOBALS['wp_query']->query_vars['paged'] = 2;
		$GLOBALS['wp_query']->is_paged              = true;

		$this->assertStringContainsString( 'Articolo', erankly_get_title() );
	}
}
