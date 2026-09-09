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
}
