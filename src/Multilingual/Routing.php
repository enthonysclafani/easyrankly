<?php
/**
 * Language prefixes in URLs and language-filtered queries.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Settings\Settings;

use const EasyRankly\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Serves every language under its URL prefix (none for the default language).
 *
 * - URLs: a copy of the core rewrite rules behind `(en|fr)/` sets the `erankly_lang` query var;
 *   with plain permalinks the var is in the query string. Post links take the prefix of the
 *   post's language; archive and home links the prefix of the language being viewed.
 * - Home per language: the static front page and posts page are the translations of the
 *   configured ones; a language without a translated front page has no home (404).
 * - Queries: on the frontend, lists of content (home, archives, search, feeds, query blocks)
 *   show only the current language. Single content requested under another language's
 *   prefix redirects to its own URL.
 * - Locale: the frontend uses the locale of the language being viewed.
 *
 * Every callback returns early with fewer than two languages. Admin, REST, AJAX, cron
 * and sitemap requests are never filtered.
 */
final class Routing {

	/**
	 * Query var with the language slug.
	 */
	public const QUERY_VAR = 'erankly_lang';

	/**
	 * Query vars that do not change which page a URL shows (WP_Query ignores them for the front page).
	 */
	private const PAGING_VARS = array( 'paged', 'page', 'cpage', 'preview' );

	/**
	 * Archive link filters that take the prefix of the language being viewed.
	 */
	private const ARCHIVE_LINKS = array( 'term_link', 'post_type_archive_link', 'year_link', 'month_link', 'day_link', 'author_link', 'search_link', 'feed_link' );

	/**
	 * True while reading the stored front page options without the language filter.
	 *
	 * @var bool
	 */
	private bool $raw_options = false;

	/**
	 * True while WordPress parses the request: it reads the bare home URL to find the path.
	 *
	 * @var bool
	 */
	private bool $parsing = false;

	/**
	 * Hooks rewrite rules, request parsing, queries, links and locale.
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'add_rewrite_rules' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_updated' ), 10, 2 );
		register_activation_hook( PLUGIN_FILE, array( self::class, 'reset_rewrite_rules' ) );
		register_deactivation_hook( PLUGIN_FILE, array( self::class, 'reset_rewrite_rules' ) );

		add_filter( 'locale', array( $this, 'filter_locale' ) );
		add_filter( 'do_parse_request', array( $this, 'start_parsing' ), PHP_INT_MAX );
		add_filter( 'request', array( $this, 'filter_request' ) );
		add_action( 'parse_request', array( $this, 'end_parsing' ), PHP_INT_MIN );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'template_redirect', array( $this, 'redirect_wrong_language' ), 9 );
		add_filter( 'option_page_on_front', array( $this, 'filter_front_option' ) );
		add_filter( 'option_page_for_posts', array( $this, 'filter_front_option' ) );

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
	 * Language being viewed: the URL prefix, or `erankly_lang` with plain permalinks.
	 *
	 * Read from the request URL, so it is known before WordPress parses the request
	 * (the locale is needed earlier). Empty with fewer than two languages.
	 *
	 * @return string Language slug.
	 */
	public static function current(): string {
		if ( ! Languages::enabled() ) {
			return '';
		}

		$default = Languages::default();

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; the value is checked against the configured languages.
			$slug = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		} else {
			$segments = explode( '/', ltrim( self::request_path(), '/' ) );
			$slug     = 'index.php' === $segments[0] ? ( $segments[1] ?? '' ) : $segments[0];
		}

