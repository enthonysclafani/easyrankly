<?php
/** Redirect runtime: activator/boot wiring, frontend runner, REST routes and admin UI. */

final class ERankly_Redirects_Runtime_Test extends WP_UnitTestCase {

	private ERankly_Redirects_Repository $repository;

	/** @var array<int,string> */
	private array $extra_allowed_hosts = array();

	public function set_up(): void {
		parent::set_up();

		wp_cache_flush();
		erankly_tests_load_settings_sanitizer();
		require_once ERANKLY_PATH . 'includes/admin.php';
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';

		if ( ! function_exists( 'erankly_ensure_redirect_classes_available' ) ) {
			require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
		}
		erankly_ensure_redirect_classes_available();

		require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-runner.php';
		require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-rest.php';
		require_once ERANKLY_PATH . 'includes/redirects/class-erankly-redirects-admin.php';
		require_once ERANKLY_PATH . 'includes/redirects.php';

		if ( ! erankly_table_exists( ERankly_Redirects_Repository::get_table_name() ) ) {
			ERankly_Redirects_Activator::activate();
		}

		$this->repository = new ERankly_Redirects_Repository();
	}

	public function tear_down(): void {
		global $wpdb;

		$table = ERankly_Redirects_Repository::get_table_name();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup of a plugin-owned table.
		foreach (
			array(
				'erankly_redirects_runtime_rules',
				'erankly_redirects_runtime_rules_global',
				'erankly_redirects_runtime_rules_all',
				'erankly_redirects_runtime_rules_prefix_index',
				'debug_deprecated',
			) as $option
		) {
			delete_option( $option );
		}

		unset( $_GET['rest_route'], $GLOBALS['erankly_redirects_admin'] );
		wp_set_current_user( 0 );
		remove_all_filters( 'allowed_redirect_hosts' );

		parent::tear_down();
	}

	private function make_rule( array $overrides = array() ): int {
		return $this->repository->create(
			array_merge(
				array(
					'source_path' => '/seed',
					'target_url'  => '/seed-target',
					'status_code' => 301,
					'match_type'  => 'exact',
					'is_active'   => 1,
					'note'        => '',
				),
				$overrides
			)
		);
	}

