# EasyRankly expansion notes (read-only)

Plugin: `/Users/enthonysclafani/Studio/easyrankly/wp-content/plugins/easyrankly`
Date: 2026-09-15
Method: re-read `tests/test-*.php` with Read+offsets; reconstruct M/L/N and leftover SEO from plugin source. No plugin/test/git writes.

**Progress this pass**
- Test files fully re-read: **50 / 50**
- Last SEO ID assigned: **SEO-070** (no padding; 038–070 are real leftovers not already SEO-001–037)
- M-01–M-18: **yes (complete)**
- L-01–L-16: **yes (complete)**
- N-01–N-12: **yes (re-derived)** — original pass-1 write-up was insufficient; each is reconstructed from cited source with evidence

TST-001–040: all IDs that cite `tests/test-*.php` are verified. Remaining unread surfaces are supporting harnesses only (`live-schema-audit.php`, `tests/js/*.cjs`, `schema-recheck.md`).

---

## 1. Test file reviews

### tests/test-breadcrumb-registration.php (28 lines, reviewed 1–28)
Purpose: legacy `easyrankly/breadcrumbs` inserter when `core/breadcrumbs` is deregistered at init 10 (env `ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS=1` before bootstrap).
Issues: TST-011
Gaps: default CI never sets the env; the only proof that `supports.inserter === true` after real deregister is skipped.

### tests/test-seo-state.php (84 lines, reviewed 1–84)
Purpose: `erankly_get_object_seo_state()` / `erankly_object_seo_state_is_noindex()` for `kind=posts_page` vs blog/page noindex and missing-page fail-closed.
Issues: reviewed, no issues in assertions that exist
Gaps: no `kind=home` / static front / author / date / search / `kind=other`; no `erankly_localize_url` vs head canonical (SEO-009). See TST-042.

### tests/bootstrap.php (96 lines, reviewed 1–96) — not a test-*.php case
Purpose: load plugin as MU-plugin; optional core-breadcrumbs deregister; `erankly_tests_set_settings` writes site option on MS.
Issues: TST-040 (sound, not a defect)
Gaps: none for bootstrap itself.

### tests/test-response-handlers.php (97 lines, reviewed 1–97)
Purpose: feed robots early-return + hook wiring; `erankly_prepare_xml_response` reject/accept/304.
Issues: TST-003
Gaps: `erankly_send_feed_robots_header()` never inspected via `headers_list()`; 304 is builder-only, not core sitemap `header()`.

### tests/test-lifecycle-rest.php (127 lines, reviewed 1–127)
Purpose: `erankly_rest_save_special_pages()` persist/ignore-scalar; route + `manage_options` permission; dispatch via server.
Issues: reviewed, no issues
Gaps: does not assert advanced robots (`index_directive`, `max_snippet`) survive a Site Editor-shaped save (SEO-004). See TST-041.

### tests/test-canonical.php (139 lines, reviewed 1–139)
Purpose: `erankly_get_canonical()` singular custom/permalink, front, posts page, category ± meta, search, 404. Separate-process for static cache.
Issues: TST-038
Gaps: no author, date, CPT archive, paged singular, attachment; no `{{canonical_url}}` on author (SEO-007); no paged search (SEO-020).

### tests/test-lifecycle-cron.php (140 lines, reviewed 1–140)
Purpose: migration/import cron callbacks: missing/mismatched job, adapter-unavailable pause, missing spool fail.
Issues: reviewed, no issues
Gaps: no successful batch that writes settings/meta; no purge-then-fail restore (INF-001). See TST-050.

### tests/test-opengraph-migrations.php (143 lines, reviewed 1–143)
Purpose: legacy `_erankly_social_image_url` split; CDN intermediate reverse-map + collision prefer original path.
Issues: reviewed, no issues
Gaps: does not prove migration is **not** invoked from `wp_head` / meta-box GET (SEO-006).

### tests/test-js-contracts.php (148 lines, reviewed 1–148)
Purpose: string-locks PHP↔JS identifiers; exec three `.cjs` probes if Node exists.
Issues: TST-012, M-18
Gaps: unprobed JS listed in TST-012; probes only assert exit 0.

### tests/test-rest-user-search.php (159 lines, reviewed 1–159)
Purpose: `/erankly/v1/users/search` 401/403, no email leak, email-match without return, cap 20, MS site-scope.
Issues: reviewed, no issues
Gaps: no pagination/`offset`; no empty `q` contract.

### tests/test-local-business-rest.php (174 lines, reviewed 1–174)
Purpose: LB sites/pages REST: anon/editor reject, bound, unknown blog 404, current-site pages, paginate/search, peeked selected page.
Issues: reviewed, no issues
Gaps: unused `$post_id` on `erankly_get_local_business_page_id()` (L-12 / SEO-070) not asserted.

### tests/test-settings-and-robots-regressions.php (177 lines, reviewed 1–177)
Purpose: robots.txt group parse/render; collection-key list; custom-code merge clears emptied collections; features merge preserves; hidden disable_sitemap.
Issues: reviewed, no issues (M-17 merge path **is** asserted here)
Gaps: sanitizer called **without** merge on a partial map is untested (the wipe if REST/import skips merge).

### tests/test-compatibility.php (178 lines, reviewed 1–178)
Purpose: WC absent; product-schema gates; reviews/brand without WC runtime; sitemap suppress; legacy enable=suppress; hook aliases; no-conflict head.
Issues: TST-014
Gaps: conflict notice never emitted; owner memoised at bootstrap.

### tests/test-hreflang-canonical.php (202 lines, reviewed 1–202)
Purpose: BCP47 validate/clean; first-wins dup keys; distinct navigable filter; render silent without provider; real `/page/2/` paged archive canonical.
Issues: reviewed, no issues
Gaps: no live multilingual provider → real `<link rel="alternate">`; navigable API only via filter (L-07).

### tests/test-helpers-video.php (216 lines, reviewed 1–216)
Purpose: extract YouTube/Vimeo/iframe/html5; nocookie; aliases; embed/content/thumb.
Issues: reviewed, no issues
Gaps: no `youtube.com/shorts/` (L-13); no schema haystack vs sitemap LIKE (SEO-008).

### tests/test-network-reset.php (221 lines, reviewed 1–221)
Purpose: tokenless save/continue; MS snapshot CAS/delete/fail/continue+schedule; wall-clock skip.
Issues: reviewed, no issues
Gaps: no live `switch_to_blog` worker wiping another site’s usermeta (INF-002).

### tests/test-uninstall-cleanup.php (229 lines, reviewed 1–229)
Purpose: transient pairs/orphans, ext-cache, notices, DELETE failure, source regex (no global flush), MS super-admin notices.
Issues: reviewed, no issues
Gaps: `get_users( blog_id => 0 )` (M-12) only string-locked, not executed as a large-network cost.

### tests/test-multilingual-registry.php (242 lines, reviewed 1–242)
Purpose: register/validate/close/boot/conflict/stored choice/preflight/diagnostics/singleton-closed/admin notices.
Issues: reviewed, no issues
Gaps: `localize_url` exception → empty URL (INF-007) not asserted; context memo before `close_and_boot` not asserted.

### tests/test-robots-output.php (244 lines, reviewed 1–244)
Purpose: paginated request helpers; wp_robots post/global/paged/private; entity/object overrides; robots.txt filter/preview; rewrite register; virtual-files noop.
Issues: TST-026 (paged archive simulated via `$wp_query->is_paged`)
Gaps: no static-front homepage special robots (SEO-002); no `index` object unsetting pagination noindex (SEO-011); force_robots_txt wipe (SEO-042) untested.

