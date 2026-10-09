<?php
/**
 * Tests for the template parts of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * A template part block shows the `{slug}-{language}` part on the pages of a language, if it exists.
 */
final class TemplatePartsTest extends WP_UnitTestCase {

	/**
	 * Italian (default), English and French; a header with an English version only.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Settings::OPTION,
			array(
				'languages' => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
					'fr' => array(
						'locale' => 'fr_FR',
						'name'   => 'Français',
					),
				),
			)
		);
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
	 * When the language version exists, the core finds it in the cache of the lookup: no second query.
	 */
	public function test_existing_part_is_not_queried_twice(): void {
		$this->go_to( 'http://example.org/en/' );
		wp_cache_flush();

		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			// The lookup of template parts by slug; priming their terms is the core's own work.
			if ( str_contains( (string) $sql, 'wp_template_part' ) && str_contains( (string) $sql, 'post_name IN' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		$html = $this->header();
		remove_filter( 'query', $capture );

		$this->assertStringContainsString( 'Header', $html );
		$this->assertCount( 1, $queries );
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
