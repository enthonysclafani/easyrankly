<?php
/** Native routing and indexed language selection. No output buffering or visitor assets. */
defined( 'ABSPATH' ) || exit;

function erankly_mlss_runtime_boot(): void {
	add_filter( 'home_url', 'erankly_mlss_filter_home_url', 20, 2 );
	add_filter( 'do_parse_request', static function ( bool $parse ): bool { $GLOBALS['erankly_mlss_localize_home'] = false; return $parse; }, 1 );
	add_action( 'wp', static function (): void { $GLOBALS['erankly_mlss_localize_home'] = erankly_mlss_frontend_request(); }, 1 );
	add_filter( 'query_vars', static fn( array $vars ): array => array_merge( $vars, array( 'erankly_lang', 'erankly_lang_slug' ) ) );
	add_filter( 'rewrite_rules_array', 'erankly_mlss_rewrite_rules' );
	add_filter( 'request', 'erankly_mlss_request', 5 );
	add_filter( 'posts_clauses', 'erankly_mlss_posts_clauses', 20, 2 );
	add_filter( 'term_exists_default_query_args', 'erankly_mlss_sitemap_query' );
	add_filter( 'terms_clauses', 'erankly_mlss_terms_clauses', 20, 3 );
	add_filter( 'the_posts', 'erankly_mlss_prime_posts', 20 );
	add_filter( 'get_terms', 'erankly_mlss_prime_terms', 20 );
	add_filter( 'post_link', 'erankly_mlss_post_link', 20, 2 );
	add_filter( 'post_type_link', 'erankly_mlss_post_link', 20, 2 );
	add_filter( 'page_link', 'erankly_mlss_page_link', 20, 2 );
	add_filter( 'term_link', 'erankly_mlss_term_link', 20, 2 );
	foreach ( array( 'post_type_archive_link', 'author_link', 'year_link', 'month_link', 'day_link', 'search_link', 'feed_link', 'get_pagenum_link' ) as $hook ) {
		add_filter( $hook, 'erankly_mlss_archive_link', 20 );
	}
	add_filter( 'redirect_canonical', 'erankly_mlss_redirect_canonical', 20, 2 );
	add_filter( 'locale', 'erankly_mlss_locale', 20 );
	add_filter( 'option_page_on_front', 'erankly_mlss_page_option' );
	add_filter( 'option_page_for_posts', 'erankly_mlss_page_option' );
	add_filter( 'option_blogname', 'erankly_mlss_blogname' );
	add_filter( 'option_blogdescription', 'erankly_mlss_blogdescription' );
	add_filter( 'wp_nav_menu_args', 'erankly_mlss_menu_args' );
	add_filter( 'wp_nav_menu_objects', 'erankly_mlss_menu_objects' );
	add_filter( 'render_block_data', 'erankly_mlss_block_data', 10, 1 );
	add_filter( 'erankly_breadcrumb_items', 'erankly_mlss_breadcrumb_items' );
	add_filter( 'wp_sitemaps_posts_query_args', 'erankly_mlss_sitemap_query', 30 );
	add_filter( 'wp_sitemaps_taxonomies_query_args', 'erankly_mlss_sitemap_query', 30 );
	add_filter( 'wp_sitemaps_posts_pre_url_list', 'erankly_mlss_sitemap_homepages', 40, 3 );
	add_action( 'wp', 'erankly_mlss_check_route', 5 );
	add_shortcode( 'erankly_language_switcher', 'erankly_mlss_switcher' );
	add_action( 'init', 'erankly_mlss_register_switcher_block' );
}

/** Exclude back-office, REST, CLI and service endpoints from implicit language filters. */
function erankly_mlss_frontend_request(): bool {
	if ( ! erankly_multilingual_enabled() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return false;
	}
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	return ! preg_match( '~(?:^|/)(?:wp-json(?:/|$)|wp-sitemap[^/]*|(?:image|video|news)(?:-sitemap)?[^/]*\.xml|robots\.txt|wp-login\.php|wp-cron\.php)~', $path )
		&& empty( $GLOBALS['wp']->query_vars['sitemap'] ) && empty( $GLOBALS['wp']->query_vars['erankly_sitemap'] );
}

