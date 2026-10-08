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
	 * In a development checkout without `npm run build` the editor script is missing and core
	 * would complain on every request: the block is then registered without it, so existing
	 * blocks still render (the admin notice of Assets explains the missing build).
	 */
	public function register_block(): void {
		$args = array( 'render_callback' => array( self::class, 'render' ) );

		if ( is_readable( dirname( PLUGIN_FILE ) . '/build/language-switcher.asset.php' ) ) {
			register_block_type( dirname( PLUGIN_FILE ) . '/blocks/language-switcher', $args );
		} else {
			register_block_type( 'easyrankly/language-switcher', $args );
		}
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

		$home   = (string) get_option( 'home' ) . '/';
		$object = get_queried_object();
		$links  = array();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() ) && is_object_in_taxonomy( $object->post_type, Translations::LANGUAGE ) ) {
			$versions = Translations::versions( $object->ID );
			foreach ( array_keys( Languages::all() ) as $language ) {
				$version = $versions[ $language ] ?? null;
				$url     = $version instanceof \WP_Post && ( $version->ID === $object->ID || ( 'publish' === $version->post_status && '' === $version->post_password ) )
					? get_permalink( $version )
					: Routing::url( $home, $language );

				$links[ $language ] = is_string( $url ) ? $url : Routing::url( $home, $language );
			}

			return $links;
		}

		$page = is_404() || is_singular() ? $home : get_pagenum_link( 1, false );
		foreach ( array_keys( Languages::all() ) as $language ) {
			$links[ $language ] = Routing::url( $page, $language );
		}

		return $links;
	}
}
