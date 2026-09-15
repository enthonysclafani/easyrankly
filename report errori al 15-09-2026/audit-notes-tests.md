# EasyRankly tests audit notes (read-only, working tree)

Plugin root: `/Users/enthonysclafani/Studio/easyrankly/wp-content/plugins/easyrankly`
Date: 2026-09-15
Method: every inventory file read with Read + offsets. Grep used only to locate production counterparts after tests were read. No plugin/test/git writes.

phpunit.xml.dist: `suffix=".php"` on `tests/`; excludes `tests/bootstrap.php` and `tests/live-schema-audit.php`. `schema-recheck.md` and `tests/js/*.cjs` and `woocommerce-doubles.inc` are not PHPUnit cases.

No commented-out `function test_` methods found. No `markTestIncomplete`. Skips are env/SAPI/MS/Node/WP_DEBUG/SQLite/core-block.

---

## Coverage table

| path | lines | status |
|---|---|---|
| tests/bootstrap.php | 96 | audited; excluded from phpunit suite (bootstrap only) |
| tests/fixtures/woocommerce-doubles.inc | 109 | audited; not a test (`.inc` so phpunit suffix skips it) |
| tests/js/breadcrumbs-block-probe.cjs | 195 | audited; executed only via test-js-contracts if node exists |
| tests/js/local-business-widget-probe.cjs | 548 | audited; executed only via test-js-contracts if node exists |
| tests/js/settings-autosave-probe.cjs | 430 | audited; executed only via test-js-contracts if node exists |
| tests/live-schema-audit.php | 890 | audited; **excluded from phpunit.xml.dist** |
| tests/schema-recheck.md | 66 | audited; docs, not a test |
| tests/test-admin-menu.php | 579 | audited |
| tests/test-admin-meta-box.php | 320 | audited |
| tests/test-admin-renderers.php | 544 | audited |
| tests/test-admin-settings-panels.php | 477 | audited |
| tests/test-breadcrumb-registration.php | 28 | audited; skipped unless env set **before** bootstrap |
| tests/test-breadcrumbs-native.php | 482 | audited; skips if `core/breadcrumbs` missing |
| tests/test-breadcrumbs.php | 1080 | audited; native subset skips if core missing |
| tests/test-canonical.php | 139 | audited |
| tests/test-compatibility.php | 178 | audited |
| tests/test-custom-code-boundaries.php | 458 | audited |
| tests/test-helpers-cache.php | 282 | audited |
| tests/test-helpers-content-defaults.php | 313 | audited |
| tests/test-helpers-global-meta.php | 500 | audited |
| tests/test-helpers-template-variables.php | 345 | audited |
| tests/test-helpers-video.php | 216 | audited |
| tests/test-hreflang-canonical.php | 202 | audited |
| tests/test-import-export.php | 713 | audited |
| tests/test-js-contracts.php | 148 | audited |
| tests/test-lifecycle-cron.php | 140 | audited |
| tests/test-lifecycle-rest.php | 127 | audited |
| tests/test-lifecycle.php | 1129 | audited |
| tests/test-local-business-rest.php | 174 | audited |
| tests/test-meta-helpers.php | 1153 | audited |
| tests/test-migration-internals.php | 426 | audited; private storage often skipped |
| tests/test-migrations-adapters.php | 1322 | audited |
| tests/test-migrations-runner.php | 586 | audited |
| tests/test-migrations-runtime.php | 330 | audited |
| tests/test-multilingual-registry.php | 242 | audited |
| tests/test-network-reset.php | 221 | audited; several MS-only skips |
| tests/test-opengraph-migrations.php | 143 | audited |
| tests/test-opengraph-output.php | 297 | audited |
| tests/test-redirects-normalizer.php | 264 | audited |
| tests/test-redirects-repository.php | 551 | audited |
| tests/test-redirects-runtime.php | 878 | audited |
| tests/test-reset-actions.php | 313 | audited; SQLite skip for DROP TABLE |
| tests/test-response-handlers.php | 97 | audited |
| tests/test-rest-user-search.php | 159 | audited |
| tests/test-robots-and-custom-code.php | 650 | audited |
| tests/test-robots-output.php | 244 | audited |
| tests/test-schema-content.php | 473 | audited |
| tests/test-schema-jsonld.php | 1143 | audited |
| tests/test-schema.php | 550 | audited |
| tests/test-seo-state.php | 84 | audited |
| tests/test-settings-and-robots-regressions.php | 177 | audited |
| tests/test-sitemap-providers.php | 399 | audited |
| tests/test-sitemap-specialists.php | 473 | audited |
| tests/test-sitemap.php | 246 | audited |
| tests/test-title-description.php | 357 | audited |
| tests/test-uninstall-cleanup.php | 229 | audited |
| tests/test-woocommerce.php | 359 | audited; separate process + doubles |

