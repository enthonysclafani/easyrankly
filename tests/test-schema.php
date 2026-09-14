<?php
/** Schema.org validation, REST meta, merge, modes, and builder regressions. */

final class ERankly_Schema_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// WP_UnitTestCase::tear_down() calls unregister_all_meta_keys(), which drops the meta the
		// plugin registered once on `init`. Without this the REST responses lose
		// _erankly_schema_blocks from the second test in the run onwards.
		erankly_register_meta();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
		require_once ERANKLY_PATH . 'includes/schema.php';
	}

	public function test_custom_json_ld_rejects_plain_json_objects(): void {
		$result = erankly_validate_custom_json_ld( '{"foo":"bar"}' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'semantic', $result['code'] );
	}

	public function test_registered_meta_matches_the_keys_each_object_type_consumes(): void {
		$post_keys = array_keys( get_registered_meta_keys( 'post' ) );
		$term_keys = array_keys( get_registered_meta_keys( 'term' ) );
		$user_keys = array_keys( get_registered_meta_keys( 'user' ) );

		// Post-only fields must not be exposed for terms or users: no reader or writer
		// touches them there (see erankly_importable_meta_keys()).
		foreach ( array( '_erankly_schema_mode', '_erankly_schema_blocks', '_erankly_primary_terms', '_erankly_breadcrumb_name' ) as $post_only_key ) {
			$this->assertContains( $post_only_key, $post_keys );
			$this->assertNotContains( $post_only_key, $term_keys );
			$this->assertNotContains( $post_only_key, $user_keys );
		}

		// The shared fields and the legacy robots booleans stay registered everywhere
		// they are written at runtime.
		$this->assertContains( '_erankly_title', $term_keys );
		$this->assertContains( '_erankly_title', $user_keys );
		$this->assertContains( '_erankly_disable_sitemap', $term_keys );
		$this->assertContains( '_erankly_noindex', $term_keys );
	}

	public function test_addon_meta_keys_register_on_terms_and_can_opt_into_users(): void {
		$filter = static function ( array $keys ): array {
			$keys['_erankly_addon_probe'] = 'string';
			return $keys;
		};
		$register_users = static function ( array $keys, string $object_type ): array {
			if ( 'user' === $object_type ) {
				$keys['_erankly_addon_probe'] = 'string';
			}
			return $keys;
		};

		add_filter( 'erankly_meta_keys', $filter );
		add_filter( 'erankly_registered_meta_keys', $register_users, 10, 2 );
		erankly_register_meta();

		try {
			$this->assertArrayHasKey( '_erankly_addon_probe', erankly_importable_meta_keys( 'term' ) );
			$this->assertArrayNotHasKey( '_erankly_addon_probe', erankly_importable_meta_keys( 'user' ) );
			$this->assertContains( '_erankly_addon_probe', array_keys( get_registered_meta_keys( 'term' ) ) );
			$this->assertContains( '_erankly_addon_probe', array_keys( get_registered_meta_keys( 'user' ) ) );
			$this->assertContains( '_erankly_addon_probe', array_keys( get_registered_meta_keys( 'post' ) ) );
		} finally {
			remove_filter( 'erankly_meta_keys', $filter );
			remove_filter( 'erankly_registered_meta_keys', $register_users, 10 );
		}
	}

	public function test_addon_term_meta_payload_is_importable_and_sanitized(): void {
		$filter = static function ( array $keys ): array {
			$keys['_erankly_addon_probe'] = 'string';
			return $keys;
		};
		$sanitize = static function ( $value, string $key ) {
			if ( '_erankly_addon_probe' !== $key ) {
				return $value;
			}

			return sanitize_text_field( (string) $value );
		};

		add_filter( 'erankly_meta_keys', $filter );
		add_filter( 'erankly_sanitize_extension_meta', $sanitize, 10, 2 );

		try {
			$this->assertArrayHasKey( '_erankly_addon_probe', erankly_importable_meta_keys( 'term' ) );
			$this->assertArrayNotHasKey( '_erankly_addon_probe', erankly_importable_meta_keys( 'user' ) );
			$this->assertArrayNotHasKey( '_erankly_schema_blocks', erankly_importable_meta_keys( 'term' ) );
			$this->assertSame( 'hello', erankly_sanitize_registered_meta( " hello \n", '_erankly_addon_probe' ) );
		} finally {
			remove_filter( 'erankly_meta_keys', $filter );
			remove_filter( 'erankly_sanitize_extension_meta', $sanitize, 10 );
		}
	}

	public function test_rest_term_and_user_meta_expose_typed_defaults_for_absent_values(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertFalse( (bool) get_term_meta( $term_id, '_erankly_noindex', true ) );
		$this->assertSame( '', (string) get_term_meta( $term_id, '_erankly_title', true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, '_erankly_title', true ) );

		$term_request = new WP_REST_Request( 'GET', '/wp/v2/categories/' . $term_id );
		$term_request->set_param( 'context', 'edit' );
		$term_response = rest_get_server()->dispatch( $term_request );
		$term_data     = $term_response->get_data();

		$this->assertSame( 200, $term_response->get_status() );
		$this->assertIsArray( $term_data['meta'] ?? null );
		$this->assertArrayHasKey( '_erankly_title', $term_data['meta'] );

		$user_request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $user_id );
		$user_request->set_param( 'context', 'edit' );
		$user_response = rest_get_server()->dispatch( $user_request );
		$user_data     = $user_response->get_data();

		$this->assertSame( 200, $user_response->get_status() );
		if ( isset( $user_data['meta'] ) && is_array( $user_data['meta'] ) ) {
			$this->assertArrayNotHasKey( '_erankly_schema_blocks', $user_data['meta'] );
		}
	}

	public function test_schema_blogposting_alias_still_emits_blogposting_type(): void {
		$post_id = self::factory()->post->create();
		$schema  = erankly_schema_blogposting( $post_id );

		$this->assertSame( 'BlogPosting', $schema['@type'] );
	}

	public function test_meta_registration_args_set_typed_defaults(): void {
		$boolean = erankly_meta_registration_args( '_erankly_noindex', 'boolean', '__return_true' );
		$integer = erankly_meta_registration_args( '_erankly_og_image_id', 'integer', '__return_true' );
		$object  = erankly_meta_registration_args( '_erankly_primary_terms', 'object', '__return_true' );
		$array   = erankly_meta_registration_args( '_erankly_schema_blocks', 'array', '__return_true' );
		$string  = erankly_meta_registration_args( '_erankly_title', 'string', '__return_true' );

		$this->assertFalse( $boolean['default'] );
		$this->assertSame( 0, $integer['default'] );
		$this->assertSame( array(), $object['default'] );
		$this->assertSame( array(), $array['default'] );
		$this->assertArrayNotHasKey( 'default', $string );
	}

	public function test_custom_json_ld_rejects_syntax_errors(): void {
		$result = erankly_validate_custom_json_ld( '{not json' );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'syntax', $result['code'] );
	}

	public function test_custom_json_ld_accepts_type_or_id_and_graph(): void {
		$this->assertTrue( erankly_validate_custom_json_ld( '{"@type":"Thing","name":"Test"}' )['valid'] );
		$this->assertTrue( erankly_validate_custom_json_ld( '{"@id":"https://example.com/#x"}' )['valid'] );
		$this->assertTrue(
			erankly_validate_custom_json_ld( '{"@graph":[{"@type":"Thing","name":"A"},{"@id":"#b"}]}' )['valid']
		);
	}

	public function test_custom_json_ld_rejects_empty_objects_and_mixed_arrays(): void {
		$this->assertFalse( erankly_validate_custom_json_ld( '{}' )['valid'] );
		$this->assertFalse( erankly_validate_custom_json_ld( '[{},{"@type":"Thing"}]' )['valid'] );
		$this->assertFalse( erankly_validate_custom_json_ld( '{"@graph":["Thing"]}' )['valid'] );
	}

	public function test_schema_blocks_rest_schema_declares_type_and_fields(): void {
		$schema = erankly_schema_blocks_rest_schema();

		$this->assertSame( 'array', $schema['type'] );
		$this->assertSame( array(), $schema['default'] );
		$this->assertSame( 'object', $schema['items']['type'] );
		$this->assertArrayHasKey( 'type', $schema['items']['properties'] );
		$this->assertArrayHasKey( 'fields', $schema['items']['properties'] );
		$this->assertSame( 'string', $schema['items']['properties']['fields']['properties']['custom_json']['type'] );
		$this->assertTrue( $schema['items']['additionalProperties'] );
	}

	public function test_rest_round_trip_never_returns_null_schema_blocks(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Schema REST',
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'meta' => array(
						'_erankly_schema_blocks' => array(
							array(
								'type'   => 'custom',
								'fields' => array(
									'custom_json' => '{"@type":"Thing","name":"Test"}',
								),
							),
						),
					),
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['meta']['_erankly_schema_blocks'] );
		$this->assertNotNull( $data['meta']['_erankly_schema_blocks'] );
		$this->assertSame( 'Thing', json_decode( $data['meta']['_erankly_schema_blocks'][0]['fields']['custom_json'], true )['@type'] );

		$get = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
		$get->set_param( 'context', 'edit' );
		$got = rest_do_request( $get );
		$this->assertSame( 200, $got->get_status() );
		$payload = $got->get_data();
		$this->assertIsArray( $payload['meta']['_erankly_schema_blocks'] );
		$this->assertCount( 1, $payload['meta']['_erankly_schema_blocks'] );
	}

	public function test_rest_empty_array_is_not_null(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$get     = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$get->set_param( 'context', 'edit' );
		$payload = rest_do_request( $get )->get_data();

		$this->assertIsArray( $payload['meta']['_erankly_schema_blocks'] );
		$this->assertSame( array(), $payload['meta']['_erankly_schema_blocks'] );
	}

	public function test_invalid_json_keeps_previous_valid_block(): void {
		$previous = array(
			array(
				'type'   => 'custom',
				'fields' => array(
					'custom_json' => '{"@type":"Thing","name":"Kept"}',
				),
			),
		);
		$clean    = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => '{not json',
					),
				),
			),
			false,
			$previous
		);

		$this->assertSame( 'Kept', json_decode( $clean[0]['fields']['custom_json'], true )['name'] );
	}

	public function test_valid_json_clears_stale_invalid_notice(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_transient( 'erankly_invalid_json_ld_' . $user_id, 'Stale error', 5 * MINUTE_IN_SECONDS );

		$clean = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => '{"@type":"Thing","name":"Valid"}',
					),
				),
			)
		);

		$this->assertCount( 1, $clean );
		$this->assertFalse( get_transient( 'erankly_invalid_json_ld_' . $user_id ) );
	}

	public function test_xss_payload_is_kept_as_json_string(): void {
		$json  = '{"@type":"Thing","name":"</script><script>alert(1)</script>"}';
		$clean = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'   => 'custom',
					'fields' => array(
						'custom_json' => $json,
					),
				),
			)
		);

		$this->assertStringContainsString( '</script>', $clean[0]['fields']['custom_json'] );
		$encoded = wp_json_encode(
			array( '@graph' => erankly_decode_custom_json_ld( $clean[0]['fields']['custom_json'] ) ),
			JSON_HEX_TAG
		);
		$this->assertStringNotContainsString( '</script>', $encoded );
	}

	public function test_same_id_nodes_merge_with_custom_overlay(): void {
		$graph = erankly_dedupe_schema_graph(
			array(
				array(
					'@type' => 'Organization',
					'@id'   => 'https://example.test/#organization',
					'name'  => 'Auto',
					'url'   => 'https://example.test/',
				),
				array(
					'@type'       => 'Organization',
					'@id'         => 'https://example.test/#organization',
					'description' => 'Custom description',
				),
			)
		);

		$this->assertCount( 1, $graph );
		$this->assertSame( 'Auto', $graph[0]['name'] );
		$this->assertSame( 'Custom description', $graph[0]['description'] );
	}

	public function test_merge_unions_types_and_dedupes_identical_nodes_without_id(): void {
		$graph = erankly_dedupe_schema_graph(
			array(
				array( '@type' => 'Person', 'name' => 'A' ),
				array( '@type' => 'Person', 'name' => 'A' ),
				array(
					'@type' => 'Person',
					'@id'   => '#p',
					'name'  => 'Base',
				),
				array(
					'@type' => 'Organization',
					'@id'   => '#p',
					'url'   => 'https://example.test/',
				),
			)
		);

		$this->assertCount( 2, $graph );
		$this->assertContains( 'Person', (array) $graph[1]['@type'] );
		$this->assertContains( 'Organization', (array) $graph[1]['@type'] );
		$this->assertSame( 'https://example.test/', $graph[1]['url'] );
	}

	public function test_event_datetime_rejects_invalid_values(): void {
		$this->assertSame( '', erankly_schema_event_datetime( 'not-a-date' ) );
		$this->assertNotSame( '', erankly_schema_event_datetime( '2026-09-06 10:00:00' ) );
	}

	public function test_event_finalize_requires_start_date_and_drops_earlier_end(): void {
		$this->assertSame( array(), erankly_schema_event_finalize( array( '@type' => 'Event' ) ) );
		$this->assertSame(
			array(),
			erankly_schema_event_finalize(
				array(
					'@type'     => 'Event',
					'startDate' => '2026-09-06T10:00:00+00:00',
				)
			)
		);

		$schema = erankly_schema_event_finalize(
			array(
				'@type'     => 'Event',
				'startDate' => '2026-09-06T10:00:00+00:00',
				'endDate'   => '2026-09-05T10:00:00+00:00',
				'location'  => array(
					'@type' => 'Place',
					'name'  => 'Venue',
				),
			)
		);

		$this->assertArrayNotHasKey( 'endDate', $schema );
		$this->assertSame( 'Venue', $schema['location']['name'] );
	}

	public function test_event_virtual_location_requires_url(): void {
		$this->assertSame(
			array(),
			erankly_schema_event_finalize(
				array(
					'@type'     => 'Event',
					'startDate' => '2026-09-06T10:00:00+00:00',
					'location'  => array(
						'@type' => 'VirtualLocation',
					),
				)
			)
		);

		$schema = erankly_schema_event_finalize(
			array(
				'@type'     => 'Event',
				'startDate' => '2026-09-06T10:00:00+00:00',
				'location'  => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.test/live',
				),
			)
		);

		$this->assertSame( 'VirtualLocation', $schema['location']['@type'] );
	}

	public function test_merge_conflict_surfaces_a_warning(): void {
		erankly_clear_schema_merge_warnings();
		erankly_merge_schema_nodes(
			array(
				'@id'  => '#org',
				'name' => array(
					'alternate' => 'Auto',
				),
			),
			array(
				'@id'  => '#org',
				'name' => 'Custom',
			)
		);

		$this->assertNotEmpty( erankly_get_schema_merge_warnings() );
		$this->assertNotEmpty( erankly_schema_merge_warning_messages() );
	}

	public function test_howto_from_html_emits_steps(): void {
		$html   = '<strong class="schema-how-to-step-name">Mix</strong><p class="schema-how-to-step-text">Stir the batter.</p>';
		$schema = erankly_schema_howto_from_html( $html, 0 );

		$this->assertSame( 'HowTo', $schema['@type'] );
		$this->assertSame( 'Mix', $schema['step'][0]['name'] );
		$this->assertSame( 'Stir the batter.', $schema['step'][0]['text'] );
	}

	public function test_faq_keeps_long_visible_text(): void {
		$question = str_repeat( 'Q', 180 );
		$answer   = str_repeat( 'A', 800 );
		$items    = array(
			array(
				'question' => $question,
				'answer'   => $answer,
			),
		);

		add_filter(
			'erankly_faq_items',
			static function () use ( $items ) {
				return $items;
			},
			20
		);

		$schema = erankly_schema_faq( 1 );
		remove_all_filters( 'erankly_faq_items' );
		add_filter( 'erankly_faq_items', 'erankly_faq_items_from_content', 10, 2 );

		$this->assertSame( $question, $schema['mainEntity'][0]['name'] );
		$this->assertSame( $answer, $schema['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_qapage_is_sanitized_to_webpage(): void {
		$this->assertSame( 'WebPage', erankly_sanitize_schema_type_name( 'QAPage' ) );
		$this->assertArrayNotHasKey( 'QAPage', erankly_get_webpage_schema_types() );
	}

	public function test_search_action_is_off_by_default(): void {
		$defaults = erankly_default_settings();

		$this->assertSame( 0, (int) $defaults['enable_website_search_action'] );
		$this->assertSame( 'when_visible', $defaults['breadcrumb_jsonld_mode'] );
	}

	public function test_disabled_types_are_case_insensitive_and_unique(): void {
		$list = erankly_sanitize_schema_type_list( array( 'Article', 'article', 'FAQPage', 'faqpage' ) );

		$this->assertCount( 2, $list );
	}

	public function test_global_block_without_context_is_saved_disabled(): void {
		$blocks = erankly_sanitize_schema_blocks(
			array(
				array(
					'type'            => 'custom',
					'enabled'         => 1,
					'target_contexts' => array(),
					'fields'          => array(
						'custom_json' => '{"@type":"Thing","name":"Global"}',
					),
				),
			),
			true
		);

		$this->assertSame( 0, (int) $blocks[0]['enabled'] );
	}

	public function test_local_business_gaps_include_page_and_address(): void {
		$gaps = erankly_local_business_requirement_gaps(
			array(
				'organization_name'            => '',
				'organization_street_address'  => '',
				'organization_locality'        => '',
				'organization_postal_code'     => '',
				'organization_country'         => '',
				'local_business_pages'         => array(),
				'local_business_page_path'     => '',
			)
		);

		$this->assertNotEmpty( $gaps );
	}

	public function test_variables_survive_placeholder_probe(): void {
		$json = '{"@type":"WebPage","@id":"{{post_url}}#custom","name":"{{post_title}}","publisher":{"@id":"{{schema_identity_id}}"}}';

		$this->assertTrue( erankly_validate_custom_json_ld( $json )['valid'] );
	}

	public function test_video_names_are_unique_for_multiple_videos(): void {
		$this->assertSame( 'Title', erankly_schema_video_name( 'Title', 0, 1 ) );
		$this->assertNotSame(
			erankly_schema_video_name( 'Title', 0, 2 ),
			erankly_schema_video_name( 'Title', 1, 2 )
		);
	}
}
