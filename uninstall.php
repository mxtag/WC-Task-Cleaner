<?php
/**
 * Uninstall handler for WC Task Cleaner.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Perform uninstall cleanup for a single site.
 */
function wctc_uninstall_on_site() {
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	global $wpdb;

	// Drop the plugin-owned log table (safe and intentional during uninstall).
	$table_name = esc_sql( $wpdb->prefix . 'wc_task_cleaner_logs' );
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
	// phpcs:enable

	// Remove plugin options.
	delete_option( 'wc_task_cleaner_settings' );
	delete_option( 'wc_task_cleaner_version' );

	// If transients with a known prefix existed, they could be deleted here as well.
}

/**
 * Multisite compatibility: perform cleanup on all subsites.
 */
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	if ( $site_ids ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			wctc_uninstall_on_site();
			restore_current_blog();
		}
	}
} else {
	wctc_uninstall_on_site();
}
