# EasyRankly SEO-set line-by-line audit notes

Read-only audit. Working tree treated as authoritative. 20 files, 9352 lines, all read via Read tool in chunks. No plugin files modified.

Date: 2026-09-15

## Coverage

All assigned files: **coperto** (every line read).

| path | lines | status |
|---|---|---|
| includes/breadcrumbs.php | 1082 | coperto |
| includes/canonical.php | 77 | coperto |
| includes/hreflang.php | 97 | coperto |
| includes/meta.php | 804 | coperto |
| includes/meta-render.php | 70 | coperto |
| includes/meta-visibility.php | 96 | coperto |
| includes/opengraph.php | 576 | coperto |
| includes/robots.php | 679 | coperto |
| includes/schema.php | 1086 | coperto |
| includes/schema-content.php | 970 | coperto |
| includes/schema-jsonld.php | 945 | coperto |
| includes/title-description.php | 244 | coperto |
| includes/special-meta.php | 188 | coperto |
| includes/sitemap/core.php | 1174 | coperto |
| includes/sitemap/image.php | 221 | coperto |
| includes/sitemap/news.php | 300 | coperto |
| includes/sitemap/video.php | 270 | coperto |
| includes/class-erankly-site-sitemaps-provider.php | 157 | coperto |
| includes/class-erankly-specialist-sitemaps-provider.php | 89 | coperto |
| includes/seo-state.php | 227 | coperto |

## Per-file notes

### includes/canonical.php (77)
Purpose: resolve current request canonical URL; cache per request.
Every line necessary except minor duplication with seo-state.php canonical resolution.
Comment quality: thin file-level doc; logic is self-explanatory.
Defects: static cache; author templates can recurse via `{{canonical_url}}`; paged search excluded so page 2 self-canonicals to page 1; date archives use `erankly_current_url()` (query junk); no `erankly_localize_url` in seo-state sibling path.

### includes/hreflang.php (97)
Purpose: print/clean hreflang alternates; separate navigable set.
Necessary. `erankly_get_navigable_hreflang_alternates` has no production caller in this plugin (tests + comment claim language nav).
Comment quality: good, especially navigable vs hreflang warning.
BCP47 regex is a reasonable subset; first-wins on duplicate tags.

### includes/meta-render.php (70)
Purpose: wp_head payload + attachment 301.
Necessary. Static `$rendered` can swallow a too-early call.
Attachment `parent` mode falls through to file URL when parent missing (undocumented).
Host compare ignores ports/www; CDN path uses `wp_redirect` (intentional).

### includes/meta-visibility.php (96)
Purpose: exclude flagged posts from search/archive main queries.
Necessary. Transient 1d + invalidation hooks in easyrankly.php.
Direct SQL `meta_value='1'` matches registered boolean storage.
Skips AJAX and REST-ish queries; `wp_doing_ajax()` means live-search AJAX ignores flags.
`$meta_id` unused (OK for hook signature).

### includes/special-meta.php (188)
Purpose: REST/Site Editor bridge for special-page SEO map.
Necessary as integration layer. REST schema/row defaults omit advanced robots fields that `erankly_sanitize_global_entity_meta` / `erankly_apply_global_entity_robot_row` support — Site Editor save replaces the full map and drops `index_directive` etc.
Multisite autoload documented. Single-site nests in `ERANKLY_OPTION`.
`register_setting('general', ...)` is a slightly odd group name.

### includes/seo-state.php (227)
Purpose: multilingual-neutral eligibility + canonical-is-self.
Necessary. Duplicates canonical resolution without `erankly_localize_url` / paged archive logic.
`sanitize_key($subtype)` lowercases.
`kind=other` and missing archive subtype are very permissive.
Identity: `get_schema_identity` is not this file; seo-state noindex for home uses `homepage` key — good — but `erankly_current_special_page_key()` (outside set) returns homepage only when `!is_singular() && is_front_page()`, so static front page never uses homepage special robots in robots.php while seo-state `kind=home` would.

