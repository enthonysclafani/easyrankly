<?php
/**
 * Uninstall cleanup. Removes the plugin-owned table, options, metadata, transients and private uploads for every
 * site of the installation, then deletes the shared network options.
 *
 * Database rows owned by EasyRankly (options, transients found in SQL, post/term/user meta, the redirects table)
 * are verified after each step: a failed DELETE aborts instead of reporting a finished uninstall. Object-cache
 * entries that were never written to SQL are cleared only for a plugin-owned inventory (per-user notices, redirect
 * form state, JSON-LD notices, visibility flags, the rotation-failed flag). Versioned sitemap transients that exist
 * only in a persistent object cache are not enumerable; they expire with their TTL (one hour by default). The
 * namespaced `erankly_redirects` group is flushed when the backend supports flush_group; otherwise leftover keys
 * remain until the cache TTL. The shared `transient` group is never flushed, so other plugins stay intact.
 *
 * The `erankly_` option prefix includes add-on rows that chose the same namespace.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers/redirect-cache.php';
require_once __DIR__ . '/includes/migrations/class-erankly-migration-upload-store.php';
require_once __DIR__ . '/includes/migrations/legacy-cleanup.php';
require_once __DIR__ . '/includes/class-erankly-import-job-runner.php';

global $wpdb;

/**
 * Removes per-site options, the redirect table, post meta and transients for one site. Global settings
 * (erankly_settings, erankly_version) are handled separately because on Multisite they are stored as network
 * options and must be deleted once.
 *
 * @throws RuntimeException When a database cleanup operation fails.
 */
function erankly_uninstall_site(): void {
	global $wpdb;
	if ( ! ERankly_Import_Job_Runner::purge_all() ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private import uploads during uninstall.', 'easyrankly' ) );
	}
	if ( ! ERankly_Migration_Upload_Store::purge_all( true ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove private migration uploads during uninstall.', 'easyrankly' ) );
	}
	if ( ! erankly_migration_purge_legacy_state( true ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove retired migration state during uninstall.', 'easyrankly' ) );
	}

	wp_unschedule_hook( 'erankly_network_reset_batch' );
	wp_unschedule_hook( 'erankly_migration_process_batch' );
	wp_unschedule_hook( 'erankly_import_process_batch' );
	$erankly_active_migration = get_option( 'erankly_migration_active_job_v1', array() );
	if ( is_array( $erankly_active_migration ) && ! empty( $erankly_active_migration['id'] ) ) {
		erankly_uninstall_delete_option_row( 'erankly_migration_lock_' . substr( hash( 'sha256', (string) $erankly_active_migration['id'] ), 0, 24 ) );
		erankly_uninstall_delete_option_row( 'erankly_migration_cancel_' . substr( hash( 'sha256', (string) $erankly_active_migration['id'] ), 0, 24 ) );
	}
	$erankly_active_import = get_option( 'erankly_import_active_job_v1', array() );
	if ( is_array( $erankly_active_import ) && ! empty( $erankly_active_import['id'] ) ) {
		erankly_uninstall_delete_option_row( 'erankly_import_lock_' . substr( hash( 'sha256', (string) $erankly_active_import['id'] ), 0, 24 ) );
	}
	erankly_uninstall_delete_option_row( 'erankly_data_transfer_start_lock_v1' );
	erankly_uninstall_delete_option_row( 'erankly_special_meta' );
	erankly_uninstall_delete_option_row( 'erankly_runtime_state' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_db_version' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_runtime_rules_global' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_runtime_rules_all' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_runtime_rules_prefix_index' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_runtime_rules' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_cache_generation' );
	erankly_uninstall_delete_option_row( 'erankly_flush_rewrite_rules' );
	erankly_uninstall_delete_option_row( 'erankly_rewrite_signature' );
	erankly_uninstall_delete_option_row( 'rewrite_rules' );
	erankly_uninstall_delete_option_row( 'erankly_sitemap_cache_version' );
	erankly_uninstall_delete_option_row( 'erankly_migration_reports_v1' );
	erankly_uninstall_delete_option_row( 'erankly_migration_active_job_v1' );
	erankly_uninstall_delete_option_row( 'erankly_import_active_job_v1' );
	erankly_uninstall_delete_option_row( 'erankly_import_last_result_v1' );

	// One-time migration markers and the redirect upgrade report. Nothing reads them
	// once the plugin is gone, so they are removed with the rest of the inventory.
	erankly_uninstall_delete_option_row( 'erankly_migrated_post_type_schema_v1' );
	erankly_uninstall_delete_option_row( 'erankly_migrated_title_defaults_v1' );
	erankly_uninstall_delete_option_row( 'erankly_migrated_local_business_pages_v1' );
	erankly_uninstall_delete_option_row( 'erankly_local_business_pages_migration_checkpoint' );
	erankly_uninstall_delete_option_row( 'erankly_legacy_social_image_migrated' );
	erankly_uninstall_delete_option_row( 'erankly_redirects_v3_migration_report' );
	// Only ever written by an administrator or an add-on choosing between competing
	// multilingual providers; read through erankly_get_plugin_option().
	erankly_uninstall_delete_option_row( 'erankly_multilingual_provider_id' );

	// Catch-all for option rows without a reader in the inventory above: per-job
	// locks and cancel markers left by a crashed job, plus runtime-rule shards whose
	// prefix index was corrupted. Every plugin option shares the erankly_ prefix,
	// including add-on rows that opted into that namespace.
	erankly_uninstall_delete_prefixed_options();

	$erankly_dropped_redirects = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall cleanup removes the plugin-owned redirects table.
		$wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall intentionally drops plugin-owned storage.
	);

	if ( false === $erankly_dropped_redirects ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove redirect storage during uninstall.', 'easyrankly' ) );
	}

	erankly_redirects_flush_external_caches();

	$erankly_deleted_post_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned post meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_post_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove post metadata during uninstall.', 'easyrankly' ) );
	}

	$erankly_deleted_term_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup removes plugin-owned term meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_term_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove term metadata during uninstall.', 'easyrankly' ) );
	}

	$erankly_deleted_user_meta = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes plugin-owned user meta.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->esc_like( '_erankly_' ) . '%'
		)
	);

	if ( false === $erankly_deleted_user_meta ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove user metadata during uninstall.', 'easyrankly' ) );
	}

	erankly_uninstall_delete_transient_pair( 'erankly_redirect_cache_rotation_failed' );
	erankly_uninstall_delete_prefixed_transients();
	erankly_uninstall_delete_known_cache_transients();
	erankly_uninstall_flush_object_cache();
}

