# WordPress Site Audit Tool

A comprehensive diagnostic and scoping plugin for WordPress. Designed for developers, agencies, and site administrators who need to analyze a WordPress installation for redesign projects, audits, or RFQ preparation.

**Version:** 1.65
**Author:** Matt Brubaker — [mattbru.me](https://mattbru.me)
**License:** GPL-2.0-or-later
**Requires:** WordPress 5.0+ / PHP 7.4+

---
 
## What It Does

Drop it into any WordPress site and get a detailed, 16-section report covering content structure, technical infrastructure, performance metrics, and technical debt — all from a single admin page under **Tools > Site Audit**.

The report is designed to give you (or a client) a clear snapshot of what a site contains, how healthy it is, and what needs attention before a redesign or migration.

---

## Report Sections

| # | Section | What It Covers |
|---|---------|----------------|
| 1 | **Post Types & Content Structure** | All registered post types with content counts and source detection (core / plugin / theme / custom) |
| 2 | **Taxonomies & Classification** | Registered taxonomies, term counts, source detection |
| 3 | **Pages & Hierarchy** | Published/draft page counts, max nesting depth, page template usage |
| 4 | **Forms & Form Plugins** | Detected form plugins (Gravity Forms, CF7, WPForms, etc.) and form counts |
| 5 | **Database & Content Quality** | Orphaned tables, table sizes, revision counts, auto-draft cleanup |
| 6 | **Custom Post Meta Analysis** | Post meta key analysis, excessive meta detection |
| 7 | **Plugin & Dependency Audit** | Active/inactive plugin list, update status, last-updated dates |
| 8 | **Theme & Front-End** | Active theme info, parent/child theme detection, template files |
| 9 | **User Roles & Permissions** | User counts by role, capability overview |
| 10 | **Media & Assets** | Attachment counts, upload directory size |
| 11 | **URL Structure & Permalinks** | Current permalink structure, rewrite rules |
| 12 | **Security Audit** | SSL certificate status, file permissions, WordPress version check, debug mode |
| 13 | **Performance & Technical** | Google PageSpeed Insights scores (Performance, Accessibility, SEO, Best Practices), Core Web Vitals (LCP, FID, CLS, FCP, TTFB), enqueued styles/scripts count |
| 14 | **SEO & Metadata** | SEO plugin detection, meta description/title checks |
| 15 | **RFQ Requirements Summary** | Consolidated data points for project estimation — content volume, technical complexity, plugin dependencies |
| 16 | **Cleanup & Technical Debt** | Unregistered shortcodes, excessive inline styling, orphaned data |

---

## Features

- **Sticky sidebar navigation** — jump to any section without scrolling
- **Source detection** — traces every custom post type and taxonomy back to the plugin, theme, or custom code that registered it
- **Color-coded status indicators** — green/yellow/red flags for security, performance, and health checks
- **Markdown export** — one-click download of the full report as a `.md` file with automatic site-name-based filename
- **Transient caching** — heavy operations (PageSpeed API, file scanning, DB queries) are cached to avoid redundant work on repeat visits
- **AJAX-based CPT/taxonomy scanning** — source detection runs asynchronously with a progress bar and timeout protection

---

## Installation

1. Upload the `wp-site-audit-tool` folder to `wp-content/plugins/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Navigate to **Tools > Site Audit** in the WordPress admin

No configuration required. The report generates on page load.

---

## Configuration

All thresholds are exposed as WordPress filters so you can override them from your theme's `functions.php` or a mu-plugin without editing the plugin itself:

```php
// Increase scan timeout to 120 seconds (default: 60)
add_filter( 'wp_audit_scan_timeout', function() { return 120; } );

// Increase max file size for scanning to 1MB (default: 500KB)
add_filter( 'wp_audit_max_file_size', function() { return 1024 * 1024; } );

// Change inline style threshold to 25 (default: 15)
add_filter( 'wp_audit_inline_style_limit', function() { return 25; } );

// Change max posts checked for shortcodes (default: 100)
add_filter( 'wp_audit_shortcode_post_limit', function() { return 200; } );

// Change max posts checked for inline styles (default: 100)
add_filter( 'wp_audit_inline_style_post_limit', function() { return 200; } );
```

---

## Requirements & Permissions

- Requires the `manage_options` capability (Administrators only)
- Google PageSpeed Insights uses the free public API (no key required, but subject to rate limits — 25 requests/day)
- SSL certificate checks require outbound socket connections on port 443

---

## Security

- All admin pages check `current_user_can( 'manage_options' )` before rendering
- AJAX endpoints are protected with nonce verification (`check_ajax_referer`)
- All user input is sanitized with `sanitize_text_field()`
- Output is escaped with `esc_html()`, `esc_url()`, `esc_attr()`, and `esc_js()` as appropriate
- Database queries use `$wpdb->prepare()` for any parameterized values

---

## Changelog

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
