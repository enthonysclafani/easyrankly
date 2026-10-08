<?php
/**
 * Custom code snippets stored as a non-public post type.
 *
 * @package EasyRankly
 */

namespace EasyRankly\CustomCode;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `erankly_snippet`, enforces who may write HTML and PHP, validates PHP
 * without running it and keeps the autoloaded cache the frontend reads.
 *
 * One post per snippet: post_title = name, post_content = code (native revisions keep
 * its history), post_status publish = active, draft = inactive; meta: type, position,
 * priority and the last runtime error.
 */
final class CustomCode {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'erankly_snippet';

	/**
	 * Autoloaded option with the active snippets per position and the snippets disabled by an error.
	 */
	public const OPTION = 'easyrankly_snippets';

	/**
	 * Snippet types.
	 */
	public const TYPES = array( 'html', 'php' );

	/**
	 * Frontend positions, as hook names.
	 */
	public const POSITIONS = array(
		'head'      => 'wp_head',
		'body_open' => 'wp_body_open',
		'footer'    => 'wp_footer',
	);

	/**
	 * Per-snippet meta capabilities of the post type.
	 */
	private const META_CAPS = array(
		'edit_post'   => 'edit_erankly_snippet',
		'read_post'   => 'read_erankly_snippet',
		'delete_post' => 'delete_erankly_snippet',
	);

	/**
	 * Hooks registration, permissions, validation, cache rebuilds, imports, notices and the runner.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'validate_rest' ), 10, 2 );
		add_action( 'rest_after_insert_' . self::POST_TYPE, array( $this, 'after_rest_save' ), 10, 3 );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'rebuild_cache' ) );
		// After the post cache is cleaned, so the rebuild query does not see the deleted snippet.
		add_action( 'after_delete_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'on_change' ) );
		add_action( 'untrashed_post', array( $this, 'on_change' ) );
		add_filter( 'rest_' . self::POST_TYPE . '_trashable', '__return_false' );
		add_filter( 'wp_import_post_data_processed', array( $this, 'import_inactive' ) );
		add_filter( 'add_post_metadata', array( $this, 'guard_type' ), 10, 4 );
		add_filter( 'update_post_metadata', array( $this, 'guard_type' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'error_notice' ) );

		( new Runner() )->register();
	}

	/**
	 * Registers the post type and its meta.
	 *
	 * Every capability maps to `manage_options`; each snippet also needs `unfiltered_html`
	 * and, for PHP, `edit_plugins` (see map_meta_cap()), so DISALLOW_FILE_EDIT locks PHP.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'          => __( 'Custom code', 'easyrankly' ),
					'singular_name' => __( 'Snippet', 'easyrankly' ),
				),
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rest_base'             => 'easyrankly-snippets',
				'rest_controller_class' => RestController::class,
				'supports'              => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'rewrite'               => false,
				'query_var'             => false,
				'can_export'            => true,
				'delete_with_user'      => false,
				'map_meta_cap'          => false,
				// Per-snippet capabilities get their own names, resolved in map_meta_cap().
				'capabilities'          => self::META_CAPS + array_fill_keys(
					array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ),
					'manage_options'
				),
			)
		);

		$meta = array(
			'type'     => array( 'string', 'html' ),
			'position' => array( 'string', 'head' ),
			'priority' => array( 'integer', 10 ),
			'error'    => array( 'string', '' ),
		);

		foreach ( $meta as $name => [ $type, $default ] ) {
			register_post_meta(
				self::POST_TYPE,
				self::meta_key( $name ),
				array(
					'type'              => $type,
					'single'            => true,
					'default'           => $default,
					'show_in_rest'      => array(
						'schema' => match ( $name ) {
							'type'     => array( 'enum' => self::TYPES ),
							'position' => array( 'enum' => array_keys( self::POSITIONS ) ),
							'priority' => array(
								'minimum' => 0,
								'maximum' => 1000,
							),
							default    => array( 'readonly' => true ),
						},
					),
					'sanitize_callback' => match ( $type ) {
						'integer' => 'absint',
						default   => 'sanitize_text_field',
					},
					'auth_callback'     => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/**
	 * Full meta key of a snippet field.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function meta_key( string $name ): string {
		return '_easyrankly_snippet_' . $name;
	}

	/**
	 * Adds the real checks behind the capabilities of a snippet.
	 *
	 * Every snippet needs `manage_options` and `unfiltered_html`, mapped like core maps
	 * it (DISALLOW_UNFILTERED_HTML, multisite rules). Writing or deleting a PHP snippet also
	 * needs `edit_plugins`, mapped the same way (DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS).
	 *
	 * @param mixed $caps    Primitive capabilities.
	 * @param mixed $cap     Requested capability.
	 * @param mixed $user_id User ID.
	 * @param mixed $args    Arguments; the post ID first.
	 * @return mixed
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::META_CAPS, true ) ) {
			return $caps;
		}

		$post_id = is_array( $args ) && isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 0;
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return array( 'do_not_allow' );
		}

		$caps = array_merge( map_meta_cap( 'manage_options', (int) $user_id ), map_meta_cap( 'unfiltered_html', (int) $user_id ) );

		// Core resolves read_post without this filter for types with map_meta_cap off: reads of
		// snippets go through RestController, which asks for the edit capability instead.
		if ( 'php' === self::type( $post_id ) ) {
			$caps = array_merge( $caps, map_meta_cap( 'edit_plugins', (int) $user_id ) );
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Whether the current user may manage snippets at all.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Whether the current user may write PHP snippets.
	 *
	 * @return bool
	 */
	public static function can_write_php(): bool {
		return self::can_manage() && current_user_can( 'edit_plugins' );
	}

