<?php
/**
 * Redirects stored as a non-public post type.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Redirects;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `erankly_redirect` post type, validates rules on save and keeps the
 * small lists the frontend reads.
 *
 * One post per rule:
 * - post_title: source (normalized path, or regex);
 * - post_name: md5 of the normalized source for exact rules (indexed lookup), "regex-…" otherwise;
 * - post_status: publish = active, draft = inactive;
 * - meta: target, status code, regex flag, forced flag.
 *
 * Exact rules are looked up only on 404. Regex rules live in a non-autoloaded option
 * read only on 404; "forced" rules (applied even when the page exists) live in a small
 * autoloaded option. Both are rebuilt whenever a redirect changes.
 */
final class Redirects {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'erankly_redirect';

	/**
	 * Autoloaded option with the forced rules.
	 */
	public const FORCED_OPTION = 'easyrankly_redirects_forced';

	/**
	 * Non-autoloaded option with the regex rules, read only on 404.
	 */
	public const REGEX_OPTION = 'easyrankly_redirects_regex';

	/**
	 * Cache group for exact lookups, invalidated with its last-changed key.
	 */
	public const CACHE_GROUP = 'easyrankly_redirects';

	/**
	 * Most forced rules allowed: they are read on every request.
	 */
	public const MAX_FORCED = 50;

	/**
	 * Meta keys, without prefix, with their schema type.
	 */
	public const META = array(
		'target' => 'string',
		'code'   => 'integer',
		'regex'  => 'boolean',
		'forced' => 'boolean',
	);

	/**
	 * Hooks registration, validation, list rebuilds, the frontend runner and slug tracking.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'validate_rest' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'rebuild_lists' ) );
		add_action( 'deleted_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_status_change' ) );
		add_action( 'untrashed_post', array( $this, 'on_status_change' ) );
		add_filter( 'rest_' . self::POST_TYPE . '_trashable', '__return_false' );
		add_action( 'rest_after_insert_' . self::POST_TYPE, array( self::class, 'rebuild_lists' ) );

		( new Runner() )->register();
		( new SlugChanges() )->register();
	}

	/**
	 * Registers the post type and its meta. Only administrators can see or change redirects.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'          => __( 'Redirects', 'easyrankly' ),
					'singular_name' => __( 'Redirect', 'easyrankly' ),
				),
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rest_base'             => 'easyrankly-redirects',
				'rest_controller_class' => RestController::class,
				'supports'              => array( 'title', 'custom-fields' ),
				'rewrite'               => false,
				'query_var'             => false,
				'can_export'            => true,
				'delete_with_user'      => false,
				'map_meta_cap'          => false,
				'capabilities'          => array_fill_keys(
					array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ),
					'manage_options'
				),
			)
		);

		foreach ( self::META as $name => $type ) {
			register_post_meta(
				self::POST_TYPE,
				self::meta_key( $name ),
				array(
					'type'              => $type,
					'single'            => true,
					'default'           => match ( $type ) {
						'integer' => 301,
						'boolean' => false,
						default   => '',
					},
					'show_in_rest'      => true,
					'sanitize_callback' => match ( $type ) {
						'integer' => 'absint',
						'boolean' => 'rest_sanitize_boolean',
						default   => 'sanitize_text_field',
					},
					'auth_callback'     => static fn(): bool => current_user_can( 'manage_options' ),
				)
			);
		}
	}

	/**
	 * Full meta key of a redirect field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function meta_key( string $name ): string {
		return '_easyrankly_redirect_' . $name;
	}

	/**
	 * Validates a redirect sent to the REST API and stores the normalized source and its hash.
	 *
	 * @param mixed $prepared Post about to be inserted (stdClass).
	 * @param mixed $request  REST request.
	 * @return mixed The post, or WP_Error with status 400.
	 */
	public function validate_rest( $prepared, $request ) {
		if ( ! $prepared instanceof \stdClass || ! $request instanceof \WP_REST_Request ) {
			return $prepared;
		}

		$id       = isset( $prepared->ID ) ? (int) $prepared->ID : 0;
		$meta     = is_array( $request['meta'] ) ? $request['meta'] : array();
		$existing = $id > 0 ? self::rule( $id ) : null;

		$source = isset( $prepared->post_title ) ? (string) $prepared->post_title : ( $existing['source'] ?? '' );
		$target = $meta[ self::meta_key( 'target' ) ] ?? $existing['target'] ?? '';
		$target = is_scalar( $target ) ? (string) $target : '';
		$code   = $meta[ self::meta_key( 'code' ) ] ?? $existing['code'] ?? 301;
		$code   = is_numeric( $code ) ? (int) $code : 0;
		$regex  = self::flag( $meta[ self::meta_key( 'regex' ) ] ?? $existing['regex'] ?? false );
		$forced = self::flag( $meta[ self::meta_key( 'forced' ) ] ?? $existing['forced'] ?? false );

		$valid = self::check( $source, $target, $code, $regex, $id );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}

