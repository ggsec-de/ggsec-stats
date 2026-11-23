=== GGSEC Stats ===
Contributors: patapik
Tags: analytics, statistics, privacy, bots, security
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Privacy-first page view statistics that separate real visitors from bots. No cookies, no external services, no raw IPs.

== Description ==

GGSEC Stats counts page views on your own server and tells you how much of your traffic comes from real people and how much from bots, AI crawlers and vulnerability scanners.

Every request is recorded on the server, so bots and clients without JavaScript are seen too. A page view is counted as human only after a small browser beacon confirms that the page was actually shown in a visible tab. Everything else is reported as bot traffic.

= Features =

* **Humans vs. bots** - human page views, unique visitors, known bots, browser-like clients that never ran JavaScript, bot share and visitors online now.
* **30-day chart** of humans and bots, rendered as inline SVG (no JavaScript chart library).
* **Top pages** and **referrers** (source sites, full referring URLs without query strings, `utm_source` / `ref` campaign tags).
* **Bot breakdown** by name and type: search engines, AI crawlers, SEO tools, social previews, monitoring services, security scanners and HTTP libraries.
* **Pages read by AI crawlers** (GPTBot, ClaudeBot, PerplexityBot and others).
* **Security reconnaissance** - failed logins, bot scans of the login page, XML-RPC requests, 404 requests from non-humans and requests for sensitive paths such as `.env`, `.git` or backup archives.
* **Broken links** - 404 pages opened by real visitors, with the referring site.
* **File downloads** - clicks by real visitors on links to PDF, ZIP and other files on your site.
* **Dashboard widget** with today's numbers.
* **Flood guard** - aggressive scanners cannot fill your database.

= Privacy =

* No cookies, no localStorage or sessionStorage, no fingerprinting scripts.
* No external services: all data stays in your WordPress database.
* Raw IP addresses are never stored. A visitor is counted with a truncated HMAC-SHA256 of IP address, user agent and date, keyed with a random salt that is replaced every day. The old salt is deleted, so visitors cannot be recognised across days and the hash cannot be reversed.
* Query strings are removed from recorded paths and referrers.
* The user agent of a confirmed human visit is erased; it is kept only for bots and unconfirmed requests.
* Raw data is deleted automatically after 90 days.
* Logged-in editors and administrators are not counted.

The plugin adds a suggested text to the privacy policy guide (Settings > Privacy).

== Installation ==

1. Install the plugin from the Plugins screen, or upload the `ggsec-stats` folder to `/wp-content/plugins/`.
2. Activate **GGSEC Stats** on the Plugins screen.
3. Open **GGSEC Stats** in the admin menu.

Optional settings in `wp-config.php`:

`define( 'GGSEC_STATS_RETENTION_DAYS', 90 );` - days raw hits are kept (default 90).

`define( 'GGSEC_STATS_DAILY_CLIENT_CAP', 500 );` - maximum recorded requests per client per day (default 500).

== Frequently Asked Questions ==

= Does it need a cookie banner? =

The plugin does not set cookies and does not store data in the browser. Whether you need a consent banner depends on your whole site and your jurisdiction, so check with your own legal advice.

= Does it work with page caching? =

Hits are recorded when WordPress renders the page. If a full-page cache serves a page without running WordPress, that view is not recorded.

= Why are my own visits not counted? =

Logged-in users who can edit posts are excluded so the site owner and editors do not distort the numbers.

= Why do bot numbers include browser user agents? =

Many scanners pretend to be a browser. A request is counted as human only when the browser confirms that the page was shown; requests that never confirm are listed as "Bots without JS".

= What happens on uninstall? =

Deleting the plugin from the Plugins screen drops its database table and removes its options and scheduled event.

== Changelog ==

= 1.3.0 =
* Prepared for WordPress.org: readme.txt, GPLv2 license file, translatable admin strings, privacy policy text.
* Hardened output escaping and request input sanitization.
* Database queries use identifier placeholders.

= 1.2.1 =
* Sensitive path detection also matches names such as `site.sql.bak`.
* Security recon card counts a request that is both a scanner hit and a sensitive path only once.

= 1.2.0 =
* File download tracking, AI crawler pages, security recon summary, broken links.

= 1.1.0 =
* Flood guard per client and day; own logins are no longer counted as probes.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.3.0 =
Translation-ready admin screens and hardening for the WordPress.org directory.