function erankly_mlss_current_language(): string {
	$settings = erankly_mlss_get_settings();
	if ( isset( $GLOBALS['erankly_mlss_request_language'] ) ) {
		return $GLOBALS['erankly_mlss_request_language'];
	}
	$raw = $_GET['erankly_lang'] ?? '';
	if ( is_string( $raw ) && in_array( $raw, $settings['languages'], true ) ) {
		return $raw;
	}
	$path = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$base = (string) wp_parse_url( erankly_mlss_base_home_url( '/' ), PHP_URL_PATH );
	$relative = str_starts_with( $path, $base ) ? substr( $path, strlen( $base ) ) : ltrim( $path, '/' );
	$first = strtok( $relative, '/' );
	$language = array_search( $first, $settings['url_slugs'], true );
	return false !== $language ? $language : $settings['default_language'];
}

/** Get the stored native value without recursively localizing special-page options. */
function erankly_mlss_base_option( string $option ): mixed {
	$previous = $GLOBALS['erankly_mlss_base_option'] ?? false;
	$GLOBALS['erankly_mlss_base_option'] = true;
	try { return get_option( $option ); }
	finally { $GLOBALS['erankly_mlss_base_option'] = $previous; }
}

function erankly_mlss_locale( string $locale ): string {
	if ( ! erankly_mlss_frontend_request() ) { return $locale; }
	$language = erankly_mlss_current_language();
	$defaults = array( 'en' => 'en_US', 'it' => 'it_IT', 'fr' => 'fr_FR', 'de' => 'de_DE', 'es' => 'es_ES', 'pt' => 'pt_PT', 'nl' => 'nl_NL', 'ja' => 'ja', 'zh' => 'zh_CN' );
	$parts = explode( '-', $language );
	$resolved = $defaults[ $language ] ?? $parts[0];
	if ( count( $parts ) > 1 ) {
		$resolved = $parts[0] . '_' . implode( '_', array_map( static fn( string $part ): string => 4 === strlen( $part ) ? ucfirst( $part ) : strtoupper( $part ), array_slice( $parts, 1 ) ) );
	}
	return (string) apply_filters( 'erankly_multilingual_locale', $resolved, $language, $locale );
}

/** One prefixed copy of the native rules regardless of language count. */
function erankly_mlss_rewrite_rules( array $rules ): array {
	if ( ! erankly_multilingual_enabled() ) { return $rules; }
	$settings = erankly_mlss_get_settings();
	$pattern = '(' . implode( '|', array_map( static fn( string $slug ): string => preg_quote( $slug, '~' ), array_values( $settings['url_slugs'] ) ) ) . ')';
	$localized = array( $pattern . '/?$' => 'index.php?erankly_lang_slug=$matches[1]' );
	foreach ( $rules as $regex => $query ) {
		if ( ! str_starts_with( $query, 'index.php?' ) || preg_match( '/(?:sitemap|robots=|rest_route=)/', $query ) || str_contains( $query, 'erankly_lang=' ) || str_contains( $query, 'erankly_lang_slug=' ) ) {
			continue;
		}
		$query = preg_replace_callback( '/\$matches\[(\d+)\]/', static fn( array $match ): string => '$matches[' . ( (int) $match[1] + 1 ) . ']', $query );
		$localized[ $pattern . '/' . ltrim( $regex, '^' ) ] = $query . '&erankly_lang_slug=$matches[1]';
	}
	return $localized + $rules;
}

