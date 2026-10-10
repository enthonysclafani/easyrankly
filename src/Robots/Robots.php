<?php
/**
 * Robots directives.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Robots;

use EasyRankly\Context\Context;
use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds noindex and nofollow to the core robots meta tag from the per-object
 * meta and the noindex rules of the settings; password-protected posts and empty archives
 * are always noindex.
 *
 * It only ever adds restrictions: core keeps its own rules (search results,
 * embeds, sites hidden from search engines) and its other directives.
 */
final class Robots {

	/**
	 * Hooks the core robots filter.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'filter' ) );
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
