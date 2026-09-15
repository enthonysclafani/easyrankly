# EasyRankly — Audit riga-per-riga (prompt + piano)

Working tree autoritativo: `/Users/enthonysclafani/Studio/easyrankly/wp-content/plugins/easyrankly` (incluse modifiche non committate). Nessuna modifica al plugin in questa sessione.

Pass: **3** (chiusura gap verdict). Note: `audit-notes-infra.md`, `audit-notes-seo.md`, `audit-notes-frontend.md`, `audit-notes-tests.md`, `audit-notes-admin.md`, `audit-notes-inf-a.md`, `audit-notes-inf-b.md`, `audit-notes-expansion.md`.

## 0. Come usare questo documento

Sei un agente di follow-up. Questo file è il **prompt** e il **piano**. Non riscrivere l’obiettivo: qualità, correttezza, completezza e necessità (bloat) di ogni riga first-party, commenti inclusi.

1. §1 è la checklist di copertura. Se lo status è `parziale`, **prima** leggi i range in §5. Non trattare un checkmark senza nota per-file come prova.
2. Esegui i work package in §4 **in ordine**. Ogni WP ha file, ID, accettazione e test. Non trattare i test verdi come prova: un test copre una riga solo se asserisce il ramo rischioso.
3. Non toccare `vendor/`. Non riscrivere `license.txt`. `composer.lock` è tooling (packages-dev PHPUnit).
4. ID finding: **non rinumerare** B-/H-/M-/L-/N- del pass 1. I nuovi usano prefissi INF-/SEO-/FE-/TST-/ADM-. Se un finding è superato, marcalo *superseded* con evidenza, non cancellarlo.
5. Namespace ADM: **solo ADM-001+** (tre cifre). Alias pass-2: ADM-01→ADM-004, ADM-02→ADM-030, ADM-03→ADM-018, ADM-04→ADM-023. Non aprire WP sugli alias.
6. Lingua utente: italiano. Codice/identificatori: originali.

## 1. Copertura

### Esclusioni (documentate)

| Path | Perché |
| --- | --- |
| `.git/` | VCS |
| `vendor/` | Terze parti **solo-dev** (PHPUnit 9.6, yoast/phpunit-polyfills). Non è nello ZIP (`.distignore`). **Restano** per i test. |
| `.dist/easyrankly.zip` | Artefatto binario gitignored |
| `.phpunit.result.cache` | Cache locale |
| `.DS_Store` | Junk OS |

`.github/workflows/phpunit.yml` è in scope.

### Totali inventario

- File first-party testuali: **183** (esclusi vendor/.git/zip/cache; incluso workflow)
- Righe first-party: **73673** (`wc -l`)
- `includes/helpers/sanitization.php`: **889** (non 890)
- `tests/test-*.php`: **50 file / 20940 righe** (non 49 / ~21900)
- Vendor: PHPUnit + polyfills; **resta**; **non** shippare

### Legenda

- `coperto`: ogni riga letta (questo pass o workstream con nota per-file: purpose + range + issues o «reviewed, no issues»).
- `parziale`: letto solo in range citati; resto in §5.
- Pass 1 ID invariati. Pass 3 **non** chiude §5.

| path | lines | status | notes |
| --- | ---: | --- | --- |
| `.commandcode/taste/taste.md` | 8 | coperto | Non runtime; distignored |
| `.distignore` | 19 | coperto | |
| `.gitignore` | 6 | coperto | |
| `.github/workflows/phpunit.yml` | 139 | coperto | Solo WP 7.1; `ignore-warnings` PCP |
| `WORDPRESS-ORG-READINESS.md` | 405 | coperto | **Stale** su composer.lock (vedi B-01 superseded) |
| `admin/assets/editor.php` | 211 | coperto | Pass 2 |
| `admin/assets/settings.php` | 248 | coperto | Pass 2; enqueue list-table |
| `admin/field-renderers.php` | 274 | coperto | Pass 2 |
| `admin/meta-box.php` | 642 | coperto | Pass 2 |
| `admin/meta-box/post-saver.php` | 179 | coperto | Pass 2 |
| `admin/meta-box/term-saver.php` | 112 | coperto | Pass 2 |
| `admin/settings-page.php` | 534 | coperto | Pass 2 (riletto intero) |
| `admin/settings/nav-icons.php` | 53 | coperto | |
| `admin/settings/page-renderer.php` | 371 | coperto | Pass 2 |
| `admin/settings/panels.php` | 448 | coperto | Pass 2 |
| `admin/settings/renderers.php` | 771 | coperto | Pass 2 |
| `admin/settings/section-links.php` | 96 | coperto | |
| `assets/css/admin-core.css` | 761 | coperto | FE; TEMP nav + unused class |
| `assets/css/admin-settings.css` | 1087 | coperto | FE |
| `assets/css/classic-editor.css` | 35 | coperto | |
| `assets/css/editor.css` | 218 | coperto | FE |
| `assets/css/migration.css` | 414 | coperto | FE |
| `assets/css/redirects.css` | 143 | coperto | FE |
| `assets/css/reset.css` | 54 | coperto | |
| `assets/css/shared.css` | 195 | coperto | FE |
| `assets/js/accordion-faq-schema.js` | 69 | coperto | FE |
| `assets/js/admin-fields.js` | 107 | coperto | FE |
| `assets/js/admin-import-export.js` | 17 | coperto | |
| `assets/js/admin-media.js` | 97 | coperto | FE |
| `assets/js/admin-panels.js` | 79 | coperto | FE |
| `assets/js/admin-reset.js` | 210 | coperto | FE |
| `assets/js/admin-schema.js` | 468 | coperto | FE |
| `assets/js/admin-settings.js` | 674 | coperto | FE |
| `assets/js/admin-tabs.js` | 606 | coperto | FE |
| `assets/js/admin-variables.js` | 443 | coperto | FE |
| `assets/js/admin-widgets.js` | 769 | coperto | FE |
| `assets/js/admin.js` | 57 | coperto | |
| `assets/js/editor-shared.js` | 1098 | coperto | FE |
| `assets/js/editor.js` | 506 | coperto | FE |
| `assets/js/redirects.js` | 357 | coperto | FE |
| `assets/js/schema-jsonld.js` | 206 | coperto | |
| `assets/js/site-editor.js` | 248 | coperto | FE |
| `blocks/breadcrumbs/block.json` | 16 | coperto | |
| `blocks/breadcrumbs/index.js` | 69 | coperto | |
| `composer.json` | 21 | coperto | Solo require-dev |
| `composer.lock` | 1865 | coperto | **necessità**: `packages: []`; instantiator **1.5.0** `^7.1 \|\| ^8.0` |
| `easyrankly.php` | 1130 | coperto | Purpose: bootstrap + REST. **1–1130 riletti** (pass 3 + INF-A; 794–1130 non più context-only). M-01, M-16, L-16, ADM-010, INF-053, INF-063. |
| `includes/admin.php` | 503 | coperto | |
| `includes/breadcrumbs.php` | 1082 | coperto | |
| `includes/canonical.php` | 77 | coperto | |
| `includes/class-erankly-import-job-runner.php` | 893 | coperto | Purpose: restore/import job. **1–893** INF-B. INF-001, INF-006, INF-081, INF-083. |
| `includes/class-erankly-multilingual-provider-registry.php` | 470 | coperto | Purpose: provider ML. **1–470** INF-B. INF-007. |
| `includes/class-erankly-site-sitemaps-provider.php` | 157 | coperto | SEO |
| `includes/class-erankly-specialist-sitemaps-provider.php` | 89 | coperto | |
| `includes/compatibility-legacy.php` | 95 | coperto | Purpose: 60 alias. 1–95. M-03. reviewed, no issues oltre M-03. |
| `includes/compatibility-woocommerce.php` | 175 | coperto | Purpose: Product JSON-LD. 1–175. INF-015, M-08, H-04. |
| `includes/compatibility.php` | 209 | coperto | Purpose: owner SEO + localize_url. 1–209. M-02. |
| `includes/custom-code.php` | 206 | coperto | Purpose: HEAD/BODY. 1–206. INF-037, L-05. |
| `includes/helpers.php` | 47 | coperto | Purpose: loader. 1–47. reviewed, no issues |
| `includes/helpers/content-defaults.php` | 177 | coperto | Purpose: org/website + special page key. 1–177. SEO-002. INF-029 superseded (placeholder usati). |
| `includes/helpers/core.php` | 51 | coperto | Purpose: kernel. 1–51. M-06, N-03. |
| `includes/helpers/defaults.php` | 448 | coperto | Purpose: default settings + entity sanitize. 1–448. L-14 QAPage. |
| `includes/helpers/feature-modules.php` | 21 | coperto | Purpose: 3 toggle. 1–21. reviewed, no issues |
| `includes/helpers/global-meta.php` | 438 | coperto | Purpose: object/global meta. 1–438. Header bugiardo INF-051. INF-027. |
| `includes/helpers/redirect-cache.php` | 90 | coperto | Purpose: generation + flush. 1–90. INF-055 indent. |
| `includes/helpers/sanitization-schema.php` | 314 | coperto | Purpose: schema/LB sanitizers. 1–314. INF-057. |
| `includes/helpers/sanitization.php` | 889 | coperto | Purpose: text/URL/custom-code/targeting. 1–889. INF-010/021/036, M-11, L-04. |
| `includes/helpers/settings.php` | 871 | coperto | Purpose: cache/merge/LB migration. 1–871. INF-003 storage MS. |
| `includes/helpers/sitemap-cache.php` | 151 | coperto | Purpose: flush versioned. 1–151. INF-052 omits user disable_sitemap. |
| `includes/helpers/template-variables.php` | 679 | coperto | Purpose: {{var}} + preview. 1–679. L-11, INF-012, H-06. |
| `includes/helpers/utils.php` | 168 | coperto | Purpose: current_url + XML. 1–168. M-05, INF-019, INF-032. |
| `includes/helpers/video.php` | 134 | coperto | Purpose: extract video URLs. 1–134. L-03, L-13. |
| `includes/hreflang.php` | 97 | coperto | |
| `includes/import-export.php` | 15 | coperto | Purpose: loader. 1–15. reviewed, no issues |
| `includes/import-export/actions.php` | 599 | coperto | Purpose: admin-post. **1–599** INF-B. reviewed, no new issues (MS caps/URL corretti). TST-027. |
| `includes/import-export/export.php` | 222 | coperto | Purpose: JSON export. **1–222** INF-B. INF-039. |
| `includes/import-export/panel.php` | 327 | coperto | Purpose: UI. **1–327** INF-B. reviewed, no issues. |
| `includes/import-export/report.php` | 498 | coperto | Purpose: report. **1–498** INF-B. INF-040, L-01. |
| `includes/localized-value-writer.php` | 365 | coperto | Purpose: CAS localized source. 1–365. INF-013 archive→author. |
| `includes/meta-render.php` | 70 | coperto | Purpose: wp_head + attachment. SEO-029/039/040. |
| `includes/meta-visibility.php` | 96 | coperto | Purpose: exclude queries. H-11, SEO-019. |
| `includes/meta.php` | 804 | coperto | Purpose: meta registry. SEO-006, M-09/13. |
| `includes/migrations.php` | 145 | coperto | Purpose: loader + start lock. 1–145. INF-003. |
| `includes/migrations/class-erankly-migration-adapter.php` | 1061 | coperto | Purpose: adapter base. **1–1061** INF-B. INF-020, INF-082. |
| `includes/migrations/class-erankly-migration-adapter-aioseo.php` | 924 | coperto | Purpose: AIOSEO. **1–924** INF-B. INF-004, INF-050, INF-020, TST-009. |
| `includes/migrations/class-erankly-migration-adapter-rankmath.php` | 738 | coperto | Purpose: Rank Math. **1–738** INF-B. INF-014, INF-023. |
| `includes/migrations/class-erankly-migration-adapter-seopress.php` | 704 | coperto | Purpose: SEOPress. **1–704** INF-B. reviewed, no new IDs. |
| `includes/migrations/class-erankly-migration-adapter-yoast.php` | 899 | coperto | Purpose: Yoast. **1–899** INF-B. INF-049. |
| `includes/migrations/class-erankly-migration-admin-presenter.php` | 145 | coperto | Purpose: UI state. 1–145. INF-061 usa settings_invalid. |
| `includes/migrations/class-erankly-migration-job-runner.php` | 1056 | coperto | Purpose: batch. **1–1056** INF-B. INF-005, INF-040, INF-082, INF-084. |
| `includes/migrations/class-erankly-migration-manager.php` | 307 | coperto | Purpose: start/report. **1–307** INF-B. INF-040. |
| `includes/migrations/class-erankly-migration-source-changed-exception.php` | 13 | coperto | Purpose: exception. 1–13. reviewed, no issues |
| `includes/migrations/class-erankly-migration-upload-store.php` | 415 | coperto | Purpose: private uploads. **1–415** INF-B. reviewed, no issues. |
| `includes/migrations/legacy-cleanup.php` | 55 | coperto | Purpose: purge retired. 1–55. reviewed, no issues |
| `includes/migrations/runtime-backup.php` | 230 | coperto | Purpose: pre-import backup. **1–230** INF-B. INF-001 (restore via start_from_file). |
| `includes/migrations/runtime-database.php` | 19 | coperto | Purpose: table_exists. 1–19. reviewed, no issues |
| `includes/migrations/runtime-redirects.php` | 125 | coperto | Purpose: prepare redirect. 1–125. INF-023. |
| `includes/migrations/runtime-variables.php` | 190 | coperto | Purpose: convert tokens. 1–190. INF-004. |
| `includes/network-reset.php` | 466 | coperto | Purpose: reset rete. **1–466** INF-B. INF-024, INF-080. |
| `includes/opengraph.php` | 576 | coperto | Purpose: OG/Twitter. SEO-003/006/028, M-04/10. |
| `includes/redirects.php` | 75 | coperto | Purpose: boot. 1–75. reviewed, no issues |
| `includes/redirects/class-erankly-redirects-activator.php` | 146 | coperto | Purpose: dbDelta. **1–146** INF-B. INF-025. |
| `includes/redirects/class-erankly-redirects-admin.php` | 832 | coperto | Purpose: UI/CRUD. **1–832** INF-B. INF-008. |
| `includes/redirects/class-erankly-redirects-normalizer.php` | 472 | coperto | Purpose: path/regex/eval. **1–472** INF-B. reviewed, no new issues. |
| `includes/redirects/class-erankly-redirects-repository.php` | 653 | coperto | Purpose: SQL + buckets. **1–653** INF-B. INF-017. |
| `includes/redirects/class-erankly-redirects-rest.php` | 162 | coperto | Purpose: toggle/delete/test. 1–162. INF-008, TST-022. |
| `includes/redirects/class-erankly-redirects-runner.php` | 235 | coperto | Purpose: parse_request. **1–235** INF-B. H-07/08, INF-016, M-07. |
| `includes/reset.php` | 387 | coperto | Purpose: wipe sito. 1–387. INF-002 :213-218, INF-018, INF-054. |
| `includes/robots.php` | 679 | coperto | Purpose: wp_robots + robots.txt. SEO-002/011/023, H-10. |
| `includes/schema-content.php` | 970 | coperto | Purpose: FAQ/HowTo/Event/Video. SEO-008/025/026/027. |
| `includes/schema-jsonld.php` | 945 | coperto | Purpose: JSON-LD + LB admin. H-06, SEO-014/024, L-12. |
| `includes/schema.php` | 1086 | coperto | Purpose: graph. H-03/04, SEO-010/021/022. |
| `includes/seo-state.php` | 227 | coperto | Purpose: eligibility. SEO-009. |
| `includes/settings-lock.php` | 302 | coperto | Purpose: mutex CAS. 1–302. H-02/INF-009, INF-010. |
| `includes/sitemap/core.php` | 1174 | coperto | SEO |
| `includes/sitemap/image.php` | 221 | coperto | SEO |
| `includes/sitemap/news.php` | 300 | coperto | SEO |
| `includes/sitemap/video.php` | 270 | coperto | SEO |
| `includes/special-meta.php` | 188 | coperto | |
| `includes/title-description.php` | 244 | coperto | |
| `languages/easyrankly.pot` | 3470 | coperto | FE: msgid unused hunt |
| `license.txt` | 338 | coperto | GPL-2 necessario |
| `phpunit.xml.dist` | 14 | coperto | Esclude live-schema-audit |
| `readme.txt` | 124 | coperto | |
| `tests/bootstrap.php` | 96 | coperto | Purpose: load plugin. TST-040 OK. TST-011 env. |
| `tests/fixtures/woocommerce-doubles.inc` | 109 | coperto | Purpose: WC stubs. TST-028. |
| `tests/js/breadcrumbs-block-probe.cjs` | 195 | coperto | TST-002 skip then ok. |
| `tests/js/local-business-widget-probe.cjs` | 548 | coperto | TST-013 no classList. |
| `tests/js/settings-autosave-probe.cjs` | 430 | coperto | TST-006 fake DOMParser. |
| `tests/live-schema-audit.php` | 890 | coperto | TST-001/010/032. Fuori PHPUnit. |
| `tests/schema-recheck.md` | 66 | coperto | TST-033 docs, non test. |
| `tests/test-admin-menu.php` | 579 | coperto | Purpose: menu/enqueue. Overlap TST-030. |
| `tests/test-admin-meta-box.php` | 320 | coperto | Purpose: classic save. Manca ADM-002/001. |
| `tests/test-admin-renderers.php` | 544 | coperto | Purpose: renderers. TST-031 CSS-as-test. |
| `tests/test-admin-settings-panels.php` | 477 | coperto | Purpose: panel field names. |
| `tests/test-breadcrumb-registration.php` | 28 | coperto | TST-011 skip-by-default. |
| `tests/test-breadcrumbs-native.php` | 482 | coperto | Native core/breadcrumbs. Skip se core assente. |
| `tests/test-breadcrumbs.php` | 1080 | coperto | TST-018/019 tautologie. |
| `tests/test-canonical.php` | 139 | coperto | TST-038 buchi author/date/CPT. |
| `tests/test-compatibility.php` | 178 | coperto | TST-014 no conflict path. |
| `tests/test-custom-code-boundaries.php` | 458 | coperto | unfiltered_html. Overlap TST-030. |
| `tests/test-helpers-cache.php` | 282 | coperto | TST-036 no positive flush. |
| `tests/test-helpers-content-defaults.php` | 313 | coperto | Special page key / placeholders. |
| `tests/test-helpers-global-meta.php` | 500 | coperto | TST-008 inherit+legacy. |
| `tests/test-helpers-template-variables.php` | 345 | coperto | TST-007 escape vs fixture. |
| `tests/test-helpers-video.php` | 216 | coperto | Extractor. Non /shorts/ (L-13). |
| `tests/test-hreflang-canonical.php` | 202 | coperto | Paged archive reale. |
| `tests/test-import-export.php` | 713 | coperto | TST-027. Manca INF-001. |
| `tests/test-js-contracts.php` | 148 | coperto | M-18 / TST-012. |
| `tests/test-lifecycle-cron.php` | 140 | coperto | Cron hooks. reviewed, no issues beyond suite gaps. |
| `tests/test-lifecycle-rest.php` | 127 | coperto | REST. Manca ADM-010 wipe. |
| `tests/test-lifecycle.php` | 1129 | coperto | Activate/settings. MS skips. |
| `tests/test-local-business-rest.php` | 174 | coperto | LB REST. INF-053 non asserito. |
| `tests/test-meta-helpers.php` | 1153 | coperto | TST-005 attachment none only. |
| `tests/test-migration-internals.php` | 426 | coperto | Private storage often skipped. |
| `tests/test-migrations-adapters.php` | 1322 | coperto | TST-009 empty tables = success. |
| `tests/test-migrations-runner.php` | 586 | coperto | Manca INF-005. |
| `tests/test-migrations-runtime.php` | 330 | coperto | Convert vars/redirects. |
| `tests/test-multilingual-registry.php` | 242 | coperto | Registry. |
| `tests/test-network-reset.php` | 221 | coperto | MS skips. INF-002 non cross-site. |
| `tests/test-opengraph-migrations.php` | 143 | coperto | SEO-006 write-on-GET. |
| `tests/test-opengraph-output.php` | 297 | coperto | TST-037 fallbacks. |
| `tests/test-redirects-normalizer.php` | 264 | coperto | Normalize/eval. |
| `tests/test-redirects-repository.php` | 551 | coperto | SQL create. Non REST. |
| `tests/test-redirects-runtime.php` | 878 | coperto | TST-004/020/021/022. |
| `tests/test-reset-actions.php` | 313 | coperto | TST-025 SQLite DROP skip. |
| `tests/test-response-handlers.php` | 97 | coperto | TST-003 feed header. |
| `tests/test-rest-user-search.php` | 159 | coperto | User search. |
| `tests/test-robots-and-custom-code.php` | 650 | coperto | Custom code + robots. |
| `tests/test-robots-output.php` | 244 | coperto | TST-026 synthetic paged. SEO-002? |
| `tests/test-schema-content.php` | 473 | coperto | HowTo/Event/Video helpers. |
| `tests/test-schema-jsonld.php` | 1143 | coperto | TST-029 WP_DEBUG. |
| `tests/test-schema.php` | 550 | coperto | TST-015/016/017. |
| `tests/test-seo-state.php` | 84 | coperto | Thin vs canonical. |
| `tests/test-settings-and-robots-regressions.php` | 177 | coperto | Regressions. |
| `tests/test-sitemap-providers.php` | 399 | coperto | TST-024 buffer/304. |
| `tests/test-sitemap-specialists.php` | 473 | coperto | News/image/video. |
| `tests/test-sitemap.php` | 246 | coperto | TST-023 default-off. |
| `tests/test-title-description.php` | 357 | coperto | TST-026. SEO-001? |
| `tests/test-uninstall-cleanup.php` | 229 | coperto | M-12 non scale. |
| `tests/test-woocommerce.php` | 359 | coperto | TST-028 doubles only. |
| `tools/build-dist.sh` | 65 | coperto | reviewed, no issues |
| `uninstall.php` | 690 | coperto | Purpose: delete all data. **1–690** INF-A. M-12. Uninstall MS DELETE usermeta è totale (ok); ripetuto per sito è ridondante. |

