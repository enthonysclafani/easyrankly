<?php
/** Shared helpers: settings access and feature flags. Part of the helpers.php loader; always loaded early on every request. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns settings merged with the complete dynamic default model. Prefer erankly_get_setting() for runtime
 * reads; this full merge is intended for settings forms, activation/reset and partial-write normalization.
 *
 * @return array<string,mixed>
 */
function erankly_get_settings(): array {
	if ( isset( $GLOBALS['erankly_settings_cache'] ) && is_array( $GLOBALS['erankly_settings_cache'] ) ) {
		return $GLOBALS['erankly_settings_cache'];
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	$GLOBALS['erankly_settings_cache'] = wp_parse_args( $settings, erankly_default_settings() );

	return $GLOBALS['erankly_settings_cache'];
}

/**
 * Returns only persisted settings, cached for the current request. Unlike erankly_get_settings(), this does not
 * build dynamic defaults. Runtime feature checks should use this path with an explicit per-key fallback.
 *
 * @return array<string,mixed>
 */
function erankly_get_stored_settings(): array {
	if ( isset( $GLOBALS['erankly_stored_settings_cache'] ) && is_array( $GLOBALS['erankly_stored_settings_cache'] ) ) {
		return $GLOBALS['erankly_stored_settings_cache'];
	}

	$settings = is_multisite()
		? get_site_option( ERANKLY_OPTION, array() )
		: get_option( ERANKLY_OPTION, array() );

	$GLOBALS['erankly_stored_settings_cache'] = is_array( $settings ) ? $settings : array();

	return $GLOBALS['erankly_stored_settings_cache'];
}

/** Clears the request-level settings cache after settings change. */
function erankly_clear_settings_cache(): void {
	unset( $GLOBALS['erankly_settings_cache'], $GLOBALS['erankly_stored_settings_cache'] );
}

/** @return array<int,string> */
function erankly_settings_toggle_keys(): array {
	$keys = array(
		'social_defaults_linked',
		'global_post_type_meta_linked',
		'global_taxonomy_meta_linked',
		'enable_local_business',
		'simplified_mode',
		'resolve_placeholders',
		'enable_sitemap',
		'enable_news_sitemap',
		'enable_image_sitemap',
		'enable_video_sitemap',
		'enable_breadcrumbs',
		'enable_website_search_action',
		'noindex_paginated',
		'noindex_paginated_content',
		'nofollow_paginated',
		'noindex_feeds',
		'robots_nosnippet',
		'robots_noimageindex',
		'robots_notranslate',
		'robots_indexifembedded',
		'enable_redirects',
		'enable_custom_code',
	);

	/**
 * Filters setting keys backed by standalone on/off toggles. Add-ons must register keys they render as checkboxes
 * so a partial Features save does not leave them stuck on.
 */
	$keys = apply_filters( 'erankly_settings_toggle_keys', $keys );

	return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
}

/**
 * Setting keys backed by a repeatable block builder.
 *
 * These render as a list of blocks, so deleting every block leaves no field behind and the key disappears from
 * the submission entirely. Without this list the merge below would read that absence as "untouched" and restore
 * the stored blocks, which is how a cleared Custom code panel kept printing its snippets on the frontend.
 *
 * @return array<int,string>
 */
function erankly_settings_collection_keys(): array {
	$keys = array(
		'global_schema_blocks',
		'head_code_blocks',
		'body_open_code_blocks',
		'body_close_code_blocks',
	);

	/**
 * Filters setting keys backed by repeatable block builders. Add-ons must register keys they render as a
 * block list so clearing the list actually clears the stored value.
 */
	$keys = apply_filters( 'erankly_settings_collection_keys', $keys );

	return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
}

/**
 * Registers an add-on checkbox in the Features panel as one atomic pipeline.
 *
 * Calling this during plugin loading wires the visible control, the server and
 * client autosave allowlists, unchecked-toggle handling, refresh behavior and
 * any repeatable collections owned by the feature. This avoids a control that
 * renders and submits successfully but is silently discarded by a missing
 * companion filter.
 *
 * The render callback receives the complete settings array and the sanitized
 * setting key. It must print the checkbox field whose name uses that key.
 *
 * @param string              $setting_key    Boolean setting key.
 * @param callable            $render_callback Callback used by the Features renderer.
 * @param array<int,string>   $collection_keys Repeatable setting collections owned by the feature.
 */
function erankly_register_settings_feature_module( string $setting_key, callable $render_callback, array $collection_keys = array() ): void {
	$setting_key    = sanitize_key( $setting_key );
	$collection_keys = array_values( array_filter( array_map( 'sanitize_key', $collection_keys ) ) );

	if ( '' === $setting_key ) {
		return;
	}

	add_action(
		'erankly_settings_features_modules',
		static function ( array $settings ) use ( $render_callback, $setting_key ): void {
			call_user_func( $render_callback, $settings, $setting_key );
		}
	);

	add_filter(
		'erankly_settings_toggle_keys',
		static function ( array $keys ) use ( $setting_key ): array {
			$keys[] = $setting_key;
			return array_values( array_unique( $keys ) );
		}
	);

	if ( $collection_keys ) {
		add_filter(
			'erankly_settings_collection_keys',
			static function ( array $keys ) use ( $collection_keys ): array {
				return array_values( array_unique( array_merge( $keys, $collection_keys ) ) );
			}
		);
	}

	add_filter(
		'erankly_settings_autosave_panels',
		static function ( array $panels ) use ( $setting_key, $collection_keys ): array {
			$panels['features']         = isset( $panels['features'] ) && is_array( $panels['features'] ) ? $panels['features'] : array();
			$panels['features']['keys'] = isset( $panels['features']['keys'] ) && is_array( $panels['features']['keys'] ) ? $panels['features']['keys'] : array();
			$panels['features']['keys'] = array_values( array_unique( array_merge( $panels['features']['keys'], array( $setting_key ), $collection_keys ) ) );
			return $panels;
		}
	);

	add_filter(
		'erankly_settings_autosave_client_panels',
		static function ( array $panels ) use ( $setting_key ): array {
			$panels['features']                = isset( $panels['features'] ) && is_array( $panels['features'] ) ? $panels['features'] : array();
			$panels['features']['restUrl']     = $panels['features']['restUrl'] ?? esc_url_raw( rest_url( 'erankly/v1/settings/features' ) );
			$panels['features']['reloadOnSave'] = true;
			$refresh_keys                       = isset( $panels['features']['refreshKeys'] ) && is_array( $panels['features']['refreshKeys'] ) ? $panels['features']['refreshKeys'] : array();
			$panels['features']['refreshKeys']  = array_values( array_unique( array_merge( $refresh_keys, array( $setting_key ) ) ) );
			return $panels;
		}
	);
}

/**
 * @param string $panel Panel slug such as "features" or "general".
 * @return array<int,string>
 */
function erankly_settings_panel_keys( string $panel ): array {
	$panel = sanitize_key( $panel );

	if ( '' === $panel || ! function_exists( 'erankly_settings_autosave_panels' ) ) {
		return array();
	}

	$registry = erankly_settings_autosave_panels();

	return isset( $registry[ $panel ]['keys'] ) && is_array( $registry[ $panel ]['keys'] )
		? $registry[ $panel ]['keys']
		: array();
}

/**
 * Merges a partial settings submission over the stored map. Classic HTML forms only send the active panel.
 * Unchecked toggles are omitted, so absent toggle keys in the submitted panel scope default to off before merge.
 *
 * @param array<string,mixed> $input Raw submitted settings fragment.
 * @param string              $panel Panel slug from erankly_settings_panel.
 * @return array<string,mixed>
 */
function erankly_merge_settings_submission( array $input, string $panel = '' ): array {
	$stored   = erankly_get_settings();
	$panel    = sanitize_key( $panel );

	if ( '' !== $panel ) {
		$panel_keys      = erankly_settings_panel_keys( $panel );
		$toggle_keys     = array_fill_keys( erankly_settings_toggle_keys(), true );
		$collection_keys = array_fill_keys( erankly_settings_collection_keys(), true );

		foreach ( $panel_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( isset( $toggle_keys[ $key ] ) ) {
				$input[ $key ] = 0;
				continue;
			}

			// An emptied block builder submits no field at all: absence means "cleared", not "unchanged".
			if ( isset( $collection_keys[ $key ] ) ) {
				$input[ $key ] = array();
			}
		}
	}

	return array_replace( $stored, $input );
}

/** @param string $active_panel Active tab slug such as "settings-features". */
function erankly_active_panel_submission_slug( string $active_panel ): string {
	if ( str_starts_with( $active_panel, 'settings-' ) ) {
		return sanitize_key( substr( $active_panel, 9 ) );
	}

	return sanitize_key( $active_panel );
}

function erankly_get_setting( string $key, mixed $default_value = null ): mixed {
	$settings    = erankly_get_stored_settings();
	$value       = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default_value;
	$provider    = function_exists( 'erankly_get_multilingual_provider' ) ? erankly_get_multilingual_provider() : null;
	$query       = $GLOBALS['wp_query'] ?? null;
	$query_ready = did_action( 'wp' ) > 0
		|| ( $query instanceof WP_Query && ( $query->is_singular || $query->is_home || $query->is_front_page || $query->is_archive || $query->is_search || $query->is_404 || $query->is_feed ) );
	$context     = $provider instanceof ERankly_Multilingual_Provider_Interface && $query_ready ? erankly_get_multilingual_context() : array();

	/** @param mixed               $value         Stored or default value. */
	return apply_filters( 'erankly_setting_value', $value, $key, $default_value, $context );
}

/**
 * Moves the per-post-type Schema.org types out of global_post_type_meta into their own setting. They used to
 * ride along with the title and description rows, which meant the "Same for all" toggle hid them for every
 * content type but the first, and a General save could drop them.
 */
function erankly_maybe_migrate_post_type_schema(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_post_type_schema_v1', false ) ) {
		return;
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	if ( empty( $settings ) || isset( $settings['global_post_type_schema'] ) ) {
		erankly_update_plugin_option( 'erankly_migrated_post_type_schema_v1', true );

		return;
	}

	$legacy = ( isset( $settings['global_post_type_meta'] ) && is_array( $settings['global_post_type_meta'] ) )
		? $settings['global_post_type_meta']
		: array();
	$schema = array();

	foreach ( array_keys( erankly_get_public_post_types() ) as $post_type ) {
		$post_type = (string) $post_type;
		$row       = ( isset( $legacy[ $post_type ] ) && is_array( $legacy[ $post_type ] ) ) ? $legacy[ $post_type ] : array();
		$defaults  = erankly_default_post_type_schema_row( $post_type );

		$webpage_type = array_key_exists( 'webpage_type', $row ) ? erankly_sanitize_schema_type_name( $row['webpage_type'] ) : '';
		// An empty stored value was how the retired free-text field expressed
		// "emit no Article node", so it maps to the explicit "none" choice.
		$article_type = array_key_exists( 'article_type', $row )
			? ( '' === trim( (string) $row['article_type'] ) ? 'none' : erankly_sanitize_schema_type_name( $row['article_type'] ) )
			: '';

		$schema[ $post_type ] = array(
			'webpage_type' => '' !== $webpage_type ? $webpage_type : $defaults['webpage_type'],
			'article_type' => '' !== $article_type ? $article_type : $defaults['article_type'],
		);
	}

	foreach ( $legacy as $post_type => $row ) {
		if ( is_array( $row ) ) {
			unset( $legacy[ $post_type ]['webpage_type'], $legacy[ $post_type ]['article_type'] );
		}
	}

	$settings['global_post_type_schema'] = $schema;
	$settings['global_post_type_meta']   = $legacy;

	erankly_update_plugin_settings( $settings, '', true );
	erankly_clear_settings_cache();
	erankly_update_plugin_option( 'erankly_migrated_post_type_schema_v1', true );
}

/** Applies one-time settings migrations. */
function erankly_maybe_migrate_settings(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_title_defaults_v1', false ) ) {
		return;
	}

	erankly_load_default_helpers();
	$settings = erankly_get_stored_settings();

	if ( empty( $settings ) ) {
		erankly_update_plugin_option( 'erankly_migrated_title_defaults_v1', true );

		return;
	}

	$changed             = false;
	$legacy_post_title   = '{{post_title}} - {{site_name}}';
	$legacy_term_title   = '{{term_name}} - {{site_name}}';
	$title_template_keys = array( 'global_post_type_meta', 'global_taxonomy_meta' );
	$single_title_keys   = array( 'default_og_title', 'default_twitter_title' );

	foreach ( $title_template_keys as $meta_key ) {
		if ( empty( $settings[ $meta_key ] ) || ! is_array( $settings[ $meta_key ] ) ) {
			continue;
		}

		foreach ( $settings[ $meta_key ] as $entity_key => $meta ) {
			if ( ! is_array( $meta ) || ! isset( $meta['title'] ) ) {
				continue;
			}

			$replacement = 'global_taxonomy_meta' === $meta_key ? '{{term_name}}' : '{{post_title}}';
			$legacy      = 'global_taxonomy_meta' === $meta_key ? $legacy_term_title : $legacy_post_title;

			if ( $legacy === (string) $meta['title'] ) {
				$settings[ $meta_key ][ $entity_key ]['title'] = $replacement;
				$changed                                       = true;
			}
		}
	}

	foreach ( $single_title_keys as $title_key ) {
		if ( isset( $settings[ $title_key ] ) && $legacy_post_title === (string) $settings[ $title_key ] ) {
			$settings[ $title_key ] = '{{post_title}}';
			$changed                = true;
		}
	}

	if ( array_key_exists( 'website_name', $settings ) && '' === trim( (string) $settings['website_name'] ) ) {
		unset( $settings['website_name'] );
		$changed = true;
	}

	if ( array_key_exists( 'website_description', $settings ) && '' === trim( (string) $settings['website_description'] ) ) {
		unset( $settings['website_description'] );
		$changed = true;
	}

	if ( $changed ) {
		erankly_update_plugin_settings( $settings, '', true );
		erankly_clear_settings_cache();
	}

	erankly_update_plugin_option( 'erankly_migrated_title_defaults_v1', true );
}

