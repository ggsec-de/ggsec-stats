<?php
/**
 * Removes all GGSEC Stats data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ggsec_stats_hits' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.

foreach ( array( 'ggsec_stats_db_version', 'ggsec_stats_salt', 'ggsec_stats_salt_day' ) as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'ggsec_stats_daily' );
