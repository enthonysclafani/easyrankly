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
 * Shows the navigation menus of the language being viewed.
 *
 * The `navigation_menus` setting maps a navigation menu (`wp_navigation`) to the one shown in its
 * place on the pages of a language; the `core/navigation` block renders that one instead.
 * Classic themes and their menu locations are not supported (decision of 10 October 2026).
 */
final class Menus {

	/**
	 * Setting with the navigation menus of each language.
	 */
	public const SETTING = 'navigation_menus';

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
