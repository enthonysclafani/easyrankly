<?php
/**
 * Tests for the settings option and its REST exposure.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Settings;

use EasyRankly\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `easyrankly_settings` is registered with a schema, sanitized and readable only by administrators.
 */
final class SettingsTest extends WP_UnitTestCase {

	/**
	 * Starts every test from a clean REST server.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Without a stored value the defaults apply.
	 */
	public function test_defaults_apply_without_stored_value(): void {
		delete_option( Settings::OPTION );

		$this->assertSame( '-', Settings::value( 'title_separator' ) );
		$this->assertSame( Settings::defaults(), Settings::get() );
	}

	/**
	 * Unknown keys are dropped and invalid values keep the current value.
	 */
	public function test_sanitize_drops_unknown_and_invalid_values(): void {
		update_option( Settings::OPTION, array( 'title_separator' => '|' ) );

		update_option(
			Settings::OPTION,
			array(
				'title_separator' => array( 'not a string' ),
				'unknown'         => 'x',
			)
		);

		$stored = get_option( Settings::OPTION );
		$this->assertSame( '|', $stored['title_separator'] );
		$this->assertArrayNotHasKey( 'unknown', $stored );
	}

	/**
	 * Text settings lose markup and overlong values are rejected.
	 */
	public function test_sanitize_strips_markup_and_enforces_length(): void {
		update_option( Settings::OPTION, array( 'title_separator' => '<b>·</b>' ) );
		$this->assertSame( '·', Settings::value( 'title_separator' ) );

		update_option( Settings::OPTION, array( 'title_separator' => str_repeat( '-', 11 ) ) );
		$this->assertSame( '·', Settings::value( 'title_separator' ) );
	}

	/**
	 * Administrators read and write the option through /wp/v2/settings.
	 */
	public function test_administrator_saves_through_rest(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'title_separator' => '|' ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '|', $response->get_data()[ Settings::OPTION ]['title_separator'] );
		$this->assertSame( '|', Settings::value( 'title_separator' ) );
	}

	/**
	 * REST rejects values outside the schema instead of storing them.
	 */
	public function test_rest_rejects_values_outside_schema(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'unknown' => 'x' ) ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * Users without manage_options can neither read nor write the settings.
	 */
	public function test_editor_cannot_use_settings_endpoint(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/settings' ) )->get_status() );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'title_separator' => '|' ) ) );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( '-', Settings::value( 'title_separator' ) );
	}
}
