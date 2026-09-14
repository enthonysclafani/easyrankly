<?php
/** Uninstall helpers: SQL transients, orphan timeouts, cache-only keys, and DELETE failure injection. */

final class ERankly_Uninstall_Cleanup_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		if ( ! defined( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY' ) ) {
			define( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY', true );
		}

		require_once ERANKLY_PATH . 'uninstall.php';
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( false );
		parent::tear_down();
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function insert_option_row( string $name, string $value = '1' ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture inserts a raw options row.
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$value,
				'no'
			)
		);
	}

	private function option_row_exists( string $name ): bool {
		global $wpdb;

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserts physical leftover rows.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name
			)
		);

		return is_string( $found ) && '' !== $found;
	}

	private function fail_deletes_containing( string $needle ): void {
		add_filter(
			'query',
			static function ( $sql ) use ( $needle ) {
				$sql = (string) $sql;
				if ( preg_match( '/^\s*DELETE\b/i', $sql ) && str_contains( $sql, $needle ) ) {
					return 'ERANKLY_FORCE_DELETE_FAILURE';
				}

				return $sql;
			}
		);
	}

	public function test_delete_transient_pair_removes_value_and_timeout_rows(): void {
		$this->insert_option_row( '_transient_erankly_sitemap_probe', 'data' );
		$this->insert_option_row( '_transient_timeout_erankly_sitemap_probe', '123' );

		erankly_uninstall_delete_transient_pair( 'erankly_sitemap_probe' );

		$this->assertFalse( $this->option_row_exists( '_transient_erankly_sitemap_probe' ) );
		$this->assertFalse( $this->option_row_exists( '_transient_timeout_erankly_sitemap_probe' ) );
	}

	public function test_prefixed_transients_remove_orphan_timeouts_and_values_without_timeouts(): void {
		$this->insert_option_row( '_transient_timeout_erankly_sitemap_orphan', '123' );
		$this->insert_option_row( '_transient_erankly_sitemap_value_only', 'data' );

		erankly_uninstall_delete_prefixed_transients();

		$this->assertFalse( $this->option_row_exists( '_transient_timeout_erankly_sitemap_orphan' ) );
		$this->assertFalse( $this->option_row_exists( '_transient_erankly_sitemap_value_only' ) );
	}

	public function test_prefixed_transients_clear_sql_rows_when_external_object_cache_is_active(): void {
		$this->insert_option_row( '_transient_erankly_sitemap_probe', 'data' );
		$this->insert_option_row( '_transient_timeout_erankly_sitemap_probe', '123' );
		wp_using_ext_object_cache( true );

		erankly_uninstall_delete_prefixed_transients();

		$this->assertFalse( $this->option_row_exists( '_transient_erankly_sitemap_probe' ) );
		$this->assertFalse( $this->option_row_exists( '_transient_timeout_erankly_sitemap_probe' ) );
	}

	public function test_known_cache_transients_remove_cache_only_keys_and_leave_other_plugins(): void {
		$user_id = self::factory()->user->create();
		wp_using_ext_object_cache( true );
		wp_cache_set( 'erankly_settings_notices_' . $user_id, array( 'notice' ), 'transient' );
		wp_cache_set( 'otherplugin_keep', 'yes', 'transient' );
		update_option( 'otherplugin_keep_option', 'yes' );

		erankly_uninstall_delete_known_cache_transients();

		$this->assertFalse( wp_cache_get( 'erankly_settings_notices_' . $user_id, 'transient' ) );
		$this->assertSame( 'yes', wp_cache_get( 'otherplugin_keep', 'transient' ) );
		$this->assertSame( 'yes', get_option( 'otherplugin_keep_option' ) );
	}

	public function test_option_delete_failure_is_not_reported_as_success(): void {
		global $wpdb;

		update_option( 'erankly_import_lock_probe', 1 );
		$this->fail_deletes_containing( 'erankly_import_lock_probe' );
		$wpdb->suppress_errors( true );

		$thrown = false;
		try {
			erankly_uninstall_delete_option_row( 'erankly_import_lock_probe' );
		} catch ( RuntimeException $exception ) {
			$thrown = true;
			$this->assertStringContainsString( 'could not remove its options', $exception->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
		}

		$this->assertTrue( $thrown );
		$this->assertTrue( $this->option_row_exists( 'erankly_import_lock_probe' ) );
	}

	public function test_idempotent_option_delete_of_a_missing_row_succeeds(): void {
		erankly_uninstall_delete_option_row( 'erankly_missing_option_probe' );
		$this->assertFalse( $this->option_row_exists( 'erankly_missing_option_probe' ) );
	}

	public function test_transient_delete_failure_is_not_reported_as_success(): void {
		global $wpdb;

		$this->insert_option_row( '_transient_erankly_sitemap_probe', 'data' );
		$this->fail_deletes_containing( 'erankly_sitemap_probe' );
		$wpdb->suppress_errors( true );

		$thrown = false;
		try {
			erankly_uninstall_delete_transient_pair( 'erankly_sitemap_probe' );
		} catch ( RuntimeException $exception ) {
			$thrown = true;
			$this->assertStringContainsString( 'could not remove transient data', $exception->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
		}

		$this->assertTrue( $thrown );
		$this->assertTrue( $this->option_row_exists( '_transient_erankly_sitemap_probe' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_option_delete_failure_is_not_reported_as_success(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		$network_id = (int) get_current_network_id();
		update_network_option( $network_id, 'erankly_network_lock_probe', 1 );
		$this->fail_deletes_containing( 'erankly_network_lock_probe' );
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->suppress_errors( true );

		$thrown = false;
		try {
			erankly_uninstall_delete_network_option_row( $network_id, 'erankly_network_lock_probe' );
		} catch ( RuntimeException $exception ) {
			$thrown = true;
			$this->assertStringContainsString( 'could not remove network options', $exception->getMessage() );
		} finally {
			$wpdb->suppress_errors( false );
		}

		$this->assertTrue( $thrown );
		$this->assertTrue( erankly_uninstall_network_option_row_exists( $network_id, 'erankly_network_lock_probe' ) );
	}

	public function test_flush_object_cache_leaves_foreign_transient_keys_intact(): void {
		wp_cache_set( 'otherplugin_keep', 'yes', 'transient' );
		wp_cache_set( 'erankly_probe', '1', 'erankly_redirects' );

		erankly_uninstall_flush_object_cache();

		$this->assertSame( 'yes', wp_cache_get( 'otherplugin_keep', 'transient' ) );

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			$this->assertFalse( wp_cache_get( 'erankly_probe', 'erankly_redirects' ) );
		} else {
			$this->assertSame( '1', wp_cache_get( 'erankly_probe', 'erankly_redirects' ) );
		}
	}

	public function test_uninstall_source_does_not_flush_the_shared_transient_group(): void {
		$source = (string) file_get_contents( ERANKLY_PATH . 'uninstall.php' );
		$this->assertDoesNotMatchRegularExpression( '/wp_cache_flush\s*\(\s*\)/', $source );
		$this->assertDoesNotMatchRegularExpression( "/wp_cache_flush_group\\s*\\(\\s*['\"]transient['\"]/", $source );
		$this->assertStringContainsString( 'wp_cache_flush_group', $source );
		$this->assertStringNotContainsString( 'erankly_uninstall_simulate_delete_failure', $source );
		$this->assertStringContainsString( "\$query['blog_id'] = 0;", $source );
	}

	/**
	 * @group ms-required
	 */
	public function test_known_cache_transients_clear_notices_for_super_admins_outside_the_current_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		remove_user_from_blog( $user_id, get_current_blog_id() );
		wp_using_ext_object_cache( true );
		wp_cache_set( 'erankly_settings_notices_' . $user_id, array( 'notice' ), 'transient' );

		erankly_uninstall_delete_known_cache_transients();

		$this->assertFalse( wp_cache_get( 'erankly_settings_notices_' . $user_id, 'transient' ) );
	}
}
