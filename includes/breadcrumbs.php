<?php
/** Breadcrumbs. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_breadcrumbs( array $args = array() ): string {
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		return '';
	}

	$args = wp_parse_args(
		$args,
		array(
			'echo'          => true,
			'mark_rendered' => null,
		)
	);

	$items = erankly_get_breadcrumb_items();

	if ( count( $items ) < 2 ) {
		return '';
	}

	$html  = '<nav class="erankly-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumbs', 'easyrankly' ) . '">';
	$html .= '<ol>';

	$last_index = count( $items ) - 1;

	foreach ( $items as $index => $item ) {
		$name = isset( $item['name'] ) ? (string) $item['name'] : '';
		$url  = isset( $item['url'] ) ? (string) $item['url'] : '';

		if ( '' === $name ) {
			continue;
		}

		$html .= '<li>';

		if ( $index < $last_index && '' !== $url ) {
			$html .= '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
		} else {
			$html .= '<span aria-current="page">' . esc_html( $name ) . '</span>';
		}

		$html .= '</li>';
	}

	$html .= '</ol>';
	$html .= '</nav>';

	$html = (string) apply_filters( 'erankly_breadcrumbs_html', $html, $items );

	$allowed_html        = wp_kses_allowed_html( 'post' );
	$allowed_html['nav'] = array(
		'aria-label' => true,
		'class'      => true,
		'id'         => true,
	);

	foreach ( array( 'a', 'li', 'ol', 'span' ) as $tag ) {
		$allowed_html[ $tag ]                 = isset( $allowed_html[ $tag ] ) ? $allowed_html[ $tag ] : array();
		$allowed_html[ $tag ]['aria-current'] = true;
		$allowed_html[ $tag ]['class']        = true;
		$allowed_html[ $tag ]['id']           = true;
	}

	$html = wp_kses( $html, $allowed_html );

	$echo          = (bool) $args['echo'];
	$mark_rendered = null === $args['mark_rendered'] ? $echo : (bool) $args['mark_rendered'];

	if ( '' !== $html && $mark_rendered ) {
		erankly_breadcrumb_trail_was_rendered( true );
	}

	if ( $echo ) {
		echo wp_kses( $html, $allowed_html );
	}

	return $html;
}

if ( ! function_exists( 'easyrankly_breadcrumbs' ) ) {
	// Legacy public function kept for backward compatibility.
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	/** Legacy alias for the public breadcrumbs template function. */
	function easyrankly_breadcrumbs( array $args = array() ): string {
		return erankly_breadcrumbs( $args );
	}
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
}

function erankly_get_post_breadcrumb_name( int $post_id ): string {
	$name = erankly_get_post_meta_string( $post_id, 'breadcrumb_name' );

	if ( '' === $name && (bool) erankly_get_setting( 'simplified_mode', 1 ) ) {
		$name = erankly_get_post_meta_string( $post_id, 'title' );

		if ( '' !== $name ) {
			$name = erankly_replace_variables( $name, $post_id, array( 'seo_title' ) );
		}
	}

	$name = '' !== $name ? erankly_normalize_seo_text( $name ) : get_the_title( $post_id );

	return (string) apply_filters( 'erankly_post_breadcrumb_name', $name, $post_id );
}

