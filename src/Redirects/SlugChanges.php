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
 * post type) or a public term changes address.
 *
 * Non-hierarchical post types are left to core, which already redirects old slugs
 * through `_wp_old_slug` and wp_old_slug_redirect().
 */
final class SlugChanges {

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

		$this->moved( (string) get_permalink( $before ), (string) get_permalink( $after ) );
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

		if ( is_string( $new ) ) {
			$this->moved( $old, $new );
		}
	}

	/**
	 * Saves old → new, and removes a rule that would now send the new address elsewhere.
	 *
	 * With plain permalinks the addresses differ only in the query string, which redirects
	 * ignore: nothing is created then.
	 *
	 * @param string $old Old URL.
	 * @param string $current New URL.
	 */
	private function moved( string $old, string $current ): void {
		$from = Rule::normalize( $old );
		$to   = Rule::normalize( $current );

		if ( $from === $to || '/' === $from ) {
			return;
		}

		// Moving back to an old address: the rule for that address would now loop.
		$stale = Redirects::find_id( Rule::hash( $to ), array( 'publish', 'draft' ) );
		if ( null !== $stale ) {
			wp_delete_post( $stale, true );
		}

		Redirects::save_exact( $from, $to );
	}
}
