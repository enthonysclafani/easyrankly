<?php
/**
 * Tests for the breadcrumb block filters and their match with the schema.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Breadcrumbs;

use EasyRankly\Meta\Meta;
use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * The core breadcrumb block follows the settings, and the BreadcrumbList follows the block.
 */
final class BreadcrumbsTest extends WP_UnitTestCase {

	/**
	 * Plain permalinks, meta registered, default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		$this->set_permalink_structure( '' );
		delete_option( Settings::OPTION );
	}

	/**
	 * Renders the core block for the current request.
	 *
	 * @return string
	 */
	private function render(): string {
		return do_blocks( '<!-- wp:breadcrumbs /-->' );
	}

	/**
	 * A post in a category and a tag.
	 *
	 * @return int Post ID.
	 */
	private function post(): int {
		$category = self::factory()->category->create( array( 'name' => 'News' ) );

		return self::factory()->post->create(
			array(
				'post_title'    => 'Hello',
				'post_category' => array( $category ),
				'tags_input'    => array( 'Featured' ),
			)
		);
	}

	/**
	 * Without settings the core trail is untouched.
	 */
	public function test_core_trail_is_untouched_by_default(): void {
		$this->go_to( get_permalink( $this->post() ) );
		$html = $this->render();

		$this->assertStringContainsString( '>Home</a>', $html );
		$this->assertStringContainsString( '>News</a>', $html );
		$this->assertStringNotContainsString( 'Featured', $html );
	}

	/**
	 * The home label from the settings replaces the core one, escaped.
	 */
	public function test_home_label_is_replaced(): void {
		update_option( Settings::OPTION, array( 'breadcrumb_home_label' => 'Start & go' ) );

		$this->go_to( get_permalink( $this->post() ) );
		$html = $this->render();

		$this->assertStringContainsString( '>Start &amp; go</a>', $html );
		$this->assertStringNotContainsString( '>Home<', $html );
	}

	/**
	 * The taxonomy chosen for the post type drives the trail.
	 */
	public function test_taxonomy_per_post_type(): void {
		update_option( Settings::OPTION, array( 'breadcrumb_taxonomies' => array( 'post' => 'post_tag' ) ) );

		$this->go_to( get_permalink( $this->post() ) );
		$html = $this->render();

		$this->assertStringContainsString( '>Featured</a>', $html );
		$this->assertStringNotContainsString( '>News</a>', $html );
	}

	/**
	 * A taxonomy the post type does not use is ignored.
	 */
	public function test_unrelated_taxonomy_is_ignored(): void {
		update_option( Settings::OPTION, array( 'breadcrumb_taxonomies' => array( 'post' => 'nav_menu' ) ) );

		$this->go_to( get_permalink( $this->post() ) );

		$this->assertStringContainsString( '>News</a>', $this->render() );
	}

	/**
	 * The BreadcrumbList carries exactly the labels the visitor sees.
	 */
	public function test_schema_matches_filtered_trail(): void {
		update_option(
			Settings::OPTION,
			array(
				'breadcrumb_home_label' => 'Start',
				'breadcrumb_taxonomies' => array( 'post' => 'post_tag' ),
			)
		);
		$schema = new Schema();
		$schema->register();

		$this->go_to( get_permalink( $this->post() ) );
		$this->render();
		$html = get_echo( array( $schema, 'print_graph' ) );

		preg_match( '#<script type="application/ld\+json">(.+)</script>#s', $html, $matches );
		$graph = json_decode( $matches[1], true )['@graph'];
		$list  = array_values( array_filter( $graph, static fn( array $node ): bool => 'BreadcrumbList' === $node['@type'] ) )[0];

		$this->assertSame( array( 'Start', 'Featured', 'Hello' ), array_column( $list['itemListElement'], 'name' ) );
	}

	/**
	 * Invalid taxonomy maps are rejected.
	 */
	public function test_invalid_map_is_rejected(): void {
		update_option( Settings::OPTION, array( 'breadcrumb_taxonomies' => array( 'Post Type' => 'x' ) ) );

		$this->assertSame( array(), Settings::value( 'breadcrumb_taxonomies' ) );
	}
}
