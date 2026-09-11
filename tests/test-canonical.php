<?php
/**
 * Canonical URL resolution.
 *
 * erankly_get_canonical() memos its result in a function-static, so each
 * request context runs in its own process.
 */

final class ERankly_Canonical_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_custom_post_meta_on_singular(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_erankly_canonical', 'https://example.org/custom-canonical/' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular() );
		$this->assertSame( 'https://example.org/custom-canonical/', erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_falls_back_to_the_permalink_on_singular(): void {
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$permalink = get_permalink( $post_id );
		$this->go_to( $permalink );

		$this->assertTrue( is_singular() );
		$this->assertSame( $permalink, erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_home_url_on_the_front_page(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_front_page() );
		$this->assertSame( home_url( '/' ), erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_the_posts_page_permalink(): void {
		$front_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Front',
			)
		);
		$posts_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Blog',
			)
		);

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $posts_id );

		$this->go_to( get_permalink( $posts_id ) );

		$this->assertTrue( is_home() );
		$this->assertFalse( is_front_page() );
		$this->assertSame( get_permalink( $posts_id ), erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_the_term_link_and_custom_term_meta(): void {
		$term_id = self::factory()->category->create( array( 'name' => 'Canonical category' ) );
		$link    = get_term_link( (int) $term_id );
		$this->assertIsString( $link );

		$this->go_to( $link );
		$this->assertTrue( is_category() );
		$this->assertSame( $link, erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_custom_term_meta(): void {
		$term_id = self::factory()->category->create( array( 'name' => 'Meta category' ) );
		update_term_meta( $term_id, '_erankly_canonical', 'https://example.org/term-canonical/' );

		$link = get_term_link( (int) $term_id );
		$this->assertIsString( $link );
		$this->go_to( $link );

		$this->assertTrue( is_category() );
		$this->assertSame( 'https://example.org/term-canonical/', erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_uses_the_search_link(): void {
		$this->go_to( home_url( '/?s=easyrankly-canonical' ) );

		$this->assertTrue( is_search() );
		$this->assertSame( get_search_link( get_search_query() ), erankly_get_canonical() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_canonical_is_empty_on_a_404(): void {
		$this->go_to( home_url( '/this-page-does-not-exist-' . wp_generate_password( 8, false ) . '/' ) );

		$this->assertTrue( is_404() );
		$this->assertSame( '', erankly_get_canonical() );
	}
}
