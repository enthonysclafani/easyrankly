<?php
/**
 * Native core/breadcrumbs rendering cycle: attribute validation, filters,
 * confirmed output, and label normalisation.
 */

final class ERankly_Breadcrumbs_Native_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		erankly_load_content_helpers();
		require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
		erankly_tests_set_settings(
			array(
				'enable_breadcrumbs'     => 1,
				'breadcrumb_jsonld_mode' => 'when_visible',
				'simplified_mode'        => 0,
			)
		);
		erankly_reset_breadcrumb_runtime_state();
		remove_theme_support( 'erankly-breadcrumbs' );
	}

	private function require_core_breadcrumbs(): void {
		if ( ! function_exists( 'render_block_core_breadcrumbs' ) || ! WP_Block_Type_Registry::get_instance()->is_registered( 'core/breadcrumbs' ) ) {
			$this->markTestSkipped( 'core/breadcrumbs is not available in this WordPress test suite.' );
		}
	}

	private function child_page( string $content ): int {
		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Parent',
			)
		);
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Child',
				'post_parent'  => $parent,
				'post_content' => $content,
			)
		);

		$this->go_to( get_permalink( $id ) );

		return $id;
	}

	public function test_preview_validates_attributes_like_real_block_render(): void {
		$this->require_core_breadcrumbs();

		$content = '<!-- wp:breadcrumbs {"separator":[]} /-->';
		$this->child_page( $content );

		$html = do_blocks( $content );
		$this->assertStringContainsString( 'Child', $html );

		erankly_reset_breadcrumb_runtime_state();
		$schema = erankly_schema_breadcrumb_list();
		$this->assertNotEmpty( $schema );
		$this->assertSame( 'BreadcrumbList', $schema['@type'] );
	}

	public function test_preview_accepts_null_separator_and_unknown_attributes(): void {
		$this->require_core_breadcrumbs();

		$content = '<!-- wp:breadcrumbs {"separator":null,"notARealAttribute":true,"showHomeItem":false} /-->';
		$this->child_page( $content );

		erankly_reset_breadcrumb_runtime_state();
		$schema = erankly_schema_breadcrumb_list();
		$html   = do_blocks( $content );

		$this->assertStringContainsString( 'Child', $html );
		$this->assertStringNotContainsString( '>Home<', $html );
		$this->assertNotEmpty( $schema );
		$this->assertNotContains( 'Home', array_column( $schema['itemListElement'], 'name' ) );
	}

	public function test_preview_falls_back_when_boolean_attributes_have_the_wrong_type(): void {
		$this->require_core_breadcrumbs();

		$content = '<!-- wp:breadcrumbs {"showHomeItem":[]} /-->';
		$this->child_page( $content );

		erankly_reset_breadcrumb_runtime_state();
		$schema = erankly_schema_breadcrumb_list();
		$html   = do_blocks( $content );

		$this->assertStringContainsString( '>Home<', $html );
		$this->assertContains( 'Home', array_column( $schema['itemListElement'], 'name' ) );
	}

	public function test_preview_respects_render_block_data_filter(): void {
		$this->require_core_breadcrumbs();

		$content = '<!-- wp:breadcrumbs /-->';
		$this->child_page( $content );

		$filter = static function ( $block ) {
			if ( isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] ) {
				$block['attrs']['showHomeItem'] = false;
			}

			return $block;
		};

		add_filter( 'render_block_data', $filter );

		try {
			$head_schema = erankly_schema_breadcrumb_list();
			$html        = do_blocks( $content );
			$this->assertStringNotContainsString( '>Home<', $html );
			$this->assertNotContains( 'Home', array_column( $head_schema['itemListElement'], 'name' ) );
		} finally {
			remove_filter( 'render_block_data', $filter );
		}
	}

	public function test_preview_respects_pre_render_block_suppression(): void {
		$this->require_core_breadcrumbs();

		$content = '<!-- wp:breadcrumbs /-->';
		$this->child_page( $content );

		$filter = static function ( $pre, $block ) {
			if ( isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] ) {
				return '';
			}

			return $pre;
		};

		add_filter( 'pre_render_block', $filter, 10, 2 );

		try {
			$head_schema = erankly_schema_breadcrumb_list();
			$html        = do_blocks( $content );
			$this->assertSame( '', $html );
			$this->assertSame( array(), $head_schema );
			$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );
		} finally {
			remove_filter( 'pre_render_block', $filter, 10 );
		}
	}

	public function test_preview_respects_render_block_context(): void {
		$this->require_core_breadcrumbs();

		$other = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Other page',
			)
		);
		$this->child_page( '<!-- wp:breadcrumbs /-->' );

		$filter = static function ( $context, $block ) use ( $other ) {
			if ( isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] ) {
				$context['postId']   = $other;
				$context['postType'] = 'page';
			}

			return $context;
		};

		add_filter( 'render_block_context', $filter, 10, 2 );

		try {
			$head_schema = erankly_schema_breadcrumb_list();
			$html        = do_blocks( '<!-- wp:breadcrumbs /-->' );
			$this->assertStringContainsString( 'Other page', $html );
			$this->assertContains( 'Other page', array_column( $head_schema['itemListElement'], 'name' ) );
			$this->assertNotContains( 'Child', array_column( $head_schema['itemListElement'], 'name' ) );
		} finally {
			remove_filter( 'render_block_context', $filter, 10 );
		}
	}

	public function test_preview_follows_a_nested_core_breadcrumbs_block(): void {
		$this->require_core_breadcrumbs();

		$content = "<!-- wp:group --><!-- wp:breadcrumbs {\"showHomeItem\":false} /--><!-- /wp:group -->";
		$this->child_page( $content );

		$head_schema = erankly_schema_breadcrumb_list();
		$html        = do_blocks( $content );

		$this->assertStringNotContainsString( '>Home<', $html );
		$this->assertNotContains( 'Home', array_column( $head_schema['itemListElement'], 'name' ) );
		$this->assertContains( 'Child', array_column( $head_schema['itemListElement'], 'name' ) );
	}

	public function test_suppressed_second_block_does_not_replace_visible_first_trail(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$first       = do_blocks( '<!-- wp:breadcrumbs /-->' );
		$first_items = erankly_captured_core_breadcrumb_items();

		$filter = static function ( $html, $block ) {
			return isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] && ! empty( $block['attrs']['className'] ) ? '' : $html;
		};

		add_filter( 'render_block_core/breadcrumbs', $filter, 20, 2 );

		try {
			$second = do_blocks( '<!-- wp:breadcrumbs {"showHomeItem":false,"className":"suppressed"} /-->' );
			$this->assertSame( '', $second );
			$this->assertStringContainsString( '>Home<', $first );
			$this->assertTrue( erankly_has_visible_breadcrumbs() );
			$this->assertSame( $first_items, erankly_get_schema_breadcrumb_items() );
		} finally {
			remove_filter( 'render_block_core/breadcrumbs', $filter, 20 );
		}
	}

	public function test_first_suppressed_block_does_not_block_a_later_visible_trail(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$filter = static function ( $html, $block ) {
			return isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] && ! empty( $block['attrs']['className'] ) ? '' : $html;
		};

		add_filter( 'render_block_core/breadcrumbs', $filter, 20, 2 );

		try {
			$first = do_blocks( '<!-- wp:breadcrumbs {"className":"suppressed"} /-->' );
			$this->assertSame( '', $first );
			$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );

			$second = do_blocks( '<!-- wp:breadcrumbs {"showHomeItem":false} /-->' );
			$this->assertStringContainsString( 'Child', $second );
			$this->assertStringNotContainsString( '>Home<', $second );
			$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );
			$this->assertNotContains( 'Home', array_column( erankly_get_schema_breadcrumb_items(), 'name' ) );
		} finally {
			remove_filter( 'render_block_core/breadcrumbs', $filter, 20 );
		}
	}

	public function test_only_suppressed_block_does_not_leave_a_visibility_flag(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$filter = static function () {
			return '';
		};

		add_filter( 'render_block_core/breadcrumbs', $filter, 20 );

		try {
			$html = do_blocks( '<!-- wp:breadcrumbs /-->' );
			$this->assertSame( '', $html );
			$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );
			$this->assertNull( erankly_captured_core_breadcrumb_items() );
		} finally {
			remove_filter( 'render_block_core/breadcrumbs', $filter, 20 );
		}
	}

	public function test_generic_render_block_filter_can_suppress_the_trail(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$filter = static function ( $html, $block ) {
			return isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] ? '' : $html;
		};

		add_filter( 'render_block', $filter, 20, 2 );

		try {
			$html = do_blocks( '<!-- wp:breadcrumbs /-->' );
			$this->assertSame( '', $html );
			$this->assertFalse( erankly_breadcrumb_trail_was_rendered() );
			$this->assertNull( erankly_captured_core_breadcrumb_items() );
		} finally {
			remove_filter( 'render_block', $filter, 20 );
		}
	}

	public function test_visible_core_block_does_not_replace_an_earlier_shortcode_trail(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$shortcode = erankly_breadcrumbs_shortcode();
		$this->assertNotSame( '', $shortcode );
		$easy_items = erankly_get_breadcrumb_items();

		$html = do_blocks( '<!-- wp:breadcrumbs {"showHomeItem":false} /-->' );
		$this->assertStringContainsString( 'Child', $html );
		$this->assertNull( erankly_captured_core_breadcrumb_items() );
		$this->assertSame( $easy_items, erankly_get_schema_breadcrumb_items() );
	}

	public function test_already_rendered_shortcode_wins_over_core_preview_from_content(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '<!-- wp:breadcrumbs {"showHomeItem":false} /-->' );
		erankly_breadcrumbs_shortcode();
		$easy   = erankly_get_breadcrumb_items();
		$schema = erankly_schema_breadcrumb_list();

		$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );
		$this->assertNull( erankly_captured_core_breadcrumb_items() );
		$this->assertNotEmpty( $schema );
		$this->assertSame( wp_list_pluck( $easy, 'name' ), wp_list_pluck( $schema['itemListElement'], 'name' ) );
		$this->assertContains( 'Home', wp_list_pluck( $schema['itemListElement'], 'name' ) );
	}

	public function test_legacy_shortcode_trail_is_not_replaced_by_a_suppressed_core_block(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$shortcode = erankly_breadcrumbs_shortcode();
		$this->assertNotSame( '', $shortcode );
		$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );
		$easy_items = erankly_get_breadcrumb_items();

		$filter = static function () {
			return '';
		};
		add_filter( 'render_block_core/breadcrumbs', $filter, 20 );

		try {
			$core = do_blocks( '<!-- wp:breadcrumbs {"showHomeItem":false} /-->' );
			$this->assertSame( '', $core );
			$this->assertTrue( erankly_breadcrumb_trail_was_rendered() );
			$this->assertNull( erankly_captured_core_breadcrumb_items() );
			$this->assertSame( $easy_items, erankly_get_schema_breadcrumb_items() );
		} finally {
			remove_filter( 'render_block_core/breadcrumbs', $filter, 20 );
		}
	}

	public function test_normalization_preserves_visible_text_of_html_labels(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$filter = static function ( $items ) {
			$items[0]['label']      = 'Research &amp; development';
			$items[0]['allow_html'] = true;

			return $items;
		};

		add_filter( 'block_core_breadcrumbs_items', $filter, 20 );

		try {
			$html   = do_blocks( '<!-- wp:breadcrumbs /-->' );
			$schema = erankly_schema_breadcrumb_list();
			$this->assertStringContainsString( 'Research &amp; development', $html );
			$this->assertSame( 'Research & development', $schema['itemListElement'][0]['name'] );
		} finally {
			remove_filter( 'block_core_breadcrumbs_items', $filter, 20 );
		}
	}

	public function test_normalization_covers_entities_and_literal_angle_brackets(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$cases = array(
			array(
				'label'      => 'Research &#38; development',
				'allow_html' => true,
				'name'       => 'Research & development',
			),
			array(
				'label'      => 'Already &amp;amp; literal',
				'allow_html' => true,
				'name'       => 'Already &amp; literal',
			),
			array(
				'label'      => '<em>Markup</em> allowed',
				'allow_html' => true,
				'name'       => 'Markup allowed',
			),
			array(
				'label'      => '<code>',
				'allow_html' => false,
				'name'       => '<code>',
			),
			array(
				'label'      => 'A & B',
				'allow_html' => false,
				'name'       => 'A & B',
			),
		);

		foreach ( $cases as $index => $case ) {
			erankly_reset_breadcrumb_runtime_state();

			$filter = static function ( $items ) use ( $case ) {
				$items[0]['label']      = $case['label'];
				$items[0]['allow_html'] = $case['allow_html'];

				return $items;
			};

			add_filter( 'block_core_breadcrumbs_items', $filter, 20 );

			try {
				$html    = do_blocks( '<!-- wp:breadcrumbs /-->' );
				$schema  = erankly_schema_breadcrumb_list();
				$visible = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$this->assertStringContainsString( $case['name'], $visible, 'Visible case ' . $index );
				$this->assertSame( $case['name'], $schema['itemListElement'][0]['name'], 'Name case ' . $index );
				$this->assertSame( substr_count( $html, '<li>' ), count( $schema['itemListElement'] ) );
			} finally {
				remove_filter( 'block_core_breadcrumbs_items', $filter, 20 );
			}
		}
	}

	public function test_rejected_javascript_urls_are_not_copied_into_schema(): void {
		$this->require_core_breadcrumbs();

		$this->child_page( '' );

		$filter = static function ( $items ) {
			$items[0]['url'] = 'javascript:alert(1)';

			return $items;
		};

		add_filter( 'block_core_breadcrumbs_items', $filter, 20 );

		try {
			$html   = do_blocks( '<!-- wp:breadcrumbs /-->' );
			$schema = erankly_schema_breadcrumb_list();
			$this->assertStringNotContainsString( 'javascript:', $html );
			$this->assertSame( erankly_get_canonical(), $schema['itemListElement'][0]['item'] );
		} finally {
			remove_filter( 'block_core_breadcrumbs_items', $filter, 20 );
		}
	}

	public function test_schema_graph_contains_a_single_breadcrumb_list_for_two_visible_trails(): void {
		$this->require_core_breadcrumbs();

		require_once ERANKLY_PATH . 'includes/canonical.php';
		require_once ERANKLY_PATH . 'includes/opengraph.php';
		require_once ERANKLY_PATH . 'includes/schema-jsonld.php';
		require_once ERANKLY_PATH . 'includes/schema.php';

		$this->child_page( '' );

		do_blocks( '<!-- wp:breadcrumbs /-->' );
		$first = erankly_captured_core_breadcrumb_items();
		do_blocks( '<!-- wp:breadcrumbs {"showHomeItem":false} /-->' );

		$this->assertSame( $first, erankly_get_schema_breadcrumb_items() );

		$graph = erankly_get_schema_graph();
		$lists = array();

		foreach ( $graph as $node ) {
			if ( isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'] ) {
				$lists[] = $node;
			}
		}

		$this->assertCount( 1, $lists );
		$this->assertSame( wp_list_pluck( $first, 'name' ), wp_list_pluck( $lists[0]['itemListElement'], 'name' ) );
	}
}
