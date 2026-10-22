# WordPress Site Audit Tool

A comprehensive diagnostic and scoping plugin for WordPress. Designed for developers, agencies, and site administrators who need to analyze a WordPress installation for redesign projects, audits, or RFQ preparation.

**Version:** 1.8
**Author:** Matt Brubaker — [mattbru.me](https://mattbru.me)
**License:** GPL-2.0-or-later
**Requires:** WordPress 6.3+ / PHP 7.4+

---
 
## What It Does

Drop it into any WordPress site and get a detailed, 16-section report covering content structure, technical infrastructure, performance metrics, and technical debt — all from a single admin page under **Tools > Site Audit**.

The report is designed to give you (or a client) a clear snapshot of what a site contains, how healthy it is, and what needs attention before a redesign or migration.

---

## Report Sections

| # | Section | What It Covers |
|---|---------|----------------|
| 1 | **Post Types & Content Structure** | All registered post types with counts by status and source (core / plugin / mu-plugin / theme / custom) |
| 2 | **Taxonomies & Classification** | Registered taxonomies, term counts, source |
| 3 | **Pages & Hierarchy** | Published/draft page counts, max nesting depth, template usage (including unused templates and pages assigned to templates missing from the theme) |
| 4 | **Forms & Form Plugins** | Gravity Forms, Contact Form 7, WPForms and Formidable form counts (active vs. inactive) |
| 5 | **Database & Content Quality** | Orphaned postmeta and term relationships, posts with excessive meta, database size, table count |
| 6 | **Custom Post Meta Analysis** | Top 50 public postmeta keys by usage |
| 7 | **Plugin & Dependency Audit** | Active/inactive plugins with update status from WordPress's own update check |
| 8 | **Theme & Front-End** | Active theme, version, parent/child relationship, block vs. classic theme |
| 9 | **User Roles & Permissions** | User counts per role, capability counts, default vs. custom roles |
| 10 | **Media & Assets** | Attachment and image counts, media attached to deleted posts |
| 11 | **URL Structure & Permalinks** | Home/site URL, permalink structure, category/tag bases, rewrite rule count |
| 12 | **Security Audit** | Core version vs. latest, SSL certificate, file permissions, debug/config constants (`WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG`, `DISALLOW_FILE_EDIT`) |
| 13 | **Performance & Technical** | PHP/memory/upload limits, caching detection (plugins, page-cache drop-ins, object cache), PageSpeed Insights scores and Core Web Vitals (LCP, INP, CLS, plus TBT, FCP, TTFB) |
| 14 | **SEO & Metadata** | SEO plugin detection, search engine visibility, live robots.txt check (whole-site blocks, Sitemap directives), posts with missing titles |
| 15 | **RFQ Requirements Summary** | Consolidated data points for project estimation — content volume, features, integrations, hosting |
| 16 | **Cleanup & Technical Debt** | Non-core tables with their likely owner, unregistered shortcodes, hand-written inline styling |

---

## Features

- **Sticky sidebar navigation** — jump to any section without scrolling
- **Exact source detection** — records which file registered each post type and taxonomy at registration time (no filesystem scanning)
- **Color-coded status indicators** — green/yellow/red flags for security, performance, and health checks
- **Markdown export** — one-click download of the full report as a `.md` file named after the site
- **Caching with one-click refresh** — database checks are cached for 15 minutes, remote checks for 1–6 hours, and PageSpeed for 24 hours. Failed remote checks are cached for 15 minutes so a timeout isn't repeated on every load. **Refresh Cached Data** re-runs everything.
- **Non-blocking PageSpeed** — PageSpeed Insights loads over AJAX after the report renders, with desktop and mobile fetched in parallel
- **No third-party scripts** — Chart.js is bundled (pinned v4.4.0) and icons use WordPress's built-in Dashicons. Assets load only on the report screen.

---

## Installation

1. Upload the `wp-site-audit-tool` folder to `wp-content/plugins/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Navigate to **Tools > Site Audit** in the WordPress admin

No configuration required. The report generates on page load.

---

## Configuration

Thresholds are exposed as WordPress filters, so you can override them from your theme's `functions.php` or a mu-plugin without editing the plugin itself:

```php
// Change inline style threshold to 25 (default: 15)
add_filter( 'wp_audit_inline_style_limit', function() { return 25; } );

// Change max posts checked for shortcodes (default: 100)
add_filter( 'wp_audit_shortcode_post_limit', function() { return 200; } );

// Change max posts checked for inline styles (default: 100)
add_filter( 'wp_audit_inline_style_post_limit', function() { return 200; } );

// Teach the orphaned-table check about another plugin's tables.
// Key is the table prefix after $wpdb->prefix; value is [ label, is the owner active? ].
add_filter( 'wp_audit_known_table_owners', function( $owners ) {
	$owners['myplugin_'] = array( 'My Plugin', class_exists( 'My_Plugin' ) );
	return $owners;
} );
```

### PageSpeed Insights API key

Without a key, Google's shared quota often returns HTTP 429. Add a free API key in `wp-config.php`:

```php
define( 'WP_AUDIT_PSI_KEY', 'your-google-api-key' );
```

…or supply it with the `wp_audit_pagespeed_api_key` filter. PageSpeed is skipped on local development sites (`.local`, `.test`, `localhost`, or `WP_ENVIRONMENT_TYPE` = `local`) because Google can't reach them.

---

## Requirements & Permissions

- Requires the `manage_options` capability (Administrators only)
- PageSpeed Insights calls `googleapis.com`; an API key is recommended (see above)
- The SSL certificate check needs outbound socket connections on port 443, and the robots.txt check makes a loopback request to the site
- File permission checks are skipped on Windows servers

---

## Security

- All admin pages, AJAX endpoints and the cache-refresh action check `current_user_can( 'manage_options' )`
- AJAX and the refresh action are protected with nonces
- Output is escaped with `esc_html()`, `esc_url()` and `esc_attr()`; data passed to JavaScript goes through `wp_json_encode()`
- Database queries use `$wpdb->prepare()` for any parameterized values
- No remote JavaScript is loaded in wp-admin

---

## Changelog

### 1.8
- **Fixed:** unregistered-shortcode detection never found anything (`get_shortcode_regex()` only matches registered tags). It now also reports posts affected and occurrences separately.
- **Fixed:** "Posts with Excessive Meta" always reported 0 or 1, which also kept it out of the database health score
- **Fixed:** the orphaned-table check told users to delete tables belonging to active plugins, and flagged multisite subsite tables. Tables are now matched to known owners and show an In use / Owner not active / Unknown status.
- **Fixed:** threshold filters had no effect when added from a theme (they were read before the theme loaded)
- **Fixed:** Formidable Forms were always counted as 0 (Formidable uses its own table, not a post type)
- **Fixed:** "query limited" notes never appeared, literal `**` markdown showed in the HTML, a `wpdb::prepare()` notice, and export button icon removal
- **Performance:** PageSpeed moved to AJAX; failed remote checks are cached; post/term counts collapsed into single grouped queries; duplicate uncached queries removed; WordPress version taken from core's update transient
- **Performance:** source detection replaced a per-post-type scan of every plugin file with registration-time capture
- **Performance:** CSS/JS moved to enqueued files on the report screen only; Lucide CDN replaced with Dashicons; Chart.js self-hosted
- **Accuracy:** FID replaced with INP (field data) and TBT; PHP recommendation updated to 8.3+; plugin detection uses classes/constants instead of name keywords ("map" no longer matches "Sitemaps"); caching detection covers host-level page caches and object caches; robots.txt checked live; file permissions report good/review/insecure and find `wp-config.php` one level up; inline-style check ignores block-generated styles; unused and missing page templates are listed
- **Added:** plugin update status, debug/config constants, role capability counts, rewrite rule count, Refresh Cached Data button, PageSpeed API key support

### 1.65
- Fixed SSL certificate check to properly verify peer certificates
- Fixed PageSpeed API to request all four categories (performance, accessibility, SEO, best practices)
- Replaced O(n^2) page nesting depth algorithm with single-query parent map
- Added error logging for file scan exceptions
- Extracted hardcoded thresholds to filterable constants
- Removed bundled legacy v1.5 code

### 1.6
- Added transient caching to source detection
- Added input sanitization to AJAX handler
- Added scan timeout protection with partial results

### 1.5
- Initial release with 15-section report and markdown export
