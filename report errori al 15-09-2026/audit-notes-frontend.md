# Frontend / i18n / root docs audit

Working tree of `/Users/enthonysclafani/Studio/easyrankly/wp-content/plugins/easyrankly` (including uncommitted changes). Read-only. No plugin/test/git writes.

Coverage: every listed file read with Read + offsets. POT 3470 lines in 400-line chunks. composer.lock necessity-only (packages empty; packages-dev is PHPUnit graph). Unused msgid/CSS/JS hunts: extract + grep/python cross-check (grep is not coverage; it is the unused-proof).

---

## 1. Coverage table

| path | lines | status |
| --- | ---: | --- |
| assets/js/accordion-faq-schema.js | 69 | complete |
| assets/js/admin-fields.js | 107 | complete |
| assets/js/admin-import-export.js | 17 | complete |
| assets/js/admin-media.js | 97 | complete |
| assets/js/admin-panels.js | 79 | complete |
| assets/js/admin-reset.js | 210 | complete |
| assets/js/admin-schema.js | 468 | complete |
| assets/js/admin-settings.js | 674 | complete |
| assets/js/admin-tabs.js | 606 | complete |
| assets/js/admin-variables.js | 443 | complete |
| assets/js/admin-widgets.js | 769 | complete |
| assets/js/admin.js | 57 | complete |
| assets/js/editor-shared.js | 1098 | complete |
| assets/js/editor.js | 506 | complete |
| assets/js/redirects.js | 357 | complete |
| assets/js/schema-jsonld.js | 206 | complete |
| assets/js/site-editor.js | 248 | complete |
| assets/css/admin-core.css | 761 | complete |
| assets/css/admin-settings.css | 1087 | complete |
| assets/css/classic-editor.css | 35 | complete |
| assets/css/editor.css | 218 | complete |
| assets/css/migration.css | 414 | complete |
| assets/css/redirects.css | 143 | complete |
| assets/css/reset.css | 54 | complete |
| assets/css/shared.css | 195 | complete |
| blocks/breadcrumbs/block.json | 16 | complete |
| blocks/breadcrumbs/index.js | 69 | complete |
| languages/easyrankly.pot | 3470 | complete (every line read; unused hunt via extract+grep) |
| readme.txt | 124 | complete |
| WORDPRESS-ORG-READINESS.md | 405 | complete |
| license.txt | 338 | complete (necessity/license only) |
| tools/build-dist.sh | 65 | complete |
| .github/workflows/phpunit.yml | 139 | complete |
| .distignore | 19 | complete |
| .gitignore | 6 | complete |
| composer.json | 21 | complete |
| composer.lock | 1865 | necessity-reviewed (`packages: []`; `packages-dev` = phpunit + yoast polyfills + transitive) |
| .commandcode/taste/taste.md | 8 | complete (dev-only, gitignored + distignored) |
| phpunit.xml.dist | 14 | complete |
| **total** | **15477** | **no remaining unread ranges** |

---

## 2. Findings (FE-)

### FE-001 — P2 — latent throw if `wp` undefined
**file:** `assets/js/accordion-faq-schema.js:5-10`, `assets/js/editor-shared.js:14-26`, `assets/js/editor.js:5-23`, `blocks/breadcrumbs/index.js:4-7`
**why:** Top-level destructure of `wp.compose` / `wp.blockEditor` / `wp.components` / `wp.blocks` / `wp.i18n` with no existence check. `ReferenceError` if `wp` is missing. Enqueued with `wp-*` deps so the WordPress editor path is safe; Node probes or a mis-enqueued copy are not. `schema-jsonld.js:8-12` is the only editor helper that guards.
**action:** Match `schema-jsonld.js`: bail if `window.wp` is missing before any property access.

### FE-002 — P2 — dead guard / throw before bail
**file:** `assets/js/site-editor.js:15-21`
**why:** `const wpEditor = wp.editor || {}` runs before `if ( ! shared || ! config || ! PluginDocumentSettingPanel || ! wp.apiFetch || ! wp.plugins ) return`. The intended quiet bail never runs when `wp` is undefined.
**action:** Move the `wp` existence check above the first `wp.` access.

