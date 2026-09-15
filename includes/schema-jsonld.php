<?php
/**
 * JSON-LD validation, graph merging, and REST schema for custom Schema.org blocks.
 *
 * Loaded with meta.php so save-time checks run even when the frontend schema renderer is not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'erankly_load_schema_sanitizers' ) ) {
	erankly_load_schema_sanitizers();
}

/**
 * Replaces {{variable}} tokens with a JSON-safe probe so validation can parse authored templates.
 */
function erankly_json_ld_placeholder_probe( string $json ): string {
	return (string) preg_replace( '/{{\s*[a-z0-9_]+\s*}}/i', 'x', $json );
}

/**
 * Public JSON-LD helpers for add-ons:
 * - erankly_validate_custom_json_ld() is the canonical validator.
 * - erankly_is_valid_custom_json_ld() and erankly_normalize_custom_json_ld_data() are compatibility shims.
 * Other erankly_json_ld_* functions in this file are internal.
 */
function erankly_is_valid_custom_json_ld( string $json ): bool {
	return (bool) erankly_validate_custom_json_ld( $json )['valid'];
}

/**
 * @return array<int,array<string,mixed>>
 */
function erankly_normalize_custom_json_ld_data( string $json ): array {
	$result = erankly_validate_custom_json_ld( $json );

	return ! empty( $result['valid'] ) && is_array( $result['nodes'] ) ? $result['nodes'] : array();
}

/**
 * Validates custom JSON-LD at two levels: syntax/structure, then Schema.org-minimum semantics.
 *
 * A document is accepted when it is one object, a list of objects, or an object with @graph, and every
 * node is a non-empty object that carries a usable @type and/or @id. Parseable JSON without that
 * minimum is not treated as valid JSON-LD.
 *
 * @param bool $allow_placeholders When true, {{variable}} tokens are replaced with a JSON-safe probe
 *                                 so authored templates can be stored. Runtime callers must pass false
 *                                 and validate the document after variable replacement.
 * @return array{valid:bool,code:string,message:string,nodes:array<int,array<string,mixed>>}
 */
function erankly_validate_custom_json_ld( string $json, bool $allow_placeholders = true ): array {
	$json = trim( $json );

	if ( '' === $json ) {
		return erankly_json_ld_validation_result( true, '', '', array() );
	}

	$prepared = $allow_placeholders ? erankly_json_ld_placeholder_probe( $json ) : $json;
	$decoded  = json_decode( $prepared, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ( ! is_array( $decoded ) && ! is_object( $decoded ) ) ) {
		return erankly_json_ld_validation_result(
			false,
			'syntax',
			__( 'This is not valid JSON, so it cannot be used as JSON-LD.', 'easyrankly' ),
			array()
		);
	}

	if ( ! is_array( $decoded ) ) {
		return erankly_json_ld_validation_result(
			false,
			'structure',
			__( 'JSON-LD must be an object, an array of objects, or an object with @graph.', 'easyrankly' ),
			array()
		);
	}

	if ( array_key_exists( '@graph', $decoded ) ) {
		if ( ! is_array( $decoded['@graph'] ) || ! erankly_array_is_list( $decoded['@graph'] ) ) {
			return erankly_json_ld_validation_result(
				false,
				'structure',
				__( '@graph must be an array of JSON-LD nodes.', 'easyrankly' ),
				array()
			);
		}

		return erankly_validate_json_ld_nodes( $decoded['@graph'] );
	}

	if ( erankly_array_is_list( $decoded ) ) {
		return erankly_validate_json_ld_nodes( $decoded );
	}

	return erankly_validate_json_ld_nodes( array( $decoded ) );
}

/**
 * @param array<int,mixed> $nodes Candidate graph nodes.
 * @return array{valid:bool,code:string,message:string,nodes:array<int,array<string,mixed>>}
 */
