<?php
/** Native language URLs and eligible translated content for the public SEO bridge. */
defined( 'ABSPATH' ) || exit;

final class ERankly_MLSS_Provider implements ERankly_Multilingual_Provider_Interface {
	public function get_id(): string { return 'singlesite'; }
	public function get_version(): string { return ERANKLY_VERSION; }
	public function get_api_version(): int { return ERANKLY_EXTENSION_API_VERSION; }
	public function get_priority(): int { return 10; }
	public function get_topology(): string { return 'singlesite'; }
	public function is_enabled(): bool { return ! is_multisite() && erankly_multilingual_enabled(); }
	public function preflight(): bool|WP_Error { return $this->is_enabled(); }
	public function register_hooks(): void { erankly_mlss_register_hooks(); }
	/** Native URL hooks already supply the language; explicit canonicals stay intact. */
	public function localize_url( string $url, array $context ): string { return $url; }

	public function get_context(): array {
		$kind = 'other';
		$id   = get_queried_object_id();
		if ( ! is_404() && ! is_preview() && ! is_paged() && (int) get_query_var( 'page' ) < 2 && ! is_feed() ) {
			if ( is_singular() ) {
				$kind = 'post';
			} elseif ( is_category() || is_tag() || is_tax() ) {
				$kind = 'term';
			} elseif ( is_home() ) {
				$kind = $id ? 'post' : 'home';
			} elseif ( is_post_type_archive() ) {
				$kind = 'archive';
			}
		}
		$map      = erankly_mlss_get_translations( $kind, $id );
		$language = in_array( $kind, array( 'post', 'term' ), true ) ? erankly_mlss_get_language( $kind, $id ) : erankly_mlss_current_language();
		$type = get_query_var( 'post_type' );
		$subtype  = 'archive' === $kind ? (string) ( is_array( $type ) ? reset( $type ) : $type ) : erankly_mlss_object_subtype( $kind, $id );
		$url      = 'post' === $kind ? get_permalink( $id ) : ( 'term' === $kind ? get_term_link( $id ) : '' );
		if ( 'home' === $kind ) { $url = erankly_mlss_home_url(); }
		if ( 'archive' === $kind ) { $url = get_post_type_archive_link( $subtype ); }
		return array(
			'kind' => $kind, 'route_kind' => $kind, 'object_id' => $id,
			'object_subtype' => $subtype, 'blog_id' => get_current_blog_id(),
			'language_id' => false === $language ? '' : $language,
			'hreflang' => false === $language ? '' : $language,
			'locale' => false === $language ? get_locale() : erankly_mlss_locale( get_option( 'WPLANG' ) ?: 'en_US' ),
			'url' => is_string( $url ) ? $url : '', 'is_preview' => is_preview(),
		);
	}

	public function get_alternates( array $context, bool $navigable ): array {
		if ( ! $this->is_enabled() ) {
			return array();
		}
		// Read the live query: the public bridge may have memoized an earlier context.
		$context  = $this->get_context();
		$kind     = $context['kind'];
		$id       = $context['object_id'];
		$map      = erankly_mlss_get_translations( $kind, $id );
		$settings = erankly_mlss_get_settings();
		if ( in_array( $kind, array( 'home', 'archive' ), true ) ) {
			$alternates = array();
			foreach ( $settings['languages'] as $language ) {
				$url = erankly_mlss_url( $context['url'], $language );
				$state = erankly_get_object_seo_state( array_replace( $context, array( 'url' => $url ) ) );
				if ( $state['public'] && ( $navigable || $state['indexable'] ) ) { $alternates[ $language ] = $url; }
			}
			if ( count( $alternates ) < 2 ) { return array(); }
			if ( isset( $alternates[ $settings['x_default_language'] ] ) ) { $alternates['x-default'] = $alternates[ $settings['x_default_language'] ]; }
			return $alternates;
		}
		if ( count( $map ) < 2 || ! in_array( $id, $map, true ) || ! in_array( $context['language_id'], $settings['languages'], true ) ) {
			return array();
		}
		'post' === $kind ? _prime_post_caches( array_values( $map ), true, true ) : _prime_term_caches( array_values( $map ), true );
		$alternates = array();
		foreach ( $map as $language => $target ) {
			if ( ! in_array( $language, $settings['languages'], true ) || erankly_mlss_get_translations( $kind, $target ) !== $map
				|| erankly_mlss_object_subtype( $kind, $target ) !== $context['object_subtype'] ) {
				continue;
			}
			$url = 'post' === $kind ? get_permalink( $target ) : get_term_link( $target );
			if ( ! is_string( $url ) ) {
				continue;
			}
			$state = erankly_get_object_seo_state( array_replace( $context, array( 'object_id' => $target, 'url' => $url ) ) );
			foreach ( array( 'page_on_front' => 'homepage', 'page_for_posts' => 'blog' ) as $option => $special ) {
				$special_id = (int) erankly_mlss_base_option( $option );
				if ( erankly_seo_enabled() && 'post' === $kind && $special_id && in_array( $target, erankly_mlss_get_translations( 'post', $special_id ), true )
					&& 'inherit' === erankly_get_object_robots_directive( 'post', $target, 'index' )
					&& erankly_get_global_entity_directive( 'global_special_meta', $special, 'noindex' ) ) {
					$state['indexable'] = false;
				}
			}
			if ( ! $state['published'] || ! $state['public'] || ( ! $navigable && ( ! $state['indexable'] || ! $state['canonical_is_self'] ) ) ) {
				continue;
			}
			$alternates[ $language ] = $url;
		}
		// Hreflang clusters require a valid self-reference and two eligible languages.
		if ( count( $alternates ) < 2 || ! isset( $alternates[ $context['language_id'] ] ) ) {
			return array();
		}
		if ( isset( $alternates[ $settings['x_default_language'] ] ) ) {
			$alternates['x-default'] = $alternates[ $settings['x_default_language'] ];
		}
		return $alternates;
	}
}