/**
 * Returns whether an option row still exists in SQL. Cache is not consulted: leftover rows after delete_option()
 * with an external object cache would otherwise look gone.
 *
 * @throws RuntimeException When the lookup fails.
 */
function erankly_uninstall_option_row_exists( string $option_name ): bool {
	global $wpdb;

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall verifies physical option rows, not the object cache.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$option_name
		)
	);

	if ( false === $found || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not inspect its options during uninstall.', 'easyrankly' ) );
	}

	return is_string( $found ) && '' !== $found;
}

/**
 * Deletes one option through delete_option() and then removes any leftover SQL row (external object cache, failed
 * cache-only delete). Absence of the row is success. A failed DELETE is not.
 *
 * @throws RuntimeException When the option cannot be removed from the database.
 */
function erankly_uninstall_delete_option_row( string $option_name ): void {
	global $wpdb;

	$wpdb->last_error = '';
	delete_option( $option_name );

	if ( '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its options during uninstall.', 'easyrankly' ) );
	}

	if ( ! erankly_uninstall_option_row_exists( $option_name ) ) {
		return;
	}

	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- External object cache can leave SQL rows after delete_option().
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$option_name
		)
	);

	if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its options during uninstall.', 'easyrankly' ) );
	}

	wp_cache_delete( $option_name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	if ( erankly_uninstall_option_row_exists( $option_name ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its options during uninstall.', 'easyrankly' ) );
	}
}

/**
 * Deletes remaining plugin-owned options through verified row deletion so the object cache stays coherent.
 *
 * @throws RuntimeException When the remaining option names cannot be read or a DELETE fails.
 */
function erankly_uninstall_delete_prefixed_options(): void {
	global $wpdb;

	$batch_size = 200;
	$after_name = '';

	do {
		if ( '' === $after_name ) {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin-owned option rows.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->esc_like( 'erankly_' ) . '%',
					$batch_size
				)
			);
		} else {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin-owned option rows.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->esc_like( 'erankly_' ) . '%',
					$after_name,
					$batch_size
				)
			);
		}

		if ( false === $option_names || '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove its options during uninstall.', 'easyrankly' ) );
		}

		$option_names = array_map( 'strval', (array) $option_names );

		foreach ( $option_names as $option_name ) {
			erankly_uninstall_delete_option_row( $option_name );
		}

		if ( $option_names ) {
			$after_name = (string) end( $option_names );
		}
	} while ( $batch_size === count( $option_names ) );
}

