# GGSEC Stats

Privacy-first page view and security telemetry plugin for WordPress by **GG Advanced IT Security UG**.

No cookies, no browser storage, no external services, and no raw IP addresses stored in the database.

## Features

- **Real Humans vs. Bots (Hybrid Verification):**
  - Records requests server-side on `template_redirect` to capture bots, crawlers, and non-JS clients.
  - Upgrades a page view to **Human** only after a lightweight browser beacon (`navigator.sendBeacon`) confirms the page was actually rendered in a visible, non-prerendered tab (`document.visibilityState === 'visible'` and `!document.prerendering`).
  - Once a browser confirms the view, the stored `User-Agent` string is erased (`ua = NULL`).
- **Privacy by Design (GDPR / DSGVO Friendly):**
  - No cookies or `localStorage` / `sessionStorage`.
  - Raw IP addresses are never written to disk. Visitors are counted using a 64-bit truncated HMAC (`SHA-256`) of `IP | User-Agent | Date`, keyed with a 256-bit random salt (`random_bytes(32)`) that rotates daily. When the salt rotates, the previous salt is overwritten so historical hashes cannot be reversed or correlated across days.
  - Query strings are stripped from recorded page paths and external referrers (except optional `utm_source` / `ref` campaign tags).
- **Bot & AI Crawler Breakdown:**
  - Classifies search engines, AI crawlers (`GPTBot`, `ClaudeBot`, `PerplexityBot`, `Applebot-Extended`, etc.), SEO tools, social previews, monitoring services, security scanners (`Nuclei`, `WPScan`, `sqlmap`, `Nikto`, `Censys`, etc.), and HTTP libraries.
  - Dedicated **Pages read by AI crawlers** table showing which content is being fetched by AI models.
  - Separate counter and User-Agent breakdown for browser-like clients that never execute JavaScript.
- **Security Reconnaissance & Broken Links:**
  - Tracks failed login attempts (`wp_login_failed`), bot login discovery scans (`wp-login.php`), and `xmlrpc.php` probes.
  - Distinguishes **Broken links** (404 pages reached by confirmed human visitors, including the referring host) from **Suspicious requests** (non-human 404s and login/XML-RPC probes).
  - Highlights reconnaissance probes targeting sensitive paths (`.env`, `.git`, `wp-config`, SQL dumps, backup archives, etc.).
- **File Download Tracking:**
  - Counts clicks (including middle-click) by confirmed human visitors on same-site links to downloadable files (`pdf`, `zip`, `7z`, `tar`, `gz`, `bin`, `asc`, `gpg`, etc.), authenticated with the page view's HMAC token.
- **Built-in Flood Guard:**
  - Caps recorded hits per client hash per day (`GGSEC_STATS_DAILY_CLIENT_CAP`, default `500`) using a bounded covering-index lookup so aggressive vulnerability scanners cannot bloat the database.
- **Zero Admin Dependencies:**
  - Renders the 30-day stacked bar chart (humans vs. bots) as inline SVG in PHP without external JavaScript charting libraries.
  - Includes a compact WordPress Dashboard widget.

## Requirements

- WordPress 6.3+
- PHP 7.4+

## Installation

1. Copy the `ggsec-stats` directory into `wp-content/plugins/`.
2. Activate **GGSEC Stats** in the WordPress admin panel (**Plugins**).
3. Open **GGSEC Stats** in the admin sidebar to view statistics.

## Optional Configuration (`wp-config.php`)

```php
// Number of days raw hit rows are kept before daily batched cleanup (default: 90).
define( 'GGSEC_STATS_RETENTION_DAYS', 90 );

// Maximum recorded requests per visitor hash per day to guard against scanner floods (default: 500).
define( 'GGSEC_STATS_DAILY_CLIENT_CAP', 500 );
```

## Uninstall

When deleted through the WordPress Plugins screen, `uninstall.php` drops the `{$wpdb->prefix}ggsec_stats_hits` table, removes plugin options (`ggsec_stats_db_version`, `ggsec_stats_salt`, `ggsec_stats_salt_day`), and clears the daily cleanup cron hook.

## License

GPL-2.0+ — Copyright (c) GG Advanced IT Security UG (haftungsbeschränkt)
