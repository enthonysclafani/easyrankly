<?php
/** Global entity metadata: post/term/user meta accessors and the linked-template fallbacks. */

final class ERankly_Helpers_Global_Meta_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		// erankly_get_post_type_schema_type() reads the default schema row
		// directly, so the defaults module has to be loaded as well.
		erankly_load_default_helpers();
	}

	/**
	 * Stores settings and drops the request-level caches so the next read sees them.
	 *
	 * @param array<string,mixed> $settings Partial settings map.
	 */
	private function store_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	public function test_get_post_meta_string_trims_a_stored_string(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_title', '  titolo  ' );

		$this->assertSame( 'titolo', erankly_get_post_meta_string( (int) $post_id, 'title' ) );
	}

	public function test_get_post_meta_string_is_empty_for_a_missing_or_non_string_value(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_flags', array( 'a' ) );

		$this->assertSame( '', erankly_get_post_meta_string( (int) $post_id, 'title' ) );
		$this->assertSame( '', erankly_get_post_meta_string( (int) $post_id, 'flags' ) );
	}

	public function test_get_post_meta_bool_accepts_the_stored_one(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_noindex', '1' );

		$this->assertTrue( erankly_get_post_meta_bool( (int) $post_id, 'noindex' ) );
		$this->assertFalse( erankly_get_post_meta_bool( (int) $post_id, 'assente' ) );
	}

	public function test_registered_boolean_meta_is_normalised_on_save(): void {
		$post_id = self::factory()->post->create();

		// _erankly_nofollow is registered with a sanitize_callback that casts to
		// bool, so any truthy input lands as a stored '1' rather than staying raw.
		update_post_meta( $post_id, '_erankly_nofollow', 'yes' );

		$this->assertSame( '1', (string) get_post_meta( $post_id, '_erankly_nofollow', true ) );
		$this->assertTrue( erankly_get_post_meta_bool( (int) $post_id, 'nofollow' ) );

		update_post_meta( $post_id, '_erankly_nofollow', '' );

		$this->assertFalse( erankly_get_post_meta_bool( (int) $post_id, 'nofollow' ) );
	}

	public function test_get_term_meta_accessors(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term_id, '_erankly_title', '  titolo termine  ' );
		update_term_meta( $term_id, '_erankly_noindex', '1' );

		$this->assertSame( 'titolo termine', erankly_get_term_meta_string( (int) $term_id, 'title' ) );
		$this->assertTrue( erankly_get_term_meta_bool( (int) $term_id, 'noindex' ) );
		$this->assertSame( '', erankly_get_term_meta_string( (int) $term_id, 'assente' ) );
	}

	public function test_get_primary_term_returns_the_stored_term_when_still_assigned(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );
		update_post_meta( $post_id, '_erankly_primary_terms', array( 'category' => $term_id ) );

		$term = erankly_get_primary_term( (int) $post_id, 'category' );

		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( (int) $term_id, (int) $term->term_id );
	}

	public function test_get_primary_term_is_null_when_the_object_is_not_assigned(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		// Stored as primary but never assigned to the post.
		update_post_meta( $post_id, '_erankly_primary_terms', array( 'category' => $term_id ) );

		$this->assertNull( erankly_get_primary_term( (int) $post_id, 'category' ) );
	}

	public function test_get_primary_term_is_null_without_stored_data(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( erankly_get_primary_term( (int) $post_id, 'category' ) );
	}

	public function test_object_robots_directive_returns_the_explicit_tri_state_value(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
		update_post_meta( $post_id, '_erankly_follow_directive', 'follow' );

		$this->assertSame( 'noindex', erankly_get_object_robots_directive( 'post', (int) $post_id, 'index' ) );
		$this->assertSame( 'follow', erankly_get_object_robots_directive( 'post', (int) $post_id, 'follow' ) );
	}

	public function test_object_robots_directive_falls_back_to_legacy_boolean_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_noindex', '1' );
		update_post_meta( $post_id, '_erankly_nofollow', '1' );

		$this->assertSame( 'noindex', erankly_get_object_robots_directive( 'post', (int) $post_id, 'index' ) );
		$this->assertSame( 'nofollow', erankly_get_object_robots_directive( 'post', (int) $post_id, 'follow' ) );
	}

	public function test_object_robots_directive_prefers_the_tri_state_over_legacy(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_index_directive', 'index' );
		update_post_meta( $post_id, '_erankly_noindex', '1' );

		// An explicit positive directive must beat the restrictive legacy boolean.
		$this->assertSame( 'index', erankly_get_object_robots_directive( 'post', (int) $post_id, 'index' ) );
	}

	public function test_object_robots_directive_treats_inherit_as_unset(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_index_directive', 'inherit' );

		$this->assertSame( 'inherit', erankly_get_object_robots_directive( 'post', (int) $post_id, 'index' ) );
	}

	public function test_object_robots_directive_returns_inherit_for_the_snippet_axis(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_snippet_directive', 'nosnippet' );

		// snippet has no legacy boolean, so an explicit value is returned as-is
		// and an absence stays inherit.
		$this->assertSame( 'nosnippet', erankly_get_object_robots_directive( 'post', (int) $post_id, 'snippet' ) );
		$this->assertSame( 'inherit', erankly_get_object_robots_directive( 'post', (int) $post_id, 'image' ) );
	}

	public function test_object_robots_directive_reads_term_and_user_meta(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$user_id = self::factory()->user->create();

		update_term_meta( $term_id, '_erankly_index_directive', 'noindex' );
		update_user_meta( $user_id, '_erankly_index_directive', 'noindex' );

		$this->assertSame( 'noindex', erankly_get_object_robots_directive( 'term', (int) $term_id, 'index' ) );
		$this->assertSame( 'noindex', erankly_get_object_robots_directive( 'user', (int) $user_id, 'index' ) );
	}

	public function test_global_entity_meta_reads_the_stored_row(): void {
		$this->store_settings(
			array(
				'global_post_type_meta' => array(
					'post' => array( 'title' => 'Titolo globale' ),
				),
			)
		);

		$this->assertSame( 'Titolo globale', erankly_get_global_post_type_meta( 'post', 'title' ) );
	}

	public function test_global_entity_meta_returns_empty_for_an_unknown_entity_when_not_linked(): void {
		$this->store_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_post_type_meta'        => array( 'post' => array( 'title' => 'Titolo globale' ) ),
			)
		);

		$this->assertSame( '', erankly_get_global_post_type_meta( 'page', 'title' ) );
	}

	public function test_global_entity_meta_borrows_the_first_row_when_linked(): void {
		$this->store_settings(
			array(
				'global_post_type_meta_linked' => 1,
				'global_post_type_meta'        => array( 'post' => array( 'title' => 'Titolo condiviso' ) ),
			)
		);

		$this->assertSame( 'Titolo condiviso', erankly_get_global_post_type_meta( 'page', 'title' ) );
	}

	public function test_global_taxonomy_meta_reads_and_borrows(): void {
		$this->store_settings(
			array(
				'global_taxonomy_meta_linked' => 0,
				'global_taxonomy_meta'        => array( 'category' => array( 'title' => 'Titolo categoria' ) ),
			)
		);

		$this->assertSame( 'Titolo categoria', erankly_get_global_taxonomy_meta( 'category', 'title' ) );
		$this->assertSame( '', erankly_get_global_taxonomy_meta( 'post_tag', 'title' ) );
	}

	public function test_global_entity_meta_row_returns_the_matching_row(): void {
		$this->store_settings(
			array(
				'global_post_type_meta' => array(
					'post' => array(
						'title'   => 'Titolo',
						'noindex' => 1,
					),
				),
			)
		);

		$row = erankly_get_global_entity_meta_row( 'global_post_type_meta', 'post' );

		$this->assertSame( 'Titolo', $row['title'] );
		$this->assertSame( 1, $row['noindex'] );
	}

	public function test_global_entity_meta_row_is_empty_for_an_unknown_unlinked_entity(): void {
		$this->store_settings( array( 'global_post_type_meta_linked' => 0 ) );

		$this->assertSame( array(), erankly_get_global_entity_meta_row( 'global_post_type_meta', 'page' ) );
	}

	public function test_special_meta_is_never_linked(): void {
		$this->store_settings( array( 'global_special_meta_linked' => 1 ) );

		$this->assertFalse( erankly_global_entity_meta_is_linked( 'global_special_meta' ) );
	}

	public function test_global_entity_meta_is_linked_defaults_to_true(): void {
		$this->store_settings( array() );

		$this->assertTrue( erankly_global_entity_meta_is_linked( 'global_post_type_meta' ) );
	}

	public function test_global_entity_directive_reads_a_stored_flag(): void {
		$this->store_settings(
			array(
				'global_post_type_meta' => array( 'post' => array( 'noindex' => 1 ) ),
			)
		);

		$this->assertTrue( erankly_get_global_post_type_directive( 'post', 'noindex' ) );
		$this->assertFalse( erankly_get_global_post_type_directive( 'post', 'nofollow' ) );
	}

	public function test_global_entity_directive_rejects_an_unknown_field(): void {
		$this->store_settings(
			array(
				'global_post_type_meta' => array( 'post' => array( 'campo_ignoto' => 1 ) ),
			)
		);

		$this->assertFalse( erankly_get_global_entity_directive( 'global_post_type_meta', 'post', 'campo_ignoto' ) );
	}

	public function test_global_entity_directive_borrows_when_linked(): void {
		$this->store_settings(
			array(
				'global_post_type_meta_linked' => 1,
				'global_post_type_meta'        => array( 'post' => array( 'noindex' => 1 ) ),
			)
		);

		$this->assertTrue( erankly_get_global_post_type_directive( 'page', 'noindex' ) );
	}

	public function test_taxonomy_directive_helper(): void {
		$this->store_settings(
			array(
				'global_taxonomy_meta' => array( 'category' => array( 'noindex' => 1 ) ),
			)
		);

		$this->assertTrue( erankly_get_global_taxonomy_directive( 'category', 'noindex' ) );
		$this->assertFalse( erankly_get_global_taxonomy_directive( 'category', 'nofollow' ) );
	}

	public function test_post_type_schema_type_reads_the_dedicated_setting(): void {
		$this->store_settings(
			array(
				'global_post_type_schema' => array(
					'post' => array(
						'webpage_type' => 'WebPage',
						'article_type' => 'BlogPosting',
					),
				),
			)
		);

		$this->assertSame( 'WebPage', erankly_get_post_type_schema_type( 'post', 'webpage_type' ) );
		$this->assertSame( 'BlogPosting', erankly_get_post_type_schema_type( 'post', 'article_type' ) );
	}

	public function test_post_type_schema_type_falls_back_to_the_legacy_row(): void {
		$this->store_settings(
			array(
				'global_post_type_meta' => array(
					'post' => array( 'webpage_type' => 'WebPage' ),
				),
			)
		);

		$this->assertSame( 'WebPage', erankly_get_post_type_schema_type( 'post', 'webpage_type' ) );
	}

	public function test_post_type_schema_type_maps_a_stored_empty_article_type_to_none(): void {
		$this->store_settings(
			array(
				'global_post_type_schema' => array(
					'post' => array(
						'webpage_type' => 'WebPage',
						'article_type' => '',
					),
				),
			)
		);

		// The retired free-text field expressed "emit no Article node" as empty.
		$this->assertSame( 'none', erankly_get_post_type_schema_type( 'post', 'article_type' ) );
	}

	public function test_post_type_schema_type_rejects_an_unknown_field(): void {
		$this->store_settings( array() );

		$this->assertSame( '', erankly_get_post_type_schema_type( 'post', 'campo_ignoto' ) );
	}

	public function test_post_type_schema_type_uses_defaults_for_an_unconfigured_type(): void {
		$this->store_settings( array() );

		$webpage = erankly_get_post_type_schema_type( 'post', 'webpage_type' );
		$article = erankly_get_post_type_schema_type( 'post', 'article_type' );

		$this->assertNotSame( '', $webpage );
		$this->assertNotSame( '', $article );
		$this->assertNotSame( 'none', $webpage );
	}

	public function test_get_site_special_meta_falls_back_to_the_defaults(): void {
		delete_option( ERANKLY_SPECIAL_META_OPTION );
		erankly_clear_settings_cache();

		$meta = erankly_get_site_special_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( '404', $meta );
		$this->assertNotEmpty( $meta['404']['noindex'] );
	}

	public function test_get_site_special_meta_reads_the_stored_option(): void {
		update_option(
			ERANKLY_SPECIAL_META_OPTION,
			array( '404' => array( 'title' => 'Titolo 404 personalizzato' ) )
		);

		$meta = erankly_get_site_special_meta();

		$this->assertSame( 'Titolo 404 personalizzato', $meta['404']['title'] );
	}

	public function test_get_site_special_meta_is_filterable(): void {
		update_option( ERANKLY_SPECIAL_META_OPTION, array() );

		$filter = static function ( array $meta ): array {
			$meta['search'] = array( 'noindex' => 1 );
			return $meta;
		};

		add_filter( 'erankly_site_special_meta', $filter );

		try {
			$this->assertSame( 1, erankly_get_site_special_meta()['search']['noindex'] );
		} finally {
			remove_filter( 'erankly_site_special_meta', $filter );
		}
	}

	public function test_global_entity_meta_map_is_filterable(): void {
		$this->store_settings( array() );

		$filter = static function ( array $map, string $key ): array {
			$map['post'] = array( 'title' => 'Dal filtro' );
			return $map;
		};

		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$map = erankly_get_global_entity_meta_map( 'global_post_type_meta' );

			$this->assertSame( 'Dal filtro', $map['post']['title'] );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_get_image_block_attachment_ids_collects_gallery_block_ids(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/gallery',
				'attrs'       => array( 'ids' => array( 11, 22 ) ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 33 ),
				'innerBlocks' => array(),
			),
		);

		$ids = erankly_get_image_block_attachment_ids( $blocks );

		$this->assertContains( 11, $ids );
		$this->assertContains( 22, $ids );
		$this->assertContains( 33, $ids );
	}

	public function test_get_image_block_attachment_ids_walks_inner_blocks(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'core/image',
						'attrs'       => array( 'id' => 44 ),
						'innerBlocks' => array(),
					),
				),
			),
		);

		$this->assertContains( 44, erankly_get_image_block_attachment_ids( $blocks ) );
	}

	public function test_get_image_block_attachment_ids_reads_media_id_and_supported_blocks(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/media-text',
				'attrs'       => array( 'mediaId' => 66 ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/cover',
				'attrs'       => array( 'id' => 77 ),
				'innerBlocks' => array(),
			),
		);

		$ids = erankly_get_image_block_attachment_ids( $blocks );

		$this->assertContains( 66, $ids );
		$this->assertContains( 77, $ids );
	}

	public function test_get_image_block_attachment_ids_ignores_unrelated_block_types(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array( 'id' => 88 ),
				'innerBlocks' => array(),
			),
		);

		$this->assertSame( array(), erankly_get_image_block_attachment_ids( $blocks ) );
	}

	public function test_get_image_block_attachment_ids_is_unique_and_ignores_junk(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 55 ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 55 ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 0 ),
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerBlocks' => array(),
			),
		);

		$ids = erankly_get_image_block_attachment_ids( $blocks );

		$this->assertSame( array( 55 ), array_values( $ids ) );
	}
}
