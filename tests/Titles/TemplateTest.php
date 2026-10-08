<?php
/**
 * Tests for template rendering.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Titles;

use EasyRankly\Titles\Template;
use WP_UnitTestCase;

/**
 * Variables are replaced with plain text and empty ones leave no dangling separators.
 */
final class TemplateTest extends WP_UnitTestCase {

	/**
	 * Known variables are replaced, unknown ones vanish, case and spaces inside braces are ignored.
	 */
	public function test_replaces_variables(): void {
		$this->assertSame(
			'Hello | Site',
			Template::render(
				'{{ Title }} {{sep}} {{site_name}}{{nope}}',
				array(
					'title'     => 'Hello',
					'site_name' => 'Site',
				),
				'|'
			)
		);
	}

	/**
	 * Empty variables do not leave double or edge separators.
	 */
	public function test_empty_variables_leave_no_dangling_separators(): void {
		$this->assertSame(
			'Hello - Site',
			Template::render(
				'{{title}} {{page}} {{sep}} {{sep}} {{site_name}}',
				array(
					'title'     => 'Hello',
					'site_name' => 'Site',
				),
				'-'
			)
		);
		$this->assertSame( 'Site', Template::render( '{{title}} {{sep}} {{site_name}} {{sep}}', array( 'site_name' => 'Site' ), '-' ) );
		$this->assertSame( '', Template::render( '{{sep}}', array(), '-' ) );
	}

	/**
	 * Values lose markup and entities; lazy values run only when used.
	 */
	public function test_values_are_plain_and_lazy(): void {
		$called = false;
		$lazy   = static function () use ( &$called ): string {
			$called = true;
			return 'x';
		};

		$this->assertSame(
			'Tom & Jerry’s',
			Template::render(
				'{{title}}',
				array(
					'title'   => '<b>Tom &amp; Jerry&#8217;s</b>',
					'excerpt' => $lazy,
				),
				'-'
			)
		);
		$this->assertFalse( $called );
		$this->assertSame( 'x', Template::render( '{{excerpt}}', array( 'excerpt' => $lazy ), '-' ) );
	}

	/**
	 * Generated excerpts drop shortcodes and are cut at a word boundary.
	 */
	public function test_excerpt_from_content(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>[gallery ids="1"]' . str_repeat( 'word ', 60 ) . '</p><!-- /wp:paragraph -->',
			)
		);

		$excerpt = Template::excerpt( $post );

		$this->assertStringNotContainsString( '[gallery', $excerpt );
		$this->assertStringEndsWith( 'word…', $excerpt );
		$this->assertLessThanOrEqual( Template::EXCERPT_LENGTH + 1, mb_strlen( $excerpt ) );
	}

	/**
	 * A manual excerpt is used as is.
	 */
	public function test_manual_excerpt_wins(): void {
		$post = self::factory()->post->create_and_get( array( 'post_excerpt' => 'Short summary.' ) );

		$this->assertSame( 'Short summary.', Template::excerpt( $post ) );
	}
}
