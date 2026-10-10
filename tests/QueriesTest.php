<?php
/**
 * Tests for the queries the plugin adds to a page.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests;

use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use EasyRankly\Tests\Multilingual\WithLanguages;
use WP_UnitTestCase;

/**
 * A page costs the same queries with and without the plugin (CLAUDE.md, "Prestazioni").
 *
 * Each scenario serves the same page twice from an empty object cache: once as it is, once
 * with every callback of the plugin taken off its hook.
 */
final class QueriesTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Pretty permalinks, a public site, meta registered; no emoji script (not built in the test suite).
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		// As on activation: the settings are stored, so they come with the autoloaded options.
		Settings::create();
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'blog_public', '1' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		// Core styles are not built in the test suite either; the plugin prints none.
		remove_action( 'wp_head', 'wp_maybe_inline_styles', 1 );
		remove_action( 'wp_head', 'wp_print_styles', 8 );
	}

	/**
	 * Back to a frontend request at the root.
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	/**
	 * Queries to serve a URL: the request, the block template if the theme has one, and the head.
	 *
	 * The test case empties the object cache for every request, as on a site without a persistent
	 * one. With `$warm`, the page is rendered once before counting a second rendering, as on a
	 * site with a persistent object cache: only the request itself is not counted then.
	 *
	 * @param string $url  URL.
	 * @param bool   $warm Whether the object cache already holds what the page reads.
	 * @return int
	 */
	private function queries( string $url, bool $warm ): int {
		global $wpdb;

		$before = $wpdb->num_queries;
		$this->go_to( $url );
		if ( $warm ) {
			$this->render();
			$before = $wpdb->num_queries;
		}
		$this->render();

		return $wpdb->num_queries - $before;
	}

	/**
	 * Renders the page as the templates do: the block template first, if the theme has one, then the head.
	 */
	private function render(): void {
		if ( wp_is_block_theme() ) {
			$template = resolve_block_template( 'single', array( 'single' ), '' );
			$this->assertInstanceOf( \WP_Block_Template::class, $template );
			$GLOBALS['_wp_current_template_content'] = $template->content;
			get_the_block_template_html();
		}
		get_echo( 'wp_head' );
	}

	/**
	 * Takes every callback of the plugin off its hook (the test case restores the hooks).
	 */
	private function unhook_plugin(): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook => $callbacks ) {
			foreach ( $callbacks->callbacks as $priority => $by_id ) {
				foreach ( $by_id as $callback ) {
					$function = $callback['function'];
					$owner    = is_array( $function ) ? $function[0] : $function;
					$class    = is_object( $owner ) && ! $owner instanceof \Closure ? get_class( $owner ) : ( is_string( $owner ) ? $owner : '' );
					if ( $owner instanceof \Closure ) {
						$scope = ( new \ReflectionFunction( $owner ) )->getClosureScopeClass();
						$class = null === $scope ? '' : $scope->getName();
					}
					if ( str_starts_with( $class, 'EasyRankly\\' ) ) {
						remove_filter( $hook, $function, $priority );
					}
				}
			}
		}
	}

	/**
	 * Queries of a URL with the plugin, then without.
	 *
	 * Each is counted after serving the URL twice in the same way, so both start from the same
	 * static state of WordPress (the first request of a test run fills more of it).
	 *
	 * @param string $url  URL.
	 * @param bool   $warm Whether the object cache already holds what the page reads.
	 * @return array{int, int}
	 */
	private function with_and_without( string $url, bool $warm = false ): array {
		$counts = array();
		foreach ( array( 'with', 'without' ) as $run ) {
			if ( 'without' === $run ) {
				$this->unhook_plugin();
			}
			$this->queries( $url, $warm );
			$this->queries( $url, $warm );
			$counts[] = $this->queries( $url, $warm );
		}

		return $counts;
	}

	/**
	 * A post with the settings untouched.
	 */
	public function test_post_with_default_settings(): void {
		$post = self::factory()->post->create( array( 'post_excerpt' => 'Summary' ) );

		[ $with, $without ] = $this->with_and_without( get_permalink( $post ) );

		$this->assertSame( $without, $with );
	}

	/**
	 * A post on a site with a logo and a default social image.
	 */
	public function test_post_with_logo_and_social_image(): void {
		$images = array();
		foreach ( array( 'logo.png', 'share.png' ) as $file ) {
			$id = self::factory()->attachment->create_object( $file, 0, array( 'post_mime_type' => 'image/png' ) );
			wp_update_attachment_metadata(
				$id,
				array(
					'width'  => 600,
					'height' => 300,
					'file'   => $file,
				)
			);
			$images[] = $id;
		}
		update_option(
			Settings::OPTION,
			array(
				'identity_logo' => $images[0],
				'social_image'  => $images[1],
			)
		);
		$post = self::factory()->post->create();

		[ $with, $without ] = $this->with_and_without( get_permalink( $post ) );

		$this->assertSame( $without, $with );
	}

	/**
	 * A translated post in a block theme, with a persistent object cache.
	 *
	 * Without one, the page also looks for its translations (for hreflang and the language
	 * switcher) and for the template parts of its language: queries kept in the object cache and
	 * expired when posts, terms or settings change, as CLAUDE.md allows.
	 */
	public function test_translated_post_in_block_theme(): void {
		switch_theme( 'twentytwentyfive' );
		$this->set_languages( 'it', 'en' );
		flush_rewrite_rules( false );
		$it = self::factory()->post->create( array( 'post_name' => 'ciao' ) );
		$en = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);

		[ $with, $without ] = $this->with_and_without( 'http://example.org/en/hello/', true );

		$this->assertSame( $without, $with );
	}
}
