<?php
/**
 * Tests for easyrankly_create_proposal(), the public way to create a proposal.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Allowlist;
use EasyRankly\Agent\Proposals;
use WP_UnitTestCase;

/**
 * Another plugin creates proposals with the same rules as the agent. The action is
 * `easyrankly-test/set-excerpt`, registered by the test plugin in tests/fixtures/plugins.
 */
final class CreateProposalTest extends WP_UnitTestCase {

	/**
	 * Action registered by the test plugin.
	 */
	private const ACTION = 'easyrankly-test/set-excerpt';

	/**
	 * Post the proposals change.
	 *
	 * @var int
	 */
	private int $post;

	/**
	 * A post with an excerpt; the current user is nobody, as during an editorial event of another plugin.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->post = self::factory()->post->create( array( 'post_excerpt' => 'Old excerpt' ) );
	}

	/**
	 * Proposes a new excerpt through the public function.
	 *
	 * @param array<string, mixed> $args Arguments that replace the defaults.
	 * @return int|\WP_Error
	 */
	private function propose( array $args = array() ) {
		return easyrankly_create_proposal(
			$args + array(
				'ability'    => self::ACTION,
				'input'      => array(
					'id'      => $this->post,
					'excerpt' => 'New excerpt',
				),
				'title'      => 'Clearer excerpt',
				'motivation' => 'The excerpt repeats the title.',
				'evidence'   => 'Excerpt: 11 characters.',
				'confidence' => '0.8',
			)
		);
	}

	/**
	 * The proposal is pending, linked to the post, with the values it replaces; the site does not change.
	 */
	public function test_creates_a_pending_proposal(): void {
		$id = $this->propose();
		$this->assertIsInt( $id );

		$post = get_post( $id );
		$this->assertSame( Proposals::POST_TYPE, $post->post_type );
		$this->assertSame( 'Clearer excerpt', $post->post_title );
		$this->assertSame( 'The excerpt repeats the title.', $post->post_content );
		$this->assertSame( $this->post, $post->post_parent );
		$this->assertSame( 0.8, (float) get_post_meta( $id, Proposals::meta_key( 'confidence' ), true ) );
		$this->assertSame( 'Excerpt: 11 characters.', get_post_meta( $id, Proposals::meta_key( 'evidence' ), true ) );

		$proposal = Proposals::get( $id, 'pending' );
		$this->assertSame( self::ACTION, $proposal['ability'] );
		$this->assertSame( 'Old excerpt', $proposal['previous']['excerpt'] );
		$this->assertSame( 'Old excerpt', get_post( $this->post )->post_excerpt );
	}

	/**
	 * The agent's own actions work the same way.
	 */
	public function test_creates_a_proposal_of_an_own_action(): void {
		$id = $this->propose(
			array(
				'ability' => Actions::POST_SEO,
				'input'   => array(
					'id'    => $this->post,
					'title' => 'New SEO title',
				),
			)
		);

		$this->assertIsInt( $id );
		$this->assertSame( Actions::POST_SEO, Proposals::get( $id )['ability'] );
	}

	/**
	 * Same rules as the agent: allowlist, destructive actions, schema, texts.
	 */
	public function test_same_rules_as_the_agent(): void {
		$cases = array(
			'easyrankly_not_allowed'     => array( 'ability' => 'easyrankly/get-post-seo' ),
			'easyrankly_destructive'     => array( 'ability' => 'easyrankly-test/replace-excerpt' ),
			'ability_invalid_input'      => array(
				'input' => array(
					'id'      => $this->post,
					'excerpt' => 'New',
					'status'  => 'publish',
				),
			),
			'easyrankly_markup'          => array(
				'input' => array(
					'id'      => $this->post,
					'excerpt' => '<a href="/">New</a>',
				),
			),
			'easyrankly_external_domain' => array(
				'input' => array(
					'id'      => $this->post,
					'excerpt' => 'See https://spam.example/',
				),
			),
			'easyrankly_proposal_title'  => array( 'title' => '<b></b>' ),
			'easyrankly_proposal_args'   => array( 'title' => 42 ),
		);

		foreach ( $cases as $code => $args ) {
			$result = $this->propose( $args );
			$this->assertWPError( $result, $code );
			$this->assertSame( $code, $result->get_error_code() );
		}

		$this->assertSame( 'easyrankly_proposal_args', easyrankly_create_proposal( array( 'ability' => self::ACTION ) )->get_error_code() );
		$query = new \WP_Query(
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => array_values( Proposals::STATUSES ),
			)
		);
		$this->assertSame( 0, $query->found_posts );
	}

	/**
	 * A newer proposal supersedes the older pending one of the same action on the same post.
	 */
	public function test_newer_proposal_supersedes_the_older(): void {
		$first  = $this->propose();
		$second = $this->propose();

		$this->assertSame( 'superseded', Proposals::get( $first )['status'] );
		$this->assertSame( 'pending', Proposals::get( $second )['status'] );
	}

	/**
	 * The daily limit counts every proposal, whoever creates it.
	 */
	public function test_daily_limit(): void {
		self::factory()->post->create_many(
			Allowlist::DAILY_LIMIT,
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => 'erankly_rejected',
				'post_date'   => current_time( 'mysql' ),
			)
		);

		$this->assertSame( 'easyrankly_daily_limit', $this->propose()->get_error_code() );
	}

	/**
	 * Applying needs the permissions of whoever accepts, not of whoever created the proposal.
	 */
	public function test_accept_needs_the_rights_of_who_accepts(): void {
		$id = $this->propose();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'ability_invalid_permissions', Proposals::accept( $id )->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Proposals::accept( $id ) );
		clean_post_cache( $this->post );
		$this->assertSame( 'New excerpt', get_post( $this->post )->post_excerpt );
	}
}
