<?php
/**
 * The agent's analysis, run in small steps by the dashboard.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

use EasyRankly\Meta\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Finds what deserves a proposal and asks the AI about one thing per request.
 *
 * No cron: the dashboard calls step() again and again while it is open. Each call looks at
 * a few posts and makes at most one AI request, so it stays short; the position is kept in
 * the `easyrankly_agent` option, so a closed tab resumes where it stopped. Content published
 * since the last look comes first; then a full pass over the published content, at most
 * once every 24 hours.
 *
 * A candidate is a post without its own meta description, or an image attached to a post
 * without alternative text, that has no proposal of that kind yet.
 */
final class Analysis {

	/**
	 * Non-autoloaded option with the state of the analysis.
	 */
	public const OPTION = 'easyrankly_agent';

	/**
	 * Most recently published posts remembered for the next analysis.
	 */
	public const MAX_RECENT = 50;

	/**
	 * Posts examined per step, at most.
	 */
	public const BATCH = 20;

	/**
	 * Seconds between two full passes.
	 */
	public const INTERVAL = DAY_IN_SECONDS;

	/**
	 * Days after which rejected and superseded proposals are deleted.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Proposals deleted per dashboard load, at most.
	 */
	public const CLEANUP_BATCH = 100;

	/**
	 * State of the analysis.
	 *
	 * @return array{recent: list<int>, cursor: int, handled: list<string>, scanned_at: int}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'recent'     => array_values( array_map( 'intval', is_array( $stored['recent'] ?? null ) ? $stored['recent'] : array() ) ),
			'cursor'     => (int) ( $stored['cursor'] ?? 0 ),
			'handled'    => array_values( array_map( 'strval', is_array( $stored['handled'] ?? null ) ? $stored['handled'] : array() ) ),
			'scanned_at' => (int) ( $stored['scanned_at'] ?? 0 ),
		);
	}

	/**
	 * Saves the state, never autoloaded.
	 *
	 * @param array{recent: list<int>, cursor: int, handled: list<string>, scanned_at: int} $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Remembers a newly published post, to look at it first.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function remember( int $post_id ): void {
		$state           = self::state();
		$recent          = array_values( array_diff( $state['recent'], array( $post_id ) ) );
		$recent[]        = $post_id;
		$state['recent'] = array_slice( $recent, -self::MAX_RECENT );
		self::save( $state );
	}

	/**
	 * Whether a full pass is due.
	 *
	 * @return bool
	 */
	public static function due(): bool {
		$state = self::state();

		return $state['cursor'] > 0 || time() - $state['scanned_at'] >= self::INTERVAL;
	}

	/**
	 * One step: finds the next candidate and asks the AI about it.
	 *
	 * @param bool $force Start a full pass even if the last one is recent.
	 * @return array{done: bool, reason: string, item: array{type: string, id: int, title: string}|null, proposal: int, message: string}
	 */
	public static function step( bool $force = false ): array {
		if ( ! Suggestions::ai_available() ) {
			return self::result( true, 'ai_unavailable' );
		}
		if ( Allowlist::daily_limit_reached() ) {
			return self::result( true, 'daily_limit' );
		}

		$state = self::state();
		if ( $force && 0 === $state['cursor'] ) {
			$state['scanned_at'] = 0;
		}

		// Recently published content first. "handled" lists what was already asked about the
		// post in focus, whatever the answer, so a step never asks twice about the same thing.
		while ( array() !== $state['recent'] ) {
			$candidate = self::candidate( $state['recent'][0], $state['handled'] );
			if ( null !== $candidate ) {
				return self::process( $state, $candidate );
			}
			array_shift( $state['recent'] );
			$state['handled'] = array();
		}

		if ( 0 === $state['cursor'] && time() - $state['scanned_at'] < self::INTERVAL ) {
			self::save( $state );
			return self::result( true, 'up_to_date' );
		}

		// The cursor is an offset in ID order: a post added or deleted meanwhile may be seen twice
		// or skipped until the next pass, which is harmless because candidate() is idempotent.
		$ids = get_posts(
			array(
				'post_type'              => Abilities::content_types(),
				'post_status'            => 'publish',
				'has_password'           => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'offset'                 => $state['cursor'],
				'posts_per_page'         => self::BATCH,
				'fields'                 => 'ids',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $ids as $index => $id ) {
			if ( $index > 0 ) {
				$state['handled'] = array();
			}
			$candidate = self::candidate( (int) $id, $state['handled'] );
			if ( null !== $candidate ) {
				// The cursor stays on this post until it has nothing left.
				$state['cursor'] += $index;
				return self::process( $state, $candidate );
			}
		}

		$state['handled'] = array();
		if ( count( $ids ) < self::BATCH ) {
			$state['cursor']     = 0;
			$state['scanned_at'] = time();
			self::save( $state );
			return self::result( true, 'finished' );
		}

		$state['cursor'] += count( $ids );
		self::save( $state );
		return self::result( false, 'scanning' );
	}

	/**
	 * The next thing to propose about a post, if any.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $handled Candidates already asked about in this pass ("type:id").
	 * @return array{type: string, id: int, title: string}|null
	 */
	private static function candidate( int $post_id, array $handled ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, Abilities::content_types(), true ) ) {
			return null;
		}

