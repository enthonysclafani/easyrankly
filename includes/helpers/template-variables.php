<?php
/** Shared helpers: dynamic template variables. Part of the helpers.php loader; always loaded early on every request. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @param array<int,string> $exclude Variables that should not resolve for this call. */
function erankly_replace_variables( string $value, int $post_id = 0, array $exclude = array() ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	$exclude = array_fill_keys( array_map( 'strtolower', $exclude ), true );

	return (string) preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id, $exclude ): string {
			$key = strtolower( (string) $matches[1] );

			if ( isset( $exclude[ $key ] ) ) {
				return '';
			}

			return erankly_get_variable_value( $key, $post_id );
		},
		$value
	);
}

function erankly_replace_json_ld_variables( string $value, int $post_id = 0 ): string {
	if ( '' === $value || ! str_contains( $value, '{{' ) ) {
		return $value;
	}

	if ( $post_id <= 0 && is_singular() ) {
		$post_id = get_queried_object_id();
	}

	$failed   = false;
	$replaced = preg_replace_callback(
		'/{{\s*([a-z0-9_]+)\s*}}/i',
		static function ( array $matches ) use ( $post_id, &$failed ): string {
			$replacement = erankly_get_variable_value( strtolower( (string) $matches[1] ), $post_id );
			$json        = wp_json_encode(
				$replacement,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);

			if ( ! is_string( $json ) || '' === $json ) {
				$failed = true;

				return '';
			}

			return '"' === $json[0] ? substr( $json, 1, -1 ) : $json;
		},
		$value
	);

	if ( $failed || ! is_string( $replaced ) ) {
		return '';
	}

	return $replaced;
}

function erankly_get_variable_value( string $key, int $post_id = 0 ): string {
	static $resolving = array();
	static $cache     = array();

	$cache_key = $key . ':' . $post_id;

	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	if ( isset( $resolving[ $key ] ) ) {
		return '';
	}

	$resolving[ $key ] = true;
	$post              = $post_id > 0 ? get_post( $post_id ) : null;
	$post              = $post instanceof WP_Post ? $post : null;
	$queried           = get_queried_object();
	$archive_author_id = erankly_get_archive_author_id();
	$value             = erankly_resolve_variable(
		$key,
		array(
			'post'              => $post,
			'term'              => $queried instanceof WP_Term ? $queried : null,
			'post_type'         => $queried instanceof WP_Post_Type ? $queried : null,
			'archive_author_id' => $archive_author_id,
			'author_id'         => $archive_author_id > 0 ? $archive_author_id : ( $post ? (int) $post->post_author : 0 ),
			'preview'           => false,
		)
	);

	unset( $resolving[ $key ] );

	$cache[ $cache_key ] = $value;

	return $value;
}

/** Returns the user whose author archive is being viewed, or 0 outside author archives. */
function erankly_get_archive_author_id(): int {
	$queried = get_queried_object();

	if ( $queried instanceof WP_User ) {
		return (int) $queried->ID;
	}

	if ( ! is_author() ) {
		return 0;
	}

	$author_id = absint( get_queried_object_id() );

	if ( $author_id < 1 ) {
		$author    = get_user_by( 'slug', (string) get_query_var( 'author_name' ) );
		$author_id = $author instanceof WP_User ? (int) $author->ID : 0;
	}

	return $author_id;
}

/**
 * Resolves one {{variable}} for a context. Frontend requests describe the queried object; admin previews pass a
 * sample post or term with `preview` set, which swaps request-only values (SEO title, archive date, pagination)
 * for stand-ins so settings fields can still show an example.
 *
 * @param array{post:?WP_Post,term:?WP_Term,post_type:?WP_Post_Type,archive_author_id:int,author_id:int,preview:bool} $context
 * @return string Plain text; empty when the variable has no value in this context.
 */
function erankly_resolve_variable( string $key, array $context ): string {
	foreach ( array( 'erankly_resolve_content_variable', 'erankly_resolve_author_variable', 'erankly_resolve_request_variable', 'erankly_resolve_site_variable' ) as $resolver ) {
		$value = $resolver( $key, $context );

		if ( null !== $value ) {
			return trim( wp_strip_all_tags( $value ) );
		}
	}

	return '';
}

