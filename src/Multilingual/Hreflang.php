<?php
/**
 * Alternate language links (hreflang).
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Meta\Meta;
use EasyRankly\Robots\Robots;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints `<link rel="alternate" hreflang>` for the versions of the current page in every
 * language, plus `x-default` pointing to the default language.
 *
 * Only indexable pages that are their own canonical get the links, and they list only
 * versions that are published, public, indexable and their own canonical: search engines
 * ignore alternates that are not. Pages printed:
 * - single content with translations, the static front page and the posts page;
 * - the home that lists posts, which exists in every language.
 * Archives, search, 404 and pages after the first get none.
 */
final class Hreflang {

	/**
	 * Hooks the links into the head, next to the canonical.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_links' ), 1 );
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_option_change' ) );
		}
	}

	/**
	 * Expires the cached alternates when an option their URLs depend on changes.
	 *
	 * @param mixed $option Option name.
	 */
	public static function on_option_change( $option ): void {
		if ( in_array( $option, array( Settings::OPTION, 'show_on_front', 'page_on_front', 'permalink_structure', 'home' ), true ) ) {
			wp_cache_set_last_changed( 'easyrankly' );
		}
	}

	/**
	 * Prints the alternate links of the current page.
	 */
	public function print_links(): void {
		foreach ( self::alternates() as $code => $url ) {
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n", esc_attr( $code ), esc_url( $url ) );
		}
	}

	/**
	 * Alternate URLs of the current page, keyed by hreflang code, `x-default` last.
	 *
	 * @return array<string, string> Empty when the page has fewer than two language versions.
	 */
	public static function alternates(): array {
		if ( ! Languages::enabled() || is_paged() || (int) get_query_var( 'page' ) > 1 || is_preview() || Robots::is_noindex() ) {
			return array();
		}

		$object = get_queried_object();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() ) ) {
			$urls = self::for_post( $object );
		} elseif ( is_home() || ( is_front_page() && ! $object instanceof \WP_Post ) ) {
			$urls = array();
			foreach ( array_keys( Languages::all() ) as $language ) {
				$urls[ $language ] = Routing::home( $language );
			}
		} else {
			return array();
		}

		if ( count( $urls ) < 2 ) {
			return array();
		}

		$links = array();
		foreach ( $urls as $language => $url ) {
			$code = self::code( Languages::all()[ $language ]['locale'] );
			if ( ! isset( $links[ $code ] ) ) {
				$links[ $code ] = $url;
			}
		}
		if ( isset( $urls[ Languages::default() ] ) ) {
			$links['x-default'] = $urls[ Languages::default() ];
		}

		return $links;
	}

	/**
	 * Code for hreflang of a WordPress locale: language and region ("de_DE_formal" → "de-DE").
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function code( string $locale ): string {
		$parts = explode( '_', $locale );

		return isset( $parts[1] ) ? $parts[0] . '-' . $parts[1] : $parts[0];
	}

	/**
	 * URLs of the indexable versions of a post, keyed by language, when the post itself is one.
	 *
	 * Cached in the object cache until a post, its meta, a term relationship, the settings or the
	 * front page and permalink options change (see on_option_change()).
	 *
	 * @param \WP_Post $post Post being viewed.
	 * @return array<string, string>
	 */
	private static function for_post( \WP_Post $post ): array {
		$group = Translations::group( $post->ID );
		if ( null === $group || ! is_object_in_taxonomy( $post->post_type, Translations::LANGUAGE ) || '' !== (string) Meta::post( $post->ID, 'canonical' ) ) {
			return array();
		}

		$key  = 'hreflang:' . $group->term_id . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' ) . ':' . wp_cache_get_last_changed( 'easyrankly' );
		$urls = wp_cache_get( $key, 'easyrankly' );
		if ( is_array( $urls ) ) {
			return isset( $urls[ $post->ID ] ) ? array_column( $urls, 'url', 'language' ) : array();
		}

		$urls = array();
		foreach ( Translations::versions( $post->ID ) as $language => $member ) {
			if ( ! self::indexable( $member ) ) {
				continue;
			}
			$url = get_permalink( $member );
			if ( is_string( $url ) ) {
				$urls[ $member->ID ] = array(
					'language' => $language,
					'url'      => $url,
				);
			}
		}

		wp_cache_set( $key, $urls, 'easyrankly' );

		return isset( $urls[ $post->ID ] ) ? array_column( $urls, 'url', 'language' ) : array();
	}

	/**
	 * Whether a version may be an alternate: published, without password, indexable and its own canonical.
	 *
	 * @param \WP_Post $post Version of the content.
	 * @return bool
	 */
	private static function indexable( \WP_Post $post ): bool {
		return Translations::is_public( $post )
			&& ! (bool) Meta::post( $post->ID, 'noindex' )
			&& '' === (string) Meta::post( $post->ID, 'canonical' );
	}
}
