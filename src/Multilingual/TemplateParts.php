<?php
/**
 * Template parts of each language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * In block themes, a template part block shows the version of the language being viewed.
 *
 * The version of a language is the template part with the language slug after a hyphen:
 * `header-en` for `header` on the English pages. It is created in the Site Editor (or shipped by
 * the theme in `parts/`); without it the block shows its own template part. No data of ours:
 * template parts are core posts and theme files.
 */
final class TemplateParts {

	/**
	 * Points a template part block to the version of a language, when the theme has one.
	 *
	 * Called by Multilingual::filter_block() on the frontend pages of a language.
	 *
	 * @param array<string, mixed> $block    Parsed `core/template-part` block.
	 * @param string               $language Language being viewed.
	 * @return array<string, mixed>
	 */
	public static function filter_block( array $block, string $language ): array {
		$slug  = $block['attrs']['slug'] ?? null;
		$theme = $block['attrs']['theme'] ?? get_stylesheet();
		// The core renders only template parts of the active theme.
		if ( ! is_string( $slug ) || Languages::default() === $language || get_stylesheet() !== $theme ) {
			return $block;
		}

		if ( self::exists( $theme, $slug . '-' . $language ) ) {
			$block['attrs']['slug'] = $slug . '-' . $language;
		}

		return $block;
	}

	/**
	 * Whether a template part exists, saved in the Site Editor or provided by the theme.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $slug  Template part slug.
	 * @return bool
	 */
	public static function exists( string $theme, string $slug ): bool {
		if ( in_array( $slug, self::saved( $theme ), true ) ) {
			return true;
		}

		return 0 === validate_file( $slug ) && null !== get_block_file_template( $theme . '//' . $slug, 'wp_template_part' );
	}

	/**
	 * Slugs of the template parts of a theme saved in the Site Editor.
	 *
	 * One query for the page, whatever the number of template part blocks: WP_Query keeps the
	 * result in the object cache until a post or a term changes, so the next blocks read it there.
	 *
	 * @param string $theme Theme stylesheet.
	 * @return list<string>
	 */
	private static function saved( string $theme ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'wp_template_part',
				'post_status'            => 'publish',
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Template parts belong to a theme only through this taxonomy.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
				'posts_per_page'         => 100,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'lazy_load_term_meta'    => false,
			)
		);

		return array_values( wp_list_pluck( (array) $query->posts, 'post_name' ) );
	}
}
