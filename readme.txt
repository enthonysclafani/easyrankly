=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take control of your WordPress SEO with simple, fast, and flexible tools.

== Description ==

EasyRankly manages the feature modules your WordPress site needs: Search Engine Optimization, Redirects, Sitemaps, Custom code, Tools, Forms and Multilingual. Enable or disable each module from Features. Disabling a module preserves its settings and content so it can be re-enabled later. SEO and Tools retain their previous enabled state until you explicitly switch them off.

Here's what it does:

* **Core metadata across your site.** SEO titles, meta descriptions, canonical URLs, and robots directives, set up sensibly out of the box, with dynamic variables to fill in titles, social tags, and schema fields automatically.
* **Great social previews.** Open Graph and Twitter (X) cards with shared image alt text, an optional X-specific override, and Media Library alt-text fallbacks for local images.
* **Structured data that search engines understand.** A modular JSON-LD schema graph covering your Organization or Person, optional local business details, articles, breadcrumbs, and WooCommerce product compatibility, plus reusable custom schema blocks you can target to specific pages.
* **Sitemaps, when you want them.** With SEO enabled, WordPress's native XML sitemap stays aligned with EasyRankly visibility rules; the Sitemap module independently adds public custom-post-type archives plus News, Image, and Video sitemaps.
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site.
* **Smart redirects built in.** An optional redirect manager with a streamlined editor for exact, wildcard, and regular-expression rules, essential query-string controls, permanent and temporary redirects, and gone (410) responses.
* **Optional forms.** Create reusable forms in the block editor and embed them with the EasyRankly Form block or `[easyrankly_form id="123"]`. Arrange fields with native Group, Row, Stack and Columns blocks, including nesting, spacing and custom CSS classes. Customize global borders, corner radius, field colors, text size, padding, spacing and button colors in Forms → Style, using a visual box model, live preview and reset. One shared stylesheet and one set of pixel-based values apply on the site, in the preview and in the block editor. Existing relative measurements are converted automatically. Developers can override --erankly-form-gap, --erankly-form-border-width, --erankly-form-border, --erankly-form-radius, --erankly-form-padding-vertical, --erankly-form-padding-horizontal, --erankly-form-font-size, --erankly-form-color, --erankly-form-background, --erankly-form-accent, --erankly-form-button-color, --erankly-form-muted and --erankly-form-error in theme CSS. Browser autocomplete, optional Cloudflare Turnstile, a honeypot, and rate limits protect submissions. Receive messages by email and optionally Slack. Configure a custom SMTP host, port, STARTTLS/SSL, authentication and sender for form emails, with a test email sent to the site administrator. Optionally connect MailerLite, choose a group per Form and map email, an optional name and a dedicated newsletter consent field. No submission archive is stored. Existing forms, block identifiers and shortcodes remain compatible with the Forms naming.
* **Optional custom code.** Off by default. A privileged administrator can add verification meta tags or other markup and scripts in the document head or body. EasyRankly does not execute PHP. Saving requires the `unfiltered_html` capability (a Super Admin on Multisite). Snippets are printed as saved so authorized code is preserved; you remain responsible for any third-party requests those snippets make.
* **Breadcrumbs and robots.txt.** JSON-LD that pairs with WordPress's native Breadcrumbs block (WordPress 7.0+), plus a breadcrumb function and shortcode for your theme (with optional shorter names per page) and an editable virtual robots.txt.

All of it lives in a redesigned, responsive admin interface with consistent form patterns, accessible label and control relationships, and keyboard-friendly tabs and dialogs.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Configure the plugin under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

Use one active SEO output provider at a time. To use another SEO plugin while keeping other EasyRankly modules, disable Search Engine Optimization in Features. If you are switching to EasyRankly from Yoast SEO, Rank Math, All in One SEO, or SEOPress, import your data with the migration assistant, then deactivate the previous SEO plugin.

= Does it support WooCommerce? =

Yes. By default, EasyRankly leaves Product structured data to WooCommerce when WooCommerce's native schema is active, avoiding duplicate Product markup. Developers can opt into EasyRankly's Product JSON-LD with the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters. When enabled, it supports core product fields, SKU, brand, GTIN, offers, aggregate ratings, and approved reviews; variable products use AggregateOffer.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly is network-aware and stores global SEO settings at network level, while content metadata and special-page values remain scoped appropriately to each site. The optional Multilingual feature automatically detects Multisite and loads its built-in network provider. On a single site it manages native content per language, indexed translation groups, language URLs, editor controls, shared strings and a server-rendered language switcher. Enable it in Feature modules.