function erankly_validate_json_ld_nodes( array $nodes ): array {
	if ( array() === $nodes ) {
		return erankly_json_ld_validation_result(
			false,
			'structure',
			__( 'JSON-LD must contain at least one node.', 'easyrankly' ),
			array()
		);
	}

	$clean = array();

	foreach ( $nodes as $index => $node ) {
		$error = erankly_json_ld_node_error( $node, (int) $index );

		if ( '' !== $error ) {
			return erankly_json_ld_validation_result( false, 'semantic', $error, array() );
		}

		unset( $node['@context'] );
		$clean[] = $node;
	}

	return erankly_json_ld_validation_result( true, '', '', $clean );
}

/**
 * @param mixed $node Candidate node.
 */
function erankly_json_ld_node_error( mixed $node, int $index ): string {
	$label = sprintf(
		/* translators: %d: 1-based node index. */
		__( 'Node %d', 'easyrankly' ),
		$index + 1
	);

	if ( ! is_array( $node ) || erankly_array_is_list( $node ) ) {
		return sprintf(
			/* translators: %s: node label such as "Node 1". */
			__( '%s must be a JSON object.', 'easyrankly' ),
			$label
		);
	}

	$keys = array_keys( $node );

	if ( array() === $node || array( '@context' ) === $keys ) {
		return sprintf(
			/* translators: %s: node label such as "Node 1". */
			__( '%s is empty and is not valid JSON-LD.', 'easyrankly' ),
			$label
		);
	}

	$has_type = erankly_json_ld_has_valid_type( $node );
	$has_id   = erankly_json_ld_has_valid_id( $node );

	if ( isset( $node['@type'] ) && ! $has_type ) {
		return sprintf(
			/* translators: %s: node label such as "Node 1". */
			__( '%s has an invalid @type. Use a non-empty type name or a list of type names.', 'easyrankly' ),
			$label
		);
	}

	if ( isset( $node['@id'] ) && ! $has_id ) {
		return sprintf(
			/* translators: %s: node label such as "Node 1". */
			__( '%s has an invalid @id. Use a non-empty string.', 'easyrankly' ),
			$label
		);
	}

	if ( ! $has_type && ! $has_id ) {
		return sprintf(
			/* translators: %s: node label such as "Node 1". */
			__( '%s needs a valid @type or @id to be JSON-LD.', 'easyrankly' ),
			$label
		);
	}

	return '';
}

/** @param array<string,mixed> $node Schema node. */
function erankly_json_ld_has_valid_type( array $node ): bool {
	if ( ! array_key_exists( '@type', $node ) ) {
		return false;
	}

	$type = $node['@type'];

	if ( is_string( $type ) ) {
		return erankly_json_ld_is_valid_type_name( $type );
	}

	if ( ! is_array( $type ) || ! erankly_array_is_list( $type ) || array() === $type ) {
		return false;
	}

	foreach ( $type as $item ) {
		if ( ! is_string( $item ) || ! erankly_json_ld_is_valid_type_name( $item ) ) {
			return false;
		}
	}

	return true;
}

function erankly_json_ld_is_valid_type_name( string $type ): bool {
	$type = trim( $type );

	if ( '' === $type ) {
		return false;
	}

	// Compact Schema.org names and JSON-LD IRIs / prefixed types are accepted.
	// There is no closed allowlist, so vendor extensions remain usable.
	if ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9._:-]*$/', $type ) ) {
		return true;
	}

	return (bool) filter_var( $type, FILTER_VALIDATE_URL );
}

/** @param array<string,mixed> $node Schema node. */
function erankly_json_ld_has_valid_id( array $node ): bool {
	return isset( $node['@id'] ) && is_string( $node['@id'] ) && '' !== trim( $node['@id'] );
}

/**
 * @param array<int,array<string,mixed>> $nodes Validated nodes.
 * @return array{valid:bool,code:string,message:string,nodes:array<int,array<string,mixed>>}
 */
