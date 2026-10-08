<?php
/**
 * Tests for the agent dashboard screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Admin\Page;
use EasyRankly\Settings\Admin\Page as SettingsPage;
use WP_UnitTestCase;

/**
 * The agent dashboard is an administrator-only submenu with its own app.
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
		unset( $_GET['tab'] );
		wp_dequeue_script( 'easyrankly-agent' );

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
		$this->assertStringContainsString( 'id="easyrankly-agent"', (string) ob_get_clean() );
	}

	/**
	 * Proposals and Memory are tabs of the core screens, chosen in the URL.
	 */
	public function test_tabs_are_classic_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = get_echo( array( new Page(), 'render' ) );
		$this->assertStringContainsString( '<nav class="nav-tab-wrapper">', $html );
		$this->assertMatchesRegularExpression( '/class="nav-tab nav-tab-active" aria-current="page">Proposals</', $html );
		$this->assertStringContainsString( 'data-tab="proposals"', $html );

		$_GET['tab'] = 'memory';
		$this->assertStringContainsString( 'data-tab="memory"', get_echo( array( new Page(), 'render' ) ) );

		$_GET['tab'] = '<script>';
		$this->assertStringContainsString( 'data-tab="proposals"', get_echo( array( new Page(), 'render' ) ) );
	}

	/**
	 * The app renders the markup of the core: it does not load the components styles.
	 */
	public function test_app_does_not_load_components_styles(): void {
		( new Page() )->enqueue();

		$built = is_readable( dirname( __DIR__, 2 ) . '/build/agent.asset.php' );
		$this->assertSame( $built, wp_script_is( 'easyrankly-agent', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wp-components', 'enqueued' ) );
	}
}
