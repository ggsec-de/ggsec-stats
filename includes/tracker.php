<?php
/**
 * Hit recording (server side) and the browser beacon endpoint.
 *
 * Privacy model: no cookies, no browser storage, raw IPs are never stored. A visitor is identified only by a
 * truncated HMAC of IP + user agent + day, keyed with a random salt that is replaced every day.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['ggsec_stats_hit_id'] = 0;

function ggsec_stats_server( $key ) {
	return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : '';
}

function ggsec_stats_truncate( $value, $length ) {
	$value = wp_check_invalid_utf8( (string) $value, true );
	return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
}

function ggsec_stats_daily_salt() {
	$today = current_time( 'Y-m-d' );
	$salt  = get_option( 'ggsec_stats_salt' );
	if ( ! $salt || get_option( 'ggsec_stats_salt_day' ) !== $today ) {
		// The previous salt is discarded, so yesterday's visitor hashes can no longer be recomputed.
		$salt = bin2hex( random_bytes( 32 ) );
		update_option( 'ggsec_stats_salt', $salt, true );
		update_option( 'ggsec_stats_salt_day', $today, true );
	}
	return $salt;
}

function ggsec_stats_visitor_hash( $ua ) {
	// REMOTE_ADDR only: forwarded-for headers are client controlled and would allow spoofing.
	$ip = ggsec_stats_server( 'REMOTE_ADDR' );
	return substr( hash_hmac( 'sha256', $ip . '|' . $ua . '|' . current_time( 'Y-m-d' ), ggsec_stats_daily_salt() ), 0, 16 );
}

function ggsec_stats_token( $hit_id ) {
	return substr( hash_hmac( 'sha256', 'ggsec-stats-hit|' . (int) $hit_id, wp_salt( 'nonce' ) ), 0, 32 );
}

/**
 * Request path relative to the WordPress home path, without query string.
 */
function ggsec_stats_request_path() {
	return ggsec_stats_normalize_path( (string) wp_parse_url( ggsec_stats_server( 'REQUEST_URI' ), PHP_URL_PATH ) );
}

/**
 * Make an absolute URL path relative to the WordPress home path (so local sub-directory installs and the
 * live site report the same paths).
 */
function ggsec_stats_normalize_path( $path ) {
	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	if ( '' !== $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = '/' . substr( $path, strlen( $home_path ) );
	}
	return ggsec_stats_truncate( '' === $path ? '/' : $path, 255 );
}

/**
 * External referrer as [host, path]; internal and malformed referrers return [null, null].
 * The query string is dropped because it can contain personal data (search terms, e-mail tokens).
 */
function ggsec_stats_referrer() {
	$ref = ggsec_stats_server( 'HTTP_REFERER' );
	if ( '' === $ref ) {
		return array( null, null );
	}
	$parts = wp_parse_url( $ref );
	$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	if ( '' === $host ) {
		return array( null, null );
	}
	$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $own ) ) {
		return array( null, null );
	}
	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	return array( ggsec_stats_truncate( $host, 190 ), ggsec_stats_truncate( $path, 255 ) );
}

function ggsec_stats_utm_source() {
	foreach ( array( 'utm_source', 'ref' ) as $key ) {
		if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			if ( '' !== $value ) {
				return ggsec_stats_truncate( $value, 64 );
			}
		}
	}
	return null;
}

