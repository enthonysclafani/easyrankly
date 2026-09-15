# Checklist audit 15-09-2026

Fonte: [easyrankly-line-audit-report.md](easyrankly-line-audit-report.md) §2–§4 (285 heading `####`). Dettaglio (file, perché, azione, test) resta nel report: non rinumerare gli ID.

## Come usarla

- `[ ]` da fare · `[x]` fatto (o già chiuso / no-op)
- Spunta l’ID quando il fix (o lo skip deliberato) è fatto. Poi spunta il gate **WPn chiuso** solo se accettazione + test del report passano.
- Alias e pointer sono già spuntati: non sono lavoro extra.
- Lingua: italiano. Codice e ID: originali.

## Progresso (contatori)

Aggiorna i numeri quando spunti. I totali “da fare” escludono superseded, alias e no-op.

| Bucket | Totale heading | Già chiusi / no-op | Da fare | Fatti |
| --- | ---: | ---: | ---: | ---: |
| Blocker | 2 live + 1 superseded (B-01) | 1 | 0 | 2 |
| High | 42 | 2 alias (INF-009, SEO-010) | 31 | 9 |
| Medium | 122 (parola *medium* in heading; il §2 del report conta 106 se 16 heading medium nella sezione Low si contano come low) | 4 (SEO-036/037/053 + INF-048) | 118 | 0 |
| Low | 93 (parola *low*; §2 ne conta 109 con lo stesso shift) | 9 (7 alias + L-08, TST-040) | 84 | 0 |
| Nit | 22 | 8 (4 alias + 4 tenere) | 14 | 0 |
| Adjacent | 2 (SEO-042, FE-011) | 1 alias; FE-011 è in WP10 | 1 in WP10 | 0 |
| **Heading** | **285** | **26** | **248** | **11** |

| Work package | ID da fare | Fatti | Gate |
| --- | ---: | ---: | --- |
| WP1 Perdita dati restore/reset | 3 | 3 | `[x]` ✅ |
| WP2 Homepage statica | 3 | 3 | `[x]` ✅ |
| WP3 Special meta REST + General | 2 | 2 | `[x]` ✅ |
| WP4 Canonical + ricorsione | 3 | 3 | `[x]` ✅ |
| WP5 Sitemap | 7 | 0 | `[ ]` |
| WP6 Write-on-GET / Person | 2 | 0 | `[ ]` |
| WP7 Import/migrazione | 9 | 0 | `[ ]` |
| WP8 Redirects | 5 | 0 | `[ ]` |
| WP9 Lock + autoload | 2 | 0 | `[ ]` |
| WP10 Admin UI + Gutenberg | 13 | 0 | `[ ]` |
| WP11 CI | 5 | 0 | `[ ]` |
| WP12 Dead code / commenti | 11 | 0 | `[ ]` |
| WP13 Suite test | 18 | 0 | `[ ]` |
| **WP1–WP13** | **83** | **8** | |
| Backlog (non in WP) | 176 | 0 | |

251 da fare = 75 WP + 176 backlog.

## Già chiusi / no-op

Pre-spuntati. Non riaprire salvo regressione.

### Superseded

- [x] **B-01** superseded — `composer.lock` PHP 8.4 (instantiator 1.5.0; aggiornare solo `WORDPRESS-ORG-READINESS.md` → FE-018 in backlog)
- [x] **INF-029** superseded — placeholders (usati in admin)

### Alias / pointer (stesso difetto del canonico)

- [x] **INF-009** high — see H-02
- [x] **SEO-010** high — see H-03
- [x] **SEO-036** medium — see M-05
- [x] **SEO-037** medium — see M-04
- [x] **SEO-041** low — see SEO-035
- [x] **SEO-042** high-adjacent — see H-10 (fix insieme a TST-047)
- [x] **SEO-053** medium — see H-01
- [x] **SEO-065** low — see SEO-028
- [x] **INF-031** low — see L-13 + SEO-008 (un haystack)
- [x] **INF-034** low — see L-11
- [x] **INF-051** low — see L-11
- [x] **INF-061** low — see INF-040
- [x] **L-15** low — see TST-001
- [x] **N-06** nit — see FE-001
- [x] **N-08** nit — see FE-020
- [x] **N-12** nit — see M-18
- [x] **FE-TEMP** nit — see FE-011

