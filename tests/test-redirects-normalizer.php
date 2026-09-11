<?php
/** Redirect source/target normalization, hashing, matching and rule evaluation. */

final class ERankly_Redirects_Normalizer_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'erankly_ensure_redirect_classes_available' ) ) {
			require_once ERANKLY_PATH . 'includes/migrations/runtime-redirects.php';
		}
		erankly_ensure_redirect_classes_available();
	}

	public function test_status_code_label_translates_known_codes_and_falls_back(): void {
		$this->assertSame( '301: Moved Permanently', ERankly_Redirects_Normalizer::status_code_label( 301 ) );
		$this->assertSame( '410: Gone', ERankly_Redirects_Normalizer::status_code_label( 410 ) );
		$this->assertSame( '999', ERankly_Redirects_Normalizer::status_code_label( 999 ) );
	}

	public function test_normalize_path_strips_query_fragment_and_collapses_slashes(): void {
		$this->assertSame( '/foo/bar', ERankly_Redirects_Normalizer::normalize_path( '/Foo//Bar/?q=1#frag' ) );
		$this->assertSame( '/path/sub', ERankly_Redirects_Normalizer::normalize_path( 'https://example.com/Path/Sub/' ) );
		$this->assertSame( '/', ERankly_Redirects_Normalizer::normalize_path( '' ) );
	}

	public function test_normalize_match_path_honours_case_and_trailing_slash_flags(): void {
		$this->assertSame( '/Foo/Bar/', ERankly_Redirects_Normalizer::normalize_match_path( '/Foo/Bar/', true, 'exact' ) );
		$this->assertSame( '/foo/bar', ERankly_Redirects_Normalizer::normalize_match_path( '/Foo/Bar/', false, 'ignore' ) );
	}

	public function test_normalize_match_path_for_capture_preserves_request_case(): void {
		$this->assertSame( '/Mixed/Case', ERankly_Redirects_Normalizer::normalize_match_path_for_capture( '/Mixed/Case/' ) );
	}

	public function test_extract_query_returns_query_string_or_empty(): void {
		$this->assertSame( 'b=1&c=2', ERankly_Redirects_Normalizer::extract_query( '/a?b=1&c=2' ) );
		$this->assertSame( '', ERankly_Redirects_Normalizer::extract_query( '/a' ) );
	}

	public function test_normalize_source_for_regex_preserves_pattern_body(): void {
		$this->assertSame( '^/old/(\d+)$', ERankly_Redirects_Normalizer::normalize_source( '^/old/(\d+)$', true ) );
		$this->assertSame( '/old/(\d+)', ERankly_Redirects_Normalizer::normalize_source( 'old/(\d+)', true ) );
	}

	public function test_normalize_source_wildcard_lowercases_and_drops_query(): void {
		$this->assertSame( '/shop/*', ERankly_Redirects_Normalizer::normalize_source( '/Shop/*?x=1', false, true ) );
	}

	public function test_normalize_source_exact_uses_path_normalization(): void {
		$this->assertSame( '/old-page', ERankly_Redirects_Normalizer::normalize_source( '/Old-Page/', false ) );
	}

	public function test_build_wildcard_pattern_matches_prefix_and_captures(): void {
		$pattern = ERankly_Redirects_Normalizer::build_wildcard_pattern( '/foo/*' );

		$this->assertSame( 1, preg_match( $pattern, '/foo/bar' ) );
		$this->assertSame( 1, preg_match( $pattern, '/foo/' ) );
		$this->assertSame( 0, preg_match( $pattern, '/bar/foo' ) );
	}

	public function test_apply_wildcard_target_substitutes_capture_groups(): void {
		$this->assertSame(
			'/new/page',
			ERankly_Redirects_Normalizer::apply_wildcard_target( '/old/*', '/old/page', '/new/*' )
		);
		// A trailing '*' also captures the empty match so the bare prefix redirects.
		$this->assertSame(
			'/bar',
			ERankly_Redirects_Normalizer::apply_wildcard_target( '/foo*', '/foo', '/bar*' )
		);
	}

	public function test_is_valid_wildcard_source_requires_star_and_no_whitespace(): void {
		$this->assertTrue( ERankly_Redirects_Normalizer::is_valid_wildcard_source( '/foo*' ) );
		$this->assertTrue( ERankly_Redirects_Normalizer::is_valid_wildcard_source( '*' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_wildcard_source( '/foo' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_wildcard_source( '/f o*' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_wildcard_source( 'foo*' ) );
	}

	public function test_source_hash_and_rule_hash_are_stable_and_identity_bound(): void {
		$this->assertSame( md5( '/a' ), ERankly_Redirects_Normalizer::source_hash( '/a' ) );

		$base  = array( 'source_path' => '/a', 'match_type' => 'exact', 'query_mode' => 'ignore' );
		$other = array( 'source_path' => '/a', 'match_type' => 'exact', 'query_mode' => 'preserve' );

		$this->assertSame(
			ERankly_Redirects_Normalizer::rule_hash( $base ),
			ERankly_Redirects_Normalizer::rule_hash( $base )
		);
		$this->assertNotSame(
			ERankly_Redirects_Normalizer::rule_hash( $base ),
			ERankly_Redirects_Normalizer::rule_hash( $other )
		);
	}

	public function test_compare_rules_prefers_exact_over_wildcard_over_regex(): void {
		$exact    = array( 'match_type' => 'exact', 'source_path' => '/a' );
		$wildcard = array( 'match_type' => 'wildcard', 'source_path' => '/a*' );
		$regex    = array( 'match_type' => 'regex', 'source_path' => '/a.*' );

		$this->assertLessThan( 0, ERankly_Redirects_Normalizer::compare_rules( $exact, $wildcard ) );
		$this->assertLessThan( 0, ERankly_Redirects_Normalizer::compare_rules( $wildcard, $regex ) );
		$this->assertGreaterThan( 0, ERankly_Redirects_Normalizer::compare_rules( $regex, $exact ) );
	}

	public function test_compare_rules_ranks_query_mode_then_literal_specificity(): void {
		$exact_query = array( 'match_type' => 'exact', 'source_path' => '/a', 'query_mode' => 'exact' );
		$any_query   = array( 'match_type' => 'exact', 'source_path' => '/a', 'query_mode' => 'ignore' );
		$this->assertLessThan( 0, ERankly_Redirects_Normalizer::compare_rules( $exact_query, $any_query ) );

		$specific = array( 'match_type' => 'wildcard', 'source_path' => '/foo/*' );
		$broad    = array( 'match_type' => 'wildcard', 'source_path' => '/f*' );
		$this->assertLessThan( 0, ERankly_Redirects_Normalizer::compare_rules( $specific, $broad ) );
	}

	public function test_evaluate_rule_exact_match_and_miss(): void {
		$rule = array(
			'match_type'     => 'exact',
			'source_path'    => '/old',
			'target_url'     => '/new',
			'query_mode'     => 'ignore',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
		);

		$hit = ERankly_Redirects_Normalizer::evaluate_rule( $rule, '/old' );
		$this->assertTrue( $hit['matches'] );
		$this->assertSame( '/new', $hit['target_url'] );

		$miss = ERankly_Redirects_Normalizer::evaluate_rule( $rule, '/other' );
		$this->assertFalse( $miss['matches'] );
		$this->assertSame( '', $miss['target_url'] );
	}

	public function test_evaluate_rule_exact_query_mode_requires_the_query(): void {
		$rule = array(
			'match_type'     => 'exact',
			'source_path'    => '/old',
			'source_query'   => 'a=1',
			'target_url'     => '/new',
			'query_mode'     => 'exact',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
		);

		$this->assertTrue( ERankly_Redirects_Normalizer::evaluate_rule( $rule, '/old?a=1' )['matches'] );
		$this->assertFalse( ERankly_Redirects_Normalizer::evaluate_rule( $rule, '/old?a=2' )['matches'] );
	}

	public function test_evaluate_rule_preserve_query_appends_visitor_query(): void {
		$rule = array(
			'match_type'     => 'exact',
			'source_path'    => '/old',
			'target_url'     => '/new',
			'query_mode'     => 'preserve',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
		);

		$result = ERankly_Redirects_Normalizer::evaluate_rule( $rule, '/old?x=1' );
		$this->assertTrue( $result['matches'] );
		$this->assertSame( '/new?x=1', $result['target_url'] );
	}

	public function test_evaluate_rule_wildcard_and_regex_apply_backreferences(): void {
		$wildcard = array(
			'match_type'     => 'wildcard',
			'source_path'    => '/old/*',
			'target_url'     => '/new/*',
			'query_mode'     => 'ignore',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
		);
		$wildcard_result = ERankly_Redirects_Normalizer::evaluate_rule( $wildcard, '/old/page' );
		$this->assertTrue( $wildcard_result['matches'] );
		$this->assertSame( '/new/page', $wildcard_result['target_url'] );

		$regex = array(
			'match_type'     => 'regex',
			'source_path'    => '^/old/(\d+)$',
			'target_url'     => '/new/$1',
			'query_mode'     => 'ignore',
			'case_sensitive' => 0,
			'trailing_slash' => 'ignore',
		);
		$regex_result = ERankly_Redirects_Normalizer::evaluate_rule( $regex, '/old/42' );
		$this->assertTrue( $regex_result['matches'] );
		$this->assertSame( '/new/42', $regex_result['target_url'] );
	}

	public function test_preserve_query_handles_existing_query_and_fragment(): void {
		$this->assertSame( '/new?z=1&x=2', ERankly_Redirects_Normalizer::preserve_query( '/new?z=1', 'x=2' ) );
		$this->assertSame( '/new?x=2#frag', ERankly_Redirects_Normalizer::preserve_query( '/new#frag', 'x=2' ) );
		$this->assertSame( '/new', ERankly_Redirects_Normalizer::preserve_query( '/new', '' ) );
	}

	public function test_normalize_target_url_accepts_internal_and_safe_external(): void {
		$this->assertSame( '/new-page', ERankly_Redirects_Normalizer::normalize_target_url( '/new-page' ) );
		$this->assertSame( 'https://example.com/x', ERankly_Redirects_Normalizer::normalize_target_url( 'https://example.com/x' ) );
	}

	public function test_normalize_target_url_rejects_unsafe_targets(): void {
		$this->assertSame( '', ERankly_Redirects_Normalizer::normalize_target_url( '' ) );
		$this->assertSame( '', ERankly_Redirects_Normalizer::normalize_target_url( 'ftp://example.com/x' ) );
		$this->assertSame( '', ERankly_Redirects_Normalizer::normalize_target_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', ERankly_Redirects_Normalizer::normalize_target_url( "/new\npage" ) );
	}

	public function test_status_code_validity_helpers(): void {
		$this->assertTrue( ERankly_Redirects_Normalizer::is_valid_status_code( 301 ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_status_code( 200 ) );
		$this->assertTrue( ERankly_Redirects_Normalizer::is_status_only_code( 410 ) );
		$this->assertTrue( ERankly_Redirects_Normalizer::is_status_only_code( 451 ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_status_only_code( 301 ) );
	}

	public function test_internal_path_and_url_helpers(): void {
		$this->assertTrue( ERankly_Redirects_Normalizer::is_valid_internal_path( '/foo/bar' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_internal_path( 'foo' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_internal_path( '/foo bar' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_internal_path( '//evil' ) );

		$this->assertTrue( ERankly_Redirects_Normalizer::is_internal_url( '/x' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_internal_url( '//x' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_internal_url( 'https://example.com/x' ) );
	}

	public function test_is_safe_absolute_url_rejects_unsafe_hosts_and_schemes(): void {
		$this->assertTrue( ERankly_Redirects_Normalizer::is_safe_absolute_url( 'https://example.com/x' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_safe_absolute_url( 'ftp://example.com/x' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_safe_absolute_url( 'http://192.168.1.1/x' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_safe_absolute_url( 'not-a-url' ) );
	}

	public function test_build_regex_pattern_and_is_valid_regex(): void {
		$pattern = ERankly_Redirects_Normalizer::build_regex_pattern( '^/old/(\d+)$' );
		$this->assertStringContainsString( 'LIMIT_MATCH', $pattern );
		$this->assertSame( 1, preg_match( $pattern, '/old/7' ) );

		$this->assertTrue( ERankly_Redirects_Normalizer::is_valid_regex( '/old/(\d+)' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_regex( '' ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_regex( str_repeat( 'a', 600 ) ) );
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_regex( '(' ) );
	}

	public function test_is_valid_regex_rejects_catastrophic_backtracking(): void {
		$this->assertFalse( ERankly_Redirects_Normalizer::is_valid_regex( '(a+)+$' ) );
	}

	public function test_apply_regex_target_substitutes_backreference(): void {
		$this->assertSame(
			'/new/7',
			ERankly_Redirects_Normalizer::apply_regex_target( '/old/(\d+)', '/old/7', '/new/$1' )
		);
	}

	public function test_target_to_local_path_resolves_internal_and_same_host_urls(): void {
		$this->assertSame( '/x/y', ERankly_Redirects_Normalizer::target_to_local_path( '/x/y' ) );
		$this->assertSame( '/foo', ERankly_Redirects_Normalizer::target_to_local_path( home_url( '/foo' ) ) );
		$this->assertNull( ERankly_Redirects_Normalizer::target_to_local_path( 'https://other.example/x' ) );
	}
}
