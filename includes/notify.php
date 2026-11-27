<?php
/**
 * E-mail reports (daily or weekly) and security alerts (login attacks, vulnerability scans).
 *
 * Everything is off until an administrator enables it under Dashboard > GGSEC Stats. Mails are sent
 * with wp_mail() (the site's own mail setup) and contain aggregate numbers, paths, bot names and user
 * agents only: no IP addresses and no visitor hashes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GGSEC_STATS_REPORT_HOUR         = 7;                   // Local hour from which a due report is sent.
const GGSEC_STATS_ALERT_WINDOW        = HOUR_IN_SECONDS;     // Alerts look at the requests of the last hour.
const GGSEC_STATS_ALERT_COOLDOWN      = 6 * HOUR_IN_SECONDS; // At most one alert of each kind per cooldown.
const GGSEC_STATS_ALERT_MIN_THRESHOLD = 5;
const GGSEC_STATS_ALERT_MAX_THRESHOLD = 100000;

/*
 * Settings and state.
 */

function ggsec_stats_notify_defaults() {
	return array(
		'report_enabled'   => false,
		'report_frequency' => 'weekly',
		'alerts_enabled'   => false,
		'recipient'        => '',
		'login_threshold'  => 50,
		'recon_threshold'  => 20,
	);
}

/**
 * @return array{report_enabled:bool,report_frequency:string,alerts_enabled:bool,recipient:string,login_threshold:int,recon_threshold:int}
 */
function ggsec_stats_notify_settings() {
	$saved = get_option( 'ggsec_stats_notify', array() );
	return array_merge( ggsec_stats_notify_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Sending state: last report period sent and the time of the last alert of each kind (UTC timestamps).
 *
 * @return array{report_period:string,alert_login_at:int,alert_recon_at:int}
 */
function ggsec_stats_notify_state() {
	$state = get_option( 'ggsec_stats_notify_state', array() );
	return array_merge(
		array(
			'report_period'  => '',
			'alert_login_at' => 0,
			'alert_recon_at' => 0,
		),
		is_array( $state ) ? $state : array()
	);
}

function ggsec_stats_notify_recipient( $settings ) {
	return is_email( $settings['recipient'] ) ? $settings['recipient'] : get_option( 'admin_email' );
}

/*
 * Report scheduling.
 */

/**
 * Report period for a frequency at a given time.
 *
 * - key:  sending slot (local date or ISO week); a report is sent once per key.
 * - due:  time from which the report for this key may be sent.
 * - from/to: local days (Y-m-d) the report covers: yesterday, or the previous Monday-Sunday week.
 *
 * @return array{key:string,due:DateTimeImmutable,from:string,to:string}
 */
function ggsec_stats_report_period( $frequency, DateTimeImmutable $now ) {
	$today = $now->setTimezone( wp_timezone() )->setTime( 0, 0 );

	if ( 'daily' === $frequency ) {
		$yesterday = $today->modify( '-1 day' )->format( 'Y-m-d' );
		return array(
			'key'  => $today->format( 'Y-m-d' ),
			'due'  => $today->setTime( GGSEC_STATS_REPORT_HOUR, 0 ),
			'from' => $yesterday,
			'to'   => $yesterday,
		);
	}

	$monday = $today->modify( '-' . ( (int) $today->format( 'N' ) - 1 ) . ' days' );
	return array(
		'key'  => $monday->format( 'o-\WW' ),
		'due'  => $monday->setTime( GGSEC_STATS_REPORT_HOUR, 0 ),
		'from' => $monday->modify( '-7 days' )->format( 'Y-m-d' ),
		'to'   => $monday->modify( '-1 day' )->format( 'Y-m-d' ),
	);
}

/**
 * A report is due once its sending time has passed and it has not been sent for this period yet,
 * so a late WP-Cron run (low-traffic site) still sends it exactly once.
 */
function ggsec_stats_report_due( $settings, $state, DateTimeImmutable $now ) {
	if ( empty( $settings['report_enabled'] ) ) {
		return false;
	}
	$period = ggsec_stats_report_period( $settings['report_frequency'], $now );
	return $now >= $period['due'] && $state['report_period'] !== $period['key'];
}

/*
 * Alert queries (time window on created_at, plugin's own table, must be live).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Requests since $since_utc: login/XML-RPC probes, and reconnaissance (scanner bots or non-human 404s for
 * sensitive paths). Unlike ggsec_stats_recon_summary(), recon here leaves out all login/XML-RPC probes,
 * including those from scanners such as WPScan, so a brute-force attack raises the login alert only, not both.
 *
 * @return array{login:int,recon:int}
 */
function ggsec_stats_alert_counts( $since_utc ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT SUM(status = %d) AS login,
				SUM(status <> %d AND ((kind = %d AND bot_type = 'scanner')
					OR (status = %d AND (kind = %d OR (kind = %d AND created_at < %s)) AND path REGEXP %s))) AS recon
			FROM %i WHERE created_at >= %s",
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_404,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			ggsec_stats_unverified_cutoff(),
			ggsec_stats_sensitive_path_regex(),
			ggsec_stats_table(),
			$since_utc
		),
		ARRAY_A
	);
	return array(
		'login' => (int) ( $row['login'] ?? 0 ),
		'recon' => (int) ( $row['recon'] ?? 0 ),
	);
}

