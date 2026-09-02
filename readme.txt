=== EasyRankly ===
Contributors: easyrankly
Tags: block editor, head, body, custom code
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
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

A Search engines panel in the post settings sidebar provides a revision-enabled
meta description. It falls back to the manually entered WordPress excerpt and is
never generated from the content body. Document titles remain entirely native to
WordPress. Trusted users can supply a complete `<title>` in Custom code when they
need an explicit override.
On non-singular views, descriptions come from WordPress-owned data: the posts-page
description or excerpt, the site tagline, or the term, author, or post-type archive
description. Date archives, search results, generic archives, and 404s stay empty.

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

Settings > Social contains the site's public profile URLs and default sharing image.
The profile URLs become `sameAs` identity values; a recognized X profile is also
emitted as `twitter:site`. An author's X username remains in their WordPress Contact
Info and is emitted separately as `twitter:creator` on posts.

In addition to the baseline tags, EasyRankly emits `og:locale`, image width,
height, and MIME type where the image comes from the Media Library. Posts also
receive available `article:published_time`, `article:modified_time`,
`article:author`, `article:section`, and `article:tag` metadata.
The default WordPress category is never used as `article:section`; when present,
the first non-default editorial category is used instead.

Trusted users can enter a social `meta` tag in either Custom code field.
Current post code applies to that singular view; Global code applies in every
supported context. A matching automatic tag is suppressed, so the manual tag
cleanly overrides it rather than creating a duplicate. A manual `og:image`,
`og:image:url`, or `twitter:image` also owns its structured image properties; a
manual structured image property replaces only that property.

Settings > General contains a Site identity section. A site can represent an
Organization, using the native Site Title and Site Logo or Site Icon, or a Person,
using a selected WordPress user's public name and avatar. The complete identity is
printed with a stable `#identity` ID when another automatic node references it.
The front page also receives a minimal `WebSite` node containing only its stable ID,
URL, name, and publisher reference. EasyRankly does not invent a `SearchAction`.

Settings > Local business contains one canonical profile for a physical business
location. When enabled and complete, it replaces the generic Organization identity
with `LocalBusiness` or a selected subtype while retaining the stable `#identity`
ID. The node includes the business name, PostalAddress, telephone, optional opening
hours, optional GeoCoordinates, location URL, logo, public profiles, and an optional
Google Business Profile or Maps URL in `sameAs`.

The same canonical data powers the server-rendered EasyRankly Business Profile
block and `[easyrankly_business_profile]` shortcode. This prevents a footer or
contact page from needing a second copy of the name, address, telephone, and hours.
EasyRankly links to Google Business Profile but does not create or edit the remote
profile. Coordinates are entered and confirmed manually; no third-party API runs
during a public request.

Published posts automatically receive `BlogPosting` JSON-LD in the document head.
It includes the canonical URL, headline, author, publisher, featured image when available,
`datePublished`, and `dateModified` after a real update. Dates use complete ISO 8601
timestamps in the WordPress site timezone. Visible dates remain entirely owned by
the theme and WordPress blocks.

Automatic nodes are emitted in one `@graph`. Valid manual JSON-LD containing
`WebSite`, `Organization`, `Person`, `LocalBusiness` or a supported subtype,
`Article`, `BlogPosting`, `NewsArticle`, or `BreadcrumbList` takes ownership of the matching automatic node. A manual identity
with an `@id` is referenced by the remaining automatic nodes. Current post code
applies to one singular item; Global code applies everywhere.

For public, indexable singular content, EasyRankly also automatically prints
one `BreadcrumbList` JSON-LD trail. Its path follows the default resolver used by
the WordPress 7.1 Breadcrumbs block: Home, an available posts archive or custom
post type archive, the applicable hierarchy or taxonomy term (including term
ancestors), and the current content title. There is no editor toggle or Breadcrumb
Name field.

