<?php
/**
 * Applies redirects on the frontend.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

use EasyRankly\Context\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the redirect for the current request, at most one hop.
 *
 * Cost on a normal page: reading the autoloaded list of forced rules, no query.
 * On a 404, and on a term archive reached by an address that is not the term's own: one
 * cached lookup by hash (indexed post_name); on a 404 the regex option too.
 */
final class Runner {

	/**
	 * Hooks before core canonical and old-slug redirects, so an explicit rule wins.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'run' ), 1 );
	}

	/**
	 * Finds and sends the redirect for the current request, if any.
	 */
	public function run(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		$rule = $this->find( Rule::normalize( Context::request_uri() ) );
		if ( null !== $rule ) {
			$this->send( $rule['code'], $rule['query'] ? self::with_query( $rule['target'] ) : $rule['target'] );
		}
	}

	/**
	 * Target with the query string of the request, for rules that pass it on (`?utm_source=…`).
	 *
	 * A target with a query string of its own keeps it as it is: merging the two could
	 * override the parameters the rule was written for.
	 *
	 * @param string $target Target path or URL.
	 * @return string
	 */
	public static function with_query( string $target ): string {
		$query = (string) wp_parse_url( Context::request_uri(), PHP_URL_QUERY );
		if ( '' === $query || '' === $target || str_contains( $target, '?' ) ) {
			return $target;
		}

		$hash = strpos( $target, '#' );
		if ( false === $hash ) {
			return $target . '?' . $query;
		}

		return substr( $target, 0, $hash ) . '?' . $query . substr( $target, $hash );
	}

	/**
	 * Rule matching a path: forced rules first, then (on 404 only) exact and regex rules, and
	 * exact ones also for a term archive at an old address (moved_term()).
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool}|null
	 */
	public function find( string $path ): ?array {
		// Read the forced list only from the autoloaded options: before the first redirect is
		// saved the option does not exist, and get_option() would query for it on every page.
		$forced = isset( wp_load_alloptions()[ Redirects::FORCED_OPTION ] ) ? get_option( Redirects::FORCED_OPTION, array() ) : array();
		$found  = $this->first_match( $forced, $path );
		if ( null !== $found ) {
			return $found;
		}
		if ( ! is_404() ) {
			return $this->moved_term( $path );
		}

		$exact = $this->exact( $path );
		if ( null !== $exact ) {
			return $exact;
		}

		return $this->first_match( get_option( Redirects::REGEX_OPTION, array() ), $path );
	}

	/**
	 * Exact rule for a term archive reached by an address that is not the term's own.
	 *
	 * Core finds a category by the last part of its address only, so the old address of a
	 * child category still answers after its parent changes slug (SlugChanges made a rule for
	 * it). Comparing with the term link needs no query: core canonical reads the same link.
	 * Its pages and feeds (/page/2, /feed) use the rule of the archive, with the same ending
	 * added to the target.
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool}|null
	 */
	private function moved_term( string $path ): ?array {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return null;
		}

		$term = get_queried_object();
		$link = $term instanceof \WP_Term ? get_term_link( $term ) : null;
		if ( ! is_string( $link ) ) {
			return null;
		}

		global $wp_rewrite;
		$ending = '';
		$base   = $path;
		if ( ( is_paged() || is_feed() ) && $wp_rewrite instanceof \WP_Rewrite
			&& preg_match( '#^(/.+?)(/' . preg_quote( $wp_rewrite->pagination_base, '#' ) . '/\d+|/(?:' . preg_quote( $wp_rewrite->feed_base, '#' ) . '/)?(?:feed|rdf|rss|rss2|atom))$#', $path, $parts ) ) {
			$base   = $parts[1];
			$ending = $parts[2];
		}

		if ( Rule::normalize( $link ) === $base ) {
			return null;
		}

		$rule = $this->exact( $base );
		// The ending is added only to a plain path: a URL or a query string is the administrator's.
		if ( null === $rule || '' === $ending || ! str_starts_with( $rule['target'], '/' ) || 1 === preg_match( '/[?#]/', $rule['target'] ) ) {
			return $rule;
		}