### Nessuna azione / tenere

- [x] **FE-024** nit — license.txt OK
- [x] **N-03** nit — `erankly_array_is_list` necessario PHP 8.0
- [x] **N-05** nit — commenti: nessuna spazzata
- [x] **N-11** nit — `.commandcode/taste/taste.md` non plugin
- [x] **TST-040** low — bootstrap OK, non un defect
- [x] **L-08** low — `easyrankly_breadcrumbs` BC, tenere
- [x] **INF-048** medium — targeting archive vuoto fail-closed (già settings error)

---

## WP1 — Perdita dati restore/reset

File: `class-erankly-import-job-runner.php`, `reset.php`, `network-reset.php`. Test: `test-import-export.php`, `test-reset-actions.php`, `test-network-reset.php` (MS).

- [x] **INF-001** blocker — restore/import: purge poi fail
- [x] **INF-002** blocker — Multisite: reset sito cancella usermeta di rete
- [x] **INF-083** high — restore merge vs snapshot (`$replace=false`; `ERANKLY_OPTION` non purged)

- [x] **WP1 chiuso** ✅ — Restore fallito dopo batch 1 lascia spool + job paused; reset sito A non cancella `_erankly_*` usermeta visibili dal sito B; restore snapshot non lascia chiavi live assenti dal backup.

## WP2 — Homepage statica = special page homepage

File: `title-description.php`, `robots.php`, `opengraph.php`, `helpers/content-defaults.php`. Test: `test-title-description.php`, `test-robots-output.php`, `test-opengraph-output.php`, `test-helpers-content-defaults.php` con `page_on_front`.

- [x] **SEO-001** high — title special-page homepage ignorato su static front
- [x] **SEO-002** high — robots homepage ignorati su static front
- [x] **SEO-003** high — OG/social homepage ignorati su static front

- [x] **WP2 chiuso** ✅ — Page on front usa title/noindex/social di Special pages → Homepage. Sitemap hide e live robots coincidono. `erankly_current_special_page_key()` ritorna `homepage` anche se `is_singular() && is_front_page()`.

## WP3 — Special meta REST + Settings > General

File: `includes/special-meta.php`; eventuale `site-editor.js`. Test: `test-lifecycle-rest.php`, round-trip robots.

- [x] **H-01** high — `register_setting( 'general' )` può azzerare special meta su MS
- [x] **SEO-004** high — REST special meta droppa robots avanzati

- [x] **WP3 chiuso** ✅ — Save Impostazioni > Generali MS non tocca special meta. Save Site Editor preserva `index_directive`.

## WP4 — Canonical unico + no ricorsione

File: `canonical.php`, `seo-state.php`, `schema-jsonld.php`, `helpers/template-variables.php`. Test: author `{{canonical_url}}`; seo-state vs head; JSON-LD titolo con `"`.

- [x] **SEO-007** high — `{{canonical_url}}` su author → ricorsione
- [x] **SEO-009** high — canonical head ≠ seo-state
- [x] **H-06** high — JSON-LD probe `x` vs replace a runtime

- [x] **WP4 chiuso** ✅ — Author template `{{canonical_url}}` non ricorre. `erankly_get_object_seo_state()` canonical === `erankly_get_canonical()` (localize + paged). Nodo JSON-LD con `"` nel title non viene droppato.

## WP5 — Sitemap correttezza

File: `class-erankly-site-sitemaps-provider.php`, `schema-content.php`, `sitemap/core.php`, `image.php`, `news.php`, `video.php`. Test: `test-sitemap.php`, `test-sitemap-specialists.php`, `test-schema-content.php`.

