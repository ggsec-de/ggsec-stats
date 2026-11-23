<?php
/**
 * Admin dashboard page and WP dashboard widget.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GGSEC_STATS_RANGES = array(
	'today' => array( 1, 'Today' ),
	'7d'    => array( 7, 'Last 7 days' ),
	'30d'   => array( 30, 'Last 30 days' ),
);

/**
 * First local day (Y-m-d) of a range that ends today and spans $days days.
 */
function ggsec_stats_since_day( $days ) {
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	return $today->modify( '-' . ( (int) $days - 1 ) . ' days' )->format( 'Y-m-d' );
}

/**
 * SQL condition for bot traffic: known bots plus unverified page loads whose beacon never arrived.
 * Probes (wp-login/xmlrpc) are reported separately and excluded here.
 */
function ggsec_stats_bot_condition() {
	global $wpdb;
	return $wpdb->prepare(
		'(status <> %d AND (kind = %d OR (kind = %d AND created_at < %s)))',
		GGSEC_STATS_STATUS_PROBE,
		GGSEC_STATS_KIND_BOT,
		GGSEC_STATS_KIND_UNVERIFIED,
		gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE )
	);
}

function ggsec_stats_human_condition() {
	global $wpdb;
	return $wpdb->prepare( '(kind = %d AND status = %d)', GGSEC_STATS_KIND_HUMAN, GGSEC_STATS_STATUS_OK );
}

/**
 * Headline numbers for a range.
 *
 * @return array<string,int>
 */
function ggsec_stats_summary( $since ) {
	global $wpdb;
	$table  = ggsec_stats_table();
	$human  = ggsec_stats_human_condition();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE );

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				SUM({$human}) AS human_views,
				COUNT(DISTINCT CASE WHEN {$human} THEN CONCAT(day, visitor) END) AS visitors,
				SUM(kind = %d AND status <> %d) AS bots_known,
				SUM(kind = %d AND status <> %d AND created_at < %s) AS bots_nojs,
				SUM(status = %d) AS probes,
				SUM(status = %d) AS downloads
			FROM {$table} WHERE day >= %s",
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			$cutoff,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_STATUS_DOWNLOAD,
			$since
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
	$table = ggsec_stats_table();
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT visitor) FROM {$table} WHERE kind = %d AND created_at >= %s",
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
	$table = ggsec_stats_table();
	$since = ggsec_stats_since_day( $days );
	$human = ggsec_stats_human_condition();
	$bot   = ggsec_stats_bot_condition();

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT day, SUM({$human}) AS h, SUM({$bot}) AS b FROM {$table} WHERE day >= %s GROUP BY day", $since ),
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

function ggsec_stats_query( $sql, ...$args ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
}

function ggsec_stats_top_pages( $since ) {
	$table = ggsec_stats_table();
	$human = ggsec_stats_human_condition();
	return ggsec_stats_query(
		"SELECT path, MAX(post_id) AS post_id, COUNT(*) AS views, COUNT(DISTINCT CONCAT(day, visitor)) AS visitors
		FROM {$table} WHERE day >= %s AND {$human} GROUP BY path ORDER BY views DESC LIMIT 15",
		$since
	);
}

function ggsec_stats_top_referrers( $since ) {
	$table = ggsec_stats_table();
	$human = ggsec_stats_human_condition();
	return array(
		'hosts' => ggsec_stats_query(
			"SELECT ref_host, COUNT(*) AS views FROM {$table}
			WHERE day >= %s AND {$human} AND ref_host IS NOT NULL GROUP BY ref_host ORDER BY views DESC LIMIT 15",
			$since
		),
		'urls'  => ggsec_stats_query(
			"SELECT ref_host, ref_path, COUNT(*) AS views FROM {$table}
			WHERE day >= %s AND {$human} AND ref_host IS NOT NULL GROUP BY ref_host, ref_path ORDER BY views DESC LIMIT 20",
			$since
		),
		'utm'   => ggsec_stats_query(
			"SELECT utm_source, COUNT(*) AS views FROM {$table}
			WHERE day >= %s AND {$human} AND utm_source IS NOT NULL GROUP BY utm_source ORDER BY views DESC LIMIT 10",
			$since
		),
	);
}

