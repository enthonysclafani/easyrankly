<?php
/**
 * Tests for the core sitemaps extension.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Sitemap;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * The core sitemaps list only indexable pages that are their own canonical.
 */
final class SitemapTest extends WP_UnitTestCase {

	/**
	 * Public site, meta registered, default settings, fresh sitemap server.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		update_option( 'blog_public', '1' );
		delete_option( Settings::OPTION );
		$GLOBALS['wp_sitemaps'] = null;
	}

	/**
	 * URLs of one sitemap page, as core would print them.
	 *
	 * @param string $provider Provider name.
	 * @param string $subtype  Post type or taxonomy.
	 * @return list<string>
	 */
	private function urls( string $provider, string $subtype = '' ): array {
		$object = wp_sitemaps_get_server()->registry->get_provider( $provider );
		$this->assertNotNull( $object );

		return array_column( $object->get_url_list( 1, $subtype ), 'loc' );
	}

	/**
	 * Names of the sitemaps listed in the index.
	 *
	 * @return list<string>
	 */
	private function index(): array {
		return array_column( wp_sitemaps_get_server()->index->get_sitemap_list(), 'loc' );
	}

	/**
	 * Posts with their own noindex or a canonical elsewhere are left out; lastmod stays.
	 */
	public function test_noindex_and_foreign_canonical_posts_are_left_out(): void {
		$kept      = self::factory()->post->create();
		$noindex   = self::factory()->post->create();
		$canonical = self::factory()->post->create();
		$empty     = self::factory()->post->create();
		update_post_meta( $noindex, '_easyrankly_noindex', true );
		update_post_meta( $canonical, '_easyrankly_canonical', 'https://example.org/elsewhere/' );
		add_post_meta( $empty, '_easyrankly_canonical', '' );

		$urls = $this->urls( 'posts', 'post' );

		$this->assertContains( get_permalink( $kept ), $urls );
		$this->assertContains( get_permalink( $empty ), $urls );
		$this->assertNotContains( get_permalink( $noindex ), $urls );
		$this->assertNotContains( get_permalink( $canonical ), $urls );

		$entries = wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'post' );
		$this->assertArrayHasKey( 'lastmod', $entries[0] );
	}

	/**
	 * A canonical equal to the page's own URL is dropped on save, so the page stays in the sitemap.
	 */
	public function test_self_canonical_keeps_posts_and_terms_listed(): void {
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->category->create();
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );

		// Another scheme is still the same page.
		update_post_meta( $post_id, '_easyrankly_canonical', set_url_scheme( get_permalink( $post_id ), 'https' ) );
		update_term_meta( $term_id, '_easyrankly_canonical', get_category_link( $term_id ) . '/' );

		$this->assertSame( '', get_post_meta( $post_id, '_easyrankly_canonical', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_easyrankly_canonical', true ) );
		$this->assertContains( get_permalink( $post_id ), $this->urls( 'posts', 'post' ) );
		$this->assertContains( get_category_link( $term_id ), $this->urls( 'taxonomies', 'category' ) );
	}

	/**
	 * Terms with their own noindex are left out.
	 */
	public function test_noindex_terms_are_left_out(): void {
		$kept   = self::factory()->category->create();
		$hidden = self::factory()->category->create();
		self::factory()->post->create( array( 'post_category' => array( $kept, $hidden ) ) );
		update_term_meta( $hidden, '_easyrankly_noindex', true );

		$urls = $this->urls( 'taxonomies', 'category' );

		$this->assertContains( get_category_link( $kept ), $urls );
		$this->assertNotContains( get_category_link( $hidden ), $urls );
	}

	/**
	 * Noindex rules in the settings remove whole sitemaps from the index.
	 */
	public function test_noindex_rules_remove_whole_sitemaps(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->post->create(
			array(
				'tags_input'  => array( 'x' ),
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);

		$before = implode( ' ', $this->index() );
		$this->assertStringContainsString( 'page', $before );
		$this->assertStringContainsString( 'post_tag', $before );
		$this->assertStringContainsString( 'users', $before );

		update_option( Settings::OPTION, array( 'noindex' => array( 'single-page', 'term-post_tag', 'author' ) ) );
		$GLOBALS['wp_sitemaps'] = null;

		$after = implode( ' ', $this->index() );
		$this->assertStringNotContainsString( 'sitemap-subtype=page', $after );
		$this->assertStringNotContainsString( 'post_tag', $after );
		$this->assertStringNotContainsString( 'users', $after );
		$this->assertStringContainsString( 'sitemap-subtype=post', $after );
	}

	/**
	 * Generic rules remove every post type or taxonomy.
	 */
	public function test_generic_rules_remove_all(): void {
		update_option( Settings::OPTION, array( 'noindex' => array( 'term' ) ) );

		$this->assertSame( array(), wp_sitemaps_get_server()->registry->get_provider( 'taxonomies' )->get_object_subtypes() );
	}
}
