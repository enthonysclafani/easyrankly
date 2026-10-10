<?php
/**
 * Tests for the synced patterns of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use WP_UnitTestCase;

/**
 * A synced pattern block shows the `{slug}-{language}` pattern on the pages of a language, if it exists.
 */
final class SyncedPatternsTest extends WP_UnitTestCase {
	use WithLanguages;

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
		$this->set_languages( 'it', 'en', 'fr', 'de' );
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
	 * However many synced patterns the page has, their language versions cost no query more than
	 * the patterns themselves: one query finds the versions and loads the patterns the core renders.
	 */
	public function test_one_query_for_all_synced_patterns(): void {
		$blocks = '<!-- wp:block {"ref":' . $this->banner . '} /-->';
		foreach ( array( 'Quote', 'Footer note', 'Call' ) as $title ) {
			$blocks .= '<!-- wp:block {"ref":' . $this->pattern( $title, $title ) . '} /-->';
		}
		$count = function ( string $url ) use ( $blocks ): int {
			global $wpdb;
			$this->go_to( $url );
			wp_cache_flush();
			$before = $wpdb->num_queries;
			do_blocks( $blocks );
			return $wpdb->num_queries - $before;
		};

		$this->assertLessThanOrEqual( $count( 'http://example.org/' ), $count( 'http://example.org/en/' ) );
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
