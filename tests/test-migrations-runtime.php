<?php
/** Migration runtime helpers: database, redirects, template variables and pre-import backups. */

require_once ERANKLY_PATH . 'includes/migrations.php';

final class ERankly_Migrations_Runtime_Test extends WP_UnitTestCase {

	/** @var array<int,string> */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		require_once ERANKLY_PATH . 'includes/migrations.php';
		erankly_load_default_helpers();
		erankly_load_content_helpers();
		erankly_ensure_redirect_classes_available();
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleaning up a test-owned temp file.
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	private function temp_path( string $contents ): string {
		$path = trailingslashit( get_temp_dir() ) . 'erankly-runtime-' . wp_generate_password( 12, false ) . '.json';
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}

	// ---------------------------------------------------------------------
	// runtime-database.php
	// ---------------------------------------------------------------------

	public function test_table_exists_checks_exact_prefixed_table(): void {
		global $wpdb;

		$this->assertTrue( erankly_table_exists( $wpdb->posts ) );
		$this->assertTrue( erankly_table_exists( $wpdb->options ) );
		$this->assertFalse( erankly_table_exists( $wpdb->prefix . 'definitely_missing_table_xyz' ) );
	}

	// ---------------------------------------------------------------------
	// runtime-redirects.php
	// ---------------------------------------------------------------------

	public function test_import_prepare_redirect_normalizes_exact_rule(): void {
		$prepared = erankly_import_prepare_redirect(
			array(
				'source_path' => '/old-page',
				'target_url'  => '/new-page',
				'status_code' => 301,
			)
		);

		$this->assertIsArray( $prepared );
		$this->assertSame( '/old-page', $prepared['source_path'] );
		$this->assertSame( '/new-page', $prepared['target_url'] );
		$this->assertSame( 301, $prepared['status_code'] );
		$this->assertSame( 'exact', $prepared['match_type'] );
		$this->assertSame( 'ignore', $prepared['query_mode'] );
		$this->assertSame( 1, $prepared['is_active'] );
		$this->assertSame( md5( '/old-page' ), $prepared['source_hash'] );
	}

	public function test_import_prepare_redirect_rejects_invalid_status_code(): void {
		$this->assertNull(
			erankly_import_prepare_redirect(
				array(
					'source_path' => '/old-page',
					'target_url'  => '/new-page',
					'status_code' => 999,
				)
			)
		);
	}

	public function test_import_prepare_redirect_converts_fuzzy_match_to_safe_regex(): void {
		$contains = erankly_import_prepare_redirect(
			array(
				'source_path' => '/search',
				'target_url'  => '/find',
				'match_type'  => 'contains',
			)
		);
		$this->assertIsArray( $contains );
		$this->assertSame( 'regex', $contains['match_type'] );
		$this->assertStringContainsString( '[Matching mode converted', $contains['note'] );

		$starts = erankly_import_prepare_redirect(
			array(
				'source_path' => '/docs',
				'target_url'  => '/manual',
				'match_type'  => 'starts_with',
			)
		);
		$this->assertIsArray( $starts );
		$this->assertSame( '^/docs', $starts['source_path'] );
		$this->assertSame( 'regex', $starts['match_type'] );

		$ends = erankly_import_prepare_redirect(
			array(
				'source_path' => '/report',
				'target_url'  => '/archive',
				'match_type'  => 'ends_with',
			)
		);
		$this->assertIsArray( $ends );
		$this->assertSame( '/report$', $ends['source_path'] );
	}

	public function test_import_prepare_redirect_keeps_wildcard_and_status_only(): void {
		$wildcard = erankly_import_prepare_redirect(
			array(
				'source_path' => '/foo*',
				'target_url'  => '/bar',
				'match_type'  => 'wildcard',
			)
		);
		$this->assertIsArray( $wildcard );
		$this->assertSame( 'wildcard', $wildcard['match_type'] );
		$this->assertSame( '/foo*', $wildcard['source_path'] );

		$gone = erankly_import_prepare_redirect(
			array(
				'source_path' => '/removed',
				'status_code' => 410,
			)
		);
		$this->assertIsArray( $gone );
		$this->assertSame( 410, $gone['status_code'] );
		$this->assertSame( '', $gone['target_url'] );
	}

	public function test_import_prepare_redirect_rejects_unsupported_visibility(): void {
		$this->assertNull(
			erankly_import_prepare_redirect(
				array(
					'source_path' => '/a',
					'target_url'  => '/b',
					'visibility'  => 'only_logged_in',
				)
			)
		);
	}

