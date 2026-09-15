<?php
/** REST autosave of the per-site "Special pages and archives" panel. */

/**
 * Covers `erankly_rest_save_special_pages()` and the route that exposes it.
 *
 * The callback writes through `erankly_update_special_meta_map()`, which stores the map in the shared settings
 * array on single-site (a dedicated per-site option on Multisite), so the persisted value is read back from
 * ERANKLY_OPTION['global_special_meta'] here.
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

	public function test_special_pages_endpoint_saves_through_the_literal_route(): void {
		$path    = '/erankly/v1/settings/special-pages';
		$matches = array();

		foreach ( array_keys( rest_get_server()->get_routes() ) as $pattern ) {
			if ( preg_match( '@^' . $pattern . '$@i', $path ) ) {
				$matches[] = $pattern;
			}
		}

		$this->assertSame( '/erankly/v1/settings/special-pages', $matches[0] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', $path );
		$request->set_param(
			'settings',
			array(
				'global_special_meta' => array( 'search' => array( 'title' => 'Saved via route' ) ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );
		$this->assertSame( 'Saved via route', $this->stored_special_meta()['search']['title'] );
	}

	/**
	 * Seeds a search/homepage map that includes advanced robots the Site Editor schema used to drop.
	 *
	 * @return array<string,array<string,string|int>>
	 */
	private function seed_special_meta_with_advanced_robots(): array {
		return erankly_update_special_meta_map(
			array(
				'search'   => array(
					'title'             => 'Search SEO',
					'noindex'           => 1,
					'index_directive'   => 'noindex',
					'max_snippet'       => '10',
					'indexifembedded'   => 1,
				),
				'homepage' => array(
					'title'           => 'Home SEO',
					'index_directive' => 'index',
				),
			)
		);
	}

	/** @return array<string,bool> */
	private function special_meta_base_rest_keys(): array {
		return array_fill_keys(
			array(
				'title',
				'description',
				'noindex',
				'nofollow',
				'noarchive',
				'disable_sitemap',
				'og_title',
				'og_description',
				'twitter_title',
				'twitter_description',
				'social_image_url',
				'og_image_id',
			),
			true
		);
	}

	public function test_special_meta_setting_is_not_registered_on_core_or_plugin_options_pages(): void {
		erankly_register_special_meta_setting();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( ERANKLY_SPECIAL_META_OPTION, $registered );
		$this->assertSame( erankly_special_meta_setting_group(), $registered[ ERANKLY_SPECIAL_META_OPTION ]['group'] );
		$this->assertNotSame( 'general', $registered[ ERANKLY_SPECIAL_META_OPTION ]['group'] );
		$this->assertNotSame( 'erankly', $registered[ ERANKLY_SPECIAL_META_OPTION ]['group'] );
	}

	public function test_special_meta_sanitize_ignores_a_null_options_php_payload(): void {
		$this->seed_special_meta_with_advanced_robots();

		$clean = erankly_sanitize_special_meta_map( null );

		$this->assertSame( 'Search SEO', $clean['search']['title'] );
		$this->assertSame( 'noindex', $clean['search']['index_directive'] );
		$this->assertSame( '10', $clean['search']['max_snippet'] );
		$this->assertSame( 'index', $clean['homepage']['index_directive'] );

		erankly_register_special_meta_setting();
		update_option( ERANKLY_SPECIAL_META_OPTION, null );

		$map = $this->stored_special_meta();

		$this->assertSame( 'Search SEO', $map['search']['title'] );
		$this->assertSame( 'noindex', $map['search']['index_directive'] );
	}

	public function test_site_editor_rest_value_exposes_advanced_robots(): void {
		$this->seed_special_meta_with_advanced_robots();

		$value = erankly_get_special_meta_rest_value();

		$this->assertSame( 'Search SEO', $value['search']['title'] );
		$this->assertTrue( $value['search']['noindex'] );
		$this->assertSame( 'noindex', $value['search']['index_directive'] );
		$this->assertSame( '10', $value['search']['max_snippet'] );
		$this->assertTrue( $value['search']['indexifembedded'] );
		$this->assertSame( 'index', $value['homepage']['index_directive'] );
	}

	public function test_site_editor_shaped_save_preserves_index_directive(): void {
		$this->seed_special_meta_with_advanced_robots();

		$stripped = array();
		$base     = $this->special_meta_base_rest_keys();

		foreach ( erankly_get_special_meta_rest_value() as $context => $row ) {
			$stripped[ $context ] = array_intersect_key( $row, $base );
		}

		$stripped['search']['title'] = 'Edited in Site Editor';

		$handled = erankly_rest_pre_update_special_meta_setting(
			false,
			ERANKLY_SPECIAL_META_OPTION,
			$stripped,
			array()
		);

		$this->assertTrue( $handled );

		$map = $this->stored_special_meta();

		$this->assertSame( 'Edited in Site Editor', $map['search']['title'] );
		$this->assertSame( 'noindex', $map['search']['index_directive'] );
		$this->assertSame( '10', $map['search']['max_snippet'] );
		$this->assertSame( 1, (int) $map['search']['indexifembedded'] );
		$this->assertSame( 'index', $map['homepage']['index_directive'] );
		$this->assertSame( 'Home SEO', $map['homepage']['title'] );

		$robots = erankly_apply_global_entity_robot_row(
			array(
				'noindex' => true,
			),
			erankly_get_global_entity_meta_row( 'global_special_meta', 'search' )
		);

		$this->assertSame( '10', $robots['max-snippet'] );
		$this->assertTrue( $robots['indexifembedded'] );
	}

	public function test_wp_v2_settings_round_trips_advanced_robots(): void {
		$this->seed_special_meta_with_advanced_robots();
		erankly_register_special_meta_setting();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		$get = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );

		$this->assertSame( 200, $get->get_status() );
		$this->assertArrayHasKey( ERANKLY_SPECIAL_META_OPTION, $get->get_data() );

		$payload = $get->get_data()[ ERANKLY_SPECIAL_META_OPTION ];

		$this->assertIsArray( $payload );
		$this->assertSame( 'noindex', $payload['search']['index_directive'] );
		$this->assertSame( '10', $payload['search']['max_snippet'] );
		$this->assertTrue( $payload['search']['indexifembedded'] );

		$payload['search']['title'] = 'Saved via wp/v2/settings';

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_param( ERANKLY_SPECIAL_META_OPTION, $payload );

		$save = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $save->get_status() );

		$map = $this->stored_special_meta();

		$this->assertSame( 'Saved via wp/v2/settings', $map['search']['title'] );
		$this->assertSame( 'noindex', $map['search']['index_directive'] );
		$this->assertSame( '10', $map['search']['max_snippet'] );
		$this->assertSame( 1, (int) $map['search']['indexifembedded'] );
		$this->assertSame( 'index', $map['homepage']['index_directive'] );
	}

	public function test_special_meta_rest_schema_declares_advanced_robots(): void {
		$schema = erankly_get_special_meta_rest_schema();

		$this->assertArrayHasKey( 'search', $schema['properties'] );

		$row = $schema['properties']['search']['properties'];

		foreach ( erankly_special_meta_advanced_robot_keys() as $key ) {
			$this->assertArrayHasKey( $key, $row, $key . ' must be in the REST schema so Core Data can round-trip it' );
		}

		$seeded = $this->seed_special_meta_with_advanced_robots();
		$value  = erankly_normalize_special_meta_map( $seeded );
		$valid  = rest_validate_value_from_schema( $value, $schema );

		$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : 'schema rejected a stored map with advanced robots' );
	}
}
