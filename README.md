=== LLM Markdown – Expose Content as .md ===

Donate link: https://www.paypal.com/donate/?hosted_button_id=EUHE8NXYEXJJ6  
Contributors: michaelsablone
Tags: markdown, llm, ai, headless, content-export
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress posts and pages as real .md URLs with YAML front matter for LLMs, AI ingestion, and headless workflows.

== Description ==

LLM Markdown gives supported public WordPress content a clean Markdown representation at a predictable `.md` URL. Append `.md` to a post, page, or enabled public post-type URL:

`https://example.com/my-post.md`

The plugin renders the canonical front-end page, selects the part of the document that contains the primary content, removes configured elements, and converts the result to Markdown. Blocks, shortcodes, and other server-rendered content are therefore represented after WordPress and the active theme have processed them. The original post is never changed and no duplicate Markdown post is stored.

= Structured Markdown output =

Each document begins with YAML front matter containing the title, post ID, content type, slug, publication and modification dates, canonical URL, Markdown URL, excerpt, and terms from public taxonomies when available.

The converter handles headings, paragraphs, emphasis, links, ordered and unordered lists, blockquotes, inline and fenced code, horizontal rules, deletion text, figures and captions, and simple tables. Images can be included as an option, with support for common lazy-loading source attributes.

= Content selection and access control =

Choose which public post types expose Markdown. Simple CSS selectors determine the main content container and which elements should be excluded before conversion. Password-protected and non-public content is rejected, and Yoast SEO noindex values can be honored. Developers can apply an additional access-control filter for membership, privacy, or custom publishing rules.

= Discovery, delivery, and caching =

Eligible HTML pages advertise their Markdown counterpart with a `<link rel="alternate" type="text/markdown">` element. Documents generated for anonymous requests are cached for performance and automatically invalidated after relevant settings, theme, or navigation changes; a manual cache control is available under Tools.

An advanced, disabled-by-default content-negotiation option can also return Markdown from the normal canonical URL when a client explicitly requests `Accept: text/markdown`. This mode sends `Vary: Accept`, but it should be enabled only after confirming every cache and CDN in front of the site honors that header correctly.

LLM Markdown has no Gutenberg lock-in, does not duplicate content, and supports public custom post types without requiring one.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin
3. Visit Settings → Markdown Output to configure options

After activation, append `.md` to supported post URLs.

== Frequently Asked Questions ==

= Does this help LLMs index my content? =

It gives crawlers and other clients a predictable, structured Markdown representation and advertises that representation from eligible HTML pages. It cannot guarantee that a particular model, crawler, or search engine will discover, index, or use it.

= Does the plugin create physical .md files? =

No. The `.md` URLs are WordPress rewrite routes generated on demand. Cached documents are stored temporarily for performance, but the plugin does not create a parallel file tree or require you to synchronize exported files.

= Does this modify my content? =

No. It reads the rendered public page and generates a separate response. Posts, blocks, templates, and database content are not rewritten.

= Does it support custom post types? =

Yes. Any public post type can be enabled under Content Types. Attachments are excluded. Leaving every content type unchecked disables Markdown output entirely.

= How do I access a static front page? =

Append `.md` to its normal permalink. The `/index.md` alias is also available when WordPress is configured to use a static front page.

= Does it expose private content? =

The plugin serves only publicly viewable content from an enabled public post type. Password-protected content is rejected. The optional Honor Noindex setting also rejects posts marked noindex by Yoast SEO, and integrations can impose additional restrictions with the `llm_markdown_can_serve_post` filter.

= How does the rendered theme affect Markdown? =

Markdown is built from the server-rendered canonical page, so blocks, shortcodes, and template output can be included. Browser-only content inserted later by JavaScript is not available. Use Main Content Selectors and Excluded Content Selectors when the theme includes navigation, banners, related content, or other unwanted elements around the article.

= Which CSS selectors are supported? =

The selector fields intentionally support a practical subset: element names, IDs, classes, combined forms such as `article.entry-content`, descendant selectors, and direct-child selectors. Separate alternatives with commas or new lines. Attribute selectors, pseudo-classes, and sibling combinators are not supported.

= Can images be included? =

Yes. Enable Images in Markdown on the Options tab. Images inside the selected content area are converted using their source, alt text, and title; common lazy-loading attributes are recognized. Images are excluded by default.

= Is this intended for SEO? =

It provides an alternate representation and adds a discovery link to eligible HTML pages, but it makes no ranking or indexing promises. The Markdown response identifies the HTML URL as canonical. Search-engine and AI-crawler behavior varies.

= Why does unexpected output appear before the Markdown document? =

Some themes, plugins, or hosting layers emit HTML, notices, or other output before `.md` or negotiated Markdown responses. Enable **Response Cleanup** on the Options tab under Settings → Markdown Output to remove that unwanted pre-output. This compatibility option is disabled by default.

If the plugin cannot retrieve or extract the rendered page, it returns a non-cacheable `503 Markdown temporarily unavailable` response rather than caching an empty or incomplete document. Unsupported, disabled, or protected `.md` routes return `404 Not Found`.

= Can clients request Markdown from the normal post URL? =

Yes. Enable **Header-Based Markdown** on the Advanced tab under Settings → Markdown Output. Supported public post URLs can then return Markdown for `GET` and `HEAD` requests that explicitly prefer `text/markdown`. Both HTML and Markdown responses include `Vary: Accept` so compliant caches can keep the representations separate.

This is an advanced, disabled-by-default feature. A CDN, proxy, server cache, or page-cache plugin that does not honor `Vary: Accept` may serve the wrong representation. Negotiated Markdown also bypasses browser-side analytics and JavaScript. Clear every cache and test both representations after enabling it.

Conservative matching requires an explicit `text/markdown` media range. The separate **Wildcard Accept Headers** option can also permit `text/*`; `*/*` alone never requests Markdown.

Test HTML:

`curl -i -H "Accept: text/html" https://example.com/my-post/`

Test Markdown:

`curl -i -H "Accept: text/markdown" https://example.com/my-post/`

= How do I refresh cached Markdown after changing my theme or site layout? =

Use **Clear Markdown Cache** on the Tools tab under Settings → Markdown Output after changing templates, navigation, widgets, shortcodes, or other content that affects the rendered page. Saving the plugin settings, switching themes, and updating navigation menus also invalidate the Markdown cache automatically.

Developers can use the `llm_markdown_cache_ttl` filter to change the default 12-hour cache duration or return `0` to disable document caching.

= What developer filters are available? =

Use `llm_markdown_can_serve_post` for access rules, `llm_markdown_is_noindex_post` for additional noindex providers, `llm_markdown_include_taxonomy` to control public taxonomy fields, `llm_markdown_front_matter` to modify metadata, `llm_markdown_markdown_document` to filter the completed document, and `llm_markdown_cache_ttl` to control caching.

= What happens to plugin data when I remove it? =

Deactivation preserves settings. Uninstalling or deleting the plugin removes its settings, cache-generation option, and generated Markdown transients. On multisite, cleanup runs for every site in the network.

== Screenshots ==

1. Basic Settings Panel
2. DOM & Selectors Settings Panel
3. Advanced Settings Panel
4. Tools Settings Panel

== Changelog ==

= 1.0.2 =
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

= 1.0.1 =
* Added support for tables.
* Added optional support for images.

= 1.0.0 =
* Initial release.