/**
 * Deletes one transient from the object cache and both SQL rows (value and timeout), even when an external
 * object cache made delete_transient() skip the database.
 *
 * @throws RuntimeException When a transient row cannot be removed.
 */
function erankly_uninstall_delete_transient_pair( string $transient ): void {
	global $wpdb;

	$transient = (string) $transient;

	if ( '' === $transient ) {
		return;
	}

	delete_transient( $transient );
	wp_cache_delete( $transient, 'transient' );

	foreach ( array( '_transient_' . $transient, '_transient_timeout_' . $transient ) as $option_name ) {
		$wpdb->last_error = '';
		$deleted          = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- External object cache skips SQL in delete_transient(); orphan timeouts also need an explicit DELETE.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$option_name
			)
		);

		if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during uninstall.', 'easyrankly' ) );
		}

		wp_cache_delete( $option_name, 'options' );
	}

	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	if (
		erankly_uninstall_option_row_exists( '_transient_' . $transient )
		|| erankly_uninstall_option_row_exists( '_transient_timeout_' . $transient )
	) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during uninstall.', 'easyrankly' ) );
	}
}

/**
 * Deletes remaining plugin transients found in SQL, including orphan timeouts without a value row.
 *
 * @throws RuntimeException When the remaining transient names cannot be read or a DELETE fails.
 */
function erankly_uninstall_delete_prefixed_transients(): void {
	global $wpdb;

	$batch_size = 200;
	$after_name = '';

	do {
		if ( '' === $after_name ) {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin transients.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->esc_like( '_transient_erankly_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%',
					$batch_size
				)
			);
		} else {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin transients.
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_name > %s ORDER BY option_name ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->esc_like( '_transient_erankly_' ) . '%',
					$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%',
					$after_name,
					$batch_size
				)
			);
		}

		if ( false === $option_names || '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove transient data during uninstall.', 'easyrankly' ) );
		}

		$option_names = array_map( 'strval', (array) $option_names );
		$seen         = array();

		foreach ( $option_names as $option_name ) {
			if ( str_starts_with( $option_name, '_transient_timeout_erankly_' ) ) {
				$transient = substr( $option_name, strlen( '_transient_timeout_' ) );
			} elseif ( str_starts_with( $option_name, '_transient_erankly_' ) ) {
				$transient = substr( $option_name, strlen( '_transient_' ) );
			} else {
				continue;
			}

			if ( isset( $seen[ $transient ] ) ) {
				continue;
			}

			$seen[ $transient ] = true;
			erankly_uninstall_delete_transient_pair( $transient );
		}

		if ( $option_names ) {
			$after_name = (string) end( $option_names );
		}
	} while ( $batch_size === count( $option_names ) );
}

/**
 * Clears plugin transients that may exist only in a persistent object cache and therefore never appear in SQL.
 */
function erankly_uninstall_delete_known_cache_transients(): void {
	$names = array(
		'erankly_redirect_cache_rotation_failed',
		'erankly_visibility_' . md5( '_erankly_exclude_search' ),
		'erankly_visibility_' . md5( '_erankly_exclude_archive' ),
	);

	foreach ( $names as $transient ) {
		delete_transient( $transient );
		wp_cache_delete( $transient, 'transient' );
	}

	$paged = 1;

	do {
		$query = array(
			'fields' => 'ID',
			'number' => 100,
			'paged'  => $paged,
		);
		if ( is_multisite() ) {
			// blog_id 0 enumerates the network user list, including super admins who are not members
			// of the current site. Deleted users are still absent; their cache-only notices expire
			// with the transient TTL and are not flushed globally.
			$query['blog_id'] = 0;
		}

		$users = get_users( $query );

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;

			if ( $user_id <= 0 ) {
				continue;
			}

			foreach ( array( 'erankly_settings_notices_', 'erankly_invalid_json_ld_', 'erankly_redirect_form_' ) as $prefix ) {
				$transient = $prefix . $user_id;
				delete_transient( $transient );
				wp_cache_delete( $transient, 'transient' );
			}
		}

		++$paged;
	} while ( 100 === count( $users ) );
}

/**
 * Drops the namespaced exact-redirect object-cache group for the current site when the backend supports
 * a group flush. Called after each site's database cleanup so Multisite generations cannot leak across blogs.
 * A full object-cache flush and a flush of the shared `transient` group are intentionally not used.
 */
function erankly_uninstall_flush_object_cache(): void {
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'erankly_redirects' );
	}
}

/**
 * @throws RuntimeException When the lookup fails.
 */