- [ ] **SEO-005** high — sitemap site provider: origin senza path
- [ ] **SEO-008** high — VideoObject vs sitemap nocookie
- [ ] **SEO-015** medium — sitemap `loc` filtrato ignorato (image)
- [ ] **SEO-016** medium — video urlset vuoto → 200 (image empty → 404)
- [ ] **SEO-017** medium — lastmod `strtotime` senza UTC
- [ ] **SEO-018** medium — canonical non-self `posts_per_page=-1` (stampede sitemap)
- [ ] **SEO-030** medium — news name vuoto cache 1h

- [ ] **WP5 chiuso** — Install `/shop/` rifiuta URL `https://host/other/`. VideoObject nocookie compare in schema. Image XML usa `loc` del filtro. Video urlset vuoto = 404. News name vuoto non cache 1h.

## WP6 — Write-on-GET e Person morto

File: `meta.php`, `opengraph.php`, `admin/meta-box.php`, `schema.php`. Test: `test-opengraph-migrations.php`; `test-schema.php` user deleted.

- [ ] **SEO-006** high — migrazione social image in GET
- [ ] **H-03** high — Person schema se utente cancellato

- [ ] **WP6 chiuso** — `erankly_get_og_image()` non scrive meta. User ID salvato ma cancellato → nessun Person finto.

## WP7 — Import/migrazione

File: `migrations.php`, `class-erankly-migration-job-runner.php`, `class-erankly-import-job-runner.php`, `runtime-variables.php`, `network-reset.php`, `helpers/settings.php`. Test: `test-migrations-runner.php`, fixture tabelle (`TST-009`), `test-network-reset.php`. INF-083 è in WP1.

- [ ] **INF-003** high — lock transfer per-blog vs settings sitemeta
- [ ] **INF-004** high — AIOSEO `#token` riscrive fragment
- [ ] **INF-005** high — fingerprint sorgente dopo le write
- [ ] **INF-006** high — import settings merge senza `$replace=true`
- [ ] **INF-062** medium — migrate flag anyway (flag dopo write ignorata)
- [ ] **INF-080** medium — network-reset sempre nuovo token (niente already-running)
- [ ] **INF-081** medium — user 0 può importare custom code
- [ ] **INF-082** medium — fingerprint COUNT+MAX non vede UPDATE
- [ ] **INF-084** low — backup pre-import prima del lock

- [ ] **WP7 chiuso** — Due siti MS non partono insieme contro lo stesso snapshot. Source change mid-run pausa prima delle write del batch. `#fff` in URL AIOSEO non diventa `{{…}}`. Import settings usa mutex + replace. Secondo click network-reset non sostituisce job in-flight.

## WP8 — Redirects

File: `class-erankly-redirects-runner.php`, `-rest.php`, `-admin.php`, `-activator.php`, `-repository.php`. Test: `test-redirects-runtime.php` 301 Location (`TST-004`), xmlrpc, wildcard loop. **INF-016** non ha heading `####` (see H-07).

- [ ] **H-07** high — Loop redirect solo exact
- [ ] **H-08** high — XML-RPC non skippato dal runner
- [ ] **INF-008** high — Redirects REST/admin MS (`manage_options` + `admin_url`)
- [ ] **INF-017** medium — regex redirects tutti nel bucket globale frontend
- [ ] **INF-025** medium — activator `SELECT *` unbounded (page backfill)

- [ ] **WP8 chiuso** — XML-RPC non redirecta. Loop A→B→A via wildcard rilevato. REST/admin su MS usano cap/URL network se la UI è network. Activator non `SELECT *` unbounded.

## WP9 — Lock visibile + autoload

File: `settings-lock.php`, `easyrankly.php`, `admin/settings-page.php`. Test: `test-lifecycle.php` lock; persist network. Persist `$replace` di INF-006 è in WP7.

- [ ] **H-02** high — Interlock settings: save silenzioso no-op
- [ ] **INF-010** medium — settings sempre autoload (custom code enorme)

- [ ] **WP9 chiuso** — Save concorrente mostra errore (non no-op silenzioso). Option settings con custom code >N KB non autoload, oppure custom code fuori option. Network persist chiama `erankly_update_plugin_settings`.

