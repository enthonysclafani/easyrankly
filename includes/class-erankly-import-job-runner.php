<?php
/** Batched EasyRankly JSON import, driven by the admin page one request per batch. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a complete EasyRankly export in bounded batches. The uploaded JSON stays in the private upload store and
 * the active job option holds the cursor, so an interrupted import resumes when the Import / Export page is opened
 * again. Every write is an idempotent upsert, so a batch that is replayed after an interruption is harmless.
 */
final class ERankly_Import_Job_Runner {
	private const FILE_PREFIX = 'erankly-import-';
	private const STAGES      = array( 'settings', 'redirects', 'user_meta', 'post_meta', 'term_meta' );

	/** Export document versions this importer can restore. */
	public const SUPPORTED_FORMATS = array( '2.0', '3.0', '4.0' );

	/**
	 * Stores an HTTP upload privately and creates the import job.
	 *
	 * @param array<string,mixed> $file Normalized $_FILES entry.
	 * @param array<string,mixed> $data Already validated decoded payload.
	 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
	 */
	public static function start( array $file, array $data, int $maximum ): array {
		$error = self::start_error( $data );
		if ( '' !== $error ) {
			return array(
				'ok'    => false,
				'error' => $error,
			);
		}

		$stored = ERankly_Migration_Upload_Store::store_import_http_upload( $file, $maximum );
		if ( empty( $stored['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => sanitize_key( (string) ( $stored['error'] ?? 'private_storage_write_failed' ) ),
			);
		}

		return self::create_job( (string) $stored['path'], $data, false );
	}

	/**
	 * Starts a restore from a backup file this plugin already owns (the migration's pre-import backup). A restore
	 * replaces EasyRankly data with the snapshot instead of merging into it.
	 *
	 * @param array<string,mixed> $data Decoded backup document.
	 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
	 */
	public static function start_from_file( string $path, array $data ): array {
		$error = self::start_error( $data );
		if ( '' === $error && ( ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) || ! ERankly_Migration_Upload_Store::owns( $path ) ) ) {
			$error = 'invalid_upload';
		}
		if ( '' !== $error ) {
			return array(
				'ok'    => false,
				'error' => $error,
			);
		}

		return self::create_job( $path, $data, true );
	}

	/** @return array<string,mixed>|null */
	public static function active_job(): ?array {
		$job = get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, null );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Applies the next batch of the active job and saves its cursor.
	 *
	 * @return array<string,mixed>|null The updated job, or null when the job finished (see ERANKLY_IMPORT_LAST_RESULT_OPTION).
	 */
	public static function process( string $job_id ): ?array {
		$job = self::active_job();
		if ( ! is_array( $job ) || ! hash_equals( (string) $job['id'], $job_id ) ) {
			return null;
		}

		unset( $job['error'] );

		try {
			$data = self::read_source( (string) ( $job['path'] ?? '' ) );
			self::apply_next_batch( $data, $job );
			unset( $data );
			$job['updated_at'] = gmdate( 'c' );

			if ( 'complete' === (string) $job['stage'] ) {
				self::finish( $job, 'complete' );
				return null;
			}
			update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, false );

			return $job;
		} catch ( Throwable $error ) {
			$job['error'] = sanitize_text_field( get_class( $error ) );
			// A restore that already cleared live data keeps its job so it can be resumed instead of abandoned.
			if ( ! empty( $job['owned_purged'] ) ) {
				update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, false );
				return $job;
			}
			self::finish( $job, 'failed' );

