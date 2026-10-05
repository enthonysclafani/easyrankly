<?php
/** Keep published form definitions and recipient settings private. */
defined( 'ABSPATH' ) || exit;
class ERankly_Forms_Rest_Controller extends WP_REST_Posts_Controller {
	public function check_read_permission( $post ) {
		return current_user_can( 'edit_post', $post->ID ) && parent::check_read_permission( $post );
	}
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You cannot access these forms.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}
}
