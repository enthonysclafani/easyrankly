<?php
/** Native sitemaps integration: core filters, caching, image collection and the site provider. */

final class ERankly_Sitemap_Providers_Test extends WP_UnitTestCase {

	/** @var array<string,array<string,mixed>> */
	private array $entity_maps = array();

	/** @var array<int,array{0:string,1:callable,2:int,3:int}> */
	private array $added_filters = array();

	/** @var array<int,array<string,string>> */
	private array $extra_site_urls = array();

	private bool $registered_cpt = false;

	public function set_up(): void {
		parent::set_up();

		erankly_load_sitemap_helpers();
		erankly_load_content_helpers();

		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		require_once ERANKLY_PATH . 'includes/class-erankly-site-sitemaps-provider.php';

		register_post_type(
			'erankly_test_doc',
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'has_archive'        => 'erankly-test-docs',
				'rewrite'            => array( 'slug' => 'erankly-test-docs' ),
				'supports'           => array( 'title', 'editor', 'author' ),
			)
		);
		$this->registered_cpt = true;

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

		if ( $this->registered_cpt ) {
			unregister_post_type( 'erankly_test_doc' );
			$this->registered_cpt = false;
		}

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

	private function create_image_attachment(): int {
		$filename = '2026/09/erankly-sitemap-' . wp_generate_password( 8, false ) . '.jpg';
		$id       = wp_insert_attachment(
			array(
				'post_title'     => 'Sitemap image',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			),
			$filename
		);
		update_attached_file( $id, $filename );
		wp_update_attachment_metadata(
			$id,
			array(
				'file'   => $filename,
				'width'  => 1200,
				'height' => 800,
			)
		);

		return (int) $id;
	}

	/** @param array<string,mixed> $map */
	public function filter_entity_map( array $map, string $setting_key ): array {
		return array_key_exists( $setting_key, $this->entity_maps ) ? $this->entity_maps[ $setting_key ] : $map;
	}

	/** @param array<string,WP_Post_Type> $post_types */
	public function filter_archive_post_types_only_cpt( array $post_types ): array {
		$cpt = get_post_type_object( 'erankly_test_doc' );

		return $cpt instanceof WP_Post_Type ? array( 'erankly_test_doc' => $cpt ) : array();
	}

	/** @param array<string,WP_Post_Type> $post_types */
	public function filter_archive_post_types_none( array $post_types ): array {
		return array();
	}

	/** @param array<int,array<string,string>> $entries */
	public function filter_site_urls( array $entries ): array {
		return array_merge( $entries, $this->extra_site_urls );
	}

	// Site-level provider ----------------------------------------------------

	public function test_site_provider_max_num_pages_counts_archive_and_extra_entries(): void {
		self::factory()->post->create( array( 'post_type' => 'erankly_test_doc', 'post_status' => 'publish' ) );

		$this->add_test_filter( 'erankly_sitemap_archive_post_types', array( $this, 'filter_archive_post_types_only_cpt' ) );
		$this->extra_site_urls = array( array( 'loc' => '/extra-landing/' ) );
		$this->add_test_filter( 'erankly_sitemap_site_urls', array( $this, 'filter_site_urls' ) );

		$provider = new ERankly_Site_Sitemaps_Provider();

		$this->assertSame( 1, $provider->get_max_num_pages() );
		$this->assertCount( 2, $provider->get_url_list( 1 ) );
	}

	public function test_site_provider_max_num_pages_is_zero_without_entries(): void {
		$this->add_test_filter( 'erankly_sitemap_archive_post_types', array( $this, 'filter_archive_post_types_none' ) );

		$provider = new ERankly_Site_Sitemaps_Provider();

		$this->assertSame( 0, $provider->get_max_num_pages() );
		$this->assertSame( array(), $provider->get_url_list( 1 ) );
	}

	// Core taxonomy cache ----------------------------------------------------

