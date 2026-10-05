<?php
/**
 * Conservative, site-scoped database maintenance. Analysis is read-only; deletion requires a short-lived,
 * user-owned server snapshot and explicit confirmation. Every item is checked again before deletion.
 */
defined( 'ABSPATH' ) || exit;

/** Internal signal used to abort a deletion when a WordPress hook changes the reviewed item. */
final class ERankly_Database_Tools_Item_Changed extends RuntimeException {}

final class ERankly_Database_Tools {
	public const PREVIEW_LIMIT = 500;
	public const BATCH_SIZE    = 20;
	public const PREVIEW_TTL   = 900;
	private const META_LIMIT  = 1000;
	private const LEASE       = 'erankly_tools_lock_';

	/** @return array<string,array<string,mixed>> */
	public static function categories(): array {
		return array(
			'expired_transients' => array(
				'label'       => __( 'Expired transients', 'easyrankly' ),
				'description' => __( 'Only expired temporary cache entries. Active cache entries are preserved.', 'easyrankly' ),
				'recommended' => true,
				'available'   => ! wp_using_ext_object_cache(),
				'reason'      => __( 'A persistent object cache is active. Database transients are excluded to protect cached data.', 'easyrankly' ),
			),
			'orphan_postmeta' => array(
				'label'       => __( 'Orphaned post metadata', 'easyrankly' ),
				'description' => __( 'Metadata whose post no longer exists. Metadata belonging to existing content is preserved.', 'easyrankly' ),
				'recommended' => true,
				'available'   => true,
			),
			'orphan_commentmeta' => array(
				'label'       => __( 'Orphaned comment metadata', 'easyrankly' ),
				'description' => __( 'Metadata whose comment no longer exists. Akismet data on existing comments is preserved.', 'easyrankly' ),
				'recommended' => true,
				'available'   => true,
			),
			'orphan_usermeta' => array(
				'label'       => __( 'Orphaned user metadata', 'easyrankly' ),
				'description' => __( 'Metadata whose user no longer exists. Existing users and their settings are preserved.', 'easyrankly' ),
				'recommended' => true,
				'available'   => ! is_multisite() && current_user_can( 'delete_users' ),
				'reason'      => __( 'Shared user metadata is excluded on Multisite; otherwise permission to delete users is required.', 'easyrankly' ),
			),
			'revisions' => array(
				'label'       => __( 'Old post revisions', 'easyrankly' ),
				'description' => __( 'Revisions older than 30 days for posts and pages, retaining the latest 5 revisions and all autosaves. Includes each removed revision’s metadata.', 'easyrankly' ),
				'recommended' => false,
				'available'   => true,
			),
			'auto_drafts' => array(
				'label'       => __( 'Old automatic drafts', 'easyrankly' ),
				'description' => __( 'Empty automatic drafts untouched for more than 7 days. Normal drafts and drafts with content are preserved. Includes associated metadata and revisions.', 'easyrankly' ),
				'recommended' => false,
				'available'   => true,
			),
			'trashed_posts' => array(
				'label'       => __( 'Old trashed posts and pages', 'easyrankly' ),
				'description' => __( 'Posts and pages trashed for at least 30 days, respecting a longer WordPress trash retention period. Includes associated metadata and revisions. Items with comments, attachments or child content are excluded.', 'easyrankly' ),
				'recommended' => false,
				'available'   => true,
			),
			'spam_comments' => array(
				'label'       => __( 'Old spam comments', 'easyrankly' ),
				'description' => __( 'Ordinary comments on posts and pages marked as spam for more than 30 days, with a verified timestamp and no replies. Includes associated metadata.', 'easyrankly' ),
				'recommended' => false,
				'available'   => true,
			),
			'trashed_comments' => array(
				'label'       => __( 'Old trashed comments', 'easyrankly' ),
				'description' => __( 'Ordinary comments trashed for at least 30 days, respecting a longer WordPress retention period. Requires a verified timestamp and no replies. Includes associated metadata.', 'easyrankly' ),
				'recommended' => false,
				'available'   => true,
			),
		);
	}

