<?php
/**
 * Admin screen of the import.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Import\Admin;

use EasyRankly\Admin\Assets;
use EasyRankly\Import\Import;
use EasyRankly\Settings\Admin\Page as SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Adds EasyRankly > Import: one button per plugin found, which runs the import batch after batch
 * through REST (assets/src/import).
 */
final class Page {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'easyrankly-import';

	/**
	 * Hooks the menu after the parent menu exists.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 30 );
	}

	/**
	 * Adds the submenu and loads its assets with the screen.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			SettingsPage::SLUG,
			__( 'Import', 'easyrankly' ),
			__( 'Import', 'easyrankly' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Enqueues the stylesheet and the script of the screen.
	 */
	public function load(): void {
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'style' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the script that runs the batches.
	 */
	public function enqueue(): void {
		Assets::enqueue( 'import' );
	}

	/**
	 * Prints the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap easyrankly-admin">';
		printf( '<h1>%s</h1>', esc_html__( 'Import', 'easyrankly' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Copies the SEO title, meta description, canonical URL, noindex and nofollow, and social title, description and image of posts and terms, and the redirects. Settings and templates are not imported. Values already set in EasyRankly are kept, and the other plugin keeps its data: running the import again changes nothing.', 'easyrankly' )
		);
		printf( '<noscript><div class="notice notice-error inline"><p>%s</p></div></noscript>', esc_html__( 'The import needs JavaScript.', 'easyrankly' ) );

		$found = false;
		foreach ( Import::sources() as $slug => $source ) {
			if ( ! $source->detected() ) {
				continue;
			}

			$found    = true;
			$progress = Import::progress( $slug );

			printf( '<h2>%s</h2>', esc_html( $source->label() ) );
			printf(
				'<p aria-live="polite" data-easyrankly-import-status="%1$s">%2$s</p>',
				esc_attr( $slug ),
				esc_html( null === $progress ? '' : Import::message( $progress ) )
			);
			printf(
				'<p><button type="button" class="button button-primary" data-easyrankly-import="%1$s" data-restart="%2$s" data-running="%3$s" data-failed="%4$s">%5$s</button></p>',
				esc_attr( $slug ),
				null === $progress || 'done' === $progress['stage'] ? '1' : '0',
				esc_attr__( 'Importing…', 'easyrankly' ),
				esc_attr__( 'The import stopped with an error. Click the button again to resume it.', 'easyrankly' ),
				esc_html(
					null === $progress || 'done' === $progress['stage']
						/* translators: %s: name of the other SEO plugin. */
						? sprintf( __( 'Import from %s', 'easyrankly' ), $source->label() )
						/* translators: %s: name of the other SEO plugin. */
						: sprintf( __( 'Resume the import from %s', 'easyrankly' ), $source->label() )
				)
			);
		}

		if ( ! $found ) {
			printf( '<p>%s</p>', esc_html__( 'No data from Yoast SEO or Rank Math was found on this site.', 'easyrankly' ) );
		}

		echo '</div>';
	}
}
