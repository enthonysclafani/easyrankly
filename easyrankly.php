<?php
/**
 * Plugin Name: EasyRankly
 * Plugin URI:  https://easyrankly.com
 * Description: Lightweight, modular, developer-first SEO essentials for WordPress.
 * Version:     2.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author:      EasyRankly
 * Author URI:  https://easyrankly.com/
 * Text Domain: easyrankly
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * Network:     true
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bail if WordPress loads this file twice in one request (e.g. during a ZIP update).
if ( defined( 'ERANKLY_VERSION' ) ) {
	return;
}

define( 'ERANKLY_VERSION', '2.0.0' );
define( 'ERANKLY_EXTENSION_API_VERSION', 1 );
define( 'ERANKLY_FILE', __FILE__ );
define( 'ERANKLY_PATH', plugin_dir_path( __FILE__ ) );
define( 'ERANKLY_URL', plugin_dir_url( __FILE__ ) );
define( 'ERANKLY_OPTION', 'erankly_settings' );
// Special-page metadata is stored per site on Multisite (see erankly_get_site_special_meta()).
define( 'ERANKLY_SPECIAL_META_OPTION', 'erankly_special_meta' );
define( 'ERANKLY_VERSION_OPTION', 'erankly_version' );
define( 'ERANKLY_REWRITE_FLUSH_OPTION', 'erankly_flush_rewrite_rules' );
define( 'ERANKLY_SITEMAP_TRANSIENT_PREFIX', 'erankly_sitemap_' );
define( 'ERANKLY_SITEMAP_CACHE_VERSION_OPTION', 'erankly_sitemap_cache_version' );
define( 'ERANKLY_REWRITE_SIGNATURE_OPTION', 'erankly_rewrite_signature' );
define( 'ERANKLY_REWRITE_GENERATION_OPTION', 'erankly_rewrite_generation' );
define( 'ERANKLY_REDIRECTS_CACHE_GENERATION_OPTION', 'erankly_redirects_cache_generation' );
define( 'ERANKLY_NETWORK_SITE_BATCH_SIZE', 100 );
define( 'ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT', 20 );
define( 'ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT', 50 );
define( 'ERANKLY_NETWORK_WEB_LIFECYCLE_LIMIT', 100 );
define( 'ERANKLY_MIGRATION_ACTIVE_JOB_OPTION', 'erankly_migration_active_job_v1' );
define( 'ERANKLY_MIGRATION_CRON_HOOK', 'erankly_migration_process_batch' );
define( 'ERANKLY_MIGRATION_BATCH_SIZE', 100 );
define( 'ERANKLY_IMPORT_ACTIVE_JOB_OPTION', 'erankly_import_active_job_v1' );
define( 'ERANKLY_IMPORT_LAST_RESULT_OPTION', 'erankly_import_last_result_v1' );
define( 'ERANKLY_IMPORT_BATCH_SIZE', 100 );

/** Native export document version. Bumped when the JSON structure changes. */
define( 'ERANKLY_EXPORT_FORMAT', '4.0' );

/** Maximum supported nesting depth for the EasyRankly export schema. */
define( 'ERANKLY_IMPORT_JSON_MAX_DEPTH', 64 );

require_once ERANKLY_PATH . 'includes/helpers.php';
require_once ERANKLY_PATH . 'includes/localized-value-writer.php';
require_once ERANKLY_PATH . 'includes/class-erankly-multilingual-provider-registry.php';
require_once ERANKLY_PATH . 'includes/seo-state.php';

/** Gets a plugin option using network storage on Multisite. */
function erankly_get_plugin_option( string $key, mixed $default_value = false ): mixed {
	return is_multisite() ? get_site_option( $key, $default_value ) : get_option( $key, $default_value );
}

/**
 * Updates a plugin option using network storage on Multisite.
 *
 * @throws RuntimeException When the settings update fails.
 */