/**
 * Top paths and clients (bot name or user agent) of the login probes since $since_utc.
 *
 * @return array{paths:array<int,array<string,string>>,clients:array<int,array<string,string>>}
 */
function ggsec_stats_alert_login_details( $since_utc ) {
	global $wpdb;
	$table = ggsec_stats_table();
	return array(
		'paths'   => $wpdb->get_results(
			$wpdb->prepare(
				'SELECT path, COUNT(*) AS hits FROM %i WHERE created_at >= %s AND status = %d GROUP BY path ORDER BY hits DESC LIMIT 5',
				$table,
				$since_utc,
				GGSEC_STATS_STATUS_PROBE
			),
			ARRAY_A
		),
		'clients' => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(bot_name, ua, '') AS client, COUNT(*) AS hits FROM %i
				WHERE created_at >= %s AND status = %d GROUP BY client ORDER BY hits DESC LIMIT 5",
				$table,
				$since_utc,
				GGSEC_STATS_STATUS_PROBE
			),
			ARRAY_A
		),
	);
}

/**
 * Top paths and clients of the reconnaissance requests since $since_utc.
 *
 * @return array{paths:array<int,array<string,string>>,clients:array<int,array<string,string>>}
 */
function ggsec_stats_alert_recon_details( $since_utc ) {
	global $wpdb;
	$table  = ggsec_stats_table();
	$cutoff = ggsec_stats_unverified_cutoff();
	$regex  = ggsec_stats_sensitive_path_regex();
	return array(
		'paths'   => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, COUNT(*) AS hits FROM %i WHERE created_at >= %s
					AND status <> %d AND ((kind = %d AND bot_type = 'scanner')
						OR (status = %d AND (kind = %d OR (kind = %d AND created_at < %s)) AND path REGEXP %s))
				GROUP BY path ORDER BY hits DESC LIMIT 5",
				$table,
				$since_utc,
				GGSEC_STATS_STATUS_PROBE,
				GGSEC_STATS_KIND_BOT,
				GGSEC_STATS_STATUS_404,
				GGSEC_STATS_KIND_BOT,
				GGSEC_STATS_KIND_UNVERIFIED,
				$cutoff,
				$regex
			),
			ARRAY_A
		),
		'clients' => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(bot_name, ua, '') AS client, COUNT(*) AS hits FROM %i WHERE created_at >= %s
					AND status <> %d AND ((kind = %d AND bot_type = 'scanner')
						OR (status = %d AND (kind = %d OR (kind = %d AND created_at < %s)) AND path REGEXP %s))
				GROUP BY client ORDER BY hits DESC LIMIT 5",
				$table,
				$since_utc,
				GGSEC_STATS_STATUS_PROBE,
				GGSEC_STATS_KIND_BOT,
				GGSEC_STATS_STATUS_404,
				GGSEC_STATS_KIND_BOT,
				GGSEC_STATS_KIND_UNVERIFIED,
				$cutoff,
				$regex
			),
			ARRAY_A
		),
	);
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/*
 * Mail rendering. All values are escaped here; the layout uses inline styles for mail clients.
 */

function ggsec_stats_mail_site_name() {
	return wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
}

/**
 * Change against the previous period, e.g. "+12%", "-5%", "new" or "" (nothing in either period).
 */
function ggsec_stats_mail_change( $current, $previous ) {
	if ( 0 === (int) $previous ) {
		return $current > 0 ? __( 'new', 'ggsec-stats' ) : '';
	}
	$pct = (int) round( 100 * ( $current - $previous ) / $previous );
	return ( $pct > 0 ? '+' : '' ) . number_format_i18n( $pct ) . '%';
}

/**
 * @param string[]   $headers      Column headings (plain text).
 * @param string[][] $rows         Plain-text cells.
 * @param int|null   $numeric_from First right-aligned (number) column; default: the last column.
 */
