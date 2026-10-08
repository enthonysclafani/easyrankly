<?php
/**
 * Loads the admin scripts compiled by `npm run build`.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Admin;

use const EasyRankly\PLUGIN_FILE;
use const EasyRankly\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues one entry point from build/ with the dependencies listed in its asset file.
 */
final class Assets {

	/**
	 * Enqueues `build/{entry}.js` as `easyrankly-{entry}`, with translations and the components stylesheet.
	 *
	 * @param string $entry Entry point name from package.json (e.g. "settings").
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
		wp_enqueue_style( 'wp-components' );

		return true;
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
