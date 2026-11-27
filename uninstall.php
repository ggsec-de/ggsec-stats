<?php
/**
 * Removes all GGSEC Stats data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removes the plugin's own table on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ggsec_stats_hits' ) );

delete_option( 'ggsec_stats_db_version' );
delete_option( 'ggsec_stats_salt' );
delete_option( 'ggsec_stats_salt_day' );
delete_option( 'ggsec_stats_notify' );
delete_option( 'ggsec_stats_notify_state' );
wp_clear_scheduled_hook( 'ggsec_stats_daily' );
wp_clear_scheduled_hook( 'ggsec_stats_hourly' );