Nota test: le 50 righe `test-*.php` hanno `wc -l` disk e note riga-per-riga da `audit-notes-expansion.md` (50/50, reviewed 1–EOF) + `audit-notes-tests.md`. TST-011–047 in §3.

### Totali copertura pass 3

| Metrica | Valore |
| --- | --- |
| File inventariati | **183** |
| File con nota per-file in §1 | **183** (50 test nominati; 3 probe nominati) |
| File `parziale` (da rileggere) | **0** |
| File `coperto` | **183** |
| Righe first-party | **73673** |
| Righe rimanenti first-party PHP/JS/CSS/`test-*.php` | **0** |

## 2. Sintesi esecutiva

### Conteggi severity (finding unici in §3)

Censimento **esatto `grep '^#### '`** su questo file (pass 3, dopo un-collapse + merge INF-A/B + expansion). Alias heading `ADM-01` **assenti**. B-01 e INF-029 *superseded* esclusi dal live. Pointer `*-adjacent` e alias ricercabili (INF-009, SEO-010) restano heading ma non sono difetti extra.

| Severity | Pass 1 dichiarato | Heading live pass 3 | Come contato |
| --- | ---: | ---: | --- |
| blocker | 1 | **2** | INF-001, INF-002 |
| superseded | 0 | **2** | B-01, INF-029 (non live) |
| high | 11 | **42** | H-01–H-11 (11) + INF-003–009 (7) + SEO-001–010 (10) + TST-001–010 (10) + ADM-001–003 (3) + INF-083 (1). Di cui alias: INF-009=H-02, SEO-010=H-03. **Unici high: 40.** SEO-042 è high-adjacent (pointer H-10), non in 42. |
| medium | 18 | **106** | heading con severity medium (sezione Medium + TST medium + ADM medium). FE-011 è medium-adjacent, non in 106. |
| low | 16 | **109** | sezione Low + heading ` — low` |
| nit | 12 | **22** | N-01–12 + pass-2 nit + FE-TEMP |
| high-adjacent / medium-adjacent | 0 | **2** | SEO-042, FE-011 (pointer, non extra live) |
| **Totale heading `####`** | 58 | **285** | 283 live + 2 superseded |
| **Totale live (no superseded, include alias/pointer)** | 58 | **283** | 2+42+106+109+22+2 |
| **Totale live unici (no alias INF-009/SEO-010, no adjacent)** | 58 | **279** | 2+40+106+109+22 |

Se un agente ricalcola: `grep '^#### '` su questo file. Non usare `≈`.

Pass 3: ADM solo ADM-001+; SEO-038–075 assegnati (ultimo **SEO-075**; non paddare 076–081); INF-051–064 e INF-080–084 da INF-A/B (collisioni INF-A INF-051–053 riassegnate a INF-062–064 per non sovrascrivere header-lie / user-flush / hasMore). TST-011–047 espansi.

### Rischi più grandi (produzione)

1. **Restore/import** (`INF-001`): `purge_owned` poi fallimento → dati SEO cancellati e spool cancellato. Perdita permanente.
2. **Reset sito su Multisite** (`INF-002`): `DELETE usermeta LIKE '_erankly_%'` non è scoped al blog → wipe meta autori di tutta la rete.
3. **Homepage statica** (`SEO-001/002/003`): title/OG/robots di Special pages → Homepage **non** si applicano se il front è una Page (`is_singular()` vince). Sitemap/seo-state invece usano `homepage` → URL indexable e assente dalla sitemap (o il contrario).
4. **Site Editor special meta** (`SEO-004`): REST schema senza campi robots avanzati; save sostituisce la mappa e **droppa** `index_directive` / `max_snippet` / ecc.
5. **Sitemap subdirectory** (`SEO-005`): origin senza path WP → URL fuori installazione.
6. **Write-on-GET** (`SEO-006`): migrazione social image in `wp_head` / meta box GET.
7. **Ricorsione canonical** (`SEO-007`): `{{canonical_url}}` su template author senza exclude.
8. **AIOSEO `#token`** (`INF-004`): `/#([a-z0-9_]+)/i` riscrive fragment URL.
9. **Lock settings silenzioso** (`H-02` / `INF-009`) e **lock per-blog vs sitemeta** (`INF-003`).
10. **Person utente cancellato** (`H-03` / `SEO-010`): sanitizer azzera in save; render ancora emette Person finto finché non si risalva.

### B-01 superseded

`composer.lock` pinna `doctrine/instantiator` **1.5.0** (`php ^7.1 || ^8.0`), `packages: []`. Il P2 “CI 8.0/8.3 fallisce sul lock” in `WORDPRESS-ORG-READINESS.md` è **obsoleto**. Rimanere: matrice WP 6.5 (`H-09`) e PCP `ignore-warnings` (`FE-019`).

### Bloat più grande

Wrapper schema mai chiamati (`L-01`); `erankly_third_party_data_exists`; 60 alias hook; export JS morti (`FE-014`); CSS `.erankly-user-search-wrap`; TEMP CSS che nasconde nav documentata; LocalBusiness admin dentro JSON-LD sempre caricato (`SEO-024`); ~700 righe breadcrumbs native; suite test duplicata (`TST-030`); `live-schema-audit.php` fuori CI.

## 3. Finding (tutti)

Ordinati: blocker → high → medium → low → nit. Pass 1 prima (ID originali). Poi pass 2. *See also* = stesso difetto, non sommare due volte nel piano.

### Blocker

#### B-01 — *superseded* — `composer.lock` PHP 8.4
- **was:** blocker tooling. **Ora:** `composer.lock:11-25` instantiator 1.5.0 `^7.1 || ^8.0`.
- **evidenza:** FE-018; lettura lock in pass 2.
- **azione:** Aggiornare `WORDPRESS-ORG-READINESS.md` (non è un fix runtime). Non rigenerare il lock per questo motivo.

#### INF-001 — blocker — restore/import: purge poi fail
- **type:** bug-latente
- **file:** `includes/class-erankly-import-job-runner.php:232,357-368,486-488,739-746`
- **perché:** `purge_owned=true` DELETE meta/redirects, poi `apply_settings`. Su `Throwable`, `finish(..., 'failed')` **unlink spool** e cancella il job. Nessun resume. Sito senza SEO e senza snapshot.
- **azione:** Non cancellare lo spool se purge è già avvenuto; staging/shadow prima del purge. Test restore che fallisce dopo il primo batch.

#### INF-002 — blocker — Multisite: reset sito cancella usermeta di rete
- **type:** bug-latente
- **file:** `includes/reset.php:213-218`
- **perché:** `DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_erankly_%'` non segue `switch_to_blog()`. Reset di un sito cancella meta autori globali. Network reset ripete lo stesso DELETE.
- **azione:** Scope agli utenti del blog, o wipe usermeta solo sul network reset una volta. Test MS.

### High — pass 1 (invariati salvo note)

#### H-01 — high — `register_setting( 'general' )` può azzerare special meta su MS
- **file:** `includes/special-meta.php:27-39,169-187`
- **see also:** SEO-053
- **perché:** Gruppo `general` = save Impostazioni → Generali (MS) passa l’option nel sanitizer. Payload vuoto/parziale azzera title/robots special pages.
- **azione:** Registrare in gruppo EasyRankly-only.

