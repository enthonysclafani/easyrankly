<?php
/**
 * REST controller for snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Core posts controller, but no snippet is readable without the rights to edit it.
 *
 * The core controller lets anyone read published posts of a REST-enabled type, which
 * would expose the code of every active snippet.
 */
final class RestController extends \WP_REST_Posts_Controller {

	/**
	 * Listing requires the rights to manage snippets.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return CustomCode::can_manage() ? parent::get_items_permissions_check( $request ) : self::forbidden();
	}

	/**
	 * Reading one snippet requires the rights to edit it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return current_user_can( (string) get_post_type_object( $this->post_type )?->cap->edit_post, (int) $request['id'] ) ? parent::get_item_permissions_check( $request ) : self::forbidden();
	}

	/**
	 * Error for users who may not see snippets.
	 *
	 * @return \WP_Error
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to manage custom code.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
