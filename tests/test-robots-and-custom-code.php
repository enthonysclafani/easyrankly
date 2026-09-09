<?php
/** Robots and custom-code sanitization regressions. */

final class ERankly_Robots_And_Custom_Code_Test extends WP_UnitTestCase {
	private const OUTPUT_PROBE = '<!-- erankly-excluded-output-probe -->';

	/** @var int[] */
	private $granted_super_admin_ids = array();

	public function set_up(): void {
		parent::set_up();
		erankly_tests_load_settings_sanitizer();
	}

	public function tear_down(): void {
		foreach ( $this->granted_super_admin_ids as $user_id ) {
			revoke_super_admin( $user_id );
		}
		$this->granted_super_admin_ids = array();
		erankly_clear_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_sanitize_settings_tests_load_admin_sanitizer_explicitly(): void {
		$this->assertTrue( function_exists( 'erankly_sanitize_settings' ) );
		$this->assertFalse( is_admin() );
		$this->assertStringContainsString(
			'admin/settings-page.php',
			(string) ( ( new ReflectionFunction( 'erankly_sanitize_settings' ) )->getFileName() )
		);
	}

	public function test_indexifembedded_is_only_emitted_with_noindex(): void {
		$with_noindex = erankly_apply_global_entity_robot_row(
			array( 'noindex' => true ),
			array( 'indexifembedded' => 1 )
		);
		$without_noindex = erankly_apply_global_entity_robot_row(
			array( 'nosnippet' => true ),
			array( 'indexifembedded' => 1 )
		);

		$this->assertTrue( $with_noindex['indexifembedded'] );
		$this->assertArrayNotHasKey( 'indexifembedded', $without_noindex );
	}

	public function test_custom_code_byte_truncation_keeps_valid_utf8(): void {
		$limit = erankly_custom_code_max_bytes();
		$value = str_repeat( 'a', $limit - 1 ) . "\xE2\x82\xACtail";
		$clean = erankly_truncate_custom_code_bytes( $value, $limit );

		$this->assertLessThanOrEqual( $limit, strlen( $clean ) );
		$this->assertSame( 1, preg_match( '//u', $clean ) );
		$this->assertSame( $limit - 1, strlen( $clean ) );
	}

	public function test_custom_code_blocks_have_a_combined_location_budget(): void {
		$limit  = erankly_custom_code_max_total_bytes();
		$blocks = erankly_sanitize_custom_code_blocks(
			array(
				array( 'enabled' => 1, 'code' => str_repeat( 'a', $limit ) ),
				array( 'enabled' => 1, 'code' => 'second block' ),
			)
		);

		$this->assertCount( 1, $blocks );
		$this->assertSame( $limit, strlen( (string) $blocks[0]['code'] ) );
	}

	public function test_one_marked_legacy_block_can_survive_the_ten_block_ui_limit(): void {
		$input = array_fill(
			0,
			erankly_custom_code_max_blocks(),
			array(
				'enabled'         => 1,
				'code'            => '<meta name="probe" content="1">',
				'target_contexts' => array( 'singular' ),
			)
		);
		$legacy_block = erankly_custom_code_migrated_block( '<meta name="legacy" content="1">' );
		$input[]      = $legacy_block;

		$blocks = erankly_sanitize_custom_code_blocks( $input, array( $legacy_block ) );

		$this->assertCount( erankly_custom_code_max_blocks() + 1, $blocks );
		$this->assertSame( 1, $blocks[ erankly_custom_code_max_blocks() ]['legacy_migrated'] );
	}

	public function test_client_cannot_grant_legacy_overflow(): void {
		$regular = array(
			'enabled'         => 1,
			'code'            => '<meta name="regular" content="1">',
			'target_contexts' => array( 'singular' ),
		);
		$forged = array(
			'enabled'           => 1,
			'legacy_migrated'   => 1,
			'code'              => '<meta name="forged-' . wp_generate_uuid4() . '" content="1">',
			'target_contexts'   => array( 'singular' ),
		);
		$input = array_fill( 0, erankly_custom_code_max_blocks(), $regular );
		$input[0] = $forged;
		$input[]  = $regular;

		erankly_clear_settings_cache();
		$blocks = erankly_sanitize_custom_code_blocks_field( $input, 'head_code_blocks' );
		erankly_clear_settings_cache();

		$this->assertCount( erankly_custom_code_max_blocks(), $blocks );
		$this->assertSame( 0, $blocks[0]['legacy_migrated'] );
	}

	public function test_submitted_legacy_scalar_cannot_create_an_overflow_block(): void {
		$stored                           = erankly_get_settings();
		$stored['head_code']              = '';
		$stored['head_code_blocks']       = array();
		$stored['body_open_code']         = '';
		$stored['body_open_code_blocks']  = array();
		$stored['body_close_code']        = '';
		$stored['body_close_code_blocks'] = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$regular = array(
			'enabled'         => 1,
			'code'            => '<meta name="regular" content="1">',
			'target_contexts' => array( 'singular' ),
		);
		$input = array(
			'erankly_settings_panel' => 'custom-code',
			'head_code_blocks'       => array_fill( 0, erankly_custom_code_max_blocks(), $regular ),
			'body_open_code_blocks'  => array(),
			'body_close_code_blocks' => array(),
			'head_code'              => str_repeat( 'L', erankly_custom_code_max_bytes() ),
		);

		$settings = erankly_sanitize_settings( $input );
		$blocks   = $settings['head_code_blocks'];

		$this->assertCount( erankly_custom_code_max_blocks(), $blocks );
		$this->assertSame( '', $settings['head_code'] );
		$this->assertSame( 0, array_sum( array_column( $blocks, 'legacy_migrated' ) ) );
		$this->assertLessThanOrEqual(
			erankly_custom_code_max_total_bytes(),
			array_sum( array_map( static fn( array $block ): int => strlen( (string) $block['code'] ), $blocks ) )
		);
	}

	public function test_persisted_legacy_scalar_is_migrated(): void {
		$legacy_code                = '<meta name="persisted-legacy" content="1">';
		$stored                     = erankly_get_settings();
		$stored['head_code']        = $legacy_code;
		$stored['head_code_blocks'] = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$settings = erankly_sanitize_settings( $stored );
		$blocks   = $settings['head_code_blocks'];

		$this->assertCount( 1, $blocks );
		$this->assertSame( '', $settings['head_code'] );
		$this->assertSame( 1, $blocks[0]['legacy_migrated'] );
		$this->assertSame( $legacy_code, $blocks[0]['code'] );
	}

	public function test_explicitly_trusted_import_legacy_scalar_is_migrated(): void {
		$legacy_code = '<meta name="imported-legacy" content="1">';
		$input       = erankly_get_settings();
		$input['head_code']        = $legacy_code;
		$input['head_code_blocks'] = array();

		$settings = erankly_sanitize_settings( $input, $input );
		$blocks   = $settings['head_code_blocks'];

		$this->assertCount( 1, $blocks );
		$this->assertSame( '', $settings['head_code'] );
		$this->assertSame( 1, $blocks[0]['legacy_migrated'] );
		$this->assertSame( $legacy_code, $blocks[0]['code'] );
	}

	public function test_explicitly_trusted_import_preserves_legacy_overflow_block(): void {
		$regular = array(
			'enabled'         => 1,
			'code'            => '<meta name="regular" content="1">',
			'target_contexts' => array( 'singular' ),
		);
		$legacy                      = erankly_custom_code_migrated_block( '<meta name="imported-overflow" content="1">' );
		$input                       = erankly_get_settings();
		$input['head_code']          = '';
		$input['head_code_blocks']   = array_fill( 0, erankly_custom_code_max_blocks(), $regular );
		$input['head_code_blocks'][] = $legacy;

		$settings = erankly_sanitize_settings( $input, $input );
		$blocks   = $settings['head_code_blocks'];

		$this->assertCount( erankly_custom_code_max_blocks() + 1, $blocks );
		$this->assertSame( 1, array_sum( array_column( $blocks, 'legacy_migrated' ) ) );
		$this->assertSame( $legacy['code'], $blocks[ erankly_custom_code_max_blocks() ]['code'] );
	}

	public function test_verified_legacy_block_cannot_lend_its_extra_slot(): void {
		$regular = array(
			'enabled'         => 1,
			'code'            => '<meta name="regular" content="1">',
			'target_contexts' => array( 'singular' ),
		);
		$legacy  = erankly_custom_code_migrated_block( '<meta name="legacy" content="1">' );
		$input   = array_fill( 0, erankly_custom_code_max_blocks() + 1, $regular );
		$input[] = $legacy;

		$blocks = erankly_sanitize_custom_code_blocks( $input, array( $legacy ) );

		$this->assertCount( erankly_custom_code_max_blocks() + 1, $blocks );
		$this->assertSame( 1, array_sum( array_column( $blocks, 'legacy_migrated' ) ) );
		$this->assertSame( $legacy['code'], $blocks[ erankly_custom_code_max_blocks() ]['code'] );
	}

	public function test_verified_legacy_block_has_a_separate_byte_budget(): void {
		$regular = array(
			'enabled'         => 1,
			'code'            => str_repeat( 'R', erankly_custom_code_max_bytes() ),
			'target_contexts' => array( 'singular' ),
		);
		$legacy = erankly_custom_code_migrated_block( str_repeat( 'L', erankly_custom_code_max_bytes() ) );

		$blocks = erankly_sanitize_custom_code_blocks( array( $regular, $regular, $legacy ), array( $legacy ) );

		$this->assertCount( 2, $blocks );
		$this->assertSame( 1, array_sum( array_column( $blocks, 'legacy_migrated' ) ) );
		$this->assertSame( erankly_custom_code_max_bytes() * 2, array_sum( array_map( static fn( array $block ): int => strlen( (string) $block['code'] ), $blocks ) ) );
	}

	public function test_custom_code_is_disabled_by_default(): void {
		$this->assertSame( 0, (int) erankly_default_settings()['enable_custom_code'] );
		$this->assertFalse( erankly_custom_code_enabled() );
	}

	public function test_user_without_unfiltered_html_cannot_enable_or_save_custom_code(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->set_current_user_without_unfiltered_html( $admin_id );

		$probe = '<script>window.ERANKLY_PROBE=1</script>';
		$input = erankly_get_settings();
		$input['enable_custom_code'] = 1;
		$input['head_code_blocks']   = array(
			array(
				'enabled'         => 1,
				'code'            => $probe,
				'target_contexts' => array( 'front_page' ),
			),
		);

		$settings = erankly_sanitize_settings( $input );

		$this->assertSame( 0, (int) $settings['enable_custom_code'] );
		$this->assertSame( array(), $settings['head_code_blocks'] );
		$this->assertStringNotContainsString( $probe, wp_json_encode( $settings ) );
	}

	public function test_user_without_unfiltered_html_cannot_replace_or_disable_stored_custom_code(): void {
		$privileged_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $privileged_id );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$stored_block = array(
			'enabled'         => 1,
			'code'            => '<meta name="erankly-kept" content="1">',
			'target_contexts' => array( 'front_page' ),
		);
		$stored                              = erankly_get_settings();
		$stored['enable_custom_code']        = 1;
		$stored['head_code_blocks']          = array( $stored_block );
		$stored['head_code']                 = '';
		$stored['body_open_code']            = '';
		$stored['body_close_code']           = '';
		$stored['body_open_code_blocks']     = array();
		$stored['body_close_code_blocks']    = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->set_current_user_without_unfiltered_html( $privileged_id );

		$input                       = erankly_get_settings();
		$input['enable_custom_code'] = 0;
		$input['head_code_blocks']   = array(
			array(
				'enabled'         => 1,
				'code'            => '<script>alert(1)</script>',
				'target_contexts' => array( 'front_page' ),
			),
		);

		$settings = erankly_sanitize_settings( $input );

		$this->assertSame( 1, (int) $settings['enable_custom_code'] );
		$this->assertCount( 1, $settings['head_code_blocks'] );
		$this->assertSame( $stored_block['code'], $settings['head_code_blocks'][0]['code'] );
	}

