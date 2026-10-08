<?php
/**
 * Tests for custom code snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\CustomCode;

use EasyRankly\CustomCode\CustomCode;
use EasyRankly\CustomCode\Runner;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Snippets: who may write them, PHP checked before saving, turned off at the first error.
 */
final class CustomCodeTest extends WP_UnitTestCase {

	/**
	 * Meta re-registered (the test case unregisters it after each test), an administrator logged in.
	 */
	public function set_up(): void {
		parent::set_up();
		( new CustomCode() )->register_post_type();
		$this->login_admin();
	}

	/**
	 * Logs in an administrator, super admin on multisite.
	 *
	 * @return int User ID.
	 */
	private function login_admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Sends a REST request to the snippets endpoint.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $body   JSON body.
	 * @param string               $route  Route below /wp/v2/easyrankly-snippets.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, array $body = array(), string $route = '' ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/wp/v2/easyrankly-snippets' . (string) strtok( $route, '?' ) );
		if ( str_contains( $route, '?force=true' ) ) {
			$request->set_param( 'force', true );
		}
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	/**
	 * Body of a snippet.
	 *
	 * @param string $type     html or php.
	 * @param string $code     Code.
	 * @param string $status   publish or draft.
	 * @param string $position Position.
	 * @param int    $priority Priority.
	 * @return array<string, mixed>
	 */
	private function body( string $type, string $code, string $status = 'publish', string $position = 'head', int $priority = 10 ): array {
		return array(
			'title'   => $type . ' snippet',
			'content' => $code,
			'status'  => $status,
			'meta'    => array(
				CustomCode::meta_key( 'type' )     => $type,
				CustomCode::meta_key( 'position' ) => $position,
				CustomCode::meta_key( 'priority' ) => $priority,
			),
		);
	}

	/**
	 * Output of a position.
	 *
	 * @param string $position Position.
	 * @return string
	 */
	private function output( string $position ): string {
		ob_start();
		( new Runner() )->run( $position );

		return (string) ob_get_clean();
	}

	/**
	 * PHP is parsed, never run, to find syntax errors.
	 */
	public function test_syntax_error(): void {
		$this->assertNull( CustomCode::syntax_error( 'echo 1;' ) );
		$this->assertStringContainsString( 'line 2', (string) CustomCode::syntax_error( "echo 1;\necho (;" ) );
		$this->assertSame( "\necho 1;", CustomCode::strip_open_tag( "<?php\necho 1;" ) );
	}

