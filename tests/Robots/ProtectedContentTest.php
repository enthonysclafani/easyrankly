<?php
/**
 * Tests for password-protected posts on real requests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Robots;

use EasyRankly\Meta\Meta;
use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use EasyRankly\Titles\Titles;
use WP_UnitTestCase;

/**
 * The text of a password-protected post stays out of <head> and the post out of search engines.
 */
final class ProtectedContentTest extends WP_UnitTestCase {

	/**
	 * Public site, meta registered, default settings, fresh sitemap server.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		$this->set_permalink_structure( '' );
		update_option( 'blog_public', '1' );
		delete_option( Settings::OPTION );
		$GLOBALS['wp_sitemaps'] = null;
	}

	/**
	 * Description, social tags and schema printed for the current request.
	 *
	 * @return string
	 */
	private function head(): string {
		return get_echo( array( new Titles(), 'print_description' ) )
			. get_echo( array( new Social(), 'print_tags' ) )
			. get_echo( array( new Schema(), 'print_graph' ) );
	}

	/**
	 * Neither the content nor the excerpt of a protected post reaches the automatic description.
	 */
	public function test_protected_text_is_not_in_head(): void {
		$from_content = self::factory()->post->create(
			array(
				'post_content'  => 'Secret body text',
				'post_password' => 'pass',
			)
		);
		$from_excerpt = self::factory()->post->create(
			array(
				'post_excerpt'  => 'Secret excerpt text',
				'post_password' => 'pass',
			)
		);

		$this->go_to( get_permalink( $from_content ) );
		$this->assertStringNotContainsString( 'Secret', $this->head() );

		$this->go_to( get_permalink( $from_excerpt ) );
		$this->assertStringNotContainsString( 'Secret', $this->head() );
	}

	/**
	 * A description the author wrote for the protected post is a deliberate choice and is kept.
	 */
	public function test_own_description_is_kept(): void {
		$post_id = self::factory()->post->create( array( 'post_password' => 'pass' ) );
		update_post_meta( $post_id, '_easyrankly_description', 'Public summary' );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringContainsString( '<meta name="description" content="Public summary" />', $this->head() );
	}

	/**
	 * Protected posts are noindex, without canonical, and out of the sitemap; the others are not.
	 */
	public function test_protected_post_is_noindex_and_out_of_sitemap(): void {
		$kept      = self::factory()->post->create();
		$protected = self::factory()->post->create( array( 'post_password' => 'pass' ) );

		$this->go_to( get_permalink( $protected ) );
		$this->assertStringContainsString( 'noindex', get_echo( 'wp_robots' ) );
		$this->assertSame( '', get_echo( 'rel_canonical' ) );

		$this->go_to( get_permalink( $kept ) );
		$this->assertStringNotContainsString( 'noindex', get_echo( 'wp_robots' ) );

		$urls = array_column( wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_url_list( 1, 'post' ), 'loc' );
		$this->assertContains( get_permalink( $kept ), $urls );
		$this->assertNotContains( get_permalink( $protected ), $urls );
	}
}