/** @return array<int,array<string,string>> */
function erankly_get_breadcrumb_items(): array {
	static $resolved = null;
	static $token    = -1;

	$current_token = erankly_breadcrumb_items_resolution_token();

	if ( $token !== $current_token ) {
		$resolved = null;
		$token    = $current_token;
	}

	if ( null !== $resolved ) {
		return $resolved;
	}

	$items = array(
		array(
			'name' => __( 'Home', 'easyrankly' ),
			'url'  => home_url( '/' ),
		),
	);

	if ( is_singular() ) {
		$post_id = get_queried_object_id();

		/*
		 * A static front page is the same resource as the leading Home crumb
		 * (identity via show_on_front/page_on_front, not the visible label).
		 * Re-adding it produces a duplicate Home→Home BreadcrumbList.
		 * With only the Home crumb left, the existing < 2 convention omits output.
		 */
		$is_static_front = is_front_page()
			|| (
				'page' === (string) get_option( 'show_on_front' )
				&& $post_id > 0
				&& (int) get_option( 'page_on_front' ) === $post_id
			);

		if ( ! $is_static_front ) {
			$type = get_post_type( $post_id );

			if ( 'post' === $type ) {
				$categories = get_the_category( $post_id );
				$primary    = erankly_get_primary_term( $post_id, 'category' );

				if ( $primary instanceof WP_Term || ! empty( $categories[0] ) ) {
					$category = $primary instanceof WP_Term ? $primary : $categories[0];
					$parents  = array_reverse( get_ancestors( $category->term_id, 'category', 'taxonomy' ) );

					foreach ( $parents as $parent_id ) {
						$parent = get_term( $parent_id, 'category' );

						if ( $parent instanceof WP_Term ) {
							$parent_link = get_term_link( $parent );

							$items[] = array(
								'name' => $parent->name,
								'url'  => is_wp_error( $parent_link ) ? '' : $parent_link,
							);
						}
					}

					$category_link = get_term_link( $category );

					$items[] = array(
						'name' => $category->name,
						'url'  => is_wp_error( $category_link ) ? '' : $category_link,
					);
				}
			} elseif ( 'page' !== $type ) {
				$archive = get_post_type_archive_link( (string) $type );
				$object  = get_post_type_object( (string) $type );

				if ( is_string( $archive ) && $object instanceof WP_Post_Type ) {
					$items[] = array(
						'name' => $object->labels->name,
						'url'  => $archive,
					);
				}
			}

			$ancestors = array_reverse( get_post_ancestors( $post_id ) );

			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array(
					'name' => erankly_get_post_breadcrumb_name( $ancestor_id ),
					'url'  => get_permalink( $ancestor_id ),
				);
			}

			$items[] = array(
				'name' => erankly_get_post_breadcrumb_name( $post_id ),
				'url'  => '',
			);
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );

			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $term->taxonomy );

				if ( $ancestor instanceof WP_Term ) {
					$ancestor_link = get_term_link( $ancestor );

					$items[] = array(
						'name' => $ancestor->name,
						'url'  => is_wp_error( $ancestor_link ) ? '' : $ancestor_link,
					);
				}
			}

			$items[] = array(
				'name' => $term->name,
				'url'  => '',
			);
		}
	} elseif ( is_archive() ) {
		$items[] = array(
			'name' => wp_strip_all_tags( get_the_archive_title() ),
			'url'  => '',
		);
	} elseif ( is_search() ) {
		$items[] = array(
			'name' => get_search_query(),
			'url'  => '',
		);
	} elseif ( is_404() ) {
		$items[] = array(
			'name' => __( 'Page not found', 'easyrankly' ),
			'url'  => '',
		);
	}

	$resolved = apply_filters( 'erankly_breadcrumb_items', $items );

	return is_array( $resolved ) ? $resolved : array();
}

/** @return array<string,mixed> */
function erankly_schema_breadcrumb_list(): array {
	$items = erankly_get_schema_breadcrumb_items();

	if ( count( $items ) < 2 ) {
		return array();
	}

	$list = array();

	foreach ( $items as $index => $item ) {
		$name = isset( $item['name'] ) ? (string) $item['name'] : '';
		$url  = isset( $item['url'] ) ? (string) $item['url'] : erankly_get_canonical();

		if ( '' === $name ) {
			continue;
		}

		$list[] = array_filter(
			array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $name,
				'item'     => '' !== $url ? $url : erankly_get_canonical(),
			)
		);
	}

	if ( empty( $list ) ) {
		return array();
	}

	$schema = array(
		'@type'           => 'BreadcrumbList',
		'@id'             => erankly_get_canonical() . '#breadcrumb',
		'itemListElement' => $list,
	);

	return apply_filters( 'erankly_schema_breadcrumb_list', $schema, $items );
}

