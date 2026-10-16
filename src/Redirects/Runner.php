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

		$path = Rule::normalize( Context::request_uri() );
		$rule = $this->find( $path );
		if ( null === $rule || ( $rule['captures'] && $this->loops( $path, $rule['target'] ) ) ) {
			return;
		}

		$this->send( $rule['code'], $rule['query'] ? self::with_query( $rule['target'] ) : $rule['target'] );
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
	 * `captures` tells whether the target was built from regex captures ($1).
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
	 */
	public function find( string $path ): ?array {
		if ( is_404() ) {
			return $this->on_404( $path );
		}

		return $this->first_match( $this->forced(), $path ) ?? $this->moved_term( $path );
	}

	/**
	 * Rule matching a path that is not found: forced, then exact, then regex.
	 *
	 * @param string $path Normalized path.
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
	 */
	private function on_404( string $path ): ?array {
		return $this->first_match( $this->forced(), $path )
			?? $this->exact( $path )
			?? $this->first_match( get_option( Redirects::REGEX_OPTION, array() ), $path );
	}

	/**
	 * Forced rules, read only from the autoloaded options: before the first redirect is saved
	 * the option does not exist, and get_option() would query for it on every page.
	 *
	 * @return mixed
	 */
	private function forced() {
		return isset( wp_load_alloptions()[ Redirects::FORCED_OPTION ] ) ? get_option( Redirects::FORCED_OPTION, array() ) : array();
	}

	/**
	 * Whether the redirects that follow a target come back to an address already passed or go
	 * on for more than Redirects::MAX_HOPS.
	 *
	 * Only for a target built from regex captures ($1): saving the rule could not check every
	 * address it matches (Redirects::loops()), so the real chain is followed here, with the
	 * rules that would really answer each address (next()). It runs only on a request that is
	 * being redirected, with at most two lookups per hop.
	 *
	 * @param string $path   Normalized request path.
	 * @param string $target Target of the rule found for it.
	 */
	private function loops( string $path, string $target ): bool {
		$seen = array( $path => true );
		for ( $hop = 0; $hop <= Redirects::MAX_HOPS; $hop++ ) {
			if ( str_starts_with( $target, '/' ) ) {
				$target = '/' . ltrim( $target, '/\\' );
			}
			$url = Rule::target_url( $target );
			if ( '' === $target || ! Rule::is_internal( $url ) ) {
				return false;
			}

			$next = Rule::normalize( $url );
			if ( isset( $seen[ $next ] ) ) {
				return true;
			}
			$seen[ $next ] = true;

			$rule = $this->next( $next );
			if ( null === $rule || in_array( $rule['code'], Rule::NO_TARGET, true ) ) {
				return false;
			}
			$target = $rule['target'];
		}

		return true;
	}

	/**
	 * Rule that answers an address reached by a redirect: a forced rule always, the others only
	 * when nothing is published there (exact and regex rules run on a 404 only).
	 *
	 * Content is recognised with url_to_postid(), as core does for its own guesses: an
	 * address it cannot resolve (an archive, a feed) counts as missing, so a loop through
	 * it is stopped rather than followed.
	 *
	 * @param string $path Normalized path.
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
	 */
	private function next( string $path ): ?array {
		$forced = $this->first_match( $this->forced(), $path );
		if ( null !== $forced || '/' === $path || 0 !== url_to_postid( home_url( $path ) ) ) {
			return $forced;
		}

		return $this->exact( $path ) ?? $this->first_match( get_option( Redirects::REGEX_OPTION, array() ), $path );
	}

	/**
	 * Exact rule for a term archive reached by an address that is not the term's own.
	 *
	 * Core finds a category by the last part of its address only, so the old address of a
	 * child category still answers after its parent changes slug (SlugChanges made a rule for
	 * it). Comparing with the term link needs no query: core canonical reads the same link.
	 * Its pages and feeds (/page/2, /feed/atom, ?paged=2, ?feed=atom, or a feed and a page
	 * together) use the rule of the archive, with the same page and feed added to the target.
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
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
		$base   = $path;
		$ending = '';
		$query  = '';
		if ( ( is_paged() || is_feed() ) && $wp_rewrite instanceof \WP_Rewrite ) {
			// The page and the feed may come in the path (/page/2, /feed/atom) or in the query
			// string (?paged=2, ?feed=atom), alone or together: the query vars hold both.
			if ( preg_match( '#^(/.+?)(?:/' . preg_quote( $wp_rewrite->pagination_base, '#' ) . '/\d+|/(?:' . preg_quote( $wp_rewrite->feed_base, '#' ) . '/)?(?:feed|rdf|rss|rss2|atom))$#', $path, $parts ) ) {
				$base = $parts[1];
			}

			$page = (int) get_query_var( 'paged' );
			if ( is_feed() ) {
				$feed   = sanitize_key( (string) get_query_var( 'feed' ) );
				$ending = '/' . $wp_rewrite->feed_base . ( '' === $feed || 'feed' === $feed ? '' : '/' . $feed );
				// Core has no pretty address for a page of a feed.
				$query = $page > 1 ? '?paged=' . $page : '';
			} elseif ( $page > 1 ) {
				$ending = '/' . $wp_rewrite->pagination_base . '/' . $page;
			}
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
		$rule['target'] = untrailingslashit( $rule['target'] ) . $ending . ( $slash ? '/' : '' ) . $query;

		return $rule;
	}

	/**
	 * Exact rule for a path, cached until a redirect changes.
	 *
	 * @param string $path Normalized request path.
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
	 */
	private function exact( string $path ): ?array {
		$key    = Rule::hash( $path ) . ':' . wp_cache_get_last_changed( Redirects::CACHE_GROUP );
		$cached = wp_cache_get( $key, Redirects::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return array() === $cached ? null : array(
				'code'     => (int) $cached['code'],
				'target'   => (string) $cached['target'],
				'query'    => ! empty( $cached['query'] ),
				'captures' => false,
			);
		}

		$id   = Redirects::find_id( Rule::hash( $path ) );
		$rule = null !== $id ? Redirects::rule( $id ) : null;
		$hit  = null !== $rule ? array(
			'code'     => $rule['code'],
			'target'   => $rule['target'],
			'query'    => $rule['query'],
			'captures' => false,
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
	 * @return array{code: int, target: string, query: bool, captures: bool}|null
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
					'code'     => (int) $rule['code'],
					'target'   => $target,
					'query'    => ! empty( $rule['query'] ),
					'captures' => ! empty( $rule['regex'] ) && 1 === preg_match( '/\$\d/', (string) $rule['target'] ),
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
			// Core would turn the chosen status into a redirect: its guess of a post with the
			// same slug (redirect_canonical), an old slug of a post (wp_old_slug_redirect), or
			// the admin for /admin and /login (wp_redirect_admin_locations).
			add_filter( 'redirect_canonical', '__return_false' );
			add_filter( 'old_slug_redirect_url', '__return_false' );
			remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
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
