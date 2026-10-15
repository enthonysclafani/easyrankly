<?php
/**
 * Tests for custom code snippets.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\CustomCode;

use EasyRankly\CustomCode\CustomCode;
use EasyRankly\CustomCode\PhpRunner;
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
	 * Turning a snippet off after an error writes the cache once.
	 */
	public function test_disable_rebuilds_cache_once(): void {
		$id = $this->rest( 'POST', $this->body( 'php', "echo 'x';", 'publish', 'footer' ) )->get_data()['id'];

		$writes = 0;
		add_filter(
			'pre_update_option_' . CustomCode::OPTION,
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		CustomCode::disable( $id, 'Error: boom' );

		$this->assertSame( 1, $writes );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( '', $this->output( 'footer' ) );
	}

	/**
	 * Each position runs from a named callback, which a theme or a snippet can take off its hook.
	 */
	public function test_positions_can_be_removed(): void {
		$this->rest( 'POST', $this->body( 'html', '<p>end</p>', 'publish', 'footer' ) );

		$GLOBALS['wp_current_filter'][] = 'wp_footer';
		$output                         = get_echo( array( Runner::class, 'run_hook' ) );
		array_pop( $GLOBALS['wp_current_filter'] );
		$this->assertSame( "<p>end</p>\n", $output );

		$this->assertSame( 10, has_action( 'wp_footer', array( Runner::class, 'run_hook' ) ) );
		remove_action( 'wp_footer', array( Runner::class, 'run_hook' ) );
		$this->assertFalse( has_action( 'wp_footer', array( Runner::class, 'run_hook' ) ) );
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
	 * A runtime error that a catch sees leaves the snippet on: a visitor's input cannot turn it
	 * off for everyone. The first error is kept for administrators until the snippet is saved again.
	 */
	public function test_runtime_error_is_recorded_and_snippet_stays_on(): void {
		$code = "echo '<b>before</b>';\nif ( isset( \$_GET['bad'] ) ) {\n\tthrow new \\RuntimeException( 'boom ' . \$_GET['bad'] );\n}";
		$id   = $this->rest( 'POST', $this->body( 'php', $code, 'publish', 'footer' ) )->get_data()['id'];

		// A visitor triggers the error twice, with different input.
		wp_set_current_user( 0 );
		$_GET['bad'] = 'first';
		$this->assertSame( '<b>before</b>', $this->output( 'footer' ) );
		$_GET['bad'] = 'second';
		$this->output( 'footer' );
		unset( $_GET['bad'] );

		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertSame( $code, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertStringContainsString( 'boom first', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
		$this->assertSame( '<b>before</b>', $this->output( 'footer' ) );

		$this->login_admin();
		ob_start();
		( new CustomCode() )->error_notice();
		$this->assertStringContainsString( 'php snippet', (string) ob_get_clean() );

		// Saving it again clears the error.
		$this->rest( 'POST', array( 'content' => "echo 'fixed';" ), '/' . $id );
		$this->assertSame( '', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
		ob_start();
		( new CustomCode() )->error_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Turning a snippet off after a fatal error keeps its code intact, also when a visitor triggers it.
	 */
	public function test_disable_keeps_code(): void {
		$code = "echo '<b>kept</b>';";
		$id   = $this->rest( 'POST', $this->body( 'php', $code, 'publish', 'footer' ) )->get_data()['id'];

		wp_set_current_user( 0 );
		kses_init();
		CustomCode::disable( $id, 'Fatal error: x' );
		kses_remove_filters();

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( $code, get_post_field( 'post_content', $id, 'raw' ) );
		$this->assertSame( '', $this->output( 'footer' ) );
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
	 * The list shows the code of PHP snippets only to those who may edit them.
	 */
	public function test_list_hides_php_without_edit_plugins(): void {
		$this->rest( 'POST', $this->body( 'php', 'echo "php";' ) );
		$html = $this->rest( 'POST', $this->body( 'html', '<i>html</i>' ) )->get_data()['id'];

		$user = wp_get_current_user();
		$user->add_cap( 'edit_plugins', false );
		if ( is_multisite() ) {
			revoke_super_admin( $user->ID );
			$user->add_cap( 'unfiltered_html', true );
			$this->markTestSkipped( 'On multisite edit_plugins and unfiltered_html belong to super admins only.' );
		}

		$list = $this->rest( 'GET' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( array( $html ), wp_list_pluck( $list->get_data(), 'id' ) );
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
	 * XML-RPC checks only the capabilities of the post type: an administrator without
	 * unfiltered_html cannot create a snippet there either.
	 */
	public function test_xmlrpc_needs_unfiltered_html(): void {
		require_once ABSPATH . WPINC . '/class-IXR.php';
		require_once ABSPATH . WPINC . '/class-wp-xmlrpc-server.php';
		add_filter( 'pre_option_enable_xmlrpc', '__return_true' );

		// Like a site administrator on multisite, or a site with DISALLOW_UNFILTERED_HTML.
		add_role(
			'easyrankly_no_html',
			'No HTML',
			array(
				'read'           => true,
				'manage_options' => true,
				'edit_posts'     => true,
			)
		);
		$user = self::factory()->user->create(
			array(
				'role'       => 'easyrankly_no_html',
				'user_login' => 'nohtml',
				'user_pass'  => 'password',
			)
		);
		wp_set_current_user( $user );
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );

		$result = ( new \wp_xmlrpc_server() )->wp_newPost(
			array(
				1,
				'nohtml',
				'password',
				array(
					'post_type'    => CustomCode::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Link',
					'post_content' => '<a href="https://example.org">link</a>',
				),
			)
		);
		remove_role( 'easyrankly_no_html' );

		$this->assertInstanceOf( \IXR_Error::class, $result );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => CustomCode::POST_TYPE,
					'post_status' => 'any',
				)
			)
		);
		$this->assertSame( '', $this->output( 'head' ) );
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
	 * Tools > Export, which asks only for the export capability, leaves snippets out, whether
	 * chosen alone or with all content (export_wp() reads the same list).
	 */
	public function test_export_leaves_snippets_out(): void {
		$this->assertNotContains( CustomCode::POST_TYPE, get_post_types( array( 'can_export' => true ) ) );
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

	/**
	 * Outside REST (XML-RPC, importers, code) the type is still locked and PHP still needs edit_plugins.
	 */
	public function test_type_guard_on_every_write_path(): void {
		$html = $this->rest( 'POST', $this->body( 'html', '<i></i>' ) )->get_data()['id'];

		$this->assertFalse( update_post_meta( $html, CustomCode::meta_key( 'type' ), 'php' ) );
		$this->assertSame( 'html', CustomCode::type( $html ) );

		$new = self::factory()->post->create( array( 'post_type' => CustomCode::POST_TYPE ) );
		$this->assertFalse( add_post_meta( $new, CustomCode::meta_key( 'type' ), 'js' ) );

		if ( is_multisite() ) {
			return;
		}

		wp_get_current_user()->add_cap( 'edit_plugins', false );
		$this->assertFalse( add_post_meta( $new, CustomCode::meta_key( 'type' ), 'php' ) );
		$this->assertNotFalse( add_post_meta( $new, CustomCode::meta_key( 'type' ), 'html' ) );
	}

	/**
	 * A REST save rebuilds the cache once, after the post and its meta are written.
	 */
	public function test_rest_save_rebuilds_cache_once(): void {
		$rebuilds = 0;
		add_filter(
			'pre_update_option_' . CustomCode::OPTION,
			static function ( $value ) use ( &$rebuilds ) {
				++$rebuilds;
				return $value;
			}
		);

		$id = $this->rest( 'POST', $this->body( 'html', '<meta name="a">', 'publish', 'footer', 5 ) )->get_data()['id'];
		$this->assertSame( 1, $rebuilds );
		$this->assertSame( array( $id ), array_column( get_option( CustomCode::OPTION )['positions']['footer'], 'id' ) );

		$rebuilds = 0;
		$this->rest( 'POST', array( 'meta' => array( CustomCode::meta_key( 'position' ) => 'head' ) ), '/' . $id );
		$this->assertSame( 1, $rebuilds );
		$this->assertSame( array( $id ), array_column( get_option( CustomCode::OPTION )['positions']['head'], 'id' ) );

		// Outside REST every write still rebuilds.
		$rebuilds = 0;
		update_post_meta( $id, CustomCode::meta_key( 'priority' ), 3 );
		$this->assertSame( 1, $rebuilds );
	}

	/**
	 * Meta changed outside REST updates the cache; PHP that does not parse is never cached.
	 */
	public function test_cache_follows_writes_outside_rest(): void {
		$id = $this->rest( 'POST', $this->body( 'html', '<i>moved</i>' ) )->get_data()['id'];

		update_post_meta( $id, CustomCode::meta_key( 'position' ), 'footer' );
		$this->assertSame( '', $this->output( 'head' ) );
		$this->assertSame( "<i>moved</i>\n", $this->output( 'footer' ) );

		$php = $this->rest( 'POST', $this->body( 'php', "echo 'ok';", 'publish', 'body_open' ) )->get_data()['id'];
		wp_update_post(
			array(
				'ID'           => $php,
				'post_content' => 'echo (;',
			)
		);
		$this->assertSame( '', $this->output( 'body_open' ) );
	}

	/**
	 * A fatal error, which no catch sees, turns off the snippet that was running.
	 */
	public function test_fatal_error_disables_running_snippet(): void {
		$id = $this->rest( 'POST', $this->body( 'php', 'echo 1;', 'publish', 'footer' ) )->get_data()['id'];

		PhpRunner::handle_fatal(
			array(
				'type'    => E_WARNING,
				'message' => 'notice',
			),
			$id
		);
		PhpRunner::handle_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'other plugin',
			),
			-1
		);
		$this->assertSame( 'publish', get_post_status( $id ) );

		PhpRunner::handle_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Cannot redeclare get_option()',
			),
			$id
		);
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertStringContainsString( 'Cannot redeclare', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
	}

	/**
	 * A snippet that runs another one through a hook is still the running one afterwards,
	 * so a fatal error in the rest of its code turns it off.
	 */
	public function test_nested_snippet_leaves_the_outer_one_running(): void {
		$this->rest( 'POST', $this->body( 'php', 'echo "";', 'publish', 'body_open' ) );
		$code  = "do_action( 'wp_body_open' );\n";
		$code .= "\$running = new \\ReflectionProperty( \\EasyRankly\\CustomCode\\PhpRunner::class, 'running' );\n";
		$code .= "\$running->setAccessible( true );\n";
		$code .= "\$GLOBALS['easyrankly_test_running'] = \$running->getValue();";
		$outer = $this->rest( 'POST', $this->body( 'php', $code, 'publish', 'footer' ) )->get_data()['id'];

		$this->output( 'footer' );

		$this->assertSame( $outer, $GLOBALS['easyrankly_test_running'] ?? null );
		unset( $GLOBALS['easyrankly_test_running'] );
	}

	/**
	 * Everywhere runs PHP at load time and drops its output; it never takes HTML.
	 */
	public function test_everywhere_runs_php_only(): void {
		$code = "remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );\nremove_action( 'template_redirect', 'wp_shortlink_header', 11 );\necho 'dropped';";
		$this->assertSame( 201, $this->rest( 'POST', $this->body( 'php', $code, 'publish', 'everywhere' ) )->get_status() );

		$this->assertSame( 10, has_action( 'wp_head', 'wp_shortlink_wp_head' ) );
		$this->assertSame( '', $this->output( 'everywhere' ) );
		$this->assertFalse( has_action( 'wp_head', 'wp_shortlink_wp_head' ) );
		$this->assertFalse( has_action( 'template_redirect', 'wp_shortlink_header' ) );

		$html = $this->rest( 'POST', $this->body( 'html', '<i>early</i>', 'publish', 'everywhere' ) );
		$this->assertSame( 400, $html->get_status() );
		$this->assertSame( 'easyrankly_snippet_position', $html->get_data()['code'] );

		// Outside REST the position can still be set: HTML there is never cached.
		$id = $this->rest( 'POST', $this->body( 'html', '<i>early</i>' ) )->get_data()['id'];
		update_post_meta( $id, CustomCode::meta_key( 'position' ), 'everywhere' );
		$this->assertSame( '', $this->output( 'everywhere' ) );
		$this->assertSame( array(), get_option( CustomCode::OPTION )['positions']['head'] );
	}

	/**
	 * Everywhere also runs in the admin, except on the Custom code screen; the page positions never do.
	 */
	public function test_everywhere_runs_in_admin_but_not_on_its_screen(): void {
		global $pagenow;

		$this->rest( 'POST', $this->body( 'php', "add_filter( 'easyrankly_test_everywhere', '__return_true' );", 'publish', 'everywhere' ) );
		$this->rest( 'POST', $this->body( 'php', "add_filter( 'easyrankly_test_head', '__return_true' );" ) );

		set_current_screen( 'dashboard' );
		$pagenow      = 'admin.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The screen the request is on.
		$_GET['page'] = 'easyrankly-snippets';
		$this->output( 'everywhere' );
		$this->output( 'head' );
		$this->assertFalse( apply_filters( 'easyrankly_test_everywhere', false ) );

		// Only for those who may fix snippets there: for anyone else the URL changes nothing.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->output( 'everywhere' );
		$this->assertTrue( apply_filters( 'easyrankly_test_everywhere', false ) );
		remove_all_filters( 'easyrankly_test_everywhere' );
		wp_set_current_user( 0 );
		$this->output( 'everywhere' );
		$this->assertTrue( apply_filters( 'easyrankly_test_everywhere', false ) );
		remove_all_filters( 'easyrankly_test_everywhere' );

		$this->login_admin();
		$_GET['page'] = 'easyrankly-redirects';
		$this->output( 'everywhere' );
		$this->assertTrue( apply_filters( 'easyrankly_test_everywhere', false ) );
		$this->assertFalse( apply_filters( 'easyrankly_test_head', false ) );

		unset( $_GET['page'] );
		$pagenow = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the default.
		set_current_screen( 'front' );
	}

	/**
	 * Errors of an everywhere snippet are recorded, and a fatal one turns it off, even before init
	 * registers the post type.
	 */
	public function test_everywhere_error_before_init(): void {
		$id = $this->rest( 'POST', $this->body( 'php', "throw new \\RuntimeException( 'early' );", 'publish', 'everywhere' ) )->get_data()['id'];

		unregister_post_type( CustomCode::POST_TYPE );
		$this->output( 'everywhere' );

		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertStringContainsString( 'early', get_post_meta( $id, CustomCode::meta_key( 'error' ), true ) );
		$this->assertArrayHasKey( $id, get_option( CustomCode::OPTION )['errors'] );

		PhpRunner::handle_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'early fatal',
			),
			$id
		);
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( array(), get_option( CustomCode::OPTION )['positions']['everywhere'] );
	}

	/**
	 * Only snippets this plugin cached run: anything else written into the option is skipped and
	 * the cache is rebuilt from the snippets.
	 */
	public function test_cache_runs_only_signed_snippets(): void {
		$this->rest( 'POST', $this->body( 'php', "echo 'saved';", 'publish', 'footer' ) );
		$this->rest( 'POST', $this->body( 'html', '<i>head</i>', 'publish', 'head' ) );
		$saved = get_option( CustomCode::OPTION );

		// Code changed in the option: the saved code runs and the option is repaired.
		$cache                                   = $saved;
		$cache['positions']['footer'][0]['code'] = "echo 'changed';";
		update_option( CustomCode::OPTION, $cache );
		$this->assertSame( 'saved', $this->output( 'footer' ) );
		$this->assertSame( $saved, get_option( CustomCode::OPTION ) );

		// A new unsigned or forged snippet never runs.
		$cache                              = $saved;
		$cache['positions']['everywhere'][] = array(
			'id'   => 999,
			'type' => 'php',
			'code' => "echo 'forged';",
			'sig'  => hash_hmac( 'sha256', "999\nphp\neverywhere\necho 'forged';", 'guess' ),
		);
		update_option( CustomCode::OPTION, $cache );
		$this->assertSame( '', $this->output( 'everywhere' ) );
		$this->assertSame( $saved, get_option( CustomCode::OPTION ) );

		// A signed snippet moved to another position, or turned into PHP, does not run there.
		$cache                                 = $saved;
		$cache['positions']['body_open'][]     = $saved['positions']['head'][0];
		$cache['positions']['head'][0]['type'] = 'php';
		update_option( CustomCode::OPTION, $cache );
		$this->assertSame( '', $this->output( 'body_open' ) );
		$this->assertSame( "<i>head</i>\n", $this->output( 'head' ) );
	}

	/**
	 * A cache written before signatures, or with other salts, is rebuilt and its snippets keep running.
	 */
	public function test_unsigned_cache_is_rebuilt(): void {
		$this->rest( 'POST', $this->body( 'html', '<i>kept</i>', 'publish', 'head' ) );
		$saved = get_option( CustomCode::OPTION );

		$cache = $saved;
		unset( $cache['positions']['head'][0]['sig'] );
		update_option( CustomCode::OPTION, $cache );

		$this->assertSame( "<i>kept</i>\n", $this->output( 'head' ) );
		$this->assertSame( $saved, get_option( CustomCode::OPTION ) );
	}
}
