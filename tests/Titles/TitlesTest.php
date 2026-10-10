<?php
/**
 * Tests for the document title and meta description on real requests.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Titles;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Titles;
use WP_UnitTestCase;

/**
 * Titles and descriptions follow templates and overrides in every context.
 */
final class TitlesTest extends WP_UnitTestCase {

	/**
	 * Fixed site name and tagline, meta registered (the test case unregisters it after each test).
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		// Multisite test installs start with pretty permalinks; each test chooses its own.
		$this->set_permalink_structure( '' );
		update_option( 'blogname', 'Site' );
		update_option( 'blogdescription', 'Tagline' );
		delete_option( Settings::OPTION );
	}

	/**
	 * Data provider: plain and pretty permalinks.
	 *
	 * @return array<string, array{string}>
	 */
	public function permalinks(): array {
		return array(
			'plain'  => array( '' ),
			'pretty' => array( '/%postname%/' ),
		);
	}

	/**
	 * Prints wp_head's description tag for the current request.
	 *
	 * @return string
	 */
	private function head(): string {
		return get_echo( array( new \EasyRankly\Titles\Titles(), 'print_description' ) );
	}

	/**
	 * A post uses the single template and its excerpt.
	 *
	 * @dataProvider permalinks
	 *
	 * @param string $structure Permalink structure.
	 */
	public function test_single_post_uses_template( string $structure ): void {
		$this->set_permalink_structure( $structure );
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Hello <em>World</em>',
				'post_excerpt' => 'About "this" & that.',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Hello World - Site', wp_get_document_title() );
		$this->assertSame( '<meta name="description" content="About &quot;this&quot; &amp; that." />' . "\n", $this->head() );
	}

	/**
	 * Protected and private posts keep their own title, without the prefixes of get_the_title().
	 */
	public function test_protected_and_private_titles_have_no_prefix(): void {
		$protected = self::factory()->post->create(
			array(
				'post_title'    => 'Locked',
				'post_password' => 'secret',
			)
		);
		$this->go_to( get_permalink( $protected ) );
		$this->assertSame( 'Locked - Site', wp_get_document_title() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$private = self::factory()->post->create(
			array(
				'post_title'  => 'Hidden',
				'post_status' => 'private',
			)
		);
		$this->go_to( get_permalink( $private ) );
		$this->assertSame( 'Hidden - Site', wp_get_document_title() );
	}

	/**
	 * The per-post override wins over the template and may use variables.
	 */
	public function test_override_wins_and_uses_variables(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Hello' ) );
		update_post_meta( $post_id, '_easyrankly_title', 'Custom {{sep}} {{title}}' );
		update_post_meta( $post_id, '_easyrankly_description', 'Custom description' );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Custom - Hello', wp_get_document_title() );
		$this->assertStringContainsString( 'content="Custom description"', $this->head() );
	}

	/**
	 * A post-type template wins over the generic single template; the separator comes from settings.
	 */
	public function test_post_type_template_and_separator(): void {
		update_option(
			Settings::OPTION,
			array(
				'title_separator' => '|',
				'templates'       => array( 'single-page' => array( 'title' => '{{title}} {{sep}} {{post_type}}' ) ),
			)
		);
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$post_id = self::factory()->post->create( array( 'post_title' => 'News' ) );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertSame( 'About | Page', wp_get_document_title() );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'News | Site', wp_get_document_title() );
	}

	/**
	 * Term archives use the term template, the term description and the term override.
	 */
	public function test_term_archive(): void {
		$term_id = self::factory()->category->create(
			array(
				'name'        => 'Shoes',
				'description' => '<p>All our shoes.</p>',
			)
		);
		self::factory()->post->create( array( 'post_category' => array( $term_id ) ) );

		$this->go_to( get_category_link( $term_id ) );
		$this->assertSame( 'Shoes - Site', wp_get_document_title() );
		$this->assertStringContainsString( 'content="All our shoes."', $this->head() );

		update_term_meta( $term_id, '_easyrankly_title', 'Best shoes' );
		$this->go_to( get_category_link( $term_id ) );
		$this->assertSame( 'Best shoes', wp_get_document_title() );
	}

	/**
	 * The front page shows the site name and tagline.
	 */
	public function test_home(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 'Site - Tagline', wp_get_document_title() );
		$this->assertStringContainsString( 'content="Tagline"', $this->head() );
	}

	/**
	 * Paged archives get "Page N of M".
	 */
	public function test_paged_archive(): void {
		update_option( 'posts_per_page', 1 );
		$term_id = self::factory()->category->create( array( 'name' => 'News' ) );
		self::factory()->post->create_many( 3, array( 'post_category' => array( $term_id ) ) );

		$this->go_to( add_query_arg( 'paged', 2, get_category_link( $term_id ) ) );

		$this->assertSame( 'News Page 2 of 3 - Site', wp_get_document_title() );
	}

	/**
	 * Search and 404 have their own titles; search terms are escaped.
	 */
	public function test_search_and_404(): void {
		$this->go_to( home_url( '/?s=%3Cscript%3E' ) );
		$title = wp_get_document_title();
		$this->assertStringContainsString( '&lt;script&gt;', $title );
		$this->assertStringNotContainsString( '<script>', $title );
		$this->assertSame( '', $this->head() );

		$this->go_to( home_url( '/?p=999999' ) );
		$this->assertSame( 'Page not found - Site', wp_get_document_title() );
	}

	/**
	 * An empty template leaves the core title and prints no description.
	 */
	public function test_empty_template_leaves_core_title(): void {
		update_option(
			Settings::OPTION,
			array(
				'templates' => array(
					'single' => array(
						'title'       => '',
						'description' => '',
					),
				),
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Core',
				'post_excerpt' => 'x',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Core &#8211; Site', wp_get_document_title() );
		$this->assertSame( '', $this->head() );
	}

	/**
	 * Unknown template keys are rejected by the settings schema.
	 */
	public function test_unknown_template_keys_are_rejected(): void {
		update_option( Settings::OPTION, array( 'templates' => array( 'bogus' => array( 'title' => 'x' ) ) ) );

		$this->assertArrayNotHasKey( 'bogus', Settings::value( 'templates' ) );
	}

	/**
	 * The variables listed on the settings screen are those the templates understand.
	 */
	public function test_variables_list_matches_templates(): void {
		$author = self::factory()->user->create( array( 'display_name' => 'Ann' ) );
		$term   = self::factory()->category->create();
		$post   = self::factory()->post->create(
			array(
				'post_author'   => $author,
				'post_category' => array( $term ),
			)
		);
		$seen   = array( 'sep' );

		foreach ( array( get_permalink( $post ), get_category_link( $term ), get_author_posts_url( $author ), home_url( '/?s=x' ), home_url( '/?m=' . gmdate( 'Y' ) ) ) as $url ) {
			$this->go_to( $url );
			$seen = array_merge( $seen, array_keys( Titles::variables() ) );
		}

		$this->assertEqualSets( Titles::VARIABLES, array_values( array_unique( $seen ) ) );
	}
}
