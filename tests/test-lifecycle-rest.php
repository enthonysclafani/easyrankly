<?php
/** REST autosave of the per-site "Special pages and archives" panel. */

/**
 * Covers `erankly_rest_save_special_pages()` and the route that exposes it.
 *
 * The callback writes through `erankly_update_special_meta_map()`, which stores the map in the shared settings
 * array on single-site (a dedicated per-site option on Multisite), so the persisted value is read back from
 * ERANKLY_OPTION['global_special_meta'] here.
 *
 * NOTE: the route `/erankly/v1/settings/special-pages` is currently shadowed by the generic panel route
 * `/erankly/v1/settings/(?P<panel>[a-z-]+)` registered earlier by erankly_register_settings_autosave_route().
 * The last test pins that (broken) dispatch behaviour on purpose; see its comment.
 */
final class ERankly_Lifecycle_Rest_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		erankly_clear_settings_cache();
	}

	public function tear_down(): void {
		erankly_clear_settings_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Returns the stored special-page map from the store the runtime writes it to. */
	private function stored_special_meta(): array {
		if ( is_multisite() ) {
			// erankly_update_special_meta_map() writes a dedicated per-site option on
			// Multisite instead of nesting the map in ERANKLY_OPTION.
			$meta = get_option( ERANKLY_SPECIAL_META_OPTION, array() );

			return is_array( $meta ) ? $meta : array();
		}

		$settings = get_option( ERANKLY_OPTION, array() );

		return isset( $settings['global_special_meta'] ) && is_array( $settings['global_special_meta'] )
			? $settings['global_special_meta']
			: array();
	}

	public function test_rest_save_special_pages_persists_the_whitelisted_map(): void {
		$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/special-pages' );
		$request->set_param(
			'settings',
			array(
				'global_special_meta' => array(
					'search' => array(
						'title'   => 'Custom Search',
						'noindex' => true,
					),
				),
			)
		);

		$response = erankly_rest_save_special_pages( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );
		$this->assertSame( array(), $response->get_data()['warnings'] );

		$map = $this->stored_special_meta();

		$this->assertArrayHasKey( 'search', $map );
		$this->assertSame( 'Custom Search', $map['search']['title'] );
		$this->assertTrue( (bool) $map['search']['noindex'] );
	}

	public function test_rest_save_special_pages_ignores_a_non_array_map(): void {
		$request = new WP_REST_Request( 'POST', '/erankly/v1/settings/special-pages' );
		$request->set_param( 'settings', array( 'global_special_meta' => 'not-a-map' ) );

		$response = erankly_rest_save_special_pages( $request );

		$this->assertSame( 200, $response->get_status() );
		// A scalar payload is treated as an empty map rather than being written through.
		$this->assertSame( array(), $this->stored_special_meta() );
	}

	public function test_special_pages_route_is_registered_with_its_own_callback_and_capability(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/erankly/v1/settings/special-pages', $routes );

		$handler = $routes['/erankly/v1/settings/special-pages'][0];

		$this->assertSame( 'erankly_rest_save_special_pages', $handler['callback'] );
		// The handler exposes its accepted methods as a set keyed by method name.
		$this->assertArrayHasKey( 'POST', (array) $handler['methods'] );

		// The permission callback is per-site: manage_options, never manage_network_options.
		wp_set_current_user( 0 );
		$this->assertFalse( (bool) call_user_func( $handler['permission_callback'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( (bool) call_user_func( $handler['permission_callback'] ) );
	}

	/**
	 * KNOWN DEFECT (pinned, not desired): `/erankly/v1/settings/special-pages` never reaches
	 * erankly_rest_save_special_pages().
	 *
	 * erankly_register_settings_autosave_route() registers `/settings/(?P<panel>[a-z-]+)` before the literal
	 * `/settings/special-pages` route, and WP_REST_Server::match_request_to_handler() returns the first pattern
	 * that matches. "special-pages" is not a member of erankly_settings_autosave_panels(), so the admin asset
	 * (admin/assets/settings.php, restUrl = erankly/v1/settings/special-pages) gets a 404 instead of a save.
	 * When the ordering is fixed, this test should assert a 200 and the saved payload instead.
	 */
	public function test_special_pages_endpoint_is_shadowed_by_the_settings_panel_route(): void {
		$path = '/erankly/v1/settings/special-pages';
		$matches = array();

		foreach ( array_keys( rest_get_server()->get_routes() ) as $pattern ) {
			if ( preg_match( '@^' . $pattern . '$@i', $path ) ) {
				$matches[] = $pattern;
			}
		}

		$this->assertSame( '/erankly/v1/settings/(?P<panel>[a-z-]+)', $matches[0] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// The generic panel route requires manage_network_options on Multisite (the settings
		// it edits are network-wide), while this per-site special-pages route requires only
		// manage_options. Grant the network capability so dispatch reaches the shadowing
		// panel handler (404) instead of short-circuiting at 403.
		$grant = null;

		if ( is_multisite() ) {
			$grant = static function ( array $allcaps ): array {
				$allcaps['manage_network_options'] = true;
				return $allcaps;
			};
			add_filter( 'user_has_cap', $grant );
		}

		try {
			$request = new WP_REST_Request( 'POST', $path );
			$request->set_body_params(
				array(
					'settings' => array(
						'global_special_meta' => array( 'search' => array( 'title' => 'Shadowed' ) ),
					),
				)
			);

			$response = rest_get_server()->dispatch( $request );
		} finally {
			if ( null !== $grant ) {
				remove_filter( 'user_has_cap', $grant );
			}
		}

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'erankly_unknown_settings_panel', $response->get_data()['code'] );
		$this->assertSame( array(), $this->stored_special_meta() );
	}
}