Each site refreshes its own rewrite rules when needed. By default, networks with more than 100 sites require WP-CLI for network deactivation and uninstall to avoid HTTP timeouts: run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly`.

= Does EasyRankly collect any personal data or phone home? =

EasyRankly does not send site or visitor data to EasyRankly and adds no external analytics or telemetry of its own. Configuration data, including any optional business contact details you enter, and temporary migration files remain on your WordPress installation. When redirects are enabled, EasyRankly stores sampled aggregate hit counts and the last sampled hit time, but it does not store visitor IP addresses, referrers, user agents, languages, or cookie values.

The optional Forms module sends submitted fields to the site's email recipients and, when selected for a form, the configured Slack incoming webhook. MailerLite receives the mapped email, optional name, selected group and consent time only when the visitor selects the dedicated newsletter consent checkbox. Optional Cloudflare Turnstile loads on pages containing forms and receives verification tokens for server-side validation. The plugin does not archive submissions or store IP addresses in plain text; rate limiting stores a salted IP hash for up to ten minutes, and delivery diagnostics contain only the last error code and date per channel. Retention in email, Slack and MailerLite is controlled by those services and the site owner.

If you enable the optional Custom Code module and add snippets that call third-party services, those requests are made by the code you saved and remain your responsibility.

= What does the Custom Code module do? =

It is off by default. When enabled, a user with the `unfiltered_html` capability (a Super Admin on WordPress Multisite) can save HTML, CSS, or JavaScript snippets for the document head, the start of the body, or the footer. Typical uses are site-owner verification meta tags and markup or scripts the site owner chooses.

EasyRankly does not execute PHP. Snippets are printed as saved, without escaping, so authorized code is preserved. EasyRankly itself does not add analytics, tracking, or phone-home calls, but any snippet you add may contact third-party services. You remain responsible for that code.

= How do I display breadcrumbs? =

Enable breadcrumbs under Settings > EasyRankly > Schema, then add a visible trail with WordPress's Breadcrumbs block (included from WordPress 7.0), the `[erankly_breadcrumbs]` shortcode, or `erankly_breadcrumbs()` in your theme. When the native block is available, EasyRankly does not add a separate Gutenberg block to the inserter. On WordPress 6.5–6.9, EasyRankly's Breadcrumbs block remains in the inserter, and the shortcode and theme function stay available. The function echoes its HTML by default; pass `array( 'echo' => false )` to return it without printing. You can customise EasyRankly's own items and markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Existing `easyrankly/breadcrumbs` blocks still render.

Breadcrumb JSON-LD is separate from the visible trail. By default it is emitted only when a visible trail is present, so structured data matches what people see. When that trail is WordPress's Breadcrumbs block, EasyRankly emits BreadcrumbList from the block's own items (including Home/current options and `block_core_breadcrumbs_items` filters) rather than from EasyRankly's trail builder. JSON-LD is printed in `wp_head`. Block themes typically render the header first, so a template-part trail is already known. For classic themes, EasyRankly reconstructs the native trail from a `core/breadcrumbs` block in the queried post content by running WordPress's block renderer (attribute validation, `render_block_data`, `pre_render_block`, block context, and the final HTML filters). The first visible trail is kept when a later block is suppressed. A block printed only from a PHP template after `wp_head`, or inside a synced pattern that is not in that content, is not used for JSON-LD unless it has already rendered. You can emit JSON-LD always, or turn it off, from the Schema settings.

= Are FAQ rich results included? =

No. Google no longer shows FAQ rich results for most sites, so EasyRankly does not generate FAQPage markup. If you need it, add it as a custom schema block.

= Is there an extension API? =

Yes. Extension API v1 includes multilingual provider registration through `erankly_register_multilingual_provider()`, neutral SEO-state reads, localised-value reads and writes, and filtered hreflang output. Localised-value reads and writes are available only on single-site installations; the provider and SEO-state contracts support provider-defined site topologies. The public contracts remain available for integrations. Native Multilingual implementations and assets load only when the feature is enabled, and only for the detected topology.

Add-ons that place a checkbox in the Features panel should call `erankly_register_settings_feature_module()`. It registers the renderer, server/client autosave allowlists, unchecked-toggle handling and optional repeatable collection keys together, so a visible field cannot drift out of sync with the save pipeline.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes, for supported fields and detected storage layouts. Open Settings > EasyRankly > Import/Export. Edition-aware database adapters read supported data directly from Yoast SEO, Rank Math, AIOSEO, and SEOPress installations on the same WordPress site.

Preview does not modify destination SEO metadata or redirects; it records only a resumable job checkpoint and report. Imports run in resumable background batches, recheck targets before applying them, preserve existing EasyRankly values, and pause if the source changes. Before a write migration starts, EasyRankly creates a private complete backup of its own settings, redirects, and registered metadata; it remains downloadable and restorable for seven days. Native EasyRankly export/import covers the same data with size and structural safeguards; a native import runs in batches while its Import / Export page stays open and resumes from where it stopped when the page is opened again.

= Can I customize the complete JSON import size limit? =

Yes. The default ceiling is 10 MB. To set a different ceiling, add this line to `wp-config.php` before the line that loads `wp-settings.php` (this example sets 50 MB):

`define( 'ERANKLY_IMPORT_MAX_BYTES', '50M' );`

The value accepts shorthand strings such as `'50M'`, `'512K'` or `'1G'`, or a numeric byte count, with a minimum of 1 KB. The `erankly_import_export_max_bytes` filter receives the configured value converted to bytes and can override it. The effective limit shown on the Import/Export page may be lower because EasyRankly also checks the PHP memory available for the current request. JSON structural checks and the server's upload limits still apply. This setting also applies to complete backup restores and migration backup size checks.

== External Services ==

EasyRankly adds no analytics, telemetry, or phone-home calls. Forms is disabled by default. Enabling the module alone does not enable the optional external services below.

Custom SMTP: When configured and enabled in Forms, submitted form fields, subject, sender, recipients, visitor reply address and source page are sent through the site owner's SMTP server. Authentication credentials are sent to that server when authentication is enabled. STARTTLS (usually port 587) and SSL/TLS (usually port 465) verify the server certificate; select None only when the provider requires an unencrypted connection. The SMTP settings apply to form emails and the administrator test email. Other WordPress emails use their existing configuration. Disabling custom SMTP restores the existing WordPress delivery for forms. Service terms, privacy policy and message retention depend on the chosen SMTP provider.

= MailerLite =

When configured in Forms, EasyRankly uses a server-side API key to read the account's groups from `https://connect.mailerlite.com/api/groups`. Saving a new key verifies it with this read-only request; Test connection refreshes the group list and creates no subscribers. Group names and IDs are cached for ten minutes. Select a group, a required email field, an optional text field for the name and a dedicated Newsletter consent field in each Form's settings. A privacy consent field cannot be used as newsletter consent. Only explicitly checked newsletter consent sends the mapped email, optional name, group ID and UTC consent time to `https://connect.mailerlite.com/api/subscribers`. No visitor IP, message text or other fields are sent. Existing unsubscribed contacts are not automatically reactivated. API double opt-in follows the MailerLite account's API/integrations setting, which can be enabled separately in MailerLite. Failed subscriptions are reported to the visitor and in delivery diagnostics; there is no background retry queue.

