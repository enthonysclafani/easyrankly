<?php
/**
 * Admin screen for the plugin settings.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Settings\Admin;

use EasyRankly\Admin\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the EasyRankly menu and loads the settings app only on its screen.
 *
 * The screen is an empty container: the app built from assets/src/settings
 * reads and saves `easyrankly_settings` through `/wp/v2/settings`.
 */
final class Page {

	/**
	 * Menu slug, shared by the top-level menu and the Settings submenu.
	 */
	public const SLUG = 'easyrankly';

	/**
	 * Hooks the menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level menu and loads assets only when its screen loads.
	 */
	public function add_menu(): void {
		$hook = add_menu_page(
			__( 'EasyRankly', 'easyrankly' ),
			__( 'EasyRankly', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-line'
		);

		add_submenu_page( self::SLUG, __( 'EasyRankly settings', 'easyrankly' ), __( 'Settings', 'easyrankly' ), 'manage_options', self::SLUG, array( $this, 'render' ) );

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Runs only on the settings screen.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the settings app built by `npm run build`.
	 */
	public function enqueue(): void {
		Assets::enqueue( 'settings' );
	}

	/**
	 * Prints the container the settings app mounts into.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="easyrankly-settings"><noscript>%2$s</noscript></div></div>',
			esc_html__( 'EasyRankly settings', 'easyrankly' ),
			esc_html__( 'The settings screen needs JavaScript.', 'easyrankly' )
		);
	}
}
