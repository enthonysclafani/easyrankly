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
- Decisione di ottobre 2026: il multilingua copre anche menu e stringhe del tema, senza tabelle. Lo fa la Fase 7.

## Fase 3 — Agente AI nel plugin (chiavi dell'utente)

Decisione di ottobre 2026: l'agente passa tutto a EasyRankly Pro e si toglie da qui con la Fase 6.

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
- [x] 4.2 Redirect e Custom code con `WP_List_Table` e schermate "Aggiungi" / "Modifica" classiche (form inviati
      alla schermata stessa, così un errore torna con i valori digitati; nonce, capability; salvataggio tramite le route
      REST esistenti con `rest_do_request()`); codice degli snippet con `wp_enqueue_code_editor()`. Test: risposte HTTP
      delle schermate e dei form, permessi (`manage_options`, `unfiltered_html`, `edit_plugins`), nonce, stesse regole
      di oggi su sorgente, destinazione e sintassi PHP. Spariscono gli entry point `redirects` e `snippets`.
- [x] 4.3 Agente AI con markup classico: schede `nav-tab-wrapper` (link, una per pagina), stati e pagine delle proposte
      come link, tabelle `wp-list-table`, decisioni ("Accetta", "Modifica e accetta", "Rifiuta", "Annulla") nella riga,
      memoria con form sopra l'elenco, nessun componente di `@wordpress/components`.
- [x] 4.4 Il plugin non accoda `wp-components` né CSS suoi sulle schermate del plugin (restano solo nell'editor a
      blocchi). Il core li carica comunque in tutto l'admin per la palette dei comandi (`wp_enqueue_command_palette_assets`,
      WordPress 7.0): conta ciò che accodiamo noi. Test: gli stili accodati dal plugin su ogni sua schermata.

## Fase 5 — Punti di estensione per EasyRankly Pro

Decisione di ottobre 2026: il Pro è autonomo e nel gratuito non resta nessun punto di estensione per lui. Questi due
si tolgono con l'agente nella Fase 6.

EasyRankly Pro è un plugin separato (repository `easyrankly-pro`) con un suo backend: vedi `docs/piano.md`, sezione 6.
Qui solo il minimo che serve nel gratuito: punti di estensione generici, usabili da qualunque plugin, senza codice,
nomi o licenze del Pro. Ogni punto aggiunge hook pubblici: il piano del punto li elenca per l'approvazione.