		$slash          = str_ends_with( $rule['target'], '/' );
		$rule['target'] = untrailingslashit( $rule['target'] ) . $ending . ( $slash ? '/' : '' );

		return $rule;
	}

	/**
	 * Exact rule for a path, cached until a redirect changes.
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool}|null
	 */
	private function exact( string $path ): ?array {
		$key    = Rule::hash( $path ) . ':' . wp_cache_get_last_changed( Redirects::CACHE_GROUP );
		$cached = wp_cache_get( $key, Redirects::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return array() === $cached ? null : array(
				'code'   => (int) $cached['code'],
				'target' => (string) $cached['target'],
				'query'  => ! empty( $cached['query'] ),
			);
		}

		$id   = Redirects::find_id( Rule::hash( $path ) );
		$rule = null !== $id ? Redirects::rule( $id ) : null;
		$hit  = null !== $rule ? array(
			'code'   => $rule['code'],
			'target' => $rule['target'],
			'query'  => $rule['query'],
		) : null;

		// Every distinct address without a rule adds an entry: misses expire sooner than hits.
		wp_cache_set( $key, $hit ?? array(), Redirects::CACHE_GROUP, null === $hit ? MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $hit;
	}

	/**
	 * First rule of a list that matches the path.
	 *
	 * @param mixed  $rules Stored list of rules.
	 * @param string $path  Normalized request path.
	 * @return array{code: int, target: string, query: bool}|null
	 */
	private function first_match( $rules, string $path ): ?array {
		if ( ! is_array( $rules ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['source'], $rule['target'], $rule['code'] ) ) {
				continue;
			}

			$target = Rule::match(
				array(
					'source' => (string) $rule['source'],
					'regex'  => ! empty( $rule['regex'] ),
					'target' => (string) $rule['target'],
				),
				$path
			);

			// A regex whose target matches it again (^/(.*)$ → /en/$1) would chain forever.
			if ( null !== $target && ! empty( $rule['regex'] ) && Rule::is_internal( Rule::target_url( $target ) )
				&& null !== Rule::match(
					array(
						'source' => (string) $rule['source'],
						'regex'  => true,
						'target' => (string) $rule['target'],
					),
					Rule::normalize( $target )
				)
			) {
				continue;
			}

			if ( null !== $target ) {
				return array(
					'code'   => (int) $rule['code'],
					'target' => $target,
					'query'  => ! empty( $rule['query'] ),
				);
			}
		}

		return null;
	}

	/**
	 * Sends the response: 410 and 451 render the 404 template with their own status, the others redirect.
	 *
	 * Internal targets go through wp_safe_redirect(). External targets were validated when
	 * an administrator saved them and are checked again here. A target equal to the
	 * current address is ignored, so a rule can never loop on itself.
	 *
	 * @param int    $code   Status code.
	 * @param string $target Target path or URL.
	 */
	private function send( int $code, string $target ): void {
		if ( in_array( $code, Rule::NO_TARGET, true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( $code );
			nocache_headers();
			return;
		}

		if ( ! in_array( $code, Rule::CODES, true ) || '' === $target ) {
			return;
		}

		// Regex captures may start with "/": never let a relative target become "//host".
		if ( str_starts_with( $target, '/' ) ) {
			$target = '/' . ltrim( $target, '/\\' );
		}

		$url = Rule::target_url( $target );

		if ( Rule::is_internal( $url ) ) {
			if ( Rule::normalize( $url ) === Rule::normalize( Context::request_uri() ) ) {
				return;
			}
			if ( wp_safe_redirect( $url, $code, 'EasyRankly' ) ) {
				exit;
			}
			return;
		}

		if ( Rule::is_absolute_url( $url ) && wp_redirect( $url, $code, 'EasyRankly' ) ) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External target saved by an administrator and checked with wp_http_validate_url() then; checked again here without a DNS lookup.
			exit;
		}
	}
}
