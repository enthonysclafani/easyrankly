<?php
/**
 * Admin screen for redirects.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects\Admin;

use EasyRankly\Admin\Assets;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Redirects submenu. The app built from assets/src/redirects manages the
 * rules through `/wp/v2/easyrankly-redirects`.
 */
final class Page {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-redirects';

	/**
	 * Hooks the menu after the parent menu exists.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
	}

	/**
	 * Adds the submenu and loads assets only when its screen loads.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'Redirects', 'easyrankly' ),
			__( 'Redirects', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Runs only on the redirects screen.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the redirects app built by `npm run build`.
	 */
	public function enqueue(): void {
		Assets::enqueue( 'redirects' );
	}

	/**
	 * Prints the container the redirects app mounts into.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="easyrankly-redirects"><noscript>%2$s</noscript></div></div>',
			esc_html__( 'Redirects', 'easyrankly' ),
			esc_html__( 'The redirects screen needs JavaScript.', 'easyrankly' )
		);
	}
}
