<?php
/**
 * Template variable resolution, JSON-LD escaping, admin previews and sample lookups.
 *
 * erankly_get_variable_value() memoises per key+post in a function-static. Under the
 * SQLite test database each test's transaction rollback also rewinds the post
 * auto-increment, so a later test can hand out an ID that a previous test already
 * cached. Tests that need a specific value for a variable therefore run isolated.
 */

final class ERankly_Helpers_Template_Variables_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
	}

	/** @param array<string,mixed> $args Extra post fields. */
	private function make_post( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			wp_parse_args(
				$args,
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Titolo articolo',
					'post_content' => 'Contenuto di prova',
					'post_excerpt' => '',
				)
			)
		);

		$this->assertIsInt( $post_id );

		return (int) $post_id;
	}

	public function test_replace_variables_leaves_a_string_without_tokens_untouched(): void {
		$this->assertSame( 'Nessun token', erankly_replace_variables( 'Nessun token' ) );
		$this->assertSame( '', erankly_replace_variables( '' ) );
	}

	public function test_replace_variables_expands_known_tokens_with_optional_spacing(): void {
		$post_id = $this->make_post();

		$this->assertSame( 'Titolo articolo', erankly_replace_variables( '{{post_title}}', $post_id ) );
		$this->assertSame( 'Titolo articolo', erankly_replace_variables( '{{  post_title  }}', $post_id ) );
		$this->assertSame( 'Titolo articolo', erankly_replace_variables( '{{POST_TITLE}}', $post_id ) );
	}

	public function test_replace_variables_empties_excluded_tokens(): void {
		$post_id = $this->make_post();

		$this->assertSame(
			' - fine',
			erankly_replace_variables( '{{post_title}} - fine', $post_id, array( 'post_title' ) )
		);
	}

	public function test_replace_variables_ignores_unknown_tokens(): void {
		$post_id = $this->make_post();

		$this->assertSame( '', erankly_replace_variables( '{{token_inesistente}}', $post_id ) );
	}

	/**
	 * Post content keeps raw double quotes (a post title does not: WordPress
	 * converts those to typographic entities before the resolver sees them), so
	 * content is the source that actually exercises the JSON escaping.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_replace_json_ld_variables_escapes_quotes_for_json_context(): void {
		$post_id = $this->make_post( array( 'post_content' => 'Testo con "virgolette"' ) );

		$fragment = erankly_replace_json_ld_variables( '{{post_content}}', $post_id );

		$this->assertStringContainsString( '\\"virgolette\\"', $fragment );

		// The escaping contract: the fragment is safe inside a JSON string.
		$decoded = json_decode( '"' . $fragment . '"' );

		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertSame( 'Testo con "virgolette"', $decoded );
	}

	public function test_replace_json_ld_variables_keeps_a_template_literal_intact(): void {
		$post_id = $this->make_post( array( 'post_content' => 'semplice' ) );

		// A template that is already a complete JSON string stays valid.
		$json = erankly_replace_json_ld_variables( '"{{post_content}}"', $post_id );

		$this->assertSame( '"semplice"', $json );
		$this->assertSame( 'semplice', json_decode( $json ) );
	}

	public function test_replace_json_ld_variables_returns_input_without_tokens(): void {
		$this->assertSame( 'nessun token', erankly_replace_json_ld_variables( 'nessun token' ) );
		$this->assertSame( '', erankly_replace_json_ld_variables( '' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_replace_json_ld_variables_escapes_backslashes_and_newlines(): void {
		$post_id = $this->make_post();

		$value = erankly_replace_json_ld_variables( '{{post_content}}', $post_id );

		$this->assertIsString( $value );
		$this->assertSame( 'Contenuto di prova', $value );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_variable_value_resolves_post_fields(): void {
		$post_id = $this->make_post( array( 'post_title' => 'Titolo variabile' ) );

		$this->assertSame( 'Titolo variabile', erankly_get_variable_value( 'post_title', $post_id ) );
		$this->assertNotSame( '', erankly_get_variable_value( 'post_url', $post_id ) );
		$this->assertSame( 'Contenuto di prova', erankly_get_variable_value( 'post_content', $post_id ) );
	}

	public function test_get_variable_value_returns_empty_for_an_unknown_key(): void {
		$post_id = $this->make_post();

		$this->assertSame( '', erankly_get_variable_value( 'chiave_inesistente', $post_id ) );
	}

	public function test_get_variable_value_resolves_site_level_fields_without_a_post(): void {
		$this->assertSame( get_bloginfo( 'name' ), erankly_get_variable_value( 'site_name' ) );
		$this->assertSame( get_bloginfo( 'description' ), erankly_get_variable_value( 'site_description' ) );
		$this->assertSame( home_url( '/' ), erankly_get_variable_value( 'site_url' ) );
		$this->assertNotSame( '', erankly_get_variable_value( 'current_year' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_variable_value_falls_back_to_the_content_when_there_is_no_excerpt(): void {
		$post_id = $this->make_post( array( 'post_content' => 'Solo contenuto, nessun riassunto' ) );

		$this->assertSame( 'Solo contenuto, nessun riassunto', erankly_get_variable_value( 'post_excerpt', $post_id ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_variable_value_strips_shortcodes_and_tags_from_content(): void {
		$post_id = $this->make_post( array( 'post_content' => '<p>Testo</p>[gallery ids="1"]' ) );

		$value = erankly_get_variable_value( 'post_content', $post_id );

		$this->assertStringContainsString( 'Testo', $value );
		$this->assertStringNotContainsString( '<p>', $value );
		$this->assertStringNotContainsString( '[gallery', $value );
	}

	public function test_get_variable_value_resolves_page_and_pagination_defaults(): void {
		$this->assertSame( '1', erankly_get_variable_value( 'page_number' ) );
		$this->assertSame( '1', erankly_get_variable_value( 'max_pages' ) );
		$this->assertSame( '', erankly_get_variable_value( 'current_pagination' ) );
	}

	public function test_get_variable_value_returns_the_resolved_seo_title_and_description(): void {
		$post_id = $this->make_post( array( 'post_title' => 'Articolo per SEO' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( erankly_get_title(), erankly_get_variable_value( 'seo_title', $post_id ) );
		$this->assertSame( erankly_get_description(), erankly_get_variable_value( 'meta_description', $post_id ) );
	}

	public function test_get_post_category_names_joins_the_assigned_categories(): void {
		$post_id = $this->make_post();
		$first   = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Alfa',
			)
		);
		$second  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Beta',
			)
		);
		wp_set_post_terms( $post_id, array( $first, $second ), 'category' );

		$this->assertSame( 'Alfa, Beta', erankly_get_post_category_names( $post_id ) );
	}

	public function test_get_post_category_names_is_empty_without_a_post_or_categories(): void {
		$post_id = $this->make_post();
		wp_set_post_terms( $post_id, array(), 'category' );

		$this->assertSame( '', erankly_get_post_category_names( 0 ) );
		$this->assertSame( '', erankly_get_post_category_names( $post_id ) );
	}

	public function test_get_post_tag_names_joins_the_assigned_tags(): void {
		$post_id = $this->make_post();
		$tag_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Etichetta',
			)
		);
		wp_set_post_terms( $post_id, array( $tag_id ), 'post_tag' );

		$this->assertSame( 'Etichetta', erankly_get_post_tag_names( $post_id ) );
	}

	public function test_get_post_tag_names_is_empty_without_a_post_or_tags(): void {
		$post_id = $this->make_post();

		$this->assertSame( '', erankly_get_post_tag_names( 0 ) );
		$this->assertSame( '', erankly_get_post_tag_names( $post_id ) );
	}

	public function test_preview_value_resolves_post_fields(): void {
		$post = get_post( $this->make_post( array( 'post_title' => 'Titolo anteprima' ) ) );
		$this->assertInstanceOf( WP_Post::class, $post );

		$this->assertSame( 'Titolo anteprima', erankly_get_variable_preview_value( 'post_title', $post ) );
		$this->assertNotSame( '', erankly_get_variable_preview_value( 'post_url', $post ) );
	}

	public function test_preview_value_returns_empty_for_an_unknown_key(): void {
		$post = get_post( $this->make_post() );
		$this->assertInstanceOf( WP_Post::class, $post );

		$this->assertSame( '', erankly_get_variable_preview_value( 'chiave_inesistente', $post ) );
	}

	public function test_preview_value_uses_stable_pagination_examples_without_a_query(): void {
		$this->assertSame( '1', erankly_get_variable_preview_value( 'page_number' ) );
		$this->assertSame( '1', erankly_get_variable_preview_value( 'max_pages' ) );
		$this->assertSame( '2', erankly_get_variable_preview_value( 'current_pagination' ) );
		$this->assertNotSame( '', erankly_get_variable_preview_value( 'pagination' ) );
	}

	public function test_preview_value_resolves_term_fields(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Categoria anteprima',
				'slug'        => 'categoria-anteprima',
				'description' => 'Descrizione categoria',
			)
		);
		$term    = get_term( $term_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->assertSame( 'Categoria anteprima', erankly_get_variable_preview_value( 'term_name', null, $term ) );
		$this->assertSame( 'Descrizione categoria', erankly_get_variable_preview_value( 'term_description', null, $term ) );
		$this->assertSame( 'categoria-anteprima', erankly_get_variable_preview_value( 'term_slug', null, $term ) );
		$this->assertNotSame( '', erankly_get_variable_preview_value( 'taxonomy_name', null, $term ) );
	}

	public function test_preview_value_resolves_site_level_fields_without_any_object(): void {
		$this->assertSame( get_bloginfo( 'name' ), erankly_get_variable_preview_value( 'site_name' ) );
		$this->assertSame( home_url( '/' ), erankly_get_variable_preview_value( 'site_url' ) );
		$this->assertNotSame( '', erankly_get_variable_preview_value( 'current_year' ) );
	}

	public function test_admin_variable_examples_skip_keys_without_a_value(): void {
		$examples = erankly_get_admin_variable_examples();

		$this->assertIsArray( $examples );
		$this->assertArrayHasKey( 'site_name', $examples );
		$this->assertArrayHasKey( 'current_year', $examples );

		foreach ( $examples as $key => $value ) {
			$this->assertIsString( $key );
			$this->assertNotSame( '', $value, "Example for $key should not be empty." );
		}
	}

	public function test_admin_variable_examples_include_post_values_when_given_a_post(): void {
		$post = get_post( $this->make_post( array( 'post_title' => 'Titolo esempio' ) ) );
		$this->assertInstanceOf( WP_Post::class, $post );

		$examples = erankly_get_admin_variable_examples( $post );

		$this->assertSame( 'Titolo esempio', $examples['post_title'] );
		$this->assertArrayHasKey( 'post_url', $examples );
	}

	public function test_sample_post_for_type_returns_the_latest_published_post(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Vecchio',
				'post_date'   => '2020-01-01 10:00:00',
			)
		);
		$recent = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Recente',
				'post_date'   => '2026-01-01 10:00:00',
			)
		);

		$sample = erankly_get_sample_post_for_type( 'post' );

		$this->assertInstanceOf( WP_Post::class, $sample );
		$this->assertSame( (int) $recent, $sample->ID );
	}

	public function test_sample_post_for_type_ignores_drafts_and_unknown_types(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Bozza',
			)
		);

		$this->assertNull( erankly_get_sample_post_for_type( 'tipo_inesistente' ) );
	}

	public function test_sample_term_for_taxonomy_returns_a_term(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Categoria campione',
			)
		);

		$sample = erankly_get_sample_term_for_taxonomy( 'category' );

		$this->assertInstanceOf( WP_Term::class, $sample );
		$this->assertSame( (int) $term_id, (int) $sample->term_id );
	}

	public function test_sample_term_for_taxonomy_returns_null_for_an_unknown_taxonomy(): void {
		$this->assertNull( erankly_get_sample_term_for_taxonomy( 'tassonomia_inesistente' ) );
	}
}
