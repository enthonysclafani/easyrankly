<?php
/**
 * Core sitemaps without noindex content.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Sitemap;

use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the core sitemaps consistent with the robots rules: a page that asks not to be
 * indexed, or whose canonical points elsewhere, is not listed.
 *
 * - Post types, taxonomies and author archives set to noindex in the settings lose their sitemap.
 * - Single posts and terms with their own noindex or canonical are left out of the queries.
 * - lastmod: core already adds it to posts (post_modified_gmt). Terms and users have no
 *   modification date of their own; computing one would cost a query per entry.
 */
final class Sitemap {

	/**
	 * Hooks the core sitemap filters.
	 */
	public function register(): void {
		add_filter( 'wp_sitemaps_post_types', array( $this, 'filter_post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'filter_taxonomies' ) );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'filter_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_query_args' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_query_args' ) );
	}

	/**
	 * Removes post types whose single pages are noindex.
	 *
	 * @param mixed $post_types Post type objects keyed by name.
	 * @return mixed
	 */
	public function filter_post_types( $post_types ) {
		return is_array( $post_types ) ? $this->without_noindex( $post_types, 'single' ) : $post_types;
	}

	/**
	 * Removes taxonomies whose archives are noindex.
	 *
	 * @param mixed $taxonomies Taxonomy objects keyed by name.
	 * @return mixed
	 */
	public function filter_taxonomies( $taxonomies ) {
		return is_array( $taxonomies ) ? $this->without_noindex( $taxonomies, 'term' ) : $taxonomies;
	}

	/**
	 * Drops the users sitemap when author archives are noindex.
	 *
	 * @param mixed $provider Sitemap provider.
	 * @param mixed $name     Provider name.
	 * @return mixed
	 */
	public function filter_provider( $provider, $name ) {
		return 'users' === $name && in_array( 'author', self::rules(), true ) ? false : $provider;
	}

	/**
	 * Leaves out posts or terms with their own noindex or with a canonical override.
	 *
	 * The same meta query is valid for WP_Query and WP_Term_Query.
	 *
	 * @param mixed $args Query arguments.
	 * @return mixed
	 */
	public function filter_query_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$conditions = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'     => Meta::PREFIX . 'noindex',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => Meta::PREFIX . 'noindex',
					'value'   => '1',
					'compare' => '!=',
				),
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => Meta::PREFIX . 'canonical',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Meta::PREFIX . 'canonical',
					'value' => '',
				),
			),
		);

		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap requests only, cached by page caches; there is no other way to skip noindex objects.
			? array(
				'relation' => 'AND',
				$args['meta_query'],
				$conditions,
			)
			: $conditions;

		return $args;
	}

	/**
	 * Objects whose context (generic or specific) is noindex in the settings are removed.
	 *
	 * @param array<mixed> $objects Objects keyed by name.
	 * @param string       $prefix  "single" or "term".
	 * @return array<mixed>
	 */
	private function without_noindex( array $objects, string $prefix ): array {
		$rules = self::rules();

		if ( in_array( $prefix, $rules, true ) ) {
			return array();
		}

		return array_filter(
			$objects,
			static fn( $name ): bool => ! in_array( $prefix . '-' . $name, $rules, true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Noindex rules from the settings.
	 *
	 * @return list<string>
	 */
	private static function rules(): array {
		$rules = Settings::value( 'noindex' );

		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_string' ) ) : array();
	}
}
