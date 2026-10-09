<?php
/**
 * Tests for the actions other plugins register with `easyrankly_agent_actions`.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Allowlist;
use EasyRankly\Agent\Memory;
use EasyRankly\Agent\Proposals;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The test plugin in tests/fixtures/plugins registers `easyrankly-test/set-excerpt` (allowed),
 * the same action annotated as destructive or without annotations (both refused), and tries
 * to replace one of the agent's own actions.
 */
final class ExtensionTest extends WP_UnitTestCase {

	/**
	 * Action registered by the test plugin.
	 */
	private const ACTION = 'easyrankly-test/set-excerpt';

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Post the proposals change.
	 *
	 * @var int
	 */
	private int $post;

	/**
	 * A fresh REST server, an administrator and a post with a two-line excerpt.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->post  = self::factory()->post->create(
			array(
				'post_author'  => $this->admin,
				'post_title'   => 'Lemon cake',
				'post_excerpt' => "Line one\nLine two",
			)
		);
		wp_set_current_user( $this->admin );
	}

	/**
	 * Resets the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Proposes a new excerpt.
	 *
	 * @param string $excerpt New excerpt.
	 * @param string $action  Ability name.
	 * @return int|\WP_Error
	 */
	private function propose( string $excerpt = "Line one\nLine 2", string $action = self::ACTION ) {
		return Proposals::create(
			array(
				'ability' => $action,
				'input'   => array(
					'id'      => $this->post,
					'excerpt' => $excerpt,
				),
				'title'   => 'Clearer excerpt',
			)
		);
	}

	/**
	 * Excerpt of the test post.
	 *
	 * @return string
	 */
	private function excerpt(): string {
		clean_post_cache( $this->post );

		return (string) get_post( $this->post )->post_excerpt;
	}

	/**
	 * A registered action becomes a proposal linked to its post, with the values it replaces;
	 * accepting applies it and "Undo" restores them.
	 */
	public function test_registered_action_is_proposed_accepted_and_undone(): void {
		$id = $this->propose();
		$this->assertIsInt( $id );

		$proposal = Proposals::get( $id, 'pending' );
		$this->assertSame( $this->post, $proposal['object'] );
		$this->assertSame(
			array(
				'id'      => $this->post,
				'excerpt' => "Line one\nLine two",
			),
			$proposal['previous']
		);
		$this->assertSame( "Line one\nLine two", $this->excerpt() );

		$this->assertTrue( Proposals::accept( $id ) );
		$this->assertSame( "Line one\nLine 2", $this->excerpt() );

		$this->assertTrue( Proposals::undo( $id ) );
		$this->assertSame( "Line one\nLine two", $this->excerpt() );
		$this->assertSame( 'reverted', Proposals::get( $id )['status'] );
	}

	/**
	 * Actions annotated as destructive, or that do not say, are refused even when registered.
	 */
	public function test_destructive_actions_are_refused(): void {
		foreach ( array( 'easyrankly-test/replace-excerpt', 'easyrankly-test/clear-excerpt' ) as $action ) {
			$this->assertNotNull( Allowlist::definition( $action ), $action );
			$result = $this->propose( 'New', $action );
			$this->assertWPError( $result, $action );
			$this->assertSame( 'easyrankly_destructive', $result->get_error_code(), $action );
		}

		// The agent's own actions pass the same check: they are not destructive.
		foreach ( Allowlist::ACTIONS as $action ) {
			$this->assertFalse( wp_get_ability( $action )->get_meta_item( 'annotations' )['destructive'], $action );
		}
	}

	/**
	 * Registered actions pass the same checks as the agent's own: schema, markup, links to other sites.
	 */
	public function test_registered_actions_pass_the_same_validation(): void {
		$this->assertSame( 'ability_invalid_input', $this->propose( str_repeat( 'a', 5001 ) )->get_error_code() );
		$this->assertSame( 'easyrankly_markup', $this->propose( "Line one\n<script>alert(1)</script>" )->get_error_code() );
		$this->assertSame( 'easyrankly_external_domain', $this->propose( "Line one\nhttps://spam.example/" )->get_error_code() );
		$page = Proposals::create(
			array(
				'ability' => self::ACTION,
				'input'   => array(
					'id'      => self::factory()->post->create( array( 'post_type' => 'page' ) ),
					'excerpt' => 'New',
				),
				'title'   => 'Page',
			)
		);
		$this->assertSame( 'easyrankly_test_not_found', $page->get_error_code() );

		$this->assertSame( 'easyrankly_not_allowed', Allowlist::validate( 'easyrankly-test/unregistered', array() )->get_error_code() );
	}

