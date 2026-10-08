<?php
/**
 * Tests for proposals: storage, decisions and REST routes.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Proposals;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A proposal changes the site only when a person with the right permissions accepts it.
 */
final class ProposalsTest extends WP_UnitTestCase {

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
	 * A fresh REST server, an administrator and a post with an SEO title.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->post  = self::factory()->post->create(
			array(
				'post_author' => $this->admin,
				'post_title'  => 'Lemon cake',
				'meta_input'  => array( '_easyrankly_title' => 'Old title' ),
			)
		);
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
	 * Creates a pending proposal for the test post.
	 *
	 * @param array<string, mixed> $values Fields to change.
	 * @return int
	 */
	private function propose( array $values = array(
		'title'       => 'New title',
		'description' => 'New description',
	) ): int {
		$id = Proposals::create(
			array(
				'ability'    => Actions::POST_SEO,
				'input'      => array( 'id' => $this->post ) + $values,
				'title'      => 'Better title',
				'motivation' => 'Low click-through rate.',
				'evidence'   => '1,240 impressions, CTR 0.8%',
				'confidence' => 0.7,
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Route.
	 * @param array<string, mixed> $params Parameters.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $path, array $params = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A new proposal is pending, linked to the content, with the values it would replace.
	 */
	public function test_create_stores_a_pending_proposal(): void {
		$id   = $this->propose();
		$post = get_post( $id );

		$this->assertSame( 'erankly_pending', $post->post_status );
		$this->assertSame( $this->post, (int) $post->post_parent );
		$this->assertSame( 'Low click-through rate.', $post->post_content );
		$this->assertSame(
			array(
				'id'          => $this->post,
				'title'       => 'Old title',
				'description' => '',
			),
			get_post_meta( $id, '_easyrankly_proposal_previous', true )
		);
		$this->assertNotSame( '', get_post_meta( $id, '_easyrankly_proposal_fingerprint', true ) );

		// Proposing changes nothing on the site.
		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * Unknown abilities, input outside the schema and missing content are refused.
	 */
	public function test_create_refuses_invalid_proposals(): void {
		$base = array( 'title' => 'x' );

		$this->assertSame(
			'easyrankly_not_allowed',
			Proposals::create(
				$base + array(
					'ability' => 'core/get-site-info',
					'input'   => array(),
				)
			)->get_error_code()
		);
		$this->assertSame(
			'ability_invalid_input',
			Proposals::create(
				$base + array(
					'ability' => Actions::POST_SEO,
					'input'   => array(
						'id'    => $this->post,
						'title' => str_repeat( 'a', 201 ),
					),
				)
			)->get_error_code()
		);
		$this->assertSame(
			'ability_invalid_input',
			Proposals::create(
				$base + array(
					'ability' => Actions::POST_SEO,
					'input'   => array(
						'id'        => $this->post,
						'canonical' => 'https://evil.example',
					),
				)
			)->get_error_code()
		);
		$this->assertSame(
			'ability_invalid_input',
			Proposals::create(
				$base + array(
					'ability' => Actions::POST_SEO,
					'input'   => array( 'id' => $this->post ),
				)
			)->get_error_code()
		);
		$this->assertSame(
			'easyrankly_not_found',
			Proposals::create(
				$base + array(
					'ability' => Actions::POST_SEO,
					'input'   => array(
						'id'    => 999999,
						'title' => 'x',
					),
				)
			)->get_error_code()
		);
	}

	/**
	 * Accepting writes the values with the accepting user's rights and records the decision.
	 */
	public function test_accept_applies_the_proposal(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );

		$this->assertTrue( Proposals::accept( $id ) );

		$this->assertSame( 'New title', get_post_meta( $this->post, '_easyrankly_title', true ) );
		$this->assertSame( 'New description', get_post_meta( $this->post, '_easyrankly_description', true ) );
		$this->assertSame( 'erankly_accepted', get_post_status( $id ) );
		$this->assertSame( $this->admin, (int) get_post_meta( $id, '_easyrankly_proposal_user', true ) );

		// A decided proposal cannot be accepted again.
		$this->assertSame( 409, Proposals::accept( $id )->get_error_data()['status'] );
	}

	/**
	 * A user who cannot edit the content cannot apply the proposal: it stays pending.
	 */
	public function test_accept_needs_the_rights_on_the_content(): void {
		$id = $this->propose();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = Proposals::accept( $id );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'erankly_pending', get_post_status( $id ) );
		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * "Edit and accept" applies edited values of the same fields, validated again.
	 */
	public function test_edit_and_accept(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );

		$this->assertSame( 400, Proposals::accept( $id, array( 'og_title' => 'x' ) )->get_error_data()['status'] );
		$this->assertSame( 400, Proposals::accept( $id, array( 'id' => self::factory()->post->create() ) )->get_error_data()['status'] );
		$this->assertSame( 400, Proposals::accept( $id, array( 'title' => str_repeat( 'a', 201 ) ) )->get_error_data()['status'] );
		$this->assertSame( 'erankly_pending', get_post_status( $id ) );

		$this->assertTrue( Proposals::accept( $id, array( 'title' => 'Edited title' ) ) );
		$this->assertSame( 'Edited title', get_post_meta( $this->post, '_easyrankly_title', true ) );
		$this->assertSame( 'Edited title', get_post_meta( $id, '_easyrankly_proposal_input', true )['title'] );
	}

	/**
	 * Rejecting keeps the reason and changes nothing.
	 */
	public function test_reject(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );

		$this->assertTrue( Proposals::reject( $id, 'Too long.' ) );

		$this->assertSame( 'erankly_rejected', get_post_status( $id ) );
		$this->assertSame( 'Too long.', get_post_meta( $id, '_easyrankly_proposal_note', true ) );
		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * Undo restores the previous values, an empty one by removing the override.
	 */
	public function test_undo_restores_previous_values(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );
		Proposals::accept( $id );

		$this->assertTrue( Proposals::undo( $id ) );

		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );
		$this->assertFalse( metadata_exists( 'post', $this->post, '_easyrankly_description' ) );
		$this->assertSame( 'erankly_reverted', get_post_status( $id ) );
		$this->assertSame( 409, Proposals::undo( $id )->get_error_data()['status'] );
	}

