<?php
/** EasyRankly multilingual provider registry (extension API v1). */

/** Configurable test double for the public provider contract. */
final class ERankly_Test_Multilingual_Provider implements ERankly_Multilingual_Provider_Interface {

	public bool $hooks_ran = false;

	/** @param array<string,mixed> $config */
	public function __construct( private array $config = array() ) {}

	public function get_id(): string {
		return (string) ( $this->config['id'] ?? 'probe' );
	}

	public function get_version(): string {
		return (string) ( $this->config['version'] ?? '1.0.0' );
	}

	public function get_api_version(): int {
		return (int) ( $this->config['api'] ?? ERANKLY_EXTENSION_API_VERSION );
	}

	public function get_priority(): int {
		return (int) ( $this->config['priority'] ?? 10 );
	}

	public function get_topology(): string {
		return (string) ( $this->config['topology'] ?? 'single' );
	}

	public function preflight(): bool|WP_Error {
		if ( ! empty( $this->config['throw'] ) ) {
			throw new RuntimeException( 'preflight exploded' );
		}

		return $this->config['preflight'] ?? true;
	}

	public function is_enabled(): bool {
		return (bool) ( $this->config['enabled'] ?? true );
	}

	public function register_hooks(): void {
		$this->hooks_ran = true;
	}

	public function get_context(): array {
		return (array) ( $this->config['context'] ?? array() );
	}

	public function get_alternates( array $context, bool $navigable ): array {
		return (array) ( $this->config['alternates'] ?? array() );
	}

	public function localize_url( string $url, array $context ): string {
		return $url;
	}
}