	/**
	 * Active snippets print by priority in their position; inactive ones do not.
	 */
	public function test_html_snippets_print_by_priority(): void {
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'html', '<meta name="b">', 'publish', 'head', 20 ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'html', '<meta name="a">', 'publish', 'head', 5 ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'html', '<meta name="off">', 'draft' ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'html', '<p>end</p>', 'publish', 'footer' ) )->get_status() );

		$this->assertSame( "<meta name=\"a\">\n<meta name=\"b\">\n", $this->output( 'head' ) );
		$this->assertSame( "<p>end</p>\n", $this->output( 'footer' ) );
		$this->assertSame( '', $this->output( 'body_open' ) );
	}

	/**
	 * PHP snippets run; a syntax error can be saved only as inactive.
	 */
	public function test_php_snippets(): void {
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'php', "<?php\necho 'from php';", 'publish', 'footer' ) )->get_status() );
		$this->assertSame( 'from php', $this->output( 'footer' ) );

		$broken = $this->rest( 'POST', $this->body( 'php', 'echo (;' ) );
		$this->assertSame( 400, $broken->get_status() );
		$this->assertSame( 'easyrankly_snippet_syntax', $broken->get_data()['code'] );

		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'php', 'echo (;', 'draft' ) )->get_status() );
	}

	/**
	 * A runtime error turns the snippet off, keeps its code intact and is shown to administrators.
	 */
	public function test_runtime_error_disables_snippet(): void {
		$code = "echo '<b>before</b>';\nthrow new \\RuntimeException( 'boom' );";
		$id   = $this->rest( 'POST', $this->body( 'php', $code, 'publish', 'footer' ) )->get_data()['id'];

		// A visitor triggers the error: the content filters must not strip the code when it is turned off.
		wp_set_current_user( 0 );
		kses_init();
		$this->assertSame( '<b>before</b>', $this->output( 'footer' ) );
		kses_remove_filters();

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( $code, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringContainsString( 'boom', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
		$this->assertSame( '', $this->output( 'footer' ) );

		$this->login_admin();
		ob_start();
		( new CustomCode() )->error_notice();
		$this->assertStringContainsString( 'php snippet', (string) ob_get_clean() );

		// Saving it again clears the error.
		$this->rest( 'POST', array( 'content' => "echo 'fixed';" ), '/' . $id );
		$this->assertSame( '', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
	}

	/**
	 * Without edit_plugins nobody writes or deletes PHP snippets, and types never change.
	 */
	public function test_php_needs_edit_plugins(): void {
		$php  = $this->rest( 'POST', $this->body( 'php', 'echo 1;' ) )->get_data()['id'];
		$html = $this->rest( 'POST', $this->body( 'html', '<i></i>' ) )->get_data()['id'];

		$this->assertSame( 400, $this->rest( 'POST', array( 'meta' => array( CustomCode::meta_key( 'type' ) => 'php' ) ), '/' . $html )->get_status() );

		$user = wp_get_current_user();
		$user->add_cap( 'edit_plugins', false );
		if ( is_multisite() ) {
			revoke_super_admin( $user->ID );
			$user->add_cap( 'unfiltered_html', true );
			$this->markTestSkipped( 'On multisite edit_plugins and unfiltered_html belong to super admins only.' );
		}

		$this->assertContains( $this->rest( 'POST', $this->body( 'php', 'echo 2;' ) )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'POST', array( 'content' => 'echo 3;' ), '/' . $php )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'DELETE', array(), '/' . $php . '?force=true' )->get_status(), array( 401, 403 ) );
		$this->assertSame( 200, $this->rest( 'POST', array( 'content' => '<b></b>' ), '/' . $html )->get_status() );
	}

	/**
	 * Without manage_options and unfiltered_html snippets cannot be read, listed or written.
	 */
	public function test_permissions(): void {
		$id = $this->rest( 'POST', $this->body( 'html', '<i></i>' ) )->get_data()['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertContains( $this->rest( 'GET' )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'GET', array(), '/' . $id )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->rest( 'POST', $this->body( 'html', '<i></i>' ) )->get_status(), array( 401, 403 ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', array(), '/' . $id )->get_status() );

		if ( is_multisite() ) {
			// A site administrator who is not a super admin has no unfiltered_html.
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertContains( $this->rest( 'POST', $this->body( 'html', '<i></i>' ) )->get_status(), array( 401, 403 ) );
			$this->assertContains( $this->rest( 'GET', array(), '/' . $id )->get_status(), array( 401, 403 ) );
		}
	}

	/**
	 * Snippets are deleted, never trashed; deleting one updates the cache.
	 */
	public function test_delete(): void {
		$id = $this->rest( 'POST', $this->body( 'html', '<i>x</i>' ) )->get_data()['id'];

		$this->assertSame( 501, $this->rest( 'DELETE', array(), '/' . $id )->get_status() );
		$this->assertSame( 200, $this->rest( 'DELETE', array(), '/' . $id . '?force=true' )->get_status() );
		$this->assertSame( '', $this->output( 'head' ) );
	}

	/**
	 * Imported snippets arrive inactive.
	 */
	public function test_import_is_inactive(): void {
		$data = apply_filters(
			'wp_import_post_data_processed',
			array(
				'post_type'   => CustomCode::POST_TYPE,
				'post_status' => 'publish',
			),
			array()
		);

		$this->assertSame( 'draft', $data['post_status'] );
	}

	/**
	 * Revisions keep the history of the code.
	 */
	public function test_revisions(): void {
		$id = $this->rest( 'POST', $this->body( 'html', '<i>1</i>' ) )->get_data()['id'];
		$this->rest( 'POST', array( 'content' => '<i>2</i>' ), '/' . $id );

		$history = wp_list_pluck( wp_get_post_revisions( $id ), 'post_content' );
		$this->assertContains( '<i>1</i>', $history );
		$this->assertContains( '<i>2</i>', $history );
	}

	/**
	 * A page without snippets costs no query, even before the first snippet exists.
	 */
	public function test_no_query_without_snippets(): void {
		global $wpdb;

		delete_option( CustomCode::OPTION );
		wp_cache_delete( 'notoptions', 'options' );
		// Every real request has loaded the autoloaded options before the theme runs.
		wp_load_alloptions();

		$before = $wpdb->num_queries;
		$this->assertSame( '', $this->output( 'head' ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}
}