Service: https://www.mailerlite.com/
API: https://developers.mailerlite.com/
Terms: https://www.mailerlite.com/legal/terms-of-service
Privacy: https://www.mailerlite.com/legal/privacy-policy
API double opt-in: https://www.mailerlite.com/help/how-to-use-double-opt-in-when-collecting-subscribers

= Cloudflare Turnstile =

When the site administrator enables Turnstile and configures both keys, pages containing a published form load the script directly from `https://challenges.cloudflare.com/turnstile/v0/api.js`. Cloudflare processes browser, device and network information to assess visitor legitimacy. On submission, EasyRankly sends the secret key and visitor's verification token to `https://challenges.cloudflare.com/turnstile/v0/siteverify`. No submitted form fields or client IP parameter are sent in this server request. The server verifies success, hostname and form action; an unavailable service prevents submission. Test keys are for local/development environments only.

Service: https://www.cloudflare.com/products/turnstile/
Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/
Turnstile Privacy Addendum: https://www.cloudflare.com/turnstile-privacy-policy/

= Slack =

When the administrator configures an incoming webhook and enables Slack delivery for a form, EasyRankly sends the form title, submitted field labels and values, same-host source page URL and submission time to the configured `https://hooks.slack.com/services/…` endpoint. A message is also sent when the administrator clicks Send test message. The plugin sends no visitor IP. Email delivery remains enabled. Review the configured channel's membership and retention before enabling Slack.

Service: https://slack.com/
Terms: https://slack.com/terms-of-service
Privacy: https://slack.com/trust/privacy/privacy-policy

Optional Custom Code snippets may contact other services; those requests remain the site owner's responsibility.