	/** The only public mutation entry point; also used by integration tests. */
	public static function dispatch( array $request, string $method = 'POST' ): array|WP_Error {
		if ( ! erankly_tools_enabled() ) {
			return self::error( 'disabled', __( 'The Tools module is disabled.', 'easyrankly' ), 403 );
		}
		if ( 'POST' !== $method ) {
			return self::error( 'method', __( 'Use a POST request for database tools.', 'easyrankly' ), 405 );
		}
		if ( ! get_current_user_id() || ! current_user_can( 'manage_options' ) || is_network_admin() ) {
			return self::error( 'forbidden', __( 'Database tools require administrator access to this site.', 'easyrankly' ), 403 );
		}
		if ( ! is_string( $request['nonce'] ?? null ) || ! wp_verify_nonce( $request['nonce'], 'erankly_database_tools' ) ) {
			return self::error( 'nonce', __( 'Your session expired. Reload this page and try again.', 'easyrankly' ), 403 );
		}
		$mode = $request['mode'] ?? '';
		if ( ! is_string( $mode ) || ! in_array( $mode, array( 'scan', 'preview', 'clean', 'cancel' ), true ) ) {
			return self::error( 'mode', __( 'Unknown database tools action.', 'easyrankly' ) );
		}
		if ( 'scan' === $mode ) {
			try {
				return self::scan();
			} catch ( Throwable $error ) {
				return self::error( 'scan_failed', __( 'Could not analyze the database. No cleanup was started.', 'easyrankly' ), 500 );
			}
		}
		require_once ERANKLY_PATH . 'includes/class-erankly-job-lease.php';
		$lease = ERankly_Job_Lease::acquire( self::LEASE, 'database' );
		if ( '' === $lease ) {
			return self::error( 'busy', __( 'Another cleanup request is running. Try again shortly.', 'easyrankly' ), 409 );
		}
		try {
			if ( 'preview' === $mode ) {
				return self::preview( $request['categories'] ?? null );
			}
			$snapshot = self::load_snapshot( $request['token'] ?? null );
			if ( is_wp_error( $snapshot ) ) {
				return $snapshot;
			}
			if ( 'cancel' === $mode ) {
				delete_transient( self::snapshot_key() );
				return self::public_result( $snapshot );
			}
			if ( ! is_scalar( $request['confirmed'] ?? null ) || '1' !== (string) $request['confirmed'] ) {
				return self::error( 'confirmation', __( 'Confirm the cleanup summary before deleting anything.', 'easyrankly' ) );
			}
			$offset = $request['offset'] ?? null;
			if ( ! is_scalar( $offset ) || ! ctype_digit( (string) $offset ) || (int) $offset > $snapshot['cursor'] ) {
				return self::error( 'offset', __( 'Cleanup progress changed. Analyze again before continuing.', 'easyrankly' ), 409 );
			}
			// A retried/lost response returns the existing checkpoint without deleting the next batch.
			if ( (int) $offset < $snapshot['cursor'] || $snapshot['cursor'] === count( $snapshot['items'] ) ) {
				return self::public_result( $snapshot );
			}
			return self::clean_batch( $snapshot, $lease );
		} catch ( Throwable $error ) {
			return self::error( 'interrupted', __( 'Cleanup was interrupted. Some items may have been removed. Analyze again to check the remaining data.', 'easyrankly' ), 500 );
		} finally {
			ERankly_Job_Lease::release( self::LEASE, 'database', $lease );
		}
	}

	private static function scan(): array {
		$rows = array();
		foreach ( self::categories() as $category => $definition ) {
			$ids = $definition['available'] ? self::candidate_ids( $category, time(), self::PREVIEW_LIMIT + 1 ) : array();
			$rows[ $category ] = array(
				'count'     => min( self::PREVIEW_LIMIT, count( $ids ) ),
				'more'      => count( $ids ) > self::PREVIEW_LIMIT,
				'available' => $definition['available'],
			);
		}
		return array( 'categories' => $rows );
	}