function ggsec_stats_is_prefetch() {
	foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
		if ( false !== stripos( ggsec_stats_server( $header ), 'prefetch' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Insert one hit row.
 *
 * @param int      $status  GGSEC_STATS_STATUS_* value.
 * @param string   $path    Normalized request path.
 * @param int|null $post_id    Queried post for successful singular views.
 * @param int|null $force_kind Kind to store instead of classifying the request (used for confirmed downloads).
 * @return int Inserted hit id (0 on failure or when the flood guard skipped the write).
 */
function ggsec_stats_record_hit( $status, $path, $post_id = null, $force_kind = null ) {
	global $wpdb;

	$ua      = ggsec_stats_truncate( ggsec_stats_server( 'HTTP_USER_AGENT' ), 255 );
	$visitor = ggsec_stats_visitor_hash( $ua );
	$day     = current_time( 'Y-m-d' );
	$table   = ggsec_stats_table();
	$cap     = ggsec_stats_daily_client_cap();

	// Flood guard: bounded index read (stops counting at the cap) instead of an extra write.
	$seen = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE day = %s AND visitor = %s LIMIT %d) AS capped", $day, $visitor, $cap )
	);
	if ( $seen >= $cap ) {
		return 0;
	}

	$bot = null === $force_kind ? ggsec_stats_classify_ua( $ua ) : null;

	if ( null !== $force_kind ) {
		$kind = (int) $force_kind;
	} elseif ( $bot ) {
		$kind = GGSEC_STATS_KIND_BOT;
	} elseif ( GGSEC_STATS_STATUS_PROBE !== $status && ggsec_stats_is_prefetch() ) {
		$kind = GGSEC_STATS_KIND_PREFETCH;
	} else {
		$kind = GGSEC_STATS_KIND_UNVERIFIED;
	}

	list( $ref_host, $ref_path ) = ggsec_stats_referrer();

	$row = array(
		'created_at' => current_time( 'mysql', true ),
		'day'        => $day,
		'path'       => $path,
		'post_id'    => $post_id ? (int) $post_id : null,
		'status'     => (int) $status,
		'visitor'    => $visitor,
		'kind'       => $kind,
		'bot_name'   => $bot ? $bot[0] : null,
		'bot_type'   => $bot ? $bot[1] : null,
		// The user agent is kept for bots and unverified clients; it is erased once a browser confirms the view.
		'ua'         => '' !== $ua && GGSEC_STATS_KIND_HUMAN !== $kind ? $ua : null,
		'ref_host'   => $ref_host,
		'ref_path'   => $ref_path,
		'utm_source' => ggsec_stats_utm_source(),
	);

	$ok = $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	if ( ! $ok ) {
		return 0;
	}
	$id = (int) $wpdb->insert_id;

	// Only served pages get a beacon (probes and downloads have no page of their own).
	if ( null === $force_kind && GGSEC_STATS_KIND_BOT !== $kind && in_array( $status, array( GGSEC_STATS_STATUS_OK, GGSEC_STATS_STATUS_404 ), true ) ) {
		$GLOBALS['ggsec_stats_hit_id'] = $id;
	}
	return $id;
}

function ggsec_stats_should_track_page() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	if ( ! in_array( strtoupper( ggsec_stats_server( 'REQUEST_METHOD' ) ), array( 'GET', 'HEAD' ), true ) ) {
		return false;
	}
	if ( is_feed() || is_robots() || is_favicon() || is_trackback() || is_preview() || is_embed() || is_customize_preview() ) {
		return false;
	}
	// Browsers label sub-resource requests (images, scripts, fetch, iframes); only full page loads count.
	$dest = ggsec_stats_server( 'HTTP_SEC_FETCH_DEST' );
	if ( '' !== $dest && 'document' !== strtolower( $dest ) ) {
		return false;
	}
	// Site editors and administrators do not pollute the statistics.
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return false;
	}
	return (bool) apply_filters( 'ggsec_stats_should_track', true );
}

function ggsec_stats_track_page() {
	if ( ! ggsec_stats_should_track_page() ) {
		return;
	}
	if ( is_404() ) {
		ggsec_stats_record_hit( GGSEC_STATS_STATUS_404, ggsec_stats_request_path() );
		return;
	}
	$post_id = is_singular() ? get_queried_object_id() : null;
	ggsec_stats_record_hit( GGSEC_STATS_STATUS_OK, ggsec_stats_request_path(), $post_id );
}
add_action( 'template_redirect', 'ggsec_stats_track_page', 99 );

/**
 * wp-login.php and xmlrpc.php never reach template_redirect; they are recorded as probes
 * (reported in the suspicious section only, never as page views).
 *
 * Login page loads are recorded only for bot/tool user agents (login discovery scans), so a normal
 * browser opening the login form (including the site owner) is not counted.
 */
function ggsec_stats_track_login_scan() {
	if ( is_user_logged_in() || ! in_array( strtoupper( ggsec_stats_server( 'REQUEST_METHOD' ) ), array( 'GET', 'HEAD' ), true ) ) {
		return;
	}
	if ( ggsec_stats_classify_ua( ggsec_stats_server( 'HTTP_USER_AGENT' ) ) ) {
		ggsec_stats_record_hit( GGSEC_STATS_STATUS_PROBE, ggsec_stats_request_path() );
	}
}
add_action( 'login_init', 'ggsec_stats_track_login_scan' );

/**
 * Failed username/password attempts on wp-login.php (brute force). XML-RPC failures are skipped
 * because the XML-RPC request itself is already recorded below.
 */
function ggsec_stats_track_login_failed() {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return;
	}
	ggsec_stats_record_hit( GGSEC_STATS_STATUS_PROBE, ggsec_stats_request_path() );
}
add_action( 'wp_login_failed', 'ggsec_stats_track_login_failed' );

function ggsec_stats_track_xmlrpc_probe() {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		ggsec_stats_record_hit( GGSEC_STATS_STATUS_PROBE, ggsec_stats_request_path() );
	}
}
add_action( 'init', 'ggsec_stats_track_xmlrpc_probe' );

