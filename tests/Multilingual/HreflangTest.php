<?php
/**
 * Tests for hreflang links and the sitemaps of every language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Hreflang;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * Alternates list only indexable versions; sitemaps list every language.
 */
final class HreflangTest extends WP_UnitTestCase {

	/**
	 * Italian (default), English and French, pretty permalinks, public site.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		update_option( 'blog_public', '1' );
		$this->set_settings( array() );
		$this->set_permalink_structure( '/%postname%/' );
		$GLOBALS['wp_sitemaps'] = null;
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Stores the three languages plus extra settings.
	 *
	 * @param array<string, mixed> $extra Other settings.
	 */
	private function set_settings( array $extra ): void {
		update_option(
			Settings::OPTION,
			$extra + array(
				'languages' => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
					'fr' => array(
						'locale' => 'fr_FR',
						'name'   => 'Français',
					),
				),
			)
		);
	}

	/**
	 * Creates an Italian post with an English and a French translation.
	 *
	 * @param array<string, mixed> $args Arguments of every post.
	 * @return array<string, int> Language => post ID.
	 */
	private function group( array $args = array() ): array {
		$map = array();
		foreach ( array( 'it', 'en', 'fr' ) as $language ) {
			$map[ $language ] = self::factory()->post->create( $args + array( 'post_name' => 'post-' . $language ) );
		}
		Translations::link( $map );

		return $map;
	}

	/**
	 * Output of the hreflang links.
	 *
	 * @return string
	 */
	private function head(): string {
		ob_start();
		( new Hreflang() )->print_links();
		return (string) ob_get_clean();
	}

	/**
	 * Every published translation is listed, with x-default on the default language.
	 */
	public function test_translations_are_alternates(): void {
		$this->group();

		$this->go_to( 'http://example.org/en/post-en/' );

		$this->assertSame(
			'<link rel="alternate" hreflang="it-IT" href="http://example.org/post-it/" />' . "\n"
			. '<link rel="alternate" hreflang="en-US" href="http://example.org/en/post-en/" />' . "\n"
			. '<link rel="alternate" hreflang="fr-FR" href="http://example.org/fr/post-fr/" />' . "\n"
			. '<link rel="alternate" hreflang="x-default" href="http://example.org/post-it/" />' . "\n",
			$this->head()
		);
	}

	/**
	 * Drafts, protected, noindex and canonicalized translations are left out.
	 */
	public function test_non_indexable_translations_are_left_out(): void {
		$map = $this->group();
		update_post_meta( $map['fr'], '_easyrankly_noindex', true );

		$this->go_to( 'http://example.org/en/post-en/' );
		$this->assertSame( array( 'it-IT', 'en-US', 'x-default' ), array_keys( Hreflang::alternates() ) );

		wp_update_post(
			array(
				'ID'          => $map['it'],
				'post_status' => 'draft',
			)
		);
		$this->go_to( 'http://example.org/en/post-en/' );
		$this->assertSame( array(), Hreflang::alternates() );

		$map = $this->group( array( 'post_password' => '' ) );
		wp_update_post(
			array(
				'ID'            => $map['fr'],
				'post_password' => 'secret',
			)
		);
		update_post_meta( $map['it'], '_easyrankly_canonical', 'https://example.org/elsewhere/' );
		$this->go_to( get_permalink( $map['en'] ) );
		$this->assertSame( array(), Hreflang::alternates() );
	}

	/**
	 * A page that is noindex, or not its own canonical, gets no alternates.
	 */
	public function test_non_indexable_page_has_no_alternates(): void {
		$map = $this->group();
		update_post_meta( $map['en'], '_easyrankly_canonical', 'http://example.org/post-it/' );

		$this->go_to( 'http://example.org/en/post-en/' );
		$this->assertSame( '', $this->head() );

		$this->set_settings( array( 'noindex' => array( 'single' ) ) );
		$this->go_to( 'http://example.org/fr/post-fr/' );
		$this->assertSame( '', $this->head() );
	}

	/**
	 * Content without translations, archives and later pages get no alternates.
	 */
	public function test_other_pages_have_no_alternates(): void {
		self::factory()->post->create( array( 'post_name' => 'alone' ) );
		$category = self::factory()->category->create( array( 'slug' => 'news' ) );
		self::factory()->post->create_many( 3, array( 'post_category' => array( $category ) ) );
		update_option( 'posts_per_page', 1 );

		foreach ( array( '/alone/', '/en/category/news/', '/en/page/2/', '/?s=x' ) as $path ) {
			$this->go_to( 'http://example.org' . $path );
			$this->assertSame( array(), Hreflang::alternates(), $path );
		}
	}

	/**
	 * The home listing posts exists in every language.
	 */
	public function test_home_of_posts_has_every_language(): void {
		$this->go_to( 'http://example.org/fr/' );

		$this->assertSame(
			array(
				'it-IT'     => 'http://example.org/',
				'en-US'     => 'http://example.org/en/',
				'fr-FR'     => 'http://example.org/fr/',
				'x-default' => 'http://example.org/',
			),
			Hreflang::alternates()
		);
	}

	/**
	 * A static front page lists its translations, as language homes.
	 */
	public function test_static_front_page_lists_language_homes(): void {
		$map = $this->group( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $map['it'] );
		wp_update_post(
			array(
				'ID'          => $map['fr'],
				'post_status' => 'draft',
			)
		);

		$this->go_to( 'http://example.org/en/' );

		$this->assertSame(
			array(
				'it-IT'     => 'http://example.org/',
				'en-US'     => 'http://example.org/en/',
				'x-default' => 'http://example.org/',
			),
			Hreflang::alternates()
		);
	}

	/**
	 * Codes for hreflang come from the locale; a repeated code keeps the first language.
	 */
	public function test_codes(): void {
		$this->assertSame( 'de-DE', Hreflang::code( 'de_DE_formal' ) );
		$this->assertSame( 'fi', Hreflang::code( 'fi' ) );

		update_option(
			Settings::OPTION,
			array(
				'languages' => array(
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
					'us' => array(
						'locale' => 'en_US',
						'name'   => 'American',
					),
				),
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( 'http://example.org/us/' );

		$this->assertSame(
			array(
				'en-US'     => 'http://example.org/',
				'x-default' => 'http://example.org/',
			),
			Hreflang::alternates()
		);
	}

	/**
	 * Alternates of a post are cached until the content changes.
	 */
	public function test_alternates_are_cached(): void {
		global $wpdb;

		$map = $this->group();
		$this->go_to( 'http://example.org/en/post-en/' );
		Hreflang::alternates();

		$queries = $wpdb->num_queries;
		Hreflang::alternates();
		$this->assertSame( $queries, $wpdb->num_queries );

		update_post_meta( $map['fr'], '_easyrankly_noindex', true );
		$this->assertArrayNotHasKey( 'fr-FR', Hreflang::alternates() );
	}

	/**
	 * Sitemaps list the content of every language, with its own URL, and the language homes.
	 */
	public function test_sitemaps_cover_every_language(): void {
		$map    = $this->group();
		$server = wp_sitemaps_get_server();
		$this->go_to( 'http://example.org/?sitemap=posts&sitemap-subtype=post&paged=1' );
		$this->assertSame( 'posts', get_query_var( 'sitemap' ) );

		$posts = array_column( $server->registry->get_provider( 'posts' )->get_url_list( 1, 'post' ), 'loc' );
		$this->assertContains( 'http://example.org/post-it/', $posts );
		$this->assertContains( 'http://example.org/en/post-en/', $posts );
		$this->assertContains( 'http://example.org/fr/post-fr/', $posts );

		$homes = $server->registry->get_provider( 'languages' );
		$this->assertNotNull( $homes );
		$this->assertSame( array( 'http://example.org/en/', 'http://example.org/fr/' ), array_column( $homes->get_url_list( 1 ), 'loc' ) );
		$this->assertContains( 'http://example.org/wp-sitemap-languages-1.xml', array_column( $server->index->get_sitemap_list(), 'loc' ) );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $map['it'] );
		$this->assertSame( array(), $homes->get_url_list( 1 ) );
		$this->assertNotContains( 'http://example.org/wp-sitemap-languages-1.xml', array_column( $server->index->get_sitemap_list(), 'loc' ) );
	}
}