function erankly_uninstall_network_option_row_exists( int $network_id, string $option_name ): bool {
	global $wpdb;

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall verifies physical network option rows.
		$wpdb->prepare(
			'SELECT meta_key FROM %i WHERE site_id = %d AND meta_key = %s LIMIT 1',
			$wpdb->sitemeta,
			$network_id,
			$option_name
		)
	);

	if ( false === $found || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not inspect network options during uninstall.', 'easyrankly' ) );
	}

	return is_string( $found ) && '' !== $found;
}

/**
 * @throws RuntimeException When the network option cannot be removed.
 */
function erankly_uninstall_delete_network_option_row( int $network_id, string $option_name ): void {
	global $wpdb;

	$wpdb->last_error = '';
	delete_network_option( $network_id, $option_name );
	wp_cache_delete( $network_id . ':' . $option_name, 'site-options' );

	if ( '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove network options during uninstall.', 'easyrankly' ) );
	}

	if ( ! erankly_uninstall_network_option_row_exists( $network_id, $option_name ) ) {
		return;
	}

	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- External object cache can leave sitemeta rows after delete_network_option().
		$wpdb->prepare(
			'DELETE FROM %i WHERE site_id = %d AND meta_key = %s',
			$wpdb->sitemeta,
			$network_id,
			$option_name
		)
	);

	if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove network options during uninstall.', 'easyrankly' ) );
	}

	wp_cache_delete( $network_id . ':' . $option_name, 'site-options' );

	if ( erankly_uninstall_network_option_row_exists( $network_id, $option_name ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove network options during uninstall.', 'easyrankly' ) );
	}
}

/**
 * Deletes remaining plugin-owned network options through verified row deletion.
 *
 * @throws RuntimeException When the remaining network option names cannot be read.
 */
function erankly_uninstall_delete_prefixed_network_options( int $network_id ): void {
	global $wpdb;

	$batch_size = 200;
	$after_name = '';

	do {
		if ( '' === $after_name ) {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin-owned network option rows.
				$wpdb->prepare(
					'SELECT meta_key FROM %i WHERE site_id = %d AND meta_key LIKE %s ORDER BY meta_key ASC LIMIT %d',
					$wpdb->sitemeta,
					$network_id,
					$wpdb->esc_like( 'erankly_' ) . '%',
					$batch_size
				)
			);
		} else {
			$option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates leftover plugin-owned network option rows.
				$wpdb->prepare(
					'SELECT meta_key FROM %i WHERE site_id = %d AND meta_key LIKE %s AND meta_key > %s ORDER BY meta_key ASC LIMIT %d',
					$wpdb->sitemeta,
					$network_id,
					$wpdb->esc_like( 'erankly_' ) . '%',
					$after_name,
					$batch_size
				)
			);
		}

		if ( false === $option_names || '' !== (string) $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove network options during uninstall.', 'easyrankly' ) );
		}

		$option_names = array_map( 'strval', (array) $option_names );

		foreach ( $option_names as $option_name ) {
			erankly_uninstall_delete_network_option_row( $network_id, $option_name );
		}

		if ( $option_names ) {
			$after_name = (string) end( $option_names );
		}
	} while ( $batch_size === count( $option_names ) );
}

