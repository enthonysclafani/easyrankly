<?php
/**
 * Language meta box on the classic post edit screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Multilingual\Admin;

use EasyRankly\Meta\Admin\ClassicFields;
use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * The language panel of the block editor as a classic meta box, where the block editor is not
 * used (see ClassicFields): language of the post, its translations, "Create draft", "Link
 * existing content" and "Unlink".
 *
 * Changes are sent with the post form and saved on save_post through Translations, with the
 * checks of the REST routes: `edit_post` on every post involved, plus `create_posts` to copy.
 */
final class ClassicPanel {

	/**
	 * Nonce action and field name.
	 */
	private const NONCE = 'easyrankly_post_language';

	/**
	 * Query argument that carries an error back to the edit screen.
	 */
	private const ERROR_ARG = 'easyrankly_language_error';

	/**
	 * Candidates listed for "Link existing content", per language.
	 */
	private const CANDIDATES = 50;

	/**
	 * Hooks the meta box and its saving.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 20, 2 );
	}

	/**
	 * Adds the box when the site has two languages and the post type has languages.
	 *
	 * @param mixed $post_type Post type of the screen.
	 * @param mixed $post      Post being edited.
	 */
	public function add( $post_type, $post ): void {
		if ( ! $post instanceof \WP_Post || ! Languages::enabled() || ! is_object_in_taxonomy( $post->post_type, Translations::LANGUAGE ) || ! ClassicFields::applies( $post ) ) {
			return;
		}

		add_meta_box( 'easyrankly-language', __( 'Language', 'easyrankly' ), array( $this, 'render' ), (string) $post_type, 'side' );
	}

