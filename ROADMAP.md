# WP Site Audit Tool — Roadmap & Improvement Ideas

Suggestions for extending the plugin and improving usability, grouped by impact.

---

## High Impact — Core Workflow

**Async section loading**
The page blocks on PageSpeed API (~3–5s) and DB-heavy sections before rendering anything. Load each section independently via AJAX after page paint — show a spinner per section, populate as results arrive. Makes the tool feel instant.

**Snapshot history**
Store each audit run in `wp_options` (or a custom table) with a timestamp. Keep the last 5 snapshots so you can say "since the last audit, 3 new plugins were added and page count grew from 48 to 61." Invaluable for returning clients.

**Per-section cache refresh button**
Right now the only way to bust transient cache is manually via the DB. A small "Refresh" icon next to each section heading that fires an AJAX cache-bust + re-render would make re-running specific checks trivial.

---

## High Impact — New Audit Sections

**Plugin vulnerability check**
Hit the WPScan API (free tier: 25 req/day) or Patchstack with each installed plugin's slug + version. Flag known CVEs in the plugin table. The most actionable security finding you can surface for a client.

**Media library audit**
- Unattached images (uploaded but never inserted into content)
- Images over a configurable size threshold (e.g. > 1MB)
- Images missing alt text
- Total library size estimate

**User & role audit**
- Count of users per role
- Number of Administrator accounts (flag if > 2–3)
- Accounts that have never logged in
- Accounts inactive > 6 months (via `user_registered` + last-login meta if tracked)

---

## Medium Impact — Polish & Usability

**Consistent "Fix" links on every finding**
Every flagged item should link directly to where it's resolved. Examples:
- Outdated plugin → `plugins.php` filtered to that plugin
- Page with excessive inline styles → `post.php?post=ID&action=edit`
- Draft pages → `edit.php?post_type=page&post_status=draft`
- Orphaned table → note about the responsible plugin

Already done in some sections — make it consistent everywhere.

**Export to printable HTML / PDF**
The markdown export is useful for developers. A "Print / Save as PDF" view (`window.print()` with printer-friendly CSS, no sidebar) would let you hand a polished report directly to a client or stakeholder.

**Per-section auditor notes**
A collapsible textarea under each section (saved to `user_meta` or `wp_options`) for findings, recommendations, or scope notes. Annotate as you review, then export with notes attached. Very useful when building an RFQ.

**Search/filter within large tables**
The plugin list and CPT/taxonomy tables can get long. A simple `<input type="search">` with a JS row-filter (no server round-trip) would make scanning large sites much less painful.

---

## Lower Effort, Good ROI

**WooCommerce / popular plugin context sections**
If WooCommerce is active, add: product count, variation count, order count, active payment gateways. Same for ACF (field group count, location rules) and Gravity Forms (form count, entry count). High-value line items for scoping.

**Robots.txt and sitemap validator**
Fetch `/robots.txt` and check for a `Sitemap:` directive. Verify the sitemap URL returns 200. Flag if `Disallow: /` is present (accidentally blocking search engines is surprisingly common). Two `wp_remote_get()` calls.

**`wp-config.php` hardening checks**
Beyond file permissions, check for: `WP_DEBUG` left on, `DISALLOW_FILE_EDIT` not set, `WP_DEBUG_LOG` writing to a public path. All readable as PHP constants — no file parsing needed.

**Transient bloat detector**
Query `wp_options` for rows where `option_name LIKE '_transient_%'` and report the count. Sites with thousands of stale transients (common with badly behaved plugins) show this as a DB bloat and performance source.

---

## Architecture — File Splitting

The plugin is currently one ~4,100-line file. As it grows, consider splitting into section files loaded via `require_once`:

```
wp-site-audit-tool/
├── wp-site-audit-tool.php        ← bootstrap, hooks, constants
├── includes/
│   ├── functions-core.php        ← detect_origin, version check, SSL
│   ├── functions-pagespeed.php
│   └── functions-db.php          ← orphaned data, transient bloat, etc.
└── sections/
    ├── section-content.php       ← pages, CPTs, taxonomies
    ├── section-plugins.php
    ├── section-media.php
    ├── section-users.php
    └── section-security.php
```

Each section file `echo`s its HTML and reads pre-fetched data passed in. Easy to add/remove sections and easy to locate things when debugging.