/**
 * Resolves post, post type and taxonomy variables.
 *
 * @param array<string,mixed> $context See erankly_resolve_variable().
 * @return string|null Null when the key belongs to another group.
 */
function erankly_resolve_content_variable( string $key, array $context ): ?string {
	$post    = $context['post'];
	$term    = $context['term'];
	$preview = $context['preview'];
	$value   = '';

	switch ( $key ) {
		case 'post_title':
			$value = $post ? get_the_title( $post ) : '';
			break;
		case 'post_excerpt':
			if ( $post ) {
				$value = has_excerpt( $post ) ? get_the_excerpt( $post ) : erankly_trim_text( $post->post_content, 160 );
			}
			break;
		case 'post_content':
			$value = $post ? wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) : '';
			$value = $preview ? erankly_trim_text( $value, 160 ) : $value;
			break;
		case 'post_url':
			$value = $post ? (string) get_permalink( $post ) : '';
			break;
		case 'post_date':
		case 'post_year':
		case 'post_month':
		case 'post_day':
			$formats = array(
				'post_date'  => DATE_W3C,
				'post_year'  => 'Y',
				'post_month' => 'F',
				'post_day'   => 'j',
			);
			$value   = $post ? get_the_date( $formats[ $key ], $post ) : '';
			break;
		case 'post_modified_date':
			$value = $post ? get_the_modified_date( DATE_W3C, $post ) : '';
			break;
		case 'post_author':
			$value = $post ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';
			break;
		case 'post_categories':
			$value = $post ? erankly_get_post_category_names( $post->ID ) : '';
			break;
		case 'post_tags':
			$value = $post ? erankly_get_post_tag_names( $post->ID ) : '';
			break;
		case 'featured_image':
			$image = $post ? get_the_post_thumbnail_url( $post, 'full' ) : '';
			$value = is_string( $image ) ? $image : '';
			break;
		case 'post_type_name':
			$post_type = $post ? get_post_type_object( $post->post_type ) : $context['post_type'];
			$value     = $post_type instanceof WP_Post_Type ? $post_type->labels->singular_name : ( $post ? $post->post_type : '' );
			break;
		case 'term_name':
			$value = $term ? $term->name : '';
			break;
		case 'term_description':
			$value = $term ? $term->description : '';
			break;
		case 'term_slug':
			$value = $term ? $term->slug : '';
			break;
		case 'term_url':
			$term_link = $term ? get_term_link( $term ) : '';
			$value     = is_string( $term_link ) ? $term_link : '';
			break;
		case 'taxonomy_name':
			if ( $term ) {
				$taxonomy = get_taxonomy( $term->taxonomy );
				$value    = $taxonomy instanceof WP_Taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;
			}
			break;
		default:
			return null;
	}

	return (string) $value;
}

/**
 * Resolves author variables: the archive author name, and the profile of the post or archive author.
 *
 * @param array<string,mixed> $context See erankly_resolve_variable().
 * @return string|null Null when the key belongs to another group.
 */
function erankly_resolve_author_variable( string $key, array $context ): ?string {
	$value = '';

	switch ( $key ) {
		case 'author_name':
			$value = $context['archive_author_id'] > 0 ? get_the_author_meta( 'display_name', $context['archive_author_id'] ) : '';
			break;
		case 'author_bio':
		case 'author_first_name':
		case 'author_last_name':
		case 'author_website':
			$fields = array(
				'author_bio'        => 'description',
				'author_first_name' => 'first_name',
				'author_last_name'  => 'last_name',
				'author_website'    => 'user_url',
			);
			$value  = $context['author_id'] > 0 ? (string) get_the_author_meta( $fields[ $key ], $context['author_id'] ) : '';
			break;
		case 'author_url':
			$value = $context['author_id'] > 0 ? (string) get_author_posts_url( $context['author_id'] ) : '';
			break;
		case 'author_profile_url':
			if ( $context['author_id'] > 0 ) {
				$value = trim( (string) get_the_author_meta( 'user_url', $context['author_id'] ) );
				$value = '' !== $value ? $value : (string) get_author_posts_url( $context['author_id'] );
			}
			break;
		default:
			return null;
	}

	return (string) $value;
}

