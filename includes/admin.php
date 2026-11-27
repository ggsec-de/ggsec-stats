<?php
/**
 * Admin dashboard page and WP dashboard widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report ranges: key => [days, label].
 *
 * @return array<string,array{0:int,1:string}>
 */
function ggsec_stats_ranges() {
	return array(
		'today' => array( 1, __( 'Today', 'ggsec-stats' ) ),
		'7d'    => array( 7, __( 'Last 7 days', 'ggsec-stats' ) ),
		'30d'   => array( 30, __( 'Last 30 days', 'ggsec-stats' ) ),
	);
}

/**
 * First local day (Y-m-d) of a range that ends today and spans $days days.
 */
function ggsec_stats_since_day( $days ) {
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	return $today->modify( '-' . ( (int) $days - 1 ) . ' days' )->format( 'Y-m-d' );
}

/**
 * Last local day (Y-m-d) of a report range; defaults to today, so dashboard ranges end today.
 */
function ggsec_stats_until_day( $until = null ) {
	return null === $until ? current_time( 'Y-m-d' ) : (string) $until;
}

/**
 * Unverified hits created before this UTC time never got a beacon and are reported as bots.
 */
function ggsec_stats_unverified_cutoff() {
	return gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE );
}

/*
 * Report queries.
 *
 * Definitions used below:
 * - human = kind HUMAN and status OK.
 * - bot   = not a probe, and either a known bot or an unverified hit older than the beacon grace period.
 *
 * All queries read the plugin's own table and must show live numbers, so the object cache is not used.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Headline numbers for a range.
 *
 * @return array<string,int>
 */
function ggsec_stats_summary( $since, $until = null ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT
				SUM(kind = %d AND status = %d) AS human_views,
				COUNT(DISTINCT CASE WHEN kind = %d AND status = %d THEN CONCAT(day, visitor) END) AS visitors,
				SUM(kind = %d AND status <> %d) AS bots_known,
				SUM(kind = %d AND status <> %d AND created_at < %s) AS bots_nojs,
				SUM(status = %d) AS probes,
				SUM(status = %d) AS downloads
			FROM %i WHERE day BETWEEN %s AND %s',
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			ggsec_stats_unverified_cutoff(),
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_STATUS_DOWNLOAD,
			ggsec_stats_table(),
			$since,
			ggsec_stats_until_day( $until )
		),
		ARRAY_A
	);

	$out = array();
	foreach ( array( 'human_views', 'visitors', 'bots_known', 'bots_nojs', 'probes', 'downloads' ) as $key ) {
		$out[ $key ] = (int) ( $row[ $key ] ?? 0 );
	}
	$out['bots'] = $out['bots_known'] + $out['bots_nojs'];
	return $out;
}

function ggsec_stats_online_now() {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(DISTINCT visitor) FROM %i WHERE kind = %d AND created_at >= %s',
			ggsec_stats_table(),
			GGSEC_STATS_KIND_HUMAN,
			gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS )
		)
	);
}

/**
 * Humans and bots per day for the last $days days (missing days filled with zeros).
 *
 * @return array<string,array{h:int,b:int}>
 */
function ggsec_stats_daily_series( $days ) {
	global $wpdb;
	$since = ggsec_stats_since_day( $days );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT day,
				SUM(kind = %d AND status = %d) AS h,
				SUM(status <> %d AND (kind = %d OR (kind = %d AND created_at < %s))) AS b
			FROM %i WHERE day >= %s GROUP BY day',
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			ggsec_stats_unverified_cutoff(),
			ggsec_stats_table(),
			$since
		),
		ARRAY_A
	);

	$series = array();
	$cursor = new DateTimeImmutable( $since, wp_timezone() );
	for ( $i = 0; $i < $days; $i++ ) {
		$series[ $cursor->format( 'Y-m-d' ) ] = array( 'h' => 0, 'b' => 0 );
		$cursor = $cursor->modify( '+1 day' );
	}
	foreach ( $rows as $r ) {
		if ( isset( $series[ $r['day'] ] ) ) {
			$series[ $r['day'] ] = array( 'h' => (int) $r['h'], 'b' => (int) $r['b'] );
		}
	}
	return $series;
}