function erankly_update_plugin_option( string $key, mixed $value ): void {
	if ( ERANKLY_OPTION === $key ) {
		if ( ! erankly_update_plugin_settings( is_array( $value ) ? $value : array() ) ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not update its settings.', 'easyrankly' ) );
		}
	} elseif ( is_multisite() ) {
		update_site_option( $key, $value );
	} else {
		// The version and rewrite generation are read on every request, so autoload them; other options aren't.
		update_option( $key, $value, in_array( $key, array( ERANKLY_VERSION_OPTION, ERANKLY_REWRITE_GENERATION_OPTION ), true ) );
	}
}

require_once ERANKLY_PATH . 'includes/compatibility.php';
require_once ERANKLY_PATH . 'includes/meta.php';
require_once ERANKLY_PATH . 'includes/meta-visibility.php';
require_once ERANKLY_PATH . 'includes/robots.php';
require_once ERANKLY_PATH . 'includes/special-meta.php';

if ( is_admin() ) {
	require_once ERANKLY_PATH . 'includes/admin.php';
}

/** Boots the plugin after all plugins are available for compatibility checks. */
function erankly_bootstrap(): void {
	erankly_suspend_legacy_multilingual_addon();
	// Disabled content shortcodes stay invisible without loading their implementations.
	add_shortcode( 'easyrankly_form', '__return_empty_string' );
	if ( ! erankly_seo_enabled() ) {
		add_shortcode( 'erankly_breadcrumbs', '__return_empty_string' );
	}
	erankly_boot_feature_modules();

	add_action( 'admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_action( 'network_admin_notices', 'erankly_render_multilingual_provider_notices' );
	add_filter( 'debug_information', 'erankly_add_multilingual_debug_information' );
	add_action( 'update_option_' . ERANKLY_SPECIAL_META_OPTION, 'erankly_handle_sitemap_visibility_updated', 10, 2 );
	add_action( ERANKLY_MIGRATION_CRON_HOOK, 'erankly_process_migration_job' );
	add_action( 'init', 'erankly_register_rewrites' );
	add_action( 'init', 'erankly_maybe_flush_after_upgrade', 20 );
	add_action( 'init', 'erankly_maybe_flush_rewrite_rules', 30 );

	if ( is_admin() ) {
		erankly_admin_bootstrap();
	}
	add_action( 'rest_api_init', 'erankly_register_settings_autosave_route' );

	if ( is_multisite() ) {
		add_action( 'update_site_option_' . ERANKLY_OPTION, 'erankly_handle_network_settings_updated', 10, 3 );
	} else {
		add_action( 'update_option_' . ERANKLY_OPTION, 'erankly_handle_settings_updated', 10, 2 );
	}

	/** Fires after the manager boots the enabled modules; public helpers remain available for add-ons. */
	do_action( 'erankly_bootstrap' );
}
add_action( 'plugins_loaded', 'erankly_bootstrap', 5 );
add_action( 'plugins_loaded', 'erankly_close_multilingual_provider_registry', 20 );

/**
 * Lazily loads and advances one resumable third-party migration batch. Keeping the adapters out of ordinary
 * frontend requests preserves the plugin's modular bootstrap while still registering a WP-Cron callback early.
 */
function erankly_process_migration_job( string $job_id ): void {
	require_once ERANKLY_PATH . 'includes/migrations.php';
	erankly_migration_job_runner()->process( $job_id );
}

/**
 * Rotates the network-wide generation used by per-site rewrite signatures. A fresh generation on every
 * activation guarantees that sites skipped by a bounded network deactivation rebuild their rules after
 * reactivation, even if another component flushed the rules while EasyRankly was inactive.
 *
 * @throws RuntimeException When the generation cannot be persisted.
 */
function erankly_rotate_rewrite_generation(): void {
	$generation = wp_generate_uuid4();

	erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, $generation );

	if ( (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' ) !== $generation ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not initialize its rewrite generation.', 'easyrankly' ) );
	}
}

/** @throws RuntimeException When initialization fails. */
function erankly_activate(): void {
	erankly_load_default_helpers();
	$is_new_install = false === erankly_get_plugin_option( ERANKLY_OPTION, false );

	if ( $is_new_install ) {
		if ( ! erankly_update_plugin_settings( erankly_default_settings(), true ) ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not initialize its settings.', 'easyrankly' ) );
		}
	}

	if ( is_multisite() ) {
		add_site_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
	} else {
		add_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION, '', true );
	}

	erankly_rotate_rewrite_generation();
	erankly_register_rewrites();
	flush_rewrite_rules( false );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, erankly_get_rewrite_signature(), true );
}
register_activation_hook( ERANKLY_FILE, 'erankly_activate' );

