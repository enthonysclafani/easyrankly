<?php
/**
 * Tests for the conversion of the settings pages into settings.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Settings\Admin\Form;
use EasyRankly\Settings\Admin\Page;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * Each page saves only its own settings, as options.php does: update_option() with the
 * unslashed `easyrankly_settings` array of the form.
 */
final class FormTest extends WP_UnitTestCase {

	/**
	 * Hooks the conversion (the admin screens hook it only in admin) and starts from known settings.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'sanitize_option_' . Settings::OPTION, array( Form::class, 'normalize' ), 5 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			Settings::OPTION,
			array(
				'title_separator'       => '|',
				'robots_txt'            => 'Disallow: /private/',
				'noindex'               => array( 'author', 'single-gone' ),
				'breadcrumb_taxonomies' => array(
					'post' => 'post_tag',
					'gone' => 'topic',
				),
				'same_as'               => array( 'https://example.org/me' ),
				'languages'             => array(
					'it' => array(
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					'en' => array(
						'locale' => 'en_US',
						'name'   => 'English',
					),
				),
				'language_templates'    => array(
					'en' => array(
						'single' => array(
							'title'       => '{{title}} (EN)',
							'description' => '',
						),
					),
				),
			)
		);
		$GLOBALS['wp_settings_errors'] = array();
	}

	/**
	 * Saves a page as options.php would.
	 *
	 * @param string               $page   Page slug.
	 * @param array<string, mixed> $fields Posted fields of the page.
	 * @return array<string, mixed> Stored settings.
	 */
	private function save( string $page, array $fields ): array {
		update_option( Settings::OPTION, array( '_page' => $page ) + $fields );

		return (array) get_option( Settings::OPTION );
	}

