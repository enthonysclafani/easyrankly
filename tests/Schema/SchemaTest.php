<?php
/**
 * Tests for the JSON-LD graph on real requests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Schema;

use EasyRankly\Meta\Meta;
use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * One connected graph per page, with breadcrumbs only when the page shows them.
 */
final class SchemaTest extends WP_UnitTestCase {

	/**
	 * Fixed site, meta registered, default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		$this->set_permalink_structure( '' );
		update_option( 'blogname', 'Site' );
		update_option( 'blogdescription', 'Tagline' );
		update_option( 'blog_public', '1' );
		delete_option( Settings::OPTION );
	}

	/**
	 * Decodes the printed graph, keyed by @type.
	 *
	 * @param Schema $schema Schema instance that saw the request.
	 * @return array<string, array<string, mixed>>
	 */
	private function nodes( Schema $schema ): array {
		$html = get_echo( array( $schema, 'print_graph' ) );
		if ( '' === $html ) {
			return array();
		}

		$this->assertSame( 1, preg_match( '#^<script type="application/ld\+json">(.+)</script>\n$#s', $html, $matches ) );
		$data = json_decode( $matches[1], true );
		$this->assertSame( 'https://schema.org', $data['@context'] );

		$nodes = array();
		foreach ( $data['@graph'] as $node ) {
			$nodes[ $node['@type'] ] = $node;
		}

		return $nodes;
	}

	/**
	 * A post gets Organization, WebSite, WebPage and Article, linked by @id.
	 */
	public function test_post_graph_is_connected(): void {
		$author  = self::factory()->user->create( array( 'display_name' => 'Ann' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello',
				'post_excerpt' => 'Summary',
				'post_author'  => $author,
			)
		);
		$url     = get_permalink( $post_id );

		$this->go_to( $url );
		$nodes = $this->nodes( new Schema() );

		$this->assertSame( home_url( '/' ) . '#organization', $nodes['Organization']['@id'] );
		$this->assertSame( 'Site', $nodes['Organization']['name'] );
		$this->assertSame( array( '@id' => home_url( '/' ) . '#organization' ), $nodes['WebSite']['publisher'] );
		$this->assertSame( $url . '#webpage', $nodes['WebPage']['@id'] );
		$this->assertSame( 'Hello - Site', $nodes['WebPage']['name'] );
		$this->assertSame( 'Summary', $nodes['WebPage']['description'] );
		$this->assertSame( array( '@id' => $url . '#webpage' ), $nodes['Article']['mainEntityOfPage'] );
		$this->assertSame( 'Hello', $nodes['Article']['headline'] );
		$this->assertSame( 'Ann', $nodes['Article']['author']['name'] );
		$this->assertArrayNotHasKey( 'BreadcrumbList', $nodes );
	}

	/**
	 * Pages are WebPages without Article; archives are CollectionPages.
	 */
	public function test_page_and_archive_types(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page_id ) );
		$nodes = $this->nodes( new Schema() );
		$this->assertSame( 'WebPage', $nodes['WebPage']['@type'] );
		$this->assertArrayNotHasKey( 'Article', $nodes );

		$term_id = self::factory()->category->create();
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );
		$this->go_to( get_category_link( $term_id ) );
		$nodes = $this->nodes( new Schema() );
		$this->assertArrayHasKey( 'CollectionPage', $nodes );
		$this->assertSame( get_category_link( $term_id ) . '#webpage', $nodes['CollectionPage']['@id'] );
	}

	/**
	 * An author archive is a ProfilePage about the author, as Google requires mainEntity.
	 */
	public function test_author_archive_is_profile_page_with_main_entity(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Ann' ) );
		self::factory()->post->create( array( 'post_author' => $author ) );
		$url = get_author_posts_url( $author );

		$this->go_to( $url );
		$nodes = $this->nodes( new Schema() );

		$this->assertSame( $url . '#webpage', $nodes['ProfilePage']['@id'] );
		$this->assertSame(
			array(
				'@type' => 'Person',
				'@id'   => $url . '#author',
				'name'  => 'Ann',
				'url'   => $url,
			),
			$nodes['ProfilePage']['mainEntity']
		);
	}

	/**
	 * A person identity with logo and profiles replaces the organization.
	 */
	public function test_person_identity_from_settings(): void {
		$photo = self::factory()->attachment->create_object( 'me.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		update_option(
			Settings::OPTION,
			array(
				'identity_type' => 'person',
				'identity_name' => 'Jane Doe',
				'identity_logo' => $photo,
				'same_as'       => array( 'https://example.com/jane' ),
			)
		);

		$this->go_to( home_url( '/' ) );
		$nodes = $this->nodes( new Schema() );

		$this->assertArrayNotHasKey( 'Organization', $nodes );
		$this->assertSame( 'Jane Doe', $nodes['Person']['name'] );
		$this->assertSame( array( 'https://example.com/jane' ), $nodes['Person']['sameAs'] );
		$this->assertStringEndsWith( 'me.jpg', $nodes['Person']['image']['url'] );
		$this->assertSame( array( '@id' => home_url( '/' ) . '#person' ), $nodes['WebSite']['publisher'] );
	}

	/**
	 * When the page renders the breadcrumb block, the graph has the same trail.
	 */
	public function test_breadcrumb_list_matches_rendered_block(): void {
		$term_id = self::factory()->category->create( array( 'name' => 'News' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Hello',
				'post_category' => array( $term_id ),
			)
		);
		$schema  = new Schema();
		$schema->register();

		$this->go_to( get_permalink( $post_id ) );
		// Block themes render the template, breadcrumbs included, before wp_head.
		$html  = do_blocks( '<!-- wp:breadcrumbs /-->' );
		$nodes = $this->nodes( $schema );

		$this->assertStringContainsString( 'News', $html );
		$items = $nodes['BreadcrumbList']['itemListElement'];
		$this->assertSame( array( 'Home', 'News', 'Hello' ), array_column( $items, 'name' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $items, 'position' ) );
		$this->assertSame( home_url( '/' ), $items[0]['item'] );
		$this->assertSame( get_category_link( $term_id ), $items[1]['item'] );
		$this->assertSame( get_permalink( $post_id ), $items[2]['item'] );
		$this->assertSame( array( '@id' => get_permalink( $post_id ) . '#breadcrumb' ), $nodes['WebPage']['breadcrumb'] );
	}

	/**
	 * Titles cannot close the script tag; search and 404 pages get no graph.
	 */
	public function test_output_is_safe_and_absent_on_search_and_404(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'x</script><script>alert(1)</script>' ) );
		$this->go_to( get_permalink( $post_id ) );
		$html = get_echo( array( new Schema(), 'print_graph' ) );
		$this->assertSame( 1, substr_count( $html, '</script>' ) );

		$this->go_to( home_url( '/?s=x' ) );
		$this->assertSame( array(), $this->nodes( new Schema() ) );

		$this->go_to( home_url( '/?p=999999' ) );
		$this->assertSame( array(), $this->nodes( new Schema() ) );
	}

	/**
	 * Invalid identity settings are rejected.
	 */
	public function test_invalid_identity_settings_are_rejected(): void {
		update_option(
			Settings::OPTION,
			array(
				'identity_type' => 'robot',
				'same_as'       => array( 'not a url' ),
			)
		);

		$this->assertSame( 'organization', Settings::value( 'identity_type' ) );
		$this->assertSame( array(), Settings::value( 'same_as' ) );
	}
}
