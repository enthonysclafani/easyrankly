<?php
/** Schema.org JSON-LD: graph assembly, node-id pruning, merge helpers, identity builders and admin surfaces. */

final class ERankly_Schema_Jsonld_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
		require_once ERANKLY_PATH . 'includes/schema-jsonld.php';
		// schema.php loads schema-content.php and breadcrumbs.php.
		require_once ERANKLY_PATH . 'includes/schema.php';

		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}
	}

	/** @param array<string,mixed> $args Post args overrides. */
	private function make_post( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Contenuto',
					'post_content' => '',
					'post_excerpt' => '',
				),
				$args
			)
		);

		$this->assertIsInt( $post_id );

		return (int) $post_id;
	}

	/** @param array<string,mixed> $settings Partial settings map. */
	private function store_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	private function seed_merge_conflict(): void {
		erankly_clear_schema_merge_warnings();
		erankly_merge_schema_nodes(
			array(
				'@id'  => '#org',
				'name' => array( 'alternate' => 'Auto' ),
			),
			array(
				'@id'  => '#org',
				'name' => 'Custom',
			)
		);
	}

	// ------------------------------------------------------------------
	// schema-jsonld.php
	// ------------------------------------------------------------------

	public function test_merge_schema_arrays_concatenates_and_deduplicates(): void {
		$this->assertSame( array( 'a', 'b', 'c' ), erankly_merge_schema_arrays( array( 'a', 'b' ), array( 'b', 'c' ) ) );

		$nodes = erankly_merge_schema_arrays(
			array( array( '@type' => 'Thing', 'name' => 'A' ) ),
			array( array( '@type' => 'Thing', 'name' => 'A' ), array( '@type' => 'Thing', 'name' => 'B' ) )
		);

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'B', $nodes[1]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_maybe_log_schema_merge_warnings_logs_once_when_debug(): void {
		$previous = ini_get( 'error_log' );
		$file     = tempnam( sys_get_temp_dir(), 'erankly_schema_log_' );
		$this->assertIsString( $file );
		ini_set( 'error_log', $file );

		$this->seed_merge_conflict();

		try {
			erankly_maybe_log_schema_merge_warnings();
			$logged = (string) file_get_contents( $file );

			$this->assertStringContainsString( 'EasyRankly:', $logged );
			$this->assertStringContainsString( 'name', $logged );

			// The static guard means a second call does not write again.
			erankly_maybe_log_schema_merge_warnings();
			$this->assertSame( strlen( $logged ), strlen( (string) file_get_contents( $file ) ) );
		} finally {
			ini_set( 'error_log', (string) $previous );
			@unlink( $file );
		}
	}

	public function test_render_schema_merge_warning_comment_requires_capability(): void {
		$this->seed_merge_conflict();

		ob_start();
		erankly_render_schema_merge_warning_comment();
		$anonymous = ob_get_clean();
		$this->assertSame( '', $anonymous );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		try {
			ob_start();
			erankly_render_schema_merge_warning_comment();
			$comment = ob_get_clean();

			$this->assertStringContainsString( 'EasyRankly schema merge:', $comment );
			$this->assertStringContainsString( 'name', $comment );
		} finally {
			wp_set_current_user( 0 );
			erankly_clear_schema_merge_warnings();
		}
	}

	public function test_admin_bar_schema_merge_warnings_adds_a_node_for_editors(): void {
		erankly_clear_schema_merge_warnings();

		$empty = new WP_Admin_Bar();
		$empty->initialize();
		erankly_admin_bar_schema_merge_warnings( $empty );
		$this->assertNull( $empty->get_node( 'erankly-schema-merge' ) );

		$this->seed_merge_conflict();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		try {
			$bar = new WP_Admin_Bar();
			$bar->initialize();
			erankly_admin_bar_schema_merge_warnings( $bar );

			$this->assertNotNull( $bar->get_node( 'erankly-schema-merge' ) );
			$this->assertNotNull( $bar->get_node( 'erankly-schema-merge-0' ) );
		} finally {
			wp_set_current_user( 0 );
			erankly_clear_schema_merge_warnings();
		}
	}

	public function test_get_schema_type_suggestions_is_filterable(): void {
		$types = erankly_get_schema_type_suggestions();

		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'FAQPage', $types );

		$filter = static function ( array $list ): array {
			$list[] = 'CustomThing';
			return $list;
		};
		add_filter( 'erankly_schema_type_suggestions', $filter );

		try {
			$this->assertContains( 'CustomThing', erankly_get_schema_type_suggestions() );
		} finally {
			remove_filter( 'erankly_schema_type_suggestions', $filter );
		}
	}

	public function test_get_schema_type_suggestions_for_post_adds_configured_types(): void {
		$this->store_settings(
			array(
				'global_post_type_schema' => array(
					'post' => array(
						'webpage_type' => 'MyCorpPage',
						'article_type' => 'none',
					),
				),
			)
		);

		$post_id = $this->make_post();

		$types = erankly_get_schema_type_suggestions_for_post( $post_id );

		$this->assertContains( 'MyCorpPage', $types );
		$this->assertNotContains( 'none', $types );
		$this->assertSame( $types, array_values( array_unique( $types ) ) );

		// Without a post there is nothing post-type specific to append.
		$plain = erankly_get_schema_type_suggestions_for_post( 0 );
		$this->assertNotContains( 'MyCorpPage', $plain );
	}

	public function test_get_local_business_page_id_prefers_the_per_blog_map(): void {
		$this->store_settings(
			array(
				'local_business_pages' => array( get_current_blog_id() => 4242 ),
			)
		);

		$this->assertSame( 4242, erankly_get_local_business_page_id() );
	}

	public function test_get_local_business_page_id_falls_back_to_the_shared_path(): void {
		$page_id = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_name'  => 'contatti',
				'post_title' => 'Contatti',
			)
		);

		$this->store_settings(
			array(
				'local_business_pages'    => array(),
				'local_business_page_path' => '/contatti/',
			)
		);

		$this->assertSame( $page_id, erankly_get_local_business_page_id() );
	}

	public function test_get_local_business_page_id_returns_zero_when_unconfigured(): void {
		$this->store_settings(
			array(
				'local_business_pages'    => array(),
				'local_business_page_path' => '',
			)
		);

		$this->assertSame( 0, erankly_get_local_business_page_id() );
	}

	public function test_get_local_business_site_choices_lists_the_current_site_pages(): void {
		$page_id = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Servizi',
				'post_name'  => 'servizi',
			)
		);

		$choices = erankly_get_local_business_site_choices();

		$this->assertCount( 1, $choices );
		$this->assertSame( get_current_blog_id(), $choices[0]['blog_id'] );
		$this->assertSame( '/', $choices[0]['path'] );
		$this->assertSame( get_bloginfo( 'name' ), $choices[0]['name'] );

		$ids = wp_list_pluck( $choices[0]['pages'], 'id' );
		$this->assertContains( $page_id, $ids );
	}

	// ------------------------------------------------------------------
	// schema.php — pure graph helpers
	// ------------------------------------------------------------------

	public function test_filter_empty_schema_values_recurses(): void {
		$schema = erankly_filter_empty_schema_values(
			array(
				'@type'  => 'Thing',
				'name'   => '',
				'nested' => array(
					'empty' => '',
					'keep'  => 'x',
				),
				'list'   => array(),
				'nil'    => null,
				'zero'   => 0,
			)
		);

		$this->assertArrayNotHasKey( 'name', $schema );
		$this->assertArrayNotHasKey( 'list', $schema );
		$this->assertArrayNotHasKey( 'nil', $schema );
		$this->assertSame( array( 'keep' => 'x' ), $schema['nested'] );
		$this->assertSame( 0, $schema['zero'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_schema_block_field_reads_raw_and_resolved_values(): void {
		$block = array(
			'fields' => array(
				'custom_json' => '  {"@type":"Thing"}  ',
				'empty'       => '   ',
			),
		);

		$this->assertSame( '{"@type":"Thing"}', erankly_schema_block_field( $block, 'custom_json', 0, true ) );
		$this->assertSame( '', erankly_schema_block_field( $block, 'empty', 0, true ) );
		$this->assertSame( '', erankly_schema_block_field( $block, 'missing', 0 ) );

		$post_id  = $this->make_post( array( 'post_title' => 'Titolo' ) );
		$resolved = erankly_schema_block_field(
			array( 'fields' => array( 'label' => '<b>{{post_title}}</b>' ) ),
			'label',
			$post_id
		);

		$this->assertSame( 'Titolo', $resolved );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_configured_custom_schemas_expand_variables_and_drop_empty_values(): void {
		$post_id = $this->make_post( array( 'post_title' => 'Titolo dinamico' ) );
		$block   = array(
			'type'   => 'custom',
			'fields' => array(
				'custom_json' => '{"@type":"Thing","name":"{{post_title}}","empty":""}',
			),
		);

		$schemas = erankly_configured_custom_schemas( $block, $post_id );

		$this->assertCount( 1, $schemas );
		$this->assertSame( 'Thing', $schemas[0]['@type'] );
		$this->assertSame( 'Titolo dinamico', $schemas[0]['name'] );
		$this->assertArrayNotHasKey( 'empty', $schemas[0] );
	}

	public function test_schema_from_configured_block_only_builds_custom_types_and_is_filterable(): void {
		$this->assertSame( array(), erankly_schema_from_configured_block( array( 'type' => 'other' ), 0 ) );

		$post_id = $this->make_post();
		$block   = array(
			'type'   => 'custom',
			'fields' => array( 'custom_json' => '{"@type":"Thing","name":"Base"}' ),
		);

		$schemas = erankly_schema_from_configured_block( $block, $post_id );
		$this->assertCount( 1, $schemas );
		$this->assertSame( 'Base', $schemas[0]['name'] );

		$filter = static function (): array {
			return array(
				array(
					'@type' => 'Thing',
					'name'  => 'Injected',
				),
			);
		};
		add_filter( 'erankly_schema_from_configured_block', $filter );

		try {
			$injected = erankly_schema_from_configured_block( $block, $post_id );
			$this->assertSame( 'Injected', $injected[0]['name'] );
		} finally {
			remove_filter( 'erankly_schema_from_configured_block', $filter );
		}
	}

	public function test_post_custom_schema_graph_builds_from_stored_blocks(): void {
		$post_id = $this->make_post();
		update_post_meta(
			$post_id,
			'_erankly_schema_blocks',
			array(
				array(
					'type'   => 'custom',
					'fields' => array( 'custom_json' => '{"@type":"Thing","name":"Custom node"}' ),
				),
				array( 'type' => 'bogus' ),
			)
		);

		$graph = erankly_post_custom_schema_graph( $post_id );

		$this->assertCount( 1, $graph );
		$this->assertSame( 'Thing', $graph[0]['@type'] );
		$this->assertSame( 'Custom node', $graph[0]['name'] );
	}

	public function test_collect_schema_node_ids_only_counts_defining_nodes(): void {
		$graph = array(
			array(
				'@id'  => '#a',
				'name' => 'A',
			),
			array( '@id' => '#ref' ),
			array(
				'@type'     => 'WebPage',
				'publisher' => array(
					'@id'   => '#b',
					'@type' => 'Organization',
				),
			),
		);

		$ids = erankly_collect_schema_node_ids( $graph );

		$this->assertArrayHasKey( '#a', $ids );
		$this->assertArrayHasKey( '#b', $ids );
		$this->assertArrayNotHasKey( '#ref', $ids );
	}

	public function test_is_dangling_schema_reference_matches_pure_id_references(): void {
		$this->assertTrue( erankly_is_dangling_schema_reference( array( '@id' => '#x' ), array() ) );
		$this->assertFalse( erankly_is_dangling_schema_reference( array( '@id' => '#x' ), array( '#x' => true ) ) );
		$this->assertFalse(
			erankly_is_dangling_schema_reference(
				array(
					'@id'   => '#x',
					'@type' => 'Thing',
				),
				array()
			)
		);
	}

	public function test_prune_dangling_schema_references_removes_unknown_ids(): void {
		$graph = array(
			array(
				'@type'     => 'Article',
				'author'    => array( '@id' => '#missing' ),
				'publisher' => array(
					'@id'   => '#org',
					'@type' => 'Organization',
				),
			),
		);

		$pruned = erankly_prune_dangling_schema_references( $graph, array( '#org' => true ) );

		$this->assertArrayNotHasKey( 'author', $pruned[0] );
		$this->assertSame( '#org', $pruned[0]['publisher']['@id'] );
	}

	public function test_prune_dangling_schema_references_in_node_drops_now_empty_holders(): void {
		$node = array(
			'@type' => 'Thing',
			'a'     => array( '@id' => '#missing' ),
			'b'     => array( 'nested' => array( '@id' => '#missing' ) ),
		);

		$out = erankly_prune_dangling_schema_references_in_node( $node, array() );

		$this->assertArrayNotHasKey( 'a', $out );
		$this->assertArrayNotHasKey( 'b', $out );
		$this->assertSame( 'Thing', $out['@type'] );
	}

	public function test_filter_schema_graph_types_drops_disabled_nodes_case_insensitively(): void {
		$graph = array(
			array( '@type' => 'FAQPage' ),
			array( '@type' => array( 'Article', 'BlogPosting' ) ),
			array( '@type' => 'WebPage' ),
		);

		$this->assertSame( $graph, erankly_filter_schema_graph_types( $graph, array() ) );

		$filtered = erankly_filter_schema_graph_types( $graph, array( 'faqpage', 'BlogPosting' ) );

		$this->assertCount( 1, $filtered );
		$this->assertSame( 'WebPage', $filtered[0]['@type'] );
	}

	// ------------------------------------------------------------------
	// schema.php — identity + builders
	// ------------------------------------------------------------------

	public function test_schema_foundational_graph_builds_organization_and_website(): void {
		$this->store_settings( array( 'schema_identity' => 'organization' ) );

		$graph = erankly_schema_foundational_graph();

		$this->assertCount( 2, $graph );
		$this->assertSame( 'Organization', $graph[0]['@type'] );
		$this->assertSame( 'WebSite', $graph[1]['@type'] );
		$this->assertSame( 'organization', erankly_get_schema_identity() );
		$this->assertSame( home_url( '/#organization' ), erankly_schema_identity_id() );
	}

	public function test_schema_identity_person_requires_an_actual_user(): void {
		$this->store_settings(
			array(
				'schema_identity'        => 'person',
				'schema_person_user_id'  => 0,
			)
		);
		$this->assertSame( 'organization', erankly_get_schema_identity() );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->store_settings(
			array(
				'schema_identity'       => 'person',
				'schema_person_user_id' => $user_id,
			)
		);

		$this->assertSame( 'person', erankly_get_schema_identity() );
		$this->assertSame( home_url( '/#person' ), erankly_schema_identity_id() );

		$graph = erankly_schema_foundational_graph();
		$this->assertSame( 'Person', $graph[0]['@type'] );
	}

	public function test_schema_organization_includes_details_address_and_logo(): void {
		$this->store_settings(
			array(
				'organization_name'            => 'ACME Srl',
				'organization_description'     => 'Azienda di prova',
				'organization_email'           => 'info@example.org',
				'organization_phone'           => '+39 02 1234567',
				'organization_legal_name'      => 'ACME Limited',
				'organization_vat_id'          => 'IT123',
				'organization_tax_id'          => 'TAX9',
				'organization_street_address'  => 'Via Roma 1',
				'organization_locality'        => 'Milano',
				'organization_region'          => 'MI',
				'organization_postal_code'     => '20100',
				'organization_country'         => 'it',
				'organization_logo_url'        => 'https://example.org/logo.png',
			)
		);

		$details = erankly_get_organization_schema_details();
		$this->assertSame( 'Azienda di prova', $details['description'] );
		$this->assertSame( 'info@example.org', $details['email'] );
		$this->assertSame( 'ACME Limited', $details['legalName'] );
		$this->assertSame( 'IT123', $details['vatID'] );
		$this->assertArrayNotHasKey( 'unused', $details );

		$address = erankly_schema_organization_address();
		$this->assertSame( 'PostalAddress', $address['@type'] );
		$this->assertSame( 'Via Roma 1', $address['streetAddress'] );
		$this->assertSame( 'IT', $address['addressCountry'] );

		$org = erankly_schema_organization();
		$this->assertSame( 'Organization', $org['@type'] );
		$this->assertSame( 'ACME Srl', $org['name'] );
		$this->assertSame( 'PostalAddress', $org['address']['@type'] );
		$this->assertSame( 'https://example.org/logo.png', $org['logo']['url'] );
		$this->assertSame( $org['logo']['@id'], $org['image']['@id'] );
	}

	public function test_schema_organization_address_require_complete_needs_every_field(): void {
		$this->store_settings( array( 'organization_street_address' => 'Via Roma 1' ) );

		// Country (and the other required fields) are missing, so a LocalBusiness-ready
		// address is refused outright...
		$this->assertSame( array(), erankly_schema_organization_address( true ) );
		// ...while the loose read still returns what exists.
		$this->assertSame( 'Via Roma 1', erankly_schema_organization_address()['streetAddress'] );
	}

	public function test_schema_person_uses_the_configured_user(): void {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Mario Rossi',
				'role'         => 'author',
			)
		);
		$this->store_settings(
			array(
				'schema_identity'       => 'person',
				'schema_person_user_id' => $user_id,
			)
		);

		$person = erankly_schema_person();

		$this->assertSame( 'Person', $person['@type'] );
		$this->assertSame( 'Mario Rossi', $person['name'] );
		$this->assertSame( home_url( '/#person' ), $person['@id'] );
		$this->assertStringContainsString( 'author', $person['url'] );
	}

	public function test_schema_website_and_search_action(): void {
		$this->store_settings(
			array(
				'website_name'                 => 'Il mio sito',
				'website_description'          => 'Descrizione del sito',
				'enable_website_search_action' => 1,
			)
		);

		$site = erankly_schema_website();

		$this->assertSame( 'WebSite', $site['@type'] );
		$this->assertSame( 'Il mio sito', $site['name'] );
		$this->assertSame( 'Descrizione del sito', $site['description'] );
		$this->assertSame( 'SearchAction', $site['potentialAction']['@type'] );

		$action = erankly_schema_website_search_action();
		$this->assertSame( 'required name=search_term_string', $action['query-input'] );
		$this->assertStringContainsString( '{search_term_string}', $action['target']['urlTemplate'] );
	}

	public function test_schema_webpage_builds_a_page_node_and_honours_configured_type(): void {
		$post_id = $this->make_post();
		$this->go_to( home_url( '/' ) );

		$page = erankly_schema_webpage();

		$this->assertSame( 'WebPage', $page['@type'] );
		$this->assertSame( home_url( '/#website' ), $page['isPartOf']['@id'] );
		$this->assertStringContainsString( '#webpage', $page['@id'] );
		$this->assertArrayNotHasKey( 'breadcrumb', $page );

		$linked = erankly_schema_webpage( 0, 'https://example.org/#breadcrumb' );
		$this->assertSame( 'https://example.org/#breadcrumb', $linked['breadcrumb']['@id'] );

		$this->store_settings(
			array(
				'global_post_type_schema' => array(
					'post' => array(
						'webpage_type' => 'ProfilePage',
						'article_type' => 'none',
					),
				),
			)
		);
		$profile = erankly_schema_webpage( $post_id );

		$this->assertSame( 'ProfilePage', $profile['@type'] );
		$this->assertSame( erankly_schema_identity_id(), $profile['mainEntity']['@id'] );

		$this->store_settings(
			array(
				'global_post_type_schema' => array(
					'post' => array(
						'webpage_type' => 'none',
						'article_type' => 'none',
					),
				),
			)
		);

		$this->assertSame( array(), erankly_schema_webpage( $post_id ) );
	}

	public function test_schema_article_author_and_blogposting(): void {
		$author_id = self::factory()->user->create(
			array(
				'display_name' => 'Anna',
				'role'         => 'author',
			)
		);
		$post_id = $this->make_post( array( 'post_author' => $author_id ) );
		$this->store_settings( array( 'schema_identity' => 'organization' ) );

		$author = erankly_schema_article_author( $author_id );
		$this->assertSame( 'Person', $author['@type'] );
		$this->assertSame( 'Anna', $author['name'] );
		$this->assertArrayNotHasKey( '@id', $author );

		$article = erankly_schema_article( $post_id );
		$this->assertSame( 'BlogPosting', $article['@type'] );
		$this->assertStringContainsString( '#article', $article['@id'] );
		$this->assertSame( erankly_schema_identity_id(), $article['publisher']['@id'] );
		$this->assertStringContainsString( '#webpage', $article['mainEntityOfPage']['@id'] );
		$this->assertSame( 'Anna', $article['author']['name'] );

		$blogposting = erankly_schema_blogposting( $post_id );
		$this->assertSame( 'BlogPosting', $blogposting['@type'] );
	}

	public function test_schema_article_author_links_the_identity_user(): void {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Io stesso',
				'role'         => 'author',
			)
		);
		$this->store_settings(
			array(
				'schema_identity'       => 'person',
				'schema_person_user_id' => $user_id,
			)
		);

		$author = erankly_schema_article_author( $user_id );

		$this->assertSame( home_url( '/#person' ), $author['@id'] );
	}

	public function test_schema_service_and_localbusiness_defaults(): void {
		$this->store_settings( array( 'organization_name' => 'Negozio' ) );

		$service = erankly_schema_service( array( 'name' => 'Riparazioni' ) );
		$this->assertSame( 'Service', $service['@type'] );
		$this->assertSame( 'Riparazioni', $service['name'] );
		$this->assertArrayHasKey( '@id', $service['provider'] );

		$local = erankly_schema_localbusiness();
		$this->assertSame( 'LocalBusiness', $local['@type'] );
		$this->assertSame( home_url( '/#localbusiness' ), $local['@id'] );
		$this->assertSame( 'Negozio', $local['name'] );
	}

	public function test_schema_local_business_for_page_emits_a_rich_node(): void {
		$page_id = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Dove trovarci',
			)
		);

		$this->store_settings(
			array(
				'enable_local_business'          => 1,
				'organization_name'              => 'Trattoria da Mario',
				'organization_street_address'    => 'Via Roma 1',
				'organization_locality'          => 'Milano',
				'organization_postal_code'       => '20100',
				'organization_country'           => 'IT',
				'organization_email'             => 'info@example.org',
				'organization_phone'             => '+39 021234567',
				'local_business_pages'           => array( get_current_blog_id() => $page_id ),
				'local_business_type'            => 'Restaurant',
				'local_business_price_range'     => '$$',
				'local_business_latitude'        => '45.4642',
				'local_business_longitude'       => '9.19',
				'local_business_menu_url'        => 'https://example.org/menu',
				'local_business_cuisine'         => 'Italiana, Pizza',
				'local_business_hours'           => array(
					'monday' => array(
						'closed'    => 0,
						'intervals' => array(
							array(
								'opens'  => '09:00',
								'closes' => '17:00',
							),
						),
					),
				),
			)
		);

		$schema = erankly_schema_local_business_for_page( $page_id );

		$this->assertSame( 'Restaurant', $schema['@type'] );
		$this->assertSame( '$$', $schema['priceRange'] );
		$this->assertSame( 45.4642, $schema['geo']['latitude'] );
		$this->assertSame( 'https://example.org/menu', $schema['menu'] );
		$this->assertSame( array( 'Italiana', 'Pizza' ), $schema['servesCuisine'] );
		$this->assertSame( 'PostalAddress', $schema['address']['@type'] );
		$this->assertSame( home_url( '/#organization' ), $schema['parentOrganization']['@id'] );
		$this->assertNotEmpty( $schema['openingHoursSpecification'] );
		$this->assertStringContainsString( '#localbusiness', $schema['@id'] );
	}

	public function test_schema_local_business_for_page_is_gated_by_setting_and_page_type(): void {
		$page_id = $this->make_post( array( 'post_type' => 'page' ) );

		$this->store_settings( array( 'enable_local_business' => 0 ) );
		$this->assertSame( array(), erankly_schema_local_business_for_page( $page_id ) );

		$this->store_settings(
			array(
				'enable_local_business'       => 1,
				'organization_name'           => 'X',
				'organization_street_address' => 'a',
				'organization_locality'       => 'b',
				'organization_postal_code'    => 'c',
				'organization_country'        => 'IT',
			)
		);

		$post_id = $this->make_post();
		$this->assertSame( array(), erankly_schema_local_business_for_page( $post_id ) );
	}

	public function test_schema_opening_hours_groups_days_and_skips_incomplete(): void {
		$hours = erankly_schema_opening_hours(
			array(
				'monday'    => array(
					'closed'    => 0,
					'intervals' => array(
						array(
							'opens'  => '09:00',
							'closes' => '17:00',
						),
					),
				),
				'tuesday'   => array(
					'closed'    => 0,
					'intervals' => array(
						array(
							'opens'  => '09:00',
							'closes' => '17:00',
						),
					),
				),
				'wednesday' => array(
					'closed'    => 0,
					'intervals' => array(
						array(
							'opens'  => '',
							'closes' => '',
						),
					),
				),
				'sunday'    => array(
					'closed'    => 1,
					'intervals' => array(
						array(
							'opens'  => '09:00',
							'closes' => '17:00',
						),
					),
				),
			)
		);

		$this->assertCount( 1, $hours );
		$this->assertSame( 'OpeningHoursSpecification', $hours[0]['@type'] );
		$this->assertSame( array( 'Monday', 'Tuesday' ), $hours[0]['dayOfWeek'] );
		$this->assertSame( '09:00', $hours[0]['opens'] );
		$this->assertSame( '17:00', $hours[0]['closes'] );
	}

	// ------------------------------------------------------------------
	// schema.php — graph assembly
	// ------------------------------------------------------------------

	public function test_automatic_schema_graph_contains_foundational_nodes_on_archives(): void {
		$this->go_to( home_url( '/' ) );

		$graph = erankly_automatic_schema_graph( 0 );
		$types = wp_list_pluck( $graph, '@type' );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'WebPage', $types );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_automatic_schema_graph_is_empty_on_a_404(): void {
		$this->go_to( '/?p=99999999' );

		$this->assertTrue( is_404() );
		$this->assertSame( array(), erankly_automatic_schema_graph( 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_schema_graph_respects_the_disabled_schema_mode(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, '_erankly_schema_mode', 'disabled' );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( array(), erankly_get_schema_graph() );
	}

	public function test_get_schema_graph_applies_the_schema_filter(): void {
		$node   = array(
			'@type' => 'Thing',
			'@id'   => 'https://example.org/#thing',
			'name'  => 'Filtered',
		);
		$filter = static function () use ( $node ): array {
			return array( $node );
		};

		add_filter( 'erankly_schema', $filter );

		try {
			$graph = erankly_get_schema_graph();
		} finally {
			remove_filter( 'erankly_schema', $filter );
		}

		$this->assertCount( 1, $graph );
		$this->assertSame( 'Thing', $graph[0]['@type'] );
		$this->assertSame( 'Filtered', $graph[0]['name'] );
	}

	public function test_render_schema_emits_a_json_ld_script(): void {
		$filter = static function (): array {
			return array(
				array(
					'@type' => 'Thing',
					'@id'   => 'https://example.org/#thing',
					'name'  => 'Rendered',
				),
			);
		};
		add_filter( 'erankly_schema', $filter );

		try {
			ob_start();
			erankly_render_schema();
			$output = ob_get_clean();
		} finally {
			remove_filter( 'erankly_schema', $filter );
		}

		$this->assertStringContainsString( '<script type="application/ld+json">', $output );

		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $matches );
		$this->assertNotEmpty( $matches );

		$decoded = json_decode( $matches[1], true );
		$this->assertSame( 'https://schema.org', $decoded['@context'] );
		$this->assertSame( 'Thing', $decoded['@graph'][0]['@type'] );
		$this->assertSame( 'Rendered', $decoded['@graph'][0]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_global_schema_graph_emits_only_matching_blocks(): void {
		$post_id = $this->make_post( array( 'post_title' => 'Servizi' ) );
		$block   = array(
			'type'            => 'custom',
			'enabled'         => 1,
			'target_contexts' => array( 'singular' ),
			'fields'          => array( 'custom_json' => '{"@type":"Service","name":"Global"}' ),
		);

		$this->store_settings( array( 'global_schema_blocks' => array( $block ) ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( erankly_global_schema_block_matches_request( $block ) );
		$this->assertFalse( erankly_global_schema_block_matches_request( array_merge( $block, array( 'enabled' => 0 ) ) ) );

		$graph = erankly_get_global_schema_graph();

		$this->assertCount( 1, $graph );
		$this->assertSame( 'Service', $graph[0]['@type'] );
		$this->assertSame( 'Global', $graph[0]['name'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_global_schema_matches_post_type_archive(): void {
		register_post_type(
			'book',
			array(
				'public'      => true,
				'has_archive' => true,
				'label'       => 'Books',
			)
		);
		$this->make_post( array( 'post_type' => 'book' ) );

		$this->go_to( get_post_type_archive_link( 'book' ) );

		$this->assertTrue( erankly_global_schema_matches_post_type_archive( array( 'target_post_types' => array( 'book' ) ) ) );
		$this->assertFalse( erankly_global_schema_matches_post_type_archive( array( 'target_post_types' => array( 'movie' ) ) ) );
		$this->assertFalse( erankly_global_schema_matches_post_type_archive( array( 'target_post_types' => array() ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_global_schema_matches_singular_and_target_lists(): void {
		$post_id = $this->make_post(
			array(
				'post_name'  => 'servizi-post',
				'post_title' => 'Servizi',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( erankly_global_schema_matches_singular( array( 'target_post_types' => array( 'post' ) ) ) );
		$this->assertFalse( erankly_global_schema_matches_singular( array( 'target_post_types' => array( 'page' ) ) ) );
		$this->assertTrue( erankly_global_schema_matches_singular( array( 'include_items' => (string) $post_id ) ) );
		$this->assertFalse( erankly_global_schema_matches_singular( array( 'exclude_items' => (string) $post_id ) ) );

		$this->assertTrue( erankly_schema_target_list_contains_post( (string) $post_id, $post_id ) );
		$this->assertTrue( erankly_schema_target_list_contains_post( 'servizi-post', $post_id ) );
		$this->assertFalse( erankly_schema_target_list_contains_post( '999999', $post_id ) );
	}
}