function erankly_json_ld_validation_result( bool $valid, string $code, string $message, array $nodes ): array {
	return array(
		'valid'   => $valid,
		'code'    => $code,
		'message' => $message,
		'nodes'   => $nodes,
	);
}

/**
 * REST schema for `_erankly_schema_blocks`. Gutenberg rejects generic object items ("type is not a valid
 * property of Object") unless properties are declared. additionalProperties stays open for add-ons.
 *
 * @return array<string,mixed>
 */
function erankly_schema_blocks_rest_schema(): array {
	$schema = array(
		'type'     => 'array',
		'default'  => array(),
		'items'    => array(
			'type'                 => 'object',
			'properties'           => array(
				'type'              => array(
					'type'    => 'string',
					'default' => 'custom',
				),
				'fields'            => array(
					'type'                 => 'object',
					'properties'           => array(
						'custom_json' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'additionalProperties' => true,
					'default'              => array(
						'custom_json' => '',
					),
				),
				'enabled'           => array(
					'type' => array( 'integer', 'boolean', 'string' ),
				),
				'target_contexts'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'target_post_types' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'include_items'     => array( 'type' => 'string' ),
				'exclude_items'     => array( 'type' => 'string' ),
			),
			'additionalProperties' => true,
		),
	);

	/** Filters the REST schema used for EasyRankly schema blocks. */
	$schema = apply_filters( 'erankly_schema_blocks_rest_schema', $schema );

	return is_array( $schema ) ? $schema : array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'object' ) );
}

/**
 * Merges two Schema.org nodes. Overlay wins scalar conflicts; @type values are unioned; compatible objects
 * merge recursively; arrays are concatenated and deduplicated. Precedence is applied by call order: later
 * layers overlay earlier ones (automatic < global < per-post custom).
 *
 * @param array<string,mixed> $base    Earlier node.
 * @param array<string,mixed> $overlay Later node.
 * @return array<string,mixed>
 */
function erankly_merge_schema_nodes( array $base, array $overlay ): array {
	$merged_types = array_values(
		array_unique(
			array_merge(
				erankly_schema_node_type_list( $base ),
				erankly_schema_node_type_list( $overlay )
			)
		)
	);
	$result = $base;

	foreach ( $overlay as $key => $value ) {
		if ( '@type' === $key ) {
			continue;
		}

		if ( ! array_key_exists( $key, $result ) ) {
			$result[ $key ] = $value;
			continue;
		}

		$existing = $result[ $key ];

		if ( is_array( $existing ) && is_array( $value ) ) {
			$existing_list = erankly_array_is_list( $existing );
			$value_list    = erankly_array_is_list( $value );

			if ( ! $existing_list && ! $value_list ) {
				$result[ $key ] = erankly_merge_schema_nodes( $existing, $value );
				continue;
			}

			if ( $existing_list && $value_list ) {
				$result[ $key ] = erankly_merge_schema_arrays( $existing, $value );
				continue;
			}

			erankly_schema_merge_warn(
				isset( $base['@id'] ) ? (string) $base['@id'] : '',
				(string) $key
			);
			$result[ $key ] = $value;
			continue;
		}

		if ( $existing !== $value && ( is_array( $existing ) || is_array( $value ) ) ) {
			erankly_schema_merge_warn(
				isset( $base['@id'] ) ? (string) $base['@id'] : '',
				(string) $key
			);
		}

		$result[ $key ] = $value;
	}

	if ( count( $merged_types ) === 1 ) {
		$result['@type'] = $merged_types[0];
	} elseif ( count( $merged_types ) > 1 ) {
		$result['@type'] = $merged_types;
	}

	return $result;
}

/**
 * @param array<int,mixed> $base    Earlier list.
 * @param array<int,mixed> $overlay Later list.
 * @return array<int,mixed>
 */
