<?php
/**
 * Links in the language of their content or of the page.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Gives links the language prefix: a post link the prefix of the post's language, archive,
 * search, feed and home links the prefix of the language being viewed. Also loads the
 * languages of the posts that menus and page lists link to with one query.
 *
 * Every callback returns early with fewer than two languages.
 */
final class Links {

	/**
	 * Archive link filters that take the prefix of the language being viewed.
	 */
	private const ARCHIVE_LINKS = array( 'term_link', 'post_type_archive_link', 'year_link', 'month_link', 'day_link', 'author_link', 'search_link', 'feed_link' );

	/**
	 * Request handling, for the stored front page and the parsing state.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Keeps the request handling the links depend on.
	 *
	 * @param Requests $requests Request handling.
	 */
	public function __construct( Requests $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Hooks the link filters.
	 */
	public function register(): void {
		add_filter( 'post_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_post_link' ), 10, 2 );
		foreach ( self::ARCHIVE_LINKS as $filter ) {
			add_filter( $filter, array( $this, 'filter_archive_link' ) );
		}
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 2 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'prime_menu_items' ) );
		add_filter( 'get_pages', array( $this, 'prime_pages' ) );
	}

	/**
	 * Gives a post link the prefix of the post's language; the translated front pages link to their language home.
	 *
	 * @param mixed $url  Permalink.
	 * @param mixed $post Post object (post_link, post_type_link) or ID (page_link).
	 * @return mixed
	 */
	public function filter_post_link( $url, $post ) {
		$post = get_post( $post instanceof \WP_Post ? $post : ( is_numeric( $post ) ? (int) $post : 0 ) );
		if ( ! is_string( $url ) || null === $post || ! Languages::enabled() || ! is_object_in_taxonomy( $post->post_type, Translations::LANGUAGE ) ) {
			return $url;
		}

		$language = Translations::language( $post->ID );

		if ( 'page' === $post->post_type && 'page' === get_option( 'show_on_front' ) ) {
			$front = (int) $this->requests->raw_option( 'page_on_front' );
			// Only pages outside the default language can be translations of the front page.
			if ( $post->ID === $front || ( $front > 0 && Languages::default() !== $language && in_array( $front, Translations::of( $post->ID ), true ) ) ) {
				return Routing::home( $language );
			}
		}

		return Routing::url( $url, $language );
	}

	/**
	 * Gives archive, search and feed links the prefix of the language being viewed.
	 *
	 * @param mixed $url Link.
	 * @return mixed
	 */
	public function filter_archive_link( $url ) {
		return is_string( $url ) && Routing::is_frontend() && '' !== Routing::current() ? Routing::url( $url, Routing::current() ) : $url;
	}

	/**
	 * Home links on the frontend lead to the home of the language being viewed.
	 *
	 * Only the bare home URL changes: URLs with a path (REST, sitemaps, files) keep it unprefixed.
	 * Not while WordPress parses the request: it reads the bare home URL to find the path.
	 *
	 * @param mixed $url  Home URL.
	 * @param mixed $path Path requested.
	 * @return mixed
	 */
	public function filter_home_url( $url, $path ) {
		if ( ! is_string( $url ) || ! in_array( $path, array( '', '/', null ), true ) || $this->requests->is_parsing() || ! Routing::is_frontend() ) {
			return $url;
		}

		$current = Routing::current();

		return '' === $current || Languages::default() === $current ? $url : Routing::url( $url, $current );
	}

	/**
	 * Loads the languages of the posts a menu links to with one query, instead of one per link.
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public function prime_menu_items( $items ) {
		if ( ! is_array( $items ) || ! Languages::enabled() ) {
			return $items;
		}

		$ids   = array();
		$types = array();
		foreach ( $items as $item ) {
			if ( is_object( $item ) && isset( $item->type, $item->object, $item->object_id ) && 'post_type' === $item->type ) {
				$ids[]   = (int) $item->object_id;
				$types[] = (string) $item->object;
			}
		}

		if ( array() !== $ids ) {
			update_object_term_cache( array_unique( $ids ), array_values( array_unique( $types ) ) );
		}

		return $items;
	}

	/**
	 * Loads the languages of pages listed by get_pages() (page list block) with one query.
	 *
	 * @param mixed $pages Pages.
	 * @return mixed
	 */
	public function prime_pages( $pages ) {
		if ( is_array( $pages ) && array() !== $pages && Languages::enabled() ) {
			update_object_term_cache( wp_list_pluck( $pages, 'ID' ), 'page' );
		}

		return $pages;
	}
}
