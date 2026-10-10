<?php
/**
 * Tests for automatic redirects after slug changes.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use WP_UnitTestCase;

/**
 * Pages and terms keep their old address working after a slug change.
 */
final class SlugChangesTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks with term links, meta registered.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules();
		( new Redirects() )->register_post_type();
	}

	/**
	 * Target of the active exact rule for a source, or null.
	 *
	 * @param string $source Normalized source.
	 * @return string|null
	 */
	private function target( string $source ): ?string {
		$id = Redirects::find_id( Rule::hash( $source ) );

		return null === $id ? null : Redirects::rule( $id )['target'] ?? null;
	}

	/**
	 * Renaming a published page, or moving it under another parent, keeps the old address.
	 */
	public function test_page_slug_and_parent_change(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$page   = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'team',
			)
		);

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'people',
			)
		);
		$this->assertSame( '/people', $this->target( '/team' ) );

		wp_update_post(
			array(
				'ID'          => $page,
				'post_parent' => $parent,
			)
		);
		$this->assertSame( '/about/people', $this->target( '/people' ) );
	}

	/**
	 * Moving back to an old address deactivates the rule that would now loop, and keeps it.
	 */
	public function test_moving_back_deactivates_stale_rule(): void {
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'first',
			)
		);

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'second',
			)
		);
		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'first',
			)
		);

		$this->assertNull( $this->target( '/first' ) );
		$this->assertSame( '/first', $this->target( '/second' ) );
		$stale = Redirects::find_id( Rule::hash( '/first' ), array( 'draft' ) );
		$this->assertNotNull( $stale );
		$this->assertSame( '/second', Redirects::rule( $stale )['target'] );
	}

	/**
	 * A rule the administrator made for the new address is deactivated, not deleted.
	 */
	public function test_admin_rule_for_new_address_is_kept_inactive(): void {
		$rule = Redirects::save_exact( '/people', '/elsewhere' );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'team',
			)
		);

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'people',
			)
		);

		$this->assertSame( '/people', $this->target( '/team' ) );
		$this->assertSame( 'draft', get_post_status( $rule ) );
		$this->assertSame( '/elsewhere', Redirects::rule( $rule )['target'] );
	}

	/**
	 * Child and grandchild pages keep their old addresses when an ancestor moves.
	 */
	public function test_descendant_pages_follow_their_ancestor(): void {
		$about = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$team  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $about,
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'lead',
				'post_parent' => $team,
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => 'hidden',
				'post_parent' => $about,
			)
		);

		wp_update_post(
			array(
				'ID'        => $about,
				'post_name' => 'company',
			)
		);

		$this->assertSame( '/company', $this->target( '/about' ) );
		$this->assertSame( '/company/team', $this->target( '/about/team' ) );
		$this->assertSame( '/company/team/lead', $this->target( '/about/team/lead' ) );
		$this->assertNull( $this->target( '/about/hidden' ) );
	}

	/**
	 * Child categories keep their old archive addresses when the parent is renamed.
	 */
	public function test_descendant_terms_follow_their_ancestor(): void {
		$news  = self::factory()->category->create( array( 'slug' => 'news' ) );
		$local = self::factory()->category->create(
			array(
				'slug'   => 'local',
				'parent' => $news,
			)
		);

		wp_update_term( $news, 'category', array( 'slug' => 'updates' ) );

		$this->assertSame( '/category/updates', $this->target( '/category/news' ) );
		$this->assertSame( '/category/updates/local', $this->target( '/category/news/local' ) );
		$this->assertSame( home_url( '/category/updates/local/' ), get_term_link( $local, 'category' ) );
	}

	/**
	 * Drafts and posts (core handles their old slugs) create nothing.
	 */
	public function test_drafts_and_posts_are_skipped(): void {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_name'   => 'draft-a',
			)
		);
		$post  = self::factory()->post->create( array( 'post_name' => 'post-a' ) );

		wp_update_post(
			array(
				'ID'        => $draft,
				'post_name' => 'draft-b',
			)
		);
		wp_update_post(
			array(
				'ID'        => $post,
				'post_name' => 'post-b',
			)
		);

		$this->assertSame( array(), get_posts( array( 'post_type' => Redirects::POST_TYPE ) ) );
	}

	/**
	 * Renaming a category keeps its old archive address.
	 */
	public function test_term_slug_change(): void {
		$term = self::factory()->category->create( array( 'slug' => 'news' ) );

		wp_update_term( $term, 'category', array( 'slug' => 'updates' ) );

		$this->assertSame( '/category/updates', $this->target( '/category/news' ) );
	}

	/**
	 * With plain permalinks addresses differ only in the query string: nothing is created.
	 */
	public function test_plain_permalinks_create_nothing(): void {
		$this->set_permalink_structure( '' );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'plain-a',
			)
		);

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'plain-b',
			)
		);

		$this->assertSame( array(), get_posts( array( 'post_type' => Redirects::POST_TYPE ) ) );
	}
}
