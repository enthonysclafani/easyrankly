<?php
/** Robots meta and robots.txt. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns whether singular content or comments are on a paginated request. This is intentionally separate from
 * is_paged(), which represents archive pagination.
 */
function erankly_is_paginated_content_request(): bool {
	$page  = (int) get_query_var( 'page', 0 );
	$cpage = (int) get_query_var( 'cpage', 0 );

	// Block themes can build comment query vars directly on the main query.
	if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query && isset( $GLOBALS['wp_query']->query['cpage'] ) ) {
		$cpage = max( $cpage, (int) $GLOBALS['wp_query']->query['cpage'] );
	}

	return $page > 1 || $cpage > 0;
}

/**
 * Filters native WordPress robots meta output. Order: global settings flags for the current context, site-wide
 * robots settings, global advanced directives, per-object overrides, then the site visibility guard.
 *
 * @return array<string,bool|string>
 */
function erankly_filter_wp_robots( array $robots ): array {
	foreach ( erankly_current_global_entity_contexts() as list( $setting_key, $entity ) ) {
		$robots = erankly_apply_global_entity_flags( $robots, $setting_key, $entity );
	}

	$robots = erankly_apply_sitewide_robots_settings( $robots );
	$robots = erankly_apply_current_global_entity_robots( $robots );
	$robots = erankly_apply_current_object_robots_overrides( $robots );

	if ( ! empty( $robots['nosnippet'] ) ) {
		unset( $robots['max-snippet'] );
	}
	if ( ! empty( $robots['noimageindex'] ) ) {
		unset( $robots['max-image-preview'] );
	}
	if ( empty( $robots['noindex'] ) ) {
		unset( $robots['indexifembedded'] );
	}

	// Imported positive overrides must never bypass WordPress site visibility.
	if ( ! (bool) get_option( 'blog_public' ) ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['index'], $robots['follow'], $robots['indexifembedded'] );
	}

	if ( empty( $robots['noindex'] ) ) {
		$robots['index'] = true;
	}

	if ( empty( $robots['nofollow'] ) ) {
		$robots['follow'] = true;
	}

	/** Filters robots directives before WordPress renders the meta tag. */
	return apply_filters( 'erankly_robots', $robots );
}

/**
 * Returns the global settings rows that apply to the current request, as [ setting key, entity ] pairs: the
 * post type, taxonomy or post type archive, then the special page (search, 404, archives, homepage, blog page).
 *
 * @return array<int,array{0:string,1:string}>
 */
function erankly_current_global_entity_contexts(): array {
	$contexts = array();

	if ( is_singular() && ! is_front_page() ) {
		$post_type = get_post_type( get_queried_object_id() );
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$contexts[] = array( 'global_post_type_meta', $post_type );
		}
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$contexts[] = array( 'global_taxonomy_meta', $term->taxonomy );
		}
	} elseif ( is_post_type_archive() ) {
		$post_type = get_query_var( 'post_type' );
		$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$contexts[] = array( 'global_post_type_meta', $post_type );
		}
	}

	$special_key = erankly_current_special_page_key();
	if ( '' !== $special_key ) {
		$contexts[] = array( 'global_special_meta', $special_key );
	}

	return $contexts;
}

