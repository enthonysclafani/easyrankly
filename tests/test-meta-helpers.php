<?php
/** Metadata, special-page, helper and lifecycle-module behaviour. */

final class ERankly_Meta_Helpers_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		erankly_tests_load_settings_sanitizer();

		foreach ( array( 'includes/meta-render.php', 'includes/custom-code.php', 'includes/reset.php', 'includes/network-reset.php' ) as $file ) {
			require_once ERANKLY_PATH . $file;
		}
	}

	public function tear_down(): void {
		foreach ( array( 'erankly_reset_notice', 'page' ) as $key ) {
			unset( $_GET[ $key ] );
		}
		wp_set_current_user( 0 );
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	/**
	 * Clears a one-time migration flag from the storage the runtime reads.
	 *
	 * erankly_get_plugin_option()/erankly_update_plugin_option() store these
	 * flags as a NETWORK option on Multisite, so delete_option() alone would
	 * leave the flag in place there and the migration would bail.
	 */
	private function clear_migration_flag( string $option ): void {
		if ( is_multisite() ) {
			delete_site_option( $option );

			return;
		}

		delete_option( $option );
	}

	/* ----------------------------------------------------------------------
	 * includes/meta.php
	 * -------------------------------------------------------------------- */

	public function test_importable_meta_keys_drops_legacy_boolean_robots_keys(): void {
		$post = erankly_importable_meta_keys( 'post' );
		$this->assertArrayHasKey( '_erankly_title', $post );
		$this->assertArrayHasKey( '_erankly_schema_blocks', $post );
		$this->assertArrayNotHasKey( '_erankly_noindex', $post );
		$this->assertArrayNotHasKey( '_erankly_nofollow', $post );
		$this->assertArrayNotHasKey( '_erankly_noarchive', $post );
	}

	public function test_importable_meta_keys_scopes_the_term_subset(): void {
		$term = erankly_importable_meta_keys( 'term' );
		$this->assertArrayHasKey( '_erankly_title', $term );
		$this->assertArrayHasKey( '_erankly_disable_sitemap', $term );
		$this->assertArrayNotHasKey( '_erankly_schema_blocks', $term );
		$this->assertArrayNotHasKey( '_erankly_primary_terms', $term );

		// Users never get the taxonomy-only sitemap flag.
		$this->assertArrayNotHasKey( '_erankly_disable_sitemap', erankly_importable_meta_keys( 'user' ) );
	}

	public function test_skip_null_schema_blocks_meta_short_circuits_only_nulls(): void {
		$this->assertTrue( erankly_skip_null_schema_blocks_meta( false, 1, '_erankly_schema_blocks', null ) );
		$this->assertFalse( erankly_skip_null_schema_blocks_meta( false, 1, '_erankly_schema_blocks', array() ) );
		$this->assertFalse( erankly_skip_null_schema_blocks_meta( false, 1, '_erankly_title', null ) );
	}

	public function test_rest_stash_schema_blocks_previous_records_stored_blocks(): void {
		$post_id = self::factory()->post->create();
		$stored  = array( array( 'type' => 'custom', 'fields' => array( 'custom_json' => '{"@type":"Thing"}' ) ) );
		update_post_meta( $post_id, '_erankly_schema_blocks', $stored );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'id', $post_id );

		$prepared = erankly_rest_stash_schema_blocks_previous( new stdClass(), $request );

		$this->assertInstanceOf( stdClass::class, $prepared );
		$this->assertSame( $stored, $GLOBALS['erankly_schema_blocks_previous'] );
		unset( $GLOBALS['erankly_schema_blocks_previous'] );
	}

	public function test_migrate_legacy_social_image_meta_runs_a_single_batch(): void {
		delete_option( 'erankly_legacy_social_image_migrated' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_social_image_url', 'https://example.test/legacy.jpg' );

		erankly_migrate_legacy_social_image_meta();

		$this->assertSame( 'https://example.test/legacy.jpg', get_post_meta( $post_id, '_erankly_og_image_url', true ) );
		$this->assertSame( 'https://example.test/legacy.jpg', get_post_meta( $post_id, '_erankly_twitter_image_url', true ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_erankly_social_image_url' ) );
		$this->assertSame( 1, (int) get_option( 'erankly_legacy_social_image_migrated' ) );

		// A second legacy row added afterwards is left alone: the migration is one-shot.
		$second = self::factory()->post->create();
		update_post_meta( $second, '_erankly_social_image_url', 'https://example.test/second.jpg' );
		erankly_migrate_legacy_social_image_meta();

		$this->assertTrue( metadata_exists( 'post', $second, '_erankly_social_image_url' ) );
	}

	public function test_sanitize_primary_terms_keeps_only_positive_pairs(): void {
		$clean = erankly_sanitize_primary_terms(
			array(
				'category'  => '5',
				'post_tag'  => 0,
				''          => 7,
				'bad key'   => '3',
				'nonint'    => 'abc',
			)
		);

		$this->assertSame( 5, $clean['category'] );
		$this->assertSame( 3, $clean['badkey'] );
		$this->assertArrayNotHasKey( 'post_tag', $clean );
		$this->assertArrayNotHasKey( '', $clean );
		$this->assertArrayNotHasKey( 'nonint', $clean );

		$this->assertSame( array(), erankly_sanitize_primary_terms( 'not-an-array' ) );
	}

	public function test_invalid_json_ld_message_is_non_empty(): void {
		$message = erankly_invalid_json_ld_message();
		$this->assertIsString( $message );
		$this->assertStringContainsString( 'JSON-LD', $message );
	}

	public function test_is_valid_custom_json_ld_accepts_a_node_and_rejects_syntax(): void {
		$this->assertTrue( erankly_is_valid_custom_json_ld( '{"@type":"Article","headline":"Probe"}' ) );
		$this->assertTrue( erankly_is_valid_custom_json_ld( '{"@id":"https://example.test/#a"}' ) );
		$this->assertFalse( erankly_is_valid_custom_json_ld( '{not json}' ) );
		$this->assertFalse( erankly_is_valid_custom_json_ld( '[{"@type":"Article"}, 5]' ) );
	}

	public function test_normalize_custom_json_ld_data_handles_graph_list_and_object(): void {
		$graph = erankly_normalize_custom_json_ld_data(
			array( '@graph' => array( array( '@type' => 'Article', '@context' => 'https://schema.org' ) ) )
		);
		$this->assertCount( 1, $graph );
		$this->assertSame( 'Article', $graph[0]['@type'] );
		$this->assertArrayNotHasKey( '@context', $graph[0] );

		$list = erankly_normalize_custom_json_ld_data( array( array( '@type' => 'Thing' ), array( '@type' => 'Person' ) ) );
		$this->assertCount( 2, $list );

		$single = erankly_normalize_custom_json_ld_data( array( '@type' => 'Thing' ) );
		$this->assertCount( 1, $single );
		$this->assertSame( 'Thing', $single[0]['@type'] );
	}

	public function test_render_invalid_json_ld_notice_prints_and_clears_the_transient(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_transient( 'erankly_invalid_json_ld_' . $user_id, 'Broken JSON-LD probe', MINUTE_IN_SECONDS );

		ob_start();
		erankly_render_invalid_json_ld_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'erankly-invalid-json-ld-notice', $output );
		$this->assertStringContainsString( 'Broken JSON-LD probe', $output );
		$this->assertFalse( get_transient( 'erankly_invalid_json_ld_' . $user_id ) );

		ob_start();
		erankly_render_invalid_json_ld_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/* ----------------------------------------------------------------------
	 * includes/meta-render.php
	 * -------------------------------------------------------------------- */

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_head_emits_description_canonical_and_open_graph_once(): void {
		foreach ( array( 'includes/canonical.php', 'includes/opengraph.php', 'includes/schema.php' ) as $file ) {
			require_once ERANKLY_PATH . $file;
		}

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Head probe',
			)
		);
		update_post_meta( $post_id, '_erankly_description', 'Head description probe' );
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		erankly_render_head();
		$first = (string) ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="Head description probe">', $first );
		$this->assertStringContainsString( '<link rel="canonical"', $first );
		$this->assertStringContainsString( 'property="og:type" content="article"', $first );

		ob_start();
		erankly_render_head();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_redirect_attachment_is_inert_by_default(): void {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertIsInt( $attachment_id );

		erankly_update_plugin_settings( array( 'attachment_redirect' => 'none' ) );
		erankly_clear_settings_cache();

		$this->go_to( get_permalink( $attachment_id ) );
		$this->assertTrue( is_attachment() );

		// With the default "none" mode the redirector must not fire (which would exit).
		ob_start();
		erankly_redirect_attachment();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/* ----------------------------------------------------------------------
	 * includes/meta-visibility.php
	 * -------------------------------------------------------------------- */

	public function test_add_query_exclusion_meta_clause_builds_an_or_group(): void {
		$query = new WP_Query();
		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_search' );

		$clause = $query->get( 'meta_query' );
		$this->assertSame( 'OR', $clause['relation'] );
		$this->assertCount( 3, $clause );
		$this->assertSame( '_erankly_exclude_search', $clause[0]['key'] );
		$this->assertSame( 'NOT EXISTS', $clause[0]['compare'] );
		$this->assertSame( '1', $clause[1]['value'] );
		$this->assertSame( '!=', $clause[1]['compare'] );
	}

	public function test_add_query_exclusion_meta_clause_ands_an_existing_group(): void {
		$query = new WP_Query();
		$query->set( 'meta_query', array( array( 'key' => 'existing_key', 'value' => 'x' ) ) );

		erankly_add_query_exclusion_meta_clause( $query, '_erankly_exclude_archive' );

		$clause = $query->get( 'meta_query' );
		$this->assertSame( 'AND', $clause['relation'] );
		$this->assertSame( 'existing_key', $clause[0][0]['key'] );
		$this->assertSame( 'OR', $clause[1]['relation'] );
	}

	/* ----------------------------------------------------------------------
	 * includes/special-meta.php
	 * -------------------------------------------------------------------- */

	public function test_sanitize_special_meta_map_drops_unknown_contexts_and_fields(): void {
		$clean = erankly_sanitize_special_meta_map(
			array(
				'search'  => array( 'title' => 'Search SEO', 'noindex' => 1, 'bogus' => 'x' ),
				'unknown' => array( 'title' => 'Ignore me' ),
			)
		);

		$this->assertArrayHasKey( 'search', $clean );
		$this->assertArrayNotHasKey( 'unknown', $clean );
		$this->assertSame( 'Search SEO', $clean['search']['title'] );
		$this->assertTrue( (bool) $clean['search']['noindex'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['search'] );
	}

	public function test_update_special_meta_map_round_trips_through_the_rest_value(): void {
		$stored = erankly_update_special_meta_map(
			array(
				'search'   => array( 'title' => 'Search SEO', 'noindex' => true ),
				'homepage' => array( 'title' => 'Home SEO' ),
			)
		);
		$this->assertSame( 'Search SEO', $stored['search']['title'] );

		$value = erankly_get_special_meta_rest_value();

		// Every known context is present so the Site Editor can update one row.
		foreach ( array_keys( erankly_special_page_keys() ) as $context ) {
			$this->assertArrayHasKey( $context, $value );
		}

		$this->assertSame( 'Search SEO', $value['search']['title'] );
		$this->assertTrue( $value['search']['noindex'] );
		$this->assertSame( 'Home SEO', $value['homepage']['title'] );
		$this->assertFalse( $value['homepage']['noindex'] );
	}

	public function test_rest_special_meta_filters_only_intercept_their_own_option(): void {
		$this->assertNull( erankly_rest_pre_get_special_meta_setting( null, 'some_other_option', array() ) );
		$this->assertFalse( erankly_rest_pre_update_special_meta_setting( false, 'some_other_option', null, array() ) );

		// Pre-get returns the normalized map for the special-meta option.
		$value = erankly_rest_pre_get_special_meta_setting( null, ERANKLY_SPECIAL_META_OPTION, array() );
		$this->assertIsArray( $value );
		$this->assertArrayHasKey( 'search', $value );

		// Pre-update writes the map and claims the update.
		$handled = erankly_rest_pre_update_special_meta_setting(
			false,
			ERANKLY_SPECIAL_META_OPTION,
			array( 'search' => array( 'title' => 'Via REST' ) ),
			array()
		);
		$this->assertTrue( $handled );
		$this->assertSame( 'Via REST', erankly_get_special_meta_rest_value()['search']['title'] );
	}

	/* ----------------------------------------------------------------------
	 * includes/localized-value-writer.php
	 * -------------------------------------------------------------------- */

	public function test_get_localized_value_source_definition_resolves_registered_keys(): void {
		$home = erankly_get_localized_value_source_definition( 'home_seo_title' );
		$this->assertIsArray( $home );
		$this->assertSame( array( 'global_special_meta', 'homepage', 'title' ), $home['path'] );
		$this->assertSame( 'text', $home['format'] );

		$description = erankly_get_localized_value_source_definition( 'home_seo_description' );
		$this->assertSame( 'textarea', $description['format'] );

		// Dynamic post-type keys are accepted only for public post types.
		$dynamic = erankly_get_localized_value_source_definition( 'seo_title_post_post' );
		$this->assertIsArray( $dynamic );
		$this->assertSame( array( 'global_post_type_meta', 'post', 'title' ), $dynamic['path'] );
	}

	public function test_get_localized_value_source_definition_rejects_unknown_keys(): void {
		$error = erankly_get_localized_value_source_definition( 'definitely_not_registered' );

		$this->assertWPError( $error );
		$this->assertSame( 'erankly_localized_value_source_key_unregistered', $error->get_error_code() );
	}

	public function test_localized_value_source_path_helpers_read_and_write_nested(): void {
		$settings = array();
		erankly_localized_value_source_set_path( $settings, array( 'global_special_meta', 'homepage', 'title' ), 'Hola' );

		$this->assertSame( 'Hola', erankly_localized_value_source_get_path( $settings, array( 'global_special_meta', 'homepage', 'title' ) ) );
		$this->assertSame( '', erankly_localized_value_source_get_path( $settings, array( 'global_special_meta', '404', 'title' ) ) );
	}

	public function test_localized_value_source_error_marks_retryable_statuses(): void {
		$retryable = erankly_localized_value_source_error( 'erankly_probe', 'locked', 423 );
		$this->assertWPError( $retryable );
		$this->assertSame( 423, $retryable->get_error_data()['status'] );
		$this->assertTrue( $retryable->get_error_data()['retryable'] );

		$simple = erankly_localized_value_source_error( 'erankly_probe', 'bad', 422 );
		$this->assertFalse( $simple->get_error_data()['retryable'] );
	}

	public function test_localized_value_source_sanitize_candidate_accepts_and_rejects(): void {
		$definition = erankly_get_localized_value_source_definition( 'home_seo_title' );
		$this->assertIsArray( $definition );

		$this->assertSame( 'Clean title', erankly_localized_value_source_sanitize_candidate( $definition, 'Clean title' ) );

		$too_long = str_repeat( 'a', $definition['max_length'] + 1 );
		$this->assertWPError( erankly_localized_value_source_sanitize_candidate( $definition, $too_long ) );
		$this->assertWPError( erankly_localized_value_source_sanitize_candidate( $definition, array( 'not a string' ) ) );
	}

	public function test_localized_value_source_authorized_forbids_anonymous_requests(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( is_admin() );

		$result = erankly_localized_value_source_authorized();

		$this->assertWPError( $result );

		if ( is_multisite() ) {
			// The writer is Single Site only, so the topology guard short-circuits
			// before the capability check on Multisite.
			$this->assertSame( 'erankly_localized_value_source_topology_unsupported', $result->get_error_code() );

			return;
		}

		$this->assertSame( 'erankly_localized_value_source_forbidden', $result->get_error_code() );
	}

	public function test_update_localized_value_source_writes_with_compare_and_swap(): void {
		if ( is_multisite() ) {
			// The localized value source writer (and its CAS contract) is Single
			// Site only: every entry point fails closed with
			// erankly_localized_value_source_topology_unsupported on Multisite.
			$this->markTestSkipped( 'The localized EasyRankly source writer is Single Site only.' );
		}

		set_current_screen( 'dashboard' );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		try {
			$this->assertTrue( erankly_localized_value_source_authorized() );

			$state = erankly_get_localized_value_source_state( 'home_seo_title' );
			$this->assertIsArray( $state );
			$this->assertSame( 'erankly-localized-source/1', $state['contract'] );

			$result = erankly_update_localized_value_source( 'home_seo_title', 'Fresh home title', $state['fingerprint'] );
			$this->assertIsArray( $result );
			$this->assertTrue( $result['changed'] );
			$this->assertFalse( $result['idempotent'] );
			$this->assertSame( 'Fresh home title', $result['value'] );

			// Repeating the same desired value is idempotent even with a stale fingerprint.
			$repeat = erankly_update_localized_value_source( 'home_seo_title', 'Fresh home title', $state['fingerprint'] );
			$this->assertTrue( $repeat['idempotent'] );
			$this->assertFalse( $repeat['changed'] );

			// A different value with a stale fingerprint is a revision conflict.
			$conflict = erankly_update_localized_value_source( 'home_seo_title', 'Another title', $state['fingerprint'] );
			$this->assertWPError( $conflict );
			$this->assertSame( 'erankly_localized_value_source_revision_conflict', $conflict->get_error_code() );

			// A malformed fingerprint is rejected up front.
			$invalid = erankly_update_localized_value_source( 'home_seo_title', 'X', 'not-a-fingerprint' );
			$this->assertWPError( $invalid );
			$this->assertSame( 'erankly_localized_value_source_fingerprint_invalid', $invalid->get_error_code() );
		} finally {
			wp_set_current_user( 0 );
			unset( $GLOBALS['current_screen'] );
		}
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/global-meta.php
	 * -------------------------------------------------------------------- */

	public function test_get_post_and_term_meta_bool_reads_stored_truthiness(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_noindex', '1' );
		$this->assertTrue( erankly_get_post_meta_bool( $post_id, 'noindex' ) );
		update_post_meta( $post_id, '_erankly_noindex', '0' );
		$this->assertFalse( erankly_get_post_meta_bool( $post_id, 'noindex' ) );
		$this->assertFalse( erankly_get_post_meta_bool( $post_id, 'missing' ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, '_erankly_nofollow', '1' );
		$this->assertTrue( erankly_get_term_meta_bool( $term_id, 'nofollow' ) );
		$this->assertFalse( erankly_get_term_meta_bool( $term_id, 'noindex' ) );
	}

	public function test_get_primary_term_requires_the_term_be_assigned(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_post_meta( $post_id, '_erankly_primary_terms', array( 'category' => $term_id ) );

		// Not assigned yet.
		$this->assertNull( erankly_get_primary_term( $post_id, 'category' ) );

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );
		$term = erankly_get_primary_term( $post_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $term_id, $term->term_id );

		// A taxonomy that was never given a primary term resolves to null.
		$this->assertNull( erankly_get_primary_term( $post_id, 'post_tag' ) );
	}

	public function test_get_global_entity_meta_reads_rows_and_linked_fallback(): void {
		$filter = static function ( array $map, string $key ): array {
			if ( 'global_post_type_meta' === $key ) {
				$map['post'] = array( 'title' => 'Global post title', 'noindex' => true );
			}

			return $map;
		};
		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$this->assertSame( 'Global post title', erankly_get_global_post_type_meta( 'post', 'title' ) );
			$this->assertSame( 'Global post title', erankly_get_global_entity_meta( 'global_post_type_meta', 'post', 'title' ) );

			$row = erankly_get_global_entity_meta_row( 'global_post_type_meta', 'post' );
			$this->assertSame( 'Global post title', $row['title'] );

			// An unknown entity falls back to the first stored row while "linked" is on (default).
			$this->assertSame( 'Global post title', erankly_get_global_entity_meta( 'global_post_type_meta', 'zzz_unknown', 'title' ) );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_get_image_block_attachment_ids_walks_nested_blocks(): void {
		$blocks = array(
			array(
				'blockName' => 'core/image',
				'attrs'     => array( 'id' => 10 ),
			),
			array(
				'blockName' => 'core/gallery',
				'attrs'     => array( 'ids' => array( 11, 12 ) ),
			),
			array(
				'blockName' => 'core/paragraph',
				'attrs'     => array( 'id' => 13 ),
			),
			array(
				'blockName'   => 'core/cover',
				'attrs'       => array( 'mediaId' => 14 ),
				'innerBlocks' => array(
					array(
						'blockName' => 'core/image',
						'attrs'     => array( 'id' => 15 ),
					),
				),
			),
		);

		$this->assertSame( array( 10, 11, 12, 14, 15 ), erankly_get_image_block_attachment_ids( $blocks ) );
	}

	public function test_get_global_taxonomy_meta_reads_the_row(): void {
		$filter = static function ( array $map, string $key ): array {
			if ( 'global_taxonomy_meta' === $key ) {
				$map['category'] = array( 'title' => 'Category SEO title' );
			}

			return $map;
		};
		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$this->assertSame( 'Category SEO title', erankly_get_global_taxonomy_meta( 'category', 'title' ) );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_get_global_taxonomy_directive_reads_the_row(): void {
		$filter = static function ( array $map, string $key ): array {
			if ( 'global_taxonomy_meta' === $key ) {
				$map['category'] = array( 'noindex' => true );
			}

			return $map;
		};
		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$this->assertTrue( erankly_get_global_taxonomy_directive( 'category', 'noindex' ) );
			$this->assertFalse( erankly_get_global_taxonomy_directive( 'category', 'nofollow' ) );
			// Unknown field names are rejected outright.
			$this->assertFalse( erankly_get_global_taxonomy_directive( 'category', 'not_a_directive' ) );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_get_post_type_schema_type_reads_stored_then_defaults(): void {
		$filter = static function ( array $map, string $key ): array {
			if ( 'global_post_type_schema' === $key ) {
				$map['post'] = array( 'webpage_type' => 'ItemPage', 'article_type' => 'NewsArticle' );
			}

			return $map;
		};
		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$this->assertSame( 'ItemPage', erankly_get_post_type_schema_type( 'post', 'webpage_type' ) );
			$this->assertSame( 'NewsArticle', erankly_get_post_type_schema_type( 'post', 'article_type' ) );

			// A type with no stored row uses its default rather than borrowing another type's.
			$this->assertSame( 'WebPage', erankly_get_post_type_schema_type( 'page', 'webpage_type' ) );
			$this->assertSame( 'none', erankly_get_post_type_schema_type( 'page', 'article_type' ) );

			// Only the two known fields are accepted.
			$this->assertSame( '', erankly_get_post_type_schema_type( 'post', 'bogus_field' ) );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_get_site_special_meta_falls_back_to_defaults(): void {
		delete_option( ERANKLY_SPECIAL_META_OPTION );

		$meta = erankly_get_site_special_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'search', $meta );
		$this->assertArrayHasKey( '404', $meta );
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/utils.php
	 * -------------------------------------------------------------------- */

	public function test_get_taxonomy_labels_use_the_owner_post_type(): void {
		$taxonomy = get_taxonomy( 'category' );
		$this->assertInstanceOf( WP_Taxonomy::class, $taxonomy );

		$owner = erankly_get_taxonomy_owner_label( $taxonomy );
		$this->assertSame( get_post_type_object( 'post' )->labels->name, $owner );

		$admin_label = erankly_get_taxonomy_admin_label( $taxonomy );
		$this->assertSame( $owner . ': ' . $taxonomy->labels->singular_name, $admin_label );
	}

	public function test_current_url_mirrors_the_request_path(): void {
		$structure = (string) get_option( 'permalink_structure' );
		try {
			$this->set_permalink_structure( '/%postname%/' );
			$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_name' => 'probe-page' ) );
			$this->go_to( get_permalink( $post_id ) );

			$this->assertSame( user_trailingslashit( get_permalink( $post_id ) ), erankly_current_url() );
		} finally {
			$this->set_permalink_structure( $structure );
		}
	}

	public function test_get_social_profiles_keeps_only_absolute_urls(): void {
		erankly_update_plugin_settings(
			array( 'social_profiles' => "https://facebook.com/probe\n\nhttps://twitter.com/probe\nhttps://facebook.com/probe" )
		);
		erankly_clear_settings_cache();

		// One URL per line, blank lines skipped, duplicates collapsed.
		$this->assertSame(
			array( 'https://facebook.com/probe', 'https://twitter.com/probe' ),
			erankly_get_social_profiles()
		);
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/sanitization.php
	 * -------------------------------------------------------------------- */

	public function test_target_list_contains_item_matches_id_or_slug(): void {
		$post_id = self::factory()->post->create( array( 'post_name' => 'hello-world' ) );

		$this->assertTrue( erankly_target_list_contains_item( 'hello-world', 'post', $post_id ) );
		$this->assertTrue( erankly_target_list_contains_item( (string) $post_id, 'post', $post_id ) );
		$this->assertTrue( erankly_target_list_contains_item( 'other, hello-world', 'post', $post_id ) );
		$this->assertFalse( erankly_target_list_contains_item( 'other', 'post', $post_id ) );
		$this->assertFalse( erankly_target_list_contains_item( 'hello-world', 'post', 0 ) );
	}

	public function test_targeted_block_matches_singular(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( erankly_targeted_block_matches_singular( array( 'target_contexts' => array( 'singular' ) ) ) );
		$this->assertTrue( erankly_targeted_block_matches_singular( array( 'target_post_types' => array( 'post' ) ) ) );
		$this->assertFalse( erankly_targeted_block_matches_singular( array( 'target_post_types' => array( 'page' ) ) ) );
		$this->assertFalse( erankly_targeted_block_matches_singular( array( 'exclude_items' => (string) $post_id ) ) );
		$this->assertTrue( erankly_targeted_block_matches_singular( array( 'include_items' => (string) $post_id ) ) );
		$this->assertFalse( erankly_targeted_block_matches_singular( array( 'include_items' => '999999' ) ) );
	}

	public function test_targeted_block_matches_term(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->go_to( get_term_link( $term_id, 'category' ) );

		$this->assertTrue( erankly_targeted_block_matches_term( array() ) );
		$this->assertFalse( erankly_targeted_block_matches_term( array( 'exclude_items' => (string) $term_id ) ) );
		$this->assertTrue( erankly_targeted_block_matches_term( array( 'include_items' => (string) $term_id ) ) );
		$this->assertFalse( erankly_targeted_block_matches_term( array( 'include_items' => '999999' ) ) );
	}

	public function test_targeted_block_matches_author(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author_id ) );
		$author = get_userdata( $author_id );
		$this->go_to( get_author_posts_url( $author_id ) );

		$this->assertTrue( erankly_targeted_block_matches_author( array() ) );
		$this->assertFalse( erankly_targeted_block_matches_author( array( 'exclude_items' => (string) $author_id ) ) );
		$this->assertTrue( erankly_targeted_block_matches_author( array( 'include_items' => $author->user_nicename ) ) );
	}

	public function test_targeted_block_matches_post_type_archive(): void {
		register_post_type(
			'erankly_probe',
			array(
				'public'      => true,
				'has_archive' => true,
				'label'       => 'Probes',
			)
		);

		$this->go_to( get_post_type_archive_link( 'erankly_probe' ) );
		$this->assertTrue( is_post_type_archive() );

		$this->assertTrue( erankly_targeted_block_matches_post_type_archive( array( 'target_post_types' => array( 'erankly_probe' ) ) ) );
		$this->assertFalse( erankly_targeted_block_matches_post_type_archive( array( 'target_post_types' => array( 'post' ) ) ) );
		// No post types selected can never match.
		$this->assertFalse( erankly_targeted_block_matches_post_type_archive( array() ) );

		unregister_post_type( 'erankly_probe' );
	}

	public function test_add_empty_custom_code_settings_error_reports_once(): void {
		$before = get_settings_errors( ERANKLY_OPTION );
		erankly_add_empty_custom_code_settings_error();
		$after = get_settings_errors( ERANKLY_OPTION );

		$codes = array_column( $after, 'code' );
		$this->assertContains( 'erankly_custom_code_empty', $codes );
		// Calling again in the same request must not stack a second notice.
		erankly_add_empty_custom_code_settings_error();
		$this->assertSame( count( $after ), count( get_settings_errors( ERANKLY_OPTION ) ) );
		$this->assertGreaterThanOrEqual( count( $before ), count( $after ) );
	}

	public function test_normalize_seo_text_cleans_orphaned_separators_and_markup(): void {
		$this->assertSame( 'A - B', erankly_normalize_seo_text( 'A - B' ) );
		$this->assertSame( 'A - B', erankly_normalize_seo_text( 'A  -  -  B' ) );
		$this->assertSame( 'Title', erankly_normalize_seo_text( 'Title ( )' ) );
		$this->assertSame( 'Leading', erankly_normalize_seo_text( '- Leading' ) );
		$this->assertSame( 'Trailing', erankly_normalize_seo_text( 'Trailing -' ) );
		$this->assertSame( 'Bold', erankly_normalize_seo_text( '<b>Bold</b>' ) );
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/sanitization-schema.php
	 * -------------------------------------------------------------------- */

	public function test_get_article_schema_types_contains_the_known_choices(): void {
		$types = erankly_get_article_schema_types();

		$this->assertArrayHasKey( 'Article', $types );
		$this->assertArrayHasKey( 'BlogPosting', $types );
		$this->assertArrayHasKey( 'none', $types );
		$this->assertNotContains( 'webpage', array_keys( $types ) );
	}

	public function test_is_food_business_type_classifies_known_types(): void {
		$this->assertTrue( erankly_is_food_business_type( 'Restaurant' ) );
		$this->assertTrue( erankly_is_food_business_type( 'Bakery' ) );
		$this->assertFalse( erankly_is_food_business_type( 'Dentist' ) );
		$this->assertFalse( erankly_is_food_business_type( '' ) );
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/settings.php
	 * -------------------------------------------------------------------- */

	public function test_active_panel_submission_slug_strips_the_settings_prefix(): void {
		$this->assertSame( 'features', erankly_active_panel_submission_slug( 'settings-features' ) );
		$this->assertSame( 'features', erankly_active_panel_submission_slug( 'features' ) );
		$this->assertSame( 'general', erankly_active_panel_submission_slug( 'settings-General' ) );
	}

	public function test_register_settings_feature_module_wires_the_registries(): void {
		$rendered = false;
		erankly_register_settings_feature_module(
			'feature_probe_flag',
			static function () use ( &$rendered ): void {
				$rendered = true;
			},
			array( 'feature_probe_blocks' )
		);

		$this->assertContains( 'feature_probe_flag', apply_filters( 'erankly_settings_toggle_keys', array() ) );
		$this->assertContains( 'feature_probe_blocks', apply_filters( 'erankly_settings_collection_keys', array() ) );

		$panels = apply_filters( 'erankly_settings_autosave_panels', array() );
		$this->assertContains( 'feature_probe_flag', $panels['features']['keys'] );
		$this->assertContains( 'feature_probe_blocks', $panels['features']['keys'] );

		$client = apply_filters( 'erankly_settings_autosave_client_panels', array() );
		$this->assertContains( 'feature_probe_flag', $client['features']['refreshKeys'] );

		do_action( 'erankly_settings_features_modules', array() );
		$this->assertTrue( $rendered );
	}

	public function test_maybe_migrate_post_type_schema_moves_types_out_of_legacy_rows(): void {
		$this->clear_migration_flag( 'erankly_migrated_post_type_schema_v1' );

		$stored                                       = erankly_get_settings();
		// The migration bails when the modern key already exists, so start without it.
		unset( $stored['global_post_type_schema'] );
		$stored['global_post_type_meta']['post']      = array( 'title' => 'Kept title', 'webpage_type' => 'ItemPage', 'article_type' => '' );
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_migrate_post_type_schema();

		$settings = erankly_get_settings();
		$this->assertSame( 'ItemPage', $settings['global_post_type_schema']['post']['webpage_type'] );
		// An empty stored article_type means "emit no Article node".
		$this->assertSame( 'none', $settings['global_post_type_schema']['post']['article_type'] );
		$this->assertSame( 'Kept title', $settings['global_post_type_meta']['post']['title'] );
		$this->assertArrayNotHasKey( 'webpage_type', $settings['global_post_type_meta']['post'] );
		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_post_type_schema_v1', false ) );
	}

	public function test_maybe_migrate_settings_rewrites_legacy_title_templates(): void {
		$this->clear_migration_flag( 'erankly_migrated_title_defaults_v1' );

		$stored                                      = erankly_get_settings();
		$stored['default_og_title']                  = '{{post_title}} - {{site_name}}';
		$stored['global_post_type_meta']['post']['title'] = '{{post_title}} - {{site_name}}';
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_migrate_settings();

		$settings = erankly_get_settings();
		$this->assertSame( '{{post_title}}', $settings['default_og_title'] );
		$this->assertSame( '{{post_title}}', $settings['global_post_type_meta']['post']['title'] );
		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_title_defaults_v1', false ) );
	}

	public function test_maybe_migrate_local_business_pages_builds_the_per_blog_map(): void {
		$this->clear_migration_flag( 'erankly_migrated_local_business_pages_v1' );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);

		$stored                                 = erankly_get_settings();
		$stored['local_business_page_path']     = '/contact/';
		$stored['local_business_pages']         = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_migrate_local_business_pages();

		$settings = erankly_get_settings();
		$this->assertArrayHasKey( get_current_blog_id(), $settings['local_business_pages'] );
		$this->assertSame( $page_id, $settings['local_business_pages'][ get_current_blog_id() ] );
		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/template-variables.php
	 * -------------------------------------------------------------------- */

	/**
	 * erankly_get_variable_value() memoises per key + object id, and ids repeat
	 * across tests once the database transaction is rolled back, so this runs
	 * in its own process to start from an empty resolver cache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_replace_json_ld_variables_escapes_for_json(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Backslash \\ here' ) );

		$result = erankly_replace_json_ld_variables( '{{post_title}}', $post_id );

		// The replacement is JSON-encoded with the outer quotes stripped, so
		// re-wrapping and decoding reproduces the original value exactly.
		$this->assertSame( get_the_title( $post_id ), json_decode( '"' . $result . '"', true ) );
		// A template without tokens is returned untouched.
		$this->assertSame( 'plain text', erankly_replace_json_ld_variables( 'plain text', $post_id ) );
	}

	public function test_get_post_category_and_tag_names_join_terms(): void {
		$post_id = self::factory()->post->create();
		$alpha   = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Alpha' ) );
		$beta    = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Beta' ) );
		$gamma   = self::factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Gamma' ) );
		wp_set_post_terms( $post_id, array( $alpha, $beta ), 'category' );
		wp_set_post_terms( $post_id, array( $gamma ), 'post_tag' );

		$categories = erankly_get_post_category_names( $post_id );
		$this->assertStringContainsString( 'Alpha', $categories );
		$this->assertStringContainsString( 'Beta', $categories );
		$this->assertSame( 'Gamma', erankly_get_post_tag_names( $post_id ) );

		$this->assertSame( '', erankly_get_post_category_names( 0 ) );
	}

	public function test_get_variable_preview_value_uses_the_supplied_post_and_term(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Preview title' ) );
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$this->assertSame( 'Preview title', erankly_get_variable_preview_value( 'post_title', $post ) );
		$this->assertSame( (string) get_permalink( $post_id ), erankly_get_variable_preview_value( 'post_url', $post ) );
		// Site-level keys resolve without a sample object.
		$this->assertSame( get_bloginfo( 'name' ), erankly_get_variable_preview_value( 'site_name' ) );
		$this->assertSame( '1', erankly_get_variable_preview_value( 'page_number' ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Preview term', 'slug' => 'preview-term' ) );
		$term    = get_term( $term_id, 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'Preview term', erankly_get_variable_preview_value( 'term_name', null, $term ) );
		$this->assertSame( 'preview-term', erankly_get_variable_preview_value( 'term_slug', null, $term ) );

		$this->assertSame( '', erankly_get_variable_preview_value( 'not_a_variable' ) );
	}

	public function test_get_admin_variable_examples_skips_empty_values(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Examples title' ) );
		$post    = get_post( $post_id );

		$examples = erankly_get_admin_variable_examples( $post );

		$this->assertSame( 'Examples title', $examples['post_title'] );
		$this->assertSame( get_bloginfo( 'name' ), $examples['site_name'] );
		// Request-only keys with no stand-in value are omitted so the token stays literal.
		$this->assertArrayNotHasKey( 'search_query', $examples );
	}

	public function test_sample_post_and_term_lookups_return_the_first_match(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Sample post' ) );
		$sample  = erankly_get_sample_post_for_type( 'post' );
		$this->assertInstanceOf( WP_Post::class, $sample );
		$this->assertSame( $post_id, $sample->ID );

		$this->assertNull( erankly_get_sample_post_for_type( 'does_not_exist' ) );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$term    = erankly_get_sample_term_for_taxonomy( 'category' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( $term_id, $term->term_id );

		$this->assertNull( erankly_get_sample_term_for_taxonomy( 'does_not_exist' ) );
	}

	/* ----------------------------------------------------------------------
	 * includes/helpers/feature-modules.php
	 * -------------------------------------------------------------------- */

	public function test_redirects_enabled_follows_the_setting(): void {
		erankly_update_plugin_settings( array( 'enable_redirects' => 0 ) );
		erankly_clear_settings_cache();
		$this->assertFalse( erankly_redirects_enabled() );

		erankly_update_plugin_settings( array( 'enable_redirects' => 1 ) );
		erankly_clear_settings_cache();
		$this->assertTrue( erankly_redirects_enabled() );
	}

	/* ----------------------------------------------------------------------
	 * includes/custom-code.php
	 * -------------------------------------------------------------------- */

	public function test_custom_code_boot_registers_output_hooks(): void {
		erankly_custom_code_boot();

		$this->assertSame( 20, has_action( 'wp_head', 'erankly_render_custom_head_code' ) );
		$this->assertSame( 5, has_action( 'wp_body_open', 'erankly_render_custom_body_open_code' ) );
		$this->assertSame( 20, has_action( 'wp_footer', 'erankly_render_custom_body_close_code' ) );
		$this->assertSame( 18, has_action( 'init', 'erankly_maybe_migrate_legacy_custom_code' ) );
	}

	public function test_maybe_migrate_legacy_custom_code_moves_scalars_into_blocks(): void {
		$stored                           = erankly_get_settings();
		$stored['head_code']              = '<meta name="legacy-head" content="1">';
		$stored['head_code_blocks']       = array();
		$stored['body_open_code']         = '<div id="legacy-open"></div>';
		$stored['body_open_code_blocks']  = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_migrate_legacy_custom_code();

		$settings = erankly_get_settings();
		$this->assertSame( '', $settings['head_code'] );
		$this->assertCount( 1, $settings['head_code_blocks'] );
		$this->assertSame( '<meta name="legacy-head" content="1">', $settings['head_code_blocks'][0]['code'] );
		$this->assertSame( '', $settings['body_open_code'] );
		$this->assertCount( 1, $settings['body_open_code_blocks'] );
	}

	public function test_maybe_migrate_legacy_custom_code_clears_a_duplicate_scalar(): void {
		$block = array(
			'enabled'         => 1,
			'code'            => '<meta name="dupe" content="1">',
			'target_contexts' => array( 'singular' ),
		);

		$stored                     = erankly_get_settings();
		$stored['head_code']        = '<meta name="dupe" content="1">';
		$stored['head_code_blocks'] = array( $block );
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_migrate_legacy_custom_code();

		$settings = erankly_get_settings();
		$this->assertSame( '', $settings['head_code'] );
		$this->assertCount( 1, $settings['head_code_blocks'] );
	}

	/* ----------------------------------------------------------------------
	 * includes/reset.php
	 * -------------------------------------------------------------------- */

	public function test_reset_url_points_at_the_settings_tab(): void {
		$url = erankly_reset_url();
		$this->assertStringContainsString( 'page=erankly', $url );
		$this->assertStringContainsString( 'erankly_tab=settings', $url );
	}

	public function test_reset_render_notice_outputs_each_state(): void {
		$_GET['erankly_reset_notice'] = 'local';
		ob_start();
		erankly_reset_render_notice();
		$this->assertStringContainsString( 'reset for this site', (string) ob_get_clean() );

		$_GET['erankly_reset_notice'] = 'global_queued';
		ob_start();
		erankly_reset_render_notice();
		$this->assertStringContainsString( 'network reset has started', (string) ob_get_clean() );

		$_GET['erankly_reset_notice'] = 'unknown_state';
		ob_start();
		erankly_reset_render_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_reset_abandon_settings_lock_removes_a_stale_lease(): void {
		erankly_add_settings_lock(
			array(
				'token'       => 'stale-token',
				'acquired_at' => time() - 600,
				'expires_at'  => time() - 300,
			)
		);
		$this->assertIsArray( erankly_get_settings_lock() );

		erankly_reset_abandon_settings_lock();

		$this->assertFalse( erankly_get_settings_lock() );
	}

	public function test_reset_network_shared_data_restores_defaults(): void {
		erankly_update_plugin_settings( array( 'organization_name' => 'Custom organisation' ) );
		erankly_clear_settings_cache();

		erankly_reset_network_shared_data();

		$defaults = erankly_default_settings();
		$settings = erankly_get_settings();
		$this->assertSame( $defaults['organization_name'], $settings['organization_name'] );
	}

	public function test_reset_network_returns_false_without_multisite(): void {
		if ( is_multisite() ) {
			// This asserts the Single Site guard: erankly_reset_network() is only
			// inert when the install is not Multisite.
			$this->markTestSkipped( 'Guards the Single Site behaviour of the network reset.' );
		}

		$this->assertFalse( is_multisite() );
		$this->assertFalse( erankly_reset_network() );
	}

	public function test_reset_render_panel_is_silent_without_capability(): void {
		wp_set_current_user( 0 );

		ob_start();
		erankly_reset_render_panel();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_reset_handle_actions_ignores_non_reset_requests(): void {
		$stored                      = erankly_get_settings();
		$stored['organization_name'] = 'Untouched by reset probe';
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();
		$before = erankly_get_settings();

		// No capability: even a valid-looking submission is ignored.
		wp_set_current_user( 0 );
		$_GET['page']                 = 'erankly';
		$_POST['erankly_reset_action'] = 'reset_local';
		erankly_reset_handle_actions();
		unset( $_POST['erankly_reset_action'] );

		// Capability, but the request is not the reset form.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$_GET['page'] = 'not-erankly';
		erankly_reset_handle_actions();

		erankly_clear_settings_cache();
		$this->assertSame( $before['organization_name'], erankly_get_settings()['organization_name'] );
	}

	/* ----------------------------------------------------------------------
	 * includes/network-reset.php (single-site guards)
	 * -------------------------------------------------------------------- */

	public function test_schedule_network_reset_batch_requires_multisite(): void {
		if ( is_multisite() ) {
			// On Single Site the scheduler rejects the request outright; on
			// Multisite it proceeds and fails later with erankly_reset_inactive
			// because no reset job exists, so this guard is Single Site only.
			$this->markTestSkipped( 'Guards the Single Site behaviour of the network reset scheduler.' );
		}

		$result = erankly_schedule_network_reset_batch( 'token' );
		$this->assertWPError( $result );
		$this->assertSame( 'erankly_reset_invalid', $result->get_error_code() );
	}

	public function test_queue_network_reset_returns_false_without_multisite(): void {
		if ( is_multisite() ) {
			// erankly_queue_network_reset() succeeds on Multisite, so the
			// Single Site "returns false" guard does not apply there.
			$this->markTestSkipped( 'Guards the Single Site behaviour of the network reset queue.' );
		}

		$this->assertFalse( erankly_queue_network_reset() );
	}

	public function test_process_network_reset_batch_is_inert_without_multisite(): void {
		erankly_process_network_reset_batch( 'token' );

		// The worker must not schedule or record anything on a single-site install.
		$this->assertFalse( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK ) );
	}

	public function test_render_network_reset_status_notice_is_inert_without_multisite(): void {
		ob_start();
		erankly_render_network_reset_status_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
