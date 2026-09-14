<?php
/** Plugin lifecycle: runtime state, activation/deactivation, rewrite signatures and settings maintenance. */

/**
 * Exercises the lifecycle half of easyrankly.php.
 *
 * Two environment notes matter here:
 *
 *  - `$erankly_runtime_state_cache` (global) and the sitemap/redirect cache flushers (function statics) survive the
 *    per-test rollback, so each test resets the globals it can and picks a unique `$GLOBALS['blog_id']` when it
 *    needs a fresh `erankly_flush_sitemap_cache()` slot.
 *  - `erankly_get_network_site_ids_batch()`, `erankly_get_current_network_site_count()` and
 *    `erankly_network_lifecycle_requires_cli()` query `$wpdb->blogs`, which is null outside Multisite. Those tests
 *    build the Multisite table inside the test transaction and point `$wpdb->blogs` at it, so the real keyset query
 *    runs instead of erroring out.
 */
final class ERankly_Lifecycle_Test extends WP_UnitTestCase {

	/** @var int|null Original current blog ID, restored after tests that override it. */
	private $original_blog_id = null;

	/** @var string Original $wpdb->blogs value, restored after the network table harness. */
	private $original_blogs_table = '';

	/** @var string Dedicated blogs fixture table, created once per class. */
	private static $blogs_fixture_table = '';

	/**
	 * Creates the fixture table once, outside the per-test transaction.
	 *
	 * The fixture must not reuse the core `blogs` table name: a CREATE TABLE against it
	 * empties the shared table on MySQL and breaks every later test. It also has to be
	 * created here rather than inside a test, because MySQL commits implicitly on a
	 * successful DDL statement, which would silently end the per-test transaction.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;

		self::$blogs_fixture_table = $wpdb->base_prefix . 'erankly_test_blogs';

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$blogs_fixture_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test harness DDL on a fixed, self-owned table name.
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test harness DDL on a fixed, self-owned table name.
			'CREATE TABLE ' . self::$blogs_fixture_table . ' (
				blog_id BIGINT(20) NOT NULL,
				site_id BIGINT(20) NOT NULL DEFAULT 0,
				domain VARCHAR(200) NOT NULL DEFAULT \'\',
				path VARCHAR(100) NOT NULL DEFAULT \'\',
				deleted TINYINT(2) NOT NULL DEFAULT 0,
				spam TINYINT(2) NOT NULL DEFAULT 0,
				archived TINYINT(2) NOT NULL DEFAULT 0,
				PRIMARY KEY (blog_id)
			)'
		);
	}

	public static function tear_down_after_class(): void {
		global $wpdb;

		if ( '' !== self::$blogs_fixture_table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::$blogs_fixture_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test harness DDL on a fixed, self-owned table name.
		}

		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();

		// Process-wide caches that outlive the per-test SQLite rollback.
		unset( $GLOBALS['erankly_runtime_state_cache'], $GLOBALS['erankly_settings_write_context'] );
		erankly_clear_settings_cache();

		// get_current_blog_id() reads $GLOBALS['blog_id'] on Multisite, so a test that
		// overrides it for a fresh sitemap-cache slot must restore it afterwards, and the
		// network-table harness must restore $wpdb->blogs, or both leak into the next class.
		global $wpdb;
		$this->original_blog_id     = $GLOBALS['blog_id'] ?? null;
		$this->original_blogs_table = (string) $wpdb->blogs;
	}

	public function tear_down(): void {
		global $wpdb;

		unset(
			$GLOBALS['erankly_runtime_state_cache'],
			$GLOBALS['erankly_settings_write_context']
		);
		erankly_clear_settings_cache();

		$wpdb->blogs = $this->original_blogs_table;

		if ( null === $this->original_blog_id ) {
			unset( $GLOBALS['blog_id'] );
		} else {
			$GLOBALS['blog_id'] = $this->original_blog_id;
		}

		wp_clear_scheduled_hook( ERANKLY_MIGRATION_CRON_HOOK );
		wp_clear_scheduled_hook( ERANKLY_IMPORT_CRON_HOOK );
		wp_clear_scheduled_hook( ERANKLY_NETWORK_RESET_CRON_HOOK );

		parent::tear_down();
	}

	/**
	 * Points `$wpdb->blogs` at the class fixture table, whose contents each test owns.
	 *
	 * The table itself is created in set_up_before_class(); this only redirects the global,
	 * and tear_down() restores it afterwards.
	 */
	private function use_network_blogs_table(): void {
		global $wpdb;

		$wpdb->blogs = self::$blogs_fixture_table;
	}

	private function clear_local_business_migration_state(): void {
		$checkpoint = erankly_local_business_pages_migration_checkpoint_option();

		delete_option( 'erankly_migrated_local_business_pages_v1' );
		delete_option( $checkpoint );
		delete_site_option( 'erankly_migrated_local_business_pages_v1' );
		delete_site_option( $checkpoint );
	}

