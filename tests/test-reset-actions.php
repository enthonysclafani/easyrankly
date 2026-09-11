<?php
/** Reset form actions and uninstall cleanup (includes/reset.php, uninstall.php). */

/**
 * Covers the reset/debug/redirect helpers, the capability and nonce guards that protect the reset submission,
 * the site reset itself and the uninstall lifecycle.
 *
 * Two of these run DROP TABLE. The PHPUnit SQLite drop-in cannot execute DDL inside the per-test transaction (it
 * raises "no such table: _wp_sqlite_mysql_information_schema_tmp_tables" and rolls the transaction back, which
 * would invalidate the isolation of every later test), so erankly_reset_site_data() is skipped on SQLite, and the
 * uninstall test neutralises only those DROP statements so the surrounding data-cleanup contract can still run.
 */
final class ERankly_Reset_Actions_Test extends WP_UnitTestCase {

	/** @var string[] Temp files created by a test. */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		// reset.php is loaded lazily by the admin bootstrap (erankly_admin_load_reset_module()); PHPUnit boots as a
		// non-admin request, so the module must be required explicitly.
		require_once ERANKLY_PATH . 'includes/reset.php';
	}

	public function tear_down(): void {
		unset( $_GET['page'], $_POST['erankly_reset_action'] );
		wp_set_current_user( 0 );

		foreach ( $this->temp_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		erankly_clear_settings_cache();
		parent::tear_down();
	}

	private function temp_file( string $prefix ): string {
		$file = tempnam( sys_get_temp_dir(), $prefix );
		$this->assertIsString( $file );
		$this->temp_files[] = (string) $file;

		return (string) $file;
	}

	/* ----------------------------------------------------------------------
	 * includes/reset.php
	 * -------------------------------------------------------------------- */

	public function test_reset_debug_log_writes_a_gated_diagnostic_line(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			$this->markTestSkipped( 'erankly_reset_debug_log() is a no-op unless WP_DEBUG is on.' );
		}

		$previous = ini_get( 'error_log' );
		$file     = $this->temp_file( 'erankly-reset-debug-' );
		ini_set( 'error_log', $file );

		try {
			erankly_reset_debug_log( new RuntimeException( 'probe failure' ), 'site reset' );
			$logged = (string) file_get_contents( $file );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertStringContainsString( 'EasyRankly site reset failed: probe failure', $logged );
	}

	public function test_reset_redirect_sends_the_settings_url_and_exits(): void {
		// erankly_reset_redirect() ends in wp_safe_redirect(); exit;, so it cannot be called here: the exit would
		// terminate the PHPUnit process. Forking is not a safe alternative either — the child is a separate OS
		// process, so on the Multisite leg it cannot see the tables the current test created inside its open
		// transaction, and its failing queries leave the shared database locked for every later test. The
		// function is therefore left uncovered rather than covered by a test that can wedge a CI leg.
		$this->markTestSkipped( 'erankly_reset_redirect() ends in exit; callers are covered up to that point.' );
	}

	public function test_reset_handle_actions_denies_a_user_without_capability(): void {
		// The capability check is the first guard; reaching check_admin_referer() below without a nonce would raise
		// WPDieException, so a silent return proves the guard fired before any reset work (and the redirect).
		$dispatched = false;
		$listener   = static function () use ( &$dispatched ): void {
			$dispatched = true;
		};
		add_action( 'erankly_reset_action', $listener );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['page']                  = 'erankly';
		$_POST['erankly_reset_action'] = 'reset_local';

		erankly_reset_handle_actions();

		remove_action( 'erankly_reset_action', $listener );
		$this->assertFalse( $dispatched );
	}

	public function test_reset_handle_actions_ignores_a_request_for_another_page(): void {
		$dispatched = false;
		$listener   = static function () use ( &$dispatched ): void {
			$dispatched = true;
		};
		add_action( 'erankly_reset_action', $listener );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['page']                  = 'not-erankly';
		$_POST['erankly_reset_action'] = 'reset_local';

		erankly_reset_handle_actions();

		remove_action( 'erankly_reset_action', $listener );
		$this->assertFalse( $dispatched );
	}

	public function test_reset_handle_actions_stops_before_dispatch_without_a_nonce(): void {
		// This is the guard that protects erankly_reset_redirect(): an authenticated admin submitting the reset form
		// without the action nonce must be stopped by check_admin_referer() before the reset action hook fires.
		$dispatched = false;
		$listener   = static function () use ( &$dispatched ): void {
			$dispatched = true;
		};
		add_action( 'erankly_reset_action', $listener );

		// The reset submission gate requires manage_network_options on Multisite (the settings it
		// writes are network-wide), which a single-site administrator never holds; grant it so the
		// nonce check is actually reached rather than the capability guard returning early.
		$grant = null;

		if ( is_multisite() ) {
			$grant = static function ( array $allcaps ): array {
				$allcaps['manage_network_options'] = true;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $grant );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['page']                  = 'erankly';
		$_POST['erankly_reset_action'] = 'reset_local';

		try {
			erankly_reset_handle_actions();
			$this->fail( 'A reset submission without a nonce must not be processed.' );
		} catch ( WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			remove_action( 'erankly_reset_action', $listener );

			if ( null !== $grant ) {
				remove_filter( 'user_has_cap', $grant );
			}
		}

		$this->assertFalse( $dispatched );
	}

	public function test_reset_site_data_wipes_plugin_data_and_restores_defaults(): void {
		if ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ) {
			$this->markTestSkipped(
				'erankly_reset_site_data() issues DROP TABLE. The PHPUnit SQLite drop-in cannot run DDL inside the '
				. 'per-test transaction (it raises "no such table: _wp_sqlite_mysql_information_schema_tmp_tables" '
				. 'and rolls the transaction back), which would invalidate the isolation of every later test. The '
				. 'body below runs on a database engine that supports transactional DDL.'
			);
		}

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_title', 'Seeded title' );
		update_option( ERANKLY_SPECIAL_META_OPTION, array( 'post' => array( 'noindex' => 1 ) ) );
		update_option( 'erankly_redirects_db_version', '9' );

		$custom                      = erankly_get_settings();
		$custom['organization_name'] = 'Custom organisation';
		erankly_update_plugin_settings( $custom, '', true );
		erankly_clear_settings_cache();

		erankly_reset_site_data();

		$this->assertSame( '', (string) get_post_meta( $post_id, '_erankly_title', true ) );
		$this->assertFalse( get_option( ERANKLY_SPECIAL_META_OPTION, false ) );
		$this->assertFalse( get_option( 'erankly_redirects_db_version', false ) );

		erankly_clear_settings_cache();
		$defaults = erankly_default_settings();
		$this->assertSame( $defaults['organization_name'], erankly_get_settings()['organization_name'] );
	}

	/* ----------------------------------------------------------------------
	 * uninstall.php
	 * -------------------------------------------------------------------- */

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_site_removes_plugin_data_at_load_time(): void {
		// uninstall.php only runs its cleanup when WP_UNINSTALL_PLUGIN is defined, and including it executes the
		// single-site lifecycle immediately (it calls erankly_uninstall_site() itself). The whole include therefore
		// runs in an isolated process so its destructive cleanup cannot leak into the rest of the suite.
		define( 'WP_UNINSTALL_PLUGIN', true );

		// uninstall.php reports failures through error_log(); keep that out of STDERR.
		$previous = ini_get( 'error_log' );
		$log      = $this->temp_file( 'erankly-uninstall-' );
		ini_set( 'error_log', $log );

		update_option( 'erankly_special_meta', array( 'post' => array( 'noindex' => 1 ) ) );
		update_option( 'erankly_runtime_state', array( 'version' => '2.0.0' ) );
		update_option( 'erankly_redirects_db_version', '9' );
		wp_schedule_single_event( time() + 300, ERANKLY_NETWORK_RESET_CRON_HOOK, array( 'tok' ) );

		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, '_erankly_title', 'Seeded title' );

		// erankly_uninstall_site() issues DROP TABLE against the plugin-owned tables. Those tables are absent here
		// anyway, and the SQLite drop-in cannot run DDL inside the per-test transaction, so translate only those
		// statements to a harmless no-op. Everything else the uninstall performs stays live.
		$neutralise_ddl = static function ( $query ) {
			if ( preg_match( '/DROP\s+(?:TEMPORARY\s+)?TABLE\s+IF\s+EXISTS\s+[`"]?\w*erankly_/i', (string) $query ) ) {
				return 'SELECT 1';
			}

			return $query;
		};
		add_filter( 'query', $neutralise_ddl, 20 );

		try {
			include ERANKLY_PATH . 'uninstall.php';
		} finally {
			remove_filter( 'query', $neutralise_ddl, 20 );
			ini_set( 'error_log', (string) $previous );
		}

		$this->assertTrue( function_exists( 'erankly_uninstall_site' ) );
		$this->assertFalse( get_option( 'erankly_special_meta', false ) );
		$this->assertFalse( get_option( 'erankly_runtime_state', false ) );
		$this->assertFalse( get_option( 'erankly_redirects_db_version', false ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_erankly_title', true ) );
		$this->assertFalse( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK ) );
	}
}
