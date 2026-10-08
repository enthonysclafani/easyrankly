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
 * Adds the AI agent submenu: two tabs, Proposals and Memory, like the tabs of the core screens.
 * The app built from assets/src/agent fills the tab with the markup of the core list tables
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
		Assets::enqueue( 'agent', false );
	}

	/**
	 * Tabs of the screen.
	 *
	 * @return array<string, string> Tab => label.
	 */
	private static function tabs(): array {
		return array(
			'proposals' => __( 'Proposals', 'easyrankly' ),
			'memory'    => __( 'Memory', 'easyrankly' ),
		);
	}

	/**
	 * Tab asked for in the URL.
	 *
	 * @return string
	 */
	private static function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return isset( self::tabs()[ $tab ] ) ? $tab : 'proposals';
	}

	/**
	 * Prints the tabs and the container the agent app mounts into.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = self::tab();

		printf( '<div class="wrap"><h1>%s</h1><nav class="nav-tab-wrapper">', esc_html__( 'AI agent', 'easyrankly' ) );
		foreach ( self::tabs() as $tab => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( add_query_arg( 'tab', $tab, menu_page_url( self::SLUG, false ) ) ),
				$tab === $current ? ' nav-tab-active' : '',
				$tab === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		printf(
			'</nav><div id="easyrankly-agent" data-tab="%1$s"><noscript><p>%2$s</p></noscript></div></div>',
			esc_attr( $current ),
			esc_html__( 'The agent dashboard needs JavaScript.', 'easyrankly' )
		);
	}
}