#### H-02 — high — Interlock settings: save silenzioso no-op
- **file:** `includes/settings-lock.php:265-289`
- **see also:** INF-009
- **perché:** Lock non ottenuto → ritorna `$old_value`. options.php sembra aver salvato. Nessun settings_error.
- **azione:** Errore visibile / WP_Error, non fingere successo.

#### H-03 — high — Person schema se utente cancellato
- **file:** `includes/schema.php:385-391,494-549`
- **see also:** SEO-010
- **perché:** Identity con `person_user_id` > 0 ma user assente emette comunque Person. `erankly_sanitize_settings` azzera l’ID allo **save**; il render resta rotto finché non c’è un save.
- **azione:** Richiedere `WP_User` vivo; altrimenti omettere Person.

#### H-04 — high — `array_filter` senza callback
- **file:** `includes/schema.php:533,640,685,717,787,802,912`; `opengraph.php:76`; `compatibility-woocommerce.php:75`
- **see also:** SEO-021
- **perché:** Droppa `0` / `''` / `'0'`. Price/sku/name edge spariscono dal grafo/OG.
- **azione:** Usare `erankly_filter_empty_schema_values` o callback solo `null`/`''`.

#### H-05 — high — BreadcrumbList `position` non contiguo
- **file:** `includes/breadcrumbs.php:264-279`
- **see also:** SEO-012; SEO-013
- **perché:** `position = $index+1` dopo skip name vuoti. Google vuole 1..n contigui; buchi se un crumb è droppato.
- **azione:** Rinumerare dopo il filtro.

#### H-06 — high — JSON-LD probe `x` vs replace a runtime
- **file:** `includes/schema-jsonld.php:19-58`; `helpers/template-variables.php:35-57`
- **see also:** SEO-014
- **perché:** Probe sostituisce con `x`; runtime `wp_json_encode` fail → `''` e il nodo può essere droppato. Title con `"` passa il probe e fallisce live.
- **azione:** Fail-closed sul documento; rivalidare dopo replace.

#### H-07 — high — Loop redirect solo exact
- **file:** `includes/redirects/class-erankly-redirects-runner.php:171-207`
- **see also:** INF-016
- **perché:** Detector segue solo `get_exact_rule_cached`. Catena regex→exact→regex non si ferma.
- **azione:** Valutare anche pattern su ogni hop, o rifiutare target non-exact nel detector.

#### H-08 — high — XML-RPC non skippato dal runner
- **file:** `includes/redirects/class-erankly-redirects-runner.php:93-121`
- **perché:** Skip admin/ajax/cron/REST/login, non `xmlrpc.php`. Redirect 301 può rompere XML-RPC.
- **azione:** Skip `xmlrpc.php` (e decidere feeds/sitemaps).

#### H-09 — high — CI solo WordPress 7.1, Requires 6.5
- **file:** `.github/workflows/phpunit.yml:55-58`
- **perché:** Plugin Requires WP 6.5; CI gira solo 7.1. Regressioni 6.5 (breadcrumbs core assente) invisibili.
- **azione:** Matrix 6.5 + 7.1.

#### H-10 — high — `erankly_force_robots_txt_request` wipe query_vars
- **file:** `includes/robots.php:618-645`
- **see also:** SEO-042, TST-047
- **perché:** Replace totale `$wp->query_vars`. Altre query var droppate. Il test locka il wipe.
- **azione:** Merge `robots=1`; aggiornare TST-047.

#### H-11 — high — Cache visibilità: errore DB = nessuna esclusione
- **file:** `includes/meta-visibility.php:40-50`
- **perché:** Query meta fallita → cache vuota = “nessun post escluso”. Post noindex appaiono in search/sitemap.
- **azione:** Fail-closed (escludi tutto / skip cache) su errore DB.

### High — pass 2

#### INF-003 — high — lock transfer per-blog vs settings sitemeta
- **file:** `includes/migrations.php:35-68` vs `includes/helpers/settings.php:38-40`
- **perché:** Start lock CAS su `$wpdb->options` (blog). Settings EasyRankly su MS sono `get_site_option`. Due siti possono avviare import/migrazione contro lo stesso snapshot network.
- **azione:** Lock sitemeta / network option su MS.

#### INF-004 — high — AIOSEO `#token` riscrive fragment
- **file:** `includes/migrations/runtime-variables.php:151-180`; anche adapter AIOSEO identity strings
- **perché:** `/#([a-z0-9_]+)/i` matcha `#product`, `#fff`, hash in URL, `@id`. Token mappati diventano `{{…}}`; unmapped restano. Identity fields (`website_name`) passano da `$convert`.
- **azione:** Solo `#token` noti con word boundary; non convertire identity.

#### INF-005 — high — fingerprint sorgente dopo le write
- **file:** `includes/migrations/class-erankly-migration-job-runner.php:313-337,394-407`
- **perché:** Fingerprint verificato dopo content+redirects. Source change → exception → pause con dati già mutati; resume ribatte lo stesso check. See INF-082 (COUNT+MAX non vede UPDATE).
- **azione:** Re-fingerprint prima di ogni write batch.

#### INF-006 — high — import settings merge
- **file:** `includes/class-erankly-import-job-runner.php:582-583`
- **perché:** Tree attuale instrada `erankly_update_plugin_option` in `erankly_update_plugin_settings` **senza** `$replace=true` (merge nel live). `special_meta` è option separata. See INF-083.
- **azione:** Restore con `$replace=true`; tenere mutex.

#### INF-007 — high — contesto multilingual memoizzato troppo presto
- **file:** `includes/class-erankly-multilingual-provider-registry.php:317-355,384-396`
- **perché:** `$resolved=true` prima di `close_and_boot`; context vuoto memoizzato. `erankly_localize_url` su Throwable → URL `''`. Hreflang owner memoizza `none` pre-boot.
- **azione:** Non memoizzare finché registry closed+booted; su exception lasciare URL originale.

#### INF-008 — high — Redirects REST/admin MS
- **file:** `includes/redirects/class-erankly-redirects-rest.php:64-66`; `class-erankly-redirects-admin.php:73,607-667,820-830`
- **perché:** Gate `manage_options` only. Form/sort/pagination sempre `admin_url('options-general.php')` anche se il resto settings è Network Admin.
- **azione:** Mirror import-export (`manage_network_options` + `network_admin_url`).

#### INF-009 — high — see H-02
- **file:** `includes/settings-lock.php:265-289`
- **perché:** Alias ricercabile di H-02 (interlock silent no-op). Non è un finding extra.
- **azione:** Come H-02.

#### SEO-001 — high — title special-page homepage ignorato su static front
- **file:** `includes/title-description.php:61-84,169-236`; `includes/helpers/content-defaults.php:151-176`
- **perché:** `is_singular()` prima di `is_front_page()`. Page on front non usa title Special pages → Homepage. Sitemap/seo-state usano `homepage`.
- **azione:** `is_front_page()` prima di singular.

#### SEO-002 — high — robots homepage ignorati su static front
- **file:** `includes/robots.php:30-104`; `erankly_current_special_page_key()` richiede `! is_singular()`
- **perché:** Stesso gate. noindex Homepage non si applica alla static Page.
- **azione:** Come SEO-001.

#### SEO-003 — high — OG/social homepage ignorati su static front
- **file:** `includes/opengraph.php:106-118,244-257,336-358`
- **perché:** Stesso gate. OG Homepage non si applica.
- **azione:** Come SEO-001.

#### SEO-004 — high — REST special meta droppa robots avanzati
- **file:** `includes/special-meta.php:46-77,86-104,119-160`
- **perché:** Schema REST + defaults senza `index_directive` / `max_snippet` / `indexifembedded`. `additionalProperties: false`. Save Site Editor sostituisce la mappa. Hidden form settings non girano nel Site Editor.
- **azione:** Schema completo **o** merge-on-update.

#### SEO-005 — high — sitemap site provider: origin senza path
- **file:** `includes/class-erankly-site-sitemaps-provider.php:124-144`
- **perché:** Confronta origin host, non path home. Subdir `/shop/` accetta `https://example.com/other/`.
- **azione:** Origin + path `home_url`.

#### SEO-006 — high — migrazione social image in GET
- **file:** `includes/meta.php:441-456`; `opengraph.php:246-262,338-364`; `admin/meta-box.php:144,482`
- **perché:** Write-on-GET in `wp_head` e classic meta-box render. Race + write frontend. Batch `init` esiste già.
- **azione:** Solo batch `init`/admin-post.

#### SEO-007 — high — `{{canonical_url}}` su author → ricorsione
- **file:** `includes/canonical.php:27,45 vs 55-58`
- **perché:** Posts/terms escludono la variabile; author no. Template author `{{canonical_url}}` richiama il builder.
- **azione:** Stesso exclude dei post.

#### SEO-008 — high — VideoObject vs sitemap nocookie
- **file:** `includes/schema-content.php:813-823` vs `includes/sitemap/video.php:145-151`
- **perché:** Haystack schema senza `youtube-nocookie.com/`; SQL sitemap sì. Video in sitemap ma assente da JSON-LD (o viceversa).
- **azione:** Stesso haystack.

#### SEO-009 — high — canonical head ≠ seo-state
- **file:** `includes/seo-state.php:153-198` vs `canonical.php:65-67`
- **perché:** Stato senza `erankly_localize_url` / paged-archive → URL in sitemap diverso dal canonical head (hide vs index).
- **azione:** Un builder.

#### SEO-010 — high — see H-03
- **file:** `includes/schema.php:385-391,494-549`
- **perché:** Alias ricercabile di H-03 (Person se user cancellato).
- **azione:** Come H-03.

#### TST-001 — high — `live-schema-audit.php` escluso da PHPUnit
- **file:** `phpunit.xml.dist:11`; `tests/live-schema-audit.php`
- **perché:** XSS JSON-LD, preserve invalid, replace/disabled, LB live: non in CI. Un regress non fallisce PHPUnit.
- **azione:** Equivalenti PHPUnit; non usare live-audit come CI.

#### TST-002 — high — breadcrumbs probe stampa `ok` dopo skip
- **file:** `tests/js/breadcrumbs-block-probe.cjs:146-195`
- **perché:** Skip merge poi stampa `ok`. CI verde senza aver testato il merge.
- **azione:** Exit non-zero sullo skip, o non skip.

#### TST-003 — high — feed robots header mai asserito
- **file:** `tests/test-response-handlers.php:27-67` vs `includes/robots.php:208-214`
- **perché:** Chiama sender, mai `headers_list()`. Header X-Robots-Tag feed può sparire in silenzio.
- **azione:** Assert header.

#### TST-004 — high — nessun test 301/302 `wp_safe_redirect`
- **file:** `tests/test-redirects-runtime.php:198-272` vs runner `:69-82`
- **perché:** Copre 410/loop/empty/admin-skip; nessuna Location 301/302/308.
- **azione:** Assert Location + status.

#### TST-005 — high — attachment redirect parent/file non testati
- **file:** `tests/test-meta-helpers.php:225-244` vs `meta-render.php:33-69`
- **perché:** Solo `attachment_redirect=none`. Parent/file+exit untested.
- **azione:** Casi parent e file.

#### TST-006 — high — autosave probe DOMParser fake
- **file:** `tests/js/settings-autosave-probe.cjs:249-261,374-422`
- **perché:** DOMParser fake ignora HTML. Probe non vede markup rotto.
- **azione:** Parser reale o assert innerHTML.

#### TST-007 — high — test escape senza `\` `"` `\n`
- **file:** `tests/test-helpers-template-variables.php:107-114`
- **perché:** Nome “escapes_backslashes_and_newlines” usa fixture senza quei caratteri. Non può fallire sul ramo.
- **azione:** Fixture con `\`, `"`, newline.

#### TST-008 — high — inherit robots ramo rischioso
- **file:** `tests/test-helpers-global-meta.php:129-134` vs `global-meta.php:217-249`
- **perché:** Store inherit → assert inherit. Coppia inherit + `_erankly_noindex=1` untested (legacy bloccato).
- **azione:** Assert coppia inherit+legacy.

#### TST-009 — high — adapter tabelle assenti = success
- **file:** `tests/test-migrations-adapters.php:854-861,1137-1141,1288-1309`
- **perché:** Rank Math/AIOSEO batch vuoti-senza-tabella = success. Mapper SQL rotto resta verde.
- **azione:** Fixture tabelle o fail-closed.

#### TST-010 — high — live-audit non può fallire
- **file:** `tests/live-schema-audit.php:72-90,416-419,853-857`
- **perché:** `scripts[0]`, `inLanguage` vuoto, WC sempre PASS. Harness stampa PASS su rami morti.
- **azione:** Assert fail-able; non CI.

### Medium — pass 1 (espansi)

#### M-01 — medium — correttezza / commento
- **file:** `easyrankly.php:157-171`
- **perché:** Il ramo `else` gira solo se `$key !== ERANKLY_OPTION`, quindi `ERANKLY_OPTION === $key` è sempre false. Il commento “autoload the settings array” è fuorviante; l’autoload è sempre `false` lì.
- **azione:** `update_option( $key, $value, false )` e commento onesto.

#### M-02 — medium — correttezza
- **file:** `includes/compatibility.php:134-152`
- **see also:** INF-041
- **perché:** Il nome `erankly_enable_sitemaps_with_external_seo` dice “enable” ma il bool è **suppress**. Un add-on `add_filter(..., '__return_true')` sopprime i sitemap.
- **azione:** Deprecare l’alias; solo `erankly_suppress_sitemaps_with_external_seo`.

#### M-03 — medium — bloat
- **file:** `includes/compatibility-legacy.php:12-95`
- **see also:** INF-038
- **perché:** 60 wrapper priority 999, 99 args, registrati ogni request. `has_filter(legacy)` è dentro il callback: il wrapper gira comunque.
- **azione:** Registrare l’alias solo se `has_filter($legacy)` a `plugins_loaded:99`.

#### M-04 — medium — bug-latente
- **file:** `includes/canonical.php:8-13`; `includes/title-description.php:52-57,159-163`; `includes/opengraph.php:327-332`
- **see also:** SEO-037
- **perché:** Se qualcosa chiama queste funzioni prima che la query sia pronta, cachea stringa vuota/sbagliata per tutto il request.
- **azione:** Non cachare se `! did_action('wp')`.

#### M-05 — medium — bug-latente
- **file:** `includes/helpers/utils.php:41-48`; caller `includes/canonical.php:59-60`
- **see also:** INF-030, SEO-036
- **perché:** Date archive con permalinks plain (`?m=202401`) → canonical = home.
- **azione:** Per `is_date()` usare query args o helper paged-archive.

