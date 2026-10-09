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

	/**
	 * Nothing of the AI agent is left: no post types, routes or admin page, and the plugin works without an AI
	 * provider (the whole suite runs without one).
	 */
	public function test_no_agent_is_registered(): void {
		$this->assertFalse( post_type_exists( 'erankly_proposal' ) );
		$this->assertFalse( post_type_exists( 'erankly_memory' ) );
		$this->assertNull( get_post_status_object( 'erankly_pending' ) );

		$routes = array_keys( rest_get_server()->get_routes() );
		$this->assertSame( array(), preg_grep( '#^/easyrankly/v1/(agent|proposals|memory)#', $routes ) );

		$abilities = array_map( static fn( $ability ) => $ability->get_name(), wp_get_abilities() );
		$this->assertSame( array(), preg_grep( '#^easyrankly/#', $abilities ) );
	}
}
