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
 * meta and the noindex rules of the settings.
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
	 * Whether the current page asks not to be indexed: by its own meta or by a context rule.
	 *
	 * @return bool
	 */
	public static function is_noindex(): bool {
		if ( true === self::object_flag( 'noindex' ) ) {
			return true;
		}

		$rules = Settings::value( 'noindex' );

		return is_array( $rules ) && array() !== array_intersect( Context::keys(), $rules );
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
		$object = get_queried_object();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() || is_front_page() ) ) {
			return (bool) Meta::post( $object->ID, $name );
		}
		if ( $object instanceof \WP_Term ) {
			return (bool) Meta::term( $object->term_id, $name );
		}

		return null;
	}
}
