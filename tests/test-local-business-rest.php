<?php
/** REST LocalBusiness site and page pickers: capability, ID validation, bounded pages. */

final class ERankly_Local_Business_Rest_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function as_settings_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public function test_local_business_sites_route_rejects_unauthenticated_users(): void {
		$request = new WP_REST_Request( 'GET', '/erankly/v1/local-business/sites' );
		$anon    = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $anon->get_status() );
	}

	public function test_local_business_sites_route_rejects_editors(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request  = new WP_REST_Request( 'GET', '/erankly/v1/local-business/sites' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_local_business_sites_route_returns_a_bounded_page(): void {
		$this->as_settings_admin();

		$request  = new WP_REST_Request( 'GET', '/erankly/v1/local-business/sites' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data['sites'] ?? null );
		$this->assertLessThanOrEqual( ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT, count( $data['sites'] ) );
		$this->assertArrayHasKey( 'hasMore', $data );
		foreach ( $data['sites'] as $site ) {
			$this->assertLessThanOrEqual( ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT + 1, count( $site['pages'] ?? array() ) );
		}
	}

	public function test_local_business_pages_route_rejects_unknown_blog_ids(): void {
		$this->as_settings_admin();

		$request = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$request->set_param( 'blog_id', 999999 );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_local_business_pages_route_returns_published_pages_for_the_current_site(): void {
		$this->as_settings_admin();
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Sede',
			)
		);

		$request = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$request->set_param( 'blog_id', get_current_blog_id() );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $data['pages'] ?? array(), 'id' );
		$this->assertContains( $page_id, $ids );
		$this->assertLessThanOrEqual( ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT, count( $ids ) );
	}

	public function test_local_business_pages_route_paginates_and_searches_beyond_the_first_batch(): void {
		$this->as_settings_admin();
		$ids = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 0 === $i % 2 ? 'Sede duplicata' : 'Sede unica ' . $i,
					'post_name'   => 'sede-' . $i,
					'menu_order'  => $i,
				)
			);
		}

		$request = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$request->set_param( 'blog_id', get_current_blog_id() );
		$first = rest_get_server()->dispatch( $request );
		$data  = $first->get_data();

		$this->assertSame( 200, $first->get_status() );
		$this->assertTrue( (bool) ( $data['hasMore'] ?? false ) );
		$first_ids = wp_list_pluck( $data['pages'] ?? array(), 'id' );
		$this->assertLessThanOrEqual( ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT + 1, count( $first_ids ) );

		$missing = array_values( array_diff( $ids, $first_ids ) );
		$this->assertNotEmpty( $missing );

		$request->set_param( 'offset', absint( $data['nextOffset'] ?? ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT ) );
		$second      = rest_get_server()->dispatch( $request );
		$second_data = $second->get_data();
		$second_ids  = wp_list_pluck( $second_data['pages'] ?? array(), 'id' );
		$this->assertContains( $missing[0], $second_ids );

		$labels = wp_list_pluck( $data['pages'] ?? array(), 'title' );
		$this->assertContains( 'Sede duplicata', $labels );
		$paths = wp_list_pluck( $data['pages'] ?? array(), 'path' );
		$this->assertNotEmpty( $paths );

		$search = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$search->set_param( 'blog_id', get_current_blog_id() );
		$search->set_param( 'q', 'Sede unica 59' );
		$found = rest_get_server()->dispatch( $search )->get_data();
		$this->assertContains( $ids[59], wp_list_pluck( $found['pages'] ?? array(), 'id' ) );

		$empty = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$empty->set_param( 'blog_id', get_current_blog_id() );
		$empty->set_param( 'q', 'no-such-page-xyz' );
		$none = rest_get_server()->dispatch( $empty )->get_data();
		$this->assertSame( array(), array_values( array_intersect( $ids, wp_list_pluck( $none['pages'] ?? array(), 'id' ) ) ) );
	}

	public function test_local_business_pages_route_keeps_the_peeked_selected_page(): void {
		$this->as_settings_admin();
		erankly_load_default_helpers();
		erankly_tests_load_settings_sanitizer();

		for ( $i = 0; $i < 60; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Sede peek ' . $i,
					'post_name'   => 'sede-rest-peek-' . $i,
					'menu_order'  => $i,
				)
			);
		}

		$first = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$first->set_param( 'blog_id', get_current_blog_id() );
		$first_data = rest_get_server()->dispatch( $first )->get_data();
		$next       = absint( $first_data['nextOffset'] ?? ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT );

		$second = new WP_REST_Request( 'GET', '/erankly/v1/local-business/pages' );
		$second->set_param( 'blog_id', get_current_blog_id() );
		$second->set_param( 'offset', $next );
		$peeked_id = absint( ( rest_get_server()->dispatch( $second )->get_data()['pages'][0]['id'] ?? 0 ) );
		$this->assertGreaterThan( 0, $peeked_id );

		$stored                         = erankly_get_settings();
		$stored['local_business_pages'] = array( get_current_blog_id() => $peeked_id );
		erankly_update_plugin_settings( $stored, '', true );
		erankly_clear_settings_cache();

		$included = rest_get_server()->dispatch( $first )->get_data();
		$this->assertContains( $peeked_id, wp_list_pluck( $included['pages'] ?? array(), 'id' ) );
		$this->assertSame( $next, absint( $included['nextOffset'] ?? 0 ) );
	}
}
