<?php
/**
 * Breadcrumb trail of the core/breadcrumbs block.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Breadcrumbs;

use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adjusts the trail of the core breadcrumb block through its own filters.
 *
 * The plugin has no breadcrumb block or shortcode of its own. The BreadcrumbList of the
 * schema (1.6) reads the items after these filters, so the visible trail and the
 * structured data always match.
 */
final class Breadcrumbs {

	/**
	 * Hooks the core block filters.
	 */
	public function register(): void {
		add_filter( 'block_core_breadcrumbs_items', array( $this, 'filter_items' ) );
		add_filter( 'block_core_breadcrumbs_post_type_settings', array( $this, 'filter_post_type_settings' ), 10, 2 );
	}

	/**
	 * Renames the home item when a label is set.
	 *
	 * The home item is the first one and links to the home page, except on the front
	 * page itself, where it is the only item and has no link.
	 *
	 * @param mixed $items Breadcrumb items.
	 * @return mixed
	 */
	public function filter_items( $items ) {
		$label = (string) Settings::value( 'breadcrumb_home_label' );

		if ( '' === $label || ! is_array( $items ) || ! isset( $items[0] ) || ! is_array( $items[0] ) ) {
			return $items;
		}

		$url     = isset( $items[0]['url'] ) && is_string( $items[0]['url'] ) ? $items[0]['url'] : '';
		$is_home = '' !== $url
			? untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) )
			: is_front_page() && 1 === count( $items );

		if ( $is_home ) {
			$items[0]['label'] = $label;
			unset( $items[0]['allow_html'] );
		}

		return $items;
	}

	/**
	 * Chooses the taxonomy of the trail for a post type, when the settings name one.
	 *
	 * An explicit choice made by an earlier filter wins.
	 *
	 * @param mixed $settings  Settings from earlier filters (taxonomy, term).
	 * @param mixed $post_type Post type of the post.
	 * @return mixed
	 */
	public function filter_post_type_settings( $settings, $post_type ) {
		$map = Settings::value( 'breadcrumb_taxonomies' );

		if ( ! is_array( $settings ) || ! is_string( $post_type ) || ! is_array( $map ) || ! isset( $map[ $post_type ] ) ) {
			return $settings;
		}

		if ( empty( $settings['taxonomy'] ) && is_object_in_taxonomy( $post_type, (string) $map[ $post_type ] ) ) {
			$settings['taxonomy'] = (string) $map[ $post_type ];
		}

		return $settings;
	}
}
