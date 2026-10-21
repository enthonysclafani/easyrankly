<?php
/**
 * Language of the request and URLs in a language.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

use EasyRankly\Context\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Tells which language a request is in and builds URLs in a language.
 *
 * Every language lives under its URL prefix (none for the default language); with plain
 * permalinks the prefix is the `erankly_lang` query var. Requests adds the rewrite rules and
 * filters queries, Links gives links the prefix: both read the language from here.
 */
final class Routing {

	/**
	 * Query var with the language slug.
	 */
	public const QUERY_VAR = 'erankly_lang';

	/**
	 * Language being viewed: the URL prefix, or `erankly_lang` with plain permalinks.
	 *
	 * Read from the request URL, so it is known before WordPress parses the request
	 * (the locale is needed earlier). Empty with fewer than two languages.
	 *
	 * @return string Language slug.
	 */
	public static function current(): string {
		$languages = Languages::all();
		if ( count( $languages ) < 2 ) {
			return '';
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing; the value is checked against the configured languages.
			$slug = isset( $_GET[ self::QUERY_VAR ] ) && is_string( $_GET[ self::QUERY_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		} else {
			$segments = explode( '/', ltrim( self::request_path(), '/' ) );
			$slug     = 'index.php' === $segments[0] ? ( $segments[1] ?? '' ) : $segments[0];
		}

		return isset( $languages[ $slug ] ) ? $slug : (string) array_key_first( $languages );
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
		$languages = Languages::all();
		if ( count( $languages ) < 2 ) {
			return $url;
		}

		$prefixed = isset( $languages[ $language ] ) && array_key_first( $languages ) !== $language;

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

		$slugs = implode( '|', array_map( static fn( string $slug ): string => preg_quote( $slug, '#' ), array_keys( $languages ) ) );
		$path  = (string) preg_replace( '#^(' . $slugs . ')(/|$|(?=[?\#]))#', '', ltrim( $matches[2] ?? '', '/' ) );

		return ( $matches[1] ?? '' ) . $base . '/' . ( $prefixed ? $language . '/' : '' ) . $path;
	}

	/**
	 * Home URL of a language.
	 *
	 * @param string $language Language slug.
	 * @return string
	 */
	public static function home( string $language ): string {
		return self::url( (string) get_option( 'home' ) . '/', $language );
	}

	/**
	 * Path of the request URL, relative to the home URL.
	 *
	 * Kept for the request: current() and is_frontend() read it for every link and string.
	 *
	 * @return string
	 */
	private static function request_path(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only compared with the URI the path was worked out from; the path comes from Context::request_uri().
		$raw  = $_SERVER['REQUEST_URI'] ?? '';
		$home = (string) get_option( 'home' );
		$kept = wp_cache_get( 'request_path', Languages::CACHE_GROUP );
		if ( is_array( $kept ) && $kept[0] === $raw && $kept[1] === $home ) {
			return (string) $kept[2];
		}

		$path = (string) wp_parse_url( Context::request_uri(), PHP_URL_PATH );
		$base = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
		if ( '' !== $base && ( $path === $base || str_starts_with( $path, $base . '/' ) ) ) {
			$path = (string) substr( $path, strlen( $base ) );
		}

		wp_cache_set( 'request_path', array( $raw, $home, $path ), Languages::CACHE_GROUP );

		return $path;
	}
}