function erankly_merge_schema_arrays( array $base, array $overlay ): array {
	$merged = array_merge( $base, $overlay );
	$seen   = array();
	$unique = array();

	foreach ( $merged as $item ) {
		$key = is_array( $item )
			? md5( (string) wp_json_encode( $item ) )
			: 's:' . md5( (string) wp_json_encode( $item ) );

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$unique[]     = $item;
	}

	return $unique;
}

/**
 * @param array<string,mixed> $node Schema node.
 * @return array<int,string>
 */
function erankly_schema_node_type_list( array $node ): array {
	if ( ! isset( $node['@type'] ) ) {
		return array();
	}

	$types = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
	$clean = array();

	foreach ( $types as $type ) {
		$type = trim( (string) $type );

		if ( '' !== $type ) {
			$clean[] = $type;
		}
	}

	return array_values( array_unique( $clean ) );
}

function erankly_schema_merge_warn( string $id, string $property ): void {
	if ( ! isset( $GLOBALS['erankly_schema_merge_warnings'] ) || ! is_array( $GLOBALS['erankly_schema_merge_warnings'] ) ) {
		$GLOBALS['erankly_schema_merge_warnings'] = array();
	}

	$GLOBALS['erankly_schema_merge_warnings'][] = array(
		'@id'      => $id,
		'property' => $property,
	);
}

/**
 * @return array<int,array{@id:string,property:string}>
 */
function erankly_get_schema_merge_warnings(): array {
	$warnings = $GLOBALS['erankly_schema_merge_warnings'] ?? array();

	return is_array( $warnings ) ? $warnings : array();
}

function erankly_clear_schema_merge_warnings(): void {
	$GLOBALS['erankly_schema_merge_warnings'] = array();
}

/**
 * User-facing messages for unresolved scalar/shape conflicts during @id merge.
 *
 * @return array<int,string>
 */
function erankly_schema_merge_warning_messages(): array {
	$messages = array();

	foreach ( erankly_get_schema_merge_warnings() as $warning ) {
		if ( ! is_array( $warning ) ) {
			continue;
		}

		$id       = isset( $warning['@id'] ) ? trim( (string) $warning['@id'] ) : '';
		$property = isset( $warning['property'] ) ? trim( (string) $warning['property'] ) : '';

		if ( '' === $property ) {
			continue;
		}

		if ( '' === $id ) {
			$messages[] = sprintf(
				/* translators: %s: Schema.org property name. */
				__( 'Conflicting Schema.org values for "%s" were merged; the later layer was kept.', 'easyrankly' ),
				$property
			);
			continue;
		}

		$messages[] = sprintf(
			/* translators: 1: Schema.org property name, 2: node @id. */
			__( 'Conflicting Schema.org values for "%1$s" on %2$s were merged; the later layer was kept.', 'easyrankly' ),
			$property,
			$id
		);
	}

	return array_values( array_unique( $messages ) );
}