	public function test_core_taxonomies_url_list_is_cached_under_the_provider_key(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $term_id ),
			)
		);
		$this->fresh_generation();

		$urls = erankly_cache_core_sitemap_taxonomies_url_list( null, 'category', 1 );

		$this->assertIsArray( $urls );
		$this->assertContains( get_term_link( $term_id ), array_column( $urls, 'loc' ) );

		$cached = get_transient( erankly_get_sitemap_cache_key( 'core_taxonomies_category_1' ) );

		$this->assertIsArray( $cached );
		$this->assertSame( $urls, $cached['urls'] );
	}

	public function test_core_url_list_cache_passes_through_guarded_inputs(): void {
		$sentinel = array( array( 'loc' => 'https://example.org/provided/' ) );

		$this->assertSame( $sentinel, erankly_cache_core_sitemap_taxonomies_url_list( $sentinel, 'category', 1 ) );
		$this->assertNull( erankly_cache_core_sitemap_taxonomies_url_list( null, 'category', 0 ) );
	}

	// Output buffering -------------------------------------------------------

	public function test_start_core_sitemap_output_buffer_ignores_non_sitemap_requests(): void {
		set_query_var( 'sitemap', '' );
		$before = ob_get_level();

		erankly_start_core_sitemap_output_buffer();

		$this->assertSame( $before, ob_get_level(), 'Non-sitemap requests must not open an output buffer.' );
	}

	// Response filtering -----------------------------------------------------

	public function test_core_sitemap_response_leaves_non_xml_and_unmatched_bodies_untouched(): void {
		$this->assertSame( '<html>not found</html>', erankly_filter_core_sitemap_response( '<html>not found</html>' ) );

		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
		$xml = '<?xml version="1.0" encoding="UTF-8"?><urlset></urlset>';

		$this->assertSame( $xml, erankly_filter_core_sitemap_response( $xml ) );
	}

	public function test_core_sitemap_response_returns_304_on_matching_etag(): void {
		if ( headers_sent() ) {
			$this->markTestSkipped( 'Headers already sent in this SAPI; the ETag branch is unreachable here.' );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?><urlset></urlset>';
		$etag = '"' . hash( 'sha256', $xml ) . '"';
		$_SERVER['HTTP_IF_NONE_MATCH'] = $etag;

		try {
			$this->assertSame( '', erankly_filter_core_sitemap_response( $xml ) );
		} finally {
			unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
		}
	}

	// Special-page visibility ------------------------------------------------

	public function test_special_page_is_sitemap_hidden_honours_disable_and_explicit_index(): void {
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );

		$this->entity_maps['global_special_meta'] = array( 'homepage' => array( 'disable_sitemap' => 1 ) );
		$this->assertTrue( erankly_special_page_is_sitemap_hidden( 'homepage' ) );

		$this->entity_maps['global_special_meta'] = array( 'homepage' => array( 'noindex' => 1 ) );
		$this->assertTrue( erankly_special_page_is_sitemap_hidden( 'homepage' ) );

		$explicit = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_post_meta( $explicit, '_erankly_index_directive', 'index' );
		$this->assertFalse( erankly_special_page_is_sitemap_hidden( 'homepage', $explicit ) );

		$inherit = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->assertTrue( erankly_special_page_is_sitemap_hidden( 'homepage', $inherit ) );
	}

	// Core post-page max pages ----------------------------------------------

	public function test_filter_posts_pre_max_num_pages_passthrough_and_page_correction(): void {
		$this->assertSame( 9, erankly_filter_core_sitemap_posts_pre_max_num_pages( 9, 'post' ) );

		// Homepage is not hidden by default, so the filter leaves null alone.
		$this->assertNull( erankly_filter_core_sitemap_posts_pre_max_num_pages( null, 'page' ) );

		update_option( 'show_on_front', 'posts' );
		$this->entity_maps['global_special_meta'] = array( 'homepage' => array( 'disable_sitemap' => 1 ) );
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );

		$this->assertSame( 1, erankly_filter_core_sitemap_posts_pre_max_num_pages( null, 'page' ) );
	}

	// Core taxonomy query args ----------------------------------------------

	public function test_filter_terms_query_args_disables_and_merges_exclusions(): void {
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );

		$this->entity_maps['global_taxonomy_meta'] = array( 'category' => array( 'disable_sitemap' => 1 ) );
		$this->assertSame( array( 'include' => array( 0 ) ), erankly_filter_core_sitemap_terms_query_args( array(), 'category' ) );

		$this->entity_maps['global_taxonomy_meta'] = array();
		$filtered = erankly_filter_core_sitemap_terms_query_args( array( 'orderby' => 'name' ), 'category' );

		$this->assertSame( 'name', $filtered['orderby'] );
		$this->assertSame( erankly_get_sitemap_term_exclusion_meta_query( false ), $filtered['meta_query'] );

		$this->entity_maps['global_taxonomy_meta'] = array( 'category' => array( 'noindex' => 1 ) );
		$noindexed = erankly_filter_core_sitemap_terms_query_args( array(), 'category' );

		$this->assertSame( erankly_get_sitemap_term_exclusion_meta_query( true ), $noindexed['meta_query'] );
	}

	public function test_filter_core_sitemap_taxonomies_removes_disabled_entries(): void {
		$this->entity_maps['global_taxonomy_meta'] = array( 'category' => array( 'disable_sitemap' => 1 ) );
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );

		$taxonomies = array(
			'category' => get_taxonomy( 'category' ),
			'post_tag' => get_taxonomy( 'post_tag' ),
		);

		$filtered = erankly_filter_core_sitemap_taxonomies( $taxonomies );

		$this->assertArrayNotHasKey( 'category', $filtered );
		$this->assertArrayHasKey( 'post_tag', $filtered );
	}

	// Image collection -------------------------------------------------------

	public function test_get_sitemap_images_collects_featured_content_and_gallery(): void {
		$featured = $this->create_image_attachment();
		$gallery  = $this->create_image_attachment();

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Intro</p><img src="https://cdn.example.org/inline.jpg" alt="">',
			)
		);
		set_post_thumbnail( $post_id, $featured );
		update_post_meta( $post_id, '_product_image_gallery', (string) $gallery );

		$images = erankly_get_sitemap_images( $post_id );

		$this->assertContains( erankly_get_image_url( $featured, 'full' ), $images );
		$this->assertContains( 'https://cdn.example.org/inline.jpg', $images );
		$this->assertContains( erankly_get_image_url( $gallery, 'full' ), $images );
		$this->assertSame( array_values( array_unique( $images ) ), $images );
	}

	public function test_get_sitemap_images_resolves_relative_and_drops_non_http_urls(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '' ) );

		$extend = static function ( array $images ): array {
			$images[] = '/wp-content/uploads/relative.jpg';
			$images[] = 'mailto:someone@example.com';
			return $images;
		};
		$this->add_test_filter( 'erankly_sitemap_images', $extend, 10, 2 );

		$images = erankly_get_sitemap_images( $post_id );

		$this->assertContains( home_url( '/wp-content/uploads/relative.jpg' ), $images );
		$this->assertNotContains( 'mailto:someone@example.com', $images );
	}

	// Post type name filtering ----------------------------------------------

	public function test_filter_sitemap_post_type_names_by_global_directives(): void {
		$this->entity_maps['global_post_type_meta'] = array( 'post' => array( 'disable_sitemap' => 1 ) );
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );

		$filtered = erankly_filter_sitemap_post_type_names_by_global_directives( array( 'post', 'Page', 'post', '' ) );

		$this->assertSame( array( 'page' ), $filtered );
	}

	// Canonical variable substitution ---------------------------------------

	public function test_get_sitemap_global_canonical_variable_value_is_whitelisted(): void {
		$this->assertSame( wp_date( 'Y' ), erankly_get_sitemap_global_canonical_variable_value( 'current_year' ) );
		$this->assertSame( '', erankly_get_sitemap_global_canonical_variable_value( 'post_title' ) );
	}

	public function test_replace_sitemap_user_canonical_variables(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Jane Doe',
			)
		);
		$user    = get_userdata( $user_id );
		$this->assertInstanceOf( WP_User::class, $user );

		$result = erankly_replace_sitemap_user_canonical_variables( 'Bio for {{author_name}} at {{author_url}}', $user );

		$this->assertSame( 'Bio for Jane Doe at ' . get_author_posts_url( $user_id ), $result );
		$this->assertSame( 'plain value', erankly_replace_sitemap_user_canonical_variables( 'plain value', $user ) );
	}

	// Post eligibility -------------------------------------------------------

	public function test_is_post_sitemap_eligible_checks_type_meta_and_directives(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertTrue( erankly_is_post_sitemap_eligible( $post_id ) );
		$this->assertFalse( erankly_is_post_sitemap_eligible( $post_id, array( 'page' ) ) );
		$this->assertFalse( erankly_is_post_sitemap_eligible( 0 ) );

		$disabled = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $disabled, '_erankly_disable_sitemap', '1' );
		$this->assertFalse( erankly_is_post_sitemap_eligible( $disabled ) );

		$this->entity_maps['global_post_type_meta'] = array( 'post' => array( 'disable_sitemap' => 1 ) );
		$this->add_test_filter( 'erankly_global_entity_meta_map', array( $this, 'filter_entity_map' ), 10, 2 );
		$this->assertFalse( erankly_is_post_sitemap_eligible( $post_id ) );
	}

	// Term exclusion meta query ---------------------------------------------

	public function test_get_sitemap_term_exclusion_meta_query_shape(): void {
		$explicit = erankly_get_sitemap_term_exclusion_meta_query( true );

		$this->assertSame( 'AND', $explicit['relation'] );
		$this->assertSame( '_erankly_index_directive', $explicit[0]['key'] );
		$this->assertSame( 'index', $explicit[0]['value'] );
		$this->assertSame( '_erankly_disable_sitemap', $explicit[2][0]['key'] );

		$inherited = erankly_get_sitemap_term_exclusion_meta_query( false );

		$this->assertSame( 'AND', $inherited['relation'] );
		$this->assertSame( 'OR', $inherited[0]['relation'] );
		$this->assertSame( '_erankly_disable_sitemap', $inherited[2][0]['key'] );
	}

	// GMT date formatting ----------------------------------------------------

	public function test_format_sitemap_gmt_date(): void {
		$this->assertSame( '', erankly_format_sitemap_gmt_date( '' ) );
		$this->assertSame( '', erankly_format_sitemap_gmt_date( '0000-00-00 00:00:00' ) );
		$this->assertSame( '2024-01-02T03:04:05+00:00', erankly_format_sitemap_gmt_date( '2024-01-02 03:04:05' ) );
	}
}
