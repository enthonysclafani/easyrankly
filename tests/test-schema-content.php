<?php
/** Schema.org content detection: Event/Video extraction from post content and supported plugins. */

final class ERankly_Schema_Content_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		// schema.php pulls in schema-content.php and breadcrumbs.php.
		require_once ERANKLY_PATH . 'includes/schema.php';
	}

	/** @return array<string,array{string}> */
	public function legacy_howto_content(): array {
		return array(
			'Yoast block'     => array( '<!-- wp:yoast/how-to-block {"steps":[{"name":"Mix","text":"Stir the batter."}]} /-->' ),
			'Rank Math block' => array( '<!-- wp:rank-math/howto-block {"steps":[{"name":"Mix","text":"Stir the batter."}]} /-->' ),
			'Rank Math alias' => array( '<!-- wp:rank-math/howto {"steps":[{"name":"Mix","text":"Stir the batter."}]} /-->' ),
			'Legacy HTML'     => array( '<strong class="schema-how-to-step-name">Mix</strong><p class="schema-how-to-step-text">Stir the batter.</p>' ),
		);
	}

	/** @param array<string,mixed> $args Post args overrides. */
	private function make_post( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Contenuto',
					'post_content' => '',
					'post_excerpt' => '',
				),
				$args
			)
		);

		$this->assertIsInt( $post_id );

		return (int) $post_id;
	}

	/** @dataProvider legacy_howto_content */
	public function test_legacy_howto_content_keeps_page_schema_without_howto( string $content ): void {
		erankly_load_default_helpers();
		erankly_tests_set_settings( erankly_default_settings() );
		$post_id = $this->make_post( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $post_id ) );

		$types = array();
		foreach ( erankly_get_schema_graph() as $node ) {
			$types = array_merge( $types, (array) ( $node['@type'] ?? array() ) );
		}

		$this->assertContains( 'WebPage', $types );
		$this->assertNotContains( 'HowTo', $types );
	}

	public function test_event_is_virtual_reads_flags(): void {
		$post_id = $this->make_post();
		$this->assertFalse( erankly_schema_event_is_virtual( $post_id ) );

		update_post_meta( $post_id, '_EventVirtual', '1' );
		$this->assertTrue( erankly_schema_event_is_virtual( $post_id ) );

		// "no" and "0" explicitly mean the event is not virtual.
		update_post_meta( $post_id, '_EventVirtual', 'no' );
		$this->assertFalse( erankly_schema_event_is_virtual( $post_id ) );
	}

	public function test_event_location_from_tec_builds_place_with_address(): void {
		$venue_id = $this->make_post( array( 'post_title' => 'Teatro Verdi' ) );
		update_post_meta( $venue_id, '_VenueAddress', 'Via Roma 1' );
		update_post_meta( $venue_id, '_VenueCity', 'Milano' );
		update_post_meta( $venue_id, '_VenueCountry', 'IT' );

		$event_id = $this->make_post();
		update_post_meta( $event_id, '_EventVenueID', $venue_id );

		$location = erankly_schema_event_location_from_tec( $event_id );

		$this->assertSame( 'Place', $location['@type'] );
		$this->assertSame( 'Teatro Verdi', $location['name'] );
		$this->assertSame( 'PostalAddress', $location['address']['@type'] );
		$this->assertSame( 'Via Roma 1', $location['address']['streetAddress'] );
		$this->assertSame( 'Milano', $location['address']['addressLocality'] );

		// Without a venue ID there is no location.
		$this->assertSame( array(), erankly_schema_event_location_from_tec( $this->make_post() ) );
	}

	public function test_schema_event_from_tec_uses_start_and_venue_meta(): void {
		$venue_id = $this->make_post( array( 'post_title' => 'Sala Blu' ) );
		$post_id  = $this->make_post( array( 'post_title' => 'Conferenza' ) );
		update_post_meta( $post_id, '_EventStartDate', '2026-10-01 09:00:00' );
		update_post_meta( $post_id, '_EventVenueID', $venue_id );

		$schema = erankly_schema_event_from_tec( $post_id );

		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( 'Sala Blu', $schema['location']['name'] );
		$this->assertNotSame( '', $schema['startDate'] );

		// A missing/invalid start date yields nothing.
		$this->assertSame( array(), erankly_schema_event_from_tec( $this->make_post() ) );
	}

	public function test_schema_event_is_only_built_for_the_events_calendar(): void {
		$plain = $this->make_post( array( 'post_content' => 'x' ) );
		$this->assertSame( array(), erankly_schema_event( $plain ) );

		// Event-like meta on another post type is not guessed into an Event node.
		$page = $this->make_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Open day',
			)
		);
		update_post_meta( $page, '_EventStartDate', '2026-11-15 10:00:00' );
		update_post_meta( $page, '_EventVenue', 'Campus' );

		$this->assertSame( array(), erankly_schema_event( $page ) );
	}

	public function test_schema_video_objects_builds_videoobject_from_an_embedded_url(): void {
		$post_id = $this->make_post(
			array(
				'post_title'   => 'Tutorial video',
				'post_excerpt' => 'Guarda il video.',
				'post_content' => '<p>Guarda: https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>',
			)
		);

		$objects = erankly_schema_video_objects( $post_id );

		$this->assertCount( 1, $objects );
		$this->assertSame( 'VideoObject', $objects[0]['@type'] );
		$this->assertSame( 'Tutorial video', $objects[0]['name'] );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $objects[0]['embedUrl'] );
		$this->assertStringContainsString( '#video-', $objects[0]['@id'] );
		$this->assertNotSame( '', $objects[0]['uploadDate'] );
	}

	public function test_schema_video_objects_returns_empty_without_a_video_marker(): void {
		$post_id = $this->make_post( array( 'post_content' => '<p>Solo testo.</p>' ) );

		$this->assertSame( array(), erankly_schema_video_objects( $post_id ) );
	}

	public function test_schema_service_for_page_requires_filter_args(): void {
		$post_id = $this->make_post();

		$this->assertSame( array(), erankly_schema_service_for_page( $post_id ) );

		$filter = static function (): array {
			return array( 'name' => 'Consulenza' );
		};
		add_filter( 'erankly_schema_service_args', $filter );

		try {
			$schema = erankly_schema_service_for_page( $post_id );

			$this->assertSame( 'Service', $schema['@type'] );
			$this->assertSame( 'Consulenza', $schema['name'] );
			$this->assertArrayHasKey( '@id', $schema['provider'] );
		} finally {
			remove_filter( 'erankly_schema_service_args', $filter );
		}
	}

	public function test_find_blocks_by_names_recurses_into_inner_blocks(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'innerBlocks' => array(
					array( 'blockName' => 'core/paragraph', 'attrs' => array() ),
					array( 'blockName' => 'yoast/faq-block', 'attrs' => array( 'questions' => array() ) ),
				),
			),
			array( 'blockName' => 'rank-math/faq-block', 'attrs' => array() ),
		);

		$found = erankly_find_blocks_by_names( $blocks, array( 'yoast/faq-block', 'rank-math/faq-block' ) );

		$this->assertCount( 2, $found );
		$this->assertSame( 'yoast/faq-block', $found[0]['blockName'] );
		$this->assertSame( 'rank-math/faq-block', $found[1]['blockName'] );
	}
}