### includes/title-description.php (244)
Purpose: title + meta description with template fallbacks.
Necessary. Static caches.
`is_singular()` runs before `is_front_page()`: static front page uses the **page** title/description, never special-page `homepage` templates. Posts-on-front correctly uses homepage.
Description generated fallback does not `wp_strip_all_tags` before `erankly_trim_text` (depends on helper).
`get_the_archive_title()` keeps “Category:” prefixes.
Both `pre_get_document_title` and `document_title_parts` hooked; parts rarely run once title is non-empty.

### includes/meta.php (804)
Purpose: meta key registry, REST registration, sanitizers, schema-block save guards, legacy social-image migration.
Necessary overall. Broken docblock indent on filters (66–72, 177–183).
`erankly_schema_block_has_content` only looks at `custom_json` — typed add-on fields without JSON are dropped.
`erankly_skip_null_schema_blocks_meta` returning `true` short-circuits `update_metadata` (correct WP contract).
Global `$GLOBALS['erankly_schema_blocks_previous']` is request-scoped stash; not thread-safe in theory, fine in PHP.
Frontend + `init` migration writes metadata on GET.
JSON-LD error static `$added` keeps only the first notice.
`erankly_decode_custom_json_ld` belongs conceptually with schema-jsonld.php (loaded from this file).
Comments: long but mostly load-bearing (import vs register vs legacy booleans).

### includes/opengraph.php (576)
Purpose: OG/Twitter tags, oEmbed JSON link, social image resolution including CDN reverse-map SQL.
Mostly necessary. `attachment_url_to_postid` + LIKE fallback is heavy for a head render.
`array_filter($tags)` drops `"0"`.
OG type `product` without product properties.
Static front page uses singular chain (not homepage special image) — same as titles.
`erankly_render_oembed_link` emits on every public URL using canonical; may 404 for archives.
`remove_action('wp_oembed_add_discovery_links')` at render time works because `wp_head` priority 1 vs core 10.
Twitter card default ignores a global setting; degrades to summary without image (good).
Term/user twitter has URL but no `_id` field (posts do).
Write-on-read legacy migration duplicated with meta.php.
Comments on CDN reverse-map are good.

### includes/robots.php (679)
Purpose: wp_robots overlay, robots.txt grouping, robots.txt rewrite fallback, specialist sitemap rewrites.
Necessary and relatively well-commented (pagination scopes, MS path prefix, group-scoped dedupe).
First-pass boolean noindex + later tri-state + global entity `index` can **unset** pagination `noindex`.
`max-snippet` / `max-video-preview` from settings are not integer-validated at output.
Preview builder uses `admin_url()` paths; live filter uses `site_url()` + `/wp-admin/` — can diverge if admin is on another host.
`erankly_register_rewrites` appends query_vars via anonymous filter every call (init + another bootstrap call).
`erankly_force_robots_txt_request` replaces **all** query vars (OK for robots.txt).
News sitemap type alias `news-sitemap` handled in core.php not here.

### includes/breadcrumbs.php (1082)
Purpose: HTML trail, BreadcrumbList, native `core/breadcrumbs` capture/preview, legacy block, shortcodes.
Necessary complexity for WP 7.0 native block vs legacy. Over-engineered vs a plugin that only shipped its own trail, but the Gutenberg/head-order problem is real.
`erankly_core_breadcrumbs_default_attributes` production-unused (tests only).
Visual skip of empty names vs `count($items)<2` checked before skip → 1-item nav possible.
JSON-LD `position` uses original index, so skipped crumbs leave gaps (1,3).
Empty last name → no `aria-current`.
Double `wp_kses`.
Home crumb always inserted; static front page correctly avoids Home→Home.
`when_visible` treats `has_shortcode` / `has_block` as proof of visibility (syntactic).
`render_block()` preview during schema build can enqueue/side-effect.
`easyrankly_breadcrumbs` BC alias is justified.
Comments: excellent (static front, when_visible, capture vs pending).

