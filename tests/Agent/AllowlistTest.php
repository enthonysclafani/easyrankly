<?php
/**
 * Tests for the deterministic checks on proposals.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Allowlist;
use EasyRankly\Agent\Proposals;
use WP_UnitTestCase;

/**
 * Only allowed actions with valid, clean input become proposals; stale proposals are never applied.
 */
final class AllowlistTest extends WP_UnitTestCase {

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
	 * An administrator and a post with an SEO title.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->post  = self::factory()->post->create(
			array(
				'post_author' => $this->admin,
				'meta_input'  => array( '_easyrankly_title' => 'Old title' ),
			)
		);
	}

	/**
	 * Proposes a new SEO title for the test post.
	 *
	 * @param string $title New title.
	 * @return int|\WP_Error
	 */
	private function propose( string $title = 'New title' ) {
		return Proposals::create(
			array(
				'ability' => Actions::POST_SEO,
				'input'   => array(
					'id'    => $this->post,
					'title' => $title,
				),
				'title'   => 'Better title',
			)
		);
	}

	/**
	 * Abilities outside the allowlist are refused, even read-only or core ones.
	 */
	public function test_actions_outside_the_allowlist_are_refused(): void {
		foreach ( array( 'easyrankly/get-post-seo', 'core/get-site-info', 'easyrankly/delete-post', 'plugin/run-code' ) as $name ) {
			$result = Allowlist::validate( $name, array( 'id' => $this->post ) );
			$this->assertWPError( $result, $name );
			$this->assertSame( 'easyrankly_not_allowed', $result->get_error_code(), $name );
		}

		$this->assertSame( array( Actions::POST_SEO ), Allowlist::ACTIONS );
	}

	/**
	 * Input outside the ability schema is refused.
	 */
	public function test_input_outside_the_schema_is_refused(): void {
		$cases = array(
			array( 'title' => 'x' ),
			array(
				'id'    => $this->post,
				'title' => array( 'x' ),
			),
			array(
				'id'      => $this->post,
				'noindex' => true,
			),
			array(
				'id'          => $this->post,
				'description' => str_repeat( 'a', 401 ),
			),
		);

		foreach ( $cases as $input ) {
			$this->assertSame( 'ability_invalid_input', Allowlist::validate( Actions::POST_SEO, $input )->get_error_code() );
		}
	}

	/**
	 * Texts with markup or links to other sites are refused; links to this site pass.
	 */
	public function test_markup_and_external_links_are_refused(): void {
		$check = static fn( string $text ) => Allowlist::validate(
			Actions::POST_SEO,
			array(
				'id'          => 1,
				'description' => $text,
			)
		);

		$this->assertSame( 'easyrankly_markup', $check( 'Buy <a href="#">now</a>' )->get_error_code() );
		$this->assertSame( 'easyrankly_markup', $check( "Tab\x07bell" )->get_error_code() );
		$this->assertSame( 'easyrankly_external_domain', $check( 'Visit https://spam.example/offer today' )->get_error_code() );
		$this->assertSame( 'easyrankly_external_domain', $check( 'Visit www.spam.example today' )->get_error_code() );
		$this->assertSame( 'easyrankly_external_domain', $check( 'See ' . home_url( '/' ) . ' or http://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '.evil.example/' )->get_error_code() );

		$this->assertIsArray( $check( 'Read more on ' . home_url( '/recipes/' ) ) );
		$this->assertIsArray( $check( 'Fresh lemons, 100% organic: order now!' ) );
	}

	/**
	 * After the daily limit no proposal is created; yesterday's proposals do not count.
	 */
	public function test_daily_limit(): void {
		self::factory()->post->create_many(
			Allowlist::DAILY_LIMIT,
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => 'erankly_rejected',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);
		$this->assertIsInt( $this->propose() );

		self::factory()->post->create_many(
			Allowlist::DAILY_LIMIT - 1,
			array(
				'post_type'   => Proposals::POST_TYPE,
				'post_status' => 'erankly_rejected',
				'post_date'   => current_time( 'mysql' ),
			)
		);

		$result = $this->propose();
		$this->assertWPError( $result );
		$this->assertSame( 'easyrankly_daily_limit', $result->get_error_code() );
	}

	/**
	 * A proposal whose content changed is superseded on accept and changes nothing.
	 */
	public function test_changed_content_supersedes_the_proposal(): void {
		$id = $this->propose();
		update_post_meta( $this->post, '_easyrankly_title', 'Edited by a person' );
		wp_set_current_user( $this->admin );

		$result = Proposals::accept( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'easyrankly_proposal_superseded', $result->get_error_code() );
		$this->assertSame( 'erankly_superseded', get_post_status( $id ) );
		$this->assertSame( 'Edited by a person', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * Changes to fields the proposal does not touch do not supersede it.
	 */
	public function test_unrelated_changes_do_not_supersede(): void {
		$id = $this->propose();
		update_post_meta( $this->post, '_easyrankly_description', 'Another field' );
		wp_set_current_user( $this->admin );

		$this->assertTrue( Proposals::accept( $id ) );
		$this->assertSame( 'New title', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * A newer proposal for the same content and action supersedes the pending one.
	 */
	public function test_newer_proposal_supersedes_the_older(): void {
		$first  = $this->propose( 'First' );
		$second = $this->propose( 'Second' );
		$other  = self::factory()->post->create();
		$third  = Proposals::create(
			array(
				'ability' => Actions::POST_SEO,
				'input'   => array(
					'id'    => $other,
					'title' => 'Other post',
				),
				'title'   => 'Other',
			)
		);

		$this->assertSame( 'erankly_superseded', get_post_status( $first ) );
		$this->assertSame( 'erankly_pending', get_post_status( $second ) );
		$this->assertSame( 'erankly_pending', get_post_status( $third ) );
	}

	/**
	 * Undo refuses to overwrite changes made after the proposal was applied.
	 */
	public function test_undo_refuses_after_later_changes(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );
		Proposals::accept( $id );
		update_post_meta( $this->post, '_easyrankly_title', 'Later change' );

		$result = Proposals::undo( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'easyrankly_proposal_changed', $result->get_error_code() );
		$this->assertSame( 'erankly_accepted', get_post_status( $id ) );
		$this->assertSame( 'Later change', get_post_meta( $this->post, '_easyrankly_title', true ) );
	}

	/**
	 * Edited values pass the same checks as the agent's.
	 */
	public function test_edited_values_are_checked_too(): void {
		$id = $this->propose();
		wp_set_current_user( $this->admin );

		$result = Proposals::accept( $id, array( 'title' => 'Go to https://spam.example' ) );

		$this->assertSame( 'easyrankly_external_domain', $result->get_error_code() );
		$this->assertSame( 'erankly_pending', get_post_status( $id ) );
	}
}