/**
 * Whether BreadcrumbList JSON-LD should be emitted for the current request.
 *
 * Visual trail and structured data are separate: Google's structured-data
 * policies expect markup to match what people see. The default `when_visible`
 * mode therefore emits JSON-LD only when a trail is actually rendered.
 */
function erankly_should_emit_breadcrumb_schema(): bool {
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		return false;
	}

	$mode = function_exists( 'erankly_sanitize_breadcrumb_jsonld_mode' )
		? erankly_sanitize_breadcrumb_jsonld_mode( erankly_get_setting( 'breadcrumb_jsonld_mode', 'when_visible' ) )
		: 'when_visible';

	if ( 'off' === $mode ) {
		return false;
	}

	if ( 'always' === $mode ) {
		return true;
	}

	return erankly_has_visible_breadcrumbs();
}

/**
 * Detects a visible breadcrumb trail: native block, shortcode, legacy block, or theme support.
 *
 * Block themes render the template before wp_head, so a trail already printed
 * in a header template part is visible to BreadcrumbList JSON-LD in `when_visible` mode.
 * A `core/breadcrumbs` comment in post content is syntactic: the block must be
 * registered to count, and it does not prove that a later render_block filter
 * will keep the markup. Synced patterns and PHP templates that print the block
 * only after wp_head are not resolved from post content.
 */
function erankly_has_visible_breadcrumbs(): bool {
	$visible = current_theme_supports( 'erankly-breadcrumbs' ) || erankly_breadcrumb_trail_was_rendered();

	if ( ! $visible && is_singular() ) {
		$post = get_post();

		if ( $post instanceof WP_Post && erankly_content_has_visible_breadcrumbs( $post->post_content ) ) {
			$visible = true;
		}
	}

	return (bool) apply_filters( 'erankly_has_visible_breadcrumbs', $visible );
}

/**
 * Items used for BreadcrumbList JSON-LD.
 *
 * When a native block has already produced a confirmed visible trail, these
 * follow that block's items (attributes, core filters, EasyRankly labels).
 * An EasyRankly trail that already printed (shortcode, legacy block, theme
 * function) is kept instead of reconstructing a later native block. Otherwise
 * they use EasyRankly's own builder, or reconstruct the first `core/breadcrumbs`
 * block in post content through `render_block()` when the head runs first.
 *
 * @return array<int,array<string,string>>
 */
function erankly_get_schema_breadcrumb_items(): array {
	$core_items = erankly_captured_core_breadcrumb_items();

	if ( is_array( $core_items ) ) {
		return $core_items;
	}

	if ( erankly_breadcrumb_trail_was_rendered() ) {
		return erankly_get_breadcrumb_items();
	}

	if ( erankly_post_content_has_core_breadcrumbs_block() ) {
		erankly_preview_core_breadcrumb_items_from_content();
		$core_items = erankly_captured_core_breadcrumb_items();

		return is_array( $core_items ) ? $core_items : array();
	}

	return erankly_get_breadcrumb_items();
}

/**
 * Whether WordPress exposes the native `core/breadcrumbs` block.
 *
 * After `init` has finished, the block registry is authoritative so a
 * deregistered block is not treated as available. Earlier calls fall back to
 * the WordPress 7.0 version heuristic when the registry has no entry yet.
 * A Gutenberg plugin that registers the block on older cores is detected via
 * the registry once that registration has run.
 */
function erankly_core_breadcrumbs_block_available(): bool {
	if ( class_exists( 'WP_Block_Type_Registry', false ) ) {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( 'core/breadcrumbs' ) ) {
			return true;
		}

		if ( erankly_block_registration_window_has_passed() ) {
			return false;
		}
	}

	global $wp_version;

	return version_compare( (string) $wp_version, '7.0', '>=' );
}

/**
 * Whether core and Gutenberg have had their usual `init` chance to register blocks.
 *
 * Core registers `core/breadcrumbs` on `init` at priority 10. During later `init`
 * callbacks the registry is authoritative even if `doing_action( 'init' )` is still true.
 */