	private static function preview( mixed $selected ): array|WP_Error {
		$definitions = self::categories();
		if ( ! is_array( $selected ) || empty( $selected ) ) {
			return self::error( 'categories', __( 'Select at least one cleanup category.', 'easyrankly' ) );
		}
		foreach ( $selected as $category ) {
			if ( ! is_string( $category ) || ! isset( $definitions[ $category ] ) || ! $definitions[ $category ]['available'] ) {
				return self::error( 'categories', __( 'The selected cleanup category is unavailable.', 'easyrankly' ) );
			}
		}
		$snapshot = array(
			'token'   => wp_generate_uuid4(),
			'user'    => get_current_user_id(),
			'blog'    => get_current_blog_id(),
			'at'      => time(),
			'expires' => time() + self::PREVIEW_TTL,
			'cursor'  => 0,
			'items'   => array(),
			'groups'  => array(),
			'limited' => false,
			'results' => array(),
		);
		foreach ( array_unique( $selected ) as $category ) {
			$remaining = self::PREVIEW_LIMIT - count( $snapshot['items'] );
			$ids       = self::candidate_ids( $category, $snapshot['at'], $remaining + 1 );
			$snapshot['limited'] = $snapshot['limited'] || count( $ids ) > $remaining;
			$group = array( 'label' => $definitions[ $category ]['label'], 'count' => 0, 'metadata' => 0, 'revisions' => 0, 'items' => array() );
			foreach ( array_slice( $ids, 0, $remaining ) as $id ) {
				$record = self::capture( $category, $id, $snapshot['at'] );
				if ( null === $record ) {
					continue;
				}
				unset( $record['data'] );
				$snapshot['items'][] = $record;
				++$group['count'];
				$group['metadata']  += $record['metadata'];
				$group['revisions'] += $record['revisions'];
				$group['items'][]    = $record['label'];
			}
			$snapshot['groups'][ $category ]  = $group;
			$snapshot['results'][ $category ] = array( 'label' => $group['label'], 'removed' => 0, 'skipped' => 0, 'failed' => 0 );
		}
		if ( ! self::save_snapshot( $snapshot ) ) {
			return self::error( 'storage', __( 'Could not save the cleanup preview. Nothing was removed.', 'easyrankly' ), 500 );
		}
		return array(
			'token'   => $snapshot['token'],
			'count'   => count( $snapshot['items'] ),
			'groups'  => array_values( $snapshot['groups'] ),
			'limited' => $snapshot['limited'],
		);
	}

	private static function clean_batch( array $snapshot, string $lease ): array|WP_Error {
		$started = microtime( true );
		$end     = min( count( $snapshot['items'] ), $snapshot['cursor'] + self::BATCH_SIZE );
		while ( $snapshot['cursor'] < $end ) {
			if ( ! ERankly_Job_Lease::owns( self::LEASE, 'database', $lease ) ) {
				throw new RuntimeException( 'Cleanup lease expired.' );
			}
			$item     = $snapshot['items'][ $snapshot['cursor'] ];
			$category = $item['category'];
			$record   = self::categories()[ $category ]['available'] ? self::capture( $category, $item['id'], $snapshot['at'] ) : null;
			$outcome  = 'skipped';
			if ( null !== $record && hash_equals( $item['fingerprint'], $record['fingerprint'] ) ) {
				try {
					$outcome = self::delete_record( $record ) ? 'removed' : 'failed';
				} catch ( ERankly_Database_Tools_Item_Changed $changed ) {
					$outcome = 'skipped';
				}
			}
			++$snapshot['results'][ $category ][ $outcome ];
			++$snapshot['cursor'];
			// Persist every completed item: an interrupted response cannot silently lose a whole batch's report.
			if ( ! self::save_snapshot( $snapshot ) ) {
				throw new RuntimeException( 'Could not checkpoint cleanup.' );
			}
			if ( microtime( true ) - $started > 8 ) {
				break;
			}
		}
		return self::public_result( $snapshot );
	}

	private static function public_result( array $snapshot ): array {
		return array(
			'cursor'  => $snapshot['cursor'],
			'total'   => count( $snapshot['items'] ),
			'done'    => $snapshot['cursor'] === count( $snapshot['items'] ),
			'results' => array_values( $snapshot['results'] ),
		);
	}

	private static function snapshot_key(): string {
		return 'erankly_tools_preview_' . get_current_user_id();
	}

