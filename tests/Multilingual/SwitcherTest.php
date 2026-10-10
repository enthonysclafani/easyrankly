<?php
/**
 * Tests for the language switcher block.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Switcher;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * The switcher is plain HTML rendered on the server, linking the page in every language.
 */
final class SwitcherTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Italian (default), English and French with pretty permalinks.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Settings::OPTION,
			array(
				'languages' => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English & co',
					),
					'fr' => array(
						'locale' => 'fr_FR',
						'name'   => 'Français',
					),
				),
			)
		);
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
	 * Rendered block.
	 *
	 * @return string
	 */
	private function render(): string {
		return do_blocks( '<!-- wp:easyrankly/language-switcher /-->' );
	}

	/**
	 * Link targets of the rendered block, keyed by hreflang.
	 *
	 * @return array<string, string>
	 */
	private function targets(): array {
		preg_match_all( '#<a href="([^"]+)" hreflang="([^"]+)"#', $this->render(), $matches );

		return array_combine( $matches[2], $matches[1] );
	}

	/**
	 * The block is registered and rendered on the server.
	 */
	public function test_block_is_registered_without_frontend_assets(): void {
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( 'easyrankly/language-switcher' );

		$this->assertNotNull( $block );
		$this->assertTrue( $block->is_dynamic() );
		$this->assertSame( array(), $block->view_script_handles );
		$this->assertSame( array(), $block->script_handles );
		$this->assertSame( array(), $block->style_handles );
		$this->assertSame( array(), $block->view_style_handles );

		$this->go_to( 'http://example.org/' );
		$this->render();
		$this->assertSame( array(), array_filter( wp_scripts()->queue, static fn( string $handle ): bool => str_starts_with( $handle, 'easyrankly' ) ) );
	}

	/**
	 * Without a build of the editor script, the block still comes from block.json, supports included.
	 */
	public function test_block_keeps_its_supports_without_build(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		$registry->unregister( 'easyrankly/language-switcher' );

		add_filter( 'block_type_metadata', array( Switcher::class, 'drop_editor_script' ) );
		register_block_type( dirname( __DIR__, 2 ) . '/blocks/language-switcher' );
		remove_filter( 'block_type_metadata', array( Switcher::class, 'drop_editor_script' ) );
		$block = $registry->get_registered( 'easyrankly/language-switcher' );

		$registry->unregister( 'easyrankly/language-switcher' );
		( new Switcher() )->register_block();

		$this->assertSame( array(), $block->editor_script_handles );
		$this->assertTrue( $block->supports['spacing']['margin'] ?? false );
	}

	/**
	 * Single content links its published translations; missing ones fall back to the language home.
	 */
	public function test_single_content_links_translations(): void {
		$it = self::factory()->post->create( array( 'post_name' => 'ciao' ) );
		$en = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$fr = self::factory()->post->create(
			array(
				'post_name'   => 'bonjour',
				'post_status' => 'draft',
			)
		);
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
				'fr' => $fr,
			)
		);

		$this->go_to( 'http://example.org/en/hello/' );
		$html = $this->render();

		$this->assertSame(
			array(
				'it-IT' => 'http://example.org/ciao/',
				'en-US' => 'http://example.org/en/hello/',
				'fr-FR' => 'http://example.org/fr/',
			),
			$this->targets()
		);
		$this->assertStringContainsString( '<nav class="wp-block-easyrankly-language-switcher" aria-label="Languages"><ul>', $html );
		$this->assertStringContainsString( 'hreflang="en-US" lang="en-US" aria-current="true">English &amp; co</a>', $html );
		$this->assertStringNotContainsString( 'aria-current="true">Italiano', $html );
	}

	/**
	 * Archives and search keep the page, on its first page, under each prefix; 404 links the homes.
	 */
	public function test_archives_search_and_404(): void {
		$category = self::factory()->category->create( array( 'slug' => 'news' ) );
		$posts    = self::factory()->post->create_many( 3, array( 'post_category' => array( $category ) ) );
		foreach ( $posts as $post ) {
			Translations::set_language( $post, 'en' );
		}
		update_option( 'posts_per_page', 1 );

		$this->go_to( 'http://example.org/en/category/news/page/2/' );
		$this->assertSame(
			array(
				'it-IT' => 'http://example.org/category/news/',
				'en-US' => 'http://example.org/en/category/news/',
				'fr-FR' => 'http://example.org/fr/category/news/',
			),
			$this->targets()
		);

		$this->go_to( 'http://example.org/fr/?s=word' );
		$this->assertSame( 'http://example.org/en/?s=word', $this->targets()['en-US'] );

		$this->go_to( 'http://example.org/en/does-not-exist/' );
		$this->assertTrue( is_404() );
		$this->assertSame( 'http://example.org/', $this->targets()['it-IT'] );
	}

	/**
	 * With one language the block renders nothing.
	 */
	public function test_nothing_with_one_language(): void {
		$this->set_languages( 'it' );
		$this->go_to( 'http://example.org/' );

		$this->assertSame( '', $this->render() );
	}
}