/**
 * Returns the published page ID at one relative path on one site, switching blog context only when the caller is
 * sweeping other sites of the network.
 *
 * @param int    $blog_id Site ID to search.
 * @param string $path    Relative path such as "contatti" or "/contatti/".
 * @return int Published page ID, or 0 when none exists.
 */
function erankly_find_published_page_id( int $blog_id, string $path ): int {
	$switched = is_multisite() && get_current_blog_id() !== $blog_id;

	if ( $switched ) {
		switch_to_blog( $blog_id );
	}

	try {
		$page = get_page_by_path( trim( $path, '/' ), OBJECT, 'page' );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $page instanceof WP_Post && 'publish' === $page->post_status ? (int) $page->ID : 0;
}

/**
 * Option that stores the in-progress LocalBusiness page-map checkpoint on Multisite.
 *
 * Networks already marked `erankly_migrated_local_business_pages_v1` are not backfilled: that marker means a
 * previous run finished (including the old 200-site cap). Manual page selections stay as stored. Frontend
 * still falls back to `local_business_page_path` when a site is missing from the map.
 */
function erankly_local_business_pages_migration_checkpoint_option(): string {
	return 'erankly_local_business_pages_migration_checkpoint';
}

/**
 * @param mixed $map Raw blog_id => page_id map.
 * @return array<int,int>
 */
function erankly_normalize_local_business_page_map( mixed $map ): array {
	if ( ! is_array( $map ) ) {
		return array();
	}

	$normalized = array();

	foreach ( $map as $blog_id => $page_id ) {
		$blog_id = (int) $blog_id;
		$page_id = absint( $page_id );

		if ( $blog_id > 0 && $page_id > 0 ) {
			$normalized[ $blog_id ] = $page_id;
		}
	}

	return $normalized;
}

/**
 * Identifies one LocalBusiness path on one network so a changed input cannot resume a foreign checkpoint.
 */
function erankly_local_business_pages_migration_input_id( string $path ): string {
	$network_id = is_multisite() ? (int) get_current_network_id() : 0;

	return hash( 'sha256', $network_id . "\n" . erankly_sanitize_relative_path( $path ) );
}

function erankly_local_business_pages_migration_lease_ttl(): int {
	return defined( 'WP_CLI' ) && WP_CLI ? 300 : 30;
}

/**
 * @return array{token:string,owns:bool}|null
 */
function erankly_local_business_pages_migration_claim_lock( string $lock_token ): ?array {
	$owns_lock = '' === $lock_token;

	if ( $owns_lock ) {
		$lock_token = erankly_acquire_settings_lock( erankly_local_business_pages_migration_lease_ttl() );
		if ( is_wp_error( $lock_token ) ) {
			return null;
		}
	} elseif ( ! erankly_settings_lock_is_valid( $lock_token ) ) {
		return null;
	}

	return array(
		'token' => $lock_token,
		'owns'  => $owns_lock,
	);
}

/**
 * @param array<string,mixed> $stored   Stored checkpoint.
 * @param array<string,mixed> $expected Expected checkpoint.
 */
function erankly_local_business_pages_checkpoint_matches( array $stored, array $expected ): bool {
	return (string) ( $stored['input'] ?? '' ) === (string) ( $expected['input'] ?? '' )
		&& (int) ( $stored['network_id'] ?? 0 ) === (int) ( $expected['network_id'] ?? 0 )
		&& (string) ( $stored['path'] ?? '' ) === (string) ( $expected['path'] ?? '' )
		&& (int) ( $stored['last_site_id'] ?? -1 ) === (int) ( $expected['last_site_id'] ?? 0 )
		&& erankly_normalize_local_business_page_map( $stored['map'] ?? array() ) === erankly_normalize_local_business_page_map( $expected['map'] ?? array() );
}

/**
 * @return array<string,mixed>
 */
function erankly_get_local_business_pages_checkpoint(): array {
	$checkpoint = erankly_get_plugin_option( erankly_local_business_pages_migration_checkpoint_option(), array() );

	return is_array( $checkpoint ) ? $checkpoint : array();
}

function erankly_delete_local_business_pages_checkpoint( string $lock_token = '' ): bool {
	$claim = erankly_local_business_pages_migration_claim_lock( $lock_token );

	if ( null === $claim ) {
		return false;
	}

	try {
		$option = erankly_local_business_pages_migration_checkpoint_option();

		if ( is_multisite() ) {
			delete_site_option( $option );

			return false === get_site_option( $option, false );
		}

		delete_option( $option );

		return false === get_option( $option, false );
	} finally {
		if ( $claim['owns'] ) {
			erankly_release_settings_lock( $claim['token'] );
		}
	}
}

/**
 * @param array<string,mixed> $checkpoint Checkpoint payload.
 */
function erankly_save_local_business_pages_checkpoint( array $checkpoint, string $lock_token = '' ): bool {
	$claim = erankly_local_business_pages_migration_claim_lock( $lock_token );

	if ( null === $claim ) {
		return false;
	}

	try {
		$option = erankly_local_business_pages_migration_checkpoint_option();

		try {
			erankly_update_plugin_option( $option, $checkpoint );
		} catch ( RuntimeException ) {
			return false;
		}

		global $wpdb;

		if ( '' !== (string) $wpdb->last_error ) {
			return false;
		}

		return erankly_local_business_pages_checkpoint_matches( erankly_get_local_business_pages_checkpoint(), $checkpoint );
	} finally {
		if ( $claim['owns'] ) {
			erankly_release_settings_lock( $claim['token'] );
		}
	}
}

function erankly_mark_local_business_pages_migration_complete( string $lock_token = '' ): bool {
	$claim = erankly_local_business_pages_migration_claim_lock( $lock_token );

	if ( null === $claim ) {
		return false;
	}

	try {
		try {
			erankly_update_plugin_option( 'erankly_migrated_local_business_pages_v1', true );
		} catch ( RuntimeException ) {
			return false;
		}

		return (bool) erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false );
	} finally {
		if ( $claim['owns'] ) {
			erankly_release_settings_lock( $claim['token'] );
		}
	}
}