## WP10 — Admin UI + Gutenberg

File: `admin/meta-box.php`, `post-saver.php`, `includes/admin.php`, `admin/assets/editor.php`, `settings.php`, `page-renderer.php`, `panels.php`, JS elencati, `admin-core.css`. Test: `test-admin-meta-box.php`, `test-admin-menu.php`.

- [ ] **ADM-001** high — Gutenberg senza primary terms
- [ ] **ADM-002** high — save classic cancella `_erankly_exclude_from_news`
- [ ] **ADM-003** high — special pages invisibili senza `edit_theme_options`
- [ ] **ADM-004** medium — enqueue classic su `edit.php` list table
- [ ] **ADM-030** medium — tab Special pages etichettata «General» su MS site
- [ ] **FE-001** medium — throw se `wp` undefined (editor/accordion/block)
- [ ] **FE-002** medium — site-editor: accesso `wp` prima del bail
- [ ] **FE-003** medium — editor.js senza bail shared/config
- [ ] **FE-004** medium — reset modal null deref
- [ ] **FE-005** medium — duplicate listbox ids
- [ ] **FE-006** medium — breakpoint 960px inclusivo JS vs CSS
- [ ] **FE-007** medium — preview variabili editor ≠ settings
- [ ] **FE-011** medium-adjacent — TEMP CSS nasconde nav documentata

- [ ] **WP10 chiuso** — Gutenberg può settare/leggere `_erankly_primary_terms`. Save classic con news sitemap off non delete l’esclusione. `manage_options` senza `edit_theme_options` vede ancora Special pages. List table `edit.php` non enqueue classic.

## WP11 — CI

File: `.github/workflows/phpunit.yml`, probes, `phpunit.xml.dist`. Test: workflow matrix; `node tests/js/*.cjs`.

- [ ] **H-09** high — CI solo WordPress 7.1, Requires 6.5
- [ ] **M-18** medium — JS contracts tautologici; probe skip se Node assente
- [ ] **TST-001** high — `live-schema-audit.php` escluso da PHPUnit
- [ ] **TST-002** high — breadcrumbs probe stampa `ok` dopo skip
- [ ] **FE-019** low — Plugin Check CI `ignore-warnings`

- [ ] **WP11 chiuso** — CI 6.5 e 7.1; probe fail se `blocks.js` assente; PHPUnit non skippa i `.cjs` se Node manca in CI.

## WP12 — Dead code / commenti

File: `schema.php`, `import-export/report.php`, `helpers/video.php`, `sanitization.php`, `custom-code.php`, `sitemap/core.php`, JS/CSS FE-012–014, `template-variables.php`, `global-meta.php`. Alias INF-051 / INF-034 = L-11 (già in no-op).

- [ ] **L-01** low — wrapper schema / `erankly_third_party_data_exists` zero caller
- [ ] **L-02** low — `erankly_core_breadcrumbs_default_attributes` solo test
- [ ] **L-03** low — quattro alias video
- [ ] **L-04** low — `erankly_custom_code_context_allowlist` alias
- [ ] **L-05** low — wrapper di `erankly_targeted_block_matches_request`
- [ ] **FE-012** low — CSS `.erankly-user-search-wrap` unused
- [ ] **FE-013** low — i18n JS localizzati e mai letti
- [ ] **FE-014** low — export JS unused su `window`
- [ ] **SEO-043** low — `erankly_news_sitemap_query` mai settato
- [ ] **INF-040** low — `settings_invalid` mai incrementato
- [ ] **L-11** low — header file “always loaded early” bugiardo

- [ ] **WP12 chiuso** — `rg` zero usi produzione dei wrapper L-01–L-05; `erankly_news_sitemap_query` assente o settato; header file non mentono sul load order; PHPUnit verde.

## WP13 — Suite test

File: elenco in report §4 WP13. Accettazione: ogni ramo ha un assert che può fallire. `live-schema-audit.php` **non** è in PHPUnit. Lanciare: `vendor/bin/phpunit` + 3 probe Node.