Remaining ranges: **none**. Full inventory read.

---

## Findings

### TST-001 — high — excluded-from-phpunit
**File:** `phpunit.xml.dist:11` + `tests/live-schema-audit.php:1-890`
**Why:** The only test that hits a live frontend `wp_remote_get`, JSON-LD XSS encoding in HTML, REST invalid JSON-LD preserve-last-valid, replace/disabled modes on a published permalink, LocalBusiness on a real page, and IT/EN targeting is excluded from the PHPUnit suite. Green PHPUnit does not cover those production output paths.
**Action:** Keep as a Studio eval-file, but add PHPUnit equivalents for XSS encoding, invalid-JSON preserve, replace/disabled graph, and merge-on-shared-@id. Do not treat live PASS as CI evidence.

### TST-002 — high — probe-skips-claimed-path
**File:** `tests/js/breadcrumbs-block-probe.cjs:146-195`
**Why:** `assertShallowSupportsMerge` returns silently when `blocks.js` is missing (148-150) or `processBlockType` is not located (156-158). The probe still prints `breadcrumbs-block-probe: ok` (195). `test-js-contracts.php:132-145` only asserts exit code 0. CI without WordPress `blocks.js` never executes the core supports-merge slice it claims to cover.
**Action:** Fail (or skip the PHPUnit case) when merge cannot run; do not print ok after a skip.

### TST-003 — high — assertion-cannot-fail / misses-risky-branch
**File:** `tests/test-response-handlers.php:27-67`
**Why:** `erankly_send_feed_robots_header()` (`includes/robots.php:208-214`) is the production header emitter. Tests call it on early-return and happy paths but never inspect `headers_list()` / `xdebug_get_headers()`. They only assert `erankly_feed_robots_tag()` string and that the action is hooked (51-52). The `headers_sent()` early return and the `header('X-Robots-Tag: …')` line are unproven.
**Action:** Assert the header when the SAPI allows it; skip only that assertion if headers already sent. Do not treat “function returned” as coverage of the header.

### TST-004 — high — missing-risky-branch
**File:** `tests/test-redirects-runtime.php:198-272` vs `includes/redirects/class-erankly-redirects-runner.php:69-82`
**Why:** Runtime tests cover 410 via `WPDieException`, self-loop, empty target, and admin skip. They never exercise a matching 301/302/308 `wp_safe_redirect()` (production 82). Hit sampling is forced to 1, which hides the default sample-rate miss path. A broken Location / host allowlist / increment-then-redirect order would still be green.
**Action:** Add a separate-process test that expects the WP test-suite redirect exception/header for an exact 301 rule, including an external host via `allow_safe_external_host_for_target`.

### TST-005 — high — missing-risky-branch
**File:** `tests/test-meta-helpers.php:225-244` vs `includes/meta-render.php:33-69`
**Why:** Only `attachment_redirect=none` is tested (inert, no exit). Production `parent` (permalink) and fallback-to-file / CDN `wp_redirect` (65-66) plus `exit` are untested. Settings panel only asserts the field name exists (`test-admin-settings-panels.php:256`).
**Action:** Separate-process tests for parent redirect and file-url redirect; assert 301 target. Do not call `exit` in-process.

