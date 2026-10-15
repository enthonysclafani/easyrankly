# Roadmap

Fonte di verità su cosa è fatto e cosa no. Spunta una casella solo quando il punto rispetta la
"Definizione di fatto" di `CLAUDE.md`. Un punto = un commit (o pochi commit coerenti).

I riferimenti Alpha indicano file del vecchio plugin (branch `Decommissioned` di questo repository, ex `Alpha`, da leggere con
`git show origin/Decommissioned:<percorso>`) per capire la logica già testata. Il codice si riscrive secondo `CLAUDE.md`: niente tabelle, job o moduli.

## Fase 0 — Fondamenta

- [x] Repository, `CLAUDE.md`, bootstrap minimo con autoloader PSR-4
- [x] `composer check`: architettura, PHPCS, PHPStan, PHPUnit
- [x] `tools/check-architecture.php` con test sui casi vietati e consentiti
- [x] CI su GitHub: controlli statici su PHP 8.1 e 8.4, test anche su multisite (anti-conflitto e disinstallazione su tutta la rete), Plugin Check
- [x] `composer.lock` generato e committato
- [x] Primo run verde della CI su GitHub (branch `pre-release`)
- [x] Branch autonomo: piano in `docs/piano.md`, preparazione automatica delle sessioni cloud (`tools/cloud-setup.sh`)
- [x] Prima sessione Claude Code nel cloud con `composer check` verde

## Fase 1 — SEO di base

Riferimenti Alpha: `includes/title-description.php`, `includes/meta*.php`, `includes/canonical.php`,
`includes/robots.php`, `includes/opengraph.php`, `includes/schema*.php`, `includes/breadcrumbs.php`,
`includes/sitemap/`, `includes/class-erankly-*-sitemaps-provider.php`, `includes/redirects/`,
`includes/custom-code.php`, `admin/meta-box*`, `admin/settings*`.

- [x] 1.1 Impostazioni: option `easyrankly_settings` con `register_setting()` e schema; pagina admin con
      `@wordpress/components`; introduzione di `@wordpress/scripts` e `npm run build`. La pagina è stata poi rifatta
      con la Settings API (superato da 4.1)
- [x] 1.2 Dati per contenuto: `register_post_meta()` / `register_term_meta()` con schema e `auth_callback`;
      pannello nell'editor a blocchi e campi nelle schermate dei termini
- [x] 1.3 Title e meta description: template con variabili, override per contenuto, `document_title_parts`
- [x] 1.4 Robots e canonical: filtro `wp_robots`, `get_canonical_url`, regole noindex per archivi, ricerca e allegati
- [x] 1.5 Open Graph e X
- [x] 1.6 Schema JSON-LD: Organization/Person, WebSite, WebPage, Article, BreadcrumbList in un unico grafo
- [x] 1.7 Breadcrumb: filtri del blocco `core/breadcrumbs` e coerenza con BreadcrumbList
- [x] 1.8 robots.txt tramite filtro `robots_txt`
- [x] 1.9 Sitemap: estensione delle sitemap del core (esclusione dei noindex, lastmod, tipi esclusi; fuori anche i post
      con un redirect esatto "applica sempre")
- [x] 1.10 Redirect: post type `erankly_redirect`, hash della sorgente in `post_name`, ricerca solo su 404 più lista
      "forzati", regex, 301/302/307/410, elenco admin con DataViews, redirect per cambio slug di pagine e termini
      (i post li gestisce già il core). Elenco fatto con una tabella di `@wordpress/components`: DataViews nel bundle
      pesava 1,9 MB contro 7 KB. L'elenco è stato poi rifatto con `WP_List_Table` (superato da 4.2)
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
- [x] 2.5 Blocco selettore di lingua renderizzato lato server (script solo nell'editor a blocchi, nessun asset nel
      frontend; dal 14 ottobre 2026 lo script non è più in `block.json`, che lo registrava a ogni richiesta)
- [x] 2.6 Template SEO per lingua
- Decisione di ottobre 2026: il multilingua copre anche menu e stringhe del tema, senza tabelle. Lo fa la Fase 7.

## Fasi 3, 5 e 6 — Agente AI: costruito qui, poi portato in EasyRankly Pro

