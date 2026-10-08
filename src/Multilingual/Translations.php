<?php
/**
 * Language of each post and groups of translations.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the language of a post as a term of `erankly_language` (term slug = language slug)
 * and links translations with a shared term of `erankly_translation` (one term per group).
 *
 * Rules kept by every write:
 * - a post has at most one language and belongs to at most one group;
 * - a group holds posts of the same post type, at most one per language, and at least two;
 * - a post without a language, or with a language no longer configured, belongs to the default language.
 *
 * Writes do not check capabilities, like the core term functions: callers (REST, editor) do.
 */
final class Translations {

	/**
	 * Taxonomy holding the language of each post.
	 */
	public const LANGUAGE = 'erankly_language';

	/**
	 * Taxonomy grouping the translations of the same content.
	 */
	public const GROUP = 'erankly_translation';

	/**
	 * Language of a post: its term if configured, otherwise the default language.
	 *
	 * The post's terms are primed with the query that loaded it, so this costs no query on the frontend.
	 *
	 * @param int $post_id Post ID.
	 * @return string Language slug, empty when no language is configured.
	 */
	public static function language( int $post_id ): string {
		$terms = get_the_terms( $post_id, self::LANGUAGE );
		$slug  = is_array( $terms ) && isset( $terms[0] ) ? $terms[0]->slug : '';

		return Languages::exists( $slug ) ? $slug : Languages::default();
	}

	/**
	 * Translation group of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Term|null
	 */
	public static function group( int $post_id ): ?\WP_Term {
		$terms = get_the_terms( $post_id, self::GROUP );

		return is_array( $terms ) && isset( $terms[0] ) ? $terms[0] : null;
	}

	/**
	 * The post and its translations, keyed by language slug, the post itself included.
	 *
	 * Members whose language repeats another member's (possible only after the languages
	 * changed) are skipped: the oldest post wins.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, int>
	 */
	public static function of( int $post_id ): array {
		$group = self::group( $post_id );
		$own   = array( self::language( $post_id ) => $post_id );

		if ( null === $group ) {
			return $own;
		}

		$default = Languages::default();
		$map     = array();
		foreach ( self::members( $group->term_id ) as $id => $slug ) {
			$language = Languages::exists( $slug ) ? $slug : $default;
			if ( ! isset( $map[ $language ] ) ) {
				$map[ $language ] = $id;
			}
		}

		return in_array( $post_id, $map, true ) ? $map : $own;
	}

	/**
	 * Sets the language of a post, keeping its group valid.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Configured language slug.
	 * @return true|\WP_Error
	 */
	public static function set_language( int $post_id, string $slug ) {
		$post = self::translatable_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! Languages::exists( $slug ) ) {
			return new \WP_Error( 'easyrankly_invalid_language', __( 'The language is not configured.', 'easyrankly' ) );
		}

		$others = array_diff( self::of( $post_id ), array( $post_id ) );
		if ( isset( $others[ $slug ] ) ) {
			return new \WP_Error( 'easyrankly_language_taken', __( 'Another translation of this content already has that language.', 'easyrankly' ) );
		}

		$result = wp_set_object_terms( $post_id, $slug, self::LANGUAGE );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Makes the given posts one complete group of translations.
	 *
	 * Each post takes the language of its key. Posts that were in the same group as any of
	 * them but are not listed leave it; groups left with a single post are deleted.
	 * A map with one post only removes that post from its group.
	 *
	 * @param array<mixed, mixed> $map Language slug => post ID.
	 * @return true|\WP_Error
	 */
	public static function link( array $map ) {
		$posts = self::validate_map( $map );
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$groups = array();
		foreach ( $posts as $post ) {
			$group = self::group( $post->ID );
			if ( null !== $group ) {
				$groups[ $group->term_id ] = $group->term_id;
			}
		}

		$target = 0;
		if ( count( $posts ) > 1 ) {
			$target = (int) reset( $groups );
			if ( 0 === $target ) {
				$term = wp_insert_term( wp_generate_uuid4(), self::GROUP );
				if ( is_wp_error( $term ) ) {
					return $term;
				}
				$target = (int) $term['term_id'];
			}

			// Members of the reused group that are not listed leave it.
			$listed = array_map( static fn( \WP_Post $post ): int => $post->ID, $posts );
			foreach ( array_diff( array_keys( self::members( $target ) ), $listed ) as $displaced ) {
				wp_remove_object_terms( $displaced, $target, self::GROUP );
			}
		}

		foreach ( $posts as $slug => $post ) {
			wp_set_object_terms( $post->ID, $slug, self::LANGUAGE );
			wp_set_object_terms( $post->ID, $target > 0 ? array( $target ) : array(), self::GROUP );
		}

		foreach ( $groups as $group_id ) {
			self::delete_if_single( $group_id );
		}

		return true;
	}

