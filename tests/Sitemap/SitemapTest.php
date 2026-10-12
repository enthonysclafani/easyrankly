<?php
/**
 * Tests for the core sitemaps extension.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Sitemap;

use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Settings\Settings;
use EasyRankly\Sitemap\Sitemap;
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
	 * Back to plain permalinks: the rewrite globals outlive the database rollback.
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
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
	 * The query of a sitemap page joins no meta: joining it to exclude noindex posts took
	 * seconds per page on large sites. The excluded posts come from a separate query.
	 */
	public function test_sitemap_page_query_joins_no_meta(): void {
		$noindex = self::factory()->post->create();
		update_post_meta( $noindex, '_easyrankly_noindex', true );
		self::factory()->post->create();

		$requests = array();
		$record   = static function ( $sql ) use ( &$requests ) {
			$requests[] = (string) $sql;
			return $sql;
		};
		add_filter( 'posts_request', $record );
		$urls = $this->urls( 'posts', 'post' );
		remove_filter( 'posts_request', $record );

		$pages = array_filter( $requests, static fn( string $sql ): bool => str_contains( $sql, 'LIMIT' ) );
		$this->assertNotEmpty( $pages );
		foreach ( $pages as $sql ) {
			$this->assertStringNotContainsString( $GLOBALS['wpdb']->postmeta, $sql );
		}
		$this->assertNotContains( get_permalink( $noindex ), $urls );
	}

	/**
	 * Exclusions already in the query arguments are kept.
	 */
	public function test_existing_exclusions_are_kept(): void {
		$noindex = self::factory()->post->create();
		update_post_meta( $noindex, '_easyrankly_noindex', true );

		$args = ( new Sitemap() )->filter_posts_query_args(
			array(
				'post_type'    => 'post',
				'post__not_in' => array( 7 ),
			)
		);

		$this->assertSame( array( 7, $noindex ), $args['post__not_in'] ?? null );
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
	 * Posts that an exact forced redirect sends elsewhere are left out; regex rules and
	 * paths that only resemble a post's address are not considered.
	 */
	public function test_posts_with_forced_redirect_are_left_out(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$kept       = self::factory()->post->create( array( 'post_name' => 'kept' ) );
		$redirected = self::factory()->post->create( array( 'post_name' => 'moved' ) );
		$gone       = self::factory()->post->create( array( 'post_name' => 'gone' ) );
		$by_regex   = self::factory()->post->create( array( 'post_name' => 'regex-only' ) );
		$page       = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'old-page',
			)
		);

		update_option(
			Redirects::FORCED_OPTION,
			array(
				array(
					'source' => '/moved',
					'regex'  => false,
					'target' => '/kept',
					'code'   => 301,
				),
				array(
					'source' => '/gone',
					'regex'  => false,
					'target' => '',
					'code'   => 410,
				),
				array(
					'source' => '^/regex-only$',
					'regex'  => true,
					'target' => '/kept',
					'code'   => 301,
				),
				array(
					'source' => '/kept/2',
					'regex'  => false,
					'target' => '/',
					'code'   => 302,
				),
				array(
					'source' => '/old-page',
					'regex'  => false,
					'target' => 'https://example.org/new/',
					'code'   => 301,
				),
			),
			true
		);

		$posts = $this->urls( 'posts', 'post' );
		$this->assertContains( get_permalink( $kept ), $posts );
		$this->assertContains( get_permalink( $by_regex ), $posts );
		$this->assertNotContains( get_permalink( $redirected ), $posts );
		$this->assertNotContains( get_permalink( $gone ), $posts );
		$this->assertNotContains( get_permalink( $page ), $this->urls( 'posts', 'page' ) );
	}

	/**
	 * The posts page is left out while there are no posts, when Robots makes it noindex.
	 */
	public function test_empty_posts_page_is_left_out(): void {
		$posts_page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		update_option( 'page_for_posts', $posts_page );

		$this->go_to( get_permalink( $posts_page ) );
		$this->assertStringContainsString( 'noindex', get_echo( 'wp_robots' ) );
		$this->assertNotContains( get_permalink( $posts_page ), $this->urls( 'posts', 'page' ) );

		self::factory()->post->create();
		$this->assertContains( get_permalink( $posts_page ), $this->urls( 'posts', 'page' ) );
	}

	/**
	 * WooCommerce's shop page is left out while there are no products; without the product
	 * post type its option is not read.
	 */
	public function test_empty_shop_page_is_left_out(): void {
		$shop = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_shop_page_id', (string) $shop );

		$this->assertContains( get_permalink( $shop ), $this->urls( 'posts', 'page' ) );

		register_post_type(
			'product',
			array(
				'public'      => true,
				'has_archive' => 'shop',
			)
		);
		$this->assertNotContains( get_permalink( $shop ), $this->urls( 'posts', 'page' ) );

		self::factory()->post->create( array( 'post_type' => 'product' ) );
		$this->assertContains( get_permalink( $shop ), $this->urls( 'posts', 'page' ) );

		_unregister_post_type( 'product' );
	}

	/**
	 * Without forced redirects the query gets no exclusion list.
	 */
	public function test_no_forced_redirects_adds_no_exclusion(): void {
		delete_option( Redirects::FORCED_OPTION );

		$args = ( new Sitemap() )->filter_posts_query_args( array( 'post_type' => 'post' ) );

		$this->assertIsArray( $args );
		$this->assertArrayNotHasKey( 'post__not_in', $args );
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
		$this->assertStringNotContainsString( 'post_tag', $after );
		$this->assertStringNotContainsString( 'users', $after );
		$this->assertStringContainsString( 'sitemap-subtype=post', $after );

		// The home listing the latest posts is not a single page: it stays, alone in the pages sitemap.
		$this->assertStringContainsString( 'sitemap-subtype=page', $after );
		$this->assertSame( array( home_url( '/' ) ), $this->urls( 'posts', 'page' ) );
	}

	/**
	 * With single pages noindex, the static front page and the posts page stay in the pages sitemap,
	 * since their own contexts are indexable; with those contexts noindex they leave.
	 */
	public function test_front_page_and_posts_page_follow_their_own_contexts(): void {
		$front = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$blog  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->post->create();
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );

		update_option( Settings::OPTION, array( 'noindex' => array( 'single-page' ) ) );
		$GLOBALS['wp_sitemaps'] = null;
		$this->assertStringContainsString( 'sitemap-subtype=page', implode( ' ', $this->index() ) );
		$urls = $this->urls( 'posts', 'page' );
		$this->assertContains( get_permalink( $front ), $urls );
		$this->assertContains( get_permalink( $blog ), $urls );
		$this->assertNotContains( get_permalink( $other ), $urls );

		// Their own noindex still removes them, even though the query lists them by ID.
		update_post_meta( $front, '_easyrankly_noindex', '1' );
		$this->assertSame( array( get_permalink( $blog ) ), $this->urls( 'posts', 'page' ) );
		delete_post_meta( $front, '_easyrankly_noindex' );

		// "Post type archives" covers the posts page (Context): noindex there, out of the sitemap.
		update_option( Settings::OPTION, array( 'noindex' => array( 'archive' ) ) );
		$GLOBALS['wp_sitemaps'] = null;
		$urls                   = $this->urls( 'posts', 'page' );
		$this->assertNotContains( get_permalink( $blog ), $urls );
		$this->assertContains( get_permalink( $front ), $urls );
		$this->assertContains( get_permalink( $other ), $urls );

		update_option( Settings::OPTION, array( 'noindex' => array( 'single-page', 'archive' ) ) );
		$GLOBALS['wp_sitemaps'] = null;
		$this->assertSame( array( get_permalink( $front ) ), $this->urls( 'posts', 'page' ) );
	}

	/**
	 * A term whose address an exact forced redirect sends elsewhere is left out, like a post.
	 */
	public function test_terms_redirected_by_forced_rules_are_left_out(): void {
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies(); // Re-adds the category permastruct for the new structure.
		$moved = self::factory()->category->create( array( 'slug' => 'old-news' ) );
		$kept  = self::factory()->category->create( array( 'slug' => 'news' ) );
		self::factory()->post->create( array( 'post_category' => array( $moved, $kept ) ) );
		update_option(
			Redirects::FORCED_OPTION,
			array(
				array(
					'source' => '/category/old-news',
					'regex'  => false,
					'target' => '/category/news/',
					'code'   => 301,
				),
			)
		);

		$urls = $this->urls( 'taxonomies', 'category' );
		$this->assertNotContains( get_category_link( $moved ), $urls );
		$this->assertContains( get_category_link( $kept ), $urls );
	}

	/**
	 * Empty terms with a description of their own stay listed, as their page stays indexable
	 * (Robots); empty terms without one are still left out, and other terms queries keep `hide_empty`.
	 */
	public function test_empty_terms_with_description_are_listed(): void {
		$described = self::factory()->category->create( array( 'description' => 'A guide to the topic.' ) );
		$blank     = self::factory()->category->create( array( 'description' => '   ' ) );
		$empty     = self::factory()->category->create( array( 'description' => '' ) ); // The factory writes one by default.
		$full      = self::factory()->category->create();
		self::factory()->post->create( array( 'post_category' => array( $full ) ) );

		$urls = $this->urls( 'taxonomies', 'category' );

		$this->assertContains( get_category_link( $described ), $urls );
		$this->assertContains( get_category_link( $full ), $urls );
		$this->assertNotContains( get_category_link( $blank ), $urls );
		$this->assertNotContains( get_category_link( $empty ), $urls );

		$others = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'fields'     => 'ids',
			)
		);
		$this->assertIsArray( $others );
		$this->assertNotContains( $described, $others );
	}

	/**
	 * A taxonomy whose only listed term is empty with a description gets its sitemap: core counts
	 * the pages with the same arguments.
	 */
	public function test_taxonomy_with_only_described_empty_terms_has_a_sitemap(): void {
		self::factory()->tag->create( array( 'description' => 'Everything about this tag.' ) );

		$provider = wp_sitemaps_get_server()->registry->get_provider( 'taxonomies' );
		$this->assertNotNull( $provider );
		$this->assertSame( 1, $provider->get_max_num_pages( 'post_tag' ) );
		$this->assertStringContainsString( 'sitemap-subtype=post_tag', implode( ' ', $this->index() ) );
	}

	/**
	 * Generic rules remove every post type or taxonomy.
	 */
	public function test_generic_rules_remove_all(): void {
		update_option( Settings::OPTION, array( 'noindex' => array( 'term' ) ) );

		$this->assertSame( array(), wp_sitemaps_get_server()->registry->get_provider( 'taxonomies' )->get_object_subtypes() );
	}
}
