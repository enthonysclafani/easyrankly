<?php
/**
 * Core sitemaps without noindex content.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Sitemap;

use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the core sitemaps consistent with the robots rules: a page that asks not to be
 * indexed, or whose canonical points elsewhere, is not listed.
 *
 * - Post types, taxonomies and author archives set to noindex in the settings lose their sitemap.
 * - Single posts and terms with their own noindex or canonical are left out of the queries.
 * - Posts whose address an exact "forced" redirect sends elsewhere are left out too: they
 *   would answer with a redirect. Regex forced rules can't be mapped to posts without
 *   scanning them all, so they are not considered.
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
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filter_posts_query_args' ) );
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
	 * Terms query: leaves out the terms with their own noindex or a canonical override.
	 *
	 * @param mixed $args Query arguments.
	 * @return mixed
	 */
	public function filter_query_args( $args ) {
		if ( ! is_array( $args ) || ! isset( $args['taxonomy'] ) ) {
			return $args;
		}

		$excluded = get_terms(
			array(
				'taxonomy'               => $args['taxonomy'],
				'hide_empty'             => false,
				'fields'                 => 'ids',
				'update_term_meta_cache' => false,
				'meta_query'             => self::excluded_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap requests only, one join on the meta_key index.
			)
		);

		return self::exclude( $args, 'exclude', is_array( $excluded ) ? $excluded : array() );
	}

	/**
	 * Posts query: leaves out the posts with their own noindex or a canonical override, and
	 * the posts redirected by forced rules.
	 *
	 * @param mixed $args Query arguments.
	 * @return mixed
	 */
	public function filter_posts_query_args( $args ) {
		if ( ! is_array( $args ) || ! isset( $args['post_type'] ) ) {
			return $args;
		}

		$excluded = get_posts(
			array(
				'post_type'              => $args['post_type'],
				'post_status'            => $args['post_status'] ?? 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'none',
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => self::excluded_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap requests only, one join on the meta_key index.
			)
		);

		return self::exclude( $args, 'post__not_in', array_merge( $excluded, self::redirected_post_ids() ) );
	}

	/**
	 * Meta query of the objects with their own noindex or a canonical override.
	 *
	 * It runs as a separate query whose IDs are then excluded: excluding them inside the
	 * sitemap query needs NOT EXISTS and != clauses, which join every meta row of every
	 * object and took seconds per sitemap page on a site with 50,000 posts.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function excluded_meta_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'   => Meta::PREFIX . 'noindex',
				'value' => '1',
			),
			// Any non-empty canonical. Unlike !=, > shares the join with the clause above.
			array(
				'key'     => Meta::PREFIX . 'canonical',
				'value'   => '',
				'compare' => '>',
			),
		);
	}

	/**
	 * Adds IDs to the exclusion argument of a query, keeping those already there.
	 *
	 * @param array<mixed> $args Query arguments.
	 * @param string       $key  post__not_in or exclude.
	 * @param array<mixed> $ids  IDs to leave out.
	 * @return array<mixed>
	 */
	private static function exclude( array $args, string $key, array $ids ): array {
		if ( array() === $ids ) {
			return $args;
		}

		$existing     = isset( $args[ $key ] ) ? wp_parse_id_list( $args[ $key ] ) : array();
		$args[ $key ] = array_values( array_unique( array_merge( $existing, array_map( 'intval', $ids ) ) ) );

		return $args;
	}

	/**
	 * IDs of the posts whose address an active exact forced redirect sends elsewhere.
	 *
	 * Resolved on each sitemap request (at most Redirects::MAX_FORCED lookups), so a post
	 * whose slug changed after the rule was saved is not left out by mistake. Never runs
	 * on normal frontend requests.
	 *
	 * @return list<int>
	 */
	private static function redirected_post_ids(): array {
		$rules = get_option( Redirects::FORCED_OPTION, array() );
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! empty( $rule['regex'] ) || ! isset( $rule['source'] ) || ! is_string( $rule['source'] ) ) {
				continue;
			}

			$id = url_to_postid( home_url( $rule['source'] ) );
			// url_to_postid() is lenient (it ignores extra segments): keep only exact matches.
			if ( $id > 0 && Rule::normalize( (string) get_permalink( $id ) ) === $rule['source'] ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
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
