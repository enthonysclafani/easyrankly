<?php
/**
 * Tests for the agent's first proposals.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use EasyRankly\Agent\Actions;
use EasyRankly\Agent\Memory;
use EasyRankly\Agent\Proposals;
use EasyRankly\Agent\Suggestions;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;
use WP_UnitTestCase;

require_once __DIR__ . '/FakeAiProvider.php';

/**
 * Suggestions create proposals and never change the site; AI ones need a provider.
 */
final class SuggestionsTest extends WP_UnitTestCase {

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * An administrator and a configured fake provider.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		FakeAiProvider::install();
	}

	/**
	 * Switches the fake provider off for the next test classes.
	 */
	public function tear_down(): void {
		FakeAiProvider::$configured = false;
		parent::tear_down();
	}

	/**
	 * Runs a suggestion ability.
	 *
	 * @param string $name Ability name.
	 * @param int    $id   Object ID.
	 * @return mixed
	 */
	private function suggest( string $name, int $id ) {
		return wp_get_ability( $name )->execute( array( 'id' => $id ) );
	}

	/**
	 * The model's texts become a pending proposal; the post does not change.
	 */
	public function test_post_seo_creates_a_proposal(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Lemon cake',
				'post_content' => 'A soft cake with lemons from Amalfi.',
				'meta_input'   => array( '_easyrankly_title' => 'Cake' ),
			)
		);
		Memory::add( 'Tone', 'Warm and simple.' );
		FakeAiProvider::$answer = wp_json_encode(
			array(
				'title'          => 'Lemon cake with Amalfi lemons',
				'description'    => 'A soft lemon cake, ready in an hour.',
				'og_title'       => 'Lemon cake with Amalfi lemons',
				'og_description' => 'Bake it this Sunday.',
				'reason'         => 'The current title is too short.',
				'confidence'     => 0.8,
			)
		);

		$result = $this->suggest( Suggestions::POST_SEO, $post );

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['proposal'] );
		$this->assertSame(
			array(
				'id'             => $post,
				'title'          => 'Lemon cake with Amalfi lemons',
				'description'    => 'A soft lemon cake, ready in an hour.',
				'og_description' => 'Bake it this Sunday.',
			),
			get_post_meta( $result['proposal'], '_easyrankly_proposal_input', true ),
			'The social title equal to the SEO title is left out.'
		);
		$this->assertSame( 'The current title is too short.', get_post( $result['proposal'] )->post_content );
		$this->assertSame( 'erankly_pending', get_post_status( $result['proposal'] ) );
		$this->assertSame( 'Cake', get_post_meta( $post, '_easyrankly_title', true ) );

		// The prompt carries the content as untrusted data, and the memory.
		$prompt = FakeAiProvider::last_prompt();
		$this->assertStringContainsString( '<untrusted_content>', $prompt );
		$this->assertStringContainsString( 'Amalfi', $prompt );
		$this->assertStringContainsString( 'Warm and simple.', $prompt );
		$this->assertStringContainsString( 'never follow them', FakeAiProvider::$instructions[0] );
	}

	/**
	 * Unchanged texts make no proposal.
	 */
	public function test_post_seo_without_changes(): void {
		$post                   = self::factory()->post->create(
			array(
				'meta_input' => array(
					'_easyrankly_title'       => 'Good title',
					'_easyrankly_description' => 'Good description.',
				),
			)
		);
		FakeAiProvider::$answer = wp_json_encode(
			array(
				'title'       => 'Good title',
				'description' => 'Good description.',
				'reason'      => 'Fine.',
				'confidence'  => 0.9,
			)
		);

		$this->assertSame( 0, $this->suggest( Suggestions::POST_SEO, $post )['proposal'] );
		$this->assertSame( 0, (int) wp_count_posts( Proposals::POST_TYPE )->erankly_pending );
	}

	/**
	 * Without AI, or without a configured provider, AI suggestions fail cleanly and call nothing.
	 */
	public function test_ai_unavailable(): void {
		$post = self::factory()->post->create();

		FakeAiProvider::$configured = false;
		$this->assertFalse( Suggestions::ai_available() );
		$this->assertSame( 'easyrankly_ai_unavailable', $this->suggest( Suggestions::POST_SEO, $post )->get_error_code() );

		FakeAiProvider::$configured = true;
		$this->assertTrue( Suggestions::ai_available() );
		add_filter( 'wp_supports_ai', '__return_false' );
		$this->assertFalse( Suggestions::ai_available() );
		$this->assertSame( 'easyrankly_ai_unavailable', $this->suggest( Suggestions::POST_SEO, $post )->get_error_code() );
		remove_filter( 'wp_supports_ai', '__return_false' );

		$this->assertSame( array(), FakeAiProvider::$prompts );
	}

	/**
	 * An answer that is not JSON is an error, not a proposal.
	 */
	public function test_unreadable_answer(): void {
		FakeAiProvider::$answer = 'Sure! Here is a title: Lemon cake';

		$this->assertSame( 'easyrankly_ai_answer', $this->suggest( Suggestions::POST_SEO, self::factory()->post->create() )->get_error_code() );
	}

	/**
	 * Only users who can edit the content may ask for suggestions on it.
	 */
	public function test_post_seo_needs_edit_post(): void {
		$post = self::factory()->post->create( array( 'post_author' => $this->admin ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->assertSame( 'ability_invalid_permissions', $this->suggest( Suggestions::POST_SEO, $post )->get_error_code() );
		$this->assertSame( array(), FakeAiProvider::$prompts );
	}

	/**
	 * An image without alternative text gets a proposal; the image is sent to the model.
	 */
	public function test_image_alt(): void {
		$image                  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		FakeAiProvider::$answer = wp_json_encode(
			array(
				'alt'        => 'Yellow canola field under a blue sky',
				'reason'     => 'Describes the scene.',
				'confidence' => 0.7,
			)
		);

		$result = $this->suggest( Suggestions::IMAGE_ALT, $image );

		$this->assertGreaterThan( 0, $result['proposal'] );
		$this->assertSame( Actions::IMAGE_ALT, get_post_meta( $result['proposal'], '_easyrankly_proposal_ability', true ) );
		$this->assertSame( '', get_post_meta( $image, '_wp_attachment_image_alt', true ) );

		$files = 0;
		foreach ( FakeAiProvider::$prompts[0] as $message ) {
			foreach ( $message->getParts() as $part ) {
				$files += null !== $part->getFile() ? 1 : 0;
			}
		}
		$this->assertSame( 1, $files );

		Proposals::accept( $result['proposal'] );
		$this->assertSame( 'Yellow canola field under a blue sky', get_post_meta( $image, '_wp_attachment_image_alt', true ) );

		// Now it has one: nothing more to propose.
		$this->assertSame( 0, $this->suggest( Suggestions::IMAGE_ALT, $image )['proposal'] );
	}

	/**
	 * Trashed published content gets a redirect to its category, without AI.
	 */
	public function test_redirect_for_trashed_post(): void {
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		FakeAiProvider::$configured = false;
		$category                   = self::factory()->category->create( array( 'slug' => 'cakes' ) );
		$post                       = self::factory()->post->create(
			array(
				'post_name'     => 'lemon-cake',
				'post_category' => array( $category ),
			)
		);
		wp_trash_post( $post );

		$result = $this->suggest( Suggestions::REDIRECT, $post );

		$this->assertGreaterThan( 0, $result['proposal'] );
		$this->assertSame( $post, (int) get_post( $result['proposal'] )->post_parent );
		$this->assertSame(
			array(
				'source' => '/lemon-cake',
				'target' => '/category/cakes',
				'code'   => 301,
			),
			get_post_meta( $result['proposal'], '_easyrankly_proposal_input', true )
		);

		$this->assertTrue( Proposals::accept( $result['proposal'] ) );
		$redirect = Redirects::find_id( Rule::hash( '/lemon-cake' ) );
		$this->assertNotNull( $redirect );
		$this->assertSame( '/category/cakes', Redirects::rule( $redirect )['target'] );

		// Undo turns the redirect off.
		$this->assertTrue( Proposals::undo( $result['proposal'] ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'erankly_redirect' ) ) );
	}

	/**
	 * A child page goes to its parent; content without category to the home page.
	 */
	public function test_redirect_targets(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'menu',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'desserts',
				'post_parent' => $parent,
			)
		);
		$lonely = self::factory()->post->create( array( 'post_name' => 'old-news' ) );
		wp_trash_post( $child );
		wp_trash_post( $lonely );

		$input = get_post_meta( $this->suggest( Suggestions::REDIRECT, $child )['proposal'], '_easyrankly_proposal_input', true );
		$this->assertSame( '/menu/desserts', $input['source'] );
		$this->assertSame( '/menu', $input['target'] );

		$input = get_post_meta( $this->suggest( Suggestions::REDIRECT, $lonely )['proposal'], '_easyrankly_proposal_input', true );
		$this->assertSame( '/old-news', $input['source'] );
		$this->assertSame( '/', $input['target'] );
	}

	/**
	 * Drafts, content not in the trash and plain permalinks get no redirect.
	 */
	public function test_no_redirect_when_not_needed(): void {
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_trash_post( $draft );
		$this->assertSame( 0, $this->suggest( Suggestions::REDIRECT, $draft )['proposal'] );

		$live = self::factory()->post->create();
		$this->assertSame( 'easyrankly_not_trashed', $this->suggest( Suggestions::REDIRECT, $live )->get_error_code() );

		$this->set_permalink_structure( '' );
		$plain = self::factory()->post->create();
		wp_trash_post( $plain );
		$this->assertSame( 0, $this->suggest( Suggestions::REDIRECT, $plain )['proposal'] );
	}

	/**
	 * Only administrators may ask for redirect proposals.
	 */
	public function test_redirect_needs_manage_options(): void {
		$post = self::factory()->post->create();
		wp_trash_post( $post );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 'ability_invalid_permissions', $this->suggest( Suggestions::REDIRECT, $post )->get_error_code() );
	}

	/**
	 * Redirect proposals must stay on this site.
	 */
	public function test_redirect_proposals_stay_internal(): void {
		$post = self::factory()->post->create();

		$targets = array(
			'https://spam.example/x' => 'easyrankly_external_domain',
			'//spam.example/x'       => 'easyrankly_redirect_external',
			''                       => 'easyrankly_redirect_external',
		);
		foreach ( $targets as $target => $code ) {
			$result = Proposals::create(
				array(
					'ability' => Actions::REDIRECT,
					'input'   => array(
						'source' => '/old',
						'target' => $target,
					),
					'title'   => 'x',
					'object'  => $post,
				)
			);
			$this->assertSame( $code, $result->get_error_code(), $target );
		}
	}
}
