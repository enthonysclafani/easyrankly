<?php
/** Optional forms. Loaded only when enable_forms is active. */
defined( 'ABSPATH' ) || exit;

function erankly_forms_boot(): void {
	foreach ( array( 'style', 'schema', 'submission', 'turnstile', 'mailer', 'mailerlite', 'slack', 'admin' ) as $class ) {
		require_once ERANKLY_PATH . 'includes/forms/class-erankly-forms-' . $class . '.php';
	}
	add_action( 'init', 'erankly_forms_register' );
	add_action( 'init', 'erankly_forms_delivery_status' );
	add_filter( 'rest_pre_insert_erankly_form', 'erankly_forms_validate_definition', 100, 2 );
	add_action( 'rest_api_init', array( 'ERankly_Forms_Submission', 'register' ) );
	add_action( 'rest_api_init', array( 'ERankly_Forms_Admin', 'register_rest' ) );
	add_action( 'admin_init', array( 'ERankly_Forms_Admin', 'privacy' ) );
	add_filter( 'erankly_settings_tabs', array( 'ERankly_Forms_Admin', 'tabs' ) );
	add_filter( 'erankly_admin_site_settings_tabs', static function ( $tabs ) { $tabs[] = 'forms'; return $tabs; } );
	add_action( 'erankly_render_settings_tab_forms', array( 'ERankly_Forms_Admin', 'render' ) );
	add_action( 'admin_enqueue_scripts', array( 'ERankly_Forms_Admin', 'assets' ) );
	add_action( 'enqueue_block_editor_assets', 'erankly_forms_editor_assets' );
	add_filter( 'block_editor_settings_all', 'erankly_forms_editor_settings', 10, 2 );
	add_filter( 'allowed_block_types_all', 'erankly_forms_allowed_blocks', 10, 2 );
	add_filter( 'parent_file', array( 'ERankly_Forms_Admin', 'parent_menu' ) );
	add_filter( 'submenu_file', array( 'ERankly_Forms_Admin', 'submenu' ) );
	add_shortcode( 'easyrankly_form', 'erankly_forms_shortcode' );
}
function erankly_forms_shortcode( $attrs ): string {
	$attrs = shortcode_atts( array( 'id' => 0 ), $attrs, 'easyrankly_form' );
	return do_blocks( get_comment_delimited_block_content( 'easyrankly/contact-form', array( 'ref' => absint( $attrs['id'] ) ), '' ) );
}

