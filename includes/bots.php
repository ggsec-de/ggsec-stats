<?php
/**
 * User-agent based bot classification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known user-agent substrings => [display name, type]. Checked case-insensitively, in order,
 * so more specific entries (e.g. Applebot-Extended) must come before generic ones (Applebot).
 *
 * @return array<string, array{0:string,1:string}>
 */
function ggsec_stats_bot_patterns() {
	$patterns = array(
		// AI crawlers and assistants.
		'GPTBot'              => array( 'GPTBot (OpenAI)', 'ai' ),
		'ChatGPT-User'        => array( 'ChatGPT-User (OpenAI)', 'ai' ),
		'OAI-SearchBot'       => array( 'OAI-SearchBot (OpenAI)', 'ai' ),
		'ClaudeBot'           => array( 'ClaudeBot (Anthropic)', 'ai' ),
		'Claude-User'         => array( 'Claude-User (Anthropic)', 'ai' ),
		'Claude-SearchBot'    => array( 'Claude-SearchBot (Anthropic)', 'ai' ),
		'anthropic-ai'        => array( 'anthropic-ai', 'ai' ),
		'PerplexityBot'       => array( 'PerplexityBot', 'ai' ),
		'Perplexity-User'     => array( 'Perplexity-User', 'ai' ),
		'Applebot-Extended'   => array( 'Applebot-Extended', 'ai' ),
		'Bytespider'          => array( 'Bytespider (ByteDance)', 'ai' ),
		'CCBot'               => array( 'CCBot (Common Crawl)', 'ai' ),
		'Amazonbot'           => array( 'Amazonbot', 'ai' ),
		'meta-externalagent'  => array( 'Meta AI crawler', 'ai' ),
		'meta-externalfetcher' => array( 'Meta AI fetcher', 'ai' ),
		'cohere-ai'           => array( 'cohere-ai', 'ai' ),
		'MistralAI-User'      => array( 'MistralAI-User', 'ai' ),
		'Diffbot'             => array( 'Diffbot', 'ai' ),
		'YouBot'              => array( 'YouBot', 'ai' ),
		'Timpibot'            => array( 'Timpibot', 'ai' ),
		'ImagesiftBot'        => array( 'ImagesiftBot', 'ai' ),

		// Search engines.
		'Googlebot'           => array( 'Googlebot', 'search' ),
		'Google-InspectionTool' => array( 'Google Inspection Tool', 'search' ),
		'Storebot-Google'     => array( 'Storebot-Google', 'search' ),
		'AdsBot-Google'       => array( 'AdsBot-Google', 'search' ),
		'Mediapartners-Google' => array( 'Google AdSense', 'search' ),
		'GoogleOther'         => array( 'GoogleOther', 'search' ),
		'bingbot'             => array( 'Bingbot', 'search' ),
		'BingPreview'         => array( 'BingPreview', 'search' ),
		'DuckDuckBot'         => array( 'DuckDuckBot', 'search' ),
		'DuckAssistBot'       => array( 'DuckAssistBot', 'ai' ),
		'YandexBot'           => array( 'YandexBot', 'search' ),
		'YandexImages'        => array( 'YandexImages', 'search' ),
		'Baiduspider'         => array( 'Baiduspider', 'search' ),
		'Applebot'            => array( 'Applebot', 'search' ),
		'SeznamBot'           => array( 'SeznamBot', 'search' ),
		'Qwantbot'            => array( 'Qwantbot', 'search' ),
		'PetalBot'            => array( 'PetalBot (Huawei)', 'search' ),
		'Sogou web spider'    => array( 'Sogou', 'search' ),
		'Yeti/'               => array( 'Yeti (Naver)', 'search' ),
		'MojeekBot'           => array( 'MojeekBot', 'search' ),
		'Slurp'               => array( 'Yahoo Slurp', 'search' ),

		// SEO tools.
		'AhrefsBot'           => array( 'AhrefsBot', 'seo' ),
		'AhrefsSiteAudit'     => array( 'Ahrefs Site Audit', 'seo' ),
		'SemrushBot'          => array( 'SemrushBot', 'seo' ),
		'SiteAuditBot'        => array( 'Semrush SiteAuditBot', 'seo' ),
		'MJ12bot'             => array( 'MJ12bot (Majestic)', 'seo' ),
		'DotBot'              => array( 'DotBot (Moz)', 'seo' ),
		'rogerbot'            => array( 'rogerbot (Moz)', 'seo' ),
		'BLEXBot'             => array( 'BLEXBot', 'seo' ),
		'DataForSeoBot'       => array( 'DataForSeoBot', 'seo' ),
		'serpstatbot'         => array( 'serpstatbot', 'seo' ),
		'SeekportBot'         => array( 'SeekportBot', 'seo' ),
		'Barkrowler'          => array( 'Barkrowler', 'seo' ),
		'Screaming Frog'      => array( 'Screaming Frog', 'seo' ),

		// Social / link previews.
		'facebookexternalhit' => array( 'Facebook preview', 'social' ),
		'facebookcatalog'     => array( 'Facebook catalog', 'social' ),
		'Twitterbot'          => array( 'Twitterbot (X)', 'social' ),
		'LinkedInBot'         => array( 'LinkedInBot', 'social' ),
		'Slackbot'            => array( 'Slackbot', 'social' ),
		'Discordbot'          => array( 'Discordbot', 'social' ),
		'TelegramBot'         => array( 'TelegramBot', 'social' ),
		'WhatsApp'            => array( 'WhatsApp preview', 'social' ),
		'Pinterestbot'        => array( 'Pinterestbot', 'social' ),
		'redditbot'           => array( 'redditbot', 'social' ),
		'SkypeUriPreview'     => array( 'Skype preview', 'social' ),
		'Mastodon'            => array( 'Mastodon preview', 'social' ),
		'Iframely'            => array( 'Iframely', 'social' ),

		// Monitoring.
		'UptimeRobot'         => array( 'UptimeRobot', 'monitor' ),
		'Pingdom'             => array( 'Pingdom', 'monitor' ),
		'StatusCake'          => array( 'StatusCake', 'monitor' ),
		'Site24x7'            => array( 'Site24x7', 'monitor' ),
		'Better Uptime'       => array( 'Better Uptime', 'monitor' ),
		'Uptime-Kuma'         => array( 'Uptime Kuma', 'monitor' ),
		'Jetpack'             => array( 'Jetpack', 'monitor' ),
		'WordPress/'          => array( 'WordPress loopback', 'monitor' ),

		// Security scanners.
		'Nuclei'              => array( 'Nuclei', 'scanner' ),
		'sqlmap'              => array( 'sqlmap', 'scanner' ),
		'Nikto'               => array( 'Nikto', 'scanner' ),
		'zgrab'               => array( 'zgrab', 'scanner' ),
		'masscan'             => array( 'masscan', 'scanner' ),
		'Nmap'                => array( 'Nmap', 'scanner' ),
		'WPScan'              => array( 'WPScan', 'scanner' ),
		'Acunetix'            => array( 'Acunetix', 'scanner' ),
		'Nessus'              => array( 'Nessus', 'scanner' ),
		'OpenVAS'             => array( 'OpenVAS', 'scanner' ),
		'CensysInspect'       => array( 'Censys', 'scanner' ),
		'Expanse'             => array( 'Palo Alto Expanse', 'scanner' ),
		'InternetMeasurement' => array( 'InternetMeasurement', 'scanner' ),
		'l9explore'           => array( 'LeakIX l9explore', 'scanner' ),
		'ModatScanner'        => array( 'Modat Scanner', 'scanner' ),
		'Odin'                => array( 'Odin', 'scanner' ),

		// HTTP libraries and headless browsers.
		'HeadlessChrome'      => array( 'Headless Chrome', 'tool' ),
		'PhantomJS'           => array( 'PhantomJS', 'tool' ),
		'Puppeteer'           => array( 'Puppeteer', 'tool' ),
		'Playwright'          => array( 'Playwright', 'tool' ),
		'curl/'               => array( 'curl', 'tool' ),
		'Wget'                => array( 'Wget', 'tool' ),
		'python-requests'     => array( 'python-requests', 'tool' ),
		'python-urllib'       => array( 'python-urllib', 'tool' ),
		'python-httpx'        => array( 'python-httpx', 'tool' ),
		'aiohttp'             => array( 'aiohttp', 'tool' ),
		'Scrapy'              => array( 'Scrapy', 'tool' ),
		'Go-http-client'      => array( 'Go-http-client', 'tool' ),
		'okhttp'              => array( 'okhttp', 'tool' ),
		'Java/'               => array( 'Java', 'tool' ),
		'Apache-HttpClient'   => array( 'Apache HttpClient', 'tool' ),
		'axios'               => array( 'axios', 'tool' ),
		'node-fetch'          => array( 'node-fetch', 'tool' ),
		'undici'              => array( 'undici', 'tool' ),
		'libwww-perl'         => array( 'libwww-perl', 'tool' ),
		'PostmanRuntime'      => array( 'Postman', 'tool' ),
		'HTTrack'             => array( 'HTTrack', 'tool' ),
	);

	/**
	 * Filter the bot pattern list (substring => [name, type]).
	 */
	return apply_filters( 'ggsec_stats_bot_patterns', $patterns );
}

