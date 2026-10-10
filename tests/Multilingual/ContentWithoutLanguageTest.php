<?php
/**
 * Tests for content without a language, or with a language no longer configured.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Hreflang;
use EasyRankly\Multilingual\Requests;
use EasyRankly\Multilingual\Translations;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Such content belongs to the default language everywhere: URL, lists, redirects, locale,
 * sitemap, hreflang and the editor.
 */
final class ContentWithoutLanguageTest extends WP_UnitTestCase {

	use WithLanguages;

	/**
	 * Post with no language at all.
	 *
	 * @var int
	 */
	private int $none;

	/**
	 * Post whose language ("de") was removed from the settings.
	 *
	 * @var int
	 */
	private int $removed;

	/**
	 * English post.
	 *
	 * @var int
	 */
	private int $english;

	/**
	 * Italian (default) and English, pretty permalinks, a post without language, one of a
	 * removed language and an English one, all in the same category.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'blog_public', '1' );
		$this->set_languages( 'it', 'en' );
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
		$GLOBALS['wp_sitemaps'] = null;

		$category      = self::factory()->category->create( array( 'slug' => 'news' ) );
		$this->none    = self::factory()->post->create(
			array(
				'post_name'     => 'none',
				'post_title'    => 'Senza lingua',
				'post_category' => array( $category ),
			)
		);
		$this->removed = self::factory()->post->create(
			array(
				'post_name'     => 'removed',
				'post_title'    => 'Lingua tolta',
				'post_category' => array( $category ),
			)
		);
		$this->english = self::factory()->post->create(
			array(
				'post_name'     => 'english',
				'post_title'    => 'English',
				'post_category' => array( $category ),
			)
		);
		wp_set_object_terms( $this->removed, 'de', Translations::LANGUAGE );
		Translations::set_language( $this->english, 'en' );
	}

	/**
	 * Back to a frontend request at the root, as a visitor.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		wp_set_current_user( 0 );
		parent::tear_down();
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
	 * Both posts are in the default language, with its URLs: no prefix.
	 */
	public function test_language_and_links_are_those_of_the_default(): void {
		$this->assertSame( 'it', Translations::language( $this->none ) );
		$this->assertSame( 'it', Translations::language( $this->removed ) );
		$this->assertSame( 'http://example.org/none/', get_permalink( $this->none ) );
		$this->assertSame( 'http://example.org/removed/', get_permalink( $this->removed ) );
	}

	/**
	 * Their own URL shows them in the default locale; under another prefix they are sent back to it.
	 */
	public function test_pages_and_redirects(): void {
		$requests = new Requests();

		foreach ( array(
			'none'    => $this->none,
			'removed' => $this->removed,
		) as $slug => $id ) {
			$this->go_to( "http://example.org/$slug/" );
			$this->assertTrue( is_single(), $slug );
			$this->assertSame( $id, get_queried_object_id(), $slug );
			$this->assertSame( 'it_IT', get_locale(), $slug );
			$this->assertSame( '', $requests->wrong_language_target(), $slug );

			$this->go_to( "http://example.org/en/$slug/" );
			$this->assertSame( "http://example.org/$slug/", $requests->wrong_language_target(), $slug );
		}
	}

	/**
	 * Home, category and search of the default language list them; those of English do not.
	 */
	public function test_lists_of_the_default_language_include_them(): void {
		foreach ( array( '', 'category/news/', '?s=lingua' ) as $path ) {
			$this->go_to( 'http://example.org/' . $path );
			$this->assertContains( $this->none, $this->main_ids(), "/$path" );
			$this->assertContains( $this->removed, $this->main_ids(), "/$path" );
			$this->assertNotContains( $this->english, $this->main_ids(), "/$path" );

			$this->go_to( 'http://example.org/en/' . $path );
			$this->assertNotContains( $this->none, $this->main_ids(), "/en/$path" );
			$this->assertNotContains( $this->removed, $this->main_ids(), "/en/$path" );
		}
	}

	/**
	 * The posts sitemap lists them at their URL without prefix.
	 */
	public function test_sitemap_lists_them_without_prefix(): void {
		// The sitemap server adds the sitemap query var, as on init in a real request.
		wp_sitemaps_get_server();
		$this->go_to( 'http://example.org/?sitemap=posts&sitemap-subtype=post&paged=1' );
		$this->assertSame( 'posts', get_query_var( 'sitemap' ) );
		$urls = array_column( wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'post' ), 'loc' );

		$this->assertContains( 'http://example.org/none/', $urls );
		$this->assertContains( 'http://example.org/removed/', $urls );
		$this->assertContains( 'http://example.org/en/english/', $urls );
	}

	/**
	 * Alone they have no alternates; linked to a translation they become its Italian version.
	 */
	public function test_hreflang_after_linking(): void {
		$this->go_to( 'http://example.org/none/' );
		$this->assertSame( array(), Hreflang::alternates() );

		$this->assertTrue(
			Translations::link(
				array(
					'it' => $this->none,
					'en' => $this->english,
				)
			)
		);

		$this->assertSame( 'it', wp_get_object_terms( $this->none, Translations::LANGUAGE, array( 'fields' => 'slugs' ) )[0] ?? '' );
		$this->go_to( 'http://example.org/en/english/' );
		$this->assertSame(
			array(
				'it-IT'     => 'http://example.org/none/',
				'en-US'     => 'http://example.org/en/english/',
				'x-default' => 'http://example.org/none/',
			),
			Hreflang::alternates()
		);
	}

	/**
	 * The editor shows them as Italian and offers them as Italian translations of other content.
	 */
	public function test_editor_sees_them_in_the_default_language(): void {
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/easyrankly/v1/translations/' . $this->none ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'it', $response->get_data()['language'] ?? '' );

		$request = new WP_REST_Request( 'GET', '/easyrankly/v1/translations/candidates' );
		$request->set_query_params(
			array(
				'post_type' => 'post',
				'language'  => 'it',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$ids = array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
		$this->assertContains( $this->none, $ids );
		$this->assertContains( $this->removed, $ids );
	}

	/**
	 * They keep Italian the default: English cannot become the first language until they have one.
	 */
	public function test_they_lock_the_default_language(): void {
		$this->assertTrue( Translations::has_content_without_language() );

		Translations::set_language( $this->none, 'it' );
		$this->assertTrue( Translations::has_content_without_language(), 'removed language still counts' );

		Translations::set_language( $this->removed, 'it' );
		$this->assertFalse( Translations::has_content_without_language() );
	}
}