### includes/schema.php (1086)
Purpose: graph assembly, identity, Webpage/Article/FAQ/Service/LocalBusiness, custom blocks, prune/dedupe.
Necessary core. Thin wrappers 1007–1021 are unused aliases of sanitization.php matchers.
`erankly_schema_blogposting` is BC + tests.
`erankly_get_schema_identity()` only checks user ID > 0, not `get_userdata`; deleted user still yields Person, then dead fallback 536–549 emits org-named Person (contradicts comment at 378–383).
`array_filter` on nodes drops `0`/`false`.
`QAPage` forced to WebPage (honest incomplete).
SearchAction template always `/?s={search_term_string}`.
FAQPage + WebPage siblings (Google FAQ rich results largely retired — product choice).
Prune dangling refs only on automatic graph (documented).
`erankly_schema_block_field` `$raw_value` skips variable replace for JSON (replace happens in `erankly_replace_json_ld_variables` later).
`erankly_filter_empty_schema_values` is the safer cleaner; inconsistent use vs `array_filter`.

### includes/schema-content.php (970)
Purpose: detect FAQ/HowTo/Event/Video/Service from content/plugins.
Necessary for automatic rich results. HTML class regex is Yoast-era leftover (still useful).
HowTo uses first matching block only.
Event generic meta keys (`location`, `start_date`, `virtual`) are collision-prone.
Event datetimes: `strtotime` + `gmdate` can shift local TEC dates.
Video early-exit markers omit `youtube-nocookie.com` → **schema skips nocookie-only embeds** that the video sitemap LIKE clause includes. Extractor itself supports nocookie; the haystack gate does not.
`erankly_schema_service_for_page` is filter-only (no built-in service builder) — OK extension point.
Accordion FAQ requires `eranklyGenerateFaqSchema` — Gutenberg/frontend aligned if attribute saved.
Comments generally good; filter docblocks sometimes mis-indented (875–878).

### includes/schema-jsonld.php (945)
Purpose: JSON-LD validation/merge/REST schema + LocalBusiness admin pickers + admin-bar warnings.
File is two concerns. Validation/merge: necessary, loaded from meta.php for save-time.
LocalBusiness page/site choice (~704–943) is admin UI loaded on every frontend request via meta.php — bloat/placement.
Probe `{{var}}` → `x` rejects unquoted numeric templates (`"price": {{price}}`).
Save-valid JSON can still fail at render if replaced values break JSON (quotes in titles) — graph node silently omitted.
`erankly_is_valid_custom_json_ld` / `erankly_normalize_custom_json_ld_data` are documented shims (tests).
Admin-bar merge warnings need `erankly_get_schema_graph()` to have run first; if graph built only in `wp_head` priority 1 and admin bar already rendered… classic themes print admin bar in footer so OK; some setups print bar earlier.
`erankly_get_local_business_page_id` unused `$post_id` param.
Comments on Gutenberg REST object schema are necessary.

### includes/sitemap/core.php (1174)
Purpose: native wp_sitemaps integration, specialist response, image URL harvest, canonical-exclusion caches, eligibility SQL.
Necessary, large. `erankly_news_sitemap_query` is never set anywhere in the plugin — dead branch in `erankly_filter_sitemap_posts_where`.
`erankly_get_non_self_canonical_*` uses `posts_per_page => -1` / all terms — cached 1h, still a cold-cache stampede.
SQL `NOT IN (ids)` unbounded if many custom canonicals.
`erankly_filter_core_sitemap_posts_pre_url_list` lastmod: `strtotime($post->post_modified_gmt)` without UTC (unlike `erankly_format_sitemap_gmt_date`).
User exclusion meta_query still used; SQL helper used for author stats.
`erankly_get_sitemap_exclusion_meta_query` for users with disable_sitemap default true is not used that way (caller passes false).
ETag/304 output buffer: solid.
Comments: high quality (virtual homepage, legacy vs tri-state SQL).

