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

		update_option( 'easyrankly_settings', array( 'title_separator' => '|' ) );

		require dirname( __DIR__ ) . '/uninstall.php';

		$left = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'easyrankly_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tests inspect the raw table on purpose.

		$this->assertSame( array(), $left );
	}

	/**
	 * SEO meta of posts and terms is removed too.
	 */
	public function test_uninstall_removes_seo_meta(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create();

		update_post_meta( $post_id, '_easyrankly_title', 'x' );
		update_post_meta( $post_id, '_easyrankly_noindex', true );
		update_term_meta( $term_id, '_easyrankly_title', 'x' );
		update_term_meta( $term_id, '_easyrankly_noindex', true );

		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertSame( '', get_post_meta( $post_id, '_easyrankly_title', true ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_easyrankly_noindex' ) );
		$this->assertSame( '', get_term_meta( $term_id, '_easyrankly_title', true ) );
		$this->assertFalse( metadata_exists( 'term', $term_id, '_easyrankly_noindex' ) );
	}

	/**
	 * Redirects, their meta and the lists built from them are removed.
	 */
	public function test_uninstall_removes_redirects(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$ids = array();
		foreach ( array( 'publish', 'draft', 'trash' ) as $status ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'erankly_redirect',
					'post_status' => $status,
					'meta_input'  => array( '_easyrankly_redirect_target' => '/new' ),
				)
			);
		}
		update_option( 'easyrankly_redirects_forced', array( array( 'source' => '/a' ) ) );
		update_option( 'easyrankly_redirects_regex', array( array( 'source' => '^/b' ) ), false );

		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( $ids as $id ) {
			$this->assertNull( get_post( $id ) );
			$this->assertFalse( metadata_exists( 'post', $id, '_easyrankly_redirect_target' ) );
		}
		$this->assertFalse( get_option( 'easyrankly_redirects_forced' ) );
		$this->assertFalse( get_option( 'easyrankly_redirects_regex' ) );
	}
}
