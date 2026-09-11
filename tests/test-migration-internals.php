<?php
/**
 * Internals that have no public entry point: the upload store's private path
 * contract, the job runner's private lock/warning helpers, and the migration
 * adapter's protected base defaults.
 *
 * These are reached with reflection on purpose. Two of the targets are worth
 * calling out:
 * - ERankly_Migration_Adapter::storage_definitions()/supported_versions() are
 *   overridden by all four concrete adapters, so their base bodies are dead
 *   defaults; reflection is the only way to execute them.
 * - The upload store's is_staged_path()/delete_staged_path() are private statics
 *   guarding deletion, so their reject paths are a security-relevant surface.
 */

final class ERankly_Migration_Internals_Test extends WP_UnitTestCase {

	/** @var array<int,string> Paths created by a test, removed in tear_down. */
	private array $created_paths = array();

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		erankly_load_default_helpers();

		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-upload-store.php';
		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-adapter.php';
		require_once ERANKLY_PATH . 'includes/migrations/class-erankly-migration-job-runner.php';
	}

	public function tear_down(): void {
		foreach ( $this->created_paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->created_paths = array();

		parent::tear_down();
	}

	/**
	 * Creates a private staging file with a controlled basename.
	 *
	 * @param string $basename File name inside the private directory.
	 * @return string Absolute path, or '' when private storage is unavailable.
	 */
	private function make_staged_file( string $basename ): string {
		$directory = ERankly_Migration_Upload_Store::directory();

		if ( '' === $directory ) {
			return '';
		}

		$path = $directory . '/' . $basename;
		file_put_contents( $path, '{}' );
		$this->created_paths[] = $path;

		return $path;
	}

	/** @return mixed */
	private static function call_private( string $class, string $method, array $args = array(), ?object $instance = null ) {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $instance, $args );
	}

	public function test_upload_store_private_directory_is_outside_the_public_tree(): void {
		$directory = ERankly_Migration_Upload_Store::directory();

		if ( '' === $directory ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$this->assertDirectoryExists( $directory );
		$this->assertStringNotContainsString( untrailingslashit( ABSPATH ), $directory );
	}

	public function test_is_staged_path_accepts_a_backup_named_file(): void {
		$basename = 'erankly-backup-' . str_repeat( 'a', 32 ) . '.json';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertTrue(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $path, $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
	}

	public function test_is_staged_path_rejects_a_wrong_extension(): void {
		$basename = 'erankly-backup-' . str_repeat( 'b', 32 ) . '.php';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $path, $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
	}

	public function test_is_staged_path_rejects_a_token_that_is_not_32_hex_characters(): void {
		$basename = 'erankly-backup-' . str_repeat( 'z', 32 ) . '.json';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $path, $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
	}

	public function test_is_staged_path_rejects_the_wrong_directory(): void {
		$basename = 'erankly-backup-' . str_repeat( 'c', 32 ) . '.json';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $path, '/tmp/not-the-private-directory', 'erankly-backup-', array( 'json' ) )
			)
		);
	}

	public function test_is_staged_path_rejects_a_missing_file(): void {
		$directory = ERankly_Migration_Upload_Store::directory();

		if ( '' === $directory ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $directory . '/erankly-backup-' . str_repeat( 'd', 32 ) . '.json', $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
	}

	public function test_is_staged_path_rejects_an_empty_extension_list(): void {
		$basename = 'erankly-backup-' . str_repeat( 'e', 32 ) . '.json';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'is_staged_path',
				array( $path, $directory, 'erankly-backup-', array() )
			)
		);
	}

	public function test_delete_staged_path_removes_a_valid_staged_file(): void {
		$basename = 'erankly-backup-' . str_repeat( 'f', 32 ) . '.json';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertFileExists( $path );
		$this->assertTrue(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'delete_staged_path',
				array( $path, $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
		$this->assertFileDoesNotExist( $path );
	}

	public function test_delete_staged_path_refuses_a_path_that_fails_the_contract(): void {
		$basename = 'erankly-backup-' . str_repeat( '1', 32 ) . '.php';
		$path     = $this->make_staged_file( $basename );

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$directory = untrailingslashit( ERankly_Migration_Upload_Store::directory() );

		$this->assertFalse(
			(bool) self::call_private(
				ERankly_Migration_Upload_Store::class,
				'delete_staged_path',
				array( $path, $directory, 'erankly-backup-', array( 'json' ) )
			)
		);
		$this->assertFileExists( $path );
	}

	public function test_job_runner_lock_key_is_derived_from_the_job_id(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$key    = (string) self::call_private( ERankly_Migration_Job_Runner::class, 'lock_key', array( 'job-abc' ), $runner );

		$this->assertSame( 'erankly_migration_lock_' . substr( hash( 'sha256', 'job-abc' ), 0, 24 ), $key );
	}

	public function test_job_runner_owns_lock_only_with_a_matching_unexpired_token(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$job_id = 'job-lock-' . wp_generate_password( 6, false );
		$key    = (string) self::call_private( ERankly_Migration_Job_Runner::class, 'lock_key', array( $job_id ), $runner );

		// A matching, unexpired token is owned.
		update_option( $key, array( 'token' => 'tok-1', 'expires' => time() + 60 ), false );
		$this->assertTrue(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok-1' ), $runner )
		);

		// A different token is not.
		$this->assertFalse(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok-2' ), $runner )
		);

		// An expired lock is not owned even with the right token.
		update_option( $key, array( 'token' => 'tok-1', 'expires' => time() - 5 ), false );
		$this->assertFalse(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok-1' ), $runner )
		);

		delete_option( $key );
	}

	public function test_job_runner_owns_lock_is_false_without_a_lock_option(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$job_id = 'job-missing-' . wp_generate_password( 6, false );

		$this->assertFalse(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok' ), $runner )
		);
	}

	public function test_job_runner_owns_lock_falls_back_to_created_plus_ttl(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$job_id = 'job-created-' . wp_generate_password( 6, false );
		$key    = (string) self::call_private( ERankly_Migration_Job_Runner::class, 'lock_key', array( $job_id ), $runner );

		// No explicit expiry: the lock is valid while created + TTL is in the future.
		update_option( $key, array( 'token' => 'tok', 'created' => time() ), false );
		$this->assertTrue(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok' ), $runner )
		);

		// A very old 'created' value is treated as expired.
		update_option( $key, array( 'token' => 'tok', 'created' => time() - WEEK_IN_SECONDS ), false );
		$this->assertFalse(
			(bool) self::call_private( ERankly_Migration_Job_Runner::class, 'owns_lock', array( $job_id, 'tok' ), $runner )
		);

		delete_option( $key );
	}

	public function test_job_runner_add_warning_appends_and_dedupes_by_code_and_reference(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$method = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'add_warning' );
		$method->setAccessible( true );

		$job = array( 'report' => array( 'warnings' => array() ) );

		$args = array( &$job, 'some_code', 'Messaggio di avviso', 'ref-1', true );
		$method->invokeArgs( $runner, $args );

		$this->assertCount( 1, $job['report']['warnings'] );
		$this->assertSame( 'some_code', $job['report']['warnings'][0]['code'] );
		$this->assertSame( 'ref-1', $job['report']['warnings'][0]['reference'] );
		$this->assertTrue( $job['report']['warnings'][0]['blocking'] );

		// Same code + reference is ignored.
		$args = array( &$job, 'some_code', 'Messaggio diverso', 'ref-1', true );
		$method->invokeArgs( $runner, $args );
		$this->assertCount( 1, $job['report']['warnings'] );

		// A different reference is recorded.
		$args = array( &$job, 'some_code', 'Messaggio di avviso', 'ref-2', false );
		$method->invokeArgs( $runner, $args );
		$this->assertCount( 2, $job['report']['warnings'] );
		$this->assertFalse( $job['report']['warnings'][1]['blocking'] );
	}

	public function test_job_runner_add_warning_sanitizes_its_input(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$method = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'add_warning' );
		$method->setAccessible( true );

		$job  = array( 'report' => array( 'warnings' => array() ) );
		$args = array( &$job, 'Code With Spaces', 'Messaggio <script>alert(1)</script>', 'Ref 1', true );
		$method->invokeArgs( $runner, $args );

		$warning = $job['report']['warnings'][0];

		$this->assertSame( 'codewithspaces', $warning['code'] );
		$this->assertStringNotContainsString( '<script>', $warning['message'] );
	}

	public function test_job_runner_add_warning_initialises_a_missing_report(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$method = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'add_warning' );
		$method->setAccessible( true );

		$job  = array();
		$args = array( &$job, 'code', 'Messaggio', 'ref', true );
		$method->invokeArgs( $runner, $args );

		$this->assertArrayHasKey( 'report', $job );
		$this->assertCount( 1, $job['report']['warnings'] );
	}

	public function test_job_runner_discard_unattached_backup_deletes_the_reported_file(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$method = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'discard_unattached_backup' );
		$method->setAccessible( true );

		$path = ERankly_Migration_Upload_Store::reserve_backup_path();

		if ( '' === $path ) {
			$this->markTestSkipped( 'Private migration storage is unavailable in this environment.' );
		}

		$this->assertTrue( ERankly_Migration_Upload_Store::is_backup( $path ) );

		file_put_contents( $path, '{"backup":true}' );
		$this->assertFileExists( $path );

		$method->invoke( $runner, array( 'backup' => array( 'path' => $path ) ) );

		$this->assertFileDoesNotExist( $path );
	}

	public function test_job_runner_discard_unattached_backup_tolerates_a_missing_path(): void {
		$runner = new ERankly_Migration_Job_Runner();
		$method = new ReflectionMethod( ERankly_Migration_Job_Runner::class, 'discard_unattached_backup' );
		$method->setAccessible( true );

		// No backup key, an empty path, and a non-array backup must all be no-ops.
		$method->invoke( $runner, array() );
		$method->invoke( $runner, array( 'backup' => array( 'path' => '' ) ) );
		$method->invoke( $runner, array( 'backup' => 'not-an-array' ) );

		$this->assertTrue( true );
	}

	public function test_migration_adapter_base_defaults_are_empty(): void {
		// Every concrete adapter overrides both methods, so these base bodies are
		// unreachable defaults; reflection is the only way to execute them.
		$adapter = new class() extends ERankly_Migration_Adapter {
			public function slug(): string {
				return 'probe';
			}

			public function label(): string {
				return 'Probe adapter';
			}

			public function version(): string {
				return '1.0.0';
			}

			public function is_available(): bool {
				return false;
			}

			public function content_records(): iterable {
				return array();
			}
		};

		$storage  = new ReflectionMethod( ERankly_Migration_Adapter::class, 'storage_definitions' );
		$versions = new ReflectionMethod( ERankly_Migration_Adapter::class, 'supported_versions' );
		$storage->setAccessible( true );
		$versions->setAccessible( true );

		$this->assertSame( array(), $storage->invoke( $adapter ) );
		$this->assertSame( array( 'min' => '', 'max' => '' ), $versions->invoke( $adapter ) );
	}
}
