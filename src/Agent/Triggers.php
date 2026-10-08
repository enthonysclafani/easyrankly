<?php
/**
 * Editorial events that wake the agent, without cron.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Reacts to what editors do, in the same request and without calling any external service:
 * - published content moved to the trash gets a redirect proposal (decided without AI);
 * - restoring it from the trash supersedes that proposal;
 * - newly published content is remembered, so the dashboard analysis looks at it first.
 */
final class Triggers {

	/**
	 * Hooks the editorial events.
	 */
	public function register(): void {
		add_action( 'trashed_post', array( $this, 'trashed' ) );
		add_action( 'untrashed_post', array( $this, 'untrashed' ) );
		add_action( 'transition_post_status', array( $this, 'published' ), 10, 3 );
	}

	/**
	 * Proposes a redirect for published content moved to the trash.
	 *
	 * The proposal is created whoever trashes the content; applying it still needs manage_options.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function trashed( $post_id ): void {
		$post_id = (int) $post_id;
		if ( in_array( get_post_type( $post_id ), Abilities::content_types(), true ) ) {
			Suggestions::suggest_redirect( array( 'id' => $post_id ) );
		}
	}

	/**
	 * Content back from the trash needs no redirect.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function untrashed( $post_id ): void {
		Proposals::supersede( (int) $post_id, Actions::REDIRECT, __( 'The content was restored from the trash.', 'easyrankly' ) );
	}

	/**
	 * Remembers content when it is published for the first time.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post.
	 */
	public function published( $new_status, $old_status, $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status && $post instanceof \WP_Post && in_array( $post->post_type, Abilities::content_types(), true ) ) {
			Analysis::remember( $post->ID );
		}
	}
}
