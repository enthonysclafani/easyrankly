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
	 * Hooks the template part blocks.
	 */
	public function register(): void {
		add_filter( 'render_block_data', array( $this, 'filter_template_part_block' ) );
	}

	/**
	 * On the pages of a language, points a template part block to the version of that language.
	 *
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public function filter_template_part_block( $block ) {
		if ( ! is_array( $block ) || 'core/template-part' !== ( $block['blockName'] ?? null ) || ! is_string( $block['attrs']['slug'] ?? null ) ) {
			return $block;
		}
		if ( ! Languages::enabled() || ! Routing::is_frontend() ) {
			return $block;
		}

		$current = Routing::current();
		$theme   = $block['attrs']['theme'] ?? get_stylesheet();
		// The core renders only template parts of the active theme.
		if ( Languages::default() === $current || get_stylesheet() !== $theme ) {
			return $block;
		}

		$slug = $block['attrs']['slug'] . '-' . $current;
		if ( self::exists( $theme, $slug ) ) {
			$block['attrs']['slug'] = $slug;
		}

		return $block;
	}

	/**
	 * Whether a template part exists, saved in the Site Editor or provided by the theme.
	 *
	 * The query has the arguments of render_block_core_template_part(), so when the part exists the
	 * core reads the result from the WP_Query cache instead of querying again.
	 *
	 * @param string $theme Theme stylesheet.
	 * @param string $slug  Template part slug.
	 * @return bool
	 */
	public static function exists( string $theme, string $slug ): bool {
		$query = new \WP_Query(
			array(
				'post_type'           => 'wp_template_part',
				'post_status'         => 'publish',
				'post_name__in'       => array( $slug ),
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Same query as the core render, so it shares its cache.
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme,
					),
				),
				'posts_per_page'      => 1,
				'no_found_rows'       => true,
				'lazy_load_term_meta' => false,
			)
		);

		if ( $query->have_posts() ) {
			return true;
		}

		return 0 === validate_file( $slug ) && null !== get_block_file_template( $theme . '//' . $slug, 'wp_template_part' );
	}
}
