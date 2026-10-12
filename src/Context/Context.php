<?php
/**
 * The kind of page being served.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Names the context of the current request with the keys used by settings
 * (templates, noindex rules): most specific first, e.g. `single-page`, then `single`.
 */
final class Context {

	/**
	 * Generic context keys, in the order the settings screen lists them.
	 */
	public const GENERIC = array( 'home', 'single', 'archive', 'term', 'author', 'date', 'search', '404' );

	/**
	 * Regex for a valid context key: a generic key or a key for one post type or taxonomy.
	 */
	public const KEY_PATTERN = '^(home|single|archive|term|author|date|search|404|(single|archive)-[a-z0-9_-]{1,20}|term-[a-z0-9_-]{1,32})$';

	/**
	 * Context keys of the current request, most specific first.
	 *
	 * @return list<string> Empty on requests that are not a frontend page (feeds, admin, REST).
	 */
	public static function keys(): array {
		$object = get_queried_object();

		if ( is_404() ) {
			return array( '404' );
		}
		if ( is_search() ) {
			return array( 'search' );
		}
		if ( is_front_page() ) {
			return array( 'home' );
		}
		if ( is_home() && $object instanceof \WP_Post ) {
			// Static posts page: a page, but it lists posts like an archive.
			return array( 'archive-post', 'archive' );
		}
		if ( is_home() ) {
			return array( 'home' );
		}
		if ( is_singular() && $object instanceof \WP_Post ) {
			return array( 'single-' . $object->post_type, 'single' );
		}
		if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof \WP_Term ) {
			return array( 'term-' . $object->taxonomy, 'term' );
		}
		if ( is_post_type_archive() ) {
			return array( 'archive-' . self::archive_post_type(), 'archive' );
		}
		if ( is_author() ) {
			return array( 'author' );
		}
		if ( is_date() ) {
			return array( 'date' );
		}

		return array();
	}

	/**
	 * Post type of the post type archive being viewed: the query var may hold several, the first counts.
	 *
	 * @return string
	 */
	public static function archive_post_type(): string {
		$post_type = get_query_var( 'post_type' );

		return is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
	}

	/**
	 * Whether the current page is an article: a single post of type `post`, not the front page.
	 * Pages, products and other types are web pages. Social (og:type) and Schema (Article) both follow it.
	 *
	 * @return bool
	 */
	public static function is_article(): bool {
		$object = get_queried_object();

		return is_singular() && ! is_front_page() && $object instanceof \WP_Post && 'post' === $object->post_type;
	}

	/**
	 * Path and query of the current request, as the browser asked for them.
	 *
	 * @return string
	 */
	public static function request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	}
}