function ggsec_stats_bot_breakdown( $since ) {
	$table = ggsec_stats_table();
	return array(
		'known' => ggsec_stats_query(
			"SELECT bot_name, bot_type, COUNT(*) AS hits, MAX(ua) AS sample_ua FROM {$table}
			WHERE day >= %s AND kind = %d AND status <> %d GROUP BY bot_name, bot_type ORDER BY hits DESC LIMIT 30",
			$since,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_PROBE
		),
		'nojs'  => ggsec_stats_query(
			"SELECT COALESCE(ua, '') AS ua, COUNT(*) AS hits FROM {$table}
			WHERE day >= %s AND kind = %d AND status <> %d AND created_at < %s GROUP BY ua ORDER BY hits DESC LIMIT 10",
			$since,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE )
		),
	);
}

/**
 * 404 pages opened by confirmed humans: broken links (internal or from referring sites).
 */
function ggsec_stats_broken_links( $since ) {
	$table = ggsec_stats_table();
	return ggsec_stats_query(
		"SELECT path, MAX(ref_host) AS ref_host, COUNT(*) AS hits FROM {$table}
		WHERE day >= %s AND kind = %d AND status = %d GROUP BY path ORDER BY hits DESC LIMIT 20",
		$since,
		GGSEC_STATS_KIND_HUMAN,
		GGSEC_STATS_STATUS_404
	);
}

/**
 * 404s and probes from non-humans. Unverified 404s are only listed after the beacon grace period,
 * so a real visitor's 404 does not flash up here before the browser confirms it.
 */
function ggsec_stats_suspicious_paths( $since ) {
	$table = ggsec_stats_table();
	return ggsec_stats_query(
		"SELECT path, status, COUNT(*) AS hits FROM {$table}
		WHERE day >= %s AND status IN (%d, %d)
			AND (kind = %d OR (kind = %d AND (status = %d OR created_at < %s)))
		GROUP BY path, status ORDER BY hits DESC LIMIT 25",
		$since,
		GGSEC_STATS_STATUS_404,
		GGSEC_STATS_STATUS_PROBE,
		GGSEC_STATS_KIND_BOT,
		GGSEC_STATS_KIND_UNVERIFIED,
		GGSEC_STATS_STATUS_PROBE,
		gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE )
	);
}

/**
 * Security reconnaissance: requests from known scanners and non-human hits on sensitive paths.
 *
 * A request can be both (Nuclei asking for /.env); `total` counts each request once.
 *
 * @return array{scanner:int,sensitive:int,total:int}
 */
function ggsec_stats_recon_summary( $since ) {
	global $wpdb;
	$table = ggsec_stats_table();
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			// Sensitive = non-human requests for missing sensitive paths (existing pages with similar names are content).
			"SELECT SUM(is_scanner) AS scanner, SUM(is_sensitive) AS sensitive_hits, SUM(is_scanner OR is_sensitive) AS total
			FROM (
				SELECT (kind = %d AND bot_type = 'scanner') AS is_scanner,
					(status IN (%d, %d) AND (kind = %d OR (kind = %d AND (status = %d OR created_at < %s))) AND path REGEXP %s) AS is_sensitive
				FROM {$table} WHERE day >= %s
			) AS recon",
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_STATUS_404,
			GGSEC_STATS_STATUS_PROBE,
			GGSEC_STATS_KIND_BOT,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_STATUS_PROBE,
			gmdate( 'Y-m-d H:i:s', time() - GGSEC_STATS_UNVERIFIED_GRACE ),
			ggsec_stats_sensitive_path_regex(),
			$since
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
	$table = ggsec_stats_table();
	return ggsec_stats_query(
		"SELECT path, MAX(post_id) AS post_id, COUNT(*) AS hits,
			GROUP_CONCAT(DISTINCT bot_name ORDER BY bot_name SEPARATOR ', ') AS bots
		FROM {$table} WHERE day >= %s AND kind = %d AND bot_type = 'ai' AND status = %d
		GROUP BY path ORDER BY hits DESC LIMIT 20",
		$since,
		GGSEC_STATS_KIND_BOT,
		GGSEC_STATS_STATUS_OK
	);
}

/**
 * File downloads clicked by real visitors (PDF reports, archives, ...).
 */