function erankly_block_registration_window_has_passed(): bool {
	if ( doing_action( 'init' ) ) {
		$priority = erankly_current_hook_priority( 'init' );

		return is_int( $priority ) && $priority > 10;
	}

	return (bool) did_action( 'init' );
}

function erankly_current_hook_priority( string $hook_name ): ?int {
	global $wp_filter;

	if ( ! isset( $wp_filter[ $hook_name ] ) || ! is_object( $wp_filter[ $hook_name ] ) || ! method_exists( $wp_filter[ $hook_name ], 'current_priority' ) ) {
		return null;
	}

	$priority = $wp_filter[ $hook_name ]->current_priority();

	return is_numeric( $priority ) ? (int) $priority : null;
}

function erankly_breadcrumb_settings_help_text( ?bool $core_available = null ): string {
	if ( null === $core_available ) {
		$core_available = erankly_core_breadcrumbs_block_available();
	}

	if ( $core_available ) {
		return sprintf(
			/* translators: 1: shortcode, 2: PHP function name */
			__( 'Add a visible trail with WordPress\'s Breadcrumbs block (included from WordPress 7.0), the %1$s shortcode, or %2$s in your theme. EasyRankly does not add a separate Gutenberg block to the inserter.', 'easyrankly' ),
			'[erankly_breadcrumbs]',
			'erankly_breadcrumbs()'
		);
	}

	return sprintf(
		/* translators: 1: shortcode, 2: PHP function name */
		__( 'The WordPress Breadcrumbs block requires WordPress 7.0 or later and is not available on this site. Add a visible trail with EasyRankly\'s Breadcrumbs block, the %1$s shortcode, or %2$s in your theme.', 'easyrankly' ),
		'[erankly_breadcrumbs]',
		'erankly_breadcrumbs()'
	);
}

function erankly_content_has_visible_breadcrumbs( string $content ): bool {
	if (
		has_shortcode( $content, 'erankly_breadcrumbs' )
		|| has_shortcode( $content, 'easyrankly_breadcrumbs' )
	) {
		return true;
	}

	if ( ! function_exists( 'has_block' ) ) {
		return false;
	}

	if ( has_block( 'easyrankly/breadcrumbs', $content ) ) {
		return true;
	}

	return has_block( 'core/breadcrumbs', $content ) && erankly_core_breadcrumbs_block_available();
}

function erankly_post_content_has_core_breadcrumbs_block(): bool {
	if ( ! is_singular() || ! function_exists( 'has_block' ) || ! erankly_core_breadcrumbs_block_available() ) {
		return false;
	}

	$post = get_post();

	return $post instanceof WP_Post && has_block( 'core/breadcrumbs', $post->post_content );
}

/**
 * Request-level flag that a visible trail has already been produced.
 *
 * @param bool|null $set When non-null, replaces the flag. Pass false to reset.
 */
function erankly_breadcrumb_trail_was_rendered( ?bool $set = null ): bool {
	static $rendered = false;

	if ( null !== $set ) {
		$rendered = $set;
	}

	return $rendered;
}

/**
 * Native breadcrumb items confirmed from a visible core block for this request.
 *
 * Confirmation happens after the block's final HTML is known. Candidate items
 * collected during `block_core_breadcrumbs_items` are not exposed here.
 *
 * @param array<int,array<string,string>>|null $items Items to store, or null to only read.
 * @param bool                                 $reset Clear the store.
 * @return array<int,array<string,string>>|null Null when nothing was confirmed.
 */
function erankly_captured_core_breadcrumb_items( ?array $items = null, bool $reset = false ): ?array {
	static $captured = false;
	static $stored   = array();

	if ( $reset ) {
		$captured = false;
		$stored   = array();

		return null;
	}

	if ( null !== $items ) {
		$captured = true;
		$stored   = $items;
	}

	return $captured ? $stored : null;
}

/**
 * @param array<int,array<string,string>>|null $items Candidate items, or null to only read.
 * @param bool                                 $reset Clear the candidate.
 * @param bool                                 $take  Return and clear the candidate.
 * @return array<int,array<string,string>>|null
 */
