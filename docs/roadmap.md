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

## Fase 4 — Interfaccia admin in stile WordPress classico

Decisione e dettagli: `docs/piano.md`, sezione 3, "Interfaccia admin". Viene prima dei punti di estensione perché la
Fase 5 tocca la dashboard delle proposte: si cambia una volta sola, sulla nuova interfaccia.

- [x] 4.1 Impostazioni su più pagine brevi con la Settings API del core (Generali, Schema e social, Titoli e
      descrizioni, Indicizzazione, Lingue), markup `form-table`, nessun JS tranne la scelta dell'immagine. Test: ogni
      pagina salva solo i suoi campi e lascia intatti gli altri (anche le caselle non spuntate e i template delle altre
      lingue), utente senza `manage_options`, nonce, valori fuori schema. Sparisce l'entry point `settings`.
- [ ] 4.2 Redirect e Custom code con `WP_List_Table` e schermate "Aggiungi" / "Modifica" classiche
      (`admin-post.php`, nonce, capability); codice degli snippet con `wp_enqueue_code_editor()`. Test: risposte HTTP
      delle schermate e dei form, permessi (`manage_options`, `unfiltered_html`, `edit_plugins`), nonce, stesse regole
      di oggi su sorgente, destinazione e sintassi PHP. Spariscono gli entry point `redirects` e `snippets`.
- [ ] 4.3 Agente AI con markup classico: schede `nav-tab-wrapper`, tabelle `wp-list-table`, "Modifica e accetta" nella
      riga, nessun `wp-components`.
- [ ] 4.4 Nessun foglio di stile `wp-components` né CSS nostro sulle schermate del plugin (resta solo nell'editor a
      blocchi). Test: gli stili accodati su ogni schermata del plugin.

## Fase 5 — Punti di estensione per EasyRankly Pro

EasyRankly Pro è un plugin separato (repository `easyrankly-pro`) con un suo backend: vedi `docs/piano.md`, sezione 6.
Qui solo il minimo che serve nel gratuito: punti di estensione generici, usabili da qualunque plugin, senza codice,
nomi o licenze del Pro. Ogni punto aggiunge hook pubblici: il piano del punto li elenca per l'approvazione.

- [ ] 5.1 Azioni proponibili registrabili da un altro plugin: un filtro che aggiunge all'allowlist un'ability con la
      sua definizione (valori correnti per impronta e "Annulla", oggetto modificato, etichette e tipo dei campi per
      l'anteprima delle differenze, anche per testi lunghi). Le azioni `destructive` restano rifiutate; tutto il resto
      passa dalla validazione di oggi. Tocca `src/Agent/Allowlist.php`, `src/Agent/Actions.php` e la dashboard delle proposte.
- [ ] 5.2 Funzione pubblica per creare una proposta, con le stesse regole di oggi (allowlist, schema, testi, tetto
      giornaliero, proposte superate). Test di 5.1 e 5.2 con un'azione di prova registrata da un plugin di test.

## EasyRankly Pro (repository separato, da creare)

Promemoria dei punti che erano qui come "Fase 4 — EasyRankly Cloud". La loro roadmap vivrà nel repository del Pro.

- Licenza annuale e attivazione con l'email d'acquisto; aggiornamenti tramite `Update URI`; backend
  (Cloudflare Workers) con Stripe Managed Payments
- Search Console via OAuth tramite il backend; token cifrato sul sito
- DataForSEO tramite il backend, con quote per sito e cache condivisa; mappa parola chiave → pagina; tassonomia
  nascosta per i cluster
- Mappa dei link interni con tassonomia nascosta; suggerimenti con embedding (AI dell'utente)
- Piano editoriale, brief, bozze, link interni, report mensile

## Decisioni aperte

- Multilingua: profondità (vedi Fase 2 e `docs/piano.md`, sezione 7).
- Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin? (riferimento Alpha: `includes/migrations/`)
- Schema extra: Local Business, Product per WooCommerce.
- IndexNow.
- EasyRankly Pro: prezzo, siti per licenza e quote di DataForSEO (proposta in `docs/piano.md`, sezione 6).
- Modelli decisionali (es. Jev di typesafe.ai): rimandati. Con l'AI pagata dall'utente, un modello sul backend
  sarebbe un costo nostro; da rivalutare dopo il lancio del Pro, con un test su 2–3 decisioni reali.