function ggsec_stats_downloads( $since ) {
	$table = ggsec_stats_table();
	return ggsec_stats_query(
		"SELECT path, COUNT(*) AS downloads, COUNT(DISTINCT CONCAT(day, visitor)) AS visitors FROM {$table}
		WHERE day >= %s AND status = %d GROUP BY path ORDER BY downloads DESC LIMIT 20",
		$since,
		GGSEC_STATS_STATUS_DOWNLOAD
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

	$svg  = '<svg class="ggsec-stats-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Humans and bots per day">';
	$svg .= '<line x1="' . $pad_l . '" y1="' . ( 8 + $plot_h ) . '" x2="' . $w . '" y2="' . ( 8 + $plot_h ) . '" class="axis"/>';
	$svg .= '<text x="' . ( $pad_l - 6 ) . '" y="14" class="lbl" text-anchor="end">' . esc_html( number_format_i18n( $max ) ) . '</text>';
	$svg .= '<text x="' . ( $pad_l - 6 ) . '" y="' . ( 8 + $plot_h ) . '" class="lbl" text-anchor="end">0</text>';

	$i = 0;
	foreach ( $series as $day => $v ) {
		$x  = $pad_l + $i * $slot + ( $slot - $bar_w ) / 2;
		$hh = $plot_h * $v['h'] / $max;
		$bh = $plot_h * $v['b'] / $max;
		$y0 = 8 + $plot_h;
		$tip = esc_html( $day . ': ' . $v['h'] . ' human views, ' . $v['b'] . ' bot requests' );

		$svg .= '<g><title>' . $tip . '</title>';
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

function ggsec_stats_register_menu() {
	add_menu_page( 'GGSEC Stats', 'GGSEC Stats', 'manage_options', 'ggsec-stats', 'ggsec_stats_render_page', 'dashicons-chart-bar', 3 );
}
add_action( 'admin_menu', 'ggsec_stats_register_menu' );

function ggsec_stats_admin_assets( $hook ) {
	if ( 'toplevel_page_ggsec-stats' === $hook || 'index.php' === $hook ) {
		wp_enqueue_style( 'ggsec-stats-admin', plugins_url( 'assets/admin.css', GGSEC_STATS_FILE ), array(), GGSEC_STATS_VERSION );
	}
}
add_action( 'admin_enqueue_scripts', 'ggsec_stats_admin_assets' );

function ggsec_stats_card( $label, $value, $hint = '' ) {
	printf(
		'<div class="ggsec-stats-card"><span class="v">%s</span><span class="l">%s</span>%s</div>',
		esc_html( is_int( $value ) ? number_format_i18n( $value ) : (string) $value ),
		esc_html( $label ),
		$hint ? '<span class="hint">' . esc_html( $hint ) . '</span>' : ''
	);
}

function ggsec_stats_table_html( $headers, $rows ) {
	echo '<table class="widefat striped ggsec-stats-table"><thead><tr>';
	foreach ( $headers as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="' . count( $headers ) . '"><em>No data yet.</em></td></tr>';
	}
	foreach ( $rows as $cells ) {
		echo '<tr>';
		foreach ( $cells as $cell ) {
			// Cells are pre-escaped HTML fragments built by the callers below.
			echo '<td>' . $cell . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
}

function ggsec_stats_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden.' );
	}

	$range = isset( $_GET['range'] ) && is_string( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'today';
	if ( ! array_key_exists( $range, GGSEC_STATS_RANGES ) ) {
		$range = 'today';
	}
	$since = ggsec_stats_since_day( GGSEC_STATS_RANGES[ $range ][0] );

	$sum       = ggsec_stats_summary( $since );
	$total     = $sum['human_views'] + $sum['bots'];
	$bot_share = $total ? round( 100 * $sum['bots'] / $total ) . '%' : '-';
	$refs      = ggsec_stats_top_referrers( $since );
	$bots      = ggsec_stats_bot_breakdown( $since );
	$recon     = ggsec_stats_recon_summary( $since );
	$downloads = ggsec_stats_downloads( $since );
	?>
	<div class="wrap ggsec-stats">
		<h1>GGSEC Stats</h1>

		<nav class="nav-tab-wrapper">
			<?php foreach ( GGSEC_STATS_RANGES as $key => $def ) : ?>
				<a class="nav-tab<?php echo $key === $range ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( add_query_arg( array( 'page' => 'ggsec-stats', 'range' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $def[1] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="ggsec-stats-cards">
			<?php
			ggsec_stats_card( 'Human page views', $sum['human_views'] );
			ggsec_stats_card( 'Unique visitors', $sum['visitors'], 'per day, summed' );
			ggsec_stats_card( 'Known bots', $sum['bots_known'], 'identified by user agent' );
			ggsec_stats_card( 'Bots without JS', $sum['bots_nojs'], 'browser-like, never rendered the page' );
			ggsec_stats_card( 'Bot share', $bot_share, 'of all page requests' );
			ggsec_stats_card( 'Login/XML-RPC probes', $sum['probes'], 'failed logins, bot login scans, XML-RPC' );
			ggsec_stats_card( 'Online now', ggsec_stats_online_now(), 'humans, last 5 minutes' );
			ggsec_stats_card( 'Downloads', $sum['downloads'], 'file links clicked by visitors' );
			ggsec_stats_card( 'Security recon', $recon['total'], $recon['scanner'] . ' from scanners, ' . $recon['sensitive'] . ' on sensitive paths (overlap counted once)' );
			?>
		</div>

		<h2>Last 30 days <span class="ggsec-stats-legend"><i class="h"></i> humans <i class="b"></i> bots</span></h2>
		<?php echo ggsec_stats_chart_svg( ggsec_stats_daily_series( 30 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>

		<div class="ggsec-stats-grid">
			<section>
				<h2>Top pages</h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_top_pages( $since ) as $r ) {
					$title  = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
					$link   = '<a href="' . esc_url( home_url( $r['path'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $r['path'] ) . '</a>';
					$rows[] = array(
						$link . ( '' !== $title ? '<br><small>' . esc_html( $title ) . '</small>' : '' ),
						esc_html( number_format_i18n( (int) $r['views'] ) ),
						esc_html( number_format_i18n( (int) $r['visitors'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'Page', 'Views', 'Visitors' ), $rows );
				?>
			</section>

			<section>
				<h2>Referrers</h2>
				<?php
				$rows = array();
				foreach ( $refs['hosts'] as $r ) {
					$rows[] = array( esc_html( $r['ref_host'] ), esc_html( number_format_i18n( (int) $r['views'] ) ) );
				}
				ggsec_stats_table_html( array( 'Source site', 'Views' ), $rows );
				?>
				<details>
					<summary>Full referring URLs</summary>
					<?php
					$rows = array();
					foreach ( $refs['urls'] as $r ) {
						$rows[] = array( esc_html( $r['ref_host'] . $r['ref_path'] ), esc_html( number_format_i18n( (int) $r['views'] ) ) );
					}
					ggsec_stats_table_html( array( 'Referring URL (query removed)', 'Views' ), $rows );
					?>
				</details>
				<?php if ( $refs['utm'] ) : ?>
					<h3>Campaigns (utm_source / ref)</h3>
					<?php
					$rows = array();
					foreach ( $refs['utm'] as $r ) {
						$rows[] = array( esc_html( $r['utm_source'] ), esc_html( number_format_i18n( (int) $r['views'] ) ) );
					}
					ggsec_stats_table_html( array( 'Source', 'Views' ), $rows );
					?>
				<?php endif; ?>
			</section>

			<section>
				<h2>Bots</h2>
				<?php
				$rows = array();
				foreach ( $bots['known'] as $r ) {
					$rows[] = array(
						'<span title="' . esc_attr( (string) $r['sample_ua'] ) . '">' . esc_html( $r['bot_name'] ) . '</span>',
						esc_html( ggsec_stats_bot_type_label( $r['bot_type'] ) )
							. ( 'scanner' === $r['bot_type'] ? ggsec_stats_badge( 'recon', 'alert' ) : '' ),
						esc_html( number_format_i18n( (int) $r['hits'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'Bot', 'Type', 'Requests' ), $rows );
				?>
				<h3>Browser-like clients without JavaScript</h3>
				<?php
				$rows = array();
				foreach ( $bots['nojs'] as $r ) {
					$rows[] = array( '<code>' . esc_html( '' !== $r['ua'] ? $r['ua'] : '(empty)' ) . '</code>', esc_html( number_format_i18n( (int) $r['hits'] ) ) );
				}
				ggsec_stats_table_html( array( 'User agent', 'Requests' ), $rows );
				?>
			</section>

			<section>
				<h2>Suspicious requests</h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_suspicious_paths( $since ) as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>'
							. ( ggsec_stats_is_sensitive_path( $r['path'] ) ? ggsec_stats_badge( 'sensitive', 'alert' ) : '' ),
						GGSEC_STATS_STATUS_PROBE === (int) $r['status'] ? 'login / XML-RPC probe' : '404',
						esc_html( number_format_i18n( (int) $r['hits'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'Path', 'Kind', 'Requests' ), $rows );
				?>
				<h3>Broken links (404 seen by real visitors)</h3>
				<?php
				$rows = array();
				foreach ( ggsec_stats_broken_links( $since ) as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>',
						esc_html( $r['ref_host'] ? $r['ref_host'] : 'internal / direct' ),
						esc_html( number_format_i18n( (int) $r['hits'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'Path', 'Came from', 'Visits' ), $rows );
				?>
			</section>

			<section>
				<h2>Pages read by AI crawlers</h2>
				<?php
				$rows = array();
				foreach ( ggsec_stats_ai_pages( $since ) as $r ) {
					$title  = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '';
					$rows[] = array(
						esc_html( $r['path'] ) . ( '' !== $title ? '<br><small>' . esc_html( $title ) . '</small>' : '' ),
						esc_html( (string) $r['bots'] ),
						esc_html( number_format_i18n( (int) $r['hits'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'Page', 'Crawlers', 'Fetches' ), $rows );
				?>
			</section>

			<section>
				<h2>Downloads</h2>
				<?php
				$rows = array();
				foreach ( $downloads as $r ) {
					$rows[] = array(
						'<code>' . esc_html( $r['path'] ) . '</code>',
						esc_html( number_format_i18n( (int) $r['downloads'] ) ),
						esc_html( number_format_i18n( (int) $r['visitors'] ) ),
					);
				}
				ggsec_stats_table_html( array( 'File', 'Downloads', 'Visitors' ), $rows );
				?>
				<p class="description">Counts clicks on file links on this site by real visitors. Direct links from outside (e-mail, social media) and bot downloads are not visible here, because the web server serves files without WordPress.</p>
			</section>
		</div>

		<p class="description">
			No cookies, no external services. Visitors are counted with a daily-rotating anonymous hash; raw IP addresses
			are never stored. Raw data is kept for <?php echo (int) ggsec_stats_retention_days(); ?> days.
			Logged-in editors and administrators are not counted. At most <?php echo (int) ggsec_stats_daily_client_cap(); ?>
			requests per client per day are recorded (flood guard), so counts for aggressive scanners are a lower bound.
		</p>
	</div>
	<?php
}

function ggsec_stats_register_widget() {
	if ( current_user_can( 'manage_options' ) ) {
		wp_add_dashboard_widget( 'ggsec_stats_widget', 'GGSEC Stats - today', 'ggsec_stats_render_widget' );
	}
}
add_action( 'wp_dashboard_setup', 'ggsec_stats_register_widget' );

function ggsec_stats_render_widget() {
	$since = ggsec_stats_since_day( 1 );
	$sum   = ggsec_stats_summary( $since );
	$refs  = ggsec_stats_top_referrers( $since );
	echo '<div class="ggsec-stats-cards compact">';
	ggsec_stats_card( 'Human views', $sum['human_views'] );
	ggsec_stats_card( 'Visitors', $sum['visitors'] );
	ggsec_stats_card( 'Bots', $sum['bots'] );
	ggsec_stats_card( 'Online now', ggsec_stats_online_now() );
	echo '</div>';
	if ( $refs['hosts'] ) {
		printf(
			'<p>Top referrer: <strong>%s</strong> (%s)</p>',
			esc_html( $refs['hosts'][0]['ref_host'] ),
			esc_html( number_format_i18n( (int) $refs['hosts'][0]['views'] ) )
		);
	}
	printf( '<p><a href="%s">Open GGSEC Stats</a></p>', esc_url( admin_url( 'admin.php?page=ggsec-stats' ) ) );
}
