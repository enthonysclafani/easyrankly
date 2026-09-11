<?php
/** Specialist sitemaps: index provider entries plus image, news and video generation. */

final class ERankly_Sitemap_Specialists_Test extends WP_UnitTestCase {

	/** @var array<string,array<string,mixed>> */
	private array $entity_maps = array();

	/** @var array<int,array{0:string,1:callable,2:int,3:int}> */
	private array $added_filters = array();

	public function set_up(): void {
		parent::set_up();

		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();

		// The specialist implementations are parsed only when their feature is on
		// in production, so the tests load the same files the owning surface does.
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		require_once ERANKLY_PATH . 'includes/sitemap/news.php';
		require_once ERANKLY_PATH . 'includes/sitemap/image.php';
		require_once ERANKLY_PATH . 'includes/sitemap/video.php';
		require_once ERANKLY_PATH . 'includes/class-erankly-specialist-sitemaps-provider.php';

		// Unlinked global groups so a per-entity filter never bleeds into other types.
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
			)
		);
		$this->fresh_generation();
	}

	public function tear_down(): void {
		foreach ( $this->added_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->added_filters = array();
		erankly_clear_settings_cache();

		parent::tear_down();
	}

	private function set_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	private function fresh_generation(): void {
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, wp_rand( 1000, PHP_INT_MAX ), false );
	}

	private function add_test_filter( string $tag, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $tag, $callback, $priority, $accepted_args );
		$this->added_filters[] = array( $tag, $callback, $priority, $accepted_args );
	}

	private function create_post( array $args = array() ): int {
		$now = time();

		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_status'   => 'publish',
					'post_title'    => 'Sitemap ' . wp_generate_password( 8, false ),
					'post_date'     => gmdate( 'Y-m-d H:i:s', $now ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
				),
				$args
			)
		);
	}

	/** @param array<string,mixed> $map */
	public function filter_entity_map( array $map, string $setting_key ): array {
		return array_key_exists( $setting_key, $this->entity_maps ) ? $this->entity_maps[ $setting_key ] : $map;
	}

	// Provider index entries -------------------------------------------------

	public function test_specialist_provider_is_inert_when_every_type_is_disabled(): void {
		$provider = new ERankly_Specialist_Sitemaps_Provider();

		$this->assertSame( array(), $provider->get_sitemap_entries() );
		$this->assertSame( array(), $provider->get_url_list( 1 ) );
		$this->assertSame( 0, $provider->get_max_num_pages() );
	}

	public function test_specialist_provider_lists_news_with_pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_news_sitemap'          => 1,
			)
		);
		$this->create_post( array( 'post_title' => 'News entry' ) );
		$this->fresh_generation();

		$entries = ( new ERankly_Specialist_Sitemaps_Provider() )->get_sitemap_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( home_url( '/sitemap-news-1.xml' ), $entries[0]['loc'] );
		$this->assertArrayHasKey( 'lastmod', $entries[0] );
		$this->assertNotSame( '', $entries[0]['lastmod'] );
	}

	public function test_specialist_provider_uses_query_args_with_plain_permalinks(): void {
		$this->set_permalink_structure( '' );
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_news_sitemap'          => 1,
			)
		);
		$this->create_post( array( 'post_title' => 'News entry' ) );
		$this->fresh_generation();

		$entries = ( new ERankly_Specialist_Sitemaps_Provider() )->get_sitemap_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( home_url( '/?erankly_sitemap=news&erankly_sitemap_page=1' ), $entries[0]['loc'] );
	}

	public function test_specialist_provider_lists_image_and_video_pages(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_image_sitemap'         => 1,
				'enable_video_sitemap'         => 1,
			)
		);
		$this->create_post(
			array(
				'post_content' => '<p><img src="https://cdn.example.org/pic.jpg" alt=""></p> https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			)
		);
		$this->fresh_generation();

		$locs = array_column( ( new ERankly_Specialist_Sitemaps_Provider() )->get_sitemap_entries(), 'loc' );

		$this->assertContains( home_url( '/sitemap-image-1.xml' ), $locs );
		$this->assertContains( home_url( '/sitemap-video-1.xml' ), $locs );
		$this->assertNotContains( home_url( '/sitemap-news-1.xml' ), $locs );
	}

	// News sitemap -----------------------------------------------------------

	public function test_news_sitemap_post_types_default_to_post_and_respect_the_filter(): void {
		$this->assertSame( array( 'post' ), erankly_get_news_sitemap_post_types() );

		$extend = static function ( array $types ): array {
			return array_merge( $types, array( 'page' ) );
		};
		$this->add_test_filter( 'erankly_news_sitemap_post_types', $extend );

		$this->assertSame( array( 'post', 'page' ), erankly_get_news_sitemap_post_types() );
	}

	public function test_news_sitemap_post_types_drop_globally_disabled_types(): void {
		$this->entity_maps['global_post_type_meta'] = array( 'post' => array( 'disable_sitemap' => 1 ) );
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );

		$this->assertSame( array(), erankly_get_news_sitemap_post_types() );
	}

	public function test_news_count_and_entries_limit_to_the_recent_window(): void {
		$recent = $this->create_post( array( 'post_title' => 'Recent' ) );
		$old    = $this->create_post(
			array(
				'post_title'    => 'Old',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
			)
		);
		$this->fresh_generation();

		$this->assertSame( 1, erankly_count_news_sitemap_posts() );

		$ids = array_column( erankly_get_news_sitemap_entries(), 'post_id' );

		$this->assertContains( $recent, $ids );
		$this->assertNotContains( $old, $ids );
	}

	public function test_news_entries_exclude_posts_flagged_from_news(): void {
		$included = $this->create_post();
		$excluded = $this->create_post();
		update_post_meta( $excluded, '_erankly_exclude_from_news', '1' );
		$this->fresh_generation();

		$ids = array_column( erankly_get_news_sitemap_entries(), 'post_id' );

		$this->assertContains( $included, $ids );
		$this->assertNotContains( $excluded, $ids );
	}

	public function test_news_entry_builds_loc_pubdate_and_title(): void {
		$post_id = $this->create_post( array( 'post_title' => 'Headline' ) );
		$this->fresh_generation();

		$entry = erankly_get_news_sitemap_entry( $post_id );

		$this->assertSame( get_permalink( $post_id ), $entry['loc'] );
		$this->assertSame( 'Headline', $entry['title'] );
		$this->assertSame( get_post_time( DATE_W3C, true, $post_id ), $entry['pubdate'] );
		$this->assertArrayHasKey( 'lastmod', $entry );
	}

	public function test_news_entry_is_filterable_and_empty_for_a_missing_post(): void {
		$this->assertSame( array(), erankly_get_news_sitemap_entry( 0 ) );

		$post_id = $this->create_post( array( 'post_title' => 'Removable' ) );

		$blank_title = static function ( array $entry ): array {
			$entry['title'] = '';
			return $entry;
		};
		$this->add_test_filter( 'erankly_news_sitemap_url', $blank_title, 10, 2 );

		$this->assertSame( array(), erankly_get_news_sitemap_entry( $post_id ) );
	}

	public function test_news_exclusion_sql_normalizes_an_invalid_alias(): void {
		$this->assertStringContainsString( '_erankly_exclude_from_news', erankly_get_news_sitemap_exclusion_sql( 'p' ) );

		$sql = erankly_get_news_sitemap_exclusion_sql( 'not-a-column' );

		$this->assertStringContainsString( 'p.ID', $sql );
		$this->assertStringNotContainsString( 'not-a-column', $sql );
	}

	public function test_news_stats_and_lastmod_follow_the_entries(): void {
		$this->create_post();
		$this->fresh_generation();

		$stats = erankly_get_news_sitemap_stats();

		$this->assertSame( 1, $stats['count'] );
		$this->assertNotSame( '', $stats['lastmod'] );
		$this->assertNotFalse( strtotime( $stats['lastmod'] ) );
		$this->assertSame( $stats['lastmod'], erankly_get_news_sitemap_lastmod() );
	}

	public function test_news_sitemap_xml_emits_news_nodes(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_news_sitemap'          => 1,
				'news_publication_name'        => 'Explicit Publication',
			)
		);
		$post_id = $this->create_post( array( 'post_title' => 'Breaking' ) );
		$this->fresh_generation();

		$xml = erankly_get_news_sitemap_xml( 1 );

		$this->assertStringContainsString( 'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"', $xml );
		$this->assertStringContainsString( '<news:name>Explicit Publication</news:name>', $xml );
		$this->assertStringContainsString( '<news:title>Breaking</news:title>', $xml );
		$this->assertStringContainsString( esc_xml( (string) get_permalink( $post_id ) ), $xml );
		$this->assertStringContainsString(
			'<news:publication_date>' . esc_html( (string) get_post_time( DATE_W3C, true, $post_id ) ) . '</news:publication_date>',
			$xml
		);
	}

	public function test_news_sitemap_xml_is_empty_without_recent_posts(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_news_sitemap'          => 1,
			)
		);
		$this->create_post(
			array(
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
			)
		);
		$this->fresh_generation();

		$this->assertSame( '', erankly_get_news_sitemap_xml( 1 ) );
	}

	public function test_build_sitemap_response_returns_news_xml_when_enabled(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_sitemap'               => 1,
				'enable_news_sitemap'          => 1,
				'news_publication_name'        => 'Builder Publication',
			)
		);
		$this->create_post( array( 'post_title' => 'Builder News' ) );
		$this->fresh_generation();

		$prepared = erankly_build_sitemap_response( 'news', 1 );

		$this->assertSame( 200, $prepared['status'] );
		$this->assertSame( 'application/xml', $prepared['content_type'] );
		$this->assertStringContainsString( '<news:title>Builder News</news:title>', $prepared['body'] );
		$this->assertStringContainsString( '<news:name>Builder Publication</news:name>', $prepared['body'] );
	}

	public function test_build_sitemap_response_returns_404_when_sitemaps_are_disabled(): void {
		$this->set_settings(
			array(
				'enable_sitemap'      => 0,
				'enable_news_sitemap' => 1,
			)
		);

		$this->assertSame( 404, erankly_build_sitemap_response( 'news', 1 )['status'] );
	}

	public function test_build_sitemap_response_returns_404_when_the_feature_or_type_is_unavailable(): void {
		$this->set_settings(
			array(
				'enable_sitemap'       => 1,
				'enable_news_sitemap'  => 0,
				'enable_image_sitemap' => 0,
			)
		);

		$this->assertSame( 404, erankly_build_sitemap_response( 'news', 1 )['status'] );
		$this->assertSame( 404, erankly_build_sitemap_response( 'image', 1 )['status'] );
		$this->assertSame( 404, erankly_build_sitemap_response( 'not-a-sitemap', 1 )['status'] );
	}

	// Image sitemap ----------------------------------------------------------

	public function test_image_sitemap_post_ids_and_count_track_posts_with_images(): void {
		$with_image    = $this->create_post(
			array( 'post_content' => '<p><img src="https://cdn.example.org/pic.jpg" alt=""></p>' )
		);
		$without_image = $this->create_post( array( 'post_content' => '<p>Only text here.</p>' ) );
		$this->fresh_generation();

		$ids = erankly_get_image_sitemap_post_ids();

		$this->assertContains( $with_image, $ids );
		$this->assertNotContains( $without_image, $ids );
		$this->assertSame( count( $ids ), erankly_count_image_sitemap_items() );
	}

	public function test_image_sitemap_entries_for_post_returns_absolute_urls(): void {
		$post_id = $this->create_post(
			array( 'post_content' => '<img src="https://cdn.example.org/inline.jpg" alt="">' )
		);

		$this->assertSame(
			array( 'https://cdn.example.org/inline.jpg' ),
			erankly_get_image_sitemap_entries_for_post( $post_id )
		);
		$this->assertSame( array(), erankly_get_image_sitemap_entries_for_post( 0 ) );
	}

	public function test_image_sitemap_xml_emits_image_nodes(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_image_sitemap'         => 1,
			)
		);
		$post_id = $this->create_post(
			array( 'post_content' => '<img src="https://cdn.example.org/pic.jpg" alt="">' )
		);
		$this->fresh_generation();

		$xml = erankly_get_image_sitemap_xml( 1 );

		$this->assertStringContainsString( 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml );
		$this->assertStringContainsString( '<image:loc>https://cdn.example.org/pic.jpg</image:loc>', $xml );
		$this->assertStringContainsString( esc_xml( esc_url_raw( (string) get_permalink( $post_id ) ) ), $xml );
	}

	public function test_image_sitemap_xml_is_empty_when_the_feature_is_disabled(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_image_sitemap'         => 0,
			)
		);
		$this->create_post( array( 'post_content' => '<img src="https://cdn.example.org/pic.jpg" alt="">' ) );
		$this->fresh_generation();

		$this->assertSame( '', erankly_get_image_sitemap_xml( 1 ) );
	}

	// Video sitemap ----------------------------------------------------------

	public function test_video_sitemap_post_ids_and_count_track_posts_with_video(): void {
		$with_video    = $this->create_post(
			array( 'post_content' => 'Watch https://www.youtube.com/watch?v=dQw4w9WgXcQ now' )
		);
		$without_video = $this->create_post( array( 'post_content' => '<p>Only text here.</p>' ) );
		$this->fresh_generation();

		$ids = erankly_get_video_sitemap_post_ids();

		$this->assertContains( $with_video, $ids );
		$this->assertNotContains( $without_video, $ids );
		$this->assertSame( count( $ids ), erankly_count_video_sitemap_posts() );
	}

	public function test_video_sitemap_entries_for_post_builds_an_absolute_entry(): void {
		$post_id = $this->create_post(
			array(
				'post_title'   => 'Clip',
				'post_content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			)
		);

		$entries = erankly_get_video_sitemap_entries_for_post( $post_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( (string) get_permalink( $post_id ), $entries[0]['loc'] );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $entries[0]['player_loc'] );
		$this->assertSame( 'https://img.youtube.com/vi/dQw4w9WgXcQ/0.jpg', $entries[0]['thumbnail_loc'] );
		$this->assertSame( 'Clip', $entries[0]['title'] );
		$this->assertNotSame( '', $entries[0]['description'] );
		$this->assertSame( array(), erankly_get_video_sitemap_entries_for_post( 0 ) );
	}

	public function test_video_sitemap_xml_emits_video_nodes(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_video_sitemap'         => 1,
			)
		);
		$this->create_post(
			array(
				'post_title'   => 'Clip',
				'post_content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			)
		);
		$this->fresh_generation();

		$xml = erankly_get_video_sitemap_xml( 1 );

		$this->assertStringContainsString( 'xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"', $xml );
		$this->assertStringContainsString( '<video:player_loc>https://www.youtube.com/embed/dQw4w9WgXcQ</video:player_loc>', $xml );
		$this->assertStringContainsString( '<video:title>Clip</video:title>', $xml );
	}

	public function test_video_sitemap_xml_is_empty_when_the_feature_is_disabled(): void {
		$this->set_settings(
			array(
				'global_post_type_meta_linked' => 0,
				'global_taxonomy_meta_linked'  => 0,
				'enable_video_sitemap'         => 0,
			)
		);
		$this->create_post( array( 'post_content' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
		$this->fresh_generation();

		$this->assertSame( '', erankly_get_video_sitemap_xml( 1 ) );
	}
}
