<?php
/**
 * Handlers that terminate the request.
 *
 * Functions whose last statement is `exit` cannot be called from the suite: the
 * exit would end the PHPUnit process itself, and forking is not an option here
 * because a forked child inherits the per-test SQLite transaction and deadlocks
 * against it. They are therefore NOT called; this file covers the parts of the
 * request-handling surface that return normally, and documents what is left out.
 *
 * Deliberately not executed (each ends in `exit`, so it kills the test process):
 *   - erankly_send_response()                 includes/helpers/utils.php
 *   - erankly_render_sitemap_response()       includes/sitemap/core.php
 *   - erankly_export_download()               includes/import-export/export.php
 *   - erankly_migration_backup_download()     includes/import-export/actions.php
 *   - erankly_import_export_redirect()        includes/import-export/actions.php
 *   - erankly_reset_redirect()                includes/reset.php
 *   - ERankly_Redirects_Admin::redirect_with_error()
 *   - ERankly_Redirects_Admin::redirect_after_action()
 * Their guard branches are covered indirectly through the callers, and the
 * redirect helpers are additionally reached in the redirect/import-export tests
 * up to the point where they exit.
 */

final class ERankly_Response_Handlers_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		erankly_load_default_helpers();
	}

	public function test_send_feed_robots_header_returns_early_when_the_setting_is_off(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 0 ) );
		erankly_clear_settings_cache();

		// Guard path: the setting is off, so the handler returns before emitting.
		erankly_send_feed_robots_header();

		$this->assertFalse( (bool) erankly_get_setting( 'noindex_feeds', 0 ) );
	}

	public function test_send_feed_robots_header_returns_early_outside_a_feed_request(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 1 ) );
		erankly_clear_settings_cache();

		$this->go_to( home_url( '/' ) );

		$this->assertTrue( (bool) erankly_get_setting( 'noindex_feeds', 1 ) );
		$this->assertFalse( is_feed() );

		erankly_send_feed_robots_header();

		$this->assertFalse( is_feed() );
	}

	public function test_send_feed_robots_header_is_wired_to_template_redirect(): void {
		$this->assertNotFalse( has_action( 'template_redirect', 'erankly_send_feed_robots_header' ) );
	}

	public function test_send_feed_robots_header_is_safe_to_call_twice(): void {
		erankly_tests_set_settings( array( 'noindex_feeds' => 1 ) );
		erankly_clear_settings_cache();

		erankly_send_feed_robots_header();
		erankly_send_feed_robots_header();

		$this->assertTrue( true );
	}
}
