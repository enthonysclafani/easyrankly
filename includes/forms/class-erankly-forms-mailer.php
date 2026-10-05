<?php
/** Plain-text mail with an optional SMTP transport scoped to form delivery. */
defined( 'ABSPATH' ) || exit;
final class ERankly_Forms_Mailer {
	public static function send( WP_Post $post, array $fields, array $values, string $source, array $settings ) {
		$recipients = $settings['recipients'] ?: array( get_option( 'admin_email' ) );
		$subject = $settings['subject'] ?: sprintf( __( 'Form: %s', 'easyrankly' ), $post->post_title );
		$subject = preg_replace_callback( '/\{([a-z0-9_-]+)\}/', static function ( $m ) use ( $values ) { return is_array( $values[ $m[1] ] ?? '' ) ? implode( ', ', $values[ $m[1] ] ) : (string) ( $values[ $m[1] ] ?? '' ); }, $subject );
		$subject = ERankly_Forms_Schema::cut( str_replace( array( "\r", "\n" ), ' ', $subject ), 500 );
		$body = wp_strip_all_tags( $post->post_title ) . "\n\n";
		$email = $name = '';
		foreach ( $fields as $key => $field ) {
			$value = $values[ $key ];
			$body .= $field['label'] . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value ) . "\n\n";
			if ( 'email' === $field['type'] && ! $email && is_email( $value ) ) { $email = $value; }
			if ( 'name' === $field['autocomplete'] && ! is_array( $value ) ) { $name = $value; }
		}
		$body .= $source . "\n" . current_time( 'mysql' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $email ) {
			$name = preg_replace( '/[\r\n<>"\\\\]/', '', $name );
			$headers[] = 'Reply-To: ' . ( $name ? $name . ' <' . $email . '>' : $email );
		}
		return self::deliver( $recipients, $subject, $body, $headers );
	}
	public static function test() {
		if ( ! erankly_forms_settings()['smtp_enabled'] ) { return new WP_Error( 'smtp_disabled', __( 'Enable custom SMTP before sending a test email.', 'easyrankly' ) ); }
		return self::deliver( array( get_option( 'admin_email' ) ), __( 'EasyRankly SMTP test', 'easyrankly' ), __( 'Your custom SMTP configuration can send form emails.', 'easyrankly' ) . "\n" . home_url(), array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}
	private static function deliver( array $recipients, string $subject, string $body, array $headers ) {
		$smtp = erankly_forms_settings();
		if ( $smtp['smtp_enabled'] && ( ! $smtp['smtp_host'] || ! is_email( $smtp['smtp_from_email'] ) || ( $smtp['smtp_auth'] && ( '' === $smtp['smtp_username'] || '' === $smtp['smtp_password'] ) ) ) ) {
			return new WP_Error( 'smtp_incomplete', __( 'Complete the custom SMTP settings before sending email.', 'easyrankly' ) );
		}
		$transport = null;
		$previous = array();
		$configure = static function ( $mailer ) use ( $smtp, &$transport, &$previous, &$configure ): void {
			// Configure only this send. Restore the shared WordPress mailer even when sending throws.
			remove_action( 'phpmailer_init', $configure, PHP_INT_MAX );
			$transport = $mailer;
			foreach ( array( 'Mailer', 'Host', 'Port', 'SMTPSecure', 'SMTPAutoTLS', 'SMTPAuth', 'Username', 'Password', 'AuthType', 'SMTPOptions', 'SMTPDebug', 'SMTPKeepAlive', 'Timeout', 'From', 'FromName', 'Sender' ) as $property ) { $previous[ $property ] = $mailer->$property; }
			$mailer->smtpClose(); // A persistent connection may still be authenticated to another SMTP server.
			$mailer->isSMTP();
			$mailer->Host = filter_var( $smtp['smtp_host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $smtp['smtp_host'] . ']' : $smtp['smtp_host'];
			$mailer->Port = $smtp['smtp_port'];
			$mailer->SMTPSecure = $smtp['smtp_encryption'];
			$mailer->SMTPAutoTLS = '' !== $smtp['smtp_encryption'];
			$mailer->SMTPAuth = (bool) $smtp['smtp_auth'];
			$mailer->Username = $smtp['smtp_auth'] ? $smtp['smtp_username'] : '';
			$mailer->Password = $smtp['smtp_auth'] ? $smtp['smtp_password'] : '';
			$mailer->AuthType = '';
			$mailer->SMTPOptions['ssl'] = array( 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false );
			$mailer->SMTPDebug = 0;
			$mailer->SMTPKeepAlive = false;
			$mailer->Timeout = 15;
			$mailer->setFrom( $smtp['smtp_from_email'], $smtp['smtp_from_name'] ?: get_bloginfo( 'name' ), false );
			$mailer->Sender = $smtp['smtp_from_email'];
		};
		// Do not persist PHPMailer messages: they may contain addresses or submitted data.
		$failed = false;
		$capture = static function () use ( &$failed ): void { $failed = true; };
		add_action( 'wp_mail_failed', $capture );
		if ( $smtp['smtp_enabled'] ) { add_action( 'phpmailer_init', $configure, PHP_INT_MAX ); }
		try { $sent = wp_mail( $recipients, $subject, $body, $headers ); }
		catch ( Throwable $error ) { $sent = false; }
		finally {
			remove_action( 'wp_mail_failed', $capture );
			remove_action( 'phpmailer_init', $configure, PHP_INT_MAX );
			if ( $transport ) {
				$transport->smtpClose();
				foreach ( $previous as $property => $value ) { $transport->$property = $value; }
			}
		}
		return $sent && ! $failed ? true : new WP_Error( 'mail_failed', __( 'Email delivery failed.', 'easyrankly' ) );
	}
}