		return Languages::exists( $slug ) ? $slug : $default;
	}

	/**
	 * Whether the request is a frontend page, where languages filter content and links.
	 *
	 * @return bool
	 */
	public static function is_frontend(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		// REST requests are flagged only once WordPress parses them: recognize their URL too.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks that the parameter exists.
		if ( isset( $_GET['rest_route'] ) ) {
			return false;
		}
		$path = ltrim( self::request_path(), '/' );
		$rest = rest_get_url_prefix();

		return ! ( $path === $rest || str_starts_with( $path, $rest . '/' ) || str_starts_with( $path, 'index.php/' . $rest ) );
	}

	/**
	 * URL in a given language: adds, replaces or removes the language prefix (or query var).
	 *
	 * URLs outside the site are returned unchanged.
	 *
	 * @param string $url      URL on this site.
	 * @param string $language Language slug; the default language has no prefix.
	 * @return string
	 */
	public static function url( string $url, string $language ): string {
		if ( ! Languages::enabled() ) {
			return $url;
		}

		$prefixed = Languages::exists( $language ) && Languages::default() !== $language;

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$url = remove_query_arg( self::QUERY_VAR, $url );
			return $prefixed ? add_query_arg( self::QUERY_VAR, $language, $url ) : $url;
		}

		// Compare without the scheme: links may be built for http or https.
		$base = (string) preg_replace( '#^https?:#', '', untrailingslashit( (string) get_option( 'home' ) ) );
		if ( str_starts_with( (string) get_option( 'permalink_structure' ), '/index.php' ) ) {
			$base .= '/index.php';
		}

		if ( ! preg_match( '#^(https?:)?' . preg_quote( $base, '#' ) . '(/.*)?$#', $url, $matches ) ) {
			return $url;
		}

		$slugs = implode( '|', array_map( static fn( string $slug ): string => preg_quote( $slug, '#' ), array_keys( Languages::all() ) ) );
		$path  = (string) preg_replace( '#^(' . $slugs . ')(/|$|(?=[?\#]))#', '', ltrim( $matches[2] ?? '', '/' ) );

		return $matches[1] . $base . '/' . ( $prefixed ? $language . '/' : '' ) . $path;
	}

	/**
	 * Registers the language query var.
	 *
	 * @param mixed $vars Public query vars.
	 * @return mixed
	 */
	public function add_query_var( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::QUERY_VAR;
		}

		return $vars;
	}

	/**
	 * Adds a prefixed copy of the rewrite rules, before the originals, plus the home of each language.
	 *
	 * One copy serves every non-default language: `(en|fr)/` captures the slug as the first
	 * match and the other matches shift by one. REST, sitemap, robots.txt and favicon rules
	 * stay unprefixed: they are the same for every language.
	 *
	 * @param mixed $rules Rewrite rules, regex => query.
	 * @return mixed
	 */
	public function add_rewrite_rules( $rules ) {
		$slugs = array_diff( array_keys( Languages::all() ), array( Languages::default() ) );
		if ( ! is_array( $rules ) || ! Languages::enabled() ) {
			return $rules;
		}

		$prefix   = '(' . implode( '|', array_map( 'preg_quote', $slugs ) ) . ')/';
		$prefixed = array( $prefix . '?$' => 'index.php?' . self::QUERY_VAR . '=$matches[1]' );

		foreach ( $rules as $regex => $query ) {
			if ( ! is_string( $regex ) || ! is_string( $query ) || preg_match( '#[?&](rest_route|sitemap|sitemap-subtype|sitemap-stylesheet|robots|favicon)=#', $query ) ) {
				continue;
			}

			$query = (string) preg_replace_callback(
				'#\$matches\[(\d+)\]#',
				static fn( array $found ): string => '$matches[' . ( (int) $found[1] + 1 ) . ']',
				$query
			);

			$prefixed[ $prefix . ltrim( $regex, '^' ) ] = $query . '&' . self::QUERY_VAR . '=$matches[1]';
		}

		return $prefixed + $rules;
	}

	/**
	 * Rebuilds the rewrite rules when the first settings are saved with languages.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  New value.
	 */
	public function on_settings_added( $option, $value ): void {
		$this->on_settings_updated( array(), $value );
	}

	/**
	 * Rebuilds the rewrite rules when the language prefixes change.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_settings_updated( $old_value, $value ): void {
		$before = is_array( $old_value ) && isset( $old_value['languages'] ) && is_array( $old_value['languages'] ) ? array_keys( $old_value['languages'] ) : array();
		$after  = is_array( $value ) && isset( $value['languages'] ) && is_array( $value['languages'] ) ? array_keys( $value['languages'] ) : array();

		if ( $before !== $after ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Drops the stored rewrite rules so WordPress rebuilds them, with or without the prefixes.
	 *
	 * Used on activation and deactivation, when this request still has the other set of filters.
	 */
	public static function reset_rewrite_rules(): void {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Uses the locale of the language being viewed.
	 *
	 * @param mixed $locale Site locale.
	 * @return mixed
	 */
	public function filter_locale( $locale ) {
		$current = self::current();
		if ( '' === $current || ! self::is_frontend() ) {
			return $locale;
		}

		return Languages::all()[ $current ]['locale'];
	}

	/**
	 * Marks the start of request parsing.
	 *
	 * @param mixed $parse Whether WordPress parses the request.
	 * @return mixed
	 */
	public function start_parsing( $parse ) {
		$this->parsing = (bool) $parse;

		return $parse;
	}

	/**
	 * Marks the end of request parsing.
	 */
	public function end_parsing(): void {
		$this->parsing = false;
	}

	/**
	 * Keeps a valid non-default language in the request and resolves the home of each language.
	 *
	 * @param mixed $vars Query vars parsed from the request.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! isset( $vars[ self::QUERY_VAR ] ) ) {
			return $vars;
		}

		$language = is_string( $vars[ self::QUERY_VAR ] ) ? $vars[ self::QUERY_VAR ] : '';
		if ( ! Languages::enabled() || ! Languages::exists( $language ) || Languages::default() === $language ) {
			unset( $vars[ self::QUERY_VAR ] );
			return $vars;
		}

		$front = (int) $this->raw_option( 'page_on_front' );
		$other = array_diff_key( $vars, array_flip( array_merge( array( self::QUERY_VAR ), self::PAGING_VARS ) ) );

		if ( array() === $other && 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$translation = Translations::of( $front )[ $language ] ?? 0;
			if ( $translation > 0 ) {
				$vars['page_id'] = $translation;
			} else {
				$vars['error'] = '404';
			}
		}

		return $vars;
	}

	/**
	 * Limits lists of content on the frontend to the language being viewed.
	 *
	 * Single content is not filtered here: redirect_wrong_language() sends it to its own URL.
	 * Content without a language belongs to the default language.
	 *
	 * @param mixed $query Query about to run.
	 */
	public function filter_query( $query ): void {
		if ( ! $query instanceof \WP_Query || ! Languages::enabled() || ! self::is_frontend() ) {
			return;
		}

		if ( $query->is_singular() || array() !== array_filter( (array) $query->get( 'post__in' ) ) || '' !== (string) get_query_var( 'sitemap' ) ) {
			return;
		}

		$types = array_filter( (array) $query->get( 'post_type' ) );
		if ( array() !== $types && ! in_array( 'any', $types, true ) && array() === array_intersect( $types, Languages::post_types() ) ) {
			return;
		}

		// The queried term of a custom taxonomy archive is read from the first tax clause:
		// resolve it before the language clause is added.
		if ( $query->is_tax() ) {
			$query->get_queried_object();
		}

		$current = self::current();
		$default = Languages::default();
		$clause  = array(
			'taxonomy' => Translations::LANGUAGE,
			'field'    => 'slug',
			'terms'    => $current === $default ? array_values( array_diff( array_keys( Languages::all() ), array( $default ) ) ) : array( $current ),
			'operator' => $current === $default ? 'NOT IN' : 'IN',
		);

		$existing = $query->get( 'tax_query' );
		$query->set(
			'tax_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The only native way to filter content by language.
			is_array( $existing ) && array() !== $existing
				? array(
					'relation' => 'AND',
					$existing,
					$clause,
				)
				: array( $clause )
		);
	}

	/**
	 * Sends single content requested under another language's prefix to its own URL.
	 */
	public function redirect_wrong_language(): void {
		$target = $this->wrong_language_target();

		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Own URL of the content being viewed, when the request used another language's prefix.
	 *
	 * @return string Empty when the language matches.
	 */
	public function wrong_language_target(): string {
		$object = get_queried_object();

		if ( ! Languages::enabled() || is_preview() || ! $object instanceof \WP_Post || ! ( is_singular() || is_home() ) ) {
			return '';
		}
		if ( ! is_object_in_taxonomy( $object->post_type, Translations::LANGUAGE ) || Translations::language( $object->ID ) === self::current() ) {
			return '';
		}

		$url = get_permalink( $object );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Front page and posts page in the language being viewed: the translation of the stored page.
	 *
	 * @param mixed $value Stored page ID.
	 * @return mixed
	 */
	public function filter_front_option( $value ) {
		if ( $this->raw_options || ! is_numeric( $value ) || (int) $value <= 0 || ! self::is_frontend() ) {
			return $value;
		}

		$current = self::current();
		if ( '' === $current || Languages::default() === $current ) {
			return $value;
		}

		return Translations::of( (int) $value )[ $current ] ?? $value;
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
			$front = (int) $this->raw_option( 'page_on_front' );
			// Only pages outside the default language can be translations of the front page.
			if ( $post->ID === $front || ( $front > 0 && Languages::default() !== $language && in_array( $front, Translations::of( $post->ID ), true ) ) ) {
				return self::url( (string) get_option( 'home' ) . '/', $language );
			}
		}

		return self::url( $url, $language );
	}

	/**
	 * Gives archive, search and feed links the prefix of the language being viewed.
	 *
	 * @param mixed $url Link.
	 * @return mixed
	 */
	public function filter_archive_link( $url ) {
		return is_string( $url ) && self::is_frontend() && '' !== self::current() ? self::url( $url, self::current() ) : $url;
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
		if ( ! is_string( $url ) || ! in_array( $path, array( '', '/', null ), true ) || $this->parsing || ! self::is_frontend() ) {
			return $url;
		}

		$current = self::current();

		return '' === $current || Languages::default() === $current ? $url : self::url( $url, $current );
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

	/**
	 * Reads a front page option as stored, without the language filter.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private function raw_option( string $name ): mixed {
		$this->raw_options = true;
		$value             = get_option( $name );
		$this->raw_options = false;

		return $value;
	}

	/**
	 * Path of the request URL, relative to the home URL.
	 *
	 * @return string
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );

		return '' !== $home && str_starts_with( $path, $home ) ? (string) substr( $path, strlen( $home ) ) : $path;
	}
}
