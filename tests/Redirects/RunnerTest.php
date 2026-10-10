<?php
/**
 * Tests for the frontend redirect runner.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Redirects;

use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Runner;
use WP_UnitTestCase;

/**
 * Exact and regex rules run only on 404, forced rules always, one hop at most.
 */
final class RunnerTest extends WP_UnitTestCase {

	/**
	 * Pretty permalinks, meta registered, an administrator saving rules, redirects intercepted.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		( new Redirects() )->register_post_type();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// wp_redirect() is followed by exit: throw instead, carrying status and location.
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new \RuntimeException( esc_html( $status . ' ' . $location ) );
			},
			10,
			2
		);
	}

	/**
	 * Clears the request globals the runner reads.
	 */
	public function tear_down(): void {
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Saves a rule the way the admin screen does.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 * @param array  $meta   Extra meta (without prefix).
	 * @param string $status Post status.
	 */
	private function rule( string $source, string $target, array $meta = array(), string $status = 'publish' ): void {
		$fields = array_merge(
			array(
				'target' => $target,
				'code'   => 301,
				'regex'  => false,
				'forced' => false,
			),
			$meta
		);
		$input  = array();
		foreach ( $fields as $name => $value ) {
			$input[ Redirects::meta_key( $name ) ] = $value;
		}

		self::factory()->post->create(
			array(
				'post_type'   => Redirects::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $source,
				'post_name'   => $fields['regex'] ? 'regex-' . md5( $source ) : md5( $source ),
				'meta_input'  => $input,
			)
		);
		Redirects::rebuild_lists();
	}

	/**
	 * Runs the runner on a URL and returns "status location", or null when nothing was sent.
	 *
	 * @param string $path Request path.
	 * @return string|null
	 */
	private function visit( string $path ): ?string {
		$this->go_to( home_url( $path ) );

		try {
			( new Runner() )->run();
		} catch ( \RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return null;
	}

	/**
	 * An exact rule redirects a missing page.
	 */
	public function test_exact_rule_on_404(): void {
		$this->rule( '/old-page', '/new-page' );

		$this->assertSame( '301 ' . home_url( '/new-page' ), $this->visit( '/Old-Page/?utm_source=x' ) );
	}

	/**
	 * Exact rules do not hide existing content; forced rules do.
	 */
	public function test_existing_page_needs_forced_rule(): void {
		self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'live',
			)
		);

		$this->rule( '/live', '/elsewhere' );
		$this->assertNull( $this->visit( '/live/' ) );

		$this->rule( '/live', '/forced', array( 'forced' => true ) );
		$this->assertSame( '301 ' . home_url( '/forced' ), $this->visit( '/live/' ) );
	}

	/**
	 * Regex rules replace captures and run after exact rules.
	 */
	public function test_regex_rule(): void {
		$this->rule(
			'^/blog/(.*)$',
			'/news/$1',
			array(
				'regex' => true,
				'code'  => 302,
			)
		);
		$this->rule( '/blog/exact', '/exact-wins' );

		$this->assertSame( '302 ' . home_url( '/news/2020/hello' ), $this->visit( '/blog/2020/hello/' ) );
		$this->assertSame( '301 ' . home_url( '/exact-wins' ), $this->visit( '/blog/exact/' ) );
	}

	/**
	 * Inactive rules are ignored.
	 */
	public function test_draft_rule_is_ignored(): void {
		$this->rule( '/old', '/new', array(), 'draft' );
		$this->rule( '^/x(.*)$', '/y', array( 'regex' => true ), 'draft' );

		$this->assertNull( $this->visit( '/old/' ) );
		$this->assertNull( $this->visit( '/xyz/' ) );
	}

	/**
	 * A rule pointing to the requested address does nothing instead of looping.
	 */
	public function test_rule_never_loops(): void {
		$this->rule( '^/(loop.*)$', '/$1', array( 'regex' => true ) );

		$this->assertNull( $this->visit( '/loop/' ) );
	}

	/**
	 * A regex capture cannot turn a relative target into another host.
	 */
	public function test_capture_cannot_change_host(): void {
		$this->rule( '^/go(.*)$', '$1', array( 'regex' => true ) );
		$this->rule( '^/to(.*)$', '/$1', array( 'regex' => true ) );

		$this->assertSame( '301 ' . home_url( '/evil.example/x' ), $this->visit( '/to//evil.example/x' ) );
		$result = $this->visit( '/go/evil.example' );
		$this->assertTrue( null === $result || str_starts_with( $result, '301 ' . home_url() ) );
	}

	/**
	 * External targets saved by an administrator are followed.
	 */
	public function test_external_target(): void {
		$this->rule( '/out', 'https://example.com/landing' );

		$this->assertSame( '301 https://example.com/landing', $this->visit( '/out/' ) );
	}

	/**
	 * 410 answers "gone" with the 404 template instead of redirecting.
	 */
	public function test_gone(): void {
		$this->rule( '/removed', '', array( 'code' => 410 ) );

		$status = 0;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$status ) {
				$status = $code;
				return $header;
			},
			10,
			2
		);

		$this->assertNull( $this->visit( '/removed/' ) );
		$this->assertSame( 410, $status );
		$this->assertTrue( is_404() );
	}

	/**
	 * 451 answers "unavailable for legal reasons" with the 404 template, even when the page exists.
	 */
	public function test_unavailable_for_legal_reasons(): void {
		self::factory()->post->create( array( 'post_name' => 'takedown' ) );
		$this->rule(
			'/takedown',
			'',
			array(
				'code'   => 451,
				'forced' => true,
			)
		);

		$status = 0;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$status ) {
				$status = $code;
				return $header;
			},
			10,
			2
		);

		$this->assertNull( $this->visit( '/takedown/' ) );
		$this->assertSame( 451, $status );
		$this->assertTrue( is_404() );
	}

	/**
	 * Only GET and HEAD requests are redirected.
	 */
	public function test_post_requests_are_left_alone(): void {
		$this->rule( '/old', '/new' );
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->assertNull( $this->visit( '/old/' ) );
	}

	/**
	 * A cached miss is invalidated when a rule is added.
	 */
	public function test_cache_follows_changes(): void {
		$this->assertNull( $this->visit( '/later/' ) );

		$this->rule( '/later', '/now' );

		$this->assertSame( '301 ' . home_url( '/now' ), $this->visit( '/later/' ) );
	}

	/**
	 * A normal page costs no query, even before the first redirect exists.
	 */
	public function test_no_query_on_existing_page(): void {
		global $wpdb;

		delete_option( Redirects::FORCED_OPTION );
		$post = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->go_to( get_permalink( $post ) );
		wp_cache_delete( 'notoptions', 'options' );

		$before = $wpdb->num_queries;
		$this->assertNull( ( new Runner() )->find( '/hello' ) );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * A regex whose target would match it again is skipped instead of chaining.
	 */
	public function test_regex_never_chains(): void {
		$this->rule(
			'^/(.*)$',
			'/en/$1',
			array(
				'regex'  => true,
				'forced' => true,
			)
		);

		$this->assertNull( $this->visit( '/x/' ) );
	}
}
