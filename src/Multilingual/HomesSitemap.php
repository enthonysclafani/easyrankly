<?php
/**
 * Sitemap of the language homes.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the home of every non-default language when the front page shows the latest posts.
 *
 * Core lists the default home in the pages sitemap and every post and page, in every
 * language, in the usual sitemaps (sitemap queries are never filtered by language). With a
 * static front page the language homes are translated pages, already listed: this sitemap
 * is then empty and core leaves it out of the index.
 */
final class HomesSitemap extends \WP_Sitemaps_Provider {

	/**
	 * Provider name, part of the sitemap URL (`wp-sitemap-languages-1.xml`).
	 */
	public const NAME = 'languages';

	/**
	 * Sets the provider name and object type.
	 */
	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
	}

	/**
	 * Home URLs of the non-default languages.
	 *
	 * @param int    $page_num       Page of the sitemap (only one).
	 * @param string $object_subtype Unused: no subtypes.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( 1 !== (int) $page_num || ! self::has_homes() ) {
			return array();
		}

		$list = array();
		foreach ( array_keys( Languages::all() ) as $language ) {
			if ( Languages::default() !== $language ) {
				$list[] = array( 'loc' => Routing::url( (string) get_option( 'home' ) . '/', $language ) );
			}
		}

		return $list;
	}

	/**
	 * One page when there are language homes to list, none otherwise.
	 *
	 * @param string $object_subtype Unused: no subtypes.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return self::has_homes() ? 1 : 0;
	}

	/**
	 * Whether the language homes are pages of their own: two languages, latest posts on the
	 * front page, and the home not set to noindex.
	 *
	 * @return bool
	 */
	private static function has_homes(): bool {
		$noindex = Settings::value( 'noindex' );

		return Languages::enabled() && 'posts' === get_option( 'show_on_front' ) && ! ( is_array( $noindex ) && in_array( 'home', $noindex, true ) );
	}
}
