<?php
/** Native single-site multilingual runtime and authenticated editorial workflows. */
final class ERankly_Multilingual_Singlesite_Test extends WP_UnitTestCase {
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		if ( ! is_multisite() ) {
			require_once ERANKLY_PATH . 'includes/multilingual/singlesite/bootstrap.php';
			erankly_mlss_install();
		}
	}
	public function set_up(): void {
		parent::set_up();
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site foundation.' );
		}
		require_once ERANKLY_PATH . 'includes/multilingual/singlesite/bootstrap.php';
		erankly_tests_set_settings( array( 'enable_multilingual' => 1 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		erankly_mlss_save_settings( array( 'languages' => array( 'it_IT', 'en_US', 'fr_FR' ), 'x_default_language' => 'it_IT' ) );
		$this->assertTrue( erankly_mlss_install() );
		unset( $GLOBALS['erankly_mlss_request_language'], $GLOBALS['erankly_mlss_invalid_language'], $GLOBALS['erankly_mlss_strings'], $GLOBALS['erankly_mlss_localize_home'], $GLOBALS['erankly_mlss_raw_home'] );
		erankly_mlss_register_hooks();
	}

	public function tear_down(): void {
		unset( $GLOBALS['erankly_mlss_settings'], $GLOBALS['erankly_mlss_request_language'], $GLOBALS['erankly_mlss_invalid_language'], $GLOBALS['erankly_mlss_strings'], $GLOBALS['erankly_mlss_localize_home'], $GLOBALS['erankly_mlss_raw_home'] );
		wp_set_current_user( 0 );
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	private function posts( int $count = 2 ): array {
		return array_map( 'intval', self::factory()->post->create_many( $count, array( 'post_status' => 'publish' ) ) );
	}

	private function link( array $ids ): array {
		$map = array_combine( array_slice( array( 'it-it', 'en-us', 'fr-fr' ), 0, count( $ids ) ), $ids );
		$this->assertTrue( erankly_mlss_set_translations( 'post', $ids[0], $map ) );
		ksort( $map );
		return $map;
	}

	public function test_language_configuration_is_validated_bounded_and_not_autoloaded(): void {
		$settings = erankly_mlss_normalize_settings( array( 'languages' => "it_IT\nen-US\nit-it\nx-default\ninvalid\n<script>", 'default_language' => 'not-configured' ) );
		$this->assertSame( array( 'it-it', 'en-us' ), $settings['languages'] );
		$this->assertSame( 'it-it', $settings['default_language'] );
		$this->assertSame( '', $settings['x_default_language'] );
		$this->assertSame( array( 'it-it' => 'it-it', 'en-us' => 'en-us' ), $settings['url_slugs'] );
		$numeric = erankly_mlss_normalize_settings( array( 'languages' => array( 'it-it' ), 'url_slugs' => array( 'it-it' => '0' ) ) );
		$this->assertSame( '0', $numeric['url_slugs']['it-it'] );
		$this->assertArrayNotHasKey( ERANKLY_MLSS_OPTION, wp_load_alloptions( true ) );
	}

	public function test_maps_are_reciprocal_and_reassignment_preserves_detached_languages(): void {
		$ids = $this->posts( 3 );
		$map = $this->link( array_slice( $ids, 0, 2 ) );
		$this->assertSame( $map, erankly_mlss_get_translations( 'post', $ids[1] ) );
		$new = array( 'it-it' => $ids[0], 'en-us' => $ids[2] );
		$this->assertTrue( erankly_mlss_set_translations( 'post', $ids[0], $new ) );
		$this->assertSame( array( 'en-us' => $ids[1] ), erankly_mlss_get_translations( 'post', $ids[1] ) );
		ksort( $new );
		$this->assertSame( $new, erankly_mlss_get_translations( 'post', $ids[2] ) );
	}

	public function test_invalid_clusters_cannot_overwrite_existing_links(): void {
		$ids = $this->posts();
		$map = $this->link( $ids );
		$page = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );
		foreach ( array( array( 'it-it' => $ids[0], 'en-us' => $ids[0] ), array( 'it-it' => $ids[0], 'de-de' => $ids[1] ), array( 'it-it' => $ids[0], 'en-us' => $page ), array( 'en-us' => $ids[1] ) ) as $invalid ) {
			$this->assertWPError( erankly_mlss_set_translations( 'post', $ids[0], $invalid ) );
			$this->assertSame( $map, erankly_mlss_get_translations( 'post', $ids[0] ) );
		}
	}

	public function test_failed_writes_restore_the_previous_cluster(): void {
		$ids = $this->posts( 3 );
		$map = $this->link( array_slice( $ids, 0, 2 ) );
		$fail = static fn( $query ) => str_starts_with( $query, 'INSERT INTO ' . erankly_mlss_table() . ' ' ) ? '' : $query;
		add_filter( 'query', $fail );
		try {
			$result = erankly_mlss_set_translations( 'post', $ids[0], array( 'it-it' => $ids[0], 'en-us' => $ids[2] ) );
		} finally {
			remove_filter( 'query', $fail );
		}
		$this->assertWPError( $result );
		$this->assertSame( $map, erankly_mlss_get_translations( 'post', $ids[0] ) );
		$this->assertSame( $map, erankly_mlss_get_translations( 'post', $ids[1] ) );
		$this->assertSame( array(), erankly_mlss_membership( 'post', $ids[2] ) );
	}

	public function test_all_affected_objects_require_edit_permission_before_any_write(): void {
		$author = (int) self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = (int) self::factory()->post->create( array( 'post_author' => $author, 'post_status' => 'publish' ) );
		$other  = $this->posts( 1 )[0];
		wp_set_current_user( $author );
		$result = erankly_mlss_set_translations( 'post', $own, array( 'it-it' => $own, 'en-us' => $other ) );
		$this->assertWPError( $result );
		$this->assertSame( 'erankly_mlss_forbidden', $result->get_error_code() );
		$this->assertSame( array(), erankly_mlss_membership( 'post', $own ) );
		$this->assertSame( array(), erankly_mlss_membership( 'post', $other ) );
	}

	public function test_deleted_objects_are_removed_from_counterparts(): void {
		$ids = $this->posts();
		$this->link( $ids );
		wp_delete_post( $ids[0], true );
		$this->assertSame( array( 'en-us' => $ids[1] ), erankly_mlss_get_translations( 'post', $ids[1] ) );
	}

	public function test_hreflang_uses_existing_self_canonical_urls_and_x_default(): void {
		$ids = $this->posts();
		$this->link( $ids );
		$this->go_to( get_permalink( $ids[0] ) );
		$provider = new ERankly_MLSS_Provider();
		$alternates = $provider->get_alternates( array(), false );
		$this->assertSame( get_permalink( $ids[0] ), $alternates['it-it'] );
		$this->assertSame( get_permalink( $ids[1] ), $alternates['en-us'] );
		$this->assertSame( $alternates['it-it'], $alternates['x-default'] );
		$this->assertSame( get_permalink( $ids[0] ), $provider->localize_url( get_permalink( $ids[0] ), array() ) );
	}

	public function test_noindex_is_kept_for_navigation_and_excluded_from_signalling(): void {
		$ids = $this->posts( 3 );
		$this->link( $ids );
		update_post_meta( $ids[1], '_erankly_index_directive', 'noindex' );
		$this->go_to( get_permalink( $ids[0] ) );
		$provider = new ERankly_MLSS_Provider();
		$this->assertArrayNotHasKey( 'en-us', $provider->get_alternates( array(), false ) );
		$this->assertArrayHasKey( 'en-us', $provider->get_alternates( array(), true ) );
		$this->go_to( get_permalink( $ids[1] ) );
		$this->assertSame( array(), $provider->get_alternates( array(), false ) );
	}

	public function test_hreflang_uses_native_urls_when_seo_is_disabled(): void {
		$ids = $this->posts();
		$this->link( $ids );
		update_post_meta( $ids[1], '_erankly_index_directive', 'noindex' );
		update_post_meta( $ids[1], '_erankly_canonical', 'https://example.net/old-canonical/' );
		erankly_tests_set_settings( array( 'enable_multilingual' => 1, 'enable_seo' => 0 ) );
		$this->go_to( get_permalink( $ids[0] ) );
		$provider = new ERankly_MLSS_Provider();
		$alternates = $provider->get_alternates( array(), false );
		$this->assertSame( get_permalink( $ids[0] ), $alternates['it-it'] );
		$this->assertSame( get_permalink( $ids[1] ), $alternates['en-us'] );
		$this->assertSame( $alternates['it-it'], $alternates['x-default'] );
	}

	public function test_drafts_passwords_and_cross_canonicals_are_excluded(): void {
		$ids = $this->posts( 3 );
		$this->link( $ids );
		$this->go_to( get_permalink( $ids[0] ) );
		$provider = new ERankly_MLSS_Provider();
		wp_update_post( array( 'ID' => $ids[1], 'post_status' => 'draft' ) );
		$this->assertArrayNotHasKey( 'en-us', $provider->get_alternates( array(), true ) );
		wp_update_post( array( 'ID' => $ids[1], 'post_status' => 'publish', 'post_password' => 'protected' ) );
		$this->assertArrayNotHasKey( 'en-us', $provider->get_alternates( array(), true ) );
		wp_update_post( array( 'ID' => $ids[1], 'post_password' => '' ) );
		update_post_meta( $ids[1], '_erankly_canonical', 'https://example.net/elsewhere/' );
		$this->assertArrayNotHasKey( 'en-us', $provider->get_alternates( array(), false ) );
		$this->assertArrayHasKey( 'en-us', $provider->get_alternates( array(), true ) );
	}

	public function test_unlinked_and_one_way_maps_do_not_create_alternates(): void {
		$ids = $this->posts();
		$this->go_to( get_permalink( $ids[0] ) );
		$provider = new ERankly_MLSS_Provider();
		$this->assertSame( array(), $provider->get_alternates( array(), false ) );
		update_post_meta( $ids[0], ERANKLY_MLSS_META_KEY, array( 'it-it' => $ids[0], 'en-us' => $ids[1] ) );
		$this->assertSame( array(), $provider->get_alternates( array(), false ) );
	}

	public function test_static_homepage_inherits_the_homepage_robots_policy(): void {
		$it = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$en = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $it );
		$this->assertTrue( erankly_mlss_set_translations( 'post', $it, array( 'it-it' => $it, 'en-us' => $en ) ) );
		erankly_tests_set_settings( array( 'enable_multilingual' => 1, 'global_special_meta' => array( 'homepage' => array( 'noindex' => 1 ) ) ) );
		$this->go_to( home_url( '/' ) );
		$provider = new ERankly_MLSS_Provider();
		$this->assertSame( array(), $provider->get_alternates( array(), false ) );
		$this->assertArrayHasKey( 'it-it', $provider->get_alternates( array(), true ) );
		update_post_meta( $it, '_erankly_index_directive', 'index' );
		$this->assertSame( array(), $provider->get_alternates( array(), false ) );
		update_post_meta( $en, '_erankly_index_directive', 'index' );
		$this->assertArrayHasKey( 'it-it', $provider->get_alternates( array(), false ) );
	}

	public function test_term_clusters_support_native_urls_and_deletion_cleanup(): void {
		$it = (int) self::factory()->category->create();
		$en = (int) self::factory()->category->create();
		$this->assertTrue( erankly_mlss_set_translations( 'term', $it, array( 'it-it' => $it, 'en-us' => $en ) ) );
		$this->go_to( get_term_link( $it ) );
		$provider = new ERankly_MLSS_Provider();
		$this->assertSame( get_term_link( $en ), $provider->get_alternates( array(), false )['en-us'] );
		wp_delete_term( $it, 'category' );
		$this->assertSame( array( 'en-us' => $en ), erankly_mlss_get_translations( 'term', $en ) );
	}

	public function test_rest_rejects_anonymous_writes_and_synchronizes_authorized_writes(): void {
		$ids = $this->posts();
		do_action( 'rest_api_init' );
		$user = get_current_user_id();
		$request = new WP_REST_Request( 'POST', '/erankly/v1/multilingual/singlesite/translations/post/' . $ids[0] );
		$request->set_param( 'translations', array( 'it-it' => $ids[0], 'en-us' => $ids[1] ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		wp_set_current_user( $user );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( erankly_mlss_get_translations( 'post', $ids[0] ), erankly_mlss_get_translations( 'post', $ids[1] ) );
	}

	public function test_feature_toggle_round_trips_through_the_core_features_panel(): void {
		erankly_tests_load_settings_sanitizer();
		$this->assertContains( 'enable_multilingual', erankly_settings_panel_keys( 'features' ) );
		$off = erankly_sanitize_settings( erankly_merge_settings_submission( array(), 'features' ) );
		$this->assertSame( 0, $off['enable_multilingual'] );
		$on = erankly_sanitize_settings( erankly_merge_settings_submission( array( 'enable_multilingual' => 1 ), 'features' ) );
		$this->assertSame( 1, $on['enable_multilingual'] );
		erankly_tests_set_settings( $off );
		$this->assertFalse( ( new ERankly_MLSS_Provider() )->is_enabled() );
	}

	public function test_indexed_storage_uses_one_row_per_member_and_does_not_copy_maps(): void {
		global $wpdb;
		$ids = $this->posts( 3 );
		$this->link( $ids );
		$this->assertSame( 3, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . erankly_mlss_table() ) );
		foreach ( $ids as $id ) { $this->assertFalse( metadata_exists( 'post', $id, ERANKLY_MLSS_META_KEY ) ); }
		$this->assertArrayNotHasKey( 'erankly_multilingual_schema', wp_load_alloptions( true ) );
	}

	public function test_batch_language_lookup_uses_one_query_for_many_objects(): void {
		$ids = $this->posts( 20 );
		foreach ( $ids as $id ) { $this->assertTrue( erankly_mlss_set_language( 'post', $id, 'en-us' ) ); wp_cache_delete( 'post:' . $id, 'erankly_languages' ); }
		$count = 0;
		$observe = static function ( $sql ) use ( &$count ) { if ( str_starts_with( $sql, 'SELECT object_id, language, group_id FROM ' . erankly_mlss_table() ) ) { ++$count; } return $sql; };
		add_filter( 'query', $observe );
		try {
			erankly_mlss_prime( 'post', $ids );
			foreach ( $ids as $id ) { $this->assertSame( 'en-us', erankly_mlss_get_language( 'post', $id ) ); }
		} finally { remove_filter( 'query', $observe ); }
		$this->assertSame( 1, $count );
	}

	public function test_native_queries_select_language_and_retain_unassigned_default_content(): void {
		$ids = $this->posts( 3 );
		$this->link( array_slice( $ids, 0, 2 ) );
		$args = array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' );
		$this->assertSame( array( $ids[0], $ids[2] ), ( new WP_Query( $args + array( 'erankly_lang' => 'it-it' ) ) )->posts );
		$query = new WP_Query( $args + array( 'erankly_lang' => 'en-us' ) );
		$this->assertSame( array( $ids[1] ), $query->posts );
		$this->assertStringContainsString( 'INNER JOIN ' . erankly_mlss_table(), $query->request );
		$this->assertSame( $ids, ( new WP_Query( $args + array( 'erankly_lang' => 'all' ) ) )->posts );
	}

	public function test_pretty_routes_preserve_default_urls_and_select_translated_posts(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ids = $this->posts();
		$this->link( $ids );
		flush_rewrite_rules( false );
		$this->assertStringNotContainsString( '/it-it/', get_permalink( $ids[0] ) );
		$this->assertStringContainsString( '/en-us/', get_permalink( $ids[1] ) );
		$this->go_to( get_permalink( $ids[1] ) );
		$this->assertTrue( is_single() );
		$this->assertSame( $ids[1], get_queried_object_id() );
		$this->assertSame( 'en-us', erankly_mlss_current_language() );
		$this->assertSame( 'en_US', get_locale() );
	}

	public function test_plain_routes_use_language_query_parameters(): void {
		$ids = $this->posts();
		$this->link( $ids );
		$this->assertStringContainsString( 'erankly_lang=en-us', get_permalink( $ids[1] ) );
		$this->go_to( get_permalink( $ids[1] ) );
		$this->assertSame( $ids[1], get_queried_object_id() );
		$this->assertSame( 'en-us', erankly_mlss_current_language() );
	}

	public function test_language_rules_scale_with_rule_count_instead_of_language_count(): void {
		$rules = array( 'article/([^/]+)/?$' => 'index.php?name=$matches[1]', 'wp-sitemap.xml$' => 'index.php?sitemap=index' );
		$result = erankly_mlss_rewrite_rules( $rules );
		$this->assertCount( 4, $result );
		$this->assertSame( 'index.php?name=$matches[2]&erankly_lang_slug=$matches[1]', $result['(it\-it|en\-us|fr\-fr)/article/([^/]+)/?$'] );
		$this->assertArrayNotHasKey( '(it\-it|en\-us|fr\-fr)/wp-sitemap.xml$', $result );
	}

	public function test_language_urls_are_idempotent_and_preserve_query_fragment_and_external_urls(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$url = home_url( '/en-us/story/?campaign=one#section' );
		$this->assertSame( $url, erankly_mlss_url( $url, 'en-us' ) );
		$this->assertSame( home_url( '/fr-fr/story/?campaign=one#section' ), erankly_mlss_url( $url, 'fr-fr' ) );
		$this->assertSame( home_url( '/story/?campaign=one#section' ), erankly_mlss_url( $url, 'it-it' ) );
		$this->assertSame( 'https://example.net/story/', erankly_mlss_url( 'https://example.net/story/', 'en-us' ) );
		$this->assertSame( rest_url(), erankly_mlss_url( rest_url(), 'en-us' ) );
	}

	public function test_custom_url_prefixes_route_content_without_changing_language_or_seo_tags(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ids = $this->posts();
		$this->link( $ids );
		$this->assertTrue( erankly_mlss_save_settings( array(
			'languages' => array( 'it-it', 'en-us', 'fr-fr' ), 'prefix_default' => 1,
			'url_slugs' => array( 'it-it' => '/it/', 'en-us' => '/en/', 'fr-fr' => '/fr/' ),
		) ) );
		flush_rewrite_rules( false );
		$this->assertStringContainsString( '/it/', get_permalink( $ids[0] ) );
		$this->assertStringContainsString( '/en/', get_permalink( $ids[1] ) );
		$this->go_to( get_permalink( $ids[1] ) );
		$this->assertSame( $ids[1], get_queried_object_id() );
		$this->assertSame( 'en-us', get_query_var( 'erankly_lang' ) );
		$this->assertSame( 'en_US', get_locale() );
		$alternates = ( new ERankly_MLSS_Provider() )->get_alternates( array(), false );
		$this->assertSame( get_permalink( $ids[0] ), $alternates['it-it'] );
		$this->assertSame( get_permalink( $ids[1] ), $alternates['en-us'] );
		$this->go_to( erankly_mlss_base_home_url( '/en/' ) );
		$this->assertTrue( is_home() );
		$this->assertSame( 'en-us', erankly_mlss_current_language() );
	}

	public function test_url_prefix_validation_rejects_duplicates_invalid_and_reserved_paths_without_saving(): void {
		$previous = erankly_mlss_get_settings();
		foreach ( array( '/wp-admin/', '/wp-json/', '/it/nested/', 'https://example.org/it/', array( 'it' ) ) as $slug ) {
			$result = erankly_mlss_save_settings( array_replace( $previous, array( 'url_slugs' => array( 'it-it' => $slug ) ) ) );
			$this->assertWPError( $result );
			$this->assertSame( 'erankly_mlss_invalid_url_slug', $result->get_error_code() );
			$this->assertSame( $previous, erankly_mlss_get_settings() );
		}
		$result = erankly_mlss_save_settings( array_replace( $previous, array( 'url_slugs' => array( 'it-it' => '/EN/', 'en-us' => 'en' ) ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'erankly_mlss_duplicate_url_slug', $result->get_error_code() );
		$this->assertSame( $previous, erankly_mlss_get_settings() );
	}

	public function test_custom_prefixes_replace_each_other_and_keep_plain_query_language_tags(): void {
		$settings = erankly_mlss_get_settings();
		$this->assertSame( array_combine( $settings['languages'], $settings['languages'] ), $settings['url_slugs'] );
		$this->assertTrue( erankly_mlss_save_settings( array_replace( $settings, array( 'url_slugs' => array( 'it-it' => '/it/', 'en-us' => '/en/' ) ) ) ) );
		$this->set_permalink_structure( '/%postname%/' );
		$url = erankly_mlss_base_home_url( '/en/story/?campaign=one#section' );
		$this->assertSame( $url, erankly_mlss_url( $url, 'en-us' ) );
		$this->assertSame( erankly_mlss_base_home_url( '/story/?campaign=one#section' ), erankly_mlss_url( $url, 'it-it' ) );
		$this->assertSame( erankly_mlss_base_home_url( '/fr-fr/story/?campaign=one#section' ), erankly_mlss_url( $url, 'fr-fr' ) );
		$this->set_permalink_structure( '' );
		$url = erankly_mlss_url( erankly_mlss_base_home_url( '/?p=123' ), 'en-us' );
		$this->assertStringContainsString( 'erankly_lang=en-us', $url );
		$this->assertStringNotContainsString( '/en/', $url );
	}

	public function test_wrong_language_and_unknown_language_routes_are_404(): void {
		$ids = $this->posts();
		$this->link( $ids );
		$this->go_to( add_query_arg( 'erankly_lang', 'it-it', get_permalink( $ids[1] ) ) );
		$this->assertTrue( is_404() );
		$this->go_to( add_query_arg( 'erankly_lang', 'de-de', get_permalink( $ids[0] ) ) );
		$this->assertTrue( is_404() );
		$this->assertSame( array(), ( new ERankly_MLSS_Provider() )->get_alternates( array(), false ) );
	}

	public function test_translated_static_homepage_uses_root_language_url(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$it = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$en = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $it );
		$this->assertTrue( erankly_mlss_set_translations( 'post', $it, array( 'it-it' => $it, 'en-us' => $en ) ) );
		flush_rewrite_rules( false );
		$this->assertSame( home_url( '/en-us/' ), get_permalink( $en ) );
		$this->go_to( home_url( '/en-us/' ) );
		$this->assertTrue( is_front_page() );
		$this->assertSame( $en, get_queried_object_id() );
		$this->assertSame( erankly_mlss_base_home_url( '/' ), get_permalink( $it ) );
		$this->go_to( home_url( '/fr-fr/' ) );
		$this->assertTrue( is_404() );
	}

	public function test_dynamic_home_search_and_pagination_filter_the_language(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ids = $this->posts( 3 ); $this->link( $ids );
		wp_update_post( array( 'ID' => $ids[1], 'post_title' => 'Translated search keyword' ) );
		flush_rewrite_rules( false );
		$this->go_to( home_url( '/en-us/' ) );
		$this->assertTrue( is_home() );
		$this->assertSame( array( $ids[1] ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertStringContainsString( '/en-us/', get_search_link( 'keyword' ) );
		$this->assertStringContainsString( '/en-us/', get_pagenum_link( 2 ) );
		$this->go_to( get_search_link( 'keyword' ) );
		$this->assertTrue( is_search() );
		$this->assertSame( array( $ids[1] ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	public function test_term_queries_and_term_routes_select_language(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$it = (int) self::factory()->category->create(); $en = (int) self::factory()->category->create();
		$this->assertTrue( erankly_mlss_set_translations( 'term', $it, array( 'it-it' => $it, 'en-us' => $en ) ) );
		$terms = get_terms( array( 'taxonomy' => 'category', 'fields' => 'ids', 'hide_empty' => false, 'erankly_lang' => 'en-us' ) );
		$this->assertSame( array( $en ), array_map( 'intval', $terms ) );
		flush_rewrite_rules( false );
		$this->go_to( get_term_link( $en ) );
		$this->assertTrue( is_category() );
		$this->assertSame( $en, get_queried_object_id() );
	}

	public function test_creation_produces_a_linked_draft_and_shares_media_without_copying_canonical(): void {
		$id = $this->posts( 1 )[0];
		wp_update_post( array( 'ID' => $id, 'post_content' => '<!-- wp:paragraph --><p>A "quoted" text.</p><!-- /wp:paragraph -->' ) );
		update_post_meta( $id, '_thumbnail_id', 567 );
		update_post_meta( $id, '_erankly_canonical', 'https://example.net/original/' );
		$en = erankly_mlss_create_translation( 'post', $id, 'en-us' );
		$this->assertIsInt( $en );
		$this->assertSame( 'draft', get_post_status( $en ) );
		$this->assertSame( get_post_field( 'post_content', $id ), get_post_field( 'post_content', $en ) );
		$this->assertSame( '567', get_post_meta( $en, '_thumbnail_id', true ) );
		$this->assertFalse( metadata_exists( 'post', $en, '_erankly_canonical' ) );
		$this->assertSame( $en, erankly_mlss_get_translations( 'post', $id )['en-us'] );
		$this->assertWPError( erankly_mlss_create_translation( 'post', $id, 'en-us' ) );
	}

	public function test_creation_maps_translated_parent_and_taxonomy_terms(): void {
		$parent = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );
		$parent_en = erankly_mlss_create_translation( 'post', $parent, 'en-us' );
		$child = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_parent' => $parent ) );
		$child_en = erankly_mlss_create_translation( 'post', $child, 'en-us' );
		$this->assertSame( $parent_en, get_post( $child_en )->post_parent );
		$term = (int) self::factory()->category->create();
		$term_en = erankly_mlss_create_translation( 'term', $term, 'en-us' );
		$this->assertIsInt( $term_en );
		$id = $this->posts( 1 )[0]; wp_set_post_categories( $id, array( $term ) );
		$en = erankly_mlss_create_translation( 'post', $id, 'en-us' );
		$this->assertSame( array( $term_en ), wp_get_object_terms( $en, 'category', array( 'fields' => 'ids', 'erankly_lang' => 'all' ) ) );
	}

	public function test_failed_linking_removes_the_new_draft_and_preserves_the_source(): void {
		$id = $this->posts( 1 )[0];
		$before = wp_count_posts()->draft;
		$fail = static fn( $sql ) => str_starts_with( $sql, 'INSERT INTO ' . erankly_mlss_table() . ' ' ) ? '' : $sql;
		add_filter( 'query', $fail );
		try { $result = erankly_mlss_create_translation( 'post', $id, 'en-us' ); }
		finally { remove_filter( 'query', $fail ); }
		$this->assertWPError( $result );
		$this->assertSame( $before, wp_count_posts()->draft );
		$this->assertSame( array( 'it-it' => $id ), erankly_mlss_get_translations( 'post', $id ) );
	}

	public function test_rest_creation_and_search_require_object_capabilities(): void {
		$id = $this->posts( 1 )[0]; do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'POST', '/erankly/v1/multilingual/singlesite/create/post/' . $id );
		$request['language'] = 'en-us';
		$admin = get_current_user_id(); wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		wp_set_current_user( $admin );
		$response = rest_do_request( $request ); $this->assertSame( 201, $response->get_status() );
		$search = new WP_REST_Request( 'GET', '/erankly/v1/multilingual/singlesite/objects' );
		$search->set_query_params( array( 'kind' => 'post', 'subtype' => 'post', 'language' => 'en-us' ) );
		$this->assertSame( $response->get_data()['id'], rest_do_request( $search )->get_data()[0]['id'] );
	}

	public function test_native_rest_lists_can_select_language_and_language_assignment_is_writable(): void {
		$ids = $this->posts(); $this->link( $ids ); do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' ); $request['erankly_lang'] = 'en-us';
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $ids[1] ), wp_list_pluck( $response->get_data(), 'id' ) );
		$this->assertSame( 'en-us', $response->get_data()[0]['erankly_language'] );
		$id = $this->posts( 1 )[0];
		$update = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id ); $update['erankly_language'] = 'fr-fr';
		$this->assertSame( 200, rest_do_request( $update )->get_status() );
		$this->assertSame( 'fr-fr', erankly_mlss_get_language( 'post', $id ) );
	}

	public function test_settings_prevent_removing_languages_assigned_to_content(): void {
		$id = $this->posts( 1 )[0]; $this->assertTrue( erankly_mlss_set_language( 'post', $id, 'en-us' ) );
		$result = erankly_mlss_save_settings( array( 'languages' => array( 'it-it' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'erankly_mlss_language_in_use', $result->get_error_code() );
		$this->assertContains( 'en-us', erankly_mlss_get_settings()['languages'] );
	}

	public function test_shared_strings_are_explicit_and_language_dictionaries_are_not_autoloaded(): void {
		$this->assertTrue( erankly_mlss_save_strings( array( 'en-us' => array( 'site_title' => 'English site', 'cta' => 'Read more' ) ), array( 'en-us' ) ) );
		$this->assertSame( 'Read more', erankly_mlss_translate_string( 'cta', 'Leggi di più', 'en-us' ) );
		$this->assertSame( 'Source', erankly_mlss_translate_string( 'missing', 'Source', 'en-us' ) );
		$this->assertSame( 'Originale', erankly_mlss_translate_string( 'cta', 'Originale', 'it-it' ) );
		$this->assertArrayNotHasKey( 'erankly_multilingual_strings_en-us', wp_load_alloptions( true ) );
		$this->go_to( home_url( '/?erankly_lang=en-us' ) );
		$this->assertSame( 'English site', get_bloginfo( 'name' ) );
	}

	public function test_language_switcher_contains_public_equivalents_and_excludes_drafts(): void {
		$ids = $this->posts( 3 ); $this->link( $ids );
		wp_update_post( array( 'ID' => $ids[2], 'post_status' => 'draft' ) );
		$this->go_to( get_permalink( $ids[0] ) );
		$html = erankly_mlss_switcher();
		$this->assertStringContainsString( 'hreflang="en-us"', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringNotContainsString( 'hreflang="fr-fr"', $html );
		$this->assertStringNotContainsString( 'x-default', $html );
	}

	public function test_navigation_blocks_resolve_translated_object_references(): void {
		$ids = $this->posts(); $this->link( $ids ); $this->go_to( get_permalink( $ids[1] ) );
		$block = array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'kind' => 'post-type', 'id' => $ids[0], 'label' => 'Original' ) );
		$translated = erankly_mlss_block_data( $block );
		$this->assertSame( $ids[1], $translated['attrs']['id'] );
		$this->assertSame( get_permalink( $ids[1] ), $translated['attrs']['url'] );
	}

	public function test_sitemaps_contain_every_language_and_dynamic_homepage(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ids = $this->posts(); $this->link( $ids );
		$this->go_to( get_permalink( $ids[1] ) );
		$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
		$list = $provider->get_url_list( 1, 'post' );
		$this->assertContains( get_permalink( $ids[0] ), array_column( $list, 'loc' ) );
		$this->assertContains( get_permalink( $ids[1] ), array_column( $list, 'loc' ) );
		$pages = $provider->get_url_list( 1, 'page' );
		foreach ( erankly_mlss_get_settings()['languages'] as $language ) { $this->assertContains( erankly_mlss_home_url( $language ), array_column( $pages, 'loc' ) ); }
	}

	public function test_legacy_maps_migrate_to_one_indexed_membership_per_object(): void {
		$ids = $this->posts();
		$map = array( 'en-us' => $ids[1], 'it-it' => $ids[0] );
		foreach ( $ids as $id ) { update_post_meta( $id, ERANKLY_MLSS_META_KEY, $map ); }
		erankly_mlss_migrate_legacy();
		foreach ( $ids as $id ) {
			$this->assertSame( $map, erankly_mlss_get_translations( 'post', $id ) );
			$this->assertFalse( metadata_exists( 'post', $id, ERANKLY_MLSS_META_KEY ) );
		}
	}

	public function test_disabled_module_restores_native_urls_and_unfiltered_queries(): void {
		$ids = $this->posts(); $this->link( $ids );
		update_option( 'rewrite_rules', array( 'test' => 'index.php?erankly_lang=en-us' ) );
		erankly_tests_set_settings( array( 'enable_multilingual' => 0 ) );
		$this->assertFalse( get_option( 'rewrite_rules' ) );
		$this->assertStringNotContainsString( 'erankly_lang=', get_permalink( $ids[1] ) );
		$query = new WP_Query( array( 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1, 'erankly_lang' => 'en-us' ) );
		$this->assertCount( 2, $query->posts );
		$this->assertSame( '', erankly_mlss_switcher() );
		$this->assertSame( array( 'test' => 'index.php?name=test' ), erankly_mlss_rewrite_rules( array( 'test' => 'index.php?name=test' ) ) );
	}
	public function test_busy_translation_group_cannot_create_duplicate_drafts(): void {
		$id = $this->posts( 1 )[0];
		require_once ERANKLY_PATH . 'includes/class-erankly-job-lease.php';
		$key = erankly_mlss_group_lock_key( 'post', $id );
		$token = ERankly_Job_Lease::acquire( 'erankly_multilingual_lock_', $key );
		$before = wp_count_posts()->draft;
		try { $result = erankly_mlss_create_translation( 'post', $id, 'en-us' ); }
		finally { ERankly_Job_Lease::release( 'erankly_multilingual_lock_', $key, $token ); }
		$this->assertWPError( $result );
		$this->assertSame( 'erankly_mlss_busy', $result->get_error_code() );
		$this->assertSame( $before, wp_count_posts()->draft );
		$this->assertArrayNotHasKey( 'en-us', erankly_mlss_get_translations( 'post', $id ) );
	}

	public function test_native_home_links_localize_after_routing_and_explicit_canonicals_are_preserved(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$ids = $this->posts(); $this->link( $ids );
		$base = erankly_mlss_base_home_url( '/' );
		$this->go_to( get_permalink( $ids[1] ) );
		$this->assertSame( $base . 'en-us/', home_url( '/' ) );
		$this->assertSame( $base, erankly_mlss_base_home_url( '/' ) );
		$provider = new ERankly_MLSS_Provider();
		$this->assertSame( $base . 'alternate/', $provider->localize_url( $base . 'alternate/', array() ) );
		$this->go_to( get_permalink( $ids[0] ) );
		$this->assertSame( $base, home_url( '/' ) );
	}

	public function test_navigation_does_not_reveal_draft_translation_titles(): void {
		$ids = $this->posts(); $this->link( $ids );
		wp_update_post( array( 'ID' => $ids[1], 'post_status' => 'draft', 'post_title' => 'Private draft translation' ) );
		$this->go_to( erankly_mlss_home_url( 'en-us' ) );
		$block = array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'kind' => 'post-type', 'id' => $ids[0], 'label' => 'Original' ) );
		$this->assertSame( $block, erankly_mlss_block_data( $block ) );
	}

	public function test_legacy_migration_rejects_mixed_subtypes_without_losing_language_assignments(): void {
		$post = $this->posts( 1 )[0];
		$page = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );
		$map = array( 'it-it' => $post, 'en-us' => $page );
		foreach ( $map as $id ) { update_post_meta( $id, ERANKLY_MLSS_META_KEY, $map ); }
		erankly_mlss_migrate_legacy();
		$this->assertSame( array( 'it-it' => $post ), erankly_mlss_get_translations( 'post', $post ) );
		$this->assertSame( array( 'en-us' => $page ), erankly_mlss_get_translations( 'post', $page ) );
	}

}
