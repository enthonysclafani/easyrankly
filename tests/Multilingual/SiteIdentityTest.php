<?php
/**
 * Tests for the site title and tagline of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Schema\Schema;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use EasyRankly\Titles\Titles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pages of a language show its site title and tagline; everything else reads Settings > General.
 */
final class SiteIdentityTest extends WP_UnitTestCase {

	/**
	 * Italian (default) and English with its own site title and tagline, French without.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'blogname', 'Il mio sito' );
		update_option( 'blogdescription', 'Solo un altro sito' );
		update_option(
			Settings::OPTION,
			array(
				'languages' => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale'     => 'en_US',
						'name'       => 'English',
						'site_title' => 'Tom & Jerry',
						'tagline'    => 'Just another site',
					),
					'fr' => array(
						'locale' => 'fr_FR',
						'name'   => 'Français',
					),
				),
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * The home of a language shows its site title and tagline, escaped as the core stores them.
	 */
	public function test_language_pages_use_its_site_title_and_tagline(): void {
		$this->go_to( 'http://example.org/en/' );

		$this->assertSame( 'Tom &amp; Jerry', get_bloginfo( 'name' ) );
		$this->assertSame( 'Just another site', get_bloginfo( 'description' ) );
		$this->assertSame( 'Tom & Jerry - Just another site', Titles::title() );
	}

	/**
	 * The default language and a language without its own texts keep those of Settings > General.
	 */
	public function test_other_languages_keep_general_texts(): void {
		$this->go_to( 'http://example.org/' );
		$this->assertSame( 'Il mio sito', get_bloginfo( 'name' ) );
		$this->assertSame( 'Solo un altro sito', get_bloginfo( 'description' ) );

		$this->go_to( 'http://example.org/fr/' );
		$this->assertSame( 'Il mio sito', get_bloginfo( 'name' ) );
	}

	/**
	 * The texts show in Open Graph and in the schema.
	 */
	public function test_head_uses_language_site_title(): void {
		$this->go_to( 'http://example.org/en/' );

		$head = get_echo( array( new Social(), 'print_tags' ) ) . get_echo( array( new Schema(), 'print_graph' ) );

		$this->assertStringContainsString( '<meta property="og:site_name" content="Tom &amp; Jerry"', $head );
		$this->assertStringContainsString( '"name":"Tom \u0026 Jerry"', $head );
		$this->assertStringNotContainsString( 'Il mio sito', $head );
	}

	/**
	 * Admin and REST read the stored options, so Settings > General edits the real values.
	 */
	public function test_admin_and_rest_read_stored_options(): void {
		$_SERVER['REQUEST_URI'] = '/en/wp-admin/options-general.php';
		set_current_screen( 'options-general' );
		$this->assertSame( 'Il mio sito', get_option( 'blogname' ) );
		set_current_screen( 'front' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/settings';
		$response               = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$this->assertSame( 'Il mio sito', $response->get_data()['title'] );
	}

	/**
	 * With one language nothing changes, even if it has its own texts.
	 */
	public function test_single_language_changes_nothing(): void {
		update_option(
			Settings::OPTION,
			array(
				'languages' => array(
					'en' => array(
						'locale'     => 'en_US',
						'name'       => 'English',
						'site_title' => 'Other',
					),
				),
			)
		);

		$this->go_to( 'http://example.org/' );

		$this->assertSame( 'Il mio sito', get_bloginfo( 'name' ) );
	}

	/**
	 * Texts longer than the schema allows are rejected with a 400.
	 */
	public function test_schema_rejects_long_texts(): void {
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params(
			array(
				Settings::OPTION => array(
					'languages' => array(
						'en' => array(
							'locale'     => 'en_US',
							'name'       => 'English',
							'site_title' => str_repeat( 'a', 201 ),
						),
					),
				),
			)
		);

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}
}