### tests/test-sitemap.php (246 lines, reviewed 1–246)
Purpose: core filters remain when module off; post URL cache; exclude password/noindex/canonical; explicit index vs disable; author noindex; term `{{term_url}}`; user stats generation; relative img; site provider extra URL allowlist.
Issues: TST-023
Gaps: no subdirectory origin (SEO-005); no buffer-start when `sitemap` query var set.

### tests/test-redirects-normalizer.php (264 lines, reviewed 1–264)
Purpose: path/query/regex/wildcard/hash/compare/evaluate/target safety/status helpers.
Issues: reviewed, no issues
Gaps: runner `wp_safe_redirect` / host allowlist not this file (TST-004).

### tests/test-helpers-cache.php (282 lines, reviewed 1–282)
Purpose: sitemap URL pretty/plain; cache key version; flush once/variadic; skip revision/draft/zero; status ignore; meta allowlists; redirect generation rotate; after-hook.
Issues: TST-036
Gaps: no published-post **positive** flush; no status publish→publish bump.

### tests/test-opengraph-output.php (297 lines, reviewed 1–297)
Purpose: simplified social text; OG/Twitter meta/defaults/trim/card/site/image/alt; special-page search image; render tags + oEmbed link.
Issues: TST-037
Gaps: no featured/`_erankly_og_image_id`/content-image/term/author fallbacks; no static-front homepage social (SEO-003); no `og:type=product` properties (SEO-034).

### tests/test-helpers-content-defaults.php (313 lines, reviewed 1–313)
Purpose: placeholders/templates; org/website name/logo; special-page keys; `erankly_current_special_page_key()` search/404/author/singular/plain-permalink miss→homepage.
Issues: reviewed, no issues
Gaps: static front Page is singular → key `''` (SEO-001/002) not asserted here.

### tests/test-reset-actions.php (313 lines, reviewed 1–313)
Purpose: reset debug log, redirect, cap/page/nonce guards, site wipe (skip SQLite), uninstall include (DROP rewritten).
Issues: TST-025
Gaps: DROP TABLE never executed on SQLite CI.

### tests/test-admin-meta-box.php (320 lines, reviewed 1–320)
Purpose: register box/tax fields; placeholders; simplified vs advanced render; robots selects; schema builder index; save nonce persist/clear.
Issues: reviewed, no issues
Gaps: no `_erankly_exclude_from_news` delete when news off (ADM-002); no primary-terms Gutenberg gap (ADM-001). See TST-049.

### tests/test-migrations-runtime.php (330 lines, reviewed 1–330)
Purpose: table_exists; import redirect prepare (exact/invalid/contains→regex/wildcard/410/visibility); variable convert/diagnostics; backup read/create/state/restore-missing.
Issues: reviewed, no issues
Gaps: AIOSEO `#token` rewrite of URL fragments (INF-004) not asserted as a **failure** — convert test preserves `#unknown` but not `#product` in a URL.

### tests/test-helpers-template-variables.php (345 lines, reviewed 1–345)
Purpose: replace/exclude/unknown; JSON-LD quote escape; get/preview values; sample post/term.
Issues: TST-007
Gaps: named “escapes_backslashes_and_newlines” uses default `'Contenuto di prova'` — no `\`, `"`, `\n`.

### tests/test-title-description.php (357 lines, reviewed 1–357)
Purpose: template resolve/collapse; document title + parts; get_title/description fallbacks; paginated suffix via mutated query.
Issues: TST-026
Gaps: no static front Page using homepage special title (SEO-001); no term archive “Category:” prefix (SEO-041).

### tests/test-woocommerce.php (359 lines, reviewed 1–359)
Purpose: Product mapper via doubles: fields, brand, aggregateRating, reviews, simple/variable offers, wrapper.
Issues: TST-028
Gaps: Review **without** rating still emitted (M-08) — tests always add `rating` meta; no Product JSON-LD on a product permalink.

### tests/test-sitemap-providers.php (399 lines, reviewed 1–399)
Purpose: site provider max pages (including 0); taxonomy URL cache; buffer ignore on non-sitemap; core response XML/304 (skip if headers sent); special-page hide; posts max pages; term query args; image collection; post-type name filter; user canonical vars; eligibility; GMT formatter.
Issues: TST-024
Gaps: buffer **start** when `sitemap` query var is set is absent; 304 skipped when `headers_sent()`; no subdirectory origin (SEO-005). `get_max_num_pages()===0` is asserted (M-15), not treated as a bug.

### tests/test-helpers-global-meta.php (500 lines, reviewed 1–500)
Purpose: post/term string/bool accessors; primary term; tri-state robots + legacy boolean; inherit; linked global maps; schema types; special meta; image block IDs.
Issues: TST-008
Gaps: inherit + legacy `_erankly_noindex=1` is not asserted. Production (`global-meta.php:240-241`) returns `'inherit'` when stored value is inherit and **blocks** the legacy fallback.

### tests/test-schema.php (550 lines, reviewed 1–550)
Purpose: JSON-LD validate; registered meta; REST schema-blocks round-trip/preserve-invalid/XSS HEX_TAG; merge; event finalize; HowTo/FAQ; QAPage→WebPage; type list; LB gaps; video names.
Issues: TST-015, TST-016, TST-017
Gaps: `assertIsArray` + `assertNotNull` tautology (226-227); type-list `count===2` without canonical values (491-494); `remove_all_filters('erankly_faq_items')` (471-473).

### tests/test-schema-content.php (473 lines, reviewed 1–473)
Purpose: FAQ from Yoast/RM/HTML/accordion; HowTo blocks/duration; event types/TEC/generic/virtual; VideoObject from content; Service filter; block walk.
Issues: reviewed, no issues
Gaps: HowTo only first matching block (SEO-027) not asserted as a limit; no nocookie-only content vs schema haystack (SEO-008); no `youtube.com/shorts/` (L-13); Event `strtotime`+`gmdate` TZ (SEO-025) not asserted.

### tests/test-sitemap-specialists.php (473 lines, reviewed 1–473)
Purpose: specialist index (news/image/video) pretty/plain; news window/exclude/xml/404; image/video ids+xml.
Issues: reviewed, no issues
Gaps: empty video urlset 200 vs image empty (SEO-016) not contrasted; news empty publication name cached 1h (SEO-030) not asserted; `erankly_news_sitemap_query` dead flag (SEO-043) not asserted; image `loc` filter ignored (SEO-015) not asserted.

### tests/test-custom-code-boundaries.php (458 lines, reviewed 1–458)
Purpose: REST/form persist custom code; `unfiltered_html` cannot replace/enable; import start gate; worker budgets; forged `legacy_migrated`; MS site-admin 403 vs super-admin save.
Issues: reviewed, no issues
Gaps: overlaps `test-robots-and-custom-code.php` (TST-030). No restore purge-then-fail (INF-001).

### tests/test-migration-internals.php (426 lines, reviewed 1–426)
Purpose: reflection on upload-store `is_staged_path`/`delete_staged_path`, job-runner lock/warning/discard helpers, and dead `ERankly_Migration_Adapter` base `storage_definitions()`/`supported_versions()`.
Issues: reviewed, no issues (skips when private storage is unavailable — TST-034)
Gaps: `is_staged_path` reject for token-not-32-hex uses `z`×32 (non-hex) — good; no `..` / symlink / prefix-mismatch basename; `owns_lock` created+TTL path is covered. Public `delete()` contract is in runner tests, not here.

