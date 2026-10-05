<?php
/** Indexed language/group membership: one row per native object. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'erankly_languages';
}

/** Schema work runs once, only for the selected native single-site provider. */
function erankly_mlss_install(): bool {
	if ( 1 === (int) get_option( 'erankly_multilingual_schema', 0 ) ) {
		return true;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = erankly_mlss_table();
	dbDelta( "CREATE TABLE $table (
		kind varchar(8) NOT NULL,
		object_id bigint(20) unsigned NOT NULL,
		language varchar(35) NOT NULL,
		group_id char(36) NOT NULL,
		PRIMARY KEY  (kind,object_id),
		UNIQUE KEY group_language (kind,group_id,language),
		KEY language_objects (kind,language,object_id)
	) " . $wpdb->get_charset_collate() . ' ENGINE=InnoDB;' );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		return false;
	}
	update_option( 'erankly_multilingual_schema', 1, false );
	delete_option( 'rewrite_rules' );
	return true;
}

/** Batch priming prevents one membership query per listed object. */
function erankly_mlss_prime( string $kind, array $ids ): void {
	global $wpdb;
	$missing = array();
	foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
		if ( false === wp_cache_get( "$kind:$id", 'erankly_languages' ) ) {
			$missing[] = $id;
		}
	}
	foreach ( array_chunk( $missing, 500 ) as $batch ) {
		$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, language, group_id FROM ' . erankly_mlss_table() . " WHERE kind = %s AND object_id IN ($placeholders)", array_merge( array( $kind ), $batch ) ), ARRAY_A );
		$found = array_column( $rows, null, 'object_id' );
		foreach ( $batch as $id ) {
			wp_cache_set( "$kind:$id", $found[ $id ] ?? array(), 'erankly_languages' );
		}
	}
}

function erankly_mlss_membership( string $kind, int $id ): array {
	if ( ! in_array( $kind, array( 'post', 'term' ), true ) || $id < 1 ) {
		return array();
	}
	erankly_mlss_prime( $kind, array( $id ) );
	return (array) wp_cache_get( "$kind:$id", 'erankly_languages' );
}

/** Existing unassigned content belongs to the default language without a bulk write. */
function erankly_mlss_get_language( string $kind, int $id ): string {
	$row = erankly_mlss_membership( $kind, $id );
	if ( isset( $row['language'] ) ) {
		return $row['language'];
	}
	$legacy = erankly_mlss_has_legacy() ? get_metadata( $kind, $id, ERANKLY_MLSS_META_KEY, true ) : false;
	$language = is_array( $legacy ) ? array_search( $id, $legacy, true ) : false;
	return false !== $language ? (string) $language : erankly_mlss_get_settings()['default_language'];
}