/**
 * Resolves variables that describe the current request: SEO output, archive date, search and pagination.
 *
 * @param array<string,mixed> $context See erankly_resolve_variable().
 * @return string|null Null when the key belongs to another group.
 */
function erankly_resolve_request_variable( string $key, array $context ): ?string {
	$post    = $context['post'];
	$preview = $context['preview'];
	$paged   = $preview ? 2 : max( (int) get_query_var( 'paged', 0 ), (int) get_query_var( 'page', 0 ) );
	$value   = '';

	switch ( $key ) {
		case 'archive_date':
			$value = $preview ? wp_date( (string) get_option( 'date_format' ) ) : erankly_get_archive_date_label();
			break;
		case 'seo_title':
			$value = $preview ? ( $post ? get_the_title( $post ) : '' ) : ( function_exists( 'erankly_get_title' ) ? erankly_get_title() : '' );
			break;
		case 'meta_description':
			if ( $preview ) {
				$value = $post ? erankly_resolve_variable( 'post_excerpt', $context ) : '';
			} else {
				$value = function_exists( 'erankly_get_description' ) ? erankly_get_description() : '';
			}
			break;
		case 'canonical_url':
			$value = $preview ? ( $post ? (string) get_permalink( $post ) : '' ) : ( function_exists( 'erankly_get_canonical' ) ? erankly_get_canonical() : '' );
			break;
		case 'search_query':
			$value = ! $preview && is_search() ? get_search_query() : '';
			break;
		case 'page_number':
			$value = $preview ? '1' : (string) max( 1, $paged );
			break;
		case 'current_pagination':
			$value = $paged > 1 ? (string) $paged : '';
			break;
		case 'pagination':
			if ( $paged > 1 ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core WP global, read-only.
				$total = $preview ? 5 : ( isset( $GLOBALS['wp_query'] ) ? max( $paged, (int) $GLOBALS['wp_query']->max_num_pages ) : $paged );
				$value = sprintf(
					/* translators: 1: current page number, 2: total pages. */
					__( 'Page %1$d of %2$d', 'easyrankly' ),
					$paged,
					$total
				);
			}
			break;
		case 'max_pages':
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core WP global, read-only.
			$value = ! $preview && isset( $GLOBALS['wp_query'] ) ? (string) max( 1, (int) $GLOBALS['wp_query']->max_num_pages ) : '1';
			break;
		default:
			return null;
	}

	return (string) $value;
}

/**
 * Resolves site-wide and identity variables.
 *
 * @param array<string,mixed> $context See erankly_resolve_variable().
 * @return string|null Null when the key belongs to another group.
 */
function erankly_resolve_site_variable( string $key, array $context ): ?string {
	$value = '';

	switch ( $key ) {
		case 'current_year':
			$value = wp_date( 'Y' );
			break;
		case 'current_month':
			$value = wp_date( 'F' );
			break;
		case 'current_day':
			$value = wp_date( 'j' );
			break;
		case 'current_date':
			$value = wp_date( (string) get_option( 'date_format' ) );
			break;
		case 'site_name':
			$value = get_bloginfo( 'name' );
			break;
		case 'site_description':
			$value = get_bloginfo( 'description' );
			break;
		case 'site_url':
			$value = home_url( '/' );
			break;
		case 'site_language':
			$value = get_bloginfo( 'language' );
			break;
		case 'organization_name':
			$value = erankly_get_organization_name();
			break;
		case 'website_name':
			$value = erankly_get_website_name();
			break;
		case 'website_description':
			$value = erankly_get_website_description();
			break;
		case 'organization_logo':
		case 'organization_logo_url':
			$value = erankly_get_organization_logo_url();
			break;
		case 'site_icon':
		case 'site_icon_url':
			$value = erankly_get_site_icon_url();
			break;
		case 'schema_identity_id':
			if ( function_exists( 'erankly_schema_identity_id' ) ) {
				$value = erankly_schema_identity_id();
			} else {
				$value = home_url( 'person' === (string) erankly_get_setting( 'schema_identity', 'organization' ) ? '/#person' : '/#organization' );
			}
			break;
		default:
			return null;
	}

	return (string) $value;
}

