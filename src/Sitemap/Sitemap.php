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
	 * Posts query: the common conditions plus the posts redirected by forced rules.
	 *
	 * @param mixed $args Query arguments.
	 * @return mixed
	 */
	public function filter_posts_query_args( $args ) {
		$args = $this->filter_query_args( $args );
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$redirected = self::redirected_post_ids();
		if ( array() !== $redirected ) {
			$excluded             = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] ) ? $args['post__not_in'] : array();
			$args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', $excluded ), $redirected ) ) );
		}

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
