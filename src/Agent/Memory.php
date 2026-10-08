<?php
/**
 * Project memory: facts the agent keeps in mind.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the project memory as the non-public post type `erankly_memory`: one fact per post,
 * in Markdown. Native revisions keep its history; import and export use one `.md` file.
 *
 * - post_title: short name of the fact; post_content: the fact, in Markdown;
 * - post_status: publish = the agent reads it, draft = kept but ignored.
 *
 * Rejections with a reason and edited proposals become entries too, visible and editable
 * like the others: this is how the agent learns, through its context, not by retraining.
 */
final class Memory {

	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'erankly_memory';

	/**
	 * Longest fact, in characters: the memory goes into every prompt.
	 */
	public const MAX_LENGTH = 5000;

	/**
	 * Most entries handed to the agent, most recently changed first.
	 */
	public const CONTEXT_ENTRIES = 50;

	/**
	 * Most entries created by one import request; a longer file is imported in several requests.
	 */
	public const IMPORT_BATCH = 100;

	/**
	 * Hooks the registration and the REST validation.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_insert_' . self::POST_TYPE, array( $this, 'validate_rest' ) );
	}

	/**
	 * Registers the post type. Only administrators can read or change the memory.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'          => __( 'Agent memory', 'easyrankly' ),
					'singular_name' => __( 'Memory entry', 'easyrankly' ),
				),
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => true,
				'rest_base'             => 'easyrankly-memory',
				'rest_controller_class' => MemoryController::class,
				'supports'              => array( 'title', 'editor', 'revisions' ),
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
	}

	/**
	 * Requires a name and keeps facts short, for entries saved through REST.
	 *
	 * @param mixed $prepared Post about to be inserted (stdClass).
	 * @return mixed The post, or WP_Error with status 400.
	 */
	public function validate_rest( $prepared ) {
		if ( ! $prepared instanceof \stdClass ) {
			return $prepared;
		}

		$id      = isset( $prepared->ID ) ? (int) $prepared->ID : 0;
		$current = $id > 0 ? get_post( $id ) : null;
		$title   = isset( $prepared->post_title ) ? (string) $prepared->post_title : ( $current->post_title ?? '' );
		$content = isset( $prepared->post_content ) ? (string) $prepared->post_content : ( $current->post_content ?? '' );

		$valid = self::check( $title, $content );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}

