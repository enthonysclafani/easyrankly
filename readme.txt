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

SEO, sitemap, redirects and custom code work with every theme. Multilingual needs a block theme: with a classic theme, contents, addresses, site title and tagline change with the language, but menus, widgets and texts set in the Customizer show the same text in every language.

= What does the "Everywhere" position of Custom code do? =

It runs a PHP snippet as soon as the plugins are loaded, on every request, admin included, like the functions.php of a theme: use it to add or remove hooks. It never runs on the Custom code screen, so a broken snippet can always be fixed there. What the snippet prints at that moment is dropped. If a snippet locks you out, add `define( 'EASYRANKLY_SAFE_MODE', true );` to wp-config.php: no snippet runs until you remove it.

= How are password-protected posts handled? =

They are noindex and left out of the sitemap. Their automatic description is empty, so their text never appears in the page source; a description you write for the post yourself is still used.

== External services ==

EasyRankly does not connect to any external service.

== Changelog ==

= 3.0.0 =
* Complete rewrite on native WordPress APIs.