	public function test_custom_code_should_output_only_on_public_frontend_html(): void {
		require_once ERANKLY_PATH . 'includes/custom-code.php';
		$this->store_custom_code_output_probes();

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( erankly_custom_code_should_output() );

		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();

		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
		unset( $GLOBALS['current_screen'] );
	}

	public function test_custom_code_output_excludes_ajax_cron_robots_preview_embed_and_trackback(): void {
		require_once ERANKLY_PATH . 'includes/custom-code.php';
		$this->store_custom_code_output_probes();

		$this->go_to( home_url( '/' ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->go_to( home_url( '/robots.txt' ) );
		$this->assertTrue( is_robots() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();

		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $author_id );
		$post_id   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);

		$this->go_to( get_preview_post_link( $post_id ) );
		$this->assertTrue( is_preview() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();

		$this->go_to( get_post_embed_url( $post_id ) );
		$this->assertTrue( is_embed() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();

		$this->go_to( get_trackback_url( $post_id ) );
		$this->assertTrue( is_trackback() );
		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_code_output_excludes_rest_request_constant(): void {
		define( 'REST_REQUEST', true );
		require_once ERANKLY_PATH . 'includes/custom-code.php';
		$this->store_custom_code_output_probes();

		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_code_output_excludes_xmlrpc_request_constant(): void {
		define( 'XMLRPC_REQUEST', true );
		require_once ERANKLY_PATH . 'includes/custom-code.php';
		$this->store_custom_code_output_probes();

		$this->assertFalse( erankly_custom_code_should_output() );
		$this->assert_custom_code_probe_not_printed();
	}

	public function test_custom_code_prints_once_on_public_frontend_when_enabled(): void {
		require_once ERANKLY_PATH . 'includes/custom-code.php';

		$probe = '<!-- erankly-output-probe-' . wp_generate_uuid4() . ' -->';
		$stored                              = erankly_get_settings();
		$stored['enable_custom_code']        = 1;
		$stored['head_code']                = '';
		$stored['head_code_blocks']          = array(
			array(
				'enabled'         => 1,
				'code'            => $probe,
				'target_contexts' => array( 'front_page' ),
			),
		);
		$stored['body_open_code_blocks']     = array();
		$stored['body_close_code_blocks']    = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_front_page() );
		$this->assertTrue( erankly_custom_code_should_output() );

		ob_start();
		erankly_render_custom_head_code();
		$first = (string) ob_get_clean();
		$this->assertStringContainsString( $probe, $first );
		$this->assertSame( 1, substr_count( $first, $probe ) );

		ob_start();
		erankly_render_custom_head_code();
		$second = (string) ob_get_clean();
		$this->assertSame( '', $second );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_super_admin_can_save_custom_code(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		$super_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super_id );
		$this->granted_super_admin_ids[] = $super_id;
		wp_set_current_user( $super_id );
		$this->assertTrue( is_super_admin( $super_id ) );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		$probe = '<meta name="erankly-ms-super" content="1">';
		$input                       = erankly_get_settings();
		$input['enable_custom_code'] = 1;
		$input['head_code_blocks']   = array(
			array(
				'enabled'         => 1,
				'code'            => $probe,
				'target_contexts' => array( 'front_page' ),
			),
		);

		$settings = erankly_sanitize_settings( $input );

		$this->assertSame( 1, (int) $settings['enable_custom_code'] );
		$this->assertSame( $probe, $settings['head_code_blocks'][0]['code'] );
	}

	private function assert_custom_code_probe_not_printed(): void {
		ob_start();
		erankly_render_custom_head_code();
		erankly_render_custom_body_open_code();
		erankly_render_custom_body_close_code();
		$output = (string) ob_get_clean();
		$this->assertStringNotContainsString( self::OUTPUT_PROBE, $output );
		$this->assertStringNotContainsString( 'EasyRankly head code', $output );
		$this->assertStringNotContainsString( 'EasyRankly body open code', $output );
		$this->assertStringNotContainsString( 'EasyRankly body close code', $output );
	}

	private function store_custom_code_output_probes(): void {
		$stored                           = erankly_get_settings();
		$stored['enable_custom_code']     = 1;
		$stored['head_code']              = self::OUTPUT_PROBE;
		$stored['body_open_code']         = self::OUTPUT_PROBE;
		$stored['body_close_code']        = self::OUTPUT_PROBE;
		$stored['head_code_blocks']       = array();
		$stored['body_open_code_blocks']  = array();
		$stored['body_close_code_blocks'] = array();
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();
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

	/**
	 * @group ms-required
	 */
	public function test_multisite_site_admin_without_unfiltered_html_cannot_save_custom_code(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		$site_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( is_super_admin( $site_admin_id ) );
		wp_set_current_user( $site_admin_id );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$input                       = erankly_get_settings();
		$input['enable_custom_code'] = 1;
		$input['head_code_blocks']   = array(
			array(
				'enabled'         => 1,
				'code'            => '<meta name="erankly-ms" content="1">',
				'target_contexts' => array( 'front_page' ),
			),
		);

		$settings = erankly_sanitize_settings( $input );

		$this->assertSame( 0, (int) $settings['enable_custom_code'] );
		$this->assertSame( array(), $settings['head_code_blocks'] );
	}
}
