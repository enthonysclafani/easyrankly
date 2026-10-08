<?php
/**
 * Tests for the redirects screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Admin\Page;
use EasyRankly\Settings\Admin\Page as SettingsPage;
use WP_UnitTestCase;

/**
 * The redirects screen is an administrator-only submenu with its own app.
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
		wp_dequeue_script( 'easyrankly-redirects' );

		parent::tear_down();
	}

	/**
	 * The submenu sits under EasyRankly, requires manage_options and loads assets only on its screen.
	 */
	public function test_submenu(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new SettingsPage() )->add_menu();
		$page = new Page();
		$page->add_menu();

		$items = array_values( array_filter( $submenu[ SettingsPage::SLUG ], static fn( array $item ): bool => Page::SLUG === $item[2] ) );
		$this->assertCount( 1, $items );
		$this->assertSame( 'manage_options', $items[0][1] );

		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );
		do_action( 'load-easyrankly_page_' . Page::SLUG );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $page, 'enqueue' ) ) );
	}

	/**
	 * The screen prints the mount point for administrators and nothing for others.
	 */
	public function test_render_only_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		( new Page() )->render();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		( new Page() )->render();
		$this->assertStringContainsString( 'id="easyrankly-redirects"', (string) ob_get_clean() );
	}
}