#### M-06 — medium — bug-latente
- **file:** `includes/helpers/core.php:49-51`; `easyrankly.php:328-330,388-401`
- **see also:** INF-011
- **perché:** `! is_admin && ! ajax && ! cron` include feed/embed. Custom code ha guardie extra; head SEO no.
- **azione:** Allineare a `erankly_custom_code_should_output()`.

#### M-07 — medium — correttezza
- **file:** `includes/redirects/class-erankly-redirects-runner.php:33-37`
- **perché:** Admin con `manage_options` non subisce mai redirect. QA 1/1000 (“i redirect non funzionano”).
- **azione:** Bypass opzionale o `?erankly_redirect_preview=1`.

#### M-08 — medium — bug-latente
- **file:** `includes/compatibility-woocommerce.php:134-154`
- **perché:** Review approvate senza meta `rating` finiscono nel grafo senza `reviewRating`.
- **azione:** Emmettere Review solo con rating numerico.

#### M-09 — medium — incompletezza
- **file:** `includes/meta.php:653-669,696-698`
- **perché:** `has_content` guarda solo `custom_json`. Un blocco add-on senza JSON viene droppato.
- **azione:** Considerare i fields filtrati.

#### M-10 — medium — bug-latente
- **file:** `includes/opengraph.php:461-473`
- **perché:** `LIKE %/basename` + `ORDER BY post_id DESC LIMIT 1` può scegliere l’allegato sbagliato.
- **azione:** Match esatto del path relativo.

#### M-11 — medium — incompletezza
- **file:** `includes/helpers/sanitization.php:840-852`
- **perché:** `erankly_trim_text` tronca a N-1 senza ellipsis.
- **azione:** Appendere `…` quando si taglia.

#### M-12 — medium — bug-latente
- **file:** `uninstall.php:365-409`
- **perché:** `get_users` a pagine di 100 senza tetto. Su 50k utenti l’uninstall HTTP scade a metà.
- **azione:** Tetto CLI o saltare transient per-user.

#### M-13 — medium — correttezza
- **file:** `includes/meta.php:313-320`
- **perché:** `update_post_metadata` skip null schema return `true` (“già aggiornato”). Opaco per altri plugin.
- **azione:** Documentare/testare che i blocchi non si cancellano su null Gutenberg.

#### M-14 — medium — correttezza
- **file:** `includes/class-erankly-specialist-sitemaps-provider.php:18-27` vs 30-55
- **perché:** News non guarda `count > 0` (ceil 0 è innocuo). Incoerenza.
- **azione:** Uniformare al guard `> 0`.

#### M-15 — medium — incompletezza
- **file:** `includes/class-erankly-specialist-sitemaps-provider.php:67-77`
- **perché:** `get_max_num_pages()` = 0 sempre. Se WP cambia, l’indice mente.
- **azione:** Implementare o `@internal` + test di non-chiamata.

#### M-16 — medium — correttezza
- **file:** `easyrankly.php:1058-1066`
- **perché:** Errori settings restano HTTP 200 `saved: true, incomplete: true`. JS può mostrare “salvato”.
- **azione:** Probe JS + UI notice.

#### M-17 — medium — bug-latente
- **file:** `admin/settings-page.php:127-135`
- **perché:** `global_schema_blocks` assente nell’input diventa `[]` (wipe) se un caller passa un subset.
- **azione:** Preservare stored se chiave assente, salvo panel collection-keys.

#### M-18 — medium — incompletezza
- **file:** `tests/test-js-contracts.php` (intero)
- **see also:** TST-012
- **perché:** Quasi tutti `assertStringContainsString`. Node probe skippati se `node` assente.
- **azione:** Job Node esplicito; non skip in CI.

### Medium — pass 2 (nuovi)

#### INF-010 — medium — autoload
- **file:** `includes/settings-lock.php:226-228`; `includes/helpers/sanitization.php:301-314,717-781`
- **perché:** Settings sempre autoload; fino a ~100KB×3 location (+ legacy) in `alloptions`. Un sito con snippet grandi gonfia ogni request frontend.
- **azione:** Custom code in option non-autoload o dedicata; o rifiutare oversize.

#### INF-012 — medium — cache vars
- **file:** `includes/helpers/template-variables.php:60-67,231-234`
- **perché:** Cache `$key:$post_id` ignora queried/paged. `{{page_number}}` è `max(1,paged)` anche sulla home (template “Page {{page_number}}” sporca).
- **azione:** Cache key + context. `page_number` vuoto se `$paged < 2`.

#### INF-013 — medium — mapping
- **file:** `includes/localized-value-writer.php:27-28`
- **perché:** `archive_seo_title` / `archive_seo_description` mappano su `global_special_meta.author`, non date archives. Import scrive title archive sull’author.
- **azione:** Mappare su slot date (o dedicato).

#### INF-014 — medium — mapping
- **file:** `includes/migrations/class-erankly-migration-adapter-rankmath.php:275-277`
- **perché:** Qualsiasi array `rank-math-options-sitemap` non vuoto setta `enable_sitemap=1` anche se il modulo sitemap è off.
- **azione:** Leggere il flag enable reale.

#### INF-015 — medium — Woo OG
- **file:** `includes/compatibility-woocommerce.php:28-31`
- **perché:** `erankly_get_og_image()` è della request, non di `$post_id`. Product schema su contesto non-prodotto prende l’immagine sbagliata.
- **azione:** Featured/`_product_image_gallery` di `$post_id`.

#### INF-017 — medium — frontend cost
- **file:** `includes/redirects/class-erankly-redirects-repository.php:166-184`
- **perché:** Regex non hanno path prefix (solo exact e wildcard con primo segmento). Tutte le regex in `global.any` su ogni request frontend.
- **azione:** Bucket regex con prefisso letterale.

#### INF-018 — medium — cache
- **file:** `includes/reset.php:191-222` vs `class-erankly-import-job-runner.php:704-707`
- **perché:** Reset SQL-delete meta senza `wp_cache_flush()`. Import purge flusha. Object cache stale dopo reset.
- **azione:** Flush dopo bulk SQL meta delete.

#### INF-019 — medium — ETag
- **file:** `includes/helpers/utils.php:106-167`
- **perché:** ETag = hash($body); output 200 è `DOMDocument->save()` che può differire (whitespace/encoding). 304 vs body fresco inconsistenti.
- **azione:** Hashare i bytes emessi, o emettere `$body` originale.

#### INF-020 — medium — sanitizer mismatch
- **file:** `includes/migrations/class-erankly-migration-adapter.php:852-870` vs `includes/helpers/defaults.php:262-275`
- **perché:** Adapter `preg_replace('/[^A-Za-z0-9_-]/','')` ≠ `erankly_sanitize_schema_type_name` (allowlist tipi). Tipo inventato passa l’adapter e fallisce/altera in settings.
- **azione:** Stesso sanitizer del core.

#### INF-021 — medium — targeting
- **file:** `includes/helpers/sanitization.php:362-379`
- **perché:** `front_page` matcha e return. Include/exclude ignorati. Static Page on front è anche singular: “front_page + exclude questa pagina” gira comunque.
- **azione:** Se `is_front_page() && is_singular()`, applicare include/exclude.

#### INF-023 — medium — mapping
- **file:** `includes/migrations/runtime-redirects.php:32-42`; `class-erankly-migration-adapter-rankmath.php:422-429,521-528`
- **perché:** Rank Math `contains` → regex `preg_quote(normalize_path())` unanchored. `normalize_path` toglie host/query: “contains” su URL piena diventa substring path.
- **azione:** Preservare semantica contains o skip + documentare.

#### INF-024 — medium — race
- **file:** `includes/network-reset.php:225-240`
- **perché:** `update_site_option` senza CAS. Due queue overwrite token; cron vecchio no-op. See INF-080.
- **azione:** CAS-insert; rifiutare se job già running.

#### INF-025 — medium — memory
- **file:** `includes/redirects/class-erankly-redirects-activator.php:71`
- **perché:** Backfill v3 `SELECT * FROM redirects ORDER BY id` unbounded. Tabella grande → OOM su activate.
- **azione:** Keyset-page.

#### INF-027 — medium — unsanitized robots
- **file:** `includes/helpers/global-meta.php:217-249`
- **perché:** Direttiva object raw se non `''`/`inherit`. Meta crafted (`noindex, follow`, token extra) arriva in output senza allowlist.
- **azione:** Allowlist come `erankly_sanitize_global_entity_directives`.

#### SEO-011 — medium — robots
- **file:** `includes/robots.php:106-167,257-279`
- **perché:** Oggetto `index` vince su `noindex_paginated`. Pagina 2+ di un post indexable resta index anche se il setting paginated è noindex.
- **azione:** Applicare noindex_paginated dopo l’override `index`, o documentare la precedenza.

#### SEO-013 — medium — breadcrumbs
- **file:** `includes/breadcrumbs.php:23-48`
- **perché:** Check `< 2` crumb **prima** di skip name vuoti. Un trail di 2 item di cui uno senza name diventa 1 item ma passa il gate; JSON-LD position può risultare 1 solo.
- **azione:** Contare dopo il filtro name.

#### SEO-015 — medium — sitemap
- **file:** `includes/sitemap/image.php:114-180`
- **perché:** Filtro `loc` applicato ai candidate ma l’XML usa l’URL originale. Integratori che riscrivono `loc` non vedono l’effetto.
- **azione:** Usare il `loc` filtrato in XML.

#### SEO-016 — medium — sitemap
- **file:** `includes/sitemap/video.php:221-266` vs `image.php:210-213`
- **perché:** Video urlset vuoto → 200; image empty → 404. Crawler indexa sitemap video vuota.
- **azione:** 404 come image.

#### SEO-017 — medium — lastmod
- **file:** `includes/sitemap/core.php:206`; `class-erankly-site-sitemaps-provider.php:148-149`
- **perché:** `strtotime` su stringa GMT senza ` UTC` usa TZ PHP. lastmod off-by-hours.
- **azione:** Helper GMT unico (`strtotime( $s . ' UTC' )` o `gmdate`).

#### SEO-018 — medium — sitemap
- **file:** `includes/sitemap/core.php:579-593,921-924`
- **perché:** Canonical non-self carica `posts_per_page=-1` per decidere hide. Archivio enorme → memory/timeout sul build sitemap.
- **azione:** Chunk / `fields=ids` + LIMIT.

#### SEO-019 — medium — visibility
- **file:** `includes/meta-visibility.php:10-11`
- **perché:** `exclude_search` solo main query non-AJAX. Search AJAX / secondary query include post noindex.
- **azione:** Applicare anche a AJAX search e query `s` non-main se è search UI.

#### SEO-020 — medium — canonical
- **file:** `includes/canonical.php:17-18,61-62`
- **perché:** Search paged canonical → page 1. Pagina 2+ di search dichiara canonical page 1 (duplicate vs intent).
- **azione:** Self-canonical paged o noindex paged search.

#### SEO-022 — medium — schema
- **file:** `includes/schema.php:581-586`
- **perché:** SearchAction sempre `/?s=`. Siti con permalink search custom / WPML rompono il target.
- **azione:** Usare `home_url( '/' )` + query arg reale / `get_search_link`.

#### SEO-023 — medium — robots.txt
- **file:** `includes/robots.php:434-441` vs `:504-509`
- **perché:** Preview admin usa `admin_url`; live usa `site_url`. Preview MS/subdirectory mente rispetto al file pubblico.
- **azione:** Stesso builder URL del live.

#### SEO-024 — medium — load cost
- **file:** `includes/schema-jsonld.php:627-943`
- **perché:** Helper LocalBusiness admin sempre loaded con lo schema frontend. Costo parse su ogni HTML request.
- **azione:** Split admin-only in `admin/`.

#### SEO-025 — medium — timezone
- **file:** `includes/schema-content.php:573-631,722-731`
- **perché:** Event `strtotime` + `gmdate` ignora TZ evento/sito. Orari ISO sbagliati di ore.
- **azione:** `wp_date` / DateTimeZone del post.

#### SEO-026 — medium — collision
- **file:** `includes/schema-content.php:641-753`
- **perché:** Chiavi event generiche (`start_date`, `location`) collision-prone con altri plugin meta.
- **azione:** Prefisso `_erankly_` o allowlist esplicita.

#### SEO-027 — medium — HowTo
- **file:** `includes/schema-content.php:287-290`
- **perché:** Solo il primo blocco Yoast/RM HowTo entra nel grafo. Post con 2 HowTo perde il secondo.
- **azione:** Merge tutti i blocchi o documentare first-only.

#### SEO-028 — medium — oEmbed
- **file:** `includes/opengraph.php:286-301`
- **perché:** oEmbed JSON su ogni canonical, anche 404. Request extra + URL inventata.
- **azione:** Solo singular pubblicato.

#### SEO-029 — medium — attachment
- **file:** `includes/meta-render.php:38-56`
- **perché:** `attachment_redirect=parent` senza parent cade su file. UI lo dice; redirect 301 su file può loopare o uscire dal sito.
- **azione:** Test + documentare; fail-closed se parent assente.

#### SEO-030 — medium — news cache
- **file:** `includes/sitemap/news.php:241-260`
- **perché:** Publication name vuoto cachato 1h. News sitemap emette name `''` per un’ora dopo fix settings.
- **azione:** Non cachare name vuoto.

#### SEO-032 — medium — visibility
- **file:** `includes/breadcrumbs.php:332-343,456-472`
- **perché:** `when_visible` crede a `has_block`/`has_shortcode` sul post corrente. Template theme / widget / pattern fuori content → false negative; codice in commento → false positive.
- **azione:** Rilevare output reale o documentare i limiti.

#### FE-001 — medium — JS throw
- **file:** `assets/js/accordion-faq-schema.js`; `editor-shared.js`; `editor.js`; `blocks/breadcrumbs/index.js`
- **perché:** Accesso a `wp.*` senza guard. Console throw se lo script è enqueue fuori Gutenberg/settings.
- **azione:** Bail `if ( ! window.wp ) return;`.

#### FE-002 — medium — site-editor
- **file:** `assets/js/site-editor.js:15-21`
- **perché:** `wp.editor` / data letto **prima** del bail shared. Throw se `wp` parziale.
- **azione:** Guard prima di ogni accesso.

#### FE-003 — medium — editor.js
- **file:** `assets/js/editor.js:5,23,65`
- **perché:** Nessun bail su shared/config assenti. Metabox Gutenberg rotto se localize manca.
- **azione:** Early return + notice.

#### FE-004 — medium — null deref
- **file:** `assets/js/admin-reset.js:35-53`
- **perché:** Modal nodes usati senza null-check. Click Reset su DOM incompleto → throw, form nativo può non partire.
- **azione:** Return se manca overlay/confirm.

