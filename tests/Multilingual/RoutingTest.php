<?php
/**
 * Tests for language prefixes, homes per language and filtered queries.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Routing;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * Each language lives under its prefix; the default language keeps the plain URLs.
 */
final class RoutingTest extends WP_UnitTestCase {

	/**
	 * Italian (default), English and French with pretty permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( array( 'it', 'en', 'fr' ) );
		$this->pretty_permalinks();
	}

	/**
	 * Pretty permalinks, with the category and tag rules that core adds only when permalinks are on.
	 */
	private function pretty_permalinks(): void {
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Stores languages by slug; the first is the default.
	 *
	 * @param string[] $slugs Language slugs.
	 */
	private function set_languages( array $slugs ): void {
		$locales   = array(
			'it' => 'it_IT',
			'en' => 'en_US',
			'fr' => 'fr_FR',
		);
		$languages = array();
		foreach ( $slugs as $slug ) {
			$languages[ $slug ] = array(
				'locale' => $locales[ $slug ],
				'name'   => strtoupper( $slug ),
			);
		}
		update_option( Settings::OPTION, array( 'languages' => $languages ) );
	}

	/**
	 * Creates a post in a language.
	 *
	 * @param string               $language Language slug, empty for none.
	 * @param array<string, mixed> $args     Post arguments.
	 * @return int
	 */
	private function post( string $language, array $args = array() ): int {
		$id = self::factory()->post->create( $args );
		if ( '' !== $language ) {
			Translations::set_language( $id, $language );
		}
		return $id;
	}

	/**
	 * IDs of the posts returned by the main query.
	 *
	 * @return list<int>
	 */
	private function main_ids(): array {
		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * A prefixed copy of the rules comes first; REST and sitemap rules stay unprefixed.
	 */
	public function test_rewrite_rules_have_language_prefixes(): void {
		$rules = get_option( 'rewrite_rules' );
		$keys  = array_keys( $rules );

		$this->assertSame( '(en|fr)/?$', $keys[0] );
		$this->assertSame( 'index.php?erankly_lang=$matches[1]', $rules['(en|fr)/?$'] );
		$this->assertSame( 'index.php?category_name=$matches[2]&erankly_lang=$matches[1]', $rules['(en|fr)/category/(.+?)/?$'] );
		$this->assertSame( 'index.php?&paged=$matches[2]&erankly_lang=$matches[1]', $rules['(en|fr)/page/?([0-9]{1,})/?$'] );
		$this->assertArrayHasKey( 'category/(.+?)/?$', $rules );
		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( 'wp-sitemap', str_starts_with( $key, '(en|fr)' ) ? $key : '' );
			$this->assertStringNotContainsString( 'wp-json', str_starts_with( $key, '(en|fr)' ) ? $key : '' );
		}
	}

	/**
	 * Changing the languages rebuilds the rules; one language removes the prefixes.
	 */
	public function test_rules_follow_the_languages(): void {
		$this->set_languages( array( 'it', 'en' ) );
		$this->assertArrayHasKey( '(en)/?$', get_option( 'rewrite_rules' ) );

		$this->set_languages( array( 'it' ) );
		$this->assertArrayNotHasKey( '(en)/?$', get_option( 'rewrite_rules' ) );
		$this->assertSame( 'http://example.org/hello/', Routing::url( 'http://example.org/hello/', 'en' ) );
	}

	/**
	 * Prefixes are added, replaced and removed; other sites are left alone.
	 */
	public function test_url_changes_only_the_prefix(): void {
		$this->assertSame( 'http://example.org/en/hello/', Routing::url( 'http://example.org/hello/', 'en' ) );
		$this->assertSame( 'https://example.org/fr/hello/?a=1', Routing::url( 'https://example.org/en/hello/?a=1', 'fr' ) );
		$this->assertSame( 'http://example.org/hello/', Routing::url( 'http://example.org/en/hello/', 'it' ) );
		$this->assertSame( 'http://example.org/en/', Routing::url( 'http://example.org', 'en' ) );
		$this->assertSame( 'http://example.org/', Routing::url( 'http://example.org/fr', 'it' ) );
		$this->assertSame( 'http://example.org/english/', Routing::url( 'http://example.org/english/', 'it' ) );
		$this->assertSame( 'http://example.org.evil/hello/', Routing::url( 'http://example.org.evil/hello/', 'en' ) );
		$this->assertSame( 'https://other.test/hello/', Routing::url( 'https://other.test/hello/', 'en' ) );
	}

	/**
	 * Post links carry the prefix of the post's language; content without a language has none.
	 */
	public function test_post_links_follow_the_post_language(): void {
		$en   = $this->post( 'en', array( 'post_name' => 'hello-en' ) );
		$none = $this->post( '', array( 'post_name' => 'hello' ) );
		$page = $this->post(
			'fr',
			array(
				'post_type' => 'page',
				'post_name' => 'about-fr',
			)
		);

		$this->assertSame( 'http://example.org/en/hello-en/', get_permalink( $en ) );
		$this->assertSame( 'http://example.org/hello/', get_permalink( $none ) );
		$this->assertSame( 'http://example.org/fr/about-fr/', get_permalink( $page ) );
	}

	/**
	 * Each language home lists only its content; the default one includes content without a language.
	 */
	public function test_homes_list_their_language(): void {
		$it   = $this->post( 'it' );
		$none = $this->post( '' );
		$en   = $this->post( 'en' );
		$fr   = $this->post( 'fr' );

		$this->go_to( 'http://example.org/en/' );
		$this->assertTrue( is_home() );
		$this->assertSame( array( $en ), $this->main_ids() );
		$this->assertSame( 'en_US', get_locale() );
		$this->assertSame( 'http://example.org/en/', home_url( '/' ) );
		$this->assertSame( 'http://example.org/en/', home_url() );
		$this->assertSame( 'http://example.org/wp-json/', get_rest_url() );

		$this->go_to( 'http://example.org/' );
		$this->assertEqualsCanonicalizing( array( $it, $none ), $this->main_ids() );
		$this->assertSame( 'it_IT', get_locale() );
		$this->assertNotContains( $fr, $this->main_ids() );
	}

	/**
	 * Archives, pagination and secondary queries follow the language being viewed.
	 */
	public function test_archives_and_secondary_queries_are_filtered(): void {
		$category = self::factory()->category->create( array( 'slug' => 'news' ) );
		$it       = $this->post( 'it', array( 'post_category' => array( $category ) ) );
		$en       = $this->post( 'en', array( 'post_category' => array( $category ) ) );

		$this->go_to( 'http://example.org/en/category/news/' );
		$this->assertTrue( is_category() );
		$this->assertSame( $category, get_queried_object_id() );
		$this->assertSame( array( $en ), $this->main_ids() );
		$this->assertSame( 'http://example.org/en/category/news/', get_category_link( $category ) );

		$secondary = new \WP_Query( array( 'post_type' => 'post' ) );
		$this->assertSame( array( $en ), array_map( 'intval', wp_list_pluck( $secondary->posts, 'ID' ) ) );

		$this->go_to( 'http://example.org/category/news/' );
		$this->assertSame( array( $it ), $this->main_ids() );
		$this->assertSame( 'http://example.org/category/news/', get_category_link( $category ) );
	}

	/**
	 * A custom taxonomy archive keeps its own term as the queried object.
	 */
	public function test_custom_taxonomy_archive_keeps_its_term(): void {
		register_taxonomy( 'genre', 'post', array( 'public' => true ) );
		flush_rewrite_rules( false );
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'genre',
				'slug'     => 'jazz',
			)
		);
		$en   = $this->post( 'en' );
		wp_set_object_terms( $en, array( $term ), 'genre' );