function erankly_forms_settings(): array {
	return array_merge( array(
		'turnstile_enabled' => false, 'turnstile_site_key' => '', 'turnstile_secret_key' => '', 'slack_webhook_url' => '',
		'smtp_enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
		'smtp_auth' => true, 'smtp_username' => '', 'smtp_password' => '', 'smtp_from_email' => '', 'smtp_from_name' => '',
		'mailerlite_enabled' => false, 'mailerlite_api_key' => '',
	), ERankly_Forms_Style::defaults(), ERankly_Forms_Style::migrate( (array) get_option( 'erankly_forms', array() ) ) );
}
function erankly_form_settings( int $id ): array {
	$defaults = array( 'recipients' => array(), 'subject' => '', 'slack' => false, 'mailerlite' => false, 'mailerlite_group_id' => '', 'mailerlite_email_field' => 'email', 'mailerlite_name_field' => '', 'mailerlite_consent_field' => 'newsletter', 'success_message' => __( 'Thank you. Your message has been sent.', 'easyrankly' ), 'submit_label' => __( 'Send message', 'easyrankly' ) );
	$meta = get_post_meta( $id, '_erankly_form_settings', true );
	$settings = array_merge( $defaults, is_array( $meta ) ? erankly_forms_sanitize_meta( $meta ) : array() );
	foreach ( array( 'submit_label', 'success_message' ) as $key ) { if ( '' === $settings[ $key ] ) { $settings[ $key ] = $defaults[ $key ]; } }
	return $settings;
}
function erankly_forms_sanitize_meta( $meta ): array {
	$meta = is_array( $meta ) ? $meta : array();
	$recipients = array();
	foreach ( array_slice( (array) ( $meta['recipients'] ?? array() ), 0, 10 ) as $email ) { if ( is_string( $email ) && is_email( $email ) ) { $recipients[] = sanitize_email( $email ); } }
	$settings = array( 'recipients' => array_values( array_unique( $recipients ) ), 'subject' => ERankly_Forms_Schema::cut( sanitize_text_field( $meta['subject'] ?? '' ), 500 ), 'slack' => ! empty( $meta['slack'] ), 'success_message' => ERankly_Forms_Schema::cut( sanitize_text_field( $meta['success_message'] ?? '' ), 1000 ), 'submit_label' => ERankly_Forms_Schema::cut( sanitize_text_field( $meta['submit_label'] ?? '' ), 100 ) );
	$settings['mailerlite'] = ! empty( $meta['mailerlite'] );
	$settings['mailerlite_group_id'] = is_string( $meta['mailerlite_group_id'] ?? null ) && ERankly_Forms_MailerLite::valid_group( $meta['mailerlite_group_id'] ) ? $meta['mailerlite_group_id'] : '';
	foreach ( array( 'mailerlite_email_field' => 'email', 'mailerlite_name_field' => '', 'mailerlite_consent_field' => 'newsletter' ) as $key => $default ) { $settings[ $key ] = substr( sanitize_key( is_string( $meta[ $key ] ?? null ) ? $meta[ $key ] : $default ), 0, 80 ); }
	return $settings;
}
/** Keep only the last error per channel, expiring entries after 30 days on site requests. */
function erankly_forms_delivery_status(): array {
	$stored = get_option( 'erankly_forms_delivery_status', array() );
	$cutoff = time() - 30 * DAY_IN_SECONDS;
	$status = is_array( $stored ) ? array_filter( $stored, static function ( $error ) use ( $cutoff ): bool {
		return is_array( $error ) && isset( $error['time'], $error['code'] ) && is_int( $error['time'] ) && $error['time'] >= $cutoff && is_string( $error['code'] );
	} ) : array();
	if ( $status !== $stored ) {
		if ( $status ) { update_option( 'erankly_forms_delivery_status', $status, false ); }
		else { delete_option( 'erankly_forms_delivery_status' ); }
	}
	return $status;
}
function erankly_forms_delivery_error( string $channel, string $code ): void {
	$status = erankly_forms_delivery_status();
	$status[ $channel ] = array( 'time' => time(), 'code' => sanitize_key( $code ) );
	update_option( 'erankly_forms_delivery_status', $status, false );
}
function erankly_forms_register(): void {
	require_once ERANKLY_PATH . 'includes/forms/class-erankly-forms-rest-controller.php';
	$caps = array_fill_keys( array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ), 'manage_options' );
	register_post_type( 'erankly_form', array(
		'labels' => array( 'name' => __( 'Forms', 'easyrankly' ), 'singular_name' => __( 'Form', 'easyrankly' ), 'add_new_item' => __( 'Add form', 'easyrankly' ), 'edit_item' => __( 'Edit form', 'easyrankly' ) ),
		'public' => false, 'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => true, 'can_export' => true,
		'rest_controller_class' => 'ERankly_Forms_Rest_Controller', 'capabilities' => $caps, 'map_meta_cap' => false,
		'supports' => array( 'title', 'editor', 'revisions', 'custom-fields' ),
		'template' => array(
			array( 'easyrankly/form-field', array( 'type' => 'text', 'name' => 'name', 'label' => __( 'Name', 'easyrankly' ), 'autocomplete' => 'name', 'required' => true ) ),
			array( 'easyrankly/form-field', array( 'type' => 'email', 'name' => 'email', 'label' => __( 'Email', 'easyrankly' ), 'autocomplete' => 'email', 'required' => true ) ),
			array( 'easyrankly/form-field', array( 'type' => 'textarea', 'name' => 'message', 'label' => __( 'Message', 'easyrankly' ), 'required' => true ) ),
			array( 'easyrankly/form-field', array( 'type' => 'consent', 'name' => 'consent', 'label' => __( 'I agree to the privacy policy.', 'easyrankly' ), 'required' => true ) ),
		),
	) );
	$properties = array( 'recipients' => array( 'type' => 'array', 'maxItems' => 10, 'items' => array( 'type' => 'string', 'format' => 'email' ) ), 'subject' => array( 'type' => 'string', 'maxLength' => 500 ), 'slack' => array( 'type' => 'boolean' ), 'success_message' => array( 'type' => 'string', 'maxLength' => 1000 ), 'submit_label' => array( 'type' => 'string', 'maxLength' => 100 ) );
	$properties['mailerlite'] = array( 'type' => 'boolean' );
	$properties['mailerlite_group_id'] = array( 'type' => 'string', 'maxLength' => 32 );
	foreach ( array( 'mailerlite_email_field', 'mailerlite_name_field', 'mailerlite_consent_field' ) as $key ) { $properties[ $key ] = array( 'type' => 'string', 'maxLength' => 80 ); }
	register_post_meta( 'erankly_form', '_erankly_form_settings', array( 'type' => 'object', 'single' => true, 'sanitize_callback' => 'erankly_forms_sanitize_meta', 'auth_callback' => static function () { return current_user_can( 'manage_options' ); }, 'show_in_rest' => array( 'schema' => array( 'type' => 'object', 'context' => array( 'edit' ), 'properties' => $properties, 'additionalProperties' => false ) ) ) );
	$deps = array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-data', 'wp-core-data' );
	wp_register_script( 'erankly-form-field-editor', ERANKLY_URL . 'blocks/form-field/index.js', $deps, ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'blocks/form-field/index.js' ), true );
	wp_register_script( 'erankly-contact-form-editor', ERANKLY_URL . 'blocks/contact-form/index.js', array_merge( $deps, array( 'wp-server-side-render' ) ), ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'blocks/contact-form/index.js' ), true );
	wp_set_script_translations( 'erankly-form-field-editor', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_set_script_translations( 'erankly-contact-form-editor', 'easyrankly', ERANKLY_PATH . 'languages' );
	wp_add_inline_script( 'erankly-contact-form-editor', 'window.eranklyFormsEditor = ' . wp_json_encode( array( 'editUrl' => admin_url( 'post.php' ) ) ) . ';', 'before' );
	wp_register_script_module( 'erankly-forms-view', ERANKLY_URL . 'blocks/contact-form/view.js', array( '@wordpress/interactivity' ), ERANKLY_VERSION );
	wp_register_style( 'erankly-forms', ERANKLY_URL . 'blocks/contact-form/style.css', array(), ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'blocks/contact-form/style.css' ) );
	$style = ERankly_Forms_Style::css( erankly_forms_settings() );
	if ( $style ) { wp_add_inline_style( 'erankly-forms', $style ); }
	register_block_type( ERANKLY_PATH . 'blocks/form-field', array( 'render_callback' => 'erankly_forms_render_field' ) );
	register_block_type( ERANKLY_PATH . 'blocks/contact-form', array( 'render_callback' => 'erankly_forms_render' ) );
}
/** Block-editor REST saves must not publish ambiguous or unusable schemas. */
function erankly_forms_validate_definition( $post, WP_REST_Request $request ) {
	if ( is_wp_error( $post ) ) { return $post; }
	$existing = ! empty( $request['id'] ) ? get_post( (int) $request['id'] ) : null;
	$content = $post->post_content ?? ( $existing ? $existing->post_content : '' );
	$status = $post->post_status ?? ( $existing ? $existing->post_status : 'draft' );
	if ( '' === $content && 'publish' !== $status ) { return $post; }
	$fields = ERankly_Forms_Schema::fields( $content );
	if ( is_wp_error( $fields ) ) { return new WP_Error( 'invalid_form', $fields->get_error_message(), array( 'status' => 400 ) ); }
	$meta = erankly_forms_sanitize_meta( ( $request['meta']['_erankly_form_settings'] ?? null ) ?? ( $existing ? erankly_form_settings( $existing->ID ) : array() ) );
	$valid = ERankly_Forms_MailerLite::validate_form( $meta, $fields );
	return is_wp_error( $valid ) ? $valid : $post;
}
function erankly_forms_supported_blocks(): array {
	return array( 'easyrankly/form-field', 'core/paragraph', 'core/heading', 'core/group', 'core/columns', 'core/column' );
}
function erankly_forms_allowed_blocks( $allowed, $context ) {
	if ( isset( $context->post ) && 'erankly_form' === $context->post->post_type ) { return erankly_forms_supported_blocks(); }
	if ( true === $allowed ) { $allowed = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ); }
	return is_array( $allowed ) ? array_values( array_diff( $allowed, array( 'easyrankly/form-field' ) ) ) : $allowed;
}
function erankly_forms_editor_assets(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'erankly_form' !== $screen->post_type ) { return; }
	// This CPT bypasses the SEO editor's asset loader.
	erankly_enqueue_editor_styles();
	wp_enqueue_script( 'erankly-forms-document-editor', ERANKLY_URL . 'assets/js/forms-editor.js', array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-blocks', 'wp-api-fetch', 'wp-i18n' ), ERANKLY_VERSION . '.' . filemtime( ERANKLY_PATH . 'assets/js/forms-editor.js' ), true );
	wp_add_inline_script( 'erankly-forms-document-editor', 'window.eranklyFormsSettings = ' . wp_json_encode( array( 'settingsUrl' => admin_url( 'options-general.php?page=erankly&erankly_tab=forms' ) ) ) . ';', 'before' );
	wp_set_script_translations( 'erankly-forms-document-editor', 'easyrankly', ERANKLY_PATH . 'languages' );
}
/** Match the spacing between standalone fields in the Form builder to the real form. */
function erankly_forms_editor_settings( array $settings, $context ): array {
	if ( isset( $context->post ) && 'erankly_form' === $context->post->post_type ) {
		$settings['styles'][] = array( 'css' => '.editor-styles-wrapper .is-root-container > .erankly-form-field-editor{margin-block-start:0;margin-block-end:var(--erankly-form-gap);}' );
	}
	return $settings;
}

