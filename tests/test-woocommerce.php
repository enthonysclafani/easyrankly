<?php
/**
 * WooCommerce Product schema mapping.
 *
 * WooCommerce is not installed here, so these tests load minimal stand-ins for the
 * types the mapper uses (see tests/fixtures/woocommerce-doubles.inc). That file is
 * required inside set_up(), and the whole class runs in separate processes, because
 * the plugin detects WooCommerce with `function_exists( 'wc_get_product' )`:
 * defining that function in the shared process would flip every other test in the
 * suite to "WooCommerce is active".
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

final class ERankly_Woocommerce_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once __DIR__ . '/fixtures/woocommerce-doubles.inc';

		erankly_load_content_helpers();
		erankly_load_default_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		require_once ERANKLY_PATH . 'includes/compatibility.php';
		require_once ERANKLY_PATH . 'includes/compatibility-woocommerce.php';
	}

	/** @param array<string,mixed> $stub Product data for the wc_get_product() double. */
	private function make_product( array $stub = array() ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Prodotto di prova',
			)
		);
		$this->assertIsInt( $post_id );

		update_post_meta( $post_id, '_erankly_test_wc_product', $stub );

		return (int) $post_id;
	}

	public function test_the_plugin_detects_woocommerce_through_the_cart_function(): void {
		// Detection is function-based, not class- or plugin-based.
		$this->assertTrue( function_exists( 'wc_get_product' ) );
		$this->assertTrue( erankly_is_woocommerce_active() );
	}

	public function test_build_product_data_returns_empty_for_a_non_product_post(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( array(), erankly_build_woocommerce_product_data( (int) $post_id ) );
	}

	public function test_build_product_data_returns_empty_when_no_product_is_resolvable(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		// No stub meta stored, so the double returns false.
		$this->assertSame( array(), erankly_build_woocommerce_product_data( (int) $post_id ) );
	}

	public function test_build_product_data_maps_the_core_fields(): void {
		$post_id = $this->make_product(
			array(
				'name'              => 'Maglietta',
				'description'       => 'Descrizione lunga',
				'short_description' => 'Descrizione breve',
				'sku'               => 'MAG-001',
				'price'             => '19.90',
				'in_stock'          => true,
			)
		);

		$data = erankly_build_woocommerce_product_data( $post_id );

		$this->assertSame( 'Product', $data['@type'] );
		$this->assertSame( 'Maglietta', $data['name'] );
		$this->assertSame( 'Descrizione breve', $data['description'] );
		$this->assertSame( 'MAG-001', $data['sku'] );
		$this->assertSame( (string) get_permalink( $post_id ), $data['url'] );
		$this->assertStringEndsWith( '#product', $data['@id'] );
	}

	public function test_build_product_data_prefers_the_short_description(): void {
		$post_id = $this->make_product(
			array(
				'name'              => 'Prodotto',
				'short_description' => 'Breve',
				'description'       => 'Lunga',
			)
		);

		$this->assertSame( 'Breve', erankly_build_woocommerce_product_data( $post_id )['description'] );
	}

	public function test_build_product_data_falls_back_to_the_long_description(): void {
		$post_id = $this->make_product(
			array(
				'name'              => 'Prodotto',
				'short_description' => '',
				'description'       => 'Solo descrizione lunga',
			)
		);

		$this->assertSame( 'Solo descrizione lunga', erankly_build_woocommerce_product_data( $post_id )['description'] );
	}

	public function test_build_product_data_omits_an_empty_sku(): void {
		$post_id = $this->make_product( array( 'name' => 'Senza SKU' ) );

		$this->assertArrayNotHasKey( 'sku', erankly_build_woocommerce_product_data( $post_id ) );
	}

	public function test_build_product_data_maps_gtin_from_the_global_unique_id(): void {
		$with_gtin = $this->make_product(
			array(
				'name'             => 'Con GTIN',
				'global_unique_id' => '1234567890123',
			)
		);
		$without   = $this->make_product( array( 'name' => 'Senza GTIN' ) );

		$this->assertSame( '1234567890123', erankly_build_woocommerce_product_data( $with_gtin )['gtin'] );
		$this->assertArrayNotHasKey( 'gtin', erankly_build_woocommerce_product_data( $without ) );
	}

	public function test_build_product_data_adds_the_brand_when_the_taxonomy_has_a_term(): void {
		register_taxonomy( 'product_brand', array( 'product' ), array( 'public' => false ) );

		$post_id = $this->make_product( array( 'name' => 'Prodotto con brand' ) );
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'product_brand',
				'name'     => 'Marchio',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'product_brand' );

		$brand = erankly_build_woocommerce_product_data( $post_id )['brand'];

		$this->assertSame( 'Brand', $brand['@type'] );
		$this->assertSame( 'Marchio', $brand['name'] );
	}

	public function test_build_product_data_adds_the_aggregate_rating_only_with_ratings(): void {
		$rated = $this->make_product(
			array(
				'name'           => 'Con rating',
				'average_rating' => '4.5',
				'rating_count'   => 7,
			)
		);
		$none  = $this->make_product(
			array(
				'name'           => 'Senza rating',
				'average_rating' => '0',
				'rating_count'   => 0,
			)
		);

		$data = erankly_build_woocommerce_product_data( $rated );

		$this->assertSame( 'AggregateRating', $data['aggregateRating']['@type'] );
		$this->assertSame( '4.5', $data['aggregateRating']['ratingValue'] );
		$this->assertSame( '7', $data['aggregateRating']['reviewCount'] );

		$this->assertArrayNotHasKey( 'aggregateRating', erankly_build_woocommerce_product_data( $none ) );
	}

	public function test_build_product_data_attaches_approved_reviews(): void {
		$post_id = $this->make_product(
			array(
				'name'  => 'Con recensione',
				'price' => '10.00',
			)
		);

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'comment_author'   => 'Recensore',
				'comment_content'  => 'Ottimo prodotto',
			)
		);
		update_comment_meta( $comment_id, 'rating', 5 );

		$reviews = erankly_build_woocommerce_product_data( $post_id )['review'];

		$this->assertNotEmpty( $reviews );
		$this->assertSame( 'Review', $reviews[0]['@type'] );
		$this->assertSame( 'Recensore', $reviews[0]['author']['name'] );
		$this->assertSame( '5', $reviews[0]['reviewRating']['ratingValue'] );
	}

	public function test_build_product_data_ignores_unapproved_reviews(): void {
		$post_id = $this->make_product( array( 'name' => 'Senza recensioni' ) );

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'review',
				'comment_approved' => 0,
				'comment_content'  => 'In attesa',
			)
		);

		$this->assertArrayNotHasKey( 'review', erankly_build_woocommerce_product_data( $post_id ) );
	}

	public function test_simple_offer_maps_price_currency_and_stock(): void {
		$product = new WC_Product(
			array(
				'price'    => '25.00',
				'in_stock' => true,
			)
		);

		$offer = erankly_get_woocommerce_simple_offer( $product, 'https://example.org/p/' );

		$this->assertSame( 'Offer', $offer['@type'] );
		$this->assertSame( '25.00', $offer['price'] );
		$this->assertSame( 'EUR', $offer['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $offer['availability'] );
		$this->assertSame( 'https://example.org/p/', $offer['url'] );
		$this->assertArrayNotHasKey( 'priceValidUntil', $offer );
	}

	public function test_simple_offer_reports_out_of_stock(): void {
		$product = new WC_Product(
			array(
				'price'    => '25.00',
				'in_stock' => false,
			)
		);

		$offer = erankly_get_woocommerce_simple_offer( $product, 'https://example.org/p/' );

		$this->assertSame( 'https://schema.org/OutOfStock', $offer['availability'] );
	}

	public function test_simple_offer_is_empty_without_a_price(): void {
		$product = new WC_Product( array( 'price' => '' ) );

		$this->assertSame( array(), erankly_get_woocommerce_simple_offer( $product, 'https://example.org/p/' ) );
	}

	public function test_simple_offer_adds_price_valid_until_for_a_dated_sale(): void {
		$product = new WC_Product(
			array(
				'price'    => '9.99',
				'on_sale'  => true,
				'sale_to'  => new WC_DateTime( '2026-12-31 23:59:59' ),
				'in_stock' => true,
			)
		);

		$offer = erankly_get_woocommerce_simple_offer( $product, 'https://example.org/p/' );

		$this->assertSame( '2026-12-31', $offer['priceValidUntil'] );
	}

	public function test_simple_offer_skips_the_sale_date_for_an_undated_sale(): void {
		$product = new WC_Product(
			array(
				'price'    => '9.99',
				'on_sale'  => true,
				'sale_to'  => null,
				'in_stock' => true,
			)
		);

		$offer = erankly_get_woocommerce_simple_offer( $product, 'https://example.org/p/' );

		$this->assertArrayNotHasKey( 'priceValidUntil', $offer );
	}

	public function test_variable_offer_maps_the_price_range(): void {
		$product = new WC_Product_Variable(
			array(
				'variation_prices' => array( 'price' => array( 10, 30, 20 ) ),
				'visible_children' => array( 1, 2, 3 ),
				'in_stock'         => true,
			)
		);

		$offer = erankly_get_woocommerce_variable_offer( $product, 'https://example.org/p/' );

		$this->assertSame( 'AggregateOffer', $offer['@type'] );
		$this->assertSame( 10, $offer['lowPrice'] );
		$this->assertSame( 30, $offer['highPrice'] );
		$this->assertSame( 3, $offer['offerCount'] );
		$this->assertSame( 'EUR', $offer['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $offer['availability'] );
	}

	public function test_variable_offer_falls_back_to_the_price_count_without_visible_children(): void {
		$product = new WC_Product_Variable(
			array(
				'variation_prices' => array( 'price' => array( 5, 15 ) ),
				'visible_children' => array(),
			)
		);

		$offer = erankly_get_woocommerce_variable_offer( $product, 'https://example.org/p/' );

		$this->assertSame( 2, $offer['offerCount'] );
	}

	public function test_variable_offer_is_empty_without_prices(): void {
		$product = new WC_Product_Variable( array( 'variation_prices' => array( 'price' => array() ) ) );

		$this->assertSame( array(), erankly_get_woocommerce_variable_offer( $product, 'https://example.org/p/' ) );
	}

	public function test_variable_product_is_offered_as_an_aggregate_offer(): void {
		$post_id = $this->make_product(
			array(
				'name'             => 'Variabile',
				'variable'         => true,
				'variation_prices' => array( 'price' => array( 12, 24 ) ),
				'visible_children' => array( 1, 2 ),
				'price'            => '12',
			)
		);

		$offers = erankly_build_woocommerce_product_data( $post_id )['offers'];

		$this->assertSame( 'AggregateOffer', $offers['@type'] );
		$this->assertSame( 12, $offers['lowPrice'] );
	}

	public function test_the_public_wrapper_delegates_once_woocommerce_is_detected(): void {
		$post_id = $this->make_product(
			array(
				'name'  => 'Prodotto',
				'price' => '5.00',
			)
		);

		// The wrapper short-circuits only while wc_get_product() is missing, which
		// is exactly what the detection function checks.
		$this->assertSame(
			erankly_build_woocommerce_product_data( $post_id ),
			erankly_get_woocommerce_product_data( $post_id )
		);
		$this->assertSame( 'Product', erankly_get_woocommerce_product_data( $post_id )['@type'] );
	}
}
