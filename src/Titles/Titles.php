<?php
/**
 * Document title and meta description.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Titles;

use EasyRankly\Context\Context;
use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Routing;
use EasyRankly\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the title and description of the current page from the per-object override
 * or the template of its context, and prints them.
 *
 * Everything it reads is already in memory: the queried object with its meta and the
 * autoloaded settings. No query is added on the frontend.
 */
final class Titles {

	/**
	 * Names of the template variables, as the settings screen lists them: those of
	 * variables(), plus `sep`, which Template::render() fills in from the settings.
	 */
	public const VARIABLES = array( 'title', 'sep', 'site_name', 'tagline', 'page', 'excerpt', 'term_description', 'author', 'post_type', 'category', 'date', 'search_query' );

	/**
	 * Hooks the title filter and the description tag.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 15 );
		add_action( 'wp_head', array( $this, 'print_description' ), 1 );
	}

	/**
	 * Replaces the document title; an empty result leaves the core title.
	 *
	 * @param mixed $title Title from earlier filters.
	 * @return mixed
	 */
	public function filter_title( $title ) {
		if ( is_string( $title ) && '' !== $title ) {
			return $title;
		}

		$ours = self::title();

		// pre_get_document_title output is printed as is: escape here.
		return '' === $ours ? $title : esc_html( $ours );
	}

	/**
	 * Prints the meta description, if there is one.
	 */
	public function print_description(): void {
		$description = self::description();

		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
	}

	/**
	 * SEO title of the current page, plain text. Empty when WordPress should decide.
	 *
	 * @return string
	 */
	public static function title(): string {
		return self::resolve( 'title' );
	}

	/**
	 * Meta description of the current page, plain text. Empty when there is none.
	 *
	 * @return string
	 */
	public static function description(): string {
		return self::resolve( 'description' );
	}

	/**
	 * Renders the override of the queried object or the template of the context.
	 *
	 * @param string $field "title" or "description".
	 * @return string
	 */
	private static function resolve( string $field ): string {
		$keys = Context::keys();
		if ( array() === $keys ) {
			return '';
		}

		$settings = Settings::get();
		$template = self::override( $field );

		// Templates of the language being viewed come first, even generic ones: their text is
		// in the right language. Then the templates of all languages.
		$sets     = array();
		$language = Routing::current();
		if ( '' !== $language && isset( $settings['language_templates'][ $language ] ) && is_array( $settings['language_templates'][ $language ] ) ) {
			$sets[] = $settings['language_templates'][ $language ];
		}
		$sets[] = is_array( $settings['templates'] ) ? $settings['templates'] : array();

		foreach ( $sets as $templates ) {
			foreach ( $keys as $key ) {
				if ( '' === $template && isset( $templates[ $key ][ $field ] ) && '' !== $templates[ $key ][ $field ] ) {
					$template = (string) $templates[ $key ][ $field ];
				}
			}
		}

		return Template::render( $template, self::variables(), (string) $settings['title_separator'] );
	}

	/**
	 * Per-object override from the post or term meta (also a template).
	 *
	 * @param string $field "title" or "description".
	 * @return string
	 */
	private static function override( string $field ): string {
		$object = get_queried_object();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() || is_front_page() ) ) {
			return (string) Meta::post( $object->ID, $field );
		}
		if ( $object instanceof \WP_Term ) {
			return (string) Meta::term( $object->term_id, $field );
		}

		return '';
	}

	/**
	 * Values of the template variables for the current request.
	 *
	 * Values that may cost a query (author, categories) or real work (excerpt) are closures,
	 * evaluated only when a template uses them.
	 *
	 * @return array<string, string|\Closure(): string>
	 */
	public static function variables(): array {
		$object = get_queried_object();
		$paged  = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ), 1 );
		$max    = (int) ( $GLOBALS['wp_query']->max_num_pages ?? 0 );

		$variables = array(
			'site_name' => (string) get_bloginfo( 'name', 'display' ),
			'tagline'   => (string) get_bloginfo( 'description', 'display' ),
			/* translators: 1: current page number, 2: total pages. */
			'page'      => $paged > 1 ? sprintf( __( 'Page %1$d of %2$d', 'easyrankly' ), $paged, max( $max, $paged ) ) : '',
			'title'     => '',
		);

		if ( is_404() ) {
			$variables['title'] = __( 'Page not found', 'easyrankly' );
		} elseif ( is_search() ) {
			// Encoded so Template::plain() keeps "<b>" typed by the visitor as text instead of stripping it.
			$variables['search_query'] = esc_html( get_search_query( false ) );
			/* translators: %s: search query. */
			$variables['title'] = sprintf( __( 'Search results for “%s”', 'easyrankly' ), $variables['search_query'] );
		} elseif ( $object instanceof \WP_Post ) {
			$variables['title']     = get_the_title( $object );
			$variables['excerpt']   = static fn(): string => Template::excerpt( $object );
			$variables['author']    = static fn(): string => (string) get_the_author_meta( 'display_name', (int) $object->post_author );
			$variables['post_type'] = (string) ( get_post_type_object( $object->post_type )->labels->singular_name ?? '' );
			$variables['date']      = static fn(): string => (string) get_the_date( '', $object );
			$variables['category']  = static function () use ( $object ): string {
				$categories = 'post' === $object->post_type ? get_the_category( $object->ID ) : array();
				return isset( $categories[0] ) ? $categories[0]->name : '';
			};
		} elseif ( $object instanceof \WP_Term ) {
			$variables['title']            = $object->name;
			$variables['term_description'] = Template::truncate( Template::plain( $object->description ), Template::EXCERPT_LENGTH );
		} elseif ( $object instanceof \WP_User ) {
			$variables['title']  = $object->display_name;
			$variables['author'] = $object->display_name;
		} elseif ( $object instanceof \WP_Post_Type ) {
			$variables['title']     = (string) $object->labels->name;
			$variables['post_type'] = (string) $object->labels->name;
		} elseif ( is_date() ) {
			$variables['title'] = wp_strip_all_tags( get_the_archive_title() );
			$variables['date']  = $variables['title'];
		}

		return $variables;
	}
}