		$this->go_to( 'http://example.org/en/genre/jazz/' );

		$this->assertTrue( is_tax( 'genre' ) );
		$this->assertSame( $term, get_queried_object_id() );
		$this->assertSame( array( $en ), $this->main_ids() );
	}

	/**
	 * Core canonical redirects leave language URLs alone.
	 */
	public function test_core_canonical_keeps_language_urls(): void {
		$category = self::factory()->category->create( array( 'slug' => 'news' ) );
		$this->post(
			'en',
			array(
				'post_name'     => 'hello-en',
				'post_category' => array( $category ),
			)
		);
		update_option( 'posts_per_page', 1 );
		$this->post( 'en', array( 'post_category' => array( $category ) ) );

		foreach ( array( '/en/', '/en/category/news/', '/en/page/2/', '/en/hello-en/', '/en/category/news/page/2/' ) as $path ) {
			$this->go_to( 'http://example.org' . $path );
			$this->assertFalse( is_404(), $path );
			$this->assertNull( redirect_canonical( 'http://example.org' . $path, false ), $path );
		}
	}

	/**
	 * Single content under another language's prefix is sent to its own URL.
	 */
	public function test_wrong_language_redirects_to_own_url(): void {
		$routing = new Routing();
		$en      = $this->post( 'en', array( 'post_name' => 'hello-en' ) );
		$it      = $this->post( 'it', array( 'post_name' => 'ciao' ) );

		$this->go_to( 'http://example.org/en/hello-en/' );
		$this->assertTrue( is_single() );
		$this->assertSame( '', $routing->wrong_language_target() );

		$this->go_to( 'http://example.org/hello-en/' );
		$this->assertSame( 'http://example.org/en/hello-en/', $routing->wrong_language_target() );

		$this->go_to( 'http://example.org/fr/ciao/' );
		$this->assertSame( 'http://example.org/ciao/', $routing->wrong_language_target() );
		$this->assertSame( $it, get_queried_object_id() );
	}

	/**
	 * With a static front page, each language home shows its translation; a missing one is a 404.
	 */
	public function test_static_front_page_per_language(): void {
		$front    = $this->post( 'it', array( 'post_type' => 'page' ) );
		$front_en = $this->post( 'en', array( 'post_type' => 'page' ) );
		$blog     = $this->post( 'it', array( 'post_type' => 'page' ) );
		$blog_en  = $this->post(
			'en',
			array(
				'post_type' => 'page',
				'post_name' => 'blog-en',
			)
		);
		$post_en  = $this->post( 'en' );
		$this->post( 'it' );
		Translations::link(
			array(
				'it' => $front,
				'en' => $front_en,
			)
		);
		Translations::link(
			array(
				'it' => $blog,
				'en' => $blog_en,
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );

		$this->assertSame( 'http://example.org/', get_permalink( $front ) );
		$this->assertSame( 'http://example.org/en/', get_permalink( $front_en ) );

		$this->go_to( 'http://example.org/en/' );
		$this->assertTrue( is_front_page() );
		$this->assertSame( $front_en, get_queried_object_id() );
		$this->assertSame( 'http://example.org/', get_permalink( $front ) );
		$this->assertSame( 'http://example.org/en/', get_permalink( $front_en ) );
		$this->assertSame( '', ( new Routing() )->wrong_language_target() );

		$this->go_to( 'http://example.org/en/blog-en/' );
		$this->assertTrue( is_home() );
		$this->assertSame( $blog_en, get_queried_object_id() );
		$this->assertSame( array( $post_en ), $this->main_ids() );

		$this->go_to( 'http://example.org/fr/' );
		$this->assertTrue( is_404() );

		$this->go_to( 'http://example.org/' );
		$this->assertTrue( is_front_page() );
		$this->assertSame( $front, get_queried_object_id() );
	}

	/**
	 * With plain permalinks the language is a query var.
	 */
	public function test_plain_permalinks_use_the_query_var(): void {
		$this->set_permalink_structure( '' );
		$en = $this->post( 'en' );
		$this->post( 'it' );

		$this->assertSame( add_query_arg( 'erankly_lang', 'en', home_url( '/?p=' . $en ) ), get_permalink( $en ) );

		$this->go_to( 'http://example.org/?erankly_lang=en' );
		$this->assertTrue( is_home() );
		$this->assertSame( array( $en ), $this->main_ids() );
		$this->assertSame( 'en_US', get_locale() );
	}

	/**
	 * Admin and REST requests see every language and keep the site locale.
	 */
	public function test_admin_and_rest_are_not_filtered(): void {
		$it = $this->post( 'it' );
		$en = $this->post( 'en' );

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$this->assertFalse( Routing::is_frontend() );
		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		$this->assertEqualsCanonicalizing( array( $it, $en ), array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );

		$_SERVER['REQUEST_URI'] = '/fr/';
		set_current_screen( 'edit.php' );
		$query = new \WP_Query( array( 'post_type' => 'post' ) );
		$this->assertEqualsCanonicalizing( array( $it, $en ), array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
		$this->assertSame( 'fr', Routing::current() );
		$this->assertNotSame( 'fr_FR', get_locale() );
		set_current_screen( 'front' );
	}

	/**
	 * With one language nothing is prefixed or filtered.
	 */
	public function test_single_language_changes_nothing(): void {
		$this->set_languages( array( 'it' ) );
		$a = $this->post( 'it', array( 'post_name' => 'a' ) );
		$b = self::factory()->post->create();
		wp_set_object_terms( $b, 'en', Translations::LANGUAGE );

		$this->assertSame( 'http://example.org/a/', get_permalink( $a ) );
		$this->go_to( 'http://example.org/' );
		$this->assertEqualsCanonicalizing( array( $a, $b ), $this->main_ids() );
		$this->assertSame( '', Routing::current() );
	}

	/**
	 * A single post costs the same queries with or without languages.
	 */
	public function test_single_post_costs_no_extra_query(): void {
		global $wpdb;

		$this->post( 'en', array( 'post_name' => 'hello-en' ) );

		$this->set_languages( array( 'it' ) );
		$this->go_to( 'http://example.org/hello-en/' );
		$queries = $wpdb->num_queries;
		$this->go_to( 'http://example.org/hello-en/' );
		$without = $wpdb->num_queries - $queries;

		$this->set_languages( array( 'it', 'en', 'fr' ) );
		$this->go_to( 'http://example.org/en/hello-en/' );
		$queries = $wpdb->num_queries;
		$this->go_to( 'http://example.org/en/hello-en/' );

		$this->assertTrue( is_single() );
		$this->assertSame( $without, $wpdb->num_queries - $queries );
	}
}
