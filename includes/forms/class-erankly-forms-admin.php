<?php
/** Site-scoped configuration; secrets never leave server storage. */
defined( 'ABSPATH' ) || exit;
final class ERankly_Forms_Admin {
	public static function tabs( $tabs ): array {
		$tabs['forms'] = array( 'label' => __( 'Forms', 'easyrankly' ), 'scope' => 'site', 'group' => 'feature_modules', 'capability' => 'manage_options', 'position' => 40 );
		return $tabs;
	}
	public static function register_rest(): void {
		register_rest_route( 'erankly/v1', '/forms/settings', array(
			array( 'methods' => 'GET', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'read' ) ),
			array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'save' ) ),
		) );
		register_rest_route( 'erankly/v1', '/forms/slack-test', array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'slack_test' ) ) );
		register_rest_route( 'erankly/v1', '/forms/smtp-test', array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'smtp_test' ) ) );
		register_rest_route( 'erankly/v1', '/forms/mailerlite-groups', array( 'methods' => 'GET', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'mailerlite_groups' ) ) );
		register_rest_route( 'erankly/v1', '/forms/mailerlite-test', array( 'methods' => 'POST', 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'mailerlite_test' ) ) );
	}
	public static function permission(): bool { return erankly_forms_enabled() && current_user_can( 'manage_options' ); }
	public static function read(): WP_REST_Response {
		$s = erankly_forms_settings();
		$public = array_intersect_key( $s, array_flip( array( 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_auth', 'smtp_username', 'smtp_from_email', 'smtp_from_name' ) ) );
		$public = array_merge( $public, ERankly_Forms_Style::values( $s ) );
		$public['mailerlite_enabled'] = (bool) $s['mailerlite_enabled'];
		$public['mailerlite_api_key_configured'] = '' !== $s['mailerlite_api_key'];
		return new WP_REST_Response( array_merge( $public, array( 'smtp_password_configured' => '' !== $s['smtp_password'], 'turnstile_enabled' => (bool) $s['turnstile_enabled'], 'turnstile_site_key' => $s['turnstile_site_key'], 'turnstile_secret_configured' => '' !== $s['turnstile_secret_key'], 'slack_enabled' => '' !== $s['slack_webhook_url'], 'slack_configured' => '' !== $s['slack_webhook_url'] ) ) );
	}
	public static function save( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || strlen( $request->get_body() ) > 8192 ) { return new WP_Error( 'invalid_settings', __( 'Please check the settings.', 'easyrankly' ), array( 'status' => 400 ) ); }
		$s = erankly_forms_settings();
		$s = ERankly_Forms_Style::sanitize( $input, $s );
		if ( is_wp_error( $s ) ) { return $s; }
		$s = self::smtp_settings( $input, $s );
		if ( is_wp_error( $s ) ) { return $s; }
		$s = ERankly_Forms_MailerLite::settings( $input, $s );
		if ( is_wp_error( $s ) ) { return $s; }
		foreach ( array( 'turnstile_site_key', 'turnstile_secret_key', 'slack_webhook_url' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) { return new WP_Error( 'invalid_settings', __( 'Please check the settings.', 'easyrankly' ), array( 'status' => 400 ) ); }
		}
		$disable_turnstile = isset( $input['turnstile_enabled'] ) && ! (bool) $input['turnstile_enabled'];
		if ( isset( $input['turnstile_enabled'] ) ) { $s['turnstile_enabled'] = (bool) $input['turnstile_enabled']; }
		if ( ! $disable_turnstile && isset( $input['turnstile_site_key'] ) ) {
			$sitekey = trim( $input['turnstile_site_key'] );
			if ( '' !== $sitekey && ! preg_match( '/^[a-zA-Z0-9_-]{10,100}$/D', $sitekey ) ) { return new WP_Error( 'invalid_site_key', __( 'The Turnstile site key is invalid.', 'easyrankly' ), array( 'status' => 400 ) ); }
			$s['turnstile_site_key'] = $sitekey;
		}
		// Disabling verification removes the secret without validating stale, hidden key fields.
		if ( $disable_turnstile ) { $s['turnstile_secret_key'] = ''; }
		$secret = $disable_turnstile ? '' : trim( $input['turnstile_secret_key'] ?? '' );
		if ( '' !== $secret ) {
			if ( strlen( $secret ) > 200 || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $secret ) ) { return new WP_Error( 'invalid_secret', __( 'The Turnstile secret key is invalid.', 'easyrankly' ), array( 'status' => 400 ) ); }
			if ( ERankly_Forms_Turnstile::is_test_secret( $secret ) && ! ERankly_Forms_Turnstile::local_environment() ) { return new WP_Error( 'test_key_in_production', __( 'Turnstile test keys are only allowed in local or development environments.', 'easyrankly' ), array( 'status' => 400 ) ); }
			$valid = ERankly_Forms_Turnstile::check_secret( $secret );
			if ( is_wp_error( $valid ) ) { return $valid; }
			$s['turnstile_secret_key'] = $secret;
		}
		if ( array_key_exists( 'slack_enabled', $input ) && ! is_bool( $input['slack_enabled'] ) ) { return new WP_Error( 'invalid_settings', __( 'Please check the settings.', 'easyrankly' ), array( 'status' => 400 ) ); }
		// The saved webhook is the source of truth for Slack activation, including existing setups.
		$disable_slack = isset( $input['slack_enabled'] ) && ! $input['slack_enabled'];
		if ( $disable_slack ) { $s['slack_webhook_url'] = ''; }
		$url = $disable_slack ? '' : trim( $input['slack_webhook_url'] ?? '' );
		if ( '' !== $url ) {
			if ( ! ERankly_Forms_Slack::valid_url( $url ) ) { return new WP_Error( 'invalid_webhook', __( 'Use an HTTPS incoming webhook from hooks.slack.com.', 'easyrankly' ), array( 'status' => 400 ) ); }
			$s['slack_webhook_url'] = $url;
		}
		if ( ! empty( $input['clear_turnstile_secret'] ) ) { $s['turnstile_secret_key'] = ''; }
		if ( ! empty( $input['clear_slack_webhook'] ) ) { $s['slack_webhook_url'] = ''; }
		if ( ! empty( $input['slack_enabled'] ) && '' === $s['slack_webhook_url'] ) { return new WP_Error( 'missing_slack_webhook', __( 'Configure a Slack webhook before enabling notifications.', 'easyrankly' ), array( 'status' => 400 ) ); }
		if ( $s['turnstile_enabled'] && ( ! $s['turnstile_site_key'] || ! $s['turnstile_secret_key'] ) ) { return new WP_Error( 'missing_turnstile_keys', __( 'Configure both Turnstile keys before enabling verification.', 'easyrankly' ), array( 'status' => 400 ) ); }
		if ( $s !== erankly_forms_settings() && ! update_option( 'erankly_forms', $s, false ) ) { return new WP_Error( 'settings_failed', __( 'Settings could not be saved.', 'easyrankly' ), array( 'status' => 500 ) ); }
		return self::read();
	}
	/** Validate the entire SMTP configuration before persisting any changes. Blank passwords keep the saved value. */
	private static function smtp_settings( array $input, array $s ) {
		$invalid = new WP_Error( 'invalid_smtp_settings', __( 'Please check the SMTP host, port, security, credentials and sender.', 'easyrankly' ), array( 'status' => 400 ) );
		foreach ( array( 'smtp_host', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				if ( ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $input[ $key ] ) ) { return $invalid; }
				$value = 'smtp_password' === $key ? $input[ $key ] : trim( $input[ $key ] );
				if ( 'smtp_password' !== $key || '' !== $value ) { $s[ $key ] = $value; }
			}
		}
		foreach ( array( 'smtp_enabled', 'smtp_auth', 'clear_smtp_password' ) as $key ) {
			if ( array_key_exists( $key, $input ) && ! is_bool( $input[ $key ] ) ) { return $invalid; }
		}
		foreach ( array( 'smtp_enabled', 'smtp_auth' ) as $key ) { if ( isset( $input[ $key ] ) ) { $s[ $key ] = $input[ $key ]; } }
		if ( ! empty( $input['clear_smtp_password'] ) ) { $s['smtp_password'] = ''; }
		if ( array_key_exists( 'smtp_port', $input ) ) {
			$port = $input['smtp_port'];
			if ( ( ! is_int( $port ) && ! is_string( $port ) ) || ! preg_match( '/^[0-9]{1,5}$/D', (string) $port ) || (int) $port < 1 || (int) $port > 65535 ) { return $invalid; }
			$s['smtp_port'] = (int) $port;
		}
		$host = $s['smtp_host'];
		if ( '' !== $host && ( strlen( $host ) > 253 || ( ! filter_var( $host, FILTER_VALIDATE_IP ) && ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/iD', $host ) ) ) ) { return $invalid; }
		if ( ! in_array( $s['smtp_encryption'], array( '', 'tls', 'ssl' ), true ) || ( '' !== $s['smtp_from_email'] && ! is_email( $s['smtp_from_email'] ) ) || strlen( $s['smtp_username'] ) > 320 || strlen( $s['smtp_from_name'] ) > 200 ) { return $invalid; }
		$s['smtp_from_email'] = sanitize_email( $s['smtp_from_email'] );
		$s['smtp_from_name'] = sanitize_text_field( $s['smtp_from_name'] );
		if ( $s['smtp_enabled'] && ( '' === $host || '' === $s['smtp_from_email'] || ( $s['smtp_auth'] && ( '' === $s['smtp_username'] || '' === $s['smtp_password'] ) ) ) ) {
			return new WP_Error( 'missing_smtp_settings', __( 'Configure the SMTP host and sender, and credentials when authentication is enabled.', 'easyrankly' ), array( 'status' => 400 ) );
		}
		return $s;
	}
	public static function smtp_test() {
		if ( get_transient( 'erankly_forms_smtp_throttle' ) ) { return new WP_Error( 'smtp_rate_limited', __( 'Wait a minute before sending another test email.', 'easyrankly' ), array( 'status' => 429 ) ); }
		set_transient( 'erankly_forms_smtp_throttle', 1, MINUTE_IN_SECONDS );
		$result = ERankly_Forms_Mailer::test();
		if ( is_wp_error( $result ) ) { erankly_forms_delivery_error( 'email', $result->get_error_code() ); return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 503 ) ); }
		$status = erankly_forms_delivery_status();
		unset( $status['email'] );
		if ( $status ) { update_option( 'erankly_forms_delivery_status', $status, false ); }
		else { delete_option( 'erankly_forms_delivery_status' ); }
		return new WP_REST_Response( array( 'message' => __( 'Test email accepted for sending to the site administrator. Check the inbox and spam folder.', 'easyrankly' ) ) );
	}
	public static function slack_test() {
		$result = ERankly_Forms_Slack::send( ERankly_Forms_Slack::payload( __( 'EasyRankly test message', 'easyrankly' ), array(), array(), home_url() ) );
		if ( is_wp_error( $result ) ) { erankly_forms_delivery_error( 'slack', $result->get_error_code() ); return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 503 ) ); }
		return new WP_REST_Response( array( 'message' => __( 'Test message sent.', 'easyrankly' ) ) );
	}
	public static function mailerlite_groups() {
		$groups = ERankly_Forms_MailerLite::groups();
		return is_wp_error( $groups ) ? $groups : new WP_REST_Response( array( 'groups' => $groups ) );
	}
	public static function mailerlite_test() {
		$groups = ERankly_Forms_MailerLite::groups( null, true );
		if ( is_wp_error( $groups ) ) { return $groups; }
		return new WP_REST_Response( array( 'message' => sprintf( __( 'Connected to MailerLite. %d groups available.', 'easyrankly' ), count( $groups ) ) ) );
	}
	public static function assets(): void {
		if ( is_network_admin() || ( $_GET['page'] ?? '' ) !== 'erankly' || ( $_GET['erankly_tab'] ?? '' ) !== 'forms' ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		wp_enqueue_style( 'erankly-forms' );
		wp_enqueue_style( 'erankly-forms-admin', ERANKLY_URL . 'assets/css/forms-admin.css', array( 'erankly-admin-settings' ), ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'assets/css/forms-admin.css' ) );
		wp_enqueue_script( 'erankly-forms-admin', ERANKLY_URL . 'assets/js/forms-admin.js', array( 'wp-api-fetch', 'wp-i18n' ), ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'assets/js/forms-admin.js' ), true );
		wp_set_script_translations( 'erankly-forms-admin', 'easyrankly', ERANKLY_PATH . 'languages' );
	}
	public static function parent_menu( $parent ) { $screen = get_current_screen(); return $screen && 'erankly_form' === $screen->post_type ? 'options-general.php' : $parent; }
	public static function submenu( $submenu ) { $screen = get_current_screen(); return $screen && 'erankly_form' === $screen->post_type ? 'erankly' : $submenu; }
	/**
	 * Renders the Forms panel.
	 *
	 * One form wraps every section, exactly like the panels that post to options.php: the form element is
	 * what forms-admin.js reads the settings from, and admin-core.css gives a panel-level form the panel's
	 * own rhythm so the sections keep the same 24px gaps as everywhere else. Settings save automatically;
	 * the live region at the end reports save results and integration test messages.
	 */
	public static function render(): void {
		if ( ! self::permission() || is_network_admin() ) { return; }
		$s = self::read()->get_data();
		echo '<form id="erankly-forms-settings" autocomplete="off">';
		self::render_form_list();
		self::render_style( $s );
		self::render_mailerlite( $s );
		self::render_turnstile( $s );
		self::render_slack( $s );
		self::render_delivery_status();
		self::render_smtp( $s );
		echo '<p class="erankly-forms-status" id="erankly-forms-admin-status" role="status" aria-live="polite"></p>';
		echo '</form>';
	}
	/** Lists the existing forms with edit and delete actions in the shared table. */
	private static function render_form_list(): void {
		erankly_section_open( __( 'Forms', 'easyrankly' ) );
		$forms = get_posts( array( 'post_type' => 'erankly_form', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => -1 ) );
		echo '<table class="widefat striped erankly-panel-table"><thead><tr><th>' . esc_html__( 'Title', 'easyrankly' ) . '</th><th>' . esc_html__( 'Actions', 'easyrankly' ) . '</th></tr></thead><tbody>';
		foreach ( $forms as $form ) {
			$edit_url = get_edit_post_link( $form->ID );
			$delete_url = get_delete_post_link( $form->ID );
			echo '<tr><td><a href="' . esc_url( $edit_url ) . '">' . esc_html( $form->post_title ?: __( 'Untitled form', 'easyrankly' ) ) . '</a> (' . esc_html( get_post_status_object( $form->post_status )->label ) . ')</td>';
			echo '<td class="erankly-form-actions">';
			if ( $edit_url ) { echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit' ) . '</a>'; }
			if ( $edit_url && $delete_url ) { echo ' <span aria-hidden="true">/</span> '; }
			if ( $delete_url ) { echo '<a class="erankly-form-delete" href="' . esc_url( $delete_url ) . '">' . esc_html__( 'Delete' ) . '</a>'; }
			echo '</td></tr>';
		}
		if ( ! $forms ) { echo '<tr><td colspan="2">' . esc_html__( 'Create your first form.', 'easyrankly' ) . '</td></tr>'; }
		echo '</tbody></table>';
		echo '<div class="erankly-panel-toolbar">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=erankly_form' ) ) . '">' . esc_html__( 'Add form', 'easyrankly' ) . '</a>';
		echo '</div>';
		erankly_section_close();
	}
	private static function render_style( array $s ): void {
		erankly_section_open( __( 'Style', 'easyrankly' ), array( 'class' => 'erankly-forms-style' ) );
		echo '<div class="erankly-form-style-layout"><div class="erankly-stack"><h3 class="erankly-form-style-heading">' . esc_html__( 'Spacing and border', 'easyrankly' ) . '</h3>';
		echo '<div class="erankly-form-box-model">';
		echo '<span class="erankly-box-caption erankly-box-caption--spacing">' . esc_html__( 'Field spacing', 'easyrankly' ) . '</span>';
		self::style_input( 'style_gap', $s, 'erankly-box-control erankly-box-control--spacing', true );
		echo '<div class="erankly-box-border"><span class="erankly-box-caption">' . esc_html__( 'Border', 'easyrankly' ) . '</span>';
		self::style_input( 'style_border_width', $s, 'erankly-box-control erankly-box-control--border', true );
		echo '<div class="erankly-box-padding"><span class="erankly-box-caption">' . esc_html__( 'Padding', 'easyrankly' ) . '</span>';
		self::style_input( 'style_padding_vertical', $s, 'erankly-box-control erankly-box-control--vertical', true );
		self::style_input( 'style_padding_horizontal', $s, 'erankly-box-control erankly-box-control--horizontal', true );
		echo '<output class="erankly-box-mirror erankly-box-mirror--bottom" data-style-value="style_padding_vertical" aria-hidden="true">' . esc_html( $s['style_padding_vertical'] ) . '</output>';
		echo '<output class="erankly-box-mirror erankly-box-mirror--left" data-style-value="style_padding_horizontal" aria-hidden="true">' . esc_html( $s['style_padding_horizontal'] ) . '</output>';
		echo '<div class="erankly-box-content"><span>Aa</span><small>' . esc_html__( 'Field content', 'easyrankly' ) . '</small></div></div></div></div>';
		echo '</div>';
		echo '<div class="erankly-form-style-details"><h3 class="erankly-form-style-heading">' . esc_html__( 'Size', 'easyrankly' ) . '</h3><div class="erankly-form-style-measures">';
		self::style_input( 'style_radius', $s );
		self::style_input( 'style_button_radius', $s );
		self::style_input( 'style_font_size', $s );
		echo '</div><h3 class="erankly-form-style-heading">' . esc_html__( 'Colors', 'easyrankly' ) . '</h3><div class="erankly-form-style-colors">';
		foreach ( array( 'style_border_color', 'style_background', 'style_color', 'style_accent', 'style_button_color' ) as $key ) { self::style_input( $key, $s ); }
		echo '</div></div></div>';
		echo '<div class="erankly-stack erankly-form-preview-area">';
		echo '<div class="erankly-form-preview-heading"><span class="erankly-field-label" id="erankly-forms-preview-label">' . esc_html__( 'Preview', 'easyrankly' ) . '</span><span class="description">' . esc_html__( 'Updates as you adjust the controls', 'easyrankly' ) . '</span></div>';
		echo '<div class="erankly-form-preview-surface"><div id="erankly-forms-style-preview" class="wp-block-easyrankly-contact-form" role="group" aria-labelledby="erankly-forms-preview-label">';
		echo '<div class="erankly-form-field"><label for="erankly-forms-preview-name">' . esc_html__( 'Name', 'easyrankly' ) . '</label><input id="erankly-forms-preview-name" type="text" placeholder="' . esc_attr__( 'Your name', 'easyrankly' ) . '" readonly></div>';
		echo '<div class="erankly-form-field"><label for="erankly-forms-preview-message">' . esc_html__( 'Message', 'easyrankly' ) . '</label><textarea id="erankly-forms-preview-message" rows="5" readonly>' . esc_html__( 'This is how your form fields will look.', 'easyrankly' ) . '</textarea></div>';
		echo '<button type="button" class="wp-element-button" aria-disabled="true">' . esc_html__( 'Send message', 'easyrankly' ) . '</button></div></div>';
		echo '</div>';
		echo '<div class="erankly-form-style-toolbar erankly-form-style-footer"><button type="button" class="button" id="erankly-forms-style-reset">' . esc_html__( 'Reset style', 'easyrankly' ) . '</button></div>';
		erankly_section_close();
	}
	private static function style_input( string $key, array $s, string $class = '', bool $compact = false ): void {
		$field = ERankly_Forms_Style::fields()[ $key ];
		$number = isset( $field['unit'] );
		echo '<div class="erankly-style-control ' . esc_attr( $class ) . '"><label' . ( $compact ? ' class="screen-reader-text"' : '' ) . ' for="erankly-' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label>';
		echo '<div class="erankly-style-control-value"><input id="erankly-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( $number ? 'number' : 'color' ) . '" value="' . esc_attr( $s[ $key ] ) . '" data-style-variable="' . esc_attr( $field['variable'] ) . '" data-style-unit="' . esc_attr( $field['unit'] ?? '' ) . '" data-style-default="' . esc_attr( $field['default'] ) . '"' . ( $number ? ' min="' . esc_attr( $field['min'] ?? 0 ) . '" max="' . esc_attr( $field['max'] ) . '" step="' . esc_attr( $field['step'] ) . '" required' : '' ) . '>';
		if ( $number ) { echo '<span class="erankly-style-unit" aria-hidden="true">px</span>'; }
		else { echo '<output for="erankly-' . esc_attr( $key ) . '" data-style-value="' . esc_attr( $key ) . '">' . esc_html( $s[ $key ] ) . '</output>'; }
		echo '</div></div>';
	}
	private static function render_smtp( array $s ): void {
		erankly_section_open( __( 'Custom SMTP', 'easyrankly' ) );
		self::checkbox( 'smtp_enabled', __( 'Enable custom SMTP', 'easyrankly' ), (bool) $s['smtp_enabled'] );
		echo '<div id="erankly-forms-smtp-fields" class="erankly-stack"' . ( $s['smtp_enabled'] ? '' : ' hidden' ) . '>';
		echo '<p class="description">' . esc_html__( 'Use this SMTP server for form emails. When disabled, forms use the existing WordPress mail configuration.', 'easyrankly' ) . '</p>';
		self::input( 'smtp_host', __( 'SMTP host', 'easyrankly' ), $s['smtp_host'], false );
		echo '<div class="erankly-field"><label for="erankly-smtp_port">' . esc_html__( 'SMTP port', 'easyrankly' ) . '</label><input class="widefat" type="number" min="1" max="65535" id="erankly-smtp_port" name="smtp_port" value="' . esc_attr( $s['smtp_port'] ) . '"></div>';
		echo '<div class="erankly-field"><label for="erankly-smtp_encryption">' . esc_html__( 'Encryption', 'easyrankly' ) . '</label><select class="widefat" id="erankly-smtp_encryption" name="smtp_encryption">';
		foreach ( array( 'tls' => __( 'STARTTLS (usually port 587)', 'easyrankly' ), 'ssl' => __( 'SSL/TLS (usually port 465)', 'easyrankly' ), '' => __( 'None', 'easyrankly' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['smtp_encryption'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></div>';
		self::checkbox( 'smtp_auth', __( 'Use SMTP authentication', 'easyrankly' ), (bool) $s['smtp_auth'] );
		self::input( 'smtp_username', __( 'SMTP username', 'easyrankly' ), $s['smtp_username'], false );
		self::input( 'smtp_password', __( 'SMTP password', 'easyrankly' ), '', $s['smtp_password_configured'] );
		self::checkbox( 'clear_smtp_password', __( 'Remove saved SMTP password (disable authentication or SMTP first)', 'easyrankly' ), false );
		self::input( 'smtp_from_email', __( 'Sender email', 'easyrankly' ), $s['smtp_from_email'], false );
		self::input( 'smtp_from_name', __( 'Sender name', 'easyrankly' ), $s['smtp_from_name'], false );
		echo '<p class="description">' . esc_html__( 'Use a sender address authorized by your SMTP provider. Replies go to the visitor\'s email address.', 'easyrankly' ) . '</p>';
		echo '<div class="erankly-card-actions"><button type="button" class="button" id="erankly-forms-smtp-test">' . esc_html__( 'Send test email', 'easyrankly' ) . '</button></div>';
		echo '<p class="description">' . esc_html( sprintf( __( 'The test email is sent to the site administrator: %s.', 'easyrankly' ), get_option( 'admin_email' ) ) ) . '</p>';
		echo '</div>';
		erankly_section_close();
	}
	private static function render_mailerlite( array $s ): void {
		erankly_section_open( __( 'MailerLite', 'easyrankly' ) );
		self::checkbox( 'mailerlite_enabled', __( 'Enable MailerLite subscriptions', 'easyrankly' ), (bool) $s['mailerlite_enabled'] );
		echo '<div id="erankly-forms-mailerlite-fields" class="erankly-stack"' . ( $s['mailerlite_enabled'] ? '' : ' hidden' ) . '>';
		self::input( 'mailerlite_api_key', __( 'API key', 'easyrankly' ), '', $s['mailerlite_api_key_configured'] );
		self::checkbox( 'clear_mailerlite_api_key', __( 'Remove saved MailerLite API key and disable subscriptions', 'easyrankly' ), false );
		echo '<p class="description">' . esc_html__( 'Choose a group and the email, name and Newsletter consent fields in each Form. Only visitors who select the dedicated newsletter consent are subscribed.', 'easyrankly' ) . '</p>';
		echo '<p class="description"><a href="https://www.mailerlite.com/help/where-to-find-the-mailerlite-api-key-groupid-and-documentation" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Find your MailerLite API key', 'easyrankly' ) . '</a> · <a href="https://www.mailerlite.com/help/how-to-use-double-opt-in-when-collecting-subscribers" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Configure double opt-in for API signups in MailerLite', 'easyrankly' ) . '</a></p>';
		echo '<div class="erankly-card-actions"><button type="button" class="button" id="erankly-forms-mailerlite-test">' . esc_html__( 'Test connection', 'easyrankly' ) . '</button></div></div>';
		erankly_section_close();
	}
	/** Spam verification keys. The secret input is always rendered empty: it is replaced, never shown. */
	private static function render_turnstile( array $s ): void {
		erankly_section_open( __( 'Cloudflare Turnstile', 'easyrankly' ) );
		self::checkbox( 'turnstile_enabled', __( 'Enable Turnstile verification', 'easyrankly' ), (bool) $s['turnstile_enabled'] );
		echo '<div id="erankly-forms-turnstile-fields" class="erankly-stack"' . ( $s['turnstile_enabled'] ? '' : ' hidden' ) . '>';
		self::input( 'turnstile_site_key', __( 'Site key', 'easyrankly' ), $s['turnstile_site_key'], false );
		self::input( 'turnstile_secret_key', __( 'Secret key', 'easyrankly' ), '', $s['turnstile_secret_configured'] );
		echo '<p class="description">' . esc_html__( 'Disabling verification removes the saved secret key. Enter it again to re-enable Turnstile.', 'easyrankly' ) . '</p>';
		echo '</div>';
		erankly_section_close();
	}
	/** Slack delivery webhook plus the test action, which saves first so it posts the stored webhook. */
	private static function render_slack( array $s ): void {
		erankly_section_open( __( 'Slack', 'easyrankly' ) );
		self::checkbox( 'slack_enabled', __( 'Enable Slack notifications', 'easyrankly' ), (bool) $s['slack_enabled'] );
		echo '<div id="erankly-forms-slack-fields" class="erankly-stack"' . ( $s['slack_enabled'] ? '' : ' hidden' ) . '>';
		echo '<p class="description">' . esc_html__( 'Notifications are sent only for forms with "Send to Slack" enabled. The webhook determines the destination channel.', 'easyrankly' ) . '</p>';
		self::input( 'slack_webhook_url', __( 'Incoming webhook', 'easyrankly' ), '', $s['slack_configured'] );
		echo '<p class="description">' . esc_html__( 'Disabling notifications removes the saved webhook. Enter it again to re-enable Slack.', 'easyrankly' ) . '</p>';
		echo '<div class="erankly-card-actions"><button type="button" class="button" id="erankly-forms-slack-test">' . esc_html__( 'Send test message', 'easyrankly' ) . '</button></div>';
		echo '</div>';
		erankly_section_close();
	}
	/** Read-only report of the last delivery error per channel. */
	private static function render_delivery_status(): void {
		erankly_section_open( __( 'Delivery status', 'easyrankly' ) );
		$status = erankly_forms_delivery_status();
		if ( ! $status ) { echo '<p>' . esc_html__( 'No delivery errors recorded.', 'easyrankly' ) . '</p>'; }
		foreach ( $status as $channel => $error ) { echo '<p>' . esc_html( $channel . ': ' . $error['code'] . ' · ' . wp_date( 'Y-m-d H:i', $error['time'] ) ) . '</p>'; }
		echo '<p class="description">' . esc_html__( 'Only the last error code and date per channel are stored for up to 30 days. Older errors are automatically removed. Submissions are not archived.', 'easyrankly' ) . '</p>';
		erankly_section_close();
	}
	/** One on/off row, in the same field + toggle anatomy as every other checkbox of the panel. */
	private static function checkbox( string $name, string $label, bool $checked ): void {
		echo '<div class="erankly-field erankly-checkboxes"><label><input type="checkbox" class="erankly-toggle" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked="checked"' : '' ) . '> ' . esc_html( $label ) . '</label></div>';
	}
	private static function input( string $name, string $label, string $value, bool $configured ): void {
		$secret = in_array( $name, array( 'turnstile_secret_key', 'slack_webhook_url', 'smtp_password', 'mailerlite_api_key' ), true );
		$type = $secret ? 'password' : ( 'smtp_from_email' === $name ? 'email' : 'text' );
		echo '<div class="erankly-field"><label for="erankly-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><input class="widefat" id="erankly-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '"' . ( $configured ? ' placeholder="' . esc_attr__( 'Configured — enter a replacement', 'easyrankly' ) . '"' : '' ) . '>';
		if ( $secret ) { echo '<p class="description">' . esc_html__( 'Leave empty to keep the saved value.', 'easyrankly' ) . '</p>'; }
		echo '</div>';
	}
	public static function privacy(): void {
		wp_add_privacy_policy_content( 'EasyRankly', wp_kses_post( wpautop( __( 'If MailerLite is enabled for a Form, selecting its dedicated newsletter consent sends the submitted email address, the optional mapped name and the consent date to the configured MailerLite group. Other form fields and visitor IP addresses are not sent to MailerLite. MailerLite stores and processes this information for newsletter delivery according to the site owner\'s account settings, including API double opt-in. Include MailerLite and the purpose of the newsletter in your privacy policy and newsletter consent text.', 'easyrankly' ) ) ) );
		wp_add_privacy_policy_content( 'EasyRankly', wp_kses_post( wpautop( __( 'Form data is sent to the site owner by email, through the configured SMTP provider when custom SMTP is enabled, and, when enabled for the form, to Slack. EasyRankly does not archive submissions. If Cloudflare Turnstile is enabled, Cloudflare processes visitor device and network information on pages containing forms to prevent spam; verification tokens are sent to Cloudflare for server validation. A salted hash of the client IP is kept for up to ten minutes for rate limiting. Only the date and code of the last delivery error per channel are stored for up to 30 days, with older errors removed automatically on site requests while forms are enabled. Consult the SMTP provider, Cloudflare and Slack privacy policies and adapt this text to your configured services and retention in email and Slack.', 'easyrankly' ) ) ) );
	}
}