function erankly_pending_core_breadcrumb_items( ?array $items = null, bool $reset = false, bool $take = false ): ?array {
	static $pending = null;

	if ( $reset ) {
		$pending = null;

		return null;
	}

	if ( $take ) {
		$taken   = $pending;
		$pending = null;

		return is_array( $taken ) ? $taken : null;
	}

	if ( null !== $items ) {
		$pending = $items;
	}

	return $pending;
}

/**
 * @param string|null $set `preview`, `render`, or empty to only read. Pass '' with $reset to clear.
 */
function erankly_core_breadcrumb_capture_source( ?string $set = null, bool $reset = false ): string {
	static $source = '';

	if ( $reset ) {
		$source = '';

		return '';
	}

	if ( null !== $set ) {
		$source = $set;
	}

	return $source;
}

function erankly_core_breadcrumb_preview_completed( ?bool $set = null, bool $reset = false ): bool {
	static $completed = false;

	if ( $reset ) {
		$completed = false;

		return false;
	}

	if ( null !== $set ) {
		$completed = $set;
	}

	return $completed;
}

function erankly_reset_breadcrumb_runtime_state(): void {
	erankly_breadcrumb_trail_was_rendered( false );
	erankly_captured_core_breadcrumb_items( null, true );
	erankly_pending_core_breadcrumb_items( null, true );
	erankly_core_breadcrumb_capture_source( null, true );
	erankly_core_breadcrumb_preview_completed( null, true );
	erankly_previewing_core_breadcrumbs( false );
	erankly_breadcrumb_items_resolution_token( true );
}

function erankly_breadcrumb_items_resolution_token( bool $bump = false ): int {
	static $token = 0;

	if ( $bump ) {
		++$token;
	}

	return $token;
}

function erankly_previewing_core_breadcrumbs( ?bool $set = null ): bool {
	static $previewing = false;

	if ( null !== $set ) {
		$previewing = $set;
	}

	return $previewing;
}

function erankly_normalize_core_breadcrumb_label( array $item ): string {
	if ( ! isset( $item['label'] ) || is_array( $item['label'] ) || is_object( $item['label'] ) ) {
		return '';
	}

	$label = (string) $item['label'];

	if ( '' === $label ) {
		return '';
	}

	if ( ! empty( $item['allow_html'] ) ) {
		$label = wp_kses_post( $label );
		$label = wp_strip_all_tags( $label );

		return html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	// With allow_html=false the core renderer uses esc_html(), so the visible
	// text is the original label, including angle brackets and entity names.
	return $label;
}

function erankly_normalize_core_breadcrumb_url( mixed $url ): string {
	if ( ! is_string( $url ) || '' === $url ) {
		return '';
	}

	if ( '' === esc_url( $url, null, 'display' ) ) {
		return '';
	}

	$safe = esc_url_raw( $url );

	return is_string( $safe ) ? $safe : '';
}

/**
 * @param array<int,array<string,mixed>> $items Core breadcrumb items.
 * @return array<int,array<string,string>>
 */
function erankly_normalize_core_breadcrumb_items( array $items ): array {
	$normalized = array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$name = erankly_normalize_core_breadcrumb_label( $item );

		if ( '' === $name ) {
			continue;
		}

		$normalized[] = array(
			'name' => $name,
			'url'  => isset( $item['url'] ) ? erankly_normalize_core_breadcrumb_url( $item['url'] ) : '',
		);
	}

	return $normalized;
}

/**
 * Stores candidate items until the block's final HTML is accepted.
 *
 * @param array<int,array<string,mixed>> $items Core breadcrumb items.
 * @return array<int,array<string,mixed>>
 */