	/**
	 * Deletes the group of a post that is about to be deleted, if only one post would remain.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public static function on_delete_post( $post_id ): void {
		$group = self::group( (int) $post_id );

		if ( null !== $group && count( self::members( $group->term_id ) ) <= 2 ) {
			wp_delete_term( $group->term_id, self::GROUP );
		}
	}

	/**
	 * Post IDs of a group with the slug of their language term (empty without one), oldest first.
	 *
	 * Cached until any term relationship changes: core bumps the `terms` last-changed key then.
	 *
	 * @param int $group_id Term ID of the group.
	 * @return array<int, string>
	 */
	private static function members( int $group_id ): array {
		$key     = 'translations:' . $group_id . ':' . wp_cache_get_last_changed( 'terms' );
		$members = wp_cache_get( $key, 'easyrankly' );

		if ( is_array( $members ) ) {
			return $members;
		}

		$ids = get_objects_in_term( $group_id, self::GROUP );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );

		$members = array_fill_keys( $ids, '' );
		if ( array() !== $ids ) {
			$terms = wp_get_object_terms(
				$ids,
				self::LANGUAGE,
				array(
					'fields'                 => 'all_with_object_id',
					'update_term_meta_cache' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( $term instanceof \WP_Term && isset( $term->object_id ) ) {
					$members[ (int) $term->object_id ] = $term->slug;
				}
			}
		}

		wp_cache_set( $key, $members, 'easyrankly' );

		return $members;
	}

	/**
	 * Deletes a group that holds fewer than two posts.
	 *
	 * @param int $group_id Term ID of the group.
	 */
	private static function delete_if_single( int $group_id ): void {
		if ( count( self::members( $group_id ) ) < 2 ) {
			wp_delete_term( $group_id, self::GROUP );
		}
	}

	/**
	 * Checks a map of translations: configured languages, existing posts of one translatable type, no repeats.
	 *
	 * @param array<mixed, mixed> $map Language slug => post ID.
	 * @return array<string, \WP_Post>|\WP_Error Posts keyed by language slug.
	 */
	private static function validate_map( array $map ) {
		$posts = array();

		foreach ( $map as $slug => $id ) {
			if ( ! is_string( $slug ) || ! Languages::exists( $slug ) ) {
				return new \WP_Error( 'easyrankly_invalid_language', __( 'The language is not configured.', 'easyrankly' ) );
			}

			$post = self::translatable_post( is_numeric( $id ) ? (int) $id : 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$posts[ $slug ] = $post;
		}

		if ( array() === $posts ) {
			return new \WP_Error( 'easyrankly_empty_group', __( 'A group of translations needs at least one post.', 'easyrankly' ) );
		}

		$ids = array_map( static fn( \WP_Post $post ): int => $post->ID, $posts );
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) {
			return new \WP_Error( 'easyrankly_repeated_post', __( 'A post can be the translation of only one language.', 'easyrankly' ) );
		}

		if ( count( array_unique( array_map( static fn( \WP_Post $post ): string => $post->post_type, $posts ) ) ) > 1 ) {
			return new \WP_Error( 'easyrankly_mixed_types', __( 'Translations must have the same content type.', 'easyrankly' ) );
		}

		return $posts;
	}

	/**
	 * A post that exists and whose type has languages.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function translatable_post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'easyrankly_invalid_post', __( 'The content does not exist.', 'easyrankly' ) );
		}
		if ( ! is_object_in_taxonomy( $post->post_type, self::LANGUAGE ) ) {
			return new \WP_Error( 'easyrankly_untranslatable', __( 'This content type has no languages.', 'easyrankly' ) );
		}

		return $post;
	}
}
