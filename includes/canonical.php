<?php
/** Canonical URL handling. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_get_canonical(): string {
	static $resolved = null;

	if ( null !== $resolved ) {
		return $resolved;
	}

	$canonical = '';

	if ( is_paged() && ! is_singular() && ! is_search() && ! is_404() ) {
		$canonical = erankly_get_paged_archive_canonical();
	} elseif ( is_singular() ) {
		$canonical = erankly_resolve_post_canonical( get_queried_object_id() );
	} elseif ( is_front_page() ) {
		$canonical = home_url( '/' );
	} elseif ( is_home() ) {
		$posts_page_id  = (int) get_option( 'page_for_posts', 0 );
		$posts_page_url = $posts_page_id > 0 ? get_permalink( $posts_page_id ) : '';
		$canonical      = is_string( $posts_page_url ) && '' !== $posts_page_url ? $posts_page_url : home_url( '/' );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$canonical = erankly_resolve_term_canonical( $term );
		}
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}
		$post_type = is_string( $post_type ) ? $post_type : '';
		$canonical = '' !== $post_type ? get_post_type_archive_link( $post_type ) : '';
	} elseif ( is_author() ) {
		$canonical = erankly_resolve_author_canonical( (int) get_queried_object_id() );
	} elseif ( is_date() ) {
		$canonical = erankly_current_url();
	} elseif ( is_search() ) {
		$canonical = get_search_link( get_search_query() );
	}

	$resolved = erankly_finalize_canonical_url( (string) $canonical );

	return $resolved;
}

function erankly_resolve_post_canonical( int $post_id ): string {
	if ( $post_id < 1 ) {
		return '';
	}

	$canonical = erankly_get_post_meta_string( $post_id, 'canonical' );

	if ( '' !== $canonical ) {
		return erankly_replace_variables( $canonical, $post_id, array( 'canonical_url' ) );
	}

	$wp_canonical = wp_get_canonical_url( $post_id );

	return is_string( $wp_canonical ) ? $wp_canonical : (string) get_permalink( $post_id );
}

function erankly_resolve_term_canonical( WP_Term $term ): string {
	$canonical = erankly_get_term_meta_string( $term->term_id, 'canonical' );

	if ( '' !== $canonical ) {
		return erankly_replace_variables( $canonical, 0, array( 'canonical_url' ) );
	}

	$term_link = get_term_link( $term );

	return is_wp_error( $term_link ) ? '' : (string) $term_link;
}

function erankly_resolve_author_canonical( int $user_id ): string {
	$canonical = $user_id > 0 ? trim( (string) get_user_meta( $user_id, '_erankly_canonical', true ) ) : '';

	if ( '' !== $canonical ) {
		$canonical = erankly_replace_variables( $canonical, 0, array( 'canonical_url' ) );
	}

	if ( '' !== $canonical ) {
		return $canonical;
	}

	return $user_id > 0 ? (string) get_author_posts_url( $user_id ) : '';
}

function erankly_get_paged_archive_canonical(): string {
	$page = max( 1, (int) get_query_var( 'paged', 1 ) );
	$url  = get_pagenum_link( $page, false );

	return is_string( $url ) ? $url : erankly_current_url();
}