function ggsec_stats_mail_table( $headers, $rows, $numeric_from = null ) {
	$numeric_from = null === $numeric_from ? count( $headers ) - 1 : (int) $numeric_from;
	$th   = 'text-align:left;padding:6px 8px;border-bottom:2px solid #dcdcde;font-size:12px;color:#50575e;';
	$td   = 'padding:6px 8px;border-bottom:1px solid #f0f0f1;font-size:13px;color:#1d2327;overflow-wrap:anywhere;';
	$html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;margin:0 0 18px;"><tr>';
	foreach ( $headers as $i => $h ) {
		$html .= '<th style="' . $th . ( $i >= $numeric_from ? 'text-align:right;' : '' ) . '">' . esc_html( $h ) . '</th>';
	}
	$html .= '</tr>';
	if ( ! $rows ) {
		$html .= '<tr><td colspan="' . count( $headers ) . '" style="' . $td . 'color:#787c82;"><em>' . esc_html__( 'No data.', 'ggsec-stats' ) . '</em></td></tr>';
	}
	foreach ( $rows as $cells ) {
		$html .= '<tr>';
		foreach ( array_values( $cells ) as $i => $cell ) {
			$html .= '<td style="' . $td . ( $i >= $numeric_from ? 'text-align:right;white-space:nowrap;' : '' ) . '">' . esc_html( (string) $cell ) . '</td>';
		}
		$html .= '</tr>';
	}
	return $html . '</table>';
}

function ggsec_stats_mail_heading( $text ) {
	return '<h2 style="margin:22px 0 8px;font-size:15px;color:#1d2327;">' . esc_html( $text ) . '</h2>';
}

/**
 * Full HTML document around a mail body.
 *
 * @param string $title Plain-text title.
 * @param string $intro Plain-text intro line.
 * @param string $body  HTML built from escaped fragments.
 */