	/**
	 * An error while applying marks the proposal failed and keeps the message.
	 */
	public function test_failure_marks_the_proposal_failed(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );
		$fail = static fn() => new \WP_Error( 'boom', 'Storage unavailable.' );
		add_filter( 'wp_ability_execute_result', $fail );

		$result = Proposals::accept( $id );
		remove_filter( 'wp_ability_execute_result', $fail );

		$this->assertWPError( $result );
		$this->assertSame( 'erankly_failed', get_post_status( $id ) );
		$this->assertSame( 'Storage unavailable.', get_post_meta( $id, '_easyrankly_proposal_note', true ) );
	}

	/**
	 * Proposals never show up in normal queries or searches.
	 */
	public function test_proposals_stay_out_of_queries(): void {
		$this->propose();

		$this->assertSame( array(), get_posts( array( 'post_type' => 'any' ) + array( 's' => 'Better' ) ) );
		$this->assertFalse( is_post_type_viewable( Proposals::POST_TYPE ) );
	}

	/**
	 * The REST routes need manage_options; the list shows the preview of the differences.
	 */
	public function test_rest_list_and_preview(): void {
		$id = $this->propose();

		$this->assertSame( 401, $this->request( 'GET', '/easyrankly/v1/proposals' )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/easyrankly/v1/proposals' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/easyrankly/v1/proposals/{$id}/accept" )->get_status() );

		wp_set_current_user( $this->admin );
		$response = $this->request( 'GET', '/easyrankly/v1/proposals' );
		$items    = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( $id, $items[0]['id'] );
		$this->assertSame( 'pending', $items[0]['status'] );
		$this->assertSame( 0.7, $items[0]['confidence'] );
		$this->assertSame( 'Lemon cake', $items[0]['object']['title'] );
		$this->assertSame(
			array(
				array(
					'field'  => 'title',
					'label'  => 'SEO title',
					'before' => 'Old title',
					'after'  => 'New title',
				),
				array(
					'field'  => 'description',
					'label'  => 'Meta description',
					'before' => '',
					'after'  => 'New description',
				),
			),
			$items[0]['diff']
		);

		$this->assertSame( array(), $this->request( 'GET', '/easyrankly/v1/proposals', array( 'status' => 'accepted' ) )->get_data() );
		$this->assertCount( 1, $this->request( 'GET', '/easyrankly/v1/proposals', array( 'status' => 'all' ) )->get_data() );
	}

	/**
	 * Accept with changes, reject and undo through REST.
	 */
	public function test_rest_decisions(): void {
		wp_set_current_user( $this->admin );

		$first    = $this->propose();
		$response = $this->request( 'POST', "/easyrankly/v1/proposals/{$first}/accept", array( 'changes' => array( 'title' => 'From REST' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'accepted', $response->get_data()['status'] );
		$this->assertSame( 'From REST', get_post_meta( $this->post, '_easyrankly_title', true ) );

		$response = $this->request( 'POST', "/easyrankly/v1/proposals/{$first}/undo" );
		$this->assertSame( 'reverted', $response->get_data()['status'] );
		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );

		$second   = $this->propose();
		$response = $this->request( 'POST', "/easyrankly/v1/proposals/{$second}/reject", array( 'reason' => "<b>No</b>\nthanks" ) );
		$this->assertSame( 'rejected', $response->get_data()['status'] );
		$this->assertSame( "No\nthanks", $response->get_data()['note'] );

		$this->assertSame( 409, $this->request( 'POST', "/easyrankly/v1/proposals/{$second}/accept" )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/easyrankly/v1/proposals/' . $this->post . '/reject' )->get_status() );
	}

	/**
	 * The write ability is not exposed in REST: the only way in is an accepted proposal.
	 */
	public function test_write_ability_is_not_in_rest(): void {
		wp_set_current_user( $this->admin );

		$ability = wp_get_ability( Actions::POST_SEO );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertFalse( $ability->get_meta_item( 'annotations' )['readonly'] );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/easyrankly/update-post-seo/run' );
		$request->set_body_params(
			array(
				'input' => array(
					'id'    => $this->post,
					'title' => 'Direct',
				),
			)
		);
		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 'Old title', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}
}
