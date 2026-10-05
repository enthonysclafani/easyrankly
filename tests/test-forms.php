<?php
/** Contact forms: validation, permissions, delivery and lifecycle integration. */
final class ERankly_Forms_Test extends WP_UnitTestCase {
	private $stored;
	private $old_ip;
	private $http;
	private $mail;
	private int $id;
	private array $values = array( 'name' => 'Ada', 'email' => 'ada@example.org', 'message' => 'Hello', 'consent' => '1' );
	public function set_up(): void {
		parent::set_up();
		$this->stored = erankly_get_settings(); $this->old_ip = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 254 );
		erankly_tests_set_settings( array_merge( $this->stored, array( 'enable_forms' => 1 ) ) );
		require_once ERANKLY_PATH . 'includes/forms.php';
		foreach ( array( 'style', 'schema', 'submission', 'turnstile', 'mailer', 'mailerlite', 'slack', 'admin' ) as $class ) { require_once ERANKLY_PATH . 'includes/forms/class-erankly-forms-' . $class . '.php'; }
		erankly_forms_register();
		add_filter( 'rest_pre_insert_erankly_form', 'erankly_forms_validate_definition', 100, 2 );
		$GLOBALS['wp_rest_server'] = new WP_REST_Server(); do_action( 'rest_api_init' );
		get_post_type_object( 'erankly_form' )->get_rest_controller()->register_routes();
		ERankly_Forms_Submission::register(); ERankly_Forms_Admin::register_rest();
		$this->id = self::factory()->post->create( array( 'post_type' => 'erankly_form', 'post_status' => 'publish', 'post_title' => 'Contact', 'post_content' => $this->content() ) );
		$this->http = static function () { return new WP_Error( 'test_no_network', 'Network blocked in tests' ); }; add_filter( 'pre_http_request', $this->http );
		$this->mail = '__return_true'; add_filter( 'pre_wp_mail', $this->mail ); wp_set_current_user( 0 );
	}
	public function tear_down(): void {
		remove_filter( 'rest_pre_insert_erankly_form', 'erankly_forms_validate_definition', 100 );
		remove_filter( 'pre_http_request', $this->http ); remove_filter( 'pre_wp_mail', $this->mail );
		delete_option( 'erankly_forms' ); delete_option( 'erankly_forms_delivery_status' ); delete_transient( 'erankly_forms_slack_throttle' ); delete_transient( 'erankly_forms_smtp_throttle' );
		delete_transient( 'erankly_form_rl_' . wp_hash( $_SERVER['REMOTE_ADDR'] ) );
		if ( null === $this->old_ip ) { unset( $_SERVER['REMOTE_ADDR'] ); } else { $_SERVER['REMOTE_ADDR'] = $this->old_ip; }
		unregister_post_meta( 'erankly_form', '_erankly_form_settings' ); unregister_post_type( 'erankly_form' );
		unregister_block_type( 'easyrankly/form-field' ); unregister_block_type( 'easyrankly/contact-form' );
		wp_dequeue_script( 'erankly-turnstile' ); wp_dequeue_script_module( 'erankly-forms-view' ); wp_dequeue_style( 'erankly-forms' );
		$GLOBALS['wp_rest_server'] = null; erankly_tests_set_settings( $this->stored ); parent::tear_down();
	}
	private function content( array $attrs = array() ): string {
		$attrs = $attrs ?: array( array( 'type' => 'text', 'name' => 'name', 'label' => 'Name', 'autocomplete' => 'name', 'required' => true ), array( 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'autocomplete' => 'email', 'required' => true ), array( 'type' => 'textarea', 'name' => 'message', 'label' => 'Message', 'required' => true ), array( 'type' => 'consent', 'name' => 'consent', 'label' => 'Consent', 'required' => true ) );
		return implode( '', array_map( static function ( $a ) { return '<!-- wp:easyrankly/form-field ' . wp_json_encode( $a ) . ' /-->'; }, $attrs ) );
	}
	private function request( string $path, string $method = 'GET', array $data = array() ): WP_REST_Response {
		$r = new WP_REST_Request( $method, $path ); if ( 'POST' === $method ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $data ) ); }
		return rest_get_server()->dispatch( $r );
	}
	private function submit( array $payload = array() ): WP_REST_Response { return $this->request( '/erankly/v1/forms/' . $this->id . '/submit', 'POST', array_merge( array( 'fields' => $this->values ), $payload ) ); }
	private function admin(): void { wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) ); }
	private function mock_http( $body, int $status = 200 ): void {
		remove_filter( 'pre_http_request', $this->http );
		$this->http = static function () use ( $body, $status ) { return array( 'response' => array( 'code' => $status ), 'body' => is_array( $body ) ? wp_json_encode( $body ) : $body, 'headers' => array() ); }; add_filter( 'pre_http_request', $this->http );
	}
	public function test_schema_handles_nested_blocks_and_rejects_invalid_definitions(): void {
		$f = ERankly_Forms_Schema::fields( '<!-- wp:group --><div>' . $this->content() . '</div><!-- /wp:group -->' );
		$this->assertSame( array( 'name', 'email', 'message', 'consent' ), array_keys( $f ) );
		$this->assertWPError( ERankly_Forms_Schema::fields( $this->content( array( array( 'name' => 'x' ), array( 'name' => 'x' ) ) ) ) );
		$this->assertWPError( ERankly_Forms_Schema::fields( $this->content( array( array( 'name' => 'x', 'type' => 'file' ) ) ) ) );
		$a = array(); for ( $i = 0; $i < 31; $i++ ) { $a[] = array( 'name' => 'x' . $i ); }
		$this->assertWPError( ERankly_Forms_Schema::fields( $this->content( $a ) ) );
	}
	/** @dataProvider invalid_values */
	public function test_invalid_values( string $type, $value, array $options = array() ): void {
		$f = ERankly_Forms_Schema::fields( $this->content( array( array( 'type' => $type, 'name' => 'value', 'options' => $options ) ) ) );
		$this->assertArrayHasKey( 'value', ERankly_Forms_Schema::validate( $f, array( 'value' => $value ) )['errors'] );
	}
	public function invalid_values(): array { return array( array( 'text', str_repeat( 'a', 201 ) ), array( 'textarea', str_repeat( 'a', 5001 ) ), array( 'email', 'invalid' ), array( 'email', str_repeat( 'a', 250 ) . '@a.org' ), array( 'tel', 'bad-number' ), array( 'tel', str_repeat( '1', 41 ) ), array( 'url', 'javascript:alert(1)' ), array( 'url', 'ftp://example.org/f' ), array( 'url', 'https://example.org/' . str_repeat( 'x', 2048 ) ), array( 'select', 'Unknown', array( 'Known' ) ), array( 'radio', 'Unknown', array( 'Known' ) ), array( 'checkboxes', array( 'Unknown' ), array( 'Known' ) ), array( 'checkboxes', 'Known', array( 'Known' ) ), array( 'consent', 'yes' ), array( 'text', array( 'injection' ) ) ); }
	public function test_unknown_fields_discarded_required_consent_and_valid_choices(): void {
		$f = ERankly_Forms_Schema::fields( get_post( $this->id )->post_content ); $v = $this->values; $v['unknown'] = 'injected'; $v['consent'] = '';
		$r = ERankly_Forms_Schema::validate( $f, $v ); $this->assertArrayNotHasKey( 'unknown', $r['values'] ); $this->assertSame( array( 'consent' ), array_keys( $r['errors'] ) );
		$f = ERankly_Forms_Schema::fields( $this->content( array( array( 'name' => 'phone', 'type' => 'tel' ), array( 'name' => 'url', 'type' => 'url' ), array( 'name' => 'choices', 'type' => 'checkboxes', 'required' => true, 'options' => array( 'One', 'Two' ) ) ) ) );
		$r = ERankly_Forms_Schema::validate( $f, array( 'phone' => '+39 (02) 123-456', 'url' => 'https://example.org/a', 'choices' => array( 'One', 'Two', 'One' ) ) );
		$this->assertSame( array(), $r['errors'] ); $this->assertSame( array( 'One', 'Two' ), $r['values']['choices'] );
	}
	public function test_render_unique_ids_autocomplete_honeypot_cache_and_assets(): void {
		$this->assertFalse( wp_style_is( 'erankly-forms', 'enqueued' ) );
		$a = erankly_forms_render( array( 'ref' => $this->id ) ); $b = erankly_forms_render( array( 'ref' => $this->id ) ); preg_match_all( '/\bid="([^"]+)"/', $a . $b, $m );
		$this->assertCount( count( array_unique( $m[1] ) ), $m[1] );
		foreach ( array( 'autocomplete="on"', 'autocomplete="name"', 'autocomplete="email"', 'autocomplete="off" tabindex="-1"' ) as $needle ) { $this->assertStringContainsString( $needle, $a ); }
		$this->assertStringNotContainsString( 'nonce', $a ); $this->assertTrue( wp_style_is( 'erankly-forms', 'enqueued' ) ); $this->assertFalse( wp_script_is( 'erankly-turnstile', 'enqueued' ) );
	}
	public function test_container_layouts_preserve_fields_and_submission(): void {
		$left = $this->content( array( array( 'name' => 'name', 'label' => 'Name', 'required' => true ), array( 'name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ) ) );
		$right = $this->content( array( array( 'name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ), array( 'name' => 'consent', 'type' => 'consent', 'label' => 'Consent', 'required' => true ) ) );
		$content = '<!-- wp:group {"className":"contact-section","layout":{"type":"flex","orientation":"vertical"},"style":{"spacing":{"padding":{"top":"24px"}}}} --><div class="wp-block-group contact-section" style="padding-top:24px"><!-- wp:heading --><h2 class="wp-block-heading">Contact us</h2><!-- /wp:heading --><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"40%"} --><div class="wp-block-column" style="flex-basis:40%">' . $left . '</div><!-- /wp:column --><!-- wp:column {"width":"60%"} --><div class="wp-block-column" style="flex-basis:60%"><!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group">' . $right . '</div><!-- /wp:group --></div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';
		wp_update_post( array( 'ID' => $this->id, 'post_content' => $content ) );
		$html = erankly_forms_render( array( 'ref' => $this->id ) );
		foreach ( array( 'wp-block-columns', 'wp-block-column', 'contact-section', 'is-layout-flex', 'padding-top:24px', 'flex-basis:40%', 'Contact us' ) as $needle ) { $this->assertStringContainsString( $needle, $html ); }
		preg_match_all( '/data-erankly-field="([^"]+)"/', $html, $matches );
		$this->assertSame( array( 'name', 'email', 'message', 'consent' ), $matches[1] );
		$this->assertSame( 200, $this->submit()->get_status() );
		$this->assertSame( 422, $this->submit( array( 'fields' => array( 'name' => 'Ada' ) ) )->get_status() );
	}
	public function test_global_style_settings_persist_and_share_css_with_both_blocks(): void {
		$this->admin();
		$path = '/erankly/v1/forms/settings';
		$this->assertSame( '#ffffff', $this->request( $path )->get_data()['style_background'] );
		$this->assertSame( ERankly_Forms_Style::css( ERankly_Forms_Style::defaults() ), ERankly_Forms_Style::css( erankly_forms_settings() ) );
		$input = array( 'style_border_width' => '0', 'style_border_color' => '#ABCDEF', 'style_radius' => '20', 'style_padding_vertical' => '18', 'style_background' => '#eef2ff', 'style_gap' => '32' );
		$result = $this->request( $path, 'POST', $input );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 0.0, $result->get_data()['style_border_width'] );
		$this->assertSame( '#abcdef', $result->get_data()['style_border_color'] );
		$this->assertSame( 18.0, $result->get_data()['style_padding_vertical'] );
		$this->assertSame( 200, $this->request( $path, 'POST', array( 'smtp_from_name' => 'Contact' ) )->get_status() );
		$this->assertSame( '#eef2ff', erankly_forms_settings()['style_background'] );
		unregister_block_type( 'easyrankly/form-field' ); unregister_block_type( 'easyrankly/contact-form' );
		wp_deregister_style( 'erankly-forms' ); erankly_forms_register();
		$css = implode( '', wp_styles()->get_data( 'erankly-forms', 'after' ) );
		foreach ( array( '.wp-block-easyrankly-contact-form', '.erankly-form-field-editor', '--erankly-form-border-width:0px;', '--erankly-form-border:#abcdef;', '--erankly-form-radius:20px;', '--erankly-form-padding-vertical:18px;', '--erankly-form-background:#eef2ff;', '--erankly-form-gap:32px;' ) as $value ) { $this->assertStringContainsString( $value, $css ); }
		$this->assertContains( 'erankly-forms', WP_Block_Type_Registry::get_instance()->get_registered( 'easyrankly/form-field' )->editor_style_handles );
		$this->assertContains( 'erankly-forms', WP_Block_Type_Registry::get_instance()->get_registered( 'easyrankly/contact-form' )->style_handles );
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		ob_start(); ERankly_Forms_Admin::render(); $html = ob_get_clean();
		$this->assertStringContainsString( 'erankly-forms-style-preview', $html );
		$this->assertStringContainsString( 'name="style_background" type="color" value="#eef2ff"', $html );
		$this->assertSame( 1, substr_count( $html, '<form ' ), 'The preview must not nest a form in the settings form.' );
		$this->assertSame( 200, $this->request( $path, 'POST', ERankly_Forms_Style::defaults() )->get_status() );
		$this->assertSame( ERankly_Forms_Style::css( ERankly_Forms_Style::defaults() ), ERankly_Forms_Style::css( erankly_forms_settings() ) );
	}
	/** @dataProvider invalid_style_values */
	public function test_invalid_style_settings_are_atomic( string $key, $value ): void {
		$this->admin();
		update_option( 'erankly_forms', array( 'style_background' => '#eef2ff', 'smtp_from_name' => 'Original', 'smtp_password' => 'saved-password' ) );
		$before = get_option( 'erankly_forms' );
		$result = $this->request( '/erankly/v1/forms/settings', 'POST', array( $key => $value, 'smtp_from_name' => 'Changed' ) );
		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'invalid_form_style', $result->get_data()['code'] );
		$this->assertSame( $before, get_option( 'erankly_forms' ) );
	}
	public function invalid_style_values(): array {
		return array( array( 'style_background', 'red' ), array( 'style_border_color', '#fff;}</style><script>alert(1)</script>' ), array( 'style_color', array( '#ffffff' ) ), array( 'style_accent', null ), array( 'style_border_width', -1 ), array( 'style_border_width', 13 ), array( 'style_font_size', 11 ), array( 'style_radius', '2rem' ), array( 'style_radius', true ), array( 'style_gap', '1e2' ), array( 'style_padding_vertical', '' ), array( 'style_padding_horizontal', .333 ) );
	}
	public function test_invalid_stored_styles_fall_back_without_css_injection(): void {
		update_option( 'erankly_forms', array( 'style_background' => '#fff;}</style><script>alert(1)</script>', 'style_radius' => array( 'injection' ) ) );
		$this->assertSame( ERankly_Forms_Style::css( ERankly_Forms_Style::defaults() ), ERankly_Forms_Style::css( erankly_forms_settings() ) );
		$this->assertSame( '#ffffff', ERankly_Forms_Admin::read()->get_data()['style_background'] );
	}
	public function test_legacy_relative_style_values_are_converted_once(): void {
		update_option( 'erankly_forms', array( 'style_radius' => 1.25, 'style_gap' => 2, 'style_padding_vertical' => 1.125, 'style_padding_horizontal' => .875 ) );
		$settings = erankly_forms_settings();
		$this->assertSame( 20.0, $settings['style_radius'] );
		$this->assertSame( 32.0, $settings['style_gap'] );
		$this->assertSame( 18.0, $settings['style_padding_vertical'] );
		$this->assertSame( 14.0, $settings['style_padding_horizontal'] );
		update_option( 'erankly_forms', $settings );
		$this->assertSame( $settings, erankly_forms_settings() );
		$this->assertStringContainsString( '--erankly-form-font-size:16px;', ERankly_Forms_Style::css( $settings ) );
	}
	public function test_mailerlite_key_is_verified_private_preserved_and_removable(): void {
		$this->admin();
		$key = 'test-mailerlite-token-for-unit-tests';
		$id = '123456789012345678901234';
		$this->mock_http( array( 'data' => array( array( 'id' => $id, 'name' => 'Newsletter' ) ), 'links' => array( 'next' => null ) ) );
		$response = $this->request( '/erankly/v1/forms/settings', 'POST', array( 'mailerlite_enabled' => true, 'mailerlite_api_key' => $key ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['mailerlite_api_key_configured'] );
		$this->assertStringNotContainsString( $key, wp_json_encode( $response->get_data() ) );
		$this->assertSame( array( array( 'id' => $id, 'name' => 'Newsletter' ) ), $this->request( '/erankly/v1/forms/mailerlite-groups' )->get_data()['groups'] );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/mailerlite-test', 'POST' )->get_status() );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'mailerlite_api_key' => '', 'style_radius' => 10 ) )->get_status() );
		$this->assertSame( $key, erankly_forms_settings()['mailerlite_api_key'] );
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		ob_start(); ERankly_Forms_Admin::render(); $html = ob_get_clean();
		$this->assertStringNotContainsString( $key, $html );
		$this->assertStringContainsString( 'name="mailerlite_api_key" type="password" value=""', $html );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'clear_mailerlite_api_key' => true, 'mailerlite_api_key' => 'stale invalid key' ) )->get_status() );
		$this->assertSame( '', erankly_forms_settings()['mailerlite_api_key'] );
		$this->assertFalse( erankly_forms_settings()['mailerlite_enabled'] );
	}
	public function test_mailerlite_connection_errors_do_not_replace_saved_settings_or_leak_responses(): void {
		$this->admin();
		update_option( 'erankly_forms', array( 'mailerlite_api_key' => 'original-mailerlite-key', 'smtp_from_name' => 'Original' ) );
		$before = get_option( 'erankly_forms' );
		$this->mock_http( array( 'message' => 'PRIVATE remote credentials' ), 401 );
		$response = $this->request( '/erankly/v1/forms/settings', 'POST', array( 'mailerlite_api_key' => 'replacement-mailerlite-key', 'smtp_from_name' => 'Changed' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $before, get_option( 'erankly_forms' ) );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $response->get_data() ) );
		$this->assertSame( 400, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'mailerlite_enabled' => 'true' ) )->get_status() );
		$this->assertSame( 400, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'mailerlite_api_key' => array( 'bad' ) ) )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( '/erankly/v1/forms/mailerlite-groups' )->get_status() );
		$this->assertSame( 401, $this->request( '/erankly/v1/forms/mailerlite-test', 'POST' )->get_status() );
	}
	private function newsletter_form(): array {
		$content = $this->content() . $this->content( array( array( 'type' => 'newsletter_consent', 'name' => 'newsletter', 'label' => 'Send me the newsletter', 'required' => false ) ) );
		wp_update_post( array( 'ID' => $this->id, 'post_content' => $content ) );
		$meta = array( 'mailerlite' => true, 'mailerlite_group_id' => '123456789012345678901234', 'mailerlite_email_field' => 'email', 'mailerlite_name_field' => 'name', 'mailerlite_consent_field' => 'newsletter' );
		update_post_meta( $this->id, '_erankly_form_settings', $meta );
		update_option( 'erankly_forms', array( 'mailerlite_enabled' => true, 'mailerlite_api_key' => 'test-subscriber-delivery-key' ) );
		return $meta;
	}
	public function test_mailerlite_sends_only_mapped_data_after_explicit_newsletter_consent(): void {
		$meta = $this->newsletter_form();
		$calls = array();
		$capture = static function ( $response, $args, $url ) use ( &$calls ) { $calls[] = array( $url, $args ); return array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'data' => array( 'id' => '12345678901234567890', 'status' => 'unconfirmed' ) ) ), 'headers' => array() ); };
		add_filter( 'pre_http_request', $capture, 20, 3 );
		try {
			$this->assertSame( 200, $this->submit()->get_status() );
			$this->assertCount( 0, $calls, 'Privacy consent alone must not create a subscriber.' );
			$this->assertSame( 422, $this->submit( array( 'fields' => array_merge( $this->values, array( 'newsletter' => 'yes' ) ) ) )->get_status() );
			$this->assertCount( 0, $calls );
			$this->assertSame( 200, $this->submit( array( 'fields' => array_merge( $this->values, array( 'newsletter' => '1' ) ) ) )->get_status() );
			$this->assertCount( 1, $calls );
			$this->assertSame( 'https://connect.mailerlite.com/api/subscribers', $calls[0][0] );
			$args = $calls[0][1];
			$this->assertSame( 'POST', $args['method'] ); $this->assertSame( 0, $args['redirection'] ); $this->assertTrue( $args['sslverify'] );
			$this->assertSame( 'Bearer test-subscriber-delivery-key', $args['headers']['Authorization'] );
			$payload = json_decode( $args['body'], true );
			$this->assertSame( 'ada@example.org', $payload['email'] );
			$this->assertSame( array( $meta['mailerlite_group_id'] ), $payload['groups'] );
			$this->assertSame( array( 'name' => 'Ada' ), $payload['fields'] );
			$this->assertFalse( $payload['resubscribe'] ); $this->assertArrayNotHasKey( 'status', $payload ); $this->assertArrayNotHasKey( 'ip_address', $payload );
			$this->assertSame( array( 'email', 'groups', 'opted_in_at', 'resubscribe', 'fields' ), array_keys( $payload ) );
			$this->assertSame( 200, $this->submit( array( 'fields' => array_merge( $this->values, array( 'newsletter' => '1' ) ), ERankly_Forms_Submission::honeypot( $this->id ) => 'bot' ) )->get_status() );
			$this->assertCount( 1, $calls, 'Spam must not create subscribers.' );
		} finally { remove_filter( 'pre_http_request', $capture, 20 ); }
	}
	public function test_mailerlite_errors_are_reported_even_when_email_succeeds_and_rate_limits_back_off(): void {
		$this->newsletter_form();
		$this->mock_http( array( 'message' => 'Private details' ), 429 );
		$calls = 0; $capture = static function ( $response ) use ( &$calls ) { ++$calls; return $response; }; add_filter( 'pre_http_request', $capture, 20 );
		try {
			$payload = array( 'fields' => array_merge( $this->values, array( 'newsletter' => '1' ) ) );
			$result = $this->submit( $payload );
			$this->assertSame( 503, $result->get_status() );
			$this->assertSame( 'subscription_failed', $result->get_data()['code'] );
			$this->assertSame( 'mailerlite_rate_limited', erankly_forms_delivery_status()['mailerlite']['code'] );
			$this->assertStringNotContainsString( 'Private', wp_json_encode( $result->get_data() ) );
			$this->assertSame( 503, $this->submit( $payload )->get_status() );
			$this->assertSame( 1, $calls, 'Retry-After must suppress repeated API requests.' );
		} finally { remove_filter( 'pre_http_request', $capture, 20 ); }
	}
	public function test_mailerlite_definition_requires_a_dedicated_consent_and_required_email(): void {
		$meta = $this->newsletter_form(); $this->admin();
		$this->assertSame( 200, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'meta' => array( '_erankly_form_settings' => $meta ) ) )->get_status() );
		$meta['mailerlite_consent_field'] = 'consent';
		$this->assertSame( 400, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'meta' => array( '_erankly_form_settings' => $meta ) ) )->get_status() );
		$meta['mailerlite_consent_field'] = 'newsletter'; $meta['mailerlite_email_field'] = 'message';
		$this->assertSame( 400, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'meta' => array( '_erankly_form_settings' => $meta ) ) )->get_status() );
		$html = erankly_forms_render( array( 'ref' => $this->id ) );
		$this->assertMatchesRegularExpression( '/name="newsletter"[^>]*type="checkbox"|type="checkbox"[^>]*name="newsletter"/', $html );
		$this->assertStringNotContainsString( 'test-subscriber-delivery-key', $html );
	}
	public function test_unsupported_nested_blocks_never_render_and_keep_field_order(): void {
		$calls = 0;
		register_block_type( 'erankly-test/side-effect', array( 'render_callback' => static function () use ( &$calls ) { ++$calls; return '<form>Unexpected</form>'; } ) );
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:erankly-test/side-effect --><div class="unsupported">' . $this->content() . '</div><!-- /wp:erankly-test/side-effect --><!-- wp:easyrankly/contact-form {"ref":' . $this->id . '} /--><!-- wp:paragraph --><p>After fields</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		try {
			wp_update_post( array( 'ID' => $this->id, 'post_content' => $content ) );
			$html = erankly_forms_render( array( 'ref' => $this->id ) );
			$this->assertSame( 0, $calls );
			$this->assertSame( 1, substr_count( $html, '<form ' ) );
			$this->assertStringNotContainsString( 'unsupported', $html );
			$this->assertStringContainsString( 'After fields', $html );
			preg_match_all( '/data-erankly-field="([^"]+)"/', $html, $matches );
			$this->assertSame( array_keys( $this->values ), $matches[1] );
		} finally { unregister_block_type( 'erankly-test/side-effect' ); }
	}
	public function test_containers_are_available_only_in_form_definitions(): void {
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( $this->id ) ) );
		$allowed = erankly_forms_allowed_blocks( true, $context );
		foreach ( array( 'core/group', 'core/columns', 'core/column', 'easyrankly/form-field' ) as $block ) { $this->assertContains( $block, $allowed ); }
		$this->assertNotContains( 'easyrankly/contact-form', $allowed );
		$context = new WP_Block_Editor_Context( array( 'post' => get_post( self::factory()->post->create() ) ) );
		$this->assertNotContains( 'easyrankly/form-field', erankly_forms_allowed_blocks( true, $context ) );
		$this->assertFalse( erankly_forms_allowed_blocks( false, $context ) );
	}
	public function test_shortcode_does_not_inherit_parent_block_attributes(): void {
		$id = $this->id;
		register_block_type( 'erankly-test/shortcode-parent', array( 'supports' => array( 'align' => true ), 'render_callback' => static function () use ( $id ) { return erankly_forms_shortcode( array( 'id' => $id ) ); } ) );
		try {
			$html = do_blocks( '<!-- wp:erankly-test/shortcode-parent {"align":"full","className":"parent-only"} /-->' );
			$this->assertMatchesRegularExpression( '/^<form\b[^>]*class="wp-block-easyrankly-contact-form"/', $html );
			$this->assertStringNotContainsString( 'alignfull', $html );
			$this->assertStringNotContainsString( 'parent-only', $html );
			$this->assertStringContainsString( 'data-erankly-field="consent"', $html );
			$this->assertSame( '', erankly_forms_shortcode( array( 'id' => 0 ) ) );
		} finally { unregister_block_type( 'erankly-test/shortcode-parent' ); }
	}
	public function test_draft_and_disabled_forms_unavailable(): void {
		wp_update_post( array( 'ID' => $this->id, 'post_status' => 'draft' ) ); $this->assertSame( '', erankly_forms_render( array( 'ref' => $this->id ) ) ); $this->assertSame( 404, $this->submit()->get_status() );
		erankly_tests_set_settings( array_merge( $this->stored, array( 'enable_forms' => 0 ) ) ); $this->assertSame( '', erankly_forms_render( array( 'ref' => $this->id ) ) );
	}
	public function test_honeypot_success_without_mail_or_error_storage(): void {
		$count = 0; $capture = static function ( $result ) use ( &$count ) { ++$count; return $result; }; add_filter( 'pre_wp_mail', $capture );
		$r = $this->submit( array( ERankly_Forms_Submission::honeypot( $this->id ) => 'bot' ) ); remove_filter( 'pre_wp_mail', $capture );
		$this->assertSame( 200, $r->get_status() ); $this->assertTrue( $r->get_data()['success'] ); $this->assertSame( 0, $count ); $this->assertFalse( get_option( 'erankly_forms_delivery_status' ) );
	}
	public function test_sixth_attempt_rate_limited_before_turnstile(): void {
		for ( $i = 0; $i < 5; $i++ ) { $this->assertSame( 200, $this->submit()->get_status() ); }
		update_option( 'erankly_forms', array( 'turnstile_enabled' => true ) ); $this->assertSame( 429, $this->submit()->get_status() );
		$this->assertArrayNotHasKey( 'ip', get_transient( 'erankly_form_rl_' . wp_hash( $_SERVER['REMOTE_ADDR'] ) ) );
	}
	public function test_json_payload_size_and_field_errors(): void {
		$r = new WP_REST_Request( 'POST', '/erankly/v1/forms/' . $this->id . '/submit' ); $this->assertSame( 415, rest_get_server()->dispatch( $r )->get_status() );
		$this->assertSame( 413, $this->submit( array( 'extra' => str_repeat( 'x', 33000 ) ) )->get_status() ); $this->assertSame( 422, $this->submit( array( 'fields' => array() ) )->get_status() );
	}
	/** @dataProvider turnstile_responses */
	public function test_turnstile_results( array $response, int $expected ): void {
		update_option( 'erankly_forms', array( 'turnstile_enabled' => true, 'turnstile_site_key' => 'site-key', 'turnstile_secret_key' => 'secret' ) );
		if ( ( $response['hostname'] ?? '' ) === 'expected' ) { $response['hostname'] = wp_parse_url( home_url(), PHP_URL_HOST ); }
		if ( ( $response['action'] ?? '' ) === 'expected' ) { $response['action'] = 'erankly_form_' . $this->id; }
		$this->mock_http( $response ); $this->assertSame( $expected, $this->submit( array( 'token' => 'valid-token' ) )->get_status() );
	}
	public function turnstile_responses(): array { return array( array( array( 'success' => true, 'hostname' => 'expected', 'action' => 'expected' ), 200 ), array( array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ), 403 ), array( array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) ), 403 ), array( array( 'success' => true, 'hostname' => 'attacker.org', 'action' => 'expected' ), 403 ), array( array( 'success' => true, 'hostname' => 'expected', 'action' => 'wrong' ), 403 ) ); }
	public function test_turnstile_network_failure_and_oversized_token(): void {
		$s = array( 'turnstile_enabled' => true, 'turnstile_site_key' => 'site-key', 'turnstile_secret_key' => 'secret' ); update_option( 'erankly_forms', $s );
		$this->assertSame( 403, $this->submit( array( 'token' => 'token' ) )->get_status() ); $this->assertWPError( ERankly_Forms_Turnstile::verify( str_repeat( 'x', 2049 ), $this->id, $s ) );
	}
	public function test_email_header_safety_subject_recipients_and_source(): void {
		$this->values['name'] = "Ada\r\nBcc: attacker@example.org"; $this->values['message'] = "First\nSecond";
		update_post_meta( $this->id, '_erankly_form_settings', array( 'subject' => 'New: {message}' ) );
		$args = null; $capture = static function ( $result, $a ) use ( &$args ) { $args = $a; return true; }; add_filter( 'pre_wp_mail', $capture, 20, 2 );
		$this->submit( array( 'source' => 'https://attacker.org/forged' ) ); remove_filter( 'pre_wp_mail', $capture, 20 );
		$this->assertSame( array( get_option( 'admin_email' ) ), $args['to'] ); $this->assertSame( 'New: First Second', $args['subject'] );
		$this->assertStringContainsString( 'ada@example.org', implode( '|', $args['headers'] ) ); $this->assertStringNotContainsString( "\n", implode( '|', $args['headers'] ) ); $this->assertStringNotContainsString( 'From:', implode( '|', $args['headers'] ) ); $this->assertStringNotContainsString( 'attacker.org/forged', $args['message'] );
	}
	public function test_slack_mentions_and_limits(): void {
		$f = $v = array(); for ( $i = 0; $i < 30; $i++ ) { $f[ $i ] = array( 'label' => str_repeat( 'Label', 100 ) ); $v[ $i ] = '<!channel> <@U123> <https://evil.org|Good> & ' . str_repeat( 'é', 5000 ); }
		$p = ERankly_Forms_Slack::payload( str_repeat( 'T', 250 ), $f, $v, 'https://example.org' );
		$this->assertCount( 32, $p['blocks'] ); $this->assertFalse( $p['unfurl_links'] ); $this->assertFalse( $p['unfurl_media'] ); $this->assertLessThanOrEqual( 150, ERankly_Forms_Schema::length( $p['blocks'][0]['text']['text'] ) );
		foreach ( array_slice( $p['blocks'], 1, 30 ) as $block ) { $this->assertLessThanOrEqual( 3000, ERankly_Forms_Schema::length( $block['text']['text'] ) ); $this->assertStringNotContainsString( '<!channel>', $block['text']['text'] ); $this->assertStringContainsString( '&lt;!channel&gt;', $block['text']['text'] ); }
		$this->assertStringNotContainsString( '<@U123>', $p['text'] );
	}
	/** @dataProvider invalid_webhooks */
	public function test_webhook_allowlist( string $url ): void { $this->assertFalse( ERankly_Forms_Slack::valid_url( $url ) ); }
	public function invalid_webhooks(): array { return array( array( 'http://hooks.slack.com/services/T/B/X' ), array( 'https://hooks.slack.com.evil.org/services/T/B/X' ), array( 'https://user@hooks.slack.com/services/T/B/X' ), array( 'https://hooks.slack.com:443/services/T/B/X' ), array( 'https://127.0.0.1/services/T/B/X' ), array( 'https://hooks.slack.com/services/T/B/X?redirect=evil' ) ); }
	/** @dataProvider channel_results */
	public function test_delivery_outcomes( bool $email, bool $slack, int $status ): void {
		remove_filter( 'pre_wp_mail', $this->mail ); $this->mail = $email ? '__return_true' : '__return_false'; add_filter( 'pre_wp_mail', $this->mail );
		update_post_meta( $this->id, '_erankly_form_settings', array( 'slack' => true ) ); update_option( 'erankly_forms', array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X' ) ); $this->mock_http( $slack ? 'ok' : 'error', $slack ? 200 : 500 );
		$this->assertSame( $status, $this->submit()->get_status() ); $errors = get_option( 'erankly_forms_delivery_status', array() );
		$this->assertSame( ! $email, isset( $errors['email'] ) ); $this->assertSame( ! $slack, isset( $errors['slack'] ) ); foreach ( $errors as $error ) { $this->assertSame( array( 'time', 'code' ), array_keys( $error ) ); }
	}
	public function channel_results(): array { return array( array( true, false, 200 ), array( false, true, 200 ), array( false, false, 503 ), array( true, true, 200 ) ); }
	public function test_public_and_editor_access_denied(): void {
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) { wp_set_current_user( $user );
			foreach ( array( '/wp/v2/erankly_form', '/wp/v2/erankly_form/' . $this->id, '/erankly/v1/forms/settings' ) as $path ) { $this->assertContains( $this->request( $path )->get_status(), array( 401, 403 ) ); }
			$this->assertContains( $this->request( '/erankly/v1/forms/settings', 'POST' )->get_status(), array( 401, 403 ) ); $this->assertContains( $this->request( '/erankly/v1/forms/slack-test', 'POST' )->get_status(), array( 401, 403 ) );
		}
	}
	public function test_admin_edit_permissions_and_meta_context(): void {
		$this->admin(); update_post_meta( $this->id, '_erankly_form_settings', array( 'recipients' => array( 'private@example.org' ) ) );
		$this->assertSame( 200, $this->request( '/wp/v2/erankly_form' )->get_status() ); $r = $this->request( '/wp/v2/erankly_form/' . $this->id ); $this->assertStringNotContainsString( 'private@example.org', wp_json_encode( $r->get_data() ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/erankly_form/' . $this->id ); $request['context'] = 'edit'; $this->assertStringContainsString( 'private@example.org', wp_json_encode( rest_get_server()->dispatch( $request )->get_data() ) );
		$this->assertSame( 200, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'title' => 'Updated' ) )->get_status() );
	}
	public function test_delivery_status_expires_old_errors_and_preserves_recent_ones(): void {
		$recent = array( 'time' => time() - 30 * DAY_IN_SECONDS + MINUTE_IN_SECONDS, 'code' => 'slack_failed' );
		update_option( 'erankly_forms_delivery_status', array(
			'email' => array( 'time' => time() - 30 * DAY_IN_SECONDS - 1, 'code' => 'mail_failed' ),
			'slack' => $recent,
			'invalid' => array( 'time' => 'invalid', 'code' => 'invalid' ),
		) );
		$this->assertSame( array( 'slack' => $recent ), erankly_forms_delivery_status() );
		$this->assertSame( array( 'slack' => $recent ), get_option( 'erankly_forms_delivery_status' ) );
		$this->assertSame( array( 'slack' => $recent ), erankly_forms_delivery_status() );
	}
	public function test_delivery_status_deletes_storage_when_all_errors_expire(): void {
		update_option( 'erankly_forms_delivery_status', array( 'email' => array( 'time' => time() - 31 * DAY_IN_SECONDS, 'code' => 'mail_failed' ) ) );
		$this->assertSame( array(), erankly_forms_delivery_status() );
		$this->assertFalse( get_option( 'erankly_forms_delivery_status' ) );
		$this->assertSame( array(), erankly_forms_delivery_status() );
		$this->assertFalse( get_option( 'erankly_forms_delivery_status' ) );
	}
	public function test_new_delivery_error_replaces_previous_error_and_prunes_other_channel(): void {
		update_option( 'erankly_forms_delivery_status', array(
			'email' => array( 'time' => time() - DAY_IN_SECONDS, 'code' => 'old_email_error' ),
			'slack' => array( 'time' => time() - 31 * DAY_IN_SECONDS, 'code' => 'slack_failed' ),
		) );
		erankly_forms_delivery_error( 'email', 'mail_failed' );
		erankly_forms_delivery_error( 'email', 'smtp_failed' );
		$status = get_option( 'erankly_forms_delivery_status' );
		$this->assertSame( array( 'email' ), array_keys( $status ) );
		$this->assertSame( 'smtp_failed', $status['email']['code'] );
		$this->assertGreaterThanOrEqual( time() - MINUTE_IN_SECONDS, $status['email']['time'] );
	}
	public function test_delivery_status_panel_cleans_expired_errors(): void {
		$this->admin();
		update_option( 'erankly_forms_delivery_status', array( 'email' => array( 'time' => time() - 31 * DAY_IN_SECONDS, 'code' => 'expired_mail_error' ) ) );
		require_once ERANKLY_PATH . 'admin/settings/section-links.php';
		ob_start(); ERankly_Forms_Admin::render(); $html = ob_get_clean();
		$this->assertStringNotContainsString( 'expired_mail_error', $html );
		$this->assertStringContainsString( 'No delivery errors recorded.', $html );
		$this->assertStringContainsString( '30 days', $html );
		$this->assertFalse( get_option( 'erankly_forms_delivery_status' ) );
	}
	public function test_secret_privacy_and_blank_preservation(): void {
		$this->admin(); update_option( 'erankly_forms', array( 'turnstile_site_key' => 'public-site-key', 'turnstile_secret_key' => 'SUPERSECRET', 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/PRIVATE' ) );
		$r = $this->request( '/erankly/v1/forms/settings', 'POST', array( 'turnstile_secret_key' => '', 'slack_webhook_url' => '' ) ); $this->assertSame( 200, $r->get_status() ); $json = wp_json_encode( $r->get_data() );
		$this->assertStringNotContainsString( 'SUPERSECRET', $json ); $this->assertStringNotContainsString( 'PRIVATE', $json ); $this->assertSame( 'SUPERSECRET', erankly_forms_settings()['turnstile_secret_key'] );
		$this->assertTrue( $r->get_data()['slack_enabled'] ); $this->assertTrue( $r->get_data()['slack_configured'] );
		$this->assertSame( 'https://hooks.slack.com/services/T/B/PRIVATE', erankly_forms_settings()['slack_webhook_url'] );
		require_once ERANKLY_PATH . 'admin/settings/section-links.php'; ob_start(); ERankly_Forms_Admin::render(); $html = ob_get_clean(); $this->assertStringNotContainsString( 'SUPERSECRET', $html ); $this->assertStringNotContainsString( 'PRIVATE', $html );
	}
	public function test_slack_activation_removal_and_reactivation(): void {
		$this->admin(); $path = '/erankly/v1/forms/settings';
		$this->assertFalse( $this->request( $path )->get_data()['slack_enabled'] );
		$r = $this->request( $path, 'POST', array( 'slack_enabled' => true ) );
		$this->assertSame( 400, $r->get_status() ); $this->assertSame( 'missing_slack_webhook', $r->get_data()['code'] );
		$this->assertSame( 400, $this->request( $path, 'POST', array( 'slack_enabled' => 'false' ) )->get_status() );
		$this->assertSame( 400, $this->request( $path, 'POST', array( 'slack_enabled' => true, 'slack_webhook_url' => 'https://evil.org' ) )->get_status() );
		$webhook = 'https://hooks.slack.com/services/T/B/PRIVATE';
		$r = $this->request( $path, 'POST', array( 'slack_enabled' => true, 'slack_webhook_url' => $webhook ) );
		$this->assertSame( 200, $r->get_status() ); $this->assertTrue( $r->get_data()['slack_enabled'] );
		$this->assertStringNotContainsString( 'PRIVATE', wp_json_encode( $r->get_data() ) );
		$this->assertSame( 200, $this->request( $path, 'POST', array( 'slack_enabled' => true, 'slack_webhook_url' => '' ) )->get_status() );
		$this->assertSame( $webhook, erankly_forms_settings()['slack_webhook_url'] );
		$this->assertSame( 200, $this->request( $path, 'POST', array( 'smtp_from_name' => 'Forms' ) )->get_status() );
		$r = $this->request( $path, 'POST', array( 'slack_enabled' => false, 'slack_webhook_url' => 'stale invalid webhook' ) );
		$this->assertSame( 200, $r->get_status() ); $this->assertFalse( $r->get_data()['slack_enabled'] ); $this->assertFalse( $r->get_data()['slack_configured'] );
		$this->assertSame( '', erankly_forms_settings()['slack_webhook_url'] );
		$this->assertSame( 'Forms', erankly_forms_settings()['smtp_from_name'] );
		$this->assertSame( 400, $this->request( $path, 'POST', array( 'slack_enabled' => true ) )->get_status() );
		$this->assertSame( 200, $this->request( $path, 'POST', array( 'slack_enabled' => true, 'slack_webhook_url' => $webhook ) )->get_status() );
		$this->assertSame( $webhook, erankly_forms_settings()['slack_webhook_url'] );
	}
	public function test_disabled_slack_skips_delivery_and_keeps_form_preferences(): void {
		$this->admin();
		update_option( 'erankly_forms', array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/PRIVATE' ) );
		update_post_meta( $this->id, '_erankly_form_settings', array( 'slack' => true ) );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'slack_enabled' => false ) )->get_status() );
		$this->assertTrue( erankly_form_settings( $this->id )['slack'] );
		$requests = 0;
		$capture = static function ( $result ) use ( &$requests ) { ++$requests; return $result; };
		add_filter( 'pre_http_request', $capture, 20 ); wp_set_current_user( 0 );
		try { $this->assertSame( 200, $this->submit()->get_status() ); } finally { remove_filter( 'pre_http_request', $capture, 20 ); }
		$this->assertSame( 0, $requests );
		$this->assertArrayNotHasKey( 'slack', get_option( 'erankly_forms_delivery_status', array() ) );
	}
	private function smtp_config(): array {
		return array( 'smtp_enabled' => true, 'smtp_host' => 'smtp.example.org', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_auth' => true, 'smtp_username' => 'mailer@example.org', 'smtp_password' => '  Secret\\"<&é  ', 'smtp_from_email' => 'forms@example.org', 'smtp_from_name' => 'Contact forms' );
	}
	public function test_smtp_settings_password_privacy_preservation_and_removal(): void {
		$this->admin(); $config = $this->smtp_config();
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', $config )->get_status() );
		$this->assertSame( $config['smtp_password'], erankly_forms_settings()['smtp_password'] );
		$r = $this->request( '/erankly/v1/forms/settings' );
		$this->assertArrayNotHasKey( 'smtp_password', $r->get_data() ); $this->assertTrue( $r->get_data()['smtp_password_configured'] );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'smtp_password' => '', 'smtp_port' => '465', 'smtp_encryption' => 'ssl' ) )->get_status() );
		$this->assertSame( $config['smtp_password'], erankly_forms_settings()['smtp_password'] ); $this->assertSame( 465, erankly_forms_settings()['smtp_port'] );
		require_once ERANKLY_PATH . 'admin/settings/section-links.php'; ob_start(); ERankly_Forms_Admin::render(); $html = ob_get_clean();
		$this->assertStringNotContainsString( esc_attr( $config['smtp_password'] ), $html ); $this->assertStringContainsString( 'name="smtp_password" type="password" value=""', $html );
		$this->assertSame( 400, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'clear_smtp_password' => true ) )->get_status() );
		$this->assertSame( $config['smtp_password'], erankly_forms_settings()['smtp_password'] );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'smtp_enabled' => false, 'clear_smtp_password' => true ) )->get_status() );
		$this->assertSame( '', erankly_forms_settings()['smtp_password'] );
	}
	/** @dataProvider invalid_smtp_settings */
	public function test_invalid_smtp_settings_are_atomic( array $input ): void {
		$this->admin(); update_option( 'erankly_forms', $this->smtp_config() ); $previous = get_option( 'erankly_forms' );
		$this->assertSame( 400, $this->request( '/erankly/v1/forms/settings', 'POST', $input )->get_status() );
		$this->assertSame( $previous, get_option( 'erankly_forms' ) );
	}
	public function invalid_smtp_settings(): array {
		return array_map( static function ( $input ) { return array( $input ); }, array(
			array( 'smtp_host' => 'tls://smtp.example.org' ), array( 'smtp_host' => 'smtp.example.org;evil.org' ), array( 'smtp_host' => 'smtp.example.org:25' ), array( 'smtp_host' => "smtp.example.org\r\n" ), array( 'smtp_host' => array() ), array( 'smtp_host' => '' ),
			array( 'smtp_port' => 0 ), array( 'smtp_port' => 65536 ), array( 'smtp_port' => -25 ), array( 'smtp_port' => '25xyz' ), array( 'smtp_port' => true ), array( 'smtp_port' => 587.5 ), array( 'smtp_port' => null ),
			array( 'smtp_encryption' => 'invalid' ), array( 'smtp_from_email' => 'invalid' ), array( 'smtp_from_email' => "forms@example.org\r\nBcc: other@example.org" ), array( 'smtp_username' => '' ), array( 'smtp_password' => "secret\0injected" ), array( 'smtp_password' => str_repeat( 'x', 513 ) ), array( 'smtp_enabled' => 'false' ), array( 'smtp_auth' => array() ), array( 'clear_smtp_password' => 'yes' ),
		) );
	}
	public function test_smtp_without_authentication_and_host_formats(): void {
		$this->admin();
		foreach ( array( 'localhost', '192.0.2.1', '2001:db8::1', 'smtp.example.org' ) as $host ) {
			$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'smtp_enabled' => true, 'smtp_host' => $host, 'smtp_auth' => false, 'smtp_encryption' => '', 'smtp_from_email' => 'forms@example.org' ) )->get_status() );
		}
	}
	/** @dataProvider smtp_transports */
	public function test_smtp_delivery_is_scoped_and_preserves_reply_to( string $encryption, bool $auth ): void {
		$config = array_merge( $this->smtp_config(), array( 'smtp_encryption' => $encryption, 'smtp_auth' => $auth ) ); update_option( 'erankly_forms', $config );
		remove_filter( 'pre_wp_mail', $this->mail );
		$original = $GLOBALS['phpmailer']; $GLOBALS['phpmailer'] = new MockPHPMailer( true );
		$other = static function ( $mailer ): void { $mailer->isSMTP(); $mailer->Host = 'global.example.org'; $mailer->Username = 'global-user'; $mailer->Password = 'global-password'; $mailer->SMTPDebug = 2; };
		add_action( 'phpmailer_init', $other );
		$sent = array(); $capture = static function () use ( &$sent ): void {
			$mailer = $GLOBALS['phpmailer']; $sent[] = array( 'host' => $mailer->Host, 'port' => $mailer->Port, 'encryption' => $mailer->SMTPSecure, 'auto_tls' => $mailer->SMTPAutoTLS, 'auth' => $mailer->SMTPAuth, 'username' => $mailer->Username, 'password' => $mailer->Password, 'from' => $mailer->From, 'sender' => $mailer->Sender, 'reply' => $mailer->getReplyToAddresses(), 'debug' => $mailer->SMTPDebug, 'ssl' => $mailer->SMTPOptions['ssl'] ?? array() );
		}; add_action( 'wp_mail_succeeded', $capture );
		try {
			$this->assertSame( 200, $this->submit()->get_status() );
			$this->assertSame( 'smtp.example.org', $sent[0]['host'] ); $this->assertSame( 587, $sent[0]['port'] ); $this->assertSame( $encryption, $sent[0]['encryption'] ); $this->assertSame( '' !== $encryption, $sent[0]['auto_tls'] ); $this->assertSame( $auth, $sent[0]['auth'] );
			$this->assertSame( $auth ? $config['smtp_username'] : '', $sent[0]['username'] ); $this->assertSame( $auth ? $config['smtp_password'] : '', $sent[0]['password'] );
			$this->assertSame( 'forms@example.org', $sent[0]['from'] ); $this->assertSame( 'forms@example.org', $sent[0]['sender'] ); $this->assertContains( array( 'ada@example.org', 'Ada' ), $sent[0]['reply'] ); $this->assertSame( 0, $sent[0]['debug'] );
			$this->assertSame( array( 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false ), $sent[0]['ssl'] );
			$this->assertSame( 'global.example.org', $GLOBALS['phpmailer']->Host ); $this->assertSame( 'global-password', $GLOBALS['phpmailer']->Password ); $this->assertSame( 2, $GLOBALS['phpmailer']->SMTPDebug );
			$this->assertTrue( wp_mail( 'other@example.org', 'Unrelated WordPress email', 'Other body' ) ); $this->assertSame( 'global.example.org', $sent[1]['host'] );
		} finally { remove_action( 'phpmailer_init', $other ); remove_action( 'wp_mail_succeeded', $capture ); $GLOBALS['phpmailer'] = $original; }
	}
	public function smtp_transports(): array { return array( array( 'tls', true ), array( 'ssl', true ), array( '', false ) ); }
	public function test_smtp_cleanup_on_exception_and_incomplete_configuration(): void {
		update_option( 'erankly_forms', $this->smtp_config() ); remove_filter( 'pre_wp_mail', $this->mail );
		$original = $GLOBALS['phpmailer']; $GLOBALS['phpmailer'] = new MockPHPMailer( true ); $GLOBALS['phpmailer']->Password = 'previous-password';
		$throw = static function (): void { throw new RuntimeException( 'Secret diagnostics must not escape' ); }; add_action( 'wp_mail_succeeded', $throw );
		try {
			$this->assertSame( 503, $this->submit()->get_status() ); $this->assertSame( 'previous-password', $GLOBALS['phpmailer']->Password );
			$this->assertSame( 'mail_failed', get_option( 'erankly_forms_delivery_status' )['email']['code'] );
		} finally { remove_action( 'wp_mail_succeeded', $throw ); $GLOBALS['phpmailer'] = $original; }
		update_option( 'erankly_forms', array( 'smtp_enabled' => true ) ); $this->assertSame( 503, $this->submit()->get_status() );
	}
	public function test_smtp_test_endpoint_permissions_and_throttle(): void {
		foreach ( array( 0, self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) { wp_set_current_user( $user ); $this->assertContains( $this->request( '/erankly/v1/forms/smtp-test', 'POST' )->get_status(), array( 401, 403 ) ); }
		$this->admin(); update_option( 'erankly_forms', $this->smtp_config() ); $args = null;
		$capture = static function ( $result, $a ) use ( &$args ) { $args = $a; return true; }; add_filter( 'pre_wp_mail', $capture, 20, 2 );
		try { $this->assertSame( 200, $this->request( '/erankly/v1/forms/smtp-test', 'POST' )->get_status() ); $this->assertSame( array( get_option( 'admin_email' ) ), $args['to'] ); $this->assertSame( 429, $this->request( '/erankly/v1/forms/smtp-test', 'POST' )->get_status() ); }
		finally { remove_filter( 'pre_wp_mail', $capture, 20 ); }
		delete_transient( 'erankly_forms_smtp_throttle' ); remove_filter( 'pre_wp_mail', $this->mail ); add_filter( 'pre_wp_mail', '__return_false' );
		try { $this->assertSame( 503, $this->request( '/erankly/v1/forms/smtp-test', 'POST' )->get_status() ); }
		finally { remove_filter( 'pre_wp_mail', '__return_false' ); }
	}
	public function test_settings_validation_and_secret_probe(): void {
		$this->admin(); $path = '/erankly/v1/forms/settings';
		$this->assertSame( 400, $this->request( $path, 'POST', array( 'turnstile_enabled' => true ) )->get_status() ); $this->mock_http( array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) ) );
		$this->assertSame( 400, $this->request( $path, 'POST', array( 'turnstile_secret_key' => 'invalid-secret' ) )->get_status() ); $this->assertSame( 400, $this->request( $path, 'POST', array( 'slack_webhook_url' => 'https://evil.org' ) )->get_status() );
		$this->mock_http( array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) ); $this->assertSame( 200, $this->request( $path, 'POST', array( 'turnstile_site_key' => 'valid-site-key', 'turnstile_secret_key' => 'valid-secret', 'turnstile_enabled' => true ) )->get_status() );
	}
	public function test_disabling_turnstile_removes_secret_and_ignores_hidden_keys(): void {
		$this->admin();
		update_option( 'erankly_forms', array( 'turnstile_enabled' => true, 'turnstile_site_key' => 'valid-site-key', 'turnstile_secret_key' => 'saved-secret', 'smtp_password' => 'saved-password' ) );
		$r = $this->request( '/erankly/v1/forms/settings', 'POST', array( 'turnstile_enabled' => false, 'turnstile_site_key' => 'invalid key', 'turnstile_secret_key' => 'invalid secret' ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertFalse( $r->get_data()['turnstile_enabled'] );
		$this->assertFalse( $r->get_data()['turnstile_secret_configured'] );
		$this->assertSame( '', erankly_forms_settings()['turnstile_secret_key'] );
		$this->assertSame( 'valid-site-key', erankly_forms_settings()['turnstile_site_key'] );
		$this->assertSame( 'saved-password', erankly_forms_settings()['smtp_password'] );
		$this->assertSame( 400, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'turnstile_enabled' => true ) )->get_status() );
		$this->assertFalse( erankly_forms_settings()['turnstile_enabled'] );
		$this->mock_http( array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) ) );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'turnstile_enabled' => true, 'turnstile_secret_key' => 'replacement-secret' ) )->get_status() );
		$this->assertSame( 'replacement-secret', erankly_forms_settings()['turnstile_secret_key'] );
	}
	public function test_unrelated_settings_do_not_remove_turnstile_secret(): void {
		$this->admin();
		update_option( 'erankly_forms', array( 'turnstile_enabled' => true, 'turnstile_site_key' => 'valid-site-key', 'turnstile_secret_key' => 'saved-secret' ) );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/settings', 'POST', array( 'smtp_from_name' => 'Contact forms' ) )->get_status() );
		$this->assertSame( 'saved-secret', erankly_forms_settings()['turnstile_secret_key'] );
		$this->assertTrue( erankly_forms_settings()['turnstile_enabled'] );
	}
	public function test_uninstall_forms_revisions_options_and_transients(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', true ); } if ( ! defined( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY' ) ) { define( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY', true ); } require_once ERANKLY_PATH . 'uninstall.php';
		update_post_meta( $this->id, '_erankly_form_settings', array( 'slack' => true ) ); $revision = _wp_put_post_revision( $this->id ); update_option( 'erankly_forms', array( 'turnstile_secret_key' => 'secret' ) ); ERankly_Forms_Submission::rate_limit();
		erankly_uninstall_delete_forms(); erankly_uninstall_delete_options(); $this->assertNull( get_post( $this->id ) ); $this->assertNull( get_post( $revision ) ); $this->assertFalse( get_option( 'erankly_forms' ) ); $this->assertFalse( get_transient( 'erankly_form_rl_' . wp_hash( $_SERVER['REMOTE_ADDR'] ) ) );
	}
	public function test_multisite_per_site_configuration(): void {
		if ( ! is_multisite() ) { $this->markTestSkipped( 'Requires Multisite.' ); }
		update_option( 'erankly_forms', array( 'turnstile_secret_key' => 'site-one', 'smtp_password' => 'smtp-one', 'style_background' => '#aabbcc' ) ); $site = self::factory()->blog->create(); switch_to_blog( $site );
		try { $this->assertSame( '', erankly_forms_settings()['turnstile_secret_key'] ); $this->assertSame( '', erankly_forms_settings()['smtp_password'] ); $this->assertSame( '#ffffff', erankly_forms_settings()['style_background'] ); update_option( 'erankly_forms', array( 'turnstile_secret_key' => 'site-two', 'smtp_password' => 'smtp-two', 'style_background' => '#ddeeff' ) ); $this->assertStringContainsString( '--erankly-form-background:#ddeeff;', ERankly_Forms_Style::css( erankly_forms_settings() ) ); $this->assertTrue( erankly_forms_enabled() ); } finally { restore_current_blog(); }
		$this->assertSame( 'site-one', erankly_forms_settings()['turnstile_secret_key'] ); $this->assertSame( 'smtp-one', erankly_forms_settings()['smtp_password'] );
		$this->assertSame( '#aabbcc', erankly_forms_settings()['style_background'] );
	}
	public function test_published_definition_cannot_save_duplicate_names(): void {
		$this->admin();
		$bad = $this->content( array( array( 'name' => 'duplicate' ), array( 'name' => 'duplicate' ) ) );
		$this->assertSame( 400, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'content' => $bad ) )->get_status() );
		$this->assertNotSame( $bad, get_post( $this->id )->post_content );
	}
	public function test_slack_test_endpoint_and_throttle(): void {
		$this->admin(); update_option( 'erankly_forms', array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X' ) ); $this->mock_http( 'ok' );
		$this->assertSame( 200, $this->request( '/erankly/v1/forms/slack-test', 'POST' )->get_status() );
		$this->assertSame( 503, $this->request( '/erankly/v1/forms/slack-test', 'POST' )->get_status() );
		$this->assertSame( 'slack_rate_limited', get_option( 'erankly_forms_delivery_status' )['slack']['code'] );
	}
	public function test_module_tab_is_site_scoped_and_not_network_scoped(): void {
		erankly_tests_load_settings_sanitizer(); $this->admin();
		$tabs = ERankly_Forms_Admin::tabs( array() );
		$this->assertArrayHasKey( 'forms', erankly_normalize_settings_tabs( $tabs, array( 'scope' => 'site' ) ) );
		$this->assertArrayNotHasKey( 'forms', erankly_normalize_settings_tabs( $tabs, array( 'scope' => 'network' ) ) );
	}
	public function test_test_keys_cannot_bypass_production_validation(): void {
		$old = getenv( 'WP_ENVIRONMENT_TYPE' ); putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$s = array( 'turnstile_site_key' => '1x00000000000000000000AA', 'turnstile_secret_key' => ERankly_Forms_Turnstile::TEST_SECRETS[0] );
		$this->mock_http( array( 'success' => true, 'hostname' => 'example.com', 'metadata' => array( 'result_with_testing_key' => true ) ) );
		try { $this->assertWPError( ERankly_Forms_Turnstile::verify( 'XXXX.DUMMY.TOKEN.XXXX', $this->id, $s ) ); }
		finally { putenv( false === $old ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $old ); }
	}

	public function test_admin_can_save_form_settings_and_invalid_recipients_rejected(): void {
		$this->admin();
		$meta = array( 'recipients' => array( 'owner@example.org' ), 'slack' => true, 'submit_label' => 'Submit', 'success_message' => 'Received', 'subject' => 'New {name}' );
		$r = $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'meta' => array( '_erankly_form_settings' => $meta ) ) );
		$this->assertSame( 200, $r->get_status() ); $this->assertSame( erankly_forms_sanitize_meta( $meta ), get_post_meta( $this->id, '_erankly_form_settings', true ) );
		$this->assertSame( 400, $this->request( '/wp/v2/erankly_form/' . $this->id, 'POST', array( 'meta' => array( '_erankly_form_settings' => array( 'recipients' => array( 'invalid' ) ) ) ) )->get_status() );
	}

}
