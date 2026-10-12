<?php
/**
 * Loads the admin scripts compiled by `npm run build` and the admin stylesheet.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Admin;

use const EasyRankly\PLUGIN_FILE;
use const EasyRankly\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues one entry point from build/ with the dependencies listed in its asset file, and the one stylesheet of
 * the plugin screens.
 */
final class Assets {

	/**
	 * Handle of `assets/admin.css`, the only stylesheet of the plugin (see CLAUDE.md, "Stile admin").
	 */
	public const STYLE = 'easyrankly-admin';

	/**
	 * Enqueues `build/{entry}.js` as `easyrankly-{entry}`, with translations and no stylesheet: the screens of
	 * the plugin get theirs from style(), and in the block editor the core already loads the `wp-components`
	 * styles (a dependency of `wp-edit-post`).
	 *
	 * @param string $entry Entry point name from webpack.config.js (e.g. "editor").
	 * @return bool False when the build is missing; a notice then explains how to build it.
	 */
	public static function enqueue( string $entry ): bool {
		$asset = dirname( PLUGIN_FILE ) . '/build/' . sanitize_key( $entry ) . '.asset.php';

		if ( ! is_readable( $asset ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_build_notice' ) );
			return false;
		}

		$info   = require $asset;
		$info   = is_array( $info ) ? $info : array();
		$handle = 'easyrankly-' . $entry;

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/' . $entry . '.js', PLUGIN_FILE ),
			isset( $info['dependencies'] ) && is_array( $info['dependencies'] ) ? $info['dependencies'] : array(),
			isset( $info['version'] ) && is_string( $info['version'] ) ? $info['version'] : VERSION,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( $handle, 'easyrankly' );

		return true;
	}

	/**
	 * Enqueues `assets/admin.css`, which dresses the core markup inside `<div class="wrap easyrankly-admin">`.
	 * Hooked on `admin_enqueue_scripts` by the plugin screens only.
	 */
	public static function style(): void {
		wp_enqueue_style( self::STYLE, plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Explains an unbuilt development checkout instead of showing an empty screen.
	 */
	public static function missing_build_notice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'EasyRankly admin assets are missing. Run `npm install && npm run build` in the plugin folder.', 'easyrankly' )
		);
	}
}