#### FE-005 — medium — a11y
- **file:** `assets/js/admin-variables.js:283-287`
- **perché:** Listbox option id duplicati (stesso prefisso + index su due liste). AT/ancoraggi sbagliati.
- **azione:** Prefisso univoco per lista.

#### FE-006 — medium — breakpoint
- **file:** `assets/js/admin-panels.js:12-18` vs `assets/css/admin-core.css:727-735`
- **perché:** JS `<=960` vs CSS `<960` (o viceversa). A 960px nav/mobile e JS discordano.
- **azione:** Stesso predicato inclusivo/esclusivo.

#### FE-007 — medium — preview
- **file:** `assets/js/editor-shared.js:178-186` vs `admin-variables.js:149-157`
- **perché:** Preview variabili editor ≠ settings (token set / sample). L’utente vede due output per lo stesso `{{var}}`.
- **azione:** Una funzione shared.

#### FE-009 — medium — i18n
- **file:** `includes/breadcrumbs.php:706`
- **perché:** `__( '(no title)' )` senza domain `easyrankly` (match core; phpcs ignore). POT non lo esporta.
- **azione:** Lasciare se regola prodotto = core; altrimenti domain.

#### ADM-001 — high — Gutenberg senza primary terms
- **type:** incompletezza / Gutenberg vs frontend
- **file:** `admin/meta-box.php:110-138`
- **perché:** Classic rende `erankly_primary_terms[...]`. `editor.js` non mappa `_erankly_primary_terms`. Breadcrumb/schema frontend leggono quella meta. Utenti block editor non possono impostare/cambiare una primary category migrata.
- **azione:** Controllo in sidebar Gutenberg, o togliere il controllo classic se “automatic only” è la regola.

#### ADM-002 — high — save classic cancella `_erankly_exclude_from_news`
- **type:** bug-latente / data loss
- **file:** `admin/meta-box/post-saver.php:129-143` + `admin/meta-box.php:275-278`
- **perché:** Il checkbox esiste solo se `enable_news_sitemap` è on. Il saver è sempre in `$booleans` e **delete** se assente. Salvare un post con news sitemap off azzera un’esclusione già salvata. Gutenberg scrive la chiave solo se `newsSitemap` è enabled (preserva).
- **azione:** Skip write se il setting news è off (come `breadcrumb_name`).

#### ADM-003 — high — special pages invisibili senza `edit_theme_options`
- **type:** incompletezza / capability
- **file:** `includes/admin.php:29-31`; `admin/assets/editor.php:147-153`; `admin/settings/panels.php:188-196`
- **perché:** `erankly_use_site_editor_special_page_panels()` = block theme + WP ≥ 6.6, **senza** check cap. Nasconde il fallback settings. Gli asset Site Editor richiedono **sia** `edit_theme_options` **sia** `manage_options`. Un ruolo solo `manage_options` non ha UI special-page.
- **azione:** Gate sul current user; tenere il fallback settings se le cap falliscono.

#### ADM-004 — medium — wrong_surface (alias: ADM-01)
- **file:** `admin/assets/settings.php:15-37`
- **perché:** `$is_editor` = post_type pubblico, non `post.php`. `edit.php` carica CSS/JS classic + media.
- **azione:** Limitare a `$screen->base` in `{post, post-new}`.

#### ADM-030 — medium — label (alias: ADM-02)
- **file:** `admin/settings/page-renderer.php:243-244`
- **perché:** Special-pages tab etichettata «General» su MS site. Confonde con il tab General network.
- **azione:** Label «Special pages» / «Pagine speciali».

#### ADM-005 — medium — gutenberg_divergence
- **file:** `admin/settings/renderers.php:642-644,721-731` vs `assets/js/site-editor.js:104-112`
- **perché:** PHP mostra Disable-sitemap solo per author. Site Editor simplified hide richiede anche `disable_sitemap`. 404/search noindex appaiono non-hidden.
- **azione:** Flag feature so hide matches PHP.

#### ADM-006 — medium — incomplete_ui
- **file:** `admin/settings/page-renderer.php:181,320-338`
- **perché:** Special-pages standalone non ha noscript submit. No-JS / JS fail: solo Enter accidentale.
- **azione:** `submit_button()` noscript nel form special-pages.

#### ADM-007 — medium — extension_api
- **file:** `includes/admin.php:87-109,158-173` vs `page-renderer.php:86-106`
- **perché:** Unknown slugs preservati su single-site; su MS per-site allowlist stretta. Addon nav morta.
- **azione:** Stesso extra-tab set su entrambi i filtri.

#### ADM-008 — medium — comment_lie
- **file:** `admin/meta-box.php:2-6,62-142`
- **perché:** Header dice PHP shared with `editor.js`. Non lo è. Drift ADM-001.
- **azione:** Spostare placeholder; non pretendere sharing.

#### ADM-009 — medium — incomplete_ui
- **file:** `admin/settings/panels.php:13-16`
- **perché:** Features lascia checkare custom code senza `unfiltered_html`; sanitizer revert. UI bugiarda.
- **azione:** Disable checkbox + hide Delete se `! unfiltered_html`.

#### ADM-010 — medium — rest_wipe
- **file:** `easyrankly.php:1108-1114`
- **perché:** Payload senza `global_special_meta` scrive mappa vuota e droppa noindex search/404.
- **azione:** Reject empty/missing o merge sulla mappa stored.

#### ADM-011 — low — placeholder_vs_counter
- **file:** `admin/meta-box.php:70-82` + `admin/assets/editor.php:74`
- **perché:** Title placeholder trim 70; counter 65.
- **azione:** Allineare a 65 (o 70).

#### ADM-012 — low — dead_doc_link
- **file:** `admin/meta-box.php:464,474`
- **perché:** `erankly_render_section_doc_link('term-meta')` — chiave assente.
- **azione:** Aggiungere URL o non wrappare.

#### ADM-013 — low — incomplete_docs
- **file:** `admin/settings/panels.php` + `section-links.php:8-15`
- **perché:** Molti `doc` keys senza URL. Non funzionale.
- **azione:** URL o drop unused keys.

#### ADM-014 — low — comment_lie
- **file:** `admin/settings/renderers.php:741-742`
- **perché:** Commento invertito su author vs non-author disable-sitemap.
- **azione:** Fix comment.

#### ADM-015 — low — comment_lie
- **file:** `includes/admin.php:87-88`
- **perché:** “Unknown slugs preserved” falso su MS per-site (ADM-007).
- **azione:** Fix docblock.

#### ADM-016 — low — over_engineered_include
- **file:** `admin/assets/editor.php:29-30,104`
- **perché:** Block editor require meta-box.php solo per placeholder helpers.
- **azione:** Muovere helper; drop unused require.

#### ADM-017 — low — incomplete_localize
- **file:** `admin/assets/editor.php:177-196`
- **perché:** Site Editor config: placeholder title/description vuoti.
- **azione:** Passare esempi come il post editor.

#### ADM-018 — low — dead_reads (alias: ADM-03)
- **file:** `admin/meta-box.php:231-233,487-489`
- **perché:** `$noindex/$nofollow/$noarchive` caricati e mai usati.
- **azione:** Delete assignments.

#### ADM-019 — low — thin_wrappers
- **file:** `admin/meta-box.php:59-61`; `includes/admin.php:323-351`
- **perché:** Alias one-line / tre helper per un sprintf.
- **azione:** Inline se non API.

#### ADM-020 — low — api_footgun
- **file:** `admin/settings/nav-icons.php:19-52`
- **perché:** Filtro `erankly_nav_icons` poi overwrite `special-pages`.
- **azione:** Non overwrite dopo il filtro.

#### ADM-021 — low — copy_divergence
- **file:** `admin/meta-box.php:272-273` vs `editor-shared.js:1057-1078`
- **perché:** Stessi flag, stringhe diverse classic vs Gutenberg.
- **azione:** Un msgid.

#### ADM-022 — low — a11y
- **file:** `admin/settings/page-renderer.php:234-236,347`
- **perché:** Toggle label vuota fino a JS; redirects panel id mancante vs aria-labelledby.
- **azione:** Label SSR; id matching.

#### ADM-023 — nit — html (alias: ADM-04)
- **file:** `admin/settings/page-renderer.php:218-295`
- **perché:** Hidden `erankly_settings_panel` orfano su MS per-site (no form padre).
- **azione:** Non stampare fuori dal form.

#### ADM-024 — nit — over_engineered
- **file:** `includes/admin.php:393-405,473-486`
- **perché:** `blocks` alias di `schema`; fallback omits reset.
- **azione:** Un handle; commento onesto.

#### ADM-025 — nit — latent
- **file:** `admin/field-renderers.php:109,117`
- **perché:** `wp_json_encode` fail → `""`; `strtolower` non mb-safe.
- **azione:** Fallback; mb_strtolower.

#### ADM-026 — nit — ux
- **file:** `admin/meta-box.php:115-116`
- **perché:** Primary dropdown skip se `! has_term`. Due save per assegnare.
- **azione:** Mostrare vuoto o Gutenberg (ADM-001).

#### ADM-027 — low — load_cost
- **file:** `includes/admin.php:272-276`
- **perché:** `save_post` require meta-box.php anche senza nonce.
- **azione:** Require solo se nonce presente.

#### ADM-028 — low — gutenberg_autosave
- **file:** `admin/meta-box/post-saver.php:13-15`
- **perché:** Classic DOING_AUTOSAVE no-op; Gutenberg REST persiste. Crash classic perde title.
- **azione:** Documentare o allineare.

#### ADM-029 — low — maxlength
- **file:** `admin/meta-box.php:107` vs `editor-shared.js:609-615`
- **perché:** Classic breadcrumb maxlength 120; Gutenberg no; sanitizer no cap.
- **azione:** Stesso limite o nessuno.

#### INF-022 — medium — rumore mapping
- **file:** adapters (mapping sempre emette stringhe vuote; runner skip via `is_meaningful`)
- **perché:** Report/UI può mostrare campi “mapped” vuoti. Non data-loss.
- **azione:** Non emettere row vuote dal mapper.

#### INF-026 — medium — flush save_post
- **file:** `includes/helpers/sitemap-cache.php:54-64`
- **perché:** Flush solo publish viewable. OK; revision/draft no-op (TST-036 manca positive path).
- **azione:** Test publish incrementa generation.

#### INF-048 — medium — targeting archive vuoto
- **file:** `includes/helpers/sanitization.php:385-394`
- **perché:** `target_post_types` vuoto su post_type_archive → never match (fail-closed, coerente).
- **azione:** Nessuna se documentato in UI (già settings error).

#### SEO-031 — medium — image page OOB
- **file:** `includes/sitemap/image.php:210-213`
- **perché:** Empty slice page returns `''` without caching → repeat cold work.
- **azione:** Cache empty 404.

#### SEO-033 — medium — render_block in schema
- **file:** `includes/breadcrumbs.php:456-472`
- **perché:** Preview `render_block()` durante schema build può enqueue/side-effect.
- **azione:** Parse items senza render pieno.

#### SEO-034 — medium — og:type product
- **file:** `includes/opengraph.php` (product type senza property prodotto)
- **perché:** `og:type=product` incompleto vs OG product spec.
- **azione:** Non emettere product o aggiungere price/etc.

#### SEO-035 — medium — max-snippet inherit
- **file:** `includes/robots.php` output max-snippet da settings
- **perché:** Valori non integer-validati all’output.
- **azione:** Cast/validate -1..n.

#### SEO-036 — medium — see M-05
- **file:** `includes/helpers/utils.php:41-48`
- **perché:** alias di M-05 per ricerca SEO-*.

#### SEO-037 — medium — see M-04
- **file:** canonical/title/OG static cache

#### FE-016 — medium — max-blocks editor vs settings
- **file:** `assets/js/editor.js:99-119` vs `admin-schema.js:214-231`
- **perché:** Settings rispetta max-blocks; post editor `addBlock()` no cap.
- **azione:** Share cap + stesso validator.

#### FE-017 — medium — LB list null
- **file:** `assets/js/admin-widgets.js:618-656`
- **perché:** `list` non null-checked; throw se nodo assente.
- **azione:** Return se `!list`.

#### FE-021 — medium — dropzone position
- **file:** `assets/css/migration.css:342-380`
- **perché:** Input absolute senza parent `position: relative`.
- **azione:** `position: relative` su `.erankly-dropzone`.

#### FE-022 — medium — color-mix
- **file:** `assets/css/admin-settings.css:467,513,730`; `shared.css:37-38`
- **perché:** Senza fallback solido. Browser WP 6.5-era possono droppare.
- **azione:** Colore solido prima di `color-mix`.

#### FE-023 — medium — redirects json()
- **file:** `assets/js/redirects.js:20-27,327-333`
- **perché:** HTML 403 → `response.json()` reject.
- **azione:** `text()` + JSON.parse try/catch.

### TST medium (espansi; non collassare)

#### TST-011 — medium — skip-by-default
- **file:** `tests/test-breadcrumb-registration.php:12-15` + `tests/bootstrap.php:32-47`
- **perché:** Default PHPUnit non setta `ERANKLY_TEST_DEREGISTER_CORE_BREADCRUMBS=1` prima del bootstrap. Contratto inserter legacy unenforced.
- **azione:** Job CI con env var.

#### TST-012 — medium — tautology
- **file:** `tests/test-js-contracts.php:20-97`
- **perché:** String-contains non prova runtime. Vedi M-18.
- **azione:** Probe per redirects/schema/accordion.

#### TST-013 — medium — incomplete-fixture
- **file:** `tests/js/local-business-widget-probe.cjs:12-141`
- **perché:** FakeNode senza `classList`; toggle closed unexecuted.
- **azione:** Aggiungere classList + assert.

#### TST-014 — medium — cannot-simulate-conflict
- **file:** `tests/test-compatibility.php:148-177`
- **perché:** Owner SEO memoizzato; notice conflitto mai emessa in test.
- **azione:** Separate-process stub owner.

#### TST-015 — medium — tautology
- **file:** `tests/test-schema.php:226-227`
- **perché:** `assertIsArray` + `assertNotNull` sullo stesso valore.
- **azione:** Drop NotNull.

#### TST-016 — medium — weak-assertion
- **file:** `tests/test-schema.php:491-494`
- **perché:** Solo `count === 2` su type list.
- **azione:** Assert valori canonici.

#### TST-017 — medium — test-isolation
- **file:** `tests/test-schema.php:471-473`
- **perché:** `remove_all_filters('erankly_faq_items')` strippa il resto del process.
- **azione:** `remove_filter` della closure.

#### TST-018 — medium — tautology
- **file:** `tests/test-breadcrumbs.php:414-423`
- **perché:** `should_emit === has_visible` senza render.
- **azione:** Fixture visible vs not.

