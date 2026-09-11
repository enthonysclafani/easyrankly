<?php
/** Redirect repository: table access, caches, runtime rule buckets and admin listing. */

final class ERankly_Redirects_Repository_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();

		if ( ! function_exists( 'erankly_ensure_redirect_classes_available' ) ) {
			require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
		}
		erankly_ensure_redirect_classes_available();

		if ( ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			ERankly_Redirects_Activator::activate();
		}
	}

	public function tear_down(): void {
		$this->delete_all_rows();
		foreach (
			array(
				'erankly_redirects_runtime_rules',
				'erankly_redirects_runtime_rules_global',
				'erankly_redirects_runtime_rules_all',
				'erankly_redirects_runtime_rules_prefix_index',
			) as $option
		) {
			delete_option( $option );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function repo(): ERankly_Redirects_Repository {
		return new ERankly_Redirects_Repository();
	}

	private function delete_all_rows(): void {
		global $wpdb;
		$table = ERankly_Redirects_Repository::get_table_name();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of a plugin-owned table.
	}

	private function make_rule( array $overrides = array() ): int {
		$data = array_merge(
			array(
				'source_path'  => '/seed',
				'source_query' => '',
				'target_url'   => '/seed-target',
				'status_code'  => 301,
				'match_type'   => 'exact',
				'is_active'    => 1,
				'note'         => '',
			),
			$overrides
		);

		return $this->repo()->create( $data );
	}

	private function invoke_static( string $class, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( null, $args );
	}

	private function invoke( ERankly_Redirects_Repository $repository, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $repository, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $repository, $args );
	}

	public function test_get_table_name_uses_wordpress_prefix(): void {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'erankly_redirects', ERankly_Redirects_Repository::get_table_name() );
	}

	public function test_cache_key_includes_the_generation_namespace(): void {
		update_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, 'gen-test', false );
		$this->assertSame( 'erankly_redirect_gen-test_abc', $this->repo()->get_cache_key( 'abc' ) );
	}

	public function test_set_and_get_cached_exact_round_trips(): void {
		$repository = $this->repo();
		$repository->set_cached_exact( 'hash1', array( 'id' => 5 ) );

		$this->assertSame( array( 'id' => 5 ), $repository->get_cached_exact( 'hash1' ) );
		$this->assertFalse( $repository->is_cached_exact_miss( $repository->get_cached_exact( 'hash1' ) ) );
	}

	public function test_negative_cache_sentinel_round_trips_and_is_deletable(): void {
		$repository = $this->repo();
		$repository->set_cached_exact_miss( 'hash2' );

		$cached = $repository->get_cached_exact( 'hash2' );
		$this->assertTrue( $repository->is_cached_exact_miss( $cached ) );

		$repository->delete_cached_exact( 'hash2' );
		$this->assertFalse( $repository->get_cached_exact( 'hash2' ) );
	}

	public function test_create_then_find_by_id_and_hash(): void {
		$repository = $this->repo();
		$id         = $repository->create(
			array(
				'source_path' => '/created-rule',
				'target_url'  => '/target',
				'status_code' => 301,
				'match_type'  => 'exact',
				'note'        => 'hello',
			)
		);

		$this->assertGreaterThan( 0, $id );

		$row = $repository->find_by_id( $id );
		$this->assertIsArray( $row );
		$this->assertSame( '/created-rule', $row['source_path'] );
		$this->assertSame(
			ERankly_Redirects_Normalizer::source_hash( ERankly_Redirects_Normalizer::normalize_path( '/created-rule' ) ),
			$row['source_hash']
		);

		$by_hash = $repository->find_by_hash( (string) $row['rule_hash'] );
		$this->assertIsArray( $by_hash );
		$this->assertSame( $id, (int) $by_hash['id'] );

		$this->assertNull( $repository->find_by_id( 999999 ) );
	}

	public function test_create_status_only_code_clears_target(): void {
		$id  = $this->make_rule(
			array(
				'source_path' => '/gone',
				'target_url'  => 'https://example.com/should-be-cleared',
				'status_code' => 410,
			)
		);
		$row = $this->repo()->find_by_id( $id );

		$this->assertSame( '', (string) $row['target_url'] );
		$this->assertSame( 410, (int) $row['status_code'] );
	}

	public function test_create_invalidates_the_exact_cache_for_its_source_hash(): void {
		$repository = $this->repo();
		$hash       = ERankly_Redirects_Normalizer::source_hash(
			ERankly_Redirects_Normalizer::normalize_path( '/cached-create' )
		);
		$repository->set_cached_exact( $hash, array( 'id' => 99 ) );

		$repository->create(
			array(
				'source_path' => '/cached-create',
				'target_url'  => '/x',
				'status_code' => 301,
			)
		);

		$this->assertFalse( $repository->get_cached_exact( $hash ) );
	}

	public function test_find_active_exact_by_hash_only_matches_default_exact_rules(): void {
		$repository = $this->repo();
		$exact_id   = $this->make_rule(
			array(
				'source_path' => '/exact-active',
				'target_url'  => '/t',
			)
		);
		$exact_hash = ERankly_Redirects_Normalizer::source_hash(
			ERankly_Redirects_Normalizer::normalize_path( '/exact-active' )
		);

		$found = $repository->find_active_exact_by_hash( $exact_hash );
		$this->assertIsArray( $found );
		$this->assertSame( $exact_id, (int) $found['id'] );

		// A wildcard rule with a matching hash is never returned by the exact lookup.
		$this->make_rule(
			array(
				'source_path' => '/wild/*',
				'target_url'  => '/t',
				'match_type'  => 'wildcard',
			)
		);
		$wild_hash = ERankly_Redirects_Normalizer::source_hash(
			ERankly_Redirects_Normalizer::normalize_path( '/wild/*' )
		);
		$this->assertNull( $repository->find_active_exact_by_hash( $wild_hash ) );

		$this->assertTrue( $repository->toggle_active( $exact_id ) );
		$this->assertNull( $repository->find_active_exact_by_hash( $exact_hash ) );
	}

	public function test_get_exact_rule_cached_populates_positive_and_negative_caches(): void {
		$repository = $this->repo();
		$missing    = ERankly_Redirects_Normalizer::source_hash(
			ERankly_Redirects_Normalizer::normalize_path( '/no-such-rule' )
		);

		$this->assertNull( $repository->get_exact_rule_cached( $missing ) );
		$this->assertTrue( $repository->is_cached_exact_miss( $repository->get_cached_exact( $missing ) ) );

		$id  = $this->make_rule(
			array(
				'source_path' => '/cached-rule',
				'target_url'  => '/target',
			)
		);
		$hit = ERankly_Redirects_Normalizer::source_hash(
			ERankly_Redirects_Normalizer::normalize_path( '/cached-rule' )
		);

		$rule = $repository->get_exact_rule_cached( $hit );
		$this->assertIsArray( $rule );
		$this->assertSame( $id, (int) $rule['id'] );
	}

	public function test_update_merges_partial_data_and_preserves_semantics(): void {
		$repository = $this->repo();
		$id         = $this->make_rule(
			array(
				'source_path' => '/keep-source',
				'target_url'  => '/keep-target',
			)
		);

		$this->assertTrue( $repository->update( $id, array( 'note' => 'updated note' ) ) );

		$row = $repository->find_by_id( $id );
		$this->assertSame( 'updated note', (string) $row['note'] );
		$this->assertSame( '/keep-source', $row['source_path'] );
		$this->assertSame( '/keep-target', $row['target_url'] );
	}

	public function test_delete_reports_existing_and_missing_rows(): void {
		$repository = $this->repo();
		$id         = $this->make_rule( array( 'source_path' => '/to-delete' ) );

		$this->assertTrue( $repository->delete( $id ) );
		$this->assertNull( $repository->find_by_id( $id ) );

		$this->assertFalse( $repository->delete( $id ) );
		$this->assertFalse( $repository->delete( 999999 ) );
	}

	public function test_toggle_active_flips_state_and_reports_missing_rows(): void {
		$repository = $this->repo();
		$id         = $this->make_rule(
			array(
				'source_path' => '/toggle-me',
				'is_active'   => 1,
			)
		);

		$this->assertTrue( $repository->toggle_active( $id ) );
		$this->assertSame( 0, (int) $repository->find_by_id( $id )['is_active'] );

		$this->assertTrue( $repository->toggle_active( $id ) );
		$this->assertSame( 1, (int) $repository->find_by_id( $id )['is_active'] );

		$this->assertFalse( $repository->toggle_active( 999999 ) );
	}

	public function test_upsert_by_hash_creates_then_updates_same_identity(): void {
		$repository = $this->repo();

		$created = $repository->upsert_by_hash(
			array(
				'source_path' => '/upsert',
				'target_url'  => '/first',
				'status_code' => 301,
				'match_type'  => 'exact',
			)
		);
		$this->assertSame( 'created', $created );

		$updated = $repository->upsert_by_hash(
			array(
				'source_path' => '/upsert',
				'target_url'  => '/second',
				'status_code' => 301,
				'match_type'  => 'exact',
			)
		);
		$this->assertSame( 'updated', $updated );
		$this->assertSame( 1, $repository->count_redirects( '' ) );

		$row = $repository->list_redirects( '', 1, 10 );
		$this->assertSame( '/second', $row[0]['target_url'] );
	}

	public function test_list_and_count_redirects_search_and_paginate(): void {
		$repository = $this->repo();
		$this->make_rule(
			array(
				'source_path' => '/alpha',
				'target_url'  => '/a',
			)
		);
		$this->make_rule(
			array(
				'source_path' => '/beta',
				'target_url'  => '/b',
			)
		);
		$this->make_rule(
			array(
				'source_path' => '/gamma-search-token',
				'target_url'  => '/c',
			)
		);

		$this->assertSame( 3, $repository->count_redirects( '' ) );
		$this->assertSame( 1, $repository->count_redirects( 'gamma-search-token' ) );

		$first_page = $repository->list_redirects( '', 1, 2 );
		$this->assertCount( 2, $first_page );
		// Newest first: the last-created rule leads.
		$this->assertSame( '/gamma-search-token', $first_page[0]['source_path'] );

		$matched = $repository->list_redirects( 'gamma-search-token', 1, 10 );
		$this->assertCount( 1, $matched );
		$this->assertSame( '/gamma-search-token', $matched[0]['source_path'] );
	}

	public function test_list_redirects_orders_by_hit_count(): void {
		global $wpdb;
		$repository = $this->repo();
		$low_id     = $this->make_rule( array( 'source_path' => '/low-hits' ) );
		$high_id    = $this->make_rule( array( 'source_path' => '/high-hits' ) );

		$table = ERankly_Redirects_Repository::get_table_name();
		$wpdb->update( $table, array( 'hit_count' => 1 ), array( 'id' => $low_id ) );
		$wpdb->update( $table, array( 'hit_count' => 50 ), array( 'id' => $high_id ) );

		$ordered = $repository->list_redirects( '', 1, 10, 'hit_count', 'asc' );
		$this->assertSame( $low_id, (int) $ordered[0]['id'] );
		$this->assertSame( $high_id, (int) $ordered[1]['id'] );
	}

	public function test_export_page_returns_bounded_rows_after_a_cursor(): void {
		$repository = $this->repo();
		$first      = $this->make_rule( array( 'source_path' => '/export-one' ) );
		$second     = $this->make_rule( array( 'source_path' => '/export-two' ) );

		$page = $repository->export_page( 0, 500 );
		$this->assertCount( 2, $page );
		$this->assertArrayHasKey( '_cursor', $page[0] );

		$cursor = (int) $page[0]['_cursor'];
		$rest   = $repository->export_page( $cursor, 500 );
		$this->assertCount( 1, $rest );
		$this->assertSame( $second, (int) $rest[0]['_cursor'] );
		$this->assertSame( $first, $cursor );
	}

	public function test_increment_hit_updates_counters_when_sampling_is_one(): void {
		$id = $this->make_rule( array( 'source_path' => '/hit-me' ) );

		add_filter(
			'erankly_redirect_hit_sample_rate',
			static function (): int {
				return 1;
			}
		);

		$this->repo()->increment_hit( $id );

		$row = $this->repo()->find_by_id( $id );
		$this->assertSame( 1, (int) $row['hit_count'] );
		$this->assertNotEmpty( (string) $row['last_hit_at'] );
	}

	public function test_get_pattern_rules_buckets_advanced_rules_and_sorts(): void {
		$repository = $this->repo();
		$this->make_rule(
			array(
				'source_path' => '/shop/*',
				'target_url'  => '/store',
				'match_type'  => 'wildcard',
			)
		);
		$this->make_rule(
			array(
				'source_path' => '^/legacy/(\d+)$',
				'target_url'  => '/new/$1',
				'match_type'  => 'regex',
			)
		);
		$this->make_rule(
			array(
				'source_path' => '/plain-exact',
				'target_url'  => '/plain',
				'match_type'  => 'exact',
			)
		);

		$all = $repository->get_pattern_rules( '' );
		$this->assertCount( 2, $all );

		$candidates = $repository->get_pattern_rules( '/shop/item', '' );
		$types      = array_column( $candidates, 'match_type' );
		$this->assertContains( 'wildcard', $types );
		$this->assertContains( 'regex', $types );

		// An unrelated route only ever sees rules bucketed globally (the regex).
		$unrelated = $repository->get_pattern_rules( '/unrelated', '' );
		$this->assertSame( array( 'regex' ), array_values( array_unique( array_column( $unrelated, 'match_type' ) ) ) );
	}

	public function test_runtime_rule_buckets_are_persisted_and_invalidated(): void {
		$repository = $this->repo();
		$this->make_rule(
			array(
				'source_path' => '/shop/*',
				'target_url'  => '/store',
				'match_type'  => 'wildcard',
			)
		);

		// First call compiles and persists the runtime options.
		$repository->get_pattern_rules( '' );
		$manifest = get_option( 'erankly_redirects_runtime_rules', null );
		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'version', $manifest );

		// Mutation clears the persisted rules so the next request rebuilds them.
		$repository->invalidate_runtime_rules();
		$this->assertFalse( get_option( 'erankly_redirects_runtime_rules', false ) );
		$this->assertFalse( get_option( 'erankly_redirects_runtime_rules_global', false ) );
	}

	public function test_begin_bulk_defers_invalidation_until_end_bulk(): void {
		$repository = $this->repo();
		$repository->get_pattern_rules( '' );
		$this->assertIsArray( get_option( 'erankly_redirects_runtime_rules', null ) );

		// must reuse the same instance: begin_bulk()/end_bulk() hold per-instance state.
		$repository->begin_bulk();
		$repository->create(
			array(
				'source_path' => '/bulk/*',
				'target_url'  => '/bulk-target',
				'match_type'  => 'wildcard',
				'status_code' => 301,
			)
		);
		// Still present: the invalidation is deferred while the bulk mutation runs.
		$this->assertIsArray( get_option( 'erankly_redirects_runtime_rules', null ) );

		$repository->end_bulk();
		$this->assertFalse( get_option( 'erankly_redirects_runtime_rules', false ) );

		// A fresh repository rebuilds the buckets, now including the new rule.
		$rebuilt = ( new ERankly_Redirects_Repository() )->get_pattern_rules( '' );
		$this->assertCount( 1, $rebuilt );
		$this->assertSame( 'wildcard', $rebuilt[0]['match_type'] );
	}

	public function test_runtime_prefix_key_and_option_name_are_stable(): void {
		$repository = $this->repo();

		$this->assertSame( 'shop', $this->invoke( $repository, 'runtime_prefix_key', array( '/Shop/Item' ) ) );
		$this->assertSame( '', $this->invoke( $repository, 'runtime_prefix_key', array( '/' ) ) );

		$name = $this->invoke( $repository, 'runtime_prefix_option_name', array( 'shop' ) );
		$this->assertSame( 1, preg_match( '/^erankly_redirects_runtime_rules_prefix_[a-f0-9]{24}$/', $name ) );
		$this->assertSame( $name, $this->invoke( $repository, 'runtime_prefix_option_name', array( 'shop' ) ) );
	}

	public function test_compile_and_persist_runtime_rules_builds_buckets(): void {
		$repository = $this->repo();

		$compiled = $this->invoke(
			$repository,
			'compile_runtime_rules',
			array(
				array(
					array(
						'id'             => 7,
						'source_path'    => '/shop/*',
						'source_hash'    => md5( '/shop/*' ),
						'source_query'   => '',
						'target_url'     => '/store',
						'status_code'    => 301,
						'match_type'     => 'wildcard',
						'case_sensitive' => 0,
						'trailing_slash' => 'ignore',
						'query_mode'     => 'ignore',
					),
				),
			)
		);

		$this->assertIsArray( $compiled );
		$this->assertArrayHasKey( 'all', $compiled );
		$this->assertCount( 1, $compiled['all'] );
		$this->assertArrayHasKey( 'prefix', $compiled );
		$this->assertArrayHasKey( 'shop', $compiled['prefix'] );

		$this->invoke( $repository, 'persist_runtime_rules', array( $compiled ) );
		$manifest = get_option( 'erankly_redirects_runtime_rules', null );
		$this->assertIsArray( $manifest );
		$this->assertSame( 1, (int) $manifest['prefix_count'] );
		$this->assertIsArray( get_option( 'erankly_redirects_runtime_rules_global', null ) );
	}

	public function test_normalize_data_applies_defaults_and_hashes(): void {
		$repository = $this->repo();

		$data = $this->invoke(
			$repository,
			'normalize_data',
			array(
				array(
					'source_path' => '/normalized',
					'target_url'  => 'https://example.com/keep',
					'status_code' => 301,
					'match_type'  => 'not-a-real-type',
				),
			)
		);

		$this->assertSame( 'exact', $data['match_type'] );
		$this->assertSame( 301, (int) $data['status_code'] );
		$this->assertSame( 1, (int) $data['is_active'] );
		$this->assertSame(
			ERankly_Redirects_Normalizer::source_hash( ERankly_Redirects_Normalizer::normalize_path( '/normalized' ) ),
			$data['source_hash']
		);
		$this->assertSame( ERankly_Redirects_Normalizer::rule_hash( $data ), $data['rule_hash'] );
	}

	public function test_build_search_clause_returns_empty_for_blank_and_like_clause_otherwise(): void {
		$repository = $this->repo();

		$blank = $this->invoke( $repository, 'build_search_clause', array( '   ' ) );
		$this->assertSame( array( '', array() ), $blank );

		$clause = $this->invoke( $repository, 'build_search_clause', array( 'needle' ) );
		$this->assertStringContainsString( 'WHERE (source_path LIKE', $clause[0] );
		$this->assertSame( '%needle%', $clause[1][0] );
	}
}