function erankly_capture_core_breadcrumb_items( array $items ): array {
	if ( (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		erankly_pending_core_breadcrumb_items( erankly_normalize_core_breadcrumb_items( $items ) );
	}

	return $items;
}

function erankly_core_breadcrumbs_untitled_label( int $post_id = 0 ): string {
	if ( $post_id > 0 && function_exists( 'block_core_breadcrumbs_get_post_title' ) && '' === wp_strip_all_tags( get_the_title( $post_id ) ) ) {
		return wp_strip_all_tags( block_core_breadcrumbs_get_post_title( $post_id ) );
	}

	return __( '(no title)' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Match the core breadcrumbs untitled fallback.
}

/**
 * @return array<string,mixed>
 */
function erankly_core_breadcrumbs_default_attributes(): array {
	$defaults = array(
		'prefersTaxonomy' => false,
		'separator'       => '/',
		'showHomeItem'    => true,
		'showCurrentItem' => true,
		'showOnHomePage'  => false,
	);

	if ( ! class_exists( 'WP_Block_Type_Registry', false ) ) {
		return $defaults;
	}

	$block = WP_Block_Type_Registry::get_instance()->get_registered( 'core/breadcrumbs' );

	if ( ! $block || ! is_array( $block->attributes ) ) {
		return $defaults;
	}

	foreach ( $block->attributes as $name => $schema ) {
		if ( ! is_array( $schema ) || ! array_key_exists( 'default', $schema ) ) {
			continue;
		}

		$defaults[ $name ] = $schema['default'];
	}

	return $defaults;
}

/**
 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
 * @return array<string,mixed>|null Smallest ancestor tree that still contains the first core breadcrumbs block.
 */
function erankly_core_breadcrumbs_preview_block( array $blocks ): ?array {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( isset( $block['blockName'] ) && 'core/breadcrumbs' === $block['blockName'] ) {
			return $block;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$nested = erankly_core_breadcrumbs_preview_block( $block['innerBlocks'] );

			if ( null !== $nested ) {
				$block['innerBlocks']  = array( $nested );
				$block['innerContent'] = erankly_inner_content_with_single_child( $block );

				return $block;
			}
		}
	}

	return null;
}

/**
 * @param array<string,mixed> $block Parent parsed block.
 * @return array<int,string|null>
 */
function erankly_inner_content_with_single_child( array $block ): array {
	$inner  = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
	$prefix = '';
	$suffix = '';

	if ( array() !== $inner ) {
		$first  = reset( $inner );
		$last   = end( $inner );
		$prefix = is_string( $first ) ? $first : '';
		$suffix = is_string( $last ) ? $last : '';
	}

	return array( $prefix, null, $suffix );
}

/**
 * Rebuilds native breadcrumb items from the first `core/breadcrumbs` block in
 * the queried post through the normal `render_block()` cycle, without printing
 * markup or setting the visibility flag.
 */
function erankly_preview_core_breadcrumb_items_from_content(): void {
	if ( is_array( erankly_captured_core_breadcrumb_items() ) || erankly_core_breadcrumb_preview_completed() ) {
		return;
	}

	if ( ! function_exists( 'render_block' ) || ! function_exists( 'parse_blocks' ) ) {
		erankly_core_breadcrumb_preview_completed( true );

		return;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		erankly_core_breadcrumb_preview_completed( true );

		return;
	}

	$block = erankly_core_breadcrumbs_preview_block( parse_blocks( (string) $post->post_content ) );

	if ( null === $block ) {
		erankly_core_breadcrumb_preview_completed( true );

		return;
	}

	erankly_previewing_core_breadcrumbs( true );

	try {
		render_block( $block );
	} finally {
		erankly_previewing_core_breadcrumbs( false );
		erankly_core_breadcrumb_preview_completed( true );
		erankly_pending_core_breadcrumb_items( null, true );
	}
}

/**
 * Applies EasyRankly breadcrumb labels to the native WordPress breadcrumbs block.
 *
 * Matches the current post by permalink or by the core current-item label,
 * including the untitled fallback `(no title)`. Ancestors are matched by
 * permalink so a custom name is not applied to a pagination crumb.
 *
 * @param array<int,array<string,mixed>> $items Core breadcrumb items.
 * @return array<int,array<string,mixed>>
 */
function erankly_filter_core_breadcrumb_items( array $items ): array {
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) || array() === $items || ! is_singular() ) {
		return $items;
	}

	$post_id = get_queried_object_id();
	if ( $post_id <= 0 ) {
		return $items;
	}

	$permalink       = untrailingslashit( (string) get_permalink( $post_id ) );
	$post_title      = wp_strip_all_tags( get_the_title( $post_id ) );
	$untitled_label  = erankly_core_breadcrumbs_untitled_label( $post_id );
	$current_name    = erankly_get_post_breadcrumb_name( $post_id );
	$ancestor_names  = array();

	foreach ( get_post_ancestors( $post_id ) as $ancestor_id ) {
		$ancestor_id = (int) $ancestor_id;
		$ancestor_url = untrailingslashit( (string) get_permalink( $ancestor_id ) );

		if ( '' !== $ancestor_url ) {
			$ancestor_names[ $ancestor_url ] = erankly_get_post_breadcrumb_name( $ancestor_id );
		}
	}

	foreach ( $items as $index => $item ) {
		$url   = isset( $item['url'] ) ? untrailingslashit( (string) $item['url'] ) : '';
		$label = isset( $item['label'] ) ? wp_strip_all_tags( (string) $item['label'] ) : '';

		if ( '' !== $url && isset( $ancestor_names[ $url ] ) && '' !== $ancestor_names[ $url ] ) {
			$items[ $index ]['label'] = $ancestor_names[ $url ];
			continue;
		}

		$is_current = ( '' !== $permalink && $permalink === $url )
			|| (
				'' === $url
				&& (
					( '' !== $post_title && $label === $post_title )
					|| ( '' === $post_title && $label === $untitled_label )
				)
			);

		if ( $is_current && '' !== $current_name ) {
			$items[ $index ]['label'] = $current_name;
		}
	}

	return $items;
}

