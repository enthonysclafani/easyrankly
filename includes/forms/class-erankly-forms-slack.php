<?php
/** Slack transport accepts only the canonical incoming webhook host. */
defined( 'ABSPATH' ) || exit;
final class ERankly_Forms_Slack {
	public static function valid_url( string $url ): bool {
		return (bool) preg_match( '~^https://hooks\.slack\.com/services/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+$~D', $url );
	}
	public static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
	public static function text( string $text, int $limit, string $suffix = '…' ): string {
		$text = self::escape( $text );
		if ( ERankly_Forms_Schema::length( $text ) <= $limit ) { return $text; }
		$text = ERankly_Forms_Schema::cut( $text, $limit - ERankly_Forms_Schema::length( $suffix ) );
		// Never cut an escaped entity halfway and accidentally restore Slack markup.
		$text = preg_replace( '/&[^;]*$/', '', $text );
		return $text . $suffix;
	}
	public static function payload( string $title, array $fields, array $values, string $source ): array {
		$blocks = array( array( 'type' => 'header', 'text' => array( 'type' => 'plain_text', 'text' => self::text( $title ?: __( 'Form', 'easyrankly' ), 150 ) ) ) );
		$fallback = self::text( $title, 150 );
		foreach ( array_slice( $fields, 0, 30, true ) as $name => $field ) {
			$value = $values[ $name ] ?? '';
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$text = self::text( $field['label'], 200 ) . "\n" . self::text( $value, 2700, __( '… (full text in email)', 'easyrankly' ) );
			$blocks[] = array( 'type' => 'section', 'text' => array( 'type' => 'mrkdwn', 'text' => $text, 'verbatim' => true ) );
			$fallback .= "\n" . $text;
		}
		$blocks[] = array( 'type' => 'context', 'elements' => array( array( 'type' => 'plain_text', 'text' => self::text( $source . ' · ' . current_time( 'mysql' ), 2000 ) ) ) );
		return array( 'text' => self::text( html_entity_decode( $fallback, ENT_QUOTES, 'UTF-8' ), 3900 ), 'blocks' => $blocks, 'unfurl_links' => false, 'unfurl_media' => false );
	}
	public static function send( array $payload ) {
		$url = erankly_forms_settings()['slack_webhook_url'];
		if ( ! self::valid_url( $url ) ) { return new WP_Error( 'slack_not_configured', __( 'Configure a Slack webhook first.', 'easyrankly' ) ); }
		if ( get_transient( 'erankly_forms_slack_throttle' ) ) { return new WP_Error( 'slack_rate_limited', __( 'Slack is busy. Please try again.', 'easyrankly' ) ); }
		set_transient( 'erankly_forms_slack_throttle', 1, 1 );
		$response = wp_safe_remote_post( $url, array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || 'ok' !== trim( wp_remote_retrieve_body( $response ) ) ) {
			return new WP_Error( 'slack_failed', __( 'Slack delivery failed.', 'easyrankly' ) );
		}
		return true;
	}
}