function ggsec_stats_top_pages( $since, $until = null ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT path, MAX(post_id) AS post_id, COUNT(*) AS views, COUNT(DISTINCT CONCAT(day, visitor)) AS visitors
			FROM %i WHERE day BETWEEN %s AND %s AND kind = %d AND status = %d GROUP BY path ORDER BY views DESC LIMIT 15',
			ggsec_stats_table(),
			$since,
			ggsec_stats_until_day( $until ),
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_OK
		),
		ARRAY_A
	);
}

function ggsec_stats_top_referrers( $since, $until = null ) {
	global $wpdb;
	$table = ggsec_stats_table();
	return array(
		'hosts' => $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ref_host, COUNT(*) AS views FROM %i
				WHERE day BETWEEN %s AND %s AND kind = %d AND status = %d AND ref_host IS NOT NULL GROUP BY ref_host ORDER BY views DESC LIMIT 15',
				$table,
				$since,
				ggsec_stats_until_day( $until ),
				GGSEC_STATS_KIND_HUMAN,
				GGSEC_STATS_STATUS_OK
			),
			ARRAY_A
		),
		'urls'  => $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ref_host, ref_path, COUNT(*) AS views FROM %i
				WHERE day BETWEEN %s AND %s AND kind = %d AND status = %d AND ref_host IS NOT NULL GROUP BY ref_host, ref_path ORDER BY views DESC LIMIT 20',
				$table,
				$since,
				ggsec_stats_until_day( $until ),
				GGSEC_STATS_KIND_HUMAN,
				GGSEC_STATS_STATUS_OK
			),
			ARRAY_A
		),
		'utm'   => $wpdb->get_results(
			$wpdb->prepare(
				'SELECT utm_source, COUNT(*) AS views FROM %i
				WHERE day BETWEEN %s AND %s AND kind = %d AND status = %d AND utm_source IS NOT NULL GROUP BY utm_source ORDER BY views DESC LIMIT 10',
				$table,
				$since,
				ggsec_stats_until_day( $until ),
				GGSEC_STATS_KIND_HUMAN,
				GGSEC_STATS_STATUS_OK
			),
			ARRAY_A
		),
	);
}

function ggsec_stats_bot_breakdown( $since, $until = null ) {
	global $wpdb;
	$table = ggsec_stats_table();
	return array(
		'known' => $wpdb->get_results(
			$wpdb->prepare(
				'SELECT bot_name, bot_type, COUNT(*) AS hits, MAX(ua) AS sample_ua FROM %i
				WHERE day BETWEEN %s AND %s AND kind = %d AND status <> %d GROUP BY bot_name, bot_type ORDER BY hits DESC LIMIT 30',
				$table,
				$since,
				ggsec_stats_until_day( $until ),
				GGSEC_STATS_KIND_BOT,
				GGSEC_STATS_STATUS_PROBE
			),
			ARRAY_A
		),
		'nojs'  => $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(ua, '') AS ua, COUNT(*) AS hits FROM %i
				WHERE day BETWEEN %s AND %s AND kind = %d AND status <> %d AND created_at < %s GROUP BY ua ORDER BY hits DESC LIMIT 10",
				$table,
				$since,
				ggsec_stats_until_day( $until ),
				GGSEC_STATS_KIND_UNVERIFIED,
				GGSEC_STATS_STATUS_PROBE,
				ggsec_stats_unverified_cutoff()
			),
			ARRAY_A
		),
	);
}

/**
 * 404 pages opened by confirmed humans: broken links (internal or from referring sites).
 */
function ggsec_stats_broken_links( $since, $until = null ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT path, MAX(ref_host) AS ref_host, COUNT(*) AS hits FROM %i
			WHERE day BETWEEN %s AND %s AND kind = %d AND status = %d GROUP BY path ORDER BY hits DESC LIMIT 20',
			ggsec_stats_table(),
			$since,
			ggsec_stats_until_day( $until ),
			GGSEC_STATS_KIND_HUMAN,
			GGSEC_STATS_STATUS_404
		),
		ARRAY_A
	);
}

/**
 * 404s and probes from non-humans. Unverified 404s are only listed after the beacon grace period,
 * so a real visitor's 404 does not flash up here before the browser confirms it.
 */
