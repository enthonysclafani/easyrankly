<?php
/**
 * Open Graph and Twitter card output.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the automatic social title used for singular content in simplified mode.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function easyrankly_get_simplified_social_title( int $post_id ): string {
	$title = easyrankly_get_post_meta_string( $post_id, 'title' );

	if ( '' !== $title ) {
		$title = easyrankly_replace_variables( $title, $post_id, array( 'seo_title' ) );
	} else {
		$title = get_the_title( $post_id );
	}

	return easyrankly_normalize_seo_text( $title );
}

/**
 * Returns the automatic social description used for singular content in simplified mode.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function easyrankly_get_simplified_social_description( int $post_id ): string {
	$description = easyrankly_get_post_meta_string( $post_id, 'description' );

	if ( '' !== $description ) {
		$description = easyrankly_replace_variables( $description, $post_id, array( 'meta_description' ) );
	} else {
		$post = get_post( $post_id );

		if ( $post instanceof WP_Post ) {
			$description = has_excerpt( $post ) ? get_the_excerpt( $post ) : excerpt_remove_blocks( $post->post_content );
		}
	}

	return easyrankly_normalize_seo_text( $description );
}

/**
 * Renders Open Graph and Twitter meta tags.
 *
 * @return void
 */
function easyrankly_render_opengraph_tags(): void {
	$title         = easyrankly_get_og_title();
	$description   = easyrankly_get_og_description();
	$url           = easyrankly_get_canonical();
	$image         = easyrankly_get_og_image();
	$twitter_image = easyrankly_get_twitter_image( $image );
	$twitter_title = easyrankly_get_twitter_title( $title );
	$twitter_desc  = easyrankly_get_twitter_description( $description );
	$twitter_site  = easyrankly_get_twitter_site();
	$type          = is_singular( 'post' ) ? 'article' : 'website';

	if ( is_singular() && 'product' === get_post_type() ) {
		$type = 'product';
	}

	$tags = array(
		'og:locale'           => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		'og:site_name'        => get_bloginfo( 'name' ),
		'og:type'             => $type,
		'og:title'            => $title,
		'og:description'      => $description,
		'og:url'              => $url,
		'og:image'            => $image,
		'twitter:card'        => easyrankly_get_twitter_card_type(),
		'twitter:site'        => $twitter_site,
		'twitter:title'       => $twitter_title,
		'twitter:description' => $twitter_desc,
		'twitter:image'       => $twitter_image,
	);

	/**
	 * Filters Open Graph and Twitter tags.
	 *
	 * @param array<string,string> $tags Tags keyed by property/name.
	 */
	$tags = apply_filters( 'easyrankly_opengraph_tags', array_filter( $tags ) );

	foreach ( $tags as $property => $content ) {
		if ( '' === (string) $content ) {
			continue;
		}

		$attribute = str_starts_with( (string) $property, 'twitter:' ) ? 'name' : 'property';

		printf(
			'<meta %1$s="%2$s" content="%3$s">' . "\n",
			esc_attr( $attribute ),
			esc_attr( (string) $property ),
			esc_attr( (string) $content )
		);
	}
}

/**
 * Resolves a social title or description through the shared fallback chain.
 *
 * Order: simplified-mode automatic value, per-content meta, special-page
 * template, global default template, then the caller-provided fallback.
 *
 * @param string $meta_key    Per-content meta key (without plugin prefix).
 * @param string $setting_key Global default setting key.
 * @param string $fallback    Final fallback value.
 * @param int    $limit       Character limit.
 * @return string
 */