/**
 * Persists the finished LocalBusiness page map under the settings mutex, then marks the migration complete.
 *
 * The map is merged into current settings so a concurrent admin save is not replaced. Completion is declared
 * only after the map (when non-empty) is re-read from storage. A write error leaves the checkpoint in place.
 * A worker whose lease expired, or whose input no longer matches the stored path, cannot mark completion.
 *
 * @param array<int,int> $map               Blog ID => published page ID discovered by this run.
 * @param string         $lock_token        Existing settings lock token, if any.
 * @param string         $expected_input_id Input identity this result was built for.
 */
function erankly_complete_local_business_pages_migration( array $map, string $lock_token = '', string $expected_input_id = '' ): bool {
	$claim = erankly_local_business_pages_migration_claim_lock( $lock_token );

	if ( null === $claim ) {
		return false;
	}

	$lock_token = $claim['token'];

	try {
		if ( ! erankly_renew_settings_lock( $lock_token, erankly_local_business_pages_migration_lease_ttl() ) ) {
			return false;
		}

		erankly_clear_settings_cache();
		$current       = erankly_get_stored_settings();
		$current_path  = erankly_sanitize_relative_path( $current['local_business_page_path'] ?? '' );
		$current_input = erankly_local_business_pages_migration_input_id( $current_path );

		if ( '' !== $expected_input_id && $expected_input_id !== $current_input ) {
			return false;
		}

		$current_map = erankly_normalize_local_business_page_map( $current['local_business_pages'] ?? array() );
		$next_map    = $current_map + $map;

		if ( $next_map !== $current_map ) {
			$result = erankly_update_plugin_settings(
				array( 'local_business_pages' => $next_map ),
				$lock_token,
				false
			);

			if ( is_wp_error( $result ) || ! $result ) {
				return false;
			}

			erankly_clear_settings_cache();
			$persisted = erankly_normalize_local_business_page_map(
				erankly_get_stored_settings()['local_business_pages'] ?? array()
			);

			foreach ( $next_map as $blog_id => $page_id ) {
				if ( (int) ( $persisted[ $blog_id ] ?? 0 ) !== (int) $page_id ) {
					return false;
				}
			}
		}

		if ( ! erankly_mark_local_business_pages_migration_complete( $lock_token ) ) {
			return false;
		}

		erankly_delete_local_business_pages_checkpoint( $lock_token );

		return true;
	} finally {
		if ( $claim['owns'] ) {
			erankly_release_settings_lock( $lock_token );
		}
	}
}