- [ ] **TST-003** high — feed robots header mai asserito
- [ ] **TST-004** high — nessun test 301/302 `wp_safe_redirect`
- [ ] **TST-005** high — attachment redirect parent/file non testati
- [ ] **TST-006** high — autosave probe DOMParser fake
- [ ] **TST-007** high — test escape senza `\`, `"`, `\n`
- [ ] **TST-008** high — inherit robots + legacy noindex untested
- [ ] **TST-009** high — adapter tabelle assenti = success
- [ ] **TST-010** high — live-audit non può fallire
- [ ] **TST-011** medium — breadcrumb registration skip-by-default
- [ ] **TST-025** medium — DROP TABLE skip SQLite nasconde uninstall
- [ ] **TST-028** medium — Woo doubles incompleti (no Product JSON-LD live)
- [ ] **TST-041** medium — REST special pages salva solo title/noindex
- [ ] **TST-042** medium — seo-state senza static-front / canonical
- [ ] **TST-043** medium — shorts URL non asseriti nell’extractor
- [ ] **TST-044** medium — Review Woo senza rating untested (M-08)
- [ ] **TST-045** medium — news exclude save (ADM-002) unasserted
- [ ] **TST-046** low — cron: nessun batch success (INF-001 untested)
- [ ] **TST-047** medium — test locka il wipe query_vars (H-10)

- [ ] **WP13 chiuso** — Ogni ramo elencato ha un assert che può fallire. `live-schema-audit.php` non è in PHPUnit.

---

## Backlog (ID non in WP1–WP13)

Ordine: high orfani, poi medium / low / nit come §3. Da fare dopo i WP, salvo dipendenze (es. H-10 con TST-047).

### High orfani

- [ ] **H-04** high — `array_filter` senza callback (droppa `0` / `'0'`)
- [ ] **H-05** high — BreadcrumbList `position` non contiguo
- [ ] **H-10** high — `erankly_force_robots_txt_request` wipe query_vars
- [ ] **H-11** high — cache visibilità: errore DB = nessuna esclusione
- [ ] **INF-007** high — contesto multilingual memoizzato troppo presto

### Medium

