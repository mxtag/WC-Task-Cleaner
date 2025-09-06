<?php
/**
 * Uninstall handler for WC Task Cleaner.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Plugin-owned table.
$table_name = $wpdb->prefix . 'wc_task_cleaner_logs';

// Safe: plugin table maintenance (identifier escaped). Placeholders don't support identifiers.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

// Clean options.
delete_option( 'wc_task_cleaner_version' );