	/**
	 * Whether PHP snippets may run on this site: never when file editing is disabled.
	 *
	 * @return bool
	 */
	public static function php_allowed(): bool {
		// Same checks core makes before granting edit_plugins (see map_meta_cap()).
		return ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) && wp_is_file_mod_allowed( 'capability_edit_themes' );
	}

	/**
	 * Whether safe mode is on (only through the constant, never from a request).
	 *
	 * @return bool
	 */
	public static function safe_mode(): bool {
		return defined( 'EASYRANKLY_SAFE_MODE' ) && EASYRANKLY_SAFE_MODE;
	}

	/**
	 * Type of a snippet.
	 *
	 * @param int $id Snippet ID.
	 * @return string
	 */
	public static function type( int $id ): string {
		return 'php' === get_post_meta( $id, self::meta_key( 'type' ), true ) ? 'php' : 'html';
	}

	/**
	 * PHP syntax error of a snippet, without running it.
	 *
	 * @param string $code Code without the opening tag.
	 * @return string|null Error message with its line, or null when the code parses.
	 */
	public static function syntax_error( string $code ): ?string {
		try {
			// Only the ParseError matters: the tokens are discarded.
			$tokens = token_get_all( "<?php\n" . $code, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			/* translators: 1: PHP error message, 2: line number. */
			return sprintf( __( '%1$s on line %2$d', 'easyrankly' ), $error->getMessage(), max( 1, $error->getLine() - 1 ) );
		}

		return null;
	}

	/**
	 * Code without a leading `<?php` tag, which the snippet does not need.
	 *
	 * @param string $code Code as typed.
	 * @return string
	 */
	public static function strip_open_tag( string $code ): string {
		return (string) preg_replace( '/^\s*<\?php\b/i', '', $code );
	}

	/**
	 * Checks a snippet sent to the REST API before it is saved.
	 *
	 * PHP needs edit_plugins; code that does not parse can be saved only as inactive.
	 *
	 * @param mixed $prepared Post about to be inserted (stdClass).
	 * @param mixed $request  REST request.
	 * @return mixed The post, or WP_Error.
	 */
	public function validate_rest( $prepared, $request ) {
		if ( ! $prepared instanceof \stdClass || ! $request instanceof \WP_REST_Request ) {
			return $prepared;
		}

		$id   = isset( $prepared->ID ) ? (int) $prepared->ID : 0;
		$meta = is_array( $request['meta'] ) ? $request['meta'] : array();
		$type = $meta[ self::meta_key( 'type' ) ] ?? ( $id > 0 ? self::type( $id ) : 'html' );
		$type = 'php' === $type ? 'php' : 'html';

		if ( ! self::can_manage() ) {
			return new \WP_Error( 'easyrankly_snippet_html', __( 'Snippets need permission to publish unfiltered HTML.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( 'php' === $type && ! self::can_write_php() ) {
			return new \WP_Error( 'easyrankly_snippet_php', __( 'PHP snippets need permission to edit plugins, and file editing must be enabled.', 'easyrankly' ), array( 'status' => rest_authorization_required_code() ) );
		}

		// Changing an existing snippet to another type is not allowed: it would bypass the PHP check above.
		if ( $id > 0 && self::type( $id ) !== $type ) {
			return new \WP_Error( 'easyrankly_snippet_type', __( 'The type of a snippet cannot change.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		$status = isset( $prepared->post_status ) ? (string) $prepared->post_status : ( $id > 0 ? (string) get_post_status( $id ) : 'draft' );
		$code   = isset( $prepared->post_content ) ? (string) $prepared->post_content : ( $id > 0 ? (string) get_post_field( 'post_content', $id, 'raw' ) : '' );

		if ( 'php' === $type ) {
			$code  = self::strip_open_tag( $code );
			$error = self::syntax_error( $code );
			if ( null !== $error && 'publish' === $status ) {
				/* translators: %s: PHP syntax error. */
				return new \WP_Error( 'easyrankly_snippet_syntax', sprintf( __( 'The code has a syntax error and cannot be active: %s', 'easyrankly' ), $error ), array( 'status' => 400 ) );
			}
			if ( isset( $prepared->post_content ) ) {
				$prepared->post_content = $code;
			}
		}

		$prepared->post_status = 'publish' === $status ? 'publish' : 'draft';

		return $prepared;
	}

	/**
	 * Clears the last error once an administrator saves the snippet again, keeps the first
	 * version in the revisions (core saves one only from the first update), then rebuilds the cache.
	 *
	 * @param mixed $post     Saved post.
	 * @param mixed $request  REST request.
	 * @param mixed $creating Whether the snippet was just created.
	 */
	public function after_rest_save( $post, $request = null, $creating = false ): void {
		if ( $post instanceof \WP_Post ) {
			delete_post_meta( $post->ID, self::meta_key( 'error' ) );
			if ( true === $creating ) {
				wp_save_post_revision( $post->ID );
			}
		}
		self::rebuild_cache();
	}

	/**
	 * Rebuilds the cache when a snippet is deleted.
	 *
	 * @param mixed $post_id Deleted post ID.
	 * @param mixed $post    Deleted post.
	 */
	public function on_delete( $post_id, $post ): void {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::rebuild_cache();
		}
	}

	/**
	 * Rebuilds the cache when a snippet is trashed or restored.
	 *
	 * @param mixed $post_id Post ID.
	 */
	public function on_change( $post_id ): void {
		if ( self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::rebuild_cache();
		}
	}

	/**
	 * Keeps the snippet type safe on every write path (REST, XML-RPC, importers, code).
	 *
	 * The type never changes once set, and only users who may write PHP snippets can set
	 * it to `php`: REST checks the same in validate_rest(), the other paths only here.
	 *
	 * @param mixed $check   Null to go on with the write.
	 * @param mixed $post_id Post ID.
	 * @param mixed $key     Meta key.
	 * @param mixed $value   New value.
	 * @return mixed False to refuse the write.
	 */
	public function guard_type( $check, $post_id, $key, $value ) {
		if ( self::meta_key( 'type' ) !== $key || self::POST_TYPE !== get_post_type( (int) $post_id ) ) {
			return $check;
		}

		// The registered default ("html") would hide a missing value: read the stored one.
		$stored  = get_metadata_raw( 'post', (int) $post_id, self::meta_key( 'type' ), true );
		$current = is_string( $stored ) ? $stored : '';
		if ( ! in_array( $value, self::TYPES, true ) || ( '' !== $current && $current !== $value ) ) {
			return false;
		}

		return 'php' === $value && ! self::can_write_php() ? false : $check;
	}

	/**
	 * Rebuilds the cache when a snippet's meta changes outside REST.
	 *
	 * @param mixed $meta_id Meta ID.
	 * @param mixed $post_id Post ID.
	 * @param mixed $key     Meta key.
	 */
	public function on_meta_change( $meta_id, $post_id, $key ): void {
		if ( is_string( $key ) && str_starts_with( $key, '_easyrankly_snippet_' ) && self::meta_key( 'error' ) !== $key && self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::rebuild_cache();
		}
	}

	/**
	 * Imported snippets always arrive inactive.
	 *
	 * @param mixed $postdata Post data about to be inserted by the WordPress importer.
	 * @return mixed
	 */
	public function import_inactive( $postdata ) {
		if ( is_array( $postdata ) && self::POST_TYPE === ( $postdata['post_type'] ?? '' ) ) {
			$postdata['post_status'] = 'draft';
		}

		return $postdata;
	}

	/**
	 * Rebuilds the autoloaded cache: active snippets per position, sorted by priority, and the
	 * snippets an error disabled.
	 */
	public static function rebuild_cache(): void {
		$cache = array(
			'positions' => array_fill_keys( array_keys( self::POSITIONS ), array() ),
			'errors'    => array(),
		);

		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $post ) {
			$error = (string) get_post_meta( $post->ID, self::meta_key( 'error' ), true );
			if ( '' !== $error ) {
				$cache['errors'][ $post->ID ] = $post->post_title;
			}

			$position = (string) get_post_meta( $post->ID, self::meta_key( 'position' ), true );
			if ( 'publish' !== $post->post_status || ! isset( self::POSITIONS[ $position ] ) ) {
				continue;
			}

			// REST refuses active PHP with syntax errors; other write paths do not, so never cache it.
			if ( 'php' === self::type( $post->ID ) && null !== self::syntax_error( $post->post_content ) ) {
				continue;
			}

			$cache['positions'][ $position ][] = array(
				'id'       => $post->ID,
				'type'     => self::type( $post->ID ),
				'priority' => (int) get_post_meta( $post->ID, self::meta_key( 'priority' ), true ),
				'code'     => $post->post_content,
			);
		}

		foreach ( $cache['positions'] as &$snippets ) {
			usort( $snippets, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		}
		unset( $snippets );

		update_option( self::OPTION, $cache, true );
	}

	/**
	 * Turns a snippet off after a runtime error and records the error.
	 *
	 * Runs on the frontend, usually for a visitor without unfiltered_html: the content
	 * filters are lifted for the update so the code is not stripped.
	 *
	 * @param int    $id      Snippet ID.
	 * @param string $message Error message.
	 */
	public static function disable( int $id, string $message ): void {
		update_post_meta( $id, self::meta_key( 'error' ), sanitize_text_field( $message ) );

		kses_remove_filters();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		kses_init();

		self::rebuild_cache();
	}

	/**
	 * Tells administrators which snippets an error turned off.
	 */
	public function error_notice(): void {
		$cache = get_option( self::OPTION );
		if ( ! is_array( $cache ) || empty( $cache['errors'] ) || ! self::can_manage() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%1$s %2$s</p></div>',
			esc_html__( 'EasyRankly turned off these snippets after an error:', 'easyrankly' ),
			esc_html( implode( ', ', array_map( 'strval', (array) $cache['errors'] ) ) )
		);
	}
}