		$status = isset( $prepared->post_status ) ? (string) $prepared->post_status : ( $id > 0 ? (string) get_post_status( $id ) : 'publish' );
		if ( $forced && 'publish' === $status && self::forced_count( $id ) >= self::MAX_FORCED ) {
			/* translators: %d: maximum number of forced redirects. */
			return new \WP_Error( 'easyrankly_redirect_forced', sprintf( __( 'At most %d redirects can be forced.', 'easyrankly' ), self::MAX_FORCED ), array( 'status' => 400 ) );
		}

		// Store the target exactly as validated, not as merely sanitized.
		if ( array_key_exists( self::meta_key( 'target' ), $meta ) ) {
			$meta[ self::meta_key( 'target' ) ] = $valid['target'];
			$request->set_param( 'meta', $meta );
		}

		$prepared->post_title  = $valid['source'];
		$prepared->post_name   = $regex ? 'regex-' . md5( $valid['source'] ) : Rule::hash( $valid['source'] );
		$prepared->post_status = $status;

		return $prepared;
	}

	/**
	 * A boolean flag from REST input, as rest_sanitize_boolean() reads it.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function flag( $value ): bool {
		return is_bool( $value ) ? $value : rest_sanitize_boolean( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Validates a rule and rejects a second exact rule for the same source.
	 *
	 * @param string $source Source as typed.
	 * @param string $target Target as typed.
	 * @param int    $code   Status code.
	 * @param bool   $regex  Whether the source is a regex.
	 * @param int    $id     ID of the redirect being updated (0 for a new one).
	 * @return array{source: string, target: string}|\WP_Error
	 */
	public static function check( string $source, string $target, int $code, bool $regex, int $id = 0 ) {
		$valid = Rule::validate( $source, $target, $code, $regex );

		if ( is_wp_error( $valid ) || $regex ) {
			return $valid;
		}

		$other = self::find_id( Rule::hash( $valid['source'] ), array( 'publish', 'draft' ) );
		if ( null !== $other && $other !== $id ) {
			return new \WP_Error( 'easyrankly_redirect_duplicate', __( 'A redirect for this source already exists.', 'easyrankly' ) );
		}

		// A → B while B → A exists would bounce the visitor between the two forever.
		if ( '' !== $valid['target'] && Rule::is_internal( Rule::target_url( $valid['target'] ) ) ) {
			$back = self::find_id( Rule::hash( Rule::normalize( $valid['target'] ) ), array( 'publish', 'draft' ) );
			$rule = null !== $back && $back !== $id ? self::rule( $back ) : null;
			if ( null !== $rule && '' !== $rule['target'] && Rule::is_internal( Rule::target_url( $rule['target'] ) ) && Rule::normalize( $rule['target'] ) === $valid['source'] ) {
				return new \WP_Error( 'easyrankly_redirect_loop', __( 'Another redirect already sends the target back to this source.', 'easyrankly' ) );
			}
		}

		return $valid;
	}

	/**
	 * Creates or updates an exact redirect outside REST (slug changes). Validates like REST.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path or URL.
	 * @param int    $code   Status code.
	 * @return int|\WP_Error Redirect ID.
	 */
	public static function save_exact( string $source, string $target, int $code = 301 ) {
		$existing = Rule::validate( $source, $target, $code, false );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$id = (int) self::find_id( Rule::hash( $existing['source'] ), array( 'publish', 'draft' ) );

		$id = wp_insert_post(
			array(
				'ID'          => $id,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $existing['source'],
				'post_name'   => Rule::hash( $existing['source'] ),
				'meta_input'  => array(
					self::meta_key( 'target' ) => $existing['target'],
					self::meta_key( 'code' )   => $code,
					self::meta_key( 'regex' )  => false,
					self::meta_key( 'forced' ) => false,
				),
			),
			true
		);

		return $id;
	}

	/**
	 * ID of the redirect with this post_name, if any.
	 *
	 * @param string   $name     post_name (hash).
	 * @param string[] $statuses Statuses to search.
	 * @return int|null
	 */
	public static function find_id( string $name, array $statuses = array( 'publish' ) ): ?int {
		$ids = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'name'                   => $name,
				'post_status'            => $statuses,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * A redirect as a plain rule.
	 *
	 * @param int $id Redirect ID.
	 * @return array{id: int, source: string, target: string, code: int, regex: bool, forced: bool}|null
	 */
	public static function rule( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return array(
			'id'     => $post->ID,
			'source' => $post->post_title,
			'target' => (string) get_post_meta( $id, self::meta_key( 'target' ), true ),
			'code'   => (int) get_post_meta( $id, self::meta_key( 'code' ), true ),
			'regex'  => (bool) get_post_meta( $id, self::meta_key( 'regex' ), true ),
			'forced' => (bool) get_post_meta( $id, self::meta_key( 'forced' ), true ),
		);
	}

	/**
	 * Rebuilds the forced and regex lists and invalidates cached exact lookups.
	 *
	 * Runs in admin or REST when a redirect changes, never on the frontend.
	 */
	public static function rebuild_lists(): void {
		$forced = array();
		$regex  = array();

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only rebuild when a redirect is saved.
					'relation' => 'OR',
					array(
						'key'   => self::meta_key( 'regex' ),
						'value' => '1',
					),
					array(
						'key'   => self::meta_key( 'forced' ),
						'value' => '1',
					),
				),
			)
		);

		foreach ( (array) $query->posts as $post ) {
			$rule = $post instanceof \WP_Post ? self::rule( $post->ID ) : null;
			if ( null === $rule ) {
				continue;
			}

			$compact = array(
				'source' => $rule['source'],
				'regex'  => $rule['regex'],
				'target' => $rule['target'],
				'code'   => $rule['code'],
			);

			if ( $rule['forced'] ) {
				$forced[] = $compact;
			} else {
				$regex[] = $compact;
			}
		}

		update_option( self::FORCED_OPTION, $forced, true );
		update_option( self::REGEX_OPTION, $regex, false );
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Rebuilds the lists when a redirect is deleted.
	 *
	 * @param mixed $post_id Deleted post ID.
	 * @param mixed $post    Deleted post.
	 */
	public function on_delete( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::rebuild_lists();
		}
	}

	/**
	 * Rebuilds the lists when a redirect leaves or returns from the trash outside REST.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_status_change( $post_id ): void {
		if ( self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::rebuild_lists();
		}
	}

	/**
	 * Number of active forced redirects other than the given one.
	 *
	 * @param int $exclude Redirect ID to leave out.
	 * @return int
	 */
	private static function forced_count( int $exclude ): int {
		$forced = get_option( self::FORCED_OPTION, array() );
		$count  = is_array( $forced ) ? count( $forced ) : 0;
		$rule   = $exclude > 0 ? self::rule( $exclude ) : null;

		if ( null !== $rule && $rule['forced'] && 'publish' === get_post_status( $exclude ) ) {
			--$count;
		}

		return $count;
	}
}