### FE-003 — P2 — editor.js has no shared/config bail
**file:** `assets/js/editor.js:5,23,65,164-176,490-505`
**why:** `site-editor.js` bails if `window.eranklyShared` / config / `PluginDocumentSettingPanel` is missing. `editor.js` immediately uses `eranklyEditor` and later `shared.searchAppearanceFields(...)`. Missing localize or a failed `editor-shared.js` parse throws on first panel render (`Cannot read properties of undefined`).
**action:** Same early return as `site-editor.js`.

### FE-004 — P2 — reset modal null deref
**file:** `assets/js/admin-reset.js:35-53`
**why:** `openModal` writes `titleEl.textContent`, `descEl.textContent`, `confirmBtn.textContent` with no null checks. Missing `[data-erankly-reset-modal-title|desc|confirm]` throws and leaves `erankly-modal-open` unset/inconsistent. Cancel focus uses `(cancelBtn || confirmBtn).focus()` which is safe only if at least one exists.
**action:** Return early if title/desc/confirm nodes are missing.

### FE-005 — P2 — duplicate listbox ids
**file:** `assets/js/admin-variables.js:283-287`
**why:** If the field control has no `id`, every picker menu becomes `id="erankly-variable-listbox"`. Duplicate IDs break `aria-controls` / `aria-activedescendant`.
**action:** Always mint a unique id (counter or `crypto.randomUUID` fallback).

### FE-006 — P2 — 960px inclusive breakpoint clash
**file:** `assets/js/admin-panels.js:12-18` vs `assets/css/admin-core.css:727-735` vs `assets/css/redirects.css:128-142`
**why:** JS allows expand when `min-width: 960px`. CSS stacks the toolbar / redirects search at `max-width: 960px`. At **exactly 960px** both apply: expand is allowed while the stacked “phone” layout is already on. JS comment says expand below that width squeezes seven columns to ~45px.
**action:** Use one exclusive edge, e.g. JS `min-width: 961px` or CSS `max-width: 959px`.

### FE-007 — P2 — editor vs settings variable-preview divergence
**file:** `assets/js/editor-shared.js:178-186` vs `assets/js/admin-variables.js:149-157`
**why:** Settings preview uses `hasOwnProperty` and will show an empty example. Editor `resolveDisplayVariables` requires a truthy `resolved[key]`, so `""` (empty term description, empty excerpt, etc.) becomes “Preview not available”. Comment in editor-shared claims the two surfaces agree.
**action:** Use the same `hasOwnProperty` rule as `admin-variables.js`.

### FE-008 — P2 — classic vs block-editor schema warning copy
**file:** `assets/js/editor.js:330` vs POT `languages/easyrankly.pot:844` / classic `admin/meta-box.php:381`
**why:** Classic: “Automatic schema ignores those blocks. Switch to Automatic + custom schema to emit them, or remove the unused blocks.” Block editor: “…until you switch to Automatic + custom schema.” Same UX, two msgids, translators must maintain both; users see different advice.
**action:** Unify the msgid (and POT) across classic + `editor.js`.

### FE-009 — P2 — i18n domain mismatch
**file:** `includes/breadcrumbs.php:706`
**why:** `__( '(no title)' );` with phpcs ignore, no `'easyrankly'` domain. Uses the default WordPress text domain so it tracks core’s untitled fallback. It is **not** in `easyrankly.pot` and EasyRankly translators cannot change it. Audit asked for domain mismatches.
**action:** Keep if core-parity is required; document in POT comments. Otherwise pass `'easyrankly'` and add the msgid.

### FE-010 — P2 — POT stale vs working-tree breadcrumbs
**file:** `languages/easyrankly.pot` header `POT-Creation-Date: 2026-09-14T21:01:41+00:00`; refs `includes/breadcrumbs.php:122,236,403,411`
**why:** Current `Home` is line 130, `Page not found` 244, trail help strings 442/450 (PHP uses escaped `WordPress\'s` / `EasyRankly\'s`). Msgids still exist in source; line references and apostrophe encoding are stale. WP-CLI makepot would refresh.
**action:** Regenerate POT from the working tree after breadcrumb work.

