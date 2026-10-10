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

	/**
	 * Records that cannot be deleted do not stop the deletion of the others.
	 */
	public function test_uninstall_goes_past_records_it_cannot_delete(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$kept = self::factory()->post->create_many( 100, array( 'post_type' => 'erankly_redirect' ) );
		$last = self::factory()->post->create(
			array(
				'post_type' => 'erankly_redirect',
				'post_date' => '2000-01-01 00:00:00',
			)
		);
		add_filter( 'pre_delete_post', static fn( $delete, $post ) => in_array( $post->ID, $kept, true ) ? false : $delete, 10, 2 );

		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertNull( get_post( $last ) );
	}

	/**
	 * Snippets, their revisions and meta, and their cache are removed.
	 */
	public function test_uninstall_removes_snippets(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$id = self::factory()->post->create(
			array(
				'post_type'    => 'erankly_snippet',
				'post_content' => '<i>1</i>',
				'meta_input'   => array( '_easyrankly_snippet_type' => 'html' ),
			)
		);
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<i>2</i>',
			)
		);
		$revisions = array_keys( wp_get_post_revisions( $id ) );
		update_option( 'easyrankly_snippets', array( 'positions' => array() ) );

		require dirname( __DIR__ ) . '/uninstall.php';

		$this->assertNull( get_post( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_easyrankly_snippet_type' ) );
		$this->assertNotEmpty( $revisions );
		foreach ( $revisions as $revision ) {
			$this->assertNull( get_post( $revision ) );
		}
		$this->assertFalse( get_option( 'easyrankly_snippets' ) );
	}

	/**
	 * Language and translation terms are removed with their relationships.
	 */
	public function test_uninstall_removes_languages_and_groups(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$post = self::factory()->post->create();
		wp_set_object_terms( $post, 'en', 'erankly_language' );
		$group = wp_insert_term( 'group', 'erankly_translation' );
		wp_set_object_terms( $post, array( (int) $group['term_id'] ), 'erankly_translation' );

		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( array( 'erankly_language', 'erankly_translation' ) as $taxonomy ) {
			$this->assertSame(
				array(),
				get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				)
			);
			$this->assertSame( array(), wp_get_object_terms( $post, $taxonomy ) );
		}
	}

	/**
	 * Notices about redirects not created are removed for every user who could get one.
	 */
	public function test_uninstall_removes_redirect_notices(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easyrankly/easyrankly.php' );
		}

		$users = array(
			self::factory()->user->create( array( 'role' => 'administrator' ) ),
			self::factory()->user->create( array( 'role' => 'editor' ) ),
			self::factory()->user->create( array( 'role' => 'contributor' ) ),
		);
		foreach ( $users as $user ) {
			set_transient(
				'easyrankly_redirect_notice_' . $user,
				array(
					'failed' => array(),
					'capped' => array( '/x' ),
				),
				DAY_IN_SECONDS
			);
		}

		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( $users as $user ) {
			$this->assertFalse( get_transient( 'easyrankly_redirect_notice_' . $user ), (string) $user );
		}
	}
}
