<?php
/**
 * Import / Export: bounded readers, JSON profiling, export serializer, the resumable import worker,
 * migration report renderers and the tools panel.
 */

final class ERankly_Import_Export_Test extends WP_UnitTestCase {

	/** @var string[] */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();
		erankly_tests_load_settings_sanitizer();
		require_once ERANKLY_PATH . 'includes/admin.php';
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		require_once ERANKLY_PATH . 'includes/import-export.php';

		if ( ! function_exists( 'erankly_ensure_redirect_classes_available' ) ) {
			require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
		}
		erankly_ensure_redirect_classes_available();

		if ( ! class_exists( 'ERankly_Redirects_Activator', false ) ) {
			require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-activator.php';
		}
		if ( ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			ERankly_Redirects_Activator::activate();
		}
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			}
		}
		$this->temp_files = array();

		ERankly_Migration_Upload_Store::purge_all();
		ERankly_Import_Job_Runner::purge_all();

		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
		delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
		delete_option( 'erankly_migration_reports_v1' );
		unset( $_GET['report_id'], $_GET['erankly_io_notice'], $_GET['erankly_io_action'], $_POST['erankly_io_action'] );

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Creates a user allowed to reach the import/export surface.
	 *
	 * On Multisite the tools panel and the export action gate on `manage_network_options`
	 * (the settings option is a network option there), which only a Super Admin holds; a
	 * per-site administrator is not enough. Granting it here lets the tests exercise the
	 * nonce and rendering behaviour that they are actually about.
	 */
	private function network_admin_user(): int {
		$user_id = $this->admin_user();
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		return $user_id;
	}

	private function capture( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	private function temp_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'erankly-ie-' );
		$this->assertIsString( $path );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}

	private function create_private_import_file( array $data ): string {
		$directory = ERankly_Migration_Upload_Store::directory();
		$this->assertNotSame( '', $directory );

		$path = $directory . '/erankly-import-' . bin2hex( random_bytes( 16 ) ) . '.json';
		file_put_contents( $path, wp_json_encode( $data ) );

		return $path;
	}

	private function run_import_job( string $job_id ): void {
		for ( $i = 0; $i < 40; $i++ ) {
			if ( null === ERankly_Import_Job_Runner::process( $job_id ) ) {
				return;
			}
		}

		$this->fail( 'The import job did not reach completion within the batch budget.' );
	}

	// ---------------------------------------------------------------------
	// actions.php
	// ---------------------------------------------------------------------

	public function test_max_bytes_is_a_positive_integer_and_respects_the_filter(): void {
		$default = erankly_import_export_max_bytes();
		$this->assertIsInt( $default );
		$this->assertGreaterThanOrEqual( 1024, $default );

		add_filter(
			'erankly_import_export_max_bytes',
			static function (): int {
				return 2048;
			}
		);
		$filtered = erankly_import_export_max_bytes();
		$this->assertGreaterThanOrEqual( 1024, $filtered );
		$this->assertLessThanOrEqual( 2048, $filtered );
	}

	public function test_read_bounded_file_reads_within_limit_and_flags_oversize(): void {
		$path = $this->temp_file( 'abcdef' );

		$read = erankly_import_export_read_bounded_file( $path, 100 );
		$this->assertTrue( $read['ok'] );
		$this->assertSame( 'abcdef', $read['contents'] );

		$too_large = erankly_import_export_read_bounded_file( $path, 3 );
		$this->assertFalse( $too_large['ok'] );
		$this->assertSame( 'too-large', $too_large['error'] );

		$invalid = erankly_import_export_read_bounded_file( sys_get_temp_dir() . '/erankly-missing-file.json', 100 );
		$this->assertFalse( $invalid['ok'] );
		$this->assertSame( 'invalid', $invalid['error'] );

		$upload = erankly_import_export_read_bounded_upload( $path, 100 );
		$this->assertTrue( $upload['ok'] );
		$this->assertSame( 'abcdef', $upload['contents'] );
	}

	public function test_json_memory_profile_counts_nodes_and_depth(): void {
		$simple = erankly_import_export_json_memory_profile( '{"a":1}' );
		$this->assertTrue( $simple['valid'] );
		$this->assertSame( 3, $simple['nodes'] );
		$this->assertSame( 1, $simple['depth'] );

		$nested = erankly_import_export_json_memory_profile( '{"a":[1,2]}' );
		$this->assertSame( 5, $nested['nodes'] );
		$this->assertSame( 2, $nested['depth'] );

		$this->assertFalse( erankly_import_export_json_memory_profile( '{"a":1' )['valid'] );
	}

	public function test_json_memory_error_flags_invalid_and_too_complex_documents(): void {
		$this->assertSame( '', erankly_import_export_json_memory_error( '{"a":1}' ) );
		$this->assertSame( 'invalid', erankly_import_export_json_memory_error( '{"a":1' ) );
		$this->assertSame( 'too-complex', erankly_import_export_json_memory_error( str_repeat( '[', ERANKLY_IMPORT_JSON_MAX_DEPTH + 5 ) ) );
	}

	public function test_import_export_url_targets_the_settings_tab(): void {
		$url = erankly_import_export_url();
		$this->assertStringContainsString( 'page=erankly', $url );
		$this->assertStringContainsString( 'erankly_tab=import-export', $url );
	}

	public function test_handle_actions_requires_a_nonce_for_export(): void {
		$this->network_admin_user();
		$_GET = array(
			'page'             => 'erankly',
			'erankly_io_action' => 'export',
		);

		$this->expectException( WPDieException::class );
		erankly_import_export_handle_actions();
	}

	public function test_import_and_migration_handlers_require_a_nonce(): void {
		$this->admin_user();
		$_REQUEST = array();

		$died = 0;
		$handlers = array(
			static function (): void {
				erankly_import_export_handle_import();
			},
			static function (): void {
				erankly_import_export_handle_third_party( 'yoast' );
			},
			static function (): void {
				erankly_import_export_handle_migration_job( 'job-probe', 'migration-process' );
			},
			static function (): void {
				erankly_import_export_handle_backup_restore( 'report-probe' );
			},
		);
		foreach ( $handlers as $handler ) {
			try {
				$handler();
			} catch ( WPDieException $exception ) {
				++$died;
			}
		}

		$this->assertSame( 4, $died );
	}

	public function test_migration_report_download_dies_with_404_for_a_missing_report(): void {
		try {
			erankly_migration_report_download( 'no-such-report' );
			$this->fail( 'A missing migration report must terminate the request.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 404, $exception->getCode() );
		}
	}

	// ---------------------------------------------------------------------
	// export.php
	// ---------------------------------------------------------------------

	public function test_export_settings_drops_the_legacy_redirect_exclude_flag(): void {
		$options = get_option( ERANKLY_OPTION, array() );
		$options = is_array( $options ) ? $options : array();
		$options['website_name']            = 'Export Site';
		$options['redirect_exclude_admins'] = 1;
		erankly_tests_set_settings( $options );

		// The runtime stores the settings as a network option on Multisite, so read the
		// same source erankly_get_stored_settings() uses instead of the per-site option.
		$stored = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );
		$this->assertArrayHasKey( 'redirect_exclude_admins', (array) $stored );

		$exported = erankly_export_settings();
		$this->assertArrayNotHasKey( 'redirect_exclude_admins', $exported );
		$this->assertSame( 'Export Site', $exported['website_name'] );
	}

	public function test_export_page_returns_meta_rows_and_ignores_unknown_streams(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_title', 'Export Title' );

		$rows = erankly_export_page( 'post_meta', 0, 100 );
		$this->assertIsArray( $rows );

		$matching = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => '_erankly_title' === ( $row['key'] ?? '' ) && $post_id === (int) ( $row['id'] ?? 0 )
			)
		);
		$this->assertCount( 1, $matching );
		$this->assertSame( 'Export Title', $matching[0]['value'] );
		$this->assertArrayHasKey( '_cursor', $matching[0] );

		$this->assertSame( array(), erankly_export_page( 'not-a-stream', 0, 100 ) );
		$this->assertIsArray( erankly_export_page( 'redirects', 0, 100 ) );
	}

	public function test_export_write_all_writes_the_complete_string(): void {
		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertIsResource( $handle );

		erankly_export_write_all( $handle, 'hello world' );

		rewind( $handle );
		$this->assertSame( 'hello world', stream_get_contents( $handle ) );
		fclose( $handle );
	}

	public function test_export_stream_array_emits_a_valid_json_array(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_title', 'Streamed Title' );

		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertIsResource( $handle );

		erankly_export_stream_array( $handle, 'post_meta', 500 );

		rewind( $handle );
		$decoded = json_decode( (string) stream_get_contents( $handle ), true );
		fclose( $handle );

		$this->assertIsArray( $decoded );
		$this->assertContains( '_erankly_title', array_column( $decoded, 'key' ) );
	}

	public function test_export_write_emits_a_valid_backup_document(): void {
		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertIsResource( $handle );

		erankly_export_write( $handle );

		rewind( $handle );
		$decoded = json_decode( (string) stream_get_contents( $handle ), true );
		fclose( $handle );

		$this->assertIsArray( $decoded );
		$this->assertSame( 'erankly', $decoded['plugin'] );
		$this->assertSame( ERANKLY_EXPORT_FORMAT, $decoded['format'] );
		$this->assertIsArray( $decoded['settings'] );
		foreach ( array( 'redirects', 'post_meta', 'term_meta', 'user_meta', 'special_meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $decoded );
		}
	}

	public function test_export_download_filename_uses_the_dated_json_pattern(): void {
		$this->assertMatchesRegularExpression(
			'/^erankly-export-\d{4}-\d{2}-\d{2}-\d{6}\.json$/',
			erankly_export_download_filename()
		);
	}

	public function test_migration_backup_download_path_is_empty_for_an_unknown_report(): void {
		$this->assertSame( '', erankly_migration_backup_download_path( 'no-such-report' ) );
	}

	public function test_migration_backup_download_redirects_when_the_path_is_missing(): void {
		$location = '';
		$catcher  = static function ( $url ) use ( &$location ) {
			$location = (string) $url;
			throw new WPDieException( (string) $url );
		};
		add_filter( 'wp_redirect', $catcher );

		try {
			erankly_migration_backup_download( 'no-such-report' );
			$this->fail( 'A missing backup must redirect rather than stream.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'erankly_tab=import-export', $location );
			$this->assertStringContainsString( 'erankly_io_notice=migration-backup-expired', $location );
			$this->assertStringContainsString( 'report_id=no-such-report', $location );
		} finally {
			remove_filter( 'wp_redirect', $catcher );
		}
	}

	public function test_import_apply_is_a_deprecated_wrapper_around_the_batch_runner(): void {
		$this->setExpectedDeprecated( 'erankly_import_apply' );

		$result = erankly_import_apply(
			array(
				'plugin' => 'erankly',
				'format' => '4.0',
			)
		);

		$this->assertTrue( $result['done'] );
		$this->assertSame( 'complete', $result['cursor']['stage'] );
	}

	public function test_import_third_party_is_a_deprecated_wrapper(): void {
		$this->setExpectedDeprecated( 'erankly_import_third_party' );

		$result = erankly_import_third_party( 'unknown-source' );

		$this->assertSame( 0, $result['post_meta'] );
		$this->assertSame( 0, $result['term_meta'] );
		$this->assertFalse( $result['queued'] );
		$this->assertSame( '', $result['job_id'] );
	}

	// ---------------------------------------------------------------------
	// import job runner.
	// ---------------------------------------------------------------------

	public function test_active_job_is_null_without_a_checkpoint(): void {
		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
	}

	public function test_start_rejects_an_unsupported_export_format(): void {
		$result = ERankly_Import_Job_Runner::start(
			array(
				'name'     => 'backup.json',
				'type'     => 'application/json',
				'tmp_name' => '/tmp/erankly-does-not-exist.json',
				'error'     => 0,
				'size'      => 1,
			),
			array(
				'plugin' => 'erankly',
				'format' => '9.9',
			),
			1024
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsupported_format', $result['error'] );
		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
	}

	public function test_start_from_file_rejects_a_path_outside_private_storage(): void {
		$result = ERankly_Import_Job_Runner::start_from_file(
			'/tmp/erankly-not-owned.json',
			array(
				'plugin' => 'erankly',
				'format' => '4.0',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_upload', $result['error'] );
	}

	public function test_start_from_file_stages_spools_and_completes_the_import(): void {
		$post_id = self::factory()->post->create();

		$data = array(
			'plugin'       => 'erankly',
			'format'       => '4.0',
			'settings'     => array( 'website_name' => 'Imported Site' ),
			'special_meta' => null,
			'redirects'    => array(
				array(
					'source_path'    => '/imported-source',
					'source_query'   => '',
					'target_url'     => '/imported-target',
					'status_code'    => 301,
					'match_type'     => 'exact',
					'case_sensitive' => 0,
					'trailing_slash' => 'ignore',
					'query_mode'     => 'ignore',
					'is_active'      => 1,
					'note'           => '',
				),
			),
			'user_meta'    => array(),
			'post_meta'    => array(
				array(
					'id'    => $post_id,
					'key'   => '_erankly_title',
					'value' => 'Imported Title',
				),
			),
			'term_meta'    => array(),
		);

		$path = $this->create_private_import_file( $data );
		$this->assertFileExists( $path );

		$started = ERankly_Import_Job_Runner::start_from_file( $path, $data );
		$this->assertTrue( $started['ok'], 'start_from_file() should stage the private document.' );
		$job     = $started['job'];
		$job_id  = (string) $job['id'];
		$spool   = $path . '.spool';
		$this->assertFileExists( $spool );
		$this->assertSame( $job, ERankly_Import_Job_Runner::active_job() );

		$this->run_import_job( $job_id );

		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
		$this->assertFileDoesNotExist( $spool );
		$this->assertFalse( wp_next_scheduled( ERANKLY_IMPORT_CRON_HOOK, array( $job_id ) ) );

		$finished = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );
		$this->assertIsArray( $finished );
		$this->assertSame( 'complete', $finished['status'] );

		$counts = $finished['counts'];
		$this->assertSame( 1, $counts['redirects'] );
		$this->assertSame( 0, $counts['redirects_invalid'] );
		$this->assertSame( 1, $counts['post_meta'] );
		$this->assertSame( 1, $counts['settings'] );

		$this->assertSame( 'Imported Title', get_post_meta( $post_id, '_erankly_title', true ) );

		$repository = new ERankly_Redirects_Repository();
		$imported   = $repository->list_redirects( 'imported-source', 1, 10 );
		$this->assertCount( 1, $imported );
		$this->assertSame( '/imported-target', $imported[0]['target_url'] );
	}

	public function test_process_fails_safely_when_the_private_spool_is_tampered_with(): void {
		$data = array(
			'plugin'       => 'erankly',
			'format'       => '4.0',
			'settings'     => array( 'website_name' => 'Tampered' ),
			'special_meta' => null,
			'redirects'    => array(),
			'user_meta'    => array(),
			'post_meta'    => array(),
			'term_meta'    => array(),
		);

		$path    = $this->create_private_import_file( $data );
		$started = ERankly_Import_Job_Runner::start_from_file( $path, $data );
		$this->assertTrue( $started['ok'] );

		$job_id = (string) $started['job']['id'];
		$spool  = $path . '.spool';
		// Grow the spool so its size no longer matches the durable checkpoint.
		file_put_contents( $spool, "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test tampering with a private spool.
		clearstatcache( true, $spool );

		$this->assertNull( ERankly_Import_Job_Runner::process( $job_id ) );

		$finished = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );
		$this->assertIsArray( $finished );
		$this->assertSame( 'failed', $finished['status'] );
		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
	}

	public function test_purge_all_removes_managed_import_files(): void {
		$path = $this->create_private_import_file( array( 'plugin' => 'erankly' ) );
		$this->assertFileExists( $path );

		$this->assertTrue( ERankly_Import_Job_Runner::purge_all() );
		$this->assertFileDoesNotExist( $path );
	}

	// ---------------------------------------------------------------------
	// report.php
	// ---------------------------------------------------------------------

	public function test_migration_format_datetime_formats_and_passes_through_invalid(): void {
		$formatted = erankly_migration_format_datetime( '2024-05-06 07:08:09' );
		$this->assertStringContainsString( '2024', $formatted );
		$this->assertNotSame( '2024-05-06 07:08:09', $formatted );

		$this->assertSame( 'not-a-date', erankly_migration_format_datetime( 'not-a-date' ) );
	}

	public function test_migration_guided_copy_returns_state_specific_titles(): void {
		$complete = erankly_migration_guided_copy( array( 'state' => 'complete' ) );
		$this->assertSame( 'Migration complete', $complete['title'] );
		$this->assertNotSame( '', $complete['instruction'] );

		$fallback = erankly_migration_guided_copy( array( 'state' => 'something-else' ) );
		$this->assertSame( 'Migration report', $fallback['title'] );

		$source_active = erankly_migration_guided_copy(
			array(
				'state'           => 'source_active',
				'source_label'    => 'Yoast SEO',
			)
		);
		$this->assertStringContainsString( 'Yoast SEO', $source_active['instruction'] );
	}

	public function test_migration_render_steps_marks_current_step(): void {
		$markup = $this->capture(
			static function (): void {
				erankly_migration_render_steps(
					array(
						'state' => 'preview_ready',
						'step'  => 2,
					)
				);
			}
		);

		$this->assertStringContainsString( 'is-current', $markup );
		$this->assertStringContainsString( 'aria-current="step"', $markup );
	}

	public function test_migration_render_guided_action_outputs_expected_controls(): void {
		$open_plugins = $this->capture(
			static function (): void {
				erankly_migration_render_guided_action(
					array(
						'primary_action' => 'open_plugins',
						'source_label'   => 'Yoast SEO',
					),
					array( 'id' => 'report-1', 'source' => 'yoast' )
				);
			}
		);
		$this->assertStringContainsString( 'Open Plugins in a new tab', $open_plugins );

		$run_import = $this->capture(
			static function (): void {
				erankly_migration_render_guided_action(
					array(
						'primary_action' => 'run_import',
						'can_run_import' => true,
					),
					array( 'id' => 'report-1', 'source' => 'yoast' )
				);
			}
		);
		$this->assertStringContainsString( 'Import the reviewed data', $run_import );
		$this->assertStringContainsString( 'value="migrate"', $run_import );
	}

	public function test_migration_render_attention_skips_without_problems_and_lists_blocking_warnings(): void {
		$this->assertSame(
			'',
			$this->capture(
				static function (): void {
					erankly_migration_render_attention( array( 'problem_count' => 0 ), array() );
				}
			)
		);

		$markup = $this->capture(
			static function (): void {
				erankly_migration_render_attention(
					array( 'problem_count' => 1 ),
					array(
						'warnings' => array(
							array(
								'message'  => 'Blocking warning',
								'blocking' => true,
							),
							array(
								'message'  => 'Non blocking warning',
								'blocking' => false,
							),
						),
						'details'  => array( array( 'code' => 'x' ) ),
					)
				);
			}
		);

		$this->assertStringContainsString( 'Items needing attention', $markup );
		$this->assertStringContainsString( 'Blocking warning', $markup );
		$this->assertStringNotContainsString( 'Non blocking warning', $markup );
	}

	public function test_migration_render_report_renders_a_persisted_report(): void {
		$this->admin_user();

		$manager                  = erankly_migration_manager();
		$report                   = $manager->new_report( 'yoast', true );
		$report['status']         = 'complete';
		$report['source_fingerprint_verified'] = true;
		$manager->finish_report( $report );
		$_GET['report_id']        = (string) $report['id'];

		$markup = $this->capture( static function (): void { erankly_migration_render_report(); } );

		$this->assertStringContainsString( 'Migration assistant', $markup );
		$this->assertStringContainsString( 'Download technical report', $markup );
	}

	public function test_migration_render_active_job_outputs_progress_and_resume(): void {
		$markup = $this->capture(
			static function (): void {
				erankly_migration_render_active_job(
					array(
						'id'      => 'job-1',
						'source'  => 'yoast',
						'status'  => 'paused',
						'stream'  => 'content',
						'dry_run' => true,
						'counts'  => array(),
						'batches' => 2,
					)
				);
			}
		);

		$this->assertStringContainsString( 'Migration paused safely', $markup );
		$this->assertStringContainsString( 'Resume migration', $markup );
	}

	public function test_third_party_data_exists_reports_availability(): void {
		$this->assertFalse( erankly_third_party_data_exists( 'unknown-source' ) );
		$this->assertIsBool( erankly_third_party_data_exists( 'yoast' ) );
	}

	// ---------------------------------------------------------------------
	// panel.php
	// ---------------------------------------------------------------------

	public function test_import_export_render_panel_renders_tools_for_an_admin(): void {
		$this->network_admin_user();

		$markup = $this->capture( static function (): void { erankly_import_export_render_panel(); } );

		$this->assertStringContainsString( 'Export data', $markup );
		$this->assertStringContainsString( 'Import file', $markup );
	}

	public function test_import_export_render_panel_is_empty_for_a_non_admin(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$this->assertSame( '', $this->capture( static function (): void { erankly_import_export_render_panel(); } ) );
	}

	public function test_import_export_render_notice_variants(): void {
		$this->assertSame( '', $this->capture( static function (): void { erankly_import_export_render_notice(); } ) );

		$_GET['erankly_io_notice'] = 'invalid';
		$invalid                   = $this->capture( static function (): void { erankly_import_export_render_notice(); } );
		$this->assertStringContainsString( 'The file could not be imported.', $invalid );

		$_GET['erankly_io_notice'] = 'migration-backup-expired';
		$expired                   = $this->capture( static function (): void { erankly_import_export_render_notice(); } );
		$this->assertStringContainsString( 'The pre-import backup is no longer stored.', $expired );

		$_GET['erankly_io_notice'] = 'too-complex';
		$too_complex               = $this->capture( static function (): void { erankly_import_export_render_notice(); } );
		$this->assertStringContainsString( 'too structurally complex', $too_complex );
	}
}
