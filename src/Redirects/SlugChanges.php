<?php
/**
 * Redirects for changed addresses of pages and terms.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a 301 from the old address when a published page (or any hierarchical public
 * post type) or a public term changes address, and for its descendants, whose addresses
 * change with it.
 *
 * Non-hierarchical post types are left to core, which already redirects old slugs
 * through `_wp_old_slug` and wp_old_slug_redirect().
 */
final class SlugChanges {

	/**
	 * Most descendants redirected for one change, so saving a page stays fast.
	 *
	 * Beyond this, the descendants' old addresses answer 404, where core may still guess
	 * the page (redirect_guess_404_permalink()).
	 */
	public const MAX_DESCENDANTS = 100;

	/**
	 * Term links captured before an update, keyed by term ID.
	 *
	 * @var array<int, string>
	 */
	private array $term_links = array();

	/**
	 * Hooks post and term updates.
	 */
	public function register(): void {
		add_action( 'post_updated', array( $this, 'post_updated' ), 10, 3 );
		add_action( 'edit_terms', array( $this, 'before_term_update' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'term_updated' ), 10, 3 );
	}

	/**
	 * Redirects the old address of a published hierarchical post to the new one.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $after   Post after the update.
	 * @param mixed $before  Post before the update.
	 */
	public function post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}

		if ( ! is_post_type_hierarchical( $after->post_type ) || ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}

		if ( $before->post_name === $after->post_name && $before->post_parent === $after->post_parent ) {
			return;
		}

		$this->moved( (string) get_permalink( $before ), (string) get_permalink( $after ), self::descendant_links( $after ) );
	}

	/**
	 * New addresses of the published descendants of a post, level by level, up to the limit.
	 *
	 * @param \WP_Post $post Post that moved.
	 * @return string[]
	 */
	private static function descendant_links( \WP_Post $post ): array {
		$links   = array();
		$parents = array( $post->ID );
		$left    = self::MAX_DESCENDANTS;

		while ( array() !== $parents && $left > 0 ) {
			$children = get_posts(
				array(
					'post_type'              => $post->post_type,
					'post_status'            => 'publish',
					'post_parent__in'        => $parents,
					'posts_per_page'         => $left,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => true,
				)
			);

			$parents = array();
			foreach ( $children as $child ) {
				$links[]   = (string) get_permalink( $child );
				$parents[] = $child->ID;
				--$left;
			}
		}

		return $links;
	}

	/**
	 * Remembers the address of a term before it changes.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function before_term_update( $term_id, $taxonomy ): void {
		if ( is_string( $taxonomy ) && is_taxonomy_viewable( $taxonomy ) ) {
			$link = get_term_link( (int) $term_id, $taxonomy );
			if ( is_string( $link ) ) {
				$this->term_links[ (int) $term_id ] = $link;
			}
		}
	}

	/**
	 * Redirects the old address of a term to the new one.
	 *
	 * @param mixed $term_id  Term ID.
	 * @param mixed $tt_id    Term taxonomy ID.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function term_updated( $term_id, $tt_id, $taxonomy ): void {
		$term_id = (int) $term_id;

		if ( ! isset( $this->term_links[ $term_id ] ) || ! is_string( $taxonomy ) ) {
			return;
		}

		$old = $this->term_links[ $term_id ];
		unset( $this->term_links[ $term_id ] );

		clean_term_cache( $term_id, $taxonomy );
		$new = get_term_link( $term_id, $taxonomy );

		if ( ! is_string( $new ) ) {
			return;
		}

		$links = array();
		$ids   = get_term_children( $term_id, $taxonomy );
		foreach ( is_array( $ids ) ? array_slice( $ids, 0, self::MAX_DESCENDANTS ) : array() as $child ) {
			$link = get_term_link( (int) $child, $taxonomy );
			if ( is_string( $link ) ) {
				$links[] = $link;
			}
		}

		$this->moved( $old, $new, $links );
	}

	/**
	 * Saves old → new for the object and for each descendant below its new address.
	 *
	 * With plain permalinks the addresses differ only in the query string, which redirects
	 * ignore: nothing is created then. A descendant's old address is its new one with the
	 * object's new path swapped for the old; descendants whose address does not contain
	 * the object's (non-hierarchical term links) are skipped.
	 *
	 * @param string   $old         Old URL.
	 * @param string   $current     New URL.
	 * @param string[] $descendants New URLs of the descendants.
	 */
	private function moved( string $old, string $current, array $descendants = array() ): void {
		$from = Rule::normalize( $old );
		$to   = Rule::normalize( $current );

		if ( $from === $to || '/' === $from || '/' === $to ) {
			return;
		}

		$this->save( $from, $to );

		foreach ( $descendants as $link ) {
			$path = Rule::normalize( $link );
			if ( str_starts_with( $path, $to . '/' ) ) {
				$this->save( $from . substr( $path, strlen( $to ) ), $path );
			}
		}
	}

	/**
	 * Saves one exact rule old → new.
	 *
	 * An active rule for the new address (moving back to an old address, or a rule the
	 * administrator made) would now send visitors away from the page, or loop: it is
	 * deactivated, not deleted, so it stays in the list. If the new rule cannot be saved,
	 * the old one is restored as it was.
	 *
	 * @param string $from Old normalized path.
	 * @param string $to   New normalized path.
	 */
	private function save( string $from, string $to ): void {
		$stale = Redirects::find_id( Rule::hash( $to ) );
		if ( null !== $stale ) {
			wp_update_post(
				array(
					'ID'          => $stale,
					'post_status' => 'draft',
				)
			);
		}

		if ( is_wp_error( Redirects::save_exact( $from, $to ) ) && null !== $stale ) {
			wp_update_post(
				array(
					'ID'          => $stale,
					'post_status' => 'publish',
				)
			);
		}
	}
}
