<?php
/** Anonymous JSON submission, with cheap abuse checks before external requests. */
defined( 'ABSPATH' ) || exit;
final class ERankly_Forms_Submission {
	public static function register(): void {
		register_rest_route( 'erankly/v1', '/forms/(?P<id>\d+)/submit', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'submit' ) ) );
	}
	public static function honeypot( int $id ): string { return 'erankly_hp_' . $id; }
	public static function rate_limit(): bool {
		$ip = apply_filters( 'erankly_forms_client_ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$hash = wp_hash( is_string( $ip ) ? $ip : '' );
		$key = 'erankly_form_rl_' . $hash;
		$lock = 'erankly_form_lock_' . $hash;
		// Serialize attempts across concurrent PHP processes; stale locks recover after 10 seconds.
		if ( ! add_option( $lock, time() + 10, '', false ) ) {
			if ( (int) get_option( $lock ) >= time() ) { return false; }
			delete_option( $lock );
			if ( ! add_option( $lock, time() + 10, '', false ) ) { return false; }
		}
		try {
			$bucket = get_transient( $key );
			if ( ! is_array( $bucket ) || (int) $bucket['until'] <= time() ) { $bucket = array( 'count' => 0, 'until' => time() + 600 ); }
			if ( $bucket['count'] >= 5 ) { return false; }
			++$bucket['count'];
			set_transient( $key, $bucket, max( 1, $bucket['until'] - time() ) );
			return true;
		} finally { delete_option( $lock ); }
	}
	public static function source( $source ): string {
		if ( ! is_string( $source ) || strlen( $source ) > 2048 ) { return ''; }
		$url = esc_url_raw( $source, array( 'http', 'https' ) );
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) || wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) ) { return ''; }
		return $url;
	}
	public static function submit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! erankly_forms_enabled() || ! $post || 'erankly_form' !== $post->post_type || 'publish' !== $post->post_status || $post->post_password ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}
		if ( ! $request->is_json_content_type() ) { return new WP_Error( 'json_required', __( 'Please send JSON data.', 'easyrankly' ), array( 'status' => 415 ) ); }
		if ( strlen( $request->get_body() ) > 32768 ) { return new WP_Error( 'payload_too_large', __( 'The submission is too large.', 'easyrankly' ), array( 'status' => 413 ) ); }
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) { return new WP_Error( 'invalid_payload', __( 'Please check the submitted data.', 'easyrankly' ), array( 'status' => 400 ) ); }
		if ( ! self::rate_limit() ) {
			return new WP_REST_Response( array( 'code' => 'rate_limited', 'message' => __( 'Too many attempts. Please try again in ten minutes.', 'easyrankly' ) ), 429, array( 'Retry-After' => '600' ) );
		}
		$meta = erankly_form_settings( $post->ID );
		$success = array( 'success' => true, 'message' => $meta['success_message'] );
		if ( ! empty( $payload[ self::honeypot( $post->ID ) ] ) ) { return new WP_REST_Response( $success ); }
		$fields = ERankly_Forms_Schema::fields( $post->post_content );
		if ( is_wp_error( $fields ) ) { return $fields; }
		$input = $payload['fields'] ?? array();
		if ( ! is_array( $input ) ) { return new WP_Error( 'invalid_payload', __( 'Please check the submitted data.', 'easyrankly' ), array( 'status' => 400 ) ); }
		$result = ERankly_Forms_Schema::validate( $fields, $input );
		if ( $result['errors'] ) { return new WP_REST_Response( array( 'code' => 'invalid_fields', 'message' => __( 'Please check the highlighted fields.', 'easyrankly' ), 'errors' => $result['errors'] ), 422 ); }
		$settings = erankly_forms_settings();
		if ( $settings['turnstile_enabled'] ) {
			$token = $payload['token'] ?? '';
			$verified = ERankly_Forms_Turnstile::verify( is_string( $token ) ? $token : '', $post->ID, $settings );
			if ( is_wp_error( $verified ) ) { return $verified; }
		}
		$source = self::source( $payload['source'] ?? '' );
		$mail = ERankly_Forms_Mailer::send( $post, $fields, $result['values'], $source, $meta );
		if ( is_wp_error( $mail ) ) { erankly_forms_delivery_error( 'email', $mail->get_error_code() ); }
		$slack = false;
		if ( $meta['slack'] && '' !== $settings['slack_webhook_url'] ) {
			$slack = ERankly_Forms_Slack::send( ERankly_Forms_Slack::payload( $post->post_title, $fields, $result['values'], $source ) );
			if ( is_wp_error( $slack ) ) { erankly_forms_delivery_error( 'slack', $slack->get_error_code() ); }
		}
		$mailerlite = ERankly_Forms_MailerLite::subscribe( $meta, $fields, $result['values'] );
		if ( is_wp_error( $mailerlite ) ) {
			erankly_forms_delivery_error( 'mailerlite', $mailerlite->get_error_code() );
			return new WP_Error( 'subscription_failed', __( 'Newsletter signup could not be completed. Please try again later.', 'easyrankly' ), array( 'status' => 503 ) );
		}
		if ( true === $mail || true === $slack || true === $mailerlite ) { return new WP_REST_Response( $success ); }
		return new WP_Error( 'delivery_failed', __( 'Your message could not be delivered. Please try again later.', 'easyrankly' ), array( 'status' => 503 ) );
	}
}
