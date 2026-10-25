# WP Site Audit Tool — Roadmap

What's left after 2.0. The goal stays the same: an exhaustive inventory whose Markdown export gives an LLM everything it needs to make scoping and redesign decisions.

Done in 1.8–2.0: async PageSpeed, cache refresh, media alt-text audit, user/role audit, edit links in inventories, WooCommerce/ACF/Gravity Forms details, robots.txt and sitemap checks, wp-config hardening checks, transient bloat, file splitting.

---

## High impact

**Auditor notes in the export**
A notes field per section (saved to an option) that is included in the Markdown under that section. Lets a human add context the site can't reveal ("client wants to drop the events calendar") before handing the report to an LLM.

**Snapshot history**
Store a compact JSON snapshot per run and add a "Changes since last audit" section (plugins added/removed, content growth, findings resolved).

**Multi-page front-end analysis**
The front-end section only analyzes the homepage. Sample one URL per template/post type (a listing, a post, an archive) for asset weight, third-party services and SEO tags.

**Plugin vulnerability check**
Match installed plugin versions against a vulnerability database (WPScan or Patchstack). Needs an API key and a decision on which third-party service to call.

---

## Medium impact

- **Uploads folder size and largest files.** Compute lazily over AJAX (like PageSpeed) since a recursive scan can be slow on large libraries.
- **Internal broken links.** Check internal links found by the content scan against the page inventory paths.
- **Per-section export.** Copy a single section's Markdown for a focused LLM prompt.
- **Gutenberg reusable blocks / patterns inventory** (`wp_block` posts and registered patterns).
- **Multisite network view** listing every site with headline counts.

---

## Lower effort

- Detect more hosts (Anchor, SiteGround, Cloudways) from headers and environment.
- Detect more page builders (Brizy, Thrive Architect, Breakdance) by post meta.
- Add the active theme's `theme.json` palette and font families to the Theme section (useful design-system context for a redesign).
