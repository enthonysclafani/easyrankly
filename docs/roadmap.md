# Roadmap

Fonte di verità su cosa è fatto e cosa no. Spunta una casella solo quando il punto rispetta la
"Definizione di fatto" di `CLAUDE.md`. Un punto = un commit (o pochi commit coerenti).

I riferimenti ad Alpha indicano file del vecchio plugin (branch `Alpha` di questo repository, da leggere con
`git show origin/Alpha:<percorso>`) per capire la logica già testata. Il codice si riscrive secondo `CLAUDE.md`: niente tabelle, job o moduli.

## Fase 0 — Fondamenta

- [x] Repository, `CLAUDE.md`, bootstrap minimo con autoloader PSR-4
- [x] `composer check`: architettura, PHPCS, PHPStan, PHPUnit
- [x] `tools/check-architecture.php` con test sui casi vietati e consentiti
- [x] CI su GitHub: controlli statici su PHP 8.1 e 8.4, test anche su multisite (solo anti-conflitto), Plugin Check
- [x] `composer.lock` generato e committato
- [x] Primo run verde della CI su GitHub (branch `Refactory`)
- [x] Branch autonomo: piano in `docs/piano.md`, preparazione automatica delle sessioni cloud (`tools/cloud-setup.sh`)
- [x] Prima sessione Claude Code nel cloud con `composer check` verde

## Fase 1 — SEO di base

Riferimenti Alpha: `includes/title-description.php`, `includes/meta*.php`, `includes/canonical.php`,
`includes/robots.php`, `includes/opengraph.php`, `includes/schema*.php`, `includes/breadcrumbs.php`,
`includes/sitemap/`, `includes/class-erankly-*-sitemaps-provider.php`, `includes/redirects/`,
`includes/custom-code.php`, `admin/meta-box*`, `admin/settings*`.

- [x] 1.1 Impostazioni: option `easyrankly_settings` con `register_setting()` e schema; pagina admin con
      `@wordpress/components`; introduzione di `@wordpress/scripts` e `npm run build`
- [x] 1.2 Dati per contenuto: `register_post_meta()` / `register_term_meta()` con schema e `auth_callback`;
      pannello nell'editor a blocchi e campi nelle schermate dei termini
- [x] 1.3 Title e meta description: template con variabili, override per contenuto, `document_title_parts`
- [x] 1.4 Robots e canonical: filtro `wp_robots`, `get_canonical_url`, regole noindex per archivi, ricerca e allegati
- [x] 1.5 Open Graph e X
- [x] 1.6 Schema JSON-LD: Organization/Person, WebSite, WebPage, Article, BreadcrumbList in un unico grafo
- [x] 1.7 Breadcrumb: filtri del blocco `core/breadcrumbs` e coerenza con BreadcrumbList
- [x] 1.8 robots.txt tramite filtro `robots_txt`
- [x] 1.9 Sitemap: estensione delle sitemap del core (esclusione dei noindex, lastmod, tipi esclusi)
- [x] 1.10 Redirect: post type `erankly_redirect`, hash della sorgente in `post_name`, ricerca solo su 404 più lista
      "forzati", regex, 301/302/307/410, elenco admin con DataViews, redirect per cambio slug di pagine e termini
      (i post li gestisce già il core). Elenco fatto con una tabella di `@wordpress/components`: DataViews nel bundle
      pesava 1,9 MB contro 7 KB
- [x] 1.11 Custom code: post type `erankly_snippet`, HTML e PHP, `src/CustomCode/PhpRunner.php` con tutte le regole
      della sezione "Custom code" di `CLAUDE.md`

Criterio di uscita: su un articolo singolo Query Monitor non mostra query in più rispetto a WordPress senza plugin.

## Fase 2 — Multilingua (singolo sito)

Riferimenti Alpha: `includes/multilingual/singlesite/`, `includes/hreflang.php`, `MULTILINGUAL.md`.

- [x] 2.1 Lingue in option; tassonomie nascoste `erankly_language` (lingua del contenuto) e `erankly_translation`
      (gruppo di traduzioni)
- [x] 2.2 URL con prefisso di lingua (rewrite), home per lingua, filtro delle query per lingua
- [x] 2.3 Editor: scelta della lingua e collegamento delle traduzioni
- [x] 2.4 hreflang e x-default; sitemap per lingua. Le sitemap del core elencano i contenuti di ogni lingua con il
      loro URL e `wp-sitemap-languages-1.xml` le home delle lingue: niente file separati per lingua, che non aggiungono nulla
- [x] 2.5 Blocco selettore di lingua renderizzato lato server (solo `editorScript`, nessun asset nel frontend)
- [x] 2.6 Template SEO per lingua
- Decisione rimandata (ottobre 2026): anche menu e stringhe del tema, o solo SEO e collegamento delle traduzioni?
  Per ora la Fase 2 copre solo SEO e collegamento delle traduzioni (vedi `docs/piano.md`, sezione 7).

## Fase 3 — Agente AI nel plugin (chiavi dell'utente)

- [x] 3.1 Categoria di ability `easyrankly` e ability di sola lettura: dati SEO di un contenuto, contesto del sito,
      ricerca nei contenuti
- [x] 3.2 Proposte: post type `erankly_proposal`, stati con `register_post_status()`, REST, dashboard con DataViews
      (Accetta, Modifica e accetta, Rifiuta, Annulla), anteprima delle differenze. L'elenco usa
      `@wordpress/components` come in 1.10: DataViews nel bundle porta lo script da 7 KB a 1,9 MB
- [x] 3.3 `src/Agent/Allowlist.php`, validazione sullo schema, rilevamento delle proposte superate, tetto giornaliero
- [x] 3.4 Memoria: post type `erankly_memory`, import/export in `.md`, apprendimento da rifiuti e modifiche
- [x] 3.5 Prime proposte: meta e social, testo alternativo, redirect quando un contenuto viene cestinato
- [x] 3.6 Inneschi senza cron: eventi editoriali e apertura della dashboard
- [x] 3.7 Test dell'agente elencati in `CLAUDE.md`, inclusa la prompt injection nei contenuti

## Fase 4 — EasyRankly Cloud

Il backend vive in un repository separato. Qui solo la parte del plugin.

- [ ] 4.1 EasyRankly Cloud registrato come provider dell'AI Client e come connector
- [ ] 4.2 Search Console via OAuth tramite il Cloud; token cifrato
- [ ] 4.3 DataForSEO via Cloud; mappa parola chiave → pagina; tassonomia nascosta per i cluster
- [ ] 4.4 Mappa dei link interni con tassonomia nascosta; suggerimenti con embedding
- [ ] 4.5 Il plugin scarica le proposte preparate dal Cloud (nessun endpoint pubblico in scrittura)
- [ ] 4.6 Piano editoriale, brief, bozze, link interni, report mensile
- [ ] 4.7 Test pratico dei modelli decisionali (es. Jev di typesafe.ai) su 2–3 decisioni reali

## Decisioni aperte

- Multilingua: profondità (vedi Fase 2 e `docs/piano.md`, sezione 7).
- Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin? (riferimento Alpha: `includes/migrations/`)
- Schema extra: Local Business, Product per WooCommerce.
- IndexNow.
- Prezzo del Cloud: abbonamento fisso o con crediti inclusi (la piattaforma è Stripe Managed Payments, vedi `docs/piano.md`, sezione 6).
