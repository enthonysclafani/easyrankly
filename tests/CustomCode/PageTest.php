<?php
/**
 * Tests for the custom code screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\CustomCode;

use EasyRankly\CustomCode\Admin\Page;
use EasyRankly\CustomCode\CustomCode;
use WP_UnitTestCase;

/**
 * Snippets are saved through their REST route, so the rules of CustomCode::validate_rest()
 * apply to the form: unfiltered_html, edit_plugins for PHP, syntax check, fixed type.
 */
final class PageTest extends WP_UnitTestCase {

	/**
	 * Registers the post type again (the test case resets post types) and logs in an administrator.
	 */
	public function set_up(): void {
		parent::set_up();

		( new CustomCode() )->register_post_type();
		$GLOBALS['wp_rest_server'] = null;
		$this->login_admin();
	}

	/**
	 * Restores the REST server and the request.
	 */
	public function tear_down(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$GLOBALS['wp_rest_server'] = null;
		wp_dequeue_script( 'code-editor' );

		parent::tear_down();
	}

	/**
	 * Logs in an administrator, super admin on multisite (only they have unfiltered_html there).
	 */
	private function login_admin(): void {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
	}

	/**
	 * Takes a capability away from every user.
	 *
	 * @param string $capability Capability.
	 */
	private function deny( string $capability ): void {
		add_filter(
			'map_meta_cap',
			static fn( $caps, $cap ) => $cap === $capability ? array( 'do_not_allow' ) : $caps,
			10,
			2
		);
	}

	/**
	 * Runs the screen with a request: the load step, as admin.php does, then the view.
	 *
	 * @param array<string, mixed> $get  Query arguments.
	 * @param array<string, mixed> $post Posted fields.
	 * @return array{0: string, 1: string} Redirect location (empty if none) and HTML.
	 */
	private function request( array $get, array $post = array() ): array {
		$get      = array( 'page' => Page::SLUG ) + $get;
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );

		add_filter(
			'wp_redirect',
			static function ( $location ): never {
				throw new \RuntimeException( esc_url_raw( (string) $location ) );
			}
		);

		$page = new Page();
		try {
			$page->load();
		} catch ( \RuntimeException $redirect ) {
			return array( $redirect->getMessage(), '' );
		}

