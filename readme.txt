=== EasyRankly ===
Contributors: easyrankly
Tags: seo, redirects, sitemap, multilingual
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight SEO built only on native WordPress APIs. No custom tables, no cron jobs, no frontend scripts.

== Description ==

EasyRankly is in active development. This version is not ready for production sites.

* **No custom tables.** Everything is stored with WordPress options, meta, post types and taxonomies.
* **No cron jobs.** Nothing runs in the background on your server.
* **No frontend scripts.** Your visitors download nothing extra.

== Frequently Asked Questions ==

= How do header, footer and patterns change language? =

In block themes, create a template part or a synced pattern with the same name followed by the language prefix: "header-en" for "header", "Banner - EN" for "Banner". On the English pages it replaces the original. Unsynced patterns become a copy where they are inserted, so they are translated with the page of each language.

= Are classic themes supported? =

Contents, menus, site title and tagline, and the texts the theme translates itself change with the language. Widgets and texts set in the Customizer do not: they show the same text in every language. Block themes are supported first.

== External services ==

EasyRankly does not connect to any external service.

== Changelog ==

= 3.0.0 =
* Complete rewrite on native WordPress APIs.