function ggsec_stats_suspicious_paths( $since, $until = null ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT path, status, COUNT(*) AS hits FROM %i
			WHERE day BETWEEN %s AND %s AND status IN (%d, %d)
				AND (kind = %d OR (kind = %d AND (status = %d OR created_at < %s)))
			GROUP BY path, status ORDER BY hits DESC LIMIT 25',
			ggsec_stats_table(),
			$since,
			ggsec_stats_until_day( $until ),
			GGSEC_STATS_STATUS_404,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			ggsec_stats_unverified_cutoff()
		),
		ARRAY_A
	);
}

/**
 * Security reconnaissance: requests from known scanners and non-human hits on sensitive paths.
 *
 * A request can be both (Nuclei asking for /.env); `total` counts each request once.
 *
 * @return array{scanner:int,sensitive:int,total:int}
 */
function ggsec_stats_recon_summary( $since, $until = null ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// Sensitive = non-human requests for missing sensitive paths (existing pages with similar names are content).
			"SELECT SUM(is_scanner) AS scanner, SUM(is_sensitive) AS sensitive_hits, SUM(is_scanner OR is_sensitive) AS total
			FROM (
				SELECT (kind = %d AND bot_type = 'scanner') AS is_scanner,
					(status IN (%d, %d) AND (kind = %d OR (kind = %d AND (status = %d OR created_at < %s))) AND path REGEXP %s) AS is_sensitive
				FROM %i WHERE day BETWEEN %s AND %s
			) AS recon",
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_404,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			ggsec_stats_unverified_cutoff(),
			ggsec_stats_sensitive_path_regex(),
			ggsec_stats_table(),
			$since,
			ggsec_stats_until_day( $until )
		),
		ARRAY_A
	);
	return array(
		'scanner'   => (int) ( $row['scanner'] ?? 0 ),
		'sensitive' => (int) ( $row['sensitive_hits'] ?? 0 ), // "sensitive" is a reserved word in MySQL/MariaDB.
		'total'     => (int) ( $row['total'] ?? 0 ),
	);
}

/**
 * Pages fetched by AI crawlers, with the crawlers that fetched them.
 */
function ggsec_stats_ai_pages( $since ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT path, MAX(post_id) AS post_id, COUNT(*) AS hits,
				GROUP_CONCAT(DISTINCT bot_name ORDER BY bot_name SEPARATOR ', ') AS bots
			FROM %i WHERE day >= %s AND kind = %d AND bot_type = 'ai' AND status = %d
			GROUP BY path ORDER BY hits DESC LIMIT 20",
			ggsec_stats_table(),
			$since,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_OK
		),
		ARRAY_A
	);
}

/**
 * File downloads clicked by real visitors (PDF reports, archives, ...).
 */
function ggsec_stats_downloads( $since ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT path, COUNT(*) AS downloads, COUNT(DISTINCT CONCAT(day, visitor)) AS visitors FROM %i
			WHERE day >= %s AND status = %d GROUP BY path ORDER BY downloads DESC LIMIT 20',
			ggsec_stats_table(),
			$since,
			GGSEC_STATS_STATUS_DOWNLOAD
		),
		ARRAY_A
	);
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/*
 * Rendering.
 */

/**
 * HTML allowed in report table cells (built by the render functions below).
 */
function ggsec_stats_allowed_cell_html() {
	return array(
		'a'     => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'br'    => array(),
		'small' => array(),
		'code'  => array(),
		'em'    => array(),
		'span'  => array(
			'class' => true,
			'title' => true,
		),
	);
}

/**
 * SVG elements and attributes used by the chart.
 */
function ggsec_stats_allowed_svg() {
	return array(
		'svg'   => array(
			'class'      => true,
			'viewbox'    => true,
			'role'       => true,
			'aria-label' => true,
		),
		'g'     => array(),
		'title' => array(),
		'line'  => array(
			'class' => true,
			'x1'    => true,
			'y1'    => true,
			'x2'    => true,
			'y2'    => true,
		),
		'rect'  => array(
			'class'  => true,
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
		),
		'text'  => array(
			'class'       => true,
			'x'           => true,
			'y'           => true,
			'text-anchor' => true,
		),
	);
}

