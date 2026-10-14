<?php
/**
 * Data left by Yoast SEO.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Yoast SEO data. Names checked against the Yoast SEO source
 * (https://github.com/Yoast/wordpress-seo, October 2026):
 *
 * - posts: meta `_yoast_wpseo_{field}` (inc/class-wpseo-meta.php); `meta-robots-noindex` is
 *   "1" for noindex, "2" for index, "0" for the post type default;
 * - terms: option `wpseo_taxonomy_meta`, [taxonomy][term ID][`wpseo_{field}`]
 *   (inc/options/class-wpseo-taxonomy-meta.php); `wpseo_noindex` is "noindex", "index" or "default";
 * - variables: `%%name%%`, custom fields as `%%cf_name%%` (inc/class-wpseo-replace-vars.php);
 * - redirects (Yoast SEO Premium, not public): option `wpseo-premium-redirects-base`, a list of
 *   `origin`, `url`, `type` and `format` ("plain" or "regex"), as read by the old EasyRankly.
 */
final class Yoast extends Source {

	/**
	 * Option with the term fields.
	 */
	private const TERM_OPTION = 'wpseo_taxonomy_meta';

	/**
	 * Option with the Premium redirects.
	 */
	private const REDIRECT_OPTION = 'wpseo-premium-redirects-base';

	/**
	 * Name shown in the admin.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Yoast SEO';
	}

	/**
	 * Whether Yoast SEO has been used here: its options outlive its deactivation.
	 *
	 * @return bool
	 */
	public function detected(): bool {
		return false !== get_option( 'wpseo' ) || false !== get_option( self::TERM_OPTION ) || false !== get_option( self::REDIRECT_OPTION );
	}

	/**
	 * Fields of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function post_fields( int $post_id ): array {
		$get = static fn( string $key ): mixed => get_post_meta( $post_id, '_yoast_wpseo_' . $key, true );

		return array(
			'title'          => $this->template( $get( 'title' ) ),
			'description'    => $this->template( $get( 'metadesc' ) ),
			'canonical'      => $get( 'canonical' ),
			'noindex'        => '1' === (string) $get( 'meta-robots-noindex' ),
			'nofollow'       => '1' === (string) $get( 'meta-robots-nofollow' ),
			'og_title'       => $this->plain( $get( 'opengraph-title' ) ),
			'og_description' => $this->plain( $get( 'opengraph-description' ) ),
			'og_image'       => self::image( $get( 'opengraph-image-id' ), $get( 'opengraph-image' ) ),
		);
	}

	/**
	 * Fields of a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term_fields( \WP_Term $term ): array {
		$all  = get_option( self::TERM_OPTION );
		$meta = is_array( $all ) && isset( $all[ $term->taxonomy ][ $term->term_id ] ) && is_array( $all[ $term->taxonomy ][ $term->term_id ] )
			? $all[ $term->taxonomy ][ $term->term_id ]
			: array();
		$get  = static fn( string $key ): mixed => $meta[ 'wpseo_' . $key ] ?? '';

		return array(
			'title'          => $this->template( $get( 'title' ) ),
			'description'    => $this->template( $get( 'desc' ) ),
			'canonical'      => $get( 'canonical' ),
			'noindex'        => 'noindex' === $get( 'noindex' ),
			'og_title'       => $this->plain( $get( 'opengraph-title' ) ),
			'og_description' => $this->plain( $get( 'opengraph-description' ) ),
			'og_image'       => self::image( $get( 'opengraph-image-id' ), $get( 'opengraph-image' ) ),
		);
	}

	/**
	 * One page of the Premium redirects.
	 *
	 * @param int $offset Redirects already read.
	 * @param int $limit  Most redirects to return.
	 * @return list<array{source: string, target: string, code: int, regex: bool, active: bool}>
	 */
	public function redirects( int $offset, int $limit ): array {
		$all       = get_option( self::REDIRECT_OPTION );
		$redirects = array();

		foreach ( array_slice( is_array( $all ) ? array_values( $all ) : array(), $offset, $limit ) as $entry ) {
			$regex       = is_array( $entry ) && 'regex' === ( $entry['format'] ?? '' );
			$redirects[] = array(
				'source' => is_array( $entry ) && is_string( $entry['origin'] ?? null ) ? self::path( $entry['origin'], $regex ) : '',
				'target' => is_array( $entry ) && is_string( $entry['url'] ?? null ) ? self::path( $entry['url'] ) : '',
				'code'   => is_array( $entry ) && is_numeric( $entry['type'] ?? null ) ? (int) $entry['type'] : 301,
				'regex'  => $regex,
				'active' => true,
			);
		}

		return $redirects;
	}

	/**
	 * Pattern of a Yoast variable.
	 *
	 * @return string
	 */
	protected function variable_pattern(): string {
		return '/%%([^%\s]+(?: [^%\s]+)*)%%/';
	}

	/**
	 * Custom field of a `%%cf_name%%` variable.
	 *
	 * @param string $variable Variable name.
	 * @return string|null
	 */
	protected function custom_field( string $variable ): ?string {
		return str_starts_with( strtolower( $variable ), 'cf_' ) ? substr( $variable, 3 ) : null;
	}
}
