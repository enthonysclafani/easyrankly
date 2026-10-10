<?php
/**
 * Tests for a post type archive whose queried object is a page, as WooCommerce sets it for the shop.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Canonical;

use EasyRankly\Canonical\Canonical;
use EasyRankly\Meta\Meta;
use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use EasyRankly\Titles\Titles;
use WP_UnitTestCase;

/**
 * The page standing for an archive gives it its SEO fields; the archive keeps its own URL.
 *
 * WooCommerce 11.0+ sets the shop page as the queried object of the product archive in
 * pre_get_posts, and on a shop used as front page also turns the page query into the archive.
 * The test reproduces both with a `product` post type, without WooCommerce.
 */
final class ArchivePageTest extends WP_UnitTestCase {

	/**
	 * ID of the page standing for the archive.
	 *
	 * @var int
	 */
	private int $shop = 0;

	/**
	 * Products with an archive at /shop/, a "Shop" page, meta registered, default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		register_post_type(
			'product',
			array(
				'public'      => true,
				'has_archive' => 'shop',
				'rewrite'     => array( 'slug' => 'product' ),
			)
		);
		flush_rewrite_rules();
		( new Meta() )->register_meta();
		update_option( 'blogname', 'Site' );
		update_option( 'blog_public', '1' );
		update_option( 'posts_per_page', 1 );
		delete_option( Settings::OPTION );

		$this->shop = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Shop',
				'post_name'  => 'shop',
			)
		);
		self::factory()->post->create_many( 2, array( 'post_type' => 'product' ) );

		add_action( 'pre_get_posts', array( $this, 'set_shop_page' ) );
	}

	/**
	 * Removes the product post type and the query hook.
	 */
	public function tear_down(): void {
		remove_action( 'pre_get_posts', array( $this, 'set_shop_page' ) );
		unregister_post_type( 'product' );
		parent::tear_down();
	}

	/**
	 * What WooCommerce does to the main query on the shop (WC_Query::pre_get_posts()).
	 *
	 * @param \WP_Query $query Query about to run.
	 */
	public function set_shop_page( \WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( $query->is_page() && 'page' === get_option( 'show_on_front' ) && absint( $query->get( 'page_id' ) ) === $this->shop ) {
			$query->set( 'post_type', 'product' );
			$query->set( 'page_id', '' );
			$query->is_singular          = false;
			$query->is_post_type_archive = true;
			$query->is_archive           = true;
			$query->is_page              = true;
		} elseif ( ! $query->is_post_type_archive( 'product' ) ) {
			return;
		}

		$query->queried_object    = get_post( $this->shop );
		$query->queried_object_id = $this->shop;
	}

	/**
	 * Canonical tags printed by core and by the plugin for the current request.
	 *
	 * @return string
	 */
	private function canonical(): string {
		return get_echo( 'rel_canonical' ) . get_echo( array( new Canonical(), 'print_archive_canonical' ) );
	}

	/**
	 * The archive reads title, description and noindex from the page, with the canonical of the archive.
	 */
	public function test_archive_uses_the_fields_of_its_page(): void {
		update_post_meta( $this->shop, '_easyrankly_title', 'Our shop' );
		update_post_meta( $this->shop, '_easyrankly_description', 'Everything we sell.' );
		$link = get_post_type_archive_link( 'product' );

		$this->go_to( $link );
		$this->assertTrue( is_post_type_archive( 'product' ) );
		$this->assertSame( $this->shop, get_queried_object_id() );

		$this->assertSame( 'Our shop', Titles::title() );
		$this->assertSame( 'Everything we sell.', Titles::description() );
		$this->assertSame( '<link rel="canonical" href="' . esc_url( $link ) . '" />' . "\n", $this->canonical() );

		$social = get_echo( array( new Social(), 'print_tags' ) );
		$this->assertStringContainsString( '<meta property="og:url" content="' . esc_attr( $link ) . '" />', $social );
		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $social );

		$schema = get_echo( array( new Schema(), 'print_graph' ) );
		$this->assertStringContainsString( '"@id":"' . $link . '#webpage"', $schema );
		$this->assertStringContainsString( '"@type":"CollectionPage"', $schema );

		update_post_meta( $this->shop, '_easyrankly_noindex', true );
		$this->go_to( $link );
		$this->assertStringContainsString( 'noindex', get_echo( 'wp_robots' ) );
		$this->assertSame( '', $this->canonical() );
	}

	/**
	 * Later pages of the archive keep the page number; a canonical override on the page wins.
	 */
	public function test_archive_pagination_and_override(): void {
		$link = get_post_type_archive_link( 'product' );

		$this->go_to( $link . 'page/2/' );
		$this->assertStringContainsString( 'href="' . esc_url( $link . 'page/2/' ) . '"', $this->canonical() );

		update_post_meta( $this->shop, '_easyrankly_canonical', 'https://example.org/catalog/' );
		$this->go_to( $link );
		$this->assertSame( '<link rel="canonical" href="https://example.org/catalog/" />' . "\n", $this->canonical() );
	}

	/**
	 * A shop used as front page gets the home canonical and the fields of its page.
	 */
	public function test_shop_on_front_page(): void {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->shop );
		update_post_meta( $this->shop, '_easyrankly_description', 'Everything we sell.' );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_front_page() );
		$this->assertFalse( is_singular() );

		$this->assertSame( '<link rel="canonical" href="' . esc_url( home_url( '/' ) ) . '" />' . "\n", $this->canonical() );
		$this->assertSame( 'Everything we sell.', Titles::description() );
	}

	/**
	 * Only posts share as articles: a product is a website.
	 */
	public function test_product_is_not_an_article(): void {
		$product = self::factory()->post->create( array( 'post_type' => 'product' ) );

		$this->go_to( get_permalink( $product ) );
		$social = get_echo( array( new Social(), 'print_tags' ) );

		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $social );
		$this->assertStringNotContainsString( 'article:published_time', $social );
	}
}