	private static function save_snapshot( array $snapshot ): bool {
		return set_transient( self::snapshot_key(), $snapshot, max( 1, $snapshot['expires'] - time() ) );
	}

	private static function load_snapshot( mixed $token ): array|WP_Error {
		$snapshot = get_transient( self::snapshot_key() );
		if ( ! is_string( $token ) || ! is_array( $snapshot )
			|| $snapshot['user'] !== get_current_user_id() || $snapshot['blog'] !== get_current_blog_id()
			|| $snapshot['expires'] <= time() || ! hash_equals( $snapshot['token'], $token )
		) {
			return self::error( 'preview_expired', __( 'This preview expired or changed. Analyze again before cleaning.', 'easyrankly' ), 409 );
		}
		return $snapshot;
	}

	/** Prepared, bounded discovery queries. Table identifiers always come from the current site's wpdb. */
	private static function candidate_query( string $category, int $at ): string {
		global $wpdb;
		$date30 = wp_date( 'Y-m-d H:i:s', $at - 30 * DAY_IN_SECONDS, wp_timezone() );
		$date7  = wp_date( 'Y-m-d H:i:s', $at - 7 * DAY_IN_SECONDS, wp_timezone() );
		$trash  = $at - max( 30, (int) EMPTY_TRASH_DAYS ) * DAY_IN_SECONDS;
		if ( 'expired_transients' === $category ) {
			$pattern = $wpdb->esc_like( '_transient_timeout_' ) . '%';
			$prefixes = is_multisite() ? 'o.option_name LIKE %s' : '(o.option_name LIKE %s OR o.option_name LIKE %s)';
			$args = array( $wpdb->options, $pattern );
			if ( ! is_multisite() ) {
				$args[] = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';
			}
			$args[] = $wpdb->esc_like( '_transient_timeout_erankly_tools_' ) . '%';
			$args[] = $at;
			return $wpdb->prepare( "SELECT o.option_name AS id FROM %i o WHERE $prefixes AND o.option_name NOT LIKE %s AND CAST(o.option_value AS UNSIGNED) > 0 AND CAST(o.option_value AS UNSIGNED) < %d", $args );
		}
		$meta = self::meta_tables( $category );
		if ( null !== $meta ) {
			return $wpdb->prepare( 'SELECT m.%i AS id FROM %i m WHERE m.%i > 0 AND NOT EXISTS (SELECT 1 FROM %i p WHERE p.%i = m.%i)', $meta['mid'], $meta['table'], $meta['object'], $meta['parent'], $meta['pid'], $meta['object'] );
		}
		if ( 'revisions' === $category ) {
			return $wpdb->prepare(
				"SELECT r.ID AS id FROM %i r INNER JOIN %i p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.post_type IN ('post','page') AND p.post_status NOT IN ('trash','auto-draft') AND r.post_name NOT LIKE %s AND r.post_date > '1970-01-01' AND r.post_date < %s AND r.post_modified < %s AND (SELECT COUNT(*) FROM %i n WHERE n.post_parent = r.post_parent AND n.post_type = 'revision' AND n.post_name NOT LIKE %s AND (n.post_date > r.post_date OR (n.post_date = r.post_date AND n.ID > r.ID))) >= 5",
				$wpdb->posts, $wpdb->posts, '%-autosave-%', $date30, $date30, $wpdb->posts, '%-autosave-%'
			);
		}
		if ( in_array( $category, array( 'auto_drafts', 'trashed_posts' ), true ) ) {
			$base = $wpdb->prepare( "SELECT p.ID AS id FROM %i p WHERE p.post_type IN ('post','page') AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.comment_post_ID = p.ID) AND NOT EXISTS (SELECT 1 FROM %i child WHERE child.post_parent = p.ID AND child.post_type <> 'revision')", $wpdb->posts, $wpdb->comments, $wpdb->posts );
			if ( 'auto_drafts' === $category ) {
				return $base . $wpdb->prepare( " AND p.post_status = 'auto-draft' AND p.post_content = '' AND p.post_excerpt = '' AND p.post_date > '1970-01-01' AND p.post_date < %s AND p.post_modified < %s", $date7, $date7 );
			}
			return $base . $wpdb->prepare( " AND p.post_status = 'trash' AND EXISTS (SELECT 1 FROM %i t WHERE t.post_id = p.ID AND t.meta_key = '_wp_trash_meta_time' AND CAST(t.meta_value AS UNSIGNED) > 0 AND CAST(t.meta_value AS UNSIGNED) < %d)", $wpdb->postmeta, $trash );
		}
		return $wpdb->prepare(
			"SELECT c.comment_ID AS id FROM %i c INNER JOIN %i p ON p.ID = c.comment_post_ID WHERE p.post_type IN ('post','page') AND c.comment_type IN ('','comment') AND c.comment_approved = %s AND NOT EXISTS (SELECT 1 FROM %i child WHERE child.comment_parent = c.comment_ID) AND EXISTS (SELECT 1 FROM %i t WHERE t.comment_id = c.comment_ID AND t.meta_key = '_wp_trash_meta_time' AND CAST(t.meta_value AS UNSIGNED) > 0 AND CAST(t.meta_value AS UNSIGNED) < %d)",
			$wpdb->comments, $wpdb->posts, 'spam_comments' === $category ? 'spam' : 'trash', $wpdb->comments, $wpdb->commentmeta, 'spam_comments' === $category ? $at - 30 * DAY_IN_SECONDS : $trash
		);
	}

