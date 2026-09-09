<?php
/** Robots and custom-code sanitization regressions. */

final class ERankly_Robots_And_Custom_Code_Test extends WP_UnitTestCase {
	public function tear_down(): void {
		erankly_clear_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
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
}
