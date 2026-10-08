<?php
/**
 * Read-only abilities of the agent.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Agent;

use EasyRankly\Meta\Meta;
use EasyRankly\Multilingual\Languages;
use EasyRankly\Multilingual\Translations;
use EasyRankly\Settings\Settings;
use EasyRankly\Titles\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities that only read: the AI uses them freely, and so do the admin screens.
 *
 * Each one is exposed by the core REST API (`/wp-abilities/v1/abilities/{name}/run`)
 * with its own permission check, so a caller sees only what it could see in the admin.
 */
final class Abilities {

	/**
	 * Most characters of content text returned to the agent.
	 */
	public const CONTENT_LENGTH = 20000;

	/**
	 * Most results of one search page.
	 */
	public const SEARCH_PER_PAGE = 20;

	/**
	 * Annotations shared by every read-only ability.
	 */
	private const READONLY = array(
		'readonly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	);

	/**
	 * Registers the read-only abilities. Runs on `wp_abilities_api_init`.
	 */
	public static function register_abilities(): void {
		wp_register_ability(
			Agent::CATEGORY . '/get-post-seo',
			array(
				'label'               => __( 'Get the SEO data of a content', 'easyrankly' ),
				'description'         => __( 'Returns the title, address, summary, language and SEO fields of a post or page. Empty SEO fields mean the site templates apply. Optionally returns the plain text of the content.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'              => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Post ID.', 'easyrankly' ),
						),
						'include_content' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Also return the plain text of the content.', 'easyrankly' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::post_schema(),
				'execute_callback'    => array( self::class, 'get_post_seo' ),
				'permission_callback' => array( self::class, 'can_edit_post' ),
				'meta'                => array(
					'annotations'  => self::READONLY,
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			Agent::CATEGORY . '/get-site-context',
			array(
				'label'               => __( 'Get the site context', 'easyrankly' ),
				'description'         => __( 'Returns the site name, tagline, address, languages, the person or organization behind the site and the published content types with their counts.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'output_schema'       => self::site_schema(),
				'execute_callback'    => array( self::class, 'get_site_context' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'annotations'  => self::READONLY,
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			Agent::CATEGORY . '/get-memory',
			array(
				'label'               => __( 'Get the project memory', 'easyrankly' ),
				'description'         => __( 'Returns the facts the site owners asked the agent to keep in mind (tone, audience, brand rules, strategic pages, preferences learned from past decisions), in Markdown, and how proposals of each kind were decided in the last 90 days.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'entries'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'      => array( 'type' => 'integer' ),
									'title'   => array( 'type' => 'string' ),
									'content' => array( 'type' => 'string' ),
								),
							),
						),
						'acceptance' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'ability'  => array( 'type' => 'string' ),
									'accepted' => array( 'type' => 'integer' ),
									'rejected' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static fn(): array => array(
					'entries'    => Memory::entries(),
					'acceptance' => Proposals::acceptance(),
				),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'annotations'  => self::READONLY,
					'show_in_rest' => true,
				),
			)
		);

		wp_register_ability(
			Agent::CATEGORY . '/search-content',
			array(
				'label'               => __( 'Search published content', 'easyrankly' ),
				'description'         => __( 'Searches the published posts and pages by text and returns their ID, title, address and summary, newest first when no text matches better.', 'easyrankly' ),
				'category'            => Agent::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'    => array(
							'type'        => 'string',
							'minLength'   => 1,
							'maxLength'   => 200,
							'description' => __( 'Text to search for.', 'easyrankly' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'pattern'     => '^[a-z0-9_-]{1,20}$',
							'description' => __( 'Limit the search to one content type. Default: every public type.', 'easyrankly' ),
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::SEARCH_PER_PAGE,
							'default' => 10,
						),
						'page'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'required'             => array( 'search' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::search_schema(),
				'execute_callback'    => array( self::class, 'search_content' ),
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
				'meta'                => array(
					'annotations'  => self::READONLY,
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Only users who can edit the post may read its SEO data (it may be a draft).
	 *
	 * @param mixed $input Validated input.
	 * @return bool
	 */
	public static function can_edit_post( $input ): bool {
		$id = is_array( $input ) && isset( $input['id'] ) ? (int) $input['id'] : 0;

		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	/**
	 * SEO data of a post.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_post_seo( $input ) {
		$input = is_array( $input ) ? $input : array();
		$post  = get_post( (int) ( $input['id'] ?? 0 ) );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::content_types(), true ) ) {
			return new \WP_Error( 'easyrankly_not_found', __( 'No content with this ID.', 'easyrankly' ) );
		}

		$data = self::summary( $post ) + array(
			'status'   => $post->post_status,
			'modified' => (string) get_post_modified_time( 'c', true, $post ),
			'seo'      => self::seo_fields( $post->ID ),
		);

		if ( ! empty( $input['include_content'] ) ) {
			$text            = Template::plain( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ) );
			$data['content'] = mb_substr( $text, 0, self::CONTENT_LENGTH );
		}

		return $data;
	}

	/**
	 * Context of the site the agent works on.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_site_context(): array {
		$settings = Settings::get();
		$types    = array();

		foreach ( self::content_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			$counts = wp_count_posts( $post_type );

			$types[] = array(
				'name'      => $post_type,
				'label'     => null !== $object ? (string) $object->labels->name : $post_type,
				'published' => (int) ( $counts->publish ?? 0 ),
			);
		}

		$languages = array();
		foreach ( Languages::all() as $slug => $language ) {
			$languages[] = array(
				'slug'   => (string) $slug,
				'locale' => $language['locale'],
				'name'   => $language['name'],
			);
		}

		return array(
			'name'          => (string) get_bloginfo( 'name' ),
			'tagline'       => (string) get_bloginfo( 'description' ),
			'url'           => home_url( '/' ),
			'locale'        => get_locale(),
			'languages'     => $languages,
			'identity'      => array(
				'type' => (string) $settings['identity_type'],
				'name' => (string) $settings['identity_name'],
			),
			'content_types' => $types,
		);
	}

	/**
	 * Published content matching a text.
	 *
	 * @param mixed $input Validated input.
	 * @return array{total: int, items: list<array<string, mixed>>}
	 */
	public static function search_content( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$types     = self::content_types();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		if ( '' !== $post_type ) {
			$types = in_array( $post_type, $types, true ) ? array( $post_type ) : array();
		}

		if ( array() === $types ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				's'                      => (string) ( $input['search'] ?? '' ),
				'posts_per_page'         => min( self::SEARCH_PER_PAGE, max( 1, (int) ( $input['per_page'] ?? 10 ) ) ),
				'paged'                  => max( 1, (int) ( $input['page'] ?? 1 ) ),
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
			)
		);

		$items = array();
		foreach ( (array) $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = self::summary( $post );
			}
		}

		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * Post types the agent works on: every viewable type except attachments.
	 *
	 * @return list<string>
	 */
	public static function content_types(): array {
		return array_values(
			array_filter(
				get_post_types(),
				static fn( string $post_type ): bool => 'attachment' !== $post_type && is_post_type_viewable( $post_type )
			)
		);
	}

	/**
	 * Fields every content result shares.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{id: int, type: string, title: string, url: string, excerpt: string, language: string}
	 */
	private static function summary( \WP_Post $post ): array {
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'title'    => Template::plain( get_the_title( $post ) ),
			'url'      => (string) get_permalink( $post ),
			'excerpt'  => Template::excerpt( $post ),
			'language' => Languages::enabled() ? Translations::language( $post->ID ) : '',
		);
	}

	/**
	 * Stored SEO fields of a post, with their schema types.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|bool|int>
	 */
	private static function seo_fields( int $post_id ): array {
		$values = array();

		foreach ( Meta::fields() as $name => $field ) {
			$value           = Meta::post( $post_id, $name );
			$values[ $name ] = match ( $field['type'] ) {
				'boolean' => (bool) $value,
				'integer' => is_numeric( $value ) ? (int) $value : 0,
				default   => is_scalar( $value ) ? (string) $value : '',
			};
		}

		return $values;
	}

	/**
	 * Schema of the fields every content result shares.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function summary_properties(): array {
		return array(
			'id'       => array( 'type' => 'integer' ),
			'type'     => array(
				'type'        => 'string',
				'description' => __( 'Content type.', 'easyrankly' ),
			),
			'title'    => array( 'type' => 'string' ),
			'url'      => array( 'type' => 'string' ),
			'excerpt'  => array(
				'type'        => 'string',
				'description' => __( 'Excerpt, or the start of the content.', 'easyrankly' ),
			),
			'language' => array(
				'type'        => 'string',
				'description' => __( 'Language slug, empty when the site has a single language.', 'easyrankly' ),
			),
		);
	}

	/**
	 * Output schema of get-post-seo.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_schema(): array {
		$seo = array();
		foreach ( Meta::fields() as $name => $field ) {
			$seo[ $name ] = array(
				'type'        => $field['type'],
				'description' => $field['description'],
			);
		}

		return array(
			'type'       => 'object',
			'properties' => self::summary_properties() + array(
				'status'   => array( 'type' => 'string' ),
				'modified' => array(
					'type'        => 'string',
					'description' => __( 'Last change, ISO 8601 in UTC.', 'easyrankly' ),
				),
				'seo'      => array(
					'type'       => 'object',
					'properties' => $seo,
				),
				'content'  => array(
					'type'        => 'string',
					'description' => __( 'Plain text of the content, only when requested.', 'easyrankly' ),
				),
			),
		);
	}

	/**
	 * Output schema of get-site-context.
	 *
	 * @return array<string, mixed>
	 */
	private static function site_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'          => array( 'type' => 'string' ),
				'tagline'       => array( 'type' => 'string' ),
				'url'           => array( 'type' => 'string' ),
				'locale'        => array( 'type' => 'string' ),
				'languages'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'   => array( 'type' => 'string' ),
							'locale' => array( 'type' => 'string' ),
							'name'   => array( 'type' => 'string' ),
						),
					),
				),
				'identity'      => array(
					'type'       => 'object',
					'properties' => array(
						'type' => array( 'type' => 'string' ),
						'name' => array( 'type' => 'string' ),
					),
				),
				'content_types' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array( 'type' => 'string' ),
							'label'     => array( 'type' => 'string' ),
							'published' => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Output schema of search-content.
	 *
	 * @return array<string, mixed>
	 */
	private static function search_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'total' => array( 'type' => 'integer' ),
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => self::summary_properties(),
					),
				),
			),
		);
	}
}