	/**
	 * Accepting needs the rights of the person who accepts; a changed post supersedes the proposal.
	 */
	public function test_permissions_and_superseded_proposals(): void {
		$id = $this->propose();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 'ability_invalid_permissions', Proposals::accept( $id )->get_error_code() );
		$this->assertSame( 'pending', Proposals::get( $id )['status'] );
		$this->assertSame( "Line one\nLine two", $this->excerpt() );

		wp_set_current_user( $this->admin );
		wp_update_post(
			array(
				'ID'           => $this->post,
				'post_excerpt' => 'Written by hand',
			)
		);
		$this->assertSame( 'easyrankly_proposal_superseded', Proposals::accept( $id )->get_error_code() );
		$this->assertSame( 'superseded', Proposals::get( $id )['status'] );
		$this->assertSame( 'Written by hand', $this->excerpt() );
	}

	/**
	 * The agent's own actions cannot be replaced by a registration.
	 */
	public function test_own_actions_cannot_be_replaced(): void {
		update_post_meta( $this->post, '_easyrankly_title', 'Old title' );

		$id = Proposals::create(
			array(
				'ability' => Actions::POST_SEO,
				'input'   => array(
					'id'    => $this->post,
					'title' => 'New title',
				),
				'title'   => 'Better title',
			)
		);

		$this->assertSame(
			array(
				'id'    => $this->post,
				'title' => 'Old title',
			),
			Proposals::get( $id )['previous']
		);
	}

	/**
	 * A definition without a snapshot is ignored, with a notice for the developer.
	 */
	public function test_incomplete_definition_is_ignored(): void {
		$this->setExpectedIncorrectUsage( 'easyrankly_agent_actions' );

		$register = static function ( $actions ) {
			$actions['easyrankly-test/no-snapshot'] = array( 'fields' => array() );
			return $actions;
		};
		add_filter( 'easyrankly_agent_actions', $register );

		$this->assertNull( Allowlist::definition( 'easyrankly-test/no-snapshot' ) );
		$this->assertNotNull( Allowlist::definition( self::ACTION ) );
	}

	/**
	 * When the plugin that registered an action goes away, its proposals can be neither accepted nor undone.
	 */
	public function test_unregistered_action_is_not_applied(): void {
		$accepted = $this->propose( 'Accepted' );
		$this->assertTrue( Proposals::accept( $accepted ) );
		$pending = $this->propose();

		$remove = static function ( $actions ) {
			unset( $actions[ self::ACTION ] );
			return $actions;
		};
		add_filter( 'easyrankly_agent_actions', $remove, 20 );

		$this->assertSame( 'easyrankly_not_allowed', Proposals::accept( $pending )->get_error_code() );
		$this->assertSame( 'easyrankly_not_allowed', Proposals::undo( $accepted )->get_error_code() );
		$this->assertSame( 'Accepted', $this->excerpt() );
	}

	/**
	 * The preview uses the registered label and compares long texts line by line.
	 */
	public function test_preview_of_a_long_text(): void {
		$id = $this->propose( "Line one\nLine 2\nLine three" );

		$request = new WP_REST_Request( 'GET', '/easyrankly/v1/proposals' );
		$item    = rest_get_server()->dispatch( $request )->get_data()[0];

		$this->assertSame( $id, $item['id'] );
		$this->assertSame(
			array(
				'field'  => 'excerpt',
				'label'  => 'Excerpt',
				'type'   => 'long_text',
				'before' => "Line one\nLine two",
				'after'  => "Line one\nLine 2\nLine three",
				'empty'  => '(empty)',
				'lines'  => array(
					array(
						'type' => 'same',
						'text' => 'Line one',
					),
					array(
						'type' => 'removed',
						'text' => 'Line two',
					),
					array(
						'type' => 'added',
						'text' => 'Line 2',
					),
					array(
						'type' => 'added',
						'text' => 'Line three',
					),
				),
			),
			$item['diff'][0]
		);
	}

	/**
	 * "Edit and accept" is remembered with the registered label.
	 */
	public function test_memory_uses_the_registered_label(): void {
		$id = $this->propose();
		$this->assertTrue( Proposals::accept( $id, array( 'excerpt' => 'Edited' ) ) );

		$entries = get_posts(
			array(
				'post_type'   => Memory::POST_TYPE,
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( '- Excerpt: proposed "Line one', $entries[0]->post_content );
	}
}
