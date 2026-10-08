<?php
/**
 * Tests for the styles the plugin loads on its admin screens.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Admin;

use EasyRankly\Agent\Admin\Page as AgentPage;
use EasyRankly\CustomCode\Admin\Page as SnippetsPage;
use EasyRankly\CustomCode\CustomCode;
use EasyRankly\Redirects\Admin\Page as RedirectsPage;
use EasyRankly\Settings\Admin\Page as SettingsPage;
use WP_Scripts;
use WP_Styles;
use WP_UnitTestCase;

use const EasyRankly\PLUGIN_FILE;

/**
 * The screens of the plugin use only the admin styles of the core: no `wp-components`, no stylesheet of ours.
 */
final class AssetsTest extends WP_UnitTestCase {

	/**
	 * Starts from empty script and style queues and logs in an administrator, super admin on multisite
	 * (only they have unfiltered_html there).
	 */
	public function set_up(): void {
		parent::set_up();

		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
		( new CustomCode() )->register_post_type();

		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
	}

	/**
	 * Restores the admin menu, the request, the screen and the queues.
	 */
	public function tear_down(): void {
		global $menu, $submenu, $_registered_pages, $wp_settings_sections, $wp_settings_fields;
		$menu                  = array();
		$submenu               = array();
		$_registered_pages     = array();
		$wp_settings_sections  = array();
		$wp_settings_fields    = array();
		$_GET                  = array();
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Every screen of the plugin, with the views that load something more.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}> Page slug and query arguments.
	 */
	public static function screens(): array {
		$screens = array();
		foreach ( array_keys( SettingsPage::pages() ) as $slug ) {
			$screens[ $slug ] = array( $slug, array() );
		}

		return $screens + array(
			'redirects'      => array( RedirectsPage::SLUG, array() ),
			'redirects, new' => array( RedirectsPage::SLUG, array( 'action' => 'new' ) ),
			'snippets'       => array( SnippetsPage::SLUG, array() ),
			'snippets, new'  => array( SnippetsPage::SLUG, array( 'action' => 'new' ) ),
			'snippets, edit' => array( SnippetsPage::SLUG, array( 'action' => 'edit' ) ),
			'agent'          => array( AgentPage::SLUG, array() ),
			'agent, memory'  => array( AgentPage::SLUG, array( 'tab' => 'memory' ) ),
		);
	}

	/**
	 * Loading a screen as the core does queues no `wp-components` and no stylesheet of the plugin,
	 * not even as a dependency of what the screen loads.
	 *
	 * @dataProvider screens
	 *
	 * @param string                $slug Page slug.
	 * @param array<string, string> $args Query arguments.
	 */
	public function test_screen_loads_no_styles_of_ours( string $slug, array $args ): void {
		( new SettingsPage() )->add_menu();
		( new RedirectsPage() )->add_menu();
		( new SnippetsPage() )->add_menu();
		( new AgentPage() )->add_menu();

		if ( isset( $args['action'] ) && 'edit' === $args['action'] ) {
			$id = self::factory()->post->create( array( 'post_type' => CustomCode::POST_TYPE ) );
			update_post_meta( $id, CustomCode::meta_key( 'type' ), 'php' );
			$args['id'] = (string) $id;
		}

		$_GET = array( 'page' => $slug ) + $args;
		$hook = (string) get_plugin_page_hookname( $slug, SettingsPage::SLUG );
		$this->assertTrue( has_action( 'load-' . $hook ), $hook );

		set_current_screen( $hook );
		do_action( 'load-' . $hook );
		// The command palette of the core loads the components styles on every admin screen: not ours.
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );
		do_action( 'admin_enqueue_scripts', $hook );

		$styles = self::queued( wp_styles() );
		$this->assertNotContains( 'wp-components', $styles );
		$this->assertNotContains( 'wp-components', self::queued( wp_scripts() ), 'A script of ours renders no components.' );

		$plugin = plugins_url( '', PLUGIN_FILE );
		foreach ( $styles as $handle ) {
			$src = wp_styles()->registered[ $handle ]->src ?? '';
			$this->assertStringStartsNotWith( $plugin, is_string( $src ) ? $src : '', $handle );
			$this->assertStringStartsNotWith( 'easyrankly', $handle );
		}
	}

	/**
	 * Handles that would be printed: the queue with its dependencies.
	 *
	 * @param WP_Scripts|WP_Styles $dependencies Scripts or styles.
	 * @return string[]
	 */
	private static function queued( $dependencies ): array {
		$dependencies->all_deps( $dependencies->queue );
		$handles             = $dependencies->to_do;
		$dependencies->to_do = array();

		return $handles;
	}
}