/** Returns the formatted date of the current year, month or day archive, or an empty string elsewhere. */
function erankly_get_archive_date_label(): string {
	if ( ! is_year() && ! is_month() && ! is_day() ) {
		return '';
	}

	$year = absint( get_query_var( 'year' ) );

	if ( $year < 1 ) {
		return '';
	}

	$month = max( 1, absint( get_query_var( 'monthnum' ) ) );
	$day   = max( 1, absint( get_query_var( 'day' ) ) );
	$date  = date_create_immutable( sprintf( '%04d-%02d-%02d 12:00:00', $year, $month, $day ), wp_timezone() );

	if ( ! $date instanceof DateTimeImmutable ) {
		return '';
	}

	$format = is_day() ? (string) get_option( 'date_format' ) : ( is_month() ? 'F Y' : 'Y' );

	return (string) wp_date( $format, $date->getTimestamp(), wp_timezone() );
}

function erankly_get_post_category_names( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$categories = get_the_category( $post_id );

	if ( empty( $categories ) || is_wp_error( $categories ) ) {
		return '';
	}

	$names = wp_list_pluck( $categories, 'name' );

	return implode( ', ', array_map( 'sanitize_text_field', $names ) );
}

function erankly_get_post_tag_names( int $post_id ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$tags = get_the_tags( $post_id );

	if ( empty( $tags ) || is_wp_error( $tags ) ) {
		return '';
	}

	$names = wp_list_pluck( $tags, 'name' );

	return implode( ', ', array_map( 'sanitize_text_field', $names ) );
}

/**
 * Resolves a variable's example value for admin field previews, given an explicit post/term instead of the
 * global queried object. Admin screens (Settings defaults, classic meta boxes) don't have one to fall back on.
 *
 * @param WP_Post|null $post Sample or currently-edited post, if any.
 * @param WP_Term|null $term Sample or currently-edited term, if any.
 * @return string Empty string when this key has no example value here.
 */
function erankly_get_variable_preview_value( string $key, ?WP_Post $post = null, ?WP_Term $term = null ): string {
	$author_id = $post ? (int) $post->post_author : (int) get_current_user_id();

	return erankly_resolve_variable(
		$key,
		array(
			'post'              => $post,
			'term'              => $term,
			'post_type'         => null,
			'archive_author_id' => $author_id,
			'author_id'         => $author_id,
			'preview'           => true,
		)
	);
}

/**
 * Builds the example-values map for an admin {{variable}} field preview, skipping any key that has no example
 * (its {{token}} then stays literal in the field, e.g. when a post type has no published posts yet). Site-level
 * keys resolve even without a sample post or term, so identity fields on Settings can preview values such as
 * {{site_description}}.
 *
 * @param WP_Post|null $post Sample or currently-edited post, if any.
 * @param WP_Term|null $term Sample or currently-edited term, if any.
 * @return array<string,string>
 */
function erankly_get_admin_variable_examples( ?WP_Post $post = null, ?WP_Term $term = null ): array {
	require_once ERANKLY_PATH . 'admin/field-renderers.php';

	$examples = array();

	foreach ( erankly_get_variable_groups() as $group ) {
		foreach ( array_keys( $group['variables'] ) as $key ) {
			$value = erankly_get_variable_preview_value( (string) $key, $post, $term );

			if ( '' !== $value ) {
				$examples[ (string) $key ] = $value;
			}
		}
	}

	return $examples;
}

/**
 * Returns the most recently published post of a type, to stand in as the "{{post_title}}"-style example on
 * global default-template admin fields that aren't tied to any single post.
 *
 * @return WP_Post|null
 */
function erankly_get_sample_post_for_type( string $post_type ): ?WP_Post {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	return ! empty( $posts ) ? $posts[0] : null;
}

/**
 * Returns the first term of a taxonomy, to stand in as the "{{term_name}}" example on global default-template
 * admin fields that aren't tied to any single term.
 *
 * @return WP_Term|null
 */
function erankly_get_sample_term_for_taxonomy( string $taxonomy ): ?WP_Term {
	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'number'     => 1,
			'hide_empty' => false,
		)
	);

	return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
}
