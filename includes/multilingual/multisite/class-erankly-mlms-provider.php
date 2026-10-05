<?php
/** Multisite hreflang provider implementing the EasyRankly multilingual provider API v1. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Treats every participating network site as one language version. Alternates are built from the per-site
 * translation relationships: front pages are always cross-linked, singulars and terms only when explicitly
 * linked, and content without linked translations gets no hreflang output at all.
 */
final class ERANKLY_MLMS_Provider implements ERankly_Multilingual_Provider_Interface {

	public function get_id(): string {
		return ERANKLY_MLMS_PROVIDER_ID;
	}

	public function get_version(): string {
		return ERANKLY_MLMS_VERSION;
	}

	public function get_api_version(): int {
		return defined( 'ERANKLY_EXTENSION_API_VERSION' ) ? (int) ERANKLY_EXTENSION_API_VERSION : 1;
	}

	public function get_priority(): int {
		return 10;
	}

	public function get_topology(): string {
		return 'multisite';
	}

	/** Performs a read-only readiness check (never mutates state). */
	public function preflight(): bool|WP_Error {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'erankly_mlms_not_multisite',
				__( 'EasyRankly Multilingual for Multisite runs only on a WordPress Multisite network.', 'easyrankly' ),
				array( 'retryable' => false )
			);
		}

		if ( ! function_exists( 'erankly_register_multilingual_provider' ) ) {
			return new WP_Error(
				'erankly_mlms_host_api_missing',
				__( 'The EasyRankly multilingual provider API is not available.', 'easyrankly' ),
				array( 'retryable' => false )
			);
		}

		return true;
	}

	public function is_enabled(): bool {
		return is_multisite() && erankly_multilingual_enabled();
	}

	/** Starts relationship, REST and admin hooks only after this provider is selected. */
	public function register_hooks(): void {
		erankly_mlms_register_hooks();
	}

	/**
	 * Describes the current request for the EasyRankly multilingual bridge. Everything except the resolved kind
	 * stays "other": search, 404, date/author archives, CPT archives and paginated pages intentionally produce
	 * no hreflang output.
	 */
	public function get_context(): array {
		$route   = $this->resolve_request_route();
		$blog_id = get_current_blog_id();
		$language = erankly_mlms_get_site_language( $blog_id );

		return array(
			'language_id'    => $language,
			'hreflang'       => erankly_mlms_locale_to_hreflang( $language ),
			'kind'           => $route['kind'],
			'route_kind'     => $route['route_kind'],
			'object_id'      => $route['object_id'],
			'object_subtype' => $route['object_subtype'],
			'blog_id'        => $blog_id,
			'url'            => erankly_mlms_current_url(),
			'locale'         => $language,
			'is_preview'     => is_preview(),
		);
	}

	/**
	 * Builds the language alternates for the current request. The hreflang set (navigable false) skips
	 * noindexed translations; the navigable set keeps every published translation.
	 *
	 * @return array<string,string> Map of hreflang tag (or x-default) to absolute URL.
	 */
	public function get_alternates( array $context, bool $navigable ): array {
		if ( ! $this->is_enabled() ) {
			return array();
		}

		$sites = erankly_mlms_get_enabled_sites();

		if ( count( $sites ) < 2 ) {
			return array(); // A single participating site cannot form a cluster.
		}

		$blog_id = (int) ( $context['blog_id'] ?? get_current_blog_id() );

		if ( ! isset( $sites[ $blog_id ] ) ) {
			return array(); // The current site is excluded from the cluster.
		}

		// The request route is re-resolved from the main query instead of trusting $context: EasyRankly's
		// request-context memo can be built before the main query exists (the core sitemap provider filter runs
		// at init), freezing a wrong 'other' kind for the whole request. The only query-dependent input kept
		// from $context is the blog_id, which never depends on query state.
		$route = $this->resolve_request_route();

		switch ( $route['kind'] ) {
			case 'front_page':
				return $this->front_page_alternates( $sites );
			case 'singular':
				return $this->singular_alternates( $sites, $blog_id, $route['object_id'], $navigable );
			case 'term':
				return $this->term_alternates( $sites, $blog_id, $route['object_id'], $navigable );
		}

		return array();
	}

	/**
	 * Localizes one URL for the supplied context. Each language version intentionally self-canonicalizes: in a
	 * hreflang cluster every translation keeps its own canonical, and EasyRankly cross-links the versions
	 * through the hreflang tags instead. Filters can opt into equivalent-URL rewriting, but the provider itself
	 * never moves the canonical off the current site.
	 */
	public function localize_url( string $url, array $context ): string {
		return (string) apply_filters( 'erankly_mlms_localize_url', $url, $context );
	}

	/**
	 * Resolves the request route from the live main query. Deliberately memo-free: get_context() is also
	 * invoked before the main query exists (e.g. during init through EasyRankly's global meta map reads), and
	 * memoizing that early answer would lock the wrong kind for the rest of the request. The underlying checks
	 * (is_front_page, is_singular, get_queried_object) are trivially cheap.
	 *
	 * @return array{kind:string,route_kind:string,object_id:int,object_subtype:string}
	 */
	private function resolve_request_route(): array {
		$settings = erankly_mlms_get_settings();

		if ( is_preview() || is_feed() || (int) get_query_var( 'page' ) > 1 ) {
			return array( 'kind' => 'other', 'route_kind' => 'other', 'object_id' => 0, 'object_subtype' => '' );
		}

		if ( is_front_page() && ! is_paged() ) {
			return array(
				'kind'           => 'front_page',
				'route_kind'     => 'home',
				'object_id'      => (int) get_queried_object_id(), // Static front page ID, or 0 for post-list homes.
				'object_subtype' => '',
			);
		}

		if ( is_singular() && ! is_paged() ) {
			$post = get_queried_object();
			$type = $post instanceof WP_Post ? $post->post_type : '';

			if ( '' !== $type && in_array( $type, $settings['post_types'], true ) ) {
				return array(
					'kind'           => 'singular',
					'route_kind'     => 'singular',
					'object_id'      => (int) $post->ID,
					'object_subtype' => $type,
				);
			}
		}

		if ( ( is_category() || is_tag() || is_tax() ) && ! is_paged() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term && in_array( $term->taxonomy, $settings['taxonomies'], true ) ) {
				return array(
					'kind'           => 'term',
					'route_kind'     => 'taxonomy',
					'object_id'      => (int) $term->term_id,
					'object_subtype' => $term->taxonomy,
				);
			}
		}

		return array(
			'kind'           => 'other',
			'route_kind'     => 'other',
			'object_id'      => 0,
			'object_subtype' => '',
		);
	}

	/**
	 * Front pages are the language landing pages of the network and are always cross-linked. Duplicate
	 * hreflangs keep the lowest blog_id (deterministic, surfaced as an admin diagnostic).
	 *
	 * @param array<int,array{blog_id:int,name:string,url:string,language:string,hreflang:string}> $sites
	 * @return array<string,string>
	 */
	private function front_page_alternates( array $sites ): array {
		$alternates = array();

		foreach ( $sites as $site ) {
			if ( ! isset( $alternates[ $site['hreflang'] ] ) ) {
				$alternates[ $site['hreflang'] ] = $site['url'];
			}
		}

		$x_default = (int) erankly_mlms_get_settings()['x_default_blog'];

		if ( $x_default > 0 && isset( $sites[ $x_default ] ) ) {
			$alternates['x-default'] = $sites[ $x_default ]['url'];
		}

		return $alternates;
	}

	/**
	 * Singular alternates: the current post plus its published, linked translations. x-default resolves to the
	 * linked translation on the default-language site (or the current URL when this site is the default).
	 *
	 * @param array<int,array{blog_id:int,name:string,url:string,language:string,hreflang:string}> $sites
	 * @return array<string,string>
	 */
	private function singular_alternates( array $sites, int $blog_id, int $post_id, bool $navigable ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$own = erankly_mlms_resolve_post_url( $blog_id, $post_id, $navigable );

		if ( '' === $own ) {
			return array();
		}

		$alternates  = array( $sites[ $blog_id ]['hreflang'] => $own );
		$translations = erankly_mlms_get_post_translations( $post_id, $blog_id );

		foreach ( $translations as $other_blog => $other_post_id ) {
			if ( ! isset( $sites[ $other_blog ] ) ) {
				continue;
			}

			$url = erankly_mlms_resolve_post_url( $other_blog, $other_post_id, $navigable );

			if ( '' === $url ) {
				continue;
			}

			$hreflang = $sites[ $other_blog ]['hreflang'];

			if ( ! isset( $alternates[ $hreflang ] ) ) {
				$alternates[ $hreflang ] = $url;
			}
		}

		if ( count( $alternates ) < 2 ) {
			return array();
		}

		$x_default = (int) erankly_mlms_get_settings()['x_default_blog'];

		if ( $x_default > 0 && isset( $sites[ $x_default ] ) ) {
			if ( $x_default === $blog_id ) {
				$alternates['x-default'] = $own;
			} else {
				$target = (int) ( $translations[ $x_default ] ?? 0 );

				if ( $target > 0 ) {
					$url = erankly_mlms_resolve_post_url( $x_default, $target, $navigable );

					if ( '' !== $url ) {
						$alternates['x-default'] = $url;
					}
				}
			}
		}

		return $alternates;
	}

	/**
	 * Term alternates: the current term plus its linked term translations on other sites.
	 *
	 * @param array<int,array{blog_id:int,name:string,url:string,language:string,hreflang:string}> $sites
	 * @return array<string,string>
	 */
	private function term_alternates( array $sites, int $blog_id, int $term_id, bool $navigable ): array {
		if ( $term_id <= 0 ) {
			return array();
		}

		$own = erankly_mlms_resolve_term_url( $blog_id, $term_id, $navigable );

		if ( '' === $own ) {
			return array();
		}

		$alternates   = array( $sites[ $blog_id ]['hreflang'] => $own );
		$translations = erankly_mlms_get_term_translations( $term_id, $blog_id );

		foreach ( $translations as $other_blog => $other_term_id ) {
			if ( ! isset( $sites[ $other_blog ] ) ) {
				continue;
			}

			$url = erankly_mlms_resolve_term_url( $other_blog, $other_term_id, $navigable );

			if ( '' === $url ) {
				continue;
			}

			$hreflang = $sites[ $other_blog ]['hreflang'];

			if ( ! isset( $alternates[ $hreflang ] ) ) {
				$alternates[ $hreflang ] = $url;
			}
		}

		if ( count( $alternates ) < 2 ) {
			return array();
		}

		$x_default = (int) erankly_mlms_get_settings()['x_default_blog'];

		if ( $x_default > 0 && isset( $sites[ $x_default ] ) ) {
			if ( $x_default === $blog_id ) {
				$alternates['x-default'] = $own;
			} else {
				$target = (int) ( $translations[ $x_default ] ?? 0 );

				if ( $target > 0 ) {
					$url = erankly_mlms_resolve_term_url( $x_default, $target, $navigable );

					if ( '' !== $url ) {
						$alternates['x-default'] = $url;
					}
				}
			}
		}

		return $alternates;
	}
}

/**
 * Builds the absolute URL of the current request. Defers to the host plugin so the provider context reports the
 * same URL the rest of EasyRankly works with -- erankly_current_url() resolves from $wp->request and applies the
 * site's trailing-slash rule, which a raw REQUEST_URI (query string and all) does not. The fallback only runs
 * when the helper is unavailable, which the requirements check already rules out in practice.
 */
function erankly_mlms_current_url(): string {
	if ( function_exists( 'erankly_current_url' ) ) {
		return erankly_current_url();
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

	return esc_url_raw( home_url( $uri ) );
}