		if ( ! in_array( 'post:' . $post_id, $handled, true ) && '' === (string) Meta::post( $post_id, 'description' ) && ! self::proposed( $post_id, Actions::POST_SEO ) ) {
			return array(
				'type'  => 'post',
				'id'    => $post_id,
				'title' => get_the_title( $post ),
			);
		}

		foreach ( get_attached_media( 'image', $post ) as $image ) {
			if ( ! in_array( 'image:' . $image->ID, $handled, true ) && '' === trim( (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ) ) && ! self::proposed( $image->ID, Actions::IMAGE_ALT ) ) {
				return array(
					'type'  => 'image',
					'id'    => $image->ID,
					'title' => get_the_title( $image ),
				);
			}
		}

		return null;
	}

	/**
	 * Whether a proposal of this kind exists for the object (any status but failed).
	 *
	 * @param int    $target  Post or image ID.
	 * @param string $ability Ability name.
	 * @return bool
	 */
	private static function proposed( int $target, string $ability ): bool {
		$statuses = array_diff_key( Proposals::STATUSES, array( 'failed' => true ) );

		$ids = get_posts(
			array(
				'post_type'              => Proposals::POST_TYPE,
				'post_status'            => array_values( $statuses ),
				'post_parent'            => $target,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-driven analysis, never on the frontend.
					array(
						'key'   => Proposals::meta_key( 'ability' ),
						'value' => $ability,
					),
				),
			)
		);

		return array() !== $ids;
	}

	/**
	 * Marks a candidate handled, then asks the AI about it with the permissions of the current user.
	 *
	 * @param array{recent: list<int>, cursor: int, handled: list<string>, scanned_at: int} $state     State to save.
	 * @param array{type: string, id: int, title: string}                                   $candidate Candidate.
	 * @return array{done: bool, reason: string, item: array{type: string, id: int, title: string}|null, proposal: int, message: string}
	 */
	private static function process( array $state, array $candidate ): array {
		$state['handled'][] = $candidate['type'] . ':' . $candidate['id'];
		self::save( $state );

		$name    = 'image' === $candidate['type'] ? Suggestions::IMAGE_ALT : Suggestions::POST_SEO;
		$ability = wp_get_ability( $name );
		$result  = null === $ability ? null : $ability->execute( array( 'id' => $candidate['id'] ) );

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return array(
				'done'     => false,
				'reason'   => 'error',
				'item'     => $candidate,
				'proposal' => 0,
				'message'  => is_wp_error( $result ) ? $result->get_error_message() : __( 'The suggestion could not run.', 'easyrankly' ),
			);
		}

		return array(
			'done'     => false,
			'reason'   => 'processed',
			'item'     => $candidate,
			'proposal' => (int) $result['proposal'],
			'message'  => (string) $result['message'],
		);
	}

	/**
	 * Result of a step without a candidate.
	 *
	 * @param bool   $done   Whether there is nothing more to do now.
	 * @param string $reason Why.
	 * @return array{done: bool, reason: string, item: null, proposal: int, message: string}
	 */
	private static function result( bool $done, string $reason ): array {
		return array(
			'done'     => $done,
			'reason'   => $reason,
			'item'     => null,
			'proposal' => 0,
			'message'  => '',
		);
	}

	/**
	 * Deletes one batch of rejected and superseded proposals older than RETENTION_DAYS.
	 *
	 * Runs when the dashboard opens: no cron, and a big backlog shrinks a batch at a time.
	 *
	 * @return int Proposals deleted.
	 */
	public static function cleanup(): int {
		$ids = get_posts(
			array(
				'post_type'              => Proposals::POST_TYPE,
				'post_status'            => array( Proposals::STATUSES['rejected'], Proposals::STATUSES['superseded'] ),
				'date_query'             => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => self::RETENTION_DAYS . ' days ago',
					),
				),
				'posts_per_page'         => self::CLEANUP_BATCH,
				'fields'                 => 'ids',
				'update_post_term_cache' => false,
			)
		);

		$deleted = 0;
		foreach ( $ids as $id ) {
			$deleted += wp_delete_post( (int) $id, true ) ? 1 : 0;
		}

		return $deleted;
	}
}
