# EasyRankly admin PHP line-by-line audit

Read-only. Working tree (including uncommitted) is authoritative. Grep used only to verify *usage* of symbols/classes, never as coverage of the listed files.

Context-only (not in coverage table): `easyrankly.php` 794–1130 (REST user search, local-business, settings autosave, special-pages autosave).

## Coverage

| path | lines | status |
|---|---|---|
| includes/admin.php | 1–503 | complete |
| admin/assets/editor.php | 1–211 | complete |
| admin/assets/settings.php | 1–248 | complete |
| admin/field-renderers.php | 1–274 | complete |
| admin/meta-box.php | 1–642 | complete |
| admin/meta-box/post-saver.php | 1–179 | complete |
| admin/meta-box/term-saver.php | 1–112 | complete |
| admin/settings-page.php | 1–534 | complete |
| admin/settings/nav-icons.php | 1–53 | complete |
| admin/settings/page-renderer.php | 1–371 | complete |
| admin/settings/panels.php | 1–448 | complete |
| admin/settings/renderers.php | 1–771 | complete |
| admin/settings/section-links.php | 1–96 | complete |
| easyrankly.php 794–1130 | context | not re-audited as bootstrap |

No remaining unread ranges in the listed files.

---

## Findings

### ADM-001 — high — gutenberg_divergence — admin/meta-box.php:110-138 vs assets/js/editor.js META_MAP

Classic Search appearance renders Primary {taxonomy} dropdowns (`erankly_primary_terms[tax]`) when the post already has terms. Gutenberg `editor.js` does not map or render `_erankly_primary_terms` at all. Frontend breadcrumbs/schema read that meta (`erankly_get_primary_term` path in includes/helpers/global-meta.php). Block-editor users cannot set or change a migrated Yoast/Rank Math primary category. Classic-only UI for a post-only key.

**Action:** Add primary-term controls to the block-editor sidebar (or drop classic UI if the product decision is “automatic only”). Do not leave one editor as the only writer.

### ADM-002 — high — data_loss — admin/meta-box/post-saver.php:129-143 + admin/meta-box.php:275-278

File header says fields not rendered are skipped to keep stored values. `_erankly_exclude_from_news` is always in `$booleans` and is deleted when the checkbox is absent. The checkbox is only rendered when `enable_news_sitemap` is on. Saving any post while the news sitemap module is off (or after it is disabled) silently clears a stored exclusion. Gutenberg only touches this key when `FEATURES.newsSitemap` is true, so it *preserves* the flag. Classic vs block save diverge.

**Action:** Gate the boolean write the same way as breadcrumb_name: skip unless the news-sitemap setting is enabled (or the field was in the POST).

### ADM-003 — high — capability_gap — includes/admin.php:29-31 + admin/assets/editor.php:147-153 + admin/settings/panels.php:188-196

`erankly_use_site_editor_special_page_panels()` is only `wp_is_block_theme() && WP >= 6.6`. It does not consider whether the current user can open the Site Editor. On that combo the settings fallback is omitted (`preserve_global_special_meta` hidden input / no special-pages section). Site Editor assets require **both** `edit_theme_options` **and** `manage_options`. A `manage_options` role without `edit_theme_options` has no UI to edit special-page SEO.

**Action:** Treat “panels available” as theme+version **and** current-user caps; if the user cannot load Site Editor assets, keep the settings fallback.

### ADM-004 — medium — wrong_surface — admin/assets/settings.php:15-37

`$is_editor` is “screen post_type is a public type”, not “this is post.php/post-new.php”. `edit.php` list tables have `post_type=post` and `is_block_editor()=false`, so they take the classic-editor branch: `admin-core.css`, `admin-settings.css`, `classic-editor.css`, `wp_enqueue_media()`, schema-jsonld, local-business REST config, variable preview, panels i18n. Attachments are excluded by `erankly_get_public_post_types()`, so Media Library is safe; Posts/Pages/CPTs lists are not. JS is mostly a no-op without matching DOM, but media frames are expensive.

