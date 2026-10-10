<?php
/**
 * Tests for robots directives and canonical URLs on real requests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Robots;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * Robots and canonical tags in <head> follow meta, settings and pagination.
 */
final class RobotsCanonicalTest extends WP_UnitTestCase {

	/**
	 * Public site, meta registered, default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		// Multisite test installs start with pretty permalinks; each test chooses its own.
		$this->set_permalink_structure( '' );
		update_option( 'blog_public', '1' );
		delete_option( Settings::OPTION );
	}

	/**
	 * Data provider: plain and pretty permalinks.
	 *
	 * @return array<string, array{string}>
	 */
	public function permalinks(): array {
		return array(
			'plain'  => array( '' ),
			'pretty' => array( '/%postname%/' ),
		);
	}

	/**
	 * The robots meta tag printed by core for the current request.
	 *
	 * @return string
	 */
	private function robots(): string {
		return get_echo( 'wp_robots' );
	}

	/**
	 * Canonical tags printed by core and by the plugin for the current request.
	 *
	 * @return string
	 */
	private function canonical(): string {
		return get_echo( 'rel_canonical' ) . get_echo( array( new \EasyRankly\Canonical\Canonical(), 'print_archive_canonical' ) );
	}

	/**
	 * Per-post noindex and nofollow reach the core robots tag and drop the canonical.
	 */
	public function test_post_meta_sets_robots_and_drops_canonical(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_easyrankly_noindex', true );
		update_post_meta( $post_id, '_easyrankly_nofollow', true );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringContainsString( 'noindex, nofollow', $this->robots() );
		$this->assertSame( '', $this->canonical() );
	}

	/**
	 * An indexable post keeps the core canonical; the override replaces it.
	 */
	public function test_post_canonical_override(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertStringContainsString( 'href="' . get_permalink( $post_id ) . '"', $this->canonical() );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );

