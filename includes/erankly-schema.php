<?php

defined( 'ABSPATH' ) || exit;

trait ERankly_Schema {

	private static function print_json_ld( $schema ): void {
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( is_string( $json ) && '' !== $json ) {
			wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Prints one minimal Schema.org graph for the current request.
	 *
	 * Valid JSON-LD supplied through Custom code always owns the matching node.
	 *
	 * @return void
	 */
	public static function print_schema_graph(): void {
		$post_id = self::get_singular_post_id();
		$nodes   = array();
		$website = self::get_website_schema();
		$article = self::get_article_schema();
		$crumbs  = self::get_breadcrumb_schema();

		if ( ! empty( $website ) ) {
			$nodes[] = $website;
		}

		if (
			( ! empty( $website ) || ! empty( $article ) || self::is_business_identity_context() )
			&& ! self::has_manual_schema_type( $post_id, self::get_identity_schema_types() )
		) {
			$identity = self::get_site_identity_schema();

			if ( ! empty( $identity ) ) {
				$nodes[] = $identity;
			}
		}

		if ( ! empty( $article ) ) {
			$nodes[] = $article;
		}

		if ( ! empty( $crumbs ) ) {
			$nodes[] = $crumbs;
		}

		if ( empty( $nodes ) ) {
			return;
		}

		self::print_json_ld(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			)
		);
	}

	private static function get_website_schema(): array {
		$post_id = self::get_singular_post_id();

		if (
			! is_front_page()
			|| ! self::is_public_request()
			|| self::has_manual_schema_type( $post_id, array( 'website' ) )
		) {
			return array();
		}

		if ( $post_id && self::is_noindex( $post_id ) ) {
			return array();
		}

		$name = self::normalize_social_text( get_bloginfo( 'name' ) );
		$url  = is_singular() && $post_id ? wp_get_canonical_url( $post_id ) : self::resolve_non_singular_url( home_url( '/' ) );
		$url  = self::sanitize_social_url( $url );

		if ( '' === $name || '' === $url ) {
			return array();
		}

		$schema = array(
			'@id'   => home_url( '/#website' ),
			'@type' => 'WebSite',
			'name'  => $name,
			'url'   => $url,
		);
		$publisher = self::resolve_identity_reference( $post_id, self::get_identity_schema_types() );

		if ( ! empty( $publisher ) ) {
			$schema['publisher'] = $publisher;
		}

		return $schema;
	}

	private static function get_article_schema(): array {
		$post_id = self::get_article_post_id();

		if ( ! $post_id || self::has_manual_schema_type( $post_id, array( 'article', 'blogposting', 'newsarticle' ) ) ) {
			return array();
		}

		$post  = get_post( $post_id );
		$dates = self::get_article_dates( $post );
		$url   = wp_get_canonical_url( $post_id );

		if ( ! $post || empty( $dates['published'] ) || ! is_string( $url ) || '' === $url ) {
			return array();
		}

		$headline = self::normalize_social_text( get_the_title( $post_id ) );

		if ( '' === $headline ) {
			return array();
		}

		$schema = array(
			'@type'            => 'BlogPosting',
			'@id'              => $url . '#article',
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $url,
			),
			'headline'         => $headline,
			'url'              => $url,
			'datePublished'    => $dates['published'],
		);
		$publisher = self::resolve_identity_reference( $post_id, self::get_identity_schema_types() );

		if ( ! empty( $publisher ) ) {
			$schema['publisher'] = $publisher;
		}

		if ( ! empty( $dates['modified'] ) ) {
			$schema['dateModified'] = $dates['modified'];
		}

		$author = self::get_author_schema( $post->post_author );

		if ( ! empty( $author ) ) {
			$schema['author'] = $author;
		}

		$image = self::get_featured_image_data( $post_id );

		if ( ! empty( $image['url'] ) ) {
			$schema['image'] = $image['url'];
		}

		return $schema;
	}

	private static function resolve_identity_reference( $post_id, $types ): array {
		$manual_id = self::get_manual_schema_id( $post_id, $types );

		if ( '' !== $manual_id ) {
			return array( '@id' => $manual_id );
		}

		if ( self::has_manual_schema_type( $post_id, $types ) ) {
			return array();
		}

		$identity = self::get_site_identity_schema();

		return ! empty( $identity['@id'] ) && is_string( $identity['@id'] )
			? array( '@id' => $identity['@id'] )
			: array();
	}