### tests/test-admin-settings-panels.php (477 lines, reviewed 1–477)
Purpose: settings panels (features/custom-code/general/social/schema/sitemap/settings/advanced), tab normalizer, nav subtabs, network/site save capability dies, settings page render + simplified-mode tab collapse.
Issues: reviewed, no issues in assertions that exist
Gaps: `attachment_redirect` only asserted as a field name (256) — no parent/file redirect (TST-005). `register_setting` group is `'erankly'` here; does not catch `ERANKLY_SPECIAL_META_OPTION` registered on `'general'` (SEO-053). Page render overlaps `test-admin-menu.php` (TST-030).

### tests/test-breadcrumbs-native.php (482 lines, reviewed 1–482)
Purpose: `core/breadcrumbs` render cycle — attribute validation, `render_block_data` / `pre_render_block` / context filters, first-vs-second trail, shortcode wins, HTML label normalisation, javascript: URL reject, single BreadcrumbList in graph.
Issues: TST-011 (skip when `render_block_core_breadcrumbs` missing), TST-035 (uses `<!-- wp:breadcrumbs -->` vs `core/breadcrumbs` comment form)
Gaps: skip hides the whole class on WP without the block; no author/date/search native trail; `when_visible` emit gate is in `test-breadcrumbs.php` not here.

### tests/test-admin-renderers.php (544 lines, reviewed 1–544)
Purpose: field/schema/custom-code/media/org/LB/opening-hours/global-meta/social/special-page renderers, section stack, nav icons.
Issues: TST-031 (opening-hours closed CSS is a substring on `admin-settings.css:307-321`, not a DOM match)
Gaps: `classList.toggle("is-closed")` unprobed (TST-013). Section stack LIFO is in-memory only. No assertion that incomplete-LB notice is omitted when address is complete.

### tests/test-redirects-repository.php (551 lines, reviewed 1–551)
Purpose: table name, exact cache ± miss, CRUD, upsert, list/search/paginate, export cursor, hit increment (sample=1), pattern buckets, runtime compile/persist/invalidate, bulk defer, `normalize_data` / search clause.
Issues: reviewed, no issues
Gaps: default hit sample-rate miss (not forced 1) untested (TST-004 family). No concurrent create collision. `query_mode=exact` / case-sensitive find-by-hash not contrasted.

### tests/test-admin-menu.php (579 lines, reviewed 1–579)
Purpose: admin bootstrap hooks, tab resolve/canonicalize, settings/meta-box wrappers, import-export/reset dispatch, plugin action links, enqueue surfaces (settings/classic/block/site editor).
Issues: reviewed, no issues
Gaps: canonicalize only asserts a **canonical** tab does **not** redirect (245-272); a non-canonical `erankly_tab` Location is never asserted. Site Editor panels require a block theme — `use_site_editor` is only the false path. Overlaps settings-page render (TST-030).

### tests/test-migrations-runner.php (586 lines, reviewed 1–586)
Purpose: adapter loader, manager/presenter, upload-store reserve/TTL/delete/prune/HTTP-fail, purge-legacy options, job-runner start/cancel/dry-run Yoast complete + conflict.
Issues: reviewed, no issues
Gaps: dry-run only — no import that writes settings/meta/redirects (same family as TST-046). `purge_legacy_state` DROP of retired tables still cannot complete on SQLite (comment 454-456). Successful HTTP upload (`is_uploaded_file`) is not staged.

### tests/test-robots-and-custom-code.php (650 lines, reviewed 1–650)
Purpose: sanitizer XSS/indexifembedded/UTF-8 truncate; custom-code budgets, legacy overflow, `unfiltered_html` gates; frontend output exclusions (feed/admin/ajax/cron/robots/preview/embed/trackback/REST/XMLRPC); force-robots.txt including subdirectory; print-once; targeting dispatcher; MS super vs site admin.
Issues: TST-047 (locks SEO-042 query wipe)
Gaps: `erankly_force_robots_txt_request` is asserted to replace `$wp->query_vars` with **only** `robots=>1` (412-413) — the wipe is treated as correct. Body-open/close print-once not separately asserted (head only). Overlaps custom-code-boundaries (TST-030).

### tests/test-import-export.php (713 lines, reviewed 1–713)
Purpose: max-bytes filter, bounded read, JSON memory profile, export streams/backup doc, import job start/complete/tamper-fail, migration report/panel/notice render, deprecated wrappers.
Issues: TST-027 (filter 2048 asserted as `>=1024 && <=2048` — memory cap can swallow the filter)
Gaps: export download with valid nonce never streams; HTTP import start is fail-paths only; `erankly_import_third_party('unknown')` is the empty wrapper, not a mapped Yoast/AIOSEO import.

### tests/test-redirects-runtime.php (878 lines, reviewed 1–878)
Purpose: activator/boot/DB upgrade, runner 410/self-loop/empty/admin-skip/skip-request/advanced-match/loop/allow-host, REST toggle/delete/test/dispatch 403, admin labels/form/table/notices/handle_actions nonce-die.
Issues: TST-004, TST-020, TST-021, TST-022
Gaps: no matching 301/302/308 `wp_safe_redirect` (production runner ~82). Hit sample forced to 1. REST create/update/list absent. Admin save/toggle/delete success + Location untested. M-07 (`manage_options` skip on frontend) is asserted as intended (255-272) rather than challenged.

### tests/test-breadcrumbs.php (1080 lines, reviewed 1–1080)
Purpose: trail items (post/term/search/404/static front), render/echo/filter, JSON-LD positions, emit-mode gates, visibility (theme/shortcode/legacy block/core), name filters, native schema match (home/current/taxonomy/archive+paged), legacy inserter sync.
Issues: TST-018, TST-019, TST-035
Gaps: `when_visible` (414-423) equates two getters without a rendered trail. Alias test (345-350) compares both functions with no `go_to` — both can be `''`. Mixed `wp:core/breadcrumbs` vs `wp:breadcrumbs` comment forms. Date/author/CPT-archive EasyRankly (non-native) trails unasserted. Overlaps native file (TST-030).

### tests/test-lifecycle.php (1129 lines, reviewed 1–1129)
Purpose: runtime-state seed/memo, rewrite generation/signature, network site ID keyset + CLI limit, LB pages migration (batch/path-change/lock/CAS), bootstrap hooks, activate, retired-key prune, lazy rewrite flush, sitemap-visibility handlers, deactivate, cancel network reset; MS-required live LB map.
Issues: reviewed, no issues
Gaps: MS tests skip on single-site (TST-034). Fixture blogs table is not a live `switch_to_blog` target — those paths only run in `@group ms-required`. `erankly_update_plugin_option` dead autoload ternary (M-01) not asserted. Network deactivate-all-sites path is not walked on single-site.

### tests/test-schema-jsonld.php (1143 lines, reviewed 1–1143)
Purpose: merge/log/admin-bar, type suggestions, LB page id/choices/pagination, JSON-LD shims, empty-filter/prune/type-filter, identity/org/person/website/webpage/article/service/LB/hours, automatic graph, disabled mode, render script, global targeting.
Issues: TST-029 (`logs_once_when_debug` never defines/undefines `WP_DEBUG`)
Gaps: unused `$post_id` on `erankly_get_local_business_page_id()` (L-12 / SEO-070) — tests call with no arg. `QAPage`→`WebPage` (L-14) unasserted. Encode-false empty script (L-10 / SEO-039) unasserted. YouTube Shorts (L-13) not here.

