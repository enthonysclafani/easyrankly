<?php
/**
 * Redirects for changed addresses of pages and terms.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

use EasyRankly\Redirects\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a 301 from the old address when a published page (or any hierarchical public
 * post type) or a public term changes address, and for its descendants, whose addresses
 * change with it.
 *
 * Non-hierarchical post types are left to core, which already redirects old slugs
 * through `_wp_old_slug` and wp_old_slug_redirect().
 *
 * Redirects that cannot be saved, and descendants beyond the limit, are kept for a day in a
 * transient of the user who made the change, shown once on their next admin screen.
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
	 * Prefix of the per-user transient with the problems to show; the user ID follows.
	 */
	public const NOTICE = 'easyrankly_redirect_notice_';

	/**
	 * Most problems of each kind kept for the notice.
	 */
	private const MAX_PROBLEMS = 20;

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
		add_action( 'admin_notices', array( $this, 'print_notice' ) );
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
	 * New addresses of the published descendants of a post, level by level, up to one more
	 * than the limit, so moved() knows when some are left out.
	 *
	 * @param \WP_Post $post Post that moved.
	 * @return string[]
	 */
	private static function descendant_links( \WP_Post $post ): array {
		$links   = array();
		$parents = array( $post->ID );
		$left    = self::MAX_DESCENDANTS + 1;

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
		foreach ( is_array( $ids ) ? array_slice( $ids, 0, self::MAX_DESCENDANTS + 1 ) : array() as $child ) {
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
	 * @param string[] $descendants New URLs of the descendants, at most one more than the limit.
	 */
	private function moved( string $old, string $current, array $descendants = array() ): void {
		$from = Rule::normalize( $old );
		$to   = Rule::normalize( $current );

		if ( $from === $to || '/' === $from || '/' === $to ) {
			return;
		}

		$problems = array(
			'failed' => array(),
			'capped' => array(),
		);

		$error = $this->save( $from, $to );
		if ( null !== $error ) {
			$problems['failed'][] = array( $from, $to, $error );
		}

		if ( count( $descendants ) > self::MAX_DESCENDANTS ) {
			$problems['capped'][] = $to;
			$descendants          = array_slice( $descendants, 0, self::MAX_DESCENDANTS );
		}

		foreach ( $descendants as $link ) {
			$path = Rule::normalize( $link );
			if ( str_starts_with( $path, $to . '/' ) ) {
				$source = $from . substr( $path, strlen( $to ) );
				$error  = $this->save( $source, $path );
				if ( null !== $error ) {
					$problems['failed'][] = array( $source, $path, $error );
				}
			}
		}

		self::remember( $problems );
	}

	/**
	 * Adds problems to the notice of the current user. Without a user (WP-CLI, cron) nobody
	 * would see it, so nothing is kept.
	 *
	 * @param array{failed: list<array{0: string, 1: string, 2: string}>, capped: list<string>} $problems New problems.
	 */
	private static function remember( array $problems ): void {
		$user = get_current_user_id();
		if ( $user <= 0 || ( array() === $problems['failed'] && array() === $problems['capped'] ) ) {
			return;
		}

		$kept = self::notice( $user );
		foreach ( array( 'failed', 'capped' ) as $kind ) {
			$kept[ $kind ] = array_slice( array_merge( $kept[ $kind ], $problems[ $kind ] ), 0, self::MAX_PROBLEMS );
		}

		set_transient( self::NOTICE . $user, $kept, DAY_IN_SECONDS );
	}

	/**
	 * Problems kept for a user.
	 *
	 * @param int $user User ID.
	 * @return array{failed: list<array{0: string, 1: string, 2: string}>, capped: list<string>}
	 */
	private static function notice( int $user ): array {
		$stored = get_transient( self::NOTICE . $user );
		$notice = array(
			'failed' => array(),
			'capped' => array(),
		);
		if ( ! is_array( $stored ) ) {
			return $notice;
		}

		foreach ( is_array( $stored['failed'] ?? null ) ? $stored['failed'] : array() as $item ) {
			if ( is_array( $item ) && 3 === count( $item ) ) {
				$notice['failed'][] = array( (string) $item[0], (string) $item[1], (string) $item[2] );
			}
		}
		foreach ( is_array( $stored['capped'] ?? null ) ? $stored['capped'] : array() as $path ) {
			$notice['capped'][] = (string) $path;
		}

		return $notice;
	}

	/**
	 * Shows the kept problems once, then forgets them. Not in the block editor, which does
	 * not show classic notices: they wait for the next screen.
	 */
	public function print_notice(): void {
		$user   = get_current_user_id();
		$screen = get_current_screen();
		if ( $user <= 0 || ( null !== $screen && $screen->is_block_editor() ) ) {
			return;
		}

		$notice = self::notice( $user );
		if ( array() === $notice['failed'] && array() === $notice['capped'] ) {
			return;
		}
		delete_transient( self::NOTICE . $user );

		echo '<div class="notice notice-warning is-dismissible">';
		if ( array() !== $notice['failed'] ) {
			printf( '<p>%s</p><ul>', esc_html__( 'EasyRankly could not create these redirects for changed addresses:', 'easyrankly' ) );
			foreach ( $notice['failed'] as [ $from, $to, $error ] ) {
				printf( '<li><code>%1$s</code> &rarr; <code>%2$s</code>: %3$s</li>', esc_html( $from ), esc_html( $to ), esc_html( $error ) );
			}
			echo '</ul>';
		}
		foreach ( $notice['capped'] as $path ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of descendants, 2: new path of the page or term. */
						__( 'Only the first %1$d pages or terms under %2$s got a redirect from their old address; the others answer "not found" there.', 'easyrankly' ),
						self::MAX_DESCENDANTS,
						$path
					)
				)
			);
		}
		if ( current_user_can( 'manage_options' ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( admin_url( 'admin.php?page=' . Page::SLUG ) ), esc_html__( 'Add the missing redirects', 'easyrankly' ) );
		}
		echo '</div>';
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
	 * @return string|null Why the rule was not saved, or null when it was.
	 */
	private function save( string $from, string $to ): ?string {
		$stale = Redirects::find_id( Rule::hash( $to ) );
		if ( null !== $stale ) {
			wp_update_post(
				array(
					'ID'          => $stale,
					'post_status' => 'draft',
				)
			);
		}

		$saved = Redirects::save_exact( $from, $to );
		if ( ! is_wp_error( $saved ) ) {
			return null;
		}

		if ( null !== $stale ) {
			wp_update_post(
				array(
					'ID'          => $stale,
					'post_status' => 'publish',
				)
			);
		}

		return $saved->get_error_message();
	}
}