function erankly_mlss_request( array $vars ): array {
	$settings = erankly_mlss_get_settings();
	if ( isset( $vars['erankly_lang_slug'] ) ) {
		$language = is_string( $vars['erankly_lang_slug'] ) ? array_search( $vars['erankly_lang_slug'], $settings['url_slugs'], true ) : false;
		$vars['erankly_lang'] = $vars['erankly_lang'] ?? ( false !== $language ? $language : '!invalid' );
		unset( $vars['erankly_lang_slug'] );
	}
	unset( $GLOBALS['erankly_mlss_request_language'] );
	$language = $vars['erankly_lang'] ?? erankly_mlss_current_language();
	$GLOBALS['erankly_mlss_invalid_language'] = ! is_string( $language ) || ! in_array( $language, $settings['languages'], true );
	$GLOBALS['erankly_mlss_request_language'] = empty( $GLOBALS['erankly_mlss_invalid_language'] ) ? $language : $settings['default_language'];
	// A custom query variable prevents core's automatic static-front-page detection.
	if ( erankly_mlss_frontend_request() && 'page' === get_option( 'show_on_front' ) && ! array_diff( array_keys( $vars ), array( 'erankly_lang', 'preview', 'page', 'paged', 'cpage' ) ) ) {
		$front = (int) get_option( 'page_on_front' );
		if ( $front ) {
			$vars['page_id'] = $front;
			if ( isset( $vars['paged'] ) ) { $vars['page'] = $vars['paged']; unset( $vars['paged'] ); }
		} else { $vars['error'] = '404'; }
	}
	return $vars;
}

function erankly_mlss_query_language( array $args ): string {
	$language = $args['erankly_lang'] ?? '';
	if ( 'all' === $language || ! erankly_multilingual_enabled() ) { return ''; }
	if ( '' === $language ) {
		if ( ! erankly_mlss_frontend_request() || ! empty( $args['erankly_sitemap_query'] ) || ! empty( $args['sitemap'] ) ) { return ''; }
		$language = erankly_mlss_current_language();
	}
	return is_string( $language ) && in_array( $language, erankly_mlss_get_settings()['languages'], true ) ? $language : '!invalid';
}

/** Indexed EXISTS avoids duplicate result rows, including counts and pagination. */
function erankly_mlss_language_sql( string $kind, string $id_column, string $language ): string {
	global $wpdb;
	$table = erankly_mlss_table();
	$match = $wpdb->prepare( "EXISTS (SELECT 1 FROM $table ml WHERE ml.kind = %s AND ml.object_id = $id_column AND ml.language = %s)", $kind, $language );
	if ( $language === erankly_mlss_get_settings()['default_language'] ) {
		$match .= $wpdb->prepare( " OR NOT EXISTS (SELECT 1 FROM $table ml WHERE ml.kind = %s AND ml.object_id = $id_column)", $kind );
	}
	return ' AND (' . $match . ')';
}