	private static function get_site_identity_schema(): array {
		$key = self::get_cache_context_key();

		if ( isset( self::$site_identity_schema_cache[ $key ] ) ) {
			return self::$site_identity_schema_cache[ $key ];
		}

		$business = self::get_business_schema();

		if ( ! empty( $business ) ) {
			self::$site_identity_schema_cache[ $key ] = $business;

			return $business;
		}

		$settings = self::get_site_identity_settings();
		$home_url = home_url( '/' );
		$profiles = self::get_social_settings()['profiles'];
		$schema   = array();

		if ( 'person' === $settings['type'] && $settings['person_user_id'] ) {
			$user = get_userdata( $settings['person_user_id'] );

			if ( $user ) {
				$name = self::normalize_social_text( $user->display_name );

				if ( '' !== $name ) {
					$profiles = array_values( array_unique( array_merge( $profiles, self::get_author_profile_urls( $user->ID ) ) ) );
					$schema   = array(
						'@id'   => $home_url . '#identity',
						'@type' => 'Person',
						'name'  => $name,
						'url'   => $home_url,
					);
					$avatar = self::sanitize_social_url( get_avatar_url( $user->ID, array( 'size' => 512 ) ) );

					if ( '' !== $avatar ) {
						$schema['image'] = array(
							'@type' => 'ImageObject',
							'url'   => $avatar,
						);
					}
				}
			}
		}

		if ( empty( $schema ) ) {
			$name = self::normalize_social_text( get_bloginfo( 'name' ) );

			if ( '' === $name ) {
				self::$site_identity_schema_cache[ $key ] = array();

				return array();
			}

			$schema = array(
				'@id'   => $home_url . '#identity',
				'@type' => 'Organization',
				'name'  => $name,
				'url'   => $home_url,
			);
			$logo = self::get_site_logo_data();

			if ( ! empty( $logo['url'] ) ) {
				$schema['logo'] = array_filter(
					array(
						'@id'    => $home_url . '#logo',
						'@type'  => 'ImageObject',
						'height' => $logo['height'],
						'url'    => $logo['url'],
						'width'  => $logo['width'],
					)
				);
			}
		}

		if ( ! empty( $profiles ) ) {
			$schema['sameAs'] = array_values( array_unique( $profiles ) );
		}

		self::$site_identity_schema_cache[ $key ] = $schema;

		return $schema;
	}

	private static function get_site_logo_data(): array {
		$logo_id = absint( get_option( 'site_logo' ) );

		if ( ! $logo_id ) {
			$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		}

		if ( ! $logo_id ) {
			$logo_id = absint( get_option( 'site_icon' ) );
		}

		return $logo_id ? self::get_attachment_social_image_data( $logo_id ) : self::get_empty_social_image_data();
	}

	private static function get_author_schema( $author_id ): array {
		$author_id = absint( $author_id );
		$author    = $author_id ? get_userdata( $author_id ) : false;

		if ( ! $author ) {
			return array();
		}

		$identity_settings = self::get_site_identity_settings();
		$business_settings = self::get_business_profile();

		if (
			! self::is_business_profile_ready( $business_settings )
			&& 'person' === $identity_settings['type']
			&& $author_id === $identity_settings['person_user_id']
		) {
			return self::resolve_identity_reference( self::get_singular_post_id(), array( 'person' ) );
		}

		$name = self::normalize_social_text( $author->display_name );

		if ( '' === $name ) {
			return array();
		}

		$url    = get_author_posts_url( $author_id );
		$schema = array(
			'@id'   => $url . '#person',
			'@type' => 'Person',
			'name'  => $name,
			'url'   => $url,
		);
		$profiles = self::get_author_profile_urls( $author_id );

		if ( ! empty( $profiles ) ) {
			$schema['sameAs'] = $profiles;
		}

		return $schema;
	}

	private static function get_author_profile_urls( $author_id ): array {
		$profiles = array();
		$user_url = self::sanitize_social_url( get_the_author_meta( 'user_url', $author_id ) );

		if ( '' !== $user_url ) {
			$profiles[] = $user_url;
		}

		$handle = self::normalize_twitter_handle( get_the_author_meta( self::TWITTER_USER_META_KEY, $author_id ) );

		if ( '' !== $handle ) {
			$profiles[] = 'https://x.com/' . ltrim( $handle, '@' );
		}

		return array_values( array_unique( $profiles ) );
	}

	private static function get_article_post_id(): int {
		if (
			! is_singular( 'post' )
			|| ! self::is_public_request()
		) {
			return 0;
		}

		$post_id = self::get_singular_post_id();
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return 0;
		}

