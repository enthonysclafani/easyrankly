<?php
/**
 * Tests for robots.txt.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\RobotsTxt;

use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * The extra rules are appended to the core robots.txt, only on public sites.
 */
final class RobotsTxtTest extends WP_UnitTestCase {

	/**
	 * Default settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
	}

	/**
	 * The robots.txt WordPress serves.
	 *
	 * @return string
	 */
	private function robots_txt(): string {
		return get_echo( 'do_robots' );
	}

	/**
	 * Without rules the core file is untouched.
	 */
	public function test_core_file_is_untouched_by_default(): void {
		update_option( 'blog_public', '1' );

		$this->assertSame( apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n", true ), $this->robots_txt() );
		$this->assertStringContainsString( 'Sitemap: ', $this->robots_txt() );
	}

	/**
	 * Rules are appended after the core lines, which stay, with markup stripped.
	 */
	public function test_rules_are_appended(): void {
		update_option( 'blog_public', '1' );
		update_option( Settings::OPTION, array( 'robots_txt' => "User-agent: GPTBot\r\nDisallow: /<b>private</b>/" ) );

		$output = $this->robots_txt();

		$this->assertStringContainsString( "Disallow: /wp-admin/\n", $output );
		$this->assertStringContainsString( 'Sitemap: ', $output );
		$this->assertStringEndsWith( "\n\nUser-agent: GPTBot\nDisallow: /private/\n", $output );
	}

	/**
	 * A site hidden from search engines does not get the extra rules.
	 */
	public function test_private_site_gets_no_rules(): void {
		update_option( 'blog_public', '0' );
		update_option( Settings::OPTION, array( 'robots_txt' => 'Allow: /' ) );

		$this->assertStringNotContainsString( "\n\nAllow: /", $this->robots_txt() );
	}

	/**
	 * Overlong rules are rejected.
	 */
	public function test_overlong_rules_are_rejected(): void {
		update_option( Settings::OPTION, array( 'robots_txt' => str_repeat( 'a', 5001 ) ) );

		$this->assertSame( '', Settings::value( 'robots_txt' ) );
	}
}