final class ERankly_Multilingual_Registry_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		if ( is_multisite() ) {
			delete_site_option( 'erankly_multilingual_provider_id' );
		}
		delete_option( 'erankly_multilingual_provider_id' );
		parent::tear_down();
	}

	public function test_extension_api_version_exposes_the_constant(): void {
		$this->assertSame( ERANKLY_EXTENSION_API_VERSION, erankly_get_extension_api_version() );
	}

	public function test_register_accepts_a_valid_provider_until_two_collide(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();

		$this->assertTrue( $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha' ) ) ) );
		$this->assertFalse( $registry->is_closed() );

		$providers = $registry->providers();
		$this->assertArrayHasKey( 'alpha', $providers );
		$this->assertInstanceOf( ERankly_Test_Multilingual_Provider::class, $providers['alpha'] );

		$duplicate = $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha' ) ) );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'erankly_provider_duplicate_id', $duplicate->get_error_code() );
	}

	public function test_register_rejects_invalid_ids_versions_and_api_versions(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();

		$bad_id = $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'Bad ID' ) ) );
		$this->assertWPError( $bad_id );
		$this->assertSame( 'erankly_provider_invalid_id', $bad_id->get_error_code() );

		$bad_api = $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'beta', 'api' => ERANKLY_EXTENSION_API_VERSION + 5 ) ) );
		$this->assertWPError( $bad_api );
		$this->assertSame( 'erankly_provider_api_mismatch', $bad_api->get_error_code() );

		$bad_version = $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'gamma', 'version' => '   ' ) ) );
		$this->assertWPError( $bad_version );
		$this->assertSame( 'erankly_provider_invalid_version', $bad_version->get_error_code() );
	}

	public function test_close_and_boot_with_no_provider_stays_empty_and_closes(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();

		$this->assertNull( $registry->close_and_boot() );
		$this->assertTrue( $registry->is_closed() );
		$this->assertNull( $registry->selected() );

		// Registration after closure is refused.
		$late = $registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'late' ) ) );
		$this->assertWPError( $late );
		$this->assertSame( 'erankly_provider_registration_closed', $late->get_error_code() );
	}

	public function test_single_provider_boots_and_runs_its_hooks(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();
		$provider = new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha' ) );
		$registry->register( $provider );

		$booted = $registry->close_and_boot();

		$this->assertSame( $provider, $booted );
		$this->assertTrue( $provider->hooks_ran );
		$this->assertSame( $provider, $registry->selected() );
	}

	public function test_two_providers_conflict_and_none_boots(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();
		$registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha' ) ) );
		$registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'beta' ) ) );

		$this->assertNull( $registry->close_and_boot() );

		$diagnostics = $registry->diagnostics();
		$this->assertNotEmpty( $diagnostics );
		$codes = array_column( $diagnostics, 'code' );
		$this->assertContains( 'erankly_provider_conflict', $codes );
	}

	public function test_a_stored_choice_resolves_the_conflict(): void {
		// erankly_get_plugin_option() reads a NETWORK option on Multisite, so the
		// choice must be written to the same storage the registry reads.
		if ( is_multisite() ) {
			update_site_option( 'erankly_multilingual_provider_id', 'beta' );
		} else {
			update_option( 'erankly_multilingual_provider_id', 'beta' );
		}

		$registry = new ERankly_Multilingual_Provider_Registry();
		$registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha' ) ) );
		$beta     = new ERankly_Test_Multilingual_Provider( array( 'id' => 'beta' ) );
		$registry->register( $beta );

		$this->assertSame( $beta, $registry->close_and_boot() );
	}

	public function test_preflight_error_rejects_the_provider(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();
		$registry->register(
			new ERankly_Test_Multilingual_Provider(
				array(
					'id'        => 'alpha',
					'preflight' => new WP_Error( 'probe_preflight', 'not ready' ),
				)
			)
		);

		$this->assertNull( $registry->close_and_boot() );
		$this->assertContains( 'probe_preflight', array_column( $registry->diagnostics(), 'code' ) );
	}

	public function test_preflight_exception_becomes_a_failure_diagnostic(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();
		$registry->register( new ERankly_Test_Multilingual_Provider( array( 'id' => 'alpha', 'throw' => true ) ) );

		$this->assertNull( $registry->close_and_boot() );
		$this->assertContains( 'erankly_provider_preflight_failed', array_column( $registry->diagnostics(), 'code' ) );
	}

	public function test_add_diagnostic_deduplicates_by_code_and_provider(): void {
		$registry = new ERankly_Multilingual_Provider_Registry();

		$registry->add_diagnostic( 'probe_code', 'first', array( 'provider_id' => 'alpha' ) );
		$registry->add_diagnostic( 'probe_code', 'second', array( 'provider_id' => 'alpha' ) );
		$registry->add_diagnostic( 'probe_code', 'third', array( 'provider_id' => 'beta' ) );

		$diagnostics = $registry->diagnostics();
		$this->assertCount( 2, $diagnostics );
		$this->assertSame( 'first', $diagnostics[0]['message'] );
	}

	public function test_singleton_is_closed_before_any_provider_registers(): void {
		// Bootstrap closes the request registry, so no provider is ever selected here.
		$this->assertTrue( ERankly_Multilingual_Provider_Registry::instance()->is_closed() );
		$this->assertNull( erankly_get_multilingual_provider() );
		$this->assertNull( erankly_close_multilingual_provider_registry() );
		$this->assertSame( array(), erankly_get_provider_alternates( true ) );
		$this->assertSame( array(), erankly_get_provider_alternates( false ) );
		$this->assertSame( 'none', erankly_get_hreflang_output_owner() );
		$this->assertIsArray( erankly_get_multilingual_diagnostics() );

		$late = erankly_register_multilingual_provider( new ERankly_Test_Multilingual_Provider() );
		$this->assertWPError( $late );
		$this->assertSame( 'erankly_provider_registration_closed', $late->get_error_code() );
	}

	public function test_add_multilingual_debug_information_reports_no_provider(): void {
		$info = erankly_add_multilingual_debug_information( array() );

		$this->assertArrayHasKey( 'easyrankly_multilingual_bridge', $info );
		$fields = $info['easyrankly_multilingual_bridge']['fields'];
		$this->assertSame( (string) ERANKLY_EXTENSION_API_VERSION, $fields['api']['value'] );
		$this->assertSame( 'none', $fields['provider']['value'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_multilingual_provider_notices_outputs_diagnostics_only_for_admins(): void {
		ERankly_Multilingual_Provider_Registry::instance()->add_diagnostic( 'probe_notice', 'Probe diagnostic message', array( 'provider_id' => 'alpha' ) );

		wp_set_current_user( 0 );
		ob_start();
		erankly_render_multilingual_provider_notices();
		$this->assertSame( '', (string) ob_get_clean() );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		erankly_render_multilingual_provider_notices();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Probe diagnostic message', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}
}
