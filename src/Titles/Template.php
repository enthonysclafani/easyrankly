<?php
/**
 * Template variables for titles and descriptions.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Titles;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces {{variables}} with plain text and tidies the result.
 */
final class Template {

	/**
	 * Length of a description generated from content, in characters.
	 */
	public const EXCERPT_LENGTH = 160;

	/**
	 * Replaces every {{name}} with its value; unknown names become empty. {{cf:key}} is the
	 * custom field `key` of the object, read by $field.
	 *
	 * Separators left dangling by empty variables are removed, so
	 * "{{title}} {{page}} {{sep}} {{site_name}}" never ends up as "Title  - - Site".
	 *
	 * @param string                                   $template  Template text.
	 * @param array<string, string|\Closure(): string> $variables Values keyed by variable name; a closure is
	 *                                                         called only if the template uses it.
	 * @param string                                   $separator Value of {{sep}}.
	 * @param (\Closure(string): string)|null          $field     Value of a custom field by key; null when
	 *                                                         the object has none.
	 * @return string Plain text, not escaped.
	 */
	public static function render( string $template, array $variables, string $separator, ?\Closure $field = null ): string {
		$text = (string) preg_replace_callback(
			'/\{\{\s*(?:cf:([a-z0-9_-]+)|([a-z_]+))\s*\}\}/i',
			static function ( array $matches ) use ( $variables, $field ): string {
				if ( '' !== $matches[1] ) {
					return null === $field ? '' : self::plain( (string) $field( $matches[1] ) );
				}

				$name = strtolower( $matches[2] );
				if ( 'sep' === $name ) {
					return "\x1F";
				}

				$value = $variables[ $name ] ?? '';

				return self::plain( $value instanceof \Closure ? (string) $value() : $value );
			},
			$template
		);

		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		// "\x1F" marks separators: collapse runs and drop them at either end.
		$text = (string) preg_replace( '/(\s*\x1F\s*)+/', "\x1F", $text );
		$text = trim( $text, " \x1F" );

		return trim( str_replace( "\x1F", '' === $separator ? ' ' : ' ' . $separator . ' ', $text ) );
	}

	/**
	 * Plain text from stored HTML: no tags, no entities, one line.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function plain( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Description-length summary of a post: its excerpt, or the start of its content, both
	 * cut to EXCERPT_LENGTH.
	 *
	 * Shortcodes are removed without running them; only text blocks are kept (core excerpt rules).
	 * A password-protected post has none, excerpt included, as in core: its text would reach the
	 * description, the social tags and the schema of a page anyone can load.
	 * Title, social tags and schema ask for it on the same page: the summary of the content is
	 * kept in the object cache, keyed by the content itself, so it is worked out once.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function excerpt( \WP_Post $post ): string {
		if ( '' !== $post->post_password ) {
			return '';
		}

		if ( '' !== trim( $post->post_excerpt ) ) {
			return self::truncate( self::plain( $post->post_excerpt ), self::EXCERPT_LENGTH );
		}

		$key  = 'excerpt:' . md5( $post->post_content );
		$text = wp_cache_get( $key, 'easyrankly' );
		if ( ! is_string( $text ) ) {
			$text = self::truncate( self::plain( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) ), self::EXCERPT_LENGTH );
			wp_cache_set( $key, $text, 'easyrankly', HOUR_IN_SECONDS );
		}

		return $text;
	}

	/**
	 * Description-length text of a custom field of a post or term, for {{cf:key}}: the value
	 * a field plugin such as ACF keeps in the object's meta. Meta are cached with the queried
	 * object, so this costs no query.
	 *
	 * Protected keys (starting with `_`, or marked by `is_protected_meta`) are never read: they
	 * hold plugin data, and a per-post override, which an editor writes, is a template too.
	 * Values that are not text (arrays, objects) are empty; shortcodes are removed without
	 * running them, as in excerpt(). A password-protected post has none, as for its excerpt.
	 *
	 * @param \WP_Post|\WP_Term $item Post or term.
	 * @param string            $key  Meta key.
	 * @return string
	 */
	public static function custom_field( $item, string $key ): string {
		$type = $item instanceof \WP_Post ? 'post' : 'term';
		if ( is_protected_meta( $key, $type ) || ( $item instanceof \WP_Post && '' !== $item->post_password ) ) {
			return '';
		}

		$value = $item instanceof \WP_Post ? get_post_meta( $item->ID, $key, true ) : get_term_meta( $item->term_id, $key, true );
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}

		return self::truncate( self::plain( strip_shortcodes( (string) $value ) ), self::EXCERPT_LENGTH );
	}

	/**
	 * Title of a post as the document title shows it: without the "Protected:" and "Private:"
	 * prefixes get_the_title() adds on the frontend.
	 *
	 * @param \WP_Post $post Post.
	 * @return string May contain markup and entities.
	 */
	public static function post_title( \WP_Post $post ): string {
		/** This filter is documented in wp-includes/general-template.php */
		return (string) apply_filters( 'single_post_title', $post->post_title, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied as the core does for the document title.
	}

	/**
	 * Cuts text at the last word boundary within the limit.
	 *
	 * @param string $text  Plain text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	public static function truncate( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > $limit / 2 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t,;:.-" ) . '…';
	}
}
