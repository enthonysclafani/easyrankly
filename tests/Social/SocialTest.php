<?php
/**
 * Tests for Open Graph and X tags on real requests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Social;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use WP_UnitTestCase;

/**
 * Social tags fall back from the object's own values to the SEO ones and to the defaults.
 */
final class SocialTest extends WP_UnitTestCase {

	/**
	 * Fixed site name, meta registered, default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		$this->set_permalink_structure( '' );
		update_option( 'blogname', 'Site' );
		update_option( 'blog_public', '1' );
		delete_option( Settings::OPTION );
	}

	/**
	 * The social tags printed for the current request.
	 *
	 * @return string
	 */
	private function head(): string {
		return get_echo( array( new Social(), 'print_tags' ) );
	}

	/**
	 * Creates an image attachment with size metadata and alt text.
	 *
	 * @param string $file File name.
	 * @param string $alt  Alt text.
	 * @return int Attachment ID.
	 */
	private function image( string $file, string $alt ): int {
		$id = self::factory()->attachment->create_object( $file, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 1200,
				'height' => 630,
				'file'   => $file,
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		return $id;
	}

	/**
	 * A post shares as an article with SEO title, description, canonical URL and featured image.
	 */
	public function test_post_uses_seo_values_and_featured_image(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello',
				'post_excerpt' => 'Summary & more',
			)
		);
		set_post_thumbnail( $post_id, $this->image( 'featured.jpg', 'A "cat"' ) );

		$this->go_to( get_permalink( $post_id ) );
		$head = $this->head();

		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $head );
		$this->assertStringContainsString( '<meta property="og:title" content="Hello - Site" />', $head );
		$this->assertStringContainsString( '<meta property="og:description" content="Summary &amp; more" />', $head );
		$this->assertStringContainsString( '<meta property="og:url" content="' . get_permalink( $post_id ) . '" />', $head );
		$this->assertStringContainsString( 'featured.jpg" />', $head );
		$this->assertStringContainsString( '<meta property="og:image:width" content="1200" />', $head );
		$this->assertStringContainsString( '<meta property="og:image:alt" content="A &quot;cat&quot;" />', $head );
		$this->assertStringContainsString( '<meta property="article:published_time"', $head );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:title" content="Hello - Site" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:description" content="Summary &amp; more" />', $head );
		$this->assertMatchesRegularExpression( '#<meta name="twitter:image" content="[^"]*featured\.jpg" />#', $head );
		$this->assertStringContainsString( '<meta name="twitter:image:alt" content="A &quot;cat&quot;" />', $head );
	}

	/**
	 * Social fields of the post win over the SEO values and the featured image.
	 */
	public function test_post_social_fields_win(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		set_post_thumbnail( $post_id, $this->image( 'featured.jpg', '' ) );
		update_post_meta( $post_id, '_easyrankly_og_title', 'Share me' );
		update_post_meta( $post_id, '_easyrankly_og_description', 'Social text' );
		update_post_meta( $post_id, '_easyrankly_og_image', $this->image( 'social.jpg', '' ) );

		$this->go_to( get_permalink( $post_id ) );
		$head = $this->head();

		$this->assertStringContainsString( 'content="Share me"', $head );
		$this->assertStringContainsString( 'content="Social text"', $head );
		$this->assertStringContainsString( 'social.jpg" />', $head );
		$this->assertStringNotContainsString( 'featured.jpg', $head );
	}

	/**
	 * Pages are websites; without any image the card is a summary; twitter:site comes from settings.
	 */
	public function test_page_without_image_and_x_username(): void {
		update_option( Settings::OPTION, array( 'x_username' => 'easyrankly' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->go_to( get_permalink( $page_id ) );
		$head = $this->head();

		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $head );
		$this->assertStringNotContainsString( 'og:image', $head );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:site" content="@easyrankly" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:title" content="', $head );
		$this->assertStringNotContainsString( 'twitter:image', $head );
	}

	/**
	 * Archives fall back to the default image of the settings and use the archive canonical.
	 */
	public function test_term_archive_uses_default_image(): void {
		$default = $this->image( 'default.jpg', '' );
		update_option( Settings::OPTION, array( 'social_image' => $default ) );
		$term_id = self::factory()->category->create( array( 'name' => 'News' ) );
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );

		$this->go_to( get_category_link( $term_id ) );
		$head = $this->head();

		$this->assertStringContainsString( 'content="News - Site"', $head );
		$this->assertStringContainsString( '<meta property="og:url" content="' . esc_attr( get_category_link( $term_id ) ) . '" />', $head );
		$this->assertStringContainsString( 'default.jpg" />', $head );
	}

	/**
	 * Search results and 404 pages are not shared.
	 */
	public function test_no_tags_on_search_and_404(): void {
		$this->go_to( home_url( '/?s=x' ) );
		$this->assertSame( '', $this->head() );

		$this->go_to( home_url( '/?p=999999' ) );
		$this->assertSame( '', $this->head() );
	}

	/**
	 * An invalid X username is rejected.
	 */
	public function test_invalid_x_username_is_rejected(): void {
		update_option( Settings::OPTION, array( 'x_username' => '@bad name' ) );

		$this->assertSame( '', Settings::value( 'x_username' ) );
	}
}