function easyrankly_resolve_social_text( string $meta_key, string $setting_key, string $fallback, int $limit ): string {
	$is_title = str_contains( $meta_key, 'title' );
	$value    = '';

	if ( is_singular() && (bool) easyrankly_get_setting( 'simplified_mode', 1 ) ) {
		$value = $is_title
			? easyrankly_get_simplified_social_title( get_queried_object_id() )
			: easyrankly_get_simplified_social_description( get_queried_object_id() );
	} elseif ( is_singular() ) {
		$post_id = get_queried_object_id();
		$value   = easyrankly_get_post_meta_string( $post_id, $meta_key );

		if ( '' !== $value ) {
			$value = easyrankly_replace_variables( $value, $post_id );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$value = easyrankly_get_term_meta_string( $term->term_id, $meta_key );

			if ( '' !== $value ) {
				$value = easyrankly_replace_variables( $value );
			}
		}
	}

	if ( '' === $value ) {
		$special_key = easyrankly_current_special_page_key();

		if ( '' !== $special_key ) {
			$value = easyrankly_get_global_entity_meta( 'global_special_meta', $special_key, $is_title ? 'title' : 'description' );

			if ( '' !== $value ) {
				$value = easyrankly_replace_variables( $value, 0, array( $is_title ? 'seo_title' : 'meta_description' ) );
			}
		}
	}

	if ( '' === $value ) {
		$value = (string) easyrankly_get_setting( $setting_key, '' );

		if ( '' !== $value ) {
			$value = easyrankly_replace_variables( $value );
		}
	}

	if ( '' === $value ) {
		$value = $fallback;
	}

	return easyrankly_trim_text( $value, $limit );
}

/**
 * Returns the computed Open Graph title.
 *
 * @return string
 */
function easyrankly_get_og_title(): string {
	$title = easyrankly_resolve_social_text( 'og_title', 'default_og_title', easyrankly_get_title(), 60 );

	/**
	 * Filters the computed Open Graph title.
	 *
	 * @param string $title Computed Open Graph title.
	 */
	return (string) apply_filters( 'easyrankly_og_title', $title );
}

/**
 * Returns the computed Open Graph description.
 *
 * @return string
 */
function easyrankly_get_og_description(): string {
	$description = easyrankly_resolve_social_text( 'og_description', 'default_og_description', easyrankly_get_description(), 200 );

	/**
	 * Filters the computed Open Graph description.
	 *
	 * @param string $description Computed Open Graph description.
	 */
	return (string) apply_filters( 'easyrankly_og_description', $description );
}

/**
 * Returns the computed X/Twitter title.
 *
 * @param string $fallback Fallback title.
 * @return string
 */
function easyrankly_get_twitter_title( string $fallback = '' ): string {
	$title = easyrankly_resolve_social_text( 'twitter_title', 'default_twitter_title', $fallback, 70 );

	/**
	 * Filters the computed X/Twitter title.
	 *
	 * @param string $title Computed X/Twitter title.
	 */
	return (string) apply_filters( 'easyrankly_twitter_title', $title );
}

/**
 * Returns the computed X/Twitter description.
 *
 * @param string $fallback Fallback description.
 * @return string
 */
function easyrankly_get_twitter_description( string $fallback = '' ): string {
	$description = easyrankly_resolve_social_text( 'twitter_description', 'default_twitter_description', $fallback, 200 );

	/**
	 * Filters the computed X/Twitter description.
	 *
	 * @param string $description Computed X/Twitter description.
	 */
	return (string) apply_filters( 'easyrankly_twitter_description', $description );
}

/**
 * Returns the selected X/Twitter card type.
 *
 * @return string
 */
function easyrankly_get_twitter_card_type(): string {
	$card_type = '';

	if ( is_singular() && ! (bool) easyrankly_get_setting( 'simplified_mode', 1 ) ) {
		$card_type = easyrankly_get_post_meta_string( get_queried_object_id(), 'twitter_card_type' );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$card_type = easyrankly_get_term_meta_string( $term->term_id, 'twitter_card_type' );
		}
	}

	if ( ! in_array( $card_type, array( 'summary', 'summary_large_image' ), true ) ) {
		$card_type = 'summary_large_image';
	}

	/**
	 * Filters the X/Twitter card type.
	 *
	 * @param string $card_type X/Twitter card type.
	 */
	return (string) apply_filters( 'easyrankly_twitter_card_type', $card_type );
}

/**
 * Returns the configured X/Twitter site handle.
 *
 * @return string
 */
function easyrankly_get_twitter_site(): string {
	$site = trim( (string) easyrankly_get_setting( 'twitter_site', '' ) );

	if ( '' !== $site && '@' !== $site[0] ) {
		$site = '@' . $site;
	}

	/**
	 * Filters the X/Twitter site handle.
	 *
	 * @param string $site X/Twitter site handle.
	 */
	return (string) apply_filters( 'easyrankly_twitter_site', $site );
}

