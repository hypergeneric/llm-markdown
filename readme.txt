=== LLM Markdown – Expose Content as .md ===

Contributors: michaelsablone
Tags: markdown, llm, ai, seo
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose public WordPress content as clean .md routes with YAML front matter, optimized for LLMs, AI crawlers, and headless workflows.

== Description ==

LLM Markdown exposes your public WordPress posts and pages as real `.md` routes.

Each Markdown document includes structured YAML front matter and clean content extracted from the rendered HTML.

Designed for:

- LLM and AI ingestion
- Headless and hybrid workflows
- Content export pipelines
- SEO-friendly alternate representations

Example:

`https://example.com/my-post.md`

Features:

- Real `.md` URLs
- YAML front matter (title, dates, taxonomy, URL)
- Selector-based content extraction
- Respects password protection
- Optional respect for noindex
- Per-post-type control
- Caching for performance
- Adds `<link rel="alternate" type="text/markdown">`

No Gutenberg lock-in. No content duplication. No custom post types required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin
3. Visit Settings → Markdown Output to configure options

After activation, append `.md` to supported post URLs.

== Frequently Asked Questions ==

= Does this modify my content? =

No. The plugin generates Markdown dynamically from rendered HTML.

= Does it support custom post types? =

Yes. Any public post type can be enabled in settings.

= Does it expose private content? =

No. Password-protected and non-public content are excluded.

= Is this intended for SEO? =

It provides an alternate Markdown representation. Search engine behavior may vary.

== Changelog ==

= 1.0.0 =
* Initial release.