			return null;
		}
	}

	/** Cancels the active job unless a restore has already cleared live data. */
	public static function cancel( string $job_id ): bool {
		$job = self::active_job();
		if ( ! is_array( $job ) || ! hash_equals( (string) $job['id'], $job_id ) || ! empty( $job['owned_purged'] ) ) {
			return false;
		}
		self::finish( $job, 'cancelled' );

		return true;
	}

	/** Returns processed and total record counts for progress display. */
	public static function progress( array $job ): array {
		$total = 1 + array_sum( array_map( 'absint', is_array( $job['totals'] ?? null ) ? $job['totals'] : array() ) );

		return array(
			'processed' => min( $total, absint( $job['processed'] ?? 0 ) ),
			'total'     => $total,
		);
	}

	/** Shared start checks; returns an error code or an empty string. */
	private static function start_error( array $data ): string {
		if ( is_array( self::active_job() ) ) {
			return 'import_already_running';
		}
		if ( is_array( self::active_migration_job() ) ) {
			return 'migration_already_running';
		}
		if ( 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
			return 'invalid_upload';
		}
		if ( ! in_array( (string) ( $data['format'] ?? '' ), self::SUPPORTED_FORMATS, true ) ) {
			return 'unsupported_format';
		}
		if ( self::payload_contains_custom_code( $data ) && ! current_user_can( 'unfiltered_html' ) ) {
			return 'unfiltered_html_required';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $data Decoded document, used only for the record totals.
	 * @return array{ok:bool,job?:array<string,mixed>,error?:string}
	 */
	private static function create_job( string $path, array $data, bool $purge_owned ): array {
		$totals = array();
		foreach ( array_slice( self::STAGES, 1 ) as $stage ) {
			$totals[ $stage ] = count( is_array( $data[ $stage ] ?? null ) ? $data[ $stage ] : array() );
		}
		$job = array(
			'id'          => wp_generate_uuid4(),
			'path'        => wp_normalize_path( $path ),
			'stage'       => 'settings',
			'offset'      => 0,
			'processed'   => 0,
			'purge_owned' => $purge_owned,
			'counts'      => self::empty_counts(),
			'totals'      => $totals,
			'started_at'  => gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
		);

		// add_option() is an atomic insert, so two concurrent starts cannot both create a job.
		if ( ! add_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, $job, '', false ) ) {
			self::delete_file( $path );
			return array(
				'ok'    => false,
				'error' => is_array( self::active_job() ) ? 'import_already_running' : 'checkpoint_unavailable',
			);
		}
		delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );

		return array(
			'ok'  => true,
			'job' => $job,
		);
	}

	/** @return array<string,mixed>|null */
	private static function active_migration_job(): ?array {
		$job = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/** Detects raw custom-code fields, which only users with unfiltered_html may restore. */
	private static function payload_contains_custom_code( array $data ): bool {
		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		foreach ( array( 'head_code_blocks', 'body_open_code_blocks', 'body_close_code_blocks' ) as $key ) {
			$blocks = is_array( $settings[ $key ] ?? null ) ? $settings[ $key ] : array();
			foreach ( $blocks as $block ) {
				if ( is_array( $block ) && '' !== trim( (string) ( $block['code'] ?? '' ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reads and decodes the private import source.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException When the source is missing or invalid.
	 */
	private static function read_source( string $path ): array {
		if ( ! self::owns( $path ) || ! is_file( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'The private import source disappeared.' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true, ERANKLY_IMPORT_JSON_MAX_DEPTH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local private file.
		if ( ! is_array( $data ) || 'erankly' !== (string) ( $data['plugin'] ?? '' ) ) {
			throw new RuntimeException( 'The private import source is invalid.' );
		}

		return $data;
	}

	/**
	 * Applies one bounded stage page, skipping empty stages.
	 *
	 * @param array<string,mixed> $job Mutable cursor and counters.
	 */
	private static function apply_next_batch( array $data, array &$job ): void {
		$limit = max( 10, min( 500, (int) apply_filters( 'erankly_import_batch_size', ERANKLY_IMPORT_BATCH_SIZE ) ) );

		while ( 'complete' !== (string) $job['stage'] ) {
			$stage  = (string) $job['stage'];
			$offset = absint( $job['offset'] ?? 0 );
			if ( 'settings' === $stage ) {
				// A restore must land on the snapshot, not merge into it: rows the backup does not carry were
				// created after it was taken, so they are removed before the document is replayed.
				if ( ! empty( $job['purge_owned'] ) ) {
					self::purge_owned_data();
					$job['purge_owned']  = false;
					$job['owned_purged'] = true;
				}
				self::apply_settings( $data, $job['counts'], ! empty( $job['owned_purged'] ) );
				++$job['processed'];
				self::advance_stage( $job );
				return;
			}

			$records = is_array( $data[ $stage ] ?? null ) ? $data[ $stage ] : array();
			if ( $offset >= count( $records ) ) {
				self::advance_stage( $job );
				continue;
			}
			$batch = array_slice( $records, $offset, $limit );
			if ( 'redirects' === $stage ) {
				self::apply_redirects( $batch, $job['counts'] );
			} else {
				self::apply_meta( $stage, $batch, $job['counts'] );
			}
			$job['offset']    = $offset + count( $batch );
			$job['processed'] = absint( $job['processed'] ?? 0 ) + count( $batch );
			if ( $job['offset'] >= count( $records ) ) {
				self::advance_stage( $job );
			}
			return;
		}
	}

	/**
	 * Applies settings once, loading the canonical sanitizer outside the settings screen.
	 *
	 * @param bool $replace When true, replace the live option with the snapshot instead of merging.
	 */
	private static function apply_settings( array $data, array &$counts, bool $replace = false ): void {
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			// The sanitizer reads the defaults and the global metadata maps; an AJAX batch renders no admin
			// screen first, so both helper bundles have to be loaded explicitly before it runs.
			erankly_load_default_helpers();
			erankly_load_content_helpers();
			if ( ! function_exists( 'erankly_sanitize_settings' ) ) {
				require_once ERANKLY_PATH . 'includes/admin.php';
				erankly_admin_load_settings_modules();
			}
			$clean = erankly_sanitize_settings( $data['settings'] );
			if ( $replace ) {
				// A restore is a snapshot. Live keys that only exist because they were
				// created after the backup must not be merged back in as "extension" settings.
				$drop_live_extensions = static function () {
					return array();
				};
				add_filter( 'erankly_preserved_extension_settings', $drop_live_extensions );
				try {
					$result = erankly_update_plugin_settings( $clean, true );
				} finally {
					remove_filter( 'erankly_preserved_extension_settings', $drop_live_extensions );
				}
				if ( ! $result ) {
					throw new RuntimeException( 'EasyRankly could not update its settings.' );
				}
			} else {
				erankly_update_plugin_option( ERANKLY_OPTION, $clean );
			}
			$counts['settings'] = 1;
		}
		if ( array_key_exists( 'special_meta', $data ) ) {
			if ( is_array( $data['special_meta'] ) ) {
				erankly_update_special_meta_map( $data['special_meta'] );
			} else {
				$special_meta_sentinel = new stdClass();
				if ( ! delete_option( ERANKLY_SPECIAL_META_OPTION ) && $special_meta_sentinel !== get_option( ERANKLY_SPECIAL_META_OPTION, $special_meta_sentinel ) ) {
					throw new RuntimeException( 'EasyRankly special-page metadata could not be cleared during the restore.' );
				}
			}
			$counts['settings'] = 1;
		}

		/** Fires after a native EasyRankly export has restored core settings. Add-ons may read extra payload keys they own. */
		do_action( 'erankly_imported_payload', $data );
	}

	private static function apply_redirects( array $records, array &$counts ): void {
		erankly_ensure_redirect_classes_available();
		if ( ! class_exists( 'ERankly_Redirects_Repository' ) || ! class_exists( 'ERankly_Redirects_Normalizer' ) ) {
			return;
		}
		if ( class_exists( 'ERankly_Redirects_Activator' ) && ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			ERankly_Redirects_Activator::activate();
		}
		$repository = new ERankly_Redirects_Repository();
		$repository->begin_bulk();
		try {
			foreach ( $records as $row ) {
				if ( ! is_array( $row ) ) {
					++$counts['redirects_invalid'];
					continue;
				}
				if ( '' !== erankly_import_redirect_unsupported_reason( $row ) ) {
					++$counts['redirects_unsupported'];
					continue;
				}
				$legacy_match = in_array( (string) ( $row['match_type'] ?? '' ), array( 'contains', 'starts_with', 'ends_with' ), true );
				$redirect     = erankly_import_prepare_redirect( $row );
				if ( null !== $redirect && in_array( $repository->upsert_by_hash( $redirect ), array( 'created', 'updated' ), true ) ) {
					++$counts['redirects'];
					if ( $legacy_match ) {
						++$counts['redirects_transformed'];
					}
				} elseif ( null === $redirect ) {
					++$counts['redirects_invalid'];
				}
			}
		} finally {
			$repository->end_bulk();
		}
	}

	/** Applies metadata after resolving every object ID in one grouped query. */
	private static function apply_meta( string $stage, array $records, array &$counts ): void {
		global $wpdb;

		$definitions = array(
			'user_meta' => array( 'user', $wpdb->users, 'ID' ),
			'post_meta' => array( 'post', $wpdb->posts, 'ID' ),
			'term_meta' => array( 'term', $wpdb->terms, 'term_id' ),
		);
		if ( ! isset( $definitions[ $stage ] ) ) {
			return;
		}
		list( $object_type, $table, $id_column ) = $definitions[ $stage ];
		$ids                                     = array_values( array_unique( array_filter( array_map( static fn( $entry ): int => is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0, $records ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$existing_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped identity resolution per bounded import batch.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Three identifier replacements precede the dynamic bounded integer list.
				"SELECT %i FROM %i WHERE %i IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only the generated integer placeholder list is interpolated.
				array_merge( array( $id_column, $table, $id_column ), $ids )
			)
		);
		$existing     = array_fill_keys( array_map( 'absint', is_array( $existing_ids ) ? $existing_ids : array() ), true );
		$allowed      = erankly_get_meta_keys();
		foreach ( $records as $entry ) {
			$id  = is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0;
			$key = is_array( $entry ) ? (string) ( $entry['key'] ?? '' ) : '';
			if ( ! isset( $existing[ $id ], $allowed[ $key ] ) ) {
				continue;
			}
			$value   = erankly_sanitize_registered_meta( $entry['value'] ?? '', $key );
			$updated = match ( $object_type ) {
				'post' => update_post_meta( $id, $key, $value ),
				'user' => update_user_meta( $id, $key, $value ),
				'term' => update_term_meta( $id, $key, $value ),
				default => update_metadata( $object_type, $id, $key, $value ),
			};
			if ( false !== $updated ) {
				++$counts[ $stage ];
			}
		}
	}

	/**
 * Removes every value EasyRankly owns before a backup is replayed over it.
 *
 * @throws RuntimeException When a deletion statement fails.
 */
	private static function purge_owned_data(): void {
		global $wpdb;

		$keys         = array_keys( erankly_get_meta_keys() );
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		foreach ( array( $wpdb->postmeta, $wpdb->termmeta ) as $table ) {
			$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk removal of plugin-owned metadata before a restore.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholder list and replacements come from the same fixed key map.
					"DELETE FROM %i WHERE meta_key IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the fixed meta-key placeholder list is interpolated.
					array_merge( array( $table ), $keys )
				)
			);
			if ( false === $deleted ) {
				throw new RuntimeException( 'EasyRankly metadata could not be cleared before the restore.' );
			}
		}
		$deleted_user_meta = erankly_delete_current_site_user_meta( $keys );
		if ( false === $deleted_user_meta ) {
			throw new RuntimeException( 'EasyRankly metadata could not be cleared before the restore.' );
		}
		// A bulk SQL delete leaves the per-object meta caches stale and core exposes no way to invalidate a
		// single meta group. A restore is a rare, explicitly confirmed operation, so a full flush is the
		// correct trade here.
		wp_cache_flush();
		$settings_sentinel = new stdClass();
		if ( is_multisite() ) {
			delete_site_option( ERANKLY_OPTION );
			$remaining_settings = get_site_option( ERANKLY_OPTION, $settings_sentinel );
		} else {
			delete_option( ERANKLY_OPTION );
			$remaining_settings = get_option( ERANKLY_OPTION, $settings_sentinel );
		}
		erankly_clear_settings_cache();
		if ( $settings_sentinel !== $remaining_settings ) {
			throw new RuntimeException( 'EasyRankly settings could not be cleared before the restore.' );
		}
		// The export now always carries this option (as an object or explicit null), so removing it first makes
		// a restore exact even when the snapshot had no special-page metadata. Older exports without the key
		// are also correctly treated as an empty map during a full restore.
		$special_meta_sentinel = new stdClass();
		if ( ! delete_option( ERANKLY_SPECIAL_META_OPTION ) && $special_meta_sentinel !== get_option( ERANKLY_SPECIAL_META_OPTION, $special_meta_sentinel ) ) {
			throw new RuntimeException( 'EasyRankly special-page metadata could not be cleared before the restore.' );
		}

		erankly_ensure_redirect_classes_available();
		if ( class_exists( 'ERankly_Redirects_Repository' ) && erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			$table   = ERankly_Redirects_Repository::get_table_name();
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table cleared before a restore replays it.
			if ( false === $deleted ) {
				throw new RuntimeException( 'Redirects could not be cleared before the restore.' );
			}
			( new ERankly_Redirects_Repository() )->invalidate_runtime_rules();
			if ( function_exists( 'erankly_rotate_redirects_cache_generation' ) ) {
				erankly_rotate_redirects_cache_generation();
			}
		}
	}

	private static function advance_stage( array &$job ): void {
		$index         = array_search( (string) $job['stage'], self::STAGES, true );
		$job['stage']  = false === $index || $index >= count( self::STAGES ) - 1 ? 'complete' : self::STAGES[ $index + 1 ];
		$job['offset'] = 0;
	}

	/** Records the outcome, removes the private file and clears the active job. */
	private static function finish( array $job, string $status ): void {
		$job['status']       = $status;
		$job['completed_at'] = gmdate( 'c' );
		self::delete_file( (string) ( $job['path'] ?? '' ) );
		unset( $job['path'] );
		update_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, $job, false );
		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
	}

	private static function empty_counts(): array {
		return array(
			'settings'             => 0,
			'redirects'            => 0,
			'redirects_transformed' => 0,
			'redirects_unsupported' => 0,
			'redirects_invalid'     => 0,
			'post_meta'            => 0,
			'term_meta'            => 0,
			'user_meta'            => 0,
		);
	}

	/** Verifies that a path is one of this site's managed import files. */
	private static function owns( string $path ): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		$path      = wp_normalize_path( $path );

		return '' !== $directory
			&& hash_equals( wp_normalize_path( $directory ), wp_normalize_path( dirname( $path ) ) )
			&& 1 === preg_match( '/^' . self::FILE_PREFIX . '[a-f0-9]{32}\.json$/', basename( $path ) );
	}

	/** Deletes only a verified managed import file. */
	private static function delete_file( string $path ): bool {
		return self::owns( $path ) && ( ! file_exists( $path ) || ( is_file( $path ) && ! is_link( $path ) && unlink( $path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Guarded private file.
	}

	public static function purge_all(): bool {
		$directory = ERankly_Migration_Upload_Store::directory( false );
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return true;
		}
		$success = true;
		$paths   = glob( $directory . '/' . self::FILE_PREFIX . '*' );
		foreach ( is_array( $paths ) ? $paths : array() as $path ) {
			$success = self::delete_file( (string) $path ) && $success;
		}

		return $success;
	}
}