**Action:** Require `$screen->base` in `{post,post-new}` (classic) / block-editor flag (block) before treating the screen as an editor.

### ADM-005 — medium — gutenberg_divergence — admin/settings/renderers.php:642-644,721-731 vs assets/js/site-editor.js:104-112 + editor-shared.js:935-946

PHP special-page visibility: 7th arg `'author' === $key` means the Disable-sitemap checkbox is shown **only for author**; other contexts hide-from-search in simplified mode is **noindex only**. Site Editor `featuresForContext` correctly sets `disableSitemap: 'author' === context` but does **not** set `triStateRobots`. Simplified `visibilityFields` then always requires `noindex && disable_sitemap` for the Hide checkbox. A 404/search row that is noindex (PHP default) shows as not hidden in Site Editor. Checking Hide in Site Editor also writes `disable_sitemap` on contexts that are not in the sitemap.

**Action:** Pass a feature flag so simplified hide matches PHP (`noindex` only when `disableSitemap` is false).

### ADM-006 — medium — incomplete_ui — admin/settings/page-renderer.php:181,320-338

Comment at file head: noscript submit is the no-JS save path. Special-pages is in `$standalone_panels`, so it never gets that noscript button. The Multisite per-site fallback form (admin-post.php, nonce `erankly_site_special_meta`) has **no submit control**. JS autosave covers it (`eranklySettingsAutosave.panels['special-pages']`). No-JS / failed JS: only incidental Enter-in-input submits. Network/single-site autosave panels still get noscript inside the main form.

**Action:** Add a noscript `submit_button()` inside the special-pages form (and keep it out of the JS-visible layout if desired).

### ADM-007 — medium — extension_api — includes/admin.php:87-109,158-173 vs admin/settings/page-renderer.php:86-106,120-122,351-366

Docblock on `erankly_admin_resolve_settings_tab()`: unknown slugs are preserved for `erankly_settings_tabs`. That is true on single-site / network admin. On `$is_site_admin_on_network` the allowlist is only `erankly_admin_site_settings_tabs` (special-pages / redirects by default). Tests even assert `'my-addon'` resolves to `special-pages`. Meanwhile page-renderer still prints extra tabs from `erankly_settings_tabs` onto the per-site screen and canonicalize **redirects those URLs away**. Two filters must be kept in sync or addon nav is dead.

**Action:** Build the per-site allowlist from the same extra-tab set the renderer uses, or document that addons **must** hook both filters. Fix the docblock.

### ADM-008 — medium — gutenberg_divergence — admin/meta-box.php:2-6,62-142 vs admin/assets/editor.php:24-88

Header claims `erankly_render_post_*_fields()` are shared with `assets/js/editor.js`. They are not. Gutenberg rebuilds the same ideas in `editor-shared.js` + `editor.js`. PHP is classic/taxonomy only. Drift already exists (ADM-001, copy differences, primary terms, news exclusion). Block enqueue still `require_once admin/meta-box.php` solely for placeholder helpers.

**Action:** Move placeholder helpers to a small helper file; stop claiming PHP field renderers are shared with Gutenberg.

### ADM-009 — medium — incomplete_ui — admin/settings/panels.php:13-16 + includes/helpers/sanitization.php (custom code toggle)

Features tab lets anyone with `manage_options` check “Enable custom code”. `erankly_sanitize_custom_code_toggle()` reverts the change without `unfiltered_html` and adds a settings error. Custom-code builder still renders Delete (field-renderers.php:231) while the textarea is readonly; block sanitizer also refuses mutations without `unfiltered_html`. On default single-site admins this is fine; `DISALLOW_UNFILTERED_HTML` / locked-down roles get a lying UI.

**Action:** Disable the Features checkbox and hide Delete when `! current_user_can('unfiltered_html')`, matching the readonly textarea.

