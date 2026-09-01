=== EasyRankly ===
Contributors: easyrankly
Tags: block editor, head, body, custom code
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an Advanced modal to the block editor for per-content and global code in the document head and body.

== Description ==

EasyRankly adds a Custom code item to the post editor's Options menu. It opens
a Preferences-style modal with Current post and Global editors for `<head>`, the
start of `<body>`, and the end of `<body>`. Current-post code is stored as
revision-enabled post meta and printed on that content's singular front-end view.
Global code uses the WordPress Options and REST Settings APIs and is printed on
every front-end page before per-content code.

A Search engines panel in the post settings sidebar provides a simple meta description
textarea. Its revision-enabled value is used first for the page description. When
it is empty, EasyRankly uses the manually entered WordPress excerpt instead;
it never generates a description from the content body.

EasyRankly produces baseline Open Graph and X (Twitter) preview metadata for
singular content, the front page, the posts page, taxonomies, author archives,
date and post-type archives, and search results. It uses the title, description or
excerpt, canonical URL, featured image, Media Library alt text, site name, and
locale. It omits previews, password-protected content, and non-public content.
Posts use `article`; other contexts use `website`.

Social previews require no separate editor fields. Open Graph and X use the same
automatic WordPress sources: the content or archive title, the Search engines
description or manual excerpt, the canonical URL, the featured image, and its Media
Library alt text. The optional default image under Settings > Social is used only
when there is no featured image. Its alt text is always read from the Media Library;
the plugin does not store a separate copy or invent descriptions or alt text.

X automatically uses `summary_large_image` whenever the resolved image exists and
`summary` otherwise. Advanced overrides belong in Custom code: a matching social
`meta` tag suppresses only the corresponding automatic tag.

An author's X handle can be set in their WordPress Contact Info and is emitted as
`twitter:creator` on posts.

In addition to the baseline tags, EasyRankly emits `og:locale`, image width,
height, and MIME type where the image comes from the Media Library. Posts also
receive available `article:published_time`, `article:modified_time`,
`article:author`, `article:section`, and `article:tag` metadata.

Trusted users can enter a social `meta` tag in either Custom code field.
Current post code applies to that singular view; Global code applies in every
supported context. A matching automatic tag is suppressed, so the manual tag
cleanly overrides it rather than creating a duplicate. A manual `og:image`,
`og:image:url`, or `twitter:image` also owns its structured image properties; a
manual structured image property replaces only that property.

Published posts automatically receive `BlogPosting` JSON-LD in the document head.
It includes the canonical URL, headline, author, featured image when available,
`datePublished`, and `dateModified` after a real update. Dates use complete ISO 8601
timestamps in the WordPress site timezone. The same timestamps are printed in
visible `time` elements immediately before the post content, while their labels use
the site's normal date format.

Valid manual JSON-LD containing `Article`, `BlogPosting`, or `NewsArticle` takes
ownership of the Article schema and suppresses the automatic copy. Current post
code applies to one post; Global code applies to every post.
A theme or custom integration can disable the automatic schema with the
`erankly_article_schema_enabled` filter or extend its data with the
`erankly_article_schema` filter.

For public, indexable singular content, EasyRankly also automatically prints
one `BreadcrumbList` JSON-LD trail. Its path follows the default resolver used by
the WordPress 7.1 Breadcrumbs block: Home, an available posts archive or custom
post type archive, the applicable hierarchy or taxonomy term (including term
ancestors), and the current content title. There is no editor toggle or Breadcrumb
Name field.

To take ownership of one content item's breadcrumb name or path, paste a complete,
valid JSON-LD `BreadcrumbList` into that item's Custom code. A Global
`BreadcrumbList` takes ownership for every eligible item. When effective code
contains `BreadcrumbList` (including `schema:BreadcrumbList`), EasyRankly leaves
breadcrumb schema to it and does not print its automatic trail. The plugin does not
parse or validate manual JSON-LD, so the manually entered schema must be complete
and valid. A standalone `name` property cannot alter the automatically generated
JSON-LD; it must be part of the complete manual `BreadcrumbList`.

For example, a manual two-level trail can be added as:

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "Blog",
          "item": "https://example.com/blog/"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "A shorter custom breadcrumb name"
        }
      ]
    }
    </script>

If there is no featured image or configured default image, EasyRankly omits
image metadata. The Settings screen highlights an empty default image;
configure one whenever content can lack a featured image. If an image has no
meaningful alt text, it omits only the corresponding alt metadata.

The document summary also includes an Indexing control. Index leaves the final
robots policy to WordPress and installed SEO plugins; Noindex adds the directive
to WordPress' canonical robots meta tag and `X-Robots-Tag` HTTP response header
for that content, and excludes published content marked Noindex from WordPress'
native XML sitemap. EasyRankly does not create or render a separate sitemap.
The HTTP header applies to pages rendered by WordPress; control static files such
as PDFs and images at the server or CDN layer. The Noindex setting also applies to
the page configured as the posts page.

Editors can update the SEO controls for content they can edit. Raw Head and Body code
also requires permission to use unfiltered HTML; Global code additionally requires the
administrator capability to manage site options.

A theme or custom integration that provides its own breadcrumb schema can disable
the automatic output with the `erankly_breadcrumb_schema_enabled` filter.