Tutte chiuse. L'agente è stato costruito qui con le chiavi AI dell'utente (Fase 3, 8 ottobre 2026) e reso
estendibile da un altro plugin (Fase 5); il 9 ottobre 2026 si è deciso che vive tutto nel Pro e la Fase 6 l'ha tolto,
senza migrazione perché la riscrittura non era pubblicata. Il codice resta nella storia di `pre-release`: commit
`2af2474`, ultimo con l'agente (`src/Agent/`, `assets/src/agent/`, `tests/Agent/`, circa 8.200 righe tra PHP, JS e
test). Lo porta la Fase 1 della roadmap del Pro.

- [x] Fase 3, l'agente. 3.1 categoria di ability `easyrankly` e ability di sola lettura (dati SEO di un contenuto,
      contesto del sito, ricerca nei contenuti); 3.2 proposte nel post type `erankly_proposal` con stati
      `register_post_status()`, REST, dashboard (Accetta, Modifica e accetta, Rifiuta, Annulla) e anteprima delle
      differenze: l'elenco usava `@wordpress/components` perché DataViews nel bundle portava lo script da 7 KB a
      1,9 MB; 3.3 `src/Agent/Allowlist.php`, validazione sullo schema, proposte superate, tetto giornaliero;
      3.4 memoria nel post type `erankly_memory`, import/export in `.md`, apprendimento da rifiuti e modifiche;
      3.5 prime proposte (meta e social, testo alternativo, redirect per un contenuto cestinato); 3.6 inneschi senza
      cron (eventi editoriali, apertura della dashboard); 3.7 test, compresa la prompt injection nei contenuti
