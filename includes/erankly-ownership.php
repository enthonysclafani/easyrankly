<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Ownership {

	/**
	 * Lets recognized Custom code take ownership before WordPress prints its
	 * canonical and robots tags.
	 *
	 * @return void
	 */
	public static function prepare_request_ownership(): void {
		$analysis = self::get_head_analysis( self::get_singular_post_id() );

		if ( ! empty( $analysis['canonical_url'] ) ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}

		if ( array_key_exists( 'robots', $analysis['meta'] ) ) {
			remove_action( 'wp_head', 'wp_robots', 1 );
		}

		if ( ! empty( $analysis['title'] ) ) {
			self::remove_core_title_actions();
		}
	}

	/**
	 * Claims the document title once the template loader has picked its renderer.
	 *
	 * Block themes swap core's title callback while locating the template, which
	 * happens after the wp action, so the removal has to run again here.
	 *
	 * @return void
	 */
	public static function claim_title_ownership(): void {
		$analysis = self::get_head_analysis( self::get_singular_post_id() );

		if ( ! empty( $analysis['title'] ) ) {
			self::remove_core_title_actions();
		}
	}

	private static function remove_core_title_actions(): void {
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
	}

	/**
	 * Makes WordPress' singular canonical resolver share EasyRankly's final URL.
	 * Valid manual Custom code wins.
	 *
	 * @param string|false $canonical_url Core canonical URL.
	 * @param WP_Post      $post          Canonical post.
	 * @return string|false
	 */
	public static function filter_core_canonical_url( $canonical_url, $post ) {
		$url             = is_string( $canonical_url ) ? $canonical_url : '';
		$current_post_id = self::get_singular_post_id();

		if ( $post instanceof WP_Post && $current_post_id === (int) $post->ID ) {
			$analysis = self::get_head_analysis( $current_post_id );

			if ( ! empty( $analysis['canonical_url'] ) ) {
				$url = $analysis['canonical_url'];
			}
		}

		if ( '' === $url ) {
			return false;
		}

		$url = self::sanitize_social_url( $url );

		return '' !== $url ? $url : $canonical_url;
	}

	private static function get_singular_post_id(): int {
		return is_singular() ? absint( get_queried_object_id() ) : 0;
	}

	private static function is_public_request(): bool {
		return ! is_preview() && ! is_feed() && ! is_robots() && (bool) get_option( 'blog_public' );
	}

	private static function is_noindex( $post_id ): bool {
		return 'noindex' === get_post_meta( absint( $post_id ), self::VISIBILITY_META_KEY, true );
	}

	private static function get_head_analysis( $post_id ): array {
		$analysis = array(
			'canonical_url' => '',
			'meta'          => array(),
			'schema_ids'    => array(),
			'schema_types'  => array(),
			'title'         => false,
		);

		/*
		 * Resolving a variable can reach this method back through WordPress'
		 * canonical resolver. Code still holding its tokens claims nothing, and
		 * the result is never cached.
		 */
		if ( self::$is_resolving_code ) {
			return $analysis;
		}

		$code = self::get_effective_head_code( $post_id );
		$key  = hash( 'sha256', $code );

		if ( isset( self::$head_analysis_cache[ $key ] ) ) {
			return self::$head_analysis_cache[ $key ];
		}

		if ( '' === $code || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			self::$head_analysis_cache[ $key ] = $analysis;

			return $analysis;
		}

		$processor = new WP_HTML_Tag_Processor( $code );

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();

			if ( 'TITLE' === $tag ) {
				if ( 1 === preg_match( '/\S/u', $processor->get_modifiable_text() ) ) {
					$analysis['title'] = true;
				}

				continue;
			}

			if ( 'META' === $tag ) {
				$name = $processor->get_attribute( 'property' );

				if ( ! is_string( $name ) || '' === trim( $name ) ) {
					$name = $processor->get_attribute( 'name' );
				}

				if ( is_string( $name ) && '' !== trim( $name ) ) {
					$name                      = strtolower( trim( $name ) );
					$content                   = $processor->get_attribute( 'content' );
					$analysis['meta'][ $name ] = is_string( $content ) ? $content : '';
				}

				continue;
			}

			if ( 'LINK' === $tag ) {
				$rel  = $processor->get_attribute( 'rel' );
				$href = $processor->get_attribute( 'href' );

				if ( is_string( $rel ) && is_string( $href ) ) {
					$rels = preg_split( '/\s+/', strtolower( trim( $rel ) ) );

					if ( is_array( $rels ) && in_array( 'canonical', $rels, true ) ) {
						$analysis['canonical_url'] = self::sanitize_social_url( $href );
					}
				}

				continue;
			}

			if ( 'SCRIPT' !== $tag ) {
				continue;
			}

			$type = $processor->get_attribute( 'type' );

			if ( ! is_string( $type ) || 'application/ld+json' !== strtolower( trim( $type ) ) ) {
				continue;
			}

			$data = json_decode( trim( $processor->get_modifiable_text() ), true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) {
				self::collect_schema_claims( $data, $analysis );
			}
		}

		self::$head_analysis_cache[ $key ] = $analysis;

		return $analysis;
	}

	private static function collect_schema_claims( $data, &$analysis ): void {
		$nodes = wp_is_numeric_array( $data ) ? $data : array( $data );

		if ( ! wp_is_numeric_array( $data ) && isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			$nodes = array_merge( $nodes, $data['@graph'] );
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}

			$types = array();
			$candidates = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );

			foreach ( $candidates as $candidate ) {
				$type = self::normalize_schema_type( $candidate );

				if ( '' !== $type ) {
					$types[]                          = $type;
					$analysis['schema_types'][ $type ] = true;
				}
			}

			if ( empty( $types ) || ! isset( $node['@id'] ) || ! is_string( $node['@id'] ) ) {
				continue;
			}

			$id = self::sanitize_social_url( $node['@id'] );

			if ( '' === $id ) {
				continue;
			}

			foreach ( $types as $type ) {
				if ( ! isset( $analysis['schema_ids'][ $type ] ) ) {
					$analysis['schema_ids'][ $type ] = $id;
				}
			}
		}
	}

	private static function normalize_schema_type( $type ): string {
		if ( ! is_string( $type ) ) {
			return '';
		}

		$type = preg_replace( '~^https?://schema\.org/~i', '', trim( $type ) );
		$type = is_string( $type ) ? preg_replace( '~^schema:~i', '', $type ) : '';

		return is_string( $type ) ? strtolower( trim( $type ) ) : '';
	}

	private static function has_manual_schema_type( $post_id, $types ): bool {
		$analysis = self::get_head_analysis( $post_id );

		foreach ( $types as $type ) {
			if ( ! empty( $analysis['schema_types'][ strtolower( $type ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function get_manual_schema_id( $post_id, $types ): string {
		$analysis = self::get_head_analysis( $post_id );

		foreach ( $types as $type ) {
			$type = strtolower( $type );

			if ( ! empty( $analysis['schema_ids'][ $type ] ) ) {
				return $analysis['schema_ids'][ $type ];
			}
		}

		return '';
	}

	private static function get_identity_schema_types(): array {
		$types = array_merge( array( 'organization', 'person' ), array_keys( self::get_local_business_types() ) );

		return array_values( array_unique( array_map( 'strtolower', $types ) ) );
	}

	private static function get_posts_page_id(): int {
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return 0;
		}

		return absint( get_option( 'page_for_posts' ) );
	}
}
