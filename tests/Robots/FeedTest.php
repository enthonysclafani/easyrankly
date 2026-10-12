<?php
/**
 * Tests for the robots header of feeds.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Robots;

use WP_UnitTestCase;

/**
 * Every feed asks not to be indexed through its headers; pages keep their headers as they are.
 */
final class FeedTest extends WP_UnitTestCase {

	/**
	 * Headers core would send for the request just parsed, after every filter of `wp_headers`.
	 *
	 * @return mixed
	 */
	private function headers() {
		return apply_filters( 'wp_headers', array( 'Content-Type' => 'text/html' ), $GLOBALS['wp'] );
	}

	/**
	 * Data provider: permalink structures.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function permalinks(): array {
		return array(
			'plain'  => array( '' ),
			'pretty' => array( '/%postname%/' ),
		);
	}

	/**
	 * Posts, comments and category feeds get noindex, follow; a post does not.
	 *
	 * @dataProvider permalinks
	 *
	 * @param string $structure Permalink structure.
	 */
	public function test_feeds_get_noindex_header( string $structure ): void {
		$this->set_permalink_structure( $structure );
		create_initial_taxonomies();
		flush_rewrite_rules();
		$post_id = self::factory()->post->create();

		foreach ( array( get_feed_link(), get_feed_link( 'comments_rss2' ), get_category_feed_link( 1 ), get_post_comments_feed_link( $post_id ) ) as $url ) {
			$this->go_to( $url );
			$this->assertTrue( is_feed(), $url );
			$this->assertSame( 'noindex, follow', $this->headers()['X-Robots-Tag'] ?? '', $url );
		}

		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( array( 'Content-Type' => 'text/html' ), $this->headers() );
	}
}
