<?php
/** Migration WP-Cron callback and the import batch processor. */

/**
 * Drives `erankly_process_migration_job()` (its WP-Cron callback) and `ERankly_Import_Job_Runner::process()`
 * directly. The assertions are on the durable job checkpoints they advance. Only the terminal and rejected branches
 * are reachable without a real adapter or a valid private source file: an unknown or mismatched job ID leaves the
 * checkpoint untouched, a matching migration job whose adapter is gone is paused, and a matching import job whose
 * source file disappeared is failed.
 */
final class ERankly_Lifecycle_Cron_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
		delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( ERANKLY_MIGRATION_CRON_HOOK );

		delete_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION );
		delete_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION );
		delete_option( ERANKLY_IMPORT_LAST_RESULT_OPTION );

		parent::tear_down();
	}

	/** @return array<string,mixed> */
	private function migration_checkpoint( string $job_id, string $source ): array {
		return array(
			'id'               => $job_id,
			'source'           => $source,
			'dry_run'          => true,
			'status'           => 'queued',
			'stream'           => 'settings',
			'cursor'           => array(),
			'batches'          => 0,
			'cancel_requested' => false,
			'counts'           => array(),
			'last_error'       => '',
			'started_at'       => gmdate( 'c' ),
			'updated_at'       => gmdate( 'c' ),
		);
	}

	/* ----------------------------------------------------------------------
	 * Migration worker
	 * -------------------------------------------------------------------- */

	public function test_process_migration_job_does_nothing_without_a_checkpoint(): void {
		erankly_process_migration_job( 'no-such-job' );

		$this->assertFalse( get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, false ) );
		$this->assertFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK, array( 'no-such-job' ) ) );
	}

	public function test_process_migration_job_ignores_a_mismatched_job_id(): void {
		update_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, $this->migration_checkpoint( 'mig-owner', 'yoast' ), false );

		erankly_process_migration_job( 'mig-other' );

		// The checkpoint belongs to another job and must be left untouched.
		$stored = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, array() );

		$this->assertSame( 'mig-owner', $stored['id'] );
		$this->assertSame( 'queued', $stored['status'] );
		$this->assertFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK, array( 'mig-other' ) ) );
	}

	public function test_process_migration_job_pauses_a_job_whose_adapter_is_unavailable(): void {
		update_option(
			ERANKLY_MIGRATION_ACTIVE_JOB_OPTION,
			$this->migration_checkpoint( 'mig-pause', 'not-a-registered-source' ),
			false
		);

		erankly_process_migration_job( 'mig-pause' );

		$stored = get_option( ERANKLY_MIGRATION_ACTIVE_JOB_OPTION, null );

		// The paused checkpoint is persisted so an administrator can resume it later.
		$this->assertIsArray( $stored );
		$this->assertSame( 'paused', $stored['status'] );
		$this->assertSame( 'RuntimeException', $stored['last_error'] );
		$this->assertSame( 'worker_interrupted', $stored['report']['warnings'][0]['code'] );
	}

	/* ----------------------------------------------------------------------
	 * Import worker
	 * -------------------------------------------------------------------- */

	private function process_import( string $job_id ): void {
		require_once ERANKLY_PATH . 'includes/migrations.php';
		require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
		ERankly_Import_Job_Runner::process( $job_id );
	}

	public function test_process_import_job_does_nothing_without_a_checkpoint(): void {
		$this->process_import( 'no-such-job' );

		$this->assertFalse( get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, false ) );
		$this->assertFalse( get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, false ) );
	}

	public function test_process_import_job_ignores_a_mismatched_job_id(): void {
		update_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, array( 'id' => 'imp-owner' ), false );

		$this->process_import( 'imp-other' );

		$this->assertSame( 'imp-owner', get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, array() )['id'] );
	}

	public function test_process_import_job_fails_a_job_whose_source_file_is_missing(): void {
		update_option(
			ERANKLY_IMPORT_ACTIVE_JOB_OPTION,
			array(
				'id'          => 'imp-missing-source',
				'path'   => '',
				'stage'  => 'settings',
				'counts' => array(),
			),
			false
		);

		$this->process_import( 'imp-missing-source' );

		// The active checkpoint is cleared and the terminal result archived for the admin screen.
		$this->assertFalse( get_option( ERANKLY_IMPORT_ACTIVE_JOB_OPTION, false ) );

		$last = get_option( ERANKLY_IMPORT_LAST_RESULT_OPTION, array() );

		$this->assertIsArray( $last );
		$this->assertSame( 'failed', $last['status'] );
		$this->assertSame( 'RuntimeException', $last['error'] );
		$this->assertSame( 'imp-missing-source', $last['id'] );
	}
}