	private function insert_network_site( int $blog_id, int $site_id = 1, array $status = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id'  => $blog_id,
				'site_id'  => $site_id,
				'domain'   => 'site-' . $blog_id . '.test',
				'path'     => '/',
				'deleted'  => (int) ( $status['deleted'] ?? 0 ),
				'spam'     => (int) ( $status['spam'] ?? 0 ),
				'archived' => (int) ( $status['archived'] ?? 0 ),
			)
		);
	}

	/**
	 * Creates the tables the retired migration subsystem left behind so the upgrade purge can actually drop them.
	 *
	 * `DROP TABLE IF EXISTS <missing>` cannot complete under the SQLite test drop-in (the existing migration test
	 * documents the same limitation), but a site that predates this release genuinely has these tables, so the
	 * upgrade path needs them present to reach the version write.
	 */
	private function create_retired_migration_tables(): void {
		global $wpdb;

		foreach ( array( 'erankly_migration_queue', 'erankly_migration_changes', 'erankly_migration_exceptions' ) as $suffix ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test harness DDL.
				'CREATE TABLE ' . $wpdb->prefix . $suffix . ' ( id INT )'
			);
		}
	}

	/* ----------------------------------------------------------------------
	 * Runtime state
	 * -------------------------------------------------------------------- */

	public function test_get_runtime_state_seeds_the_compact_state_from_the_legacy_options(): void {
		update_option( ERANKLY_VERSION_OPTION, '9.9.9' );
		update_option( ERANKLY_REWRITE_GENERATION_OPTION, 'gen-legacy' );
		delete_option( 'erankly_migrated_post_type_schema_v1' );
		delete_option( 'erankly_migrated_title_defaults_v1' );
		delete_option( 'erankly_migrated_local_business_pages_v1' );
		delete_option( ERANKLY_RUNTIME_STATE_OPTION );
		unset( $GLOBALS['erankly_runtime_state_cache'] );

		$state = erankly_get_runtime_state();

		$this->assertSame(
			array(
				'version'            => '9.9.9',
				'rewrite_generation' => 'gen-legacy',
			),
			$state
		);

		// The lazily built state is persisted for the next request.
		$this->assertSame( $state, get_option( ERANKLY_RUNTIME_STATE_OPTION ) );
	}

	public function test_get_runtime_state_returns_the_stored_state_array(): void {
		update_option( ERANKLY_RUNTIME_STATE_OPTION, array( 'version' => '5.5.5' ), true );
		unset( $GLOBALS['erankly_runtime_state_cache'] );

		$this->assertSame( array( 'version' => '5.5.5' ), erankly_get_runtime_state() );
	}

	public function test_get_runtime_state_memoises_for_the_rest_of_the_request(): void {
		$first = erankly_get_runtime_state();

		update_option( ERANKLY_RUNTIME_STATE_OPTION, array( 'version' => 'changed-mid-request' ), true );

		// A second read in the same request must not re-query the option.
		$this->assertSame( $first, erankly_get_runtime_state() );
	}

	public function test_update_runtime_state_mirrors_the_compact_key_and_the_legacy_option(): void {
		erankly_update_runtime_state( ERANKLY_REWRITE_GENERATION_OPTION, 'gen-42' );

		$this->assertSame( 'gen-42', get_option( ERANKLY_REWRITE_GENERATION_OPTION ) );
		$this->assertSame( 'gen-42', get_option( ERANKLY_RUNTIME_STATE_OPTION )['rewrite_generation'] );

		erankly_update_runtime_state( ERANKLY_VERSION_OPTION, '3.3.3' );

		$this->assertSame( '3.3.3', get_option( ERANKLY_VERSION_OPTION ) );
		$this->assertSame( '3.3.3', erankly_get_runtime_state()['version'] );
	}

	public function test_runtime_state_key_maps_only_the_hot_bootstrap_options(): void {
		$this->assertSame( 'version', erankly_runtime_state_key( ERANKLY_VERSION_OPTION ) );
		$this->assertSame( 'rewrite_generation', erankly_runtime_state_key( ERANKLY_REWRITE_GENERATION_OPTION ) );
		$this->assertSame( '', erankly_runtime_state_key( 'erankly_migrated_title_defaults_v1' ) );
		$this->assertSame( '', erankly_runtime_state_key( ERANKLY_OPTION ) );
	}

	/* ----------------------------------------------------------------------
	 * Rewrite generation and signature
	 * -------------------------------------------------------------------- */

	public function test_rotate_rewrite_generation_persists_a_fresh_uuid(): void {
		erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, 'gen-before' );

		erankly_rotate_rewrite_generation();

		$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' );

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$generation
		);
		$this->assertNotSame( 'gen-before', $generation );

		if ( is_multisite() ) {
			// The generation is stored as a network option on Multisite; erankly_rotate_rewrite_generation()
			// does not mirror it to the per-site option or to the compact runtime state.
			$this->assertSame( $generation, get_site_option( ERANKLY_REWRITE_GENERATION_OPTION ) );
		} else {
			$this->assertSame( $generation, get_option( ERANKLY_REWRITE_GENERATION_OPTION ) );
			$this->assertSame( $generation, erankly_get_runtime_state()['rewrite_generation'] );
		}
	}

	public function test_get_rewrite_signature_tracks_version_generation_and_sitemap_state(): void {
		erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, 'gen-sig' );

		// Sitemaps disabled by default: the trailing flag is 0.
		$this->assertSame( ERANKLY_VERSION . ':gen-sig:0', erankly_get_rewrite_signature() );

		$settings                = erankly_get_settings();
		$settings['enable_sitemap'] = 1;
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		// Enabling the sitemap module changes the signature so every site rebuilds its rules.
		$this->assertSame( ERANKLY_VERSION . ':gen-sig:1', erankly_get_rewrite_signature() );
	}

	/* ----------------------------------------------------------------------
	 * Network site enumeration
	 * -------------------------------------------------------------------- */

	public function test_get_network_site_ids_batch_paginates_by_keyset(): void {
		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );
		$this->insert_network_site( 12, 2 );

		$this->assertSame( array( 5, 9 ), erankly_get_network_site_ids_batch( 0, 100 ) );

		// Keyset pagination resumes strictly after the last returned ID.
		$this->assertSame( array( 9 ), erankly_get_network_site_ids_batch( 5, 100 ) );
		$this->assertSame( array(), erankly_get_network_site_ids_batch( 100, 100 ) );

		// The limit is clamped to at least 1, and a negative cursor to 0.
		$this->assertSame( array( 5 ), erankly_get_network_site_ids_batch( -50, 0 ) );

		foreach ( erankly_get_network_site_ids_batch( 0, 100 ) as $site_id ) {
			$this->assertIsInt( $site_id );
		}
	}

	public function test_get_network_site_ids_batch_can_skip_deleted_spam_and_archived_sites(): void {
		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 6, 1, array( 'deleted' => 1 ) );
		$this->insert_network_site( 7, 1, array( 'spam' => 1 ) );
		$this->insert_network_site( 8, 1, array( 'archived' => 1 ) );
		$this->insert_network_site( 9 );

		$this->assertSame( array( 5, 6, 7, 8, 9 ), erankly_get_network_site_ids_batch( 0, 100 ) );
		$this->assertSame( array( 5, 9 ), erankly_get_network_site_ids_batch( 0, 100, true ) );
		$this->assertSame( array( 9 ), erankly_get_network_site_ids_batch( 5, 100, true ) );
	}

	public function test_advance_local_business_pages_migration_completes_across_two_batches(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The lifecycle blogs fixture is not a live switch_to_blog target.' );
		}

		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/contact/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->clear_local_business_migration_state();

		erankly_advance_local_business_pages_migration( '/contact/', 1 );

		$checkpoint = erankly_get_plugin_option( erankly_local_business_pages_migration_checkpoint_option(), array() );
		$this->assertIsArray( $checkpoint );
		$this->assertSame( 5, (int) $checkpoint['last_site_id'] );
		$this->assertSame( $page_id, (int) $checkpoint['map'][5] );
		$this->assertSame( erankly_local_business_pages_migration_input_id( '/contact/' ), (string) $checkpoint['input'] );
		$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$this->assertSame( array(), erankly_get_stored_settings()['local_business_pages'] );

		erankly_advance_local_business_pages_migration( '/contact/', 1 );

		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$this->assertEmpty( erankly_get_plugin_option( erankly_local_business_pages_migration_checkpoint_option(), array() ) );

		$pages = erankly_get_stored_settings()['local_business_pages'];
		$this->assertSame( $page_id, (int) $pages[5] );
		$this->assertSame( $page_id, (int) $pages[9] );
	}

	public function test_advance_local_business_pages_migration_restarts_when_the_path_changes(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The lifecycle blogs fixture is not a live switch_to_blog target.' );
		}

		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$old_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'old-path',
			)
		);
		$new_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'new-path',
			)
		);

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/old-path/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->clear_local_business_migration_state();

		erankly_advance_local_business_pages_migration( '/old-path/', 1 );

		$stored                             = erankly_get_stored_settings();
		$stored['local_business_page_path'] = '/new-path/';
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		erankly_advance_local_business_pages_migration( '/old-path/', 100 );

		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$pages = erankly_get_stored_settings()['local_business_pages'];
		$this->assertSame( $new_id, (int) $pages[5] );
		$this->assertSame( $new_id, (int) $pages[9] );
		$this->assertNotSame( $old_id, (int) $pages[5] );
	}

	public function test_advance_local_business_pages_migration_ignores_a_stale_path_argument_after_lock(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The lifecycle blogs fixture is not a live switch_to_blog target.' );
		}

		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$old_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'old-path',
			)
		);
		$new_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'new-path',
			)
		);

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/new-path/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->clear_local_business_migration_state();

		erankly_advance_local_business_pages_migration( '/old-path/', 100 );

		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$pages = erankly_get_stored_settings()['local_business_pages'];
		$this->assertSame( $new_id, (int) $pages[5] );
		$this->assertNotSame( $old_id, (int) $pages[5] );
	}

	public function test_complete_local_business_pages_migration_rejects_a_foreign_input_id(): void {
		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/new-path/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();
		$this->clear_local_business_migration_state();

		$token = erankly_acquire_settings_lock();
		$this->assertIsString( $token );

		try {
			$this->assertFalse(
				erankly_complete_local_business_pages_migration(
					array( 5 => 105 ),
					$token,
					erankly_local_business_pages_migration_input_id( '/old-path/' )
				)
			);
			$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
			$this->assertSame( array(), erankly_get_stored_settings()['local_business_pages'] );
		} finally {
			erankly_release_settings_lock( $token );
		}
	}

	public function test_complete_local_business_pages_migration_requires_a_live_lease_when_the_map_is_unchanged(): void {
		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();
		$this->clear_local_business_migration_state();

		erankly_save_local_business_pages_checkpoint(
			array(
				'input'        => erankly_local_business_pages_migration_input_id( '/contact/' ),
				'network_id'   => 0,
				'path'         => '/contact/',
				'last_site_id' => 5,
				'map'          => array(),
			)
		);

		$this->assertFalse( erankly_complete_local_business_pages_migration( array(), 'expired-worker' ) );
		$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$this->assertSame( 5, (int) erankly_get_local_business_pages_checkpoint()['last_site_id'] );
	}

	public function test_expired_local_business_worker_cannot_rewind_a_successor_checkpoint(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The lifecycle blogs fixture is not a live switch_to_blog target.' );
		}

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();
		$this->clear_local_business_migration_state();

		$token_a = erankly_acquire_settings_lock();
		$this->assertIsString( $token_a );

		$old_checkpoint = array(
			'input'        => erankly_local_business_pages_migration_input_id( '/contact/' ),
			'network_id'   => 0,
			'path'         => '/contact/',
			'last_site_id' => 5,
			'map'          => array( 5 => 105 ),
		);
		$this->assertTrue( erankly_save_local_business_pages_checkpoint( $old_checkpoint, $token_a ) );

		$current = erankly_get_settings_lock();
		$this->assertIsArray( $current );
		$current['expires_at'] = time() - 1;
		update_option( ERANKLY_SETTINGS_LOCK_OPTION, $current, false );

		$token_b = erankly_acquire_settings_lock();
		$this->assertIsString( $token_b );

		try {
			$successor = array(
				'input'        => erankly_local_business_pages_migration_input_id( '/contact/' ),
				'network_id'   => 0,
				'path'         => '/contact/',
				'last_site_id' => 9,
				'map'          => array(
					5 => 105,
					9 => 109,
				),
			);
			$this->assertTrue( erankly_save_local_business_pages_checkpoint( $successor, $token_b ) );
			$this->assertFalse( erankly_save_local_business_pages_checkpoint( $old_checkpoint, $token_a ) );
			$this->assertSame( 9, (int) erankly_get_local_business_pages_checkpoint()['last_site_id'] );
			$this->assertFalse(
				erankly_complete_local_business_pages_migration(
					array( 5 => 105 ),
					$token_a,
					erankly_local_business_pages_migration_input_id( '/contact/' )
				)
			);
			$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
			$this->assertSame( 9, (int) erankly_get_local_business_pages_checkpoint()['last_site_id'] );
		} finally {
			erankly_release_settings_lock( $token_b );
		}
	}

	public function test_renew_settings_lock_fails_after_expiry(): void {
		$token = erankly_acquire_settings_lock();
		$this->assertIsString( $token );
		$this->assertTrue( erankly_renew_settings_lock( $token ) );

		$current = erankly_get_settings_lock();
		$this->assertIsArray( $current );
		$current['expires_at'] = time() - 1;
		if ( is_multisite() ) {
			update_network_option( get_current_network_id(), ERANKLY_SETTINGS_LOCK_OPTION, $current );
		} else {
			update_option( ERANKLY_SETTINGS_LOCK_OPTION, $current, false );
		}

		$this->assertFalse( erankly_renew_settings_lock( $token ) );
		$this->assertFalse( erankly_settings_lock_is_valid( $token ) );
	}

	public function test_advance_local_business_pages_migration_is_a_noop_while_the_settings_lock_is_held(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The lifecycle blogs fixture is not a live switch_to_blog target.' );
		}

		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );

		erankly_load_default_helpers();
		$this->clear_local_business_migration_state();
		$token = erankly_acquire_settings_lock();
		$this->assertIsString( $token );

		try {
			erankly_advance_local_business_pages_migration( '/contact/', 1 );
			$this->assertEmpty( erankly_get_plugin_option( erankly_local_business_pages_migration_checkpoint_option(), array() ) );
			$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		} finally {
			erankly_release_settings_lock( $token );
		}
	}

	public function test_complete_local_business_pages_migration_keeps_the_checkpoint_when_the_writer_is_locked(): void {
		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$stored                         = erankly_get_settings();
		$stored['local_business_pages'] = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->clear_local_business_migration_state();
		erankly_save_local_business_pages_checkpoint(
			array(
				'input'        => erankly_local_business_pages_migration_input_id( '/contact/' ),
				'network_id'   => 0,
				'path'         => '/contact/',
				'last_site_id' => 5,
				'map'          => array( 5 => 105 ),
			)
		);

		$token = erankly_acquire_settings_lock();
		$this->assertIsString( $token );

		try {
			$this->assertFalse( erankly_complete_local_business_pages_migration( array( 5 => 105 ) ) );
			$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
			$this->assertSame( array(), erankly_get_stored_settings()['local_business_pages'] );
			$checkpoint = erankly_get_local_business_pages_checkpoint();
			$this->assertSame( 5, (int) $checkpoint['last_site_id'] );
		} finally {
			erankly_release_settings_lock( $token );
		}
	}

	public function test_complete_local_business_pages_migration_preserves_unrelated_settings(): void {
		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$stored                         = erankly_get_settings();
		$stored['organization_name']    = 'Keep me';
		$stored['local_business_pages'] = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$this->assertTrue( erankly_complete_local_business_pages_migration( array( get_current_blog_id() => $page_id ) ) );
		erankly_clear_settings_cache();
		$this->assertSame( 'Keep me', erankly_get_stored_settings()['organization_name'] );
		$this->assertSame( $page_id, (int) erankly_get_stored_settings()['local_business_pages'][ get_current_blog_id() ] );

		$this->clear_local_business_migration_state();
	}

	public function test_get_current_network_site_count_counts_only_the_current_network(): void {
		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );
		$this->insert_network_site( 12, 2 );

		// The site on network 2 must not be counted for network 1.
		$this->assertSame( 2, erankly_get_current_network_site_count() );
	}

	public function test_network_lifecycle_requires_cli_honours_the_limit_filter(): void {
		$this->use_network_blogs_table();
		$this->insert_network_site( 5 );
		$this->insert_network_site( 9 );

		$this->assertFalse( erankly_network_lifecycle_requires_cli() );

		$filter = static fn(): int => 1;
		add_filter( 'erankly_network_web_lifecycle_limit', $filter );

		try {
			$this->assertTrue( erankly_network_lifecycle_requires_cli() );
		} finally {
			remove_filter( 'erankly_network_web_lifecycle_limit', $filter );
		}
	}

	/* ----------------------------------------------------------------------
	 * Bootstrap and activation
	 * -------------------------------------------------------------------- */

	public function test_bootstrap_registers_the_lifecycle_cron_and_rewrite_hooks(): void {
		erankly_bootstrap();

		$this->assertSame( 10, has_action( ERANKLY_MIGRATION_CRON_HOOK, 'erankly_process_migration_job' ) );
		$this->assertSame( 10, has_action( ERANKLY_IMPORT_CRON_HOOK, 'erankly_process_import_job' ) );
		$this->assertSame( 15, has_action( 'init', 'erankly_maybe_migrate_settings' ) );
		$this->assertSame( 16, has_action( 'init', 'erankly_maybe_migrate_post_type_schema' ) );
		$this->assertSame( 17, has_action( 'init', 'erankly_maybe_migrate_local_business_pages' ) );
		$this->assertSame( 20, has_action( 'init', 'erankly_maybe_flush_after_upgrade' ) );
		$this->assertSame( 30, has_action( 'init', 'erankly_maybe_flush_rewrite_rules' ) );
		$this->assertSame(
			10,
			has_action( 'update_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_handle_sitemap_visibility_updated' )
		);
		$this->assertNotFalse( has_filter( 'debug_information', 'erankly_add_multilingual_debug_information' ) );
		$this->assertNotFalse( has_action( 'rest_api_init', 'erankly_register_special_pages_autosave_route' ) );
		$this->assertNotFalse( has_action( 'rest_api_init', 'erankly_register_local_business_routes' ) );
	}

	public function test_activate_initialises_a_fresh_install(): void {
		// The settings and version are network options on Multisite; clear them where the
		// runtime reads them so this really starts from a fresh install.
		if ( is_multisite() ) {
			delete_site_option( ERANKLY_OPTION );
			delete_site_option( ERANKLY_VERSION_OPTION );
			delete_site_option( ERANKLY_REWRITE_GENERATION_OPTION );
		} else {
			delete_option( ERANKLY_OPTION );
			delete_option( ERANKLY_VERSION_OPTION );
		}

		delete_option( ERANKLY_RUNTIME_STATE_OPTION );
		delete_option( ERANKLY_REWRITE_SIGNATURE_OPTION );
		erankly_clear_settings_cache();
		unset( $GLOBALS['erankly_runtime_state_cache'] );

		erankly_activate();

		$settings = erankly_get_plugin_option( ERANKLY_OPTION, false );

		$this->assertIsArray( $settings );
		$this->assertArrayHasKey( 'enable_sitemap', $settings );
		$this->assertSame( 0, $settings['enable_sitemap'] );
		$this->assertSame( ERANKLY_VERSION, erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' ) );

		$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $generation );
		$this->assertSame(
			ERANKLY_VERSION . ':' . $generation . ':0',
			get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' )
		);
		// Activation clears the pending-flush flag it just superseded.
		$this->assertFalse( get_option( ERANKLY_REWRITE_FLUSH_OPTION, false ) );
	}

	public function test_activate_on_an_existing_install_keeps_settings_and_rotates_the_generation(): void {
		erankly_update_plugin_settings( erankly_get_settings(), '', true );
		erankly_clear_settings_cache();

		$stored_before = erankly_get_plugin_option( ERANKLY_OPTION, array() );

		erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, 'gen-before-activate' );
		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, 'stale-signature' );

		erankly_activate();

		$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' );

		$this->assertNotSame( 'gen-before-activate', $generation );
		$this->assertSame( $stored_before, erankly_get_plugin_option( ERANKLY_OPTION, array() ) );
		$this->assertSame(
			ERANKLY_VERSION . ':' . $generation . ':0',
			get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' )
		);
	}

	/* ----------------------------------------------------------------------
	 * Retired settings and version upgrade
	 * -------------------------------------------------------------------- */

	public function test_remove_retired_setting_keys_prunes_only_the_listed_keys(): void {
		$settings                                  = erankly_get_settings();
		$settings['robots_noodp']                  = 1;
		$settings['robots_max_image_preview_large'] = 1;
		$settings['keep_me']                       = 'yes';

		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		$this->assertTrue(
			erankly_remove_retired_setting_keys(
				array( 'robots_noodp', 'robots_max_image_preview_large' )
			)
		);

		$stored = erankly_get_plugin_option( ERANKLY_OPTION, array() );

		$this->assertArrayNotHasKey( 'robots_noodp', $stored );
		$this->assertArrayNotHasKey( 'robots_max_image_preview_large', $stored );
		$this->assertSame( 'yes', $stored['keep_me'] );
	}

	public function test_remove_retired_setting_keys_is_a_noop_when_nothing_matches(): void {
		erankly_update_plugin_settings( erankly_get_settings(), '', true );
		erankly_clear_settings_cache();

		$before = erankly_get_plugin_option( ERANKLY_OPTION, array() );

		$this->assertTrue( erankly_remove_retired_setting_keys( array( 'definitely_not_present' ) ) );
		$this->assertSame( $before, erankly_get_plugin_option( ERANKLY_OPTION, array() ) );
	}

	public function test_maybe_flush_after_upgrade_records_the_version_and_prunes_retired_keys(): void {
		$this->create_retired_migration_tables();
		erankly_update_plugin_option( ERANKLY_VERSION_OPTION, '1.0.0' );

		$settings                 = erankly_get_settings();
		$settings['robots_noodp'] = 1;
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		erankly_maybe_flush_after_upgrade();

		if ( is_multisite() ) {
			$this->assertSame( ERANKLY_VERSION, get_site_option( ERANKLY_VERSION_OPTION, '' ) );
		} else {
			$this->assertSame( ERANKLY_VERSION, get_option( ERANKLY_VERSION_OPTION, '' ) );
		}
		$this->assertSame( ERANKLY_VERSION, erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' ) );
		$this->assertArrayNotHasKey( 'robots_noodp', erankly_get_plugin_option( ERANKLY_OPTION, array() ) );
	}

	public function test_maybe_flush_after_upgrade_is_a_noop_when_the_version_matches(): void {
		$settings                 = erankly_get_settings();
		$settings['robots_noodp'] = 1;
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();

		erankly_update_plugin_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );

		erankly_maybe_flush_after_upgrade();

		// The early return must leave the retired key in place (only the upgrade path prunes it).
		$stored = erankly_get_plugin_option( ERANKLY_OPTION, array() );

		$this->assertIsArray( $stored );
		$this->assertSame( 1, $stored['robots_noodp'] );
	}

	/* ----------------------------------------------------------------------
	 * Lazy rewrite flush
	 * -------------------------------------------------------------------- */

	public function test_maybe_flush_rewrite_rules_applies_the_signature_and_clears_flags(): void {
		erankly_update_plugin_settings( erankly_get_settings(), '', true );
		erankly_clear_settings_cache();

		$signature = erankly_get_rewrite_signature();

		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, 'stale-signature' );
		update_option( ERANKLY_REWRITE_FLUSH_OPTION, 'legacy-flag' );
		update_option( 'rewrite_rules', array( '^probe' => 'index.php?probe=1' ) );
		$rules_before = get_option( 'rewrite_rules' );

		erankly_maybe_flush_rewrite_rules();

		$this->assertSame( $signature, get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' ) );
		$this->assertFalse( get_option( ERANKLY_REWRITE_FLUSH_OPTION, false ) );
		// A real flush regenerates the rules the stale signature invalidated.
		$this->assertNotSame( $rules_before, get_option( 'rewrite_rules' ) );
	}

	public function test_maybe_flush_rewrite_rules_is_a_noop_when_the_signature_matches(): void {
		$signature = erankly_get_rewrite_signature();

		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, $signature );
		update_option( ERANKLY_REWRITE_FLUSH_OPTION, 'keep-me' );

		erankly_maybe_flush_rewrite_rules();

		// A real flush deletes the pending flag, so it surviving proves the early return ran.
		$this->assertSame( 'keep-me', get_option( ERANKLY_REWRITE_FLUSH_OPTION, 'MISSING' ) );
		$this->assertSame( $signature, get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' ) );
	}

	/* ----------------------------------------------------------------------
	 * Settings-change handlers
	 * -------------------------------------------------------------------- */

	public function test_handle_sitemap_visibility_updated_flushes_only_when_the_value_changes(): void {
		// erankly_flush_sitemap_cache() acts at most once per site per process; a unique blog ID gives this test a
		// fresh slot so an increment proves the flusher actually ran.
		$GLOBALS['blog_id'] = 9001;
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 500, false );

		erankly_handle_sitemap_visibility_updated( array( 'noindex' => 0 ), array( 'noindex' => 1 ) );

		$this->assertSame( 501, get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 0 ) );

		$GLOBALS['blog_id'] = 9002;
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 510, false );

		// Identical old/new values must not invalidate the cache.
		erankly_handle_sitemap_visibility_updated( array( 'noindex' => 1 ), array( 'noindex' => 1 ) );

		$this->assertSame( 510, get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 0 ) );
	}

	public function test_handle_network_settings_updated_clears_the_cache_and_flushes_watched_keys(): void {
		$GLOBALS['blog_id'] = 9011;
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 600, false );

		erankly_handle_network_settings_updated(
			ERANKLY_OPTION,
			array( 'enable_sitemap' => 1 ),
			array( 'enable_sitemap' => 0 )
		);

		$this->assertSame( 601, get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 0 ) );

		// An unwatched key change clears the request cache but must not flush sitemap output.
		$GLOBALS['blog_id'] = 9012;
		update_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 610, false );
		$GLOBALS['erankly_settings_cache'] = array( 'sentinel' => 1 );

		erankly_handle_network_settings_updated(
			ERANKLY_OPTION,
			array( 'attachment_redirect' => 'a' ),
			array( 'attachment_redirect' => 'b' )
		);

		$this->assertSame( 610, get_option( ERANKLY_SITEMAP_CACHE_VERSION_OPTION, 0 ) );
		$this->assertFalse( isset( $GLOBALS['erankly_settings_cache'] ) );
	}

	/* ----------------------------------------------------------------------
	 * Deactivation and network reset cancellation
	 * -------------------------------------------------------------------- */

	public function test_deactivate_current_site_clears_cron_hooks_and_rewrite_state(): void {
		wp_schedule_single_event( time() + 300, ERANKLY_MIGRATION_CRON_HOOK );
		wp_schedule_single_event( time() + 301, ERANKLY_IMPORT_CRON_HOOK );
		wp_schedule_single_event( time() + 302, ERANKLY_NETWORK_RESET_CRON_HOOK );

		update_option( ERANKLY_REWRITE_FLUSH_OPTION, '1' );
		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, 'sig' );
		update_option( 'rewrite_rules', array( '^x' => 'y' ) );

		$this->assertNotFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK ) );

		erankly_deactivate_current_site();

		$this->assertFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( ERANKLY_IMPORT_CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( ERANKLY_NETWORK_RESET_CRON_HOOK ) );
		$this->assertFalse( get_option( ERANKLY_REWRITE_FLUSH_OPTION, false ) );
		$this->assertFalse( get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, false ) );
		$this->assertFalse( get_option( 'rewrite_rules', false ) );
	}

	public function test_deactivate_uses_the_current_site_path_outside_a_network_deactivation(): void {
		wp_schedule_single_event( time() + 400, ERANKLY_MIGRATION_CRON_HOOK );
		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, 'sig-x' );

		erankly_deactivate( false );

		$this->assertFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK ) );
		$this->assertFalse( get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, false ) );

		// On single-site the network branch is skipped even when network deactivation is requested.
		wp_schedule_single_event( time() + 401, ERANKLY_MIGRATION_CRON_HOOK );
		update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, 'sig-y' );

		erankly_deactivate( true );

		$this->assertFalse( wp_next_scheduled( ERANKLY_MIGRATION_CRON_HOOK ) );
		$this->assertFalse( get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, false ) );
	}

	public function test_cancel_network_reset_job_removes_the_stored_job(): void {
		// erankly_cancel_network_reset_job() cancels the job in the store the runtime uses:
		// a network option on Multisite, the single-site option otherwise. Seed the same store.
		if ( is_multisite() ) {
			update_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, array( 'token' => 'tok-cancel' ) );
		} else {
			update_option( ERANKLY_NETWORK_RESET_JOB_OPTION, array( 'token' => 'tok-cancel' ) );
		}

		erankly_cancel_network_reset_job();

		$this->assertSame( 'gone', $this->read_network_reset_job_option( 'gone' ) );

		// Cancelling again is safe and idempotent.
		erankly_cancel_network_reset_job();

		$this->assertSame( 'gone', $this->read_network_reset_job_option( 'gone' ) );
	}

	/** Reads the network reset job from the store the runtime uses on this install. */
	private function read_network_reset_job_option( mixed $default ): mixed {
		return is_multisite()
			? get_site_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $default )
			: get_option( ERANKLY_NETWORK_RESET_JOB_OPTION, $default );
	}

	/**
	 * @group ms-required
	 */
	public function test_local_business_migration_uses_per_site_page_ids_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$primary_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);

		$secondary = self::factory()->blog->create();
		switch_to_blog( $secondary );
		$secondary_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);
		restore_current_blog();

		$foreign_network = self::factory()->network->create();
		$foreign_blog    = self::factory()->blog->create( array( 'site_id' => $foreign_network ) );
		switch_to_blog( $foreign_blog );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);
		restore_current_blog();

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/contact/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->clear_local_business_migration_state();

		erankly_advance_local_business_pages_migration( '/contact/', 100 );

		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$pages = erankly_normalize_local_business_page_map( erankly_get_stored_settings()['local_business_pages'] ?? array() );
		$this->assertSame( $primary_id, (int) $pages[ get_current_blog_id() ] );
		$this->assertSame( $secondary_id, (int) $pages[ (int) $secondary ] );
		$this->assertArrayNotHasKey( (int) $foreign_blog, $pages );
		$this->assertNotSame( $primary_id, $secondary_id );
	}

	/**
	 * @group ms-required
	 */
	public function test_local_business_migration_completes_across_live_multisite_batches(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		$primary_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);

		$secondary = self::factory()->blog->create();
		switch_to_blog( $secondary );
		$secondary_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);
		restore_current_blog();

		$foreign_network = self::factory()->network->create();
		$foreign_blog    = self::factory()->blog->create( array( 'site_id' => $foreign_network ) );
		switch_to_blog( $foreign_blog );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'contact',
			)
		);
		restore_current_blog();

		$stored                             = erankly_get_settings();
		$stored['local_business_page_path'] = '/contact/';
		$stored['local_business_pages']     = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();
		$this->clear_local_business_migration_state();

		erankly_advance_local_business_pages_migration( '/contact/', 1 );
		$this->assertEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );

		for ( $attempt = 0; $attempt < 50 && ! erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ); $attempt++ ) {
			erankly_advance_local_business_pages_migration( '/contact/', 1 );
		}

		$this->assertNotEmpty( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) );
		$pages = erankly_normalize_local_business_page_map( erankly_get_stored_settings()['local_business_pages'] ?? array() );
		$this->assertSame( $primary_id, (int) $pages[ get_current_blog_id() ] );
		$this->assertSame( $secondary_id, (int) $pages[ (int) $secondary ] );
		$this->assertArrayNotHasKey( (int) $foreign_blog, $pages );
		$this->assertNotSame( $primary_id, $secondary_id );
	}

	/**
	 * @group ms-required
	 */
	public function test_get_network_site_ids_batch_stays_on_the_current_live_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a live Multisite install.' );
		}

		$foreign_network = self::factory()->network->create();
		$foreign_blog    = (int) self::factory()->blog->create( array( 'site_id' => $foreign_network ) );
		$ids             = erankly_get_network_site_ids_batch( 0, 100, true );

		$this->assertNotContains( $foreign_blog, $ids );
		$this->assertContains( get_current_blog_id(), $ids );
	}
}