/**
 * Classify a user agent.
 *
 * @param string $ua Raw user agent.
 * @return array{0:string,1:string}|null [name, type] for bots/non-browser clients, null for browsers.
 */
function ggsec_stats_classify_ua( $ua ) {
	$ua = trim( (string) $ua );
	if ( '' === $ua ) {
		return array( 'Empty user agent', 'unknown' );
	}

	foreach ( ggsec_stats_bot_patterns() as $needle => $info ) {
		if ( false !== stripos( $ua, $needle ) ) {
			return $info;
		}
	}

	// Generic self-declared crawlers: use the token that contains the keyword as the name.
	if ( preg_match( '/[\w.\-]*(?:bot|crawler|spider|scanner|scraper)[\w.\-]*/i', $ua, $m ) ) {
		return array( substr( $m[0], 0, 64 ), 'unknown' );
	}

	// Every mainstream browser sends "Mozilla/5.0 ..."; anything else is a library or script.
	if ( 0 !== stripos( $ua, 'Mozilla/' ) ) {
		$token = strtok( $ua, ' ' );
		return array( substr( (string) $token, 0, 64 ), 'tool' );
	}

	return null;
}

/**
 * Paths that reconnaissance scanners look for (secrets, VCS metadata, backups, admin tools).
 * Plain POSIX-style alternation so the same pattern works in PHP (preg, case-insensitive) and in
 * MySQL/MariaDB REGEXP. Every entry is anchored at the start of a path segment.
 */
