<?php
/**
 * Smoke test for the plugin bootstrap.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests;

use EasyRankly\Plugin;
use WP_UnitTestCase;

/**
 * The plugin loads inside WordPress.
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * The bootstrap loads the autoloader and defines the version.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( '3.0.0-dev', \EasyRankly\VERSION );
	}
}
