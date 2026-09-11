<?php
/** Robots meta directives, robots.txt output and virtual sitemap routes. */

final class ERankly_Robots_Output_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_default_helpers();
		erankly_load_content_helpers();
		// includes/robots.php is loaded unconditionally by easyrankly.php.
	}

	public function tear_down(): void {
		update_option( 'blog_public', 1 );
		erankly_clear_settings_cache();
		parent::tear_down();
	}

	/**
	 * Injects a single row into a global entity metadata map for the request.
	 */
	private function inject_entity_row( string $setting_key, string $entity, array $row ): callable {
		$filter = static function ( array $map, string $key ) use ( $setting_key, $entity, $row ): array {
			if ( $key === $setting_key ) {
				$map[ $entity ] = array_merge(
					isset( $map[ $entity ] ) && is_array( $map[ $entity ] ) ? $map[ $entity ] : array(),
					$row
				);
			}

			return $map;
		};

		add_filter( 'erankly_global_entity_meta_map', $filter, 10, 2 );

		return $filter;
	}

	public function test_is_paginated_content_request_tracks_page_and_cpage_vars(): void {
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( erankly_is_paginated_content_request() );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'First page<!--nextpage-->Second page',
			)
		);
		$this->assertIsInt( $post_id );

		$this->go_to( add_query_arg( array( 'p' => $post_id, 'page' => 2 ), home_url( '/' ) ) );
		$this->assertTrue( is_singular() );
		$this->assertTrue( erankly_is_paginated_content_request() );

		// Comment pagination (cpage) is tracked separately from singular page pagination.
		$this->go_to( get_permalink( $post_id ) );
		$this->assertFalse( erankly_is_paginated_content_request() );
		$GLOBALS['wp_query']->query['cpage'] = 2;
		$this->assertTrue( erankly_is_paginated_content_request() );
		unset( $GLOBALS['wp_query']->query['cpage'] );
	}

	public function test_filter_wp_robots_indexes_a_clean_post_by_default(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$robots = erankly_filter_wp_robots( array() );

		$this->assertTrue( $robots['index'] );
		$this->assertTrue( $robots['follow'] );
		$this->assertArrayNotHasKey( 'noindex', $robots );
	}

	public function test_filter_wp_robots_honours_per_post_directives(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
		update_post_meta( $post_id, '_erankly_follow_directive', 'nofollow' );
		$this->go_to( get_permalink( $post_id ) );

		$robots = erankly_filter_wp_robots( array( 'index' => true, 'follow' => true ) );

		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertArrayNotHasKey( 'index', $robots );
		$this->assertArrayNotHasKey( 'follow', $robots );
	}

	public function test_filter_wp_robots_honours_the_global_post_type_directive(): void {
		$post_id = $this->get_a_post();
		$this->go_to( get_permalink( $post_id ) );

		$filter = $this->inject_entity_row( 'global_post_type_meta', 'post', array( 'noindex' => true ) );

		try {
			$robots = erankly_filter_wp_robots( array() );
			$this->assertTrue( $robots['noindex'] );
			$this->assertArrayNotHasKey( 'index', $robots );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_filter_wp_robots_noindexes_paginated_archives_when_enabled(): void {
		erankly_update_plugin_settings( array( 'noindex_paginated' => 1 ) );
		erankly_clear_settings_cache();

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_paged() );
		// Simulate the paged-archive state the filter reads via is_paged().
		$GLOBALS['wp_query']->is_paged = true;

		$robots = erankly_filter_wp_robots( array() );

		$this->assertTrue( $robots['noindex'] );
		$this->assertArrayNotHasKey( 'index', $robots );
	}

	public function test_filter_wp_robots_cannot_be_bypassed_on_a_private_site(): void {
		update_option( 'blog_public', 0 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_erankly_index_directive', 'index' );
		$this->go_to( get_permalink( $post_id ) );

		$robots = erankly_filter_wp_robots( array() );

		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertArrayNotHasKey( 'index', $robots );
		$this->assertArrayNotHasKey( 'follow', $robots );
	}

	public function test_apply_current_global_entity_robots_uses_the_404_row(): void {
		$this->go_to( home_url( '/?p=99999999' ) );
		$this->assertTrue( is_404() );

		$filter = $this->inject_entity_row( 'global_special_meta', '404', array( 'archive_directive' => 'noarchive' ) );

		try {
			$robots = erankly_apply_current_global_entity_robots( array() );
			$this->assertTrue( $robots['noarchive'] );
		} finally {
			remove_filter( 'erankly_global_entity_meta_map', $filter, 10 );
		}
	}

	public function test_apply_current_object_robots_overrides_maps_post_meta(): void {
		$post_id = $this->get_a_post();
		$this->go_to( get_permalink( $post_id ) );
		update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
		update_post_meta( $post_id, '_erankly_max_snippet', '5' );

		$robots = erankly_apply_current_object_robots_overrides( array( 'index' => true, 'follow' => true ) );

		$this->assertTrue( $robots['noindex'] );
		$this->assertArrayNotHasKey( 'index', $robots );
		$this->assertSame( '5', $robots['max-snippet'] );
		// nosnippet would remove the max-snippet directive; here it must survive.
		$this->assertArrayNotHasKey( 'nosnippet', $robots );
	}

	public function test_filter_robots_txt_adds_admin_bootstrap_rules_for_a_public_site(): void {
		$output = erankly_filter_robots_txt( "User-agent: *\nDisallow: /wp-admin/\n", true );

		$this->assertStringContainsString( 'User-agent: *', $output );
		$this->assertStringContainsString( 'Disallow: /wp-admin/', $output );
		$this->assertStringContainsString( 'Allow: /wp-admin/admin-ajax.php', $output );
	}

	public function test_filter_robots_txt_disallows_everything_for_a_private_site(): void {
		$output = erankly_filter_robots_txt( "User-agent: *\n", false );

		$this->assertStringContainsString( 'Disallow: /', $output );
		$this->assertStringNotContainsString( 'Allow: /wp-admin/admin-ajax.php', $output );
	}

	public function test_filter_robots_txt_appends_custom_rules_and_groups(): void {
		erankly_update_plugin_settings(
			array( 'robots_txt_extra' => "Disallow: /private/\n\nUser-agent: BadBot\nDisallow: /badbot/" )
		);
		erankly_clear_settings_cache();

		$output = erankly_filter_robots_txt( "User-agent: *\n", true );

		$this->assertStringContainsString( 'Disallow: /private/', $output );
		$this->assertStringContainsString( 'User-agent: BadBot', $output );
		$this->assertStringContainsString( 'Disallow: /badbot/', $output );
	}

	public function test_get_robots_txt_preview_runs_the_public_robots_txt_filter(): void {
		update_option( 'blog_public', 1 );
		$preview = erankly_get_robots_txt_preview();

		$this->assertStringContainsString( 'User-agent: *', $preview );
		// The plugin's own robots_txt callback also runs on the preview, adding the admin allow rule.
		$this->assertStringContainsString( 'Allow:', $preview );
	}

	public function test_register_rewrites_adds_the_specialist_sitemap_rule(): void {
		erankly_update_plugin_settings( array( 'enable_sitemap' => 1 ) );
		erankly_clear_settings_cache();

		erankly_register_rewrites();

		$rules = (array) $GLOBALS['wp_rewrite']->extra_rules_top;
		$this->assertArrayHasKey( '^sitemap-(image|video|news)-([0-9]+)\.xml$', $rules );
		$this->assertStringContainsString( 'erankly_sitemap=$matches[1]', (string) $rules['^sitemap-(image|video|news)-([0-9]+)\.xml$'] );

		$query_vars = apply_filters( 'query_vars', array() );
		$this->assertContains( 'erankly_sitemap', $query_vars );
		$this->assertContains( 'erankly_sitemap_page', $query_vars );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_rewrites_is_a_noop_when_sitemap_is_disabled(): void {
		erankly_update_plugin_settings( array( 'enable_sitemap' => 0 ) );
		erankly_clear_settings_cache();

		erankly_register_rewrites();

		$rules = (array) $GLOBALS['wp_rewrite']->extra_rules_top;
		$this->assertArrayNotHasKey( '^sitemap-(image|video|news)-([0-9]+)\.xml$', $rules );
	}

	public function test_maybe_render_virtual_files_does_nothing_for_plain_requests(): void {
		$this->go_to( home_url( '/' ) );

		ob_start();
		erankly_maybe_render_virtual_files();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	private function get_a_post(): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertIsInt( $post_id );

		return (int) $post_id;
	}
}