### ADM-010 — medium — rest_wipe — easyrankly.php:1108-1114 (context)

`erankly_rest_save_special_pages()` uses `global_special_meta` or `array()` and writes through `erankly_update_special_meta_map()`. A payload missing that key stores an empty map and drops default noindex on search/404. Autosave serialize of the PHP panel should send the nested keys; a client bug or hand-rolled REST call is a 1/1000 wipe. Contrast: `erankly_rest_save_settings_panel()` merges onto `erankly_get_settings()` first.

**Action:** Reject empty/missing maps or merge onto the stored special-meta map before sanitize.

### ADM-011 — low — placeholder_vs_counter — admin/meta-box.php:70-82 + admin/assets/editor.php:74

Title placeholder is trimmed to **70**; the classic counter and Gutenberg `VariableControl` warn at **65**. Description 160/160 and OG 60/60 match. Only the meta title is inconsistent (both editors).

**Action:** Use 65 (or 70) in both the trim and the counter.

### ADM-012 — low — dead_doc_link — admin/meta-box.php:464,474 + admin/settings/section-links.php:4-16

`erankly_render_section_doc_link('term-meta')` — no `term-meta` key in `erankly_section_doc_links()`. Always no-ops. Still emits `<p class="erankly-term-doc-link">` so `moveTermDocLink` runs, finds no `<a>`, and bails. Empty paragraph on add/edit term.

**Action:** Add a URL or stop wrapping when the section has no link.

### ADM-013 — low — incomplete_docs — admin/settings/panels.php + section-links.php:8-15

`erankly_section_open(..., array('doc' => ...))` is called with `feature-modules`, `site-identity`, `post-type-defaults`, `taxonomy-defaults`, `special-pages`, `default-images`, `social-defaults`, `social-profiles`, `preferences`, `indexing-robots`, `robots-txt`, `pagination`, `attachment-pages`. Only sitemap/schema URLs exist. File comment says missing sections render no link — true, so most “Learn more” slots are dead args. Not a functional bug.

**Action:** Add URLs or drop unused `doc` keys to reduce comment/arg noise.

### ADM-014 — low — comment_lie — admin/settings/renderers.php:741-742 vs :642

Call site passes `'author' === $key` as `$show_disable_sitemap`. The else branch (hidden disable-sitemap field) is for **non-author** contexts. Comment says “No checkbox for this entity (author archives)” — inverted. Site-editor.js comment is the correct one.

**Action:** Fix the comment.

### ADM-015 — low — comment_lie — includes/admin.php:87-88

“Unknown slugs are preserved for extension tabs” is false on Multisite per-site (see ADM-007).

### ADM-016 — low — over_engineered_include — admin/assets/editor.php:29-30,104

Block-editor enqueue loads all of `admin/meta-box.php` (and thus post-saver, term-saver, field-renderers, section-links) for `erankly_get_post_global_*_placeholder()`. `erankly_enqueue_editor_shared_assets()` requires `section-links.php` and never calls it (block-editor path requires it again for `erankly_section_doc_links()`).

**Action:** Move placeholders next to other meta helpers; drop the unused require.

### ADM-017 — low — incomplete_localize — admin/assets/editor.php:177-196

Site Editor config: empty `titlePlaceholder` / `descriptionPlaceholder`; no `canonicalPlaceholder` (unused because canonical is off). Post editor gets resolved placeholders. Special-page PHP fallback also starts empty. Minor, but the React controls then have no example text.

### ADM-018 — low — dead_reads — admin/meta-box.php:231-233,487-489

`$noindex`, `$nofollow`, `$noarchive` are loaded in post visibility and term fields and never used. Hide-from-search uses `index_directive` + `disable_sitemap`. Leftover from the legacy boolean UI.

**Action:** Delete the unused assignments.

### ADM-019 — low — thin_wrappers — admin/meta-box.php:59-61; includes/admin.php:323-351

