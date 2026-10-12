<?php
/**
 * Core sitemaps without noindex content.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Sitemap;

use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the core sitemaps consistent with the robots rules: a page that asks not to be
 * indexed, or whose canonical points elsewhere, is not listed.
 *
 * - Post types, taxonomies and author archives set to noindex in the settings lose their sitemap.
 * - Single posts and terms with their own noindex or canonical are left out of the queries, and
 *   so are password-protected posts, which are always noindex.
 * - Posts and terms whose address an exact "forced" redirect sends elsewhere are left out too:
 *   they would answer with a redirect. Regex forced rules can't be mapped to objects without
 *   scanning them all, so they are not considered.
 * - The front page and the posts page follow their own contexts (`home`, `archive-post`,
 *   `archive`), not the one of single pages: with pages set to noindex they stay listed, and
 *   with their own context set to noindex they leave.
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
		add_filter( 'wp_sitemaps_posts_pre_max_num_pages', array( $this, 'filter_max_num_pages' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'filter_query_args' ) );
	}

	/**
	 * Removes post types whose single pages are noindex.
	 *
	 * @param mixed $post_types Post type objects keyed by name.
	 * @return mixed
	 */
	public function filter_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}

		$kept = $this->without_noindex( $post_types, 'single' );

		// The front page and the posts page are not single pages for the robots rules (Context):
		// they keep the pages sitemap, with only them in it (filter_posts_query_args()).
		if ( isset( $post_types['page'] ) && ! isset( $kept['page'] ) && self::lists_home_pages() ) {
			$kept['page'] = $post_types['page'];
		}

		return $kept;
	}

	/**
	 * One page for the pages sitemap that holds only the home listing the latest posts: core adds
	 * its URL on the first page, but counts no page when the query finds no pages.
	 *
	 * @param mixed $max_num_pages Number of pages from earlier filters, null to let core count.
	 * @param mixed $post_type     Post type of the sitemap.
	 * @return mixed
	 */
	public function filter_max_num_pages( $max_num_pages, $post_type ) {
		if ( null === $max_num_pages && 'page' === $post_type && array() === self::home_pages() && self::lists_home_pages() ) {
			return 1;
		}

		return $max_num_pages;
	}

	/**
	 * The front page and the posts page, with their translations, when single pages are noindex but
	 * their own contexts (`home`, `archive-post`, `archive`) are not.
	 *
	 * @return list<int>|null Null when single pages are indexable: the pages sitemap is the core one.
	 */
	private static function home_pages(): ?array {
		$rules = self::rules();
		if ( array() === array_intersect( array( 'single', 'single-page' ), $rules ) ) {
			return null;
		}
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return array();
		}

		$pages = array();
		if ( ! in_array( 'home', $rules, true ) ) {
			$pages = self::with_translations( (int) get_option( 'page_on_front' ) );
		}
		if ( array() === array_intersect( array( 'archive', 'archive-post' ), $rules ) ) {
			$pages = array_merge( $pages, self::with_translations( (int) get_option( 'page_for_posts' ) ) );
		}

		return $pages;
	}

	/**
	 * Whether the pages sitemap has something to list while single pages are noindex: the front
	 * page or the posts page (home_pages()), or the home that lists the latest posts.
	 *
	 * @return bool
	 */
	private static function lists_home_pages(): bool {
		$pages = self::home_pages();

		return null !== $pages
			&& ( array() !== $pages || ( 'posts' === get_option( 'show_on_front' ) && ! in_array( 'home', self::rules(), true ) ) );
	}

	/**
	 * The front page and the posts page with their translations, when their own context is noindex
	 * in the settings: the posts page follows `archive-post` and `archive` (Context), the front page `home`.
	 *
	 * @return list<int>
	 */
	private static function noindex_home_pages(): array {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return array();
		}

		$rules = self::rules();
		$pages = array();
		if ( in_array( 'home', $rules, true ) ) {
			$pages = self::with_translations( (int) get_option( 'page_on_front' ) );
		}
		if ( array() !== array_intersect( array( 'archive', 'archive-post' ), $rules ) ) {
			$pages = array_merge( $pages, self::with_translations( (int) get_option( 'page_for_posts' ) ) );
		}

		return $pages;
	}

	/**
	 * A page and its translations, which stand for it in the other languages.
	 *
	 * @param int $page_id Page ID, 0 when the option is not set.
	 * @return list<int>
	 */
	private static function with_translations( int $page_id ): array {
		if ( $page_id < 1 ) {
			return array();
		}

		return Languages::enabled() ? array_values( Translations::of( $page_id ) ) : array( $page_id );
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

		return self::exclude( $args, 'exclude', array_merge( is_array( $excluded ) ? $excluded : array(), self::redirected_term_ids( $args['taxonomy'] ) ) );
	}

	/**
	 * Posts query: leaves out the password-protected posts (always noindex), the posts with their
	 * own noindex or a canonical override, the posts redirected by forced rules and the posts or
	 * shop page when it is empty.
	 *
	 * The first four are Robots::is_indexable_post() written as queries: keep the two in step.
	 *
	 * @param mixed $args Query arguments.
	 * @return mixed
	 */
	public function filter_posts_query_args( $args ) {
		if ( ! is_array( $args ) || ! isset( $args['post_type'] ) ) {
			return $args;
		}

		$args['has_password'] = false;

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

		$excluded = array_merge( $excluded, self::redirected_post_ids(), self::empty_listing_pages( $args['post_type'] ) );

		$home_pages = 'page' === $args['post_type'] ? self::home_pages() : null;
		if ( null !== $home_pages ) {
			// Single pages are noindex: only the front page and the posts page stay (filter_post_types()).
			// WP_Query ignores post__not_in when post__in is set, so the exclusions apply here.
			$home_pages       = array_values( array_diff( $home_pages, array_map( 'intval', $excluded ) ) );
			$args['post__in'] = array() === $home_pages ? array( 0 ) : $home_pages;

			return $args;
		}

		return self::exclude( $args, 'post__not_in', array_merge( $excluded, 'page' === $args['post_type'] ? self::noindex_home_pages() : array() ) );
	}

	/**
	 * Pages that stand for an archive with nothing to list: Robots makes them noindex, as any
	 * empty archive. They are the posts page and WooCommerce's shop page.
	 *
	 * WooCommerce keeps the shop page in the `woocommerce_shop_page_id` option, which wc_get_page_id()
	 * reads (woocommerce/includes/wc-page-functions.php). It is read only here and only while
	 * the `product` post type exists, so without WooCommerce nothing changes.
	 *
	 * @param mixed $post_type Post type of the sitemap.
	 * @return list<int>
	 */
	private static function empty_listing_pages( $post_type ): array {
		if ( 'page' !== $post_type ) {
			return array();
		}

		$pages = array();
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$pages['post'] = (int) get_option( 'page_for_posts' );
		}
		if ( post_type_exists( 'product' ) ) {
			$pages['product'] = (int) get_option( 'woocommerce_shop_page_id' );
		}

		$empty = array();
		foreach ( $pages as $listed => $page ) {
			// A shop used as front page is the home: the sitemap lists it apart.
			if ( $page < 1 || (int) get_option( 'page_on_front' ) === $page ) {
				continue;
			}

			$posts = get_posts(
				array(
					'post_type'              => $listed,
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( array() === $posts ) {
				$empty[] = $page;
			}
		}

		return $empty;
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
		$ids = array();
		foreach ( Redirects::forced_sources() as $source ) {
			$id = url_to_postid( home_url( $source ) );
			// url_to_postid() is lenient (it ignores extra segments): keep only exact matches,
			// the same test as Robots::is_indexable_post().
			if ( $id > 0 && Rule::normalize( (string) get_permalink( $id ) ) === $source ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * IDs of the terms whose address an active exact forced redirect sends elsewhere.
	 *
	 * The last segment of each source is a candidate slug: one query finds the terms with those
	 * slugs, and only those whose link is exactly the source count. Sitemap requests only.
	 *
	 * @param mixed $taxonomy Taxonomy of the sitemap.
	 * @return list<int>
	 */
	private static function redirected_term_ids( $taxonomy ): array {
		if ( ! is_string( $taxonomy ) ) {
			return array();
		}

		$sources = array();
		foreach ( Redirects::forced_sources() as $source ) {
			$sources[ $source ] = sanitize_title( basename( $source ) );
		}
		$slugs = array_values( array_filter( array_unique( $sources ) ) );
		if ( array() === $slugs ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomy,
				'slug'                   => $slugs,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			)
		);

		$ids = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_string( $link ) && isset( $sources[ Rule::normalize( $link ) ] ) ) {
				$ids[] = $term->term_id;
			}
		}

		return $ids;
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
