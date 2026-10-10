<?php
/**
 * Tests for the menus of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Menus;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * Navigation blocks show the menu of the language being viewed.
 */
final class MenusTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Italian (default) and English.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en' );
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Navigation blocks show the navigation menu chosen for the language, if it is one.
	 */
	public function test_navigation_block_uses_menu_of_language(): void {
		$it   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Navigazione',
				'post_content' => '<!-- wp:navigation-link {"label":"Chi siamo","url":"https://example.org/chi-siamo"} /-->',
			)
		);
		$en   = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'Navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"About","url":"https://example.org/about"} /-->',
			)
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION ),
				array(
					Menus::SETTING => array(
						'en' => array(
							$it => $en,
							$en => $page,
						),
					),
				)
			)
		);
		$block = '<!-- wp:navigation {"ref":' . $it . '} /-->';

		$this->go_to( 'http://example.org/en/' );
		$html = do_blocks( $block );
		$this->assertStringContainsString( 'About', $html );
		$this->assertStringNotContainsString( 'Chi siamo', $html );

		// A target that is not a navigation menu is ignored.
		$this->assertSame( 0, Menus::navigation( $en, 'en' ) );

		$this->go_to( 'http://example.org/' );
		$this->assertStringContainsString( 'Chi siamo', do_blocks( $block ) );
	}
}