/** Bounded, resumable conversion of the earlier reciprocal-meta foundation. */
function erankly_mlss_migrate_legacy(): void {
	if ( ! erankly_mlss_has_legacy() ) { return; }
	global $wpdb;
	$complete = true;
	foreach ( array( 'post' => $wpdb->postmeta, 'term' => $wpdb->termmeta ) as $kind => $table ) {
		$id_column = 'post' === $kind ? 'post_id' : 'term_id';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT $id_column AS object_id, meta_value FROM $table WHERE meta_key = %s ORDER BY meta_id LIMIT 100", ERANKLY_MLSS_META_KEY ), ARRAY_A );
		foreach ( $rows as $row ) {
			$id = (int) $row['object_id'];
			$map = maybe_unserialize( $row['meta_value'] );
			$language = is_array( $map ) ? array_search( $id, $map, true ) : false;
			if ( false === $language || ! in_array( $language, erankly_mlss_get_settings()['languages'], true ) || '' === erankly_mlss_object_subtype( $kind, $id ) ) {
				delete_metadata( $kind, $id, ERANKLY_MLSS_META_KEY );
				continue;
			}
			ksort( $map );
			$hash = md5( $kind . wp_json_encode( $map ) );
			$expected_group = substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20 );
			$reciprocal = count( array_unique( $map ) ) === count( $map );
			$subtype = erankly_mlss_object_subtype( $kind, $id );
			foreach ( $map as $tag => $target ) {
				$other = get_metadata( $kind, (int) $target, ERANKLY_MLSS_META_KEY, true );
				if ( is_array( $other ) ) { ksort( $other ); }
				$existing = erankly_mlss_membership( $kind, (int) $target );
				$migrated = $existing && $existing['group_id'] === $expected_group && $existing['language'] === $tag;
				if ( ! is_int( $target ) || $target < 1 || ! in_array( $tag, erankly_mlss_get_settings()['languages'], true ) || erankly_mlss_object_subtype( $kind, $target ) !== $subtype || ( $other !== $map && ! $migrated ) || ( $existing && ! $migrated ) ) {
					$reciprocal = false;
				}
			}
			$group = $reciprocal ? $expected_group : wp_generate_uuid4();
			if ( ! erankly_mlss_membership( $kind, $id ) && false === $wpdb->insert( erankly_mlss_table(), array( 'kind' => $kind, 'object_id' => $id, 'language' => $language, 'group_id' => $group ) ) ) {
				return; // Keep original metadata for a retry after a database failure.
			}
			wp_cache_delete( "$kind:$id", 'erankly_languages' );
			wp_cache_delete( "$kind:group:$group", 'erankly_languages' );
		}
		// Examine the whole batch before removing metadata needed for reciprocity.
		foreach ( $rows as $row ) {
			if ( erankly_mlss_membership( $kind, (int) $row['object_id'] ) ) {
				delete_metadata( $kind, (int) $row['object_id'], ERANKLY_MLSS_META_KEY );
			}
		}
		if ( count( $rows ) === 100 ) { $complete = false; }
		if ( count( $rows ) === 100 && ! wp_next_scheduled( 'erankly_multilingual_migrate' ) ) {
			wp_schedule_single_event( time() + 30, 'erankly_multilingual_migrate' );
		}
	}
	if ( $complete ) { update_option( 'erankly_multilingual_migrated', 1, false ); erankly_flush_sitemap_cache(); }
}

/** Reuse the core's expiring, token-owned lease only on editorial writes. */
function erankly_mlss_lock( string $key ): string {
	if ( isset( $GLOBALS['erankly_mlss_locks'][ $key ] ) ) {
		++$GLOBALS['erankly_mlss_locks'][ $key ]['depth'];
		return $GLOBALS['erankly_mlss_locks'][ $key ]['token'];
	}
	require_once ERANKLY_PATH . 'includes/class-erankly-job-lease.php';
	$token = ERankly_Job_Lease::acquire( 'erankly_multilingual_lock_', $key );
	if ( '' !== $token ) { $GLOBALS['erankly_mlss_locks'][ $key ] = array( 'token' => $token, 'depth' => 1 ); }
	return $token;
}
function erankly_mlss_unlock( string $key, string $token ): void {
	if ( isset( $GLOBALS['erankly_mlss_locks'][ $key ] ) && --$GLOBALS['erankly_mlss_locks'][ $key ]['depth'] > 0 ) { return; }
	unset( $GLOBALS['erankly_mlss_locks'][ $key ] );
	ERankly_Job_Lease::release( 'erankly_multilingual_lock_', $key, $token );
}
function erankly_mlss_group_lock_key( string $kind, int $id ): string {
	$map = erankly_mlss_get_translations( $kind, $id );
	return $kind . ':' . ( $map ? min( $map ) : $id );
}
function erankly_mlss_refresh_membership( string $kind, int $id ): void {
	$row = erankly_mlss_membership( $kind, $id );
	wp_cache_delete( "$kind:$id", 'erankly_languages' );
	if ( $row ) { wp_cache_delete( "$kind:group:{$row['group_id']}", 'erankly_languages' ); }
}

function erankly_mlss_has_legacy(): bool { return 1 !== (int) get_option( 'erankly_multilingual_migrated', 0 ); }
