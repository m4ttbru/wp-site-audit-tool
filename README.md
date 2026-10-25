# WordPress Site Audit Tool

An exhaustive, read-only inventory of a WordPress site, built for scoping redesigns, rebuilds, migrations and RFQs. The report lives under **Tools > Site Audit**, and its main output is a **Markdown export written for an LLM** to read and make scoping decisions from.

**Version:** 2.0
**Author:** Matt Brubaker — [mattbru.me](https://mattbru.me)
**License:** GPL-2.0-or-later
**Requires:** WordPress 6.3+ / PHP 7.4+

---

## Getting the report

| Where | How |
|---|---|
| wp-admin | **Tools > Site Audit**, then **Copy Markdown for AI**, **.md** or **.json** |
| WP-CLI | `wp site-audit report > audit.md` |

The Markdown starts with YAML front matter and a short reading guide, lists **Key Findings** first (issues, warnings and notable scoping facts as full sentences), then gives one section per area of the site. Each section opens with a one-line description, and statuses are written out as `[ISSUE]`, `[WARN]` and `[OK]`, so nothing depends on color. Everything shown on the admin page is in the export, including collapsed lists.

### WP-CLI

```bash
wp site-audit report                                  # Markdown to STDOUT
wp site-audit report --output=audit.md                # write a file
wp site-audit report --format=json --output=audit.json
wp site-audit report --refresh --pagespeed            # clear caches and fetch PageSpeed (slow)
```

---

## Report sections

| Section | What it covers |
|---|---|
| **Key Findings** | Every issue and warning across the report, plus notable facts for scoping |
| **Site Overview** | Identity, URLs, WordPress/PHP/database versions, hosting, environment, locale, timezone, content totals |
| **Content Types** | Every post type: counts by status, public/archive/URL slug, hierarchy, the exact file that registered it; content left in unregistered post types; word counts; comments |
| **Taxonomies** | Every taxonomy: terms, hierarchy, attached post types, URL slug, registering file |
| **Pages & Hierarchy** | Page template usage (including unused and missing templates), homepage settings, and a **full page inventory**: title, path, status, template, editor, word count, modified date, in tree order |
| **Editors, Builders & Blocks** | Which editor or page builder built each item (Elementor, Beaver Builder, Divi, Bricks, Oxygen, WPBakery, block, classic), every block and shortcode in use, embedded third-party media |
| **Navigation & Layout Areas** | Menu locations, every menu with its full item tree, widget areas and their widgets |
| **Custom Fields & Data Model** | ACF field groups with location rules and storage, **every field** (label, name, type, including repeater/flexible-content sub-fields), options pages, other field frameworks, top meta keys |
| **Forms** | Every form (Gravity Forms, CF7, WPForms, Formidable): fields, stored entries, notifications, conditional logic / multi-page / uploads / payments, add-on integrations |
| **E-commerce** | WooCommerce products by type, variations, orders by status, payment gateways, shipping zones, taxes, HPOS (only if WooCommerce is active) |
| **Plugins** | Every plugin with a functional category, description, update status and auto-update setting; must-use plugins; drop-ins |
| **Theme** | Block vs. classic, parent, theme.json, supports, block templates and parts (and whether they were edited in the Site Editor), classic template files, Customizer CSS |
| **Users & Roles** | Counts per role, capabilities, custom roles, admin count, "admin" username, registration settings |
| **Media Library** | Files by MIME type, images missing alt text, unattached and orphaned media |
| **SEO & URLs** | SEO plugin, indexing, permalinks, robots.txt, sitemaps, redirect rules, homepage title/description/canonical/H1/Open Graph/schema |
| **Front-End (Homepage)** | Response time, HTML size, scripts and styles, images (lazy, dimensions, alt, WebP/AVIF), third-party services (analytics, chat, consent, fonts, maps…), tracking IDs, external hosts |
| **Performance & Hosting** | PHP limits, OPcache, compression, caching and CDN, autoloaded options (and the largest ones), transients, cron, **PageSpeed Insights & Core Web Vitals** |
| **Security** | Core version, SSL certificate, debug/config constants, publicly exposed debug.log / directory listing / REST usernames / XML-RPC, security headers, file permissions |
| **Database** | Size, orphaned and bloated data, revisions, and every non-core table with its likely owner, rows and size |
| **Scoping Summary (RFQ)** | Functionality to carry over, content volume, integrations, users to train, hosting, plus open questions for stakeholders |
| **Technical Debt & Cleanup** | Suggested cleanup tasks, unregistered (broken) shortcodes, hand-written inline styling |

---

## How it works

- **One data model, three outputs.** Collectors gather plain arrays (`includes/collectors.php`, `includes/checks.php`), section builders turn them into facts, tables and lists (`includes/sections.php`), and the HTML page, Markdown/JSON export and WP-CLI all render that same model.
- **One pass over content.** A single batched scan of published content (pages first) powers editor detection, block and shortcode usage, embeds, word counts and inline-style checks. It is cached for an hour.
- **Exact source detection.** Which file registered each post type and taxonomy is captured from the call stack at registration time, only on requests that build the report.
- **Caching.** Database checks are cached for 15 minutes, the content scan and remote checks for 1–6 hours, and PageSpeed for 24 hours. Failed remote checks are cached for 15 minutes so a timeout isn't repeated on every load. **Refresh data** clears everything.
- **Non-blocking PageSpeed.** PageSpeed loads in the background after the page renders. Exports include it once it's cached; the copy and download buttons wait for it.
- **No third-party scripts.** Chart.js is bundled (v4.4.0) and icons are WordPress Dashicons. Assets load only on the report screen, deferred.

---

## Installation

1. Upload the `wp-site-audit-tool` folder to `wp-content/plugins/`
2. Activate it in **Plugins**
3. Open **Tools > Site Audit**

No configuration required.

---

## Configuration

Filters can be added from a theme's `functions.php` or a mu-plugin:

```php
// Inline-style threshold per item (default 15).
add_filter( 'wp_audit_inline_style_limit', fn() => 25 );

// Published items read by the content scan, pages first (default 2000).
add_filter( 'wp_audit_content_scan_limit', fn() => 5000 );

// Pages listed in the page inventory (default 1000).
add_filter( 'wp_audit_page_inventory_limit', fn() => 3000 );

// Teach the non-core table check about another plugin's tables:
// key = table prefix after $wpdb->prefix, value = [ label, is the owner active? ].
add_filter( 'wp_audit_known_table_owners', function( $owners ) {
	$owners['myplugin_'] = array( 'My Plugin', class_exists( 'My_Plugin' ) );
	return $owners;
} );

// Add or adjust plugin categories (label => regex on name/description).
add_filter( 'wp_audit_plugin_categories', function( $rules ) {
	$rules['Directory'] = '/directory|listing/i';
	return $rules;
} );

// Add homepage services to detect (label => regex on the HTML).
add_filter( 'wp_audit_known_services', function( $services ) {
	$services['Acme Chat'] = '/widget\.acmechat\.com/';
	return $services;
} );

// Add, remove or reorder report sections (see the shape documented in includes/sections.php).
add_filter( 'wp_audit_report_sections', function( $sections, $ctx ) {
	return $sections;
}, 10, 2 );
```

### PageSpeed Insights API key

Without a key, Google's shared quota often returns HTTP 429. Add a free key in `wp-config.php`:

```php
define( 'WP_AUDIT_PSI_KEY', 'your-google-api-key' );
```

…or use the `wp_audit_pagespeed_api_key` filter. PageSpeed is skipped on local development sites (`.local`, `.test`, `localhost`, or `WP_ENVIRONMENT_TYPE` = `local`) because Google can't reach them.

---

## Requirements & permissions

- Requires the `manage_options` capability (administrators only)
- PageSpeed calls `googleapis.com`. The SSL check opens a socket to port 443. The homepage, robots.txt and exposure checks make unauthenticated loopback requests to the site itself.
- File permission checks are skipped on Windows servers

---

## Security

- Every page, AJAX endpoint, export and the refresh action checks `current_user_can( 'manage_options' )` and a nonce
- Output is escaped; data passed to JavaScript goes through `wp_json_encode()`
- Database queries use `$wpdb->prepare()` for parameterized values
- The report never includes passwords, keys, email addresses or usernames; exposure checks report counts only (e.g. "7 users listed publicly")
- No remote JavaScript is loaded in wp-admin
- `uninstall.php` removes the plugin's option and cached transients

---

## Changelog

### 2.0
- **Restructured** into `includes/` (core, checks, collectors, sections, renderers, admin, CLI). One data model now feeds the HTML page, Markdown, JSON and WP-CLI.
- **Markdown export rebuilt for LLMs:** generated on the server (no longer scraped from the page), with YAML front matter, a reading guide, contents, Key Findings first, section descriptions, text status markers, and every row, including collapsed lists and PageSpeed
- **New:** JSON export, **Copy Markdown for AI** button, `wp site-audit report` WP-CLI command, `uninstall.php`
- **New inventories:** full page inventory, editor/page-builder usage, block and shortcode usage, embeds, word counts, menus with every item, widget areas, ACF field groups and every field, forms with entries/features/integrations, WooCommerce, plugin categories and descriptions, MU plugins and drop-ins, block templates and parts, role capabilities, media by type and alt-text coverage, homepage front-end analysis (assets, third-party services, tracking IDs, SEO tags, schema), autoloaded options, transients, cron, redirects, comments
- **New security checks:** publicly downloadable debug.log, uploads directory listing, REST API username exposure, XML-RPC, security headers, "admin" username, administrator count, table prefix, open registration
- **Key Findings:** every section reports issues, warnings and scoping notes as sentences, summarized at the top with per-section badges in the navigation
- **Fixed:** bracketed prose such as "[Skip]" is no longer reported as a broken shortcode; HTML entities in titles; content in post types whose plugin was removed is now reported
- **UX:** snapshot cards, collapsible long tables and lists, filter boxes on long tables, keyboard-accessible sorting (`aria-sort`), status shown by icon plus text (WCAG AA colors), expand/collapse all, print view that expands everything, responsive layout, reduced-motion support
- **Changed:** `wp_audit_shortcode_post_limit` and `wp_audit_inline_style_post_limit` replaced by `wp_audit_content_scan_limit`; new `wp_audit_page_inventory_limit`

### 1.8
- **Fixed:** unregistered-shortcode detection never found anything; "Posts with Excessive Meta" always reported 0 or 1; the orphaned-table check flagged active plugins' tables; threshold filters didn't work from a theme; Formidable forms always counted as 0
- **Performance:** PageSpeed moved to AJAX; failed remote checks cached; grouped count queries; registration-time source detection; enqueued assets; Lucide CDN replaced with Dashicons; Chart.js self-hosted
- **Accuracy:** INP/TBT replace FID; PHP 8.3+ recommendation; class/constant plugin detection; host-level caching detection; live robots.txt; three-level file permissions; block-generated styles ignored
- **Added:** plugin update status, debug/config constants, role capabilities, rewrite rules, Refresh button, PageSpeed API key

### 1.65
- Fixed SSL certificate check to properly verify peer certificates
- Fixed PageSpeed API to request all four categories
- Replaced O(n^2) page nesting depth algorithm with single-query parent map
- Extracted hardcoded thresholds to filterable constants

### 1.6
- Added transient caching to source detection, input sanitization, scan timeout protection

### 1.5
- Initial release with 15-section report and markdown export
