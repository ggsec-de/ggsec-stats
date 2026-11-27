<?php
/**
 * Plugin Name:       GGSEC Stats
 * Plugin URI:        https://github.com/ggsec-de/ggsec-stats
 * Description:       Privacy-first page view statistics: humans vs bots, referrers, top pages, online now. No cookies, no external services.
 * Version:           1.5.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            GG Advanced IT Security UG
 * Author URI:        https://ggsec.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ggsec-stats
 *
 * Optional constants in wp-config.php:
 *   define( 'GGSEC_STATS_RETENTION_DAYS', 90 );   // days raw hits are kept
 *   define( 'GGSEC_STATS_DAILY_CLIENT_CAP', 500 ); // max recorded requests per client per day (flood guard)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GGSEC_STATS_VERSION    = '1.5.0';
// Bumped also when scheduled events change: ggsec_stats_maybe_upgrade() then re-runs the install routine,
// which schedules missing events on sites updated without re-activation (WordPress.org updates, FTP).
const GGSEC_STATS_DB_VERSION = '4';

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
require_once GGSEC_STATS_DIR . 'includes/notify.php';

function ggsec_stats_table() {
	global $wpdb;
	return $wpdb->prefix . 'ggsec_stats_hits';
}

/**
 * Daily totals (counts only, no personal data); kept after the raw hits are deleted.
 */
function ggsec_stats_daily_table() {
	global $wpdb;
	return $wpdb->prefix . 'ggsec_stats_daily';
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

	$daily = ggsec_stats_daily_table();
	dbDelta(
		"CREATE TABLE {$daily} (
  day date NOT NULL,
  human_views int(10) unsigned NOT NULL DEFAULT 0,
  visitors int(10) unsigned NOT NULL DEFAULT 0,
  bots_known int(10) unsigned NOT NULL DEFAULT 0,
  bots_nojs int(10) unsigned NOT NULL DEFAULT 0,
  probes int(10) unsigned NOT NULL DEFAULT 0,
  downloads int(10) unsigned NOT NULL DEFAULT 0,
  recon int(10) unsigned NOT NULL DEFAULT 0,
  ai_referrals int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (day)
) {$charset};"
	);

	update_option( 'ggsec_stats_db_version', GGSEC_STATS_DB_VERSION, false );

	// Fills the daily history from the raw hits already recorded (first install of 1.5.0).
	ggsec_stats_rollup();

	if ( ! wp_next_scheduled( 'ggsec_stats_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ggsec_stats_daily' );
	}
	// E-mail reports and alerts (does nothing until enabled in the settings).
	if ( ! wp_next_scheduled( 'ggsec_stats_hourly' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'ggsec_stats_hourly' );
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
	wp_clear_scheduled_hook( 'ggsec_stats_hourly' );
}
register_deactivation_hook( __FILE__, 'ggsec_stats_deactivate' );

/**
 * Aggregate complete local days (before today) from the raw hits into the daily table.
 *
 * Re-aggregates from the day before the last aggregated day (late beacons may still have upgraded hits after
 * midnight), but never a day that retention cleanup may already have partly deleted: such a day would overwrite
 * its correct totals with smaller ones.
 *
 * @return bool False on a database error.
 */