### tests/test-meta-helpers.php (1153 lines, reviewed 1–1153)
Purpose: importable keys, schema-blocks null skip, REST stash, legacy social-image migrate, primary-terms sanitize, JSON-LD validate/decode/notice, `erankly_render_head` once, attachment-redirect **none**, exclusion meta_query, special-meta REST, localized-value CAS, global/term/schema helpers, current_url, targeting, SEO text normalize, feature-module registry, settings migrations, JSON-LD variable escape, custom-code boot/legacy migrate, reset notices/guards.
Issues: TST-005 (attachment redirect only `none`; parent/file+exit untested)
Gaps: `erankly_current_url()` (631-641) uses a pretty permalink with no query — does not catch M-05 / SEO-036 query strip. `update_post_metadata` null→true short-circuit (M-13) only via `skip_null_schema_blocks_meta` helper, not a real `update_post_meta(null)` persist. Special-meta `register_setting('general')` (SEO-053) unasserted.

### tests/test-migrations-adapters.php (1322 lines, reviewed 1–1322)
Purpose: probe-adapter helpers (warnings, batches, robots, social, schema, fingerprint); Yoast option+meta+premium redirects; Rank Math option+meta; SEOPress option+meta+post-meta redirects; AIOSEO options + v3 meta + `map_row` (v4 columns without a table).
Issues: TST-009
Gaps: Rank Math `redirect_records` empty-without-table asserted as success (854-861). AIOSEO `content_batch` / `redirect_records` empty-without-`aioseo_posts`/`aioseo_redirects` (1137-1141, 1305-1309). A broken SQL mapper for those tables stays green. Yoast table-less redirects **are** covered via options.

---

## 2. TST-001–040 verification + new TST-041+

### Verified this pass (file re-read)
- **TST-001** — keep. `phpunit.xml.dist` excludes `live-schema-audit.php`. Not re-read line-by-line this pass (see remaining).
- **TST-003** — keep. `test-response-handlers.php:27-67` calls sender, never inspects headers.
- **TST-007** — keep. `test-helpers-template-variables.php:107-114`.
- **TST-011** — keep. `test-breadcrumb-registration.php:12-15` + `bootstrap.php:32-47`.
- **TST-012** — keep. `test-js-contracts.php:20-97` string-contains; probes 100-145 exit 0 only.
- **TST-014** — keep. `test-compatibility.php:148-177` comments admit memoised owner.
- **TST-023** — keep. `test-sitemap.php:38-43` assumes default-off.
- **TST-025** — keep. `test-reset-actions.php:171-178` SQLite skip; uninstall rewrites DROP.
- **TST-026** — keep. title-description 342-355 and robots-output 104-116 mutate query flags.
- **TST-028** — keep. doubles + no product URL graph.
- **TST-036** — keep. cache tests only skip non-public.
- **TST-037** — keep. OG image only explicit URL + default setting.
- **TST-008** — keep. `test-helpers-global-meta.php:129-134` stores inherit and asserts inherit; production `includes/helpers/global-meta.php:240-241` returns inherit and skips legacy. Risky pair inherit+`_erankly_noindex=1` untested.
- **TST-015** — keep. `test-schema.php:226-227` `assertIsArray` then `assertNotNull`.
- **TST-016** — keep. `test-schema.php:491-494` only `count===2`.
- **TST-017** — keep. `test-schema.php:471-473` `remove_all_filters('erankly_faq_items')`.
- **TST-024** — keep. `test-sitemap-providers.php:178-185` only non-sitemap buffer; 198-211 304 skipped when `headers_sent()`.
- **TST-038** — keep. canonical file holes confirmed.
- **TST-040** — keep as non-defect. bootstrap MS helper is correct.
- **TST-004** — keep. `test-redirects-runtime.php:198-272` covers 410 / self-loop / empty / admin skip; no Location for 301/302/308.
- **TST-020** — keep. `test-redirects-runtime.php:144-154` `has_action('parse_request')` / `rest_api_init` without callback.
- **TST-021** — keep. `test-redirects-runtime.php:840-877` nonce-only `handle_actions`.
- **TST-022** — keep. REST 355-451 toggle/delete/test/dispatch 403; no create/update/list.
- **TST-027** — keep. `test-import-export.php:118-131` filter 2048, assert range not exact.
- **TST-031** — keep. `test-admin-renderers.php:307-321` CSS substring.
- **TST-005** — keep. `test-meta-helpers.php:225-244` only `attachment_redirect=none`.
- **TST-009** — keep. Rank Math redirects 854-861 and AIOSEO batches 1137-1141 / 1305-1309 empty-without-table.
- **TST-018** — keep. `test-breadcrumbs.php:414-423` `should_emit === has_visible` without a trail.
- **TST-019** — keep. `test-breadcrumbs.php:345-350` alias vs alias, no `go_to`.
- **TST-029** — keep. `test-schema-jsonld.php:81-103` never toggles `WP_DEBUG`.
- **TST-035** — keep. Mixed `wp:core/breadcrumbs` (479-950) vs `wp:breadcrumbs` (989).

### Pending re-read (supporting harnesses only; not `test-*.php`)
TST-002 (breadcrumbs-block-probe.cjs), TST-006 (settings-autosave-probe.cjs), TST-010/032 (live-schema-audit.php), TST-013 (local-business-widget-probe.cjs), TST-033 (schema-recheck.md), TST-039 (schema-jsonld.js). TST-030 duplicate-bloat stands as a suite observation.

### TST-041 — medium — missing-risky-branch
- file: `tests/test-lifecycle-rest.php:41-67`
- why: Saves `title`/`noindex` only. Production Site Editor schema omits advanced robots and replaces the map (SEO-004). A wipe of `index_directive` would stay green.
- action: Round-trip a map that already has `index_directive` / `max_snippet` through the REST save.

### TST-042 — medium — missing-risky-branch
- file: `tests/test-seo-state.php:1-84`
- why: Only `posts_page`. `kind=home` vs static front, `kind=other` permissive public, archive subtype, and canonical-without-`erankly_localize_url` are untested.
- action: Add home/static-front and `kind=other` cases; compare state canonical to `erankly_get_canonical()`.

### TST-043 — medium — missing-risky-branch
- file: `tests/test-helpers-video.php:27-38` vs `includes/helpers/video.php:17-19`
- why: Watch/youtu.be/embed/nocookie covered. `youtube.com/shorts/{id}` is not in the extractor regex (L-13).
- action: Assert shorts URL extraction or document as unsupported.

### TST-044 — medium — missing-risky-branch
- file: `tests/test-woocommerce.php:179-204` vs `includes/compatibility-woocommerce.php:134-154`
- why: Review fixture always sets `rating`. Production still appends a Review node when rating is missing (M-08).
- action: Approved review with no rating meta; assert node exists and `reviewRating` is absent.

### TST-045 — medium — missing-risky-branch
- file: `tests/test-admin-meta-box.php:272-318`
- why: Save tests title only. ADM-002 (`_erankly_exclude_from_news` deleted when news sitemap off) is unasserted.
- action: Store exclude, save with news disabled, assert meta survives.

### TST-046 — low — missing-risky-branch
- file: `tests/test-lifecycle-cron.php:56-139`
- why: Only reject/pause/fail. A successful settings/meta batch never runs.
- action: Fixture a dry-run or tiny spool through one successful process().

### TST-047 — medium — locks-production-defect
- file: `tests/test-robots-and-custom-code.php:402-419` vs `includes/robots.php:618-645`
- why: `erankly_force_robots_txt_request` is asserted to set `$wp->query_vars` to **exactly** `array( 'robots' => '1' )`. That locks the query-wipe (SEO-042 / H-10) as the intended contract. A merge-only fix would fail this test.
- action: Assert `robots` is set **and** pre-existing query vars survive (or change the test when production merges).

