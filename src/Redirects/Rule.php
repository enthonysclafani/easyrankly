<?php
/**
 * Redirect sources and targets: normalization, validation and matching.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions on redirect rules. No database access.
 *
 * Sources are paths relative to the site root (a site installed in /blog/ stores
 * "/old" for "/blog/old"): decoded, lowercase, without query string, duplicate
 * slashes or trailing slash. Regex sources match that same normalized path.
 */
final class Rule {

	/**
	 * Status codes a redirect can answer with.
	 */
	public const CODES = array( 301, 302, 307, 410, 451 );

	/**
	 * Codes that answer with an error page instead of redirecting, so they have no target:
	 * 410 "gone" and 451 "unavailable for legal reasons".
	 */
	public const NO_TARGET = array( 410, 451 );

	/**
	 * Longest accepted source or target, in characters.
	 */
	public const MAX_LENGTH = 512;

	/**
	 * Normalized path of a URL or path on this site.
	 *
	 * @param string $url Absolute URL, root-relative path or bare path.
	 * @return string Path starting with "/" ("/" for the home page).
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		// "//a/b" is a path with an extra slash here, not a protocol-relative URL.
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = '/' . ltrim( $url, '/' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = mb_strtolower( rawurldecode( $path ) );
		$path = '/' . trim( (string) preg_replace( '#/+#', '/', $path ), '/' );
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

		if ( '' !== $home && '/' !== $home ) {
			$home = mb_strtolower( $home );
			if ( $path === $home ) {
				return '/';
			}
			if ( str_starts_with( $path, $home . '/' ) ) {
				$path = substr( $path, strlen( $home ) );
			}
		}

		return $path;
	}

	/**
	 * Value stored in post_name (an indexed column) for an exact source.
	 *
	 * @param string $source Normalized source.
	 * @return string
	 */
	public static function hash( string $source ): string {
		return md5( $source );
	}

	/**
	 * Compiled regex with backtracking limits, so a bad pattern cannot hang a request.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return string
	 */
	public static function compile( string $pattern ): string {
		return '#(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=10000)' . str_replace( '#', '\#', $pattern ) . '#iu';
	}

	/**
	 * Whether a regex pattern compiles.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return bool
	 */
	public static function is_valid_regex( string $pattern ): bool {
		if ( '' === $pattern || mb_strlen( $pattern ) > self::MAX_LENGTH ) {
			return false;
		}

		// A pattern that does not compile raises a warning: turn it into a boolean.
		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored on the next line; compile errors are expected input here.
		$result = preg_match( self::compile( $pattern ), '' );
		restore_error_handler();

		return false !== $result;
	}

	/**
	 * Target for a path, or null when the rule does not match it.
	 *
	 * @param array{source: string, regex: bool, target: string} $rule Rule.
	 * @param string                                             $path Normalized request path.
	 * @return string|null Target with regex captures ($1) replaced.
	 */
	public static function match( array $rule, string $path ): ?string {
		if ( ! $rule['regex'] ) {
			return $rule['source'] === $path ? $rule['target'] : null;
		}

		set_error_handler( static fn(): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored below; a rule hitting its limits must not print warnings.
		$matched = preg_match( self::compile( $rule['source'] ), $path, $captures );
		restore_error_handler();

		if ( 1 !== $matched ) {
			return null;
		}

		// $1, $2… become the captured path parts, URL-encoded except for slashes.
		$target = (string) preg_replace_callback(
			'/\$(\d+)/',
			static fn( array $m ): string => str_replace( '%2F', '/', rawurlencode( $captures[ (int) $m[1] ] ?? '' ) ),
			$rule['target']
		);

		// Captures never change the host chosen by the administrator.
		if ( self::is_absolute_url( $rule['target'] ) && strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $rule['target'], PHP_URL_HOST ) ) ) {
			return null;
		}

		return $target;
	}

	/**
	 * Absolute URL of a target: root-relative paths are resolved against the site.
	 *
	 * @param string $target Root-relative path or absolute URL.
	 * @return string
	 */
	public static function target_url( string $target ): string {
		return str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ? home_url( $target ) : $target;
	}

	/**
	 * Whether a URL is an absolute http(s) address with a host. No DNS lookup.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_absolute_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			&& '' !== ( $parts['host'] ?? '' )
			&& ! preg_match( '/[\s<>"]/', $url );
	}

	/**
	 * Whether a target URL is on this site.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_internal( string $url ): bool {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Checks a rule before saving it.
	 *
	 * @param string $source Source as typed (path, URL or regex).
	 * @param string $target Target as typed (path or URL; empty for 410 and 451).
	 * @param int    $code   Status code.
	 * @param bool   $regex  Whether the source is a regex.
	 * @return array{source: string, target: string}|\WP_Error Normalized source and target, or the reason they are invalid.
	 */
	public static function validate( string $source, string $target, int $code, bool $regex ) {
		$source = trim( $source );
		$target = trim( $target );

		if ( ! in_array( $code, self::CODES, true ) ) {
			return new \WP_Error( 'easyrankly_redirect_code', __( 'Unsupported status code.', 'easyrankly' ) );
		}

		if ( '' === $source || mb_strlen( $source ) > self::MAX_LENGTH ) {
			return new \WP_Error( 'easyrankly_redirect_source', __( 'The source is empty or too long.', 'easyrankly' ) );
		}

		if ( $regex ) {
			if ( ! self::is_valid_regex( $source ) ) {
				return new \WP_Error( 'easyrankly_redirect_regex', __( 'The regular expression is not valid.', 'easyrankly' ) );
			}
		} else {
			$host = wp_parse_url( $source, PHP_URL_HOST );
			if ( is_string( $host ) && ! self::is_internal( $source ) ) {
				return new \WP_Error( 'easyrankly_redirect_source', __( 'The source must be an address on this site.', 'easyrankly' ) );
			}
			$source = self::normalize( $source );
		}

		if ( in_array( $code, self::NO_TARGET, true ) ) {
			return array(
				'source' => $source,
				'target' => '',
			);
		}

		if ( '' === $target || mb_strlen( $target ) > self::MAX_LENGTH ) {
			return new \WP_Error( 'easyrankly_redirect_target', __( 'The target is empty or too long.', 'easyrankly' ) );
		}

		if ( str_starts_with( $target, '/' ) && ! str_starts_with( $target, '//' ) ) {
			$target = esc_url_raw( $target );
		} elseif ( ! self::is_absolute_url( $target ) || str_contains( (string) wp_parse_url( $target, PHP_URL_HOST ), '$' ) || false === wp_http_validate_url( $target ) ) {
			// The host must be fixed by the administrator: a "$1" there would let visitors pick it.
			return new \WP_Error( 'easyrankly_redirect_target', __( 'The target must be a path starting with / or a valid http(s) address.', 'easyrankly' ) );
		} else {
			$target = esc_url_raw( $target, array( 'http', 'https' ) );
		}

		if ( ! $regex && self::is_internal( self::target_url( $target ) ) && self::normalize( $target ) === $source ) {
			return new \WP_Error( 'easyrankly_redirect_loop', __( 'The target is the same address as the source.', 'easyrankly' ) );
		}

		return array(
			'source' => $source,
			'target' => $target,
		);
	}
}
