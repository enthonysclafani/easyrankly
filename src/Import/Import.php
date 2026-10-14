<?php
/**
 * Import from Yoast SEO and Rank Math.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Import;

use EasyRankly\Meta\Meta;
use EasyRankly\Redirects\Redirects;
use EasyRankly\Redirects\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the SEO fields of posts and terms, and the redirects, that another plugin left.
 *
 * The admin screen drives the work: each POST to `easyrankly/v1/import/{source}` handles one
 * batch and saves where it stopped in the `easyrankly_import` option, so a closed tab resumes
 * where it was. Stages: posts, terms, redirects.
 *
 * Nothing already set in EasyRankly is overwritten and nothing is deleted from the other plugin,
 * so running it again changes nothing. Settings and templates are not imported (decision of
 * 10 October 2026, docs/decisioni.md).
 */
final class Import {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'easyrankly/v1';

	/**
	 * Option with the progress of each source, not autoloaded.
	 */
	public const OPTION = 'easyrankly_import';

	/**
	 * Posts, terms or redirects handled by one request.
	 */
	public const BATCH = 50;

	/**
	 * Stages, in order.
	 */
	private const STAGES = array( 'posts', 'terms', 'redirects' );

	/**
	 * Hooks the route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Sources that can be imported, by slug.
	 *
	 * @return array<string, Source>
	 */
	public static function sources(): array {
		return array(
			'yoast'    => new Yoast(),
			'rankmath' => new RankMath(),
		);
	}

	/**
	 * Registers the route. Only administrators can import.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<source>[a-z]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_batch' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'source'  => array(
						'type'     => 'string',
						'enum'     => array_keys( self::sources() ),
						'required' => true,
					),
					'restart' => array(
						'description' => __( 'Start again from the first batch.', 'easyrankly' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);
	}

	/**
	 * Handles one batch and returns the progress.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$progress = self::run( (string) $request['source'], (bool) $request['restart'] );

		return new \WP_REST_Response( $progress + array( 'message' => self::message( $progress ) ) );
	}

	/**
	 * Progress of a source, as saved after its last batch.
	 *
	 * @param string $slug Source slug.
	 * @return array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int}|null Null before the first batch.
	 */
	public static function progress( string $slug ): ?array {
		$all   = get_option( self::OPTION );
		$saved = is_array( $all ) && isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : null;
		if ( null === $saved ) {
			return null;
		}

		$count = static fn( string $key ): int => isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ? absint( $saved[ $key ] ) : 0;

		return array(
			'stage'     => in_array( $saved['stage'] ?? null, array( ...self::STAGES, 'done' ), true ) ? (string) $saved['stage'] : 'posts',
			'offset'    => $count( 'offset' ),
			'posts'     => $count( 'posts' ),
			'terms'     => $count( 'terms' ),
			'redirects' => $count( 'redirects' ),
			'kept'      => $count( 'kept' ),
			'rejected'  => $count( 'rejected' ),
		);
	}

	/**
	 * What the import has done so far, for the admin screen.
	 *
	 * @param array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int} $progress Progress.
	 * @return string
	 */
	public static function message( array $progress ): string {
		$counts = sprintf(
			/* translators: 1: posts updated, 2: terms updated, 3: redirects added, 4: values kept, 5: redirects not accepted. */
			__( 'Posts updated: %1$d. Terms updated: %2$d. Redirects added: %3$d. Values already set in EasyRankly, kept: %4$d. Redirects not accepted: %5$d.', 'easyrankly' ),
			$progress['posts'],
			$progress['terms'],
			$progress['redirects'],
			$progress['kept'],
			$progress['rejected']
		);

		$stage = match ( $progress['stage'] ) {
			'posts' => __( 'Importing posts…', 'easyrankly' ),
			'terms' => __( 'Importing terms…', 'easyrankly' ),
			'redirects' => __( 'Importing redirects…', 'easyrankly' ),
			default => __( 'Import complete.', 'easyrankly' ),
		};

		return $stage . ' ' . $counts;
	}

	/**
	 * Handles the next batch of a source.
	 *
	 * @param string $slug    Source slug.
	 * @param bool   $restart Whether to start again from the first batch.
	 * @return array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int}
	 */
	public static function run( string $slug, bool $restart = false ): array {
		$source   = self::sources()[ $slug ];
		$progress = self::progress( $slug );
		$progress = $restart || null === $progress || 'done' === $progress['stage'] ? self::fresh() : $progress;

		$handled = match ( $progress['stage'] ) {
			'posts' => self::posts( $source, $progress ),
			'terms' => self::terms( $source, $progress ),
			default => self::redirects( $source, $progress ),
		};

		if ( $handled < self::BATCH ) {
			$next               = array_search( $progress['stage'], self::STAGES, true );
			$progress['stage']  = self::STAGES[ (int) $next + 1 ] ?? 'done';
			$progress['offset'] = 0;
		} else {
			$progress['offset'] += $handled;
		}

		$all          = get_option( self::OPTION );
		$all          = is_array( $all ) ? $all : array();
		$all[ $slug ] = $progress;
		update_option( self::OPTION, $all, false );

		return $progress;
	}