	public function test_import_redirect_unsupported_reason_detects_unsupported_rules(): void {
		$this->assertSame( '', erankly_import_redirect_unsupported_reason( array() ) );
		$this->assertSame( '', erankly_import_redirect_unsupported_reason( array( 'visibility' => 'all' ) ) );
		$this->assertSame( 'audience', erankly_import_redirect_unsupported_reason( array( 'visibility' => 'only_logged_in' ) ) );
		$this->assertSame( 'condition', erankly_import_redirect_unsupported_reason( array( 'required_role' => 'editor' ) ) );
		$this->assertSame( 'condition', erankly_import_redirect_unsupported_reason( array( 'conditions' => array( array( 'type' => 'referrer' ) ) ) ) );
		$this->assertSame( 'condition', erankly_import_redirect_unsupported_reason( array( 'conditions' => 'raw text' ) ) );
		$this->assertSame( 'schedule', erankly_import_redirect_unsupported_reason( array( 'start_at' => '2020-01-01' ) ) );
		$this->assertSame( 'schedule', erankly_import_redirect_unsupported_reason( array( 'end_at' => '2020-01-02' ) ) );
	}

	// ---------------------------------------------------------------------
	// runtime-variables.php
	// ---------------------------------------------------------------------

	public function test_import_variable_diagnostics_records_and_resets(): void {
		erankly_import_variable_diagnostics( null, true );
		$this->assertSame( array(), erankly_import_variable_diagnostics() );

		$warning = array(
			'message'   => 'Unsupported rankmath template variable was removed: %unknown%.',
			'reference' => 'rankmath:unknown',
		);
		$result  = erankly_import_variable_diagnostics( $warning );
		$this->assertCount( 1, $result );
		$this->assertSame( 'unsupported_template_variable', $result[0]['code'] );
		$this->assertSame( 'rankmath:unknown', $result[0]['reference'] );

		// Duplicate references are de-duplicated.
		$this->assertCount( 1, erankly_import_variable_diagnostics( $warning ) );

		erankly_import_variable_diagnostics( null, true );
		$this->assertSame( array(), erankly_import_variable_diagnostics() );
	}

	public function test_convert_variables_per_source_patterns(): void {
		$this->assertSame( '', erankly_import_convert_variables( '', 'yoast' ) );
		$this->assertSame( 'My {{post_title}} page', erankly_import_convert_variables( 'My %%title%% page', 'yoast' ) );
		$this->assertSame( '{{post_title}} at {{site_name}}', erankly_import_convert_variables( '#post_title at #site_title', 'aioseo' ) );
		$this->assertSame( '{{post_title}} - {{site_name}}', erankly_import_convert_variables( '%title% - %sitename%', 'rankmath' ) );
		$this->assertSame( 'a - b', erankly_import_convert_variables( 'a %%sep%% b', 'yoast' ) );
	}

	public function test_convert_variables_trims_and_reports_unsupported_tokens(): void {
		erankly_import_variable_diagnostics( null, true );

		$this->assertSame( '{{post_title}}', erankly_import_convert_variables( '- %%title%% -', 'yoast' ) );
		$this->assertSame( 'x', erankly_import_convert_variables( '%unknown_var% x', 'rankmath' ) );

		$diagnostics = erankly_import_variable_diagnostics();
		$this->assertNotEmpty( $diagnostics );
		$this->assertSame( 'rankmath:unknown_var', $diagnostics[0]['reference'] );

		erankly_import_variable_diagnostics( null, true );
	}

	public function test_convert_variables_preserves_aioseo_hash_and_applies_seopress_overrides(): void {
		erankly_import_variable_diagnostics( null, true );

		// Unknown AIOSEO hash tokens are preserved rather than removed.
		$this->assertSame( 'x #unknown', erankly_import_convert_variables( 'x #unknown', 'aioseo' ) );

		$this->assertSame( '{{author_url}}', erankly_import_convert_variables( '%%author_url%%', 'yoast' ) );
		$this->assertSame( '{{author_profile_url}}', erankly_import_convert_variables( '%%author_url%%', 'seopress' ) );

		erankly_import_variable_diagnostics( null, true );
	}

	// ---------------------------------------------------------------------
	// runtime-backup.php
	// ---------------------------------------------------------------------