### includes/sitemap/image.php (221)
Purpose: image sitemap candidates + XML.
Necessary when feature on. Count is **posts** not image URLs (pagination by post).
Filter `erankly_image_sitemap_url` `loc` is ignored at render (always `get_permalink`).
Empty slice page returns `''` without caching → repeat cold work for bogus pages.
Per-post image discovery runs at candidate filter time **and** again at XML render.
LIKE `% <img %` false positives accepted then filtered.
Comments good (Google 1000 images, no title element).

### includes/sitemap/news.php (300)
Purpose: Google News 48h window.
Necessary when enabled. `erankly_get_news_sitemap_xml` does **not** re-check enable flag (wrapper does).
Empty pub name caches `''` for an hour (404 until expiry after setting a name).
DB error returns uncached empty (retry OK).
Language = first 2 chars of locale (OK-ish; `zh_CN` → `zh`).
Off-site `loc` from filter allowed if absolute http.
Comments good.

### includes/sitemap/video.php (270)
Purpose: video sitemap.
Necessary when enabled. Unlike image, if all posts on a page lose entries, still caches an empty `<urlset>` 200.
`get_the_excerpt` in sitemap context can be heavy.
`mb_substr` vs `substr` UTF-8 split.
Load `erankly_load_video_helpers()` at file include.
Alias `erankly_extract_sitemap_video_urls` lives in helpers (not this file).

### includes/class-erankly-site-sitemaps-provider.php (157)
Purpose: CPT archives + `erankly_sitemap_site_urls`.
Necessary. **Subdirectory bug**: origin check uses scheme+host+port only, then `str_starts_with($normalized, $home_origin . '/')` — URLs on the same host outside the WP path are accepted.
`url_to_postid` to dedupe Woo archive-as-page is expensive and flaky on some permalinks.
`get_max_num_pages` of 0 when empty: `ceil(0/N)=0`.
`strtotime` twice on lastmod.

### includes/class-erankly-specialist-sitemaps-provider.php (89)
Purpose: index entries for news/image/video XML served via template_redirect.
Necessary. News omits `>0` guard (harmless, ceil 0). Image/video omit lastmod in index (news has it).
`get_url_list`/`get_max_num_pages` stubs documented.
Provider name `erankly` → core still lists these via overridden `get_sitemap_entries`.

## Findings (canonical list for the report)

See final agent message for SEO-* IDs. This file is the working notebook.

## Settings keys written here / read here

Written:
- `ERANKLY_SPECIAL_META_OPTION` / `global_special_meta` (special-meta.php)
- `erankly_legacy_social_image_migrated` (meta.php)
- post/term `_erankly_og_image_url`, `_erankly_twitter_image_url`, delete `_erankly_social_image_url` (migration)
- transients: visibility, sitemap caches, invalid JSON-LD notice

Read (non-exhaustive, from this set): enable_breadcrumbs, breadcrumb_jsonld_mode, simplified_mode, paginated_title_format, attachment_redirect, default_og_*, default_twitter_*, twitter_site, default_social_image_url, default_og_image, schema_identity, schema_person_user_id, enable_website_search_action, enable_local_business, local_business_*, organization_*, global_schema_blocks, news_*, enable_*_sitemap, robots_*, noindex_paginated*, nofollow_paginated, noindex_feeds, robots_txt_extra, enable_sitemap (via helpers).

Homepage special-page keys are **written** via special-meta and **not applied** to a static front page in title/OG/robots because `is_singular()` wins and `erankly_current_special_page_key()` excludes singular front.

## Duplicate paths

- Canonical: `erankly_get_canonical` vs `erankly_get_object_seo_state` vs sitemap non-self-canonical replace (term/user have dedicated sitemap replacers; posts use `erankly_replace_variables`).
- Breadcrumbs: EasyRankly builder vs captured `core/breadcrumbs` items vs preview `render_block`.
- Schema video vs sitemap video: shared extractor, **different** candidate gates.
- Robots: boolean first pass + global entity row + object tri-state (overlapping).
- Social images: OG vs Twitter vs special-page `social_image_url`.
