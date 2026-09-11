<?php
/** Hreflang alternate validation/cleaning and the paged-archive canonical. */

final class ERankly_Hreflang_Canonical_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();

		foreach ( array( 'includes/hreflang.php', 'includes/canonical.php', 'includes/class-erankly-multilingual-provider-registry.php' ) as $file ) {
			require_once ERANKLY_PATH . $file;
		}
	}

	public function test_valid_hreflang_tags_are_accepted(): void {
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'it' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'en-US' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'x-default' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'es-419' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'pt-BR' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'ja' ) );
	}

	public function test_script_subtag_hreflangs_are_accepted(): void {
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'zh-Hant' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'sr-Latn' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'zh-Hans-CN' ) );
	}

	public function test_script_subtag_hreflangs_are_kept_by_the_cleaner(): void {
		$clean = erankly_clean_hreflang_alternates(
			array(
				'zh-Hant'    => 'https://example.org/tw/',
				'zh-Hans'    => 'https://example.org/cn/',
				'zh-Hans-CN' => 'https://example.org/cn-region/',
				'it-IT'      => 'https://example.org/it/',
			)
		);

		$this->assertSame(
			array(
				'zh-hant'    => 'https://example.org/tw/',
				'zh-hans'    => 'https://example.org/cn/',
				'zh-hans-cn' => 'https://example.org/cn-region/',
				'it-it'      => 'https://example.org/it/',
			),
			$clean
		);
	}

	public function test_hreflang_validation_is_case_and_space_insensitive(): void {
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'IT' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( '  EN-us  ' ) );
		$this->assertTrue( erankly_is_valid_hreflang_tag( 'X-Default' ) );
	}

	public function test_invalid_hreflang_tags_are_rejected(): void {
		$this->assertFalse( erankly_is_valid_hreflang_tag( '' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( 'i' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( 'italiano-lunghissimo' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( '12' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( 'en_US' ) );
		$this->assertFalse( erankly_is_valid_hreflang_tag( '<script>' ) );
	}

	public function test_clean_alternates_returns_empty_for_a_non_array(): void {
		$this->assertSame( array(), erankly_clean_hreflang_alternates( null ) );
		$this->assertSame( array(), erankly_clean_hreflang_alternates( 'string' ) );
		$this->assertSame( array(), erankly_clean_hreflang_alternates( 42 ) );
	}

	public function test_clean_alternates_drops_invalid_tags_and_relative_urls(): void {
		$clean = erankly_clean_hreflang_alternates(
			array(
				'it-IT'  => 'https://example.org/it/',
				'bogus'  => 'https://example.org/bogus/',
				'en-US'  => '/relative/',
				'fr-FR'  => '',
				'de-DE'  => 'https://example.org/de/',
			)
		);

		$this->assertSame(
			array(
				'it-it' => 'https://example.org/it/',
				'de-de' => 'https://example.org/de/',
			),
			$clean
		);
	}

	public function test_clean_alternates_lowercases_keys_and_keeps_the_first_duplicate(): void {
		$clean = erankly_clean_hreflang_alternates(
			array(
				'IT-it' => 'https://example.org/primo/',
				'it-IT' => 'https://example.org/secondo/',
			)
		);

		$this->assertSame( array( 'it-it' => 'https://example.org/primo/' ), $clean );
	}

	public function test_clean_alternates_sanitizes_urls(): void {
		$clean = erankly_clean_hreflang_alternates(
			array( 'it-IT' => 'https://example.org/it/?a=1&b=<script>' )
		);

		$this->assertArrayHasKey( 'it-it', $clean );
		$this->assertStringNotContainsString( '<script>', $clean['it-it'] );
	}

	public function test_get_hreflang_alternates_is_filterable(): void {
		$filter = static function (): array {
			return array(
				'it-IT'     => 'https://example.org/it/',
				'x-default' => 'https://example.org/',
			);
		};

		add_filter( 'erankly_hreflang_alternates', $filter );

		try {
			$alternates = erankly_get_hreflang_alternates();

			$this->assertSame( 'https://example.org/it/', $alternates['it-it'] );
			$this->assertSame( 'https://example.org/', $alternates['x-default'] );
		} finally {
			remove_filter( 'erankly_hreflang_alternates', $filter );
		}
	}

	public function test_get_navigable_hreflang_alternates_is_filterable(): void {
		$filter = static function (): array {
			return array( 'fr-FR' => 'https://example.org/fr/' );
		};

		add_filter( 'erankly_navigable_hreflang_alternates', $filter );

		try {
			$this->assertSame(
				array( 'fr-fr' => 'https://example.org/fr/' ),
				erankly_get_navigable_hreflang_alternates()
			);
		} finally {
			remove_filter( 'erankly_navigable_hreflang_alternates', $filter );
		}
	}

	public function test_the_two_alternate_sets_use_distinct_filters(): void {
		$signalling = static function (): array {
			return array( 'it-IT' => 'https://example.org/seo/' );
		};
		$navigation = static function (): array {
			return array( 'it-IT' => 'https://example.org/visitor/' );
		};

		add_filter( 'erankly_hreflang_alternates', $signalling );
		add_filter( 'erankly_navigable_hreflang_alternates', $navigation );

		try {
			$this->assertSame( 'https://example.org/seo/', erankly_get_hreflang_alternates()['it-it'] );
			$this->assertSame( 'https://example.org/visitor/', erankly_get_navigable_hreflang_alternates()['it-it'] );
		} finally {
			remove_filter( 'erankly_hreflang_alternates', $signalling );
			remove_filter( 'erankly_navigable_hreflang_alternates', $navigation );
		}
	}

	public function test_render_alternates_emits_nothing_without_a_matching_provider(): void {
		// No multilingual provider is registered in this environment, so the
		// renderer must stay silent rather than emit unowned hreflang tags.
		ob_start();
		erankly_render_hreflang_alternates();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_paged_archive_canonical_points_at_the_current_page(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'posts_per_page', 1 );

		$this->go_to( home_url( '/page/2/' ) );

		$canonical = erankly_get_paged_archive_canonical();

		$this->assertIsString( $canonical );
		$this->assertStringContainsString( 'page/2', $canonical );
	}

	public function test_paged_archive_canonical_uses_page_one_when_the_query_is_not_paginated(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$this->go_to( home_url( '/' ) );

		$canonical = erankly_get_paged_archive_canonical();

		$this->assertIsString( $canonical );
		$this->assertStringNotContainsString( 'page/2', $canonical );
	}
}
