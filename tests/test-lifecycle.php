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

	private function insert_network_site( int $blog_id, int $site_id = 1 ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->blogs,
			array(
				'blog_id' => $blog_id,
				'site_id' => $site_id,
				'domain'  => 'site-' . $blog_id . '.test',
				'path'    => '/',
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

	public function test_runtime_state_key_maps_only_the_compact_options(): void {
		$this->assertSame( 'version', erankly_runtime_state_key( ERANKLY_VERSION_OPTION ) );
		$this->assertSame( 'rewrite_generation', erankly_runtime_state_key( ERANKLY_REWRITE_GENERATION_OPTION ) );
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
		$this->assertSame( 20, has_action( 'init', 'erankly_maybe_flush_after_upgrade' ) );
		$this->assertSame( 30, has_action( 'init', 'erankly_maybe_flush_rewrite_rules' ) );
		$this->assertSame(
			10,
			has_action( 'update_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_handle_sitemap_visibility_updated' )
		);
		$this->assertNotFalse( has_filter( 'debug_information', 'erankly_add_multilingual_debug_information' ) );
		$this->assertNotFalse( has_action( 'rest_api_init', 'erankly_register_special_pages_autosave_route' ) );
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
}
