<?php
/**
 * Data left by Rank Math.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Rank Math data. Names checked against the Rank Math source
 * (https://github.com/rankmath/seo-by-rank-math, October 2026):
 *
 * - posts and terms: meta `rank_math_title`, `rank_math_description`, `rank_math_canonical_url`,
 *   `rank_math_facebook_title`, `rank_math_facebook_description`, `rank_math_facebook_image_id`
 *   (and `rank_math_facebook_image`, its URL), and `rank_math_robots`, a list such as
 *   array( 'noindex', 'nofollow' ) (includes/admin/importers/class-yoast.php);
 * - variables: `%name%`, custom fields as `%customfield(name)%` (includes/replace-variables/).
 */
final class RankMath extends Source {

	/**
	 * Name shown in the admin.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Rank Math';
	}

	/**
	 * Whether Rank Math has been used here: its list of modules outlives it.
	 *
	 * @return bool
	 */
	public function detected(): bool {
		return false !== get_option( 'rank_math_modules' );
	}

	/**
	 * Fields of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function post_fields( int $post_id ): array {
		return $this->fields( static fn( string $key ): mixed => get_post_meta( $post_id, 'rank_math_' . $key, true ) );
	}

	/**
	 * Fields of a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	public function term_fields( \WP_Term $term ): array {
		return $this->fields( static fn( string $key ): mixed => get_term_meta( $term->term_id, 'rank_math_' . $key, true ) );
	}

	/**
	 * Rank Math redirects live in a table of their own: not imported (see docs/decisioni.md).
	 *
	 * @param int $offset Redirects already read.
	 * @param int $limit  Most redirects to return.
	 * @return list<array{source: string, target: string, code: int, regex: bool, active: bool}>
	 */
	public function redirects( int $offset, int $limit ): array {
		return array();
	}

	/**
	 * Fields from the meta of a post or a term, which use the same keys.
	 *
	 * @param \Closure $get Reads a meta value by key, without the prefix: fn( string ): mixed.
	 * @return array<string, mixed>
	 */
	private function fields( \Closure $get ): array {
		$robots = $get( 'robots' );
		$robots = is_array( $robots ) ? $robots : array();

		return array(
			'title'          => $this->template( $get( 'title' ) ),
			'description'    => $this->template( $get( 'description' ) ),
			'canonical'      => $get( 'canonical_url' ),
			'noindex'        => in_array( 'noindex', $robots, true ),
			'nofollow'       => in_array( 'nofollow', $robots, true ),
			'og_title'       => $this->plain( $get( 'facebook_title' ) ),
			'og_description' => $this->plain( $get( 'facebook_description' ) ),
			'og_image'       => self::image( $get( 'facebook_image_id' ), $get( 'facebook_image' ) ),
		);
	}

	/**
	 * Pattern of a Rank Math variable, arguments included.
	 *
	 * @return string
	 */
	protected function variable_pattern(): string {
		return '/%([a-z0-9_]+(?:\([^()%]*\))?)%/i';
	}

	/**
	 * Custom field of a `%customfield(name)%` variable.
	 *
	 * @param string $variable Variable name with its arguments.
	 * @return string|null
	 */
	protected function custom_field( string $variable ): ?string {
		return 1 === preg_match( '/^customfield\(([^()]*)\)$/i', $variable, $matches ) ? trim( $matches[1] ) : null;
	}
}