function erankly_mlss_posts_clauses( array $clauses, WP_Query $query ): array {
	global $wpdb;
	$language = erankly_mlss_query_language( $query->query_vars );
	$types = (array) ( $query->get( 'post_type' ) ?: 'post' );
	if ( '' !== $language && ! array_intersect( $types, array( 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_navigation', 'erankly_form' ) ) ) {
		if ( $language !== erankly_mlss_get_settings()['default_language'] ) {
			$clauses['join'] .= $wpdb->prepare( ' INNER JOIN ' . erankly_mlss_table() . " erankly_ml_post ON erankly_ml_post.kind = 'post' AND erankly_ml_post.object_id = $wpdb->posts.ID AND erankly_ml_post.language = %s", $language );
		} else {
			$clauses['where'] .= erankly_mlss_language_sql( 'post', "$wpdb->posts.ID", $language );
		}
	}
	return $clauses;
}

function erankly_mlss_terms_clauses( array $clauses, array $taxonomies, array $args ): array {
	if ( ! empty( $args['object_ids'] ) && ! isset( $args['erankly_lang'] ) ) { return $clauses; }
	$language = erankly_mlss_query_language( $args );
	if ( '' !== $language && ! array_intersect( $taxonomies, array( 'nav_menu', 'post_format', 'wp_theme' ) ) ) {
		global $wpdb;
		if ( $language !== erankly_mlss_get_settings()['default_language'] ) {
			$clauses['join'] .= $wpdb->prepare( ' INNER JOIN ' . erankly_mlss_table() . " erankly_ml_term ON erankly_ml_term.kind = 'term' AND erankly_ml_term.object_id = t.term_id AND erankly_ml_term.language = %s", $language );
		} else {
			$clauses['where'] .= erankly_mlss_language_sql( 'term', 't.term_id', $language );
		}
	}
	return $clauses;
}

function erankly_mlss_prime_posts( array $posts ): array {
	erankly_mlss_prime( 'post', array_map( static fn( $post ): int => $post instanceof WP_Post && '' !== erankly_mlss_object_subtype( 'post', $post->ID ) ? $post->ID : 0, $posts ) );
	return $posts;
}
function erankly_mlss_prime_terms( mixed $terms ): mixed {
	if ( is_array( $terms ) ) { erankly_mlss_prime( 'term', array_map( static fn( $term ): int => $term instanceof WP_Term ? $term->term_id : 0, $terms ) ); }
	return $terms;
}

/** Internal URL only; preserve query/fragment, replace an existing prefix, handle subdirectories. */
function erankly_mlss_url( string $url, string $language ): string {
	$settings = erankly_mlss_get_settings();
	if ( ! erankly_multilingual_enabled() || ! in_array( $language, $settings['languages'], true ) ) { return $url; }
	$home = wp_parse_url( erankly_mlss_base_home_url( '/' ) );
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || ! is_array( $home ) || strtolower( $parts['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) || ( $parts['port'] ?? 0 ) !== ( $home['port'] ?? 0 ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) { return $url; }
	$base_path = trailingslashit( $home['path'] ?? '/' );
	$path = $parts['path'] ?? '/';
	if ( ! str_starts_with( trailingslashit( $path ), $base_path ) ) { return $url; }
	$relative = substr( $path, strlen( $base_path ) );
	$first = strtok( $relative, '/' );
	if ( in_array( $first, $settings['url_slugs'], true ) ) { $relative = ltrim( substr( $relative, strlen( $first ) ), '/' ); }
	if ( preg_match( '~^(?:wp-admin|wp-json|wp-login\.php|wp-sitemap|robots\.txt)(?:/|\b)~', $relative ) ) { return $url; }
	$url = remove_query_arg( 'erankly_lang', $url );
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		return $language !== $settings['default_language'] || $settings['prefix_default'] ? add_query_arg( 'erankly_lang', $language, $url ) : $url;
	}
	$prefix = $language !== $settings['default_language'] || $settings['prefix_default'] ? $settings['url_slugs'][ $language ] . '/' : '';
	$parts = wp_parse_url( $url );
	$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	return $origin . $base_path . $prefix . $relative . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
}

function erankly_mlss_home_url( string $language = '' ): string { return erankly_mlss_url( erankly_mlss_base_home_url( '/' ), $language ?: erankly_mlss_current_language() ); }

function erankly_mlss_post_link( string $url, WP_Post $post ): string {
	return '' !== erankly_mlss_object_subtype( 'post', $post->ID ) ? erankly_mlss_url( $url, erankly_mlss_get_language( 'post', $post->ID ) ) : $url;
}
function erankly_mlss_page_link( string $url, int $id ): string {
	$language = erankly_mlss_get_language( 'post', $id );
	$front = (int) erankly_mlss_base_option( 'page_on_front' );
	if ( 'page' === get_option( 'show_on_front' ) && $front && in_array( $id, erankly_mlss_get_translations( 'post', $front ), true ) ) {
		$url = erankly_mlss_base_home_url( '/' );
	}
	return erankly_mlss_url( $url, $language );
}
function erankly_mlss_term_link( string $url, WP_Term $term ): string { return erankly_mlss_url( $url, erankly_mlss_get_language( 'term', $term->term_id ) ); }
function erankly_mlss_archive_link( string $url ): string { return erankly_mlss_frontend_request() ? erankly_mlss_url( $url, erankly_mlss_current_language() ) : $url; }

function erankly_mlss_page_option( mixed $id ): mixed {
	if ( ! $id || ! empty( $GLOBALS['erankly_mlss_base_option'] ) || ! erankly_mlss_frontend_request() ) { return $id; }
	$language = erankly_mlss_current_language();
	$map = erankly_mlss_get_translations( 'post', (int) $id );
	return $map[ $language ] ?? ( $language === erankly_mlss_get_settings()['default_language'] ? $id : 0 );
}

/** Wrong-language object routes become 404s; never expose the default page under every prefix. */
function erankly_mlss_check_route(): void {
	if ( ! erankly_mlss_frontend_request() ) { return; }
	$kind = is_singular() ? 'post' : ( is_category() || is_tag() || is_tax() ? 'term' : '' );
	$id = get_queried_object_id();
	$route = trim( $GLOBALS['wp']->request ?? '', '/' );
	$root = '' === $route || in_array( $route, erankly_mlss_get_settings()['languages'], true );
	$missing_front = $root && is_home() && 'page' === get_option( 'show_on_front' ) && ! get_option( 'page_on_front' ) && ! is_paged();
	if ( ! empty( $GLOBALS['erankly_mlss_invalid_language'] ) || $missing_front || ( $kind && $id && erankly_mlss_get_language( $kind, $id ) !== erankly_mlss_current_language() ) ) {
		$GLOBALS['wp_query']->set_404();
		status_header( 404 );
	}
}

/** Keep native slash/permalink corrections without dropping a language prefix. */
function erankly_mlss_redirect_canonical( mixed $redirect, string $requested ): mixed {
	if ( ! erankly_mlss_frontend_request() ) { return $redirect; }
	if ( is_404() ) { return false; }
	return is_string( $redirect ) ? erankly_mlss_url( $redirect, erankly_mlss_current_language() ) : $redirect;
}

function erankly_mlss_menu_args( array $args ): array {
	if ( ! erankly_mlss_frontend_request() ) { return $args; }
	$menu = wp_get_nav_menu_object( $args['menu'] ?? '' );
	if ( ! $menu && ! empty( $args['theme_location'] ) ) {
		$locations = get_nav_menu_locations();
		$menu = wp_get_nav_menu_object( $locations[ $args['theme_location'] ] ?? 0 );
	}
	if ( $menu instanceof WP_Term ) {
		$map = erankly_mlss_get_translations( 'term', $menu->term_id );
		if ( isset( $map[ erankly_mlss_current_language() ] ) ) { $args['menu'] = $map[ erankly_mlss_current_language() ]; }
	}
	return $args;
}

function erankly_mlss_menu_objects( array $items ): array {
	if ( ! erankly_mlss_frontend_request() ) { return $items; }
	$language = erankly_mlss_current_language();
	foreach ( $items as $item ) {
		$kind = 'post_type' === $item->type ? 'post' : ( 'taxonomy' === $item->type ? 'term' : '' );
		if ( ! $kind ) { continue; }
		$map = erankly_mlss_get_translations( $kind, (int) $item->object_id );
		$target = $map[ $language ] ?? 0;
		if ( ! $target ) { continue; }
		$url = erankly_mlss_public_url( $kind, $target );
		if ( '' !== $url ) { $item->url = $url; }
	}
	return $items;
}

/** Remap native navigation block references before rendering, not rendered HTML. */
function erankly_mlss_block_data( array $block ): array {
	if ( ! erankly_mlss_frontend_request() || ! in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link' ), true ) ) { return $block; }
	if ( 'core/home-link' === $block['blockName'] ) { $block['attrs']['url'] = erankly_mlss_home_url(); return $block; }
	$attrs = $block['attrs'] ?? array();
	$kind = 'post-type' === ( $attrs['kind'] ?? '' ) ? 'post' : ( 'taxonomy' === ( $attrs['kind'] ?? '' ) ? 'term' : '' );
	if ( $kind && ! empty( $attrs['id'] ) ) {
		$map = erankly_mlss_get_translations( $kind, (int) $attrs['id'] );
		$target = $map[ erankly_mlss_current_language() ] ?? 0;
		$url = $target ? erankly_mlss_public_url( $kind, $target ) : '';
		if ( is_string( $url ) && '' !== $url ) {
			$block['attrs']['id'] = $target;
			$block['attrs']['url'] = $url;
			$block['attrs']['label'] = 'post' === $kind ? get_the_title( $target ) : get_term( $target )->name;
		}
	}
	return $block;
}

function erankly_mlss_breadcrumb_items( array $items ): array {
	if ( isset( $items[0]['url'] ) && $items[0]['url'] === erankly_mlss_base_home_url( '/' ) ) { $items[0]['url'] = erankly_mlss_home_url(); }
	return $items;
}
function erankly_mlss_sitemap_query( array $args ): array { $args['erankly_lang'] = 'all'; return $args; }

/** Include each dynamic homepage even when a sitemap is built outside its HTTP route. */
function erankly_mlss_sitemap_homepages( mixed $list, string $type, int $page ): mixed {
	if ( 'page' !== $type || 1 !== $page || 'posts' !== get_option( 'show_on_front' ) || erankly_get_global_entity_directive( 'global_special_meta', 'homepage', 'noindex' ) || erankly_get_global_entity_directive( 'global_special_meta', 'homepage', 'disable_sitemap' ) ) { return $list; }
	if ( null === $list ) {
		$args = erankly_get_core_sitemap_posts_query_args( 'page' );
		$args['erankly_lang'] = 'all';
		$query = new WP_Query( $args );
		$list = array();
		foreach ( $query->posts as $post ) { $list[] = apply_filters( 'wp_sitemaps_posts_entry', array( 'loc' => get_permalink( $post ), 'lastmod' => wp_date( DATE_W3C, strtotime( $post->post_modified_gmt ) ) ), $post, 'page' ); }
	}
	foreach ( erankly_mlss_get_settings()['languages'] as $language ) {
		$url = erankly_mlss_home_url( $language );
		if ( ! in_array( $url, array_column( $list, 'loc' ), true ) ) { $list[] = apply_filters( 'wp_sitemaps_posts_show_on_front_entry', array( 'loc' => $url ) ); }
	}
	return $list;
}

/** Server-rendered switcher: only existing public equivalents, no frontend CSS or JS. */
function erankly_mlss_switcher(): string {
	$provider = new ERankly_MLSS_Provider();
	$links = $provider->get_alternates( array(), true );
	unset( $links['x-default'] );
	if ( count( $links ) < 2 ) { return ''; }
	$html = '<nav class="erankly-language-switcher" aria-label="' . esc_attr__( 'Languages', 'easyrankly' ) . '"><ul>';
	foreach ( $links as $language => $url ) {
		$html .= '<li><a href="' . esc_url( $url ) . '" lang="' . esc_attr( $language ) . '" hreflang="' . esc_attr( $language ) . '"' . ( $language === erankly_mlss_current_language() ? ' aria-current="page"' : '' ) . '>' . esc_html( $language ) . '</a></li>';
	}
	return $html . '</ul></nav>';
}
function erankly_mlss_register_switcher_block(): void {
	wp_register_script( 'erankly-mlss-switcher-block', ERANKLY_URL . 'assets/multilingual/singlesite/switcher-block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), ERANKLY_VERSION, true );
	wp_set_script_translations( 'erankly-mlss-switcher-block', 'easyrankly', ERANKLY_PATH . 'languages' );
	register_block_type( 'easyrankly/language-switcher', array( 'api_version' => 3, 'editor_script' => 'erankly-mlss-switcher-block', 'render_callback' => 'erankly_mlss_switcher', 'supports' => array( 'html' => false ) ) );
}

function erankly_mlss_public_url( string $kind, int $id ): string {
	if ( 'post' === $kind ) {
		$post = get_post( $id );
		if ( ! $post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) { return ''; }
		$url = get_permalink( $post );
	} else {
		$term = get_term( $id );
		if ( ! $term instanceof WP_Term || ! is_taxonomy_viewable( get_taxonomy( $term->taxonomy ) ) ) { return ''; }
		$url = get_term_link( $term );
	}
	return is_string( $url ) ? $url : '';
}

/** Localize native home links only after routing, preserving the stored home base. */
function erankly_mlss_filter_home_url( string $url, string $path ): string {
	return ! empty( $GLOBALS['erankly_mlss_localize_home'] ) && empty( $GLOBALS['erankly_mlss_raw_home'] ) && in_array( $path, array( '', '/' ), true ) && erankly_mlss_frontend_request()
		? erankly_mlss_url( $url, erankly_mlss_current_language() ) : $url;
}
function erankly_mlss_base_home_url( string $path = '/' ): string {
	$previous = $GLOBALS['erankly_mlss_raw_home'] ?? false;
	$GLOBALS['erankly_mlss_raw_home'] = true;
	try { return home_url( $path ); }
	finally { $GLOBALS['erankly_mlss_raw_home'] = $previous; }
}
