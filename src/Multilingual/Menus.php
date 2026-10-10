<?php
/**
 * Menus of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the menus of the language being viewed.
 *
 * - Classic themes: every menu location of the theme gets one location per other language
 *   ("Primary (English)"), assigned in Appearance > Menus like any location and stored by the core
 *   in the `nav_menu_locations` theme mod. On the pages of a language the location shows the
 *   menu of its language location; with none assigned it keeps the menu of the default language.
 * - Block themes: the `navigation_menus` setting maps a navigation menu (`wp_navigation`) to
 *   the one shown in its place on the pages of a language; the `core/navigation` block
 *   renders that one instead.
 *
 * Admin, REST and the other non-frontend requests see the menus as stored.
 */
final class Menus {

	/**
	 * Between a theme location and a language slug in the name of the language location.
	 */
	public const SEPARATOR = '__erankly_';

	/**
	 * Setting with the navigation menus of each language.
	 */
	public const SETTING = 'navigation_menus';

	/**
	 * Hooks the language locations and the menus shown on the frontend.
	 */
	public function register(): void {
		// After the theme registers its locations (after_setup_theme or init), but before
		// check_theme_switched (init, 99): on a theme switch the core keeps only registered locations.
		add_action( 'init', array( $this, 'register_locations' ), 98 );
		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'filter_locations' ) );
	}

	/**
	 * Name of the location of a theme location in one language.
	 *
	 * @param string $location Theme location.
	 * @param string $language Language slug.
	 * @return string
	 */
	public static function location( string $location, string $language ): string {
		return $location . self::SEPARATOR . $language;
	}

	/**
	 * Registers one location per theme location and per language other than the default.
	 */
	public function register_locations(): void {
		if ( ! Languages::enabled() ) {
			return;
		}

		$default   = Languages::default();
		$locations = array();

		foreach ( get_registered_nav_menus() as $location => $description ) {
			if ( str_contains( (string) $location, self::SEPARATOR ) ) {
				continue;
			}
			foreach ( Languages::all() as $slug => $language ) {
				if ( $default !== $slug ) {
					/* translators: 1: menu location of the theme, 2: language name. */
					$locations[ self::location( (string) $location, $slug ) ] = sprintf( __( '%1$s (%2$s)', 'easyrankly' ), $description, $language['name'] );
				}
			}
		}

		if ( array() !== $locations ) {
			register_nav_menus( $locations );
		}
	}

	/**
	 * On the pages of a language, each theme location shows the menu of its language location.
	 *
	 * @param mixed $locations Theme location => menu ID, as stored by the core.
	 * @return mixed
	 */
	public function filter_locations( $locations ) {
		if ( ! is_array( $locations ) || ! Languages::enabled() || ! Routing::is_frontend() ) {
			return $locations;
		}

		$current = Routing::current();
		if ( Languages::default() === $current ) {
			return $locations;
		}

		$suffix = self::SEPARATOR . $current;
		foreach ( $locations as $location => $menu ) {
			if ( is_string( $location ) && str_ends_with( $location, $suffix ) && (int) $menu > 0 ) {
				$locations[ substr( $location, 0, -strlen( $suffix ) ) ] = (int) $menu;
			}
		}

		return $locations;
	}

	/**
	 * Points a navigation block to the navigation menu of a language, when one is chosen.
	 *
	 * Called by Multilingual::filter_block() on the frontend pages of a language.
	 *
	 * @param array<string, mixed> $block    Parsed `core/navigation` block.
	 * @param string               $language Language being viewed.
	 * @return array<string, mixed>
	 */
	public static function filter_block( array $block, string $language ): array {
		$ref    = $block['attrs']['ref'] ?? null;
		$target = is_numeric( $ref ) ? self::navigation( (int) $ref, $language ) : 0;
		if ( $target > 0 ) {
			$block['attrs']['ref'] = $target;
		}

		return $block;
	}

	/**
	 * Navigation menu shown in place of another in one language, or 0 to keep it.
	 *
	 * The core loads the navigation menu anyway, so checking it costs no query.
	 *
	 * @param int    $navigation Navigation menu ID in the block.
	 * @param string $language   Language slug.
	 * @return int
	 */
	public static function navigation( int $navigation, string $language ): int {
		$menus  = Settings::value( self::SETTING );
		$target = is_array( $menus ) ? (int) ( $menus[ $language ][ $navigation ] ?? 0 ) : 0;

		return $target > 0 && 'wp_navigation' === get_post_type( $target ) ? $target : 0;
	}
}
