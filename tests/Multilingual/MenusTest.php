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
 * Classic locations and navigation blocks show the menu of the language being viewed.
 */
final class MenusTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Italian (default) and English, a theme with one location.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en' );
		$this->set_permalink_structure( '/%postname%/' );
		register_nav_menus( array( 'primary' => 'Primary' ) );
		( new Menus() )->register_locations();
	}

	/**
	 * Back to a frontend request at the root, without the test locations.
	 */
	public function tear_down(): void {
		unregister_nav_menu( 'primary' );
		unregister_nav_menu( Menus::location( 'primary', 'en' ) );
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Creates a classic menu with one custom link.
	 *
	 * @param string $label Link label.
	 * @return int Menu ID.
	 */
	private function menu( string $label ): int {
		$menu = (int) wp_create_nav_menu( 'Menu ' . $label );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => $label,
				'menu-item-url'    => 'https://example.org/' . strtolower( $label ),
				'menu-item-status' => 'publish',
			)
		);

		return $menu;
	}

	/**
	 * Prints the primary location.
	 *
	 * @return string
	 */
	private function primary(): string {
		return (string) wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'echo'           => false,
				'fallback_cb'    => '__return_empty_string',
			)
		);
	}

	/**
	 * Each theme location gets one location per other language, named after both.
	 */
	public function test_language_locations_are_registered(): void {
		$this->assertSame( 'Primary (English)', get_registered_nav_menus()[ Menus::location( 'primary', 'en' ) ] ?? null );
		$this->assertArrayNotHasKey( Menus::location( 'primary', 'it' ), get_registered_nav_menus() );
	}

	/**
	 * Pages of a language show the menu of its location; the default language keeps the theme location.
	 */
	public function test_location_shows_menu_of_language(): void {
		set_theme_mod(
			'nav_menu_locations',
			array(
				'primary'                          => $this->menu( 'Chi-siamo' ),
				Menus::location( 'primary', 'en' ) => $this->menu( 'About' ),
			)
		);

		$this->go_to( 'http://example.org/en/' );
		$this->assertStringContainsString( '>About<', $this->primary() );
		$this->assertStringNotContainsString( 'Chi-siamo', $this->primary() );

		$this->go_to( 'http://example.org/' );
		$this->assertStringContainsString( '>Chi-siamo<', $this->primary() );
	}

	/**
	 * A language location without a menu keeps the menu of the default language.
	 */
	public function test_empty_language_location_keeps_default_menu(): void {
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $this->menu( 'Chi-siamo' ) ) );

		$this->go_to( 'http://example.org/en/' );

		$this->assertStringContainsString( '>Chi-siamo<', $this->primary() );
	}

	/**
	 * Admin reads the locations as stored, so Appearance > Menus edits the real assignments.
	 */
	public function test_admin_reads_stored_locations(): void {
		$it = $this->menu( 'Chi-siamo' );
		$en = $this->menu( 'About' );
		set_theme_mod(
			'nav_menu_locations',
			array(
				'primary'                          => $it,
				Menus::location( 'primary', 'en' ) => $en,
			)
		);

		$_SERVER['REQUEST_URI'] = '/en/wp-admin/nav-menus.php';
		set_current_screen( 'nav-menus' );

		$this->assertSame( $it, get_nav_menu_locations()['primary'] );
		set_current_screen( 'front' );
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

	/**
	 * With one language there are no language locations.
	 */
	public function test_single_language_registers_nothing(): void {
		unregister_nav_menu( Menus::location( 'primary', 'en' ) );
		$this->set_languages( 'it' );

		( new Menus() )->register_locations();

		$this->assertArrayHasKey( 'primary', get_registered_nav_menus() );
		$this->assertArrayNotHasKey( Menus::location( 'primary', 'en' ), get_registered_nav_menus() );
	}
}