### FE-011 — P2 — TEMP CSS hides shipped nav
**file:** `assets/css/admin-core.css:758-761`; markup `admin/settings/page-renderer.php:279-283`
**why:** Comment `TEMP: nasconde la sezione "Useful resources" finché il sito non è pronto — da rimuovere` sets `display: none` on the docs/help nav. PHP still renders Documentation / Need help? (and POT still has those msgids). Hidden outbound links + leftover Italian TEMP in production CSS.
**action:** Remove the TEMP rule when docs are ready, or stop rendering the section until then.

### FE-012 — P3 — unused CSS class
**file:** `assets/css/admin-core.css:499-501`
**why:** `.erankly-user-search-wrap` is never applied. Markup uses `[data-erankly-user-search-wrap]` only (`admin/settings/panels.php:130`).
**action:** Drop the class selector or add the class in PHP.

### FE-013 — P3 — unused localized JS strings
**file:** `admin/assets/settings.php:75-76,223` (payload); never read in `assets/js/admin-widgets.js` or `assets/js/redirects.js`
**why:** `eranklyUserSearch.i18n.remove`, `i18n.noSelected`, `eranklyRedirects.emptyTable` are localized and never referenced. `eranklyLocalBusiness.i18n.loadMore` (`settings.php:131`) is unused in JS (the PHP button already has “Load more sites”).
**action:** Use them or stop localizing them.

### FE-014 — P3 — unused JS exports (dead API surface)
Assigned on `window.ERanklyAdmin` / `window.eranklyShared` with no production callers (only the defining file):
- `assets/js/admin-schema.js:435-438` — `isValidJsonLd`, `setSchemaBlockExpanded`, `updateSchemaBuilderState`, `bindSchemaBlock`
- `assets/js/admin-variables.js:435-441` — `getActiveVariableToken`, `filterVariablePicker`, `insertVariable`, `resolveVariablePreviewText`, `bindVariablePreview`, `renderVariablePreview`, `bindVariablePicker` (plural `bindVariablePickers` **is** used)
- `assets/js/admin-settings.js:670-672` — `bindSettingsReplacement`, `refreshSettingsRoot`, `bindSettingsAutosave` (internal only)
- `assets/js/admin-tabs.js:600-604` — `getLinkedFieldName`, `getLinkedDefaultFields`, `getLinkedDefaultSource`, `syncLinkedDefaultFields`, `setLinkedDefaultsState`
- `assets/js/editor-shared.js:1086-1096` — `SocialImageControl`, `VariableControl`, `getRobotsDirectiveInconsistencies`, `normalizeRobotsDirectiveToken`, `resolveRobotsDirectiveTokens`, `selectRobotsDirectiveToken` (no `eranklyShared.*` reads in tests either)
**action:** Stop exporting, or add a comment that they are the add-on/test API.

### FE-015 — P3 — JS class names with no CSS
- `blocks/breadcrumbs/index.js:52,60` — `erankly-breadcrumbs-editor`, `erankly-breadcrumbs-editor__hint` (unstyled editor placeholder)
- `assets/js/editor-shared.js:461` — `erankly-editor-variable-field--multiline` (modifier never styled)
- `assets/js/editor-shared.js:548` — `erankly-social-image-alt-override` (no CSS)
- `assets/js/admin-widgets.js:84,100` — `erankly-user-result-status` (status uses `.erankly-autocomplete-status` only)
**action:** Add styles or drop the unused class names.

### FE-016 — P3 — editor vs settings schema builder divergence
**file:** `assets/js/editor.js:99-119,311-316` vs `assets/js/admin-schema.js:214-231,45-53`
**why:** Settings respects `data-erankly-max-blocks` and full `eranklyJsonLd.validate`. Post editor `addBlock()` has no cap; `validateJsonLd` fallback is `JSON.parse` only (no @type/@id semantics) if `eranklyJsonLd` is missing. Fallback path: `admin-schema.js:50-52` treats any non-empty string as invalid when the helper is absent (`trim() === ""`).
**action:** Share max-blocks + always enqueue `schema-jsonld.js` (already a dep) and drop the weak fallback or make it call the same validator.

