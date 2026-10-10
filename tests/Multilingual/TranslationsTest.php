<?php
/**
 * Tests for the language of posts and the groups of translations.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Multilingual;

use EasyRankly\Multilingual\Translations;
use WP_UnitTestCase;

/**
 * Groups stay valid after every write: one post per language, one type, at least two posts.
 */
final class TranslationsTest extends WP_UnitTestCase {
	use WithLanguages;

	/**
	 * Three languages, Italian first (default).
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_languages( 'it', 'en', 'fr' );
	}

	/**
	 * Content without a language, or with a language removed from the settings, is in the default language.
	 */
	public function test_language_falls_back_to_default(): void {
		$post    = self::factory()->post->create();
		$removed = self::factory()->post->create();
		wp_set_object_terms( $removed, 'de', Translations::LANGUAGE );

		$this->assertSame( 'it', Translations::language( $post ) );
		$this->assertSame( 'it', Translations::language( $removed ) );
		$this->assertSame( array( 'it' => $post ), Translations::of( $post ) );
	}

	/**
	 * A language that cannot be stored stops the link, and the posts keep their groups.
	 */
	public function test_link_reports_errors_and_changes_no_group(): void {
		$it = self::factory()->post->create();
		$fr = self::factory()->post->create();
		add_filter(
			'pre_insert_term',
			static fn( $term, $taxonomy ) => Translations::LANGUAGE === $taxonomy && 'fr' === $term ? new \WP_Error( 'blocked', 'Blocked' ) : $term,
			10,
			2
		);

		$result = Translations::link(
			array(
				'it' => $it,
				'fr' => $fr,
			)
		);

		$this->assertWPError( $result );
		$this->assertNull( Translations::group( $it ) );
		$this->assertNull( Translations::group( $fr ) );
	}

	/**
	 * Setting a language stores its term; unknown languages and untranslatable types are refused.
	 */
	public function test_set_language(): void {
		$post       = self::factory()->post->create();
		$attachment = self::factory()->attachment->create();

		$this->assertTrue( Translations::set_language( $post, 'en' ) );
		$this->assertSame( 'en', Translations::language( $post ) );
		$this->assertSame( 'easyrankly_invalid_language', $this->error_code( Translations::set_language( $post, 'de' ) ) );
		$this->assertSame( 'easyrankly_untranslatable', $this->error_code( Translations::set_language( $attachment, 'en' ) ) );
		$this->assertSame( 'easyrankly_invalid_post', $this->error_code( Translations::set_language( 999999, 'en' ) ) );
	}

	/**
	 * Linking sets languages and every member sees the same map.
	 */
	public function test_link_creates_a_group(): void {
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();

		$this->assertTrue(
			Translations::link(
				array(
					'it' => $it,
					'en' => $en,
				)
			)
		);

		$expected = array(
			'it' => $it,
			'en' => $en,
		);
		$this->assertSame( $expected, Translations::of( $it ) );
		$this->assertSame( $expected, Translations::of( $en ) );
		$this->assertSame( 'en', Translations::language( $en ) );
		$this->assertSame( Translations::group( $it )->term_id, Translations::group( $en )->term_id );
	}

	/**
	 * A translation cannot take the language of another member of its group.
	 */
	public function test_language_already_in_group_is_refused(): void {
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);

		$this->assertSame( 'easyrankly_language_taken', $this->error_code( Translations::set_language( $en, 'it' ) ) );
		$this->assertTrue( Translations::set_language( $en, 'fr' ) );
		$this->assertSame(
			array(
				'it' => $it,
				'fr' => $en,
			),
			Translations::of( $it )
		);
	}

	/**
	 * Relinking replaces the group: displaced posts leave it, posts from other groups join it,
	 * and groups left with one post disappear.
	 */
	public function test_relink_moves_posts_and_removes_single_groups(): void {
		$it  = self::factory()->post->create();
		$en  = self::factory()->post->create();
		$it2 = self::factory()->post->create();
		$fr  = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);
		Translations::link(
			array(
				'it' => $it2,
				'fr' => $fr,
			)
		);
		$other_group = Translations::group( $it2 )->term_id;

		Translations::link(
			array(
				'it' => $it,
				'fr' => $fr,
			)
		);

		$this->assertSame(
			array(
				'it' => $it,
				'fr' => $fr,
			),
			Translations::of( $it )
		);
		$this->assertNull( Translations::group( $en ) );
		$this->assertSame( array( 'en' => $en ), Translations::of( $en ) );
		$this->assertNull( Translations::group( $it2 ) );
		$this->assertNull( get_term( $other_group, Translations::GROUP ) );
	}

	/**
	 * A map with one post unlinks it; the remaining single post loses its group too.
	 */
	public function test_single_post_map_unlinks(): void {
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);
		$group = Translations::group( $it )->term_id;

		$this->assertTrue( Translations::link( array( 'en' => $en ) ) );

		$this->assertNull( Translations::group( $en ) );
		$this->assertNull( Translations::group( $it ) );
		$this->assertNull( get_term( $group, Translations::GROUP ) );
	}

	/**
	 * Invalid maps change nothing.
	 */
	public function test_invalid_maps_are_refused(): void {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'easyrankly_empty_group', $this->error_code( Translations::link( array() ) ) );
		$this->assertSame( 'easyrankly_invalid_language', $this->error_code( Translations::link( array( 'de' => $post ) ) ) );
		$this->assertSame(
			'easyrankly_repeated_post',
			$this->error_code(
				Translations::link(
					array(
						'it' => $post,
						'en' => $post,
					)
				)
			)
		);
		$this->assertSame(
			'easyrankly_mixed_types',
			$this->error_code(
				Translations::link(
					array(
						'it' => $post,
						'en' => $page,
					)
				)
			)
		);
		$this->assertNull( Translations::group( $post ) );
		$this->assertSame( 'it', Translations::language( $page ) );
	}

	/**
	 * Deleting a post removes a group that would be left with one post, and keeps larger ones.
	 */
	public function test_deleting_a_post_cleans_its_group(): void {
		$it = self::factory()->post->create();
		$en = self::factory()->post->create();
		$fr = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
				'fr' => $fr,
			)
		);

		wp_delete_post( $fr, true );
		$this->assertSame(
			array(
				'it' => $it,
				'en' => $en,
			),
			Translations::of( $it )
		);

		$group = Translations::group( $it )->term_id;
		wp_delete_post( $en, true );

		$this->assertNull( Translations::group( $it ) );
		$this->assertNull( get_term( $group, Translations::GROUP ) );
	}

	/**
	 * Reading a group again costs no query once the posts' own terms are loaded.
	 */
	public function test_group_members_are_cached(): void {
		global $wpdb;

		$it = self::factory()->post->create();
		$en = self::factory()->post->create();
		Translations::link(
			array(
				'it' => $it,
				'en' => $en,
			)
		);
		Translations::of( $it );
		get_the_terms( $en, Translations::LANGUAGE );
		get_the_terms( $en, Translations::GROUP );

		$queries = $wpdb->num_queries;
		Translations::of( $it );
		Translations::of( $en );

		$this->assertSame( $queries, $wpdb->num_queries );
	}

	/**
	 * Error code of a result, or empty when it is not an error.
	 *
	 * @param mixed $result Result.
	 * @return string
	 */
	private function error_code( $result ): string {
		return is_wp_error( $result ) ? (string) $result->get_error_code() : '';
	}
}
