# LLM Markdown

Expose supported public WordPress content as clean Markdown at predictable `.md` URLs.

```text
https://example.com/my-post.md
```

LLM Markdown is designed for AI and LLM ingestion, headless or hybrid workflows, and content-export pipelines. It generates Markdown from the rendered front-end page without modifying the original post or maintaining duplicate content.

[Donate to support development](https://www.paypal.com/donate/?hosted_button_id=EUHE8NXYEXJJ6)

## Requirements

| Requirement | Version |
| --- | --- |
| WordPress | 6.0 or later |
| PHP | 7.4 or later |
| Tested through | WordPress 7.0 |
| Plugin version | 1.0.2 |

## How it works

The plugin requests the canonical front-end page, selects its primary content container, removes configured elements, and converts the remaining rendered HTML to Markdown. Blocks, shortcodes, and other server-rendered output are represented after WordPress and the active theme have processed them.

The resulting document begins with YAML front matter containing:

- Title
- Post ID, type, and slug
- Publication and modification dates
- Canonical and Markdown URLs
- Excerpt
- Terms from public taxonomies

The body converter handles headings, paragraphs, emphasis, links, ordered and unordered lists, blockquotes, inline and fenced code, horizontal rules, deletion text, figures and captions, simple tables, and optional images.

## Features

- Dynamic `.md` routes for posts, pages, and enabled public post types
- YAML front matter with useful document metadata
- Extraction from fully rendered, server-side HTML
- Configurable main-content and exclusion selectors
- Optional image conversion, including common lazy-loading attributes
- Password-protection and public-visibility checks
- Optional support for Yoast SEO noindex values
- Alternate Markdown discovery links in eligible HTML pages
- Cached anonymous responses with automatic and manual invalidation
- Advanced, opt-in HTTP content negotiation
- Extension points for access control, metadata, taxonomies, output, and caching

No Gutenberg lock-in. No content duplication. No custom post type is required.

## Installation

1. Upload the plugin directory to `/wp-content/plugins/`.
2. Activate **LLM Markdown** in WordPress.
3. Open **Settings → Markdown Output**.
4. Select the content types that should expose Markdown.
5. Append `.md` to a supported permalink.

For a static front page, its normal permalink can use `.md`, and `/index.md` is also available as an alias.

## Configuration

The settings screen is divided into four tabs:

| Tab | Controls |
| --- | --- |
| Options | Content types, noindex handling, images, and response cleanup |
| DOM & Selectors | Main content selectors and excluded content selectors |
| Advanced | Header-based Markdown and wildcard Accept-header matching |
| Tools | Manual Markdown cache invalidation |

### Content selectors

The selector fields support a practical CSS subset:

- Element names, IDs, and classes
- Combined selectors such as `article.entry-content`
- Descendant selectors
- Direct-child selectors
- Comma- or newline-separated alternatives

Attribute selectors, pseudo-classes, and sibling combinators are not supported.

### Header-based Markdown

Header-based Markdown is an advanced, disabled-by-default option. When enabled, eligible canonical URLs can return Markdown for `GET` and `HEAD` requests that select `text/markdown` through the `Accept` header.

```bash
curl -i -H "Accept: text/html" https://example.com/my-post/
curl -i -H "Accept: text/markdown" https://example.com/my-post/
```

Both eligible HTML and Markdown responses include `Vary: Accept`. Conservative matching requires an explicit `text/markdown` media range. The separate wildcard option can also permit `text/*`; `*/*` alone never selects Markdown.

> [!WARNING]
> Content negotiation depends on every CDN, reverse proxy, server cache, and page-cache layer correctly honoring `Vary: Accept`. A misconfigured cache can serve Markdown to browsers or HTML to Markdown clients. Negotiated responses also bypass browser-side JavaScript, analytics, advertising, consent tools, and personalization. Clear every cache and test both representations before enabling this in production.

## Caching

Documents generated for anonymous requests are cached for 12 hours by default. Cache generations change automatically after:

- Plugin settings are saved
- The active theme is switched
- A navigation menu is updated

Use **Tools → Clear Markdown Cache** after changing templates, widgets, shortcodes, or other site-wide rendered content.

Developers can use `llm_markdown_cache_ttl` to change the duration or return `0` to disable document caching.

## Frequently asked questions

### Does this create physical Markdown files?

No. The `.md` URLs are WordPress rewrite routes generated on demand. Cached documents are temporary; the plugin does not create or synchronize a parallel file tree.

### Does it modify WordPress content?

No. Posts, blocks, templates, and database content are not rewritten.

### Does it support custom post types?

Yes. Any public post type can be enabled. Attachments are excluded. Leaving every content type unchecked disables Markdown output entirely.

### Does it expose private content?

The plugin serves only publicly viewable content from enabled public post types. Password-protected content is rejected. **Honor Noindex** can additionally reject posts marked noindex by Yoast SEO, and integrations can impose further restrictions through `llm_markdown_can_serve_post`.

### How does the active theme affect Markdown?

Markdown is built from the server-rendered canonical page, so blocks, shortcodes, and template output can be included. Content inserted later by browser-side JavaScript is unavailable. Adjust the main and excluded selectors if a theme includes navigation, banners, related content, or other unwanted elements around the article.

### Can images be included?

Yes. Enable **Images in Markdown** under Options. Images inside the selected content area use their source, alt text, and title; common lazy-loading attributes are recognized. Images are excluded by default.

### Why does unexpected output appear before Markdown?

Some themes, plugins, or hosting layers emit HTML, notices, or other output before the Markdown document. Enable **Response Cleanup** under Options to remove that pre-output. This compatibility option is disabled by default.

### What happens when rendering fails?

If the plugin cannot retrieve or extract the rendered page, it returns a non-cacheable `503 Markdown temporarily unavailable` response instead of caching incomplete output. Unsupported, disabled, or protected `.md` routes return `404 Not Found`.

### Does this guarantee AI or search-engine indexing?

No. The plugin provides and advertises a predictable alternate representation, but individual crawlers and search engines decide whether to discover, index, or use it. The Markdown response identifies the HTML URL as canonical.

## Developer filters

| Filter | Purpose |
| --- | --- |
| `llm_markdown_can_serve_post` | Apply membership, privacy, or custom access rules |
| `llm_markdown_is_noindex_post` | Integrate additional noindex providers |
| `llm_markdown_include_taxonomy` | Include or exclude public taxonomy data |
| `llm_markdown_front_matter` | Modify YAML front matter |
| `llm_markdown_markdown_document` | Filter the completed Markdown document |
| `llm_markdown_cache_ttl` | Change or disable document caching |

## Removal

Deactivation preserves settings. Uninstalling or deleting the plugin removes its settings, cache-generation state, and generated Markdown transients. On multisite, cleanup runs for every site in the network.

## Changelog

### 1.0.2

- Added advanced, opt-in Markdown content negotiation for normal singular URLs using `Accept: text/markdown`.
- Added quality-aware Accept-header matching, optional `text/*` support, `GET`/`HEAD` restrictions, and `Vary: Accept` signaling.
- Added a disabled-by-default Response Cleanup option for unexpected early output.
- Reorganized settings into Options, DOM & Selectors, Advanced, and Tools tabs.
- Added manual cache invalidation and automatic generation changes after settings, theme, and navigation updates.
- Added the `llm_markdown_cache_ttl` filter and more complete cache keys.
- Changed failed or empty loopback renders to return a non-cacheable 503 response.
- Added `llm_markdown_can_serve_post` for access-control integrations.
- Restricted front-matter taxonomy data to public taxonomies and added `llm_markdown_include_taxonomy`.
- Allowed an empty content-type selection to disable Markdown output.
- Fixed HTML entity decoding and code delimiters when source code contains backticks.
- Added horizontal rules, deletion text, figures, and captions, and made simple tables safer.
- Expanded uninstall cleanup across single-site and multisite installations.

### 1.0.1

- Added support for tables.
- Added optional support for images.

### 1.0.0

- Initial release.

## License

LLM Markdown is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
