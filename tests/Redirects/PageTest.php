<?php
/**
 * Tests for the redirects screen.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Admin\Page;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use WP_UnitTestCase;
use WPDieException;

/**
 * A classic list and form: every change checks its nonce and goes through the REST route,
 * so the rules of Redirects::validate_rest() apply.
 */
final class PageTest extends WP_UnitTestCase {

	/**
	 * Registers the post type again (the test case resets post types) and logs in an administrator.
	 */
	public function set_up(): void {
		parent::set_up();

		( new Redirects() )->register_post_type();
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Restores the menu, the REST server and the request.
	 */
	public function tear_down(): void {
		global $menu, $submenu, $_registered_pages;
		$menu                      = array();
		$submenu                   = array();
		$_registered_pages         = array();
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * Runs the screen's load step with a request, as admin.php does.
	 *
	 * @param array<string, mixed> $get  Query arguments.
	 * @param array<string, mixed> $post Posted fields.
	 * @return string Where the screen redirected, or empty when it did not.
	 */
	private function load( array $get, array $post = array() ): string {
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

		try {
			( new Page() )->load();
		} catch ( \RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * HTML of the screen for the current request.
	 *
	 * @param Page|null $page Screen that already handled the request, to show its error.
	 * @return string
	 */
	private function render( ?Page $page = null ): string {
		return get_echo( array( $page ?? new Page(), 'render' ) );
	}

	/**
	 * Fields of a valid redirect form.
	 *
	 * @param int                  $id     Redirect ID, 0 for a new one.
	 * @param array<string, mixed> $fields Fields that change.
	 * @return array<string, mixed>
	 */
	private function form( int $id, array $fields ): array {
		return $fields + array(
			'easyrankly_save' => (string) $id,
			'_wpnonce'        => wp_create_nonce( 'easyrankly-save_' . Page::SLUG . '_' . $id ),
			'source'          => '/old-page',
			'target'          => '/new-page',
			'code'            => '301',
			'active'          => '1',
		);
	}

	/**
	 * Number of callbacks on admin_enqueue_scripts.
	 *
	 * @return int
	 */
	private static function enqueue_hooks(): int {
		global $wp_filter;

		return isset( $wp_filter['admin_enqueue_scripts'] ) ? count( $wp_filter['admin_enqueue_scripts']->callbacks, COUNT_RECURSIVE ) : 0;
	}

	/**
	 * The submenu requires manage_options.
	 */
	public function test_submenu_requires_manage_options(): void {
		global $submenu;

		( new Page() )->add_menu();

		$this->assertSame( 'manage_options', $submenu['easyrankly'][0][1] );
		$this->assertSame( Page::SLUG, $submenu['easyrankly'][0][2] );
	}

	/**
	 * The list is a core list table with search, row actions with nonces and bulk actions, and no script.
	 */
	public function test_list_shows_redirects_with_actions(): void {
		$id = Redirects::save_exact( '/old-page', '/new-page' );
		$this->assertIsInt( $id );

		$hooks = self::enqueue_hooks();
		$this->load( array() );
		$html = $this->render();

		$this->assertSame( $hooks, self::enqueue_hooks(), 'The list loads no asset.' );
		$this->assertStringContainsString( 'class="page-title-action"', $html );
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( '<code>/old-page</code>', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'value="deactivate"', $html );
		$this->assertMatchesRegularExpression( '/action=deactivate&(amp;|#038;)id=' . $id . '&(amp;|#038;)_wpnonce=/', $html );
	}

	/**
	 * Search narrows the list.
	 */
	public function test_search_filters_the_list(): void {
		Redirects::save_exact( '/alpha', '/new' );
		Redirects::save_exact( '/beta', '/new' );

		$this->load( array( 's' => 'alpha' ) );
		$html = $this->render();

		$this->assertStringContainsString( '/alpha', $html );
		$this->assertStringNotContainsString( '/beta', $html );
	}

	/**
	 * A valid form is saved through REST and goes back to the list with a message.
	 */
	public function test_add_redirect_saves_through_rest(): void {
		$location = $this->load( array( 'action' => 'new' ), $this->form( 0, array( 'source' => '/Old-Page/' ) ) );

		$this->assertStringContainsString( 'message=saved', $location );
		$id = Redirects::find_id( Rule::hash( '/old-page' ) );
		$this->assertIsInt( $id );
		$this->assertSame( '/new-page', Redirects::rule( (int) $id )['target'] ?? '' );
	}

	/**
	 * An invalid form comes back with the error and the values typed.
	 */
	public function test_invalid_redirect_comes_back_with_error(): void {
		$get      = array(
			'page'   => Page::SLUG,
			'action' => 'new',
		);
		$post     = $this->form(
			0,
			array(
				'source' => '/loop',
				'target' => '/loop',
			)
		);
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );

		$page = new Page();
		$page->load();
		$html = $this->render( $page );

		$this->assertNull( Redirects::find_id( Rule::hash( '/loop' ) ) );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'name="source" value="/loop"', $html );
	}

	/**
	 * A 410 is saved without a target.
	 */
	public function test_gone_has_no_target(): void {
		$location = $this->load(
			array( 'action' => 'new' ),
			$this->form(
				0,
				array(
					'source' => '/removed',
					'code'   => '410',
				)
			)
		);

		$this->assertStringContainsString( 'message=saved', $location );
		$rule = Redirects::rule( (int) Redirects::find_id( Rule::hash( '/removed' ) ) );
		$this->assertSame( 410, $rule['code'] ?? 0 );
		$this->assertSame( '', $rule['target'] ?? 'x' );
	}

	/**
	 * Saving without a valid nonce stops.
	 */
	public function test_save_requires_nonce(): void {
		$this->expectException( WPDieException::class );

		$this->load( array( 'action' => 'new' ), $this->form( 0, array( '_wpnonce' => 'forged' ) ) );
	}

	/**
	 * Editors cannot change redirects from the screen and see nothing.
	 */
	public function test_editors_change_and_see_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->load( array( 'action' => 'new' ), $this->form( 0, array() ) ) );
		$this->assertNull( Redirects::find_id( Rule::hash( '/old-page' ) ) );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * The row action deactivates with its nonce, and only with it.
	 */
	public function test_row_action_needs_its_nonce(): void {
		$id = (int) Redirects::save_exact( '/old-page', '/new-page' );

		$location = $this->load(
			array(
				'action'   => 'deactivate',
				'id'       => (string) $id,
				'_wpnonce' => wp_create_nonce( 'easyrankly-deactivate_' . $id ),
			)
		);
		$this->assertStringContainsString( 'message=deactivated', $location );
		$this->assertSame( 'draft', get_post_status( $id ) );

		$this->expectException( WPDieException::class );
		$this->load(
			array(
				'action'   => 'activate',
				'id'       => (string) $id,
				'_wpnonce' => wp_create_nonce( 'easyrankly-activate_' . ( $id + 1 ) ),
			)
		);
	}

	/**
	 * Bulk actions use the list table nonce.
	 */
	public function test_bulk_activate(): void {
		$first  = (int) Redirects::save_exact( '/one', '/new' );
		$second = (int) Redirects::save_exact( '/two', '/new' );
		foreach ( array( $first, $second ) as $id ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}

		$location = $this->load(
			array(
				'action'   => 'activate',
				'ids'      => array( (string) $first, (string) $second ),
				'_wpnonce' => wp_create_nonce( 'bulk-' . Page::SLUG ),
			)
		);

		$this->assertStringContainsString( 'message=activated', $location );
		$this->assertSame( array( 'publish', 'publish' ), array( get_post_status( $first ), get_post_status( $second ) ) );
	}

	/**
	 * Delete asks to confirm, then deletes with its nonce.
	 */
	public function test_delete_asks_confirmation(): void {
		$id = (int) Redirects::save_exact( '/old-page', '/new-page' );

		$this->assertSame(
			'',
			$this->load(
				array(
					'action' => 'delete',
					'ids'    => array( (string) $id ),
				)
			)
		);
		$html = $this->render();
		$this->assertStringContainsString( '/old-page', $html );
		$this->assertStringContainsString( 'name="easyrankly_delete"', $html );
		$this->assertInstanceOf( \WP_Post::class, get_post( $id ) );

		$location = $this->load(
			array( 'action' => 'delete' ),
			array(
				'easyrankly_delete' => '1',
				'ids'               => array( (string) $id ),
				'_wpnonce'          => wp_create_nonce( 'easyrankly-delete_' . Page::SLUG ),
			)
		);

		$this->assertStringContainsString( 'message=deleted', $location );
		$this->assertNull( get_post( $id ) );
	}

	/**
	 * The edit form shows the stored rule.
	 */
	public function test_edit_form_shows_values(): void {
		$id = (int) Redirects::save_exact( '/old-page', '/new-page', 302 );

		$this->load(
			array(
				'action' => 'edit',
				'id'     => (string) $id,
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'class="form-table"', $html );
		$this->assertStringContainsString( 'name="source" value="/old-page"', $html );
		$this->assertStringContainsString( 'name="target" value="/new-page"', $html );
		$this->assertMatchesRegularExpression( '/value="302"\s*selected/', $html );
	}
}
