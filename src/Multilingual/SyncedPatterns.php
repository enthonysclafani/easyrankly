<?php
/**
 * Synced patterns of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * A synced pattern block shows the version of the language being viewed.
 *
 * The version of a language is the synced pattern with the language slug after a hyphen in its slug:
 * "Name - IT" (`name-it`) for "Name" (`name`) on the Italian pages. It is created in the Site Editor;
 * without it, or when it is not published or has a password, the block shows its own pattern.
 * No data of ours: synced patterns are core posts (`wp_block`).
 *
 * Unsynced patterns are not covered: once inserted they are a copy of their blocks, edited in place.
 */
final class SyncedPatterns {

	/**
	 * Points a synced pattern block to the version of a language, when there is one.
	 *
	 * Called by Multilingual::filter_block() on the frontend pages of a language.
	 *
	 * @param array<string, mixed> $block    Parsed `core/block` block.
	 * @param string               $language Language being viewed.
	 * @return array<string, mixed>
	 */
	public static function filter_block( array $block, string $language ): array {
		$ref = $block['attrs']['ref'] ?? null;
		if ( ! is_numeric( $ref ) || Languages::default() === $language ) {
			return $block;
		}

		// The core reads the same post to render the block: it is in the cache.
		$pattern = get_post( (int) $ref );
		if ( ! $pattern instanceof \WP_Post || 'wp_block' !== $pattern->post_type ) {
			return $block;
		}

		$version = self::published()[ $pattern->post_name . '-' . $language ] ?? 0;
		if ( $version > 0 ) {
			$block['attrs']['ref'] = $version;
		}

		return $block;
	}

	/**
	 * IDs of the published synced patterns the core would render, keyed by slug.
	 *
	 * One query for the page, whatever the number of pattern blocks: WP_Query keeps the result
	 * in the object cache until a post changes, and it loads the patterns the core then renders.
	 * The core renders nothing for a pattern with a password: it is left out.
	 *
	 * @return array<string, int>
	 */
	private static function published(): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'wp_block',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);

		$patterns = array();
		foreach ( $query->posts as $pattern ) {
			if ( $pattern instanceof \WP_Post && '' === $pattern->post_password ) {
				$patterns[ $pattern->post_name ] = $pattern->ID;
			}
		}

		return $patterns;
	}
}
