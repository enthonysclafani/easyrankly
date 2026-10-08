<?php
/**
 * Tests for the settings screens.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Settings\Admin\Page;
use EasyRankly\Settings\Settings;
use WP_UnitTestCase;

/**
 * One short Settings API page per topic, only for administrators, with the markup of the
 * WordPress settings screens and no script except on the page with image fields.
 */
final class PageTest extends WP_UnitTestCase {

	/**
	 * Restores the admin menu, the registered sections and the enqueued assets.
	 */
	public function tear_down(): void {
		global $menu, $submenu, $_registered_pages, $wp_settings_sections, $wp_settings_fields;
		$menu                 = array();
		$submenu              = array();
		$_registered_pages    = array();
		$wp_settings_sections = array();
		$wp_settings_fields   = array();
		unset( $_GET['language'] );
		wp_dequeue_script( 'easyrankly-media-field' );
		wp_dequeue_style( 'wp-components' );

		parent::tear_down();
	}

	/**
	 * Logs in a user with the role.
	 *
	 * @param string $role Role.
	 */
	private function login( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * HTML of one page, as an administrator would see it.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private function page( string $slug ): string {
		$page = new Page();
		$page->load( $slug );

		return get_echo( array( $page, 'render' ), array( $slug ) );
	}

	/**
	 * The menu has one submenu per settings page, all requiring manage_options.
	 */
	public function test_menu_has_one_page_per_topic(): void {
		global $menu, $submenu;

		$this->login( 'administrator' );
		( new Page() )->add_menu();

		$items = array_values( array_filter( $menu, static fn( array $item ): bool => Page::SLUG === $item[2] ) );
		$this->assertCount( 1, $items );
		$this->assertSame( 'manage_options', $items[0][1] );
		$this->assertSame( array_keys( Page::pages() ), array_column( $submenu[ Page::SLUG ], 2 ) );
		$this->assertSame( array( 'manage_options' ), array_values( array_unique( array_column( $submenu[ Page::SLUG ], 1 ) ) ) );
	}

	/**
	 * Every page is a form posted to options.php with the group nonce and its own name.
	 */
	public function test_pages_are_settings_api_forms(): void {
		$this->login( 'administrator' );

		foreach ( array_keys( Page::pages() ) as $slug ) {
			$html = $this->page( $slug );

			$this->assertStringContainsString( '<form action="options.php" method="post">', $html, $slug );
			$this->assertStringContainsString( "name='option_page' value='easyrankly'", $html, $slug );
			$this->assertStringContainsString( 'name="_wpnonce"', $html, $slug );
			$this->assertStringContainsString( 'name="easyrankly_settings[_page]" value="' . $slug . '"', $html, $slug );
			$this->assertStringContainsString( 'id="submit"', $html, $slug );
			$this->assertStringNotContainsString( 'easyrankly-settings"', $html, $slug );
		}
	}

	/**
	 * The core options.php accepts the group, for the capability the menu asks.
	 */
	public function test_options_php_accepts_the_settings_group(): void {
		$allowed = apply_filters( 'allowed_options', array() );

		$this->assertSame( array( Settings::OPTION ), $allowed['easyrankly'] );
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_easyrankly', 'manage_options' ) );
	}

	/**
	 * Without manage_options a page prints nothing.
	 */
	public function test_pages_print_nothing_for_editors(): void {
		$this->login( 'editor' );

		foreach ( array_keys( Page::pages() ) as $slug ) {
			$this->assertSame( '', $this->page( $slug ), $slug );
		}
	}

	/**
	 * Fields show the current values, in the classic markup.
	 */
	public function test_fields_show_current_values(): void {
		$this->login( 'administrator' );
		update_option(
			Settings::OPTION,
			array(
				'title_separator' => '»',
				'noindex'         => array( 'author' ),
				'same_as'         => array( 'https://example.org/a', 'https://example.org/b' ),
				'robots_txt'      => 'Disallow: /x/',
			)
		);

		$general = $this->page( Page::SLUG );
		$this->assertStringContainsString( 'class="form-table"', $general );
		$this->assertStringContainsString( 'name="easyrankly_settings[title_separator]" value="»"', $general );
		$this->assertStringContainsString( 'name="easyrankly_settings[breadcrumb_taxonomies][post]"', $general );

		$indexing = $this->page( 'easyrankly-indexing' );
		$this->assertMatchesRegularExpression( '/name="easyrankly_settings\[noindex\]\[author\]" value="1"\s+checked=\'checked\'/', $indexing );
		$this->assertDoesNotMatchRegularExpression( '/name="easyrankly_settings\[noindex\]\[date\]" value="1"\s+checked/', $indexing );
		$this->assertStringContainsString( 'Disallow: /x/</textarea>', $indexing );

		$schema = $this->page( 'easyrankly-schema' );
		$this->assertStringContainsString( "https://example.org/a\nhttps://example.org/b</textarea>", $schema );
		$this->assertStringContainsString( 'class="easyrankly-media-field"', $schema );

		$titles = $this->page( 'easyrankly-titles' );
		$this->assertStringContainsString( 'name="easyrankly_settings[templates][home][title]" value="{{site_name}} {{sep}} {{tagline}}"', $titles );
		$this->assertStringContainsString( 'name="easyrankly_settings[templates][single-page][title]"', $titles );
		$this->assertStringNotContainsString( 'subsubsub', $titles );

		$languages = $this->page( 'easyrankly-languages' );
		$this->assertStringContainsString( 'name="easyrankly_settings[languages][0][slug]"', $languages );
	}

	/**
	 * With languages, the Titles page links each language and edits one at a time.
	 */
	public function test_titles_page_edits_one_language_at_a_time(): void {
		$this->login( 'administrator' );
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
				),
			)
		);

		$all = $this->page( 'easyrankly-titles' );
		$this->assertStringContainsString( '<ul class="subsubsub">', $all );
		$this->assertStringContainsString( 'language=en', $all );
		$this->assertStringNotContainsString( '[_language]', $all );

		$GLOBALS['wp_settings_sections'] = array();
		$GLOBALS['wp_settings_fields']   = array();
		$_GET['language']                = 'en';
		$english                         = $this->page( 'easyrankly-titles' );

		$this->assertStringContainsString( 'name="easyrankly_settings[_language]" value="en"', $english );
		// The templates of all languages show as placeholders of the empty fields.
		$this->assertStringContainsString( 'name="easyrankly_settings[templates][home][title]" value="" placeholder="{{site_name}} {{sep}} {{tagline}}"', $english );
	}

	/**
	 * Only the page with image fields loads a script, and no page loads the components styles.
	 */
	public function test_only_the_schema_page_loads_a_script(): void {
		$this->login( 'administrator' );

		foreach ( array_keys( Page::pages() ) as $slug ) {
			$page = new Page();
			$page->load( $slug );
			$hooked = has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_media' ) );

			$this->assertSame( 'easyrankly-schema' === $slug ? 10 : false, $hooked, $slug );
		}

		( new Page() )->enqueue_media();

		$built = is_readable( dirname( __DIR__, 2 ) . '/build/media-field.asset.php' );
		$this->assertSame( $built, wp_script_is( 'easyrankly-media-field', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wp-components', 'enqueued' ) );
	}
}
