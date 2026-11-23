<?php
/**
 * Plugin Name: GGSEC Stats
 * Description: Privacy-first page view statistics: humans vs bots, referrers, top pages, online now. No cookies, no external services.
 * Version:     1.2.1
 * Author:      GG Advanced IT Security UG
 * License:     GPL-2.0+
 * Requires at least: 6.3
 * Requires PHP: 7.4
 *
 * Optional constants in wp-config.php:
 *   define( 'GGSEC_STATS_RETENTION_DAYS', 90 );   // days raw hits are kept
 *   define( 'GGSEC_STATS_DAILY_CLIENT_CAP', 500 ); // max recorded requests per client per day (flood guard)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GGSEC_STATS_VERSION    = '1.2.1';
const GGSEC_STATS_DB_VERSION = '2';

// Hit kinds.
const GGSEC_STATS_KIND_UNVERIFIED = 0; // Page served, browser beacon not (yet) received.
const GGSEC_STATS_KIND_HUMAN      = 1; // Beacon received: a real browser rendered the page.
const GGSEC_STATS_KIND_BOT        = 2; // User agent identified as a bot or non-browser client.
const GGSEC_STATS_KIND_PREFETCH   = 3; // Speculative prefetch; becomes human only if the page is actually shown.

// Hit status values.
const GGSEC_STATS_STATUS_OK    = 200;
const GGSEC_STATS_STATUS_404   = 404;
const GGSEC_STATS_STATUS_PROBE    = 1; // wp-login.php / xmlrpc.php requests.
const GGSEC_STATS_STATUS_DOWNLOAD = 2; // File link (PDF, ZIP, ...) clicked by a confirmed visitor.

// Unverified hits older than this are reported as bots (no JavaScript executed).
const GGSEC_STATS_UNVERIFIED_GRACE = 120;

define( 'GGSEC_STATS_FILE', __FILE__ );
define( 'GGSEC_STATS_DIR', plugin_dir_path( __FILE__ ) );

require_once GGSEC_STATS_DIR . 'includes/bots.php';
require_once GGSEC_STATS_DIR . 'includes/tracker.php';
require_once GGSEC_STATS_DIR . 'includes/admin.php';

function ggsec_stats_table() {
	global $wpdb;
	return $wpdb->prefix . 'ggsec_stats_hits';
}

function ggsec_stats_retention_days() {
	$days = defined( 'GGSEC_STATS_RETENTION_DAYS' ) ? (int) GGSEC_STATS_RETENTION_DAYS : 90;
	return max( 1, $days );
}

/**
 * Scanners can send tens of thousands of requests; past this many rows per client (visitor hash) per day
 * further requests are not written, which bounds table growth and write load.
 */
function ggsec_stats_daily_client_cap() {
	$cap = defined( 'GGSEC_STATS_DAILY_CLIENT_CAP' ) ? (int) GGSEC_STATS_DAILY_CLIENT_CAP : 500;
	return max( 1, $cap );
}

function ggsec_stats_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = ggsec_stats_table();
	$charset = $wpdb->get_charset_collate();

	// dbDelta format: two spaces after PRIMARY KEY, one index per line.
	dbDelta(
		"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  day date NOT NULL,
  path varchar(255) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned DEFAULT NULL,
  status smallint(5) unsigned NOT NULL DEFAULT 200,
  visitor char(16) NOT NULL DEFAULT '',
  kind tinyint(3) unsigned NOT NULL DEFAULT 0,
  bot_name varchar(64) DEFAULT NULL,
  bot_type varchar(16) DEFAULT NULL,
  ua varchar(255) DEFAULT NULL,
  ref_host varchar(190) DEFAULT NULL,
  ref_path varchar(255) DEFAULT NULL,
  utm_source varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY day_kind (day,kind),
  KEY created_at (created_at),
  KEY day_path (day,path(100)),
  KEY day_ref (day,ref_host),
  KEY day_visitor (day,visitor)
) {$charset};"
	);

	update_option( 'ggsec_stats_db_version', GGSEC_STATS_DB_VERSION, false );

	if ( ! wp_next_scheduled( 'ggsec_stats_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ggsec_stats_daily' );
	}
}
register_activation_hook( __FILE__, 'ggsec_stats_install' );

// Files replaced over FTP skip the activation hook, so schema upgrades are also checked on load.
function ggsec_stats_maybe_upgrade() {
	if ( get_option( 'ggsec_stats_db_version' ) !== GGSEC_STATS_DB_VERSION ) {
		ggsec_stats_install();
	}
}
add_action( 'plugins_loaded', 'ggsec_stats_maybe_upgrade' );

function ggsec_stats_deactivate() {
	wp_clear_scheduled_hook( 'ggsec_stats_daily' );
}
register_deactivation_hook( __FILE__, 'ggsec_stats_deactivate' );

/**
 * Daily maintenance: delete raw hits past the retention period, in batches.
 */
function ggsec_stats_cleanup() {
	global $wpdb;
	$table  = ggsec_stats_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ggsec_stats_retention_days() * DAY_IN_SECONDS );

	for ( $i = 0; $i < 50; $i++ ) {
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff ) );
		if ( ! $deleted ) {
			break;
		}
	}
}
add_action( 'ggsec_stats_daily', 'ggsec_stats_cleanup' );
