<?php
/**
 * Canonical URLs.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Canonical;

use EasyRankly\Meta\Meta;
use EasyRankly\Robots\Robots;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical URL of every indexable page.
 *
 * Single content keeps the core tag (rel_canonical) and only filters its URL. Core prints
 * nothing on the home page and archives, so for those this class prints the tag itself,
 * built from the archive's own link plus the page number, never from the request URL
 * (tracking parameters would end up in the canonical).
 */
final class Canonical {

	/**
	 * Hooks the core filter and the archive tag.
	 */
	public function register(): void {
		add_filter( 'get_canonical_url', array( $this, 'filter_singular' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'print_archive_canonical' ), 1 );

		foreach ( array( 'added', 'updated' ) as $event ) {
			add_action( "{$event}_post_meta", array( $this, 'drop_self_post_override' ), 10, 4 );
			add_action( "{$event}_term_meta", array( $this, 'drop_self_term_override' ), 10, 4 );
		}
	}

	/**
	 * Empties a post override equal to the post's own URL.
	 *
	 * An override means "this page is a copy of another one", so the sitemap and hreflang leave the page out.
	 * Users often paste the page's own URL (a "self-referencing canonical"): it changes nothing in the tag,
	 * but without this it would silently drop the page from the sitemap and its translation group.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     Saved value.
	 */
	public function drop_self_post_override( $meta_id, $object_id, $meta_key, $value ): void {
		if ( Meta::PREFIX . 'canonical' === $meta_key && is_string( $value ) && self::same_url( $value, (string) get_permalink( (int) $object_id ) ) ) {
			delete_post_meta( (int) $object_id, Meta::PREFIX . 'canonical' );
		}
	}

	/**
	 * Empties a term override equal to the term's own archive URL (see drop_self_post_override()).
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Term ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     Saved value.
	 */
	public function drop_self_term_override( $meta_id, $object_id, $meta_key, $value ): void {
		if ( Meta::PREFIX . 'canonical' !== $meta_key || ! is_string( $value ) ) {
			return;
		}

		$link = get_term_link( (int) $object_id );
		if ( is_string( $link ) && self::same_url( $value, $link ) ) {
			delete_term_meta( (int) $object_id, Meta::PREFIX . 'canonical' );
		}
	}

	/**
	 * Whether two URLs are the same page, ignoring the scheme and the trailing slash.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	private static function same_url( string $a, string $b ): bool {
		return '' !== $a && '' !== $b
			&& untrailingslashit( set_url_scheme( $a, 'https' ) ) === untrailingslashit( set_url_scheme( $b, 'https' ) );
	}

	/**
	 * Applies the per-post override; drops the canonical of the page being viewed when it is noindex.
	 *
	 * @param mixed $url  Canonical URL built by core.
	 * @param mixed $post Post the URL belongs to.
	 * @return mixed
	 */
	public function filter_singular( $url, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return $url;
		}

		if ( is_singular() && get_queried_object_id() === $post->ID && Robots::is_noindex() ) {
			return '';
		}

		$override = (string) Meta::post( $post->ID, 'canonical' );

		return '' !== $override ? $override : $url;
	}

	/**
	 * Prints the canonical tag on the home page and archives.
	 */
	public function print_archive_canonical(): void {
		if ( is_singular() && ! is_front_page() ) {
			return;
		}

		$url = self::archive_url();

		if ( '' !== $url && ! Robots::is_noindex() ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	/**
	 * Canonical URL of the current home or archive page, or empty when it has none.
	 *
	 * @return string
	 */
	public static function archive_url(): string {
		$object = get_queried_object();

		if ( is_404() || is_search() || ( is_singular() && ! is_front_page() ) ) {
			return '';
		}

		if ( is_front_page() && $object instanceof \WP_Post ) {
			// Static front page: core prints the page's canonical through rel_canonical.
			return '';
		}

		if ( $object instanceof \WP_Term ) {
			$override = (string) Meta::term( $object->term_id, 'canonical' );
			if ( '' !== $override ) {
				return $override;
			}
			$base = get_term_link( $object );
		} elseif ( is_front_page() || ( is_home() && ! $object instanceof \WP_Post ) ) {
			$base = home_url( '/' );
		} elseif ( is_home() && $object instanceof \WP_Post ) {
			$base = get_permalink( $object );
		} elseif ( is_post_type_archive() && $object instanceof \WP_Post_Type ) {
			$base = get_post_type_archive_link( $object->name );
		} elseif ( is_author() && $object instanceof \WP_User ) {
			$base = get_author_posts_url( $object->ID, $object->user_nicename );
		} elseif ( is_day() ) {
			$base = get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
		} elseif ( is_month() ) {
			$base = get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
		} elseif ( is_year() ) {
			$base = get_year_link( (int) get_query_var( 'year' ) );
		} else {
			return '';
		}

		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}

		return self::paged( $base, max( 1, (int) get_query_var( 'paged' ) ) );
	}

	/**
	 * Adds the page number to an archive URL, in the site's permalink format.
	 *
	 * @param string $base  URL of the first page.
	 * @param int    $paged Page number.
	 * @return string
	 */
	private static function paged( string $base, int $paged ): string {
		global $wp_rewrite;

		if ( $paged < 2 ) {
			return $base;
		}

		if ( ! $wp_rewrite->using_permalinks() || str_contains( $base, '?' ) ) {
			return add_query_arg( 'paged', $paged, $base );
		}

		return user_trailingslashit( trailingslashit( $base ) . $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
	}
}
