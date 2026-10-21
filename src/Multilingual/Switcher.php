<?php
/**
 * Language switcher block.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use const EasyRankly\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `easyrankly/language-switcher` and renders it on the server: a list of links,
 * no script and no stylesheet on the frontend.
 *
 * Each language links to the version of the current page in that language:
 * - single content: its published translation, or the language home when there is none;
 * - home, archives and search: the same page under the language prefix (first page);
 * - 404: the language home.
 * A language whose home would be a 404 (a static front page not translated into it) gets no
 * link where the home stands in.
 */
final class Switcher {

	/**
	 * Hooks the block registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the block from its block.json, with the server render.
	 *
	 * The block.json declares no script: registering one here would make the core build its whole
	 * list of scripts on every request, REST, redirects and sitemaps included. The editor script
	 * is enqueued in the block editors only, by Admin\EditorPanel.
	 */
	public function register_block(): void {
		register_block_type( dirname( PLUGIN_FILE ) . '/blocks/language-switcher', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * Markup of the block: empty with fewer than two languages. The block has no attributes.
	 *
	 * @return string
	 */
	public static function render(): string {
		$links = self::links();
		if ( count( $links ) < 2 ) {
			return '';
		}

		$current   = Routing::current();
		$languages = Languages::all();
		$items     = '';
		foreach ( $links as $language => $url ) {
			$items .= sprintf(
				'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a></li>',
				esc_url( $url ),
				esc_attr( Hreflang::code( $languages[ $language ]['locale'] ) ),
				$current === $language ? ' aria-current="true"' : '',
				esc_html( $languages[ $language ]['name'] )
			);
		}

		return sprintf(
			'<nav %1$s aria-label="%2$s"><ul>%3$s</ul></nav>',
			get_block_wrapper_attributes(),
			esc_attr__( 'Languages', 'easyrankly' ),
			$items
		);
	}

	/**
	 * URL of the current page in each language, in the order of the settings.
	 *
	 * @return array<string, string>
	 */
	public static function links(): array {
		if ( ! Languages::enabled() ) {
			return array();
		}

		$object = get_queried_object();
		$links  = array();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() ) && is_object_in_taxonomy( $object->post_type, Translations::LANGUAGE ) ) {
			$versions = Translations::versions( $object->ID );
			foreach ( array_keys( Languages::all() ) as $language ) {
				$version = $versions[ $language ] ?? null;
				$url     = $version instanceof \WP_Post && ( $version->ID === $object->ID || Translations::is_public( $version ) ) ? get_permalink( $version ) : false;

				if ( is_string( $url ) ) {
					$links[ $language ] = $url;
				} elseif ( self::has_home( $language ) ) {
					$links[ $language ] = Routing::home( $language );
				}
			}

			return $links;
		}

		$page = is_404() || is_singular() ? '' : get_pagenum_link( 1, false );
		foreach ( array_keys( Languages::all() ) as $language ) {
			if ( '' !== $page ) {
				$links[ $language ] = Routing::url( $page, $language );
			} elseif ( self::has_home( $language ) ) {
				$links[ $language ] = Routing::home( $language );
			}
		}

		return $links;
	}

	/**
	 * Whether a language has a home: with a static front page, only the languages it is translated
	 * into (Requests answers 404 for the others). Its group is cached with the front page.
	 *
	 * @param string $language Language slug.
	 * @return bool
	 */
	private static function has_home( string $language ): bool {
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' !== get_option( 'show_on_front' ) || $front < 1 ) {
			return true;
		}

		return isset( Translations::of( $front )[ $language ] );
	}
}