/**
 * Resolves the LocalBusiness path on a bounded batch of network sites.
 *
 * Web requests process one keyset page per init and persist a checkpoint so a large network cannot run
 * thousands of switch_to_blog calls in a single request. WP-CLI walks every remaining batch in this call.
 * Deleted, spam and archived blogs are skipped: they are not live mapping targets.
 *
 * Advancement and finalization share the settings mutex so concurrent init workers and a manual save cannot
 * rewrite a stale snapshot. After the lock is acquired the stored path is re-read: a caller-supplied path is
 * never trusted once another writer may have replaced it. A changed path or network identity discards the
 * previous checkpoint. Checkpoint, marker and completion writes require a live lease; a lost lease does not
 * fall back to saving this worker's checkpoint.
 *
 * @param string $path       Ignored once the lock is held; kept so existing callers stay valid.
 * @param int    $batch_size Keyset page size. Defaults to ERANKLY_NETWORK_SITE_BATCH_SIZE.
 * @param string $lock_token Existing settings lock token, if any.
 */
function erankly_advance_local_business_pages_migration( string $path = '', int $batch_size = 0, string $lock_token = '' ): void {
	unset( $path );

	$claim = erankly_local_business_pages_migration_claim_lock( $lock_token );

	if ( null === $claim ) {
		return;
	}

	$lock_token = $claim['token'];
	$batch_size = $batch_size > 0 ? $batch_size : ERANKLY_NETWORK_SITE_BATCH_SIZE;
	$cli        = defined( 'WP_CLI' ) && WP_CLI;
	$ttl        = erankly_local_business_pages_migration_lease_ttl();

	try {
		do {
			if ( ! erankly_renew_settings_lock( $lock_token, $ttl ) ) {
				return;
			}

			erankly_clear_settings_cache();
			$settings = erankly_get_stored_settings();
			$existing = erankly_normalize_local_business_page_map( $settings['local_business_pages'] ?? array() );
			$path     = erankly_sanitize_relative_path( $settings['local_business_page_path'] ?? '' );
			$input_id = erankly_local_business_pages_migration_input_id( $path );

			if ( ! empty( $existing ) || '' === $path ) {
				erankly_complete_local_business_pages_migration( array(), $lock_token, $input_id );

				return;
			}

			$network_id = is_multisite() ? (int) get_current_network_id() : 0;
			$checkpoint = erankly_get_local_business_pages_checkpoint();

			if ( $checkpoint && (string) ( $checkpoint['input'] ?? '' ) !== $input_id ) {
				$checkpoint = array();
			}

			$last_site_id = isset( $checkpoint['last_site_id'] ) ? (int) $checkpoint['last_site_id'] : 0;
			$map          = erankly_normalize_local_business_page_map( $checkpoint['map'] ?? array() );

			$site_ids = erankly_get_network_site_ids_batch( $last_site_id, $batch_size + 1, true );
			$has_more = count( $site_ids ) > $batch_size;

			if ( $has_more ) {
				array_pop( $site_ids );
			}

			foreach ( $site_ids as $blog_id ) {
				$page_id = erankly_find_published_page_id( $blog_id, $path );

				if ( $page_id > 0 ) {
					$map[ $blog_id ] = $page_id;
				}
			}

			if ( $site_ids ) {
				$last_site_id = (int) end( $site_ids );
			}

			if ( ! erankly_renew_settings_lock( $lock_token, $ttl ) ) {
				return;
			}

			$next_checkpoint = array(
				'input'        => $input_id,
				'network_id'   => $network_id,
				'path'         => $path,
				'last_site_id' => $last_site_id,
				'map'          => $map,
			);

			if ( ! $has_more ) {
				if ( ! erankly_complete_local_business_pages_migration( $map, $lock_token, $input_id ) ) {
					erankly_clear_settings_cache();
					$stored_path = erankly_sanitize_relative_path(
						erankly_get_stored_settings()['local_business_page_path'] ?? ''
					);

					if (
						erankly_settings_lock_is_valid( $lock_token )
						&& erankly_local_business_pages_migration_input_id( $stored_path ) === $input_id
					) {
						erankly_save_local_business_pages_checkpoint( $next_checkpoint, $lock_token );
					}
				}

				return;
			}

			if ( ! erankly_save_local_business_pages_checkpoint( $next_checkpoint, $lock_token ) ) {
				return;
			}

			if ( ! $cli ) {
				return;
			}
		} while ( true );
	} catch ( RuntimeException ) {
		// The site list could not be read. Leave the migration marker unset so a
		// later request retries instead of failing this one.
	} finally {
		if ( $claim['owns'] ) {
			erankly_release_settings_lock( $lock_token );
		}
	}
}

