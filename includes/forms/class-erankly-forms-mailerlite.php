<?php
/** MailerLite subscriber delivery. Credentials and requests stay on the server. */
defined( 'ABSPATH' ) || exit;

final class ERankly_Forms_MailerLite {
	private const API = 'https://connect.mailerlite.com/api/';
	public static function settings( array $input, array $settings ) {
		foreach ( array( 'mailerlite_enabled', 'clear_mailerlite_api_key' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! is_bool( $input[ $key ] ) ) { return new WP_Error( 'invalid_mailerlite_settings', __( 'Please check the MailerLite settings.', 'easyrankly' ), array( 'status' => 400 ) ); }
		}
		if ( isset( $input['mailerlite_enabled'] ) ) { $settings['mailerlite_enabled'] = $input['mailerlite_enabled']; }
		if ( empty( $input['clear_mailerlite_api_key'] ) && array_key_exists( 'mailerlite_api_key', $input ) ) {
			if ( ! is_string( $input['mailerlite_api_key'] ) ) { return new WP_Error( 'invalid_mailerlite_key', __( 'The MailerLite API key is invalid.', 'easyrankly' ), array( 'status' => 400 ) ); }
			$key = trim( $input['mailerlite_api_key'] );
			if ( '' !== $key ) {
				if ( ! preg_match( '/^[a-zA-Z0-9._=\-]{20,2048}$/D', $key ) ) { return new WP_Error( 'invalid_mailerlite_key', __( 'The MailerLite API key is invalid.', 'easyrankly' ), array( 'status' => 400 ) ); }
				// Verify a replacement before saving; a failed connection leaves the old key intact.
				$groups = self::groups( $key );
				if ( is_wp_error( $groups ) ) { return $groups; }
				$settings['mailerlite_api_key'] = $key;
			}
		}
		if ( ! empty( $input['clear_mailerlite_api_key'] ) ) { $settings['mailerlite_api_key'] = ''; $settings['mailerlite_enabled'] = false; }
		if ( $settings['mailerlite_enabled'] && '' === $settings['mailerlite_api_key'] ) { return new WP_Error( 'missing_mailerlite_key', __( 'Enter your MailerLite API key before enabling subscriptions.', 'easyrankly' ), array( 'status' => 400 ) ); }
		return $settings;
	}
	/** Group IDs are strings: casting large MailerLite IDs to numbers loses precision. */
	public static function valid_group( $id ): bool { return is_string( $id ) && (bool) preg_match( '/^[1-9][0-9]{0,31}$/D', $id ); }
	public static function groups( ?string $key = null, bool $refresh = false ) {
		$key = $key ?? erankly_forms_settings()['mailerlite_api_key'];
		if ( '' === $key ) { return new WP_Error( 'missing_mailerlite_key', __( 'Connect MailerLite in Forms settings first.', 'easyrankly' ), array( 'status' => 400 ) ); }
		$cache_key = 'erankly_forms_ml_groups_' . wp_hash( $key );
		$cached = get_transient( $cache_key );
		if ( ! $refresh && is_array( $cached ) ) { return $cached; }
		$response = self::request( 'groups?limit=1000&sort=name', 'GET', $key );
		if ( is_wp_error( $response ) ) { return $response; }
		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) { return self::invalid_response(); }
		$groups = array();
		foreach ( $response['data'] as $group ) {
			if ( ! is_array( $group ) || ( ! is_string( $group['id'] ?? null ) && ! is_int( $group['id'] ?? null ) ) || ! self::valid_group( (string) $group['id'] ) || ! isset( $group['name'] ) || ! is_string( $group['name'] ) ) { return self::invalid_response(); }
			$groups[] = array( 'id' => (string) $group['id'], 'name' => ERankly_Forms_Schema::cut( sanitize_text_field( $group['name'] ), 255 ) );
		}
		// Do not silently present an incomplete list if the service paginates it.
		if ( ! empty( $response['links']['next'] ) ) { return new WP_Error( 'mailerlite_groups_incomplete', __( 'MailerLite returned an incomplete group list. Try refreshing the connection.', 'easyrankly' ), array( 'status' => 503 ) ); }
		set_transient( $cache_key, $groups, 10 * MINUTE_IN_SECONDS );
		return $groups;
	}
	/** A newsletter consent field is deliberately separate from the privacy consent. */
	public static function validate_form( array $meta, array $fields ) {
		if ( ! $meta['mailerlite'] ) { return true; }
		$email = $fields[ $meta['mailerlite_email_field'] ] ?? array();
		$consent = $fields[ $meta['mailerlite_consent_field'] ] ?? array();
		$name = $meta['mailerlite_name_field'];
		if ( ! self::valid_group( $meta['mailerlite_group_id'] ) || 'email' !== ( $email['type'] ?? '' ) || empty( $email['required'] ) || 'newsletter_consent' !== ( $consent['type'] ?? '' ) || ( '' !== $name && 'text' !== ( $fields[ $name ]['type'] ?? '' ) ) ) {
			return new WP_Error( 'invalid_mailerlite_form', __( 'Select a MailerLite group, a required email field and a dedicated Newsletter consent field. The optional name must be a text field.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		return true;
	}
	public static function subscribe( array $meta, array $fields, array $values ) {
		if ( ! $meta['mailerlite'] || '1' !== ( $values[ $meta['mailerlite_consent_field'] ] ?? '' ) ) { return false; }
		$valid = self::validate_form( $meta, $fields );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$settings = erankly_forms_settings();
		if ( ! $settings['mailerlite_enabled'] || '' === $settings['mailerlite_api_key'] ) { return new WP_Error( 'mailerlite_disabled', __( 'Newsletter signup is temporarily unavailable.', 'easyrankly' ) ); }
		$email = $values[ $meta['mailerlite_email_field'] ] ?? '';
		if ( ! is_string( $email ) || ! is_email( $email ) ) { return new WP_Error( 'invalid_mailerlite_email', __( 'Please check your email address.', 'easyrankly' ) ); }
		$payload = array( 'email' => sanitize_email( $email ), 'groups' => array( $meta['mailerlite_group_id'] ), 'opted_in_at' => gmdate( 'Y-m-d H:i:s' ), 'resubscribe' => false );
		$name = $values[ $meta['mailerlite_name_field'] ] ?? '';
		if ( is_string( $name ) && '' !== $name ) { $payload['fields'] = array( 'name' => ERankly_Forms_Schema::cut( sanitize_text_field( $name ), 200 ) ); }
		// Omitting status respects MailerLite's API double opt-in and existing unsubscribes.
		$response = self::request( 'subscribers', 'POST', $settings['mailerlite_api_key'], $payload );
		if ( is_wp_error( $response ) ) { return $response; }
		$id = $response['data']['id'] ?? null;
		return ( is_string( $id ) || is_int( $id ) ) && self::valid_group( (string) $id ) ? true : self::invalid_response();
	}
	private static function invalid_response(): WP_Error { return new WP_Error( 'mailerlite_invalid_response', __( 'MailerLite returned an unexpected response. Try again later.', 'easyrankly' ), array( 'status' => 503 ) ); }
	private static function request( string $path, string $method, string $key, array $payload = array() ) {
		$backoff = 'erankly_forms_ml_backoff_' . wp_hash( $key );
		if ( get_transient( $backoff ) ) { return new WP_Error( 'mailerlite_rate_limited', __( 'MailerLite is busy. Try again later.', 'easyrankly' ), array( 'status' => 429 ) ); }
		$args = array( 'method' => $method, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Version' => '2026-10-02' ), 'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048576 );
		if ( 'POST' === $method ) { $args['body'] = wp_json_encode( $payload ); }
		$response = wp_safe_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) { return new WP_Error( 'mailerlite_connection_failed', __( 'Could not connect to MailerLite. Try again later.', 'easyrankly' ), array( 'status' => 503 ) ); }
		$status = wp_remote_retrieve_response_code( $response );
		if ( in_array( $status, array( 401, 403 ), true ) ) { return new WP_Error( 'mailerlite_unauthorized', __( 'MailerLite rejected the API key. Check the key and account access.', 'easyrankly' ), array( 'status' => 400 ) ); }
		if ( 429 === $status ) {
			set_transient( $backoff, 1, min( 3600, max( 60, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) ) );
			return new WP_Error( 'mailerlite_rate_limited', __( 'MailerLite is busy. Try again later.', 'easyrankly' ), array( 'status' => 429 ) );
		}
		if ( ! in_array( $status, array( 200, 201 ), true ) ) { return new WP_Error( 'mailerlite_request_failed', __( 'MailerLite could not accept the request. Check the group and mapped fields.', 'easyrankly' ), array( 'status' => 503 ) ); }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : self::invalid_response();
	}
}