/** Filter the entire tree before WordPress renders containers and their layout supports. */
function erankly_forms_prepare_blocks( array $blocks, int $depth = 0 ): array {
	if ( $depth > 32 ) { return array(); }
	$safe = array();
	foreach ( $blocks as $block ) {
		if ( ! in_array( $block['blockName'], erankly_forms_supported_blocks(), true ) ) {
			array_push( $safe, ...erankly_forms_prepare_blocks( $block['innerBlocks'], $depth + 1 ) );
			continue;
		}
		$children = $fragments = array();
		$index = 0;
		foreach ( $block['innerContent'] as $fragment ) {
			if ( is_string( $fragment ) ) { $fragments[] = $fragment; continue; }
			foreach ( erankly_forms_prepare_blocks( array( $block['innerBlocks'][ $index++ ] ), $depth + 1 ) as $child ) {
				$children[] = $child;
				$fragments[] = null;
			}
		}
		$block['innerBlocks'] = $children;
		$block['innerContent'] = $fragments;
		$safe[] = $block;
	}
	return $safe;
}
function erankly_forms_content( array $blocks ): string {
	return do_blocks( serialize_blocks( erankly_forms_prepare_blocks( $blocks ) ) );
}
function erankly_forms_render( array $attrs ): string {
	if ( ! erankly_forms_enabled() ) { return ''; }
	$id = absint( $attrs['ref'] ?? 0 );
	$post = get_post( $id );
	if ( ! $post || 'erankly_form' !== $post->post_type || 'publish' !== $post->post_status || $post->post_password ) {
		return $id && current_user_can( 'edit_post', $id ) ? '<p>' . esc_html__( 'Publish this form to display it.', 'easyrankly' ) . '</p>' : '';
	}
	$fields = ERankly_Forms_Schema::fields( $post->post_content );
	if ( is_wp_error( $fields ) ) { return current_user_can( 'manage_options' ) ? '<p>' . esc_html( $fields->get_error_message() ) . '</p>' : ''; }
	$settings = erankly_forms_settings();
	$meta = erankly_form_settings( $id );
	$instance = wp_unique_id( 'erankly-form-' );
	wp_enqueue_style( 'erankly-forms' );
	wp_enqueue_script_module( 'erankly-forms-view' );
	if ( $settings['turnstile_enabled'] && $settings['turnstile_site_key'] && $settings['turnstile_secret_key'] ) {
		wp_enqueue_script( 'erankly-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), null, array( 'strategy' => 'defer', 'in_footer' => true ) );
	}
	wp_interactivity_state( 'easyrankly/forms', array( 'sending' => __( 'Sending…', 'easyrankly' ), 'error' => __( 'Your message could not be delivered. Please try again later.', 'easyrankly' ), 'verification' => __( 'Please complete the verification and try again.', 'easyrankly' ) ) );
	$context = array( 'sending' => false, 'status' => '', 'endpoint' => rest_url( 'erankly/v1/forms/' . $id . '/submit' ), 'siteKey' => $settings['turnstile_enabled'] ? $settings['turnstile_site_key'] : '', 'turnstile' => (bool) $settings['turnstile_enabled'], 'action' => 'erankly_form_' . $id );
	$old = $GLOBALS['erankly_forms_render_context'] ?? null;
	$GLOBALS['erankly_forms_render_context'] = array( 'instance' => $instance, 'fields' => array_values( $fields ), 'cursor' => 0 );
	try { $content = erankly_forms_content( parse_blocks( $post->post_content ) ); }
	finally { $GLOBALS['erankly_forms_render_context'] = $old; }
	$wrapper = get_block_wrapper_attributes( array( 'class' => 'wp-block-easyrankly-contact-form' ) );
	$html = '<form ' . $wrapper . ' id="' . esc_attr( $instance ) . '" autocomplete="on" data-wp-interactive="easyrankly/forms" ' . wp_interactivity_data_wp_context( $context ) . ' data-wp-init="callbacks.init" data-wp-on--submit="actions.submit" data-wp-bind--aria-busy="context.sending">' . $content;
	$hp = ERankly_Forms_Submission::honeypot( $id );
	$html .= '<div class="erankly-form-honeypot" aria-hidden="true"><label for="' . esc_attr( $instance . '-hp' ) . '">' . esc_html__( 'Leave this field empty', 'easyrankly' ) . '</label><input id="' . esc_attr( $instance . '-hp' ) . '" name="' . esc_attr( $hp ) . '" type="text" autocomplete="off" tabindex="-1"></div>';
	if ( $settings['turnstile_enabled'] ) { $html .= '<div class="erankly-form-turnstile"></div>'; }
	$html .= '<button type="submit" class="wp-element-button" data-wp-bind--disabled="context.sending">' . esc_html( $meta['submit_label'] ) . '</button><p class="erankly-form-status" role="status" aria-live="polite" data-wp-text="context.status"></p><noscript>' . esc_html__( 'JavaScript is required to submit this form.', 'easyrankly' ) . '</noscript></form>';
	return $html;
}
function erankly_forms_render_field( array $attrs ): string {
	$ctx = &$GLOBALS['erankly_forms_render_context'];
	if ( ! is_array( $ctx ) || ! isset( $ctx['fields'][ $ctx['cursor'] ] ) ) { return ''; }
	$f = $ctx['fields'][ $ctx['cursor']++ ];
	$id = $ctx['instance'] . '-' . $f['name'];
	$group = in_array( $f['type'], array( 'radio', 'checkboxes' ), true );
	$consent = in_array( $f['type'], array( 'consent', 'newsletter_consent' ), true );
	$wrapper = get_block_wrapper_attributes( array( 'class' => 'erankly-form-field' . ( $consent ? ' erankly-form-field--consent' : '' ) ) );
	$html = $group ? '<fieldset ' . $wrapper . ' data-erankly-field="' . esc_attr( $f['name'] ) . '"><legend>' : '<div ' . $wrapper . ' data-erankly-field="' . esc_attr( $f['name'] ) . '"><label for="' . esc_attr( $id ) . '">';
	$label = esc_html( $f['label'] ) . ( $f['required'] ? ' <span aria-hidden="true">*</span>' : '' );
	if ( ! $consent ) { $html .= $label . ( $group ? '</legend>' : '</label>' ); }
	$common = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $f['name'] ) . '" aria-describedby="' . esc_attr( $id . '-help ' . $id . '-error' ) . '"' . ( $f['required'] ? ' required' : '' );
	if ( $f['autocomplete'] ) { $common .= ' autocomplete="' . esc_attr( $f['autocomplete'] ) . '"'; }
	$placeholder = ' placeholder="' . esc_attr( $f['placeholder'] ) . '"';
	if ( 'textarea' === $f['type'] ) { $html .= '<textarea' . $common . $placeholder . ' maxlength="5000" rows="5"></textarea>'; }
	elseif ( 'select' === $f['type'] ) {
		$html .= '<select' . $common . '><option value="">' . esc_html( $f['placeholder'] ?: __( 'Choose an option', 'easyrankly' ) ) . '</option>';
		foreach ( $f['options'] as $option ) { $html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>'; }
		$html .= '</select>';
	} elseif ( $group ) {
		foreach ( $f['options'] as $i => $option ) {
			$option_id = $id . '-' . $i;
			$html .= '<label for="' . esc_attr( $option_id ) . '"><input id="' . esc_attr( $option_id ) . '" type="' . ( 'radio' === $f['type'] ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $f['name'] ) . '" value="' . esc_attr( $option ) . '" aria-describedby="' . esc_attr( $id . '-help ' . $id . '-error' ) . '"' . ( $f['required'] && 'radio' === $f['type'] ? ' required' : '' ) . '> ' . esc_html( $option ) . '</label>';
		}
	} elseif ( $consent ) { $html .= '<input type="checkbox"' . $common . ' value="1"><span>' . $label . '</span></label>'; }
	else { $html .= '<input type="' . esc_attr( $f['type'] ) . '"' . $common . $placeholder . ' maxlength="' . ERankly_Forms_Schema::limit( $f['type'] ) . '">'; }
	$html .= '<small id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $f['help'] ) . '</small><small id="' . esc_attr( $id . '-error' ) . '" class="erankly-form-error"></small>' . ( $group ? '</fieldset>' : '</div>' );
	return $html;
}
