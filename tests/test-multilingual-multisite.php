<?php
/** Tests for the EasyRankly Multilingual for Multisite provider and relationships. */

class ERANKLY_MLMS_Test extends WP_UnitTestCase {
	private ?ReflectionProperty $registry_property = null;
	private $original_registry;

	/** Runs a test case only on the Multisite suite. */
	private function require_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test: run the suite with WP_TESTS_MULTISITE=1.' );
		}
	}

	protected function setUp(): void {
		parent::setUp();

		$this->require_multisite();
		require_once ERANKLY_PATH . 'includes/multilingual/multisite/bootstrap.php';
		erankly_tests_set_settings( array( 'enable_multilingual' => 1 ) );
		erankly_mlms_flush_runtime_caches();
		delete_site_option( 'erml_ms_settings' );
		$this->registry_property = new ReflectionProperty( ERankly_Multilingual_Provider_Registry::class, 'instance' );
		$this->registry_property->setAccessible( true );
		$this->original_registry = $this->registry_property->getValue();
		$this->registry_property->setValue( null, null );
		erankly_mlms_boot();
		erankly_close_multilingual_provider_registry();
		erankly_mlms_register_rest_meta();
	}

	protected function tearDown(): void {
		if ( is_multisite() && $this->registry_property ) {
			erankly_mlms_flush_runtime_caches();
			delete_site_option( 'erml_ms_settings' );
			$this->registry_property->setValue( null, $this->original_registry );
		}
		parent::tearDown();
	}

	/** Enables the module with explicit per-site languages. */
	private function enable_module( array $sites = array() ): void {
		$settings          = erankly_mlms_default_settings();
		$settings['sites'] = $sites;

		update_site_option( 'erml_ms_settings', erankly_mlms_sanitize_settings( $settings ) );
		erankly_mlms_flush_runtime_caches();
	}

	public function test_locale_to_hreflang(): void {
		$this->assertSame( 'it-it', erankly_mlms_locale_to_hreflang( 'it_IT' ) );
		$this->assertSame( 'en-us', erankly_mlms_locale_to_hreflang( 'en_US' ) );
		$this->assertSame( 'de-ch-informal', erankly_mlms_locale_to_hreflang( 'de_CH_informal' ) );
		$this->assertSame( 'es-419', erankly_mlms_locale_to_hreflang( 'es_419' ) );
		$this->assertSame( '', erankly_mlms_locale_to_hreflang( '' ) );
		$this->assertSame( '', erankly_mlms_locale_to_hreflang( '   ' ) );
	}

	/**
	 * A locale the permissive locale validator accepts can still derive a tag EasyRankly's own grammar rejects.
	 * Such a tag must resolve to '' here rather than being displayed and then silently dropped from the head.
	 */
	public function test_locale_to_hreflang_rejects_tags_the_host_would_drop(): void {
		if ( ! function_exists( 'erankly_is_valid_hreflang_tag' ) ) {
			$this->markTestSkipped( 'Requires the EasyRankly host plugin.' );
		}

		// Subtag longer than the 8 characters BCP 47 allows: accepted as a locale, invalid as a tag.
		$this->assertSame( 'it_ABCDEFGHIJ', erankly_mlms_sanitize_locale( 'it_ABCDEFGHIJ' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( 'it-abcdefghij' ) );
		$this->assertSame( '', erankly_mlms_locale_to_hreflang( 'it_ABCDEFGHIJ' ) );
	}

	public function test_sanitize_locale(): void {
		$this->assertSame( 'it_IT', erankly_mlms_sanitize_locale( 'it_IT' ) );
		$this->assertSame( 'de_CH_informal', erankly_mlms_sanitize_locale( ' de_CH_informal ' ) );
		$this->assertSame( '', erankly_mlms_sanitize_locale( 'it; drop table' ) );
		$this->assertSame( '', erankly_mlms_sanitize_locale( 'i' ) );
		$this->assertSame( '', erankly_mlms_sanitize_locale( '' ) );
	}

	public function test_sanitize_settings_filters_junk(): void {
		$settings = erankly_mlms_sanitize_settings(
			array(
				'x_default_blog' => '999999',
				'sites'          => array(
					'1'        => array( 'language' => 'it_IT', 'enabled' => '1' ),
					'not-a-blog' => array( 'language' => 'en_US', 'enabled' => '1' ),
					'0'        => array( 'language' => 'fr_FR', 'enabled' => '1' ),
				),
				'post_types'     => array( 'post', 'attachment', 'hacker-type' ),
				'taxonomies'     => array( 'category', 'post_format', 'nav_menu' ),
			)
		);

		$this->assertSame( 0, $settings['x_default_blog'] ); // Unknown site collapses to "none".
		$this->assertArrayHasKey( 1, $settings['sites'] );
		$this->assertArrayNotHasKey( 0, $settings['sites'] );
		$this->assertArrayNotHasKey( 'not-a-blog', $settings['sites'] );
		$this->assertSame( 'it_IT', $settings['sites'][1]['language'] );
		$this->assertNotContains( 'attachment', $settings['post_types'] );
		$this->assertContains( 'post', $settings['post_types'] );
		$this->assertNotContains( 'post_format', $settings['taxonomies'] );
		$this->assertContains( 'category', $settings['taxonomies'] );
	}

	public function test_sanitize_settings_rejects_malformed_locales(): void {
		$settings = erankly_mlms_sanitize_settings(
			array(
				'sites' => array(
					'1' => array( 'language' => "it_IT'); --", 'enabled' => '1' ),
				),
			)
		);

		$this->assertSame( '', $settings['sites'][1]['language'] );
	}

	public function test_provider_contract(): void {
		$this->require_multisite();

		$provider = erankly_get_multilingual_provider();

		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );
		$this->assertSame( 'multisite', $provider->get_id() );
		$this->assertSame( ERANKLY_MLMS_VERSION, $provider->get_version() );
		$this->assertSame( ERANKLY_EXTENSION_API_VERSION, $provider->get_api_version() );
		$this->assertSame( 'multisite', $provider->get_topology() );
		$this->assertTrue( $provider->preflight() );
		$this->assertTrue( $provider->is_enabled() ); // On Multisite the add-on is active by definition.
	}

	public function test_single_site_cluster_produces_no_alternates(): void {
		$this->require_multisite();

		// Deterministic one-site cluster: every other public site opts out of participation.
		$sites = array();

		foreach ( erankly_mlms_get_network_sites() as $site ) {
			$sites[ $site['blog_id'] ] = array(
				'language' => 'en_US',
				'enabled'  => get_main_site_id() === $site['blog_id'] ? 1 : 0,
			);
		}

		$this->enable_module( $sites );

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );
		$this->assertTrue( $provider->is_enabled() );
		$this->assertSame( array(), $provider->get_alternates( array( 'kind' => 'front_page', 'blog_id' => get_main_site_id() ), false ) );
	}

	public function test_front_page_alternates(): void {
		$this->require_multisite();

		$main  = get_main_site_id();
		$other = (int) self::factory()->blog->create();
		$this->enable_module(
			array(
				$main  => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$other => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );
		$this->assertTrue( $provider->is_enabled() );

		$this->go_to( get_home_url( $main, '/' ) );
		$alternates = $provider->get_alternates(
			array( 'kind' => 'front_page', 'blog_id' => $main ),
			false
		);

		$this->assertArrayHasKey( 'it-it', $alternates );
		$this->assertArrayHasKey( 'en-us', $alternates );
		$this->assertArrayHasKey( 'x-default', $alternates );
		$this->assertSame( untrailingslashit( get_home_url( $main ) ) . '/', $alternates['it-it'] );
		$this->assertSame( untrailingslashit( get_home_url( $other ) ) . '/', $alternates['en-us'] );
		$this->assertSame( $alternates['it-it'], $alternates['x-default'] ); // Main site is x-default.
	}

	public function test_duplicate_hreflang_keeps_lowest_blog_id(): void {
		$this->require_multisite();

		$main  = get_main_site_id();
		$other = (int) self::factory()->blog->create();
		$this->enable_module(
			array(
				$main  => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$other => array( 'language' => 'it_IT', 'enabled' => 1 ),
			)
		);

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );

		$this->go_to( get_home_url( $main, '/' ) );
		$alternates = $provider->get_alternates( array( 'kind' => 'front_page', 'blog_id' => $main ), false );

		$this->assertCount( 2, $alternates ); // One hreflang + x-default; the duplicate collapses.
		$this->assertSame( get_home_url( $main, '/' ), $alternates['it-it'] );

		$diagnostics = wp_list_pluck( erankly_get_multilingual_diagnostics(), 'code' );
		$this->assertContains( 'erankly_mlms_duplicate_hreflang', $diagnostics );
	}

	public function test_post_relationships_sync_both_sides(): void {
		$this->require_multisite();

		$main = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create( array( 'post_title' => 'Original' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create( array( 'post_title' => 'Translation' ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );

		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a_id, $main ) );

		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $a_id ), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		// Unlinking clears both sides.
		erankly_mlms_set_post_translations( $a_id, array() );

		$this->assertSame( array(), erankly_mlms_get_post_translations( $a_id, $main ) );

		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array(), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_reassignment_displaces_stale_link(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a1 = (int) self::factory()->post->create();
		$a2 = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_post_translations( $a1, array( $blog_b => $b_id ) );
		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a1, $main ) );

		// Reassign the counterpart to a2: a1 must lose the stale link, b1 must point at a2 only.
		erankly_mlms_set_post_translations( $a2, array( $blog_b => $b_id ) );

		$this->assertSame( array(), erankly_mlms_get_post_translations( $a1, $main ) );
		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a2, $main ) );

		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $a2 ), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_post_deletion_removes_counterpart_links(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );

		wp_delete_post( $a_id, true );

		$this->assertSame( array(), erankly_mlms_get_post_translations( $a_id, $main ) );

		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array(), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_singular_alternates_with_linked_translation(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$a_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$b_url = (string) get_permalink( $b_id );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );

		// No link yet: no alternates at all.
		$this->assertSame(
			array(),
			$provider->get_alternates( array( 'kind' => 'singular', 'blog_id' => $main, 'object_id' => $a_id ), false )
		);

		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );

		$this->go_to( get_permalink( $a_id ) );
		$alternates = $provider->get_alternates( array( 'kind' => 'singular', 'blog_id' => $main, 'object_id' => $a_id ), false );

		$this->assertArrayHasKey( 'it-it', $alternates );
		$this->assertArrayHasKey( 'en-us', $alternates );
		$this->assertArrayHasKey( 'x-default', $alternates );
		$this->assertSame( $b_url, $alternates['en-us'] );
		$this->assertSame( $alternates['it-it'], $alternates['x-default'] ); // Default site is the main site.

		// Draft translations never enter the cluster.
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		wp_update_post( array( 'ID' => $b_id, 'post_status' => 'draft' ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
		erankly_mlms_flush_runtime_caches();

		$this->assertSame(
			array(),
			$provider->get_alternates( array( 'kind' => 'singular', 'blog_id' => $main, 'object_id' => $a_id ), false )
		);
	}

	public function test_noindexed_translation_excluded_from_hreflang_but_not_from_navigable(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$a_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$b_url = (string) get_permalink( $b_id );
		update_post_meta( $b_id, '_erankly_index_directive', 'noindex' );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );

		$this->go_to( get_permalink( $a_id ) );
		$hreflang_set = $provider->get_alternates( array( 'kind' => 'singular', 'blog_id' => $main, 'object_id' => $a_id ), false );
		$navigable    = $provider->get_alternates( array( 'kind' => 'singular', 'blog_id' => $main, 'object_id' => $a_id ), true );

		$this->assertArrayNotHasKey( 'en-us', $hreflang_set );
		$this->assertSame( array(), $hreflang_set ); // One eligible language does not form a cluster.
		$this->assertArrayHasKey( 'en-us', $navigable );
		$this->assertSame( $b_url, $navigable['en-us'] );
	}

	public function test_term_relationships_sync(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$category_a = (int) self::factory()->category->create( array( 'name' => 'News' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$category_b = (int) self::factory()->category->create( array( 'name' => 'News EN' ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_term_translations( $category_a, array( $blog_b => $category_b ) );

		$this->assertSame( array( $blog_b => $category_b ), erankly_mlms_get_term_translations( $category_a, $main ) );

		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $category_a ), erankly_mlms_get_term_translations( $category_b ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_term_alternates(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$category_a = (int) self::factory()->category->create( array( 'name' => 'News', 'slug' => 'news' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$category_b = (int) self::factory()->category->create( array( 'name' => 'News EN', 'slug' => 'news-en' ) );
		$category_b_url = (string) get_term_link( $category_b );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_term_translations( $category_a, array( $blog_b => $category_b ) );

		$provider = erankly_get_multilingual_provider();
		$this->assertInstanceOf( ERANKLY_MLMS_Provider::class, $provider );

		$this->go_to( get_term_link( $category_a ) );
		$alternates = $provider->get_alternates( array( 'kind' => 'term', 'blog_id' => $main, 'object_id' => $category_a ), false );

		$this->assertArrayHasKey( 'it-it', $alternates );
		$this->assertArrayHasKey( 'en-us', $alternates );
		$this->assertSame( $category_b_url, $alternates['en-us'] );
	}

	public function test_unlink_fires_no_save_post_recursion(): void {
		$this->require_multisite();

		$blog_b = (int) self::factory()->blog->create();
		$a_id   = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );

		$saves = array();
		add_action(
			'save_post',
			static function ( int $post_id ) use ( &$saves ): void {
				$saves[] = $post_id;
			},
			10,
			1
		);

		erankly_mlms_set_post_translations( $a_id, array() );

		$this->assertSame( array(), $saves, 'Counterpart synchronization must write meta only, never trigger save_post.' );
	}

	public function test_rest_meta_is_registered_for_linkable_types(): void {
		$this->enable_module();

		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( '_erankly_mlms_translations', $registered );
		$this->assertTrue( (bool) $registered['_erankly_mlms_translations']['show_in_rest'] );
	}

	public function test_external_meta_write_synchronizes_counterparts(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		// A raw meta write (what a REST / block editor save produces) must sync the counterpart side.
		update_post_meta( $a_id, ERANKLY_MLMS_POST_META_KEY, array( (string) $blog_b => $b_id ) );

		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a_id, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $a_id ), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		// A raw meta deletion must unlink both sides.
		delete_post_meta( $a_id, ERANKLY_MLMS_POST_META_KEY );

		$this->assertSame( array(), erankly_mlms_get_post_translations( $a_id, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array(), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_external_meta_write_cannot_touch_sites_the_user_cannot_edit(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		// An editor that is a member of the main site only cannot create links towards blog B through raw meta
		// writes: non-members hold no caps on a site they do not belong to.
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_to_blog( $main, $editor, 'editor' );
		wp_set_current_user( $editor );

		update_post_meta( $a_id, ERANKLY_MLMS_POST_META_KEY, array( (string) $blog_b => $b_id ) );

		// The uneditable target is dropped from the map and the counterpart is never written.
		$this->assertSame( array(), erankly_mlms_get_post_translations( $a_id, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array(), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	public function test_settings_autosave_route_persists_the_payload(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( 'POST', '/erankly/v1/multilingual/multisite/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'settings' => array(
						'sites'          => array(
							(string) $main   => array( 'language' => 'it_IT', 'enabled' => '1' ),
							// An unchecked toggle posts an empty string, not a missing key.
							(string) $blog_b => array( 'language' => 'en_US', 'enabled' => '' ),
						),
						'x_default_blog' => (string) $main,
						'post_types'     => array( 'post' ),
						'taxonomies'     => array( 'category' ),
					),
				)
			)
		);

		// Without the network capability the payload must not reach the option.
		$this->assertSame( 401, rest_do_request( $request )->get_status() );
		$this->assertFalse( get_site_option( 'erml_ms_settings' ) );

		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );

		erankly_mlms_flush_runtime_caches();
		$stored = erankly_mlms_get_settings();

		$this->assertSame( 'it_IT', $stored['sites'][ $main ]['language'] );
		$this->assertSame( 1, $stored['sites'][ $main ]['enabled'] );
		$this->assertSame( 0, $stored['sites'][ $blog_b ]['enabled'] );
		$this->assertSame( $main, $stored['x_default_blog'] );
		$this->assertSame( array( 'post' ), $stored['post_types'] );
		$this->assertSame( array( 'category' ), $stored['taxonomies'] );
	}

	/**
	 * Regression: the capability filter computed the right map but erankly_mlms_set_translations() skipped the write
	 * whenever that map equalled the pre-write snapshot. On the external-write path the row already held the
	 * rejected value, so it survived. The write decision is now made against the stored row.
	 */
	public function test_rejected_external_write_is_rolled_back_in_the_database(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_to_blog( $main, $editor, 'editor' );
		wp_set_current_user( $editor );

		update_post_meta( $a_id, ERANKLY_MLMS_POST_META_KEY, array( (string) $blog_b => $b_id ) );

		// Asserted against the stored row, not the normalized reader: the bug was the row keeping a value the
		// reader would happily report. metadata_exists() is used rather than get_post_meta(), which returns the
		// registered default array() for a missing row and so cannot distinguish the two states.
		$this->assertFalse( metadata_exists( 'post', $a_id, ERANKLY_MLMS_POST_META_KEY ) );
		erankly_mlms_flush_runtime_caches();
		$this->assertSame( array(), erankly_mlms_get_post_translations( $a_id, $main ) );
	}

	/**
	 * Regression: an external deletion of a map holding an uneditable target removed the row anyway, because the
	 * preserved map equalled the snapshot and the write was skipped. The link and its backlink must both survive.
	 */
	public function test_external_delete_cannot_drop_a_link_the_user_cannot_edit(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_id = (int) self::factory()->post->create();
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_id = (int) self::factory()->post->create();
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		// A super admin establishes the two-sided link first.
		wp_set_current_user( 1 );
		grant_super_admin( 1 );
		erankly_mlms_set_post_translations( $a_id, array( $blog_b => $b_id ) );
		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a_id, $main ) );

		// An editor confined to the main site then deletes the map wholesale.
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		add_user_to_blog( $main, $editor, 'editor' );
		wp_set_current_user( $editor );

		delete_post_meta( $a_id, ERANKLY_MLMS_POST_META_KEY );

		erankly_mlms_flush_runtime_caches();
		$this->assertSame( array( $blog_b => $b_id ), erankly_mlms_get_post_translations( $a_id, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $a_id ), erankly_mlms_get_post_translations( $b_id ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	/** Term meta written outside the term edit screen synchronizes the counterpart, like post meta does. */
	public function test_external_term_meta_write_synchronizes_counterparts(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$a_term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$b_term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		update_term_meta( $a_term, ERANKLY_MLMS_TERM_META_KEY, array( (string) $blog_b => $b_term ) );

		$this->assertSame( array( $blog_b => $b_term ), erankly_mlms_get_term_translations( $a_term, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array( $main => $a_term ), erankly_mlms_get_term_translations( $b_term ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();

		delete_term_meta( $a_term, ERANKLY_MLMS_TERM_META_KEY );

		$this->assertSame( array(), erankly_mlms_get_term_translations( $a_term, $main ) );
		switch_to_blog( $blog_b );
		erankly_mlms_rebuild_rewrite_for_current_site();
		$this->assertSame( array(), erankly_mlms_get_term_translations( $b_term ) );
		restore_current_blog();
		erankly_mlms_rebuild_rewrite_for_current_site();
	}

	/**
	 * Regression: the term label memo keyed on blog and term ID only, so a lookup passing the wrong taxonomy
	 * cached the empty result and returned it for the correct taxonomy afterwards.
	 */
	public function test_term_title_memo_is_scoped_to_the_taxonomy(): void {
		$this->require_multisite();

		$term_id = (int) self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Cronaca',
			)
		);
		$blog_id = get_current_blog_id();

		// A mismatched lookup must not poison the correct one.
		$this->assertSame( '', erankly_mlms_get_term_title( $blog_id, 'post_tag', $term_id ) );
		$this->assertSame( sprintf( '#%d — Cronaca', $term_id ), erankly_mlms_get_term_title( $blog_id, 'category', $term_id ) );
	}

	/** The Site Health section reports the resolved cluster. */
	public function test_debug_information_reports_the_cluster(): void {
		$this->require_multisite();

		$main   = get_current_blog_id();
		$blog_b = (int) self::factory()->blog->create();

		$this->enable_module(
			array(
				$main   => array( 'language' => 'it_IT', 'enabled' => 1 ),
				$blog_b => array( 'language' => 'en_US', 'enabled' => 1 ),
			)
		);

		$info = erankly_mlms_add_debug_information( array() );

		$this->assertArrayHasKey( 'easyrankly_multilingual_multisite', $info );
		$this->assertSame( ERANKLY_MLMS_VERSION, $info['easyrankly_multilingual_multisite']['fields']['version']['value'] );
		$this->assertSame( '2', $info['easyrankly_multilingual_multisite']['fields']['cluster']['value'] );
		$this->assertStringContainsString( 'it-it', $info['easyrankly_multilingual_multisite']['fields']['hreflangs']['value'] );
	}
}