/**
 * @param mixed $html Rendered core breadcrumbs markup.
 * @param mixed $block Parsed block, when called from the render_block filter.
 * @return mixed
 */
function erankly_confirm_rendered_core_breadcrumbs( $html, $block = array() ) {
	unset( $block );

	$pending = erankly_pending_core_breadcrumb_items( null, false, true );

	if ( ! is_array( $pending ) ) {
		return $html;
	}

	$visible = is_string( $html ) && '' !== trim( $html );

	if ( ! $visible ) {
		return $html;
	}

	$from_preview = erankly_previewing_core_breadcrumbs();
	$source       = erankly_core_breadcrumb_capture_source();

	if ( $from_preview ) {
		if ( '' === $source && ! erankly_breadcrumb_trail_was_rendered() ) {
			erankly_captured_core_breadcrumb_items( $pending );
			erankly_core_breadcrumb_capture_source( 'preview' );
		}

		return $html;
	}

	if ( 'render' === $source ) {
		erankly_mark_rendered_core_breadcrumbs( $html );

		return $html;
	}

	if ( erankly_breadcrumb_trail_was_rendered() ) {
		return $html;
	}

	erankly_captured_core_breadcrumb_items( $pending );
	erankly_core_breadcrumb_capture_source( 'render' );
	erankly_mark_rendered_core_breadcrumbs( $html );

	return $html;
}

/**
 * @param mixed $html Rendered core breadcrumbs markup.
 * @return mixed
 */
function erankly_mark_rendered_core_breadcrumbs( $html ) {
	if ( erankly_previewing_core_breadcrumbs() ) {
		return $html;
	}

	if ( (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) && is_string( $html ) && '' !== trim( $html ) ) {
		erankly_breadcrumb_trail_was_rendered( true );
	}

	return $html;
}

/** @param array<string,mixed>|string $atts Shortcode attributes. */
function erankly_breadcrumbs_shortcode( $atts = array() ): string {
	unset( $atts );

	return erankly_breadcrumbs(
		array(
			'echo'          => false,
			'mark_rendered' => true,
		)
	);
}