		return $prepared;
	}

	/**
	 * Checks a fact before it is stored.
	 *
	 * @param string $title   Name.
	 * @param string $content Fact.
	 * @return true|\WP_Error
	 */
	public static function check( string $title, string $content ) {
		if ( '' === trim( $title ) ) {
			return new \WP_Error( 'easyrankly_memory_title', __( 'A memory entry needs a name.', 'easyrankly' ) );
		}

		if ( mb_strlen( $content ) > self::MAX_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			return new \WP_Error( 'easyrankly_memory_length', sprintf( __( 'A memory entry can be at most %d characters long.', 'easyrankly' ), self::MAX_LENGTH ) );
		}

		return true;
	}

	/**
	 * Adds an active entry.
	 *
	 * @param string $title   Name.
	 * @param string $content Fact, in Markdown.
	 * @return int|\WP_Error Entry ID.
	 */
	public static function add( string $title, string $content ) {
		$title   = sanitize_text_field( $title );
		$content = trim( $content );

		$valid = self::check( $title, $content );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_author'  => get_current_user_id(),
			),
			true
		);
	}

	/**
	 * Active entries for the agent, most recently changed first.
	 *
	 * @param int $limit Most entries.
	 * @return list<array{id: int, title: string, content: string}>
	 */
	public static function entries( int $limit = self::CONTEXT_ENTRIES ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$entries = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$entries[] = array(
					'id'      => $post->ID,
					'title'   => $post->post_title,
					'content' => $post->post_content,
				);
			}
		}

		return $entries;
	}

	/**
	 * Every active entry as one Markdown document: a level-2 heading per entry.
	 *
	 * @return string
	 */
	public static function export(): string {
		$markdown = '# ' . __( 'EasyRankly agent memory', 'easyrankly' ) . "\n";

		foreach ( array_reverse( self::entries( -1 ) ) as $entry ) {
			$markdown .= "\n## " . str_replace( array( "\r", "\n" ), ' ', $entry['title'] ) . "\n\n" . trim( $entry['content'] ) . "\n";
		}

		return $markdown;
	}

	/**
	 * Splits a Markdown document into entries: each level-2 heading starts one.
	 *
	 * Text before the first level-2 heading (such as the level-1 title) is ignored. Headings
	 * inside fenced code blocks do not start an entry.
	 *
	 * @param string $markdown Document.
	 * @return list<array{title: string, content: string}>
	 */
	public static function parse( string $markdown ): array {
		$entries = array();
		$current = null;
		$fenced  = false;

		$lines = preg_split( '/\R/', $markdown );

		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( 1 === preg_match( '/^\s{0,3}(```|~~~)/', $line ) ) {
				$fenced = ! $fenced;
			}

			if ( ! $fenced && 1 === preg_match( '/^##\s+(.+?)\s*#*\s*$/', $line, $matches ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}
				$current = array(
					'title'   => $matches[1],
					'content' => '',
				);
				continue;
			}

			if ( null !== $current ) {
				$current['content'] .= $line . "\n";
			}
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		return array_map(
			static fn( array $entry ): array => array(
				'title'   => $entry['title'],
				'content' => trim( $entry['content'] ),
			),
			$entries
		);
	}

	/**
	 * Imports the entries of a Markdown document, from a given position.
	 *
	 * Idempotent: an entry with the same name and text as an existing one is skipped. At most
	 * IMPORT_BATCH entries are read per call; `next` says where the following call starts.
	 *
	 * @param string $markdown Document.
	 * @param int    $offset   Index of the first entry to read.
	 * @return array{created: int, skipped: int, errors: list<string>, next: int, total: int}
	 */
	public static function import( string $markdown, int $offset = 0 ): array {
		$entries = self::parse( $markdown );
		$batch   = array_slice( $entries, max( 0, $offset ), self::IMPORT_BATCH );
		$result  = array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => array(),
			'next'    => min( count( $entries ), max( 0, $offset ) + count( $batch ) ),
			'total'   => count( $entries ),
		);

		foreach ( $batch as $entry ) {
			if ( self::exists( sanitize_text_field( $entry['title'] ), $entry['content'] ) ) {
				++$result['skipped'];
				continue;
			}

			$id = self::add( $entry['title'], $entry['content'] );
			if ( is_wp_error( $id ) ) {
				$result['errors'][] = $entry['title'] . ': ' . $id->get_error_message();
			} else {
				++$result['created'];
			}
		}

		return $result;
	}

	/**
	 * Remembers why a person rejected a proposal.
	 *
	 * @param \WP_Post $proposal Proposal post.
	 * @param string   $reason   Reason given.
	 * @return int|\WP_Error Entry ID.
	 */
	public static function learn_rejection( \WP_Post $proposal, string $reason ) {
		return self::add(
			/* translators: %s: summary of the proposal. */
			sprintf( __( 'Rejected: %s', 'easyrankly' ), $proposal->post_title ),
			sprintf(
				/* translators: 1: content title, 2: reason given by the person. */
				__( "A proposal on \"%1\$s\" was rejected.\n\nReason: %2\$s", 'easyrankly' ),
				self::content_title( $proposal ),
				$reason
			)
		);
	}

	/**
	 * Remembers how a person changed a proposal before accepting it.
	 *
	 * @param \WP_Post             $proposal Proposal post.
	 * @param array<string, mixed> $proposed Values the agent proposed.
	 * @param array<string, mixed> $applied  Values the person applied.
	 * @return int|\WP_Error|null Entry ID, or null when nothing changed.
	 */
	public static function learn_edit( \WP_Post $proposal, array $proposed, array $applied ) {
		$labels = Actions::labels();
		$lines  = array();

		foreach ( $applied as $field => $value ) {
			if ( 'id' === $field || ( $proposed[ $field ] ?? null ) === $value ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: field label, 2: proposed value, 3: value the person kept. */
				__( '- %1$s: proposed "%2$s", changed to "%3$s"', 'easyrankly' ),
				$labels[ $field ] ?? (string) $field,
				is_scalar( $proposed[ $field ] ?? '' ) ? (string) ( $proposed[ $field ] ?? '' ) : '',
				is_scalar( $value ) ? (string) $value : ''
			);
		}

		if ( array() === $lines ) {
			return null;
		}

		return self::add(
			/* translators: %s: summary of the proposal. */
			sprintf( __( 'Edited: %s', 'easyrankly' ), $proposal->post_title ),
			sprintf(
				/* translators: %s: content title. */
				__( 'A proposal on "%s" was accepted after these changes:', 'easyrankly' ),
				self::content_title( $proposal )
			) . "\n\n" . implode( "\n", $lines )
		);
	}

	/**
	 * Title of the content a proposal changes.
	 *
	 * @param \WP_Post $proposal Proposal post.
	 * @return string
	 */
	private static function content_title( \WP_Post $proposal ): string {
		return $proposal->post_parent > 0 ? wp_strip_all_tags( get_the_title( $proposal->post_parent ) ) : '';
	}

	/**
	 * Whether an entry with this name and text exists, active or not.
	 *
	 * @param string $title   Name.
	 * @param string $content Fact.
	 * @return bool
	 */
	private static function exists( string $title, string $content ): bool {
		$posts = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => array( 'publish', 'draft' ),
				'title'                  => $title,
				'posts_per_page'         => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			if ( trim( $post->post_content ) === trim( $content ) ) {
				return true;
			}
		}

		return false;
	}
}
