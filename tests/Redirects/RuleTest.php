<?php
/**
 * Tests for redirect normalization, validation and matching.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Rule;
use WP_UnitTestCase;

/**
 * Rules are pure functions of their input and the site address.
 */
final class RuleTest extends WP_UnitTestCase {

	/**
	 * Paths are decoded, lowercased and stripped of query string and extra slashes.
	 */
	public function test_normalize(): void {
		$this->assertSame( '/old-page', Rule::normalize( '/Old-Page/?utm=1' ) );
		$this->assertSame( '/a/b', Rule::normalize( '//a///b/' ) );
		$this->assertSame( '/caffè', Rule::normalize( '/caff%C3%A8' ) );
		$this->assertSame( '/old', Rule::normalize( home_url( '/old/' ) ) );
		$this->assertSame( '/', Rule::normalize( home_url() ) );
		$this->assertSame( '/old', Rule::normalize( 'old' ) );
	}

	/**
	 * A site in a subdirectory stores paths relative to it.
	 */
	public function test_normalize_strips_home_subdirectory(): void {
		update_option( 'home', 'http://example.org/blog' );

		$this->assertSame( '/old', Rule::normalize( '/blog/old' ) );
		$this->assertSame( '/', Rule::normalize( '/blog/' ) );
		$this->assertSame( '/blogger', Rule::normalize( '/blogger' ) );
	}

	/**
	 * Valid exact and regex rules come back normalized.
	 */
	public function test_validate_accepts_valid_rules(): void {
		$this->assertSame(
			array(
				'source' => '/old',
				'target' => '/new',
			),
			Rule::validate( ' /Old/ ', '/new', 301, false )
		);
		$this->assertSame(
			array(
				'source' => '^/blog/(.*)$',
				'target' => 'https://93.184.215.14/$1',
			),
			Rule::validate( '^/blog/(.*)$', 'https://93.184.215.14/$1', 302, true )
		);
		$this->assertSame(
			array(
				'source' => '/gone',
				'target' => '',
			),
			Rule::validate( '/gone', 'ignored', 410, false )
		);
		$this->assertSame(
			array(
				'source' => '/blocked',
				'target' => '',
			),
			Rule::validate( '/blocked', 'ignored', 451, false )
		);
	}

	/**
	 * Every kind of invalid input is rejected with its own error code.
	 */
	public function test_validate_rejects_invalid_rules(): void {
		$cases = array(
			'easyrankly_redirect_code'   => array( '/a', '/b', 303, false ),
			'easyrankly_redirect_source' => array( '', '/b', 301, false ),
			'easyrankly_redirect_regex'  => array( '^/(unclosed', '/b', 301, true ),
			'easyrankly_redirect_target' => array( '/a', 'javascript:alert(1)', 301, false ),
			'easyrankly_redirect_loop'   => array( '/a', '/A/', 301, false ),
		);

		foreach ( $cases as $code => $args ) {
			$result = Rule::validate( ...$args );
			$this->assertWPError( $result, $code );
			$this->assertSame( $code, $result->get_error_code() );
		}

		$this->assertWPError( Rule::validate( 'https://other.example/a', '/b', 301, false ) );
		$this->assertWPError( Rule::validate( '/a', '', 301, false ) );
		$this->assertWPError( Rule::validate( '/a', '//evil.example/x', 301, false ) );
		$this->assertWPError( Rule::validate( str_repeat( 'a', Rule::MAX_LENGTH + 1 ), '/b', 301, false ) );
	}

	/**
	 * Exact rules compare whole paths; regex rules replace captures URL-encoded.
	 */
	public function test_match(): void {
		$exact = array(
			'source' => '/old',
			'regex'  => false,
			'target' => '/new',
		);
		$this->assertSame( '/new', Rule::match( $exact, '/old' ) );
		$this->assertNull( Rule::match( $exact, '/old/more' ) );

		$regex = array(
			'source' => '^/blog/(.*)$',
			'regex'  => true,
			'target' => '/news/$1',
		);
		$this->assertSame( '/news/a/b%20c', Rule::match( $regex, '/blog/a/b c' ) );
		$this->assertSame( '/news/', Rule::match( $regex, '/blog/' ) );
		$this->assertNull( Rule::match( $regex, '/other' ) );
	}

	/**
	 * A "#" in a pattern works whether the administrator escaped it or not.
	 */
	public function test_hash_in_regex(): void {
		$this->assertTrue( Rule::is_valid_regex( '^/a#b$' ) );
		$this->assertTrue( Rule::is_valid_regex( '^/a\\#b$' ) );
		$this->assertTrue( Rule::is_valid_regex( '^/a\\\\#b$' ) );

		$rule = array(
			'source' => '^/a\\#(.*)$',
			'regex'  => true,
			'target' => '/b/$1',
		);
		$this->assertSame( '/b/c', Rule::match( $rule, '/a#c' ) );
	}

	/**
	 * Stored targets keep their encoded octets; anything that is not an address is dropped.
	 */
	public function test_sanitize_target(): void {
		$this->assertSame( '/caff%C3%A8?q=a%20b', Rule::sanitize_target( ' /caff%C3%A8?q=a%20b ' ) );
		$this->assertSame( 'https://example.com/a%2Fb?x=1&y=2', Rule::sanitize_target( 'https://example.com/a%2Fb?x=1&y=2' ) );
		$this->assertSame( '/news/$1', Rule::sanitize_target( '/news/$1' ) );
		$this->assertSame( '', Rule::sanitize_target( 'javascript:alert(1)' ) );
		$this->assertSame( '', Rule::sanitize_target( array( '/a' ) ) );
	}

	/**
	 * A catastrophic pattern hits the backtracking limit instead of hanging.
	 */
	public function test_match_stops_runaway_regex(): void {
		$rule = array(
			'source' => '^/(a+)+$',
			'regex'  => true,
			'target' => '/x',
		);

		$this->assertNull( Rule::match( $rule, '/' . str_repeat( 'a', 40 ) . '!' ) );
	}

	/**
	 * Only targets on this host are internal.
	 */
	public function test_is_internal(): void {
		$this->assertTrue( Rule::is_internal( Rule::target_url( '/new' ) ) );
		$this->assertFalse( Rule::is_internal( 'https://other.example/new' ) );
	}

	/**
	 * A capture can fill the path of an external target, never its host.
	 */
	public function test_capture_cannot_pick_external_host(): void {
		$this->assertWPError( Rule::validate( '^/go/(.*)$', 'https://$1', 301, true ) );
		$this->assertWPError( Rule::validate( '^/go/(.*)$', 'https://93.184.215.14$1', 301, true ) );
		$this->assertWPError( Rule::validate( '/a', 'http://127.0.0.1/x', 301, false ) );

		$rule = array(
			'source' => '^/go/(.*)$',
			'regex'  => true,
			'target' => 'https://$1',
		);
		$this->assertNull( Rule::match( $rule, '/go/evil.example' ) );
	}
}