---

## 3. Pass-1 medium findings (full records)

### M-01 — medium — dead-code
- file: `easyrankly.php:157-171`
- why: `erankly_update_plugin_option()` handles `ERANKLY_OPTION === $key` in the first branch via `erankly_update_plugin_settings()`. The else branch still passes `ERANKLY_OPTION === $key` as the autoload argument to `update_option()`. That ternary is always false in the else branch, so the comment “autoload the settings array” is a lie and the third argument is always `false`.
- action: Drop the ternary; pass `false` explicitly or remove the dead comment.

### M-02 — medium — api-footgun
- file: `includes/compatibility.php:145-152`
- why: Legacy filter `erankly_enable_sitemaps_with_external_seo` is documented as enable-named but its boolean is the **suppression** state (`return (bool) apply_filters( ..., $suppress )`). Returning `true` suppresses sitemaps. Confirmed by `test-compatibility.php:111-123`. Same defect as INF-041.
- action: Keep the hook for BC; document loudly; prefer `erankly_suppress_sitemaps_with_external_seo`.

### M-03 — medium — request-cost
- file: `includes/compatibility-legacy.php:13-95`
- why: `erankly_register_legacy_developer_api_hook_aliases()` runs at file load and `add_filter`s **60** canonical hooks (lines 15-72) at priority 999, accepted-args 99, every request. Each callback then `has_filter($legacy)` + `apply_filters_ref_array`. Same as INF-038.
- action: Register aliases lazily on first `has_filter` of a legacy name, or only when a compatibility layer is needed.

### M-04 — medium — stale-cache
- file: `includes/canonical.php:8-13`; `includes/title-description.php:52-57`; `includes/opengraph.php` (per-request statics on image id map `:399`)
- why: `erankly_get_canonical()` and `erankly_get_title()` memoize in function-statics for the whole request. A too-early call (admin-bar, feed, REST-in-front) freezes the wrong URL/title. Same as SEO-037.
- action: Key the static by a request fingerprint (is_singular/id/paged) or reset on `parse_query`.

### M-05 — medium — wrong-url
- file: `includes/helpers/utils.php:41-47`
- why: `erankly_current_url()` uses `$wp->request` + `home_url` + `user_trailingslashit` and **drops the query string**. Date archives (`canonical.php:59-60`) and paged-archive fallback (`:76`) inherit that. Pagination/`s`/filters disappear. Same as INF-030 / SEO-036.
- action: Append the current query (minus tracking) or use `home_url( add_query_arg( ... ) )`.

### M-06 — medium — gate-too-broad
- file: `includes/helpers/core.php:49-51`
- why: `erankly_is_frontend_html_request()` is only `! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()`. REST, feeds, sitemaps, robots.txt, and XML-RPC count as “frontend HTML”. Head/custom-code callers that trust this gate can emit HTML-only tags on non-HTML responses. Same as INF-011.
- action: Also exclude `wp_is_json_request()`, `is_feed()`, sitemap/robots query vars, and `REST_REQUEST`.

### M-07 — medium — capability-bypass
- file: `includes/redirects/class-erankly-redirects-runner.php:33-37`
- why: After path skip checks, **any** user with `manage_options` returns without redirect — including on the public frontend. An administrator previewing a renamed URL never hits 301/410. Comment says this prevents lock-out; it also makes “test as admin” impossible and skips hit increments.
- action: Skip only `is_admin()` / AJAX / REST, or require an explicit preview query arg.

### M-08 — medium — incomplete-schema
- file: `includes/compatibility-woocommerce.php:134-154`
- why: Each approved `review` comment becomes a Schema.org `Review` even when `rating` meta is missing or non-numeric. Google Product rich results expect `reviewRating` on Review nodes. AggregateRating is correctly gated (`:60-68`); Review is not.
- action: Skip the Review node unless a numeric rating exists, or omit `review` when none qualify.

### M-09 — medium — data-loss
- file: `includes/meta.php:697-699`
- why: `erankly_schema_block_has_content()` is solely `isset($block['fields']['custom_json']) && '' !== trim(...)`. Typed add-on fields (FAQ/HowTo/etc.) without JSON are treated as empty and dropped on save (`:663`).
- action: Treat any non-empty sanitized field (or enabled typed block) as content.

### M-10 — medium — costly-sql
- file: `includes/opengraph.php:461-469`
- why: After `attachment_url_to_postid` fails, each candidate runs `meta_value = %s OR meta_value LIKE %s` on `_wp_attached_file` (`LIKE '%/' . esc_like($candidate)`). On a large media library this is an unindexed suffix/prefix scan during `wp_head`.
- action: Exact path match only; or cache; avoid leading-wildcard LIKE.

### M-11 — medium — ux
- file: `includes/helpers/sanitization.php:841-852`
- why: `erankly_trim_text()` cuts to `$limit - 1` and `rtrim`s punctuation. No ellipsis. Callers (Twitter title 70, descriptions 160) show a hard chop. Tests lock the 69-char behavior (`test-opengraph-output.php:110-119`).
- action: Append `…` when truncated, or document the chop as intentional.

### M-12 — medium — request-cost
- file: `uninstall.php:377-409`
- why: Notice/transient cleanup pages **all users** with `get_users( ['fields'=>'ID','number'=>100,'paged'=>N] )`. On Multisite it sets `blog_id => 0` (network-wide, including super-admins not on the site). Large networks walk the entire user table at uninstall.
- action: Delete prefixed transients via SQL `LIKE '_transient_erankly_%'` instead of enumerating users.

### M-13 — medium — wp-contract
- file: `includes/meta.php:313-321`
- why: `erankly_skip_null_schema_blocks_meta` returns **`true`** when `_erankly_schema_blocks` is `null`. In WordPress, a non-null `$check` from `update_{$meta_type}_metadata` **short-circuits** the update (the filter’s “override” contract). Returning `true` means “pretend the update succeeded” and skips the write — correct intent (do not wipe) but a boolean `true` is easy to misread as “allow update”. A future refactor to `return $check` would wipe.
- action: Keep the short-circuit; comment the WP contract; add a unit test that `update_post_meta( ..., null )` leaves the previous array.

### M-14 — medium — inconsistency
- file: `includes/class-erankly-specialist-sitemaps-provider.php:18-27`
- why: News index entries use `ceil($news_count / PER_PAGE)` with **no** `$news_count > 0` guard (image/video have the guard at 33/47). `ceil(0/N)=0` so the loop does not run — harmless today — but if `ERANKLY_SITEMAP_PER_PAGE` were ever 0, `ceil` warns/divides by zero. The missing guard is also why an empty news sitemap can still be considered “enabled” elsewhere.
- action: Use the same `> 0` guard as image/video.

### M-15 — medium — sitemap-index
- file: `includes/class-erankly-site-sitemaps-provider.php:31-35`; `includes/class-erankly-specialist-sitemaps-provider.php:75-77`
- why: Site provider `get_max_num_pages` is `(int) ceil(count(entries)/PER_PAGE)`, which is **0** when empty. Core providers typically `max(1, ceil(...))`. Specialist provider **always** returns 0 (documented stub). An empty site provider with max=0 may be omitted from the index (OK) or confuse core pagination.
- action: Document; for the site provider use `max(1, ceil(...))` only if core requires a page-1 empty sitemap.

