<?php
/**
 * Open Graph and X (Twitter) tags.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Social;

use EasyRankly\Canonical\Canonical;
use EasyRankly\Context\Context;
use EasyRankly\Meta\Meta;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Titles;

defined( 'ABSPATH' ) || exit;

/**
 * Prints Open Graph tags for sharing, plus the two X tags that Open Graph does not cover.
 *
 * X reads og:title, og:description and og:image itself, so they are not repeated as
 * twitter:* tags. Title and description fall back to the SEO ones (1.3); the image to the
 * featured image and then to the default image of the settings.
 */
final class Social {

	/**
	 * Hooks the tags into <head>, after title and description.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_tags' ), 2 );
	}

	/**
	 * Prints the tags of the current page. Search results and 404 pages get none.
	 */
	public function print_tags(): void {
		foreach ( self::tags() as $tag ) {
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $tag[0] ), esc_attr( $tag[1] ), esc_attr( $tag[2] ) );
		}
	}

	/**
	 * Tags of the current page as [attribute, name, content], in output order.
	 *
	 * @return list<array{string, string, string}>
	 */
	public static function tags(): array {
		$keys = Context::keys();
		if ( array() === $keys || is_search() || is_404() ) {
			return array();
		}

		$object  = get_queried_object();
		$post    = $object instanceof \WP_Post ? $object : null;
		$article = null !== $post && is_singular() && ! is_front_page() && 'page' !== $post->post_type;

		$title = (string) self::override( 'og_title' );
		if ( '' === $title ) {
			$title = Titles::title();
		}
		if ( '' === $title ) {
			$title = html_entity_decode( wp_get_document_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$description = (string) self::override( 'og_description' );
		if ( '' === $description ) {
			$description = Titles::description();
		}

		$tags = array(
			array( 'property', 'og:locale', get_locale() ),
			array( 'property', 'og:site_name', html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			array( 'property', 'og:type', $article ? 'article' : 'website' ),
			array( 'property', 'og:title', $title ),
		);

		if ( '' !== $description ) {
			$tags[] = array( 'property', 'og:description', $description );
		}

		$url = null !== $post && is_singular() ? (string) wp_get_canonical_url( $post ) : Canonical::archive_url();
		if ( '' !== $url ) {
			$tags[] = array( 'property', 'og:url', $url );
		}

		$image = self::image( $post );
		if ( null !== $image ) {
			$tags[] = array( 'property', 'og:image', $image['url'] );
			if ( $image['width'] > 0 && $image['height'] > 0 ) {
				$tags[] = array( 'property', 'og:image:width', (string) $image['width'] );
				$tags[] = array( 'property', 'og:image:height', (string) $image['height'] );
			}
			if ( '' !== $image['alt'] ) {
				$tags[] = array( 'property', 'og:image:alt', $image['alt'] );
			}
		}

		if ( $article && null !== $post ) {
			$tags[] = array( 'property', 'article:published_time', (string) get_post_time( 'c', true, $post ) );
			$tags[] = array( 'property', 'article:modified_time', (string) get_post_modified_time( 'c', true, $post ) );
		}

		$tags[] = array( 'name', 'twitter:card', null !== $image ? 'summary_large_image' : 'summary' );

		$username = (string) Settings::value( 'x_username' );
		if ( '' !== $username ) {
			$tags[] = array( 'name', 'twitter:site', '@' . $username );
		}

		return $tags;
	}

	/**
	 * Per-object social field of the queried post or term.
	 *
	 * @param string $name Field name without prefix.
	 * @return mixed
	 */
	private static function override( string $name ): mixed {
		$object = get_queried_object();

		if ( $object instanceof \WP_Post && ( is_singular() || is_home() ) ) {
			return Meta::post( $object->ID, $name );
		}
		if ( $object instanceof \WP_Term ) {
			return Meta::term( $object->term_id, $name );
		}

		return '';
	}

	/**
	 * Image to share: the object's social image, the featured image, then the default one.
	 *
	 * Loading the featured image costs the same queries the theme makes to show it, and
	 * they are cached for it. The default image is copied in the settings: no query.
	 *
	 * @param \WP_Post|null $post Queried post, if any.
	 * @return array{url: string, width: int, height: int, alt: string}|null
	 */
	private static function image( ?\WP_Post $post ): ?array {
		$candidates = array( (int) self::override( 'og_image' ) );

		if ( null !== $post && is_singular() ) {
			$candidates[] = (int) get_post_thumbnail_id( $post );
		}

		foreach ( $candidates as $attachment_id ) {
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$source = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( false === $source ) {
				continue;
			}

			return array(
				'url'    => $source[0],
				'width'  => (int) $source[1],
				'height' => (int) $source[2],
				'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ),
			);
		}

		return Settings::image( 'social_image' );
	}
}
