<?php
/** Compatibility guards: WooCommerce absence, external SEO owners and legacy aliases. */

final class ERankly_Compatibility_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
	}

	public function tear_down(): void {
		erankly_clear_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_woocommerce_is_reported_absent_in_this_environment(): void {
		$this->assertFalse( function_exists( 'wc_get_product' ) );
		$this->assertFalse( erankly_is_woocommerce_active() );
		$this->assertFalse( erankly_woocommerce_structured_data_enabled() );
	}

	public function test_product_schema_rendering_follows_woocommerce_state(): void {
		$post_id = self::factory()->post->create();

		// No WooCommerce structured data: EasyRankly owns the Product schema.
		$this->assertTrue( erankly_should_render_woocommerce_product_schema( $post_id ) );

		add_filter( 'erankly_woocommerce_structured_data_enabled', '__return_true' );
		try {
			$this->assertTrue( erankly_woocommerce_structured_data_enabled() );
			$this->assertFalse( erankly_should_render_woocommerce_product_schema( $post_id ) );
		} finally {
			remove_filter( 'erankly_woocommerce_structured_data_enabled', '__return_true' );
		}

		add_filter( 'erankly_render_woocommerce_product_schema', '__return_false' );
		try {
			$this->assertFalse( erankly_should_render_woocommerce_product_schema( $post_id ) );
		} finally {
			remove_filter( 'erankly_render_woocommerce_product_schema', '__return_false' );
		}
	}

	public function test_get_woocommerce_product_data_is_empty_without_woocommerce(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'product' ) );

		// The wrapper refuses to run (and never loads the WC-only implementation)
		// when wc_get_product() is undefined.
		$this->assertFalse( function_exists( 'wc_get_product' ) );
		$this->assertSame( array(), erankly_get_woocommerce_product_data( $post_id ) );
	}

	public function test_woocommerce_review_and_brand_helpers_work_without_the_wc_runtime(): void {
		// These two helpers only rely on core APIs, so they can be exercised even
		// though the WC_Product-typed helpers in the same file cannot be called.
		require_once ERANKLY_PATH . 'includes/compatibility-woocommerce.php';

		$post_id = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );

		$this->assertSame( array(), erankly_get_woocommerce_product_reviews( $post_id ) );

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 1,
				'comment_type'     => 'review',
				'comment_author'   => 'Reviewer Name',
				'comment_content'  => 'A very good product',
			)
		);
		update_comment_meta( $comment_id, 'rating', '4' );

		$reviews = erankly_get_woocommerce_product_reviews( $post_id );
		$this->assertCount( 1, $reviews );
		$this->assertSame( 'Review', $reviews[0]['@type'] );
		$this->assertSame( 'Reviewer Name', $reviews[0]['author']['name'] );
		$this->assertSame( '4', $reviews[0]['reviewRating']['ratingValue'] );

		register_taxonomy( 'product_brand', 'post', array( 'public' => true ) );
		$brand_term = self::factory()->term->create( array( 'taxonomy' => 'product_brand', 'name' => 'Acme' ) );
		wp_set_post_terms( $post_id, array( $brand_term ), 'product_brand' );

		$this->assertSame( 'Acme', erankly_get_woocommerce_product_brand( $post_id ) );

		$no_brand = self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->assertSame( '', erankly_get_woocommerce_product_brand( $no_brand ) );
	}

	public function test_should_serve_sitemaps_requires_the_module_and_no_external_seo(): void {
		erankly_update_plugin_settings( array( 'enable_sitemap' => 0 ) );
		erankly_clear_settings_cache();
		$this->assertFalse( erankly_should_suppress_sitemaps() );
		$this->assertFalse( erankly_should_serve_sitemaps() );

		erankly_update_plugin_settings( array( 'enable_sitemap' => 1 ) );
		erankly_clear_settings_cache();
		$this->assertTrue( erankly_should_serve_sitemaps() );

		add_filter( 'erankly_suppress_sitemaps_with_external_seo', '__return_true' );
		try {
			$this->assertTrue( erankly_should_suppress_sitemaps() );
			$this->assertFalse( erankly_should_serve_sitemaps() );
		} finally {
			remove_filter( 'erankly_suppress_sitemaps_with_external_seo', '__return_true' );
		}
	}

	public function test_legacy_sitemap_filter_treats_its_boolean_as_suppression(): void {
		// Despite its "enable_..." name, the legacy hook's value is the suppression
		// state, so returning true suppresses -- and therefore disables serving.
		erankly_update_plugin_settings( array( 'enable_sitemap' => 1 ) );
		erankly_clear_settings_cache();

		add_filter( 'erankly_enable_sitemaps_with_external_seo', '__return_true' );
		try {
			$this->assertTrue( erankly_should_suppress_sitemaps() );
			$this->assertFalse( erankly_should_serve_sitemaps() );
		} finally {
			remove_filter( 'erankly_enable_sitemaps_with_external_seo', '__return_true' );
		}
	}

	public function test_legacy_hook_aliases_map_to_canonical_hooks(): void {
		$aliases = erankly_legacy_developer_api_hook_aliases();

		$this->assertNotEmpty( $aliases );
		$this->assertArrayHasKey( 'easyrankly_title', $aliases );
		$this->assertSame( 'erankly_title', $aliases['easyrankly_title'] );
		$this->assertSame( 'erankly_og_title', $aliases['easyrankly_og_title'] );
		$this->assertSame( 'erankly_schema', $aliases['easyrankly_schema'] );
	}

	public function test_legacy_hook_alias_is_actually_wired_to_the_canonical_hook(): void {
		$legacy = static fn( string $value ): string => $value . '|legacy';
		add_filter( 'easyrankly_title', $legacy, 10, 1 );

		try {
			// Applying the canonical hook runs the legacy hook through the alias.
			$this->assertSame( 'Base|legacy', apply_filters( 'erankly_title', 'Base' ) );
		} finally {
			remove_filter( 'easyrankly_title', $legacy, 10 );
		}
	}

	public function test_no_external_seo_owner_lets_easy_rankly_own_the_head(): void {
		// The external-owner list is memoised during bootstrap, so a conflicting
		// SEO plugin cannot be simulated here; this covers the no-conflict path.
		$this->assertFalse( erankly_detect_external_seo_head_owner() );
		$this->assertTrue( erankly_should_output_head() );

		add_filter( 'erankly_enable_head_output', '__return_false' );
		try {
			$this->assertFalse( erankly_should_output_head() );
		} finally {
			remove_filter( 'erankly_enable_head_output', '__return_false' );
		}
	}

	public function test_compatibility_notice_is_silent_without_a_conflict(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		// No external owner: an administrator is never shown the conflict notice.
		ob_start();
		erankly_compatibility_notice_external_seo();
		$this->assertSame( '', (string) ob_get_clean() );

		// Without the capability it is silent regardless.
		wp_set_current_user( 0 );
		ob_start();
		erankly_compatibility_notice_external_seo();
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