function erankly_render_breadcrumbs_block( array $attributes = array(), string $content = '' ): string {
	unset( $attributes, $content );

	return erankly_breadcrumbs(
		array(
			'echo'          => false,
			'mark_rendered' => true,
		)
	);
}

function erankly_register_breadcrumb_integrations(): void {
	add_shortcode( 'erankly_breadcrumbs', 'erankly_breadcrumbs_shortcode' );
	add_shortcode( 'easyrankly_breadcrumbs', 'erankly_breadcrumbs_shortcode' );

	add_filter( 'block_core_breadcrumbs_items', 'erankly_filter_core_breadcrumb_items' );
	add_filter( 'block_core_breadcrumbs_items', 'erankly_capture_core_breadcrumb_items', PHP_INT_MAX );
	add_filter( 'render_block_core/breadcrumbs', 'erankly_confirm_rendered_core_breadcrumbs', PHP_INT_MAX, 2 );

	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$block_dir      = ERANKLY_PATH . 'blocks/breadcrumbs';
	$core_available = erankly_core_breadcrumbs_block_available();

	wp_register_script(
		'erankly-breadcrumbs-block',
		ERANKLY_URL . 'blocks/breadcrumbs/index.js',
		array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor' ),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-breadcrumbs-block', 'easyrankly', ERANKLY_PATH . 'languages' );

	// Keep the legacy block registered so existing content still renders.
	// Hide it from the inserter only when the native block is actually available.
	register_block_type(
		$block_dir,
		array(
			'render_callback'       => 'erankly_render_breadcrumbs_block',
			'editor_script_handles' => array( 'erankly-breadcrumbs-block' ),
			'supports'              => array(
				'html'     => false,
				'align'    => array( 'wide', 'full' ),
				'inserter' => ! $core_available,
			),
		)
	);

	erankly_sync_legacy_breadcrumbs_availability();
}

/**
 * Aligns server supports and the editor snapshot with the current block registry.
 *
 * Gutenberg on older cores, or a deregistration after our `init` callback, can
 * change availability after the legacy block was first registered.
 */
function erankly_sync_legacy_breadcrumbs_availability(): void {
	if ( ! class_exists( 'WP_Block_Type_Registry', false ) ) {
		return;
	}

	$registry = WP_Block_Type_Registry::get_instance();

	if ( ! $registry->is_registered( 'easyrankly/breadcrumbs' ) ) {
		return;
	}

	$legacy         = $registry->get_registered( 'easyrankly/breadcrumbs' );
	$supports       = is_array( $legacy->supports ) ? $legacy->supports : array();
	$core_available = erankly_core_breadcrumbs_block_available();

	$supports['html']     = false;
	$supports['align']    = array( 'wide', 'full' );
	$supports['inserter'] = ! $core_available;
	$legacy->supports     = $supports;

	erankly_set_legacy_breadcrumbs_availability_script( $core_available );
}

function erankly_set_legacy_breadcrumbs_availability_script( bool $core_available ): void {
	if ( ! function_exists( 'wp_scripts' ) || ! wp_script_is( 'erankly-breadcrumbs-block', 'registered' ) ) {
		return;
	}

	$script    = 'window.eranklyBreadcrumbsBlock = ' . wp_json_encode( array( 'coreAvailable' => $core_available ) ) . ';';
	$wp_scripts = wp_scripts();

	if ( $wp_scripts instanceof WP_Scripts && isset( $wp_scripts->registered['erankly-breadcrumbs-block'] ) ) {
		$before = $wp_scripts->registered['erankly-breadcrumbs-block']->extra['before'] ?? array();

		if ( ! is_array( $before ) ) {
			$before = array();
		}

		$filtered = array();

		foreach ( $before as $chunk ) {
			if ( is_string( $chunk ) && false !== strpos( $chunk, 'window.eranklyBreadcrumbsBlock' ) ) {
				continue;
			}

			$filtered[] = $chunk;
		}

		$wp_scripts->registered['erankly-breadcrumbs-block']->extra['before'] = $filtered;
	}

	wp_add_inline_script( 'erankly-breadcrumbs-block', $script, 'before' );
}