- [ ] **M-01** medium — ramo else `ERANKLY_OPTION` morto; commento autoload fuorviante
- [ ] **M-02** medium — filtro “enable sitemaps” in realtà suppress
- [ ] **M-03** medium — 60 alias hook legacy ogni request
- [ ] **M-04** medium — cache canonical/title/OG prima di `wp`
- [ ] **M-05** medium — date archive permalinks plain → canonical home
- [ ] **M-06** medium — gate frontend (`!admin && !ajax && !cron`) include feed/embed
- [ ] **M-07** medium — admin `manage_options` bypassa tutti i redirect
- [ ] **M-08** medium — Review Woo senza `reviewRating`
- [ ] **M-09** medium — schema block `has_content` solo `custom_json`
- [ ] **M-10** medium — CDN reverse-map `LIKE` allegato sbagliato
- [ ] **M-11** medium — `erankly_trim_text` tronca senza ellipsis
- [ ] **M-12** medium — uninstall `get_users` senza tetto
- [ ] **M-13** medium — skip null schema blocks ritorna `true` opaco
- [ ] **M-14** medium — news sitemap index senza guard `count > 0`
- [ ] **M-15** medium — specialist `get_max_num_pages()` sempre 0
- [ ] **M-16** medium — REST settings errori HTTP 200 `saved: true`
- [ ] **M-17** medium — `global_schema_blocks` assente → wipe `[]`
- [ ] **INF-012** medium — cache vars ignora queried/paged; `{{page_number}}` è 1 anche in home
- [ ] **INF-013** medium — `archive_seo_title` mappato su author, non date
- [ ] **INF-014** medium — Rank Math sitemap non-empty = enable
- [ ] **INF-015** medium — Woo OG image usa query corrente, non `$post_id`
- [ ] **INF-018** medium — reset SQL meta senza `wp_cache_flush`
- [ ] **INF-019** medium — ETag hashed su body pre-`DOMDocument->save()`
- [ ] **INF-020** medium — adapter sanitizer mismatch
- [ ] **INF-021** medium — `front_page` ignora include/exclude sulla static Page
- [ ] **INF-022** medium — mapper emette row vuote (rumore report)
- [ ] **INF-023** medium — Rank Math `contains` → regex unanchored sul path
- [ ] **INF-024** medium — race network reset
- [ ] **INF-026** medium — flush sitemap solo publish viewable (manca test positivo)
- [ ] **INF-027** medium — robots unsanitized all’output
- [ ] **SEO-011** medium — oggetto `index` vince su `noindex_paginated`
- [ ] **SEO-013** medium — skip crumb → nav 1 item possibile
- [ ] **SEO-019** medium — `exclude_search` solo main query non-AJAX
- [ ] **SEO-020** medium — search paged: canonical punta a page 1
- [ ] **SEO-022** medium — SearchAction sempre `/?s={search_term_string}`
- [ ] **SEO-023** medium — robots.txt preview `admin_url` vs live `site_url`
- [ ] **SEO-024** medium — LocalBusiness admin caricato da JSON-LD frontend
- [ ] **SEO-025** medium — Event `strtotime`+`gmdate` shift timezone
- [ ] **SEO-026** medium — Event meta keys collision-prone
- [ ] **SEO-027** medium — HowTo solo primo blocco
- [ ] **SEO-028** medium — oEmbed JSON su ogni canonical, anche 404
- [ ] **SEO-029** medium — attachment `parent` fallback file URL
- [ ] **SEO-031** medium — image sitemap page OOB uncached empty
- [ ] **SEO-032** medium — breadcrumbs `when_visible` crede a `has_block`/`has_shortcode`
- [ ] **SEO-033** medium — `render_block()` durante schema breadcrumbs
- [ ] **SEO-034** medium — `og:type=product` senza property prodotto
- [ ] **SEO-035** medium — `max-snippet` / preview non integer-validati
- [ ] **SEO-068** medium — twitter card default ignora setting globale
- [ ] **SEO-069** medium — `erankly_register_rewrites` appende filtri anonimi
- [ ] **SEO-073** medium — news `loc` off-site ammesso dal filtro
- [ ] **SEO-075** medium — `url_to_postid` per ogni CPT archive
- [ ] **FE-009** medium — `__( '(no title)' )` senza domain `easyrankly`
- [ ] **FE-016** medium — settings rispetta max-blocks; post editor `addBlock()` no cap
- [ ] **FE-017** medium — Local Business list null deref
- [ ] **FE-021** medium — dropzone input absolute senza parent relative
- [ ] **FE-022** medium — `color-mix` senza fallback solido
- [ ] **FE-023** medium — redirects `response.json()` assume JSON
- [ ] **ADM-005** medium — Site Editor hide ≠ PHP (disable_sitemap)
- [ ] **ADM-006** medium — special-pages senza noscript submit
- [ ] **ADM-007** medium — addon tabs: due filtri MS da tenere in sync
- [ ] **ADM-008** medium — header PHP “shared with editor.js” bugiardo
- [ ] **ADM-009** medium — UI custom code bugiarda senza `unfiltered_html`
- [ ] **ADM-010** medium — REST special-pages wipe se manca la mappa
- [ ] **INF-063** medium — network deactivate swallow `Throwable`
- [ ] **TST-012** medium — JS contracts tautologia string-contains
- [ ] **TST-013** medium — LB probe FakeNode senza `classList`
- [ ] **TST-014** medium — conflict SEO owner non simulabile
- [ ] **TST-015** medium — schema `assertIsArray` + `assertNotNull`
- [ ] **TST-016** medium — schema type list solo `count === 2`
- [ ] **TST-017** medium — `remove_all_filters('erankly_faq_items')`
- [ ] **TST-018** medium — breadcrumbs `should_emit === has_visible`
- [ ] **TST-019** medium — alias breadcrumbs confrontati tra loro
- [ ] **TST-020** medium — `has_action('parse_request')` senza callback
- [ ] **TST-021** medium — redirect admin solo nonce missing
- [ ] **TST-022** medium — nessun REST create/update/list redirects
- [ ] **TST-023** medium — sitemap test assume default off
- [ ] **TST-024** medium — 304 sitemap skip se `headers_sent`
- [ ] **TST-026** medium — pagination test sintetico, non `go_to(/page/2/)`
- [ ] **TST-027** medium — import size filter assert range troppo largo
- [ ] **TST-029** medium — JSON-LD test accoppiato a `WP_DEBUG`
- [ ] **TST-030** medium — suite duplicata (stessi contratti, più file)
- [ ] **TST-031** medium — CSS substring come test renderer
- [ ] **TST-032** medium — live-audit muta pagine/settings reali
- [ ] **TST-036** medium — cache flush: manca path publish positivo
- [ ] **TST-037** medium — OG fallbacks featured/content/term untested
- [ ] **TST-038** medium — canonical: no author/date/CPT/paged/attachment
- [ ] **TST-039** medium — editor schema UX solo in `schema-recheck.md`