### M-16 — medium — rest-contract
- file: `easyrankly.php:1058-1066`
- why: Settings REST autosave returns **HTTP 200** with `saved=>true` and `incomplete=>true` when validation errors exist. Clients that only check status treat an incomplete LocalBusiness/identity save as success (JS then shows a notice — `admin-settings.js` reads `incomplete`). External API clients will not.
- action: Use 200 + incomplete for the existing JS, or 422 for API; document the contract.

### M-17 — medium — wipe-on-absent
- file: `includes/helpers/settings.php:230-232`
- why: `erankly_merge_settings_submission()` sets missing **collection** keys in the active panel to `[]` (“emptied builder”). Intentional for HTML forms. If sanitize/update is invoked with a partial map and an empty/wrong panel slug, stored `head_code_blocks` / `global_schema_blocks` become `[]`. Tests cover the custom-code panel path (`test-settings-and-robots-regressions.php:124-141`).
- action: Only apply the empty-array default when the panel actually owns that collection (already partly true); never apply on REST bodies that omit the key.

### M-18 — medium — tautology
- file: `tests/test-js-contracts.php:20-97`
- why: Identifier presence via `assertStringContainsString` cannot fail if the symbol is dead, mistyped at runtime, or never bound. Same as TST-012.
- action: Keep string locks for PHP↔JS names; rely on `.cjs` probes for behavior.

---

## 4. Pass-1 low findings (full records)

### L-01 — low — dead-code
- file: `includes/schema.php:1007-1021`
- why: `erankly_global_schema_block_matches_request`, `erankly_global_schema_matches_post_type_archive`, `erankly_global_schema_matches_singular`, `erankly_schema_target_list_contains_post` are one-line wrappers around `erankly_targeted_block_*` / `erankly_target_list_contains_item`. Production graph assembly calls the targeted helpers (or the first wrapper). The last three have no production callers (tests may still use them). Report wrappers in import-export/report.php (`ERankly_Migration_Manager::update_report` deprecated per INF notes) are the other half of “schema/report wrappers”.
- action: Delete unused wrappers; keep one public matcher.

### L-02 — low — test-only-api
- file: `includes/breadcrumbs.php:712-729`
- why: `erankly_core_breadcrumbs_default_attributes()` is not called from production render/register. Tests use it to snapshot core defaults.
- action: Move to a test helper or call it from the capture/preview path so it earns its keep.

### L-03 — low — alias-bloat
- file: `includes/helpers/video.php:63-65,91-94,107-110,131-134`
- why: Four `erankly_get_sitemap_video_*` / `erankly_extract_sitemap_video_urls` aliases exist only so the sitemap file can call a second name. Tests assert delegation (`test-helpers-video.php:107-214`).
- action: Call the canonical names from the sitemap; keep aliases as deprecated one-liners if BC requires.

### L-04 — low — dead-wrapper
- file: `includes/schema.php:1011-1013`
- why: `erankly_global_schema_matches_post_type_archive()` only forwards to `erankly_targeted_block_matches_post_type_archive()`. No production caller found in the plugin (grep of includes/).
- action: Remove or use in the graph loop for symmetry.

### L-05 — low — dead-wrapper
- file: `includes/schema.php:1015-1021`
- why: `erankly_global_schema_matches_singular()` and `erankly_schema_target_list_contains_post()` are unused forwards of targeting helpers.
- action: Same as L-04.

### L-06 — low — bc-alias
- file: `includes/schema.php:720-730`
- why: `erankly_schema_blogposting()` copies `erankly_schema_article()` and forces `@type=BlogPosting`, then `apply_filters('erankly_schema_blogposting')`. New code should set type explicitly. Kept for BC + tests.
- action: Leave until a major version; stop using internally.

### L-07 — low — unused-public-api
- file: `includes/hreflang.php:46-59`
- why: `erankly_get_navigable_hreflang_alternates()` is a public API for “language nav” (comment `:41-42`). No production caller in the plugin — only tests (`test-hreflang-canonical.php:133-167`) and the filter.
- action: Use it in a nav helper or mark `@deprecated` until an integration exists.

### L-08 — low — duplicate-surface
- file: `includes/breadcrumbs.php:982-984`
- why: Both `[erankly_breadcrumbs]` and `[easyrankly_breadcrumbs]` register the same callback. Justified BC; two shortcodes to document and kses.
- action: Keep; document the alias once.

### L-09 — low — triple-inserter
- file: `blocks/breadcrumbs/block.json` (`"inserter": false`); `blocks/breadcrumbs/index.js` (`inserter: ! coreAvailable`); `includes/breadcrumbs.php:1013-1016` + `:1047`
- why: Inserter visibility is declared three times (static JSON false, JS runtime, PHP `supports['inserter']`). Drift is already the subject of TST-011 / native tests.
- action: Single source: PHP register + JS read `window.eranklyBreadcrumbsBlock`; keep JSON as the static default.

### L-10 — low — encode-failure
- file: `includes/schema.php:28-38`
- why: `wp_json_encode(...)` return is echoed without a `false` check. On failure PHP echoes nothing between `<script type="application/ld+json">` and `</script>` — an **empty** JSON-LD script (leftover “empty script”).
- action: If encode fails, do not print the script tags.

### L-11 — low — comment-lie
- file: `includes/helpers/template-variables.php:1-2`
- why: Header claims “Part of the helpers.php loader; always loaded early on every request.” The file is loaded lazily via `erankly_load_content_helpers()`. Same class of lie as INF-034 (global-meta.php).
- action: Fix the file header to say lazy-loaded.

### L-12 — low — unused-param
- file: `includes/schema-jsonld.php:704-734`
- why: `erankly_get_local_business_page_id( int $post_id = 0 )` never uses `$post_id` except `unset( $post_id )` before returning 0. Callers cannot pass “current post” as the page. Report alias SEO-073.
- action: Remove the parameter or implement “if this post is the mapped page”.

### L-13 — low — missing-url-form
- file: `includes/helpers/video.php:17-19,80,124`
- why: Extractor/embed/thumb regexes know `watch?v=`, `youtu.be/`, `embed/`, nocookie embed. They do **not** match `youtube.com/shorts/{id}`. Shorts in content are invisible to VideoObject and the video sitemap.
- action: Add a shorts alternative in the same ID-capture group.

### L-14 — low — silent-fallback
- file: `includes/schema.php:601-605`; `includes/helpers/defaults.php:271`
- why: Configured webpage type `QAPage` is rewritten to `WebPage` because a Question `mainEntity` builder does not exist. Users who pick QAPage get WebPage with no admin warning.
- action: Hide QAPage in the UI or emit a settings notice.

### L-15 — low — excluded-harness
- file: `phpunit.xml.dist:11`; `tests/live-schema-audit.php` (excluded)
- why: The only live frontend JSON-LD / XSS / replace-mode / LB-on-page harness is not in PHPUnit. Same family as TST-001 / TST-010.
- action: Do not treat live PASS as CI; add PHPUnit equivalents.

### L-16 — low — i18n-in-throw
- file: `easyrankly.php:162`; also `includes/reset.php:141-221`, `includes/network-reset.php:92,135,167`
- why: `throw new RuntimeException( esc_html__( '...' ) )` HTML-escapes a string that is not rendered as HTML (exception message / logs). Escaping can entity-encode apostrophes; translators see HTML context incorrectly.
- action: Use `__(..., 'easyrankly')` in exceptions; `esc_html__` only when echoing.

---

## 5. Pass-1 nits (N-01–N-12) — re-derived

Original pass-1 N write-up was not in the notes (only “N-01–N-12 pass 1”). Each item below is **re-derived** from cited areas / comment-quality notes.