if ( defined( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY' ) && ERANKLY_UNINSTALL_FUNCTIONS_ONLY ) {
	return;
}

try {
	if ( is_multisite() ) {
		$erankly_site_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count is needed before any destructive work starts.
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->blogs )
		);

		if ( $wpdb->last_error ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not count multisite sites before uninstall.', 'easyrankly' ) );
		}

		// Plugin files disappear as soon as uninstall returns, so a large cleanup
		// cannot continue through WP-Cron. Refuse the unsafe web path before deleting
		// anything and direct the administrator to the timeout-free WP-CLI lifecycle.
		if (
		(int) $erankly_site_count > max( 1, (int) apply_filters( 'erankly_network_web_lifecycle_limit', 100 ) )
		&& ! ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			$erankly_plugin_slug = basename( __DIR__ );
			$erankly_command     = sprintf( 'wp plugin uninstall %s', $erankly_plugin_slug );
			$erankly_message     = '<p>' . esc_html__( 'This installation is too large to uninstall EasyRankly safely in one web request.', 'easyrankly' ) . '</p>';
			$erankly_message    .= '<p>' . esc_html__( 'Run the following WP-CLI command so cleanup can finish without an HTTP timeout:', 'easyrankly' ) . '</p>';
			$erankly_message    .= '<p><code>' . esc_html( $erankly_command ) . '</code></p>';

			wp_die(
				$erankly_message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Message markup contains only escaped translated text and command output.
				esc_html__( 'EasyRankly network cleanup required', 'easyrankly' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}

		// Per-site cleanup is necessarily synchronous during uninstall because the
		// plugin code will no longer be available to a future Cron request. This query
		// intentionally includes every network because plugin deletion removes the
		// shared files installation-wide. Large installations are routed to WP-CLI
		// above; keyset pagination keeps memory bounded there.
		$erankly_last_site_id = 0;
		$erankly_batch_size   = 100;

		do {
			$erankly_site_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded keyset pagination is required for complete network uninstall cleanup.
				$wpdb->prepare(
					'SELECT blog_id FROM %i WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d',
					$wpdb->blogs,
					$erankly_last_site_id,
					$erankly_batch_size
				)
			);

			if ( $wpdb->last_error ) {
				throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve the next network site batch during uninstall.', 'easyrankly' ) );
			}

			$erankly_site_ids   = array_map( 'intval', (array) $erankly_site_ids );
			$erankly_site_count = count( $erankly_site_ids );

			foreach ( $erankly_site_ids as $erankly_site_id ) {
				switch_to_blog( $erankly_site_id );

				try {
					erankly_uninstall_site();
				} finally {
					restore_current_blog();
				}
			}

			if ( $erankly_site_ids ) {
				$erankly_last_site_id = (int) end( $erankly_site_ids );
			}
		} while ( $erankly_batch_size === $erankly_site_count );

		// Removing plugin files is installation-wide, not scoped to the Network Admin
		// that initiated it. Delete network settings for every network only after the
		// per-site sweep has completed, so a failed enumeration cannot erase settings
		// while leaving the rest of the uninstall unfinished.
		$erankly_network_option_names = array(
			'erankly_settings',
			'erankly_version',
			'erankly_rewrite_generation',
			'erankly_network_reset_job',
			'erankly_settings_lock_v1',
			'erankly_migrated_post_type_schema_v1',
			'erankly_migrated_title_defaults_v1',
			'erankly_migrated_local_business_pages_v1',
			'erankly_local_business_pages_migration_checkpoint',
			'erankly_multilingual_provider_id',
		);

		$erankly_last_network_id = 0;
		$erankly_network_batch   = 100;

		do {
			$erankly_network_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps multi-network cleanup memory-bounded.
				$wpdb->prepare(
					'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
					$wpdb->site,
					$erankly_last_network_id,
					$erankly_network_batch
				)
			);

			if ( $wpdb->last_error ) {
				throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve networks during uninstall.', 'easyrankly' ) );
			}

			$erankly_network_ids   = array_map( 'intval', (array) $erankly_network_ids );
			$erankly_network_count = count( $erankly_network_ids );

			foreach ( $erankly_network_ids as $erankly_network_id ) {
				foreach ( $erankly_network_option_names as $erankly_network_option_name ) {
					erankly_uninstall_delete_network_option_row( $erankly_network_id, $erankly_network_option_name );
				}

				erankly_uninstall_delete_prefixed_network_options( $erankly_network_id );
			}

			if ( $erankly_network_ids ) {
				$erankly_last_network_id = (int) end( $erankly_network_ids );
			}
		} while ( $erankly_network_batch === $erankly_network_count );

	} else {
		erankly_uninstall_delete_option_row( 'erankly_settings' );
		erankly_uninstall_delete_option_row( 'erankly_version' );
		erankly_uninstall_delete_option_row( 'erankly_rewrite_generation' );
		erankly_uninstall_delete_option_row( 'erankly_settings_lock_v1' );
		erankly_uninstall_site();
	}
} catch ( Throwable $erankly_uninstall_error ) {
	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Uninstall has no persistent plugin logger; server logs retain the actionable internal failure.
		sprintf(
			'EasyRankly uninstall failed (%1$s): %2$s',
			get_class( $erankly_uninstall_error ),
			sanitize_text_field( $erankly_uninstall_error->getMessage() )
		)
	);

	$erankly_public_error = esc_html__( 'EasyRankly could not complete its data cleanup. No further cleanup steps were attempted. Check the server error log, resolve the reported storage problem, and retry the uninstall.', 'easyrankly' );
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $erankly_public_error );
	}

	wp_die(
		esc_html( $erankly_public_error ),
		esc_html__( 'EasyRankly uninstall incomplete', 'easyrankly' ),
		array(
			'response'  => 500,
			'back_link' => true,
		)
	);
}
