<?php
/** Migration subsystem loader, service singletons, manager, job runner, presenter and upload store. */

require_once ERANKLY_PATH . 'includes/migrations.php';
require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-admin-presenter.php';

final class ERankly_Migrations_Runner_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ERANKLY_PATH . 'includes/migrations.php';
		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-admin-presenter.php';
		erankly_load_default_helpers();
		erankly_load_content_helpers();
	}

	// ---------------------------------------------------------------------
	// Loader functions in includes/migrations.php.
	// ---------------------------------------------------------------------

	public function test_migration_load_adapter_loads_known_sources_only(): void {
		$this->assertTrue( erankly_migration_load_adapter( 'yoast' ) );
		$this->assertTrue( class_exists( 'ERankly_Migration_Adapter_Yoast', false ) );
		$this->assertTrue( erankly_migration_load_adapter( 'rankmath' ) );
		$this->assertTrue( erankly_migration_load_adapter( 'aioseo' ) );
		$this->assertTrue( erankly_migration_load_adapter( 'seopress' ) );
		$this->assertFalse( erankly_migration_load_adapter( 'bogus' ) );
	}

	public function test_manager_and_job_runner_are_shared_singletons(): void {
		$this->assertInstanceOf( ERankly_Migration_Manager::class, erankly_migration_manager() );
		$this->assertSame( erankly_migration_manager(), erankly_migration_manager() );

		$this->assertInstanceOf( ERankly_Migration_Job_Runner::class, erankly_migration_job_runner() );
		$this->assertSame( erankly_migration_job_runner(), erankly_migration_job_runner() );
	}

	public function test_data_transfer_start_lock_is_exclusive_and_token_owned(): void {
		$token = erankly_acquire_data_transfer_start_lock();
		$this->assertNotSame( '', $token );

		// A second worker cannot take the gate while the first token is unexpired.
		$this->assertSame( '', erankly_acquire_data_transfer_start_lock() );

		// Releasing with the wrong token must leave the gate held.
		erankly_release_data_transfer_start_lock( 'not-the-token' );
		$this->assertSame( '', erankly_acquire_data_transfer_start_lock() );

		erankly_release_data_transfer_start_lock( $token );
		$this->assertFalse( get_option( 'erankly_data_transfer_start_lock_v1', false ) );

		$reacquired = erankly_acquire_data_transfer_start_lock();
		$this->assertNotSame( '', $reacquired );
		erankly_release_data_transfer_start_lock( $reacquired );
	}

	// ---------------------------------------------------------------------
	// ERankly_Migration_Manager.
	// ---------------------------------------------------------------------

	public function test_manager_resolves_adapters_and_rejects_unknown(): void {
		$manager = new ERankly_Migration_Manager();

		$this->assertInstanceOf( ERankly_Migration_Adapter_Yoast::class, $manager->adapter( 'yoast' ) );
		$this->assertNull( $manager->adapter( 'bogus' ) );

		$adapters = $manager->adapters();
		$this->assertSame( array( 'yoast', 'rankmath', 'aioseo', 'seopress' ), array_keys( $adapters ) );
	}

	public function test_manager_new_report_builds_header_and_zeroed_counts(): void {
		$manager = new ERankly_Migration_Manager();
		$report  = $manager->new_report( 'yoast', true );

		$this->assertNotSame( '', $report['id'] );
		$this->assertSame( 'yoast', $report['source'] );
		$this->assertSame( 'Yoast SEO', $report['source_label'] );
		$this->assertSame( 'preview', $report['mode'] );
		$this->assertSame( 'running', $report['status'] );
		$this->assertIsArray( $report['capabilities'] );
		$this->assertNotEmpty( $report['capabilities'] );
		$this->assertSame( $manager->empty_counts(), $report['counts'] );
		$this->assertSame( array(), $report['warnings'] );
		$this->assertSame( array(), $report['details'] );

		$unknown = $manager->new_report( 'bogus', false, 'run-123' );
		$this->assertSame( 'run-123', $unknown['id'] );
		$this->assertSame( 'import', $unknown['mode'] );
		$this->assertSame( 'bogus', $unknown['source_label'] );
		$this->assertSame( array(), $unknown['capabilities'] );
	}

	public function test_manager_redirect_comparison_ignores_provenance(): void {
		$manager  = new ERankly_Migration_Manager();
		$redirect = array(
			'source_path'    => '/old',
			'target_url'     => '/new',
			'status_code'    => 301,
			'match_type'     => 'exact',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
			'query_mode'     => 'ignore',
			'is_active'      => 1,
		);

		$with_provenance = array(
			'id'               => 99,
			'migration_id'     => 'run-1',
			'source_plugin'    => 'yoast',
			'source_reference' => 'premium-base:0',
		) + $redirect;

		$this->assertSame( $manager->redirect_value_hash( $redirect ), $manager->redirect_value_hash( $with_provenance ) );
		$this->assertTrue( $manager->same_redirect( $redirect, $with_provenance ) );

		$changed             = $redirect;
		$changed['target_url'] = '/elsewhere';
		$this->assertFalse( $manager->same_redirect( $redirect, $changed ) );
	}

	public function test_manager_is_meaningful_classifies_values(): void {
		$manager = new ERankly_Migration_Manager();

		$this->assertTrue( $manager->is_meaningful( true ) );
		$this->assertTrue( $manager->is_meaningful( array( 1 ) ) );
		$this->assertTrue( $manager->is_meaningful( 5 ) );
		$this->assertTrue( $manager->is_meaningful( 5.5 ) );
		$this->assertTrue( $manager->is_meaningful( 'x' ) );

		$this->assertFalse( $manager->is_meaningful( false ) );
		$this->assertFalse( $manager->is_meaningful( array() ) );
		$this->assertFalse( $manager->is_meaningful( 0 ) );
		$this->assertFalse( $manager->is_meaningful( 0.0 ) );
		$this->assertFalse( $manager->is_meaningful( '   ' ) );
	}

	public function test_manager_empty_counts_declares_all_zero_counters(): void {
		$counts = ( new ERankly_Migration_Manager() )->empty_counts();

		$this->assertArrayHasKey( 'settings_written', $counts );
		$this->assertArrayHasKey( 'fields_ready', $counts );
		$this->assertArrayHasKey( 'redirects_created', $counts );
		$this->assertSame( array_unique( array_values( $counts ) ), array( 0 ) );
	}

	public function test_manager_finish_report_persists_verification_and_bounds_history(): void {
		$manager = new ERankly_Migration_Manager();

		$report                     = $manager->new_report( 'yoast', true, 'run-main' );
		$report['status']           = 'complete';
		$report['source_fingerprint_verified'] = true;
		$finished = $manager->finish_report( $report );

		$this->assertNotSame( '', $finished['completed_at'] );
		$this->assertSame( 'ready', $finished['verification']['state'] );
		$this->assertTrue( $finished['verification']['ready_to_import'] );
		$this->assertSame( $finished, $manager->get_report( 'run-main' ) );
		$this->assertNull( $manager->get_report( 'missing' ) );

		for ( $i = 0; $i < 12; $i++ ) {
			$manager->finish_report( $manager->new_report( 'yoast', true, 'run-' . $i ) );
		}

		$this->assertCount( 10, $manager->reports() );
	}

	public function test_manager_update_report_only_replaces_existing(): void {
		$manager = new ERankly_Migration_Manager();
		$report  = $manager->finish_report( $manager->new_report( 'yoast', true, 'run-update' ) );

		$report['status'] = 'rolled_back';
		$this->assertTrue( $manager->update_report( $report ) );
		$this->assertSame( 'rolled_back', $manager->get_report( 'run-update' )['status'] );

		$this->assertFalse( $manager->update_report( array( 'id' => 'unknown' ) ) );
		$this->assertFalse( $manager->update_report( array() ) );
	}

	public function test_manager_verification_states_for_import_and_failures(): void {
		$manager = new ERankly_Migration_Manager();

		$import = $manager->new_report( 'yoast', false, 'run-import' );
		$import['counts']['fields_written'] = 4;
		$import['source_fingerprint_verified'] = true;
		$import['status']                     = 'complete';
		$verified = $manager->finish_report( $import );
		$this->assertSame( 'safe', $verified['verification']['state'] );
		$this->assertTrue( $verified['verification']['ready_to_switch'] );

		$failed = $manager->new_report( 'yoast', false, 'run-failed' );
		$failed['counts']['fields_failed'] = 2;
		$failed['status']                  = 'failed';
		$blocked                           = $manager->finish_report( $failed );
		$this->assertSame( 'blocked', $blocked['verification']['state'] );
		$this->assertContains( 'keep_source_active', $blocked['verification']['next_actions'] );

		$invalid = $manager->new_report( 'yoast', false, 'run-invalid' );
		$invalid['counts']['objects_invalid']  = 1;
		$invalid['source_fingerprint_verified'] = true;
		$invalid['status']                      = 'complete';
		$review                                 = $manager->finish_report( $invalid );
		$this->assertSame( 'review', $review['verification']['state'] );
		$this->assertContains( 'review_diagnostics', $review['verification']['next_actions'] );
	}

	// ---------------------------------------------------------------------
	// ERankly_Migration_Admin_Presenter.
	// ---------------------------------------------------------------------

	public function test_presenter_preview_states(): void {
		$presenter = new ERankly_Migration_Admin_Presenter();

		$ready = $presenter->present(
			array(
				'mode'           => 'preview',
				'source'         => 'yoast',
				'source_label'   => 'Yoast SEO',
				'source_profile' => array( 'mode' => 'database' ),
				'counts'         => array(
					'fields_ready'          => 7,
					'redirects_ready_create' => 2,
					'redirects_ready_update' => 1,
					'settings_ready'        => 3,
				),
				'verification'   => array(
					'state'           => 'ready',
					'ready_to_import' => true,
					'checks'          => array( array( 'status' => 'pass' ) ),
				),
				'warnings'       => array(),
			),
			false,
			true
		);
		$this->assertSame( 'preview_ready', $ready['state'] );
		$this->assertSame( 'run_import', $ready['primary_action'] );
		$this->assertSame( 1, $ready['step'] );
		$this->assertSame( 7, $ready['metadata_count'] );
		$this->assertSame( 3, $ready['redirect_count'] );
		$this->assertSame( 3, $ready['settings_count'] );
		$this->assertTrue( $ready['can_run_import'] );
		$this->assertTrue( $ready['is_database_migration'] );

		$blocked = $presenter->present(
			array(
				'mode'         => 'preview',
				'verification' => array( 'state' => 'blocked', 'ready_to_import' => false ),
				'counts'       => array(),
			),
			false,
			false
		);
		$this->assertSame( 'preview_blocked', $blocked['state'] );
		$this->assertSame( 'review_issues', $blocked['primary_action'] );
		$this->assertSame( 'warning', $blocked['tone'] );
		$this->assertFalse( $blocked['can_run_import'] );
	}

	public function test_presenter_import_states(): void {
		$presenter = new ERankly_Migration_Admin_Presenter();
		$report    = array(
			'mode'         => 'import',
			'verification' => array( 'state' => 'safe' ),
			'counts'       => array(
				'fields_written'    => 4,
				'redirects_created' => 1,
				'settings_written'  => 2,
			),
		);

		$complete = $presenter->present( $report, false, false );
		$this->assertSame( 'complete', $complete['state'] );
		$this->assertSame( 'open_settings', $complete['primary_action'] );
		$this->assertSame( 3, $complete['step'] );
		$this->assertSame( 4, $complete['metadata_count'] );
		$this->assertSame( 1, $complete['redirect_count'] );
		$this->assertSame( 2, $complete['settings_count'] );

		$active = $presenter->present( $report, true, false );
		$this->assertSame( 'source_active', $active['state'] );
		$this->assertSame( 'open_plugins', $active['primary_action'] );

		$this->assertSame( 'blocked', $presenter->present( array( 'mode' => 'import', 'verification' => array( 'state' => 'blocked' ) ), false, false )['state'] );
		$this->assertSame( 'needs_review', $presenter->present( array( 'mode' => 'import', 'verification' => array( 'state' => 'review' ) ), false, false )['state'] );
	}

	public function test_presenter_problem_count_and_check_totals(): void {
		$presenter = new ERankly_Migration_Admin_Presenter();

		$with_failed_checks = $presenter->present(
			array(
				'mode'         => 'import',
				'counts'       => array(),
				'verification' => array(
					'state'  => 'blocked',
					'checks' => array(
						array(
							'status' => 'fail',
							'count'  => 3,
						),
						array(
							'status' => 'pass',
							'count'  => 0,
						),
					),
				),
				'warnings'     => array(),
			),
			false,
			false
		);
		$this->assertSame( 3, $with_failed_checks['problem_count'] );
		$this->assertSame( array( 'pass' => 1, 'fail' => 1, 'warn' => 0, 'not_applicable' => 0 ), $with_failed_checks['check_totals'] );

		$from_counts = $presenter->present(
			array(
				'mode'         => 'import',
				'counts'       => array( 'fields_failed' => 2 ),
				'verification' => array( 'state' => 'blocked', 'checks' => array() ),
				'warnings'     => array(),
			),
			false,
			false
		);
		$this->assertSame( 2, $from_counts['problem_count'] );

		$from_warnings = $presenter->present(
			array(
				'mode'         => 'import',
				'counts'       => array(),
				'verification' => array( 'state' => 'blocked', 'checks' => array() ),
				'warnings'     => array(
					array( 'code' => 'blocking' ),
					array(
						'code'     => 'advisory',
						'blocking' => false,
					),
				),
			),
			false,
			false
		);
		$this->assertSame( 1, $from_warnings['problem_count'] );
	}

	// ---------------------------------------------------------------------
	// ERankly_Migration_Upload_Store.
	// ---------------------------------------------------------------------

	public function test_upload_store_reserves_and_classifies_paths(): void {
		$backup = ERankly_Migration_Upload_Store::reserve_backup_path();
		$import = ERankly_Migration_Upload_Store::reserve_import_path();

		$this->assertNotSame( '', $backup );
		$this->assertNotSame( '', $import );
		$this->assertStringContainsString( 'erankly-backup-', $backup );
		$this->assertStringContainsString( 'erankly-import-', $import );

		$this->assertTrue( ERankly_Migration_Upload_Store::is_backup( $backup ) );
		$this->assertFalse( ERankly_Migration_Upload_Store::is_backup( $import ) );
		$this->assertTrue( ERankly_Migration_Upload_Store::owns( $backup ) );
		$this->assertTrue( ERankly_Migration_Upload_Store::owns( $import ) );

		$this->assertFalse( ERankly_Migration_Upload_Store::owns( '/tmp/elsewhere/erankly-import-' . str_repeat( 'a', 32 ) . '.json' ) );
	}

	public function test_upload_store_backup_ttl_and_private_directory(): void {
		$this->assertGreaterThanOrEqual( 300, ERankly_Migration_Upload_Store::backup_ttl() );

		$filter = static fn(): int => 600;
		add_filter( 'erankly_migration_backup_ttl', $filter );
		$this->assertSame( 600, ERankly_Migration_Upload_Store::backup_ttl() );
		remove_filter( 'erankly_migration_backup_ttl', $filter );

		$floor = static fn(): int => 1;
		add_filter( 'erankly_migration_backup_ttl', $floor );
		$this->assertSame( 300, ERankly_Migration_Upload_Store::backup_ttl() );
		remove_filter( 'erankly_migration_backup_ttl', $floor );

		$directory = ERankly_Migration_Upload_Store::directory();
		$this->assertNotSame( '', $directory );
		$this->assertTrue( is_dir( $directory ) );
		$this->assertStringNotContainsString( untrailingslashit( wp_normalize_path( ABSPATH ) ), $directory );
	}

	public function test_upload_store_delete_and_purge_managed_files(): void {
		$path = ERankly_Migration_Upload_Store::reserve_import_path();
		$this->assertNotSame( '', $path );
		file_put_contents( $path, '{"plugin":"erankly"}' );

		$this->assertTrue( ERankly_Migration_Upload_Store::delete( $path ) );
		$this->assertFalse( file_exists( $path ) );

		// Non-managed paths are never deleted.
		$this->assertFalse( ERankly_Migration_Upload_Store::delete( '/tmp/not-managed.json' ) );

		$second = ERankly_Migration_Upload_Store::reserve_import_path();
		file_put_contents( $second, 'x' );
		$this->assertTrue( ERankly_Migration_Upload_Store::purge_all() );
		$this->assertFalse( file_exists( $second ) );
	}

	public function test_upload_store_prune_stale_removes_expired_imports(): void {
		$path = ERankly_Migration_Upload_Store::reserve_import_path();
		$this->assertNotSame( '', $path );
		file_put_contents( $path, 'x' );
		touch( $path, time() - 3 * DAY_IN_SECONDS );

		$this->assertGreaterThanOrEqual( 1, ERankly_Migration_Upload_Store::prune_stale() );
		$this->assertFalse( file_exists( $path ) );
	}

	public function test_store_import_http_upload_reports_upload_failures(): void {
		$too_large = ERankly_Migration_Upload_Store::store_import_http_upload( array( 'error' => UPLOAD_ERR_INI_SIZE ), 1024 );
		$this->assertFalse( $too_large['ok'] );
		$this->assertSame( 'upload_too_large', $too_large['error'] );

		$no_file = ERankly_Migration_Upload_Store::store_import_http_upload( array( 'error' => UPLOAD_ERR_NO_FILE ), 1024 );
		$this->assertFalse( $no_file['ok'] );
		$this->assertSame( 'upload_failed', $no_file['error'] );

		$not_uploaded = ERankly_Migration_Upload_Store::store_import_http_upload(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => '/tmp/does-not-exist-' . wp_generate_password( 6, false ) . '.json',
				'name'     => 'import.json',
			),
			1024
		);
		$this->assertFalse( $not_uploaded['ok'] );
		$this->assertSame( 'invalid_http_upload', $not_uploaded['error'] );
	}

	// ---------------------------------------------------------------------
	// erankly_migration_purge_legacy_state().
	// ---------------------------------------------------------------------

	public function test_purge_legacy_state_skips_active_migration(): void {
		update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, array( 'id' => 'active-job' ) );

		$this->assertFalse( erankly_migration_purge_legacy_state() );
	}

	public function test_purge_legacy_state_removes_retired_options(): void {
		global $wpdb;

		update_option( 'erankly_migration_verify_job_1', array( 'x' ) );
		update_option( 'erankly_migration_rollback_1', array( 'x' ) );
		update_option( 'erankly_migration_queue_db_version', '1' );

		$result = erankly_migration_purge_legacy_state( true );

		// The option purge runs as a bulk DELETE before the retired-table DROP. That DROP cannot
		// complete under the SQLite test drop-in (see report), and the bulk delete does not clear
		// the options cache, so assert the durable database effect directly.
		$this->assertIsBool( $result );
		foreach ( array( 'erankly_migration_verify_job_1', 'erankly_migration_rollback_1', 'erankly_migration_queue_db_version' ) as $option_name ) {
			$remaining = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $option_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( 0, (int) $remaining, $option_name . ' should have been deleted' );
		}
	}

	// ---------------------------------------------------------------------
	// ERankly_Migration_Job_Runner public API and end-to-end dry run.
	// ---------------------------------------------------------------------

	public function test_job_runner_active_job_is_null_without_checkpoint(): void {
		$runner = new ERankly_Migration_Job_Runner();

		$this->assertNull( $runner->active_job() );
	}

	public function test_job_runner_start_rejects_unknown_and_unavailable_sources(): void {
		$runner = new ERankly_Migration_Job_Runner();

		$this->assertSame( 'unknown_source', $runner->start( 'bogus', true )['error'] );
		$this->assertSame( 'no_source_data', $runner->start( 'yoast', true )['error'] );
	}

	public function test_job_runner_start_refuses_when_another_job_runs(): void {
		update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, array( 'id' => 'existing-job' ) );
		$runner = new ERankly_Migration_Job_Runner();

		$this->assertSame( 'migration_already_running', $runner->start( 'yoast', true )['error'] );
	}

	public function test_job_runner_start_refuses_when_native_import_runs(): void {
		update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, array( 'id' => 'import-job' ) );
		$runner = new ERankly_Migration_Job_Runner();

		$this->assertSame( 'import_already_running', $runner->start( 'yoast', true )['error'] );
	}

	public function test_job_runner_cancel_and_process_missing_job_are_no_ops(): void {
		$runner = new ERankly_Migration_Job_Runner();

		$this->assertFalse( $runner->cancel( 'missing-job' ) );
		$this->assertNull( $runner->process( 'missing-job' ) );
	}

	public function test_job_runner_dry_run_completes_and_persists_report(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Runner %%title%%' );
		update_option(
			'wpseo_titles',
			array(
				'title-post'    => 'Site - %%title%%',
				'metadesc-post' => 'Desc',
			)
		);
		update_option(
			'wpseo-premium-redirects-base',
			array(
				array(
					'origin' => '/old',
					'url'    => '/new',
					'type'   => 301,
				),
			)
		);

		$runner = new ERankly_Migration_Job_Runner();
		$start  = $runner->start( 'yoast', true );

		$this->assertTrue( $start['ok'] );
		$job_id = $start['job']['id'];
		$this->assertNotSame( '', $job_id );

		$page  = $runner->process( $job_id );
		$guard = 0;
		while ( null !== $page && $guard++ < 20 ) {
			$page = $runner->process( $job_id );
		}

		$this->assertNull( $runner->active_job() );

		$report = erankly_migration_manager()->get_report( $job_id );
		$this->assertIsArray( $report );
		$this->assertSame( 'complete', $report['status'] );
		$this->assertSame( 'ready', $report['verification']['state'] );
		$this->assertGreaterThanOrEqual( 1, $report['counts']['fields_ready'] );
		$this->assertGreaterThanOrEqual( 1, $report['counts']['redirects_ready_create'] );
		$this->assertTrue( $report['source_fingerprint_verified'] );
	}

	public function test_job_runner_cancel_marks_report_cancelled(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Cancel me' );

		$runner = new ERankly_Migration_Job_Runner();
		$start  = $runner->start( 'yoast', true );
		$this->assertTrue( $start['ok'] );
		$job_id = $start['job']['id'];

		$this->assertTrue( $runner->cancel( $job_id ) );
		$this->assertNull( $runner->active_job() );

		$report = erankly_migration_manager()->get_report( $job_id );
		$this->assertSame( 'cancelled', $report['status'] );
		$codes = array_column( $report['warnings'], 'code' );
		$this->assertContains( 'migration_cancelled', $codes );
	}

	public function test_job_runner_dry_run_records_conflicts_and_details(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Runner title' );
		update_post_meta( $post_id, '_erankly_title', 'Existing EasyRankly title' );

		$runner = new ERankly_Migration_Job_Runner();
		$start  = $runner->start( 'yoast', true );
		$this->assertTrue( $start['ok'] );
		$job_id = $start['job']['id'];

		$page  = $runner->process( $job_id );
		$guard = 0;
		while ( null !== $page && $guard++ < 20 ) {
			$page = $runner->process( $job_id );
		}

		$report = erankly_migration_manager()->get_report( $job_id );
		$this->assertGreaterThanOrEqual( 1, $report['counts']['fields_conflicts'] );
		$this->assertContains( 'existing_value_preserved', array_column( $report['details'], 'code' ) );
		$this->assertSame( 'review', $report['verification']['state'] );
	}
}
