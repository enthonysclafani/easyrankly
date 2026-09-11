<?php
/** Third-party SEO migration adapters: shared contract helpers and per-source mappings. */

// The adapter classes are lazy-loaded in production; require the loader before the probe
// subclass below is declared so the abstract base is available at file include time.
require_once ERANKLY_PATH . 'includes/migrations.php';

/**
 * Concrete adapter used to exercise the abstract base contract and its protected helpers.
 */
final class ERankly_Migrations_Probe_Adapter extends ERankly_Migration_Adapter {

	/** @var array<int,array<string,mixed>> */
	public array $records = array();

	/** @var array<string,array<string,mixed>> */
	public array $definitions = array();

	/** @var array{min:string,max:string} */
	public array $versions = array(
		'min' => '',
		'max' => '',
	);

	public function slug(): string {
		return 'probe';
	}

	public function label(): string {
		return 'Probe Source';
	}

	public function version(): string {
		return '1.2.3';
	}

	public function is_available(): bool {
		return true;
	}

	public function content_records(): iterable {
		return $this->records;
	}

	protected function storage_definitions(): array {
		return $this->definitions;
	}

	protected function supported_versions(): array {
		return $this->versions;
	}

	/** Invokes any protected base helper by name. */
	public function expose( string $method, mixed ...$args ): mixed {
		return $this->$method( ...$args );
	}
}