#### TST-019 — medium — tautology
- **file:** `tests/test-breadcrumbs.php:345-350`
- **perché:** Alias confrontati tra loro; entrambi `''` passano.
- **azione:** Confrontare su HTML non vuoto.

#### TST-020 — medium — assertion-cannot-fail
- **file:** `tests/test-redirects-runtime.php:144-154`
- **perché:** `has_action('parse_request')` senza callback. Core ha sempre l’hook.
- **azione:** `has_action(..., array($runner,'maybe_redirect'))`.

#### TST-021 — medium — nonce-only
- **file:** `tests/test-redirects-runtime.php:840-877`
- **perché:** Solo WPDieException missing nonce. Success save/toggle untested.
- **azione:** Nonce valido + assert row.

#### TST-022 — medium — REST hole
- **file:** `tests/test-redirects-runtime.php:355-451`
- **perché:** No create/update/list REST.
- **azione:** Dispatch POST/GET via rest_get_server.

#### TST-023 — medium — environment-coupled
- **file:** `tests/test-sitemap.php:38-43`
- **perché:** Assume default enable_sitemap=0.
- **azione:** Set explicit off/on.

#### TST-024 — medium — skip-hides-branch
- **file:** `tests/test-sitemap-providers.php:178-211`
- **perché:** Buffer on sitemap request assente; 304 skip se headers_sent.
- **azione:** Test con query var sitemap.

#### TST-025 — medium — skip-hides-destructive
- **file:** `tests/test-reset-actions.php:171-178`
- **perché:** DROP TABLE skip SQLite. Uninstall riscrive DROP in SELECT 1.
- **azione:** Job MySQL o assert table gone.

#### TST-026 — medium — synthetic-query
- **file:** `tests/test-title-description.php:342-355`; `test-robots-output.php:104-116`
- **perché:** Pagination mutando `$wp_query->is_paged` su singular, non `go_to(/page/2/)`.
- **azione:** Request paged reale.

#### TST-027 — medium — filter swallowed
- **file:** `tests/test-import-export.php:118-131`
- **perché:** Assert range 1024–2048 anche se filtro ignorato (min cap).
- **azione:** `assertSame(2048)` se cap permette.

#### TST-028 — medium — incomplete-WC
- **file:** `tests/fixtures/woocommerce-doubles.inc:20-109`
- **perché:** Doubles EUR hardcoded; no Product JSON-LD on permalink.
- **azione:** Gruppo opzionale WC-installed.

#### TST-029 — medium — WP_DEBUG coupling
- **file:** `tests/test-schema-jsonld.php:81-103`
- **perché:** Non definisce WP_DEBUG; fail se false.
- **azione:** Separate-process true/false.

#### TST-030 — medium — duplicate-bloat
- **file:** più test-* (sitemap, uninstall regex, admin render, meta, JSON-LD, custom-code, breadcrumbs)
- **perché:** Stesse linee produzione, tempo suite extra.
- **azione:** Un owner per contratto.

#### TST-031 — medium — CSS-as-test
- **file:** `tests/test-admin-renderers.php:307-321`
- **perché:** Substring CSS non prova match markup.
- **azione:** Probe DOM.

#### TST-032 — medium — live-audit mutates
- **file:** `tests/live-schema-audit.php:156-882`
- **perché:** Scrive pagine/settings reali. Fatal prima di finally lascia dirty.
- **azione:** Non CI.

#### TST-036 — medium — missing-positive-flush
- **file:** `tests/test-helpers-cache.php:145-162`
- **perché:** Solo assert “non bump” revision/draft.
- **azione:** Publish incrementa generation.

#### TST-037 — medium — OG fallbacks
- **file:** `tests/test-opengraph-output.php:221-239`
- **perché:** Non testa featured/content/term/author image.
- **azione:** Featured + content image.

#### TST-038 — medium — canonical holes
- **file:** `tests/test-canonical.php` (139)
- **perché:** No author/date/CPT/paged singular/attachment.
- **azione:** Aggiungere quei go_to.

#### TST-039 — medium — editor schema UX
- **file:** `tests/schema-recheck.md:14-27`
- **perché:** aria-invalid / Gutenberg persist solo Playwright notes.
- **azione:** Probe isValidTypeName.

### Low — pass 1 (espansi)

#### L-01 — low — bloat
- **file:** `includes/schema.php:1011-1021`; `includes/import-export/report.php:494-498`
- **perché:** `erankly_global_schema_matches_*`, `erankly_schema_target_list_contains_post`, `erankly_third_party_data_exists` zero caller produzione.
- **azione:** Eliminare o usare.

#### L-02 — low — bloat
- **file:** `includes/breadcrumbs.php:712-740`
- **perché:** `erankly_core_breadcrumbs_default_attributes` solo test.
- **azione:** Spostare nei test helper.

#### L-03 — low — bloat
- **file:** `includes/helpers/video.php:58-65,91-94,107-110,131-134`
- **perché:** Quattro alias back-compat.
- **azione:** Un nome; grep consolidare.

#### L-04 — low — bloat
- **file:** `includes/helpers/sanitization.php:324-327`
- **perché:** `erankly_custom_code_context_allowlist` = alias.
- **azione:** Inline.

#### L-05 — low — sovra-ingegneria
- **file:** `includes/custom-code.php:147-149`; `includes/schema.php:1007-1009`
- **perché:** Wrapper di `erankly_targeted_block_matches_request`.
- **azione:** Chiamare direttamente.

#### L-06 — low — bloat
- **file:** `includes/schema.php:720-730`
- **perché:** `erankly_schema_blogposting` solo test + hook legacy.
- **azione:** Deprecare se non promised.

#### L-07 — low — API
- **file:** `includes/hreflang.php:46-58`
- **perché:** `erankly_get_navigable_hreflang_alternates` solo test + readme.
- **azione:** Tenere se Extension API.

#### L-08 — low — bloat giustificato
- **file:** `includes/breadcrumbs.php:86-94,982-984`
- **perché:** `easyrankly_breadcrumbs` + shortcode doppio. Compat.
- **azione:** Tenere.

#### L-09 — low — sovra-ingegneria
- **file:** `blocks/breadcrumbs/block.json:14`; `includes/breadcrumbs.php:1008-1017`; `blocks/breadcrumbs/index.js:28-32`
- **perché:** Triple source of truth inserter.
- **azione:** Commento “JSON fallback; PHP+JS vincono”.

#### L-10 — low — bug-latente
- **file:** `includes/schema.php:28-38`
- **perché:** `wp_json_encode` false → script vuoto.
- **azione:** Se false, non echo.

#### L-11 — low — commento
- **file:** `includes/helpers/template-variables.php:2`; anche `global-meta.php:2` (INF-051)
- **perché:** Dice “always loaded early”; loader è `erankly_load_content_helpers()`.
- **azione:** Correggere header.

#### L-12 — low — incompletezza
- **file:** `includes/schema-jsonld.php:704-734`
- **see also:** SEO-073
- **perché:** `$post_id` unused in `erankly_get_local_business_page_id`.
- **azione:** Rimuovere o usare.

#### L-13 — low — incompletezza
- **file:** `includes/helpers/video.php:17-21`
- **perché:** No `/shorts/ID`. VideoObject/sitemap mancanti.
- **azione:** Aggiungere shorts.

#### L-14 — low — incompletezza
- **file:** `includes/schema.php:601-605`; `helpers/defaults.php:271-273`
- **perché:** QAPage forzatamente WebPage.
- **azione:** Non elencare QAPage o costruire Question.

#### L-15 — low — incompletezza
- **file:** `phpunit.xml.dist:11`; `tests/live-schema-audit.php`
- **see also:** TST-001
- **perché:** Escluso da PHPUnit. Non è copertura CI.
- **azione:** Documentare come manuale.

#### L-16 — low — correttezza
- **file:** `easyrankly.php:162` e analoghi
- **perché:** `esc_html__` in throw; REST/log double-escape.
- **azione:** `__()` in throw.

### Low — pass 2/3

#### INF-028 — low — linked-entity robots
- **file:** `includes/helpers/global-meta.php:416-437`
- **perché:** Linked group può ereditare noindex del primo row non-vuoto.
- **azione:** Test + UI copy.

#### INF-031 — low — video extractor
- **file:** `includes/helpers/video.php` (stesso L-13)
- **perché:** Marker schema vs sitemap (SEO-008) + shorts.
- **azione:** Un haystack.

#### INF-032 — low — DOCTYPE case
- **file:** `includes/helpers/utils.php:99`
- **perché:** `str_contains('<!DOCTYPE')` case-sensitive. `<!doctype` bypassa il reject.
- **azione:** `stripos`.

#### INF-033 — low — @id slash
- **file:** schema identity IDs `home_url('/#organization')`
- **perché:** Trailing slash home vs no-slash può duplicare nodi.
- **azione:** Normalizzare home.

#### INF-034 — low — see L-11

#### INF-035 — low — migrate senza lock sul flag
- **file:** `includes/helpers/settings.php:267-316`
- **perché:** Flag `erankly_migrated_*` via `erankly_update_plugin_option` (non settings mutex). Due request possono migrare due volte (idempotente-ish).
- **azione:** Accettabile; documentare.

#### INF-036 — low — sanitize_text array
- **file:** `includes/helpers/sanitization.php:15-17`
- **perché:** `(string) $array` → `"Array"` che può passare pattern.
- **azione:** Reject non-scalar (defaults.php già lo fa per schema type).

#### INF-037 — low — custom-code migrate ogni init
- **file:** `includes/custom-code.php:9-52`
- **perché:** `init:18` legge settings ogni frontend request finché legacy non vuoto; write se changes.
- **azione:** Flag migrated.

#### INF-039 — low — export special_meta
- **file:** `includes/import-export/export.php:159`
- **perché:** Export special_meta solo blog corrente su MS.
- **azione:** Documentare o export per-site zip.

#### INF-040 — low — settings_invalid
- **file:** presenter `class-erankly-migration-admin-presenter.php:121` vs runner
- **perché:** `settings_invalid` sommato in problem_count ma mai incrementato (citato).
- **azione:** Incrementare o drop.

#### INF-049 — low — Yoast empty array
- **file:** `includes/migrations/class-erankly-migration-adapter-yoast.php:339-349`
- **perché:** Empty option array = source available.
- **azione:** Require non-empty titles/redirects.

#### INF-050 — low — AIOSEO match_type
- **file:** `includes/migrations/class-erankly-migration-adapter-aioseo.php:528-562`
- **perché:** Solo exact/regex; altri tipi droppati.
- **azione:** Convert come INF-023 o report unsupported.

#### INF-051 — low — header lie
- **file:** `includes/helpers/global-meta.php:2`
- **perché:** “always loaded early”; in realtà via `erankly_load_content_helpers()`.
- **azione:** Come L-11.

#### INF-052 — low — user meta flush
- **file:** `includes/helpers/sitemap-cache.php:130-138`
- **perché:** User keys omettono `_erankly_disable_sitemap`. Author hide-from-sitemap può non invalidare.
- **azione:** Aggiungere la chiave.

#### INF-053 — low — hasMore
- **file:** `easyrankly.php:924`
- **perché:** `count($sites) === LIMIT` → hasMore true anche se era l’ultima pagina esatta.
- **azione:** Fetch LIMIT+1.

#### INF-054 — nit — indent
- **file:** `includes/reset.php:57-63`
- **perché:** Docblock `do_action` indentato male.
- **azione:** Fix indent.

#### INF-055 — nit — indent
- **file:** `includes/helpers/redirect-cache.php:83-89`
- **perché:** Docblock `erankly_redirects_caches_flushed` a colonna 0 dentro la funzione.
- **azione:** Fix indent.

#### INF-057 — low — LB type regex
- **file:** `includes/helpers/sanitization-schema.php:65` vs `:129`
- **perché:** Webpage types richiedono `[A-Z]`; LB types accettano `[A-Za-z]` first letter.
- **azione:** Stessa regex.

#### INF-061 — low — presenter invalid
- **file:** `includes/migrations/class-erankly-migration-admin-presenter.php:112-131`
- **perché:** Usa `settings_invalid` (INF-040).
- **azione:** Come INF-040.

#### INF-029 — *superseded* — placeholders
- **evidenza:** usati da `admin/meta-box.php` e `admin/settings/panels.php`.

#### SEO-038 — low — hreflang first-wins
- **file:** `includes/hreflang.php`
- **perché:** Duplicate BCP47 tags: first wins, rest drop.
- **azione:** Dedup warn.

#### SEO-039 — low — static $rendered
- **file:** `includes/meta-render.php`
- **perché:** Early call swallows later head render.
- **azione:** Guard `did_action('wp_head')`.

#### SEO-040 — low — host/port
- **file:** `includes/meta-render.php` attachment host compare
- **perché:** Ignora port/www.
- **azione:** Normalizzare host.

#### SEO-041 — low — max-snippet output
- **file:** `includes/robots.php`
- **perché:** Non integer-validati (overlap SEO-035).
- **azione:** WP4/WP5.

#### SEO-042 — high-adjacent — query-wipe
- **file:** `includes/robots.php:618-645`
- **see also:** H-10
- **perché:** `erankly_force_robots_txt_request()` sostituisce **tutte** le `$wp->query_vars` con `array( 'robots' => '1' )`. Query var concorrenti (preview, custom) droppate. TST-047 locka questo wipe come contratto.
- **azione:** Merge `$wp->query_vars['robots']='1'` invece di replace. Aggiornare TST-047.

#### SEO-043 — low — news query flag morto
- **file:** `includes/sitemap/core.php` `erankly_news_sitemap_query`
- **perché:** Mai settato; branch morto in `posts_where`.
- **azione:** Rimuovere o settare.

#### SEO-044 — low — image count is posts
- **file:** `includes/sitemap/image.php`
- **perché:** Paginazione per post, non per image URL.
- **azione:** Documentare o paginare per URL.

#### SEO-045 — low — aria-current
- **file:** `includes/breadcrumbs.php`
- **perché:** Empty last name → no `aria-current`.
- **azione:** Fallback title.

#### SEO-046 — low — FAQPage+WebPage
- **file:** `includes/schema.php`
- **perché:** Sibling nodes; Google FAQ retired. Product choice.
- **azione:** Documentare.

#### SEO-047 — low — news lang 2 chars
- **file:** `includes/sitemap/news.php`
- **perché:** `zh_CN` → `zh`.
- **azione:** Mappa BCP47.

#### SEO-048 — low — image LIKE
- **file:** `includes/sitemap/image.php`
- **perché:** `LIKE % <img %` false positive poi filtrati.
- **azione:** Accettabile.