For posts containing YouTube or Vimeo URLs or embeds, EasyRankly may include provider player URLs in VideoObject structured data and video sitemaps. For YouTube videos without a featured image, it may also include a thumbnail URL derived from the public video ID. EasyRankly does not fetch video metadata or thumbnails server-side and does not use vumbnail.com. A browser or search engine that loads these provider URLs sends its normal request data to the provider. See YouTube terms (https://www.youtube.com/t/terms) and privacy policy (https://policies.google.com/privacy), and Vimeo terms (https://vimeo.com/legal) and privacy policy (https://vimeo.com/legal/privacy).

EasyRankly uses WordPress's avatar API when searching for a user in its administration screens and when a WordPress user is selected for Person schema. Depending on the site's avatar configuration and installed filters, this may return a Gravatar URL containing a hash derived from the user's email address. Loading that image sends the usual request data to Gravatar. See Gravatar (https://gravatar.com/), terms (https://wordpress.com/tos/) and privacy policy (https://automattic.com/privacy/).

== Privacy ==

Form submissions are delivered by email and optionally Slack without being archived by EasyRankly. MailerLite receives newsletter subscriptions only after dedicated consent. Only a salted IP hash (up to ten minutes) for abuse limiting and the last error date/code per delivery channel are kept. MailerLite group lists are cached for ten minutes and temporary service backoff expires within one hour. Turnstile processes visitor information on pages with forms only when enabled. Administrators can use the suggested privacy-policy text in WordPress and adapt it to their selected services, form fields and retention policies. Secret keys, MailerLite API keys, SMTP passwords and Slack webhook URLs are stored per site, are never returned by the configuration API, and are excluded from EasyRankly JSON exports. Leave the API key or password field empty to retain its saved value; use the explicit removal checkbox to delete it. All forms, revisions, configuration and plugin-owned transients are deleted on uninstall, on every site of a Multisite network. With a persistent object cache, short-lived hashed abuse-limit entries expire within ten minutes and MailerLite caches/backoff expire within their stated limits.

== Changelog ==

= 2.0.0 =
* Added global Form styling with a visual box model and one shared appearance across the site, preview and editor.
* Added MailerLite groups per Form, dedicated newsletter consent, protected API keys and a read-only connection test.
* Added custom SMTP configuration scoped to form email delivery, protected password handling and an administrator test email.
* Added an optional Forms module with reusable block-editor forms, autocomplete, email/Slack delivery, Turnstile, honeypot, rate limiting, per-site secret configuration and privacy-policy guidance.

EasyRankly 2.0.0 marks the transition from the initial foundation introduced with version 1.0.0 to a more mature, capable, and extensible SEO platform. This release revisits every major area of the plugin, with a renewed focus on delivering essential SEO tools through a fast, modular, and developer-friendly core.

The entire experience has been redesigned around clearer URL-addressable settings, native controls for the block editor, improved classic-editor and taxonomy panels, and contextual Site Editor integration for block themes. Accessibility has also been strengthened throughout, with more explicit labels, better semantic relationships, and keyboard-safe navigation and dialogs.

Metadata and structured data now offer substantially greater control. Primary taxonomy terms, advanced robots directives, dedicated Open Graph and X images, richer schema modes, Event and VideoObject markup, and expanded WooCommerce support make it possible to describe and optimize content with far greater precision.

Redirect management and migration tools have evolved with the same attention to reliability. Redirects now focus on exact, wildcard, and regular-expression matching, essential query-string behavior, automatic rule precedence, response codes, and per-pattern safety limits. Audience targeting, request conditions, scheduling, and manual priority were removed from the public model; incompatible imported rules are skipped or disabled for review instead of being broadened silently. Imports from Yoast SEO, Rank Math, AIOSEO, and SEOPress now include non-writing previews, resumable background processing, and a complete pre-import backup retained for seven days.

Behind the scenes, contextual module loading keeps inactive features from adding unnecessary overhead, while bounded background processing, stronger Multisite support, and dedicated WP-CLI workflows provide a more dependable foundation for sites of every size. EasyRankly 2.0.0 supports WordPress 6.5 and later with PHP 8.0 or newer.

As part of this clearer modular direction, AI generation, content analysis, internal linking, and Health monitoring are now provided through a separate add-on, allowing the core to remain focused, efficient, and easier to extend.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.0.0 =
Before upgrading, create a full backup. Existing SEO content and redirects are preserved. AI generation, content analysis, internal linking and Health monitoring have moved to a separate add-on. Review settings after upgrade.

= 1.0.0 =
First public release of EasyRankly.