/** Logs merge conflicts when WP_DEBUG is on so they are not silent. */
function erankly_maybe_log_schema_merge_warnings(): void {
	static $logged = false;

	if ( $logged || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	$messages = erankly_schema_merge_warning_messages();

	if ( array() === $messages ) {
		return;
	}

	$logged = true;

	foreach ( $messages as $message ) {
		error_log( 'EasyRankly: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-only diagnostic for unresolved schema merges.
	}
}

/** HTML comment for editors viewing source; anonymous visitors never see it. */
function erankly_render_schema_merge_warning_comment(): void {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$messages = erankly_schema_merge_warning_messages();

	if ( array() === $messages ) {
		return;
	}

	echo "\n<!-- EasyRankly schema merge: " . esc_html( implode( ' ', $messages ) ) . " -->\n";
}

/**
 * @param WP_Admin_Bar $bar Toolbar.
 */
function erankly_admin_bar_schema_merge_warnings( WP_Admin_Bar $bar ): void {
	if ( is_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$messages = erankly_schema_merge_warning_messages();

	if ( array() === $messages ) {
		return;
	}

	$bar->add_node(
		array(
			'id'    => 'erankly-schema-merge',
			'title' => __( 'Schema merge warning', 'easyrankly' ),
			'meta'  => array(
				'class' => 'erankly-schema-merge-warning',
			),
		)
	);

	foreach ( $messages as $index => $message ) {
		$bar->add_node(
			array(
				'id'     => 'erankly-schema-merge-' . $index,
				'parent' => 'erankly-schema-merge',
				'title'  => $message,
			)
		);
	}
}

/**
 * @return array<int,string>
 */
function erankly_get_schema_type_suggestions(): array {
	$types = array(
		'Organization',
		'Person',
		'WebSite',
		'WebPage',
		'ItemPage',
		'AboutPage',
		'ContactPage',
		'CollectionPage',
		'ProfilePage',
		'Article',
		'BlogPosting',
		'NewsArticle',
		'BreadcrumbList',
		'FAQPage',
		'HowTo',
		'Event',
		'VideoObject',
		'Product',
		'LocalBusiness',
		'Service',
	);

	$types = apply_filters( 'erankly_schema_type_suggestions', $types );

	return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
}

/**
 * Types that can realistically appear for one post, used by the suppression token field.
 *
 * @return array<int,string>
 */
function erankly_get_schema_type_suggestions_for_post( int $post_id ): array {
	$types     = erankly_get_schema_type_suggestions();
	$post_type = $post_id > 0 ? (string) get_post_type( $post_id ) : '';

	if ( '' !== $post_type ) {
		$webpage = function_exists( 'erankly_get_post_type_schema_type' )
			? erankly_get_post_type_schema_type( $post_type, 'webpage_type' )
			: '';
		$article = function_exists( 'erankly_get_post_type_schema_type' )
			? erankly_get_post_type_schema_type( $post_type, 'article_type' )
			: '';

		if ( '' !== $webpage && 'none' !== strtolower( $webpage ) ) {
			$types[] = $webpage;
		}

		if ( '' !== $article && 'none' !== strtolower( $article ) ) {
			$types[] = $article;
		}
	}

	$types = array_values( array_unique( array_filter( $types ) ) );

	/** Filters the Schema.org type tokens offered for one post. */
	$types = apply_filters( 'erankly_schema_type_suggestions_for_post', $types, $post_id );

	return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : array();
}

/**
 * Organization-only details (description, legal identifiers) are for Organization identity.
 *
 * @param array<string,mixed> $settings Stored settings.
 */
function erankly_settings_show_organization_fields( array $settings ): bool {
	return 'person' !== (string) ( $settings['schema_identity'] ?? 'organization' );
}

/**
 * Address and contact fields stay available when LocalBusiness is enabled, even if
 * the primary identity is a Person. LocalBusiness still needs a PostalAddress.
 *
 * @param array<string,mixed> $settings Stored settings.
 */
function erankly_settings_show_location_fields( array $settings ): bool {
	return erankly_settings_show_organization_fields( $settings ) || ! empty( $settings['enable_local_business'] );
}

/**
 * @param array<string,mixed> $settings Stored settings.
 * @return array<string,string> Missing requirement key => label.
 */
function erankly_local_business_requirement_gap_map( array $settings ): array {
	$gaps = array();
	$name = trim( (string) ( $settings['organization_name'] ?? '' ) );

	if ( '' === $name ) {
		$name = trim( (string) get_bloginfo( 'name' ) );
	}

	if ( '' === $name ) {
		$gaps['organization_name'] = __( 'Organization name', 'easyrankly' );
	}

	$street   = trim( (string) ( $settings['organization_street_address'] ?? '' ) );
	$locality = trim( (string) ( $settings['organization_locality'] ?? '' ) );
	$postal   = trim( (string) ( $settings['organization_postal_code'] ?? '' ) );
	$country  = erankly_sanitize_country_code( $settings['organization_country'] ?? '' );

	if ( '' === $street ) {
		$gaps['organization_street_address'] = __( 'Street address', 'easyrankly' );
	}

	if ( '' === $locality ) {
		$gaps['organization_locality'] = __( 'City / locality', 'easyrankly' );
	}

	if ( '' === $postal ) {
		$gaps['organization_postal_code'] = __( 'Postal code', 'easyrankly' );
	}

	if ( '' === $country ) {
		$gaps['organization_country'] = __( 'Country code', 'easyrankly' );
	}

	$pages = isset( $settings['local_business_pages'] ) && is_array( $settings['local_business_pages'] )
		? array_filter( array_map( 'absint', $settings['local_business_pages'] ) )
		: array();
	$path  = erankly_sanitize_relative_path( $settings['local_business_page_path'] ?? '' );

	if ( array() === $pages && '' === $path ) {
		$gaps['location_page'] = __( 'Location page', 'easyrankly' );
	}

	return $gaps;
}

/**
 * @param array<string,mixed> $settings Stored settings.
 * @return array<int,string> Missing requirement labels.
 */
function erankly_local_business_requirement_gaps( array $settings ): array {
	return array_values( erankly_local_business_requirement_gap_map( $settings ) );
}

/**
 * Resolves the LocalBusiness page ID for the current site.
 *
 * Prefers the per-blog page ID map. Falls back to the legacy shared relative path looked up on the
 * current blog so existing settings keep working until they are re-saved.
 */
function erankly_get_local_business_page_id( int $post_id = 0 ): int {
	$pages   = erankly_get_setting( 'local_business_pages', array() );
	$blog_id = get_current_blog_id();

	if ( is_array( $pages ) ) {
		foreach ( array( $blog_id, (string) $blog_id ) as $key ) {
			if ( isset( $pages[ $key ] ) ) {
				$page_id = absint( $pages[ $key ] );

				if ( $page_id > 0 ) {
					return $page_id;
				}
			}
		}
	}

	$path = erankly_sanitize_relative_path( erankly_get_setting( 'local_business_page_path', '' ) );

	if ( '' === $path ) {
		return 0;
	}

	$page = get_page_by_path( trim( $path, '/' ), OBJECT, 'page' );

	if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
		return (int) $page->ID;
	}

	unset( $post_id );

	return 0;
}

/**
 * Returns whether a blog ID may be used as a LocalBusiness mapping target on the current network.
 */
function erankly_local_business_site_is_selectable( int $blog_id ): bool {
	if ( $blog_id <= 0 ) {
		return false;
	}

	if ( ! is_multisite() ) {
		return $blog_id === (int) get_current_blog_id();
	}

	$site = get_site( $blog_id );

	return $site instanceof WP_Site
		&& (int) $site->site_id === (int) get_current_network_id()
		&& empty( $site->deleted )
		&& empty( $site->spam )
		&& empty( $site->archived );
}

/**
 * @param-out bool $has_more Whether another bounded page of results exists.
 * @return array<int,array<string,mixed>>
 */
function erankly_get_local_business_published_pages( int $blog_id, string $search = '', int $limit = 0, int $include_page_id = 0, int $offset = 0, ?bool &$has_more = null ): array {
	$limit    = $limit > 0 ? min( ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT, $limit ) : ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT;
	$offset   = max( 0, $offset );
	$has_more = false;
	$switched = is_multisite() && get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		if ( ! erankly_local_business_site_is_selectable( $blog_id ) ) {
			return array();
		}

		switch_to_blog( $blog_id );
	}

	try {
		$query_args = array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => $limit + 1,
			'offset'                 => $offset,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
				'ID'         => 'ASC',
			),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );

		$options = array();

		foreach ( $query->posts as $page ) {
			if ( ! $page instanceof WP_Post ) {
				continue;
			}

			$options[] = erankly_local_business_page_choice( $page );
		}

		if ( count( $options ) > $limit ) {
			$has_more = true;
			array_pop( $options );
		}

		$seen = array();
		foreach ( $options as $option ) {
			$seen[ absint( $option['id'] ?? 0 ) ] = true;
		}

		if ( $include_page_id > 0 && empty( $seen[ $include_page_id ] ) ) {
			$page = get_post( $include_page_id );

			if ( $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status ) {
				$options[] = erankly_local_business_page_choice( $page );
			}
		}

		return $options;
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * @return array{id:int,title:string,path:string}
 */
function erankly_local_business_page_choice( WP_Post $page ): array {
	$uri = (string) get_page_uri( $page );

	return array(
		'id'    => (int) $page->ID,
		'title' => get_the_title( $page ),
		'path'  => '' !== $uri ? '/' . trim( $uri, '/' ) . '/' : '/',
	);
}

function erankly_local_business_page_choice_label( array $page ): string {
	$title = (string) ( $page['title'] ?? '' );
	$path  = (string) ( $page['path'] ?? '' );
	$id    = absint( $page['id'] ?? 0 );

	return trim(
		sprintf(
			/* translators: 1: page title, 2: page path, 3: page ID. */
			__( '%1$s (%2$s) [#%3$d]', 'easyrankly' ),
			$title,
			$path,
			$id
		)
	);
}

/**
 * @return array<string,mixed>
 */
function erankly_get_local_business_site_choice( int $blog_id, string $search = '', int $page_limit = 0, int $include_page_id = 0 ): array {
	$path = '/';

	if ( is_multisite() ) {
		$site = get_site( $blog_id );
		$path = $site instanceof WP_Site ? (string) $site->path : '/';
	}

	if ( is_multisite() && ! erankly_local_business_site_is_selectable( $blog_id ) ) {
		return array(
			'blog_id'  => $blog_id,
			'name'     => '',
			'language' => '',
			'locale'   => '',
			'path'     => $path,
			'pages'    => array(),
		);
	}

	$switched = is_multisite() && get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		return array(
			'blog_id'  => $blog_id,
			'name'     => get_bloginfo( 'name' ),
			'language' => get_bloginfo( 'language' ),
			'locale'   => get_locale(),
			'path'     => $path,
			'pages'    => erankly_get_local_business_published_pages( $blog_id, $search, $page_limit, $include_page_id ),
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Returns one page of LocalBusiness site choices. Callers must not loop this until exhaustion: each request
 * loads at most ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT sites and ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT
 * pages per site.
 *
 * @param array<int,int> $selected_pages Stored blog_id => page_id map used to keep the current selection visible.
 * @return array<int,array<string,mixed>>
 */
function erankly_get_local_business_site_choices( int $after_site_id = 0, int $limit = 0, array $selected_pages = array(), string $search = '' ): array {
	$limit = $limit > 0 ? min( ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT, $limit ) : ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT;
	$sites = array();

	if ( is_multisite() ) {
		try {
			$site_ids = erankly_get_network_site_ids_batch( max( 0, $after_site_id ), $limit, true );
		} catch ( RuntimeException ) {
			return array();
		}

		foreach ( $site_ids as $blog_id ) {
			if ( erankly_local_business_site_is_selectable( $blog_id ) ) {
				$sites[] = $blog_id;
			}
		}
	} else {
		$sites[] = (int) get_current_blog_id();
	}

	$choices = array();

	foreach ( $sites as $blog_id ) {
		$include_page_id = isset( $selected_pages[ $blog_id ] ) ? absint( $selected_pages[ $blog_id ] ) : 0;
		$choices[]       = erankly_get_local_business_site_choice( $blog_id, $search, 0, $include_page_id );
	}

	return $choices;
}

add_action( 'admin_bar_menu', 'erankly_admin_bar_schema_merge_warnings', 100 );