### N-01 — nit — comment
- file: `includes/canonical.php:1-2`
- why: File-level doc is one line. The static cache, author-variable exclude hole, and search-pagination skip are undocumented at the file head.
- action: One paragraph listing request kinds and cache.

### N-02 — nit — unused-param
- file: `includes/meta-visibility.php` (hook signature `$meta_id`)
- why: Invalidation callbacks accept `$meta_id` unused (standard WP hook shape). Not a bug.
- action: `unset( $meta_id )` or prefix with underscore for PHPCS.

### N-03 — nit — docblock-indent
- file: `includes/meta.php:66-72,177-183` (SEO notes)
- why: Filter `@param` docblocks are mis-indented relative to neighboring functions.
- action: phpcs alignment.

### N-04 — nit — placement
- file: `includes/meta.php` (`erankly_decode_custom_json_ld`)
- why: Decoder lives with meta registration conceptually belongs with `schema-jsonld.php` (already loaded from meta.php).
- action: Move on the next schema-jsonld split.

### N-05 — nit — docblock-indent
- file: `includes/schema-content.php:875-878` (SEO notes)
- why: Filter docblocks mis-indented.
- action: Align.

### N-06 — nit — compat-shim
- file: `includes/helpers/core.php:24-31`
- why: `erankly_array_is_list()` reimplements PHP 8.1 `array_is_list()`. Requires PHP is already 8.x on supported WP 6.5+ in many hosts; still needed if 8.0 is in the matrix (H-09 / WP 6.5).
- action: Keep until PHP 8.1 is the minimum; then delete.

### N-07 — nit — i18n-split
- file: `includes/redirects/class-erankly-redirects-normalizer.php` (`status_code_label`); admin list table uses `esc_html__` separately
- why: Normalizer labels (`301: Moved Permanently`) are English in the class; admin UI has its own strings. Tests lock the English form (`test-redirects-normalizer.php:15-18`).
- action: One i18n source for status labels.

### N-08 — nit — missing-textdomain
- file: `includes/breadcrumbs.php:706`
- why: `__( '(no title)' )` has no `easyrankly` domain (matches core on purpose; phpcs ignore). Same as FE-009.
- action: Leave if matching core is the product rule; otherwise add domain.

### N-09 — nit — comment-lie
- file: `includes/helpers/template-variables.php:1-2` (duplicate of L-11 at nit severity if L-11 is kept)
- why: Same header lie. Listed so N-09 has a reconstructed identity from pass-1 “comment/style nits”.
- action: See L-11.

### N-10 — nit — unused-param
- file: `includes/schema-jsonld.php:704` (duplicate of L-12 at nit)
- why: `$post_id` unused. Pass-1 likely filed this as a nit before it was promoted.
- action: See L-12.

### N-11 — nit — comment-lie
- file: `admin/meta-box.php:2-6` (ADM-008)
- why: Header claims PHP field renderers are shared with `editor.js`. They are not.
- action: Fix the header.

### N-12 — nit — comment-language
- file: `assets/css/admin-core.css:758-761`
- why: TEMP CSS comment in Italian hiding documented nav. Pass-2 listed this as a nit sibling of N-01–12.
- action: Remove TEMP rule or comment in English next to the product decision.

---

## 6. Leftover SEO defects (SEO-038+)

SEO-001–037 already assigned in the line-audit report. Below are **additional** defects reconstructed from `audit-notes-seo.md` per-file notes. Sequential IDs only; last ID **SEO-070**. Report aliases SEO-042, SEO-043, SEO-053, SEO-073 map to SEO-042, SEO-043, SEO-053, SEO-070 here.

### SEO-038 — low — first-wins
- file: `includes/hreflang.php:90-93`
- why: Duplicate tags after `strtolower` keep the first URL silently. A provider emitting `IT-it` and `it-IT` with different URLs drops the second. Tests lock first-wins (`test-hreflang-canonical.php:93-102`).
- action: If URLs differ, last-wins or diagnostic; if same, current behavior is fine.

### SEO-039 — medium — empty-script
- file: `includes/schema.php:28-38`
- why: Script tags are opened before `wp_json_encode`. Encode `false` prints an empty `<script type="application/ld+json"></script>`. Same mechanism as L-10.
- action: Encode first; echo tags only on a string.

### SEO-040 — low — og-locale
- file: `includes/opengraph.php:57`
- why: `og:locale` is `str_replace( '-', '_', get_bloginfo( 'language' ) )`. `zh-Hans-CN` becomes `zh_Hans_CN`, which is not a Facebook `language_TERRITORY` locale. No `og:locale:alternate` for hreflang siblings.
- action: Map via `get_locale()` + a small BCP47→OG table; emit alternates from the provider.

### SEO-041 — medium — archive-title-prefix
- file: `includes/title-description.php:97-98,106-107,115-116`
- why: Term/CPT/author/date fallback is `get_the_archive_title()`, which prefixes “Category:”, “Tag:”, “Archives:”. Those prefixes become the document title and OG title when no template is set.
- action: Use `single_term_title()` / `post_type_archive_title()` / `get_the_archive_title` filtered via `get_the_archive_title_prefix`.

### SEO-042 — high — query-wipe
- file: `includes/robots.php:618-645`
- why: `erankly_force_robots_txt_request()` sets `$wp->query_vars = array( 'robots' => '1' )`, replacing **all** query vars when the path is `robots.txt`. Same as H-10. A concurrent query var (preview, custom) is dropped.
- action: Set only `$wp->query_vars['robots'] = '1'` (or merge).

### SEO-043 — medium — dead-branch
- file: `includes/sitemap/core.php:270-272`
- why: `erankly_filter_sitemap_posts_where` appends news exclusion SQL only when `$query->get( 'erankly_news_sitemap_query' )`. Nothing in the plugin sets that query flag. News exclusions never apply on this WHERE filter. WP12 cites this as SEO-043.
- action: Set the flag in the news query or delete the branch and use the dedicated news SQL path only.

### SEO-044 — medium — too-early-render
- file: `includes/meta-render.php:8-15`
- why: `erankly_render_head()` function-static `$rendered` returns on a second call. A too-early first call (empty query) prints empty head tags and blocks the real `wp_head` pass.
- action: Key `$rendered` after `wp` / `template_redirect`, or reset when `did_action('wp')` is 0.

### SEO-045 — medium — host-compare
- file: `includes/meta-render.php:62-66`
- why: Attachment redirect uses `wp_parse_url( $target, HOST ) === wp_parse_url( home_url(), HOST )`. Ports and `www` vs apex differ → `wp_redirect` (non-safe) to what is still first-party, or the inverse.
- action: Compare with `wp_parse_url` host+port and a www-normalized host.

### SEO-046 — low — sanitize-key
- file: `includes/seo-state.php:118-120`
- why: `$subtype = sanitize_key( $context['object_subtype'] )` lowercases. A mixed-case CPT slug that is registered with capitals would miss `post_type_exists` / meta maps.
- action: Document that subtypes are keys; sanitize only when looking up settings.

### SEO-047 — medium — permissive-kind
- file: `includes/seo-state.php:188-193`
- why: `kind` not in post/term/archive (`home`, `other`, default else) sets exists/published/public **true** and canonical = caller URL. `kind=other` is “very permissive”: a caller can mark junk URLs indexable.
- action: Require an allowlist for `other` or default public=false.

### SEO-048 — low — dual-title-hooks
- file: `includes/title-description.php:11-30`
- why: Both `pre_get_document_title` and `document_title_parts` are hooked. Once the title is non-empty, parts rarely run. Duplicate work and two filters to reason about.
- action: Hook one; keep the other as a thin unused fallback.