/**
 * Returns a keyset-paginated batch of site IDs for the current network.
 *
 * Deactivation keeps $active_only false so deleted, spam and archived blogs are
 * still swept. Admin pickers pass true to skip those sites. That is stricter than core get_sites() defaults,
 * which leave deleted/spam/archived unfiltered (null) and do not limit network_id=0
 * to the current network.
 *
 * @param int  $after_site_id Return sites whose ID is greater than this value.
 * @param int  $limit         Maximum IDs to return.
 * @param bool $active_only   Whether to skip deleted, spam and archived blogs.
 * @return int[]
 * @throws RuntimeException When the site batch cannot be read.
 */
function erankly_get_network_site_ids_batch(
	int $after_site_id = 0,
	int $limit = ERANKLY_NETWORK_SITE_BATCH_SIZE,
	bool $active_only = false
): array {
	global $wpdb;

	$after      = max( 0, $after_site_id );
	$limit      = max( 1, $limit );
	$network_id = (int) get_current_network_id();

	if ( $active_only ) {
		$site_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps lifecycle sweeps bounded.
			$wpdb->prepare(
				'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d AND deleted = %d AND spam = %d AND archived = %d ORDER BY blog_id ASC LIMIT %d',
				$wpdb->blogs,
				$network_id,
				$after,
				0,
				0,
				0,
				$limit
			)
		);
	} else {
		$site_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination keeps lifecycle sweeps bounded.
			$wpdb->prepare(
				'SELECT blog_id FROM %i WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d',
				$wpdb->blogs,
				$network_id,
				$after,
				$limit
			)
		);
	}

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not retrieve the next network site batch.', 'easyrankly' ) );
	}

	return array_map( 'intval', (array) $site_ids );
}

/**
 * Counts sites in the current network.
 *
 * @throws RuntimeException When the site count cannot be read.
 */
function erankly_get_current_network_site_count(): int {
	global $wpdb;

	$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The count selects the safe lifecycle execution path.
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE site_id = %d',
			$wpdb->blogs,
			(int) get_current_network_id()
		)
	);

	if ( $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not count the current network sites.', 'easyrankly' ) );
	}

	return (int) $count;
}

/** Returns whether a network lifecycle sweep must run through WP-CLI. */
function erankly_network_lifecycle_requires_cli(): bool {
	$limit = (int) apply_filters( 'erankly_network_web_lifecycle_limit', ERANKLY_NETWORK_WEB_LIFECYCLE_LIMIT );

	return erankly_get_current_network_site_count() > max( 1, $limit );
}

/**
 * Returns the rewrite configuration currently expected by this site. Every site stores the last signature it
 * applied. An activation, plugin upgrade, or network-wide sitemap setting change alters this value
 * automatically, so the next request to each site can rebuild its own rules without scanning the network or
 * coordinating a background job.
 */
function erankly_get_rewrite_signature(): string {
	$generation = (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '0' );

	return ERANKLY_VERSION . ':' . $generation . ':' . ( erankly_sitemap_enabled() ? '1' : '0' );
}

/**
 * Records the plugin version after an upgrade. Per-site rewrite updates are handled independently by
 * erankly_maybe_flush_rewrite_rules() through the lazy rewrite signature.
 */
function erankly_maybe_flush_after_upgrade(): void {
	$stored = (string) erankly_get_plugin_option( ERANKLY_VERSION_OPTION, '' );

	if ( ERANKLY_VERSION === $stored ) {
		return;
	}

	// Updates skip the activation hook: store the autoloaded rewrite generation it would have created.
	if ( '' === (string) erankly_get_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, '' ) ) {
		erankly_update_plugin_option( ERANKLY_REWRITE_GENERATION_OPTION, wp_generate_uuid4() );
	}
	erankly_update_plugin_option( ERANKLY_VERSION_OPTION, ERANKLY_VERSION );
}

