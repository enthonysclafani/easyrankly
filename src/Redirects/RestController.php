<?php
/**
 * REST controller for redirects.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Core posts controller, but nothing is readable without manage_options.
 *
 * The core controller lets anyone read published posts of a REST-enabled type, which
 * would expose every redirect. Writes already require manage_options through the
 * post type capabilities.
 */
final class RestController extends \WP_REST_Posts_Controller {

	/**
	 * Listing requires manage_options.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return self::can_manage() ?? parent::get_items_permissions_check( $request );
	}

	/**
	 * Reading one redirect requires manage_options.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return self::can_manage() ?? parent::get_item_permissions_check( $request );
	}

	/**
	 * Null when the user may go on to the core checks, an error otherwise.
	 *
	 * @return \WP_Error|null
	 */
	private static function can_manage(): ?\WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to manage redirects.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