		if ( self::is_noindex( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	private static function get_article_dates( $post ): array {
		$empty = array(
			'modified'  => '',
			'published' => '',
		);

		if ( ! $post instanceof WP_Post ) {
			return $empty;
		}

		$published = get_post_datetime( $post, 'date', 'local' );
		$modified  = get_post_datetime( $post, 'modified', 'local' );

		if ( ! $published ) {
			return $empty;
		}

		$published_timestamp = $published->getTimestamp();
		$modified_timestamp  = $modified ? $modified->getTimestamp() : 0;

		$dates = array(
			'modified'  => '',
			'published' => $published->format( DATE_W3C ),
		);

		if ( $modified && $modified_timestamp > $published_timestamp ) {
			$dates['modified'] = $modified->format( DATE_W3C );
		}

		return $dates;
	}

	private static function get_breadcrumb_schema(): array {
		$post_id = self::get_breadcrumb_post_id();

		if ( ! $post_id || self::has_manual_schema_type( $post_id, array( 'breadcrumblist' ) ) ) {
			return array();
		}

		$items = self::get_breadcrumb_items( $post_id );

		if ( ! is_array( $items ) || count( $items ) < 2 ) {
			return array();
		}

		$list_items = array();
		$last_index = count( $items ) - 1;

		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['label'] ) || ! is_scalar( $item['label'] ) ) {
				return array();
			}

			$name = trim(
				wp_specialchars_decode(
					wp_strip_all_tags( (string) $item['label'], true ),
					ENT_QUOTES
				)
			);

			if ( '' === $name ) {
				return array();
			}

			$list_item = array(
				'@type'    => 'ListItem',
				'name'     => $name,
				'position' => $index + 1,
			);

			// Google treats the current page URL as optional for the final item.
			if ( $index < $last_index ) {
				if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
					return array();
				}

				$url = sanitize_url( $item['url'] );

				if ( '' === $url ) {
					return array();
				}

				$list_item['item'] = $url;
			}

			$list_items[] = $list_item;
		}

		$url    = wp_get_canonical_url( $post_id );
		$schema = array(
			'@id'             => is_string( $url ) && '' !== $url ? $url . '#breadcrumb' : home_url( '/#breadcrumb' ),
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list_items,
		);

		return $schema;
	}

	private static function get_breadcrumb_post_id(): int {
		if (
			! is_singular()
			|| is_front_page()
			|| ! self::is_public_request()
		) {
			return 0;
		}

		$post_id = self::get_singular_post_id();
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return 0;
		}

		$post_type = get_post_type_object( $post->post_type );

		if ( ! $post_type || ! $post_type->public ) {
			return 0;
		}

		if ( self::is_noindex( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	private static function get_breadcrumb_items( $post_id ): array {
		$core_functions = array(
			'block_core_breadcrumbs_create_page_number_item',
			'block_core_breadcrumbs_get_hierarchical_post_type_breadcrumbs',
			'block_core_breadcrumbs_get_post_title',
			'block_core_breadcrumbs_get_terms_breadcrumbs',
		);

		foreach ( $core_functions as $function ) {
			if ( ! function_exists( $function ) ) {
				return array();
			}
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$items = array(
			array(
				// Match the visible core Breadcrumbs block translation.
				'label' => __( 'Home' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				'url'   => home_url( '/' ),
			),
		);
		$path_post      = $post;
		$path_post_type = $post->post_type;

		if ( ! is_post_type_hierarchical( $path_post_type ) && $post->post_parent ) {
			$parent_post = get_post( $post->post_parent );

			if ( $parent_post ) {
				$path_post      = $parent_post;
				$path_post_type = $parent_post->post_type;
			}
		}

		$post_type_object = get_post_type_object( $path_post_type );
		$archive_link     = get_post_type_archive_link( $path_post_type );
		$page_for_posts   = get_option( 'page_for_posts' );

		if (
			$post_type_object
			&& $archive_link
			&& untrailingslashit( home_url() ) !== untrailingslashit( $archive_link )
		) {
			$label = $post_type_object->labels->archives;

			if ( 'post' === $path_post_type && $page_for_posts ) {
				$label = block_core_breadcrumbs_get_post_title( $page_for_posts );
			}

			$items[] = array(
				'label' => $label,
				'url'   => $archive_link,
			);
		}

		$show_terms = ! is_post_type_hierarchical( $path_post_type ) && ! $path_post->post_parent;

		if ( $show_terms ) {
			$items = array_merge(
				$items,
				block_core_breadcrumbs_get_terms_breadcrumbs( $path_post->ID, $path_post_type )
			);
		} else {
			$items = array_merge(
				$items,
				block_core_breadcrumbs_get_hierarchical_post_type_breadcrumbs( $path_post->ID )
			);
		}

		$is_paged = (int) get_query_var( 'page' ) > 1 || (int) get_query_var( 'cpage' ) > 1;
		$title    = block_core_breadcrumbs_get_post_title( $post );

		if ( $is_paged ) {
			$items[] = array(
				'label' => $title,
				'url'   => get_permalink( $post ),
			);
			$items[] = block_core_breadcrumbs_create_page_number_item(
				(int) get_query_var( 'cpage' ) > 1 ? 'cpage' : 'page'
			);
		} else {
			$items[] = array( 'label' => $title );
		}

		// Reuse WordPress core's Breadcrumbs hook to match the visible block.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return apply_filters( 'block_core_breadcrumbs_items', $items );
	}
}