Hierarchical content follows post ancestors by default, matching the Breadcrumbs
block's default. Flat content uses its primary available public taxonomy path.

To take ownership of one content item's breadcrumb name or path, paste a complete,
valid JSON-LD `BreadcrumbList` into that item's Custom code. A Global
`BreadcrumbList` takes ownership for every eligible item. EasyRankly parses valid
JSON-LD semantically; invalid JSON-LD and plain text mentions do not suppress the
automatic trail. A standalone `name` property cannot alter it.

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

Custom code accepts variables written as `{{name}}`. They turn Global code into a
template every content item inherits, so one pattern covers the whole site:

    <title>{{title}} - {{siteName}}</title>
    <meta name="description" content="{{description}}">

The Custom code modal has a Variables tab listing every available name, the source
it reads, and the value it currently resolves to for the content being edited.
Available names are `title`, `description`, `excerpt`, `siteName`,
`siteDescription`, `url`, `siteUrl`, `image`, `author`, `published`, `modified`,
`postType`, `category`, `tags`, `locale`, `searchQuery` and `page`. Every one of
them reads the data EasyRankly already uses for its automatic metadata, so a
template prints what the plugin would print on its own.

A variable can list fallbacks separated by `|`, ending with a quoted literal:
`{{excerpt|siteDescription|'Fixed text'}}` uses the first value that is not empty.
When the whole chain stays empty, a tag written on a line of its own is dropped
from the output and the automatic EasyRankly metadata takes over; write one tag
per line to get that behavior. A name that does not exist is left untouched, so a
typo stays visible in the page source instead of silently printing nothing.

Values are escaped for the context each variable sits in: attribute values, text
and `<title>`, or a string inside a `<script type="application/ld+json">` block,
where the variable must be written inside the quotes
(`{"headline":"{{title}}"}`). This matters because content titles and excerpts are
written by users who do not have the unfiltered HTML capability. Variables resolve
in Global and Current post code alike, in the head and at both body positions.
Code without a variable is printed exactly as entered.

If there is no featured image or configured default image, EasyRankly omits
image metadata. Configure a default image whenever content can lack a featured
image. If an image has no meaningful alt text, it omits only the corresponding
alt metadata.

The document summary also includes an Indexing control. Index leaves the final
robots policy to WordPress; Noindex adds the directive
to WordPress' canonical robots meta tag and `X-Robots-Tag` HTTP response header
for that content, and excludes published content marked Noindex from WordPress'
native XML sitemap. EasyRankly does not create or render a separate sitemap.
The HTTP header applies to pages rendered by WordPress; control static files such
as PDFs and images at the server or CDN layer. The Noindex setting also applies to
the page configured as the posts page. A manual `robots` meta tag takes ownership
of both automatic robots outputs. A valid manual canonical tag becomes the URL used
by WordPress canonical resolution, social metadata, and automatic schema.

Editors can update the SEO controls for content they can edit. Raw Head and Body code
also requires permission to use unfiltered HTML; Global code additionally requires the
administrator capability to manage site options.

Slack's normal link preview uses the emitted Open Graph metadata. Interactive
Slack unfurls, and audio or video social metadata, require a separate integration.

EasyRankly is the site's exclusive SEO owner. Running another SEO plugin at the
same time is unsupported because both products may print conflicting metadata.

Deactivation preserves settings. WordPress uninstall removes EasyRankly options,
post metadata, retired EasyRankly Zero social fields, and author X metadata,
including multisite data.

== Upgrade Notice ==

= 2.1.0 =
Custom code now supports {{variables}}, so Global code can act as a title and
description template that every content item inherits.

= 2.0.1 =
Hardening release: restores compatibility shims, warns about conflicting SEO
plugins, and fixes schema ownership, breadcrumb parity, and multisite caches.

= 2.0.0 =
Breaking change: EasyRankly is now the exclusive SEO owner. Its former public
filters and compatibility mode for other SEO plugins have been removed.

