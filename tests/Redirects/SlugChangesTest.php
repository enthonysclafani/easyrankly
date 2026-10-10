<?php
/**
 * Tests for automatic redirects after slug changes.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use EasyRankly\Redirects\SlugChanges;
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
	 * Back to a visitor on the frontend.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Output of the notice on an admin screen.
	 *
	 * @param string $screen       Screen ID.
	 * @param bool   $block_editor Whether the screen is the block editor.
	 * @return string
	 */
	private function notice( string $screen = 'dashboard', bool $block_editor = false ): string {
		set_current_screen( $screen );
		get_current_screen()->is_block_editor( $block_editor );
		ob_start();
		( new SlugChanges() )->print_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Creates a published page.
	 *
	 * @param string $name      Slug.
	 * @param int    $parent_id Parent page.
	 * @return int
	 */
	private function page( string $name, int $parent_id = 0 ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => $name,
				'post_parent' => $parent_id,
			)
		);
	}

	/**
	 * Makes every redirect fail to save, as a database error would.
	 */
	private function refuse_redirects(): void {
		add_filter(
			'wp_insert_post_empty_content',
			static fn( $maybe_empty, $post ) => Redirects::POST_TYPE === ( $post['post_type'] ?? '' ) ? true : $maybe_empty,
			10,
			2
		);
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

	/**
	 * A redirect that cannot be saved is shown once to who made the change, with a link to add it.
	 */
	public function test_failed_redirect_is_shown_once(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$page = $this->page( 'old-name' );
		$this->refuse_redirects();

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'new-name',
			)
		);

		$this->assertNull( $this->target( '/old-name' ) );
		$this->assertSame( '', $this->notice( 'page', true ), 'not in the block editor' );

		$html = $this->notice();
		$this->assertStringContainsString( 'notice notice-warning', $html );
		$this->assertStringContainsString( '<code>/old-name</code> &rarr; <code>/new-name</code>', $html );
		$this->assertStringContainsString( 'admin.php?page=easyrankly-redirects', $html );
		$this->assertSame( '', $this->notice(), 'shown once' );
		$this->assertFalse( get_transient( SlugChanges::NOTICE . $user ) );
	}

	/**
	 * Descendants beyond the limit get no redirect, and the notice says so; editors get no link.
	 */
	public function test_descendants_beyond_the_limit_are_reported(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$parent   = $this->page( 'docs' );
		$children = array();
		for ( $i = 0; $i <= SlugChanges::MAX_DESCENDANTS; $i++ ) {
			$children[] = $this->page( 'child-' . $i, $parent );
		}

		wp_update_post(
			array(
				'ID'        => $parent,
				'post_name' => 'manual',
			)
		);

		$this->assertSame( '/manual/child-0', $this->target( '/docs/child-0' ) );
		$last = SlugChanges::MAX_DESCENDANTS - 1;
		$this->assertSame( "/manual/child-$last", $this->target( "/docs/child-$last" ) );
		$this->assertNull( $this->target( '/docs/child-' . SlugChanges::MAX_DESCENDANTS ) );
		$this->assertCount( SlugChanges::MAX_DESCENDANTS + 1, $children );

		$html = $this->notice();
		$this->assertStringContainsString( 'Only the first ' . SlugChanges::MAX_DESCENDANTS . ' pages or terms under /manual', $html );
		$this->assertStringNotContainsString( 'easyrankly-redirects', $html );
	}

	/**
	 * Without a logged-in user (WP-CLI, cron) nobody would see a notice: nothing is kept.
	 */
	public function test_no_notice_without_a_user(): void {
		$page = $this->page( 'cli-old' );
		$this->refuse_redirects();

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'cli-new',
			)
		);

		$this->assertNull( $this->target( '/cli-old' ) );
		$this->assertFalse( get_transient( SlugChanges::NOTICE . '0' ) );
	}

	/**
	 * Changes that work leave no notice.
	 */
	public function test_no_notice_when_everything_is_saved(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$page = $this->page( 'fine-old' );

		wp_update_post(
			array(
				'ID'        => $page,
				'post_name' => 'fine-new',
			)
		);

		$this->assertSame( '/fine-new', $this->target( '/fine-old' ) );
		$this->assertFalse( get_transient( SlugChanges::NOTICE . $user ) );
		$this->assertSame( '', $this->notice() );
	}
}