function ggsec_stats_mail_layout( $title, $intro, $body ) {
	$settings_url = admin_url( 'index.php?page=ggsec-stats#ggsec-stats-notify' );
	// Mail clients in dark mode recolour CSS backgrounds; the color-scheme hints and bgcolor attributes keep
	// the light design, and the button is a table cell because many clients drop backgrounds on links.
	return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
		. '<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only">'
		. '<style>:root{color-scheme:light only;supported-color-schemes:light only;}</style></head>'
		. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;">'
		. '<table role="presentation" cellspacing="0" cellpadding="0" bgcolor="#f0f0f1" style="width:100%;background:#f0f0f1;"><tr><td style="padding:24px 12px;">'
		. '<table role="presentation" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="max-width:640px;width:100%;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">'
		. '<tr><td bgcolor="#0a1830" style="background:#0a1830;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:700;">GGSEC <span style="color:#00d5ff;">Stats</span>'
		. '<div style="font-size:13px;font-weight:400;color:#b9cbe6;margin-top:4px;">' . esc_html( ggsec_stats_mail_site_name() ) . '</div></td></tr>'
		. '<tr><td style="padding:22px 24px 8px;">'
		. '<h1 style="margin:0 0 6px;font-size:20px;color:#1d2327;">' . esc_html( $title ) . '</h1>'
		. '<p style="margin:0 0 12px;font-size:14px;color:#50575e;">' . esc_html( $intro ) . '</p>'
		. $body
		. '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:18px 0 0;"><tr><td bgcolor="#2271b1" style="background:#2271b1;border-radius:4px;">'
		. '<a href="' . esc_url( admin_url( 'index.php?page=ggsec-stats' ) ) . '" style="display:inline-block;padding:9px 16px;color:#ffffff;text-decoration:none;font-size:14px;">'
		. esc_html__( 'Open GGSEC Stats', 'ggsec-stats' ) . '</a></td></tr></table>'
		. '</td></tr>'
		. '<tr><td style="padding:16px 24px 22px;font-size:12px;color:#787c82;border-top:1px solid #f0f0f1;">'
		. esc_html__( 'You receive this e-mail because e-mail reports or alerts are enabled in GGSEC Stats on this site.', 'ggsec-stats' )
		. ' <a href="' . esc_url( $settings_url ) . '" style="color:#2271b1;">' . esc_html__( 'Change e-mail settings', 'ggsec-stats' ) . '</a>'
		. '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Headline numbers as a two-column grid of cards (value, label, change vs previous period).
 *
 * @param array<int,array{0:string,1:string,2:string}> $cards [formatted value, label, change].
 */
function ggsec_stats_mail_cards( $cards ) {
	$html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:separate;border-spacing:0 8px;">';
	foreach ( array_chunk( $cards, 2 ) as $pair ) {
		$html .= '<tr>';
		foreach ( $pair as $i => $c ) {
			if ( 1 === $i ) {
				$html .= '<td style="width:4%;font-size:0;line-height:0;">&nbsp;</td>';
			}
			$html .= '<td bgcolor="#f6f7f7" style="width:48%;padding:10px 12px;background:#f6f7f7;border-radius:6px;">'
				. '<div style="font-size:22px;font-weight:700;color:#1d2327;">' . esc_html( $c[0] )
				. ( '' !== $c[2] ? ' <span style="font-size:12px;font-weight:400;color:#787c82;">' . esc_html( $c[2] ) . '</span>' : '' ) . '</div>'
				. '<div style="font-size:12px;color:#50575e;">' . esc_html( $c[1] ) . '</div></td>';
		}
		if ( 1 === count( $pair ) ) {
			$html .= '<td style="width:4%;font-size:0;line-height:0;">&nbsp;</td><td style="width:48%;"></td>';
		}
		$html .= '</tr>';
	}
	return $html . '</table>';
}

/**
 * Display date of a local day (Y-m-d) in the site's date format.
 */
function ggsec_stats_mail_date( $day ) {
	return wp_date( get_option( 'date_format' ), ( new DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp() );
}

/**
 * Statistics report for the period that is due at $now (daily: yesterday; weekly: previous week).
 *
 * @return array{subject:string,html:string}
 */
function ggsec_stats_build_report( $frequency, DateTimeImmutable $now ) {
	$period = ggsec_stats_report_period( $frequency, $now );
	$from   = $period['from'];
	$to     = $period['to'];
	$days   = 'daily' === $frequency ? 1 : 7;
	$tz     = wp_timezone();

	$prev_to   = ( new DateTimeImmutable( $from, $tz ) )->modify( '-1 day' )->format( 'Y-m-d' );
	$prev_from = ( new DateTimeImmutable( $from, $tz ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d' );

	$sum        = ggsec_stats_summary( $from, $to );
	$prev       = ggsec_stats_summary( $prev_from, $prev_to );
	$recon      = ggsec_stats_recon_summary( $from, $to );
	$prev_recon = ggsec_stats_recon_summary( $prev_from, $prev_to );
	$total      = $sum['human_views'] + $sum['bots'];

	$range = $from === $to ? ggsec_stats_mail_date( $from ) : ggsec_stats_mail_date( $from ) . ' – ' . ggsec_stats_mail_date( $to );
	if ( 'daily' === $frequency ) {
		/* translators: %s: report date. */
		$title = sprintf( __( 'Daily report: %s', 'ggsec-stats' ), $range );
		$intro = __( 'Change compared with the day before.', 'ggsec-stats' );
	} else {
		/* translators: %s: report date range. */
		$title = sprintf( __( 'Weekly report: %s', 'ggsec-stats' ), $range );
		$intro = __( 'Change compared with the week before.', 'ggsec-stats' );
	}

	$body = ggsec_stats_mail_cards(
		array(
			array( number_format_i18n( $sum['human_views'] ), __( 'Human page views', 'ggsec-stats' ), ggsec_stats_mail_change( $sum['human_views'], $prev['human_views'] ) ),
			array( number_format_i18n( $sum['visitors'] ), __( 'Unique visitors (per day, summed)', 'ggsec-stats' ), ggsec_stats_mail_change( $sum['visitors'], $prev['visitors'] ) ),
			array( number_format_i18n( $sum['bots'] ), __( 'Bot requests', 'ggsec-stats' ), ggsec_stats_mail_change( $sum['bots'], $prev['bots'] ) ),
			array( $total ? number_format_i18n( round( 100 * $sum['bots'] / $total ) ) . '%' : '-', __( 'Bot share of all page requests', 'ggsec-stats' ), '' ),
			array( number_format_i18n( $sum['probes'] ), __( 'Login/XML-RPC probes', 'ggsec-stats' ), ggsec_stats_mail_change( $sum['probes'], $prev['probes'] ) ),
			array( number_format_i18n( $recon['total'] ), __( 'Security recon requests', 'ggsec-stats' ), ggsec_stats_mail_change( $recon['total'], $prev_recon['total'] ) ),
		)
	);

	$rows = array();
	foreach ( array_slice( ggsec_stats_top_pages( $from, $to ), 0, 5 ) as $r ) {
		$title_post = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
		$rows[]     = array( $r['path'] . ( '' !== $title_post ? ' (' . $title_post . ')' : '' ), number_format_i18n( (int) $r['views'] ), number_format_i18n( (int) $r['visitors'] ) );
	}
	$body .= ggsec_stats_mail_heading( __( 'Top pages', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Page', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ), __( 'Visitors', 'ggsec-stats' ) ), $rows, 1 );

	$refs = ggsec_stats_top_referrers( $from, $to );
	$rows = array();
	foreach ( array_slice( $refs['hosts'], 0, 5 ) as $r ) {
		$rows[] = array( $r['ref_host'], number_format_i18n( (int) $r['views'] ) );
	}
	$body .= ggsec_stats_mail_heading( __( 'Top referrers', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Source site', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ) ), $rows );

	// Shown only when there were such visits, to keep the report short for sites without them.
	$ai_refs = ggsec_stats_ai_referrals( $from, $to );
	if ( $ai_refs['total'] ) {
		$prev_ai = ggsec_stats_ai_referrals( $prev_from, $prev_to );
		$rows    = array();
		foreach ( array_slice( $ai_refs['assistants'], 0, 5, true ) as $name => $r ) {
			$rows[] = array( $name, number_format_i18n( $r['views'] ), number_format_i18n( $r['visitors'] ) );
		}
		$change = ggsec_stats_mail_change( $ai_refs['total'], $prev_ai['total'] );
		$body  .= ggsec_stats_mail_heading(
			trim(
				sprintf(
					/* translators: 1: number of human page views from AI assistants, 2: change vs previous period (may be empty). */
					__( 'Visitors from AI assistants: %1$s %2$s', 'ggsec-stats' ),
					number_format_i18n( $ai_refs['total'] ),
					'' !== $change ? '(' . $change . ')' : ''
				)
			)
		) . ggsec_stats_mail_table( array( __( 'Assistant', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ), __( 'Visitors', 'ggsec-stats' ) ), $rows, 1 );
	}

	$bots = ggsec_stats_bot_breakdown( $from, $to );
	$rows = array();
	foreach ( array_slice( $bots['known'], 0, 5 ) as $r ) {
		$rows[] = array( $r['bot_name'], ggsec_stats_bot_type_label( $r['bot_type'] ), number_format_i18n( (int) $r['hits'] ) );
	}
	$body .= ggsec_stats_mail_heading( __( 'Top bots', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Bot', 'ggsec-stats' ), __( 'Type', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );

	$rows = array();
	foreach ( array_slice( ggsec_stats_suspicious_paths( $from, $to ), 0, 5 ) as $r ) {
		$rows[] = array(
			$r['path'],
			GGSEC_STATS_STATUS_PROBE === (int) $r['status'] ? __( 'login / XML-RPC probe', 'ggsec-stats' ) : '404',
			number_format_i18n( (int) $r['hits'] ),
		);
	}
	$body .= ggsec_stats_mail_heading( __( 'Suspicious requests', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Path', 'ggsec-stats' ), __( 'Kind', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );

	$broken = count( ggsec_stats_broken_links( $from, $to ) );
	if ( $broken ) {
		$body .= '<p style="margin:0;font-size:13px;color:#1d2327;">' . esc_html(
			sprintf(
				/* translators: %s: number of broken links. */
				_n( 'Real visitors opened %s missing page (404). See "Broken links" in GGSEC Stats.', 'Real visitors opened %s missing pages (404). See "Broken links" in GGSEC Stats.', $broken, 'ggsec-stats' ),
				number_format_i18n( $broken )
			)
		) . '</p>';
	}

	return array(
		/* translators: 1: site name, 2: report title. */
		'subject' => sprintf( __( '[%1$s] GGSEC Stats %2$s', 'ggsec-stats' ), ggsec_stats_mail_site_name(), $title ),
		'html'    => ggsec_stats_mail_layout( $title, $intro, $body ),
	);
}

/**
 * Alert mail for one kind ('login' or 'recon').
 *
 * @param array{paths:array,clients:array} $details
 * @return array{subject:string,html:string}
 */
function ggsec_stats_build_alert( $kind, $count, $threshold, $details ) {
	if ( 'login' === $kind ) {
		$title = __( 'Login attack in progress', 'ggsec-stats' );
		/* translators: 1: number of requests, 2: alert threshold. */
		$intro = sprintf( __( '%1$s failed logins, login page scans and XML-RPC requests in the last hour (alert threshold: %2$s).', 'ggsec-stats' ), number_format_i18n( $count ), number_format_i18n( $threshold ) );
		$tips  = array(
			__( 'Use strong, unique passwords and two-factor authentication for all administrator accounts.', 'ggsec-stats' ),
			__( 'Disable XML-RPC if you do not use it (no mobile app publishing, no Jetpack).', 'ggsec-stats' ),
			__( 'Limit login attempts on the web server or with a firewall if the attack continues.', 'ggsec-stats' ),
		);
	} else {
		$title = __( 'Vulnerability scan detected', 'ggsec-stats' );
		/* translators: 1: number of requests, 2: alert threshold. */
		$intro = sprintf( __( '%1$s requests from security scanners or for sensitive paths in the last hour (alert threshold: %2$s).', 'ggsec-stats' ), number_format_i18n( $count ), number_format_i18n( $threshold ) );
		$tips  = array(
			__( 'Check that the requested files (.env, backups, .git, configuration copies) do not exist on the server.', 'ggsec-stats' ),
			__( 'Keep WordPress, plugins and themes up to date and remove the ones you do not use.', 'ggsec-stats' ),
			__( 'Block the scanner on the web server or firewall if it keeps coming back.', 'ggsec-stats' ),
		);
	}

	$rows = array();
	foreach ( $details['paths'] as $r ) {
		$rows[] = array( $r['path'], number_format_i18n( (int) $r['hits'] ) );
	}
	$body = ggsec_stats_mail_heading( __( 'Requested paths', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Path', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );

	$rows = array();
	foreach ( $details['clients'] as $r ) {
		$client = '' !== (string) $r['client'] ? (string) $r['client'] : __( '(empty user agent)', 'ggsec-stats' );
		$rows[] = array( ggsec_stats_truncate( $client, 120 ), number_format_i18n( (int) $r['hits'] ) );
	}
	$body .= ggsec_stats_mail_heading( __( 'Clients', 'ggsec-stats' ) )
		. ggsec_stats_mail_table( array( __( 'Bot or user agent', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );

	$body .= ggsec_stats_mail_heading( __( 'What you can do', 'ggsec-stats' ) ) . '<ul style="margin:0;padding-left:20px;font-size:13px;color:#1d2327;">';
	foreach ( $tips as $tip ) {
		$body .= '<li style="margin:0 0 4px;">' . esc_html( $tip ) . '</li>';
	}
	$body .= '</ul><p style="margin:12px 0 0;font-size:12px;color:#787c82;">'
		. esc_html__( 'GGSEC Stats records these requests but does not block them. IP addresses are not stored, so they are not listed here.', 'ggsec-stats' )
		. '</p>';

	return array(
		/* translators: 1: site name, 2: alert title. */
		'subject' => sprintf( __( '[%1$s] GGSEC Stats alert: %2$s', 'ggsec-stats' ), ggsec_stats_mail_site_name(), $title ),
		'html'    => ggsec_stats_mail_layout( $title, $intro, $body ),
	);
}

/**
 * @param array{subject:string,html:string} $mail
 */
function ggsec_stats_send_mail( $to, $mail ) {
	return wp_mail( $to, $mail['subject'], $mail['html'], array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/*
 * Hourly job.
 */

/**
 * Sends alerts whose threshold was reached in the last hour, outside the cooldown.
 *
 * @param array $state Updated in place with the time of each alert sent.
 * @return bool Whether the state changed.
 */
function ggsec_stats_check_alerts( $settings, &$state, $now_ts ) {
	if ( empty( $settings['alerts_enabled'] ) ) {
		return false;
	}
	$since   = gmdate( 'Y-m-d H:i:s', $now_ts - GGSEC_STATS_ALERT_WINDOW );
	$counts  = ggsec_stats_alert_counts( $since );
	$changed = false;

	foreach ( array( 'login', 'recon' ) as $kind ) {
		$threshold = (int) $settings[ $kind . '_threshold' ];
		if ( $counts[ $kind ] < $threshold || $now_ts - (int) $state[ 'alert_' . $kind . '_at' ] < GGSEC_STATS_ALERT_COOLDOWN ) {
			continue;
		}
		$details = 'login' === $kind ? ggsec_stats_alert_login_details( $since ) : ggsec_stats_alert_recon_details( $since );
		$mail    = ggsec_stats_build_alert( $kind, $counts[ $kind ], $threshold, $details );
		if ( ggsec_stats_send_mail( ggsec_stats_notify_recipient( $settings ), $mail ) ) {
			$state[ 'alert_' . $kind . '_at' ] = $now_ts;
			$changed                           = true;
		}
	}
	return $changed;
}

function ggsec_stats_notify_tick() {
	$settings = ggsec_stats_notify_settings();
	if ( empty( $settings['report_enabled'] ) && empty( $settings['alerts_enabled'] ) ) {
		return;
	}
	$state   = ggsec_stats_notify_state();
	$now     = new DateTimeImmutable( 'now', wp_timezone() );
	$changed = ggsec_stats_check_alerts( $settings, $state, $now->getTimestamp() );

	if ( ggsec_stats_report_due( $settings, $state, $now ) ) {
		$report = ggsec_stats_build_report( $settings['report_frequency'], $now );
		// A failed send is retried on the next run.
		if ( ggsec_stats_send_mail( ggsec_stats_notify_recipient( $settings ), $report ) ) {
			$state['report_period'] = ggsec_stats_report_period( $settings['report_frequency'], $now )['key'];
			$changed                = true;
		}
	}

	if ( $changed ) {
		update_option( 'ggsec_stats_notify_state', $state, false );
	}
}
add_action( 'ggsec_stats_hourly', 'ggsec_stats_notify_tick' );

/*
 * Settings form (bottom of Dashboard > GGSEC Stats).
 */

function ggsec_stats_sanitize_threshold( $value, $default ) {
	$value = is_numeric( $value ) ? (int) $value : $default;
	return min( GGSEC_STATS_ALERT_MAX_THRESHOLD, max( GGSEC_STATS_ALERT_MIN_THRESHOLD, $value ) );
}

function ggsec_stats_handle_notify_form() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'ggsec-stats' ), 403 );
	}
	check_admin_referer( 'ggsec_stats_notify' );

	$input = isset( $_POST['ggsec_stats_notify'] ) && is_array( $_POST['ggsec_stats_notify'] )
		? map_deep( wp_unslash( $_POST['ggsec_stats_notify'] ), 'sanitize_text_field' )
		: array();
	$do    = isset( $_POST['ggsec_stats_do'] ) ? sanitize_key( wp_unslash( $_POST['ggsec_stats_do'] ) ) : 'save';

	$old      = ggsec_stats_notify_settings();
	$defaults = ggsec_stats_notify_defaults();
	$notice   = 'saved';

	$recipient = sanitize_email( $input['recipient'] ?? '' );
	if ( '' !== trim( (string) ( $input['recipient'] ?? '' ) ) && ! is_email( $recipient ) ) {
		$recipient = $old['recipient'];
		$notice    = 'bad_email';
	}

	$new = array(
		'report_enabled'   => ! empty( $input['report_enabled'] ),
		'report_frequency' => in_array( $input['report_frequency'] ?? '', array( 'daily', 'weekly' ), true ) ? $input['report_frequency'] : $defaults['report_frequency'],
		'alerts_enabled'   => ! empty( $input['alerts_enabled'] ),
		'recipient'        => $recipient,
		'login_threshold'  => ggsec_stats_sanitize_threshold( $input['login_threshold'] ?? null, $defaults['login_threshold'] ),
		'recon_threshold'  => ggsec_stats_sanitize_threshold( $input['recon_threshold'] ?? null, $defaults['recon_threshold'] ),
	);
	update_option( 'ggsec_stats_notify', $new, false );

	// The first report comes at the next regular sending time, not right after enabling it.
	if ( $new['report_enabled'] && ( ! $old['report_enabled'] || $old['report_frequency'] !== $new['report_frequency'] ) ) {
		$state                  = ggsec_stats_notify_state();
		$state['report_period'] = ggsec_stats_report_period( $new['report_frequency'], new DateTimeImmutable( 'now', wp_timezone() ) )['key'];
		update_option( 'ggsec_stats_notify_state', $state, false );
	}

	if ( 'test' === $do && 'bad_email' !== $notice ) {
		$report = ggsec_stats_build_report( $new['report_frequency'], new DateTimeImmutable( 'now', wp_timezone() ) );
		$notice = ggsec_stats_send_mail( ggsec_stats_notify_recipient( $new ), $report ) ? 'test_sent' : 'test_failed';
	}

	wp_safe_redirect( add_query_arg( array( 'page' => 'ggsec-stats', 'ggsec_stats_notice' => $notice ), admin_url( 'index.php' ) ) . '#ggsec-stats-notify' );
	exit;
}
add_action( 'admin_post_ggsec_stats_save_notify', 'ggsec_stats_handle_notify_form' );

function ggsec_stats_render_notify_settings() {
	$s         = ggsec_stats_notify_settings();
	$recipient = ggsec_stats_notify_recipient( $s );
	$notices   = array(
		'saved'       => array( 'success', __( 'E-mail settings saved.', 'ggsec-stats' ) ),
		'bad_email'   => array( 'error', __( 'The recipient is not a valid e-mail address; the previous recipient was kept. Nothing was sent.', 'ggsec-stats' ) ),
		/* translators: %s: recipient e-mail address. */
		'test_sent'   => array( 'success', sprintf( __( 'Settings saved and a test report was sent to %s.', 'ggsec-stats' ), $recipient ) ),
		'test_failed' => array( 'error', __( 'Settings saved, but WordPress could not send the test report. Check the mail setup of this site.', 'ggsec-stats' ) ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice after the redirect, whitelisted below.
	$notice = isset( $_GET['ggsec_stats_notice'] ) && is_string( $_GET['ggsec_stats_notice'] ) ? sanitize_key( wp_unslash( $_GET['ggsec_stats_notice'] ) ) : '';
	?>
	<h2 id="ggsec-stats-notify"><?php esc_html_e( 'E-mail reports and alerts', 'ggsec-stats' ); ?></h2>
	<?php if ( isset( $notices[ $notice ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ggsec-stats-notify">
		<input type="hidden" name="action" value="ggsec_stats_save_notify">
		<?php wp_nonce_field( 'ggsec_stats_notify' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Statistics report', 'ggsec-stats' ); ?></th>
				<td>
					<label><input type="checkbox" name="ggsec_stats_notify[report_enabled]" value="1" <?php checked( $s['report_enabled'] ); ?>> <?php esc_html_e( 'Send a statistics report by e-mail', 'ggsec-stats' ); ?></label>
					<br>
					<select name="ggsec_stats_notify[report_frequency]" aria-label="<?php esc_attr_e( 'Report frequency', 'ggsec-stats' ); ?>">
						<option value="weekly" <?php selected( $s['report_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly: Monday morning, previous week', 'ggsec-stats' ); ?></option>
						<option value="daily" <?php selected( $s['report_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily: every morning, previous day', 'ggsec-stats' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Visitors, bots, top pages, referrers and suspicious requests, compared with the previous period.', 'ggsec-stats' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Security alerts', 'ggsec-stats' ); ?></th>
				<td>
					<label><input type="checkbox" name="ggsec_stats_notify[alerts_enabled]" value="1" <?php checked( $s['alerts_enabled'] ); ?>> <?php esc_html_e( 'Send an alert when, within one hour, there are at least', 'ggsec-stats' ); ?></label>
					<br>
					<label><input type="number" class="small-text" min="<?php echo (int) GGSEC_STATS_ALERT_MIN_THRESHOLD; ?>" max="<?php echo (int) GGSEC_STATS_ALERT_MAX_THRESHOLD; ?>" name="ggsec_stats_notify[login_threshold]" value="<?php echo (int) $s['login_threshold']; ?>"> <?php esc_html_e( 'failed logins, login page scans or XML-RPC requests', 'ggsec-stats' ); ?></label>
					<br>
					<label><input type="number" class="small-text" min="<?php echo (int) GGSEC_STATS_ALERT_MIN_THRESHOLD; ?>" max="<?php echo (int) GGSEC_STATS_ALERT_MAX_THRESHOLD; ?>" name="ggsec_stats_notify[recon_threshold]" value="<?php echo (int) $s['recon_threshold']; ?>"> <?php esc_html_e( 'requests from security scanners or for sensitive paths (.env, .git, backups)', 'ggsec-stats' ); ?></label>
					<p class="description"><?php esc_html_e( 'Checked every hour; at most one alert of each kind every 6 hours. GGSEC Stats records attacks but does not block them.', 'ggsec-stats' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ggsec-stats-recipient"><?php esc_html_e( 'Recipient', 'ggsec-stats' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="ggsec-stats-recipient" name="ggsec_stats_notify[recipient]" value="<?php echo esc_attr( $s['recipient'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Leave empty to use the administration e-mail address of this site. Mails are sent with the site\'s own mail setup and contain only aggregate numbers, paths, bot names and user agents, no IP addresses.', 'ggsec-stats' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" class="button button-primary" name="ggsec_stats_do" value="save"><?php esc_html_e( 'Save settings', 'ggsec-stats' ); ?></button>
			<button type="submit" class="button" name="ggsec_stats_do" value="test"><?php esc_html_e( 'Save and send a test report', 'ggsec-stats' ); ?></button>
		</p>
	</form>
	<?php
}
