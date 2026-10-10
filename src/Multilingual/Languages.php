<?php
/**
 * Languages configured in the settings.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the `languages` setting: an object keyed by language slug, in display order.
 *
 * The slug is the URL prefix and the slug of the language term. The first language is
 * the default one: its URLs keep no prefix and content without a language belongs to it.
 * Multilingual behavior starts with two languages; with fewer, nothing changes on the site.
 */
final class Languages {

	/**
	 * Regex for a language slug: "en", "pt-br", "zh-hant". "wp-…" would shadow WordPress paths.
	 */
	public const SLUG_PATTERN = '^(?!wp-)[a-z]{2,3}(-[a-z0-9]{2,8})?$';

	/**
	 * Regex for a WordPress locale: "en_US", "de_DE_formal", "fi".
	 */
	public const LOCALE_PATTERN = '^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$';

	/**
	 * Configured languages, keyed by slug, in display order.
	 *
	 * `site_title` and `tagline` are empty when the language uses those of Settings > General.
	 *
	 * The option is autoloaded, so this costs no query. Settings::get() builds nothing
	 * translated, so the locale filter can read this.
	 *
	 * @return array<string, array{locale: string, name: string, site_title: string, tagline: string}>
	 */
	public static function all(): array {
		$languages = Settings::value( 'languages' );
		$clean     = array();

		foreach ( is_array( $languages ) ? $languages : array() as $slug => $language ) {
			if ( is_string( $slug ) && is_array( $language ) && isset( $language['locale'], $language['name'] ) ) {
				$clean[ $slug ] = array(
					'locale'     => (string) $language['locale'],
					'name'       => (string) $language['name'],
					'site_title' => (string) ( $language['site_title'] ?? '' ),
					'tagline'    => (string) ( $language['tagline'] ?? '' ),
				);
			}
		}

		return $clean;
	}

	/**
	 * Slug of the default language, or empty when no language is configured.
	 *
	 * @return string
	 */
	public static function default(): string {
		return (string) array_key_first( self::all() );
	}

	/**
	 * Whether the site has at least two languages, so multilingual behavior applies.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return count( self::all() ) >= 2;
	}

	/**
	 * Whether a slug is a configured language.
	 *
	 * @param string $slug Language slug.
	 * @return bool
	 */
	public static function exists( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	/**
	 * Post types whose content has a language: every viewable type except attachments.
	 *
	 * Media is shared by all languages.
	 *
	 * @return list<string>
	 */
	public static function post_types(): array {
		return array_values(
			array_filter(
				get_post_types(),
				static fn( string $post_type ): bool => 'attachment' !== $post_type && is_post_type_viewable( $post_type )
			)
		);
	}
}
