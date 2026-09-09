<?php
/** REST user search: authorization, limits, site scope, and email minimization. */

final class ERankly_Rest_User_Search_Test extends WP_UnitTestCase {
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_user_search_rejects_unauthenticated_and_low_privilege_users(): void {
		$request = new WP_REST_Request( 'GET', '/erankly/v1/users/search' );
		$request->set_param( 'q', 'admin' );

		$anon = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $anon->get_status() );

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$editor = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $editor->get_status() );
	}

	public function test_user_search_response_omits_email_and_keeps_id_meta(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$unique   = 'erankly-rest-' . wp_generate_uuid4();
		$email    = $unique . '@example.com';
		$target_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => $unique,
				'user_email'   => $email,
				'display_name' => 'Rest Search Target',
			)
		);

		$request = new WP_REST_Request( 'GET', '/erankly/v1/users/search' );
		$request->set_param( 'q', 'Rest Search Target' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );

		$match = null;
		foreach ( $data as $row ) {
			if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) === (int) $target_id ) {
				$match = $row;
				break;
			}
		}

		$this->assertIsArray( $match );
		$this->assertArrayNotHasKey( 'email', $match );
		$this->assertArrayNotHasKey( 'user_email', $match );
		$this->assertSame( sprintf( 'ID %d', $target_id ), $match['meta'] );
		$this->assertSame( 'Rest Search Target', $match['name'] );
		$this->assertStringNotContainsString( $email, wp_json_encode( $data ) );
		$this->assertNotEmpty( $match['avatar'] );
	}

	public function test_user_search_can_match_email_without_returning_it(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$unique    = 'erankly-mail-' . wp_generate_uuid4();
		$email     = $unique . '@example.com';
		$target_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'user_login'   => $unique,
				'user_email'   => $email,
				'display_name' => 'Email Hidden User',
			)
		);

		$request = new WP_REST_Request( 'GET', '/erankly/v1/users/search' );
		$request->set_param( 'q', $email );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$ids      = array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), is_array( $data ) ? $data : array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( (int) $target_id, $ids );
		$this->assertStringNotContainsString( $email, wp_json_encode( $data ) );
	}

	public function test_user_search_caps_results_at_twenty(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$prefix     = 'eranklycap' . wp_generate_password( 12, false, false );
		$created_ids = array();
		for ( $i = 0; $i < 21; $i++ ) {
			$created_ids[] = (int) self::factory()->user->create(
				array(
					'role'         => 'author',
					'user_login'   => $prefix . sprintf( '%02d', $i ),
					'user_email'   => $prefix . sprintf( '%02d', $i ) . '@example.com',
					'display_name' => $prefix . sprintf( '%02d', $i ),
				)
			);
		}

		$request = new WP_REST_Request( 'GET', '/erankly/v1/users/search' );
		$request->set_param( 'q', $prefix );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertCount( 20, $data, 'The endpoint must return the cap of 20 when more than 20 users match.' );

		$returned_ids = array();
		foreach ( $data as $row ) {
			$this->assertIsArray( $row );
			$this->assertArrayNotHasKey( 'email', $row );
			$this->assertArrayNotHasKey( 'user_email', $row );
			$id = (int) ( $row['id'] ?? 0 );
			$this->assertContains( $id, $created_ids );
			$returned_ids[] = $id;
		}

		$this->assertCount( 20, array_unique( $returned_ids ) );
		$this->assertSame( 21, count( $created_ids ) );
		$this->assertStringNotContainsString( '@example.com', (string) wp_json_encode( $data ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_site_admin_user_search_stays_on_current_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires Multisite.' );
		}

		$site_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( is_super_admin( $site_admin_id ) );

		$foreign_id = self::factory()->user->create(
			array(
				'user_login'   => 'erankly-foreign-' . wp_generate_uuid4(),
				'display_name' => 'Foreign Network Only',
			)
		);
		remove_user_from_blog( $foreign_id, get_current_blog_id() );

		wp_set_current_user( $site_admin_id );
		$request = new WP_REST_Request( 'GET', '/erankly/v1/users/search' );
		$request->set_param( 'q', 'Foreign Network Only' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$ids      = array_map( static fn( $row ): int => (int) ( $row['id'] ?? 0 ), is_array( $data ) ? $data : array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotContains( (int) $foreign_id, $ids );
	}
}