== Changelog ==

= 2.1.0 =
* Added `{{variables}}` to Custom code, so Global code works as a site-wide metadata template.
* Added fallback chains and automatic removal of a template tag whose value resolves empty.
* Added a Variables tab to the Custom code modal listing every name, its source, and its current value.
* Escaped resolved values per context for attributes, text, titles, and JSON-LD blocks.
* Fixed a manual or templated title not replacing the document title on block themes.

= 2.0.1 =
* Fixed nested JSON-LD objects being mistaken for top-level schema ownership.
* Restored core breadcrumb customization parity and guarded core breadcrumb helpers.
* Isolated runtime caches by site and locale and consolidated test cache resets.
* Restored deprecated compatibility entry points removed in 2.0.0.
* Added an administrator warning for known overlapping SEO plugins without changing output behavior.
* Removed redundant schema and social defenses, unused timestamp payloads, and an experimental editor API.
* Aligned trait responsibilities, translation loading, and release metadata.

= 2.0.0 =
* Made EasyRankly the exclusive SEO owner and removed competitor detection.
* Removed the public filter surface and legacy schema printers.
* Split the 4,323-line bootstrap into focused internal modules.
* Removed redundant private docblocks and cached repeated request normalization.
* Fixed late custom-post-type meta registration and modernized the Business Profile block.
* Added validation feedback for invalid opening hours and prevented dangling schema references.
* Made smoke tests self-contained and aligned release metadata and exclusions.
* Omitted the default WordPress category from article sections and removed dead internal surfaces.

= 1.18.0 =
* Added a canonical single-location business profile with NAP, opening hours, coordinates, and Google Business Profile URL.
* Added LocalBusiness and specific subtype output on the existing stable identity node.
* Added a dynamic Business Profile block, shortcode, and public PHP helper backed by the same data.
* Extended manual schema ownership to Organization, Person, LocalBusiness, and supported LocalBusiness subtypes.
* Kept visible business data available when another SEO plugin owns automatic head output.

= 1.17.0 =
* Replaced private Preferences APIs with public modal components and accessible Custom code labels.
* Migrated legacy social images once, removed empty image placeholders, and added distribution exclusions.
* Added regression coverage for breadcrumb paths, revision sanitization, and revision metadata permissions.
* Added an explicit taxonomy preference filter so structured breadcrumbs can match the visible WordPress block.
* Replaced experimental stack components and added a public fallback for the indexing popover header.
* Restored native block-editor spacing and popover anchoring for the Indexing row.
* Kept late developer filters for Custom Head code and request context effective through final output.
* Corrected front-page resolution, social image preview cleanup, and Site identity field registration.
* Deprecated the three legacy standalone schema printers in favor of the consolidated schema graph.
* Removed the unused data-version marker and automatic database check.
* Let Settings > General render Site identity fields in their native registered position without DOM reordering.

= 1.16.1 =
* Removed the Search result title field and title-format setting.
* Returned automatic document titles to WordPress and made a valid Custom code `<title>` the explicit override.

= 1.16.0 =
* Kept Custom code active beside other SEO plugins while pausing automatic SEO output.
* Made manual description, canonical, robots, social, and valid JSON-LD true overrides.
* Added WordPress-owned descriptions for the posts page and descriptive archives.
* Added a minimal WebSite node and consolidated automatic schema into one filterable graph.
* Returned visible dates to themes and core blocks.
* Added developer filters, data-versioned upgrades, multisite-safe uninstall, and anti-duplication tests.

= 1.15.0 =
* Renamed the existing Settings submenu and screen to Social.
* Added site profile URLs, site-level `twitter:site`, and structured `sameAs` values.
* Added a native Site identity section under Settings > General.
* Added Organization or Person identity schema and reused it as Article publisher.
* Enriched Article author identity with stable IDs and available public profile URLs.
* Added a revision-enabled search result title and title format under Settings > Reading.

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