/**
 * Migrates the shared LocalBusiness path into a per-blog page ID map.
 *
 * Reads the stored path only after acquiring the settings mutex so a concurrent save cannot change the
 * input between the identity calculation and the work that publishes the map.
 */
function erankly_maybe_migrate_local_business_pages(): void {
	if ( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) ) {
		if ( erankly_get_local_business_pages_checkpoint() ) {
			erankly_delete_local_business_pages_checkpoint();
		}

		return;
	}

	$claim = erankly_local_business_pages_migration_claim_lock( '' );

	if ( null === $claim ) {
		return;
	}

	$lock_token = $claim['token'];

	try {
		if ( erankly_get_plugin_option( 'erankly_migrated_local_business_pages_v1', false ) ) {
			erankly_delete_local_business_pages_checkpoint( $lock_token );

			return;
		}

		erankly_load_default_helpers();
		erankly_clear_settings_cache();
		$settings = erankly_get_stored_settings();

		if ( empty( $settings ) ) {
			erankly_delete_local_business_pages_checkpoint( $lock_token );
			erankly_mark_local_business_pages_migration_complete( $lock_token );

			return;
		}

		$existing = erankly_normalize_local_business_page_map( $settings['local_business_pages'] ?? array() );

		if ( ! empty( $existing ) ) {
			erankly_delete_local_business_pages_checkpoint( $lock_token );
			erankly_mark_local_business_pages_migration_complete( $lock_token );

			return;
		}

		$path = erankly_sanitize_relative_path( $settings['local_business_page_path'] ?? '' );

		if ( '' === $path ) {
			erankly_delete_local_business_pages_checkpoint( $lock_token );
			erankly_mark_local_business_pages_migration_complete( $lock_token );

			return;
		}

		$input_id = erankly_local_business_pages_migration_input_id( $path );

		if ( ! is_multisite() ) {
			$map     = array();
			$page_id = erankly_find_published_page_id( get_current_blog_id(), $path );

			if ( $page_id > 0 ) {
				$map[ get_current_blog_id() ] = $page_id;
			}

			if ( ! erankly_complete_local_business_pages_migration( $map, $lock_token, $input_id ) ) {
				if ( erankly_settings_lock_is_valid( $lock_token ) ) {
					erankly_save_local_business_pages_checkpoint(
						array(
							'input'        => $input_id,
							'network_id'   => 0,
							'path'         => $path,
							'last_site_id' => get_current_blog_id(),
							'map'          => $map,
						),
						$lock_token
					);
				}
			}

			return;
		}

		erankly_advance_local_business_pages_migration( $path, 0, $lock_token );
	} finally {
		erankly_release_settings_lock( $lock_token );
	}
}