	public function test_load_backup_import_helpers_requires_import_export_module(): void {
		erankly_migration_load_backup_import_helpers();

		$this->assertTrue( function_exists( 'erankly_import_export_read_bounded_file' ) );
		$this->assertTrue( function_exists( 'erankly_import_export_max_bytes' ) );
	}

	public function test_read_backup_document_validates_plugin_document(): void {
		$valid = $this->temp_path( wp_json_encode( array( 'plugin' => 'erankly' ) ) );
		$read  = erankly_migration_read_backup_document( $valid );
		$this->assertTrue( $read['ok'] );
		$this->assertSame( 'erankly', $read['data']['plugin'] );

		$invalid_json = $this->temp_path( 'not json at all' );
		$read_invalid = erankly_migration_read_backup_document( $invalid_json );
		$this->assertFalse( $read_invalid['ok'] );
		$this->assertSame( 'backup_unreadable', $read_invalid['error'] );

		$foreign = $this->temp_path( wp_json_encode( array( 'plugin' => 'some-other-plugin' ) ) );
		$read_foreign = erankly_migration_read_backup_document( $foreign );
		$this->assertFalse( $read_foreign['ok'] );
		$this->assertSame( 'backup_unreadable', $read_foreign['error'] );

		$missing = erankly_migration_read_backup_document( trailingslashit( get_temp_dir() ) . 'erankly-missing-' . wp_generate_password( 8, false ) . '.json' );
		$this->assertFalse( $missing['ok'] );
		$this->assertSame( 'backup_unreadable', $missing['error'] );
	}

	public function test_create_backup_writes_a_restorable_document(): void {
		$backup = erankly_migration_create_backup();

		$this->assertTrue( $backup['ok'] );
		$this->assertTrue( file_exists( $backup['path'] ) );
		$this->assertGreaterThan( 1, $backup['bytes'] );
		$this->assertNotSame( '', $backup['created_at'] );
		$this->assertTrue( ERankly_Migration_Upload_Store::is_backup( $backup['path'] ) );

		$document = erankly_migration_read_backup_document( $backup['path'] );
		$this->assertTrue( $document['ok'] );
		$this->assertSame( 'erankly', $document['data']['plugin'] );

		ERankly_Migration_Upload_Store::delete( $backup['path'] );
	}

	public function test_backup_state_requires_recent_restorable_backup(): void {
		$backup = erankly_migration_create_backup();
		$this->assertTrue( $backup['ok'] );

		$fresh = erankly_migration_backup_state(
			array(
				'backup' => array(
					'path'       => $backup['path'],
					'created_at' => gmdate( 'c' ),
				),
			)
		);
		$this->assertSame( $backup['path'], $fresh['path'] );
		$this->assertArrayHasKey( 'expires_at', $fresh );

		$missing = erankly_migration_backup_state( array( 'backup' => array( 'path' => trailingslashit( get_temp_dir() ) . 'erankly-backup-' . str_repeat( 'a', 32 ) . '.json', 'created_at' => gmdate( 'c' ) ) ) );
		$this->assertSame( array(), $missing );

		$expired = erankly_migration_backup_state(
			array(
				'backup' => array(
					'path'       => $backup['path'],
					'created_at' => gmdate( 'c', time() - 2 * WEEK_IN_SECONDS ),
				),
			)
		);
		$this->assertSame( array(), $expired );

		$this->assertSame( array(), erankly_migration_backup_state( array() ) );

		ERankly_Migration_Upload_Store::delete( $backup['path'] );
	}

	public function test_referenced_backups_respects_retention_window(): void {
		$recent = wp_normalize_path( trailingslashit( get_temp_dir() ) . 'erankly-backup-' . str_repeat( 'b', 32 ) . '.json' );
		$stale  = wp_normalize_path( trailingslashit( get_temp_dir() ) . 'erankly-backup-' . str_repeat( 'c', 32 ) . '.json' );

		update_option(
			'erankly_migration_reports_v1',
			array(
				'recent' => array( 'backup' => array( 'path' => $recent, 'created_at' => gmdate( 'c' ) ) ),
				'stale'  => array( 'backup' => array( 'path' => $stale, 'created_at' => gmdate( 'c', time() - 2 * WEEK_IN_SECONDS ) ) ),
			)
		);

		$this->assertSame( array( $recent ), erankly_migration_referenced_backups() );
	}

	public function test_restore_backup_reports_unavailable_for_unknown_report(): void {
		$result = erankly_migration_restore_backup( 'no-such-report' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'backup_unavailable', $result['error'] );
	}
}
