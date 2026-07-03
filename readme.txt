=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, breadcrumbs
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly provides only the technical SEO primitives a lean WordPress site needs:

* Document title integration.
* Meta description.
* Canonical URL.
* Robots meta through the native WordPress robots API.
* Open Graph and Twitter cards.
* Modular JSON-LD schema graph.
* Extended Organization identity details and optional single-location LocalBusiness schema.
* Optional XML sitemap index, post type sitemaps with image entries, taxonomy sitemaps, and SEO-aware author sitemap.
* Virtual robots.txt integration.
* Breadcrumb function.
* Granular noindex, nofollow, noarchive, and sitemap exclusion controls.
* Per-content search and archive query exclusion controls.
* Global Schema settings with repeatable custom JSON-LD blocks.
* Targeting rules for applying global schema blocks to front page, posts page, singular content, post type archives, and search results.
* Dynamic variables for content metadata, social metadata, and schema fields.
* Optional redirect management with exact, wildcard, and regex matching.
* Optional Health monitoring with frequent 404 detection (no user data is tracked; paths are anonymized and stored locally).
* Optional per-content breadcrumb names for shorter visible breadcrumbs and BreadcrumbList schema labels.

It does not include keyword scoring, readability analysis, AI, analytics, tracking, internal linking suggestions, marketing widgets, or upsells.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Complete the short setup wizard, or configure the plugin later under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

Yes. EasyRankly detects active SEO plugins and automatically steps back from overlapping output (document title, meta description, canonical, robots meta, sitemaps, and robots.txt) so you never get duplicate tags. You can force any of it back on with the `easyrankly_enable_head_output`, `easyrankly_enable_sitemaps_with_external_seo`, and `easyrankly_enable_robots_txt_with_external_seo` filters.

= Does it support WooCommerce? =

Yes. EasyRankly can output Product JSON-LD structured data for WooCommerce products. It is controlled by the `easyrankly_woocommerce_structured_data_enabled` and `easyrankly_render_woocommerce_product_schema` filters.

= Does EasyRankly work on WordPress Multisite? =

Yes. There is full Multisite support with network-level global settings, plus an optional multilingual module that links posts, pages, and terms across network sites and outputs hreflang alternates in the head and XML sitemaps.

= Does EasyRankly collect any personal data or phone home? =

No. There is no analytics, tracking, telemetry, or external service call. The optional Health 404 monitor stores only anonymized request paths in your own database; no user data is recorded.

= How do I display breadcrumbs? =

Call `easyrankly_breadcrumbs()` in your theme template. You can customise the markup with the `easyrankly_breadcrumb_items` and `easyrankly_breadcrumbs_html` filters.

= Can I import my settings from Yoast SEO or Rank Math? =

Yes. Open Settings > EasyRankly > Import/Export. It can import all plugin data from a JSON file and includes dedicated Yoast SEO and Rank Math importers for per-content meta.

== Developer API ==

Available filters include:

* `easyrankly_breadcrumb_items`
* `easyrankly_breadcrumbs_html`
* `easyrankly_canonical`
* `easyrankly_description`
* `easyrankly_enable_head_output`
* `easyrankly_enable_robots_txt_with_external_seo`
* `easyrankly_enable_sitemaps_with_external_seo`
* `easyrankly_faq_items`
* `easyrankly_health_404_sample_rate`
* `easyrankly_hreflang_alternates`
* `easyrankly_image_sitemap_url`
* `easyrankly_include_user_sitemap`
* `easyrankly_local_business_types`
* `easyrankly_localized_url`
* `easyrankly_news_sitemap_post_types`
* `easyrankly_news_sitemap_publication_language`
* `easyrankly_news_sitemap_publication_name`
* `easyrankly_news_sitemap_url`
* `easyrankly_og_description`
* `easyrankly_og_image`
* `easyrankly_og_title`
* `easyrankly_opengraph_tags`
* `easyrankly_organization_schema_details`
* `easyrankly_post_breadcrumb_name`
* `easyrankly_post_types`
* `easyrankly_redirect_hit_sample_rate`
* `easyrankly_render_woocommerce_product_schema`
* `easyrankly_robots`
* `easyrankly_robots_txt_lines`
* `easyrankly_schema`
* `easyrankly_schema_article`
* `easyrankly_schema_blogposting`
* `easyrankly_schema_breadcrumb_list`
* `easyrankly_schema_faq`
* `easyrankly_schema_local_business`
* `easyrankly_schema_localbusiness`
* `easyrankly_schema_organization`
* `easyrankly_schema_person`
* `easyrankly_schema_service`
* `easyrankly_schema_webpage`
* `easyrankly_schema_website`
* `easyrankly_sitemap_images`
* `easyrankly_sitemap_post_types`
* `easyrankly_special_pages`
* `easyrankly_taxonomies`
* `easyrankly_title`
* `easyrankly_twitter_card_type`
* `easyrankly_twitter_description`
* `easyrankly_twitter_image`
* `easyrankly_twitter_site`
* `easyrankly_twitter_title`
* `easyrankly_video_sitemap_url`
* `easyrankly_woocommerce_structured_data_enabled`

Use `easyrankly_breadcrumbs()` to render breadcrumbs.

== Changelog ==

= 0.1.0 =
* First public release.

== Upgrade Notice ==

= 0.1.0 =
First public release of EasyRankly.
