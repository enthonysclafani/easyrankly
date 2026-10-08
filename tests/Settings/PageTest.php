<?php
/**
 * Tests for the settings screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Settings\Admin\Page;
use WP_UnitTestCase;

/**
 * The settings screen exists only for administrators and loads its app only there.
 */
final class PageTest extends WP_UnitTestCase {

	/**
	 * Restores the global admin menu.
	 */
	public function tear_down(): void {
		global $menu, $submenu, $_registered_pages;
		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		wp_dequeue_script( 'easyrankly-settings' );

		parent::tear_down();
	}

	/**
	 * The menu and its Settings submenu require manage_options.
	 */
	public function test_menu_requires_manage_options(): void {
		global $menu, $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Page() )->add_menu();

		$items = array_values( array_filter( $menu, static fn( array $item ): bool => Page::SLUG === $item[2] ) );
		$this->assertCount( 1, $items );
		$this->assertSame( 'manage_options', $items[0][1] );
		$this->assertSame( 'manage_options', $submenu[ Page::SLUG ][0][1] );
	}

	/**
	 * Assets are hooked only when the settings screen loads.
	 */
	public function test_assets_load_only_on_settings_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = new Page();
		$page->add_menu();

		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );

		do_action( 'load-toplevel_page_' . Page::SLUG );

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );
	}

	/**
	 * The built app is enqueued when present; otherwise the screen explains how to build it.
	 */
	public function test_enqueue_uses_build_or_explains_missing_build(): void {
		$page = new Page();
		$page->enqueue();

		if ( is_readable( dirname( __DIR__, 2 ) . '/build/settings.asset.php' ) ) {
			$this->assertTrue( wp_script_is( 'easyrankly-settings', 'enqueued' ) );
			$this->assertContains( 'wp-components', wp_scripts()->registered['easyrankly-settings']->deps );
		} else {
			$this->assertFalse( wp_script_is( 'easyrankly-settings', 'enqueued' ) );
			$this->assertSame( 10, has_action( 'admin_notices', array( \EasyRankly\Admin\Assets::class, 'missing_build_notice' ) ) );
		}
	}

	/**
	 * The screen prints the mount point for administrators and nothing for others.
	 */
	public function test_render_prints_container_only_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', get_echo( array( new Page(), 'render' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'id="easyrankly-settings"', get_echo( array( new Page(), 'render' ) ) );
	}
}