`erankly_get_term_global_meta_placeholder()` is a one-line alias of `erankly_get_global_taxonomy_meta()`. Plugin action-link helpers are three functions for one `sprintf`. Harmless.

### ADM-020 — low — api_footgun — admin/settings/nav-icons.php:19-52

`erankly_nav_icons()` applies `erankly_nav_icons` then **overwrites** `special-pages` with the general icon, so the filter cannot customize that slug. `erankly_nav_icon()` interpolates raw path markup; callers `wp_kses()` it. Safe at current call sites; unsafe if echoed raw.

### ADM-021 — low — copy_divergence — admin/meta-box.php:272-273 vs editor-shared.js:1057-1078

Classic: “Exclude from site search” / “Exclude from archives” / “Exclude this page from Google News sitemap”. Gutenberg: “Exclude from site search queries” / “Exclude from archive queries” / “Exclude from Google News sitemap”. Same flags, different strings.

### ADM-022 — low — a11y — admin/settings/page-renderer.php:234-236,347

Sidebar toggle label span is empty until JS. Redirects panel omits `id="erankly-settings-panel-redirects"` while `aria-labelledby` points at the nav tab (other panels set a matching panel id).

### ADM-023 — nit — html — admin/settings/page-renderer.php:218-295

On Multisite per-site, no main `<form>` opens, but the `erankly_settings_panel` hidden input is still printed as an orphan, then the special-pages form follows. Browsers ignore it.

### ADM-024 — nit — over_engineered — includes/admin.php:393-405,473-486; admin/settings/section-links.php:39-49

`blocks` is an alias of `schema` (same handle/file). Addon-tab fallback uses `array_keys(array('media'=>true,...))` and omits `reset`; comment says “complete bundle”. `erankly_section_stack()` exists for non-nested sections.

### ADM-025 — nit — latent — admin/field-renderers.php:109,117

`wp_json_encode($examples)` failure becomes `""` in the data attribute. `strtolower()` on translated labels is not mb-safe (Turkish locale search).

### ADM-026 — nit — ux — admin/meta-box.php:115-116

Primary dropdown is skipped when `! has_term('', $taxonomy, $post)` at render time. Assigning the first terms and a primary category requires two saves. Gutenberg has no control at all (ADM-001).

### ADM-027 — low — load_cost — includes/admin.php:272-276

`save_post` always `require_once` meta-box.php even when the nonce is missing (Gutenberg REST, autosave, bulk edit). After the first include it is cheap; first REST save in a request still pulls renderers + both savers for a no-op.

### ADM-028 — low — gutenberg_autosave — admin/meta-box/post-saver.php:13-15

Classic `DOING_AUTOSAVE` returns without writing. Gutenberg persists registered meta on autosave via REST. Expected for classic meta boxes, but SEO title typed in classic can be lost on crash; block editor keeps it.

### ADM-029 — low — maxlength — admin/meta-box.php:107 vs editor-shared.js:609-615

Classic breadcrumb `maxlength="120"`. Gutenberg TextControl has no max. Sanitizer is `erankly_sanitize_text()` (no 120 cap). Classic UI-only limit.

---

## Non-findings (verified OK)

