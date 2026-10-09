<?php
/**
 * Tests for the synced patterns of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * A synced pattern block shows the `{slug}-{language}` pattern on the pages of a language, if it exists.
 */
final class SyncedPatternsTest extends WP_UnitTestCase {

	/**
	 * Synced pattern referenced by the block.
	 *
	 * @var int
	 */
	private int $banner;

	/**
	 * Italian (default), English, French and German; a banner with an English version only.
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
					'de' => array(
						'locale' => 'de_DE',
						'name'   => 'Deutsch',
					),
				),
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		$this->banner = $this->pattern( 'Banner', 'Testo' );
		$this->pattern( 'Banner - EN', 'Text' );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Saves a synced pattern, as the Site Editor does: the slug comes from the title.
	 *
	 * @param string               $title Pattern title.
	 * @param string               $text  Text of its paragraph.
	 * @param array<string, mixed> $args  Other post fields.
	 * @return int
	 */
	private function pattern( string $title, string $text, array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'wp_block',
					'post_title'   => $title,
					'post_content' => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->',
				),
				$args
			)
		);
	}

	/**
	 * Renders the banner block.
	 *
	 * @return string
	 */
	private function banner(): string {
		return do_blocks( '<!-- wp:block {"ref":' . $this->banner . '} /-->' );
	}

	/**
	 * The English pages show the English banner; the default language its own.
	 */
	public function test_language_shows_its_synced_pattern(): void {
		$this->go_to( 'http://example.org/en/' );
		$this->assertStringContainsString( 'Text', $this->banner() );
		$this->assertStringNotContainsString( 'Testo', $this->banner() );

		$this->go_to( 'http://example.org/' );
		$this->assertStringContainsString( 'Testo', $this->banner() );
	}

	/**
	 * A language without its version shows the pattern of the block.
	 */
	public function test_language_without_pattern_keeps_default(): void {
		$this->go_to( 'http://example.org/fr/' );

		$this->assertStringContainsString( 'Testo', $this->banner() );
	}

	/**
	 * A version the core would not render (draft, password) or that is not a synced pattern is ignored.
	 */
	public function test_unrenderable_versions_are_ignored(): void {
		$this->pattern( 'Banner - FR', 'Brouillon', array( 'post_status' => 'draft' ) );
		$this->pattern( 'Banner - DE', 'Geheim', array( 'post_password' => 'secret' ) );
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'banner-fr',
				'post_title' => 'Banner FR',
			)
		);

		$this->go_to( 'http://example.org/fr/' );
		$this->assertStringContainsString( 'Testo', $this->banner() );

		$this->go_to( 'http://example.org/de/' );
		$this->assertStringContainsString( 'Testo', $this->banner() );
	}

	/**
	 * The default language does not look for versions: no query beyond the core's.
	 */
	public function test_default_language_adds_no_query(): void {
		$this->go_to( 'http://example.org/' );
		wp_cache_flush();

		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			if ( str_contains( (string) $sql, 'wp_block' ) && str_contains( (string) $sql, 'post_name' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		$html = $this->banner();
		remove_filter( 'query', $capture );

		$this->assertStringContainsString( 'Testo', $html );
		$this->assertCount( 0, $queries );
	}

	/**
	 * Admin and REST render the synced pattern of the block, as saved.
	 */
	public function test_admin_keeps_synced_pattern(): void {
		$_SERVER['REQUEST_URI'] = '/en/wp-admin/site-editor.php';
		set_current_screen( 'site-editor' );

		$this->assertStringContainsString( 'Testo', $this->banner() );
		set_current_screen( 'front' );
	}
}
