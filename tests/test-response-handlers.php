<?php
/**
 * Request-terminating handlers, tested through the extracted builders that do
 * not call `exit`. The thin wrappers still emit headers and exit in production.
 */

final class ERankly_Response_Handlers_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		erankly_load_default_helpers();
		erankly_load_sitemap_helpers();
		require_once ERANKLY_PATH . 'includes/sitemap/core.php';
		require_once ERANKLY_PATH . 'includes/sitemap/news.php';
		require_once ERANKLY_PATH . 'includes/sitemap/image.php';
		require_once ERANKLY_PATH . 'includes/sitemap/video.php';
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	public function test_send_feed_robots_header_returns_early_when_the_setting_is_off(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 0 ) );
		erankly_clear_settings_cache();

		$this->go_to( get_feed_link() );

		$this->assertTrue( is_feed() );
		$this->assertSame( '', erankly_feed_robots_tag() );

		erankly_send_feed_robots_header();
	}

	public function test_send_feed_robots_header_returns_early_outside_a_feed_request(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 1 ) );
		erankly_clear_settings_cache();

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( is_feed() );
		$this->assertSame( '', erankly_feed_robots_tag() );

		erankly_send_feed_robots_header();
	}

	public function test_send_feed_robots_header_is_wired_to_template_redirect(): void {
		$this->assertNotFalse( has_action( 'template_redirect', 'erankly_send_feed_robots_header' ) );
	}

	public function test_send_feed_robots_header_is_safe_to_call_twice(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 1 ) );
		erankly_clear_settings_cache();

		$this->go_to( get_feed_link() );

		$this->assertTrue( is_feed() );
		$this->assertSame( 'noindex, follow', erankly_feed_robots_tag() );
		$this->assertSame( 'noindex, follow', erankly_feed_robots_tag() );

		erankly_send_feed_robots_header();
		erankly_send_feed_robots_header();
	}

	public function test_prepare_xml_response_rejects_unsafe_or_invalid_documents(): void {
		$valid = '<?xml version="1.0"?><urlset></urlset>';

		$this->assertSame( 500, erankly_prepare_xml_response( $valid, 'text/plain' )['status'] );
		$this->assertSame( 500, erankly_prepare_xml_response( '', 'application/xml' )['status'] );
		$this->assertSame( 500, erankly_prepare_xml_response( '<?xml version="1.0"?><!DOCTYPE foo><urlset></urlset>', 'application/xml' )['status'] );
		$this->assertSame( 500, erankly_prepare_xml_response( '<?xml version="1.0"?><urlset><!ENTITY xxe SYSTEM "http://x"></urlset>', 'application/xml' )['status'] );
		$this->assertSame( 500, erankly_prepare_xml_response( '<not-xml', 'application/xml' )['status'] );
	}

	public function test_prepare_xml_response_accepts_valid_xml_and_honours_etag(): void {
		$xml      = '<?xml version="1.0"?><urlset></urlset>';
		$prepared = erankly_prepare_xml_response( $xml, 'application/xml' );

		$this->assertSame( 200, $prepared['status'] );
		$this->assertSame( $xml, $prepared['body'] );
		$this->assertInstanceOf( DOMDocument::class, $prepared['document'] );
		$this->assertSame( 'noindex, follow', $prepared['headers']['X-Robots-Tag'] );
		$this->assertArrayHasKey( 'ETag', $prepared['headers'] );
		$this->assertStringContainsString( 'application/xml', $prepared['headers']['Content-Type'] );

		$_SERVER['HTTP_IF_NONE_MATCH'] = $prepared['headers']['ETag'];
		$cached                        = erankly_prepare_xml_response( $xml, 'application/xml' );

		$this->assertSame( 304, $cached['status'] );
		$this->assertSame( '', $cached['body'] );
		$this->assertSame( $prepared['headers']['ETag'], $cached['headers']['ETag'] );
	}
}