	/**
	 * Codes of the errors shown after saving.
	 *
	 * @return list<string>
	 */
	private function errors(): array {
		return array_values( array_column( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	/**
	 * The General page changes its settings and leaves those of the other pages alone.
	 */
	public function test_general_page_keeps_other_settings(): void {
		$stored = $this->save(
			Page::SLUG,
			array(
				'title_separator'       => '·',
				'breadcrumb_home_label' => 'Start',
				'breadcrumb_taxonomies' => array( 'post' => '' ),
			)
		);

		$this->assertSame( '·', $stored['title_separator'] );
		$this->assertSame( 'Start', $stored['breadcrumb_home_label'] );
		// Emptied for a post type on the page; kept for one the page does not show.
		$this->assertSame( array( 'gone' => 'topic' ), $stored['breadcrumb_taxonomies'] );
		$this->assertSame( 'Disallow: /private/', $stored['robots_txt'] );
		$this->assertSame( array( 'it', 'en' ), array_keys( $stored['languages'] ) );
	}

	/**
	 * Unchecked boxes are not posted: rules shown on the page go, rules for gone types stay.
	 */
	public function test_indexing_page_clears_unchecked_rules(): void {
		$stored = $this->save( 'easyrankly-indexing', array( 'robots_txt' => '' ) );

		$this->assertSame( array( 'single-gone' ), $stored['noindex'] );
		$this->assertSame( '', $stored['robots_txt'] );

		$stored = $this->save(
			'easyrankly-indexing',
			array(
				'robots_txt' => 'Disallow: /tmp/',
				'noindex'    => array(
					'date'          => '1',
					'term-category' => '1',
					'invented'      => '1',
				),
			)
		);

		$this->assertSame( array( 'single-gone', 'date', 'term-category' ), $stored['noindex'] );
		$this->assertSame( '|', $stored['title_separator'] );
	}

	/**
	 * Templates of all languages: generic contexts keep empty fields, type contexts drop them.
	 */
	public function test_titles_page_saves_templates_of_all_languages(): void {
		$stored = $this->save(
			'easyrankly-titles',
			array(
				'templates' => array(
					'home'        => array(
						'title'       => '',
						'description' => '<b>Welcome</b>',
					),
					'single-page' => array(
						'title'       => '{{title}}',
						'description' => '',
					),
					'single-post' => array(
						'title'       => '',
						'description' => '',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'title'       => '',
				'description' => 'Welcome',
			),
			$stored['templates']['home']
		);
		// A generic context not posted is saved empty, as the page shows every generic context.
		$this->assertSame( '', $stored['templates']['single']['title'] );
		$this->assertSame( '{{title}}', $stored['templates']['single-page']['title'] );
		$this->assertArrayNotHasKey( 'single-post', $stored['templates'] );
		$this->assertSame( '{{title}} (EN)', $stored['language_templates']['en']['single']['title'] );
	}

	/**
	 * One language at a time: the others keep their templates; an empty language disappears.
	 */
	public function test_titles_page_saves_one_language(): void {
		$stored = $this->save(
			'easyrankly-titles',
			array(
				'_language' => 'it',
				'templates' => array(
					'single' => array(
						'title'       => '{{title}} (IT)',
						'description' => '',
					),
				),
			)
		);

		$this->assertSame( '{{title}} (IT)', $stored['language_templates']['it']['single']['title'] );
		$this->assertSame( array( 'single' ), array_keys( $stored['language_templates']['it'] ) );
		$this->assertSame( '{{title}} (EN)', $stored['language_templates']['en']['single']['title'] );
		$this->assertSame( '{{title}} {{page}} {{sep}} {{site_name}}', $stored['templates']['single']['title'] );

		$stored = $this->save( 'easyrankly-titles', array( '_language' => 'en' ) );
		$this->assertArrayNotHasKey( 'en', $stored['language_templates'] );

		// A language that does not exist changes nothing.
		$before = $stored;
		$this->assertSame( $before, $this->save( 'easyrankly-titles', array( '_language' => 'xx' ) ) );
	}

	/**
	 * Profiles come one per line, the X username may start with @, an empty image is 0.
	 */
	public function test_schema_page_converts_its_fields(): void {
		$stored = $this->save(
			'easyrankly-schema',
			array(
				'identity_type' => 'person',
				'identity_name' => 'Ada',
				'identity_logo' => '',
				'social_image'  => '12',
				'same_as'       => " https://example.org/a \r\n\r\nhttps://example.org/b\nhttps://example.org/a\n",
				'x_username'    => '@ada_l',
			)
		);

		$this->assertSame( 'person', $stored['identity_type'] );
		$this->assertSame( 'Ada', $stored['identity_name'] );
		$this->assertSame( 0, $stored['identity_logo'] );
		$this->assertSame( 12, $stored['social_image'] );
		$this->assertSame( array( 'https://example.org/a', 'https://example.org/b' ), $stored['same_as'] );
		$this->assertSame( 'ada_l', $stored['x_username'] );
		$this->assertSame( array(), $this->errors() );
	}

	/**
	 * An invalid value is not saved and the page says which one.
	 */
	public function test_invalid_value_keeps_current_value_with_error(): void {
		$stored = $this->save(
			'easyrankly-schema',
			array(
				'same_as'    => "https://example.org/ok\njavascript:alert(1)",
				'x_username' => 'not a username!',
			)
		);

		$this->assertSame( array( 'https://example.org/me' ), $stored['same_as'] );
		$this->assertSame( '', $stored['x_username'] );
		$this->assertSame( array( 'easyrankly_x_username', 'easyrankly_same_as' ), $this->sorted_desc( $this->errors() ) );
	}

	/**
	 * Sorts error codes so the assertion does not depend on field order.
	 *
	 * @param string[] $codes Codes.
	 * @return string[]
	 */
	private function sorted_desc( array $codes ): array {
		rsort( $codes );
		return $codes;
	}

	/**
	 * Rows: order decides the default language; removed and blank rows go; a new row is added.
	 */
	public function test_languages_page_adds_removes_and_orders(): void {
		$stored = $this->save(
			'easyrankly-languages',
			array(
				'languages' => array(
					array(
						'order'  => '3',
						'slug'   => 'it',
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					array(
						'order'  => '2',
						'slug'   => 'en',
						'locale' => 'en_US',
						'name'   => 'English',
						'remove' => '1',
					),
					array(
						'order'  => '1',
						'slug'   => 'FR ',
						'locale' => 'fr_FR',
						'name'   => 'Français',
					),
					array(
						'order'  => '4',
						'slug'   => '',
						'locale' => '',
						'name'   => '',
					),
				),
			)
		);

		$this->assertSame( array( 'fr', 'it' ), array_keys( $stored['languages'] ) );
		$this->assertSame(
			array(
				'locale' => 'fr_FR',
				'name'   => 'Français',
			),
			$stored['languages']['fr']
		);
		$this->assertSame( '|', $stored['title_separator'] );
	}

	/**
	 * Site title and tagline are saved trimmed, and only when filled in.
	 */
	public function test_languages_page_saves_site_title_and_tagline(): void {
		$stored = $this->save(
			'easyrankly-languages',
			array(
				'languages' => array(
					array(
						'order'      => '1',
						'slug'       => 'it',
						'locale'     => 'it_IT',
						'name'       => 'Italiano',
						'site_title' => '',
						'tagline'    => ' ',
					),
					array(
						'order'      => '2',
						'slug'       => 'en',
						'locale'     => 'en_US',
						'name'       => 'English',
						'site_title' => ' My <b>site</b> ',
						'tagline'    => 'Just another site',
					),
				),
			)
		);

		$this->assertSame(
			array(
				'locale' => 'it_IT',
				'name'   => 'Italiano',
			),
			$stored['languages']['it']
		);
		$this->assertSame(
			array(
				'locale'     => 'en_US',
				'name'       => 'English',
				'site_title' => 'My site',
				'tagline'    => 'Just another site',
			),
			$stored['languages']['en']
		);
	}

	/**
	 * Navigation menus: only other languages, only real choices; not posted = kept as they are.
	 */
	public function test_languages_page_saves_navigation_menus(): void {
		$rows = array(
			array(
				'order'  => '1',
				'slug'   => 'it',
				'locale' => 'it_IT',
				'name'   => 'Italiano',
			),
			array(
				'order'  => '2',
				'slug'   => 'en',
				'locale' => 'en_US',
				'name'   => 'English',
			),
		);

		$stored = $this->save(
			'easyrankly-languages',
			array(
				'languages'        => $rows,
				'navigation_menus' => array(
					'it' => array( '10' => '11' ),
					'en' => array(
						'10' => '12',
						'11' => '',
						'12' => '12',
					),
					'fr' => array( '10' => '13' ),
				),
			)
		);
		$this->assertSame( array( 'en' => array( 10 => 12 ) ), $stored['navigation_menus'] );

		$stored = $this->save( 'easyrankly-languages', array( 'languages' => $rows ) );
		$this->assertSame( array( 'en' => array( 10 => 12 ) ), $stored['navigation_menus'] );
	}

	/**
	 * A row that cannot be saved leaves every language as it was.
	 *
	 * @dataProvider invalid_language_rows
	 *
	 * @param array<string, string> $row Extra row.
	 */
	public function test_languages_page_rejects_invalid_rows( array $row ): void {
		$stored = $this->save(
			'easyrankly-languages',
			array(
				'languages' => array(
					array(
						'order'  => '1',
						'slug'   => 'it',
						'locale' => 'it_IT',
						'name'   => 'Italiano',
					),
					$row,
				),
			)
		);

		$this->assertSame( array( 'it', 'en' ), array_keys( $stored['languages'] ) );
		$this->assertSame( array( 'easyrankly_languages' ), $this->errors() );
	}

	/**
	 * Rows that cannot be saved.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function invalid_language_rows(): array {
		return array(
			'bad prefix'       => array(
				array(
					'slug'   => 'english',
					'locale' => 'en_US',
					'name'   => 'English',
				),
			),
			'duplicate prefix' => array(
				array(
					'slug'   => 'it',
					'locale' => 'it_CH',
					'name'   => 'Svizzera',
				),
			),
			'bad locale'       => array(
				array(
					'slug'   => 'de',
					'locale' => 'German',
					'name'   => 'Deutsch',
				),
			),
			'no name'          => array(
				array(
					'slug'   => 'de',
					'locale' => 'de_DE',
					'name'   => ' ',
				),
			),
		);
	}

	/**
	 * Values from REST or code have no `_page` and pass through; an unknown page changes nothing.
	 */
	public function test_values_without_known_page_are_untouched(): void {
		update_option( Settings::OPTION, array( 'title_separator' => '~' ) );
		$this->assertSame( '~', Settings::value( 'title_separator' ) );
		$this->assertSame( array( 'author', 'single-gone' ), Settings::value( 'noindex' ) );

		$before = get_option( Settings::OPTION );
		$this->assertSame( $before, $this->save( 'easyrankly-unknown', array( 'title_separator' => '!' ) ) );
	}
}