/** Adapter for the update_site_option_ hook, which passes args in a different order. */
function erankly_handle_network_settings_updated( string $option, mixed $value, mixed $old_value ): void {
	erankly_handle_settings_updated( $old_value, $value );
}

function erankly_handle_settings_updated( mixed $old_value, mixed $value ): void {
	erankly_clear_settings_cache();

	$old_value = is_array( $old_value ) ? $old_value : array();
	$value     = is_array( $value ) ? $value : array();
	if ( ! is_multisite() && (bool) ( $old_value['enable_multilingual'] ?? false ) !== (bool) ( $value['enable_multilingual'] ?? false ) ) {
		delete_option( 'rewrite_rules' );
		wp_unschedule_hook( 'erankly_multilingual_migrate' );
	}
	$keys      = array(
		'enable_seo',
		'enable_multilingual',
		'enable_sitemap',
		'enable_news_sitemap',
		'news_sitemap_post_types',
		'news_publication_name',
		'enable_image_sitemap',
		'enable_video_sitemap',
		'global_post_type_meta',
		'global_taxonomy_meta',
		'global_special_meta',
	);

	foreach ( $keys as $key ) {
		if ( ( $old_value[ $key ] ?? null ) !== ( $value[ $key ] ?? null ) ) {
			erankly_load_sitemap_helpers();
			erankly_flush_sitemap_cache();
			break;
		}
	}
}

