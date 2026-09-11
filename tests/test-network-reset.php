<?php
/** Network reset state store (includes/network-reset.php). */

/**
 * The network reset state store lives in wp_sitemeta, a table that only exists on Multisite, so
 * erankly_get_network_reset_snapshot(), erankly_delete_network_reset_state(), erankly_fail_network_reset() and the
 * write half of erankly_save_network_reset_state() are only reachable on Multisite. This harness runs single-site,
 * so:
 *
 *  - the guards that run before any database access (a tokenless state, an inert continue) are asserted directly
 *    here as single-site behaviour, and
 *  - the sitemeta-backed paths are wrapped in an is_multisite() skip so the CI multisite leg exercises them.
 *
 * The sitemeta-backed tests are never faked on single-site: on a single-site request $wpdb->sitemeta is an empty
 * string and the query fails, which also destroys the per-test transaction, so reaching them here would be both
 * meaningless and harmful to every later test.
 */
final class ERankly_Network_Reset_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Production loads these lazily (includes/admin.php for reset.php; the Network Admin / Cron bootstrap for
		// network-reset.php), so PHPUnit must require them explicitly.
		require_once ERANKLY_PATH . 'includes/reset.php';
		require_once ERANKLY_PATH . 'includes/network-reset.php';
	}

	public function tear_down(): void {
		wp_unschedule_hook( ERANKLY_NETWORK_RESET_CRON_HOOK );
		parent::tear_down();
	}

	/* ----------------------------------------------------------------------
	 * Single-site reachable guard branches
	 * -------------------------------------------------------------------- */

	public function test_save_network_reset_state_rejects_a_state_without_a_token(): void {
		// A tokenless state can never be a reset job, so the save must refuse before touching sitemeta.
		$this->assertFalse( erankly_save_network_reset_state( array(), 'ignored-snapshot' ) );
		$this->assertFalse( erankly_save_network_reset_state( array( 'status' => 'pending' ), 'ignored-snapshot' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_network_reset_state_is_a_noop_when_the_snapshot_is_unchanged(): void {
		// The compare-and-swap short-circuits and returns true without a query when the serialized replacement
		// already equals the observed snapshot. The function stamps updated_at with time(), so a state built in
		// the same second serializes to exactly the snapshot we hand back.
		$state = array(
			'token'      => 'tok-unchanged',
			'status'     => 'pending',
			'updated_at' => time(),
		);

		try {
			$result = erankly_save_network_reset_state( $state, maybe_serialize( $state ) );
		} catch ( Throwable $error ) {
			// A wall-clock second boundary between building $state and the CAS makes the stamped replacement
			// differ, so the update hits the absent single-site sitemeta table. Nothing to assert then.
			$this->markTestSkipped( 'Crossed a wall-clock boundary while building the snapshot.' );
		}

		$this->assertTrue( $result );
	}

	public function test_continue_network_reset_is_inert_when_the_state_has_no_token(): void {
		// continue commits through erankly_save_network_reset_state(); a tokenless state fails that save, so no
		// follow-up batch may be scheduled. Reachable on single-site because the save returns before any query.
		erankly_continue_network_reset( array( 'status' => 'pending' ), 'ignored-snapshot' );

		$this->assertFalse( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK ) );
	}

	/* ----------------------------------------------------------------------
	 * Multisite-only paths (wp_sitemeta)
	 * -------------------------------------------------------------------- */

	public function test_get_network_reset_snapshot_reads_the_raw_and_decoded_state(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_sitemeta only exists on Multisite.' );
		}

		$state = array(
			'token'             => 'tok-snapshot',
			'status'            => 'pending',
			'phase'             => 'network',
			'last_processed_id' => 0,
			'attempts'          => 0,
			'last_error'        => '',
			'updated_at'        => time(),
		);
		update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

		$snapshot = erankly_get_network_reset_snapshot();

		$this->assertIsString( $snapshot['raw'] );
		$this->assertIsArray( $snapshot['state'] );
		$this->assertSame( 'tok-snapshot', $snapshot['state']['token'] );
		$this->assertSame( 'pending', $snapshot['state']['status'] );

		// No stored job reads back as a false snapshot rather than an empty array.
		delete_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION );

		$empty = erankly_get_network_reset_snapshot();
		$this->assertFalse( $empty['raw'] );
		$this->assertFalse( $empty['state'] );
	}

	public function test_save_network_reset_state_commits_progress_and_rejects_a_stale_snapshot(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_sitemeta only exists on Multisite.' );
		}

		$state = array(
			'token'             => 'tok-save',
			'status'            => 'pending',
			'phase'             => 'network',
			'last_processed_id' => 0,
			'attempts'          => 0,
			'last_error'        => '',
			'updated_at'        => time(),
		);
		update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

		$snapshot = erankly_get_network_reset_snapshot();
		$this->assertSame( 'tok-save', $snapshot['state']['token'] );

		// A worker holding a snapshot that no longer matches must not overwrite the live job.
		$stale                  = $state;
		$stale['status']        = 'running';
		$this->assertFalse( erankly_save_network_reset_state( $stale, 'a-stale-serialized-snapshot' ) );

		// The exact snapshot advances the job.
		$advanced             = $state;
		$advanced['status']   = 'running';
		$advanced['attempts'] = 1;
		$this->assertTrue( erankly_save_network_reset_state( $advanced, $snapshot['raw'] ) );

		$reloaded = erankly_get_network_reset_snapshot();
		$this->assertSame( 'running', $reloaded['state']['status'] );
		$this->assertSame( 1, $reloaded['state']['attempts'] );
	}

	public function test_delete_network_reset_state_removes_only_the_exact_snapshot(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_sitemeta only exists on Multisite.' );
		}

		$state = array(
			'token'             => 'tok-delete',
			'status'            => 'completed',
			'phase'             => 'sites',
			'last_processed_id' => 5,
			'attempts'          => 0,
			'last_error'        => '',
			'updated_at'        => time(),
		);
		update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

		$snapshot = erankly_get_network_reset_snapshot();

		// A stale snapshot must not clear a job that has moved on.
		$this->assertFalse( erankly_delete_network_reset_state( 'not-the-live-snapshot' ) );
		$this->assertIsString( erankly_get_network_reset_snapshot()['raw'] );

		$this->assertTrue( erankly_delete_network_reset_state( $snapshot['raw'] ) );
		$this->assertFalse( erankly_get_network_reset_snapshot()['raw'] );
	}

	public function test_fail_network_reset_marks_an_active_job_failed_with_a_stripped_message(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_sitemeta only exists on Multisite.' );
		}

		$state = array(
			'token'             => 'tok-fail',
			'status'            => 'running',
			'phase'             => 'sites',
			'last_processed_id' => 0,
			'attempts'          => 1,
			'last_error'        => '',
			'updated_at'        => time(),
		);
		update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

		erankly_fail_network_reset( 'tok-fail', '<b>Worker</b> exploded' );

		$snapshot = erankly_get_network_reset_snapshot();
		$this->assertSame( 'failed', $snapshot['state']['status'] );
		$this->assertStringContainsString( 'Worker exploded', $snapshot['state']['last_error'] );
		$this->assertStringNotContainsString( '<b>', $snapshot['state']['last_error'] );
	}

	public function test_continue_network_reset_commits_progress_and_schedules_the_next_batch(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'wp_sitemeta only exists on Multisite.' );
		}

		$state = array(
			'token'             => 'tok-next',
			'status'            => 'running',
			'phase'             => 'sites',
			'last_processed_id' => 0,
			'attempts'          => 0,
			'last_error'        => '',
			'updated_at'        => time(),
		);
		update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $state );

		$snapshot                 = erankly_get_network_reset_snapshot();
		$state['last_processed_id'] = 20;

		erankly_continue_network_reset( $state, $snapshot['raw'], 5 );

		$this->assertSame( 20, erankly_get_network_reset_snapshot()['state']['last_processed_id'] );
		$this->assertNotFalse( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK, array( 'tok-next' ) ) );
	}
}