- [x] 5.1 Azioni proponibili registrabili da un altro plugin: un filtro che aggiunge all'allowlist un'ability con la
      sua definizione (valori correnti per impronta e "Annulla", oggetto modificato, etichette e tipo dei campi per
      l'anteprima delle differenze, anche per testi lunghi). Le azioni `destructive` restano rifiutate; tutto il resto
      passa dalla validazione di oggi. Tocca `src/Agent/Allowlist.php`, `src/Agent/Actions.php` e la dashboard delle proposte.
- [x] 5.2 Funzione pubblica per creare una proposta, con le stesse regole di oggi (allowlist, schema, testi, tetto
      giornaliero, proposte superate). Test di 5.1 e 5.2 con un'azione di prova registrata da un plugin di test.

## Fase 6 — Agente AI fuori dal gratuito

Decisione e perché: `docs/piano.md`, sezioni 5 e 6. L'agente vive tutto in EasyRankly Pro (repository
`easyrankly-pro`); nessuna migrazione, perché la versione 3 non è ancora pubblicata. Il codice da portare resta nella
storia di `Refactory` (ultimo commit con l'agente prima di 6.1).

- [x] 6.1 Togliere l'agente: `src/Agent/`, l'entry point JS, la voce di menu "AI agent", la chiave `agent_auto` di
      `easyrankly_settings`, l'option `easyrankly_agent`, i post type `erankly_proposal` ed `erankly_memory` con i
      loro stati e meta, la pulizia in `uninstall.php`, i test, le voci di `docs/data-model.md` e la parte AI di `readme.txt`
- [x] 6.2 Togliere i punti di estensione della Fase 5 (`easyrankly_agent_actions`, `easyrankly_create_proposal()`,
      `src/functions.php` se resta vuoto, il plugin di prova) e aggiornare `CLAUDE.md` (sezioni "Agente AI",
      "EasyRankly Pro", "Perimetro", invarianti 6 e 7)
- [x] 6.3 Verifica: nessun riferimento ad agente, proposte, AI o Pro nel codice (`composer architecture` può
      controllarlo); il plugin funziona uguale senza un provider AI

## Fase 7 — Multilingua: menu e stringhe del tema

Decisione e strada proposta: `docs/piano.md`, sezione 7. Niente tabelle né moduli; option e meta nuovi si
approvano nel piano di ogni punto.

- [x] 7.1 Titolo e descrizione del sito per lingua (pagina Lingue), letti con `option_blogname` e
      `option_blogdescription` sulle pagine di quella lingua
- [x] 7.2 Menu per lingua: per ogni posizione del tema un menu per lingua (`theme_mod_nav_menu_locations`) e, nei temi
      a blocchi, la navigazione per lingua del blocco `core/navigation`
- [x] 7.3 Altre stringhe del tema: nei temi a blocchi il blocco `core/template-part` mostra, sulle pagine di una
      lingua, la sua versione `{slug}-{lingua}` (es. `header-en`) se esiste nell'Editor del sito o nel tema; nessun dato
      nuovo. Widget dei temi classici esclusi (decisione di ottobre 2026)
- [x] 7.4 Pattern sincronizzati: sulle pagine di una lingua il blocco `core/block` mostra, al posto del pattern
      sincronizzato (`wp_block`) che richiama, la sua versione con lo slug `{slug}-{lingua}` (es. "Nome - IT" per
      "Nome") se esiste ed è pubblicata; nessun dato nuovo. I pattern non sincronizzati restano esclusi (vedi
      `docs/piano.md`, sezione 7)
- [x] 7.5 Aiuto nella pagina Lingue e in `readme.txt`: la convenzione `{slug}-{lingua}` per template part e pattern
      sincronizzati, e l'avviso che nei temi classici i testi del tema (widget, Customizer) non cambiano lingua

I temi a blocchi hanno la priorità: nei temi classici funzionano contenuti, menu, titolo e descrizione del sito e le
stringhe tradotte del tema, ma non widget né testi del Customizer (decisione del 9 ottobre 2026).

## Requisiti minimi

Decisione di ottobre 2026: il più bassi possibile senza peggiorare il codice (vedi `docs/piano.md`, sezione 7).

- [x] Minimo di PHP: 8.0 (`testVersion` in `phpcs.xml.dist`, intestazioni, Composer, CI su 8.0 e 8.4). Sotto non si
      scende senza riscrivere codice: `match`, operatore `?->` e tipo `mixed` sono di PHP 8.0. PHPCompatibility 9.3.5 non
      conosce le novità di PHP 8: la conferma vera sono PHPStan e PHPUnit della CI su PHP 8.0
- [x] Minimo di WordPress: resta 7.0 (decisione del 9 ottobre 2026). Lo richiede solo il breadcrumb: blocco
      `core/breadcrumbs` e filtri `block_core_breadcrumbs_items` e `block_core_breadcrumbs_post_type_settings`. Senza,
      il minimo sarebbe 6.6 (`PluginDocumentSettingPanel` da `@wordpress/editor`), poi 6.5
      (`wp_is_serving_rest_request()`) e 6.3 (`wp_cache_set_last_changed()`)

## EasyRankly Pro (repository `easyrankly-pro`)

Promemoria dei punti che erano qui come "Fase 4 — EasyRankly Cloud", più l'agente. La loro roadmap vive nel
repository del Pro. Nome nel codice: `erankly-pro`; richiede il gratuito (`Requires Plugins: easyrankly`).

- Agente AI portato dalle Fasi 3 e 5 (proposte, memoria, dashboard, allowlist con la validazione), prima funzione del
  Pro; struttura per funzioni, pronta ad accoglierne altre senza un sistema a moduli

- Licenza annuale e attivazione con l'email d'acquisto; aggiornamenti tramite `Update URI`; backend
  (Cloudflare Workers) con Stripe Managed Payments
- Search Console via OAuth tramite il backend; token cifrato sul sito
- DataForSEO tramite il backend, con quote per sito e cache condivisa; mappa parola chiave → pagina; tassonomia
  nascosta per i cluster
- Mappa dei link interni con tassonomia nascosta; suggerimenti con embedding (AI dell'utente)
- Piano editoriale, brief, bozze, link interni, report mensile
- Noindex su pagine povere tra le azioni proponibili (rischio alto): l'AI propone, l'utente sceglie

## Decisioni aperte

- Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin? (riferimento Alpha: `includes/migrations/`)
- Schema extra: Local Business, Product per WooCommerce.
- Modelli decisionali (es. Jev di typesafe.ai): rimandati. Con l'AI pagata dall'utente, un modello sul backend
  sarebbe un costo nostro; da rivalutare dopo il lancio del Pro, con un test su 2–3 decisioni reali.

Decise a ottobre 2026 (dettagli in `docs/piano.md`, sezione 7): niente IndexNow; prezzi del Pro confermati; multilingua
anche per menu e stringhe del tema (Fase 7), con i template part per lingua e senza i widget classici; requisiti minimi il più bassi possibile; noindex su pagine povere
proponibile nel Pro; agente AI tutto nel Pro (Fase 6); vendita e licenze del Pro con il
backend nostro e Stripe Managed Payments.
