<?php
/**
 * Tests for the languages setting and the hidden taxonomies.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Languages are validated by the settings schema; the taxonomies stay hidden.
 */
final class LanguagesTest extends WP_UnitTestCase {

	/**
	 * Fresh REST server and no stored settings.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Saves the languages setting through the REST settings endpoint as an administrator.
	 *
	 * @param mixed $languages Value of the `languages` setting.
	 * @return \WP_REST_Response
	 */
	private function save( $languages ): \WP_REST_Response {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'languages' => $languages ) ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Without languages nothing is enabled and there is no default.
	 */
	public function test_no_languages_by_default(): void {
		$this->assertSame( array(), Languages::all() );
		$this->assertSame( '', Languages::default() );
		$this->assertFalse( Languages::enabled() );
	}

	/**
	 * The first language is the default; two languages enable the feature.
	 */
	public function test_first_language_is_default_and_two_enable(): void {
		$response = $this->save(
			array(
				'it' => array(
					'locale' => 'it_IT',
					'name'   => 'Italiano',
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'it', Languages::default() );
		$this->assertFalse( Languages::enabled() );

		$this->save(
			array(
				'en' => array(
					'locale' => 'en_US',
					'name'   => '<b>English</b>',
				),
				'it' => array(
					'locale' => 'it_IT',
					'name'   => 'Italiano',
				),
			)
		);

		$this->assertSame( 'en', Languages::default() );
		$this->assertTrue( Languages::enabled() );
		$this->assertSame( array( 'en', 'it' ), array_keys( Languages::all() ) );
		$this->assertSame( 'English', Languages::all()['en']['name'] );
	}

	/**
	 * Invalid slugs, locales and missing names are rejected with a 400 and change nothing.
	 *
	 * @dataProvider invalid_languages
	 *
	 * @param array<string, mixed> $languages Invalid value.
	 */
	public function test_invalid_languages_are_rejected( array $languages ): void {
		$response = $this->save( $languages );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), Languages::all() );
	}

	/**
	 * Invalid values of the languages setting.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function invalid_languages(): array {
		$valid = array(
			'locale' => 'en_US',
			'name'   => 'English',
		);

		return array(
			'uppercase slug'   => array( array( 'EN' => $valid ) ),
			'slug with slash'  => array( array( 'en/us' => $valid ) ),
			'wp- slug'         => array( array( 'wp-json' => $valid ) ),
			'bad locale'       => array(
				array(
					'en' => array(
						'locale' => 'en-us',
						'name'   => 'English',
					),
				),
			),
			'missing name'     => array( array( 'en' => array( 'locale' => 'en_US' ) ) ),
			'empty name'       => array(
				array(
					'en' => array(
						'locale' => 'en_US',
						'name'   => '',
					),
				),
			),
			'unknown property' => array( array( 'en' => $valid + array( 'flag' => 'x' ) ) ),
		);
	}

	/**
	 * More than 20 languages are rejected.
	 */
	public function test_too_many_languages_are_rejected(): void {
		$languages = array();
		foreach ( range( 'a', 'u' ) as $letter ) {
			$languages[ 'a' . $letter ] = array(
				'locale' => 'en_US',
				'name'   => 'Language ' . $letter,
			);
		}

		$this->assertSame( 400, $this->save( $languages )->get_status() );
	}

	/**
	 * Both taxonomies exist on viewable post types only, hidden from UI, URLs and REST.
	 */
	public function test_taxonomies_are_hidden_and_on_viewable_types(): void {
		foreach ( array( Translations::LANGUAGE, Translations::GROUP ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			$this->assertInstanceOf( \WP_Taxonomy::class, $taxonomy );
			$this->assertFalse( $taxonomy->public );
			$this->assertFalse( $taxonomy->show_ui );
			$this->assertFalse( $taxonomy->show_in_rest );
			$this->assertFalse( $taxonomy->rewrite );
			$this->assertFalse( $taxonomy->query_var );
			$this->assertSame( 'manage_options', $taxonomy->cap->edit_terms );
			$this->assertSame( 'edit_posts', $taxonomy->cap->assign_terms );
			$this->assertContains( 'post', $taxonomy->object_type );
			$this->assertContains( 'page', $taxonomy->object_type );
			$this->assertNotContains( 'attachment', $taxonomy->object_type );
			$this->assertNotContains( 'erankly_redirect', $taxonomy->object_type );
		}
	}
}
