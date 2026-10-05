<?php
/**
 * Expiring, token-owned option lease shared by the import and migration background job runners. Every write is
 * a compare-and-swap on the stored value, so a stale worker can neither steal nor release a successor's lease.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ERankly_Job_Lease {
	public const TTL = 300;

	/** Returns the bounded option name for one job lease. */
	public static function key( string $prefix, string $job_id ): string {
		return $prefix . substr( hash( 'sha256', $job_id ), 0, 24 );
	}

	/**
	 * Acquires the lease, taking over an expired one atomically.
	 *
	 * @return string Lease token, or an empty string when another worker holds it.
	 */
	public static function acquire( string $prefix, string $job_id ): string {
		global $wpdb;

		$key   = self::key( $prefix, $job_id );
		$token = wp_generate_uuid4();
		$lease = array(
			'token'   => $token,
			'expires' => time() + self::TTL,
		);
		if ( add_option( $key, $lease, '', false ) ) {
			return $token;
		}

		$existing = get_option( $key, array() );
		if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) >= time() ) {
			return '';
		}

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap prevents two workers taking over the same stale lease.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $lease ),
				$key,
				maybe_serialize( $existing )
			)
		);
		if ( 1 === $updated ) {
			wp_cache_delete( $key, 'options' );
		}

		return 1 === $updated ? $token : '';
	}

	/** Extends the lease only while this token still owns an unexpired one. */
	public static function renew( string $prefix, string $job_id, string $token ): bool {
		global $wpdb;

		$key      = self::key( $prefix, $job_id );
		$existing = get_option( $key, array() );
		if ( ! self::is_owned( $existing, $token ) ) {
			return false;
		}

		$renewed            = $existing;
		$renewed['expires'] = max( time() + self::TTL, (int) $existing['expires'] + 1 );
		$updated            = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lease renewal fences stale workers before a checkpoint write.
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				maybe_serialize( $renewed ),
				$key,
				maybe_serialize( $existing )
			)
		);
		if ( 1 === $updated ) {
			wp_cache_delete( $key, 'options' );
		}

		return 1 === $updated;
	}

	/** Whether the token still owns an unexpired lease. */
	public static function owns( string $prefix, string $job_id, string $token ): bool {
		return self::is_owned( get_option( self::key( $prefix, $job_id ), array() ), $token );
	}

	/** Releases the lease only when the token still owns it. */
	public static function release( string $prefix, string $job_id, string $token ): void {
		global $wpdb;

		$key      = self::key( $prefix, $job_id );
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token-matched delete cannot release a successor's lease.
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$key,
				maybe_serialize( $existing )
			)
		);
		wp_cache_delete( $key, 'options' );
	}

	/** Queues one background batch unless an identical event is already pending. */
	public static function schedule( string $hook, string $job_id, int $delay = 1 ): bool {
		$args = array( $job_id );
		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			return true;
		}

		$result = wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args, true );

		return ! is_wp_error( $result ) && false !== $result;
	}

	private static function is_owned( mixed $lease, string $token ): bool {
		return is_array( $lease )
			&& (int) ( $lease['expires'] ?? 0 ) >= time()
			&& hash_equals( (string) ( $lease['token'] ?? '' ), $token );
	}
}