function ggsec_stats_sensitive_path_regex() {
	$parts = array(
		'\.git', '\.svn', '\.hg', '\.env', '\.aws', '\.ssh', '\.htpasswd', '\.htaccess', '\.ds_store', '\.idea', '\.vscode',
		'wp-config', 'config\.php', 'configuration\.php', 'settings\.php', 'web\.config', 'composer\.(json|lock)', 'package\.json',
		'debug\.log', 'error_log', 'phpinfo', 'info\.php', 'phpmyadmin', 'adminer', 'server-status', 'server-info', 'actuator',
		'vendor/', 'node_modules', 'backup', 'dump', 'id_rsa', 'cgi-bin', 'eval-stdin', 'xmlrpc\.php', 'wp-login\.php',
		// Backup/dump extensions after any file name in the segment (site.sql, index.php.bak, .index.php.swp, x.tar.gz),
		// but not longer extensions such as .sqlite or .older.
		'[^/]*\.(sql|bak|old|orig|swp|tar)($|[./])',
	);
	$regex = '(^|/)(' . implode( '|', $parts ) . ')';
	return (string) apply_filters( 'ggsec_stats_sensitive_path_regex', $regex );
}

function ggsec_stats_is_sensitive_path( $path ) {
	return (bool) preg_match( '~' . str_replace( '~', '\~', ggsec_stats_sensitive_path_regex() ) . '~i', (string) $path );
}

function ggsec_stats_bot_type_label( $type ) {
	$labels = array(
		'search'  => 'Search engine',
		'ai'      => 'AI crawler',
		'seo'     => 'SEO tool',
		'social'  => 'Social preview',
		'monitor' => 'Monitoring',
		'scanner' => 'Security scanner',
		'tool'    => 'Script / library',
		'unknown' => 'Other',
	);
	return $labels[ $type ] ?? 'Other';
}
