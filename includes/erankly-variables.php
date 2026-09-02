<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Variables {

	/**
	 * Returns the variables Custom code can reference, in display order.
	 *
	 * The keys are the token names; the labels document each source on the
	 * editor's Variables tab.
	 *
	 * @return array<string, string>
	 */
	private static function get_code_variable_definitions(): array {
		return array(
			'title'           => __( 'Title of the current content or archive.', 'easyrankly' ),
			'description'     => __( 'Resolved description: the Search engines description, then the manual excerpt.', 'easyrankly' ),
			'excerpt'         => __( 'Manually entered WordPress excerpt.', 'easyrankly' ),
			'siteName'        => __( 'Site title.', 'easyrankly' ),
			'siteDescription' => __( 'Site tagline.', 'easyrankly' ),
			'url'             => __( 'Canonical URL of the current view.', 'easyrankly' ),
			'siteUrl'         => __( 'Site home URL.', 'easyrankly' ),
			'image'           => __( 'Sharing image: the featured image, then the default social image.', 'easyrankly' ),
			'author'          => __( 'Display name of the content author.', 'easyrankly' ),
			'published'       => __( 'Publication date in W3C format.', 'easyrankly' ),
			'modified'        => __( 'Last modification date in W3C format.', 'easyrankly' ),
			'postType'        => __( 'Singular label of the content type.', 'easyrankly' ),
			'category'        => __( 'First editorial category, excluding the default one.', 'easyrankly' ),
			'tags'            => __( 'Comma-separated tags.', 'easyrankly' ),
			'locale'          => __( 'Locale of the current request.', 'easyrankly' ),
			'searchQuery'     => __( 'Search term on search results.', 'easyrankly' ),
			'page'            => __( 'Current page number in paginated views.', 'easyrankly' ),
		);
	}

	private static function get_site_code_variables(): array {
		return array(
			'siteName'        => self::normalize_social_text( get_bloginfo( 'name' ) ),
			'siteDescription' => self::normalize_social_text( get_bloginfo( 'description' ) ),
			'siteUrl'         => home_url( '/' ),
			'locale'          => get_locale(),
		);
	}

	/**
	 * Resolves the variables a single piece of content owns.
	 *
	 * Every value comes from a resolver EasyRankly already uses for its automatic
	 * metadata, so a template prints what the plugin would print on its own.
	 *
	 * @param int $post_id Content ID, or 0 for non-singular views.
	 * @return array<string, string>
	 */
	private static function get_post_code_variables( $post_id ): array {
		$post_id   = absint( $post_id );
		$variables = array(
			'author'      => '',
			'category'    => '',
			'description' => '',
			'excerpt'     => '',
			'image'       => '',
			'modified'    => '',
			'postType'    => '',
			'published'   => '',
			'tags'        => '',
			'title'       => '',
			'url'         => '',
		);

		if ( ! $post_id ) {
			return $variables;
		}

		$canonical_url = wp_get_canonical_url( $post_id );
		$published     = get_post_time( DATE_W3C, false, $post_id );
		$modified      = get_post_modified_time( DATE_W3C, false, $post_id );
		$post_type     = get_post_type_object( get_post_type( $post_id ) );
		$excerpt       = get_post_field( 'post_excerpt', $post_id, 'raw' );

		$variables['author']      = self::normalize_social_text(
			get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) )
		);
		$variables['category']    = self::get_primary_category_name( $post_id );
		$variables['description'] = self::get_content_description( $post_id );
		$variables['excerpt']     = is_string( $excerpt ) ? self::normalize_social_text( strip_shortcodes( $excerpt ) ) : '';
		$variables['image']       = self::get_social_image_data( $post_id )['url'];
		$variables['modified']    = is_string( $modified ) ? $modified : '';
		$variables['postType']    = $post_type instanceof WP_Post_Type
			? self::normalize_social_text( $post_type->labels->singular_name )
			: '';
		$variables['published']   = is_string( $published ) ? $published : '';
		$variables['tags']        = self::get_post_tag_names( $post_id );
		$variables['title']       = self::normalize_social_text( get_the_title( $post_id ) );
		$variables['url']         = is_string( $canonical_url ) ? $canonical_url : '';

		return $variables;
	}

	/**
	 * Returns the first editorial category name, skipping the default category.
	 *
	 * @param int $post_id Content ID.
	 * @return string
	 */
	private static function get_primary_category_name( $post_id ): string {
		$categories          = get_the_category( absint( $post_id ) );
		$default_category_id = absint( get_option( 'default_category' ) );

		if ( ! is_array( $categories ) ) {
			return '';
		}

		foreach ( $categories as $category ) {
			if (
				! $category instanceof WP_Term
				|| $default_category_id === (int) $category->term_id
				|| empty( $category->name )
			) {
				continue;
			}

			return self::normalize_social_text( $category->name );
		}

		return '';
	}

	private static function get_post_tag_names( $post_id ): string {
		$tags  = get_the_tags( absint( $post_id ) );
		$names = array();

		if ( ! is_array( $tags ) ) {
			return '';
		}

		foreach ( $tags as $tag ) {
			if ( is_object( $tag ) && ! empty( $tag->name ) ) {
				$names[] = self::normalize_social_text( $tag->name );
			}
		}

		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * Resolves every variable for the current request.
	 *
	 * @return array<string, string>
	 */
	private static function get_code_variables(): array {
		$key = self::get_cache_context_key() . '|' . self::get_singular_post_id();

		if ( isset( self::$code_variables_cache[ $key ] ) ) {
			return self::$code_variables_cache[ $key ];
		}

		$post_id   = self::get_singular_post_id();
		$variables = array_merge(
			self::get_site_code_variables(),
			self::get_post_code_variables( $post_id )
		);

		$variables['description'] = self::normalize_social_text( self::get_request_description() );
		$variables['searchQuery'] = is_search() ? self::normalize_social_text( get_search_query( false ) ) : '';
		$variables['page']        = (string) max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

		if ( ! is_singular() ) {
			$context = self::get_social_context();

			$variables['title'] = isset( $context['title'] ) ? self::normalize_social_text( $context['title'] ) : '';
			$variables['url']   = isset( $context['url'] ) ? self::sanitize_social_url( $context['url'] ) : '';
			$variables['image'] = self::get_social_image_data( 0 )['url'];
		}

		self::$code_variables_cache[ $key ] = self::finalize_code_variables( $variables );

		return self::$code_variables_cache[ $key ];
	}

	/**
	 * Applies the site-wide fallbacks and reduces every value to plain text.
	 *
	 * @param array<string, string> $variables Resolved variables.
	 * @return array<string, string>
	 */
	private static function finalize_code_variables( $variables ): array {
		if ( '' === $variables['title'] ) {
			$variables['title'] = $variables['siteName'];
		}

		if ( '' === $variables['url'] ) {
			$variables['url'] = $variables['siteUrl'];
		}

		$resolved = array();

		foreach ( $variables as $name => $value ) {
			$resolved[ $name ] = self::decode_variable_value( (string) $value );
		}

		return $resolved;
	}

	/**
	 * Reduces a value to the plain text the escaping layers expect.
	 *
	 * WordPress hands out display-ready titles and terms, so their character
	 * references have to be decoded before the HTML API encodes them again.
	 * Escaping always follows, which keeps decoded markup inert.
	 *
	 * @param string $value Resolved value.
	 * @return string
	 */
	private static function decode_variable_value( $value ): string {
		if ( false === strpos( $value, '&' ) ) {
			return $value;
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
	}

	/**
	 * Returns the variable reference the block editor displays.
	 *
	 * Values are resolved for the content being edited so the tab doubles as a
	 * preview of what the template will print.
	 *
	 * @param int $post_id Content being edited.
	 * @return array<int, array<string, string>>
	 */
	private static function get_code_variable_examples( $post_id ): array {
		$variables = self::finalize_code_variables(
			array_merge(
				self::get_site_code_variables(),
				self::get_post_code_variables( $post_id ),
				array(
					'page'        => '1',
					'searchQuery' => '',
				)
			)
		);
		$examples  = array();

		foreach ( self::get_code_variable_definitions() as $name => $label ) {
			$examples[] = array(
				'label' => $label,
				'token' => '{{' . $name . '}}',
				'value' => isset( $variables[ $name ] ) ? $variables[ $name ] : '',
			);
		}

		return $examples;
	}

	/**
	 * Replaces the variables inside trusted Custom code.
	 *
	 * Values are escaped for the context each token appears in, because they can
	 * carry content written by users without the unfiltered_html capability.
	 * Code without a token is returned untouched.
	 *
	 * @param string $code Combined Custom code.
	 * @return string
	 */
	private static function resolve_code( $code ): string {
		if ( ! is_string( $code ) || '' === $code || false === strpos( $code, '{{' ) ) {
			return is_string( $code ) ? $code : '';
		}

		if ( self::$is_resolving_code || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $code;
		}

		self::$is_resolving_code = true;

		try {
			$variables = self::get_code_variables();
			$processor = new WP_HTML_Tag_Processor( $code );
			$omitted   = false;

			while ( $processor->next_token() ) {
				if ( '#text' === $processor->get_token_type() ) {
					self::resolve_modifiable_text( $processor, $variables, 'text', $omitted );

					continue;
				}

				if ( '#tag' !== $processor->get_token_type() || $processor->is_tag_closer() ) {
					continue;
				}

				self::resolve_tag_attributes( $processor, $variables, $omitted );

				$tag = $processor->get_tag();

				if ( 'TITLE' === $tag || 'TEXTAREA' === $tag ) {
					self::resolve_modifiable_text( $processor, $variables, 'text', $omitted );

					continue;
				}

				if ( 'SCRIPT' === $tag && self::is_json_ld_script( $processor ) ) {
					self::resolve_modifiable_text( $processor, $variables, 'json', $omitted );
				}
			}

			$code = $processor->get_updated_html();
		} finally {
			self::$is_resolving_code = false;
		}

		return $omitted ? self::remove_omitted_lines( $code ) : $code;
	}

	private static function resolve_tag_attributes( $processor, $variables, &$omitted ): void {
		$names = $processor->get_attribute_names_with_prefix( '' );

		if ( ! is_array( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			$value = $processor->get_attribute( $name );

			if ( ! is_string( $value ) || false === strpos( $value, '{{' ) ) {
				continue;
			}

			$resolved = self::resolve_tokens( $value, $variables, 'text', $omitted );

			if ( $resolved !== $value ) {
				$processor->set_attribute( $name, $resolved );
			}
		}
	}

	private static function resolve_modifiable_text( $processor, $variables, $context, &$omitted ): void {
		$text = $processor->get_modifiable_text();

		if ( '' === $text || false === strpos( $text, '{{' ) ) {
			return;
		}

		$resolved = self::resolve_tokens( $text, $variables, $context, $omitted );

		if ( $resolved !== $text ) {
			$processor->set_modifiable_text( $resolved );
		}
	}

	private static function is_json_ld_script( $processor ): bool {
		$type = $processor->get_attribute( 'type' );

		return is_string( $type ) && 'application/ld+json' === strtolower( trim( $type ) );
	}

	/**
	 * Replaces every recognized token in one value.
	 *
	 * A value left empty by its whole fallback chain becomes the omission
	 * sentinel, so the tag can be dropped and EasyRankly's automatic metadata
	 * takes over. Unrecognized tokens stay literal.
	 *
	 * @param string                $value     Raw value.
	 * @param array<string, string> $variables Resolved variables.
	 * @param string                $context   Either text or json.
	 * @param bool                  $omitted   Set when a sentinel is written.
	 * @return string
	 */
	private static function resolve_tokens( $value, $variables, $context, &$omitted ): string {
		$replaced = false;
		$resolved = preg_replace_callback(
			'/\{\{([^{}]*)\}\}/',
			static function ( $matches ) use ( $variables, $context, &$replaced ) {
				$parts = self::parse_token_expression( $matches[1], $variables );

				if ( null === $parts ) {
					return $matches[0];
				}

				$replaced = true;

				return 'json' === $context ? self::escape_json_value( $parts ) : $parts;
			},
			$value
		);

		if ( ! is_string( $resolved ) ) {
			return $value;
		}

		if ( $replaced && 'json' !== $context && '' === trim( $resolved ) ) {
			$omitted = true;

			return self::get_omit_sentinel();
		}

		return $resolved;
	}

	/**
	 * Resolves one token's fallback chain.
	 *
	 * @param string                $expression Token contents without the braces.
	 * @param array<string, string> $variables  Resolved variables.
	 * @return string|null Resolved value, or null when the token is not recognized.
	 */
	private static function parse_token_expression( $expression, $variables ): ?string {
		$parts = array();

		if ( ! preg_match_all( '/"[^"]*"|\'[^\']*\'|[^|]+/', $expression, $matches ) ) {
			return null;
		}

		foreach ( $matches[0] as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				return null;
			}

			$quote = $part[0];

			if ( ( '"' === $quote || "'" === $quote ) && substr( $part, -1 ) === $quote && strlen( $part ) > 1 ) {
				$parts[] = substr( $part, 1, -1 );

				continue;
			}

			if ( ! array_key_exists( $part, $variables ) ) {
				return null;
			}

			$parts[] = $variables[ $part ];
		}

		foreach ( $parts as $part ) {
			if ( '' !== trim( $part ) ) {
				return $part;
			}
		}

		return '';
	}

	/**
	 * Escapes a value for use inside a JSON string literal.
	 *
	 * The flags also remove every character that could close the script element.
	 *
	 * @param string $value Resolved value.
	 * @return string
	 */
	private static function escape_json_value( $value ): string {
		$encoded = wp_json_encode(
			$value,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $encoded ) && strlen( $encoded ) > 1 ? substr( $encoded, 1, -1 ) : '';
	}

	private static function get_omit_sentinel(): string {
		if ( '' === self::$omit_sentinel ) {
			self::$omit_sentinel = 'erankly-omit-' . wp_generate_password( 20, false );
		}

		return self::$omit_sentinel;
	}

	/**
	 * Drops the lines whose only tag lost its value.
	 *
	 * A sentinel that does not stand alone on its line is simply cleared, which
	 * keeps multi-line constructs such as JSON-LD blocks intact.
	 *
	 * @param string $code Resolved Custom code.
	 * @return string
	 */
	private static function remove_omitted_lines( $code ): string {
		$sentinel = self::get_omit_sentinel();
		$segments = preg_split( '/(\R)/u', $code, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $segments ) ) {
			return str_replace( $sentinel, '', $code );
		}

		$resolved = '';

		for ( $index = 0; $index < count( $segments ); $index += 2 ) {
			$line      = $segments[ $index ];
			$separator = isset( $segments[ $index + 1 ] ) ? $segments[ $index + 1 ] : '';

			if ( false === strpos( $line, $sentinel ) ) {
				$resolved .= $line . $separator;

				continue;
			}

			if ( self::is_single_element_line( $line ) ) {
				continue;
			}

			$resolved .= str_replace( $sentinel, '', $line ) . $separator;
		}

		return $resolved;
	}

	private static function is_single_element_line( $line ): bool {
		$line = trim( $line );

		if ( '' === $line || '<' !== $line[0] || '>' !== substr( $line, -1 ) ) {
			return false;
		}

		$processor = new WP_HTML_Tag_Processor( $line );
		$openers   = 0;

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( ! $processor->is_tag_closer() ) {
				++$openers;
			}
		}

		return 1 === $openers;
	}
}
