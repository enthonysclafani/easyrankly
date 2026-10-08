<?php
/**
 * Tests for the triggers of the agent: editorial events and the dashboard analysis.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Admin\Page;
use EasyRankly\Agent\Allowlist;
use EasyRankly\Agent\Analysis;
use EasyRankly\Agent\Proposals;
use EasyRankly\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

require_once __DIR__ . '/FakeAiProvider.php';

/**
 * The agent wakes up on editorial events and when the dashboard runs it, never on its own.
 */
final class TriggersTest extends WP_UnitTestCase {

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * A fresh REST server, an administrator, a configured fake provider and no analysis due.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->set_permalink_structure( '/%postname%/' );

		FakeAiProvider::install( true, (string) wp_json_encode( $this->answer( 'Better title' ) ) );
		update_option(
			Analysis::OPTION,
			array(
				'recent'     => array(),
				'cursor'     => 0,
				'handled'    => array(),
				'scanned_at' => time(),
			),
			false
		);
	}

	/**
	 * Switches the fake provider off and resets the REST server.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server             = null;
		FakeAiProvider::$configured = false;
		parent::tear_down();
	}

	/**
	 * An answer of the fake model for the SEO texts.
	 *
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function answer( string $title ): array {
		return array(
			'title'       => $title,
			'description' => '',
			'reason'      => 'Clearer.',
			'confidence'  => 0.6,
		);
	}

	/**
	 * Proposals of an ability on an object.
	 *
	 * @param int    $target  Post or image ID.
	 * @param string $ability Ability name.
	 * @param string $status  Status key.
	 * @return int[]
	 */
	private function proposals( int $target, string $ability, string $status = 'pending' ): array {
		return get_posts(
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => Proposals::STATUSES[ $status ],
				'post_parent' => $target,
				'fields'      => 'ids',
				'meta_key'    => '_easyrankly_proposal_ability', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test.
				'meta_value'  => $ability, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test.
			)
		);
	}

	/**
	 * Trashing published content proposes a redirect, whoever trashes it; restoring it supersedes the proposal.
	 */
	public function test_trash_and_restore(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_name'   => 'spring-menu',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
			)
		);

		wp_trash_post( $post );
		wp_trash_post( $draft );

		$pending = $this->proposals( $post, Actions::REDIRECT );
		$this->assertCount( 1, $pending );
		$this->assertSame( array(), $this->proposals( $draft, Actions::REDIRECT ) );
		$this->assertSame( array(), FakeAiProvider::$prompts, 'Redirect proposals need no AI.' );

		// The author cannot apply it: redirects need manage_options.
		$this->assertSame( 403, Proposals::accept( $pending[0] )->get_error_data()['status'] );

		wp_untrash_post( $post );
		$this->assertSame( 'erankly_superseded', get_post_status( $pending[0] ) );
	}

	/**
	 * Publishing remembers the content once; updates do not.
	 */
	public function test_publish_remembers_new_content(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( array(), Analysis::state()['recent'] );

		wp_publish_post( $draft );
		wp_update_post(
			array(
				'ID'         => $draft,
				'post_title' => 'Updated',
			)
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( array( $draft, $page ), Analysis::state()['recent'] );
		$this->assertSame( array(), FakeAiProvider::$prompts, 'Publishing calls no external service.' );
	}

	/**
	 * Recent content is analyzed first, its images too, one AI request per step.
	 */
	public function test_step_analyzes_recent_content_and_images(): void {
		$post  = self::factory()->post->create( array( 'post_title' => 'Lemon cake' ) );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );

		$first = Analysis::step();
		$this->assertFalse( $first['done'] );
		$this->assertSame( 'post', $first['item']['type'] );
		$this->assertSame( $post, $first['item']['id'] );
		$this->assertGreaterThan( 0, $first['proposal'] );
		$this->assertCount( 1, FakeAiProvider::$prompts );

		FakeAiProvider::$answer = (string) wp_json_encode(
			array(
				'alt'        => 'A canola field',
				'reason'     => 'Describes it.',
				'confidence' => 0.7,
			)
		);
		$second                 = Analysis::step();
		$this->assertSame( 'image', $second['item']['type'] );
		$this->assertSame( $image, $second['item']['id'] );
		$this->assertCount( 1, $this->proposals( $image, Actions::IMAGE_ALT ) );

		$third = Analysis::step();
		$this->assertTrue( $third['done'] );
		$this->assertSame( 'up_to_date', $third['reason'] );
		$this->assertSame( array(), Analysis::state()['recent'] );
		$this->assertCount( 2, FakeAiProvider::$prompts );
	}

	/**
	 * A full pass walks the published content in batches, skips what has a description or a
	 * proposal, never asks twice about the same item, and then waits 24 hours.
	 */
	public function test_full_pass(): void {
		$described = self::factory()->post->create( array( 'meta_input' => array( '_easyrankly_description' => 'Fine.' ) ) );
		$ids       = self::factory()->post->create_many( Analysis::BATCH + 2 );
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_option( Analysis::OPTION, array( 'scanned_at' => time() ), false );

		// Not due yet; forcing starts a pass.
		$this->assertSame( 'up_to_date', Analysis::step()['reason'] );

		// The model keeps the current texts: no proposal, but each post is asked about once.
		FakeAiProvider::$answer = (string) wp_json_encode( $this->answer( '' ) );
		$asked                  = array();
		$steps                  = 0;
		do {
			$step = Analysis::step( 0 === $steps );
			if ( null !== $step['item'] ) {
				$asked[] = $step['item']['id'];
			}
			++$steps;
		} while ( ! $step['done'] && $steps < 100 );

		$this->assertSame( 'finished', $step['reason'] );
		$this->assertSame( $ids, $asked );
		$this->assertNotContains( $described, $asked );
		$this->assertSame( 0, Analysis::state()['cursor'] );
		$this->assertFalse( Analysis::due() );
		$this->assertSame( 'up_to_date', Analysis::step()['reason'] );
	}

	/**
	 * Without AI the analysis stops at once; at the daily limit too.
	 */
	public function test_step_stops_without_ai_or_at_the_limit(): void {
		self::factory()->post->create();

		FakeAiProvider::$configured = false;
		$this->assertSame( 'ai_unavailable', Analysis::step()['reason'] );

		FakeAiProvider::$configured = true;
		self::factory()->post->create_many(
			Allowlist::DAILY_LIMIT,
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => 'erankly_rejected',
			)
		);
		$this->assertSame( 'daily_limit', Analysis::step()['reason'] );
		$this->assertSame( array(), FakeAiProvider::$prompts );
	}

	/**
	 * Opening the dashboard deletes old rejected and superseded proposals, and nothing else.
	 */
	public function test_cleanup_on_dashboard_load(): void {
		$old    = gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		$recent = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
		$make   = static function ( string $status, string $modified ): int {
			$id = self::factory()->post->create(
				array(
					'post_type'   => Proposals::POST_TYPE,
					'post_status' => $status,
				)
			);
			global $wpdb;
			// Core rewrites post_modified on every save; the test needs an old one.
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $modified ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
			clean_post_cache( $id );
			return $id;
		};

		$gone = array( $make( 'erankly_rejected', $old ), $make( 'erankly_superseded', $old ) );
		$kept = array( $make( 'erankly_rejected', $recent ), $make( 'erankly_accepted', $old ), $make( 'erankly_pending', $old ) );
		( new Page() )->on_load();

		foreach ( $gone as $id ) {
			$this->assertNull( get_post( $id ) );
		}
		foreach ( $kept as $id ) {
			$this->assertNotNull( get_post( $id ) );
		}
	}

	/**
	 * The status and step routes need manage_options; the status tells the dashboard what to do.
	 */
	public function test_rest_routes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/easyrankly/v1/agent' ) )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/easyrankly/v1/agent/step' ) )->get_status() );

		wp_set_current_user( $this->admin );
		self::factory()->post->create();
		$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/easyrankly/v1/agent' ) )->get_data();

		$this->assertTrue( $status['ai'] );
		$this->assertFalse( $status['auto'] );
		$this->assertSame( 1, $status['recent'] );
		$this->assertFalse( $status['due'] );

		$step = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/easyrankly/v1/agent/step' ) )->get_data();
		$this->assertSame( 'processed', $step['reason'] );
	}

	/**
	 * The opt-in is a setting saved alone, without touching the others.
	 */
	public function test_auto_setting_partial_update(): void {
		update_option( Settings::OPTION, array( 'title_separator' => '|' ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( Settings::OPTION => array( 'agent_auto' => true ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$this->assertTrue( Settings::value( 'agent_auto' ) );
		$this->assertSame( '|', Settings::value( 'title_separator' ) );
	}
}