### FE-017 — P3 — Local Business list null deref
**file:** `assets/js/admin-widgets.js:618-656`
**why:** `list = siteRoot.querySelector("[data-erankly-local-business-site-list]")` is not null-checked. `sites.forEach(site => appendSite(list, site))` then `list.querySelector` throws if the list node is missing.
**action:** Return if `!list`.

### FE-018 — P3 — docs stale vs lockfile
**file:** `WORDPRESS-ORG-READINESS.md:15,19,60,195,310,382`
**why:** Report says `composer.lock` pins `doctrine/instantiator` 2.1.0 (`php ^8.4`) and CI 8.0/8.3 `composer install` fails. Working-tree lock has **instantiator 1.5.0** (`php ^7.1 || ^8.0`), `packages: []`, `packages-dev` only. Re-verify install before treating the P2 tooling gate as current.
**action:** Refresh the readiness doc after `composer install --dry-run` on 8.0/8.3.

### FE-019 — P3 — Plugin Check CI swallows warnings
**file:** `.github/workflows/phpunit.yml:136`
**why:** `ignore-warnings: true` while the readiness doc tracks PCP warnings as the remaining Directory signal.
**action:** Fail on new warnings or document why they are ignored.

### FE-020 — P3 — block.json inserter always false
**file:** `blocks/breadcrumbs/block.json:14` vs `blocks/breadcrumbs/index.js:31` vs PHP `includes/breadcrumbs.php:1013-1017`
**why:** Manifest says `"inserter": false` unconditionally. PHP + JS set `inserter: ! coreAvailable`. Intended (tests lock both). A reader of block.json alone thinks the block is never insertable; on WP 6.5–6.9 JS/PHP re-enable it. Description line 8 is accurate only after JS runs.
**action:** Optional: set inserter in block.json to true and let PHP/JS hide it on 7.0+, or add a comment in block.json.

### FE-021 — P3 — dropzone absolute input without containing block
**file:** `assets/css/migration.css:342-380`
**why:** `.erankly-dropzone-input` is `position: absolute` but `.erankly-dropzone` is not `position: relative`. The 1px clipped input positions against a higher ancestor. Label `for=` still opens the file picker; layout is slightly fragile.
**action:** `position: relative` on `.erankly-dropzone`.

### FE-022 — P3 — `color-mix` / `:has()` without fallback
**file:** `assets/css/admin-settings.css:467,513,730`; `assets/css/shared.css:37-38`; `assets/css/migration.css:367`
**why:** Tab colors, notice surfaces, and dropzone dragover use `color-mix`. Autocomplete grid uses `:has()`. Unsupported engines drop the declaration (tabs may lose selected background). WP 6.5 is the min version; some still-listed browsers lack `color-mix`.
**action:** Solid color fallback before `color-mix`.

### FE-023 — P3 — redirects `response.json()` assumes JSON
**file:** `assets/js/redirects.js:20-27,327-333`
**why:** Non-JSON error bodies (HTML 403 from a WAF) make `response.json()` reject; catch shows a generic message. Fine, but `emptyTable` was localized as if JS owned the empty state (it does not).
**action:** `response.text()` + JSON.parse try/catch; remove unused `emptyTable`.

### FE-024 — P3 — license / packaging
**file:** `license.txt` (GPL-2.0 full text, necessary); `composer.json` require-dev only; `composer.lock` not shipped (`.distignore` + `build-dist.sh` rm); `phpunit.xml.dist` excluded; `.commandcode/taste/taste.md` user taste (Italian replies), not product code.
**why:** GPL v2 file vs header `GPL-2.0-or-later` is the usual pairing. No production Composer packages. No finding to rewrite license.txt.
**action:** None for license. Keep lock as dev-only.

### FE-025 — P3 — readme vs plugin header description
**file:** `readme.txt:11` vs `easyrankly.php:5` / POT description msgid
**why:** Header/POT: “Lightweight, modular, developer-first SEO essentials for WordPress.” Short description: “Take control of your WordPress SEO with simple, fast, and flexible tools.” Not a functional bug; Directory shows the header description.
**action:** Align if Directory copy should match readme.

