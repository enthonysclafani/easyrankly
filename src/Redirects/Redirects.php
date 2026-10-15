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
 * - post_excerpt: notes, shown only in the admin;
 * - meta: target, status code, regex flag, forced flag, query flag (pass the query string on).
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
	 * Most other redirects a target may lead through (loops()): browsers give up after 20.
	 */
	private const MAX_HOPS = 10;

	/**
	 * Meta keys, without prefix, with their schema type.
	 */
	public const META = array(
		'target' => 'string',
		'code'   => 'integer',
		'regex'  => 'boolean',
		'forced' => 'boolean',
		'query'  => 'boolean',
	);

	/**
	 * Whether a REST save of a redirect is under way: core saves the post before its meta,
	 * so the lists wait for `rest_after_insert` and are rebuilt once, with the new meta.
	 *
	 * @var bool
	 */
	private bool $rest_saving = false;

	/**
	 * Redirects created by the WordPress importer under way, checked when it ends.
	 *
	 * @var list<int>
	 */
	private array $imported = array();

	/**
	 * IDs saved by the batch under way (see batch()), null outside a batch: the lists are
	 * refreshed once, at the end.
	 *
	 * @var list<int>|null
	 */
	private static ?array $batch = null;

	/**
	 * Hooks registration, validation, imports, list rebuilds, the frontend runner and slug tracking.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'validate_rest' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'on_save' ) );
		// After clean_post_cache(), so the rebuild cannot read the deleted rule from a cached query.
		add_action( 'after_delete_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_status_change' ) );
		add_action( 'untrashed_post', array( $this, 'on_status_change' ) );
		add_filter( 'rest_' . self::POST_TYPE . '_trashable', '__return_false' );
		add_action( 'rest_after_insert_' . self::POST_TYPE, array( $this, 'after_rest_save' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'after_rest_request' ) );
		add_action( 'wp_import_insert_post', array( $this, 'on_import' ) );
		add_action( 'import_end', array( $this, 'check_imported' ) );

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
				'supports'              => array( 'title', 'excerpt', 'custom-fields' ),
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
						default   => array( Rule::class, 'sanitize_target' ),
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
		$this->rest_saving     = true;

		return $prepared;
	}

	/**
	 * Refreshes the lists when a redirect is saved outside REST, where the meta is already saved.
	 *
	 * @param mixed $post_id Saved redirect ID.
	 */
	public function on_save( $post_id ): void {
		if ( $this->rest_saving ) {
			return;
		}
		if ( null !== self::$batch ) {
			self::$batch[] = (int) $post_id;
			return;
		}

		self::refresh( array( (int) $post_id ) );
	}

	/**
	 * Refreshes the lists once a REST save has written the post and its meta.
	 *
	 * @param mixed $post Saved redirect.
	 */
	public function after_rest_save( $post ): void {
		$this->rest_saving = false;
		if ( $post instanceof \WP_Post ) {
			self::refresh( array( $post->ID ) );
		} else {
			self::rebuild_lists();
		}
	}

	/**
	 * Rebuilds the lists if a REST save stopped after validation, before `rest_after_insert`.
	 *
	 * @param mixed $response Response of the REST request.
	 * @return mixed
	 */
	public function after_rest_request( $response ) {
		if ( $this->rest_saving ) {
			$this->rest_saving = false;
			self::rebuild_lists();
		}

		return $response;
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

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! $regex ) {
			$other = self::find_id( Rule::hash( $valid['source'] ), array( 'publish', 'draft' ) );
			if ( null !== $other && $other !== $id ) {
				return new \WP_Error( 'easyrankly_redirect_duplicate', __( 'A redirect for this source already exists.', 'easyrankly' ) );
			}
		}

		$loop = self::loops( $valid['source'], $regex, $valid['target'], $id );
		if ( null !== $loop ) {
			return $loop;
		}

		return $valid;
	}

	/**
	 * Whether the active rules would send the target back to the source, so the visitor would
	 * bounce between them forever (A → B with B → A, B → C → A, two regex rules that send
	 * their addresses to each other), or through more than MAX_HOPS redirects.
	 *
	 * Follows the target through the active rules as a 404 would (forced, exact, regex).
	 * Inactive rules are checked again when someone activates them (through REST, so here).
	 * Rules saved earlier in the same batch (an import) count too, though the lists are only
	 * rebuilt at its end. A regex target with captures ($1) depends on the address it matched:
	 * it is followed with each capture set to sample values.
	 *
	 * @param string $source Normalized source, or the pattern of a regex.
	 * @param bool   $regex  Whether the source is a regex.
	 * @param string $target Target as stored.
	 * @param int    $id     ID of the redirect being updated (0 for a new one), left out of the chain.
	 * @return \WP_Error|null Why the rule cannot be saved, or null.
	 */
	private static function loops( string $source, bool $regex, string $target, int $id ): ?\WP_Error {
		$rule  = array(
			'source' => $source,
			'regex'  => $regex,
			'target' => $target,
		);
		$lists = array();
		foreach ( array( self::FORCED_OPTION, self::REGEX_OPTION ) as $option ) {
			$list    = get_option( $option, array() );
			$lists[] = is_array( $list ) ? $list : array();
		}
		foreach ( self::$batch ?? array() as $saved ) {
			$pending = self::rule( $saved );
			if ( null !== $pending && $saved !== $id && ( $pending['forced'] || $pending['regex'] ) && 'publish' === get_post_status( $saved ) ) {
				$lists[ $pending['forced'] ? 0 : 1 ][] = $pending;
			}
		}

		$starts = $regex && str_contains( $target, '$' )
			? array( (string) preg_replace( '/\$\d+/', 'x', $target ), (string) preg_replace( '/\$\d+/', '1', $target ) )
			: array( $target );

		foreach ( $starts as $next ) {
			for ( $hop = 0; ; $hop++ ) {
				if ( '' === $next || ! Rule::is_internal( Rule::target_url( $next ) ) ) {
					break;
				}

				$path = Rule::normalize( $next );
				if ( null !== Rule::match( $rule, $path ) ) {
					return new \WP_Error( 'easyrankly_redirect_loop', __( 'Other redirects already send the target back to this source.', 'easyrankly' ) );
				}

				$next = self::next( $path, $lists, $id );
				if ( null === $next ) {
					break;
				}

				if ( $hop >= self::MAX_HOPS ) {
					/* translators: %d: number of redirects. */
					return new \WP_Error( 'easyrankly_redirect_chain', sprintf( __( 'The target leads through more than %d other redirects.', 'easyrankly' ), self::MAX_HOPS ) );
				}
			}
		}

		return null;
	}

	/**
	 * Target of the active rule that answers a path on a 404, other than the one being saved.
	 *
	 * @param string                        $path  Normalized path.
	 * @param array<int, array<int, mixed>> $lists Forced and regex lists, as stored.
	 * @param int                           $id    ID of the redirect being saved.
	 * @return string|null
	 */
	private static function next( string $path, array $lists, int $id ): ?string {
		foreach ( array( $lists[0], null, $lists[1] ) as $list ) {
			if ( null === $list ) {
				$exact = self::find_id( Rule::hash( $path ) );
				$found = null !== $exact && $exact !== $id ? self::rule( $exact ) : null;
				if ( null !== $found ) {
					return $found['target'];
				}
				continue;
			}

			foreach ( $list as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['source'], $item['target'] ) || (int) ( $item['id'] ?? 0 ) === $id ) {
					continue;
				}
				$rule   = array(
					'source' => (string) $item['source'],
					'regex'  => ! empty( $item['regex'] ),
					'target' => (string) $item['target'],
				);
				$target = Rule::match( $rule, $path );
				// Runner::first_match() skips a regex whose target matches it again.
				if ( null !== $target && ( ! $rule['regex'] || null === Rule::match( $rule, Rule::normalize( $target ) ) ) ) {
					return $target;
				}
			}
		}

		return null;
	}

	/**
	 * Runs several saves outside REST and refreshes the lists once at the end, not after each save.
	 *
	 * A page moved with 30 children saves 31 redirects: rebuilding after each one read every
	 * forced and regex rule 31 times.
	 *
	 * @param callable $saves Work that saves redirects.
	 */
	public static function batch( callable $saves ): void {
		if ( null !== self::$batch ) {
			$saves();
			return;
		}

		self::$batch = array();
		try {
			$saves();
		} finally {
			$ids         = self::$batch;
			self::$batch = null;
			self::refresh( $ids );
		}
	}

	/**
	 * Creates or updates an active exact redirect outside REST (slug changes).
	 *
	 * Runs the same checks as REST (check()): a rule that already exists for the source
	 * is updated, one that would loop with an active rule is refused.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path or URL.
	 * @param int    $code   Status code.
	 * @return int|\WP_Error Redirect ID.
	 */
	public static function save_exact( string $source, string $target, int $code = 301 ) {
		$id    = (int) self::find_id( Rule::hash( Rule::normalize( $source ) ), array( 'publish', 'draft' ) );
		$valid = self::check( $source, $target, $code, false, $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return wp_insert_post(
			array(
				'ID'          => $id,
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $valid['source'],
				'post_name'   => Rule::hash( $valid['source'] ),
				'meta_input'  => array(
					self::meta_key( 'target' ) => $valid['target'],
					self::meta_key( 'code' )   => $code,
					self::meta_key( 'regex' )  => false,
					self::meta_key( 'forced' ) => false,
				),
			),
			true
		);
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
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * A redirect as a plain rule.
	 *
	 * @param int $id Redirect ID.
	 * @return array{id: int, source: string, target: string, code: int, regex: bool, forced: bool, query: bool}|null
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
			'query'  => (bool) get_post_meta( $id, self::meta_key( 'query' ), true ),
		);
	}

	/**
	 * After some redirects changed: rebuilds the lists only when one of them is or was in them,
	 * otherwise only invalidates the cached exact lookups.
	 *
	 * Most saves are plain exact rules (slug changes, the admin form), which no list holds:
	 * rebuilding would read every forced and regex rule for nothing.
	 *
	 * @param int[] $ids Changed redirect IDs.
	 */
	private static function refresh( array $ids ): void {
		if ( array() === $ids ) {
			wp_cache_set_last_changed( self::CACHE_GROUP );
			return;
		}

		foreach ( $ids as $id ) {
			$rule = self::rule( $id );
			if ( null !== $rule && ( $rule['forced'] || $rule['regex'] ) && 'publish' === get_post_status( $id ) ) {
				self::rebuild_lists();
				return;
			}
		}

		foreach ( array( self::FORCED_OPTION, self::REGEX_OPTION ) as $option ) {
			$list = get_option( $option, array() );
			foreach ( is_array( $list ) ? $list : array() as $entry ) {
				// An entry without its ID cannot be told apart: rebuild to be safe.
				if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || in_array( (int) $entry['id'], $ids, true ) ) {
					self::rebuild_lists();
					return;
				}
			}
		}

		wp_cache_set_last_changed( self::CACHE_GROUP );
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
				'id'     => $rule['id'],
				'source' => $rule['source'],
				'regex'  => $rule['regex'],
				'target' => $rule['target'],
				'code'   => $rule['code'],
				'query'  => $rule['query'],
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
	 * Remembers a redirect created by the WordPress importer: its meta comes after the post.
	 *
	 * @param mixed $post_id Imported post ID.
	 */
	public function on_import( $post_id ): void {
		if ( self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			$this->imported[] = (int) $post_id;
		}
	}

	/**
	 * When the import ends, runs the checks of the Redirects screen on every imported active
	 * rule and deactivates those that fail, as the screen would have refused them. Then the
	 * lists are rebuilt: the rules were saved before their meta, so forced and regex ones
	 * are not in them yet.
	 */
	public function check_imported(): void {
		if ( array() === $this->imported ) {
			return;
		}

		$ids            = $this->imported;
		$this->imported = array();

		self::batch(
			static function () use ( $ids ): void {
				foreach ( $ids as $id ) {
					$rule = self::rule( $id );
					if ( null === $rule || 'publish' !== get_post_status( $id ) ) {
						continue;
					}
					if ( is_wp_error( self::check( $rule['source'], $rule['target'], $rule['code'], $rule['regex'], $id ) ) ) {
						wp_update_post(
							array(
								'ID'          => $id,
								'post_status' => 'draft',
							)
						);
						continue;
					}
					// Accepted: the next rules are checked against it, as if it were in the lists.
					self::$batch[] = $id;
				}
			}
		);
		self::rebuild_lists();
	}

	/**
	 * Refreshes the lists when a redirect is deleted.
	 *
	 * @param mixed $post_id Deleted post ID.
	 * @param mixed $post    Deleted post.
	 */
	public function on_delete( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::refresh( array( $post->ID ) );
		}
	}

	/**
	 * Refreshes the lists when a redirect leaves or returns from the trash outside REST.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_status_change( $post_id ): void {
		if ( null === self::$batch && self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::refresh( array( (int) $post_id ) );
		}
	}

	/**
	 * Sources of the active exact forced rules: the addresses they send elsewhere even when
	 * the page exists. Regex rules are left out, as they may match any number of addresses.
	 *
	 * Read only from the autoloaded options: before the first redirect is saved the option does
	 * not exist, and get_option() would query for it.
	 *
	 * @return list<string> Normalized paths.
	 */
	public static function forced_sources(): array {
		$rules   = isset( wp_load_alloptions()[ self::FORCED_OPTION ] ) ? get_option( self::FORCED_OPTION, array() ) : array();
		$sources = array();
		foreach ( is_array( $rules ) ? $rules : array() as $rule ) {
			if ( is_array( $rule ) && empty( $rule['regex'] ) && isset( $rule['source'] ) && is_string( $rule['source'] ) ) {
				$sources[] = $rule['source'];
			}
		}

		return $sources;
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
