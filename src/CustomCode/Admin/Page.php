<?php
/**
 * Admin screen for custom code.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode\Admin;

use EasyRankly\Admin\Assets;
use EasyRankly\CustomCode\CustomCode;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Custom code submenu. The app built from assets/src/snippets manages the
 * snippets through `/wp/v2/easyrankly-snippets`.
 */
final class Page {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-snippets';

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
			__( 'Custom code', 'easyrankly' ),
			__( 'Custom code', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Runs only on the custom code screen.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the custom code app built by `npm run build`.
	 */
	public function enqueue(): void {
		if ( Assets::enqueue( 'snippets' ) ) {
			// What the app may offer: the server enforces the same rules on save.
			$state = array(
				'canPhp'     => CustomCode::can_write_php(),
				'phpAllowed' => CustomCode::php_allowed(),
				'safeMode'   => CustomCode::safe_mode(),
			);
			wp_add_inline_script( 'easyrankly-snippets', 'window.easyrankly = ' . wp_json_encode( $state ) . ';', 'before' );
		}
	}

	/**
	 * Prints the container the custom code app mounts into.
	 */
	public function render(): void {
		if ( ! CustomCode::can_manage() ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="easyrankly-snippets"><noscript>%2$s</noscript></div></div>',
			esc_html__( 'Custom code', 'easyrankly' ),
			esc_html__( 'The custom code screen needs JavaScript.', 'easyrankly' )
		);
	}
}