	/**
	 * Prints the language select and one row per other language.
	 *
	 * @param mixed $post Post being edited.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$error = isset( $_GET[ self::ERROR_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ERROR_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a fixed message to show.
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( self::message( $error ) ) );
		}

		$current = Translations::language( $post->ID );
		$map     = Translations::of( $post->ID );

		wp_nonce_field( self::NONCE, self::NONCE );
		printf( '<p><label for="easyrankly-language"><strong>%s</strong></label><br><select id="easyrankly-language" name="easyrankly_language">', esc_html__( 'Language of this content', 'easyrankly' ) );
		foreach ( Languages::all() as $slug => $language ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $slug ), selected( $slug, $current, false ), esc_html( $language['name'] ) );
		}
		echo '</select></p>';

		foreach ( Languages::all() as $slug => $language ) {
			if ( $slug === $current ) {
				continue;
			}

			$translation = isset( $map[ $slug ] ) && $map[ $slug ] !== $post->ID ? get_post( $map[ $slug ] ) : null;
			printf( '<p><strong>%s</strong>: ', esc_html( $language['name'] ) );

			if ( $translation instanceof \WP_Post ) {
				$this->render_translation( $translation, $slug );
				continue;
			}

			echo esc_html__( 'no translation', 'easyrankly' ) . '</p><p>';
			if ( 'auto-draft' !== $post->post_status ) {
				printf(
					'<button type="submit" class="button" name="easyrankly_translate" value="%1$s">%2$s</button></p><p>',
					esc_attr( $slug ),
					/* translators: %s: language name. */
					esc_html( sprintf( __( 'Create %s draft', 'easyrankly' ), $language['name'] ) )
				);
			}
			$this->render_candidates( $post, $slug );
			echo '</p>';
		}
	}

	/**
	 * Prints a linked translation: its title, linked if the user can edit it, and "Unlink".
	 * A translation the user cannot read only marks its language as taken.
	 *
	 * @param \WP_Post $translation Translation.
	 * @param string   $slug        Its language.
	 */
	private function render_translation( \WP_Post $translation, string $slug ): void {
		if ( ! current_user_can( 'read_post', $translation->ID ) ) {
			echo esc_html__( 'content you cannot view', 'easyrankly' ) . '</p>';
			return;
		}

		$title = '' === get_the_title( $translation ) ? __( '(no title)', 'easyrankly' ) : get_the_title( $translation );
		if ( current_user_can( 'edit_post', $translation->ID ) ) {
			printf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $translation->ID ) ), esc_html( $title ) );
		} else {
			echo esc_html( $title );
		}
		if ( 'publish' !== $translation->post_status ) {
			printf( ' (%s)', esc_html( $translation->post_status ) );
		}
		printf( ' <button type="submit" class="button-link button-link-delete" name="easyrankly_unlink" value="%1$s">%2$s</button></p>', esc_attr( $slug ), esc_html__( 'Unlink', 'easyrankly' ) );
	}

	/**
	 * Prints the select of posts of the same type in a language that the user can edit.
	 *
	 * @param \WP_Post $post Post being edited.
	 * @param string   $slug Language.
	 */
	private function render_candidates( \WP_Post $post, string $slug ): void {
		$query = new \WP_Query(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::CANDIDATES,
				'no_found_rows'  => true,
				'perm'           => 'editable',
				'tax_query'      => array( Translations::query_clause( $slug ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Admin screen, limited to CANDIDATES posts.
			)
		);

		$id = 'easyrankly-link-' . $slug;
		printf( '<label for="%1$s">%2$s</label><br><select id="%1$s" name="easyrankly_link[%3$s]"><option value="0">&mdash;</option>', esc_attr( $id ), esc_html__( 'Link existing content', 'easyrankly' ), esc_attr( $slug ) );
		foreach ( $query->posts as $candidate ) {
			if ( $candidate instanceof \WP_Post && $candidate->ID !== $post->ID && current_user_can( 'edit_post', $candidate->ID ) ) {
				printf( '<option value="%1$d">%2$s</option>', (int) $candidate->ID, esc_html( '' === get_the_title( $candidate ) ? '#' . $candidate->ID : get_the_title( $candidate ) ) );
			}
		}
		echo '</select>';
	}

	/**
	 * Saves the language and the links, then creates the draft asked for and opens it.
	 *
	 * Only for the post the form was sent for: the draft created here runs save_post too,
	 * with the same form data.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    Post.
	 */
	public function save( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( ! isset( $_POST[ self::NONCE ], $_POST['post_ID'], $_POST['easyrankly_language'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( absint( $_POST['post_ID'] ) !== $post_id || ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! Languages::enabled() || ! is_object_in_taxonomy( $post->post_type, Translations::LANGUAGE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$language  = sanitize_key( wp_unslash( $_POST['easyrankly_language'] ) );
		$unlink    = isset( $_POST['easyrankly_unlink'] ) ? sanitize_key( wp_unslash( $_POST['easyrankly_unlink'] ) ) : '';
		$translate = isset( $_POST['easyrankly_translate'] ) ? sanitize_key( wp_unslash( $_POST['easyrankly_translate'] ) ) : '';
		$links     = isset( $_POST['easyrankly_link'] ) && is_array( $_POST['easyrankly_link'] ) ? array_map( 'absint', wp_unslash( $_POST['easyrankly_link'] ) ) : array();

		$before = array_diff( Translations::of( $post_id ), array( $post_id ) );
		$others = $before;
		unset( $others[ $unlink ] );
		foreach ( $links as $slug => $id ) {
			if ( $id > 0 && ! isset( $others[ $slug ] ) ) {
				$others[ (string) $slug ] = $id;
			}
		}

		if ( isset( $others[ $language ] ) ) {
			$result = new \WP_Error( 'easyrankly_language_taken' );
		} elseif ( $others != $before ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Same languages and posts, in any order.
			$result = self::can_edit_all( self::with_groups( array_merge( array( $post_id ), array_values( $others ) ) ) )
				? Translations::link( array( $language => $post_id ) + $others )
				: new \WP_Error( 'easyrankly_forbidden' );
		} elseif ( Translations::language( $post_id ) !== $language ) {
			$result = Translations::set_language( $post_id, $language );
		} else {
			$result = true;
		}

		if ( true === $result && '' !== $translate ) {
			$type   = get_post_type_object( $post->post_type );
			$result = null !== $type && current_user_can( $type->cap->create_posts ) && self::can_edit_all( self::with_groups( array( $post_id ) ) )
				? Translations::create_translation( $post_id, $translate )
				: new \WP_Error( 'easyrankly_forbidden' );
		}

		if ( is_int( $result ) ) {
			$copy = $result;
			add_filter( 'redirect_post_location', static fn(): string => (string) get_edit_post_link( $copy, 'raw' ) );
		} elseif ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			add_filter( 'redirect_post_location', static fn( $location ): string => add_query_arg( self::ERROR_ARG, sanitize_key( (string) $code ), (string) $location ) );
		}
	}

	/**
	 * Message shown for an error code; codes come from the URL, so only known ones are named.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private static function message( string $code ): string {
		return match ( $code ) {
			'easyrankly_language_taken' => __( 'Another translation of this content already has that language.', 'easyrankly' ),
			'easyrankly_forbidden'      => __( 'Sorry, you are not allowed to change the translations of this content.', 'easyrankly' ),
			default                     => __( 'The translations were not changed.', 'easyrankly' ),
		};
	}

	/**
	 * Posts plus every member of their current groups, as Rest::with_groups().
	 *
	 * @param int[] $ids Post IDs.
	 * @return list<int>
	 */
	private static function with_groups( array $ids ): array {
		$all = $ids;
		foreach ( $ids as $id ) {
			if ( get_post( $id ) instanceof \WP_Post ) {
				$all = array_merge( $all, array_values( Translations::of( $id ) ) );
			}
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * Whether the user can edit every post that exists among the IDs, as Rest::can_edit_all().
	 *
	 * @param int[] $ids Post IDs.
	 * @return bool
	 */
	private static function can_edit_all( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( get_post( $id ) instanceof \WP_Post && ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}

		return true;
	}
}
