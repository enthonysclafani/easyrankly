<?php
/**
 * Sitemap and redirect cache helpers: URL building, versioned keys, invalidation.
 *
 * The flush helpers guard against running twice per request with a function-static
 * keyed by get_current_blog_id() (the $blog_id global). Tests that need a first
 * bump pick a unique blog_id so an earlier flush in this process cannot no-op them.
 */

final class ERankly_Helpers_Cache_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();

		// Production entry point for the redirect cache helpers.
		if ( ! function_exists( 'erankly_ensure_redirect_classes_available' ) ) {
			require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
		}

		erankly_ensure_redirect_classes_available();
	}

	/**
	 * Runs $callback with a blog_id the sitemap flush static has not seen.
	 *
	 * get_current_blog_id() reads $GLOBALS['blog_id']; get_option() still uses the
	 * current $wpdb prefix, so a synthetic ID only opens a fresh flush slot.
	 */
	private function with_fresh_sitemap_flush_slot( callable $callback ): void {
		$original             = $GLOBALS['blog_id'] ?? 1;
		$GLOBALS['blog_id'] = $original + wp_rand( 100000, 999999 );

		try {
			$callback();
		} finally {
			$GLOBALS['blog_id'] = $original;
		}
	}

	public function test_get_sitemap_url_uses_pretty_permalinks_when_configured(): void {
		$this->set_permalink_structure( '/%postname%/' );

		$this->assertSame( home_url( '/wp-sitemap.xml' ), erankly_get_sitemap_url( '/wp-sitemap.xml' ) );
		$this->assertSame( home_url( '/sitemap-news-1.xml' ), erankly_get_sitemap_url( 'sitemap-news-1.xml' ) );
	}

	public function test_get_sitemap_url_maps_the_index_to_a_query_arg_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( home_url( '/?sitemap=index' ), erankly_get_sitemap_url( '/wp-sitemap.xml' ) );
	}

	public function test_get_sitemap_url_maps_specialist_sitemaps_to_query_args_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame(
			home_url( '/?erankly_sitemap=news&erankly_sitemap_page=2' ),
			erankly_get_sitemap_url( '/sitemap-news-2.xml' )
		);
		$this->assertSame(
			home_url( '/?erankly_sitemap=image&erankly_sitemap_page=7' ),
			erankly_get_sitemap_url( '/sitemap-image-7.xml' )
		);
		$this->assertSame(
			home_url( '/?erankly_sitemap=video&erankly_sitemap_page=1' ),
			erankly_get_sitemap_url( '/sitemap-video-1.xml' )
		);
	}

	public function test_get_sitemap_url_falls_back_to_home_url_for_other_paths(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( home_url( '/something-else.xml' ), erankly_get_sitemap_url( '/something-else.xml' ) );
	}

	public function test_get_sitemap_stylesheet_url_returns_the_core_stylesheet(): void {
		$url = erankly_get_sitemap_stylesheet_url();

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'sitemap', $url );
		$this->assertSame( wp_sitemaps_get_server()->renderer->get_sitemap_stylesheet_url(), $url );
	}

	public function test_get_sitemap_cache_key_is_versioned_and_sanitized(): void {
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 7, false );

		// sanitize_key() drops disallowed characters rather than replacing them.
		$this->assertSame( ERANKLY_SITEMAP_TRANSIENT_PREFIX . '7_news-sitemap', erankly_get_sitemap_cache_key( 'news-sitemap' ) );
		$this->assertSame( ERANKLY_SITEMAP_TRANSIENT_PREFIX . '7_newssitemap', erankly_get_sitemap_cache_key( 'News Sitemap!' ) );
		$this->assertSame( ERANKLY_SITEMAP_TRANSIENT_PREFIX . '7_news_sitemap_2', erankly_get_sitemap_cache_key( 'news_sitemap_2' ) );
	}

	public function test_get_sitemap_cache_key_floors_the_version_at_one(): void {
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 0, false );

		$this->assertSame( ERANKLY_SITEMAP_TRANSIENT_PREFIX . '1_page', erankly_get_sitemap_cache_key( 'page' ) );
	}

	public function test_flush_sitemap_cache_bumps_the_version_at_most_once_per_request(): void {
		$this->with_fresh_sitemap_flush_slot(
			function (): void {
				$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				erankly_flush_sitemap_cache();
				$first = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				erankly_flush_sitemap_cache();
				erankly_flush_sitemap_cache();
				$second = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				$this->assertSame( $before + 1, $first );
				$this->assertSame( $first, $second );
			}
		);
	}

	public function test_flush_sitemap_cache_accepts_arbitrary_hook_arguments(): void {
		$this->with_fresh_sitemap_flush_slot(
			function (): void {
				$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				erankly_flush_sitemap_cache( 1, 2, 3 );
				$after_variadic = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				erankly_flush_sitemap_cache();
				$after_empty = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

				$this->assertSame( $before + 1, $after_variadic );
				$this->assertSame( $after_variadic, $after_empty );
			}
		);
	}

	public function test_flush_for_deleted_post_ignores_a_zero_id(): void {
		$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

		erankly_flush_sitemap_cache_for_deleted_post( 0 );

		$this->assertSame( $before, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	public function test_flush_for_post_skips_revisions_autosaves_and_non_public_posts(): void {
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$revision  = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
			)
		);
		$draft_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

		erankly_flush_sitemap_cache_for_post( (int) $revision );
		erankly_flush_sitemap_cache_for_post( (int) $draft_id );

		$this->assertSame( $before, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	public function test_flush_for_status_ignores_unchanged_and_non_public_transitions(): void {
		$post = self::factory()->post->create_and_get( array( 'post_status' => 'publish' ) );
		$this->assertInstanceOf( WP_Post::class, $post );

		$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

		erankly_flush_sitemap_cache_for_status( 'draft', 'draft', $post );
		erankly_flush_sitemap_cache_for_status( 'draft', 'pending', $post );

		$this->assertSame( $before, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	public function test_flush_meta_helpers_expose_filterable_key_allowlists(): void {
		$captured = array();

		$term_filter = static function ( array $keys ) use ( &$captured ): array {
			$captured['term'] = $keys;
			return $keys;
		};
		$post_filter = static function ( array $keys ) use ( &$captured ): array {
			$captured['post'] = $keys;
			return $keys;
		};
		$user_filter = static function ( array $keys ) use ( &$captured ): array {
			$captured['user'] = $keys;
			return $keys;
		};

		add_filter( 'erankly_sitemap_term_meta_cache_keys', $term_filter );
		add_filter( 'erankly_sitemap_post_meta_cache_keys', $post_filter );
		add_filter( 'erankly_sitemap_user_meta_cache_keys', $user_filter );

		try {
			erankly_flush_sitemap_cache_for_term_meta( 1, 1, '_erankly_irrelevant_key' );
			erankly_flush_sitemap_cache_for_post_meta( 1, 1, '_erankly_irrelevant_key' );
			erankly_flush_sitemap_cache_for_user_meta( 1, 1, '_erankly_irrelevant_key' );
		} finally {
			remove_filter( 'erankly_sitemap_term_meta_cache_keys', $term_filter );
			remove_filter( 'erankly_sitemap_post_meta_cache_keys', $post_filter );
			remove_filter( 'erankly_sitemap_user_meta_cache_keys', $user_filter );
		}

		$this->assertArrayHasKey( 'term', $captured );
		$this->assertArrayHasKey( 'post', $captured );
		$this->assertArrayHasKey( 'user', $captured );

		$this->assertContains( '_erankly_noindex', $captured['term'] );
		$this->assertContains( '_erankly_canonical', $captured['term'] );

		$this->assertContains( '_erankly_disable_sitemap', $captured['post'] );
		$this->assertContains( '_thumbnail_id', $captured['post'] );
		$this->assertContains( '_erankly_exclude_from_news', $captured['post'] );

		$this->assertContains( '_erankly_index_directive', $captured['user'] );
	}

	public function test_irrelevant_meta_keys_never_flush(): void {
		$before = (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 );

		erankly_flush_sitemap_cache_for_post_meta( 1, 1, 'some_other_plugin_key' );
		erankly_flush_sitemap_cache_for_term_meta( 1, 1, 'some_other_plugin_key' );
		erankly_flush_sitemap_cache_for_user_meta( 1, 1, 'some_other_plugin_key' );

		$this->assertSame( $before, (int) get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 1 ) );
	}

	public function test_flush_helpers_are_wired_to_the_documented_hooks(): void {
		$this->assertNotFalse( has_action( 'save_post', 'erankly_flush_sitemap_cache_for_post' ) );
		$this->assertNotFalse( has_action( 'deleted_post', 'erankly_flush_sitemap_cache_for_deleted_post' ) );
		$this->assertNotFalse( has_action( 'transition_post_status', 'erankly_flush_sitemap_cache_for_status' ) );
		$this->assertNotFalse( has_action( 'added_post_meta', 'erankly_flush_sitemap_cache_for_post_meta' ) );
		$this->assertNotFalse( has_action( 'updated_post_meta', 'erankly_flush_sitemap_cache_for_post_meta' ) );
		$this->assertNotFalse( has_action( 'deleted_post_meta', 'erankly_flush_sitemap_cache_for_post_meta' ) );
		$this->assertNotFalse( has_action( 'added_term_meta', 'erankly_flush_sitemap_cache_for_term_meta' ) );
		$this->assertNotFalse( has_action( 'added_user_meta', 'erankly_flush_sitemap_cache_for_user_meta' ) );
		$this->assertNotFalse( has_action( 'profile_update', 'erankly_flush_sitemap_cache' ) );
	}

	public function test_redirects_cache_key_includes_the_generation(): void {
		update_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, 'gen-42', false );

		$this->assertSame( 'erankly_redirect_gen-42_abc123', erankly_redirects_cache_key( 'abc123' ) );
	}

	public function test_redirects_cache_key_defaults_the_generation_to_zero(): void {
		delete_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION );

		$this->assertSame( 'erankly_redirect_0_hash', erankly_redirects_cache_key( 'hash' ) );
	}

	public function test_rotate_redirects_cache_generation_stores_a_new_uuid(): void {
		update_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, 'gen-1', false );

		erankly_rotate_redirects_cache_generation();

		$generation = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' );

		$this->assertNotSame( 'gen-1', $generation );
		$this->assertNotSame( '', $generation );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $generation );
	}

	public function test_rotate_redirects_cache_generation_changes_each_call(): void {
		erankly_rotate_redirects_cache_generation();
		$first = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' );

		erankly_rotate_redirects_cache_generation();
		$second = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' );

		$this->assertNotSame( '', $first );
		$this->assertNotSame( $first, $second );
	}

	public function test_flush_external_caches_fires_the_public_after_hook(): void {
		erankly_redirects_flush_external_caches();

		$this->assertGreaterThanOrEqual( 1, did_action( 'erankly_redirects_caches_flushed' ) );
	}
}