### Low

- [ ] **ADM-011** low — title placeholder trim 70 vs counter 65
- [ ] **ADM-012** low — doc link `term-meta` chiave assente
- [ ] **ADM-013** low — molti `doc` keys senza URL
- [ ] **ADM-014** low — commento invertito disable-sitemap author
- [ ] **ADM-015** low — docblock unknown slugs falso su MS per-site
- [ ] **ADM-016** low — block editor require `meta-box.php` per placeholder
- [ ] **ADM-017** low — Site Editor placeholder title/description vuoti
- [ ] **ADM-018** low — `$noindex/$nofollow/$noarchive` caricati e mai usati
- [ ] **ADM-019** low — thin wrappers alias / tre helper per sprintf
- [ ] **ADM-020** low — filtro `erankly_nav_icons` overwrite `special-pages`
- [ ] **ADM-021** low — copy classic vs Gutenberg (exclude flags)
- [ ] **ADM-022** low — toggle label vuota fino a JS; redirects panel id
- [ ] **ADM-027** low — `save_post` require meta-box anche senza nonce
- [ ] **ADM-028** low — classic `DOING_AUTOSAVE` no-op vs Gutenberg REST
- [ ] **ADM-029** low — breadcrumb maxlength 120 solo classic
- [ ] **L-06** low — `erankly_schema_blogposting` solo test + hook legacy
- [ ] **L-07** low — `erankly_get_navigable_hreflang_alternates` solo test/readme
- [ ] **L-09** low — triple source of truth inserter breadcrumbs
- [ ] **L-10** low — `wp_json_encode` false → script JSON-LD vuoto
- [ ] **L-12** low — `$post_id` unused in `erankly_get_local_business_page_id`
- [ ] **L-13** low — extractor video senza `/shorts/`
- [ ] **L-14** low — QAPage forzatamente WebPage
- [ ] **L-16** low — `esc_html__` in throw (double-escape REST/log)
- [ ] **INF-028** low — linked-entity robots eredita noindex primo row
- [ ] **INF-032** low — DOCTYPE check case-sensitive
- [ ] **INF-033** low — `@id` slash home_url può duplicare nodi
- [ ] **INF-035** low — migrate flag senza lock (doppia migrate)
- [ ] **INF-036** low — `sanitize_text` su array → `"Array"`
- [ ] **INF-037** low — custom-code migrate ogni `init` finché legacy
- [ ] **INF-039** low — export `special_meta` solo blog corrente su MS
- [ ] **INF-049** low — Yoast empty option array = source available
- [ ] **INF-050** low — AIOSEO `match_type` solo exact/regex
- [ ] **INF-052** low — user meta flush omette `_erankly_disable_sitemap`
- [ ] **INF-053** low — `hasMore` true sull’ultima pagina esatta
- [ ] **INF-057** low — LB type regex ≠ webpage types
- [ ] **INF-064** low — `switch_to_blog` senza try/finally (LB page)
- [ ] **SEO-038** low — hreflang duplicate BCP47 first-wins
- [ ] **SEO-039** low — static `$rendered` swallow too-early `wp_head`
- [ ] **SEO-040** low — attachment host compare ignora port/www
- [ ] **SEO-044** low — image sitemap pagina per post, non per URL
- [ ] **SEO-045** low — empty last crumb → no `aria-current`
- [ ] **SEO-046** low — FAQPage + WebPage siblings (product choice)
- [ ] **SEO-047** low — news lang primi 2 char (`zh_CN` → `zh`)
- [ ] **SEO-048** low — image `LIKE % <img %` false positive poi filtrati
- [ ] **SEO-049** low — image discovery due volte (candidate + XML)
- [ ] **SEO-051** low — specialist index: image/video senza lastmod
- [ ] **SEO-054** low — `get_the_archive_title()` tiene “Category:”
- [ ] **SEO-055** low — dual title hooks (`pre_get_document_title` + parts)
- [ ] **SEO-056** low — JSON-LD admin notice solo il primo
- [ ] **SEO-057** low — term/user twitter URL senza `_id`
- [ ] **SEO-058** low — seo-state `kind=other` permissivo
- [ ] **SEO-059** low — CDN path `wp_redirect` non `wp_safe_redirect`
- [ ] **SEO-060** low — news XML getter non ri-checka enable
- [ ] **SEO-061** low — `get_the_excerpt` in video sitemap
- [ ] **SEO-063** low — admin-bar merge warnings se grafo non ancora built
- [ ] **SEO-064** low — probe `x` reject `{{price}}` unquoted
- [ ] **SEO-066** low — `og:locale` da `get_bloginfo('language')` non Facebook
- [ ] **SEO-067** low — `sanitize_key` lowercased su CPT subtype
- [ ] **SEO-070** low — unused `$post_id` (stesso L-12)
- [ ] **SEO-071** low — double `wp_kses` breadcrumbs
- [ ] **SEO-072** low — user exclusion dual path meta_query vs SQL
- [ ] **SEO-074** low — video title `substr` split UTF-8 senza mbstring
- [ ] **FE-008** low — due msgid schema warning classic vs block
- [ ] **FE-010** low — POT stale (line refs breadcrumbs)
- [ ] **FE-015** low — class JS senza CSS
- [ ] **FE-018** low — `WORDPRESS-ORG-READINESS.md` stale su lockfile
- [ ] **FE-020** low — `block.json` inserter sempre false
- [ ] **TST-033** low — `schema-recheck.md` docs, non test
- [ ] **TST-034** low — skip-noise (Node, MS, SQLite, headers_sent)
- [ ] **TST-035** low — comment form `wp:core/breadcrumbs` vs `wp:breadcrumbs`

### Nit

- [ ] **ADM-023** nit — hidden `erankly_settings_panel` orfano su MS per-site
- [ ] **ADM-024** nit — `blocks` alias di `schema`; fallback omits reset
- [ ] **ADM-025** nit — `wp_json_encode` fail → `""`; `strtolower` non mb-safe
- [ ] **ADM-026** nit — primary dropdown skip se `! has_term` (due save)
- [ ] **INF-054** nit — docblock indent `reset.php`
- [ ] **INF-055** nit — docblock indent `redirect-cache.php`
- [ ] **SEO-062** nit — BCP47 subset stretto (see N-09)
- [ ] **FE-025** nit — readme vs plugin header description
- [ ] **N-01** nit — doppio `wp_kses` breadcrumbs (see SEO-071)
- [ ] **N-02** nit — `typeof getBlockType === 'function'` ridondante
- [ ] **N-04** nit — icona special-pages = general
- [ ] **N-07** nit — import-export reload cieco 15s
- [ ] **N-09** nit — BCP47 ridotto
- [ ] **N-10** nit — ProfilePage `mainEntity` = identità sito, non autore
