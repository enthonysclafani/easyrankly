<?php
/**
 * Agent dashboard: proposals to accept, edit or reject.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent\Admin;

use EasyRankly\Admin\Assets;
use EasyRankly\Agent\Analysis;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the AI agent submenu. The app built from assets/src/agent lists the proposals
 * and records decisions through `/easyrankly/v1/proposals`.
 */
final class Page {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-agent';

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
			__( 'AI agent', 'easyrankly' ),
			__( 'AI agent', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		}
	}

	/**
	 * Runs only on the agent screen: loads its assets and cleans up old proposals.
	 */
	public function on_load(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// Old rejected and superseded proposals go away a batch at a time, without cron.
		Analysis::cleanup();
	}

	/**
	 * Enqueues the agent app built by `npm run build`.
	 */
	public function enqueue(): void {
		Assets::enqueue( 'agent' );
	}

	/**
	 * Prints the container the agent app mounts into.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="easyrankly-agent"><noscript>%2$s</noscript></div></div>',
			esc_html__( 'AI agent', 'easyrankly' ),
			esc_html__( 'The agent dashboard needs JavaScript.', 'easyrankly' )
		);
	}
}