/**
 * Applies the noindex, nofollow and noarchive checkboxes of one global settings row.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_global_entity_flags( array $robots, string $setting_key, string $entity ): array {
	if ( erankly_get_global_entity_directive( $setting_key, $entity, 'noindex' ) ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	}

	if ( erankly_get_global_entity_directive( $setting_key, $entity, 'nofollow' ) ) {
		$robots['nofollow'] = true;
		unset( $robots['follow'] );
	}

	if ( erankly_get_global_entity_directive( $setting_key, $entity, 'noarchive' ) ) {
		$robots['noarchive'] = true;
	}

	return $robots;
}

/**
 * Applies the site-wide robots settings: pagination, preview limits and the global directive toggles.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_sitewide_robots_settings( array $robots ): array {
	$is_archive_paged = is_paged();
	$is_content_paged = erankly_is_paginated_content_request();

	// Providers expose archive and content/comment pagination as distinct scopes.
	if ( ( $is_archive_paged && (bool) erankly_get_setting( 'noindex_paginated', 0 ) ) || ( $is_content_paged && (bool) erankly_get_setting( 'noindex_paginated_content', 0 ) ) ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	}
	if ( ( $is_archive_paged || $is_content_paged ) && (bool) erankly_get_setting( 'nofollow_paginated', 0 ) ) {
		$robots['nofollow'] = true;
		unset( $robots['follow'] );
	}

	// Only an explicitly stored value emits the directive. With no stored value, WordPress
	// core adds its own max-image-preview:large on public sites (wp_robots_max_image_preview_large()).
	$max_image_preview = sanitize_key( (string) erankly_get_setting( 'robots_max_image_preview', '' ) );
	if ( in_array( $max_image_preview, array( 'none', 'standard', 'large' ), true ) ) {
		$robots['max-image-preview'] = $max_image_preview;
	}

	foreach ( array( 'robots_max_snippet' => 'max-snippet', 'robots_max_video_preview' => 'max-video-preview' ) as $setting => $directive ) {
		$value = trim( (string) erankly_get_setting( $setting, '' ) );
		if ( '' !== $value ) {
			$robots[ $directive ] = $value;
		}
	}

	foreach ( array( 'robots_nosnippet' => 'nosnippet', 'robots_noimageindex' => 'noimageindex', 'robots_notranslate' => 'notranslate' ) as $setting => $directive ) {
		if ( (bool) erankly_get_setting( $setting, 0 ) ) {
			$robots[ $directive ] = true;
		}
	}

	// Google only honors indexifembedded with noindex. It lets an embedded copy
	// be indexed while the standalone URL remains excluded from results.
	if ( ! empty( $robots['noindex'] ) && (bool) erankly_get_setting( 'robots_indexifembedded', 0 ) ) {
		$robots['indexifembedded'] = true;
	}

	return $robots;
}

/** Returns the feed-level robots tag, or an empty string when it must not be sent. */
function erankly_feed_robots_tag(): string {
	if ( ! is_feed() || ! (bool) erankly_get_setting( 'noindex_feeds', 0 ) ) {
		return '';
	}

	return 'noindex, follow';
}

/** Sends the feed-level robots policy through the X-Robots-Tag HTTP header. */
function erankly_send_feed_robots_header(): void {
	$tag = erankly_feed_robots_tag();
	if ( '' === $tag || headers_sent() ) {
		return;
	}

	header( 'X-Robots-Tag: ' . $tag, true );
}

/** @return array<string,bool|string> */
function erankly_apply_current_global_entity_robots( array $robots ): array {
	foreach ( erankly_current_global_entity_contexts() as list( $setting_key, $entity ) ) {
		$robots = erankly_apply_global_entity_robot_row( $robots, erankly_get_global_entity_meta_row( $setting_key, $entity ) );
	}

	return $robots;
}

/**
 * Applies one sanitized global entity robot row.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_global_entity_robot_row( array $robots, array $row ): array {
	$pairs = array(
		'index_directive'   => array( 'index', 'noindex' ),
		'follow_directive'  => array( 'follow', 'nofollow' ),
		'archive_directive' => array( 'archive', 'noarchive' ),
		'snippet_directive' => array( 'snippet', 'nosnippet' ),
		'image_directive'   => array( 'imageindex', 'noimageindex' ),
	);

	foreach ( $pairs as $key => $values ) {
		if ( ! isset( $row[ $key ] ) || ! is_string( $row[ $key ] ) ) {
			continue;
		}
		list( $allow, $deny ) = $values;
		if ( $deny === $row[ $key ] ) {
			$robots[ $deny ] = true;
			unset( $robots[ $allow ] );
		} elseif ( $allow === $row[ $key ] ) {
			unset( $robots[ $deny ] );
			if ( in_array( $allow, array( 'index', 'follow' ), true ) ) {
				$robots[ $allow ] = true;
			}
		}
	}

	// noodp was retired: DMOZ shut down in 2017 and no engine reads the directive.
	if ( array_key_exists( 'notranslate', $row ) ) {
		if ( ! empty( $row['notranslate'] ) ) {
			$robots['notranslate'] = true;
		} else {
			unset( $robots['notranslate'] );
		}
	}

	foreach ( array(
		'max_snippet'       => 'max-snippet',
		'max_video_preview' => 'max-video-preview',
		'max_image_preview' => 'max-image-preview',
	) as $key => $directive ) {
		if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
			$robots[ $directive ] = trim( (string) $row[ $key ] );
		}
	}

	if ( array_key_exists( 'indexifembedded', $row ) ) {
		if ( ! empty( $row['indexifembedded'] ) && ! empty( $robots['noindex'] ) ) {
			$robots['indexifembedded'] = true;
		} else {
			unset( $robots['indexifembedded'] );
		}
	}

	if ( ! empty( $robots['nosnippet'] ) ) {
		unset( $robots['max-snippet'] );
	}
	if ( ! empty( $robots['noimageindex'] ) ) {
		unset( $robots['max-image-preview'] );
	}
	if ( empty( $robots['noindex'] ) ) {
		unset( $robots['indexifembedded'] );
	}

	return $robots;
}

/**
 * Applies tri-state per-object directives after global defaults.
 *
 * @return array<string,bool|string>
 */
