<?php
/** Open Graph and Twitter card output resolution. */

final class ERankly_Opengraph_Output_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
	}

	public function tear_down(): void {
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	private function make_post( array $meta = array(), string $content = '', string $title = 'Probe post', string $excerpt = '' ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);

		$this->assertIsInt( $post_id );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, '_erankly_' . $key, $value );
		}

		return (int) $post_id;
	}

	public function test_simplified_social_title_prefers_the_seo_title_meta(): void {
		$with_meta    = $this->make_post( array( 'title' => 'Curated SEO title' ), '', 'Fallback post title' );
		$without_meta = $this->make_post( array(), '', 'Plain post title' );

		$this->assertSame( 'Curated SEO title', erankly_get_simplified_social_title( $with_meta ) );
		$this->assertSame( 'Plain post title', erankly_get_simplified_social_title( $without_meta ) );
	}

	public function test_simplified_social_description_falls_back_to_excerpt_then_content(): void {
		$with_meta    = $this->make_post( array( 'description' => 'Curated description' ), '<p>Body copy.</p>' );
		$with_excerpt = $this->make_post( array(), '<p>Body copy.</p>', 'Excerpt post', 'The hand written excerpt' );
		$from_content = $this->make_post( array(), '<p>Hello <strong>world</strong>.</p>' );

		$this->assertSame( 'Curated description', erankly_get_simplified_social_description( $with_meta ) );
		$this->assertSame( 'The hand written excerpt', erankly_get_simplified_social_description( $with_excerpt ) );
		$this->assertSame( 'Hello world.', erankly_get_simplified_social_description( $from_content ) );
	}

	/**
	 * The variable resolver memoises per key+post, and the SQLite test rollback
	 * rewinds post IDs, so a shared process can read another test's cached title.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_og_title_expands_variables_from_per_post_meta(): void {
		$post_id = $this->make_post( array( 'og_title' => '{{post_title}} rocks' ), '', 'Widgets' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		$this->assertSame( 'Widgets rocks', erankly_get_og_title() );
	}

	public function test_og_description_prefers_per_post_meta(): void {
		$post_id = $this->make_post( array( 'og_description' => 'Custom Open Graph description' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Custom Open Graph description', erankly_get_og_description() );
	}

	public function test_twitter_title_and_description_use_their_own_meta(): void {
		$post_id = $this->make_post(
			array(
				'twitter_title'       => 'X card title',
				'twitter_description' => 'X card description',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'X card title', erankly_get_twitter_title() );
		$this->assertSame( 'X card description', erankly_get_twitter_description() );
	}

	public function test_social_text_falls_back_to_the_global_default_setting(): void {
		// simplified_mode off so the per-object automatic value does not win.
		erankly_update_plugin_settings(
			array(
				'simplified_mode'     => 0,
				'default_og_title'    => 'Global Open Graph title',
				'default_twitter_title' => 'Global X title',
			)
		);
		erankly_clear_settings_cache();

		$post_id = $this->make_post( array(), '', 'Ignored post title' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Global Open Graph title', erankly_get_og_title() );
		$this->assertSame( 'Global X title', erankly_get_twitter_title() );
	}

	public function test_social_text_is_trimmed_to_the_limit(): void {
		$long = str_repeat( 'a', 120 );
		$post_id = $this->make_post( array( 'twitter_title' => $long ) );
		$this->go_to( get_permalink( $post_id ) );

		$title = erankly_get_twitter_title();

		$this->assertLessThanOrEqual( 70, strlen( $title ) );
		// erankly_trim_text keeps limit - 1 characters when it has to cut.
		$this->assertSame( str_repeat( 'a', 69 ), $title );
	}

	public function test_twitter_card_type_defaults_and_degrades_without_an_image(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'summary_large_image', erankly_get_twitter_card_type( 'https://example.test/image.jpg' ) );
		// No image to build a large card from: fall back to the plain summary card.
		$this->assertSame( 'summary', erankly_get_twitter_card_type( '' ) );
	}

	public function test_twitter_card_type_honours_an_explicit_post_meta(): void {
		$post_id = $this->make_post( array( 'twitter_card_type' => 'summary' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'summary', erankly_get_twitter_card_type( 'https://example.test/image.jpg' ) );
	}

	public function test_twitter_site_normalizes_the_handle(): void {
		erankly_update_plugin_settings( array( 'twitter_site' => 'mypage' ) );
		erankly_clear_settings_cache();
		$this->assertSame( '@mypage', erankly_get_twitter_site() );

		erankly_update_plugin_settings( array( 'twitter_site' => '@other' ) );
		erankly_clear_settings_cache();
		$this->assertSame( '@other', erankly_get_twitter_site() );

		erankly_update_plugin_settings( array( 'twitter_site' => '' ) );
		erankly_clear_settings_cache();
		$this->assertSame( '', erankly_get_twitter_site() );
	}

	public function test_twitter_image_prefers_per_post_meta_then_argument(): void {
		$post_id = $this->make_post( array( 'twitter_image_url' => 'https://example.test/twitter.jpg' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'https://example.test/twitter.jpg', erankly_get_twitter_image( 'https://example.test/fallback.jpg' ) );

		delete_post_meta( $post_id, '_erankly_twitter_image_url' );
		$this->assertSame( 'https://example.test/fallback.jpg', erankly_get_twitter_image( 'https://example.test/fallback.jpg' ) );
	}

	public function test_social_image_alt_prefers_per_post_meta_then_argument(): void {
		$post_id = $this->make_post( array( 'og_image_alt' => 'Per post alt text' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Per post alt text', erankly_get_social_image_alt( 'og', 'fallback alt', '' ) );

		delete_post_meta( $post_id, '_erankly_og_image_alt' );
		$this->assertSame( 'fallback alt', erankly_get_social_image_alt( 'og', 'fallback alt', '' ) );
	}

	public function test_twitter_image_alt_inherits_the_shared_alt_only_when_images_match(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_singular() );
		$this->assertSame(
			'Shared social alt',
			erankly_get_twitter_image_alt( 'https://example.test/same.jpg', 'https://example.test/same.jpg', 'Shared social alt' )
		);
		// A distinct X image must not inherit the Open Graph alt text.
		$this->assertSame(
			'',
			erankly_get_twitter_image_alt( 'https://example.test/twitter.jpg', 'https://example.test/og.jpg', 'Shared social alt' )
		);
	}

	public function test_special_page_social_image_is_empty_outside_special_pages(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( '', erankly_get_special_page_social_image() );
	}

	public function test_special_page_social_image_resolves_the_search_row(): void {
		$filter = static function ( array $map, string $setting_key ): array {
			if ( 'global_special_meta' === $setting_key ) {
				$map['search'] = array_merge(
					isset( $map['search'] ) && is_array( $map['search'] ) ? $map['search'] : array(),
					array( 'social_image_url' => 'https://example.test/search-social.jpg' )
				);
			}

			return $map;
		};
		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		try {
			$this->go_to( home_url( '/?s=probe' ) );
			$this->assertTrue( is_search() );
			$this->assertSame( 'search', erankly_current_special_page_key() );
			$this->assertSame( 'https://example.test/search-social.jpg', erankly_get_special_page_social_image() );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_og_image_prefers_the_per_post_url(): void {
		$post_id = $this->make_post( array( 'og_image_url' => 'https://example.test/explicit-og.jpg' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'https://example.test/explicit-og.jpg', erankly_get_og_image() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_og_image_falls_back_to_the_default_social_image_setting(): void {
		erankly_update_plugin_settings( array( 'default_social_image_url' => 'https://example.test/default-social.jpg' ) );
		erankly_clear_settings_cache();

		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'https://example.test/default-social.jpg', erankly_get_og_image() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_opengraph_tags_emits_property_and_name_attributes(): void {
		erankly_update_plugin_settings( array( 'twitter_site' => 'testsite' ) );
		erankly_clear_settings_cache();

		$post_id = $this->make_post(
			array(
				'og_title'       => 'OG title',
				'og_description' => 'OG description',
				'og_image_url'   => 'https://example.test/social.jpg',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		erankly_render_opengraph_tags();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'property="og:title" content="OG title"', $output );
		$this->assertStringContainsString( 'property="og:description" content="OG description"', $output );
		$this->assertStringContainsString( 'property="og:type" content="article"', $output );
		$this->assertStringContainsString( 'property="og:image" content="https://example.test/social.jpg"', $output );
		$this->assertStringContainsString( 'property="og:locale" content="en_US"', $output );
		$this->assertStringContainsString( 'name="twitter:card" content="summary_large_image"', $output );
		$this->assertStringContainsString( 'name="twitter:site" content="@testsite"', $output );

		// Open Graph uses property=, Twitter uses name=.
		$this->assertStringNotContainsString( 'name="og:title"', $output );
		$this->assertStringNotContainsString( 'property="twitter:card"', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_render_oembed_link_emits_a_json_discovery_link(): void {
		$post_id = $this->make_post();
		$this->go_to( get_permalink( $post_id ) );

		ob_start();
		erankly_render_oembed_link();
		$output = (string) ob_get_clean();

		$decoded = urldecode( html_entity_decode( $output ) );

		$this->assertStringContainsString( '<link rel="alternate" type="application/json+oembed"', $output );
		$this->assertStringContainsString( 'oembed/1.0/embed', $decoded );
		// The discovery link points at this page's canonical URL.
		$this->assertStringContainsString( 'url=http://example.org/?p=' . $post_id, $decoded );
		// Exactly one discovery link is emitted, not the native JSON+XML pair as well.
		$this->assertSame( 1, substr_count( $output, 'application/json+oembed' ) );
	}
}