	/**
	 * Progress before the first batch.
	 *
	 * @return array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int}
	 */
	private static function fresh(): array {
		return array(
			'stage'     => 'posts',
			'offset'    => 0,
			'posts'     => 0,
			'terms'     => 0,
			'redirects' => 0,
			'kept'      => 0,
			'rejected'  => 0,
		);
	}

	/**
	 * Imports one batch of posts of the viewable post types, in ID order.
	 *
	 * @param Source                                                                                              $source   Source.
	 * @param array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int} $progress Progress, updated.
	 * @return int Posts read.
	 */
	private static function posts( Source $source, array &$progress ): int {
		$types = array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );
		$ids   = array() === $types ? array() : get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => 'any',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => self::BATCH,
				'offset'                 => $progress['offset'],
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$ids   = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {
			if ( self::write( 'post', $id, (string) get_post_type( $id ), $source->post_fields( $id ), $progress ) ) {
				++$progress['posts'];
			}
		}

		return count( $ids );
	}

	/**
	 * Imports one batch of terms of the viewable taxonomies, in ID order.
	 *
	 * @param Source                                                                                              $source   Source.
	 * @param array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int} $progress Progress, updated.
	 * @return int Terms read.
	 */
	private static function terms( Source $source, array &$progress ): int {
		$taxonomies = array_values( array_filter( get_taxonomies(), 'is_taxonomy_viewable' ) );
		$terms      = array() === $taxonomies ? array() : get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'number'     => self::BATCH,
				'offset'     => $progress['offset'],
			)
		);
		$terms      = is_array( $terms ) ? array_filter( $terms, static fn( $term ): bool => $term instanceof \WP_Term ) : array();

		foreach ( $terms as $term ) {
			if ( self::write( 'term', $term->term_id, $term->taxonomy, $source->term_fields( $term ), $progress ) ) {
				++$progress['terms'];
			}
		}

		return count( $terms );
	}

	/**
	 * Imports one batch of redirects. A source that already has a redirect is kept as it is;
	 * a redirect EasyRankly cannot accept (unsupported code, invalid regex, loop) is counted
	 * as rejected.
	 *
	 * @param Source                                                                                              $source   Source.
	 * @param array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int} $progress Progress, updated.
	 * @return int Redirects read.
	 */
	private static function redirects( Source $source, array &$progress ): int {
		$redirects = $source->redirects( $progress['offset'], self::BATCH );

		Redirects::batch(
			static function () use ( $redirects, &$progress ): void {
				foreach ( $redirects as $redirect ) {
					$valid = Redirects::check( $redirect['source'], $redirect['target'], $redirect['code'], $redirect['regex'] );
					if ( is_wp_error( $valid ) ) {
						++$progress[ 'easyrankly_redirect_duplicate' === $valid->get_error_code() ? 'kept' : 'rejected' ];
						continue;
					}

					$id = wp_insert_post(
						array(
							'post_type'   => Redirects::POST_TYPE,
							'post_status' => $redirect['active'] ? 'publish' : 'draft',
							'post_title'  => $valid['source'],
							'post_name'   => $redirect['regex'] ? 'regex-' . md5( $valid['source'] ) : Rule::hash( $valid['source'] ),
							'meta_input'  => array(
								Redirects::meta_key( 'target' ) => $valid['target'],
								Redirects::meta_key( 'code' )   => $redirect['code'],
								Redirects::meta_key( 'regex' )  => $redirect['regex'],
								Redirects::meta_key( 'forced' ) => false,
							),
						),
						true
					);

					++$progress[ is_wp_error( $id ) ? 'rejected' : 'redirects' ];
				}
			}
		);

		return count( $redirects );
	}

	/**
	 * Saves the imported fields of a post or term that EasyRankly does not have yet.
	 *
	 * @param string                                                                                              $type     "post" or "term".
	 * @param int                                                                                                 $id       Object ID.
	 * @param string                                                                                              $subtype  Post type or taxonomy, which the sanitize callbacks are registered for.
	 * @param array<string, mixed>                                                                                $fields   Imported values by field name.
	 * @param array{stage: string, offset: int, posts: int, terms: int, redirects: int, kept: int, rejected: int} $progress Progress, updated.
	 * @return bool Whether a field was saved.
	 */
	private static function write( string $type, int $id, string $subtype, array $fields, array &$progress ): bool {
		$saved = false;

		foreach ( Meta::fields() as $name => $field ) {
			$key   = Meta::PREFIX . $name;
			$value = sanitize_meta( $key, $fields[ $name ] ?? $field['default'], $type, $subtype );
			if ( $value === $field['default'] || '' === $value ) {
				continue;
			}

			if ( metadata_exists( $type, $id, $key ) ) {
				++$progress['kept'];
				continue;
			}

			$saved = false !== add_metadata( $type, $id, $key, $value, true ) || $saved;
		}

		return $saved;
	}
}
