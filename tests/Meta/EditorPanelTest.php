<?php
/**
 * Tests for the block editor SEO panel loader.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Meta;

use EasyRankly\Meta\Admin\EditorPanel;
use WP_UnitTestCase;

/**
 * The panel script loads only in the post editor of public post types.
 */
final class EditorPanelTest extends WP_UnitTestCase {

	/**
	 * Resets the screen and the queue.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'easyrankly-editor' );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Not on the site editor or the widgets screen.
	 */
	public function test_not_loaded_outside_post_editor(): void {
		set_current_screen( 'site-editor' );
		( new EditorPanel() )->enqueue();

		$this->assertFalse( wp_script_is( 'easyrankly-editor', 'enqueued' ) );
		$this->assertFalse( has_action( 'admin_notices', array( \EasyRankly\Admin\Assets::class, 'missing_build_notice' ) ) );
	}

	/**
	 * In the post editor the panel loads (or the missing-build notice explains why not).
	 */
	public function test_loaded_in_post_editor(): void {
		set_current_screen( 'post' );
		( new EditorPanel() )->enqueue();

		if ( is_readable( dirname( __DIR__, 2 ) . '/build/editor.asset.php' ) ) {
			$this->assertTrue( wp_script_is( 'easyrankly-editor', 'enqueued' ) );
		} else {
			$this->assertSame( 10, has_action( 'admin_notices', array( \EasyRankly\Admin\Assets::class, 'missing_build_notice' ) ) );
		}
	}
}
