<?php
/** Schema.org content detection: FAQ/HowTo/Event/Video extraction from post content and supported plugins. */

final class ERankly_Schema_Content_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		// schema.php pulls in schema-content.php and breadcrumbs.php.
		require_once ERANKLY_PATH . 'includes/schema.php';
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

	public function test_faq_items_from_content_reads_gutenberg_faq_blocks(): void {
		$content = '<!-- wp:yoast/faq-block {"questions":[{"question":"Quanto costa?","answer":"Dieci euro."}]} /-->'
			. "\n"
			. '<!-- wp:rank-math/faq-block {"faqs":[{"question":"Serve un account?","answer":"No, è libero."}]} /-->';
		$post_id = $this->make_post( array( 'post_content' => $content ) );

		$items = erankly_faq_items_from_content( array(), $post_id );

		$this->assertCount( 2, $items );
		// The block extraction also works stand-alone.
		$this->assertCount( 2, erankly_extract_faq_items_from_blocks( $content ) );
		$this->assertSame( 'Quanto costa?', $items[0]['question'] );
		$this->assertSame( 'Dieci euro.', $items[0]['answer'] );
		$this->assertSame( 'Serve un account?', $items[1]['question'] );
	}

	public function test_faq_items_from_content_returns_existing_items_untouched(): void {
		$existing = array(
			array(
				'question' => 'Già presente',
				'answer'   => 'Mantieni',
			),
		);

		// A non-empty list short-circuits before any post lookup, even with a bogus ID.
		$this->assertSame( $existing, erankly_faq_items_from_content( $existing, 0 ) );
	}

	public function test_extract_faq_items_from_content_falls_back_to_html(): void {
		$html = '<strong class="schema-faq-question">Come funziona?</strong><p class="schema-faq-answer">Premi il pulsante.</p>';

		$items = erankly_extract_faq_items_from_content( $html );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Come funziona?', $items[0]['question'] );
		$this->assertSame( 'Premi il pulsante.', $items[0]['answer'] );
	}

	public function test_extract_faq_items_from_accordion_blocks_requires_opt_in_flag(): void {
		$content = '<!-- wp:accordion -->
<!-- wp:accordion-item -->
<!-- wp:accordion-heading {"title":"Dove siete?"} /-->
<!-- wp:accordion-panel -->
<!-- wp:paragraph {"content":"In centro."} /-->
<!-- /wp:accordion-panel -->
<!-- /wp:accordion-item -->
<!-- /wp:accordion -->';

		// Without eranklyGenerateFaqSchema the accordion is not treated as an FAQ.
		$this->assertSame( array(), erankly_extract_faq_items_from_accordion_blocks( $content ) );

		$content = '<!-- wp:accordion {"eranklyGenerateFaqSchema":true} -->
<!-- wp:accordion-item -->
<!-- wp:accordion-heading {"title":"Dove siete?"} /-->
<!-- wp:accordion-panel -->
<!-- wp:paragraph {"content":"In centro."} /-->
<!-- /wp:accordion-panel -->
<!-- /wp:accordion-item -->
<!-- /wp:accordion -->';

		$items = erankly_extract_faq_items_from_accordion_blocks( $content );

		$this->assertCount( 1, $items );
		$this->assertSame( 'Dove siete?', $items[0]['question'] );
		$this->assertSame( 'In centro.', $items[0]['answer'] );
	}

	public function test_extract_accordion_item_question_prefers_the_heading_title_attr(): void {
		$item = array(
			'innerBlocks' => array(
				array(
					'blockName' => 'core/accordion-heading',
					'attrs'     => array( 'title' => '<em>Domanda</em> in HTML' ),
				),
			),
		);

		$this->assertSame( 'Domanda in HTML', erankly_extract_accordion_item_question( $item ) );
	}

	public function test_extract_accordion_item_question_reads_the_toggle_title_from_inner_html(): void {
		$item = array(
			'innerBlocks' => array(
				array(
					'blockName' => 'core/accordion-heading',
					'attrs'     => array(),
					'innerHTML' => '<h3 class="wp-block-accordion-heading"><button><span class="wp-block-accordion-heading__toggle-title">Senza attr</span></button></h3>',
				),
			),
		);

		$this->assertSame( 'Senza attr', erankly_extract_accordion_item_question( $item ) );
		// No heading block at all yields an empty question.
		$this->assertSame( '', erankly_extract_accordion_item_question( array() ) );
	}

	public function test_extract_accordion_item_answer_reads_the_panel_blocks(): void {
		$item = array(
			'innerBlocks' => array(
				array(
					'blockName' => 'core/accordion-panel',
					'attrs'     => array(),
					'innerBlocks' => array(
						array(
							'blockName' => 'core/paragraph',
							'attrs'     => array( 'content' => 'Prima parte' ),
						),
						array(
							'blockName' => 'core/paragraph',
							'attrs'     => array( 'content' => 'Seconda parte' ),
						),
					),
				),
			),
		);

		$this->assertSame( 'Prima parte Seconda parte', erankly_extract_accordion_item_answer( $item ) );
		$this->assertSame( '', erankly_extract_accordion_item_answer( array() ) );
	}

	public function test_plain_text_from_inner_blocks_prefers_attrs_and_recurses(): void {
		$blocks = array(
			array(
				'blockName' => 'core/heading',
				'attrs'     => array( 'content' => '<b>Titolo</b>' ),
			),
			array(
				'blockName' => 'core/columns',
				'innerBlocks' => array(
					array(
						'blockName' => 'core/paragraph',
						'attrs'     => array(),
						'innerHTML' => '<p>Solo innerHTML.</p>',
					),
				),
			),
			'not-a-block',
		);

		$this->assertSame( 'Titolo Solo innerHTML.', erankly_schema_plain_text_from_inner_blocks( $blocks ) );
	}

	public function test_extract_faq_items_from_html_parses_pairs(): void {
		$html = '<strong class="schema-faq-question">Domanda uno</strong>'
			. '<p class="schema-faq-answer">Risposta <em>uno</em>.</p>'
			. '<strong class="schema-faq-question">Domanda due</strong>'
			. '<p class="schema-faq-answer">Risposta due.</p>';

		$items = erankly_extract_faq_items_from_html( $html );

		$this->assertCount( 2, $items );
		$this->assertSame( 'Risposta uno.', $items[0]['answer'] );
		$this->assertSame( 'Domanda due', $items[1]['question'] );
	}

	public function test_schema_howto_from_blocks_builds_steps_and_total_time(): void {
		$content = '<!-- wp:yoast/how-to-block {"description":"Guida rapida","hasDuration":true,"hours":1,"minutes":30,"steps":[{"name":"Prepara","text":"Raccogli gli ingredienti."},{"name":"Cuoci","text":"In forno per 20 minuti."}]} /-->';
		$post_id = $this->make_post(
			array(
				'post_content' => $content,
				'post_title'   => 'Ricetta',
			)
		);

		$schema = erankly_schema_howto_from_blocks( $content, $post_id );

		$this->assertSame( 'HowTo', $schema['@type'] );
		$this->assertSame( 'Ricetta', $schema['name'] );
		$this->assertSame( 'Guida rapida', $schema['description'] );
		$this->assertSame( 'PT1H30M', $schema['totalTime'] );
		$this->assertCount( 2, $schema['step'] );
		$this->assertSame( 1, $schema['step'][0]['position'] );
		$this->assertSame( 'Prepara', $schema['step'][0]['name'] );
		$this->assertSame( 'Cuoci', $schema['step'][1]['name'] );
	}

	public function test_schema_howto_reads_the_post_content(): void {
		$content = '<!-- wp:yoast/how-to-block {"steps":[{"name":"Unico","text":"Fai così."}]} /-->';
		$post_id = $this->make_post(
			array(
				'post_content' => $content,
				'post_title'   => 'Procedura',
			)
		);

		$schema = erankly_schema_howto( $post_id );

		$this->assertSame( 'HowTo', $schema['@type'] );
		$this->assertSame( 'Unico', $schema['step'][0]['name'] );

		// A missing post cannot produce a HowTo.
		$this->assertSame( array(), erankly_schema_howto( 0 ) );
	}

	public function test_schema_howto_total_time_formats_and_guards(): void {
		$this->assertSame( '', erankly_schema_howto_total_time( array() ) );
		$this->assertSame( '', erankly_schema_howto_total_time( array( 'hasDuration' => true ) ) );
		$this->assertSame( '', erankly_schema_howto_total_time( array( 'hours' => 2 ) ) );
		$this->assertSame(
			'P1DT2H3M',
			erankly_schema_howto_total_time(
				array(
					'hasDuration' => true,
					'days'        => 1,
					'hours'       => 2,
					'minutes'     => 3,
				)
			)
		);
		// Negative values are clamped to zero, not printed.
		$this->assertSame(
			'PT0H5M',
			erankly_schema_howto_total_time(
				array(
					'hasDuration' => true,
					'days'        => -3,
					'minutes'     => 5,
				)
			)
		);
	}

	public function test_event_post_type_helpers_apply_the_filter(): void {
		$types = erankly_get_event_post_types();

		$this->assertContains( 'tribe_events', $types );
		$this->assertContains( 'event', $types );
		$this->assertTrue( erankly_is_event_post_type( 'event' ) );
		$this->assertFalse( erankly_is_event_post_type( 'post' ) );

		$filter = static function ( array $list ): array {
			$list[] = 'webinar';
			return $list;
		};

		add_filter( 'erankly_event_post_types', $filter );

		try {
			$this->assertTrue( erankly_is_event_post_type( 'webinar' ) );
			$this->assertContains( 'webinar', erankly_get_event_post_types() );
		} finally {
			remove_filter( 'erankly_event_post_types', $filter );
		}
	}

	public function test_event_meta_value_returns_first_non_empty_trimmed(): void {
		$post_id = $this->make_post();
		update_post_meta( $post_id, 'second_key', '  Secondo valore  ' );

		$this->assertSame(
			'Secondo valore',
			erankly_schema_event_meta_value( $post_id, array( 'missing_key', 'second_key' ) )
		);
		$this->assertSame( '', erankly_schema_event_meta_value( $post_id, array( 'missing_key' ) ) );
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

	public function test_event_location_generic_uses_venue_meta(): void {
		$post_id = $this->make_post();
		$this->assertSame( array(), erankly_schema_event_location_generic( $post_id ) );

		update_post_meta( $post_id, 'venue', 'Palazzo dei Congressi' );

		$location = erankly_schema_event_location_generic( $post_id );

		$this->assertSame( 'Place', $location['@type'] );
		$this->assertSame( 'Palazzo dei Congressi', $location['name'] );
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

	public function test_schema_event_generic_builds_a_usable_event(): void {
		$post_id = $this->make_post( array( 'post_title' => 'Concerto' ) );
		update_post_meta( $post_id, '_EventStartDate', '2026-09-06 20:30:00' );
		update_post_meta( $post_id, '_EventEndDate', '2026-09-06 23:00:00' );
		update_post_meta( $post_id, 'location', 'Arena' );

		$schema = erankly_schema_event_generic( $post_id, 'event' );

		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( get_permalink( $post_id ) . '#event', $schema['@id'] );
		$this->assertNotSame( '', $schema['startDate'] );
		$this->assertSame( 'Arena', $schema['location']['name'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $schema['eventStatus'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $schema['eventAttendanceMode'] );
		$this->assertArrayHasKey( '@id', $schema['organizer'] );
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

	public function test_schema_event_rejects_non_event_post_types_and_builds_for_targeted_ones(): void {
		$plain = $this->make_post( array( 'post_content' => 'x' ) );
		$this->assertSame( array(), erankly_schema_event( $plain ) );

		// Opt a page into the event list; the generic builder then applies.
		$filter = static function ( array $list ): array {
			$list[] = 'page';
			return $list;
		};
		add_filter( 'erankly_event_post_types', $filter );

		try {
			$event_page = $this->make_post(
				array(
					'post_type'  => 'page',
					'post_title' => 'Open day',
				)
			);
			update_post_meta( $event_page, '_EventStartDate', '2026-11-15 10:00:00' );
			update_post_meta( $event_page, '_EventVenue', 'Campus' );

			$schema = erankly_schema_event( $event_page );

			$this->assertSame( 'Event', $schema['@type'] );
			$this->assertSame( 'Campus', $schema['location']['name'] );
		} finally {
			remove_filter( 'erankly_event_post_types', $filter );
		}
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

	public function test_schema_plain_text_from_field_strips_html_and_skips_empties(): void {
		$row = array(
			'title'    => '',
			'question' => '<strong>Domanda</strong> &amp; altro',
		);

		$this->assertSame( 'Domanda & altro', erankly_schema_plain_text_from_field( $row, array( 'title', 'question' ) ) );
		$this->assertSame( '', erankly_schema_plain_text_from_field( array( 'name' => 5 ), array( 'name' ) ) );
	}
}