/**
 * Returns the best available X/Twitter image URL.
 *
 * @param string $fallback Fallback image URL.
 * @return string
 */
function easyrankly_get_twitter_image( string $fallback = '' ): string {
	$image = '';

	if ( is_singular() && ! (bool) easyrankly_get_setting( 'simplified_mode', 1 ) ) {
		$post_id   = get_queried_object_id();
		$image     = easyrankly_get_post_meta_string( $post_id, 'social_image_url' );
		$custom_id = absint( get_post_meta( $post_id, '_easyrankly_twitter_image_id', true ) );

		if ( '' !== $image ) {
			$image = esc_url_raw( easyrankly_replace_variables( $image, $post_id ) );
		}

		if ( '' === $image && $custom_id > 0 ) {
			$image = easyrankly_get_image_url( $custom_id, 'full' );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$image = easyrankly_get_term_meta_string( $term->term_id, 'social_image_url' );

			if ( '' !== $image ) {
				$image = esc_url_raw( easyrankly_replace_variables( $image ) );
			}
		}
	}

	if ( '' === $image ) {
		$image = $fallback;
	}

	/**
	 * Filters the X/Twitter image URL.
	 *
	 * @param string $image X/Twitter image URL.
	 */
	return (string) apply_filters( 'easyrankly_twitter_image', $image );
}

/**
 * Renders the oEmbed JSON discovery link.
 *
 * Always active on every public page unless the Bloat tab removes oEmbed.
 *
 * @return void
 */
function easyrankly_render_oembed_link(): void {
	$settings = easyrankly_get_settings();

	// Honour the Bloat tab: if oEmbed removal is active, emit nothing.
	if ( ! empty( $settings['bloat_remove_oembed'] ) ) {
		return;
	}

	// Suppress the native WP discovery (runs at priority 10, JSON + XML) so
	// that our single JSON-only link does not appear twice on singular pages.
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

	$canonical = easyrankly_get_canonical();

	if ( '' === $canonical ) {
		return;
	}

	printf(
		'<link rel="alternate" type="application/json+oembed" href="%s">' . "\n",
		esc_url( get_oembed_endpoint_url( $canonical, 'json' ) )
	);
}

/**
 * Returns the best available Open Graph image URL.
 *
 * @return string
 */
function easyrankly_get_og_image(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$image = '';

	if ( is_singular() ) {
		$post_id     = get_queried_object_id();
		$custom_id   = absint( get_post_meta( $post_id, '_easyrankly_og_image_id', true ) );
		$featured_id = get_post_thumbnail_id( $post_id );
		$image       = (bool) easyrankly_get_setting( 'simplified_mode', 1 ) ? '' : easyrankly_get_post_meta_string( $post_id, 'social_image_url' );

		if ( '' !== $image ) {
			$image = esc_url_raw( easyrankly_replace_variables( $image, $post_id ) );
		}

		if ( '' === $image && $custom_id > 0 && ! (bool) easyrankly_get_setting( 'simplified_mode', 1 ) ) {
			$image = easyrankly_get_image_url( $custom_id, 'full' );
		}

		if ( '' === $image && $featured_id > 0 ) {
			$image = easyrankly_get_image_url( (int) $featured_id, 'full' );
		}

		if ( '' === $image ) {
			$content_images = easyrankly_get_post_content_image_urls( $post_id );
			$image          = $content_images[0] ?? '';
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$image = easyrankly_get_term_meta_string( $term->term_id, 'social_image_url' );

			if ( '' !== $image ) {
				$image = esc_url_raw( easyrankly_replace_variables( $image ) );
			}
		}
	}

	if ( '' === $image ) {
		$image = esc_url_raw( easyrankly_replace_variables( (string) easyrankly_get_setting( 'default_social_image_url', '' ) ) );
	}

	if ( '' === $image ) {
		$image = easyrankly_get_image_url( absint( easyrankly_get_setting( 'default_og_image', 0 ) ), 'full' );
	}

	if ( '' === $image ) {
		$image = easyrankly_get_organization_logo_url();
	}

	/**
	 * Filters the Open Graph image URL.
	 *
	 * @param string $image Image URL.
	 */
	$resolved = (string) apply_filters( 'easyrankly_og_image', $image );

	return $resolved;
}
