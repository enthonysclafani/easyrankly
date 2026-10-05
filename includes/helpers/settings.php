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

/**
 * Merges changes into the stored settings, or replaces them. A replacement keeps extension keys that are not part
 * of the core default model (filterable through erankly_preserved_extension_settings).
 *
 * @param array<string,mixed> $changes Changes or replacement snapshot.
 * @param bool                $replace Replace the snapshot instead of merging.
 */
function erankly_update_plugin_settings( array $changes, bool $replace = false ): bool {
	$current = is_multisite() ? get_site_option( ERANKLY_OPTION, array() ) : get_option( ERANKLY_OPTION, array() );
	$current = is_array( $current ) ? $current : array();

	if ( $replace ) {
		erankly_load_default_helpers();
		$extension_settings = apply_filters( 'erankly_preserved_extension_settings', array_diff_key( $current, erankly_default_settings() ), $changes );
		$next               = array_replace( is_array( $extension_settings ) ? $extension_settings : array(), $changes );
	} else {
		$next = array_replace( $current, $changes );
	}

	$updated = is_multisite()
		? update_site_option( ERANKLY_OPTION, $next )
		: update_option( ERANKLY_OPTION, $next, true );

	erankly_clear_settings_cache();

	// update_option() also returns false when the (sanitized) value already matches storage; that is a success.
	global $wpdb;

	return $updated || '' === (string) $wpdb->last_error;
}

/** @return array<int,string> */
function erankly_settings_toggle_keys(): array {
	$keys = array(
		'enable_seo',
		'enable_tools',
		'global_post_type_meta_linked',
		'global_taxonomy_meta_linked',
		'enable_local_business',
		'enable_sitemap',
		'enable_news_sitemap',
		'enable_image_sitemap',
		'enable_video_sitemap',
		'enable_breadcrumbs',
		'noindex_paginated',
		'noindex_paginated_content',
		'nofollow_paginated',
		'noindex_feeds',
		'robots_nosnippet',
		'robots_noimageindex',
		'robots_notranslate',
		'robots_indexifembedded',
		'enable_redirects',
		'enable_forms',
		'enable_multilingual',
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