- [x] Fase 5, i punti di estensione. 5.1 filtro `easyrankly_agent_actions` che aggiungeva all'allowlist un'azione
      proponibile di un altro plugin (valori correnti per impronta e "Annulla", oggetto modificato, etichette e tipi
      dei campi per l'anteprima; azioni `destructive` rifiutate); 5.2 funzione `easyrankly_create_proposal()` con le
      stesse regole; test con un plugin di prova
- [x] Fase 6, l'uscita. 6.1 tolti `src/Agent/`, l'entry point JS, la voce "AI agent", la chiave `agent_auto` di
      `easyrankly_settings`, l'option `easyrankly_agent`, i post type `erankly_proposal` ed `erankly_memory` con stati e
      meta, la loro pulizia in `uninstall.php`, i test, le voci di `docs/data-model.md` e la parte AI di `readme.txt`;
      6.2 tolti i punti della Fase 5 (con `src/functions.php` e il plugin di prova) e aggiornato `CLAUDE.md`;
      6.3 `composer architecture` rifiuta funzioni e hook di AI Client, Abilities API e Connectors API e i nomi
      dell'agente e del Pro

## Fase 4 — Interfaccia admin in stile WordPress classico

Decisione e dettagli: `docs/piano.md`, sezione 3, "Interfaccia admin". È venuta prima dei punti di estensione perché
la Fase 5 toccava la dashboard delle proposte: si è cambiata una volta sola, sulla nuova interfaccia.

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

## Fase 7 — Multilingua: menu e stringhe del tema

Decisioni e perché: `docs/decisioni.md`, "Multilingua". Niente tabelle né moduli; option e meta nuovi si
approvano nel piano di ogni punto.

- [x] 7.1 Titolo e descrizione del sito per lingua (pagina Lingue), letti con `option_blogname` e
      `option_blogdescription` sulle pagine di quella lingua
- [x] 7.2 Menu per lingua: la navigazione per lingua del blocco `core/navigation` (temi a blocchi). Le posizioni di
      menu per lingua dei temi classici sono state tolte il 10 ottobre 2026; il 12 ottobre 2026 è stata tolta anche la
      scelta del menu per lingua: il menu di una lingua sta nel suo template part (`header-en`, punto 7.3)
- [x] 7.3 Altre stringhe del tema: nei temi a blocchi il blocco `core/template-part` mostra, sulle pagine di una
      lingua, la sua versione `{slug}-{lingua}` (es. `header-en`) se esiste nell'Editor del sito o nel tema; nessun dato
      nuovo. Widget dei temi classici esclusi (decisione di ottobre 2026)
- [x] 7.4 Pattern sincronizzati: sulle pagine di una lingua il blocco `core/block` mostra, al posto del pattern
      sincronizzato (`wp_block`) che richiama, la sua versione con lo slug `{slug}-{lingua}` (es. "Nome - IT" per
      "Nome") se esiste ed è pubblicata; nessun dato nuovo. I pattern non sincronizzati restano esclusi (vedi
      `docs/decisioni.md`, "Multilingua")
- [x] 7.5 Aiuto nella pagina Lingue e in `readme.txt`: la convenzione `{slug}-{lingua}` per template part e pattern
      sincronizzati, e l'avviso che il multilingua richiede un tema a blocchi

Il multilingua richiede un tema a blocchi (decisione del 10 ottobre 2026): nei temi classici cambiano lingua
contenuti, indirizzi, titolo e descrizione del sito e le stringhe tradotte del tema, ma non menu, widget né testi del
Customizer, e la pagina Lingue lo dice con un avviso. SEO, sitemap, redirect e custom code funzionano con ogni tema.

## Requisiti minimi

Decisione del 9 ottobre 2026: il più bassi possibile senza peggiorare il codice (vedi `docs/decisioni.md`).

- [x] Minimo di PHP: 8.0 (`testVersion` in `phpcs.xml.dist`, intestazioni, Composer, CI su 8.0 e 8.4). Sotto non si
      scende senza riscrivere codice: `match`, operatore `?->` e tipo `mixed` sono di PHP 8.0. PHPCompatibility 9.3.5 non
      conosce le novità di PHP 8: la conferma vera sono PHPStan e PHPUnit della CI su PHP 8.0
- [x] Minimo di WordPress: resta 7.0 (decisione del 9 ottobre 2026). Lo richiede solo il breadcrumb: blocco
      `core/breadcrumbs` e filtri `block_core_breadcrumbs_items` e `block_core_breadcrumbs_post_type_settings`. Senza,
      il minimo sarebbe 6.6 (`PluginDocumentSettingPanel` da `@wordpress/editor`), poi 6.5
      (`wp_is_serving_rest_request()`) e 6.3 (`wp_cache_set_last_changed()`)

## Fase 8 — Controllo di sicurezza

Da chiudere prima della pubblicazione della 2.0.0 (decisione del 12 ottobre 2026). Cosa controllare in ogni area e
come: `docs/sicurezza.md`; modello di minaccia: `docs/piano.md`, sezione 5. Finora ci sono stati solo controlli
mirati. I problemi trovati e non ancora corretti restano in note private fuori dal repository; ogni correzione è una
PR a sé, con il suo test di regressione, che descrive il rinforzo senza la ricetta d'attacco.

- [x] 8.1 Custom code: CC-01 (matrice dei permessi, resa permanente da un test con data provider in
      `tests/CustomCode/`), CC-02 (tutte le strade di scrittura del codice), CC-03 (l'option `easyrankly_snippets`
      come confine di fiducia, firma HMAC compresa)
- [x] 8.2 Custom code: CC-04 … CC-09 (sintassi ed errori a runtime, dove girano, spegnimento avviato da un
      visitatore, lettura del codice, schermata admin, disinstallazione)
- [x] 8.3 Redirect (RD) e uscita nel `<head>` (HD): le aree raggiungibili dai visitatori anonimi
- [x] 8.4 Multilingua (ML)
- [x] 8.5 Impostazioni (ST), sitemap (SM), admin generico e JS (AD)
- [x] 8.6 Resoconto finale; rischi accettati scritti in `CLAUDE.md`; correzione dei problemi trovati, compresi quelli
      dei controlli mirati ancora aperti

Criterio di uscita: ogni problema trovato è corretto, oppure accettato con una decisione scritta in
`docs/decisioni.md`.

Il 13 ottobre 2026 il controllo dei punti 8.1–8.5 è stato fatto leggendo il codice, con PHPCS (sniff di sicurezza
senza annotazioni) e con prove PHPUnit; la matrice dei permessi degli snippet è in
`tests/CustomCode/PermissionsTest.php`. Restano le prove che nel cloud non si possono fare (riga "Sicurezza" in
"Verifiche a mano su Studio").

## Compatibilità con WooCommerce e ACF

Analisi del 10 ottobre 2026, da completare più avanti. Dettagli, motivi e proposte: `docs/compatibilita.md`.

- [x] W2 — La pagina Negozio dà all'archivio dei prodotti i suoi campi SEO
- [x] W3 — Canonical, `og:url` e schema del Negozio, anche come pagina iniziale
- [x] W6 — `og:type` `article` solo per gli articoli
- [x] W7 — Pagina Negozio senza prodotti fuori dalla sitemap
- [x] W1 — Campi SEO e lingua dei prodotti (meta box nell'editor classico; allegati esclusi)
- [x] W4 — Carrello, checkout e account fuori dalla sitemap
- [ ] W5 — Multilingua sui negozi (oggi non supportato; in sospeso)
- [x] A1 — Description da un campo personalizzato (ACF e altri): variabile `{{cf:nome_campo}}`
- [ ] A2 — "Crea traduzione" copia i meta (campi ACF), in sospeso con W5
- [ ] Verifica su Studio con WooCommerce e ACF attivi

## EasyRankly Pro

Il Pro ha la sua roadmap, a fasi, nel repository privato `easyrankly-pro` (`docs/roadmap.md`, branch `main`): agente
AI portato da qui, backend di licenze e aggiornamenti, Search Console, DataForSEO, link interni, contenuti, vendita.
Qui conta solo il contratto con il gratuito (`docs/piano.md`, sezione 6): se il Pro chiede di allargarlo, è una
decisione (`docs/decisioni.md`).

## In sospeso

Elenco unico di ciò che resta aperto, aggiornato al 14 ottobre 2026. Dove un punto è già descritto altrove, qui c'è
solo il rimando: i dettagli restano in quel documento.

### Pubblicazione della 2.0.0 su WordPress.org

Lo slug `easyrankly` ha già la 1.0.0 pubblicata (codice del branch `main`, meno di 10 installazioni): la riscrittura
esce come aggiornamento 2.0.0, con un commit SVN su `trunk` e `tags/2.0.0`, senza una nuova revisione. Per chi
aggiorna dalla 1.0.0 non si fa niente (decisione del 12 ottobre 2026): meta `_erankly_*`, option, tabelle e cron della
1.0.0 restano orfani. Già fatti: link al sorgente JS nel readme e soglia `unfiltered_html` sugli snippet (PR #67).

- [x] Readme per la scheda pubblica: togliere "not ready for production sites", descrivere cosa fa e cosa non fa,
      aggiungere `== Installation ==`, `== Upgrade Notice ==` e il changelog 2.0.0 ("le impostazioni della 1.0.0 non
      vengono importate"); nella FAQ chi può usare gli snippet PHP (`edit_plugins`, mai con `DISALLOW_FILE_EDIT`) e la
      modalità sicura
- [x] Versione da 3.0.0 a 2.0.0: header di `easyrankly.php`, costante `VERSION`, `Stable tag`, changelog,
      `tests/PluginTest.php`; nei documenti "EasyRankly 3" diventa 2.0
- [x] Plugin Check completo, una volta, a mano: tutte le categorie, con i warning e senza escludere
      `src/CustomCode/PhpRunner.php` (14 ottobre 2026: corretti i segnalati con le PR #83 e #84; resta solo
      l'`eval` di `PhpRunner.php`, eccezione approvata)
- [ ] Controllare che `easyrankly.com` (`Plugin URI` e `Author URI`) risponda e che l'account `easyrankly` dei
      `Contributors` abbia accesso in scrittura all'SVN
- [ ] Prova a mano su Studio con lo zip di `tools/build-dist.sh` (o quello della CI, tra gli Artifacts), compreso l'aggiornamento da una 1.0.0 installata
      (vedi "Verifiche a mano su Studio" qui sotto)
- [x] Controllo di sicurezza completo e correzione dei problemi trovati (Fase 8; restano le prove a mano su Studio)
- [ ] Commit SVN su `trunk` e `tags/2.0.0`

Miglioramenti non bloccanti, da fare quando capita:

- [ ] Icona, banner e screenshot della scheda (cartella `assets/` dell'SVN)
- [ ] Plugin Check completo come job CI manuale (`workflow_dispatch`), per ripeterlo a ogni rilascio
- [ ] `Plugin URI` diverso da `Author URI` (toglie un warning di Plugin Check)
- [ ] Dependabot anche per Composer e npm (oggi solo GitHub Actions)

### Verifiche a mano su Studio

I test automatici sono verdi, ma nel cloud non c'è un sito WordPress: queste cose non sono mai state viste in un
browser dopo le PR che le hanno toccate.

- [ ] Query Monitor: nessuna query in più su articolo singolo, pagina, archivi e home (criterio di uscita della Fase 1;
      l'eccezione nota è in `CLAUDE.md`, sezione Prestazioni)
- [ ] Schermate admin: Impostazioni (Titoli, Indicizzazione con la "Pagina articoli", Schema e social con Local
      Business), Redirect e Custom code con il campo Note, pagina Lingue con le tabelle Lingue e Strings
- [ ] Pannello SEO dell'editor ("Search appearance" e "Social sharing", anteprima dell'immagine social), pannello
      Lingua e campi SEO dei termini (dopo `npm run build`)
- [ ] Multilingua nel Site Editor: template part `{slug}-{lingua}`, pattern sincronizzati per lingua, blocco
      Navigation dentro `header-{lingua}`, selettore di lingua; avviso della pagina Lingue con un tema classico
- [ ] Custom code: snippet "Ovunque" che rompe l'admin e recupero (vedi `docs/custom-code.md`, "Da verificare a mano")
- [ ] Contenuti protetti da password, archivi vuoti in noindex, ProfilePage degli autori con il Rich Results Test di
      Google
- [ ] WooCommerce e ACF attivi (riga già nella sezione "Compatibilità con WooCommerce e ACF")
- [ ] Sicurezza: costanti `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `DISALLOW_UNFILTERED_HTML` ed
      `EASYRANKLY_SAFE_MODE`; snippet da un file WXR importato; errori fatali veri in uno snippet (memoria, tempo,
      `exit`); posizioni di pagina nel Customizer e nell'editor del sito (`docs/sicurezza.md`, sezione 5, punto 3)

### Da decidere

Tutte le decisioni aperte, del gratuito e del Pro, stanno in `docs/decisioni.md`, "Da decidere".

### Funzioni decise ma non ancora fatte

- [x] Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin, senza cron (`docs/decisioni.md`); i redirect
      di Rank Math si leggono dalla sua tabella con `$wpdb` in un solo file (decisione del 15 ottobre 2026)
- [ ] Prova su Studio dell'import con dati veri di Yoast SEO (anche Premium, per i redirect) e di Rank Math

### Debito tecnico residuo

Dalla seconda analisi del 10 ottobre 2026 sono stati corretti i punti scelti (PR #62) e, il 12 ottobre 2026, i
cinque punti rimasti: una sola regola di "indicizzabile" (`Robots::is_indexable_post()`, l'hreflang ora esclude i
contenuti con un redirect forzato), liste dei redirect ricostruite solo quando serve, una sola ricostruzione in
`CustomCode::disable()`, le piccole duplicazioni. Restano, accettati e senza lavoro previsto:

- Pattern sincronizzati e template part nelle lingue non predefinite: una query carica tutti i pattern pubblicati e
  fino a 100 template part. Misurato, non aggiunge query (le precarica per il core, che poi non le chiede); pesa solo
  sui siti con moltissimi pattern. Se servirà, l'alternativa è una mappa delle versioni in un'option autoload.
- Redirect e Custom code tengono controller REST e salvataggio separati: le regole dei permessi sono diverse
  (`manage_options` contro `unfiltered_html` ed `edit_plugins`) e il codice in comune è poco; le schermate
  condividono già `Admin/RecordsPage`.
- Lo stile dell'anteprima dell'immagine social nel pannello dell'editor resta inline: il plugin non carica fogli
  di stile propri (`Admin/Assets`), e un file CSS per sette regole costerebbe di più.

### Eccezioni PHPCS

Le righe con `phpcs:ignore` sono 35, quasi tutte giuste (letture di `$_GET` nel frontend, query sulle tassonomie
delle lingue, snippet, redirect esterni validati). Da fare quando si decide (Enthony, 14 ottobre 2026):

- [x] Rivedere le eccezioni e togliere quelle che si possono evitare (14 ottobre 2026: tolte 7, da 36 a 29, ognuna con
      un test: i campi SEO dei termini e dell'editor classico letti uno per uno, già sanitizzati, e stampati senza
      stringhe preparate prima; la tassonomia della schermata dei termini letta da `$taxnow` del core; l'anteprima
      dell'immagine costruita con `esc_url()`; il confronto delle traduzioni nel box classico fatto in modo stretto su
      elenchi ordinati). Restano quelle giustificate; gli input di sorgente e destinazione dei Redirect restano grezzi,
      perché `sanitize_text_field()` toglierebbe le codifiche `%XX`, e li valida `Redirects::check()`

### Controllo di sicurezza

Fase 8 chiusa il 13 ottobre 2026: problemi corretti e rischi residui decisi (`docs/decisioni.md`, "Sicurezza" tra le
decise). Restano le prove a mano su Studio (riga "Sicurezza" in "Verifiche a mano su Studio").
