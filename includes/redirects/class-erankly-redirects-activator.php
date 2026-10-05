<?php
/**
 * Redirects table schema. Creates or upgrades the custom table through dbDelta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ERankly_Redirects_Activator {
	public static function activate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = ERankly_Redirects_Repository::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_path VARCHAR(512) NOT NULL,
			source_hash CHAR(32) NOT NULL,
			rule_hash CHAR(32) NULL DEFAULT NULL,
			source_query VARCHAR(512) NOT NULL DEFAULT '',
			target_url TEXT NOT NULL,
			status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			match_type VARCHAR(20) NOT NULL DEFAULT 'exact',
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			trailing_slash VARCHAR(20) NOT NULL DEFAULT 'ignore',
			query_mode VARCHAR(20) NOT NULL DEFAULT 'ignore',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			source_plugin VARCHAR(40) NOT NULL DEFAULT '',
			source_reference VARCHAR(191) NOT NULL DEFAULT '',
			migration_id VARCHAR(64) NOT NULL DEFAULT '',
			note TEXT NULL,
			hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_hit_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_hash (source_hash),
			UNIQUE KEY rule_hash (rule_hash),
			KEY is_active (is_active),
			KEY migration_id (migration_id),
			KEY match_type (match_type)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