final class ERankly_Migrations_Adapters_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ERANKLY_PATH . 'includes/migrations.php';
		erankly_load_default_helpers();
		erankly_load_content_helpers();

		erankly_migration_load_adapter( 'yoast' );
		erankly_migration_load_adapter( 'rankmath' );
		erankly_migration_load_adapter( 'aioseo' );
		erankly_migration_load_adapter( 'seopress' );
	}

	/** Invokes a private base method, which a subclass cannot reach through $this. */
	private function invoke_base_private( object $instance, string $method, array $args = array() ): mixed {
		return $this->invoke_private( ERankly_Migration_Adapter::class, $instance, $method, $args );
	}

	/** Invokes any private method declared on the given class. */
	private function invoke_private( string $class, object $instance, string $method, array $args = array() ): mixed {
		$reflection = new ReflectionMethod( $class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $instance, $args );
	}

	// ---------------------------------------------------------------------
	// Base adapter contract.
	// ---------------------------------------------------------------------

	public function test_default_adapter_contract_is_source_neutral(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertSame( 'probe', $adapter->slug() );
		$this->assertSame( 'Probe Source', $adapter->label() );
		$this->assertSame( '1.2.3', $adapter->version() );
		$this->assertTrue( $adapter->is_available() );
		$this->assertSame( array(), $adapter->redirect_records() );
		$this->assertSame( array(), $adapter->global_settings() );
		$this->assertSame( array( 'posts', 'terms', 'social', 'robots' ), $adapter->capabilities() );
		$this->assertSame( array(), $adapter->warnings() );
	}

	public function test_add_warning_is_sanitized_and_bounded(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$adapter->expose( 'add_warning', 'My Code!', 'A <b>message</b>', 'ref 1', false );

		$warnings = $adapter->warnings();
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'mycode', $warnings[0]['code'] );
		$this->assertSame( 'A message', $warnings[0]['message'] );
		$this->assertSame( 'ref 1', $warnings[0]['reference'] );
		$this->assertFalse( $warnings[0]['blocking'] );

		for ( $i = 0; $i < 150; $i++ ) {
			$adapter->expose( 'add_warning', 'code', 'message', 'ref-' . $i );
		}

		$this->assertCount( 100, $adapter->warnings() );
	}

	public function test_content_batch_uses_offset_fallback_and_reports_done(): void {
		$adapter          = new ERankly_Migrations_Probe_Adapter();
		$adapter->records = array(
			array( 'n' => 1 ),
			array( 'n' => 2 ),
			array( 'n' => 3 ),
			array( 'n' => 4 ),
			array( 'n' => 5 ),
		);

		$first = $adapter->content_batch( array(), 2 );
		$this->assertSame( array( array( 'n' => 1 ), array( 'n' => 2 ) ), $first['records'] );
		$this->assertSame( array( 'offset' => 2 ), $first['cursor'] );
		$this->assertFalse( $first['done'] );

		$second = $adapter->content_batch( $first['cursor'], 2 );
		$this->assertSame( array( array( 'n' => 3 ), array( 'n' => 4 ) ), $second['records'] );
		$this->assertFalse( $second['done'] );

		$third = $adapter->content_batch( $second['cursor'], 2 );
		$this->assertSame( array( array( 'n' => 5 ) ), $third['records'] );
		$this->assertSame( array( 'offset' => 5 ), $third['cursor'] );
		$this->assertTrue( $third['done'] );
	}

	public function test_redirect_batch_defaults_to_empty_and_done(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$page    = $adapter->redirect_batch( array(), 10 );

		$this->assertSame( array(), $page['records'] );
		$this->assertTrue( $page['done'] );
	}

	public function test_value_and_option_helpers_normalize_source_data(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertSame( 'trimmed', $adapter->expose( 'value', array( 'k' => '  trimmed ' ), 'k' ) );
		$this->assertSame( '', $adapter->expose( 'value', array( 'k' => array( 'x' ) ), 'k' ) );
		$this->assertSame( '', $adapter->expose( 'value', array(), 'missing' ) );

		update_option( 'erankly_probe_option', '{"a":1,"b":2}' );
		$this->assertSame( array( 'a' => 1, 'b' => 2 ), $adapter->expose( 'option_array', 'erankly_probe_option' ) );
		$this->assertTrue( $adapter->expose( 'has_option_map', 'erankly_probe_option' ) );

		update_option( 'erankly_probe_scalar', 'plain' );
		$this->assertSame( array(), $adapter->expose( 'option_array', 'erankly_probe_scalar' ) );
		$this->assertFalse( $adapter->expose( 'has_option_map', 'erankly_probe_scalar' ) );
	}

	public function test_nested_path_helpers_handle_missing_and_false_values(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$source  = array( 'a' => array( 'b' => array( 'c' => 0 ) ), 'flag' => false );

		$this->assertSame( 0, $adapter->expose( 'nested_value', $source, 'a.b.c' ) );
		$this->assertSame( 'fallback', $adapter->expose( 'nested_value', $source, 'a.missing', 'fallback' ) );
		$this->assertTrue( $adapter->expose( 'has_nested_value', $source, array( 'a', 'b', 'c' ) ) );
		$this->assertTrue( $adapter->expose( 'has_nested_value', $source, 'flag' ) );
		$this->assertFalse( $adapter->expose( 'has_nested_value', $source, 'nope' ) );
		$this->assertSame( 0, $adapter->expose( 'first_nested_value', $source, array( 'missing', 'a.b.c' ) ) );
		$this->assertSame( 'default', $adapter->expose( 'first_nested_value', $source, array( 'missing', 'also' ), 'default' ) );
	}

	public function test_global_robots_normalizes_tokens_and_named_values(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$robots = $adapter->expose( 'global_robots', 'noindex, nofollow' );
		$this->assertSame( 1, $robots['noindex'] );
		$this->assertSame( 1, $robots['nofollow'] );
		$this->assertSame( 'noindex', $robots['index_directive'] );
		$this->assertSame( 'nofollow', $robots['follow_directive'] );

		$named = $adapter->expose(
			'global_robots',
			array(
				'max_snippet'       => '5',
				'max_image_preview' => 'large',
				'noindex'           => '0',
			)
		);
		$this->assertSame( 0, $named['noindex'] );
		$this->assertSame( '5', $named['max_snippet'] );
		$this->assertSame( 'large', $named['max_image_preview'] );

		$explicit = $adapter->expose( 'global_robots', array( 'index' => false ) );
		$this->assertSame( 'index', $explicit['index_directive'] );
	}

	public function test_has_robot_configuration_recognizes_known_directives(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertFalse( $adapter->expose( 'has_robot_configuration', '' ) );
		$this->assertFalse( $adapter->expose( 'has_robot_configuration', array() ) );
		$this->assertFalse( $adapter->expose( 'has_robot_configuration', array( 'unknown' => 1 ) ) );
		$this->assertTrue( $adapter->expose( 'has_robot_configuration', 'noindex,nofollow' ) );
		$this->assertTrue( $adapter->expose( 'has_robot_configuration', array( 'noindex' => false ) ) );
	}

	public function test_global_meta_row_builds_robots_sitemap_and_schema_fields(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$row = $adapter->expose( 'global_meta_row', 'T', 'D', array( 'noindex' => true ), false, 'WebPage', 'BlogPosting' );

		$this->assertSame( 'T', $row['title'] );
		$this->assertSame( 'D', $row['description'] );
		$this->assertSame( 1, $row['noindex'] );
		$this->assertSame( 1, $row['disable_sitemap'] );
		$this->assertSame( 'WebPage', $row['webpage_type'] );
		$this->assertSame( 'BlogPosting', $row['article_type'] );

		$empty_article = $adapter->expose( 'global_meta_row', 'T', 'D', null, true, 'WebPage', '' );
		$this->assertSame( 'none', $empty_article['article_type'] );
		$this->assertSame( 0, $empty_article['disable_sitemap'] );
		$this->assertArrayNotHasKey( 'noindex', $empty_article );
	}

	public function test_split_post_type_schema_extracts_schema_map(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		list( $clean, $schema ) = $adapter->expose(
			'split_post_type_schema',
			array(
				'post' => array(
					'title'        => 'Posts',
					'webpage_type' => 'AboutPage',
					'article_type' => 'NewsArticle',
				),
				'page' => array( 'title' => 'Pages' ),
			)
		);

		$this->assertSame(
			array(
				'webpage_type' => 'AboutPage',
				'article_type' => 'NewsArticle',
			),
			$schema['post']
		);
		$this->assertArrayNotHasKey( 'webpage_type', $clean['post'] );
		$this->assertArrayNotHasKey( 'article_type', $clean['post'] );
		$this->assertSame( 'Posts', $clean['post']['title'] );
		$this->assertArrayNotHasKey( 'page', $schema );
	}

	public function test_special_template_rewrites_context_specific_variables(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertSame( 'By {{author_name}}', $adapter->expose( 'special_template', 'By %%name%%', 'yoast', 'author' ) );
		$this->assertSame( 'On {{archive_date}}', $adapter->expose( 'special_template', 'On %%date%%', 'yoast', 'date' ) );
	}

	public function test_social_helpers_filter_urls_and_normalize_handles(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$list = $adapter->expose(
			'social_profile_list',
			array( 'https://a.example/', '', 'https://a.example/', 'javascript:alert(1)', 'https://b.example/' )
		);
		$this->assertSame( "https://a.example/\nhttps://b.example/", $list );

		$this->assertSame( '@example', $adapter->expose( 'social_handle', 'https://twitter.com/example' ) );
		$this->assertSame( '@another', $adapter->expose( 'social_handle', '@another' ) );
	}

	public function test_person_user_id_resolves_id_name_and_warns_when_unmatched(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$user_id = self::factory()->user->create( array( 'display_name' => 'Jane Unique' ) );

		$this->assertSame( $user_id, $adapter->expose( 'person_user_id', $user_id ) );
		$this->assertSame( $user_id, $adapter->expose( 'person_user_id', 0, 'Jane Unique' ) );
		$this->assertSame( 0, $adapter->expose( 'person_user_id', 0, 'Nobody Here' ) );

		$this->assertSame( 0, $adapter->expose( 'person_user_id_or_warning', 0, 'Nobody Here' ) );
		$warnings = $adapter->warnings();
		$this->assertNotEmpty( $warnings );
		$this->assertSame( 'schema_person_identity_unmatched', $warnings[0]['code'] );
		$this->assertTrue( $warnings[0]['blocking'] );
	}

	public function test_attachment_alt_reads_attachment_metadata(): void {
		$adapter       = new ERankly_Migrations_Probe_Adapter();
		$attachment_id = self::factory()->attachment->create();
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Alt text' );

		$this->assertSame( 'Alt text', $adapter->expose( 'attachment_alt', $attachment_id ) );
		$this->assertSame( '', $adapter->expose( 'attachment_alt', 0 ) );
	}

	public function test_with_extension_meta_applies_filter(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$filter  = static fn( array $mapped ): array => array( 'filtered' => true ) + $mapped;
		add_filter( 'erankly_migration_mapped_meta', $filter );

		$result = $adapter->expose( 'with_extension_meta', array( 'title' => 'X' ) );
		remove_filter( 'erankly_migration_mapped_meta', $filter );

		$this->assertTrue( $result['filtered'] );
		$this->assertSame( 'X', $result['title'] );
	}

	public function test_enabled_recognizes_truthy_tokens(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertTrue( $adapter->expose( 'enabled', 'On' ) );
		$this->assertTrue( $adapter->expose( 'enabled', 'TRUE' ) );
		$this->assertTrue( $adapter->expose( 'enabled', 1 ) );
		$this->assertFalse( $adapter->expose( 'enabled', 'off' ) );
		$this->assertFalse( $adapter->expose( 'enabled', 0 ) );
	}

	public function test_schema_blocks_drop_metadata_and_extract_entities(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$blocks = $adapter->expose(
			'schema_blocks',
			array(
				array(
					'@type'    => 'Thing',
					'name'     => 'Kept',
					'metadata' => 'dropped',
				),
				array( 'name' => 'skipped' ),
			)
		);
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'custom', $blocks[0]['type'] );
		$this->assertSame(
			array(
				'@type' => 'Thing',
				'name'  => 'Kept',
			),
			json_decode( $blocks[0]['fields']['custom_json'], true )
		);

		$graph = $adapter->expose( 'extract_schema_entities', '{"@graph":[{"@type":"A"},{"@type":"B"}]}' );
		$this->assertCount( 2, $graph );
		$this->assertSame( array( array( '@type' => 'C' ) ), $adapter->expose( 'extract_schema_entities', array( '@type' => 'C' ) ) );
		$this->assertSame( array(), $adapter->expose( 'extract_schema_entities', '{invalid json' ) );
	}

	public function test_schema_type_templates_emit_blocks_and_disabled_types(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$templates = $adapter->expose( 'schema_type_templates', 'AboutPage', 'NewsArticle' );

		$this->assertCount( 2, $templates['blocks'] );
		$this->assertContains( 'WebPage', $templates['disabled'] );
		$this->assertContains( 'Article', $templates['disabled'] );
		$this->assertContains( 'BlogPosting', $templates['disabled'] );

		$none = $adapter->expose( 'schema_type_templates', 'none', 'none' );
		$this->assertSame( array(), $none['blocks'] );
		$this->assertContains( 'WebPage', $none['disabled'] );
	}

	public function test_has_meta_and_metadata_iteration(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_probe_meta', 'value' );

		$this->assertTrue( $adapter->expose( 'has_meta', 'post', array( '_erankly_probe_meta' ) ) );
		$this->assertFalse( $adapter->expose( 'has_meta', 'post', array( '_missing_probe_meta' ) ) );
		$this->assertFalse( $adapter->expose( 'has_meta', 'bogus', array( '_erankly_probe_meta' ) ) );

		$batch = $adapter->expose( 'meta_object_batch', 'post', array( '_erankly_probe_meta' ), array(), 0, 200 );
		$this->assertGreaterThanOrEqual( 1, $batch['scanned'] );
		$this->assertTrue( $batch['done'] );
		$record = $batch['records'][0];
		$this->assertSame( $post_id, $record['id'] );
		$this->assertSame( 'value', $record['meta']['_erankly_probe_meta'] );

		$iterated = array();
		foreach ( $adapter->expose( 'meta_objects', 'post', array( '_erankly_probe_meta' ) ) as $item ) {
			$iterated[] = $item;
		}
		$this->assertNotEmpty( $iterated );
	}

	public function test_source_table_batch_rejects_unknown_and_missing_tables(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$unknown = $adapter->expose( 'source_table_batch', 'nope', 0, 10 );
		$this->assertTrue( $unknown['done'] );
		$this->assertSame( array(), $unknown['records'] );

		$missing = $adapter->expose( 'source_table_batch', 'aioseo_posts', 0, 10 );
		$this->assertTrue( $missing['done'] );
	}

	public function test_object_exists_checks_the_underlying_object(): void {
		$adapter  = new ERankly_Migrations_Probe_Adapter();
		$post_id  = self::factory()->post->create();
		$term_id  = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$user_id  = self::factory()->user->create();

		$this->assertTrue( $this->invoke_base_private( $adapter, 'object_exists', array( 'post', $post_id ) ) );
		$this->assertFalse( $this->invoke_base_private( $adapter, 'object_exists', array( 'post', 99999999 ) ) );
		$this->assertTrue( $this->invoke_base_private( $adapter, 'object_exists', array( 'term', $term_id ) ) );
		$this->assertTrue( $this->invoke_base_private( $adapter, 'object_exists', array( 'user', $user_id ) ) );
	}

	public function test_private_surface_helpers_read_columns_and_meta_queries(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$columns = $this->invoke_base_private( $adapter, 'table_columns', array( $GLOBALS['wpdb']->postmeta ) );
		$this->assertContains( 'meta_key', $columns );

		$query = $this->invoke_base_private(
			$adapter,
			'meta_surface_query',
			array(
				array(
					'type'        => 'meta',
					'object_type' => 'post',
					'keys'        => array( '_probe' ),
					'prefixes'    => array( 'p_' ),
				),
			)
		);
		$this->assertSame( $GLOBALS['wpdb']->postmeta, $query['table'] );
		$this->assertStringContainsString( 'meta_key IN', $query['where'] );
		$this->assertStringContainsString( 'meta_key LIKE', $query['where'] );
	}

	public function test_storage_surface_fingerprint_covers_option_meta_table_and_post_type(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		update_option( 'erankly_probe_fingerprint_option', array( 'a' => 1 ) );
		$option_surface = $this->invoke_base_private(
			$adapter,
			'storage_surface_fingerprint',
			array(
				array(
					'type'   => 'option',
					'option' => 'erankly_probe_fingerprint_option',
				),
			)
		);
		$this->assertIsString( $option_surface );
		$this->assertSame( 64, strlen( $option_surface ) );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_erankly_probe_meta', 'v' );
		$meta_surface = $this->invoke_base_private(
			$adapter,
			'storage_surface_fingerprint',
			array(
				array(
					'type'        => 'meta',
					'object_type' => 'post',
					'keys'        => array( '_erankly_probe_meta' ),
				),
			)
		);
		$this->assertArrayHasKey( 'row_count', $meta_surface );
		$this->assertGreaterThanOrEqual( 1, (int) $meta_surface['row_count'] );

		$table_surface = $this->invoke_base_private(
			$adapter,
			'storage_surface_fingerprint',
			array(
				array(
					'type'    => 'table',
					'suffix'  => 'posts',
					'columns' => array( 'ID' ),
				),
			)
		);
		$this->assertArrayHasKey( 'max_id', $table_surface );

		$post_type_surface = $this->invoke_base_private(
			$adapter,
			'storage_surface_fingerprint',
			array(
				array(
					'type'      => 'post_type',
					'post_type' => 'post',
				),
			)
		);
		$this->assertArrayHasKey( 'row_count', $post_type_surface );
	}

	public function test_fingerprint_is_stable_and_tracks_source_option(): void {
		$adapter              = new ERankly_Migrations_Probe_Adapter();
		$adapter->definitions = array(
			'opt' => array(
				'type'   => 'option',
				'option' => 'erankly_probe_fingerprint_option',
			),
		);

		update_option( 'erankly_probe_fingerprint_option', array( 'a' => 1 ) );
		$first = $adapter->fingerprint();
		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( $first, $adapter->fingerprint() );

		update_option( 'erankly_probe_fingerprint_option', array( 'a' => 2 ) );
		$this->assertNotSame( $first, $adapter->fingerprint() );
	}

	public function test_version_status_classifies_versions(): void {
		$adapter           = new ERankly_Migrations_Probe_Adapter();
		$adapter->versions = array(
			'min' => '1.0.0',
			'max' => '2.0.0',
		);

		$this->assertSame( 'unversioned', $adapter->version_status( '' ) );
		$this->assertSame( 'certified', $adapter->version_status( '1.5.0' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '0.9.0' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '2.0.1' ) );

		$open = new ERankly_Migrations_Probe_Adapter();
		$this->assertSame( 'certified', $open->version_status( '9.9.9' ) );
	}

	public function test_installed_plugins_and_detect_version_resolution(): void {
		$adapter = new ERankly_Migrations_Probe_Adapter();

		$this->assertSame( array(), $adapter->expose( 'installed_plugins', array() ) );
		$this->assertSame( array(), $adapter->expose( 'installed_plugins', array( 'no/such-plugin.php' ) ) );

		$this->assertSame( ABSPATH, $adapter->expose( 'detect_version', 'ABSPATH', array(), array() ) );

		update_option( 'erankly_probe_version', '7.7.7' );
		$this->assertSame( '7.7.7', $adapter->expose( 'detect_version', 'ERANKLY_NONEXISTENT_VERSION_CONST', array( 'erankly_probe_version' ), array() ) );

		$this->assertSame( '', $adapter->expose( 'detect_version', 'ERANKLY_NONEXISTENT_VERSION_CONST', array( 'erankly_missing_version' ), array() ) );
	}

	// ---------------------------------------------------------------------
	// Yoast adapter.
	// ---------------------------------------------------------------------

	public function test_yoast_identity_capabilities_and_version_range(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();

		$this->assertSame( 'yoast', $adapter->slug() );
		$this->assertSame( 'Yoast SEO', $adapter->label() );
		$this->assertContains( 'primary terms', $adapter->capabilities() );
		$this->assertSame( 'certified', $adapter->version_status( '20.0' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '2.0' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '29.0' ) );
	}

	public function test_yoast_is_available_reflects_source_surfaces(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();
		$this->assertFalse( $adapter->is_available() );

		update_option( 'wpseo', array( 'enable_xml_sitemap' => true ) );
		$this->assertTrue( $adapter->is_available() );
	}

	public function test_yoast_version_reads_option_and_constant(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();
		update_option( 'wpseo', array( 'version' => '21.4' ) );

		$this->assertSame( '21.4', $adapter->version() );
	}

	public function test_yoast_global_settings_maps_titles_robots_and_schema(): void {
		update_option(
			'wpseo_titles',
			array(
				'title-post'             => 'Site - %%title%%',
				'metadesc-post'          => 'Post description',
				'noindex-post'           => true,
				'schema-page-type-post'  => 'AboutPage',
				'schema-article-type-post' => 'NewsArticle',
			)
		);
		update_option( 'wpseo', array( 'enable_xml_sitemap' => true ) );

		$settings = ( new ERankly_Migration_Adapter_Yoast() )->global_settings();

		$this->assertArrayHasKey( 'global_post_type_meta', $settings );
		$this->assertSame( 'Site - {{post_title}}', $settings['global_post_type_meta']['post']['title'] );
		$this->assertSame( 'Post description', $settings['global_post_type_meta']['post']['description'] );
		$this->assertSame( 1, $settings['global_post_type_meta']['post']['noindex'] );
		$this->assertSame( 1, $settings['global_post_type_meta']['post']['disable_sitemap'] );
		$this->assertSame( 1, $settings['enable_sitemap'] );
		$this->assertSame(
			array(
				'webpage_type' => 'AboutPage',
				'article_type' => 'NewsArticle',
			),
			$settings['global_post_type_schema']['post']
		);
	}

	public function test_yoast_global_settings_maps_special_contexts_and_social(): void {
		update_option(
			'wpseo_titles',
			array(
				'title-author-wpseo'     => 'Author %%name%%',
				'metadesc-author-wpseo'  => 'About author',
				'noindex-author-wpseo'   => true,
			)
		);
		update_option(
			'wpseo_social',
			array(
				'facebook_site'   => 'https://facebook.com/example',
				'twitter_site'    => 'https://twitter.com/example',
				'og_default_image' => 'https://cdn.example/img.jpg',
			)
		);

		$settings = ( new ERankly_Migration_Adapter_Yoast() )->global_settings();

		$this->assertSame( 'Author {{author_name}}', $settings['global_special_meta']['author']['title'] );
		$this->assertSame( 1, $settings['global_special_meta']['author']['noindex'] );
		$this->assertSame( '@example', $settings['twitter_site'] );
		$this->assertSame( 'https://cdn.example/img.jpg', $settings['default_social_image_url'] );
		$this->assertStringContainsString( 'https://facebook.com/example', $settings['social_profiles'] );
	}

	public function test_yoast_content_records_maps_post_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Yoast %%title%%' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_Yoast() )->content_records() );
		$found   = null;
		foreach ( $records as $record ) {
			if ( 'post' === $record['object_type'] && $post_id === $record['object_id'] ) {
				$found = $record;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'Yoast {{post_title}}', $found['meta']['_erankly_title'] );
		$this->assertSame( 'noindex', $found['meta']['_erankly_index_directive'] );
		$this->assertSame( 'nofollow', $found['meta']['_erankly_follow_directive'] );
	}

	public function test_yoast_content_batch_advances_across_stages(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Batch title' );

		$adapter = new ERankly_Migration_Adapter_Yoast();
		$first   = $adapter->content_batch( array(), 200 );
		$this->assertNotEmpty( $first['records'] );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 'term_meta', $first['cursor']['stage'] );

		$page = $first;
		$guard = 0;
		while ( ! $page['done'] && $guard++ < 10 ) {
			$page = $adapter->content_batch( $page['cursor'], 200 );
		}
		$this->assertTrue( $page['done'] );
	}

	public function test_yoast_redirect_records_map_premium_base_and_legacy(): void {
		update_option(
			'wpseo-premium-redirects-base',
			array(
				array(
					'origin' => '/old-page',
					'url'    => '/new-page',
					'type'   => 301,
					'format' => 'plain',
				),
			)
		);
		update_option( 'wpseo-premium-redirects-export-regex', array( '/^legacy/(.*)$/' => '/catch/$1' ) );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_Yoast() )->redirect_records() );

		$this->assertNotEmpty( $records );
		$this->assertSame( '/old-page', $records[0]['source_path'] );
		$this->assertSame( '/new-page', $records[0]['target_url'] );
		$this->assertSame( 'exact', $records[0]['match_type'] );

		$regex = end( $records );
		$this->assertSame( 'regex', $regex['match_type'] );
	}

	public function test_yoast_fingerprint_changes_with_source_option(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();

		update_option( 'wpseo', array( 'a' => 1 ) );
		$first = $adapter->fingerprint();
		update_option( 'wpseo', array( 'a' => 2 ) );
		$second = $adapter->fingerprint();

		$this->assertSame( 64, strlen( $first ) );
		$this->assertNotSame( $first, $second );
	}

	public function test_yoast_private_mapping_and_keys_helpers(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();

		$mapped = $this->invoke_private(
			ERankly_Migration_Adapter_Yoast::class,
			$adapter,
			'map_meta',
			array(
				array(
					'_yoast_wpseo_title'                      => 'Y %%title%%',
					'_yoast_wpseo_metadesc'                   => 'Y desc',
					'_yoast_wpseo_meta-robots-noindex'        => '1',
					'_yoast_wpseo_meta-robots-adv'            => 'noarchive,nosnippet,noimageindex',
					'_yoast_wpseo_schema_page_type'           => 'AboutPage',
					'_yoast_wpseo_primary_category'           => 7,
				),
				false,
			)
		);
		$this->assertSame( 'Y {{post_title}}', $mapped['_erankly_title'] );
		$this->assertSame( 'noindex', $mapped['_erankly_index_directive'] );
		$this->assertSame( 'noarchive', $mapped['_erankly_archive_directive'] );
		$this->assertSame( 'nosnippet', $mapped['_erankly_snippet_directive'] );
		$this->assertSame( 'noimageindex', $mapped['_erankly_image_directive'] );
		$this->assertSame( 7, $mapped['_erankly_primary_terms']['category'] );
		$this->assertSame( 'merge', $mapped['_erankly_schema_mode'] );
		$this->assertContains( 'WebPage', $mapped['_erankly_schema_disabled_types'] );

		$user = $this->invoke_private(
			ERankly_Migration_Adapter_Yoast::class,
			$adapter,
			'map_user_meta',
			array(
				array(
					'wpseo_title'          => '%%name%%',
					'wpseo_noindex_author' => 'on',
				),
			)
		);
		$this->assertSame( '{{author_name}}', $user['_erankly_title'] );
		$this->assertSame( 'noindex', $user['_erankly_index_directive'] );

		$this->assertContains( '_yoast_wpseo_title', $this->invoke_private( ERankly_Migration_Adapter_Yoast::class, $adapter, 'post_keys' ) );
		$this->assertContains( 'wpseo_title', $this->invoke_private( ERankly_Migration_Adapter_Yoast::class, $adapter, 'user_keys' ) );

		$redirect = $this->invoke_private( ERankly_Migration_Adapter_Yoast::class, $adapter, 'redirect_from_values', array( '/old?x=1', '/new', 302, false, 'ref' ) );
		$this->assertSame( 'x=1', $redirect['source_query'] );
		$this->assertSame( 'exact', $redirect['query_mode'] );
		$this->assertSame( 'exact', $redirect['match_type'] );
	}

	public function test_yoast_option_batches_page_source_options(): void {
		$adapter = new ERankly_Migration_Adapter_Yoast();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		update_option( 'wpseo_taxonomy_meta', array( 'category' => array( $term_id => array( 'wpseo_title' => 'Term title' ) ) ) );
		$terms = $this->invoke_private( ERankly_Migration_Adapter_Yoast::class, $adapter, 'taxonomy_option_batch', array( 0, 10 ) );
		$this->assertNotEmpty( $terms['records'] );
		$this->assertSame( 'term', $terms['records'][0]['object_type'] );
		$this->assertSame( $term_id, $terms['records'][0]['object_id'] );

		update_option(
			'wpseo-premium-redirects-base',
			array(
				array(
					'origin' => '/a',
					'url'    => '/b',
					'type'   => 302,
				),
			)
		);
		$redirects = $this->invoke_private( ERankly_Migration_Adapter_Yoast::class, $adapter, 'redirect_option_batch', array( 'premium_base', 0, 10 ) );
		$this->assertNotEmpty( $redirects['records'] );
		$this->assertSame( '/a', $redirects['records'][0]['source_path'] );
		$this->assertSame( '/b', $redirects['records'][0]['target_url'] );
	}

	// ---------------------------------------------------------------------
	// Rank Math adapter.
	// ---------------------------------------------------------------------
	public function test_rankmath_identity_range_and_availability(): void {
		$adapter = new ERankly_Migration_Adapter_RankMath();

		$this->assertSame( 'rankmath', $adapter->slug() );
		$this->assertSame( 'Rank Math', $adapter->label() );
		$this->assertSame( 'certified', $adapter->version_status( '1.0.100' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '2.0.0' ) );
		$this->assertFalse( $adapter->is_available() );

		update_option( 'rank-math-options-titles', array( 'homepage_title' => 'Home' ) );
		$this->assertTrue( $adapter->is_available() );
	}

	public function test_rankmath_version_reads_option(): void {
		update_option( 'rank_math_version', '1.0.219' );

		$this->assertSame( '1.0.219', ( new ERankly_Migration_Adapter_RankMath() )->version() );
	}

	public function test_rankmath_global_settings_maps_post_and_special(): void {
		update_option(
			'rank-math-options-titles',
			array(
				'pt_post_title'          => 'RM - %title%',
				'pt_post_description'    => 'RM desc',
				'pt_post_custom_robots'  => true,
				'pt_post_robots'         => array( 'noindex' => 'on' ),
				'homepage_title'         => 'Home Title',
				'homepage_description'   => 'Home Desc',
			)
		);
		update_option( 'rank-math-options-sitemap', array( 'pt_post_sitemap' => 'on' ) );

		$settings = ( new ERankly_Migration_Adapter_RankMath() )->global_settings();

		$this->assertSame( 'RM - {{post_title}}', $settings['global_post_type_meta']['post']['title'] );
		$this->assertSame( 1, $settings['global_post_type_meta']['post']['noindex'] );
		$this->assertSame( 'Home Title', $settings['global_special_meta']['homepage']['title'] );
		$this->assertSame( 1, $settings['enable_sitemap'] );
	}

	public function test_rankmath_content_records_map_meta_and_robots(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'rank_math_title', 'RMath %title%' );
		update_post_meta( $post_id, 'rank_math_robots', array( 'noindex', 'nofollow' ) );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_RankMath() )->content_records() );
		$found   = null;
		foreach ( $records as $record ) {
			if ( 'post' === $record['object_type'] && $post_id === $record['object_id'] ) {
				$found = $record;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'RMath {{post_title}}', $found['meta']['_erankly_title'] );
		$this->assertSame( 'noindex', $found['meta']['_erankly_index_directive'] );
		$this->assertSame( 'nofollow', $found['meta']['_erankly_follow_directive'] );
	}

	public function test_rankmath_redirect_records_empty_without_source_table(): void {
		$adapter = new ERankly_Migration_Adapter_RankMath();

		$this->assertSame( array(), iterator_to_array( $adapter->redirect_records() ) );

		$page = $adapter->redirect_batch( array(), 10 );
		$this->assertSame( array(), $page['records'] );
		$this->assertTrue( $page['done'] );
	}

	public function test_rankmath_map_meta_maps_robots_primary_schema_and_twitter(): void {
		$adapter = new ERankly_Migration_Adapter_RankMath();
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$meta = array(
			'rank_math_title'             => 'RM %title%',
			'rank_math_description'       => 'RM desc',
			'rank_math_robots'            => array( 'noindex', 'nofollow', 'noimageindex' ),
			'rank_math_advanced_robots'   => array( 'max-snippet' => '4' ),
			'rank_math_primary_category'  => $term_id,
			'rank_math_twitter_card_type' => 'summary_large_image',
			'rank_math_schema_Article'    => array(
				array(
					'@type'    => 'Article',
					'headline' => '%title%',
				),
			),
		);

		$mapped = $this->invoke_private( ERankly_Migration_Adapter_RankMath::class, $adapter, 'map_meta', array( $meta, 'post' ) );

		$this->assertSame( 'RM {{post_title}}', $mapped['_erankly_title'] );
		$this->assertSame( 'noindex', $mapped['_erankly_index_directive'] );
		$this->assertSame( 'nofollow', $mapped['_erankly_follow_directive'] );
		$this->assertSame( 'noimageindex', $mapped['_erankly_image_directive'] );
		$this->assertSame( '4', $mapped['_erankly_max_snippet'] );
		$this->assertSame( 'summary_large_image', $mapped['_erankly_twitter_card_type'] );
		$this->assertSame( $term_id, $mapped['_erankly_primary_terms']['category'] );
		$this->assertSame( 'merge', $mapped['_erankly_schema_mode'] );
		$this->assertSame(
			'{{post_title}}',
			json_decode( $mapped['_erankly_schema_blocks'][0]['fields']['custom_json'], true )['headline']
		);
	}

	// ---------------------------------------------------------------------
	// SEOPress adapter.
	// ---------------------------------------------------------------------

	public function test_seopress_identity_and_availability(): void {
		$adapter = new ERankly_Migration_Adapter_SEOPress();

		$this->assertSame( 'seopress', $adapter->slug() );
		$this->assertSame( 'SEOPress', $adapter->label() );
		$this->assertSame( 'certified', $adapter->version_status( '5.0' ) );
		$this->assertFalse( $adapter->is_available() );

		update_option( 'seopress_titles_option_name', array( 'seopress_titles_home_site_title' => 'Home' ) );
		$this->assertTrue( $adapter->is_available() );
	}

	public function test_seopress_version_reads_option(): void {
		update_option( 'seopress_version', '7.9' );

		$this->assertSame( '7.9', ( new ERankly_Migration_Adapter_SEOPress() )->version() );
	}

	public function test_seopress_global_settings_maps_titles_and_special(): void {
		update_option(
			'seopress_titles_option_name',
			array(
				'seopress_titles_single_titles'       => array(
					'post' => array(
						'title'       => 'SP - %%title%%',
						'description' => 'SP desc',
					),
				),
				'seopress_titles_home_site_title'     => 'SP Home',
				'seopress_titles_home_site_desc'      => 'SP Home Desc',
			)
		);

		$settings = ( new ERankly_Migration_Adapter_SEOPress() )->global_settings();

		$this->assertSame( 'SP - {{post_title}}', $settings['global_post_type_meta']['post']['title'] );
		$this->assertSame( 'SP Home', $settings['global_special_meta']['homepage']['title'] );
	}

	public function test_seopress_content_records_map_meta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seopress_titles_title', 'SP %%title%%' );
		update_post_meta( $post_id, '_seopress_robots_index', '1' );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_SEOPress() )->content_records() );
		$found   = null;
		foreach ( $records as $record ) {
			if ( 'post' === $record['object_type'] && $post_id === $record['object_id'] ) {
				$found = $record;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'SP {{post_title}}', $found['meta']['_erankly_title'] );
		$this->assertSame( 'noindex', $found['meta']['_erankly_index_directive'] );
	}

	public function test_seopress_content_records_map_legacy_schema(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_type', 'products' );
		update_post_meta( $post_id, '_seopress_pro_rich_snippets_products_name', 'My Widget' );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_SEOPress() )->content_records() );
		$found   = null;
		foreach ( $records as $record ) {
			if ( 'post' === $record['object_type'] && $post_id === $record['object_id'] ) {
				$found = $record;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'merge', $found['meta']['_erankly_schema_mode'] );
		$decoded = json_decode( $found['meta']['_erankly_schema_blocks'][0]['fields']['custom_json'], true );
		$this->assertSame( 'Product', $decoded['@type'] );
		$this->assertSame( 'My Widget', $decoded['name'] );
	}

	public function test_seopress_redirect_batch_maps_record(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_seopress_redirections_value', 'https://target.example/dest' );
		update_post_meta( $post_id, '_seopress_redirections_enabled', '1' );
		update_post_meta( $post_id, '_seopress_redirections_type', '302' );

		$page = ( new ERankly_Migration_Adapter_SEOPress() )->redirect_batch( array(), 10 );

		$this->assertNotEmpty( $page['records'] );
		$this->assertSame( 'https://target.example/dest', $page['records'][0]['target_url'] );
		$this->assertSame( 302, $page['records'][0]['status_code'] );
		$this->assertSame( 1, $page['records'][0]['is_active'] );
	}

	public function test_seopress_private_mapping_and_redirect_record_helpers(): void {
		$adapter = new ERankly_Migration_Adapter_SEOPress();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$mapped = $this->invoke_private(
			ERankly_Migration_Adapter_SEOPress::class,
			$adapter,
			'map_meta',
			array(
				array(
					'_seopress_titles_title'                   => 'SP %%title%%',
					'_seopress_robots_index'                   => '1',
					'_seopress_robots_follow'                  => '1',
					'_seopress_pro_rich_snippets_type'         => 'products',
					'_seopress_pro_rich_snippets_products_name' => 'Widget',
				),
				'post',
				$post_id,
			)
		);
		$this->assertSame( 'SP {{post_title}}', $mapped['_erankly_title'] );
		$this->assertSame( 'noindex', $mapped['_erankly_index_directive'] );
		$this->assertSame( 'nofollow', $mapped['_erankly_follow_directive'] );
		$this->assertSame( 'merge', $mapped['_erankly_schema_mode'] );

		$entity = $this->invoke_private(
			ERankly_Migration_Adapter_SEOPress::class,
			$adapter,
			'legacy_schema_entity',
			array(
				array(
					'_seopress_pro_rich_snippets_type'          => 'recipes',
					'_seopress_pro_rich_snippets_recipes_name'  => 'Cake',
				),
			)
		);
		$this->assertSame( 'Recipe', $entity['@type'] );
		$this->assertSame( 'Cake', $entity['name'] );
		$this->assertSame( '{{post_excerpt}}', $entity['description'] );

		$this->assertContains( '_seopress_titles_title', $this->invoke_private( ERankly_Migration_Adapter_SEOPress::class, $adapter, 'content_keys' ) );
		$this->assertContains( '_seopress_redirections_value', $this->invoke_private( ERankly_Migration_Adapter_SEOPress::class, $adapter, 'redirect_keys' ) );
		$this->assertFalse( $this->invoke_private( ERankly_Migration_Adapter_SEOPress::class, $adapter, 'has_redirect_posts' ) );

		$record = $this->invoke_private(
			ERankly_Migration_Adapter_SEOPress::class,
			$adapter,
			'map_redirect_record',
			array(
				'post',
				array(
					'id'   => $post_id,
					'meta' => array(
						'_seopress_redirections_value'   => '/dest',
						'_seopress_redirections_enabled' => '1',
						'_seopress_redirections_type'    => '301',
					),
				),
			)
		);
		$this->assertSame( '/dest', $record['target_url'] );
		$this->assertSame( 1, $record['is_active'] );
	}

	// ---------------------------------------------------------------------
	// AIOSEO adapter.
	// ---------------------------------------------------------------------

	public function test_aioseo_identity_and_availability(): void {
		$adapter = new ERankly_Migration_Adapter_AIOSEO();

		$this->assertSame( 'aioseo', $adapter->slug() );
		$this->assertSame( 'All in One SEO', $adapter->label() );
		$this->assertSame( 'certified', $adapter->version_status( '4.5' ) );
		$this->assertSame( 'unsupported', $adapter->version_status( '6.0' ) );
		$this->assertFalse( $adapter->is_available() );

		update_option( 'aioseo_options', array( 'a' => 1 ) );
		$this->assertTrue( $adapter->is_available() );
	}

	public function test_aioseo_version_reads_option(): void {
		update_option( 'aioseo_version', '4.6.5' );

		$this->assertSame( '4.6.5', ( new ERankly_Migration_Adapter_AIOSEO() )->version() );
	}

	public function test_aioseo_global_settings_maps_nested_options(): void {
		update_option(
			'aioseo_options',
			array(
				'searchAppearance' => array(
					'global' => array(
						'schema' => array( 'websiteName' => 'AIOSEO Site' ),
					),
				),
				'sitemap'          => array(
					'general' => array( 'enable' => true ),
				),
			)
		);
		update_option(
			'aioseo_options_dynamic',
			array(
				'searchAppearance' => array(
					'postTypes' => array(
						'post' => array(
							'title'           => 'AI #title',
							'metaDescription' => 'AI desc',
							'schemaType'      => 'article',
							'articleType'     => 'NewsArticle',
						),
					),
				),
			)
		);

		$settings = ( new ERankly_Migration_Adapter_AIOSEO() )->global_settings();

		$this->assertSame( 'AIOSEO Site', $settings['website_name'] );
		$this->assertSame( 'AI {{post_title}}', $settings['global_post_type_meta']['post']['title'] );
		$this->assertSame( 1, $settings['enable_sitemap'] );
	}

	public function test_aioseo_content_records_map_v3_postmeta(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_aioseop_title', 'V3 #title' );
		update_post_meta( $post_id, '_aioseop_noindex', 'on' );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_AIOSEO() )->content_records() );
		$found   = null;
		foreach ( $records as $record ) {
			if ( 'post' === $record['object_type'] && $post_id === $record['object_id'] ) {
				$found = $record;
			}
		}

		$this->assertNotNull( $found );
		$this->assertSame( 'V3 {{post_title}}', $found['meta']['_erankly_title'] );
		$this->assertSame( 'noindex', $found['meta']['_erankly_index_directive'] );
	}

	public function test_aioseo_content_batch_is_empty_without_source_tables(): void {
		$page = ( new ERankly_Migration_Adapter_AIOSEO() )->content_batch( array(), 200 );

		$this->assertSame( array(), $page['records'] );
		$this->assertTrue( $page['done'] );
	}

	public function test_aioseo_map_row_maps_v4_columns_schema_and_primary_term(): void {
		$adapter = new ERankly_Migration_Adapter_AIOSEO();
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$row = array(
			'title'               => 'V4 #title',
			'description'         => 'V4 desc',
			'canonical_url'       => '/canonical',
			'og_title'            => 'OG title',
			'twitter_card'        => 'summary_large_image',
			'twitter_use_og'      => 1,
			'robots_default'      => 0,
			'robots_noindex'      => 1,
			'primary_term'        => wp_json_encode( array( 'category' => $term_id ) ),
			'schema'              => wp_json_encode( array( array( '@type' => 'Article', 'headline' => '#title' ) ) ),
			'schema_type'         => 'Product',
			'schema_type_options' => wp_json_encode( array( 'product' => array( 'name' => 'Widget' ) ) ),
		);

		$mapped = $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'map_row', array( $row, 'post', $post_id ) );

		$this->assertSame( 'V4 {{post_title}}', $mapped['_erankly_title'] );
		$this->assertSame( '/canonical', $mapped['_erankly_canonical'] );
		$this->assertSame( 'noindex', $mapped['_erankly_index_directive'] );
		$this->assertSame( 'summary_large_image', $mapped['_erankly_twitter_card_type'] );
		$this->assertSame( 'OG title', $mapped['_erankly_twitter_title'] );
		$this->assertSame( $term_id, $mapped['_erankly_primary_terms']['category'] );
		$this->assertSame( 'merge', $mapped['_erankly_schema_mode'] );

		$types = array();
		foreach ( $mapped['_erankly_schema_blocks'] as $block ) {
			$types[] = json_decode( $block['fields']['custom_json'], true )['@type'];
		}
		$this->assertContains( 'Article', $types );
		$this->assertContains( 'Product', $types );
	}

	public function test_aioseo_private_helpers_handle_robots_sitemap_and_social(): void {
		$adapter = new ERankly_Migration_Adapter_AIOSEO();

		$this->assertSame(
			array(
				'noindex'           => false,
				'nofollowPaginated' => true,
				'maxImagePreview'   => 'large',
			),
			array_intersect_key(
				$this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'resolved_global_robots', array( array( 'default' => true ) ) ),
				array(
					'noindex'           => true,
					'nofollowPaginated' => true,
					'maxImagePreview'   => true,
				)
			)
		);

		$this->assertSame(
			array( 'noindex' => true ),
			$this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'effective_robots', array( array( 'advanced' => array( 'robotsMeta' => array( 'noindex' => true ) ) ), array() ) )
		);
		$this->assertSame(
			array( 'global' => true ),
			$this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'effective_robots', array( array( 'robotsMeta' => array( 'default' => true ) ), array( 'global' => true ) ) )
		);

		$this->assertTrue(
			$this->invoke_private(
				ERankly_Migration_Adapter_AIOSEO::class,
				$adapter,
				'sitemap_membership',
				array( array( 'sitemap' => array( 'general' => array( 'postTypes' => array( 'all' => true ) ) ) ), 'postTypes', 'post' )
			)
		);
		$this->assertTrue(
			$this->invoke_private(
				ERankly_Migration_Adapter_AIOSEO::class,
				$adapter,
				'sitemap_membership',
				array( array( 'sitemap' => array( 'general' => array( 'taxonomies' => array( 'included' => array( 'category' ) ) ) ) ), 'taxonomies', 'category' )
			)
		);
		$this->assertNull(
			$this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'sitemap_membership', array( array(), 'postTypes', 'post' ) )
		);

		$this->assertSame(
			array( 'https://x.com/example' ),
			$this->invoke_private(
				ERankly_Migration_Adapter_AIOSEO::class,
				$adapter,
				'same_username_profiles',
				array( array( 'social' => array( 'profiles' => array( 'sameUsername' => array( 'enable' => true, 'username' => '@example', 'included' => array( 'twitterUrl' ) ) ) ) ) )
			)
		);

		$this->assertSame( 'preserve', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'aioseo_query_mode', array( 'preserve', '' ) ) );
		$this->assertSame( 'preserve', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'aioseo_query_mode', array( 'pass_query', 'a=1' ) ) );
		$this->assertSame( 'exact', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'aioseo_query_mode', array( 'exact_match', '' ) ) );
		$this->assertSame( 'exact', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'aioseo_query_mode', array( '', 'a=1' ) ) );
		$this->assertSame( 'ignore', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'aioseo_query_mode', array( '', '' ) ) );

		$this->assertSame( 'summary_large_image', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'twitter_card', array( 'summary_large_image' ) ) );
		$this->assertSame( 'summary', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'twitter_card', array( 'summary' ) ) );
		$this->assertSame( '', $this->invoke_private( ERankly_Migration_Adapter_AIOSEO::class, $adapter, 'twitter_card', array( 'default' ) ) );
	}

	// ---------------------------------------------------------------------
	// Cross-adapter surface checks.
	// ---------------------------------------------------------------------

	public function test_every_adapter_declares_capabilities_and_storage_fingerprint(): void {
		$adapters = array(
			new ERankly_Migration_Adapter_Yoast(),
			new ERankly_Migration_Adapter_RankMath(),
			new ERankly_Migration_Adapter_AIOSEO(),
			new ERankly_Migration_Adapter_SEOPress(),
		);

		foreach ( $adapters as $adapter ) {
			$class = get_class( $adapter );
			$this->assertNotEmpty( $adapter->capabilities(), $class . ' capabilities' );
			// fingerprint() hashes the adapter's storage_definitions() surfaces.
			$this->assertSame( 64, strlen( $adapter->fingerprint() ), $class . ' fingerprint' );
		}
	}

	public function test_rankmath_and_seopress_content_batch_return_records(): void {
		$rank_post = self::factory()->post->create();
		update_post_meta( $rank_post, 'rank_math_title', 'RM batch title' );

		$rank_page = ( new ERankly_Migration_Adapter_RankMath() )->content_batch( array(), 200 );
		$this->assertNotEmpty( $rank_page['records'] );
		$this->assertSame( $rank_post, $rank_page['records'][0]['object_id'] );
		$this->assertSame( 'post', $rank_page['records'][0]['object_type'] );

		$sp_post = self::factory()->post->create();
		update_post_meta( $sp_post, '_seopress_titles_title', 'SP batch title' );

		$sp_page = ( new ERankly_Migration_Adapter_SEOPress() )->content_batch( array(), 200 );
		$this->assertNotEmpty( $sp_page['records'] );
		$this->assertSame( $sp_post, $sp_page['records'][0]['object_id'] );
	}

	public function test_yoast_redirect_batch_and_aioseo_redirect_guards(): void {
		update_option(
			'wpseo-premium-redirects-base',
			array(
				array(
					'origin' => '/old',
					'url'    => '/new',
					'type'   => 301,
				),
			)
		);

		$page = ( new ERankly_Migration_Adapter_Yoast() )->redirect_batch( array(), 10 );
		$this->assertNotEmpty( $page['records'] );
		$this->assertSame( '/old', $page['records'][0]['source_path'] );
		$this->assertSame( '/new', $page['records'][0]['target_url'] );

		$aioseo = new ERankly_Migration_Adapter_AIOSEO();
		$this->assertSame( array(), iterator_to_array( $aioseo->redirect_records() ) );
		$aioseo_page = $aioseo->redirect_batch( array(), 10 );
		$this->assertSame( array(), $aioseo_page['records'] );
		$this->assertTrue( $aioseo_page['done'] );
	}

	public function test_seopress_redirect_records_map_post_meta(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_seopress_redirections_value', '/dest' );
		update_post_meta( $post_id, '_seopress_redirections_enabled', '1' );

		$records = iterator_to_array( ( new ERankly_Migration_Adapter_SEOPress() )->redirect_records() );
		$this->assertNotEmpty( $records );
		$this->assertSame( '/dest', $records[0]['target_url'] );
		$this->assertSame( 1, $records[0]['is_active'] );
	}
}