function ggsec_stats_badge( $text, $variant ) {
	return ' <span class="ggsec-stats-badge ' . esc_attr( $variant ) . '">' . esc_html( $text ) . '</span>';
}

/**
 * Stacked bar chart (humans bottom, bots top) as inline SVG; no JavaScript library.
 *
 * @param array<string,array{h:int,b:int}> $series
 */
function ggsec_stats_chart_svg( $series ) {
	$w      = 760;
	$h      = 200;
	$pad_l  = 36;
	$pad_b  = 22;
	$n      = max( 1, count( $series ) );
	$max    = 1;
	foreach ( $series as $v ) {
		$max = max( $max, $v['h'] + $v['b'] );
	}
	$plot_h = $h - $pad_b - 8;
	$slot   = ( $w - $pad_l ) / $n;
	$bar_w  = max( 2, $slot * 0.7 );

	$svg  = '<svg class="ggsec-stats-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr__( 'Humans and bots per day', 'ggsec-stats' ) . '">';
	$svg .= '<line x1="' . $pad_l . '" y1="' . ( 8 + $plot_h ) . '" x2="' . $w . '" y2="' . ( 8 + $plot_h ) . '" class="axis"/>';
	$svg .= '<text x="' . ( $pad_l - 6 ) . '" y="14" class="lbl" text-anchor="end">' . esc_html( number_format_i18n( $max ) ) . '</text>';
	$svg .= '<text x="' . ( $pad_l - 6 ) . '" y="' . ( 8 + $plot_h ) . '" class="lbl" text-anchor="end">0</text>';

	$i = 0;
	foreach ( $series as $day => $v ) {
		$x   = $pad_l + $i * $slot + ( $slot - $bar_w ) / 2;
		$hh  = $plot_h * $v['h'] / $max;
		$bh  = $plot_h * $v['b'] / $max;
		$y0  = 8 + $plot_h;
		$tip = sprintf(
			/* translators: 1: date, 2: number of human page views, 3: number of bot requests. */
			__( '%1$s: %2$s human views, %3$s bot requests', 'ggsec-stats' ),
			$day,
			number_format_i18n( $v['h'] ),
			number_format_i18n( $v['b'] )
		);

		$svg .= '<g><title>' . esc_html( $tip ) . '</title>';
		$svg .= sprintf( '<rect class="h" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>', $x, $y0 - $hh, $bar_w, $hh );
		$svg .= sprintf( '<rect class="b" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>', $x, $y0 - $hh - $bh, $bar_w, $bh );
		$svg .= '</g>';
		if ( 0 === $i % 5 || $i === $n - 1 ) {
			$svg .= sprintf( '<text class="lbl" x="%.1f" y="%d" text-anchor="middle">%s</text>', $x + $bar_w / 2, $h - 6, esc_html( substr( $day, 5 ) ) );
		}
		$i++;
	}
	return $svg . '</svg>';
}

// The statistics page lives under Dashboard (index.php?page=ggsec-stats), next to the dashboard widget.
function ggsec_stats_register_menu() {
	add_dashboard_page(
		__( 'GGSEC Stats', 'ggsec-stats' ),
		__( 'GGSEC Stats', 'ggsec-stats' ),
		'manage_options',
		'ggsec-stats',
		'ggsec_stats_render_page'
	);
}
add_action( 'admin_menu', 'ggsec_stats_register_menu' );

function ggsec_stats_admin_assets( $hook ) {
	if ( 'dashboard_page_ggsec-stats' === $hook || 'index.php' === $hook ) {
		wp_enqueue_style( 'ggsec-stats-admin', plugins_url( 'assets/admin.css', GGSEC_STATS_FILE ), array(), GGSEC_STATS_VERSION );
	}
}
add_action( 'admin_enqueue_scripts', 'ggsec_stats_admin_assets' );

/**
 * One headline card. $value is a number or a short preformatted string (e.g. "42%").
 */