function erankly_apply_current_object_robots_overrides( array $robots ): array {
	$object_type = '';
	$object_id   = 0;

	if ( is_singular() ) {
		$object_type = 'post';
		$object_id   = get_queried_object_id();
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$object_type = 'term';
			$object_id   = $term->term_id;
		}
	} elseif ( is_author() ) {
		$object_type = 'user';
		$object_id   = get_queried_object_id();
	}

	if ( '' === $object_type || $object_id < 1 ) {
		return $robots;
	}

	$directives = array(
		'index'   => erankly_get_object_robots_directive( $object_type, $object_id, 'index' ),
		'follow'  => erankly_get_object_robots_directive( $object_type, $object_id, 'follow' ),
		'archive' => erankly_get_object_robots_directive( $object_type, $object_id, 'archive' ),
		'snippet' => erankly_get_object_robots_directive( $object_type, $object_id, 'snippet' ),
		'image'   => erankly_get_object_robots_directive( $object_type, $object_id, 'image' ),
	);

	$pairs = array(
		'index'   => array( 'index', 'noindex' ),
		'follow'  => array( 'follow', 'nofollow' ),
		'archive' => array( 'archive', 'noarchive' ),
		'snippet' => array( 'snippet', 'nosnippet' ),
		'image'   => array( 'imageindex', 'noimageindex' ),
	);

	foreach ( $pairs as $axis => $values ) {
		list( $allow, $deny ) = $values;

		if ( $deny === $directives[ $axis ] ) {
			$robots[ $deny ] = true;
			unset( $robots[ $allow ] );
		} elseif ( $allow === $directives[ $axis ] ) {
			unset( $robots[ $deny ] );

			if ( in_array( $allow, array( 'index', 'follow' ), true ) ) {
				$robots[ $allow ] = true;
			}
		}
	}

	$get_meta = static function ( string $key ) use ( $object_type, $object_id ): mixed {
		if ( 'term' === $object_type ) {
			return get_term_meta( $object_id, $key, true );
		}

		if ( 'user' === $object_type ) {
			return get_user_meta( $object_id, $key, true );
		}

		return get_post_meta( $object_id, $key, true );
	};

	$max_snippet = trim( (string) $get_meta( '_erankly_max_snippet' ) );
	$max_video   = trim( (string) $get_meta( '_erankly_max_video_preview' ) );
	$max_image   = trim( (string) $get_meta( '_erankly_max_image_preview' ) );

	if ( '' !== $max_snippet ) {
		$robots['max-snippet'] = $max_snippet;
	}

	if ( '' !== $max_video ) {
		$robots['max-video-preview'] = $max_video;
	}

	if ( in_array( $max_image, array( 'none', 'standard', 'large' ), true ) ) {
		$robots['max-image-preview'] = $max_image;
	}

	if ( '1' === (string) $get_meta( '_erankly_indexifembedded' ) && ! empty( $robots['noindex'] ) ) {
		$robots['indexifembedded'] = true;
	}

	return $robots;
}

/**
 * Filters virtual robots.txt content.
 *
 * @param bool   $is_public Whether the site discourages search engines.
 */
