<?php
/**
 * Tests for the import from Yoast SEO and Rank Math.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Import;

use EasyRankly\Import\Admin\Page;
use EasyRankly\Import\Import;
use EasyRankly\Import\RankMath;
use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The import copies fields and redirects in batches, never overwrites, and can run again.
 */
final class ImportTest extends WP_UnitTestCase {

	/**
	 * Registers the meta and the redirect post type, and logs in an administrator.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Meta() )->register_meta();
		( new Redirects() )->register_post_type();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Runs the import of a source until it is done.
	 *
	 * @param string $slug Source slug.
	 * @return array<string, int|string> Final progress.
	 */
	private function import( string $slug ): array {
		$progress = Import::run( $slug, true );
		for ( $i = 0; $i < 20 && 'done' !== $progress['stage']; $i++ ) {
			$progress = Import::run( $slug );
		}

		return $progress;
	}

	/**
	 * Yoast post fields become EasyRankly fields, with the variables converted.
	 */
	public function test_yoast_post_fields(): void {
		$image   = self::factory()->attachment->create_object( 'share.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$post_id = self::factory()->post->create(
			array(
				'meta_input' => array(
					'_yoast_wpseo_title'                => '%%title%% %%sep%% %%sitename%% %%focuskw%%',
					'_yoast_wpseo_metadesc'             => 'About %%cf_price%% and %%cf_bad field%%',
					'_yoast_wpseo_canonical'            => 'https://example.org/caff%C3%A8/',
					'_yoast_wpseo_meta-robots-noindex'  => '1',
					'_yoast_wpseo_meta-robots-nofollow' => '0',
					'_yoast_wpseo_opengraph-title'      => 'Share me',
					'_yoast_wpseo_opengraph-image-id'   => (string) $image,
				),
			)
		);
		$index   = self::factory()->post->create( array( 'meta_input' => array( '_yoast_wpseo_meta-robots-noindex' => '2' ) ) );

		$progress = $this->import( 'yoast' );

		$this->assertSame( '{{title}} {{sep}} {{site_name}}', Meta::post( $post_id, 'title' ) );
		$this->assertSame( 'About {{cf:price}} and', Meta::post( $post_id, 'description' ) );
		$this->assertSame( 'https://example.org/caff%C3%A8/', Meta::post( $post_id, 'canonical' ) );
		$this->assertTrue( (bool) Meta::post( $post_id, 'noindex' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_easyrankly_nofollow' ) );
		$this->assertSame( 'Share me', Meta::post( $post_id, 'og_title' ) );
		$this->assertSame( $image, (int) Meta::post( $post_id, 'og_image' ) );
		$this->assertFalse( metadata_exists( 'post', $index, '_easyrankly_noindex' ) );
		$this->assertSame( 1, $progress['posts'] );
	}

	/**
	 * Values already set in EasyRankly are kept, social texts with variables are skipped, and a
	 * second run changes nothing.
	 */
	public function test_existing_values_are_kept_and_runs_again(): void {
		$post_id = self::factory()->post->create(
			array(
				'meta_input' => array(
					'_yoast_wpseo_title'           => 'Theirs',
					'_yoast_wpseo_metadesc'        => 'Their description',
					'_yoast_wpseo_opengraph-title' => '%%title%% on social',
					'_easyrankly_title'            => 'Ours',
				),
			)
		);

		$first = $this->import( 'yoast' );
		update_post_meta( $post_id, '_easyrankly_description', 'Edited after the import' );
		$second = $this->import( 'yoast' );

		$this->assertSame( 'Ours', Meta::post( $post_id, 'title' ) );
		$this->assertSame( 'Edited after the import', Meta::post( $post_id, 'description' ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, '_easyrankly_og_title' ) );
		$this->assertSame( 1, $first['kept'] );
		$this->assertSame( 0, $second['posts'] );
		$this->assertSame( 2, $second['kept'] );
	}

	/**
	 * Yoast term fields come from its option, by taxonomy and term ID.
	 */
	public function test_yoast_term_fields(): void {
		$term_id = self::factory()->category->create();
		update_option(
			'wpseo_taxonomy_meta',
			array(
				'category' => array(
					$term_id => array(
						'wpseo_title'   => '%%term_title%% archive',
						'wpseo_desc'    => '%%term_description%%',
						'wpseo_noindex' => 'noindex',
					),
				),
			)
		);

		$progress = $this->import( 'yoast' );

		$this->assertSame( '{{title}} archive', Meta::term( $term_id, 'title' ) );
		$this->assertSame( '{{term_description}}', Meta::term( $term_id, 'description' ) );
		$this->assertTrue( (bool) Meta::term( $term_id, 'noindex' ) );
		$this->assertSame( 1, $progress['terms'] );
	}

	/**
	 * Rank Math fields of posts and terms, with its robots list and custom field variable.
	 */
	public function test_rank_math_fields(): void {
		$post_id = self::factory()->post->create(
			array(
				'meta_input' => array(
					'rank_math_title'          => '%title% %sep% %sitename% %customfield(price)% 50% off',
					'rank_math_robots'         => array( 'noindex', 'nofollow' ),
					'rank_math_facebook_title' => 'Share me',
				),
			)
		);
		$term_id = self::factory()->category->create();
		update_term_meta( $term_id, 'rank_math_description', '%term_description% %date(F)%' );
		update_term_meta( $term_id, 'rank_math_robots', array( 'index' ) );

		$this->import( 'rankmath' );

		$this->assertSame( '{{title}} {{sep}} {{site_name}} {{cf:price}} 50% off', Meta::post( $post_id, 'title' ) );
		$this->assertTrue( (bool) Meta::post( $post_id, 'noindex' ) );
		$this->assertTrue( (bool) Meta::post( $post_id, 'nofollow' ) );
		$this->assertSame( 'Share me', Meta::post( $post_id, 'og_title' ) );
		$this->assertSame( '{{term_description}}', Meta::term( $term_id, 'description' ) );
		$this->assertFalse( metadata_exists( 'term', $term_id, '_easyrankly_noindex' ) );
	}

	/**
	 * Yoast Premium redirects: paths get their leading slash, regexes their "^/", an existing
	 * source is kept and an unsupported code is rejected.
	 */
	public function test_yoast_redirects(): void {
		Redirects::save_exact( '/taken', '/ours' );
		update_option(
			'wpseo-premium-redirects-base',
			array(
				array(
					'origin' => 'old-page',
					'url'    => 'new-page/',
					'type'   => 301,
					'format' => 'plain',
				),
				array(
					'origin' => '^blog/(.*)',
					'url'    => 'news/$1',
					'type'   => 302,
					'format' => 'regex',
				),
				array(
					'origin' => 'gone',
					'url'    => '',
					'type'   => 410,
					'format' => 'plain',
				),
				array(
					'origin' => 'taken',
					'url'    => 'theirs',
					'type'   => 301,
					'format' => 'plain',
				),
				array(
					'origin' => 'odd',
					'url'    => 'elsewhere',
					'type'   => 303,
					'format' => 'plain',
				),
			)
		);

		$progress = $this->import( 'yoast' );

		$exact = Redirects::rule( (int) Redirects::find_id( md5( '/old-page' ) ) );
		$this->assertSame( '/new-page/', $exact['target'] ?? null );
		$this->assertSame( 301, $exact['code'] ?? null );
		$this->assertSame( 410, Redirects::rule( (int) Redirects::find_id( md5( '/gone' ) ) )['code'] ?? null );
		$this->assertSame( '/ours', Redirects::rule( (int) Redirects::find_id( md5( '/taken' ) ) )['target'] ?? null );

		$regex = get_posts(
			array(
				'post_type' => Redirects::POST_TYPE,
				'name'      => 'regex-' . md5( '^/blog/(.*)' ),
				'fields'    => 'ids',
			)
		);
		$this->assertCount( 1, $regex );
		$this->assertTrue( Redirects::rule( (int) $regex[0] )['regex'] ?? false );
		$this->assertSame( array( '^/blog/(.*)' ), array_column( (array) get_option( Redirects::REGEX_OPTION ), 'source' ) );

		$this->assertSame( 3, $progress['redirects'] );
		$this->assertSame( 1, $progress['kept'] );
		$this->assertSame( 1, $progress['rejected'] );
	}

	/**
	 * Rank Math redirects come from its table: one row can have several sources, every comparison
	 * becomes a path or a regex, inactive rows become drafts, trashed rows and rows holding an
	 * object are skipped.
	 */
	public function test_rank_math_redirects(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$wpdb->query( $wpdb->prepare( "CREATE TABLE %i ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, sources text NOT NULL, url_to text NOT NULL, header_code smallint(4) unsigned NOT NULL, status varchar(25) NOT NULL DEFAULT 'active', PRIMARY KEY (id) )", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixture: the table Rank Math creates.
		$rows = array(
			array(
				array(
					array(
						'pattern'    => 'old',
						'comparison' => 'exact',
					),
					array(
						'pattern'    => 'shop/',
						'comparison' => 'start',
					),
				),
				'new/',
				301,
				'active',
			),
			array(
				array(
					array(
						'pattern'    => '^cat/(.*)',
						'comparison' => 'regex',
					),
				),
				'c/$1',
				302,
				'inactive',
			),
			array(
				array(
					array(
						'pattern'    => 'promo',
						'comparison' => 'contains',
					),
					array(
						'pattern'    => '.pdf',
						'comparison' => 'end',
					),
				),
				'',
				410,
				'active',
			),
			array(
				array(
					array(
						'pattern'    => 'trashed',
						'comparison' => 'exact',
					),
				),
				'x',
				301,
				'trashed',
			),
			array( new \ArrayObject( array( 'pattern' => 'object' ) ), 'x', 301, 'active' ),
		);
		foreach ( $rows as $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fixture rows.
				$table,
				array(
					'sources'     => serialize( $row[0] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Stored as Rank Math stores it.
					'url_to'      => $row[1],
					'header_code' => $row[2],
					'status'      => $row[3],
				)
			);
		}

		$progress = $this->import( 'rankmath' );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixture cleanup.

		$this->assertSame( '/new/', Redirects::rule( (int) Redirects::find_id( md5( '/old' ) ) )['target'] ?? null );
		$this->assertNull( Redirects::find_id( md5( '/trashed' ) ) );

		$regexes = get_posts(
			array(
				'post_type'   => Redirects::POST_TYPE,
				'post_status' => array( 'publish', 'draft' ),
				'meta_key'    => Redirects::meta_key( 'regex' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$this->assertSame( array( '^/shop/', '^/cat/(.*)', 'promo', '\.pdf/?$' ), wp_list_pluck( $regexes, 'post_title' ) );
		$this->assertSame( array( 'publish', 'draft', 'publish', 'publish' ), wp_list_pluck( $regexes, 'post_status' ) );
		$this->assertSame( 410, Redirects::rule( $regexes[3]->ID )['code'] ?? null );
		$this->assertSame( 5, $progress['redirects'] );
	}

	/**
	 * Each request handles one batch and saves where it stopped; the stages follow each other.
	 */
	public function test_batches_resume_where_they_stopped(): void {
		self::factory()->post->create_many( Import::BATCH + 1, array( 'meta_input' => array( '_yoast_wpseo_title' => 'Imported' ) ) );

		$first = Import::run( 'yoast', true );
		$this->assertSame( 'posts', $first['stage'] );
		$this->assertSame( Import::BATCH, $first['offset'] );
		$this->assertSame( $first, Import::progress( 'yoast' ) );

		$second = Import::run( 'yoast' );
		$this->assertSame( 'terms', $second['stage'] );
		$this->assertSame( Import::BATCH + 1, $second['posts'] );

		$this->assertSame( 'redirects', Import::run( 'yoast' )['stage'] );
		$this->assertSame( 'done', Import::run( 'yoast' )['stage'] );
		$this->assertSame( 'posts', Import::run( 'yoast' )['stage'], 'A finished import starts again.' );
	}

	/**
	 * Only administrators can run the import through REST.
	 */
	public function test_rest_route_needs_manage_options(): void {
		do_action( 'rest_api_init' );
		( new Import() )->register_routes();

		$request = new WP_REST_Request( 'POST', '/easyrankly/v1/import/yoast' );
		$request->set_param( 'restart', true );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( 'Importing terms…', $response->get_data()['message'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 400, rest_do_request( new WP_REST_Request( 'POST', '/easyrankly/v1/import/other' ) )->get_status() );
	}

	/**
	 * The screen offers only the plugins found on the site.
	 */
	public function test_screen_lists_detected_plugins(): void {
		$html = get_echo( array( new Page(), 'render' ) );
		$this->assertStringContainsString( 'No data from Yoast SEO or Rank Math was found', $html );

		update_option( 'rank_math_modules', array( 'redirections' ) );
		$html = get_echo( array( new Page(), 'render' ) );
		$this->assertStringContainsString( 'data-easyrankly-import="rankmath"', $html );
		$this->assertStringNotContainsString( 'data-easyrankly-import="yoast"', $html );
	}

	/**
	 * A Rank Math source holding an object is never unserialized, even behind whitespace,
	 * which maybe_unserialize() trims: the class of the object is never even looked up.
	 */
	public function test_rank_math_sources_never_build_objects(): void {
		$sources = new \ReflectionMethod( RankMath::class, 'sources' );
		$sources->setAccessible( true );
		$wanted   = array();
		$autoload = static function ( string $name ) use ( &$wanted ): void {
			$wanted[] = $name;
		};
		spl_autoload_register( $autoload );

		foreach ( array( '', ' ', "\t", "\n", "\r\n " ) as $prefix ) {
			$this->assertSame( array(), $sources->invoke( null, $prefix . 'a:1:{i:0;O:20:"EasyRankly_NotLoaded":0:{}}' ) );
			$this->assertSame( array(), $sources->invoke( null, $prefix . 'O:20:"EasyRankly_NotLoaded":0:{}' ) );
		}
		spl_autoload_unregister( $autoload );
		$this->assertSame( array(), $wanted );

		$this->assertSame(
			array(
				array(
					'source' => '/old',
					'regex'  => false,
				),
			),
			$sources->invoke( null, "\n" . serialize( array( array( 'pattern' => 'old' ) ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Stored as Rank Math stores it.
		);
	}
}