Slack's normal link preview uses the emitted Open Graph metadata. Interactive
Slack unfurls, and audio or video social metadata, require a separate integration.

To prevent duplicate or conflicting metadata, EasyRankly remains completely
inactive when it detects another head owner. Supported owners include Yoast SEO,
Rank Math, All in One SEO, SEOPress, The SEO Framework, Slim SEO, SmartCrawl,
Squirrly SEO, SureRank, WP Meta SEO, SEO Ultimate, and Platinum SEO Pack. Site and
network-active plugins are both detected. Custom integrations can extend the
known plugin list with `erankly_owner_plugins`, or the final detection
result with `erankly_has_active_head_owner`. Themes can use the latter
filter because ownership is checked after the active theme loads.

When a known SEO head owner makes EasyRankly inactive, administrators see a
notice identifying the detected plugin where WordPress can provide its name.

== Testing ==

From the site root, run:

    studio wp eval-file wp-content/plugins/easyrankly/tests/social-preview-smoke.php
    studio wp eval-file wp-content/plugins/easyrankly/tests/article-schema-smoke.php

== Changelog ==

= 1.14.0 =
* Added global and per-content code at the start and end of the document body.
* Reused WordPress Preferences styles and removed the duplicated modal CSS.

= 1.13.0 =
* Matched the Custom code modal to WordPress 7.1 Preferences using public core components.
* Added trusted-administrator global Head code, printed before per-content code on every front-end page.

= 1.12.0 =
* Added `X-Robots-Tag: noindex` HTTP responses for WordPress-rendered content marked Noindex.

= 1.11.0 =
* Replaced the plugin-branded settings screen with Settings > Social.
* Made the default social image a native Media Library selection whose alt text stays inherited from its attachment.
* Removed the site-wide X account and Facebook app ID settings.

= 1.10.0 =
* Added automatic BlogPosting JSON-LD for published, indexable posts.
* Added visible publication and update dates sourced from the same WordPress timestamps.
* Added valid manual Article JSON-LD ownership and filters for custom integrations.

= 1.9.0 =
* Removed the per-content Social previews fields and their stored-value precedence.
* Open Graph and X now share automatic WordPress title, description, image, and alt sources.
* X card type is always `summary_large_image` with an image and `summary` without one.
* Kept per-content social overrides in Custom code only.

= 1.8.4 =
* Removed the custom Indexing icon; the control now uses only WordPress core component styling.

= 1.8.3 =
* Removed the legacy PanelRow wrapper so Indexing uses the same direct HStack DOM as native summary rows.

= 1.8.2 =
* Matched the Indexing summary row and choice popover to WordPress 7.1's native Status control.

= 1.8.1 =
* Used WordPress canonical URLs and excluded preview, non-public, and
  password-protected social contexts.
* Fixed paginated archive URLs, X-image alt precedence, and partial manual image
  overrides.
* Restricted social image URLs to absolute HTTP(S) URLs.
* Made Settings API labels accessible and replaced generic script localization.
* Simplified the Indexing editor control to stable WordPress components.

= 1.8.0 =
* Added generic and X-specific social-preview overrides in the block editor.
* Added X site and author handles, a Facebook app ID, and a default social image.
* Added social previews for home, archive, taxonomy, author, and search contexts.
* Added Open Graph locale, image dimensions/type, and article metadata for posts.
* Made matching manual social meta tags override automatic output without duplicates.
* Added an administrator notice when another SEO plugin owns document-head metadata.

= 1.7.0 =
* Added automatic BreadcrumbList JSON-LD for public, indexable singular content.
* Uses WordPress 7.1's breadcrumb resolver and native breadcrumb filter.
* Lets manually entered BreadcrumbList markup take precedence over automatic schema.
* Added a filter for disabling automatic breadcrumb output when another integration owns it.

= 1.6.5 =
* Anchored the Indexing popover to its complete row, matching WordPress core.

= 1.6.4 =
* Reused WordPress' Status popover controls for the Indexing choices.

= 1.6.3 =
* Matched the Indexing row structure and spacing to WordPress' native HStack rows.

= 1.6.2 =
* Restored the Indexing control to the end of the native document summary.

= 1.6.1 =
* Removed the Nofollow, Nosnippet, and Noimageindex controls from Visibility.

= 1.6.0 =
* Excluded content marked Noindex from WordPress' native XML sitemap.
* Applied the Noindex visibility setting to the configured posts page.

= 1.5.0 =
* Added automatic, complete Open Graph and X (Twitter) preview metadata for singular content.
* Uses the manually entered WordPress excerpt when the per-content meta description is empty.

= 1.4.0 =
* Remains completely inactive when another SEO head owner is active.

= 1.3.2 =
* Uses the native content title for Open Graph and X (Twitter) titles.

= 1.3.1 =
* Uses the Search engines meta description for Open Graph and X (Twitter) descriptions.

= 1.3.0 =
* Added a Search engines sidebar panel with a per-content meta description.

= 1.2.0 =
* Matched the WordPress 7.1 Status row HStack structure.
* Added Nofollow, Nosnippet, and Noimageindex controls.

= 1.1.0 =
* Added per-content Index/Noindex visibility to the document summary.

= 1.0.0 =
* Initial release for WordPress 7.1.