function erankly_filter_robots_txt( string $output, bool $is_public ): string {
	$custom = (string) erankly_get_setting( 'robots_txt_extra', '' );

	// Custom rules replace the entire file, including WordPress defaults and sitemap directives.
	if ( '' !== trim( $custom ) ) {
		return $custom;
	}

	erankly_load_sitemap_helpers();

	$parsed = erankly_robots_txt_parse_groups( explode( "\n", $output ) );

	// Core's do_robots() already prints the wildcard group, and it derives the admin paths from site_url(). On a
	// subdirectory Multisite that path is "/en/wp-admin/", so hardcoding "/wp-admin/" appended a second, wrong
	// pair of rules to every sub-site. Deriving the prefix the same way core does makes our rules identical to
	// the ones already present, so the group-scoped dedupe below drops them instead of duplicating them.
	$site_path = untrailingslashit( (string) ( wp_parse_url( site_url(), PHP_URL_PATH ) ?? '' ) );

	$required = $is_public
		? array(
			'Disallow: ' . $site_path . '/wp-admin/',
			'Allow: ' . $site_path . '/wp-admin/admin-ajax.php',
		)
		: array( 'Disallow: /' );

	$wildcard = null;

	foreach ( $parsed['groups'] as $index => $group ) {
		if ( in_array( '*', $group['agents'], true ) ) {
			$wildcard = $index;
			break;
		}
	}

	if ( null === $wildcard ) {
		// Empty $output (the settings preview calls this filter directly) has no group to extend.
		$parsed['groups'][] = array(
			'agents' => array( '*' ),
			'rules'  => array(),
		);

		$wildcard = array_key_last( $parsed['groups'] );
	}

	foreach ( $required as $rule ) {
		if ( ! in_array( $rule, $parsed['groups'][ $wildcard ]['rules'], true ) ) {
			$parsed['groups'][ $wildcard ]['rules'][] = $rule;
		}
	}

	if ( $is_public && erankly_sitemap_enabled() ) {
		// Core wp_sitemaps serves the main sitemap index at /wp-sitemap.xml.
		$parsed['globals'][] = 'Sitemap: ' . esc_url_raw( erankly_get_sitemap_url( '/wp-sitemap.xml' ) );
	}

	$lines = erankly_robots_txt_render_groups( $parsed );

	/** @param bool              $is_public Whether the site is public. */
	$lines = apply_filters( 'erankly_robots_txt_lines', $lines, $is_public );

	return implode( "\n", array_map( 'trim', (array) $lines ) ) . "\n";
}

/**
 * Builds the same robots.txt body that WordPress passes through the public
 * `robots_txt` filter, without sending frontend headers from the admin screen.
 *
 * The `do_robotstxt` action is intentionally not fired here: callbacks on that
 * action may print directly or perform request-only side effects. The settings
 * UI calls this limitation out beside the preview.
 */
function erankly_get_robots_txt_preview(): string {
	$output  = "User-agent: *\n";
	$output .= 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n";
	$output .= 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";

	return (string) apply_filters( 'robots_txt', $output, (bool) get_option( 'blog_public' ) );
}

/**
 * Splits robots.txt lines into User-agent groups plus file-level directives.
 *
 * robots.txt is positional: every rule belongs to the User-agent header above it. Deduplicating the flat line
 * list (as this module used to do with array_unique()) silently deletes a repeated `User-agent: *` header and
 * re-attaches its rules to whatever group came before, so the grouping has to be rebuilt before any dedupe.
 *
 * @param array<int,string> $lines Raw robots.txt lines.
 * @return array{preamble:array<int,string>,groups:array<int,array{agents:array<int,string>,rules:array<int,string>}>,globals:array<int,string>}
 */
function erankly_robots_txt_parse_groups( array $lines ): array {
	$preamble  = array();
	$groups    = array();
	$globals   = array();
	$current   = null;
	$in_header = false;

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			continue;
		}

		$separator = strpos( $line, ':' );
		$directive = false === $separator ? '' : strtolower( trim( substr( $line, 0, $separator ) ) );

		if ( 'user-agent' === $directive ) {
			// Consecutive User-agent lines share a single group; only a rule line closes the header.
			if ( null === $current || ! $in_header ) {
				$groups[]  = array(
					'agents' => array(),
					'rules'  => array(),
				);
				$current   = array_key_last( $groups );
				$in_header = true;
			}

			$groups[ $current ]['agents'][] = trim( substr( $line, $separator + 1 ) );
			continue;
		}

		// Sitemap and Host are file-level: they belong to no group and may appear anywhere.
		if ( in_array( $directive, array( 'sitemap', 'host' ), true ) ) {
			$globals[] = $line;
			continue;
		}

		if ( null === $current ) {
			$preamble[] = $line;
			continue;
		}

		$in_header                     = false;
		$groups[ $current ]['rules'][] = $line;
	}

	return array(
		'preamble' => $preamble,
		'groups'   => $groups,
		'globals'  => $globals,
	);
}

