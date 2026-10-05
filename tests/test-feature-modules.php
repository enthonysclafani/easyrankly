<?php
/** Module activation boundaries and preservation of saved data. */
final class ERankly_Feature_Modules_Test extends WP_UnitTestCase {
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();
		erankly_tests_load_settings_sanitizer();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin_id );
		}
		wp_set_current_user( $this->admin_id );
		erankly_clear_settings_cache();
	}

	public function tear_down(): void {
		if ( is_multisite() ) {
			revoke_super_admin( $this->admin_id );
		}
		wp_set_current_user( 0 );
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	public function test_manager_can_disable_and_restore_seo_and_tools_without_losing_data(): void {
		$settings = erankly_get_settings();
		$settings['website_name'] = 'Preserved SEO identity';
		$settings['twitter_site'] = '@preserved';
		erankly_tests_set_settings( $settings );
		$id = self::factory()->post->create();
		update_post_meta( $id, '_erankly_description', 'Preserved description' );

		foreach ( array( 0, 1 ) as $enabled ) {
			$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/features' );
			$request->set_param( 'settings', array( 'enable_seo' => $enabled, 'enable_tools' => $enabled ) );
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( (bool) $enabled, erankly_seo_enabled() );
			$this->assertSame( (bool) $enabled, erankly_tools_enabled() );
			$this->assertSame( 'Preserved SEO identity', erankly_get_setting( 'website_name' ) );
			$this->assertSame( '@preserved', erankly_get_setting( 'twitter_site' ) );
			$this->assertSame( 'Preserved description', get_post_meta( $id, '_erankly_description', true ) );
			if ( ! $enabled ) {
				$this->assertSame( '', erankly_breadcrumbs() );
				$this->assertSame( '', do_shortcode( '[erankly_breadcrumbs]' ) );
			}
		}
	}

	public function test_disabled_seo_rejects_current_and_legacy_settings_writes(): void {
		erankly_tests_set_settings( array( 'enable_seo' => 0, 'website_name' => 'Keep this identity' ) );
		foreach ( array( 'seo', 'general', 'social', 'schema', 'advanced' ) as $panel ) {
			$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/' . $panel );
			$request->set_param( 'settings', array( 'website_name' => 'Unexpected write' ) );
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'erankly_disabled_settings_module', $response->get_data()['code'] );
		}
		$this->assertSame( 'Keep this identity', erankly_get_setting( 'website_name' ) );
	}

	public function test_disabled_tools_blocks_an_already_loaded_cleanup_handler(): void {
		require_once ERANKLY_PATH . 'includes/class-erankly-database-tools.php';
		erankly_tests_set_settings( array( 'enable_tools' => 0 ) );
		$post = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$result = ERankly_Database_Tools::dispatch( array( 'mode' => 'clean', 'nonce' => wp_create_nonce( 'erankly_database_tools' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}

	public function test_native_object_eligibility_ignores_saved_seo_rules_when_module_is_off(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, '_erankly_index_directive', 'noindex' );
		update_post_meta( $id, '_erankly_canonical', 'https://example.net/elsewhere/' );
		erankly_tests_set_settings( array( 'enable_seo' => 0, 'global_post_type_meta' => array( 'post' => array( 'noindex' => 1 ) ) ) );
		$context = array( 'kind' => 'post', 'object_id' => $id, 'object_subtype' => 'post', 'blog_id' => get_current_blog_id(), 'url' => get_permalink( $id ) );
		$state = erankly_get_object_seo_state( $context );
		$this->assertTrue( $state['indexable'] );
		$this->assertTrue( $state['canonical_is_self'] );
		$this->assertSame( get_permalink( $id ), $state['canonical_url'] );
		update_option( 'blog_public', 0 );
		$this->assertFalse( erankly_get_object_seo_state( $context )['indexable'] );
		update_option( 'blog_public', 1 );
		wp_update_post( array( 'ID' => $id, 'post_password' => 'secret' ) );
		$this->assertFalse( erankly_get_object_seo_state( $context )['public'] );
	}
}
