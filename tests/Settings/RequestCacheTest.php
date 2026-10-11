<?php
/**
 * Tests for the values worked out once per request.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Titles;
use WP_UnitTestCase;

/**
 * The head asks for settings, title and robots many times: each is worked out once per page,
 * and worked out again when the page, the settings or the content change.
 */
final class RequestCacheTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks, a public site, meta registered; no core styles (not built in the test suite).
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		Settings::create();
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'blog_public', '1' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_head', 'wp_maybe_inline_styles', 1 );
		remove_action( 'wp_head', 'wp_print_styles', 8 );
	}

	/**
	 * One head reads the settings option and the title override of the post at most once.
	 */
	public function test_head_works_values_out_once(): void {
		$post = self::factory()->post->create( array( 'post_excerpt' => 'Text' ) );
		update_post_meta( $post, Meta::PREFIX . 'title', 'Own title' );
		$this->go_to( get_permalink( $post ) );
		wp_cache_delete( 'settings', Settings::REQUEST_GROUP );

		$settings = 0;
		$count    = static function ( $value ) use ( &$settings ) {
			++$settings;
			return $value;
		};
		$titles   = 0;
		$read     = static function ( $value, $object_id, $meta_key ) use ( &$titles ) {
			if ( Meta::PREFIX . 'title' === $meta_key ) {
				++$titles;
			}
			return $value;
		};
		add_filter( 'option_' . Settings::OPTION, $count );
		add_filter( 'get_post_metadata', $read, 10, 3 );
		$head = get_echo( 'wp_head' );
		remove_filter( 'option_' . Settings::OPTION, $count );
		remove_filter( 'get_post_metadata', $read );

		$this->assertStringContainsString( '<title>Own title</title>', $head );
		$this->assertStringContainsString( 'content="Own title"', $head );
		$this->assertSame( 1, $settings );
		$this->assertSame( 1, $titles );
	}

	/**
	 * New settings, new meta and a new page each give fresh values in the same request.
	 */
	public function test_values_follow_changes(): void {
		$first  = self::factory()->post->create( array( 'post_title' => 'First' ) );
		$second = self::factory()->post->create( array( 'post_title' => 'Second' ) );

		$this->go_to( get_permalink( $first ) );
		$this->assertSame( 'First - ' . get_bloginfo( 'name' ), Titles::title() );

		update_option( Settings::OPTION, array( 'templates' => array( 'single' => array( 'title' => '{{title}} | Blog' ) ) ) );
		$this->assertSame( 'First | Blog', Titles::title() );

		update_post_meta( $first, Meta::PREFIX . 'title', 'Own title' );
		$this->assertSame( 'Own title', Titles::title() );

		$this->go_to( get_permalink( $second ) );
		$this->assertSame( 'Second | Blog', Titles::title() );
	}
}
