<?php
/** Uninstall helpers: options, SQL transients, orphan timeouts, network options and DELETE failure injection. */

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

	public function test_delete_options_removes_options_transients_and_orphan_timeouts(): void {
		$this->insert_option_row( 'erankly_import_lock_probe' );
		$this->insert_option_row( 'erml_ms_settings' );
		$this->insert_option_row( 'erml_ms_version' );
		$this->insert_option_row( '_transient_erankly_sitemap_probe', 'data' );
		$this->insert_option_row( '_transient_timeout_erankly_sitemap_probe', '123' );
		$this->insert_option_row( '_transient_timeout_erankly_sitemap_orphan', '123' );
		update_option( 'otherplugin_keep_option', 'yes' );

		erankly_uninstall_delete_options();

		foreach ( array( 'erankly_import_lock_probe', 'erml_ms_settings', 'erml_ms_version', '_transient_erankly_sitemap_probe', '_transient_timeout_erankly_sitemap_probe', '_transient_timeout_erankly_sitemap_orphan' ) as $name ) {
			$this->assertFalse( $this->option_row_exists( $name ), $name );
		}
		$this->assertSame( 'yes', get_option( 'otherplugin_keep_option' ) );
	}

	public function test_delete_options_clears_sql_rows_when_external_object_cache_is_active(): void {
		$this->insert_option_row( '_transient_erankly_sitemap_probe', 'data' );
		wp_using_ext_object_cache( true );

		erankly_uninstall_delete_options();

		$this->assertFalse( $this->option_row_exists( '_transient_erankly_sitemap_probe' ) );
	}

	public function test_delete_options_keeps_the_object_cache_coherent(): void {
		update_option( 'erankly_cached_probe', 'value', false );
		$this->assertSame( 'value', get_option( 'erankly_cached_probe' ) );

		erankly_uninstall_delete_options();

		$this->assertFalse( get_option( 'erankly_cached_probe' ) );
	}

	public function test_failed_delete_is_not_reported_as_success(): void {
		global $wpdb;

		update_option( 'erankly_import_lock_probe', 1 );
		$this->assertTrue( $this->option_row_exists( 'erankly_import_lock_probe' ) );
		$failed_deletes = 0;
		$fail = static function ( $sql ) use ( &$failed_deletes ) {
			if ( preg_match( '/^\s*DELETE\b/i', (string) $sql ) && str_contains( (string) $sql, 'erankly' ) ) {
				++$failed_deletes;
				// wpdb rejects an empty query on both drivers. Invalid SQL would also roll back SQLite's test fixtures.
				return '';
			}

			return $sql;
		};
		add_filter( 'query', $fail );
		$previous_suppress_errors = $wpdb->suppress_errors( true );

		$thrown = false;
		try {
			erankly_uninstall_delete_options();
		} catch ( RuntimeException $exception ) {
			$thrown = true;
			$this->assertStringContainsString( 'could not remove its data', $exception->getMessage() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous_suppress_errors );
		}

		$this->assertSame( 1, $failed_deletes, 'Uninstall must stop at the first failed DELETE.' );
		$this->assertTrue( $thrown );
		$this->assertTrue( $this->option_row_exists( 'erankly_import_lock_probe' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_cleanup_removes_only_plugin_network_options(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		$network_id = (int) get_current_network_id();
		update_network_option( $network_id, 'erankly_network_probe', 1 );
		update_network_option( $network_id, 'erml_ms_settings', array( 'sites' => array() ) );
		update_network_option( $network_id, 'erml_ms_version', '1.3.0' );
		update_network_option( $network_id, 'otherplugin_network_keep', 'yes' );

		erankly_uninstall_network( $network_id );

		$this->assertFalse( get_network_option( $network_id, 'erankly_network_probe', false ) );
		$this->assertFalse( get_network_option( $network_id, 'erml_ms_settings', false ) );
		$this->assertFalse( get_network_option( $network_id, 'erml_ms_version', false ) );
		$this->assertSame( 'yes', get_network_option( $network_id, 'otherplugin_network_keep' ) );
	}

	public function test_uninstall_source_does_not_flush_the_shared_transient_group(): void {
		$source = (string) file_get_contents( ERANKLY_PATH . 'uninstall.php' );
		$this->assertDoesNotMatchRegularExpression( '/wp_cache_flush\s*\(\s*\)/', $source );
		$this->assertDoesNotMatchRegularExpression( "/wp_cache_flush_group\\s*\\(\\s*['\"]transient['\"]/", $source );
		$this->assertStringContainsString( "wp_cache_flush_group( 'erankly_redirects' )", $source );
	}
}