function ggsec_stats_enqueue_beacon() {
	$id = (int) $GLOBALS['ggsec_stats_hit_id'];
	if ( ! $id ) {
		return;
	}
	wp_enqueue_script(
		'ggsec-stats-beacon',
		plugins_url( 'assets/beacon.js', GGSEC_STATS_FILE ),
		array(),
		GGSEC_STATS_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_add_inline_script(
		'ggsec-stats-beacon',
		'window.ggsecStats=' . wp_json_encode(
			array(
				'u' => rest_url( 'ggsec-stats/v1/hit' ),
				'd' => rest_url( 'ggsec-stats/v1/download' ),
				'x' => ggsec_stats_download_extensions(),
				'i' => $id,
				't' => ggsec_stats_token( $id ),
			)
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'ggsec_stats_enqueue_beacon' );

function ggsec_stats_register_rest() {
	register_rest_route(
		'ggsec-stats/v1',
		'/hit',
		array(
			'methods'             => 'POST',
			// Public by design: the HMAC token proves the hit was issued by this site for this page view.
			'permission_callback' => '__return_true',
			'callback'            => 'ggsec_stats_rest_confirm',
			'args'                => array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
					'minimum'  => 1,
				),
				't'  => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'ggsec-stats/v1',
		'/download',
		array(
			'methods'             => 'POST',
			// Public by design: requires the HMAC token of a recent non-bot page view (see callback).
			'permission_callback' => '__return_true',
			'callback'            => 'ggsec_stats_rest_download',
			'args'                => array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
					'minimum'  => 1,
				),
				't'  => array(
					'required' => true,
					'type'     => 'string',
				),
				'f'  => array(
					'required'  => true,
					'type'      => 'string',
					'maxLength' => 1024,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'ggsec_stats_register_rest' );

/**
 * Mark a served page as viewed by a real browser. Only a token issued for that hit is accepted, only
 * unverified/prefetch hits from the last 30 minutes are upgraded, and a replay changes nothing.
 */
function ggsec_stats_rest_confirm( WP_REST_Request $request ) {
	global $wpdb;

	$id = (int) $request['id'];
	if ( ! hash_equals( ggsec_stats_token( $id ), (string) $request['t'] ) ) {
		return new WP_Error( 'ggsec_stats_invalid_token', 'Invalid token.', array( 'status' => 403 ) );
	}

	$table = ggsec_stats_table();
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET kind = %d, ua = NULL WHERE id = %d AND kind IN (%d, %d) AND created_at >= %s",
			GGSEC_STATS_KIND_HUMAN,
			$id,
			GGSEC_STATS_KIND_UNVERIFIED,
			GGSEC_STATS_KIND_PREFETCH,
			gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS )
		)
	);

	return new WP_REST_Response( null, 204 );
}

/**
 * File extensions counted as downloads.
 *
 * @return string[]
 */
function ggsec_stats_download_extensions() {
	$ext = array( 'pdf', 'zip', '7z', 'rar', 'gz', 'tgz', 'xz', 'bz2', 'tar', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'csv', 'asc', 'gpg', 'bin', 'img', 'iso', 'exe', 'msi', 'dmg', 'deb', 'rpm', 'apk' );
	return array_values( array_unique( array_map( 'strtolower', (array) apply_filters( 'ggsec_stats_download_extensions', $ext ) ) ) );
}

/**
 * Record a click on a same-site file link. The click must come with the token of a page view that was
 * served in the last 12 hours and was not a bot, so downloads cannot be injected without loading a page.
 */
function ggsec_stats_rest_download( WP_REST_Request $request ) {
	global $wpdb;

	$id = (int) $request['id'];
	if ( ! hash_equals( ggsec_stats_token( $id ), (string) $request['t'] ) ) {
		return new WP_Error( 'ggsec_stats_invalid_token', 'Invalid token.', array( 'status' => 403 ) );
	}

	$table  = ggsec_stats_table();
	$parent = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE id = %d AND kind <> %d AND created_at >= %s",
			$id,
			GGSEC_STATS_KIND_BOT,
			gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS )
		)
	);
	if ( ! $parent ) {
		return new WP_REST_Response( null, 204 );
	}

	$raw  = (string) wp_parse_url( (string) $request['f'], PHP_URL_PATH );
	$path = ggsec_stats_normalize_path( $raw );
	$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( '' === $raw || '/' !== $raw[0] || ! in_array( $ext, ggsec_stats_download_extensions(), true ) ) {
		return new WP_Error( 'ggsec_stats_invalid_file', 'Not a tracked file.', array( 'status' => 400 ) );
	}

	ggsec_stats_record_hit( GGSEC_STATS_STATUS_DOWNLOAD, $path, null, GGSEC_STATS_KIND_HUMAN );
	return new WP_REST_Response( null, 204 );
}
