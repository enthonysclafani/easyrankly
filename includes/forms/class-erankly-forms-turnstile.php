<?php
/** Cloudflare validation always fails closed, including network failures. */
defined( 'ABSPATH' ) || exit;
final class ERankly_Forms_Turnstile {
	public const TEST_SECRETS = array( '1x0000000000000000000000000000000AA', '2x0000000000000000000000000000000AA', '3x0000000000000000000000000000000AA' );
	public static function is_test_secret( string $secret ): bool { return in_array( $secret, self::TEST_SECRETS, true ); }
	public static function local_environment(): bool { return in_array( wp_get_environment_type(), array( 'local', 'development' ), true ); }
	public static function request( string $secret, string $token ) {
		$response = wp_safe_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
			'timeout' => 5, 'redirection' => 0, 'body' => array( 'secret' => $secret, 'response' => $token ),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'turnstile_unavailable', __( 'Verification is unavailable. Please try again.', 'easyrankly' ), array( 'status' => 403 ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'turnstile_unavailable', __( 'Verification is unavailable. Please try again.', 'easyrankly' ), array( 'status' => 403 ) );
	}
	public static function verify( string $token, int $id, array $settings ) {
		if ( '' === $token || strlen( $token ) > 2048 || empty( $settings['turnstile_secret_key'] ) || empty( $settings['turnstile_site_key'] ) ) {
			return self::invalid();
		}
		$testing = self::is_test_secret( $settings['turnstile_secret_key'] );
		if ( $testing && ! self::local_environment() ) { return self::invalid(); }
		$data = self::request( $settings['turnstile_secret_key'], $token );
		if ( is_wp_error( $data ) ) { return $data; }
		// Cloudflare dummy responses do not bind hostname/action. Accept only its explicit test
		// marker, the official dummy token and a known test secret in local/development.
		if ( $testing && 'XXXX.DUMMY.TOKEN.XXXX' === $token && ! empty( $data['metadata']['result_with_testing_key'] ) && true === ( $data['success'] ?? false ) ) { return true; }
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( true !== ( $data['success'] ?? false ) || $host !== strtolower( (string) ( $data['hostname'] ?? '' ) ) || 'erankly_form_' . $id !== ( $data['action'] ?? '' ) ) {
			return self::invalid();
		}
		return true;
	}
	private static function invalid(): WP_Error {
		return new WP_Error( 'turnstile_failed', __( 'Please complete the verification and try again.', 'easyrankly' ), array( 'status' => 403 ) );
	}
	public static function check_secret( string $secret ) {
		$data = self::request( $secret, 'erankly-invalid-test-token' );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( in_array( 'invalid-input-secret', (array) ( $data['error-codes'] ?? array() ), true ) || in_array( 'missing-input-secret', (array) ( $data['error-codes'] ?? array() ), true ) ) {
			return new WP_Error( 'invalid_secret', __( 'The Turnstile secret key is invalid.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		if ( empty( $data['success'] ) && ! array_intersect( array( 'invalid-input-response', 'timeout-or-duplicate' ), (array) ( $data['error-codes'] ?? array() ) ) ) {
			return new WP_Error( 'turnstile_unavailable', __( 'Verification is unavailable. Please try again.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		return true;
	}
}