		update_post_meta( $post_id, '_easyrankly_canonical', 'https://example.org/original/' );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 1, substr_count( $this->canonical(), 'rel="canonical"' ) );
		$this->assertStringContainsString( 'href="https://example.org/original/"', $this->canonical() );
	}

	/**
	 * Term archives get a canonical with the page number, never with stray query args.
	 *
	 * @dataProvider permalinks
	 *
	 * @param string $structure Permalink structure.
	 */
	public function test_term_canonical_with_pagination( string $structure ): void {
		$this->set_permalink_structure( $structure );
		create_initial_taxonomies(); // Re-adds the category permastruct for the new structure.
		flush_rewrite_rules();
		update_option( 'posts_per_page', 1 );
		$term_id = self::factory()->category->create( array( 'slug' => 'news' ) );
		self::factory()->post->create_many( 2, array( 'post_category' => array( $term_id ) ) );
		$link = get_category_link( $term_id );

		$this->go_to( add_query_arg( 'utm_source', 'x', $link ) );
		$this->assertSame( '<link rel="canonical" href="' . esc_url( $link ) . '" />' . "\n", $this->canonical() );

		$this->go_to( '' === $structure ? add_query_arg( 'paged', 2, $link ) : $link . 'page/2/' );
		$expected = '' === $structure ? add_query_arg( 'paged', 2, $link ) : $link . 'page/2/';
		$this->assertStringContainsString( 'href="' . esc_url( $expected ) . '"', $this->canonical() );
	}

	/**
	 * The posts home page has a canonical; search and 404 do not.
	 */
	public function test_home_search_and_404(): void {
		$this->go_to( home_url( '/' ) );
		$this->assertStringContainsString( 'href="' . home_url( '/' ) . '"', $this->canonical() );

		$this->go_to( home_url( '/?s=x' ) );
		$this->assertSame( '', $this->canonical() );
		$this->assertStringContainsString( 'noindex', $this->robots() );

		$this->go_to( home_url( '/?p=999999' ) );
		$this->assertSame( '', $this->canonical() );
	}

	/**
	 * An archive with no posts is noindex without canonical; one with posts, or a term with a
	 * description of its own, stays indexable.
	 *
	 * @dataProvider permalinks
	 *
	 * @param string $structure Permalink structure.
	 */
	public function test_empty_archives_are_noindex( string $structure ): void {
		$this->set_permalink_structure( $structure );
		create_initial_taxonomies(); // Re-adds the category permastruct for the new structure.
		flush_rewrite_rules();
		// The factory gives every term a description: these two have none.
		$empty     = self::factory()->category->create(
			array(
				'slug'        => 'empty',
				'description' => '',
			)
		);
		$described = self::factory()->category->create(
			array(
				'slug'        => 'described',
				'description' => 'A guide to the topic.',
			)
		);
		$full      = self::factory()->category->create(
			array(
				'slug'        => 'full',
				'description' => '',
			)
		);
		self::factory()->post->create( array( 'post_category' => array( $full ) ) );

		$this->go_to( get_category_link( $empty ) );
		$this->assertTrue( is_category() );
		$this->assertStringContainsString( 'noindex', $this->robots() );
		$this->assertSame( '', $this->canonical() );

		$this->go_to( get_category_link( $described ) );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );

		$this->go_to( get_category_link( $full ) );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );
	}

	/**
	 * Author and posts-page archives with no posts are noindex; the home page is not.
	 */
	public function test_other_empty_archives_and_home(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->go_to( get_author_posts_url( $author ) );
		$this->assertTrue( is_author() );
		$this->assertStringContainsString( 'noindex', $this->robots() );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_home() );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );

		$posts_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		update_option( 'page_for_posts', $posts_page );
		$this->go_to( get_permalink( $posts_page ) );
		$this->assertTrue( is_home() );
		$this->assertStringContainsString( 'noindex', $this->robots() );
	}

	/**
	 * Context rules from the settings noindex author and date archives, with no canonical.
	 */
	public function test_settings_noindex_contexts(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_date'   => '2026-01-15 10:00:00',
			)
		);

		$this->go_to( get_author_posts_url( $author ) );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );
		$this->assertStringContainsString( 'rel="canonical"', $this->canonical() );

		update_option( Settings::OPTION, array( 'noindex' => array( 'author', 'date' ) ) );

		$this->go_to( get_author_posts_url( $author ) );
		$this->assertStringContainsString( 'noindex', $this->robots() );
		$this->assertSame( '', $this->canonical() );

		$this->go_to( get_month_link( 2026, 1 ) );
		$this->assertStringContainsString( 'noindex', $this->robots() );
	}

	/**
	 * A per-type rule noindexes attachments only, and term meta noindexes one term.
	 */
	public function test_type_rule_and_term_meta(): void {
		update_option( 'wp_attachment_pages_enabled', 1 );
		update_option( Settings::OPTION, array( 'noindex' => array( 'single-attachment' ) ) );
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_object( 'image.jpg', $post_id, array( 'post_mime_type' => 'image/jpeg' ) );

		$this->go_to( get_permalink( $attachment_id ) );
		$this->assertStringContainsString( 'noindex', $this->robots() );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertStringNotContainsString( 'noindex', $this->robots() );

		$term_id = self::factory()->tag->create();
		self::factory()->post->create( array( 'tags_input' => array( get_term( $term_id )->name ) ) );
		update_term_meta( $term_id, '_easyrankly_noindex', true );
		$this->go_to( get_tag_link( $term_id ) );
		$this->assertStringContainsString( 'noindex', $this->robots() );
	}

	/**
	 * Invalid rules are rejected by the settings schema.
	 */
	public function test_invalid_rules_are_rejected(): void {
		update_option( Settings::OPTION, array( 'noindex' => array( 'author', 'Bad Key' ) ) );

		$this->assertSame( array(), Settings::value( 'noindex' ) );
	}

	/**
	 * A site hidden from search engines stays fully hidden.
	 */
	public function test_private_site_keeps_core_rules(): void {
		update_option( 'blog_public', '0' );
		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringContainsString( 'noindex, nofollow', $this->robots() );
	}
}
