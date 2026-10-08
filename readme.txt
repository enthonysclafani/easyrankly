=== EasyRankly ===
Contributors: easyrankly
Tags: seo, redirects, sitemap, multilingual, ai
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight SEO built only on native WordPress APIs. No custom tables, no cron jobs, no frontend scripts.

== Description ==

EasyRankly is in active development. This version is not ready for production sites.

* **No custom tables.** Everything is stored with WordPress options, meta, post types and taxonomies.
* **No cron jobs.** Nothing runs in the background on your server.
* **No frontend scripts.** Your visitors download nothing extra.

== External services ==

EasyRankly never contacts an AI service on its own and holds no API key. Its AI agent works only through the AI Client built into WordPress, with the provider a site administrator connects in Settings → Connectors. Without a connected provider, the AI features stay hidden and the rest of the plugin works the same.

When a person with the right permissions asks the agent for a suggestion, the request goes to that provider and contains:

* for the SEO texts of a post: the site name, address and language, the project memory written by the site administrators, and the post's title, address, excerpt, current SEO texts and up to 6,000 characters of its text;
* for the alternative text of an image: the same site details and memory, the image file (a resized copy when available), its file name, title and caption, and the title of the post it belongs to.

What the provider does with this data is governed by its own terms of service and privacy policy, which you accept when you connect it in WordPress. Redirect suggestions for trashed content are made on your site, without any external service.

== Changelog ==

= 3.0.0 =
* Complete rewrite on native WordPress APIs.
