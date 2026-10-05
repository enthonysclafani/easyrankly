<?php
/** Redirect migration pagination preserves source mappings and skips malformed entries. */

final class ERankly_Migration_Redirect_Pagination_Test extends WP_UnitTestCase {

	/** @var array<int,string> */
	private static array $fixture_tables = array();

	/** Source tables must be visible to SHOW TABLES and created outside the per-test MySQL transaction. */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		global $wpdb;

		$schemas = array(
			'aioseo_redirects' => 'id bigint unsigned NOT NULL PRIMARY KEY, source_url text, target_url text, type int, source_url_match text, query_param text, enabled int, ignore_case int',
			'rank_math_redirections' => 'id bigint unsigned NOT NULL PRIMARY KEY, sources longtext, url_to text, header_code int, status text',
		);
		foreach ( $schemas as $suffix => $schema ) {
			$table = $wpdb->prefix . $suffix;
			$result = $wpdb->query( $wpdb->prepare( "CREATE TABLE %i ({$schema})", $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-owned table schema selected from the literals above.
			if ( false === $result ) {
				throw new RuntimeException( 'Could not create redirect source fixture: ' . $table );
			}
			self::$fixture_tables[] = $table;
		}
	}

	public static function tear_down_after_class(): void {
		global $wpdb;

		foreach ( self::$fixture_tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		self::$fixture_tables = array();
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		require_once ERANKLY_PATH . 'includes/migrations.php';
		foreach ( array( 'yoast', 'aioseo', 'rankmath', 'seopress' ) as $source ) {
			erankly_migration_load_adapter( $source );
		}
	}

	/** Checks that small pages preserve every redirect and finish even when invalid source records are skipped. */
	private function assert_redirect_pages_match_iterator( ERankly_Migration_Adapter $adapter ): array {
		$expected = iterator_to_array( $adapter->redirect_records(), false );
		$records  = array();
		$cursor   = array();

		for ( $page_number = 0; $page_number < 100; ++$page_number ) {
			$page = $adapter->redirect_batch( $cursor, 1 );
			$this->assertLessThanOrEqual( 1, count( $page['records'] ) );
			$records = array_merge( $records, $page['records'] );
			if ( $page['done'] ) {
				$by_reference = static fn( array $a, array $b ): int => strcmp( $a['source_reference'], $b['source_reference'] );
				usort( $expected, $by_reference );
				usort( $records, $by_reference );
				$this->assertSame( $expected, $records );
				return $records;
			}
			$this->assertNotSame( $cursor, $page['cursor'], 'Redirect pagination must advance.' );
			$cursor = $page['cursor'];
		}

		$this->fail( 'Redirect pagination did not finish.' );
	}

	public function test_yoast_redirect_pages_preserve_premium_and_legacy_mappings(): void {
		update_option( 'wpseo-premium-redirects-base', array(
			array( 'origin' => '/old/(.*)', 'url' => '/new/$1', 'type' => 302, 'format' => 'regex' ),
			'invalid',
			array( 'origin' => '' ),
		) );
		update_option( 'wpseo-premium-redirects-export-plain', array(
			'/z'   => '/z-target',
			'/a'   => array( 'origin' => '/override?x=1', 'target' => '/a-target', 'type' => 307 ),
			'/bad' => array( 'url' => array( '/invalid' ) ),
		) );
		update_option( 'wpseo-premium-redirects-export-regex', array( '/from/(.*)' => '/to/$1' ) );

		$records = $this->assert_redirect_pages_match_iterator( new ERankly_Migration_Adapter_Yoast() );
		$this->assertCount( 4, $records );
		$this->assertSame( 'regex', $records[0]['match_type'] );
		$this->assertSame( 302, $records[0]['status_code'] );
		$override = array_values( array_filter( $records, static fn( array $record ): bool => '/override?x=1' === $record['source_path'] ) );
		$this->assertSame( '/a-target', $override[0]['target_url'] );
		$this->assertSame( 'x=1', $override[0]['source_query'] );
		$this->assertSame( 307, $override[0]['status_code'] );
	}

	public function test_aioseo_redirect_pages_preserve_query_regex_and_disabled_rules(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_redirects';
		$defaults = array( 'source_url' => '', 'target_url' => '/target', 'type' => 301, 'source_url_match' => 'exact', 'query_param' => '', 'enabled' => 1, 'ignore_case' => 0 );
		foreach ( array(
			array( 'id' => 1 ),
			array( 'id' => 2, 'source_url' => '/shop?x=1', 'query_param' => 'exact_match', 'type' => 302, 'ignore_case' => 1 ),
			array( 'id' => 3, 'source_url' => '/old/(.*)', 'source_url_match' => 'REGEX', 'query_param' => 'preserve' ),
			array( 'id' => 4, 'source_url' => '/disabled', 'enabled' => 0 ),
		) as $row ) {
			$this->assertSame( 1, $wpdb->insert( $table, $row + $defaults ) );
		}

		$records = $this->assert_redirect_pages_match_iterator( new ERankly_Migration_Adapter_AIOSEO() );
		$this->assertCount( 3, $records );
		$this->assertSame( 'x=1', $records[0]['source_query'] );
		$this->assertSame( 302, $records[0]['status_code'] );
		$this->assertSame( 0, $records[0]['case_sensitive'] );
		$this->assertSame( 'regex', $records[1]['match_type'] );
		$this->assertSame( '', $records[1]['source_query'] );
		$this->assertSame( 'preserve', $records[1]['query_mode'] );
		$this->assertSame( 0, $records[2]['is_active'] );
	}

	public function test_rankmath_redirect_pages_resume_within_sources_and_skip_invalid_rows(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_redirections';
		$sources = array(
			'invalid',
			array( 'pattern' => '' ),
			array( 'pattern' => '/exact?x=1', 'comparison' => 'exact', 'ignore' => 'case' ),
			array( 'pattern' => '/contains', 'comparison' => 'contains' ),
			array( 'pattern' => '/prefix', 'comparison' => 'start' ),
			array( 'pattern' => '/suffix', 'comparison' => 'end' ),
			array( 'pattern' => '/old/(.*)', 'comparison' => 'regex' ),
			array( 'pattern' => '/unknown', 'comparison' => 'unknown' ),
		);
		foreach ( array(
			array( 'id' => 1, 'sources' => 'unreadable', 'status' => 'active' ),
			array( 'id' => 2, 'sources' => serialize( $sources ), 'status' => 'inactive' ),
		) as $row ) {
			$this->assertSame( 1, $wpdb->insert( $table, $row + array( 'url_to' => '/target', 'header_code' => 307 ) ) );
		}

		$adapter = new ERankly_Migration_Adapter_RankMath();
		$records = $this->assert_redirect_pages_match_iterator( $adapter );
		$this->assertCount( 6, $records );
		$this->assertSame( array( 'exact', 'contains', 'starts_with', 'ends_with', 'regex', 'exact' ), array_column( $records, 'match_type' ) );
		$this->assertSame( 'x=1', $records[0]['source_query'] );
		$this->assertSame( 0, $records[0]['case_sensitive'] );
		$this->assertSame( array( 0 ), array_values( array_unique( array_column( $records, 'is_active' ) ) ) );
		$this->assertSame( array( 307 ), array_values( array_unique( array_column( $records, 'status_code' ) ) ) );
		$this->assertSame( 'invalid_redirect_sources', $adapter->warnings()[0]['code'] );
	}

	public function test_seopress_redirect_pages_preserve_post_term_and_visibility_mappings(): void {
		register_post_type( 'seopress_404', array( 'public' => false ) );
		try {
			$post_id = self::factory()->post->create( array( 'post_type' => 'seopress_404', 'post_title' => '/old?x=1' ) );
			$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
			foreach ( array( 'post' => $post_id, 'term' => $term_id ) as $type => $id ) {
				update_metadata( $type, $id, '_seopress_redirections_value', '/target' );
				update_metadata( $type, $id, '_seopress_redirections_enabled', '1' );
				update_metadata( $type, $id, '_seopress_redirections_type', '302' );
				update_metadata( $type, $id, '_seopress_redirections_param', 'exact_match' );
				update_metadata( $type, $id, '_seopress_redirections_logged_status', 'only_logged_in' );
			}
			$invalid = self::factory()->post->create( array( 'post_type' => 'seopress_404', 'post_title' => '   ' ) );
			update_post_meta( $invalid, '_seopress_redirections_value', '/invalid' );

			$records = $this->assert_redirect_pages_match_iterator( new ERankly_Migration_Adapter_SEOPress() );
			$this->assertCount( 2, $records );
			$this->assertSame( '/old?x=1', $records[0]['source_path'] );
			$this->assertSame( 'x=1', $records[0]['source_query'] );
			$this->assertSame( get_term_link( $term_id ), $records[1]['source_path'] );
			$this->assertSame( array( 'logged_in', 'logged_in' ), array_column( $records, 'visibility' ) );
			$this->assertSame( array( 302, 302 ), array_column( $records, 'status_code' ) );
		} finally {
			unregister_post_type( 'seopress_404' );
		}
	}

}