/** Invalidates author/home/archive sitemap state stored outside the main settings option. */
function erankly_handle_sitemap_visibility_updated( mixed $old_value, mixed $value ): void {
	if ( $old_value === $value ) {
		return;
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
}

/** Lazily applies the current rewrite signature to this site. */
function erankly_maybe_flush_rewrite_rules(): void {
	$signature      = erankly_get_rewrite_signature();
	$last_signature = (string) get_option( ERANKLY_REWRITE_SIGNATURE_OPTION, '' );

	if ( $signature === $last_signature ) {
		return;
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
	flush_rewrite_rules( false );
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	update_option( ERANKLY_REWRITE_SIGNATURE_OPTION, $signature, true );
}

/**
 * Removes deactivation-only state from the current site. Clears the migration WP-Cron hook so pending
 * migration and rollback pages cannot fire after reactivation. Active job checkpoints are intentionally retained
 * so an administrator can resume from the admin UI. Uninstall deletes those checkpoints; deactivation must not.
 *
 * @throws RuntimeException When a scheduled task cannot be removed.
 */
function erankly_deactivate_current_site(): void {
	wp_unschedule_hook( 'erankly_multilingual_migrate' );
	$result = wp_unschedule_hook( ERANKLY_MIGRATION_CRON_HOOK, true );

	if ( false === $result || is_wp_error( $result ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its scheduled tasks during deactivation.', 'easyrankly' ) );
	}

	erankly_load_sitemap_helpers();
	erankly_flush_sitemap_cache();
	delete_option( ERANKLY_REWRITE_FLUSH_OPTION );
	delete_option( ERANKLY_REWRITE_SIGNATURE_OPTION );
	// Invalidate the stored rules. Core rebuilds them without EasyRankly on the
	// site's next request; no costly hard flush is needed here.
	delete_option( 'rewrite_rules' );
}

/** @param bool $network_deactivating Whether this is a network deactivation. */
function erankly_deactivate( bool $network_deactivating = false ): void {
	if ( is_multisite() && $network_deactivating ) {
		if (
			! ( defined( 'WP_CLI' ) && WP_CLI )
			&& erankly_network_lifecycle_requires_cli()
		) {
			$plugin_slug = dirname( plugin_basename( ERANKLY_FILE ) );
			$command     = sprintf( 'wp plugin deactivate %s --network', $plugin_slug );
			$message     = '<p>' . esc_html__( 'This network is too large to deactivate EasyRankly safely in one web request.', 'easyrankly' ) . '</p>';
			$message    .= '<p>' . esc_html__( 'Run the following WP-CLI command so every site can remove its scheduled tasks and rewrite rules:', 'easyrankly' ) . '</p>';
			$message    .= '<p><code>' . esc_html( $command ) . '</code></p>';

			wp_die(
				$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup contains only escaped translated text and command output.
				esc_html__( 'EasyRankly network cleanup required', 'easyrankly' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}

		$last_site_id = 0;

		do {
			$site_ids   = erankly_get_network_site_ids_batch( $last_site_id );
			$site_count = count( $site_ids );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );

				try {
					erankly_deactivate_current_site();
				} catch ( Throwable $error ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
						error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Multisite deactivation diagnostics when WP_DEBUG is enabled.
							sprintf(
								'EasyRankly network deactivation failed for site %d: %s',
								(int) $site_id,
								$error->getMessage()
							)
						);
					}
				} finally {
					restore_current_blog();
				}
			}

			if ( $site_ids ) {
				$last_site_id = (int) end( $site_ids );
			}
		} while ( ERANKLY_NETWORK_SITE_BATCH_SIZE === $site_count );

		return;
	}

	erankly_deactivate_current_site();
}
register_deactivation_hook( ERANKLY_FILE, 'erankly_deactivate' );

function erankly_register_user_search_route(): void {
	register_rest_route(
		'erankly/v1',
		'/users/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'erankly_rest_user_search',
			'permission_callback' => static fn() => current_user_can( 'manage_options' ) || current_user_can( 'manage_network_options' ),
			'args'                => array(
				'q' => array(
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

/**
 * Handles the user search REST request. Returns up to 20 users matching the query. Network-wide lookups (blog_id
 * = 0) are reserved for users who can manage the whole network; a regular site admin is scoped to the members of
 * their own site so they cannot enumerate every account on the network. On single-site the blog_id is ignored.
 *
 * @return WP_REST_Response
 */
function erankly_rest_user_search( WP_REST_Request $request ): WP_REST_Response {
	$query = (string) $request->get_param( 'q' );

	// Only network managers may search across every site; everyone else is
	// limited to their current site, which on single-site means all users.
	$blog_id = ( is_multisite() && ! current_user_can( 'manage_network_options' ) )
		? get_current_blog_id()
		: 0;

	$args = array(
		'blog_id' => $blog_id, // 0 = network-wide on multisite; ignored on single-site.
		'number'  => 20,
		'orderby' => 'display_name',
		'order'   => 'ASC',
		'fields'  => array( 'ID', 'display_name' ),
	);

	if ( '' !== $query ) {
		$args['search']         = '*' . $query . '*';
		// Email remains a search column for administrators; it is not returned in the JSON.
		$args['search_columns'] = array( 'user_login', 'user_nicename', 'display_name', 'user_email' );
	}

	$users   = get_users( $args ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_users_get_users -- intentional admin-only user lookup with strict capability check.
	$results = array();

	foreach ( $users as $user ) {
		if ( ! isset( $user->ID, $user->display_name ) ) {
			continue;
		}

		$results[] = array(
			'id'     => (int) $user->ID,
			/* translators: 1: User display name, 2: User ID. */
			'text'   => sprintf( __( '%1$s (ID: %2$d)', 'easyrankly' ), $user->display_name, $user->ID ),
			'name'   => (string) $user->display_name,
			/* translators: %d: User ID shown instead of an email address. */
			'meta'   => sprintf( __( 'ID %d', 'easyrankly' ), (int) $user->ID ),
			'avatar' => (string) get_avatar_url( (int) $user->ID, array( 'size' => 48 ) ),
		);
	}

	return new WP_REST_Response( $results, 200 );
}

function erankly_register_local_business_routes(): void {
	$permission = static fn() => current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );

	register_rest_route(
		'erankly/v1',
		'/local-business/sites',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'erankly_rest_local_business_sites',
			'permission_callback' => $permission,
			'args'                => array(
				'after' => array(
					'default'           => 0,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'erankly/v1',
		'/local-business/pages',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'erankly_rest_local_business_pages',
			'permission_callback' => $permission,
			'args'                => array(
				'blog_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'q'       => array(
					'default'           => '',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'offset'  => array(
					'default'           => 0,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_local_business_sites( WP_REST_Request $request ) {
	$after = absint( $request->get_param( 'after' ) );
	$map   = erankly_normalize_local_business_page_map( erankly_get_setting( 'local_business_pages', array() ) );
	$sites = erankly_get_local_business_site_choices( $after, ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT, $map );

	return new WP_REST_Response(
		array(
			'sites'   => $sites,
			'hasMore' => count( $sites ) === ERANKLY_LOCAL_BUSINESS_SITE_CHOICE_LIMIT,
		),
		200
	);
}

/**
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_local_business_pages( WP_REST_Request $request ) {
	$blog_id = absint( $request->get_param( 'blog_id' ) );

	if ( ! erankly_local_business_site_is_selectable( $blog_id ) ) {
		return new WP_Error( 'erankly_unknown_site', __( 'Unknown site.', 'easyrankly' ), array( 'status' => 404 ) );
	}

	$search   = (string) $request->get_param( 'q' );
	$offset   = absint( $request->get_param( 'offset' ) );
	$map      = erankly_normalize_local_business_page_map( erankly_get_setting( 'local_business_pages', array() ) );
	$include  = isset( $map[ $blog_id ] ) ? absint( $map[ $blog_id ] ) : 0;
	$has_more = false;
	$pages    = erankly_get_local_business_published_pages(
		$blog_id,
		$search,
		ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT,
		$include,
		$offset,
		$has_more
	);

	return new WP_REST_Response(
		array(
			'blog_id'    => $blog_id,
			'pages'      => $pages,
			'hasMore'    => $has_more,
			'offset'     => $offset,
			'nextOffset' => $offset + ERANKLY_LOCAL_BUSINESS_PAGE_CHOICE_LIMIT,
		),
		200
	);
}

/**
 * Registers the REST route that autosaves settings panels. One route serves every autosave-enabled panel (see
 * erankly_settings_autosave_panels() in admin/settings-page.php for the per-panel whitelist registry); the
 * `panel` slug is validated against that registry inside the handler, not the route pattern, so this never needs
 * editing again as panels are added. The char class is just a safe charset for a path segment, not an allowlist
 * (the registry lookup is what actually prevents an unknown or cross-panel request from touching anything).
 */
function erankly_register_settings_autosave_route(): void {
	register_rest_route(
		'erankly/v1',
		'/settings/(?P<panel>[a-z-]+)',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'erankly_rest_save_settings_panel',
			// Multisite stores these settings network-wide (see erankly_get_settings()),
			// so editing them there requires the network capability, not just the
			// per-site one. A subsite admin must not be able to reach this route.
			'permission_callback' => static fn() => current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ),
			'args'                => array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Saves a partial payload from a settings panel autosave. Looks up the requested panel in
 * erankly_settings_autosave_panels(), merges its whitelisted fields onto the currently stored settings (so
 * panels that aren't part of this request are left untouched), optionally runs a panel-specific normalize hook,
 * then runs the result through the same sanitizer the full options.php submission uses and persists it. Several
 * of those admin-only helpers (erankly_use_site_editor_special_page_panels(),
 * add_settings_error()/get_settings_errors()) aren't loaded on a bare REST request the way they are on wp-admin
 * requests, so they're pulled in on demand here. On Multisite,
 * erankly_get_settings()/erankly_update_plugin_option() already route through the network-wide site option
 * regardless of which admin screen the request came from, so no Network Admin detection is needed here. The
 * permission_callback is what keeps subsite admins out.
 *
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_save_settings_panel( WP_REST_Request $request ) {
	erankly_load_content_helpers();
	require_once ERANKLY_PATH . 'includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once ERANKLY_PATH . 'admin/settings-page.php';

	$panel_key = sanitize_key( (string) $request['panel'] );
	$registry  = erankly_settings_autosave_panels();

	if ( ! isset( $registry[ $panel_key ] ) ) {
		return new WP_Error( 'erankly_unknown_settings_panel', __( 'Unknown settings panel.', 'easyrankly' ), array( 'status' => 404 ) );
	}
	$module = match ( $panel_key ) {
		'seo', 'general', 'social', 'schema', 'advanced', 'special-pages' => 'seo',
		'sitemap' => 'sitemap',
		'custom-code' => 'custom-code',
		default => '',
	};
	if ( '' !== $module && ! erankly_feature_module_enabled( $module ) ) {
		return new WP_Error( 'erankly_disabled_settings_module', __( 'This feature module is disabled.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	$panel_config = $registry[ $panel_key ];
	$payload      = (array) $request->get_param( 'settings' );
	$changes      = array_intersect_key( $payload, array_flip( $panel_config['keys'] ) );
	$merged       = array_merge( erankly_get_settings(), $changes );

	if ( ! empty( $panel_config['normalize'] ) ) {
		$merged = call_user_func( $panel_config['normalize'], $merged, $changes );
	}

	$sanitized = erankly_sanitize_settings( $merged );

	try {
		erankly_update_plugin_option( ERANKLY_OPTION, $sanitized );
	} catch ( RuntimeException $exception ) {
		return new WP_Error(
			'erankly_settings_save_failed',
			$exception->getMessage(),
			array( 'status' => 500 )
		);
	}

	$notices  = function_exists( 'get_settings_errors' ) ? get_settings_errors( ERANKLY_OPTION ) : array();
	$errors   = array();
	$warnings = array();

	foreach ( $notices as $notice ) {
		$message = isset( $notice['message'] ) ? (string) $notice['message'] : '';
		if ( '' === $message ) {
			continue;
		}
		if ( isset( $notice['type'] ) && 'error' === $notice['type'] ) {
			$errors[] = $message;
		} else {
			$warnings[] = $message;
		}
	}

	return new WP_REST_Response(
		array(
			'saved'      => true,
			'incomplete' => array() !== $errors,
			'errors'     => $errors,
			'warnings'   => $warnings,
		),
		200
	);
}

/**
 * Registers the REST route that autosaves the per-site "Special pages and archives" panel, the Multisite
 * fallback for sites that can't use the Site Editor panels (see erankly_use_site_editor_special_page_panels()).
 * Kept separate from erankly_register_settings_autosave_route(): this panel doesn't merge into
 * ERANKLY_OPTION/erankly_get_settings() the way every other panel does. erankly_update_special_meta_map()
 * already owns reading, sanitizing and writing this data (a dedicated per-site option on Multisite), so it
 * doesn't fit the shared registry's shape.
 */
function erankly_register_special_pages_autosave_route(): void {
	register_rest_route(
		'erankly/v1',
		'/settings/special-pages',
		array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'erankly_rest_save_special_pages',
			// Always per-site, even on Multisite: this panel is only ever
			// shown to a per-site admin (see $is_site_admin_on_network in
			// admin/settings-page.php), never to Network Admin, so it doesn't
			// need the manage_network_options ternary the other route uses.
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'args'                => array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			),
		)
	);
}

/**
 * Saves the "Special pages and archives" autosave payload. Uses erankly_update_special_meta_map()
 * (includes/special-meta.php, always loaded), which already sanitizes its input and routes the write to the
 * correct storage, so the whitelisted map is passed straight through with no merge step. Unlike
 * erankly_rest_save_settings_panel(), there's no risk of this payload clobbering another panel's fields since
 * this data isn't part of ERANKLY_OPTION on Multisite at all.
 *
 * @return WP_REST_Response|WP_Error
 */
function erankly_rest_save_special_pages( WP_REST_Request $request ) {
	erankly_load_content_helpers();
	$payload = (array) $request->get_param( 'settings' );
	$map     = isset( $payload['global_special_meta'] ) && is_array( $payload['global_special_meta'] ) ? $payload['global_special_meta'] : array();

	try {
		erankly_update_special_meta_map( $map );
	} catch ( RuntimeException $exception ) {
		return new WP_Error(
			'erankly_settings_save_failed',
			$exception->getMessage(),
			array( 'status' => 500 )
		);
	}

	return new WP_REST_Response(
		array(
			'saved'    => true,
			'warnings' => array(),
		),
		200
	);
}
