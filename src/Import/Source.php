<?php
/**
 * A plugin whose SEO data can be imported.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the data another SEO plugin left in the database, as EasyRankly fields and redirects.
 *
 * Nothing here writes: Import decides what is saved. Values are returned raw and sanitized by
 * the callbacks registered for the EasyRankly meta and by the redirect checks.
 */
abstract class Source {

	/**
	 * Variables of the other plugin => EasyRankly template variables. Names left out are
	 * dropped from the text: EasyRankly has nothing like them.
	 */
	private const VARIABLES = array(
		'title'                => 'title',
		'term_title'           => 'title',
		'term'                 => 'title',
		'sitename'             => 'site_name',
		'sitedesc'             => 'tagline',
		'sep'                  => 'sep',
		'separator'            => 'sep',
		'page'                 => 'page',
		'excerpt'              => 'excerpt',
		'excerpt_only'         => 'excerpt',
		'term_description'     => 'term_description',
		'category_description' => 'term_description',
		'tag_description'      => 'term_description',
		'pt_single'            => 'post_type',
		'pt_plural'            => 'post_type',
		'name'                 => 'author',
		'post_author'          => 'author',
		'user_description'     => 'author_description',
		'category'             => 'category',
		'primary_category'     => 'category',
		'date'                 => 'date',
		'searchphrase'         => 'search_query',
		'search_query'         => 'search_query',
	);

	/**
	 * Name shown in the admin.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether the plugin has ever been used on this site: its options outlive it.
	 *
	 * @return bool
	 */
	abstract public function detected(): bool;

	/**
	 * Fields of a post, keyed by EasyRankly field name (see Meta::fields()).
	 *
	 * @param int $post_id Post ID; its meta is already in the cache.
	 * @return array<string, mixed>
	 */
	abstract public function post_fields( int $post_id ): array;

	/**
	 * Fields of a term, keyed by EasyRankly field name.
	 *
	 * @param \WP_Term $term Term; its meta is already in the cache.
	 * @return array<string, mixed>
	 */
	abstract public function term_fields( \WP_Term $term ): array;

	/**
	 * One page of redirects, in a stable order: one list per entry of the other plugin, since an
	 * entry can have several sources.
	 *
	 * @param int $offset Entries already read.
	 * @param int $limit  Most entries to return; fewer means there are no more.
	 * @return list<list<array{source: string, target: string, code: int, regex: bool, active: bool}>>
	 */
	abstract public function redirects( int $offset, int $limit ): array;

	/**
	 * Pattern of a variable in the texts of the plugin; group 1 is its name, with arguments.
	 *
	 * @return string
	 */
	abstract protected function variable_pattern(): string;

	/**
	 * Custom field named by a variable, or null when the variable is not a custom field.
	 *
	 * @param string $variable Variable name as written, with arguments.
	 * @return string|null
	 */
	abstract protected function custom_field( string $variable ): ?string;

	/**
	 * A title or description template with its variables turned into EasyRankly ones.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	public function template( $text ): string {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim(
			(string) preg_replace_callback(
				$this->variable_pattern(),
				function ( array $matches ): string {
					$field = $this->custom_field( $matches[1] );
					if ( null !== $field ) {
						return 1 === preg_match( '/^[a-z0-9_-]+$/i', $field ) ? '{{cf:' . $field . '}}' : '';
					}

					$name = strtolower( $matches[1] );

					return isset( self::VARIABLES[ $name ] ) ? '{{' . self::VARIABLES[ $name ] . '}}' : '';
				},
				$text
			)
		);
	}

	/**
	 * A social text: kept only without variables, which EasyRankly does not fill in there.
	 *
	 * @param mixed $text Raw text.
	 * @return string
	 */
	public function plain( $text ): string {
		return is_string( $text ) && 1 !== preg_match( $this->variable_pattern(), $text ) ? trim( $text ) : '';
	}

	/**
	 * Attachment ID of an image, from its stored ID or, failing that, from its URL.
	 *
	 * @param mixed $id  Stored attachment ID.
	 * @param mixed $url Stored image URL.
	 * @return int
	 */
	public static function image( $id, $url ): int {
		$id = is_numeric( $id ) ? absint( $id ) : 0;
		if ( $id > 0 ) {
			return $id;
		}

		return is_string( $url ) && '' !== $url ? attachment_url_to_postid( $url ) : 0;
	}

	/**
	 * A redirect source or target as EasyRankly reads it: the other plugins store paths
	 * without the leading slash, and match regexes against them.
	 *
	 * @param string $value Source, target or regex.
	 * @param bool   $regex Whether it is a regex.
	 * @return string
	 */
	public static function path( string $value, bool $regex = false ): string {
		$value = trim( $value );

		if ( $regex ) {
			return str_starts_with( $value, '^' ) && ! str_starts_with( $value, '^/' ) ? '^/' . substr( $value, 1 ) : $value;
		}

		return '' === $value || str_starts_with( $value, '/' ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ? $value : '/' . $value;
	}
}
