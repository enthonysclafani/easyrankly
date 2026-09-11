<?php
/** Runtime content defaults: identity/logo resolution, placeholders and special-page keys. */

final class ERankly_Helpers_Content_Defaults_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
	}

	/**
	 * Stores settings and drops the request-level caches so the next read sees them.
	 *
	 * @param array<string,mixed> $settings Partial settings map.
	 */
	private function store_settings( array $settings ): void {
		erankly_tests_set_settings( $settings );
		erankly_clear_settings_cache();
	}

	public function test_placeholder_helpers_return_the_documented_examples(): void {
		$this->assertSame( 'https://example.com/social-image.jpg', erankly_default_social_image_placeholder() );
		$this->assertSame( 'https://example.com/logo.png', erankly_default_organization_logo_placeholder() );
	}

	public function test_default_templates_reference_site_variables(): void {
		$this->assertSame( '{{site_name}}', erankly_default_organization_name_template() );
		$this->assertSame( '{{site_name}}', erankly_default_website_name_template() );
		$this->assertSame( '{{site_description}}', erankly_default_website_description_template() );
		$this->assertSame( '{{site_icon_url}}', erankly_default_organization_logo_url_template() );
	}

	public function test_get_site_icon_url_returns_empty_without_a_configured_icon(): void {
		$this->assertSame( '', erankly_get_site_icon_url() );
	}

	public function test_get_site_icon_url_returns_the_configured_icon(): void {
		$filename      = '2026/09/erankly-icon-' . wp_generate_password( 8, false ) . '.png';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Site icon probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$filename
		);
		$this->assertIsInt( $attachment_id );

		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => $filename,
				'width'  => 512,
				'height' => 512,
			)
		);
		update_option( 'site_icon', $attachment_id );

		$url = erankly_get_site_icon_url();

		$this->assertNotSame( '', $url );
		$this->assertStringContainsString( 'erankly-icon-', $url );
	}

	public function test_get_organization_name_falls_back_to_the_site_name(): void {
		$this->store_settings( array() );

		$this->assertSame( get_bloginfo( 'name' ), erankly_get_organization_name() );
	}

	// The variable resolver keeps a per-request static cache, so these tests assert
	// against the live site values instead of mutating blogname/blogdescription:
	// a mutation would be masked by an already-resolved variable.

	public function test_get_organization_name_expands_the_site_name_variable(): void {
		$this->store_settings( array( 'organization_name' => '{{site_name}} Srl' ) );

		$this->assertSame( get_bloginfo( 'name' ) . ' Srl', erankly_get_organization_name() );
	}

	public function test_get_organization_name_uses_an_explicit_value(): void {
		$this->store_settings( array( 'organization_name' => 'Acme Publishing' ) );

		$this->assertSame( 'Acme Publishing', erankly_get_organization_name() );
	}

	public function test_get_website_name_expands_the_site_name_variable(): void {
		$this->store_settings( array( 'website_name' => '{{site_name}} Blog' ) );

		$this->assertSame( get_bloginfo( 'name' ) . ' Blog', erankly_get_website_name() );
	}

	public function test_get_website_name_does_not_recurse_into_itself(): void {
		$this->store_settings( array( 'website_name' => '{{website_name}} Blog' ) );

		// Self-reference is excluded from resolution, so the token collapses to an
		// empty string and never loops.
		$this->assertSame( ' Blog', erankly_get_website_name() );
	}

	public function test_get_website_description_expands_the_tagline_variable(): void {
		$this->store_settings( array( 'website_description' => '{{site_description}}' ) );

		$this->assertSame( get_bloginfo( 'description' ), erankly_get_website_description() );
	}

	public function test_get_website_description_is_trimmed(): void {
		$this->store_settings( array( 'website_description' => '  spazi  ' ) );

		$this->assertSame( 'spazi', erankly_get_website_description() );
	}

	public function test_get_organization_logo_url_prefers_the_explicit_url_setting(): void {
		$this->store_settings( array( 'organization_logo_url' => 'https://cdn.example.org/logo.svg' ) );

		$this->assertSame( 'https://cdn.example.org/logo.svg', erankly_get_organization_logo_url() );
	}

	public function test_get_organization_logo_url_expands_the_site_icon_variable(): void {
		$filename      = '2026/09/erankly-logo-' . wp_generate_password( 8, false ) . '.png';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Logo probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$filename
		);
		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata( $attachment_id, array( 'file' => $filename ) );
		update_option( 'site_icon', $attachment_id );

		$this->store_settings( array( 'organization_logo_url' => '{{site_icon_url}}' ) );

		$this->assertNotSame( '', erankly_get_organization_logo_url() );
		$this->assertSame( erankly_get_site_icon_url(), erankly_get_organization_logo_url() );
	}

	public function test_get_organization_logo_url_falls_back_to_the_attachment_setting(): void {
		$filename      = '2026/09/erankly-logo-attach-' . wp_generate_password( 8, false ) . '.png';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Logo attachment probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$filename
		);
		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata( $attachment_id, array( 'file' => $filename ) );

		$this->store_settings(
			array(
				'organization_logo_url' => '',
				'organization_logo'     => $attachment_id,
			)
		);

		$url = erankly_get_organization_logo_url();

		$this->assertNotSame( '', $url );
		$this->assertSame( erankly_get_image_url( $attachment_id, 'full' ), $url );
	}

	public function test_get_organization_logo_url_is_empty_when_nothing_is_configured(): void {
		$this->store_settings(
			array(
				'organization_logo_url' => '',
				'organization_logo'     => 0,
			)
		);

		$this->assertSame( '', erankly_get_organization_logo_url() );
	}

	public function test_schema_organization_logo_is_empty_without_a_logo(): void {
		$this->store_settings(
			array(
				'organization_logo_url' => '',
				'organization_logo'     => 0,
			)
		);

		$this->assertSame( array(), erankly_schema_organization_logo() );
	}

	public function test_schema_organization_logo_carries_the_attachment_dimensions(): void {
		$filename      = '2026/09/erankly-logo-dim-' . wp_generate_password( 8, false ) . '.png';
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'Logo dimensions probe',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$filename
		);
		update_attached_file( $attachment_id, $filename );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => $filename,
				'width'  => 600,
				'height' => 60,
			)
		);

		$this->store_settings(
			array(
				'organization_logo_url' => '',
				'organization_logo'     => $attachment_id,
			)
		);

		$logo = erankly_schema_organization_logo();

		$this->assertSame( 'ImageObject', $logo['@type'] );
		$this->assertSame( home_url( '/#organization-logo' ), $logo['@id'] );
		$this->assertSame( 600, $logo['width'] );
		$this->assertSame( 60, $logo['height'] );
	}

	public function test_schema_organization_logo_omits_dimensions_for_a_url_only_logo(): void {
		$this->store_settings(
			array(
				'organization_logo_url' => 'https://cdn.example.org/logo.svg',
				'organization_logo'     => 0,
			)
		);

		$logo = erankly_schema_organization_logo();

		$this->assertSame( 'https://cdn.example.org/logo.svg', $logo['url'] );
		$this->assertArrayNotHasKey( 'width', $logo );
		$this->assertArrayNotHasKey( 'height', $logo );
	}

	public function test_special_page_keys_returns_every_supported_entity(): void {
		$keys = erankly_special_page_keys();

		// PHP casts the numeric string key '404' to the integer 404.
		$this->assertSame(
			array( 'homepage', 'blog', 'author', 'date', 'search', 404 ),
			array_keys( $keys )
		);
		$this->assertSame( 'Homepage', $keys['homepage'] );
		$this->assertSame( '404 page', $keys[404] );
	}

	public function test_special_page_keys_can_skip_translation(): void {
		$keys = erankly_special_page_keys( false );

		$this->assertSame( 'Homepage', $keys['homepage'] );
		$this->assertSame( 'Blog page', $keys['blog'] );
	}

	public function test_special_page_keys_is_filterable(): void {
		$filter = static function ( array $keys ): array {
			$keys['custom'] = 'Custom entity';
			return $keys;
		};

		add_filter( 'erankly_special_pages', $filter );

		try {
			$this->assertSame( 'Custom entity', erankly_special_page_keys()['custom'] );
		} finally {
			remove_filter( 'erankly_special_pages', $filter );
		}
	}

	public function test_current_special_page_key_detects_the_search_request(): void {
		$this->go_to( home_url( '/?s=termine' ) );

		$this->assertSame( 'search', erankly_current_special_page_key() );
	}

	public function test_current_special_page_key_detects_the_404_request(): void {
		// A missing post ID reaches a 404 even with plain permalinks, where a
		// missing path would resolve to the home page instead.
		$this->go_to( home_url( '/?p=99999999' ) );

		$this->assertTrue( is_404() );
		$this->assertSame( '404', erankly_current_special_page_key() );
	}

	public function test_current_special_page_key_treats_a_missing_slug_as_the_homepage_with_plain_permalinks(): void {
		$this->go_to( home_url( '/erankly-no-such-page-' . wp_generate_password( 6, false ) . '/' ) );

		$this->assertFalse( is_404() );
		$this->assertSame( 'homepage', erankly_current_special_page_key() );
	}

	public function test_current_special_page_key_detects_the_author_archive(): void {
		$user_id = self::factory()->user->create( array( 'user_nicename' => 'erankly-author' ) );
		$this->assertIsInt( $user_id );

		$this->go_to( get_author_posts_url( $user_id ) );

		$this->assertTrue( is_author() );
		$this->assertSame( 'author', erankly_current_special_page_key() );
	}

	public function test_current_special_page_key_returns_empty_on_a_singular_post(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		$this->assertSame( '', erankly_current_special_page_key() );
	}
}