#### SEO-049 — low — image twice
- **file:** `includes/sitemap/image.php`
- **perché:** Discovery a candidate filter e di nuovo a XML render.
- **azione:** Cache per post.

#### SEO-051 — low — specialist lastmod
- **file:** `includes/class-erankly-specialist-sitemaps-provider.php`
- **perché:** Image/video index omettono lastmod (news ce l’ha).
- **azione:** Uniformare.

#### SEO-053 — medium — settings-group
- **file:** `includes/special-meta.php:27-39`
- **see also:** H-01
- **perché:** `register_setting( 'general', ERANKLY_SPECIAL_META_OPTION )`. Save Impostazioni → Generali (MS) può sanitizzare un oggetto vuoto/parziale e azzerare special meta.
- **azione:** Registrare in gruppo EasyRankly, non `general`.

#### SEO-054 — low — archive title prefix
- **file:** `includes/title-description.php`
- **perché:** `get_the_archive_title()` tiene “Category:”.
- **azione:** `single_term_title`.

#### SEO-055 — low — dual title hooks
- **file:** `includes/title-description.php`
- **perché:** `pre_get_document_title` + `document_title_parts`; parts raramente.
- **azione:** Uno solo.

#### SEO-056 — low — first JSON-LD notice
- **file:** `includes/meta.php`
- **perché:** Static `$added` tiene solo il primo notice.
- **azione:** Aggregare.

#### SEO-057 — low — twitter no _id
- **file:** `includes/opengraph.php`
- **perché:** Term/user twitter ha URL ma no `_id` (posts sì).
- **azione:** Allineare.

#### SEO-058 — low — kind=other
- **file:** `includes/seo-state.php`
- **perché:** `kind=other` permissivo.
- **azione:** Fail-closed.

#### SEO-059 — low — CDN wp_redirect
- **file:** `includes/meta-render.php:65-66`
- **perché:** CDN path usa `wp_redirect` non `wp_safe_redirect` (commento: intentional).
- **azione:** Documentare.

#### SEO-060 — low — news xml no enable recheck
- **file:** `includes/sitemap/news.php`
- **perché:** Getter XML non ri-checka enable (wrapper sì).
- **azione:** Recheck.

#### SEO-061 — low — excerpt in video sitemap
- **file:** `includes/sitemap/video.php`
- **perché:** `get_the_excerpt` in sitemap context è pesante.
- **azione:** Raw excerpt.

#### SEO-062 — nit — BCP47
- **file:** `includes/hreflang.php:61-68`
- **see also:** N-09
- **perché:** Validazione BCP47 ristretta; tag con script/region extra droppati o first-wins (SEO-038).
- **azione:** Tabella BCP47 o last-wins se URL differiscono.

#### SEO-063 — low — admin-bar order
- **file:** `includes/schema-jsonld.php`
- **perché:** Merge warnings need graph already built.
- **azione:** Build on admin_bar_init.

#### SEO-064 — low — probe numeric
- **file:** `includes/schema-jsonld.php` + H-06
- **perché:** Probe `x` reject unquoted `{{price}}`.
- **azione:** Documentare o quote.

#### SEO-065 — low — oEmbed overlap
- **file:** `includes/opengraph.php:286-301`
- **see also:** SEO-028
- **perché:** Stesso path oEmbed-on-404 già in SEO-028. Tenuto per ricerca ID; non è un difetto extra.
- **azione:** Non paddare; lavorare SEO-028.

#### SEO-066 — low — og-locale
- **file:** `includes/opengraph.php:57`
- **perché:** `og:locale` = `str_replace('-','_', get_bloginfo('language'))`. `zh-Hans-CN` → `zh_Hans_CN`, non Facebook `language_TERRITORY`. Nessun `og:locale:alternate` da hreflang.
- **azione:** Mappa `get_locale()` + BCP47→OG; alternate dal provider.

#### SEO-067 — low — sanitize-key CPT
- **file:** `includes/seo-state.php:118-120`
- **perché:** `sanitize_key` lowercased. CPT slug mixed-case manca `post_type_exists` / mappe meta.
- **azione:** Sanitizzare solo in lookup settings; documentare che subtype è key.

#### SEO-068 — medium — twitter card default
- **file:** `includes/opengraph.php` (helper card; test lock `summary_large_image`)
- **perché:** Default hard-coded `summary_large_image` poi degrade a summary senza immagine. Un default globale stored non si applica se manca meta per-post.
- **azione:** Leggere setting globale prima del default.

#### SEO-069 — medium — query_vars leak
- **file:** `includes/robots.php:648+` (`erankly_register_rewrites`)
- **perché:** Ogni call appende un filtro anonimo `query_vars`. Duplicate `erankly_sitemap` e closure extra per request.
- **azione:** Registrare una volta; `remove_filter` prima di re-add.

#### SEO-070 — low — unused-param
- **file:** `includes/schema-jsonld.php:704-734`
- **see also:** L-12
- **perché:** `erankly_get_local_business_page_id( $post_id = 0 )` non usa `$post_id` (unset). Caller non può chiedere “questa pagina è la LB?”.
- **azione:** Rimuovere il param o implementare.

#### SEO-071 — low — double-kses
- **file:** `includes/breadcrumbs.php` (HTML trail)
- **perché:** `wp_kses` più di una volta (builder + shortcode/block). Costo; secondo pass può divergere se i tag allowed differiscono.
- **azione:** Kses una volta al boundary pubblico.

#### SEO-072 — low — user exclusion dual path
- **file:** `includes/sitemap/core.php` (user exclusion)
- **perché:** `meta_query` users e helper SQL author stats sono due path. `disable_sitemap` default true non sempre usato allo stesso modo.
- **azione:** Un builder exclusion per users.

#### SEO-073 — medium — off-site news loc
- **file:** `includes/sitemap/news.php`
- **perché:** Filtro può fornire `loc` http assoluto off-site; ammesso. News sitemap pubblica un altro host.
- **azione:** Same-origin (+ path) come site provider.

#### SEO-074 — low — utf8-split video
- **file:** `includes/sitemap/video.php`
- **perché:** Title/description `mb_substr` se disponibile altrimenti `substr` (split codepoint senza mbstring).
- **azione:** Sempre `erankly_trim_text`.

#### SEO-075 — medium — url_to_postid cost
- **file:** `includes/class-erankly-site-sitemaps-provider.php:77-79`
- **perché:** `url_to_postid( $archive_url )` per ogni CPT archive pubblico (dedupe Woo shop-as-page). Costoso e permalink-flaky.
- **azione:** Confrontare shop page ID option se WC attivo.

#### INF-062 — medium — migrate flag anyway
- **file:** `includes/helpers/settings.php:267-316,319-381`
- **perché:** Title/schema migration `erankly_update_plugin_settings( $settings, '', true )` su snapshot pre-lock, ignora WP_Error/false, poi setta `erankly_migrated_*_v1`. Lock contendente → write fail + nessun retry. (INF-A usava INF-051; qui nuovo ID per non collisionare con header-lie.)
- **azione:** Flag solo se `true === $result`. Niente replace snapshot pre-lock.

#### INF-063 — medium — network deactivate swallow
- **file:** `easyrankly.php:762-776`
- **perché:** Deactivation rete catch `Throwable` per-sito, logga solo `WP_DEBUG`, continua. Plugin risulta disattivato con cron/rewrite ancora attivi su un sito. Uninstall invece abortisce. (INF-A usava INF-052; collision con user-meta flush.)
- **azione:** Contare fail; se >0 `wp_die` 409 o non considerare riuscita. `error_log` incondizionato.

#### INF-064 — low — switch_to_blog no finally
- **file:** `includes/helpers/sanitization-schema.php:290-304`
- **perché:** `switch_to_blog` senza try/finally. `erankly_find_published_page_id` già usa finally. Throw in `get_post`/filtro lascia la request sull’altro blog (autosave General ri-sanitizza tutta la mappa LB). (INF-A usava INF-053; collision con hasMore.)
- **azione:** Stesso try/finally.

#### INF-080 — medium — race / job integrity
- **file:** `includes/network-reset.php:225-258`
- **perché:** `erankly_queue_network_reset()` scrive sempre nuovo token e `last_processed_id=0`. Nessun guard “already running”. Secondo click sostituisce job in-flight; worker vecchio può essere in `switch_to_blog` + reset mentre il nuovo resetta gli stessi siti.
- **azione:** Rifiutare queue se pending/running/retrying; CAS-insert solo se assente.

#### INF-081 — medium — authz
- **file:** `includes/class-erankly-import-job-runner.php:260-264`
- **perché:** `current_user_may_import_custom_code()` true se `get_current_user_id()===0`. Commento parla del cron worker ma la funzione è solo da `start()`/`start_from_file()`. WP-CLI / caller user-0 può stage HTML raw.
- **azione:** Richiedere `unfiltered_html` per user reale; reject user 0 allo start.

#### INF-082 — medium — fingerprint completeness
- **file:** `includes/migrations/class-erankly-migration-adapter.php:892-927`
- **perché:** Fingerprint meta/table = `COUNT(*)`+`MAX(id)`. UPDATE in-place Yoast/AIOSEO/RM non cambia fingerprint; INF-005 non vede content edit. `fingerprint_columns` unused.
- **azione:** Checksum colonne consumate, o MAX(meta_id)+hash valori.

#### INF-083 — high — restore merge vs snapshot
- **file:** `includes/class-erankly-import-job-runner.php:568-584,708-714`
- **perché:** `purge_owned_data()` non cancella `ERANKLY_OPTION`. `apply_settings` scrive con `$replace=false` (`array_replace` live←backup). Chiavi live assenti dal backup sopravvivono. Contradice il commento spool “restore = snapshot”.
- **azione:** Purge o `$replace=true` sui restore `purge_owned`.

#### INF-084 — low — backup before lock
- **file:** `includes/migrations/class-erankly-migration-job-runner.php:94-124`
- **perché:** Backup pre-import scritto **prima** di `erankly_acquire_data_transfer_start_lock()`. Due click concorrenti streamano entrambi un export; il perdente scarta il file. Durante backup unlocked un import nativo può già girare.
- **azione:** Acquire lock prima di `erankly_migration_create_backup()`.

#### TST-041 — medium — missing-risky-branch
- **file:** `tests/test-lifecycle-rest.php:41-67`
- **perché:** Salva solo `title`/`noindex`. Wipe `index_directive` (SEO-004) resterebbe verde.
- **azione:** Round-trip mappa con `index_directive`/`max_snippet`.

#### TST-042 — medium — missing-risky-branch
- **file:** `tests/test-seo-state.php:1-84`
- **perché:** Solo `posts_page`. `kind=home` vs static front, `kind=other`, canonical vs localize untested.
- **azione:** Casi home/static-front/`other`; confrontare con `erankly_get_canonical()`.

#### TST-043 — medium — missing-risky-branch
- **file:** `tests/test-helpers-video.php:27-38` vs `includes/helpers/video.php:17-19`
- **perché:** Shorts URL non nell’extractor (L-13); test non lo asserisce.
- **azione:** Assert shorts o documentare unsupported.

#### TST-044 — medium — missing-risky-branch
- **file:** `tests/test-woocommerce.php:179-204` vs `includes/compatibility-woocommerce.php:134-154`
- **perché:** Review fixture ha sempre `rating`. Production emette Review senza rating (M-08).
- **azione:** Review approvata senza rating meta.

#### TST-045 — medium — missing-risky-branch
- **file:** `tests/test-admin-meta-box.php:272-318`
- **perché:** Save solo title. ADM-002 (news exclude deleted) unasserted.
- **azione:** Store exclude, save con news off, assert meta survives.

#### TST-046 — low — missing-risky-branch
- **file:** `tests/test-lifecycle-cron.php:56-139`
- **perché:** Solo reject/pause/fail. Nessun batch settings/meta success (INF-001 untested).
- **azione:** Spool tiny + un `process()` success.

#### TST-047 — medium — locks-production-defect
- **file:** `tests/test-robots-and-custom-code.php:402-419` vs `includes/robots.php:618-645`
- **perché:** Assert `$wp->query_vars === array('robots'=>'1')` locka il wipe H-10/SEO-042. Un fix merge-only fallirebbe questo test.
- **azione:** Assert `robots` set **e** query vars preesistenti sopravvivono (quando si fissa produzione).

#### FE-008 — low — copy schema warning
- **file:** `assets/js/editor.js:330` vs `admin/meta-box.php:381`
- **perché:** Due msgid stesso UX.
- **azione:** Unificare.

#### FE-010 — low — POT stale
- **file:** `languages/easyrankly.pot` refs breadcrumbs.php:122 vs source :130
- **perché:** Line refs stale.
- **azione:** Regenerare POT.

#### FE-011 — medium-adjacent — TEMP CSS
- **file:** `assets/css/admin-core.css:758-761`
- **perché:** Nasconde nav documentata. Italiano TEMP in prod.
- **azione:** Rimuovere regola o sezione PHP.

#### FE-012 — low — unused CSS
- **file:** `assets/css/admin-core.css:499-501`
- **perché:** `.erankly-user-search-wrap` mai applicata.
- **azione:** Drop o add class.

#### FE-013 — low — unused i18n JS
- **file:** `admin/assets/settings.php:75-76,223`
- **perché:** `remove`/`noSelected`/`emptyTable`/`loadMore` localizzati, non letti.
- **azione:** Usare o stop.

#### FE-014 — low — unused JS exports
- **file:** admin-schema.js:435-438, admin-variables.js:435-441, admin-settings.js:670-672, admin-tabs.js:600-604, editor-shared.js:1086-1096
- **perché:** Assegnati a window, zero caller produzione.
- **azione:** Stop export o documentare API.

#### FE-015 — low — class no CSS
- **file:** block index.js:52,60; editor-shared.js:461,548; admin-widgets.js:84,100
- **perché:** Class names senza regole.
- **azione:** Style o drop.

#### FE-018 — low — docs stale
- **file:** `WORDPRESS-ORG-READINESS.md`
- **perché:** Lock instantiator 2.1.0 vs disk 1.5.0.
- **azione:** Refresh doc.

#### FE-019 — low — PCP ignore-warnings
- **file:** `.github/workflows/phpunit.yml:136`
- **perché:** Swallow warnings.
- **azione:** Fail on new warnings.

#### FE-020 — low — block.json inserter
- **file:** `blocks/breadcrumbs/block.json:14`
- **perché:** Manifest `inserter:false`; PHP/JS override.
- **azione:** Commento.

#### FE-024 — nit — license OK
- **file:** `license.txt`
- **perché:** Necessario. Nessuna azione.

#### FE-025 — nit — readme vs header
- **file:** `readme.txt:11` vs `easyrankly.php:5`
- **perché:** Due description.
- **azione:** Allineare se Directory deve matchare.