/**
 * Flattens parsed robots.txt groups back into lines, deduplicating within each group only.
 *
 * @param array{preamble:array<int,string>,groups:array<int,array{agents:array<int,string>,rules:array<int,string>}>,globals:array<int,string>} $parsed Parsed groups.
 * @return array<int,string>
 */
function erankly_robots_txt_render_groups( array $parsed ): array {
	$lines = array_values( array_unique( $parsed['preamble'] ) );

	foreach ( $parsed['groups'] as $group ) {
		$agents = array_values( array_unique( $group['agents'] ) );

		if ( empty( $agents ) ) {
			continue;
		}

		foreach ( $agents as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
		}

		foreach ( array_values( array_unique( $group['rules'] ) ) as $rule ) {
			$lines[] = $rule;
		}
	}

	foreach ( array_values( array_unique( $parsed['globals'] ) ) as $global ) {
		$lines[] = $global;
	}

	return $lines;
}

/**
 * Forces robots.txt handling when the core rewrite rule is missing. On some Multisite networks (notably staging
 * clones or sub-sites created outside wp_initialize_site) the stored rewrite rules can lack the core
 * `robots\.txt$` rule. A request for /robots.txt is then treated as a regular page, canonical-redirected to
 * /robots.txt/, and never reaches do_robots(). Detecting the raw request path here and forcing the `robots`
 * query var makes the virtual robots.txt behave exactly like /?robots=1, independently of the rewrite-rule flush
 * state of the current site.
 *
 * @param WP $wp Current WordPress environment instance.
 */
function erankly_force_robots_txt_request( WP $wp ): void {
	// Core already routed the request to the robots handler: nothing to do.
	if ( ! empty( $wp->query_vars['robots'] ) || ! empty( $wp->query_vars['robots_txt'] ) ) {
		return;
	}

	$request = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
	if ( '' === $request ) {
		// With "Plain" permalinks (or an empty rewrite-rule set) core skips rewrite
		// matching entirely and never populates ->request, so derive the request
		// path from the raw URI, relative to the home URL path.
		$request = trim( (string) wp_parse_url( wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );
		$home    = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 !== strpos( $request . '/', $home . '/' ) ) {
				return;
			}
			$request = trim( substr( $request, strlen( $home ) ), '/' );
		}
	}

	if ( 'robots.txt' !== $request ) {
		return;
	}

	// Mirror exactly what the core `robots\.txt$` => index.php?robots=1 rule
	// would have produced, so do_robots() (and the robots_txt filter) run.
	$wp->query_vars = array( 'robots' => '1' );
}

function erankly_register_rewrites(): void {
	if ( ! erankly_sitemap_enabled() ) {
		return;
	}

	// Core wp_sitemaps handles /wp-sitemap.xml and the post/taxonomy/user sub-sitemaps.
	// We only need rules for the specialised sitemaps (image, video, news), which core
	// doesn't support because they need non-standard XML namespaces.
	add_rewrite_rule( '^sitemap-(image|video|news)-([0-9]+)\.xml$', 'index.php?erankly_sitemap=$matches[1]&erankly_sitemap_page=$matches[2]', 'top' );

	add_filter(
		'query_vars',
		static function ( array $vars ): array {
			$vars[] = 'erankly_sitemap';
			$vars[] = 'erankly_sitemap_page';

			return $vars;
		}
	);
}

function erankly_maybe_render_virtual_files(): void {
	$sitemap = get_query_var( 'erankly_sitemap' );

	if ( ! is_string( $sitemap ) || '' === $sitemap ) {
		return;
	}

	$page = absint( get_query_var( 'erankly_sitemap_page', 1 ) );

	erankly_render_sitemap_response( $sitemap, $page );
}