- Nonces: meta box, term fields, network save (`check_admin_referer('erankly_network_settings')`), site special meta (`erankly_site_special_meta`), REST `wp_rest` nonces. Import/export and reset defer to their modules after a cap check.
- Caps: settings `manage_options` / network `manage_network_options`; post meta `edit_post`; terms `edit_term`. Import/export and reset on MS require network cap. REST settings autosave uses the same network ternary as storage.
- Escaping: form values use `esc_attr` / `esc_textarea` / `esc_url`; notices `esc_html`; Network Admin link uses the `esc_html__` + printf HTML-placeholder pattern correctly.
- `erankly_get_public_post_types()` unsets `attachment` — Media Library is not a false `$is_editor`.
- Block editor meta box is not registered (`is_block_editor()` early return). Classic `save_post` no-ops without nonce, so Gutenberg REST is the writer.
- Image ID companions: post-saver deletes `_erankly_og_image_id` / `_erankly_twitter_image_id` when URL cleared. Terms do not register those ID keys — no matching term bug.
- Post vs term simplified uncheck: post writes `index_directive=inherit`, term deletes the key. `erankly_get_object_robots_directive()` treats both as inherit.
- Panel-scoped sanitizer + `erankly_merge_settings_submission` + autosave JS explicit checkbox 0/'' prevent cross-panel checkbox wipe for settings.
- `preserve_global_special_meta` is a form flag, not stored.
- `local_business_page_path` hidden field is still a frontend fallback; not dead.
- Custom-code users without `unfiltered_html` cannot persist deletes (sanitizer returns stored) — ADM-009 is UI honesty, not a privilege escalation.
- Site Editor enqueue is skipped before the `wp-edit-post` post-editor bundle (`$is_site_editor` first). `editor.js` still lists `wp-edit-post` with a runtime fallback to `wp.editor.PluginDocumentSettingPanel`.

---

## Cross-file notes

1. **Two special-page UIs:** PHP `erankly_render_special_page_defaults()` vs Site Editor React (`site-editor.js` + `editor-shared.js`). Site Editor save goes through `register_setting( ERANKLY_SPECIAL_META_OPTION )` / Core Data, not `erankly/v1/settings/special-pages`. The REST special-pages route is only the Multisite fallback panel. Single-site Site Editor writes the native setting; settings General uses `preserve_global_special_meta` so a General autosave does not clobber it.
2. **Autosave client map vs PHP registry:** `erankly_settings_autosave_client_panels` in settings.php includes `special-pages`; `erankly_settings_autosave_panels()` does not (separate route). Intentional. Comment in settings.php about not deriving the client map from the PHP registry on Network Admin is still accurate because settings-page.php may be unloaded at `admin_enqueue_scripts`.
3. **`erankly_admin_asset_modules`:** General tab loads `blocks`+`widgets`, so `eranklyLocalBusiness` is localized on General (user-search lives in widgets JS). Schema tab loads `schema`+`widgets`. Duplicate registry keys `schema`/`blocks`.
4. **Global `$erankly_schema_blocks_previous`:** set in post-saver.php:145 and again in includes/meta.php for REST. Classic and REST sanitizer share a request global.
5. **Hooks for addons (used as extension points, not dead):** `erankly_admin_enqueue_assets`, `erankly_admin_media_enqueued`, `erankly_admin_asset_modules`, `erankly_editor_panel_order`, `erankly_nav_icons`, `erankly_section_doc_links`, `erankly_settings_tabs`, `erankly_admin_site_settings_tabs`, `erankly_admin_site_settings_modules_enabled`, `erankly_settings_autosave_panels`, `erankly_settings_autosave_client_panels`, `erankly_preserved_extension_settings`, `erankly_save_meta_box`, `erankly_meta_box_panels`, `erankly_post_general_fields_after`, `erankly_post_social_fields_after`, `erankly_term_general_fields_after`, `erankly_term_social_fields_after`, `erankly_settings_features_modules`, `erankly_render_settings_tab_{slug}`. No in-plugin listeners required.
6. **Term save has no `do_action` equivalent of `erankly_save_meta_box`.**
7. **Settings keys:** sanitizer writes the full default model. Hidden inputs in simplified special-page social and `erankly_render_global_advanced_robot_preservation()` exist specifically so partial UI does not wipe advanced keys. Exception: ADM-002 news exclusion on posts.
8. **`erankly_general_panel_setting_keys()` includes `preserve_global_special_meta`**, which is not a stored setting; sanitizer consumes it and does not persist it.

---

## Remaining ranges

None for the listed files.
