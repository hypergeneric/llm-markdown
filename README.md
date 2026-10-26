# LLM Markdown – Markdown Endpoints for AI Agents

WordPress plugin · Version 1.1.1 · WordPress 6.0+ · PHP 7.4+ · GPLv2 or later

Serve clean Markdown versions of WordPress posts, pages, and custom post types for AI agents, LLM tools, and content pipelines.

## Description

LLM Markdown makes your public WordPress content available as clean, structured Markdown at predictable `.md` URLs. See it on a live site:

* Static front page: [https://compiledrogue.com/index.md](https://compiledrogue.com/index.md)
* Public custom post type: [https://compiledrogue.com/case-studies/sentinelone.md](https://compiledrogue.com/case-studies/sentinelone.md)

For regular supported posts, pages, and custom post types, the `.md` URL follows the permalink. Static front pages also have an `/index.md` alias.

LLM Markdown converts the actual server-rendered page after WordPress, blocks, shortcodes, and the active theme have processed it. You can choose the content container and exclude unwanted page elements. The original post remains unchanged. Markdown is generated on demand and cached temporarily; no API keys, external service, or static file management are required.

### Preview and control from the editor

The block editor sidebar checks Markdown availability and shows the last public response, word count, approximate token count, and document size. Both the block and Classic Editors provide an in-editor View Markdown panel, Copy Markdown action, and link to the public Markdown URL. Editors can disable Markdown for an individual post without changing its normal HTML page. Editor tools can be turned off in the plugin settings.

### Structured Markdown output

Each document begins with YAML front matter containing the title, post ID, content type, slug, publication and modification dates, canonical URL, Markdown URL, excerpt, and terms from public taxonomies when available.

The converter handles headings, paragraphs, emphasis, links, ordered and unordered lists, blockquotes, inline and fenced code, horizontal rules, deletion text, figures and captions, and simple tables. Images can be included as an option, with support for common lazy-loading source attributes.

### Content selection and access control

Choose which public post types expose Markdown. Simple CSS selectors determine the main content container and which elements are excluded before conversion. A test on the DOM & Selectors tab shows which selector matched and previews the resulting Markdown. Password-protected, unpublished, and individually disabled content is rejected. The optional Honor Noindex setting respects WordPress search visibility and supported noindex settings from Yoast SEO, Rank Math, and All in One SEO. Developers can apply an additional access-control filter for membership or privacy rules.

### Discovery, delivery, and caching

Eligible HTML pages advertise their Markdown counterpart with both a `<link rel="alternate" type="text/markdown">` element and an HTTP `Link` header. Documents generated for anonymous requests are cached for performance and invalidated after relevant settings, theme, navigation, or per-post availability changes. A manual cache control is available under Tools.

An advanced, disabled-by-default content-negotiation option can also return Markdown from the normal canonical URL when a client explicitly requests `Accept: text/markdown`. This mode sends `Vary: Accept`, but it should be enabled only after confirming every cache and CDN in front of the site honors that header correctly.

LLM Markdown also works with the Classic Editor and supports public custom post types without requiring one.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin
3. Visit Settings → Markdown Output to choose content types and selectors

After activation, append `.md` to supported post URLs.

## Frequently Asked Questions

### Does this help LLMs index my content?

It gives crawlers and other clients a predictable, structured Markdown representation and advertises that representation from eligible HTML pages. It cannot guarantee that a particular model, crawler, or search engine will discover, index, or use it.

### Does the plugin create physical .md files?

No. The `.md` URLs are WordPress rewrite routes generated on demand. Cached documents are stored temporarily for performance, but the plugin does not create a parallel file tree or require you to synchronize exported files.

### Does this modify my content?

No. It reads the rendered public page and generates a separate response. Posts, blocks, templates, and database content are not rewritten.

### Does it support custom post types?

Yes. Any public post type can be enabled under Content Types. Attachments are excluded. Leaving every content type unchecked disables Markdown output entirely.

### How do I access a static front page?

Append `.md` to its normal permalink. The `/index.md` alias is also available when WordPress is configured to use a static front page.

### Does it expose private content?

The plugin serves only publicly viewable content from an enabled public post type. Password-protected and individually disabled content is rejected. The optional Honor Noindex setting also respects WordPress search visibility and supported settings from Yoast SEO, Rank Math, and All in One SEO. Integrations can impose additional restrictions with the `llm_markdown_can_serve_post` filter.

### Can I turn Markdown off for one post?

Yes. Use **Disable Markdown for this content** in the block editor sidebar or Classic Editor Markdown box. The normal HTML page remains available, and the setting can be reversed at any time.

### What do the editor statistics mean?

The block editor sidebar checks the last saved public Markdown response. It shows response status, word count, document size, and a rough token estimate. Token use varies by model; the number is a quick size guide, not a billing estimate. The Classic Editor box provides availability, an in-editor Markdown view, copy, and per-post control without the statistics table.

### How does the rendered theme affect Markdown?

Markdown is built from the server-rendered canonical page, so blocks, shortcodes, and template output can be included. Browser-only content inserted later by JavaScript is not available. Use Main Content Selectors and Excluded Content Selectors when the theme includes navigation, banners, related content, or other unwanted elements around the article.

### Which CSS selectors are supported?

The selector fields intentionally support a practical subset: element names, IDs, classes, combined forms such as `article.entry-content`, descendant selectors, and direct-child selectors. Separate alternatives with commas or new lines. Attribute selectors, pseudo-classes, and sibling combinators are not supported.

### Can images be included?

Yes. Enable Images in Markdown on the Options tab. Images inside the selected content area are converted using their source, alt text, and title; common lazy-loading attributes are recognized. Images are excluded by default.

### Is this intended for SEO?

It provides an alternate representation and adds a discovery link to eligible HTML pages, but it makes no ranking or indexing promises. The Markdown response identifies the HTML URL as canonical. Search-engine and AI-crawler behavior varies.

### Why does unexpected output appear before the Markdown document?

Some themes, plugins, or hosting layers emit HTML, notices, or other output before `.md` or negotiated Markdown responses. Enable **Response Cleanup** on the Options tab under Settings → Markdown Output to remove that unwanted pre-output. This compatibility option is disabled by default.

If the plugin cannot retrieve or extract the rendered page, it returns a non-cacheable `503 Markdown temporarily unavailable` response rather than caching an empty or incomplete document. Unsupported, disabled, or protected `.md` routes return `404 Not Found`.

### Can clients request Markdown from the normal post URL?

Yes. Enable **Header-Based Markdown** on the Advanced tab under Settings → Markdown Output. Supported public post URLs can then return Markdown for `GET` and `HEAD` requests that explicitly prefer `text/markdown`. Both HTML and Markdown responses include `Vary: Accept` so compliant caches can keep the representations separate.

This is an advanced, disabled-by-default feature. A CDN, proxy, server cache, or page-cache plugin that does not honor `Vary: Accept` may serve the wrong representation. Negotiated Markdown also bypasses browser-side analytics and JavaScript. Clear every cache and test both representations after enabling it.

Conservative matching requires an explicit `text/markdown` media range. The separate **Wildcard Accept Headers** option can also permit `text/*`; `*/*` alone never requests Markdown.

Try the live HTML response:

`curl -i -H "Accept: text/html" https://compiledrogue.com/case-studies/sentinelone/`

Try the live negotiated Markdown response:

`curl -i -H "Accept: text/markdown" https://compiledrogue.com/case-studies/sentinelone/`

### How do I refresh cached Markdown after changing my theme or site layout?

Use **Clear Markdown Cache** on the Tools tab under Settings → Markdown Output after changing templates, navigation, widgets, shortcodes, or other content that affects the rendered page. Saving the plugin settings, switching themes, and updating navigation menus also invalidate the Markdown cache automatically.

Developers can use the `llm_markdown_cache_ttl` filter to change the default 12-hour cache duration or return `0` to disable document caching.

### How can I verify which main content selector is being used?

Use **Content Extraction Test** on the DOM & Selectors tab with a public URL from the site. The test reports the first configured selector that matched, the selected HTML element, a short preview of the generated Markdown body after exclusions, and the result for every configured selector. It also warns when extraction is relying on the built-in `main`, `article`, or `body` fallback instead of a configured selector.

### What developer filters are available?

Use `llm_markdown_can_serve_post` for access rules, `llm_markdown_is_noindex_post` for additional noindex providers, `llm_markdown_include_taxonomy` to control public taxonomy fields, `llm_markdown_front_matter` to modify metadata, `llm_markdown_markdown_document` to filter the completed document, and `llm_markdown_cache_ttl` to control caching.

### What happens to plugin data when I remove it?

Deactivation preserves settings. Uninstalling or deleting the plugin removes its settings, cache-generation option, and generated Markdown transients. On multisite, cleanup runs for every site in the network.

## Screenshots

1. Check Markdown status and size, copy or view the response, and control availability in the block editor.
2. A public .md endpoint serves rendered content as Markdown with YAML front matter.
3. Test content selectors and inspect a generated Markdown excerpt.
4. Choose supported content types and configure Markdown behavior.
5. Set main-content and exclusion selectors for rendered HTML.
6. Configure optional header-based Markdown delivery and Accept-header matching.

## Changelog

### 1.1.1

* Fixed a fatal error on WooCommerce HPOS order screens and other non-post meta-box screens while preserving editor tools for normal posts and pages.

### 1.1.0

* Added in-editor Markdown view, copy, and public-URL controls to the block and Classic Editors, with response and size statistics in the block editor.
* Added a per-post Markdown opt-out that leaves the HTML page available and invalidates the post's cached Markdown.
* Added a DOM & Selectors extraction test showing selector matches, fallback behavior, and a short Markdown preview.
* Expanded Honor Noindex support to WordPress search visibility, Yoast SEO, Rank Math, and All in One SEO.
* Added an HTTP `Link` header advertising eligible Markdown URLs alongside the existing HTML alternate link.
* Updated the plugin name, listing copy, and compatibility information.

### 1.0.2

* Added advanced, opt-in Markdown content negotiation for normal singular URLs using `Accept: text/markdown`.
* Added quality-aware Accept-header matching, optional `text/*` support, GET/HEAD restrictions, and `Vary: Accept` signaling for eligible HTML and Markdown responses.
* Added a disabled-by-default Response Cleanup option for unwanted output emitted before Markdown and Markdown-route error responses.
* Reorganized the settings screen into Options, DOM & Selectors, Advanced, and Tools tabs with clearer labels, guidance, and operational warnings.
* Added manual cache invalidation and automatic generation changes after settings updates, theme switches, and navigation-menu updates.
* Added the `llm_markdown_cache_ttl` filter and expanded cache keys to account for conversion version, relevant options, site, locale, and content modification time.
* Changed failed or empty loopback renders to return a non-cacheable 503 response instead of caching incomplete output.
* Added `llm_markdown_can_serve_post` for membership and access-control integrations.
* Restricted front-matter taxonomy data to public taxonomies and added `llm_markdown_include_taxonomy` for further control.
* Allowed an intentionally empty content-type selection to disable all Markdown output.
* Fixed HTML entity decoding and Markdown code delimiters when source code contains backticks.
* Added Markdown conversion for horizontal rules, deletion text, figures, and figure captions, and made simple table output safer for uneven rows and pipe characters.
* Expanded uninstall cleanup to remove all plugin options and generated Markdown transients across single-site and multisite installations.

### 1.0.1

* Added support for tables.
* Added optional support for images.

### 1.0.0

* Initial release.
