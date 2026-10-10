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
 *   with plain permalinks the var is in the query string.
 * - Home per language: the static front page and posts page are the translations of the
 *   configured ones; a language without a translated front page has no home (404).
 * - Queries: on the frontend, lists of content (home, archives, search, feeds, query blocks)
 *   show only the current language. Single content requested under another language's
 *   prefix redirects to its own URL.
 * - Locale: the frontend uses the locale of the language being viewed.
 *
 * Links gives links the prefix. Every callback returns early with fewer than two languages.
 * Admin, REST, AJAX, cron and sitemap requests are never filtered.
 */
final class Requests {

	/**
	 * Query vars that do not change which page a URL shows (WP_Query ignores them for the front page).
	 */
	private const PAGING_VARS = array( 'paged', 'page', 'cpage', 'preview' );

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
	 * Hooks rewrite rules, request parsing, queries and locale.
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
	}

	/**
	 * Registers the language query var.
	 *
	 * @param mixed $vars Public query vars.
	 * @return mixed
	 */
	public function add_query_var( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = Routing::QUERY_VAR;
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
		$prefixed = array( $prefix . '?$' => 'index.php?' . Routing::QUERY_VAR . '=$matches[1]' );

		foreach ( $rules as $regex => $query ) {
			if ( ! is_string( $regex ) || ! is_string( $query ) || preg_match( '#[?&](rest_route|sitemap|sitemap-subtype|sitemap-stylesheet|robots|favicon)=#', $query ) ) {
				continue;
			}

			$query = (string) preg_replace_callback(
				'#\$matches\[(\d+)\]#',
				static fn( array $found ): string => '$matches[' . ( (int) $found[1] + 1 ) . ']',
				$query
			);

			$prefixed[ $prefix . ltrim( $regex, '^' ) ] = $query . '&' . Routing::QUERY_VAR . '=$matches[1]';
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
		$current = Routing::current();
		if ( '' === $current || ! Routing::is_frontend() ) {
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
	 * Whether WordPress is parsing the request.
	 *
	 * @return bool
	 */
	public function is_parsing(): bool {
		return $this->parsing;
	}

	/**
	 * Keeps a valid non-default language in the request and resolves the home of each language.
	 *
	 * @param mixed $vars Query vars parsed from the request.
	 * @return mixed
	 */
	public function filter_request( $vars ) {
		if ( ! is_array( $vars ) || ! isset( $vars[ Routing::QUERY_VAR ] ) ) {
			return $vars;
		}

		$language = is_string( $vars[ Routing::QUERY_VAR ] ) ? $vars[ Routing::QUERY_VAR ] : '';
		if ( ! Languages::enabled() || ! Languages::exists( $language ) || Languages::default() === $language ) {
			unset( $vars[ Routing::QUERY_VAR ] );
			return $vars;
		}

		$front = (int) $this->raw_option( 'page_on_front' );
		$other = array_diff_key( $vars, array_flip( array_merge( array( Routing::QUERY_VAR ), self::PAGING_VARS ) ) );

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
		if ( ! $query instanceof \WP_Query || ! Languages::enabled() || ! Routing::is_frontend() ) {
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

		// A query that already picks a language keeps its choice.
		$existing = $query->get( 'tax_query' );
		if ( is_array( $existing ) && in_array( Translations::LANGUAGE, array_column( array_filter( $existing, 'is_array' ), 'taxonomy' ), true ) ) {
			return;
		}

		$clause = Translations::query_clause( Routing::current() );
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
		if ( ! is_object_in_taxonomy( $object->post_type, Translations::LANGUAGE ) || Translations::language( $object->ID ) === Routing::current() ) {
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
		if ( $this->raw_options || ! is_numeric( $value ) || (int) $value <= 0 || ! Routing::is_frontend() ) {
			return $value;
		}

		$current = Routing::current();
		if ( '' === $current || Languages::default() === $current ) {
			return $value;
		}

		return Translations::of( (int) $value )[ $current ] ?? $value;
	}

	/**
	 * Reads a front page option as stored, without the language filter.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function raw_option( string $name ): mixed {
		$this->raw_options = true;
		$value             = get_option( $name );
		$this->raw_options = false;

		return $value;
	}
}