### SEO-049 — low — first-notice-only
- file: `includes/meta.php` (`erankly_add_schema_json_settings_error` static `$added`)
- why: JSON-LD error notice static keeps only the first notice per request. A second invalid block is silent.
- action: Queue all messages or a count.

### SEO-050 — low — array-filter
- file: `includes/opengraph.php:76`
- why: `array_filter( $tags )` drops values that are `"0"` (valid content). Same class as H-04 / SEO-021.
- action: Filter with a callback that only drops `''`.

### SEO-051 — medium — ignored-setting
- file: `includes/opengraph.php` twitter card helper (default `summary_large_image` then degrade without image)
- why: SEO notes: Twitter card default ignores a global setting and degrades to summary without image. Tests lock large-image default (`test-opengraph-output.php:122-128`). A stored global default card type is not applied unless per-post meta is set.
- action: Read a global `default_twitter_card` (if in settings model) before the hard-coded default.

### SEO-052 — low — missing-id-field
- file: `includes/opengraph.php` term/user image resolution
- why: Posts have `_erankly_og_image_id` / twitter `_id`. Terms/users have URL meta only. ID-based alt/dimensions (CDN reverse-map) never run for archives/authors.
- action: Add term/user `_id` fields or resolve URL→id for those objects too.

### SEO-053 — high — settings-group
- file: `includes/special-meta.php:27-39`
- why: `register_setting( 'general', ERANKLY_SPECIAL_META_OPTION )`. Saving Settings → General (especially MS) can run the sanitizer with an empty/partial object and reset special meta. Same as H-01.
- action: Register in an EasyRankly-only group, not `general`.

### SEO-054 — medium — query-var-leak
- file: `includes/robots.php:648+` (`erankly_register_rewrites` anonymous `query_vars` filter)
- why: SEO notes: each call **appends** query_vars via a new anonymous filter (init + another bootstrap). Duplicate `erankly_sitemap` entries and extra closures per request.
- action: Register the query vars once; `remove_filter` before re-add.

### SEO-055 — low — a11y
- file: `includes/breadcrumbs.php` (empty last name)
- why: Empty last crumb name skips `aria-current`. The current page is not identified to AT.
- action: Fall back to untitled label (already have `erankly_core_breadcrumbs_untitled_label`).

### SEO-056 — low — double-kses
- file: `includes/breadcrumbs.php` (HTML trail)
- why: Trail HTML is `wp_kses`’d more than once (builder + shortcode/block). Extra cost; second pass can differ if allowed tags diverge.
- action: Kses once at the public output boundary.

### SEO-057 — low — graph-siblings
- file: `includes/schema.php` (FAQ + WebPage)
- why: FAQPage is emitted **alongside** WebPage. Google FAQ rich results are largely retired; two page types for one URL is noisy.
- action: Product decision: FAQ only, or WebPage `mainEntity` FAQPage.

### SEO-058 — medium — admin-bar-timing
- file: `includes/schema-jsonld.php:507+` (`erankly_render_schema_merge_warning_comment`)
- why: Merge warnings need `erankly_get_schema_graph()` to have run. Graph is built in `wp_head` priority 1. Themes that print the admin bar before `wp_head` never see warnings.
- action: Build a cheap warning list earlier, or hook admin-bar after `wp_head`.

### SEO-059 — low — unused-sql-path
- file: `includes/sitemap/core.php` (user exclusion)
- why: SEO notes: user exclusion `meta_query` still used; SQL helper used for author stats. `erankly_get_sitemap_exclusion_meta_query` for users with `disable_sitemap` default true is not used that way (caller passes false). Two paths can diverge.
- action: One exclusion builder for users.

### SEO-060 — medium — wrong-count
- file: `includes/sitemap/image.php` (count)
- why: Image sitemap pagination count is **posts**, not image URLs. A post with 20 images occupies one slot; a page can exceed Google’s 1000-images-per-file in spirit, or under-fill pages.
- action: Count images or cap per post at render (already 1000 comment).

### SEO-061 — low — double-work
- file: `includes/sitemap/image.php`
- why: Per-post image discovery runs at candidate-filter time **and** again at XML render.
- action: Cache URLs on the candidate row.

### SEO-062 — low — false-positive-sql
- file: `includes/sitemap/image.php` (LIKE `% <img %`)
- why: SQL LIKE accepts posts whose content mentions `<img ` in code samples; later filtered. Extra candidate work.
- action: Acceptable; or require a real `src=`.

### SEO-063 — low — language-chop
- file: `includes/sitemap/news.php` (publication language)
- why: Language is first two chars of locale (`zh_CN` → `zh`). Acceptable-ish for Google News; loses script/region.
- action: Map `zh_CN`/`zh_TW` explicitly if news is a product feature.

### SEO-064 — medium — off-site-news
- file: `includes/sitemap/news.php`
- why: Filter may supply an absolute http `loc` off-site; it is allowed. News sitemap then advertises another host.
- action: Same origin check as the site provider (with path).

### SEO-065 — low — enable-not-rechecked
- file: `includes/sitemap/news.php` (`erankly_get_news_sitemap_xml`)
- why: XML builder does not re-check the enable flag (wrapper does). A direct call or stale rewrite can emit news XML after the setting is off.
- action: Re-check enable inside the builder.

### SEO-066 — low — utf8-split
- file: `includes/sitemap/video.php`
- why: Title/description cut uses `mb_substr` if available else `substr`, which can split a UTF-8 codepoint on hosts without mbstring.
- action: Always use `erankly_trim_text` (already mb-aware).

### SEO-067 — medium — costly-resolve
- file: `includes/class-erankly-site-sitemaps-provider.php:77-79`
- why: `url_to_postid( $archive_url )` per public CPT archive to dedupe Woo shop-as-page. Expensive and permalink-flaky.
- action: Compare to the shop page ID option when WC is active.

### SEO-068 — low — missing-lastmod
- file: `includes/class-erankly-specialist-sitemaps-provider.php:36-55`
- why: Image/video index entries omit `lastmod`; news includes it. Crawlers refresh image/video sitemaps less predictably.
- action: Add lastmod from the specialist cache’s newest post.

### SEO-069 — medium — unvalidated-robots
- file: `includes/robots.php` (max-snippet / max-video-preview output)
- why: Settings values are printed into `wp_robots` without integer validation at output (SEO-035 is inherit; this is raw output). A stored `"<script>"` or `""` can become a broken directive.
- action: Cast to int with min/max; skip if inherit/empty.

### SEO-070 — low — unused-param
- file: `includes/schema-jsonld.php:704-734`
- why: Same as L-12 / report SEO-073. Listed so the leftover SEO sequence includes the cited ID’s defect without skipping to 073.
- action: See L-12.

---

## 7. Remaining ranges

All 50 `tests/test-*.php` files were fully re-read line-by-line (Purpose / Issues / Gaps in §1).

Supporting (not required `test-*.php`; not fully re-read this pass):

- `tests/live-schema-audit.php:1-890`
- `tests/js/*.cjs`
- `tests/fixtures/woocommerce-doubles.inc`
- `tests/schema-recheck.md`

---

## Return summary

| Item | Value |
|---|---|
| Test files done | **50 / 50** (listed in §1) |
| Last SEO ID | **SEO-070** |
| M-01–M-18 complete | **yes** |
| L-01–L-16 complete | **yes** |
| N-01–N-12 complete | **yes (re-derived)** |
| Remaining ranges | none for `test-*.php`; supporting files in §7 |