function ggsec_stats_card( $label, $value, $hint = '' ) {
	echo '<div class="ggsec-stats-card"><span class="v">' . esc_html( is_int( $value ) ? number_format_i18n( $value ) : (string) $value ) . '</span>';
	echo '<span class="l">' . esc_html( $label ) . '</span>';
	if ( '' !== $hint ) {
		echo '<span class="hint">' . esc_html( $hint ) . '</span>';
	}
	echo '</div>';
}

/**
 * Report table. Cells are HTML fragments built from escaped values; they are filtered again on output.
 *
 * @param string[]   $headers Column headings (plain text).
 * @param string[][] $rows    Rows of HTML cells.
 */
function ggsec_stats_table_html( $headers, $rows ) {
	echo '<table class="widefat striped ggsec-stats-table"><thead><tr>';
	foreach ( $headers as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="' . (int) count( $headers ) . '"><em>' . esc_html__( 'No data yet.', 'ggsec-stats' ) . '</em></td></tr>';
	}
	$allowed = ggsec_stats_allowed_cell_html();
	foreach ( $rows as $cells ) {
		echo '<tr>';
		foreach ( $cells as $cell ) {
			echo '<td>' . wp_kses( $cell, $allowed ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function ggsec_stats_num( $value ) {
	return esc_html( number_format_i18n( (int) $value ) );
}

function ggsec_stats_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ggsec-stats' ) );
	}

	$ranges = ggsec_stats_ranges();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filter, whitelisted below.
	$range = isset( $_GET['range'] ) && is_string( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today';
	if ( ! array_key_exists( $range, $ranges ) ) {
		$range = 'today';
	}
	$since = ggsec_stats_since_day( $ranges[ $range ][0] );

	$sum       = ggsec_stats_summary( $since );
	$total     = $sum['human_views'] + $sum['bots'];
	$bot_share = $total ? number_format_i18n( round( 100 * $sum['bots'] / $total ) ) . '%' : '-';
	$refs      = ggsec_stats_top_referrers( $since );
	$bots      = ggsec_stats_bot_breakdown( $since );
	$recon     = ggsec_stats_recon_summary( $since );
	$downloads = ggsec_stats_downloads( $since );
	?>
	<div class="wrap ggsec-stats">
		<h1><?php esc_html_e( 'GGSEC Stats', 'ggsec-stats' ); ?></h1>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $ranges as $key => $def ) : ?>
				<a class="nav-tab<?php echo $key === $range ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( array( 'page' => 'ggsec-stats', 'range' => $key ), admin_url( 'index.php' ) ) ); ?>"><?php echo esc_html( $def[1] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="ggsec-stats-cards">
			<?php
			ggsec_stats_card( __( 'Human page views', 'ggsec-stats' ), $sum['human_views'] );
			ggsec_stats_card( __( 'Unique visitors', 'ggsec-stats' ), $sum['visitors'], __( 'per day, summed', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Known bots', 'ggsec-stats' ), $sum['bots_known'], __( 'identified by user agent', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Bots without JS', 'ggsec-stats' ), $sum['bots_nojs'], __( 'browser-like, never rendered the page', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Bot share', 'ggsec-stats' ), $bot_share, __( 'of all page requests', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Login/XML-RPC probes', 'ggsec-stats' ), $sum['probes'], __( 'failed logins, bot login scans, XML-RPC', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Online now', 'ggsec-stats' ), ggsec_stats_online_now(), __( 'humans, last 5 minutes', 'ggsec-stats' ) );
			ggsec_stats_card( __( 'Downloads', 'ggsec-stats' ), $sum['downloads'], __( 'file links clicked by visitors', 'ggsec-stats' ) );
			ggsec_stats_card(
				__( 'Security recon', 'ggsec-stats' ),
				$recon['total'],
				sprintf(
					/* translators: 1: requests from security scanners, 2: requests for sensitive paths. */
					__( '%1$s from scanners, %2$s on sensitive paths (overlap counted once)', 'ggsec-stats' ),
					number_format_i18n( $recon['scanner'] ),
					number_format_i18n( $recon['sensitive'] )
				)
			);
			?>
		</div>

		<h2>
			<?php esc_html_e( 'Last 30 days', 'ggsec-stats' ); ?>
			<span class="ggsec-stats-legend"><i class="h"></i> <?php esc_html_e( 'humans', 'ggsec-stats' ); ?> <i class="b"></i> <?php esc_html_e( 'bots', 'ggsec-stats' ); ?></span>
		</h2>
		<?php echo wp_kses( ggsec_stats_chart_svg( ggsec_stats_daily_series( 30 ) ), ggsec_stats_allowed_svg() ); ?>

		<div class="ggsec-stats-grid">
			<section>
				<h2><?php esc_html_e( 'Top pages', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_top_pages( $since ) as $r ) {
					$title  = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
					$link   = '<a href="' . esc_url( home_url( $r['path'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $r['path'] ) . '</a>';
					$rows[] = array(
						$link . ( '' !== $title ? '<br><small>' . esc_html( $title ) . '</small>' : '' ),
						ggsec_stats_num( $r['views'] ),
						ggsec_stats_num( $r['visitors'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'Page', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ), __( 'Visitors', 'ggsec-stats' ) ), $rows );
				?>
			</section>

			<section>
				<h2><?php esc_html_e( 'Referrers', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( $refs['hosts'] as $r ) {
					$rows[] = array( esc_html( $r['ref_host'] ), ggsec_stats_num( $r['views'] ) );
				}
				ggsec_stats_table_html( array( __( 'Source site', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ) ), $rows );
				?>
				<details>
					<summary><?php esc_html_e( 'Full referring URLs', 'ggsec-stats' ); ?></summary>
					<?php
					$rows = array();
					foreach ( $refs['urls'] as $r ) {
						$rows[] = array( esc_html( $r['ref_host'] . $r['ref_path'] ), ggsec_stats_num( $r['views'] ) );
					}
					ggsec_stats_table_html( array( __( 'Referring URL (query removed)', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ) ), $rows );
					?>
				</details>
				<?php if ( $refs['utm'] ) : ?>
					<h3><?php esc_html_e( 'Campaigns (utm_source / ref)', 'ggsec-stats' ); ?></h3>
					<?php
					$rows = array();
					foreach ( $refs['utm'] as $r ) {
						$rows[] = array( esc_html( $r['utm_source'] ), ggsec_stats_num( $r['views'] ) );
					}
					ggsec_stats_table_html( array( __( 'Source', 'ggsec-stats' ), __( 'Views', 'ggsec-stats' ) ), $rows );
					?>
				<?php endif; ?>
			</section>

			<section>
				<h2><?php esc_html_e( 'Bots', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( $bots['known'] as $r ) {
					$rows[] = array(
						'<span title="' . esc_attr( (string) $r['sample_ua'] ) . '">' . esc_html( $r['bot_name'] ) . '</span>',
						esc_html( ggsec_stats_bot_type_label( $r['bot_type'] ) )
							. ( 'scanner' === $r['bot_type'] ? ggsec_stats_badge( __( 'recon', 'ggsec-stats' ), 'alert' ) : '' ),
						ggsec_stats_num( $r['hits'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'Bot', 'ggsec-stats' ), __( 'Type', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );
				?>
				<h3><?php esc_html_e( 'Browser-like clients without JavaScript', 'ggsec-stats' ); ?></h3>
				<?php
				$rows = array();
				foreach ( $bots['nojs'] as $r ) {
					$rows[] = array(
						'<code>' . esc_html( '' !== $r['ua'] ? $r['ua'] : __( '(empty)', 'ggsec-stats' ) ) . '</code>',
						ggsec_stats_num( $r['hits'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'User agent', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );
				?>
			</section>

			<section>
				<h2><?php esc_html_e( 'Suspicious requests', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_suspicious_paths( $since ) as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>'
							. ( ggsec_stats_is_sensitive_path( $r['path'] ) ? ggsec_stats_badge( __( 'sensitive', 'ggsec-stats' ), 'alert' ) : '' ),
						GGSEC_STATS_STATUS_PROBE === (int) $r['status'] ? esc_html__( 'login / XML-RPC probe', 'ggsec-stats' ) : '404',
						ggsec_stats_num( $r['hits'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'Path', 'ggsec-stats' ), __( 'Kind', 'ggsec-stats' ), __( 'Requests', 'ggsec-stats' ) ), $rows );
				?>
				<h3><?php esc_html_e( 'Broken links (404 seen by real visitors)', 'ggsec-stats' ); ?></h3>
				<?php
				$rows = array();
				foreach ( ggsec_stats_broken_links( $since ) as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>',
						esc_html( $r['ref_host'] ? $r['ref_host'] : __( 'internal / direct', 'ggsec-stats' ) ),
						ggsec_stats_num( $r['hits'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'Path', 'ggsec-stats' ), __( 'Came from', 'ggsec-stats' ), __( 'Visits', 'ggsec-stats' ) ), $rows );
				?>
			</section>

			<section>
				<h2><?php esc_html_e( 'Pages read by AI crawlers', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_ai_pages( $since ) as $r ) {
					$title  = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
					$rows[] = array(
						esc_html( $r['path'] ) . ( '' !== $title ? '<br><small>' . esc_html( $title ) . '</small>' : '' ),
						esc_html( (string) $r['bots'] ),
						ggsec_stats_num( $r['hits'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'Page', 'ggsec-stats' ), __( 'Crawlers', 'ggsec-stats' ), __( 'Fetches', 'ggsec-stats' ) ), $rows );
				?>
			</section>

			<section>
				<h2><?php esc_html_e( 'Downloads', 'ggsec-stats' ); ?></h2>
				<?php
				$rows = array();
				foreach ( $downloads as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>',
						ggsec_stats_num( $r['downloads'] ),
						ggsec_stats_num( $r['visitors'] ),
					);
				}
				ggsec_stats_table_html( array( __( 'File', 'ggsec-stats' ), __( 'Downloads', 'ggsec-stats' ), __( 'Visitors', 'ggsec-stats' ) ), $rows );
				?>
				<p class="description"><?php esc_html_e( 'Counts clicks on file links on this site by real visitors. Direct links from outside (e-mail, social media) and bot downloads are not visible here, because the web server serves files without WordPress.', 'ggsec-stats' ); ?></p>
			</section>
		</div>

		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of days raw data is kept, 2: maximum recorded requests per client per day. */
					__( 'No cookies, no external services. Visitors are counted with a daily-rotating anonymous hash; raw IP addresses are never stored. Raw data is kept for %1$d days. Logged-in editors and administrators are not counted. At most %2$d requests per client per day are recorded (flood guard), so counts for aggressive scanners are a lower bound.', 'ggsec-stats' ),
					ggsec_stats_retention_days(),
					ggsec_stats_daily_client_cap()
				)
			);
			?>
		</p>

		<?php ggsec_stats_render_notify_settings(); ?>
	</div>
	<?php
}

function ggsec_stats_register_widget() {
	if ( current_user_can( 'manage_options' ) ) {
		wp_add_dashboard_widget( 'ggsec_stats_widget', __( 'GGSEC Stats - today', 'ggsec-stats' ), 'ggsec_stats_render_widget' );
	}
}
add_action( 'wp_dashboard_setup', 'ggsec_stats_register_widget' );

function ggsec_stats_render_widget() {
	$since = ggsec_stats_since_day( 1 );
	$sum   = ggsec_stats_summary( $since );
	$refs  = ggsec_stats_top_referrers( $since );
	echo '<div class="ggsec-stats-cards compact">';
	ggsec_stats_card( __( 'Human views', 'ggsec-stats' ), $sum['human_views'] );
	ggsec_stats_card( __( 'Visitors', 'ggsec-stats' ), $sum['visitors'] );
	ggsec_stats_card( __( 'Bots', 'ggsec-stats' ), $sum['bots'] );
	ggsec_stats_card( __( 'Online now', 'ggsec-stats' ), ggsec_stats_online_now() );
	echo '</div>';
	if ( $refs['hosts'] ) {
		echo '<p>' . esc_html__( 'Top referrer:', 'ggsec-stats' ) . ' <strong>' . esc_html( $refs['hosts'][0]['ref_host'] ) . '</strong> ('
			. esc_html( number_format_i18n( (int) $refs['hosts'][0]['views'] ) ) . ')</p>';
	}
	echo '<p><a href="' . esc_url( admin_url( 'index.php?page=ggsec-stats' ) ) . '">' . esc_html__( 'Open GGSEC Stats', 'ggsec-stats' ) . '</a></p>';
}
