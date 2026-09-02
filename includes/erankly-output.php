<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Output {

	private static function get_global_head_code(): string {
		return self::sanitize_raw_code( get_option( self::GLOBAL_HEAD_OPTION, '' ) );
	}

	private static function get_global_body_code( $option_name ): string {
		return self::sanitize_raw_code( get_option( $option_name, '' ) );
	}

	private static function get_head_code( $post_id ): string {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		return self::sanitize_raw_code( get_post_meta( $post_id, self::HEAD_META_KEY, true ) );
	}

	private static function get_body_code( $post_id, $meta_key ): string {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		return self::sanitize_raw_code( get_post_meta( $post_id, $meta_key, true ) );
	}

	private static function combine_code( $global_code, $post_code ): string {
		if ( '' === $global_code ) {
			return $post_code;
		}

		if ( '' === $post_code ) {
			return $global_code;
		}

		return $global_code . "\n" . $post_code;
	}

	private static function get_effective_head_code( $post_id ): string {
		$post_id = absint( $post_id );
		$key     = self::get_cache_context_key() . '|' . $post_id;

		if ( array_key_exists( $key, self::$effective_head_code_cache ) ) {
			return self::$effective_head_code_cache[ $key ];
		}

		$code = self::resolve_code(
			self::combine_code(
				self::get_global_head_code(),
				self::get_head_code( $post_id )
			)
		);

		// Variable resolution reads this method back; only the outer pass is final.
		if ( self::$is_resolving_code ) {
			return $code;
		}

		self::$effective_head_code_cache[ $key ] = $code;

		return $code;
	}

	private static function get_effective_body_code( $post_id, $global_option, $meta_key ): string {
		$post_id = absint( $post_id );
		$key     = self::get_cache_context_key() . '|' . $post_id . '|' . $global_option;

		if ( array_key_exists( $key, self::$effective_body_code_cache ) ) {
			return self::$effective_body_code_cache[ $key ];
		}

		$code = self::resolve_code(
			self::combine_code(
				self::get_global_body_code( $global_option ),
				self::get_body_code( $post_id, $meta_key )
			)
		);

		if ( self::$is_resolving_code ) {
			return $code;
		}

		self::$effective_body_code_cache[ $key ] = $code;

		return $code;
	}

	private static function get_manual_social_meta_keys( $post_id ): array {
		$analysis = self::get_head_analysis( $post_id );
		$keys     = array();

		foreach ( array_keys( $analysis['meta'] ) as $key ) {
			if ( preg_match( '/^(?:article|fb|og|twitter):/', $key ) ) {
				$keys[ $key ] = true;
			}
		}

		return $keys;
	}

	private static function is_manual_social_override( $key, $manual_keys ): bool {
		if ( isset( $manual_keys[ $key ] ) ) {
			return true;
		}

		if ( 0 === strpos( $key, 'og:image' ) ) {
			return isset( $manual_keys['og:image'] ) || isset( $manual_keys['og:image:url'] );
		}

		if ( 0 === strpos( $key, 'twitter:image' ) ) {
			return isset( $manual_keys['twitter:image'] );
		}

		return false;
	}

	/**
	 * Prints global code and, on singular views, the current content's code.
	 *
	 * @return void
	 */
	public static function print_head_code(): void {
		$post_id = self::get_singular_post_id();
		$code    = self::get_effective_head_code( $post_id );

		self::print_raw_code( $code );
	}

	/**
	 * Prints global and current-content code after the opening body tag.
	 *
	 * @return void
	 */
	public static function print_body_start_code(): void {
		$post_id = self::get_singular_post_id();
		$code    = self::get_effective_body_code(
			$post_id,
			self::GLOBAL_BODY_START_OPTION,
			self::BODY_START_META_KEY
		);

		self::print_raw_code( $code );
	}

	/**
	 * Prints global and current-content code near the closing body tag.
	 *
	 * @return void
	 */
	public static function print_body_end_code(): void {
		$post_id = self::get_singular_post_id();
		$code    = self::get_effective_body_code(
			$post_id,
			self::GLOBAL_BODY_END_OPTION,
			self::BODY_END_META_KEY
		);

		self::print_raw_code( $code );
	}

	private static function print_raw_code( $code ): void {
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return;
		}

		// Deliberately unescaped: only users with unfiltered_html can save this code.
		echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Adds per-content directives to WordPress' canonical robots tag.
	 *
	 * Selecting index leaves WordPress' site-wide privacy policy in control.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		if ( ! self::should_force_noindex() ) {
			return $robots;
		}

		unset( $robots['index'] );
		$robots['noindex'] = true;

		return $robots;
	}

	/**
	 * Adds the per-content noindex directive to the HTTP response.
	 *
	 * This mirrors the canonical robots meta tag for WordPress-rendered pages.
	 * Static files bypass WordPress and must be controlled at the web server or
	 * CDN level.
	 *
	 * @param string[] $headers HTTP headers to be sent.
	 * @return string[] Filtered HTTP headers.
	 */
	public static function filter_robots_headers( $headers ) {
		if ( ! self::should_force_noindex() ) {
			return $headers;
		}

		$robots_values = array();

		// Header names are case-insensitive, while PHP array keys are not.
		foreach ( $headers as $name => $value ) {
			if ( 'x-robots-tag' !== strtolower( (string) $name ) ) {
				continue;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$robots_values[] = trim( $value );
			}

			unset( $headers[ $name ] );
		}

		$robots_value = implode( ', ', $robots_values );

		if ( ! preg_match( '/(?:^|,)\s*noindex\s*(?=,|$)/i', $robots_value ) ) {
			$robots_value = '' === $robots_value ? 'noindex' : $robots_value . ', noindex';
		}

		$headers['X-Robots-Tag'] = $robots_value;

		return $headers;
	}

	private static function should_force_noindex(): bool {
		$post_id = self::get_visibility_post_id();

		if ( ! $post_id ) {
			return false;
		}

		$analysis = self::get_head_analysis( self::get_singular_post_id() );

		return ! array_key_exists( 'robots', $analysis['meta'] ) && self::is_noindex( $post_id );
	}

	/**
	 * Excludes content marked noindex from WordPress' native post sitemaps.
	 *
	 * The core provider uses this query for both URL lists and pagination, which
	 * keeps page counts correct without creating or rendering another sitemap.
	 *
	 * @param array  $args      Sitemap WP_Query arguments.
	 * @param string $post_type Post type being mapped.
	 * @return array Sitemap WP_Query arguments.
	 */
	public static function filter_sitemap_post_query_args( $args, $post_type ) {
		if ( ! is_array( $args ) || '' === $post_type ) {
			return $args;
		}

		$args['_erankly_exclude_noindex'] = true;
		$args['suppress_filters']          = false;

		return $args;
	}

	/**
	 * Excludes noindex content without adding postmeta joins to sitemap queries.
	 *
	 * @param string   $where Sitemap query WHERE clause.
	 * @param WP_Query $query Current query.
	 * @return string
	 */
	public static function filter_sitemap_posts_where( $where, $query ) {
		if ( ! $query instanceof WP_Query || ! $query->get( '_erankly_exclude_noindex' ) ) {
			return $where;
		}

		global $wpdb;

		return $where . $wpdb->prepare(
			' AND NOT EXISTS (
				SELECT 1 FROM %i AS erankly_visibility_meta
				WHERE erankly_visibility_meta.post_id = %i.ID
				AND erankly_visibility_meta.meta_key = %s
				AND erankly_visibility_meta.meta_value = %s
			)',
			$wpdb->postmeta,
			$wpdb->posts,
			self::VISIBILITY_META_KEY,
			'noindex'
		);
	}

	private static function get_visibility_post_id(): int {
		if ( is_singular() ) {
			return self::get_singular_post_id();
		}

		if ( ! is_home() ) {
			return 0;
		}

		return self::get_posts_page_id();
	}
}
