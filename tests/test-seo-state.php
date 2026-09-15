<?php
/** Object SEO-state regressions. */

final class ERankly_SEO_State_Test extends WP_UnitTestCase {

	public function test_posts_page_uses_blog_special_noindex_policy(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$url          = get_permalink( $page_id );
		$blog_noindex = true;
		$page_noindex = false;

		$this->assertIsString( $url );

		$filter = static function ( $map, string $setting_key ) use ( &$blog_noindex, &$page_noindex ): array {
			$map = is_array( $map ) ? $map : array();

			if ( 'global_special_meta' === $setting_key ) {
				$map['blog'] = array_merge(
					isset( $map['blog'] ) && is_array( $map['blog'] ) ? $map['blog'] : array(),
					array( 'noindex' => $blog_noindex )
				);
			}

			if ( 'global_post_type_meta' === $setting_key ) {
				$map['page'] = array_merge(
					isset( $map['page'] ) && is_array( $map['page'] ) ? $map['page'] : array(),
					array( 'noindex' => $page_noindex )
				);
			}

			return $map;
		};

		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		$context = array(
			'kind'           => 'posts_page',
			'object_id'      => $page_id,
			'object_subtype' => 'page',
			'blog_id'        => get_current_blog_id(),
			'url'            => $url,
		);

		try {
			$state = erankly_get_object_seo_state( $context );

			$this->assertTrue( $state['exists'] );
			$this->assertFalse( $state['indexable'] );
			$this->assertContains( 'noindex', $state['reason_codes'] );

			update_post_meta( $page_id, '_erankly_index_directive', 'index' );
			$state = erankly_get_object_seo_state( $context );

			$this->assertTrue( $state['indexable'] );
			$this->assertNotContains( 'noindex', $state['reason_codes'] );

			delete_post_meta( $page_id, '_erankly_index_directive' );
			$blog_noindex = false;
			$page_noindex = true;
			$state        = erankly_get_object_seo_state( $context );

			$this->assertTrue( $state['indexable'] );
			$this->assertNotContains( 'noindex', $state['reason_codes'] );

			update_post_meta( $page_id, '_erankly_index_directive', 'noindex' );
			$state = erankly_get_object_seo_state( $context );

			$this->assertFalse( $state['indexable'] );
			$this->assertContains( 'noindex', $state['reason_codes'] );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
			delete_post_meta( $page_id, '_erankly_index_directive' );
		}
	}

	public function test_posts_page_noindex_check_fails_closed_for_a_missing_page(): void {
		$this->assertTrue( erankly_object_seo_state_is_noindex( 'posts_page', PHP_INT_MAX, 'page' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_object_seo_state_canonical_matches_head_after_localize(): void {
		require_once ERANKLY_PATH . 'includes/canonical.php';

		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$permalink = get_permalink( $post_id );
		$this->assertIsString( $permalink );
		$this->go_to( $permalink );

		$localize = static function ( string $url ): string {
			if ( '' === $url || str_contains( $url, 'erankly-lang=it' ) ) {
				return $url;
			}

			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'erankly-lang=it';
		};
		add_filter( 'erankly_localized_url', $localize );

		try {
			$head  = erankly_get_canonical();
			$state = erankly_get_object_seo_state(
				array(
					'kind'           => 'post',
					'object_id'      => $post_id,
					'object_subtype' => 'post',
					'blog_id'        => get_current_blog_id(),
					'url'            => $permalink,
				)
			);

			$this->assertStringContainsString( 'erankly-lang=it', $head );
			$this->assertSame( $head, $state['canonical_url'] );
			$this->assertTrue( $state['canonical_is_self'] );
			$this->assertNotContains( 'canonical_not_self', $state['reason_codes'] );
		} finally {
			remove_filter( 'erankly_localized_url', $localize );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_object_seo_state_canonical_matches_head_on_paged_archive(): void {
		require_once ERANKLY_PATH . 'includes/canonical.php';

		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'posts_per_page', 1 );

		$this->go_to( home_url( '/page/2/' ) );

		$localize = static function ( string $url ): string {
			if ( '' === $url || str_contains( $url, 'erankly-lang=it' ) ) {
				return $url;
			}

			return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'erankly-lang=it';
		};
		add_filter( 'erankly_localized_url', $localize );

		try {
			$this->assertTrue( is_paged() );

			$request_url = erankly_get_paged_archive_canonical();
			$head        = erankly_get_canonical();
			$state       = erankly_get_object_seo_state(
				array(
					'kind'           => 'home',
					'object_id'      => 0,
					'object_subtype' => '',
					'blog_id'        => get_current_blog_id(),
					'url'            => $request_url,
				)
			);

			$this->assertStringContainsString( 'page/2', $head );
			$this->assertSame( $head, $state['canonical_url'] );
			$this->assertTrue( $state['canonical_is_self'] );
		} finally {
			remove_filter( 'erankly_localized_url', $localize );
		}
	}
}