		return array( '', get_echo( array( $page, 'render' ) ) );
	}

	/**
	 * Fields of a new snippet form.
	 *
	 * @param array<string, mixed> $fields Fields that change.
	 * @return array<string, mixed>
	 */
	private function form( array $fields ): array {
		$id = (int) ( $fields['easyrankly_save'] ?? 0 );

		return $fields + array(
			'easyrankly_save' => '0',
			'_wpnonce'        => wp_create_nonce( 'easyrankly-save_' . Page::SLUG . '_' . $id ),
			'name'            => 'Analytics',
			'type'            => 'html',
			'code'            => '<meta name="x" content="1">',
			'position'        => 'head',
			'priority'        => '10',
			'active'          => '1',
		);
	}

	/**
	 * Snippets with this name.
	 *
	 * @param string $name Name.
	 * @return list<\WP_Post>
	 */
	private static function snippets( string $name ): array {
		return array_values(
			array_filter(
				get_posts(
					array(
						'post_type'   => CustomCode::POST_TYPE,
						'post_status' => array( 'publish', 'draft' ),
						'numberposts' => -1,
					)
				),
				static fn( \WP_Post $post ): bool => $name === $post->post_title
			)
		);
	}

	/**
	 * The list shows the snippets; the code is saved as typed.
	 */
	public function test_add_html_snippet_and_list_it(): void {
		[ $location ] = $this->request( array( 'action' => 'new' ), $this->form( array() ) );

		$this->assertStringContainsString( 'message=saved', $location );
		$snippets = self::snippets( 'Analytics' );
		$this->assertCount( 1, $snippets );
		$this->assertSame( '<meta name="x" content="1">', $snippets[0]->post_content );
		$this->assertSame( 'publish', $snippets[0]->post_status );

		[ , $html ] = $this->request( array() );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( 'Analytics', $html );
		$this->assertStringContainsString( 'Head (10)', $html );
	}

	/**
	 * Without unfiltered_html the screen shows and changes nothing.
	 */
	public function test_requires_unfiltered_html(): void {
		$this->deny( 'unfiltered_html' );

		[ $location, $html ] = $this->request( array( 'action' => 'new' ), $this->form( array() ) );

		$this->assertSame( '', $location );
		$this->assertSame( '', $html );
		$this->assertSame( array(), self::snippets( 'Analytics' ) );
	}

	/**
	 * PHP that does not parse cannot be active: the form comes back with the error.
	 */
	public function test_php_with_syntax_error_is_not_saved_active(): void {
		$fields = array(
			'type' => 'php',
			'name' => 'Broken',
			'code' => 'echo (',
		);

		[ $location, $html ] = $this->request( array( 'action' => 'new' ), $this->form( $fields ) );

		$this->assertSame( '', $location );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'echo (</textarea>', $html );
		$this->assertSame( array(), self::snippets( 'Broken' ) );

		[ $location ] = $this->request( array( 'action' => 'new' ), $this->form( $fields + array( 'active' => '' ) ) );

		$this->assertStringContainsString( 'message=saved', $location );
		$this->assertSame( 'draft', self::snippets( 'Broken' )[0]->post_status ?? '' );
	}

	/**
	 * Without edit_plugins there is no PHP option, and a forged PHP form is refused.
	 */
	public function test_php_needs_edit_plugins(): void {
		$this->deny( 'edit_plugins' );

		[ , $html ] = $this->request( array( 'action' => 'new' ) );
		$this->assertStringContainsString( 'value="html"', $html );
		$this->assertStringNotContainsString( 'value="php"', $html );

		[ $location, $html ] = $this->request(
			array( 'action' => 'new' ),
			$this->form(
				array(
					'type' => 'php',
					'name' => 'Sneaky',
					'code' => 'echo 1;',
				)
			)
		);

		$this->assertSame( '', $location );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertSame( array(), self::snippets( 'Sneaky' ) );
	}

	/**
	 * The type of a saved snippet cannot change from the form.
	 */
	public function test_type_never_changes(): void {
		$this->request( array( 'action' => 'new' ), $this->form( array() ) );
		$id = self::snippets( 'Analytics' )[0]->ID;

		[ , $html ] = $this->request(
			array(
				'action' => 'edit',
				'id'     => (string) $id,
			)
		);
		$this->assertStringNotContainsString( 'name="type"', $html );

		[ $location ] = $this->request(
			array(
				'action' => 'edit',
				'id'     => (string) $id,
			),
			$this->form(
				array(
					'easyrankly_save' => (string) $id,
					'type'            => 'php',
					'code'            => 'echo 1;',
				)
			)
		);

		$this->assertStringContainsString( 'message=saved', $location );
		$this->assertSame( 'html', CustomCode::type( $id ) );
	}

	/**
	 * The form loads the code editor of the core; the list loads nothing.
	 */
	public function test_code_editor_only_on_the_form(): void {
		$this->request( array() );
		$this->assertFalse( wp_script_is( 'code-editor', 'enqueued' ) );

		$this->request( array( 'action' => 'new' ) );
		set_current_screen( 'easyrankly_page_' . Page::SLUG );
		// The command palette of the core loads the components styles on every admin screen: not ours.
		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );
		do_action( 'admin_enqueue_scripts', 'easyrankly_page_' . Page::SLUG );

		$this->assertTrue( wp_script_is( 'code-editor', 'enqueued' ) );
		$this->assertStringContainsString( 'easyrankly-code', implode( '', (array) wp_scripts()->get_data( 'code-editor', 'after' ) ) );
		$this->assertFalse( wp_style_is( 'wp-components', 'enqueued' ) );
	}
}
