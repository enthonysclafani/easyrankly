<?php
/**
 * Uninstall cleanup. Removes the plugin-owned table, options, transients, metadata and private uploads for every
 * site of the installation, then deletes the network options. A failed query aborts instead of reporting a
 * finished uninstall.
 *
 * Every plugin option, transient and network option shares the `erankly_` prefix (add-on rows that chose the same
 * namespace included); every plugin meta key shares `_erankly_`. With a persistent object cache, transients never
 * written to SQL cannot be enumerated: the long-lived ones are deleted by name, while per-user notices and
 * versioned sitemap caches expire on their own (5 minutes and one hour).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/helpers/redirect-cache.php';
require_once __DIR__ . '/includes/migrations/class-erankly-migration-upload-store.php';
require_once __DIR__ . '/includes/class-erankly-import-job-runner.php';

/**
 * Runs one cleanup statement.
 *
 * @throws RuntimeException When the statement fails.
 */
function erankly_uninstall_query( string $sql ): void {
	global $wpdb;

	if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Callers pass statements prepared for plugin-owned rows.
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its data during uninstall.', 'easyrankly' ) );
	}
}

/**
 * Deletes the current site's plugin options and SQL transients, then drops their object-cache entries.
 *
 * @throws RuntimeException When the rows cannot be read or deleted.
 */
function erankly_uninstall_delete_options(): void {
	global $wpdb;

	$where = $wpdb->prepare(
		'option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name IN ( %s, %s )',
		$wpdb->esc_like( 'erankly_' ) . '%',
		$wpdb->esc_like( '_transient_erankly_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_erankly_' ) . '%',
		'erml_ms_settings',
		'erml_ms_version'
	);
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WHERE clause prepared above.

	if ( '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its data during uninstall.', 'easyrankly' ) );
	}

	erankly_uninstall_query( "DELETE FROM {$wpdb->options} WHERE {$where}" );

	foreach ( (array) $names as $name ) {
		wp_cache_delete( (string) $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	// Long-lived transients that a persistent object cache may hold without an SQL row.
	foreach ( array( 'erankly_redirect_cache_rotation_failed', 'erankly_visibility_' . md5( '_erankly_exclude_search' ), 'erankly_visibility_' . md5( '_erankly_exclude_archive' ) ) as $transient ) {
		delete_transient( $transient );
	}
}

/** Removes private contact forms and their revisions even when the module is disabled. */
function erankly_uninstall_delete_forms(): void {
	global $wpdb;

	// Use the native deletion API to remove form revisions and all associated metadata too.
	$form_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'erankly_form' ) );
	if ( '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its data during uninstall.', 'easyrankly' ) );
	}
	foreach ( $form_ids as $form_id ) {
		if ( ! wp_delete_post( (int) $form_id, true ) ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove its data during uninstall.', 'easyrankly' ) );
		}
	}

}

/**
 * Removes the current site's EasyRankly data.
 *
 * @throws RuntimeException When a cleanup step fails.
 */
function erankly_uninstall_site(): void {
	global $wpdb;

	if ( ! ERankly_Import_Job_Runner::purge_all() || ! ERankly_Migration_Upload_Store::purge_all( true ) ) {
		throw new RuntimeException( esc_html__( 'EasyRankly could not remove its private uploads during uninstall.', 'easyrankly' ) );
	}

	erankly_uninstall_delete_forms();

	wp_unschedule_hook( 'erankly_migration_process_batch' );
	wp_unschedule_hook( 'erankly_multilingual_migrate' );
	erankly_uninstall_delete_options();
	// Core rebuilds its rewrite rules without the EasyRankly routes on the next request.
	delete_option( 'rewrite_rules' );

	erankly_uninstall_query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_redirects' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops plugin-owned storage.
	erankly_uninstall_query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'erankly_languages' ) );
	if ( wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'erankly_languages' );
	}
	erankly_redirects_flush_external_caches();
	if ( wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'erankly_redirects' );
	}

	// User meta is network-global on Multisite; repeating the delete per site is harmless.
	foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ) as $table ) {
		erankly_uninstall_query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $table, $wpdb->esc_like( '_erankly_' ) . '%' ) );
	}
}

/**
 * Deletes every plugin network option of one network.
 *
 * @throws RuntimeException When an option cannot be removed.
 */
function erankly_uninstall_network( int $network_id ): void {
	global $wpdb;

	$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates plugin-owned network options.
		$wpdb->prepare( 'SELECT meta_key FROM %i WHERE site_id = %d AND ( meta_key LIKE %s OR meta_key IN ( %s, %s ) )', $wpdb->sitemeta, $network_id, $wpdb->esc_like( 'erankly_' ) . '%', 'erml_ms_settings', 'erml_ms_version' )
	);

	foreach ( (array) $names as $name ) {
		if ( ! delete_network_option( $network_id, (string) $name ) ) {
			throw new RuntimeException( esc_html__( 'EasyRankly could not remove its data during uninstall.', 'easyrankly' ) );
		}
	}
}

if ( defined( 'ERANKLY_UNINSTALL_FUNCTIONS_ONLY' ) && ERANKLY_UNINSTALL_FUNCTIONS_ONLY ) {
	return;
}

try {
	if ( is_multisite() ) {
		// Plugin files disappear as soon as uninstall returns, so the cleanup cannot continue through WP-Cron.
		// Refuse a web request that could time out halfway and point large installations to WP-CLI.
		$erankly_limit = max( 1, (int) apply_filters( 'erankly_network_web_lifecycle_limit', 100 ) );
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && get_sites( array( 'count' => true ) ) > $erankly_limit ) {
			wp_die(
				'<p>' . esc_html__( 'This installation is too large to uninstall EasyRankly safely in one web request.', 'easyrankly' ) . '</p>'
				. '<p>' . esc_html__( 'Run the following WP-CLI command so cleanup can finish without an HTTP timeout:', 'easyrankly' ) . '</p>'
				. '<p><code>' . esc_html( sprintf( 'wp plugin uninstall %s', basename( __DIR__ ) ) ) . '</code></p>',
				esc_html__( 'EasyRankly network cleanup required', 'easyrankly' ),
				array(
					'response'  => 409,
					'back_link' => true,
				)
			);
		}

		// Every site of every network: plugin deletion removes the shared files installation-wide.
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $erankly_site_id ) {
			switch_to_blog( (int) $erankly_site_id );
			try {
				erankly_uninstall_site();
			} finally {
				restore_current_blog();
			}
		}

		// Network settings go last, so a failed site sweep never leaves sites configured without them.
		foreach ( get_networks( array( 'fields' => 'ids', 'number' => 0 ) ) as $erankly_network_id ) {
			erankly_uninstall_network( (int) $erankly_network_id );
		}
	} else {
		erankly_uninstall_site();
	}
} catch ( Throwable $erankly_uninstall_error ) {
	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Uninstall has no persistent plugin logger; server logs retain the actionable internal failure.
		sprintf( 'EasyRankly uninstall failed (%1$s): %2$s', get_class( $erankly_uninstall_error ), sanitize_text_field( $erankly_uninstall_error->getMessage() ) )
	);

	$erankly_public_error = esc_html__( 'EasyRankly could not complete its data cleanup. No further cleanup steps were attempted. Check the server error log, resolve the reported storage problem, and retry the uninstall.', 'easyrankly' );
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $erankly_public_error );
	}

	wp_die(
		esc_html( $erankly_public_error ),
		esc_html__( 'EasyRankly uninstall incomplete', 'easyrankly' ),
		array(
			'response'  => 500,
			'back_link' => true,
		)
	);
}