---

## 3. Unused CSS / JS / translations

### Unused CSS selectors
| selector | defined | used in PHP/JS? |
| --- | --- | --- |
| `.erankly-user-search-wrap` | admin-core.css:500 | **no** (only `[data-erankly-user-search-wrap]`) |
| `.erankly-migration-card--success` | migration.css:9 | **yes** (dynamic `erankly-migration-card--{$tone}`, tone `success`) |
| `.erankly-migration-card--error` | migration.css:17 | **yes** (tone `error`) |
| `.wp-core-ui` in comment | admin-settings.css:989 | comment only, not a dead rule |

Reserved (add-on), not unused: `.erankly-panel--translations` in `editor.css:107-175` + `PANEL_ORDER` in `editor-shared.js:38`.

### JS classes with no CSS
`erankly-breadcrumbs-editor`, `erankly-breadcrumbs-editor__hint`, `erankly-editor-variable-field--multiline`, `erankly-social-image-alt-override`, `erankly-user-result-status`.

### Unused JS functions / exports
See FE-014. Internally used (not unused): `bindVariablePickers`, `closeVariablePicker`, `bindTabs`, `bindSettingsTabs`, `bindLinkedDefaults`, `bindAllSettingsAutosave`, `bindResetConfirmModal`, `bindSchemaBuilder`, `bindPostSchemaPanel`, `bindSchemaIdentityField`, `syncOrganizationFieldsVisibility`, `focusInvalidJsonLd`, `bindUserSearch`, `bindLocalBusiness`, `bindMediaUrlField`, `bindCharacterCounter`, `bindFileDropzone`, `moveTermDocLink`, `bindExpandablePanel`, `bindSimplifiedModeNav`. `bindSchemaTargeting` is internal-only (not exported) — fine.

Dead localized keys: `remove`, `noSelected`, `emptyTable`, `loadMore` (JS i18n object).

Hardcoded English fallbacks (not POT; used if localize missing): admin-fields `"too long"`; admin-panels Expand/Collapse table; admin-variables `"Preview not available"`; admin-schema JSON error; admin-settings Saving/Saved/…; redirects Yes/No/Enable/Disable/test strings; widgets Searching/Retry.

### Translations
- **Unused msgids:** none confirmed. Apostrophe-escaped PHP strings (`site\'s`, `WordPress\'s`) still match POT unescaped msgids. Ellipsis/`→`/`—` strings are used.
- **Missing from POT:** `__( '(no title)' )` in `includes/breadcrumbs.php:706` (default domain, FE-009).
- **Stale POT refs:** breadcrumbs.php line numbers (FE-010).
- **Copy drift (two msgids):** schema custom-JSON warning classic vs `editor.js` (FE-008).
- **Domain:** all other `__` / `_e` / `esc_html_e` / JS `__( …, 'easyrankly' )` use `easyrankly`. `block.json` `"textdomain": "easyrankly"`. Plugin header `Text Domain: easyrankly` / `Domain Path: /languages`. No `load_plugin_textdomain()` (WP 4.6+ auto-load; acceptable).
- **Hidden but still translated:** Useful resources / Documentation / Need help? (FE-011).

### JS throw-if-wp-undefined (summary)
| file | guards `wp`? |
| --- | --- |
| schema-jsonld.js | yes |
| admin-schema.js | yes (optional `window.wp.i18n`) |
| site-editor.js | **no** (uses `wp` then bails) |
| editor.js | **no** |
| editor-shared.js | **no** |
| accordion-faq-schema.js | **no** |
| blocks/breadcrumbs/index.js | **no** |
| admin-*.js, redirects.js, admin-import-export.js | N/A (no `wp` global) |

---

## 4. Remaining ranges

None. All listed paths read. composer.lock not line-audited for every hash; confirmed `packages: []` and packages-dev names: doctrine/instantiator, myclabs/deep-copy, nikic/php-parser, phar-io/*, phpunit/*, sebastian/*, theseer/tokenizer, yoast/phpunit-polyfills.
