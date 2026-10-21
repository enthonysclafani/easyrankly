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
 * - variables: `%name%`, custom fields as `%customfield(name)%` (includes/replace-variables/);
 * - redirects: table `{prefix}rank_math_redirections` (includes/modules/redirections/class-db.php),
 *   with `sources` (a serialized list of `pattern` and `comparison`: exact, contains, start, end
 *   or regex), `url_to`, `header_code` and `status` (active, inactive or trashed).
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
	 * One page of the redirects, one list per row: a Rank Math redirect can have several sources.
	 *
	 * The redirects are in a table of Rank Math, which has no API once Rank Math is inactive: this
	 * is the only place where EasyRankly reads the database directly. Read only, from the import
	 * that an administrator runs.
	 *
	 * @param int $offset Rows already read.
	 * @param int $limit  Most rows to return.
	 * @return list<list<array{source: string, target: string, code: int, regex: bool, active: bool}>>
	 */
	public function redirects( int $offset, int $limit ): array {
		global $wpdb;

		// No table (Rank Math never had its redirects on) is an error to keep quiet, not to print.
		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table of another plugin, read once per import batch.
			$wpdb->prepare(
				"SELECT sources, url_to, header_code, status FROM %i WHERE status <> 'trashed' ORDER BY id ASC LIMIT %d OFFSET %d",
				$wpdb->prefix . 'rank_math_redirections',
				$limit,
				$offset
			),
			ARRAY_A
		);
		$wpdb->suppress_errors( $suppress );

		$redirects = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$target = is_string( $row['url_to'] ?? null ) ? self::path( $row['url_to'] ) : '';
			$code   = is_numeric( $row['header_code'] ?? null ) ? (int) $row['header_code'] : 301;
			$active = 'active' === ( $row['status'] ?? '' );
			$group  = array();

			foreach ( self::sources( $row['sources'] ?? null ) as $source ) {
				$group[] = array(
					'source' => $source['source'],
					'target' => $target,
					'code'   => $code,
					'regex'  => $source['regex'],
					'active' => $active,
				);
			}

			$redirects[] = $group;
		}

		return $redirects;
	}

	/**
	 * Sources of a redirect row, as EasyRankly paths or regexes.
	 *
	 * The column is serialized by Rank Math; a value that holds an object is not read, so nothing
	 * but arrays and strings is ever unserialized. The check runs on the trimmed value, as
	 * maybe_unserialize() reads it.
	 *
	 * @param mixed $raw Value of the `sources` column.
	 * @return list<array{source: string, regex: bool}>
	 */
	private static function sources( mixed $raw ): array {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( ! is_serialized( $raw ) || 1 === preg_match( '/(?:^|[;{])[OC]:\d+:/', $raw ) ) {
			return array();
		}

		$entries = maybe_unserialize( $raw );
		$sources = array();

		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$pattern = is_array( $entry ) && is_string( $entry['pattern'] ?? null ) ? trim( $entry['pattern'] ) : '';
			if ( '' === $pattern ) {
				continue;
			}

			$sources[] = match ( $entry['comparison'] ?? 'exact' ) {
				'regex' => array(
					'source' => self::path( $pattern, true ),
					'regex'  => true,
				),
				'contains' => array(
					'source' => preg_quote( $pattern, '#' ),
					'regex'  => true,
				),
				'start' => array(
					'source' => '^/' . preg_quote( ltrim( $pattern, '/' ), '#' ),
					'regex'  => true,
				),
				'end' => array(
					'source' => preg_quote( rtrim( $pattern, '/' ), '#' ) . '/?$',
					'regex'  => true,
				),
				default => array(
					'source' => self::path( $pattern ),
					'regex'  => false,
				),
			};
		}

		return $sources;
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