function ggsec_stats_rollup() {
	global $wpdb;
	$hits  = ggsec_stats_table();
	$daily = ggsec_stats_daily_table();
	$tz    = wp_timezone();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own tables, maintenance job.
	$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(day) FROM %i', $daily ) );
	$from = '' !== $last
		? ( new DateTimeImmutable( $last, $tz ) )->modify( '-1 day' )->format( 'Y-m-d' )
		: (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(day) FROM %i', $hits ) );
	if ( '' === $from ) {
		return true; // No hits recorded yet.
	}

	// Hits created before the last cleanup cutoff are gone; the local day containing it is incomplete.
	// Without a recorded cutoff (sites updated from 1.4 or older), assume the most recent possible one.
	$purged    = (int) get_option( 'ggsec_stats_purged_before', time() - ggsec_stats_retention_days() * DAY_IN_SECONDS );
	$safe_from = ( new DateTimeImmutable( '@' . $purged ) )->setTimezone( $tz )->modify( '+1 day' )->format( 'Y-m-d' );
	$from      = max( $from, $safe_from );
	$to        = ( new DateTimeImmutable( 'yesterday', $tz ) )->format( 'Y-m-d' );
	if ( $from > $to ) {
		return true;
	}

	$cutoff = ggsec_stats_unverified_cutoff();
	// Same definitions as ggsec_stats_summary(), ggsec_stats_recon_summary() and ggsec_stats_ai_referrals().
	$result = $wpdb->query(
		$wpdb->prepare(
			"REPLACE INTO %i (day, human_views, visitors, bots_known, bots_nojs, probes, downloads, recon, ai_referrals)
			SELECT day,
				SUM(kind = %d AND status = %d),
				COUNT(DISTINCT CASE WHEN kind = %d AND status = %d THEN visitor END),
				SUM(kind = %d AND status <> %d),
				SUM(kind = %d AND status <> %d AND created_at < %s),
				SUM(status = %d),
				SUM(status = %d),
				COALESCE(SUM((kind = %d AND bot_type = 'scanner')
					OR (status IN (%d, %d) AND (kind = %d OR (kind = %d AND (status = %d OR created_at < %s))) AND path REGEXP %s)), 0),
				COALESCE(SUM(kind = %d AND status = %d AND (ref_host REGEXP %s OR utm_source REGEXP %s)), 0)
			FROM %i WHERE day BETWEEN %s AND %s GROUP BY day",
			$daily,
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			$cutoff,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_STATUS_DOWNLOAD,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_404,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			$cutoff,
			ggsec_stats_sensitive_path_regex(),
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			ggsec_stats_ai_referrer_regex(),
			ggsec_stats_ai_referrer_regex(),
			$hits,
			$from,
			$to
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return false !== $result;
}

/**
 * Daily maintenance: aggregate finished days, then delete raw hits past the retention period, in batches.
 */
function ggsec_stats_cleanup() {
	global $wpdb;

	// A day is deleted only after it was aggregated.
	if ( ! ggsec_stats_rollup() ) {
		return;
	}

	$purged = time() - ggsec_stats_retention_days() * DAY_IN_SECONDS;
	$cutoff = gmdate( 'Y-m-d H:i:s', $purged );
	update_option( 'ggsec_stats_purged_before', $purged, false );

	for ( $i = 0; $i < 50; $i++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, batched retention cleanup.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 5000', ggsec_stats_table(), $cutoff ) );
		if ( ! $deleted ) {
			break;
		}
	}
}
add_action( 'ggsec_stats_daily', 'ggsec_stats_cleanup' );

/**
 * Suggested text for the site's privacy policy (Settings > Privacy > Policy Guide).
 */
function ggsec_stats_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p>' . __( 'This website counts page views with the GGSEC Stats plugin. The statistics are stored only in the website database; no data is sent to third parties and no cookies or browser storage are used.', 'ggsec-stats' ) . '</p>'
		. '<p>' . __( 'Your IP address is not stored. To count unique visitors, a shortened one-way hash of your IP address, browser user agent and the current date is stored, keyed with a random value that is replaced every day, so a visit cannot be linked to visits on other days. We also store the requested page, the referring website without query string and the time of the request. For requests not confirmed as a visible page view (for example bots), the browser user agent is stored as well.', 'ggsec-stats' ) . '</p>'
		. '<p>' . sprintf(
			/* translators: %d: number of days raw statistics are kept. */
			__( 'Statistics data is deleted automatically after %d days.', 'ggsec-stats' ),
			ggsec_stats_retention_days()
		) . ' ' . __( 'Only daily totals (numbers of page views, visitors and bot requests, without any personal data) are kept longer.', 'ggsec-stats' ) . '</p>';
	wp_add_privacy_policy_content( 'GGSEC Stats', wp_kses_post( $content ) );
}
add_action( 'admin_init', 'ggsec_stats_privacy_policy_content' );