	private function admin_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	private function invoke( object $object, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $object, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $object, $args );
	}

	private function invoke_static( string $class, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( null, $args );
	}

	private function capture( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	private function enable_exact_hit_sampling(): void {
		add_filter(
			'erankly_redirect_hit_sample_rate',
			static function (): int {
				return 1;
			}
		);
	}

	// ---------------------------------------------------------------------
	// Activator, boot and DB upgrade.
	// ---------------------------------------------------------------------

	public function test_activator_creates_a_usable_redirects_table(): void {
		ERankly_Redirects_Activator::activate();

		// The table only accepts reads/writes once activation has created the full schema.
		$id  = $this->make_rule(
			array(
				'source_path'    => '/activator-probe',
				'target_url'     => '/probe-target',
				'status_code'    => 308,
				'match_type'     => 'regex',
				'case_sensitive' => 1,
				'trailing_slash' => 'exact',
				'query_mode'     => 'preserve',
				'note'           => 'probe',
			)
		);
		$row = $this->repository->find_by_id( $id );

		$this->assertIsArray( $row );
		$this->assertSame( '/activator-probe', $row['source_path'] );
		$this->assertSame( '308', (string) $row['status_code'] );
		$this->assertSame( 'regex', $row['match_type'] );
		$this->assertSame( 'exact', $row['trailing_slash'] );
		$this->assertSame( 'preserve', $row['query_mode'] );
		$this->assertSame( 1, (int) $row['case_sensitive'] );
	}

	public function test_boot_registers_runner_and_rest_and_tracks_the_db_version(): void {
		delete_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION );

		erankly_redirects_boot();

		$this->assertTrue( class_exists( 'ERankly_Redirects_Runner' ) );
		$this->assertTrue( class_exists( 'ERankly_Redirects_Rest' ) );
		$this->assertNotFalse( has_action( 'parse_request' ) );
		$this->assertNotFalse( has_action( 'rest_api_init' ) );
		$this->assertSame( ERANKLY_REDIRECTS_DB_VERSION, get_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION ) );
	}

	public function test_maybe_upgrade_db_skips_current_version_and_rotates_when_stale(): void {
		update_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION, ERANKLY_REDIRECTS_DB_VERSION, false );
		$before = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' );

		erankly_redirects_maybe_upgrade_db();
		$this->assertSame( $before, (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' ) );

		delete_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION );
		erankly_redirects_maybe_upgrade_db();

		$after = (string) get_option( ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION, '' );
		$this->assertNotSame( $before, $after );
		$this->assertNotEmpty( $after );
		$this->assertSame( ERANKLY_REDIRECTS_DB_VERSION, get_option( ERANKLY_REDIRECTS_DB_VERSION_OPTION ) );
	}

	public function test_render_panel_delegates_to_the_registered_admin_instance(): void {
		$this->admin_user();

		$this->assertSame( '', $this->capture( static function (): void { erankly_redirects_render_panel(); } ) );

		$GLOBALS['erankly_redirects_admin'] = new ERankly_Redirects_Admin( $this->repository );
		$markup = $this->capture( static function (): void { erankly_redirects_render_panel(); } );

		$this->assertStringContainsString( 'erankly-redirects-table-wrap', $markup );
		$this->assertStringContainsString( 'Source URL', $markup );
	}

	// ---------------------------------------------------------------------
	// Frontend runner.
	// ---------------------------------------------------------------------

	public function test_runner_registers_parse_request_at_priority_eleven(): void {
		$runner = new ERankly_Redirects_Runner( $this->repository );
		$runner->register_hooks();

		$this->assertSame( 11, has_action( 'parse_request', array( $runner, 'maybe_redirect' ) ) );
	}

	public function test_maybe_redirect_answers_a_status_only_rule_with_410(): void {
		$id = $this->make_rule(
			array(
				'source_path' => '/gone-route',
				'status_code' => 410,
				'target_url'  => '',
			)
		);
		$this->enable_exact_hit_sampling();
		wp_set_current_user( 0 );
		unset( $_GET['rest_route'] );
		$_SERVER['REQUEST_URI'] = '/gone-route';

		$runner = new ERankly_Redirects_Runner( $this->repository );

		try {
			$runner->maybe_redirect();
			$this->fail( 'A 410 rule must terminate the request through wp_die().' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( 410, $exception->getCode() );
		}

		$row = $this->repository->find_by_id( $id );
		$this->assertSame( 1, (int) $row['hit_count'] );
		$this->assertNotEmpty( (string) $row['last_hit_at'] );
	}

	public function test_maybe_redirect_ignores_self_loops_and_empty_targets(): void {
		$loop_id  = $this->make_rule(
			array(
				'source_path' => '/selfloop',
				'target_url'  => '/selfloop',
				'status_code' => 301,
			)
		);
		$empty_id = $this->make_rule(
			array(
				'source_path' => '/emptytarget',
				'target_url'  => '',
				'status_code' => 301,
			)
		);
		$this->enable_exact_hit_sampling();
		wp_set_current_user( 0 );
		unset( $_GET['rest_route'] );

		$runner = new ERankly_Redirects_Runner( $this->repository );

		$_SERVER['REQUEST_URI'] = '/selfloop';
		$runner->maybe_redirect();
		$_SERVER['REQUEST_URI'] = '/emptytarget';
		$runner->maybe_redirect();

		$this->assertSame( 0, (int) $this->repository->find_by_id( $loop_id )['hit_count'] );
		$this->assertSame( 0, (int) $this->repository->find_by_id( $empty_id )['hit_count'] );
	}

	public function test_maybe_redirect_never_redirects_administrators(): void {
		$id = $this->make_rule(
			array(
				'source_path' => '/admin-only-rule',
				'target_url'  => '/somewhere',
				'status_code' => 301,
			)
		);
		$this->enable_exact_hit_sampling();
		$this->admin_user();
		unset( $_GET['rest_route'] );
		$_SERVER['REQUEST_URI'] = '/admin-only-rule';

		$runner = new ERankly_Redirects_Runner( $this->repository );
		$runner->maybe_redirect();

		$this->assertSame( 0, (int) $this->repository->find_by_id( $id )['hit_count'] );
	}

	public function test_should_skip_request_recognises_core_endpoints(): void {
		$runner = new ERankly_Redirects_Runner( $this->repository );

		unset( $_GET['rest_route'] );
		$this->assertFalse( $this->invoke( $runner, 'should_skip_request', array( '/normal-page' ) ) );

		$_GET['rest_route'] = '/erankly/v1/x';
		$this->assertTrue( $this->invoke( $runner, 'should_skip_request', array( '/normal-page' ) ) );
		unset( $_GET['rest_route'] );

		$this->assertTrue( $this->invoke( $runner, 'should_skip_request', array( '/wp-login.php' ) ) );

		$rest_path = wp_parse_url( rest_url(), PHP_URL_PATH );
		$this->assertIsString( $rest_path );
		$this->assertTrue( $this->invoke( $runner, 'should_skip_request', array( $rest_path ) ) );
	}

	public function test_find_advanced_match_returns_first_match_and_rejects_overlong_paths(): void {
		$runner = new ERankly_Redirects_Runner( $this->repository );
		$rules  = array(
			array(
				'id'             => 1,
				'match_type'     => 'wildcard',
				'source_path'    => '/blog/*',
				'target_url'     => '/x',
				'query_mode'     => 'ignore',
				'case_sensitive' => 0,
				'trailing_slash' => 'ignore',
			),
		);

		$match = $this->invoke( $runner, 'find_advanced_match', array( '/blog/post', $rules ) );
		$this->assertIsArray( $match );
		$this->assertSame( 1, (int) $match['id'] );

		$this->assertNull( $this->invoke( $runner, 'find_advanced_match', array( '/nope', $rules ) ) );

		$catch_all = array( $rules[0] + array( 'source_path' => '/*' ) );
		$this->assertNull(
			$this->invoke( $runner, 'find_advanced_match', array( '/' . str_repeat( 'a', 5000 ), $catch_all ) )
		);
	}

	public function test_is_loop_detects_direct_and_chained_cycles(): void {
		$this->make_rule(
			array(
				'source_path' => '/b',
				'target_url'  => '/c',
			)
		);
		$this->make_rule(
			array(
				'source_path' => '/c',
				'target_url'  => '/b',
			)
		);

		$runner = new ERankly_Redirects_Runner( $this->repository );

		$this->assertTrue( $this->invoke( $runner, 'is_loop', array( '/a', '/a' ) ) );
		$this->assertTrue( $this->invoke( $runner, 'is_loop', array( '/a', '/b' ) ) );
		$this->assertFalse( $this->invoke( $runner, 'is_loop', array( '/a', '/d' ) ) );
	}

	public function test_allow_safe_external_host_for_target_adds_validated_hosts_only(): void {
		$runner = new ERankly_Redirects_Runner( $this->repository );

		remove_all_filters( 'allowed_redirect_hosts' );
		$this->invoke( $runner, 'allow_safe_external_host_for_target', array( 'https://example.com/x' ) );
		$hosts = apply_filters( 'allowed_redirect_hosts', array() );
		$this->assertContains( 'example.com', $hosts );

		remove_all_filters( 'allowed_redirect_hosts' );
		$this->invoke( $runner, 'allow_safe_external_host_for_target', array( '/internal-path' ) );
		$this->assertSame( array(), apply_filters( 'allowed_redirect_hosts', array() ) );
	}

	// ---------------------------------------------------------------------
	// REST routes.
	// ---------------------------------------------------------------------

	public function test_rest_registers_hooks_and_permission_follows_manage_options(): void {
		$rest = new ERankly_Redirects_Rest( $this->repository );
		$rest->register_hooks();
		$this->assertNotFalse( has_action( 'rest_api_init', array( $rest, 'register_routes' ) ) );

		wp_set_current_user( 0 );
		$this->assertFalse( $rest->check_permission() );

		$this->admin_user();
		$this->assertTrue( $rest->check_permission() );
	}

	public function test_rest_toggle_flips_active_state_and_reports_missing(): void {
		$rest = new ERankly_Redirects_Rest( $this->repository );
		$id   = $this->make_rule( array( 'source_path' => '/rest-toggle' ) );

		$request = new WP_REST_Request( 'POST', '/erankly/v1/redirects/toggle' );
		$request->set_param( 'id', $id );
		$response = $rest->toggle( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertFalse( $response->get_data()['is_active'] );

		$missing = new WP_REST_Request( 'POST', '/erankly/v1/redirects/toggle' );
		$missing->set_param( 'id', 999999 );
		$error = $rest->toggle( $missing );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'erankly_redirect_not_found', $error->get_error_code() );
	}

	public function test_rest_delete_removes_row_and_reports_missing(): void {
		$rest = new ERankly_Redirects_Rest( $this->repository );
		$id   = $this->make_rule( array( 'source_path' => '/rest-delete' ) );

		$request = new WP_REST_Request( 'POST', '/erankly/v1/redirects/delete' );
		$request->set_param( 'id', $id );
		$response = $rest->delete( $request );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNull( $this->repository->find_by_id( $id ) );

		$missing = new WP_REST_Request( 'POST', '/erankly/v1/redirects/delete' );
		$missing->set_param( 'id', 999999 );
		$this->assertInstanceOf( WP_Error::class, $rest->delete( $missing ) );
	}

	public function test_rest_test_rule_validates_modes_and_evaluates_a_match(): void {
		$rest = new ERankly_Redirects_Rest( $this->repository );

		$invalid = new WP_REST_Request( 'POST', '/erankly/v1/redirects/test' );
		$invalid->set_param( 'match_type', 'nope' );
		$invalid->set_param( 'query_mode', 'ignore' );
		$error = $rest->test_rule( $invalid );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'erankly_redirect_test_invalid_mode', $error->get_error_code() );

		$missing = new WP_REST_Request( 'POST', '/erankly/v1/redirects/test' );
		$missing->set_param( 'match_type', 'exact' );
		$missing->set_param( 'query_mode', 'ignore' );
		$missing->set_param( 'source_path', '' );
		$missing->set_param( 'test_url', '' );
		$this->assertSame( 'erankly_redirect_test_required', $rest->test_rule( $missing )->get_error_code() );

		$valid = new WP_REST_Request( 'POST', '/erankly/v1/redirects/test' );
		$valid->set_param( 'match_type', 'exact' );
		$valid->set_param( 'query_mode', 'ignore' );
		$valid->set_param( 'source_path', '/old' );
		$valid->set_param( 'test_url', '/old' );
		$valid->set_param( 'target_url', '/new' );
		$valid->set_param( 'status_code', 301 );
		$data = $rest->test_rule( $valid )->get_data();
		$this->assertTrue( $data['matches'] );
		$this->assertSame( '/new', $data['target_url'] );
		$this->assertFalse( $data['status_only'] );
	}

	public function test_rest_routes_dispatch_respect_the_permission_callback(): void {
		$rest = new ERankly_Redirects_Rest( $this->repository );
		$rest->register_hooks();
		// register_rest_route() requires the rest_api_init action to have fired.
		do_action( 'rest_api_init', rest_get_server() );

		$id = $this->make_rule( array( 'source_path' => '/dispatch-toggle' ) );

		$this->admin_user();
		$request = new WP_REST_Request( 'POST', '/erankly/v1/redirects/toggle' );
		$request->set_param( 'id', $id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['is_active'] );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$forbidden = new WP_REST_Request( 'POST', '/erankly/v1/redirects/test' );
		$forbidden->set_param( 'match_type', 'exact' );
		$forbidden->set_param( 'query_mode', 'ignore' );
		$forbidden->set_param( 'source_path', '/a' );
		$forbidden->set_param( 'test_url', '/a' );
		$this->assertContains( rest_get_server()->dispatch( $forbidden )->get_status(), array( 401, 403 ) );
	}

	// ---------------------------------------------------------------------
	// Admin UI.
	// ---------------------------------------------------------------------

	public function test_admin_registers_actions_hook(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );
		$admin->register_hooks();

		$this->assertSame( 10, has_action( 'admin_init', array( $admin, 'handle_actions' ) ) );
	}

	public function test_status_code_labels_return_translated_map(): void {
		$labels = ERankly_Redirects_Admin::status_code_labels();

		$this->assertArrayHasKey( 301, $labels );
		$this->assertArrayHasKey( 451, $labels );
		$this->assertStringContainsString( '301', $labels[301] );
	}

	public function test_admin_label_helpers_fall_back_to_raw_values(): void {
		$this->assertSame( 'Exact URL', $this->invoke_static( ERankly_Redirects_Admin::class, 'match_type_label', array( 'exact' ) ) );
		$this->assertSame( 'mystery', $this->invoke_static( ERankly_Redirects_Admin::class, 'match_type_label', array( 'mystery' ) ) );
		$this->assertSame( 'Any, discarded', $this->invoke_static( ERankly_Redirects_Admin::class, 'query_mode_label', array( 'ignore' ) ) );
		$this->assertSame( 'other', $this->invoke_static( ERankly_Redirects_Admin::class, 'query_mode_label', array( 'other' ) ) );
	}

	public function test_format_last_hit_formats_valid_dates_and_passes_through_invalid(): void {
		$formatted = $this->invoke_static( ERankly_Redirects_Admin::class, 'format_last_hit', array( '2024-01-02 03:04:05' ) );
		$this->assertStringContainsString( '2024', $formatted );
		$this->assertNotSame( '2024-01-02 03:04:05', $formatted );

		$this->assertSame( 'not-a-date', $this->invoke_static( ERankly_Redirects_Admin::class, 'format_last_hit', array( 'not-a-date' ) ) );
	}

	public function test_table_state_args_extracts_supported_state(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$state = $this->invoke(
			$admin,
			'table_state_args',
			array(
				array(
					's'       => 'needle',
					'paged'   => 3,
					'orderby' => 'hit_count',
					'order'   => 'asc',
				),
			)
		);
		$this->assertSame( 'needle', $state['s'] );
		$this->assertSame( 3, $state['paged'] );
		$this->assertSame( 'hit_count', $state['orderby'] );
		$this->assertSame( 'asc', $state['order'] );

		$empty = $this->invoke( $admin, 'table_state_args', array( array( 'orderby' => 'not-a-column' ) ) );
		$this->assertSame( array(), $empty );
	}

	public function test_store_and_consume_form_state_round_trips_once(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );
		$this->admin_user();

		$this->invoke(
			$admin,
			'store_form_state',
			array(
				0,
				array(
					'match_type'   => 'exact',
					'source_path'  => '/a?embed=1',
					'target_url'   => '/b',
					'status_code'  => 301,
					'query_mode'   => 'ignore',
					'source_query' => '',
					'is_active'    => 1,
					'note'         => 'n',
				),
			)
		);

		$state = $this->invoke( $admin, 'consume_form_state' );
		$this->assertIsArray( $state );
		$this->assertSame( 0, $state['id'] );
		$this->assertSame( 'exact', $state['values']['query_mode'] );
		$this->assertSame( 'embed=1', $state['values']['source_query'] );

		$this->assertNull( $this->invoke( $admin, 'consume_form_state' ) );
	}

	public function test_prepare_redirect_data_validates_and_normalizes(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		list( $data, $errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path'  => '/valid-source',
					'target_url'   => '/valid-target',
					'status_code'  => 301,
					'match_type'   => 'exact',
					'query_mode'   => 'ignore',
					'source_query' => '',
					'is_active'    => 1,
					'note'         => 'hi',
				),
			)
		);
		$this->assertSame( array(), $errors );
		$this->assertSame( '/valid-source', $data['source_path'] );
		$this->assertSame( '/valid-target', $data['target_url'] );

		list( , $source_errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path' => '',
					'match_type'  => 'bogus',
				),
			)
		);
		$this->assertContains( 'source_required', $source_errors );
		$this->assertContains( 'matching_mode', $source_errors );

		list( , $status_errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path' => '/x',
					'target_url'  => '/y',
					'status_code' => 200,
					'match_type'  => 'exact',
					'query_mode'  => 'ignore',
				),
			)
		);
		$this->assertContains( 'status_code', $status_errors );

		list( , $wildcard_errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path' => '/no-star',
					'target_url'  => '/y',
					'status_code' => 301,
					'match_type'  => 'wildcard',
					'query_mode'  => 'ignore',
				),
			)
		);
		$this->assertContains( 'source_wildcard', $wildcard_errors );

		list( $embedded, $embedded_errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path'  => '/a?x=1',
					'target_url'   => '/y',
					'status_code'  => 301,
					'match_type'   => 'exact',
					'query_mode'   => 'ignore',
					'source_query' => '',
				),
			)
		);
		$this->assertSame( array(), $embedded_errors );
		$this->assertSame( '/a', $embedded['source_path'] );
		$this->assertSame( 'x=1', $embedded['source_query'] );
		$this->assertSame( 'exact', $embedded['query_mode'] );

		list( $status_only, $status_only_errors ) = $this->invoke(
			$admin,
			'prepare_redirect_data',
			array(
				array(
					'source_path' => '/gone',
					'target_url'  => '',
					'status_code' => 410,
					'match_type'  => 'exact',
					'query_mode'  => 'ignore',
				),
			)
		);
		$this->assertNotContains( 'target_required', $status_only_errors );
		$this->assertSame( '', $status_only['target_url'] );
	}

	public function test_read_prefill_reads_get_values_and_defaults_bad_status(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$this->assertNull( $this->invoke( $admin, 'read_prefill' ) );

		$_GET['erankly_redirects_prefill_source'] = '/from-deep-link';
		$_GET['erankly_redirects_prefill_target'] = '/to-deep-link';
		$_GET['erankly_redirects_prefill_status'] = 999;

		$prefill = $this->invoke( $admin, 'read_prefill' );
		$this->assertIsArray( $prefill );
		$this->assertSame( '/from-deep-link', $prefill['source_path'] );
		$this->assertSame( '/to-deep-link', $prefill['target_url'] );
		$this->assertSame( 301, $prefill['status_code'] );

		unset( $_GET['erankly_redirects_prefill_source'], $_GET['erankly_redirects_prefill_target'], $_GET['erankly_redirects_prefill_status'] );
	}

	public function test_render_item_count_and_pagination_output(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$this->assertStringContainsString(
			'0 redirects',
			$this->capture(
				function () use ( $admin ): void {
					$this->invoke( $admin, 'render_item_count', array( 1, 25, 0, 0 ) );
				}
			)
		);

		$range = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_item_count', array( 2, 25, 5, 30 ) );
			}
		);
		$this->assertStringContainsString( '26', $range );
		$this->assertStringContainsString( '30', $range );

		$this->assertSame(
			'',
			$this->capture(
				function () use ( $admin ): void {
					$this->invoke( $admin, 'render_pagination', array( 1, 1, '', '', 'desc' ) );
				}
			)
		);
		$this->assertStringContainsString(
			'tablenav',
			$this->capture(
				function () use ( $admin ): void {
					$this->invoke( $admin, 'render_pagination', array( 1, 3, '', '', 'desc' ) );
				}
			)
		);
	}

	public function test_render_notices_outputs_error_and_success_variants(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$_GET['erankly_redirects_error'] = 'source_required';
		$error_markup                    = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_notices' );
			}
		);
		$this->assertStringContainsString( 'notice-error', $error_markup );
		$this->assertStringContainsString( 'Enter a source URL or path.', $error_markup );
		unset( $_GET['erankly_redirects_error'] );

		$_GET['erankly_redirects_notice'] = 'created';
		$success_markup                   = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_notices' );
			}
		);
		$this->assertStringContainsString( 'notice-success', $success_markup );
		$this->assertStringContainsString( 'Redirect created.', $success_markup );
		unset( $_GET['erankly_redirects_notice'] );

		update_option( 'erankly_redirects_v3_migration_report', array( 'transformed' => array( 1 ), 'disabled' => array() ), false );
		$migration_markup = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_notices' );
			}
		);
		$this->assertStringContainsString( 'Redirect upgrade completed', $migration_markup );
		delete_option( 'erankly_redirects_v3_migration_report' );
	}

	public function test_render_sortable_column_header_marks_active_column(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$markup = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_sortable_column_header', array( 'hit_count', 'Hits', 'hit_count', 'asc', 'needle' ) );
			}
		);

		$this->assertStringContainsString( 'sorted', $markup );
		$this->assertStringContainsString( 'ascending', $markup );
		$this->assertStringContainsString( 'orderby=hit_count', $markup );
		$this->assertStringContainsString( 'order=desc', $markup );
	}

	public function test_render_redirect_table_handles_empty_and_populated_states(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$empty = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_redirect_table', array( array(), '', 'desc', '', array() ) );
			}
		);
		$this->assertStringContainsString( 'No redirects found.', $empty );

		$row = array(
			'id'           => 12,
			'source_path'  => '/table-source',
			'target_url'   => '/table-target',
			'status_code'  => 301,
			'is_active'    => 1,
			'hit_count'    => 0,
			'last_hit_at'  => null,
			'match_type'   => 'exact',
			'query_mode'   => 'ignore',
			'source_query' => '',
			'note'         => '',
		);
		$populated = $this->capture(
			function () use ( $admin, $row ): void {
				$this->invoke( $admin, 'render_redirect_table', array( array( $row ), '', 'desc', '', array() ) );
			}
		);
		$this->assertStringContainsString( '/table-source', $populated );
		$this->assertStringContainsString( 'Disable', $populated );
	}

	public function test_render_redirect_form_switches_between_add_and_edit_modes(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$edit = $this->capture(
			function () use ( $admin ): void {
				$this->invoke(
					$admin,
					'render_redirect_form',
					array(
						array(
							'id'             => 5,
							'source_path'    => '/edit-source',
							'target_url'     => '/edit-target',
							'status_code'    => 302,
							'match_type'     => 'exact',
							'query_mode'     => 'ignore',
							'source_query'   => '',
							'case_sensitive' => 0,
							'trailing_slash' => 'ignore',
							'is_active'      => 1,
							'note'           => '',
						),
						null,
						array(),
					)
				);
			}
		);
		$this->assertStringContainsString( 'Update Redirect', $edit );
		$this->assertStringContainsString( '/edit-source', $edit );

		$add = $this->capture(
			function () use ( $admin ): void {
				$this->invoke( $admin, 'render_redirect_form', array( null, null, array() ) );
			}
		);
		$this->assertStringContainsString( 'Add Redirect', $add );
	}

	public function test_admin_url_builds_the_settings_screen_url(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );

		$url = $this->invoke( $admin, 'admin_url', array( array( 'erankly_redirects_edit' => 5 ) ) );
		$this->assertStringContainsString( 'page=erankly', $url );
		$this->assertStringContainsString( 'erankly_tab=redirects', $url );
		$this->assertStringContainsString( 'erankly_redirects_edit=5', $url );
	}

	public function test_handle_actions_dispatches_delete_and_requires_a_nonce(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );
		$this->admin_user();

		$_GET = array(
			'page'                     => 'erankly',
			'erankly_redirects_action' => 'delete',
			'redirect_id'              => 5,
		);

		$this->expectException( WPDieException::class );
		$admin->handle_actions();
	}

	public function test_handle_actions_dispatches_toggle_and_requires_a_nonce(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );
		$this->admin_user();

		$_GET = array(
			'page'                     => 'erankly',
			'erankly_redirects_action' => 'toggle',
			'redirect_id'              => 5,
		);

		$this->expectException( WPDieException::class );
		$admin->handle_actions();
	}

	public function test_handle_actions_dispatches_save_and_requires_a_nonce(): void {
		$admin = new ERankly_Redirects_Admin( $this->repository );
		$this->admin_user();

		$_GET  = array( 'page' => 'erankly' );
		$_POST = array( 'erankly_redirects_action' => 'save_redirect' );

		$this->expectException( WPDieException::class );
		$admin->handle_actions();
	}
}