	private static function candidate_ids( string $category, int $at, int $limit ): array {
		global $wpdb;
		$ids = $wpdb->get_col( self::candidate_query( $category, $at ) . $wpdb->prepare( ' ORDER BY id ASC LIMIT %d', $limit ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Database analysis failed.' );
		}
		return $ids;
	}

	/** A fresh SELECT, including related rows, produces a private fingerprint; values never reach the browser. */
	private static function capture( string $category, mixed $id, int $at ): ?array {
		global $wpdb;
		// Discovery's complete predicate is repeated against this exact ID. Newly eligible IDs are never added.
		$sql = 'SELECT id FROM (' . self::candidate_query( $category, $at ) . ') eligible WHERE id = %s';
		if ( null === $wpdb->get_var( $wpdb->prepare( $sql, (string) $id ) ) ) {
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Database revalidation failed.' );
			}
			return null;
		}
		$data = array();
		$metadata_count = 0;
		$revision_count = 0;
		$meta = self::meta_tables( $category );
		if ( 'expired_transients' === $category ) {
			$site = str_starts_with( (string) $id, '_site_transient_timeout_' );
			$name = substr( (string) $id, strlen( $site ? '_site_transient_timeout_' : '_transient_timeout_' ) );
			$value_key = ( $site ? '_site_transient_' : '_transient_' ) . $name;
			$data['timeout'] = self::row( $wpdb->options, 'option_name', $id );
			$data['value']   = self::row( $wpdb->options, 'option_name', $value_key );
			$data['value_key'] = $value_key;
			if ( ! $data['timeout'] || ! ctype_digit( $data['timeout']['option_value'] ) || (int) $data['timeout']['option_value'] <= 0 ) {
				return null;
			}
			$label = $name;
		} elseif ( null !== $meta ) {
			$data['row'] = self::row( $meta['table'], $meta['mid'], $id );
			if ( ! $data['row'] ) {
				return null;
			}
			$label = sprintf( '#%d · %s · ID %d', $id, $data['row']['meta_key'], $data['row'][ $meta['object'] ] );
		} elseif ( in_array( $category, array( 'spam_comments', 'trashed_comments' ), true ) ) {
			$data['row']  = self::row( $wpdb->comments, 'comment_ID', $id );
			$data['meta'] = self::metadata( $wpdb->commentmeta, 'comment_id', array( (int) $id ) );
			if ( ! $data['row'] || count( $data['meta'] ) > self::META_LIMIT || ! current_user_can( 'edit_comment', (int) $id ) || ! self::old_status_time( $data['meta'], $at, 'trashed_comments' === $category ) ) {
				return null;
			}
			$metadata_count = count( $data['meta'] );
			$label = sprintf( '#%d · %s', $id, wp_html_excerpt( wp_strip_all_tags( $data['row']['comment_content'] ), 80, '…' ) );
		} else {
			$data['row'] = self::row( $wpdb->posts, 'ID', $id );
			if ( ! $data['row'] ) {
				return null;
			}
			$parent_id = 'revisions' === $category ? (int) $data['row']['post_parent'] : (int) $id;
			if ( ! current_user_can( 'delete_post', $parent_id ) ) {
				return null;
			}
			$data['edit_lock'] = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = '_edit_lock' ORDER BY meta_id", $wpdb->postmeta, $parent_id ) );
			foreach ( $data['edit_lock'] as $lock ) {
				if ( (int) explode( ':', $lock )[0] > time() - 15 * MINUTE_IN_SECONDS ) {
					return null;
				}
			}
			$data['revisions'] = 'revisions' === $category ? array() : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID LIMIT 201", $wpdb->posts, $id ), ARRAY_A );
			if ( count( $data['revisions'] ) > 200 ) {
				return null;
			}
			$post_ids = array_merge( array( (int) $id ), array_map( 'intval', array_column( $data['revisions'], 'ID' ) ) );
			$data['meta'] = self::metadata( $wpdb->postmeta, 'post_id', $post_ids );
			if ( count( $data['meta'] ) > self::META_LIMIT || ( 'trashed_posts' === $category && ! self::old_status_time( array_filter( $data['meta'], static fn( $m ) => (int) $m['post_id'] === (int) $id ), $at, true ) ) ) {
				return null;
			}
			$metadata_count = count( $data['meta'] );
			$revision_count = count( $data['revisions'] );
			$label = sprintf( '#%d · %s', $id, $data['row']['post_title'] ?: __( '(Untitled)', 'easyrankly' ) );
		}
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read cleanup dependencies.' );
		}
		return array( 'category' => $category, 'id' => (string) $id, 'at' => $at, 'label' => $label, 'fingerprint' => hash( 'sha256', serialize( $data ) ), 'metadata' => $metadata_count, 'revisions' => $revision_count, 'data' => $data );
	}

	private static function row( string $table, string $key, mixed $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', $table, $key, (string) $id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read cleanup row.' );
		}
		return $row;
	}

	private static function metadata( string $table, string $column, array $ids ): array {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ($placeholders) ORDER BY meta_id LIMIT %d", array_merge( array( $table, $column ), $ids, array( self::META_LIMIT + 1 ) ) ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read cleanup metadata.' );
		}
		return $rows;
	}

	/** Ambiguous, missing and malformed status timestamps all fail closed. */
	private static function old_status_time( array $meta, int $at, bool $trash ): bool {
		$times = array_values( array_filter( $meta, static fn( $m ) => '_wp_trash_meta_time' === $m['meta_key'] ) );
		return 1 === count( $times ) && ctype_digit( $times[0]['meta_value'] ) && (int) $times[0]['meta_value'] > 0 && (int) $times[0]['meta_value'] < $at - ( $trash ? max( 30, (int) EMPTY_TRASH_DAYS ) : 30 ) * DAY_IN_SECONDS;
	}

	private static function meta_tables( string $category ): ?array {
		global $wpdb;
		return match ( $category ) {
			'orphan_postmeta' => array( 'type' => 'post', 'table' => $wpdb->postmeta, 'mid' => 'meta_id', 'object' => 'post_id', 'parent' => $wpdb->posts, 'pid' => 'ID' ),
			'orphan_commentmeta' => array( 'type' => 'comment', 'table' => $wpdb->commentmeta, 'mid' => 'meta_id', 'object' => 'comment_id', 'parent' => $wpdb->comments, 'pid' => 'comment_ID' ),
			'orphan_usermeta' => array( 'type' => 'user', 'table' => $wpdb->usermeta, 'mid' => 'umeta_id', 'object' => 'user_id', 'parent' => $wpdb->users, 'pid' => 'ID' ),
			default => null,
		};
	}

	private static function delete_record( array $record ): bool {
		global $wpdb;
		$category = $record['category'];
		$data     = $record['data'];
		$meta     = self::meta_tables( $category );
		if ( 'expired_transients' === $category ) {
			$timeout = $data['timeout'];
			if ( null !== $data['value'] ) {
				// One conditional statement deletes a pair only while BOTH stored values still match the preview.
				// Like core's delete_expired_transients(), this bypasses cache reads that can delete during analysis.
				$deleted = $wpdb->query( $wpdb->prepare( 'DELETE v,t FROM %i v INNER JOIN %i t ON t.option_name = %s WHERE v.option_name = %s AND t.option_value = %s AND v.option_value = %s', $wpdb->options, $wpdb->options, $timeout['option_name'], $data['value_key'], $timeout['option_value'], $data['value']['option_value'] ) );
			} else {
				$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $timeout['option_name'], $timeout['option_value'] ) );
			}
			wp_cache_delete( $timeout['option_name'], 'options' );
			wp_cache_delete( $data['value_key'], 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			if ( 0 === $deleted ) {
				throw new ERankly_Database_Tools_Item_Changed();
			}
			return false !== $deleted && $deleted > 0;
		}
		if ( null !== $meta ) {
			$row = $data['row'];
			$object_id = (int) $row[ $meta['object'] ];
			$mid       = (int) $record['id'];
			$value     = maybe_unserialize( $row['meta_value'] );
			$check     = apply_filters( 'delete_' . $meta['type'] . '_metadata_by_mid', null, $mid );
			if ( null !== $check ) {
				return null === self::row( $meta['table'], $meta['mid'], $mid );
			}
			// Core's metadata API cannot express an atomic "parent still absent" condition. Keep its hooks/cache
			// contract around this narrowly scoped delete, rather than risk deleting newly restored metadata.
			do_action( 'delete_' . $meta['type'] . '_meta', array( $mid ), $object_id, $row['meta_key'], $value );
			self::guard_record( $record );
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i = %d AND %i = %d AND meta_key = %s AND meta_value = %s AND NOT EXISTS (SELECT 1 FROM %i p WHERE p.%i = %d)', $meta['table'], $meta['mid'], $mid, $meta['object'], $object_id, $row['meta_key'], $row['meta_value'], $meta['parent'], $meta['pid'], $object_id ) );
			if ( 1 !== $deleted ) {
				if ( 0 === $deleted ) {
					throw new ERankly_Database_Tools_Item_Changed();
				}
				return false;
			}
			wp_cache_delete( $object_id, $meta['type'] . '_meta' );
			do_action( 'deleted_' . $meta['type'] . '_meta', array( $mid ), $object_id, $row['meta_key'], $value );
			return true;
		}
		$is_comment = in_array( $category, array( 'spam_comments', 'trashed_comments' ), true );
		$hook       = $is_comment ? 'delete_comment' : 'before_delete_post';
		$guard      = static function ( $id ) use ( $record ): void {
			if ( (int) $id === (int) $record['id'] ) {
				self::guard_record( $record );
			}
		};
		// Recheck after other plugins' pre-deletion hooks, before core removes any associated data.
		add_action( $hook, $guard, PHP_INT_MAX );
		try {
			if ( $is_comment ) {
				clean_comment_cache( (int) $record['id'] );
				$deleted = wp_delete_comment( (int) $record['id'], true );
			} else {
				clean_post_cache( (int) $record['id'] );
				$deleted = 'revisions' === $category ? wp_delete_post_revision( (int) $record['id'] ) : wp_delete_post( (int) $record['id'], true );
			}
			return (bool) $deleted && null === self::row( $is_comment ? $wpdb->comments : $wpdb->posts, $is_comment ? 'comment_ID' : 'ID', $record['id'] );
		} finally {
			remove_action( $hook, $guard, PHP_INT_MAX );
		}
	}

	private static function guard_record( array $record ): void {
		$fresh = self::capture( $record['category'], $record['id'], $record['at'] );
		if ( null === $fresh || ! hash_equals( $record['fingerprint'], $fresh['fingerprint'] ) ) {
			throw new ERankly_Database_Tools_Item_Changed();
		}
	}

	private static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'erankly_tools_' . $code, $message, array( 'status' => $status ) );
	}
}