#### TST-033 — low — docs-not-tests
- **file:** `tests/schema-recheck.md:1-66`
- **perché:** Manual log. Non evidenza produzione.
- **azione:** Ignore as CI.

#### TST-034 — low — skip-noise
- **file:** molti test (Node, MS, SQLite, headers_sent)
- **perché:** Default job nasconde superfici.
- **azione:** Track skip rates.

#### TST-035 — low — block comment form
- **file:** `tests/test-breadcrumbs.php` vs native
- **perché:** `wp:core/breadcrumbs` vs `wp:breadcrumbs`. Parser accetta entrambi.
- **azione:** Un form.

#### TST-040 — low — bootstrap OK
- **file:** `tests/bootstrap.php:70-87`
- **perché:** Non un defect. Helper MS corretto.

### Nit — pass 1 (espansi)

#### N-01 — nit — bloat
- **file:** `includes/breadcrumbs.php:70-80`
- **perché:** Doppio `wp_kses`.
- **azione:** Uno.

#### N-02 — nit
- **file:** `blocks/breadcrumbs/index.js:8`
- **perché:** `typeof getBlockType === 'function'` ridondante.
- **azione:** Drop.

#### N-03 — nit
- **file:** `includes/helpers/core.php:24-31`
- **perché:** `erankly_array_is_list` necessario per PHP 8.0.
- **azione:** Tenere.

#### N-04 — nit
- **file:** `admin/settings/nav-icons.php:20`
- **perché:** Hugeicons special-pages = general.
- **azione:** Icona distinta o accettare.

#### N-05 — nit
- **perché:** Commenti lunghi per lo più utili. Non spazzare. Eccezione L-11.
- **azione:** Nessuna.

#### N-06 — nit
- **file:** accordion/editor/block IIFE
- **see also:** FE-001
- **perché:** Throw se `wp` undefined; mitigato da deps.
- **azione:** WP10.

#### N-07 — nit
- **file:** `assets/js/admin-import-export.js:14-16`
- **perché:** Reload cieco 15s.
- **azione:** Check job completed.

#### N-08 — nit
- **file:** `blocks/breadcrumbs/block.json`
- **see also:** FE-020

#### N-09 — nit
- **file:** `includes/hreflang.php:61-68`
- **perché:** BCP47 ridotto. Tag rari droppati.
- **azione:** Documentare subset.

#### N-10 — nit
- **file:** `includes/schema.php:634-637`
- **perché:** ProfilePage `mainEntity` = identità sito, non autore post.
- **azione:** Non offrire ProfilePage su articoli.

#### N-11 — nit
- **file:** `.commandcode/taste/taste.md`
- **perché:** Non plugin. Distignored.
- **azione:** Nessuna.

#### N-12 — nit
- **file:** `tests/test-js-contracts.php`
- **see also:** M-18
- **perché:** Duplica stringhe source.
- **azione:** WP11/13.

#### FE-TEMP — nit — TEMP CSS italiano
- **file:** `assets/css/admin-core.css:758-761`
- **see also:** FE-011
- **perché:** Commento TEMP in italiano nasconde nav documentata. Regola di prodotto non committata.
- **azione:** Rimuovere la regola o commento EN accanto alla decisione.

## 4. Piano d’azione (prompt-ready)

Eseguire in ordine. Non implementare in questo audit. WP0 del pass 1 è **chiuso**.

### WP1 — Perdita dati restore/reset (INF-001, INF-002, INF-083)
- **Cosa:** Restore: non unlink spool dopo purge; job resumable o shadow; `$replace=true` o purge `ERANKLY_OPTION` (INF-083). Reset MS: non DELETE usermeta globale per-sito.
- **File:** `class-erankly-import-job-runner.php`, `reset.php`, `network-reset.php`
- **Accettazione:** Restore fallito dopo batch 1 lascia spool + job paused; reset sito A non cancella `_erankly_*` usermeta visibili dal sito B; restore snapshot non lascia chiavi live assenti dal backup.
- **Test:** nuovo in `test-import-export.php` / `test-reset-actions.php` / `test-network-reset.php` (MS).

### WP2 — Homepage statica = special page homepage (SEO-001/002/003)
- **Cosa:** `is_front_page()` (anche Page) prima di `is_singular()` in title, robots, OG; allineare `erankly_current_special_page_key()`.
- **File:** `includes/title-description.php`, `includes/robots.php`, `includes/opengraph.php`, **`includes/helpers/content-defaults.php`** (`erankly_current_special_page_key` :168-169)
- **Accettazione:** Page on front usa title/noindex/social di Special pages → Homepage. Sitemap hide e live robots coincidono. `erankly_current_special_page_key()` ritorna `homepage` anche se `is_singular() && is_front_page()`.
- **Test:** `test-title-description.php`, `test-robots-output.php`, `test-opengraph-output.php`, `test-helpers-content-defaults.php` con `page_on_front`.

### WP3 — Special meta REST + Settings > General (H-01, SEO-004)
- **Cosa:** Non registrare nel gruppo `general` di options.php. REST schema include robots avanzati **oppure** merge-on-update. Site Editor non deve droppare hidden fields.
- **File:** `includes/special-meta.php`; eventuale `site-editor.js`
- **Accettazione:** Save Impostazioni > Generali MS non tocca special meta. Save Site Editor preserva `index_directive`.
- **Test:** `test-lifecycle-rest.php`, `test-local-business-rest.php` pattern, nuovo REST round-trip robots.

### WP4 — Canonical unico + no ricorsione (SEO-007, SEO-009, H-06)
- **Cosa:** Un builder per head e seo-state (localize + pagination). Exclude `canonical_url` su author. Rivalidare JSON-LD dopo replace.
- **File:** `includes/canonical.php`, `includes/seo-state.php`, `includes/schema-jsonld.php`, `includes/helpers/template-variables.php`
- **Accettazione:** Author template `{{canonical_url}}` non ricorre. `erankly_get_object_seo_state()` canonical === `erankly_get_canonical()` (localize + paged). Nodo JSON-LD con `"` nel title non viene droppato.
- **Test:** `test-canonical.php` author `{{canonical_url}}`; `test-seo-state.php` vs head; JSON-LD titolo con `"`.

### WP5 — Sitemap correttezza (SEO-005, SEO-008, SEO-015–018, SEO-030)
- **Cosa:** Origin con path home; VideoObject markers = SQL; usare `loc` filtrato; empty video → 404 come image; GMT helper; chunk NOT IN; non cachare news name vuoto.
- **File:** `includes/class-erankly-site-sitemaps-provider.php`, `includes/schema-content.php`, `includes/sitemap/core.php`, `image.php`, `news.php`, `video.php`
- **Accettazione:** Install `/shop/` rifiuta URL `https://host/other/`. VideoObject nocookie compare in schema. Image XML usa `loc` del filtro. Video urlset vuoto = 404. News name vuoto non cache 1h.
- **Test:** `test-sitemap.php`, `test-sitemap-specialists.php`, `test-schema-content.php`

### WP6 — Write-on-GET e Person morto (SEO-006, H-03)
- **Cosa:** Migrazione social solo batch `init`/admin-post, non `wp_head` né meta-box render. Identity richiede `WP_User` vivo.
- **File:** `includes/meta.php`, `includes/opengraph.php`, `admin/meta-box.php`, `includes/schema.php`
- **Accettazione:** `erankly_get_og_image()` non scrive meta. User ID salvato ma cancellato → nessun Person finto.
- **Test:** `test-opengraph-migrations.php`; `test-schema.php` user deleted.

### WP7 — Import/migrazione (INF-003–006, INF-004, INF-005, INF-080–084, INF-062)
- **Cosa:** Lock/job su sitemeta in MS; fingerprint prima di ogni batch (e checksum colonne, INF-082); settings `$replace=true` su restore (INF-083); AIOSEO token allowlist; non queue reset se già running (INF-080); lock prima del backup (INF-084); reject user 0 custom-code start (INF-081); migrate flag solo dopo write (INF-062).
- **File:** `includes/migrations.php`, `includes/migrations/class-erankly-migration-job-runner.php`, `includes/class-erankly-import-job-runner.php`, `includes/migrations/runtime-variables.php`, `includes/network-reset.php`, `includes/helpers/settings.php`
- **Accettazione:** Due siti MS non partono insieme contro lo stesso snapshot. Source change mid-run pausa **prima** delle write del batch. `#fff` in URL AIOSEO non diventa `{{…}}`. Import settings usa mutex + replace. Secondo click network-reset non sostituisce job in-flight.
- **Test:** `test-migrations-runner.php`, fixture tabelle RM/AIOSEO (`TST-009`), `test-network-reset.php`

### WP8 — Redirects (H-07, H-08, INF-008, INF-016, INF-017, INF-025)
- **Cosa:** Skip xmlrpc; loop anche pattern; cap/MS URLs; page backfill activator.
- **File:** `includes/redirects/class-erankly-redirects-runner.php`, `class-erankly-redirects-rest.php`, `class-erankly-redirects-admin.php`, `class-erankly-redirects-activator.php`, `class-erankly-redirects-repository.php`
- **Accettazione:** XML-RPC non redirecta. Loop A→B→A via wildcard rilevato. REST/admin su MS usano cap/URL network se la UI è network. Activator non `SELECT *` unbounded.
- **Test:** `test-redirects-runtime.php` 301 Location (`TST-004`), xmlrpc, wildcard loop

### WP9 — Lock visibile + autoload (H-02, INF-010, INF-006 persist)
- **Cosa:** `pre_update` errore visibile; custom code non autoload se enorme; network persist usa mutex.
- **File:** `includes/settings-lock.php`, `easyrankly.php`, `admin/settings-page.php`
- **Accettazione:** Save concorrente mostra errore (non no-op silenzioso). Option settings con custom code >N KB non autoload, oppure custom code fuori option. Network persist chiama `erankly_update_plugin_settings`.
- **Test:** `tests/test-lifecycle.php` lock; nuovo test persist network.

### WP10 — Admin UI + Gutenberg (ADM-001, ADM-002, ADM-003, ADM-004, ADM-030, FE-001–007, FE-011)
- **Cosa:** Primary terms in block editor **o** rimuovere il controllo classic. Non cancellare `_erankly_exclude_from_news` se il checkbox non è renderizzato. Gate Site Editor special pages sulle cap (`edit_theme_options`); fallback settings se mancano. Enqueue solo `post`/`post-new`; label Special pages; guard `wp`; listbox id unici; 960px; TEMP CSS.
- **File:** `admin/meta-box.php`, `post-saver.php`, `includes/admin.php`, `admin/assets/editor.php`, `admin/assets/settings.php`, `page-renderer.php`, `panels.php`, JS elencati, `admin-core.css`
- **Accettazione:** Gutenberg può settare/leggere `_erankly_primary_terms`. Save classic con news sitemap off **non** delete l’esclusione. `manage_options` senza `edit_theme_options` vede ancora Special pages. List table `edit.php` non enqueue classic.
- **Test:** `test-admin-meta-box.php` (news exclude + primary terms REST); `test-admin-menu.php` (list table); Site Editor cap in `test-lifecycle-rest.php` / menu.

### WP11 — CI (H-09, M-18, TST-001/002, FE-019)
- **Cosa:** Job WP 6.5 + env deregister core breadcrumbs; Node obbligatorio per `.cjs` (fail se skip); non stampare ok su skip; opzionale PCP warnings.
- **File:** `.github/workflows/phpunit.yml`, probes, `phpunit.xml.dist`
- **Accettazione:** CI 6.5 e 7.1; probe fail se `blocks.js` assente; PHPUnit non skippa i `.cjs` se Node manca in CI.
- **Test:** workflow matrix; `node tests/js/*.cjs`.

### WP12 — Dead code / commenti (L-01–L-05, FE-012–014, SEO-043, INF-040, L-11, INF-051)
- **Cosa:** Rimuovere wrapper morti e i18n JS non letti; flag news query mai settato; `settings_invalid`; correggere header file bugiardi.
- **File:** `includes/schema.php`, `includes/import-export/report.php`, `includes/helpers/video.php`, `includes/helpers/sanitization.php`, `includes/custom-code.php`, `includes/sitemap/core.php`, JS/CSS FE-012–014, `template-variables.php`, `global-meta.php`
- **Accettazione:** `rg` zero usi produzione dei wrapper L-01–L-05; `erankly_news_sitemap_query` assente o settato; header file non mentono sul load order; PHPUnit verde.

### WP13 — Suite test (TST-003–011, 025, 028, 041–047)
- **Cosa:** Header feed, 301 redirect, attachment redirect, JSON-LD escape, inherit+legacy noindex, fixture tabelle migrazione, live-audit equivalenti in PHPUnit; round-trip REST robots (TST-041); static-front seo-state (TST-042); shorts (TST-043); review senza rating (TST-044); news exclude save (TST-045); cron success batch (TST-046); non lockare wipe robots.txt (TST-047).
- **File:** `tests/test-response-handlers.php`, `test-redirects-runtime.php`, `test-meta-helpers.php`, `test-helpers-template-variables.php`, `test-helpers-global-meta.php`, `test-migrations-adapters.php`, `test-lifecycle-rest.php`, `test-seo-state.php`, `test-helpers-video.php`, `test-woocommerce.php`, `test-admin-meta-box.php`, `test-lifecycle-cron.php`, `test-robots-and-custom-code.php`, `phpunit.xml.dist`
- **Accettazione:** Ogni ramo elencato ha un assert che può fallire. `live-schema-audit.php` **non** è in PHPUnit.
- **Test da lanciare:** `vendor/bin/phpunit` + i 3 probe Node. Non usare `live-schema-audit.php` come CI.

## 5. Linee/file ancora da coprire

**Nessun range first-party PHP / JS / CSS / `tests/test-*.php` rimasto.** I 54 file INF del verdict e i 50 `test-*.php` sono in §1 `coperto` con purpose + range 1–EOF + issues o «reviewed, no issues». `easyrankly.php` 794–1130 è stato riletto (pass 3 + INF-A).

Caveat (non sono gap A–E; non sbloccare WP):

- `composer.lock`: grafo `packages: []` + instantiator 1.5.0 verificati; **hash SHA** delle dist non auditati.
- CSS: letto staticamente (unused selector, TEMP, `color-mix`); **viewport browser non esercitato**.
- `tests/live-schema-audit.php` e `tests/js/*.cjs`: coperti in pass 2 via TST-001/002/006/010/013/032; expansion non li ha rilette byte-per-byte di nuovo.

---

*Fine report pass 3. Agente successivo: eseguire WP1 → WP13. Non riaprire un pass di audit sulla copertura first-party salvo regressioni. Non implementare WP in un pass di audit.*

