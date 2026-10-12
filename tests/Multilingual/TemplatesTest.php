<?php
/**
 * Tests for the title and description templates of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use EasyRankly\Social\Social;
use EasyRankly\Titles\Titles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pages of a language use its templates first, then the templates of all languages.
 */
final class TemplatesTest extends WP_UnitTestCase {

	/**
	 * Italian (default) and English, with general and English templates.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		update_option(
			Settings::OPTION,
			array(
				'title_separator'    => '|',
				'languages'          => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
				),
				'templates'          => array(
					'single'      => array(
						'title'       => '{{title}} {{sep}} Leggi',
						'description' => 'Articolo: {{title}}',
					),
					'single-post' => array(
						'title'       => '{{title}} {{sep}} Articolo',
						'description' => '',
					),
				),
				'language_templates' => array(
					'en' => array(
						'single' => array(
							'title'       => '{{title}} {{sep}} Read',
							'description' => '',
						),
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
	 * A generic template of the language beats a specific one of all languages; empty fields fall back.
	 */
	public function test_language_templates_come_first(): void {
		$en = self::factory()->post->create(
			array(
				'post_title' => 'Hello',
				'post_name'  => 'hello',
			)
		);
		Translations::set_language( $en, 'en' );

		$this->go_to( 'http://example.org/en/hello/' );

		$this->assertSame( 'Hello | Read', Titles::title() );
		$this->assertSame( 'Articolo: Hello', Titles::description() );
	}

	/**
	 * The default language and other languages without templates use the general ones.
	 */
	public function test_default_language_uses_general_templates(): void {
		self::factory()->post->create(
			array(
				'post_title' => 'Ciao',
				'post_name'  => 'ciao',
			)
		);

		$this->go_to( 'http://example.org/ciao/' );

		$this->assertSame( 'Ciao | Articolo', Titles::title() );
	}

	/**
	 * The override of a post still wins over every template.
	 */
	public function test_override_wins(): void {
		$en = self::factory()->post->create(
			array(
				'post_name'  => 'hello',
				'meta_input' => array( '_easyrankly_title' => 'Custom' ),
			)
		);
		Translations::set_language( $en, 'en' );

		$this->go_to( 'http://example.org/en/hello/' );

		$this->assertSame( 'Custom', Titles::title() );
	}

	/**
	 * Terms are not translated: their own texts and description are in the default language,
	 * so the other languages use their templates instead.
	 */
	public function test_term_texts_stay_in_the_default_language(): void {
		create_initial_taxonomies(); // Re-adds the category permastruct for the new structure.
		flush_rewrite_rules( false );
		$settings                                     = get_option( Settings::OPTION );
		$settings['language_templates']['en']['term'] = array(
			'title'       => '{{title}} {{sep}} Topic',
			'description' => 'All posts about {{title}}{{term_description}}',
		);
		update_option( Settings::OPTION, $settings );

		$term = self::factory()->category->create(
			array(
				'name'        => 'News',
				'slug'        => 'news',
				'description' => 'Notizie',
			)
		);
		update_term_meta( $term, '_easyrankly_title', 'Notizie dal sito' );
		update_term_meta( $term, '_easyrankly_description', 'Tutte le notizie' );
		update_term_meta( $term, '_easyrankly_og_title', 'Le notizie' );
		Translations::set_language( self::factory()->post->create( array( 'post_category' => array( $term ) ) ), 'it' );
		Translations::set_language( self::factory()->post->create( array( 'post_category' => array( $term ) ) ), 'en' );

		$this->go_to( 'http://example.org/category/news/' );
		$this->assertSame( 'Notizie dal sito', Titles::title() );
		$this->assertSame( 'Tutte le notizie', Titles::description() );
		$this->assertContains( array( 'property', 'og:title', 'Le notizie' ), Social::tags() );

		$this->go_to( 'http://example.org/en/category/news/' );
		$this->assertSame( 'News | Topic', Titles::title() );
		$this->assertSame( 'All posts about News', Titles::description() );
		$this->assertContains( array( 'property', 'og:title', 'News | Topic' ), Social::tags() );
	}

	/**
	 * Unknown languages or contexts are rejected by the settings schema.
	 */
	public function test_schema_rejects_invalid_keys(): void {
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach (
			array(
				array( 'EN' => array( 'single' => array( 'title' => 'x' ) ) ),
				array( 'en' => array( 'bogus' => array( 'title' => 'x' ) ) ),
				array( 'en' => array( 'single' => array( 'other' => 'x' ) ) ),
			) as $value
		) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
			$request->set_body_params( array( Settings::OPTION => array( 'language_templates' => $value ) ) );
			$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
		}

		$this->assertArrayHasKey( 'en', Settings::value( 'language_templates' ) );
	}
}
