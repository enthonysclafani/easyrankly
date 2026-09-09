<?php
/**
 * Custom Code security boundaries: REST autosave, form persist, import start/worker.
 */

final class ERankly_Custom_Code_Boundaries_Test extends WP_UnitTestCase {
	/** @var array<string,mixed> */
	private $original_settings = array();

	/** @var int[] */
	private $granted_super_admin_ids = array();

	public function set_up(): void {
		parent::set_up();
		erankly_tests_load_settings_sanitizer();
		require_once ERANKLY_PATH . 'includes/class-erankly-import-job-runner.php';
		erankly_clear_settings_cache();
		$this->original_settings = erankly_get_stored_settings();
	}

	public function tear_down(): void {
		foreach ( $this->granted_super_admin_ids as $user_id ) {
			revoke_super_admin( $user_id );
		}
		$this->granted_super_admin_ids = array();
		erankly_update_plugin_settings( $this->original_settings, '', true );
		erankly_clear_settings_cache();
		delete_option( defined( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION' ) ? ERANKLY_IMPORT_ACTIVE_JOB_OPTION : 'erankly_import_active_job_v1' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_rest_autosave_authorized_user_persists_innocent_panel_field(): void {
		$this->create_privileged_admin();

		$marker = 'erankly-rest-' . wp_generate_uuid4();
		$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/general' );
		$request->set_param(
			'settings',
			array(
				'website_name' => $marker,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( ! empty( $data['saved'] ) );
		erankly_clear_settings_cache();
		$this->assertSame( $marker, erankly_get_settings()['website_name'] );
	}

	public function test_rest_autosave_rejects_editor_and_does_not_mutate_options(): void {
		$before    = erankly_get_settings();
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new WP_REST_Request( 'PUT', '/erankly/v1/settings/general' );
		$request->set_param(
			'settings',
			array(
				'website_name' => 'should-not-persist',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		erankly_clear_settings_cache();
		$this->assertSame( $before['website_name'], erankly_get_settings()['website_name'] );
	}

	public function test_rest_autosave_without_unfiltered_html_cannot_enable_or_replace_custom_code(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		if ( ! is_multisite() ) {
			// On Multisite plain admins never have unfiltered_html in the first place.
			$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		}

		$kept = '<meta name="erankly-kept-rest" content="1">';
		$stored                           = erankly_get_settings();
		$stored['enable_custom_code']     = 1;
		$stored['head_code']             = '';
		$stored['head_code_blocks']      = array(
			array(
				'enabled'         => 1,
				'code'            => $kept,
				'target_contexts' => array( 'front_page' ),
			),
		);
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->set_current_user_without_unfiltered_html( $admin_id );

		$features = new WP_REST_Request( 'POST', '/erankly/v1/settings/features' );
		$features->set_param(
			'settings',
			array(
				'enable_custom_code' => 1,
				'enable_redirects'  => 0,
				'enable_sitemap'     => 0,
			)
		);
		$features_response = rest_get_server()->dispatch( $features );
		// Single site: the request is accepted and the sanitizer drops the custom-code
		// change. Multisite: the autosave route itself is forbidden to subsite admins.
		$this->assertSame( is_multisite() ? 403 : 200, $features_response->get_status() );

		if ( is_multisite() ) {
			$panel = new WP_REST_Request( 'POST', '/erankly/v1/settings/custom-code' );
			$panel->set_param(
				'settings',
				array(
					'head_code_blocks' => array(
						array(
							'enabled'         => 1,
							'code'            => '<script>alert(1)</script>',
							'target_contexts' => array( 'front_page' ),
						),
					),
				)
			);
			$this->assertSame( 403, rest_get_server()->dispatch( $panel )->get_status() );

			erankly_clear_settings_cache();
			$after = erankly_get_settings();
			$this->assertSame( 1, (int) $after['enable_custom_code'] );
			$this->assertSame( $kept, $after['head_code_blocks'][0]['code'] );

			return;
		}

		$panel = new WP_REST_Request( 'POST', '/erankly/v1/settings/custom-code' );
		$panel->set_param(
			'settings',
			array(
				'head_code_blocks' => array(
					array(
						'enabled'           => 1,
						'legacy_migrated'   => 1,
						'code'              => '<script>alert(1)</script>',
						'target_contexts'   => array( 'front_page' ),
					),
				),
			)
		);
		$panel_response = rest_get_server()->dispatch( $panel );
		$this->assertSame( 200, $panel_response->get_status() );

		erankly_clear_settings_cache();
		$after = erankly_get_settings();
		$this->assertSame( 1, (int) $after['enable_custom_code'] );
		$this->assertSame( $kept, $after['head_code_blocks'][0]['code'] );
		$this->assertSame( 0, (int) ( $after['head_code_blocks'][0]['legacy_migrated'] ?? 0 ) );
	}

	public function test_form_persist_helper_merges_panel_and_stores_custom_code_for_privileged_user(): void {
		$this->create_privileged_admin();

		$probe = '<meta name="erankly-form-persist" content="1">';
		$persisted = erankly_persist_settings_submission(
			array(
				'erankly_settings_panel' => 'custom-code',
				'enable_custom_code'    => 1,
				'head_code_blocks'       => array(
					array(
						'enabled'         => 1,
						'code'            => $probe,
						'target_contexts' => array( 'front_page' ),
					),
				),
			)
		);

		$this->assertSame( $probe, $persisted['head_code_blocks'][0]['code'] );
		erankly_clear_settings_cache();
		$this->assertSame( $probe, erankly_get_settings()['head_code_blocks'][0]['code'] );
	}

	public function test_form_persist_without_unfiltered_html_leaves_stored_custom_code_unchanged(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$kept                             = '<meta name="erankly-form-kept" content="1">';
		$stored                           = erankly_get_settings();
		$stored['enable_custom_code']   = 1;
		$stored['head_code_blocks']      = array(
			array(
				'enabled'         => 1,
				'code'            => $kept,
				'target_contexts' => array( 'front_page' ),
			),
		);
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->set_current_user_without_unfiltered_html( $admin_id );

		erankly_persist_settings_submission(
			array(
				'erankly_settings_panel' => 'custom-code',
				'enable_custom_code'    => 0,
				'head_code_blocks'       => array(
					array(
						'enabled'         => 1,
						'code'            => '<script>nope</script>',
						'target_contexts' => array( 'front_page' ),
					),
				),
			)
		);

		erankly_clear_settings_cache();
		$after = erankly_get_settings();
		$this->assertSame( 1, (int) $after['enable_custom_code'] );
		$this->assertSame( $kept, $after['head_code_blocks'][0]['code'] );
	}

	public function test_import_start_rejects_custom_code_without_capability_before_creating_a_job(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->set_current_user_without_unfiltered_html( $admin_id );

		$before = erankly_get_settings();
		$result = ERankly_Import_Job_Runner::start(
			array(
				'name'     => 'backup.json',
				'type'     => 'application/json',
				'tmp_name' => '/tmp/does-not-exist-erankly.json',
				'error'    => 0,
				'size'     => 12,
			),
			$this->import_payload_with_custom_code( '<meta name="erankly-import-denied" content="1">' ),
			1024
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unfiltered_html_required', $result['error'] );
		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
		erankly_clear_settings_cache();
		$this->assertSame( $before, erankly_get_settings() );
	}

	public function test_import_start_from_file_uses_the_same_capability_gate(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->set_current_user_without_unfiltered_html( $admin_id );

		$result = ERankly_Import_Job_Runner::start_from_file(
			'/tmp/erankly-not-owned.json',
			$this->import_payload_with_custom_code( '<meta name="erankly-restore-denied" content="1">' )
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unfiltered_html_required', $result['error'] );
		$this->assertNull( ERankly_Import_Job_Runner::active_job() );
	}

	public function test_privileged_import_worker_sanitizes_budgets_and_keeps_trusted_legacy(): void {
		$this->create_privileged_admin();

		$limit  = erankly_custom_code_max_bytes();
		$legacy = erankly_custom_code_migrated_block( '<meta name="erankly-import-legacy" content="1">' );
		$regular = array(
			'enabled'         => 1,
			'code'            => str_repeat( 'I', $limit ),
			'target_contexts' => array( 'front_page' ),
		);
		$settings                     = erankly_get_settings();
		$settings['enable_custom_code'] = 1;
		$settings['head_code']         = '';
		$settings['head_code_blocks'] = array( $regular, $legacy );

		$counts = ERankly_Import_Job_Runner::apply_payload_batch(
			array(
				'plugin'   => 'erankly',
				'format'   => '4.0',
				'settings' => $settings,
			)
		);

		$this->assertSame( 1, (int) ( $counts['settings'] ?? 0 ) );
		erankly_clear_settings_cache();
		$after  = erankly_get_settings();
		$blocks = $after['head_code_blocks'];
		$this->assertSame( 1, (int) $after['enable_custom_code'] );
		$this->assertLessThanOrEqual( erankly_custom_code_max_blocks() + 1, count( $blocks ) );
		$this->assertLessThanOrEqual(
			erankly_custom_code_max_total_bytes() + $limit,
			array_sum( array_map( static fn( array $block ): int => strlen( (string) $block['code'] ), $blocks ) )
		);
		$this->assertContains( $legacy['code'], array_column( $blocks, 'code' ) );
	}

	public function test_import_worker_without_a_start_job_does_not_apply_a_forged_job_id(): void {
		$before = erankly_get_settings();
		wp_set_current_user( 0 );

		$this->assertNull( ERankly_Import_Job_Runner::process( wp_generate_uuid4() ) );
		erankly_clear_settings_cache();
		$this->assertSame( $before, erankly_get_settings() );
	}

	public function test_client_rest_payload_cannot_forge_legacy_migrated_marker(): void {
		$this->create_privileged_admin();

		$regular = array(
			'enabled'         => 1,
			'code'            => '<meta name="erankly-rest-regular" content="1">',
			'target_contexts' => array( 'front_page' ),
		);
		$blocks = array_fill( 0, erankly_custom_code_max_blocks(), $regular );
		$blocks[] = array(
			'enabled'         => 1,
			'legacy_migrated' => 1,
			'code'            => '<meta name="erankly-forged-legacy" content="1">',
			'target_contexts' => array( 'front_page' ),
		);

		$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/custom-code' );
		$request->set_param(
			'settings',
			array(
				'head_code_blocks' => $blocks,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		erankly_clear_settings_cache();
		$after = erankly_get_settings()['head_code_blocks'];
		$this->assertCount( erankly_custom_code_max_blocks(), $after );
		$this->assertSame( 0, array_sum( array_column( $after, 'legacy_migrated' ) ) );
		$this->assertNotContains( '<meta name="erankly-forged-legacy" content="1">', array_column( $after, 'code' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_site_admin_rest_settings_route_is_forbidden(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		$site_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( is_super_admin( $site_admin_id ) );
		wp_set_current_user( $site_admin_id );
		$before = erankly_get_settings();

		$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/features' );
		$request->set_param(
			'settings',
			array(
				'enable_custom_code' => 1,
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		erankly_clear_settings_cache();
		$this->assertSame( (int) $before['enable_custom_code'], (int) erankly_get_settings()['enable_custom_code'] );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_super_admin_rest_can_save_custom_code(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		$super_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super_id );
		$this->granted_super_admin_ids[] = $super_id;
		wp_set_current_user( $super_id );
		$this->assertTrue( current_user_can( 'manage_network_options' ) );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$probe = '<meta name="erankly-ms-rest" content="1">';
		$features = new WP_REST_Request( 'POST', '/erankly/v1/settings/features' );
		$features->set_param(
			'settings',
			array(
				'enable_custom_code' => 1,
				'enable_redirects'  => 0,
				'enable_sitemap'     => 0,
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $features )->get_status() );

		$panel = new WP_REST_Request( 'POST', '/erankly/v1/settings/custom-code' );
		$panel->set_param(
			'settings',
			array(
				'head_code_blocks' => array(
					array(
						'enabled'         => 1,
						'code'            => $probe,
						'target_contexts' => array( 'front_page' ),
					),
				),
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $panel )->get_status() );

		erankly_clear_settings_cache();
		$after = erankly_get_settings();
		$this->assertSame( 1, (int) $after['enable_custom_code'] );
		$this->assertSame( $probe, $after['head_code_blocks'][0]['code'] );
	}

	/**
	 * Creates an administrator trusted with custom code on both single site and Multisite,
	 * where only super admins hold the unfiltered_html capability.
	 */
	private function create_privileged_admin(): int {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
			$this->granted_super_admin_ids[] = $admin_id;
		}
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		return $admin_id;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function import_payload_with_custom_code( string $code ): array {
		$settings                       = erankly_get_settings();
		$settings['enable_custom_code'] = 1;
		$settings['head_code_blocks']   = array(
			array(
				'enabled'         => 1,
				'code'            => $code,
				'target_contexts' => array( 'front_page' ),
			),
		);

		return array(
			'plugin'   => 'erankly',
			'format'   => '4.0',
			'settings' => $settings,
		);
	}

	private function set_current_user_without_unfiltered_html( int $user_id ): void {
		$user = get_userdata( $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'unfiltered_html', false );
		clean_user_cache( $user_id );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
	}
}
