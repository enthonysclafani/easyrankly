<?php
/**
 * Tests for the project memory.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Memory;
use EasyRankly\Agent\Proposals;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Memory entries are administrator-only, round-trip through Markdown and grow from decisions.
 */
final class MemoryTest extends WP_UnitTestCase {

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * A fresh REST server and an administrator.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
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
	 * Entries are managed through the core routes, by administrators only, with validation.
	 */
	public function test_rest_crud_needs_manage_options(): void {
		$id = Memory::add( 'Tone', 'Friendly, never pushy.' );

		$this->assertSame( 401, $this->request( 'GET', '/wp/v2/easyrankly-memory' )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', "/wp/v2/easyrankly-memory/{$id}" )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/wp/v2/easyrankly-memory' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/wp/v2/easyrankly-memory', array( 'title' => 'x' ) )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/easyrankly-memory' )->get_status() );

		$response = $this->request(
			'POST',
			'/wp/v2/easyrankly-memory',
			array(
				'title'   => 'Audience',
				'content' => 'Home cooks in Italy.',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 201, $response->get_status() );

		$this->assertSame( 400, $this->request( 'POST', '/wp/v2/easyrankly-memory', array( 'content' => 'No name' ) )->get_status() );
		$this->assertSame(
			400,
			$this->request(
				'POST',
				"/wp/v2/easyrankly-memory/{$id}",
				array( 'content' => str_repeat( 'a', Memory::MAX_LENGTH + 1 ) )
			)->get_status()
		);
	}

	/**
	 * The agent reads active entries only, through a read-only ability.
	 */
	public function test_get_memory_ability(): void {
		Memory::add( 'Tone', 'Friendly.' );
		self::factory()->post->create(
			array(
				'post_type'    => Memory::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Old rule',
				'post_content' => 'Ignored.',
			)
		);

		$ability = wp_get_ability( 'easyrankly/get-memory' );
		$this->assertTrue( $ability->get_meta_item( 'annotations' )['readonly'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $ability->execute() );

		wp_set_current_user( $this->admin );
		$data = $ability->execute();

		$this->assertSame( array( 'Tone' ), array_column( $data['entries'], 'title' ) );
		$this->assertSame( 'Friendly.', $data['entries'][0]['content'] );
		$this->assertSame( Actions::POST_SEO, $data['acceptance'][0]['ability'] );
	}

	/**
	 * Export then import gives back the same entries; importing again creates nothing.
	 */
	public function test_markdown_round_trip_is_idempotent(): void {
		Memory::add( 'Tone', "Friendly.\n\n- Short sentences\n- No jargon" );
		Memory::add( 'Pages', "```\n## not a heading\n```\nPillar: /recipes/" );

		$markdown = Memory::export();
		$this->assertStringContainsString( "## Tone\n\nFriendly.", $markdown );

		$this->assertSame(
			array(
				array(
					'title'   => 'Tone',
					'content' => "Friendly.\n\n- Short sentences\n- No jargon",
				),
				array(
					'title'   => 'Pages',
					'content' => "```\n## not a heading\n```\nPillar: /recipes/",
				),
			),
			Memory::parse( $markdown )
		);

		$result = Memory::import( $markdown );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 2, $result['skipped'] );

		$result = Memory::import( "# Notes\nintro ignored\n\n## Competitors ##\nTrattoria Rossi\r\n\r\n## Tone\nDifferent text" );
		$this->assertSame( 2, $result['created'] );
		$this->assertCount( 4, Memory::entries() );
	}

	/**
	 * Long files are imported in batches, each call resuming where the previous stopped.
	 */
	public function test_import_in_batches(): void {
		$markdown = '';
		for ( $i = 1; $i <= Memory::IMPORT_BATCH + 5; $i++ ) {
			$markdown .= "## Fact {$i}\nText {$i}\n";
		}

		$first = Memory::import( $markdown );
		$this->assertSame( Memory::IMPORT_BATCH, $first['created'] );
		$this->assertSame( Memory::IMPORT_BATCH, $first['next'] );
		$this->assertSame( Memory::IMPORT_BATCH + 5, $first['total'] );

		$second = Memory::import( $markdown, $first['next'] );
		$this->assertSame( 5, $second['created'] );
		$this->assertSame( $second['total'], $second['next'] );
	}

	/**
	 * Import and export routes need manage_options; invalid entries are reported, not stored.
	 */
	public function test_rest_import_and_export(): void {
		$this->assertSame( 401, $this->request( 'GET', '/easyrankly/v1/memory/export' )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', '/easyrankly/v1/memory/import', array( 'markdown' => "## A\nB" ) )->get_status() );

		wp_set_current_user( $this->admin );
		$response = $this->request( 'POST', '/easyrankly/v1/memory/import', array( 'markdown' => "## A\nB\n## Too long\n" . str_repeat( 'x', Memory::MAX_LENGTH + 1 ) ) );
		$this->assertSame( 1, $response->get_data()['created'] );
		$this->assertCount( 1, $response->get_data()['errors'] );

		$this->assertStringContainsString( "## A\n\nB", $this->request( 'GET', '/easyrankly/v1/memory/export' )->get_data()['markdown'] );
	}

	/**
	 * A rejection with a reason and an edited proposal become memory entries.
	 */
	public function test_decisions_become_memory(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Lemon cake' ) );
		$make = static fn( string $title ): int => Proposals::create(
			array(
				'ability' => Actions::POST_SEO,
				'input'   => array(
					'id'          => $post,
					'title'       => $title,
					'description' => 'Proposed description',
				),
				'title'   => 'Better snippet',
			)
		);
		wp_set_current_user( $this->admin );

		Proposals::reject( $make( 'One' ) );
		$this->assertSame( array(), Memory::entries(), 'A rejection without a reason teaches nothing.' );

		Proposals::reject( $make( 'Two' ), 'Never use exclamation marks.' );
		$entries = Memory::entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'Rejected: Better snippet', $entries[0]['title'] );
		$this->assertStringContainsString( 'Lemon cake', $entries[0]['content'] );
		$this->assertStringContainsString( 'Never use exclamation marks.', $entries[0]['content'] );

		Proposals::accept( $make( 'Three' ), array( 'title' => 'Lemon cake recipe' ) );
		$entries = Memory::entries();
		$this->assertCount( 2, $entries );
		$this->assertSame( 'Edited: Better snippet', $entries[0]['title'] );
		$this->assertStringContainsString( 'SEO title: proposed "Three", changed to "Lemon cake recipe"', $entries[0]['content'] );
		$this->assertStringNotContainsString( 'Meta description', $entries[0]['content'] );

		Proposals::accept( $make( 'Four' ) );
		$this->assertCount( 2, Memory::entries(), 'Accepting as proposed teaches nothing.' );
	}

	/**
	 * Revisions keep the history of an entry.
	 */
	public function test_revisions_keep_history(): void {
		$id = Memory::add( 'Tone', 'First' );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'Second',
			)
		);

		$this->assertNotEmpty( wp_get_post_revisions( $id ) );
	}
}