### TST-006 — high — incomplete-fixture / probe-does-not-execute-claimed-JS
**File:** `tests/js/settings-autosave-probe.cjs:249-261, 374-422`
**Why:** Fake `DOMParser.parseFromString` ignores HTML and returns a canned empty `.erankly-settings` div. The probe asserts a GET with `cache: no-store` after incomplete 200, not that the settings root was replaced with server markup or that autosave status nodes survived. Production refresh path can be wrong and still pass.
**Action:** Parse the mocked HTML (or at least assert `replaceWith` received a node built from `text()`), and assert status/panel identity after refresh.

### TST-007 — high — wrong-thing / misses-risky-branch
**File:** `tests/test-helpers-template-variables.php:107-114`
**Why:** Name is `escapes_backslashes_and_newlines`. Fixture content is default `'Contenuto di prova'` (no `\`, no newline). Assertion is identity of plain text. JSON-LD escape of backslash/quote/newline in `erankly_replace_json_ld_variables` is untested here.
**Action:** Use content with `\`, `"`, and `\n`; decode JSON and compare.

### TST-008 — high — misnamed / misses-risky-branch
**File:** `tests/test-helpers-global-meta.php:129-134` vs `includes/helpers/global-meta.php:217-249`
**Why:** Name claims inherit is treated as unset. Production: empty `''` falls through to legacy `_erankly_noindex`; stored `'inherit'` **blocks** legacy and returns `'inherit'` (240-241). Test only stores inherit and asserts inherit — would also pass if inherit were returned as a normal stored value in the first branch. The risky pair (inherit + `_erankly_noindex=1`) is not asserted.
**Action:** Assert inherit + legacy `1` still returns inherit; assert empty + legacy `1` returns `noindex`.

### TST-009 — high — empty-success-for-untested-storage
**File:** `tests/test-migrations-adapters.php:854-861, 1137-1141, 1288-1309`
**Why:** Rank Math redirects and AIOSEO content/redirect batches are asserted empty/`done` when source tables are absent. That is the no-plugin path, not mapping from `rank_math_redirections` / `aioseo_posts` / `aioseo_redirects`. Yoast option-based redirects are covered; table-backed adapters are not. A broken SQL mapper would stay green in CI.
**Action:** Fixture the source tables (or skip with a hard “source tables required” message) and map at least one real row each.

### TST-010 — high — live-audit-cannot-fail
**File:** `tests/live-schema-audit.php:72-90, 416-419, 853-857`
**Why:**
- `erankly_live_parse_graph` only decodes `scripts[0]`; extra JSON-LD scripts are ignored (80-89).
- English `inLanguage` check: empty string passes (`416`: `'' !== $en_lang && …`).
- WooCommerce branch always `erankly_live_pass` whether WC exists or not (853-857); Product schema is never asserted.
- `wp_remote_get` uses `sslverify => false` (51).
**Action:** Fail on extra JSON-LD scripts when one is expected; fail missing `inLanguage`; skip WC instead of PASS; do not treat this file as CI.

### TST-011 — medium — skip-by-default
**File:** `tests/test-breadcrumb-registration.php:12-15` + `tests/bootstrap.php:32-47`
**Why:** Default PHPUnit never sets `ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS=1` before bootstrap. The only test that the legacy inserter is `true` when core is actually deregistered at init priority 10 is skipped. Native/legacy tests that skip when core is missing (`test-breadcrumbs.php:40`, `test-breadcrumbs-native.php:27`) leave the deregistered-core registration contract unenforced in CI.
**Action:** Add a documented PHPUnit job with the env var, or use `@runInSeparateProcess` with a bootstrap that can deregister after load (if production allows).

### TST-012 — medium — tautology / string-contract-not-behavior
**File:** `tests/test-js-contracts.php:20-97`
**Why:** Most cases are `assertStringContainsString` on source. That cannot fail if identifiers are present but dead, mistyped at runtime, or never bound. Probes at 100-145 **do** execute the three `.cjs` files (good). Unprobed JS: `assets/js/redirects.js`, `accordion-faq-schema.js`, `admin-fields.js`, `admin-tabs.js`, `admin-media.js`, `admin-panels.js`, `admin-import-export.js`, `admin-variables.js`, `editor.js` (META_MAP strings only), `site-editor.js`, `admin-reset.js`, `schema-jsonld.js` (`isValidTypeName` string only), `editor-shared.js`.
**Action:** Keep string locks for PHP↔JS identifiers; add probes for redirects UI, schema JSON validation, accordion FAQ, import/export.

### TST-013 — medium — incomplete-fixture
**File:** `tests/js/local-business-widget-probe.cjs:12-141` vs `assets/js/admin-widgets.js:738`
**Why:** LocalBusiness `FakeNode` has no `classList`. Production toggles `.is-closed` via `day.classList.toggle("is-closed", closed.checked)`. Probe never builds opening-hours markup, so that line is unexecuted. `test-js-contracts.php:86` still string-locks `classList.toggle("is-closed", closed.checked)`. If the probe later added a closed checkbox, it would throw.
**Action:** Add `classList` to FakeNode and a closed-day toggle assertion, or drop the string contract until the probe covers it.

### TST-014 — medium — cannot-simulate-conflict
**File:** `tests/test-compatibility.php:148-177`
**Why:** Comments admit external SEO owner is memoised at bootstrap; tests only cover the no-conflict path and the `erankly_enable_head_output` filter. `erankly_compatibility_notice_external_seo()` never emits the conflict notice. Production branch that yields the head when Yoast/Rank Math/AIOSEO/SEOPress is active is untested.
**Action:** `@runInSeparateProcess` with a stub owner function defined before the plugin memo, or a filter if production exposes one.

### TST-015 — medium — tautology
**File:** `tests/test-schema.php:226-227`
**Why:** `assertIsArray` then `assertNotNull` on the same value. Arrays are never null. Does not prove REST did not return a JSON `null` that became `null` in PHP (that would fail `assertIsArray` already). Weak duplicate.
**Action:** Keep `assertIsArray` + structure; drop `assertNotNull`.

### TST-016 — medium — weak-assertion
**File:** `tests/test-schema.php:491-494`
**Why:** `erankly_sanitize_schema_type_list( array( 'Article', 'article', 'FAQPage', 'faqpage' ) )` only asserts `count === 2`. Could be two unexpected strings and still pass.
**Action:** Assert the canonical values (e.g. `Article` and `FAQPage`) and uniqueness case-insensitively.

### TST-017 — medium — test-isolation
**File:** `tests/test-schema.php:471-473`
**Why:** `remove_all_filters( 'erankly_faq_items' )` then re-adds only `erankly_faq_items_from_content`. Any other production/test callbacks on that hook are stripped for the rest of the process (not separate-process).
**Action:** `remove_filter` the closure; do not `remove_all_filters`.

### TST-018 — medium — tautology
**File:** `tests/test-breadcrumbs.php:414-423`
**Why:** `when_visible` mode asserts `should_emit === has_visible` without rendering a trail or a block. Equality of two getters is not evidence either is correct vs production visibility.
**Action:** Fixture visible vs not-visible content and assert both sides independently.

### TST-019 — medium — tautology / alias-both-empty
**File:** `tests/test-breadcrumbs.php:345-350`
**Why:** `easyrankly_breadcrumbs` vs `erankly_breadcrumbs` compared to each other. If both return `''` (home/no trail), the alias still “passes”.
**Action:** Compare on a request that produces non-empty HTML.

### TST-020 — medium — assertion-cannot-fail
**File:** `tests/test-redirects-runtime.php:144-154`
**Why:** After `erankly_redirects_boot()`, `has_action( 'parse_request' )` and `has_action( 'rest_api_init' )` without a callback. Core always registers those hooks. `class_exists` after `require_once` in set_up is similarly weak. Does not prove the runner/REST callbacks were attached.
**Action:** `has_action( 'parse_request', array( $runner, 'maybe_redirect' ) )` (as the later unit test already does at 191-195) and the REST instance callback.

### TST-021 — medium — nonce-only / misses-success-path
**File:** `tests/test-redirects-runtime.php:840-877`
**Why:** `handle_actions` for delete/toggle/save only expects `WPDieException` (missing nonce). Successful save, edit, toggle, delete, and `wp_safe_redirect` back to the settings URL are untested. REST covers toggle/delete callbacks directly but not admin form POST.
**Action:** Valid nonce + admin user; assert row changes and redirect args.

### TST-022 — medium — REST surface hole
**File:** `tests/test-redirects-runtime.php:355-451`
**Why:** REST tests: permission, toggle, delete, test_rule, dispatch 403. No create, update, list/get. Broken sanitizer on create would not fail this file (repository tests cover SQL create separately, not the REST wrapper).
**Action:** Dispatch POST create/update and GET collection through `rest_get_server()`.

### TST-023 — medium — environment-coupled
**File:** `tests/test-sitemap.php:38-43`
**Why:** `assertFalse( erankly_sitemap_enabled() )` then asserts core sitemap filters remain registered. Default `enable_sitemap` is 0 (`test-lifecycle.php:726`), so this is the default-off path, not “module off after being on”. If defaults change, this fails for the wrong reason. The **on** buffer path is not in this class.
**Action:** Explicitly set the setting off/on rather than assuming env default.

### TST-024 — medium — skip-hides-branch
**File:** `tests/test-sitemap-providers.php:178-185, 198-211`
**Why:** Buffer start is only tested for **non-sitemap** requests (must not open a buffer). The sitemap-on buffer start is absent. 304 ETag is skipped when `headers_sent()` — typical PHPUnit SAPI — so the 304 branch is often never run. `test-response-handlers.php:90-95` does cover 304 via `erankly_prepare_xml_response` (builder, not `erankly_filter_core_sitemap_response` + `header()`).
**Action:** Test buffer start with `sitemap` query var; run 304 in a SAPI that can send headers or assert via a wrapper.

### TST-025 — medium — skip-hides-destructive-path
**File:** `tests/test-reset-actions.php:171-178`
**Why:** `erankly_reset_site_data()` (DROP TABLE) skipped on SQLite PHPUnit drop-in. Uninstall test (223-312) rewrites DROP TABLE to `SELECT 1` via `query` filter, so DROP is still never executed. CI on SQLite never proves table drop.
**Action:** Document MySQL job; or assert table existence after reset on engines that support transactional DDL.

### TST-026 — medium — synthetic-query-state
**File:** `tests/test-title-description.php:342-355`; `tests/test-robots-output.php:104-116`
**Why:** Pagination is simulated by mutating `$wp_query->is_paged` / `paged` on a singular permalink (title) or home (robots), not `go_to( /page/2/ )`. Contrast `test-hreflang-canonical.php:180-191` which really requests `/page/2/`. `is_paged()` on singular is not the same as archive page 2.
**Action:** Use a real paged request (hreflang test already shows how).

### TST-027 — medium — filter-assertion-can-be-swallowed
**File:** `tests/test-import-export.php:118-131` vs `includes/import-export/actions.php:35-54`
**Why:** Filter returns 2048. Assertion is `>= 1024 && <= 2048`. Memory cap `min($configured, $memory_cap)` can return 1024 even if the filter was ignored. Does not prove the filter value was applied.
**Action:** If memory cap allows, `assertSame( 2048, $filtered )`; otherwise skip with the measured cap.

### TST-028 — medium — incomplete-WC-doubles
**File:** `tests/fixtures/woocommerce-doubles.inc:20-109`; `tests/test-woocommerce.php`
**Why:** Doubles implement a small getter set; `get_woocommerce_currency()` is hardcoded `EUR` (105-108). No real `WC_Product` image/gallery/type/tax. Schema is never rendered on a product permalink. `test-compatibility.php` and live-audit both skip real WC. Variable prices exist on the double; other WC types do not.
**Action:** Keep doubles for mapper unit tests; add an optional WC-installed group for Product JSON-LD on the product URL.

### TST-029 — medium — WP_DEBUG coupling
**File:** `tests/test-schema-jsonld.php:81-103` vs `includes/schema-jsonld.php:486-490`
**Why:** Test name is `logs_once_when_debug`. It never defines/undefines `WP_DEBUG`. If WP_DEBUG is true (usual in WP tests) it asserts log once; the `! WP_DEBUG` early return is untested. If WP_DEBUG is false, the test fails despite “when_debug”.
**Action:** Separate-process: WP_DEBUG true logs; WP_DEBUG false writes nothing.

### TST-030 — medium — duplicate-bloat
Overlapping coverage (same production lines, extra suite time):
- Sitemap: `test-sitemap.php` vs `test-sitemap-providers.php` (core query args, cache generation).
- Uninstall source regex: `test-reset-actions.php:309-311` vs `test-uninstall-cleanup.php`.
- Admin page render: `test-admin-menu.php` vs `test-admin-settings-panels.php`.
- Meta/robots/primary term/image: `test-meta-helpers.php` vs `test-helpers-global-meta.php`.
- JSON-LD validate: `test-schema.php` vs `test-meta-helpers.php` vs `test-schema-jsonld.php:365-370`.
- unfiltered_html custom code: `test-custom-code-boundaries.php` vs `test-robots-and-custom-code.php`.
- Breadcrumb schema emit/native: `test-breadcrumbs.php` vs `test-breadcrumbs-native.php`.
- Sample post lookup: `test-meta-helpers.php` vs `test-helpers-template-variables.php` (names disagree: “first match” vs “latest published”).
**Action:** Keep one behavioral owner per contract; leave a single smoke test in the other file.

### TST-031 — medium — CSS-as-test
**File:** `tests/test-admin-renderers.php:307-321`
**Why:** Opening-hours “closed hides intervals” is a substring contract on `assets/css/admin-settings.css`. Does not prove the selector matches the rendered markup (`data-erankly-day-closed`, `is-closed`). JS `classList.toggle` is also unprobed (TST-013).
**Action:** Probe or DOM assertion; treat CSS strings as optional snapshot.

### TST-032 — medium — live-audit-mutates-site / not-isolated
**File:** `tests/live-schema-audit.php:156-882`
**Why:** Writes real pages, `erankly_update_plugin_settings`, REST saves, then restores in `finally`. Depends on blog 1 `/esempio/`, blog 2 `/example/`, and `/en/` permalinks. Registers stub `event` / `tribe_events` post types — not The Events Calendar. Failures still restore, but a fatal before `finally` would leave settings dirty. Not in PHPUnit.
**Action:** Do not use as CI. Pin fixtures or skip when `esempio`/`example` missing instead of fetching `home_url('/esempio/')` (376).

### TST-033 — low — docs-not-tests
**File:** `tests/schema-recheck.md:1-66`
**Why:** Manual recheck log. States PHPUnit was not installed in that environment (51, 62). WooCommerce Product skipped; real TEC not installed.
**Action:** Ignore as evidence of current production.

### TST-034 — low — skip-noise
Many legitimate skips: Node (`test-js-contracts.php:102-135`), Multisite (`test-lifecycle.php:995+`, network-reset, uninstall, rest-user-search, custom-code), SQLite reset, `headers_sent`, private migration storage (`test-migration-internals.php:76+`), WP_DEBUG reset log (`test-reset-actions.php:55`), wall-clock (`test-network-reset.php:63`).
**Action:** Track skip rates in CI; a default job that skips MS + SQLite DROP + Node + core breadcrumbs hides large surfaces.

### TST-035 — low — mixed block comment form (not proven wrong)
**File:** `tests/test-breadcrumbs.php:479-486, 762, 802-950` uses `<!-- wp:core/breadcrumbs -->`; `test-breadcrumbs-native.php` uses `<!-- wp:breadcrumbs -->`. WP parser accepts both as `core/breadcrumbs`. Inconsistency, not a false green by itself.

### TST-036 — medium — missing-positive-flush
**File:** `tests/test-helpers-cache.php:145-162`
**Why:** `erankly_flush_sitemap_cache_for_post` is only asserted **not** to bump generation for revisions/drafts. No published-post positive bump. Same for status transition (164-173): only unchanged/non-public.
**Action:** Assert generation increments for a public publish.

### TST-037 — medium — OG image fallbacks untested
**File:** `tests/test-opengraph-output.php:221-239` vs `includes/opengraph.php:327-358`
**Why:** Tests per-post URL then `default_social_image_url`. Production also uses `_erankly_og_image_id`, featured image, first content image, term meta, author meta. Those branches have no test.
**Action:** Featured-image and content-image fallbacks at minimum.

### TST-038 — medium — canonical holes
**File:** `tests/test-canonical.php` (full 139)
**Why:** Covers singular custom/permalink, front, posts page, category ± meta, search, 404. No author archive, date archive, post-type archive, paged singular `page/N`, or attachment. Paged archive helper lives in hreflang tests, not `erankly_get_canonical()`.
**Action:** Author/date/CPT archive + paged singular.

### TST-039 — medium — JS editor schema UX untested in PHPUnit
**File:** `tests/schema-recheck.md:14-27` (Playwright notes only)
**Why:** Invalid JSON-LD `aria-invalid`, Gutenberg persist, mobile overflow are not in PHPUnit or probes. `schema-jsonld.js` only has a textdomain string lock.
**Action:** Probe or PHPUnit+JSDOM for `isValidTypeName` and invalid-state DOM.

### TST-040 — low — bootstraps/helpers are sound
**File:** `tests/bootstrap.php:70-87`
**Why:** `erankly_tests_set_settings` correctly uses site option on Multisite. Comment documents why `update_option` is wrong on MS. Not a defect.

---

## Production risk areas with weak/no tests

1. **Redirect 301/302 Location** — runner `wp_safe_redirect`, external host allowlist on a real match, hit sample-rate default (not forced 1), REST create/update/list, admin save with nonce.
2. **Attachment redirect** — `parent` and file/CDN 301 + `exit`.
3. **Feed `X-Robots-Tag` header** — tag string tested; `header()` not.
4. **OG image** — featured, attachment id, first content image, author/term.
5. **Canonical** — author/date/CPT archive, paged singular, attachment.
6. **WooCommerce Product JSON-LD on a product URL** — doubles only; live-audit PASSes skip; no real WC.
7. **External SEO head owner** — memoised; conflict notice never shown in tests.
8. **Rank Math / AIOSEO table adapters** — empty-without-table asserted as success.
9. **Legacy breadcrumb inserter when core is deregistered at init** — skipped unless special env before bootstrap.
10. **Sitemap output buffer when `sitemap` query var is set**; core 304 headers in PHPUnit SAPI.
11. **Site reset DROP TABLE** on SQLite CI.
12. **`redirects.js`, accordion FAQ schema JS, Gutenberg schema validation, import-export JS, media field JS.**
13. **Hreflang renderer with a real multilingual provider** — only “no provider ⇒ empty output” (`test-hreflang-canonical.php:170-177`).
14. **Network reset worker on a live switch_to_blog network** — many lifecycle tests skip without live MS blogs fixture.
15. **Settings lock CAS** (`includes/settings-lock.php` compare-delete) — lifecycle covers acquire/renew/expiry; not concurrent writers.
16. **HowTo/Event/TEC** — unit helpers in `test-schema-content.php` / `test-schema.php`; frontend graph + real TEC plugin only in excluded live audit / stub CPT.
17. **JSON-LD XSS in printed `<script>`** — live-audit only (excluded).
18. **`erankly_flush_sitemap_cache_for_post` positive path.**
19. **Person identity + LocalBusiness location field JS** — PHP visibility helpers tested (`test-schema-jsonld.php:671-712`); `admin-schema.js` only string-locked.

---

## Remaining ranges

None. All listed files fully read.
