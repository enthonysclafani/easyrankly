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
		$this->assertSame( '3.0.0', \EasyRankly\VERSION );
	}

	/**
	 * Header version, VERSION constant and readme Stable tag match, as WordPress.org requires.
	 */
	public function test_versions_match(): void {
		$root   = dirname( __DIR__ );
		$header = get_file_data( $root . '/easyrankly.php', array( 'version' => 'Version' ) );
		$readme = get_file_data( $root . '/readme.txt', array( 'stable' => 'Stable tag' ) );

		$this->assertSame( \EasyRankly\VERSION, $header['version'] );
		$this->assertSame( \EasyRankly\VERSION, $readme['stable'] );
	}
}
