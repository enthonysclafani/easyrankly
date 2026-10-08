<?php
/**
 * Uninstall leaves nothing behind.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests;

use WP_UnitTestCase;

/**
 * Every option the plugin creates must be removed by uninstall.php.
 *
 * When a feature adds meta keys, post types or taxonomies, extend this test
 * so it seeds them and proves uninstall.php removes them.
 */
final class UninstallTest extends WP_UnitTestCase {

	/**
	 * No option with the plugin prefix survives uninstall.
	 */
	public function test_uninstall_removes_every_option(): void {
		global $wpdb;

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		require dirname( __DIR__ ) . '/uninstall.php';

		$left = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'easyrankly_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tests inspect the raw table on purpose.

		$this->assertSame( array(), $left );
	}
}
