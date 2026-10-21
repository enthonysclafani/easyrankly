<?php
/**
 * Robots directives.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Robots;

use EasyRankly\Context\Context;
use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds noindex and nofollow to the core robots meta tag from the per-object
 * meta and the noindex rules of the settings; password-protected posts and empty archives
 * are always noindex.
 *
 * It only ever adds restrictions: core keeps its own rules (search results,
 * embeds, sites hidden from search engines) and its other directives.
 *
 * Feeds have no robots meta tag: they get `X-Robots-Tag: noindex, follow`, so a feed never
 * shows up in search results in place of the page it repeats, while its links are still followed.
 */
final class Robots {

	/**
	 * Hooks the core robots filter.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'filter' ) );
		add_filter( 'wp_headers', array( $this, 'feed_headers' ), 10, 2 );
	}

	/**
	 * Adds noindex to the headers of every feed.
	 *
	 * The headers are sent before the main query runs, so is_feed() is not known yet: the parsed
	 * `feed` query var is, and it is what core itself reads to send the feed's content type.
	 *
	 * @param mixed $headers Headers to send.
	 * @param mixed $wp      Current WordPress environment.
	 * @return mixed
	 */
	public function feed_headers( $headers, $wp ) {
		if ( is_array( $headers ) && $wp instanceof \WP && ! empty( $wp->query_vars['feed'] ) ) {
			$headers['X-Robots-Tag'] = 'noindex, follow';
		}

		return $headers;
	}

	/**
	 * Adds our directives to the core ones.
	 *
	 * @param mixed $robots Directives from core and earlier filters.
	 * @return mixed
	 */
	public function filter( $robots ) {
		if ( ! is_array( $robots ) ) {
			return $robots;
		}

		if ( self::is_noindex() ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		if ( self::is_nofollow() ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		return $robots;
	}

	/**
	 * Whether the current page asks not to be indexed: by its own meta, by a context rule,
	 * because it is a password-protected post, whose text search engines cannot read, or
	 * because it is an archive with nothing in it.
	 *
	 * @return bool
	 */
	public static function is_noindex(): bool {
		return (bool) Settings::remember( 'noindex', static fn(): bool => self::compute_noindex() );
	}

	/**
	 * Works out is_noindex().
	 *
	 * @return bool
	 */
	private static function compute_noindex(): bool {
		$object = get_queried_object();
		if ( is_singular() && $object instanceof \WP_Post && '' !== $object->post_password ) {
			return true;
		}

		if ( true === self::object_flag( 'noindex' ) || self::is_empty_archive( $object ) ) {
			return true;
		}

		$rules = Settings::value( 'noindex' );

		return is_array( $rules ) && array() !== array_intersect( Context::keys(), $rules );
	}

	/**
	 * Whether the page is an archive that lists nothing: a term, post type or author archive,
	 * or the posts page, with no posts.
	 *
	 * The main query has already run, so this costs no query. Core answers 404 for an empty
	 * date archive and for the later pages of any empty archive, so only these get here. A term with a description of
	 * its own keeps its page indexable: the description is content.
	 *
	 * @param mixed $queried Queried object.
	 * @return bool
	 */
	private static function is_empty_archive( $queried ): bool {
		global $wp_query;

		if ( ! ( is_archive() || ( is_home() && ! is_front_page() ) ) || ! $wp_query instanceof \WP_Query || $wp_query->post_count > 0 ) {
			return false;
		}

		return ! ( $queried instanceof \WP_Term && '' !== trim( $queried->description ) );
	}

	/**
	 * Whether a post may be listed as a page of its own for search engines: published, without
	 * password, without its own noindex, its own canonical and not sent elsewhere by an exact
	 * forced redirect.
	 *
	 * This is the one rule for the other places that list posts: hreflang uses it as it is, the
	 * sitemap applies the same criteria as queries (Sitemap::filter_posts_query_args()), so a
	 * criterion added here goes there too. The context rules of the settings are not part of it:
	 * they hold for whole post types, which the sitemap drops as a whole and translations share.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function is_indexable_post( \WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& '' === $post->post_password
			&& ! (bool) Meta::post( $post->ID, 'noindex' )
			&& '' === (string) Meta::post( $post->ID, 'canonical' )
			&& ! in_array( Rule::normalize( (string) get_permalink( $post ) ), Redirects::forced_sources(), true );
	}

	/**
	 * Whether the current page asks not to follow its links.
	 *
	 * @return bool
	 */
	public static function is_nofollow(): bool {
		return true === self::object_flag( 'nofollow' );
	}

	/**
	 * Boolean meta of the queried post or term, or null when the page has no such object.
	 *
	 * @param string $name "noindex" or "nofollow".
	 * @return bool|null
	 */
	private static function object_flag( string $name ): ?bool {
		$value = Meta::queried( $name );

		return null === $value ? null : (bool) $value;
	}
}
