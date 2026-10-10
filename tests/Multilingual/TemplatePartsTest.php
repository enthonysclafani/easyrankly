<?php
/**
 * Tests for the template parts of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use WP_UnitTestCase;

/**
 * A template part block shows the `{slug}-{language}` part on the pages of a language, if it exists.
 */
final class TemplatePartsTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Italian (default), English and French; a header with an English version only.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en', 'fr' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->part( 'header', 'Intestazione' );
		$this->part( 'header-en', 'Header' );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Saves a template part of the active theme, as the Site Editor does.
	 *
	 * @param string $slug Template part slug.
	 * @param string $text Text of its paragraph.
	 */
	private function part( string $slug, string $text ): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->',
			)
		);
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
	}

	/**
	 * Renders the header block.
	 *
	 * @return string
	 */
	private function header(): string {
		return do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
	}

	/**
	 * The English pages show the English header; the default language its own.
	 */
	public function test_language_shows_its_template_part(): void {
		$this->go_to( 'http://example.org/en/' );
		$this->assertStringContainsString( 'Header', $this->header() );
		$this->assertStringNotContainsString( 'Intestazione', $this->header() );

		$this->go_to( 'http://example.org/' );
		$this->assertStringContainsString( 'Intestazione', $this->header() );
	}

	/**
	 * A language without its version shows the template part of the block.
	 */
	public function test_language_without_part_keeps_default(): void {
		$this->go_to( 'http://example.org/fr/' );

		$this->assertStringContainsString( 'Intestazione', $this->header() );
	}

	/**
	 * However many template parts the page has, the language versions cost one query.
	 */
	public function test_one_query_for_all_template_parts(): void {
		$this->part( 'footer', 'Piede' );
		$blocks = '<!-- wp:template-part {"slug":"header"} /--><!-- wp:template-part {"slug":"footer"} /--><!-- wp:template-part {"slug":"sidebar"} /-->';
		$count  = function ( string $url ) use ( $blocks ): int {
			global $wpdb;
			$this->go_to( $url );
			wp_cache_flush();
			$before = $wpdb->num_queries;
			do_blocks( $blocks );
			return $wpdb->num_queries - $before;
		};

		$default = $count( 'http://example.org/' );

		$this->assertLessThanOrEqual( $default + 1, $count( 'http://example.org/fr/' ) );
		$this->assertLessThanOrEqual( $default + 1, $count( 'http://example.org/en/' ) );
	}

	/**
	 * Admin and REST render the template part of the block, as saved.
	 */
	public function test_admin_keeps_template_part(): void {
		$_SERVER['REQUEST_URI'] = '/en/wp-admin/site-editor.php';
		set_current_screen( 'site-editor' );

		$this->assertStringContainsString( 'Intestazione', $this->header() );
		set_current_screen( 'front' );
	}
}
