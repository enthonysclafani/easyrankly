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
	 * Hooks the synced pattern blocks.
	 */
	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'filter_synced_pattern_block' ) );
	}

	/**
	 * On the pages of a language, points a synced pattern block to the version of that language.
	 *
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public function filter_synced_pattern_block( $block ) {
		if ( ! is_array( $block ) || 'core/block' !== ( $block['blockName'] ?? null ) || ! is_numeric( $block['attrs']['ref'] ?? null ) ) {
			return $block;
		}
		if ( ! Languages::enabled() || ! Routing::is_frontend() ) {
			return $block;
		}

		$current = Routing::current();
		if ( Languages::default() === $current ) {
			return $block;
		}

		// The core reads the same post to render the block: it is in the cache.
		$pattern = get_post( (int) $block['attrs']['ref'] );
		if ( ! $pattern || 'wp_block' !== $pattern->post_type ) {
			return $block;
		}

		$version = self::find( $pattern->post_name . '-' . $current );
		if ( null !== $version ) {
			$block['attrs']['ref'] = $version;
		}

		return $block;
	}

	/**
	 * ID of the published synced pattern with a slug, if the core would render it.
	 *
	 * WP_Query keeps the result in the object cache until a post changes.
	 *
	 * @param string $slug Synced pattern slug.
	 * @return int|null
	 */
	public static function find( string $slug ): ?int {
		$query   = new \WP_Query(
			array(
				'post_type'              => 'wp_block',
				'post_status'            => 'publish',
				'name'                   => $slug,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);
		$version = $query->posts[0] ?? null;

		// The core renders nothing for a pattern with a password: keep the pattern of the block.
		if ( ! $version instanceof \WP_Post || '' !== $version->post_password ) {
			return null;
		}

		return $version->ID;
	}
}
