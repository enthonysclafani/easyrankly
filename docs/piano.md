# Piano di EasyRankly 2.0

Questo documento raccoglie la visione del prodotto: perché esiste, i principi, le funzioni, dove vivono i dati, la
sicurezza e il contratto con EasyRankly Pro. Le singole decisioni, aperte e prese, con data e perché, stanno in
`docs/decisioni.md`; `CLAUDE.md` contiene le regole operative; `docs/roadmap.md` l'avanzamento. Se una decisione
cambia, si aggiorna prima `docs/decisioni.md` (e questo file, se tocca un principio), poi le regole e la roadmap.

## 1. Perché un nuovo plugin

Il vecchio EasyRankly (branch `main` e `Decommissioned`, ex `Alpha`, che contiene anche il vecchio `beta`) è cresciuto per moduli:
circa 36.000 righe di PHP, due tabelle custom (`erankly_redirects`, `erankly_languages`), job in cron per
migrazioni e import, form, strumenti database, multisite. Ripulirlo costerebbe più che riscriverlo.

Si riparte da un repository pulito sul branch `pre-release` (nato come `Refactory`), usando Alpha (branch `Decommissioned`) **solo come riferimento** per la logica già
testata (meta, schema, sitemap, matching dei redirect, editor del multilingua). Obiettivo: circa 10–12.000 righe.

## 2. Principi non negoziabili

1. **Zero tabelle custom.** Solo Options API, meta, post type non pubblici, tassonomie nascoste.
2. **Zero cron.** Il lavoro lungo si fa a lotti guidati dall'admin, anche nel Pro.
3. **Zero script nel frontend.** Lo schema JSON-LD è dati, non codice; il selettore di lingua è solo HTML.
4. **Solo API native di WordPress**, comprese quelle AI nel Pro: AI Client, Connectors, Abilities API.
5. **Nessun sistema a moduli.** Il plugin gratuito è unico e definito. L'unica estensione è EasyRankly Pro,
   un plugin separato e autonomo: nel gratuito non c'è nessun codice per il Pro, nemmeno punti di estensione dedicati
   (decisione del 9 ottobre 2026, sezione 6).
6. **Nessuna funzione dedicata a multisite**, ma nessun conflitto: solo API per sito. Eccezione: la disinstallazione
   pulisce ogni sito della rete con `get_sites()`, perché una disinstallazione incompleta lascerebbe dati ovunque
   (decisione del 12 ottobre 2026).
7. **L'AI non scrive mai direttamente**: ogni modifica è una proposta che un umano approva.

Conseguenza principale: ciò che costa (dati di DataForSEO) o richiede un server (OAuth di Search Console, licenze,
aggiornamenti) vive nel **backend di EasyRankly Pro**, non dentro WordPress. Lo storico non si conserva da nessuna parte:
Search Console tiene già 16 mesi.

## 3. Funzioni

| Area | Cosa fa | Come rispetta i principi |
|---|---|---|
| SEO | Title e description (template + override), robots, canonical, Open Graph e X, schema JSON-LD di base, robots.txt | Filtri del core: `document_title_parts`, `wp_robots`, `get_canonical_url`, `robots_txt` |
| Breadcrumb | Usa il blocco `core/breadcrumbs` del core; il plugin aggiunge BreadcrumbList | Filtro `block_core_breadcrumbs_items` |
| Sitemap | Estende le sitemap del core | `wp_sitemaps_*`, nessun generatore proprio |
| Redirect | 301/302/307/410/451, esatti e regex | Post type `erankly_redirect`; hash della sorgente in `post_name` (indicizzato); ricerca solo su 404 più una piccola lista di "forzati" |
| Custom code | Snippet HTML e PHP in head, apertura del body, footer; PHP anche "ovunque", come `functions.php` | Post type `erankly_snippet`; PHP solo con `edit_plugins`, controllo della sintassi senza esecuzione, disattivazione automatica in caso di errore, modalità sicura |
| Multilingua | Singolo sito: lingua per contenuto, gruppi di traduzioni, prefisso nella URL, hreflang, sitemap per lingua; menu e stringhe del tema per lingua (Fase 7) | Tassonomie nascoste (come Polylang); blocco selettore renderizzato lato server; menu e testi per lingua nella option e nei filtri del core, senza tabelle |
| EasyRankly Pro (plugin a parte) | Agente AI con proposte da approvare e lavoro operativo di un SEO di agenzia: Search Console, parole chiave e cluster, link interni, contenuti, report | Plugin separato e autonomo, fuori da WordPress.org; usa solo ciò che il gratuito espone già (sezione 6) |

**Fuori perimetro**, con il motivo:

| Esclusa | Perché |
|---|---|
| Log dei 404, contatori di visite | Scrivono nel DB a ogni visita e rompono la cache; Search Console fornisce i 404 visti da Google |
| Punteggio SEO / analisi di leggibilità in tempo reale | Molto JS, valore discutibile; le proposte dell'agente del Pro fanno meglio lo stesso lavoro |
| Indice dei link in tabella | Nel Pro la mappa dei link è una tassonomia nascosta |
| Sitemap news e video, form, pulizia "bloat" | Fuori dalla direzione del prodotto |
| Funzioni multisite | Decisione esplicita: nessun codice dedicato, tranne la pulizia di ogni sito in `uninstall.php` |
| Google Indexing API | Google la consente solo per offerte di lavoro e dirette |
| IndexNow | Decisione del 9 ottobre 2026: non interessa. Le sitemap del core bastano per la scoperta dei contenuti |
| Pubblicazione automatica | Rischio "scaled content abuse": i contenuti AI nascono bozze |

### Interfaccia admin: stile WordPress classico

Fatta con la Fase 4 (decisione del 9 ottobre 2026). Le prime schermate erano app React con `@wordpress/components`:
una pagina di impostazioni lunga, a schede grandi, con un aspetto diverso dal resto dell'admin. Oggi:

- **Impostazioni su pagine brevi**, come Impostazioni → Generali, Lettura, Permalink: *Generali* (separatore del
  titolo, breadcrumb), *Schema e social* (identità: organizzazione, Local Business o persona; immagine social, utente
  X), *Titoli e descrizioni* (template per contesto, una lingua per volta), *Indicizzazione* (noindex per contesto,
  pagina articoli, regole di robots.txt), *Lingue* (tabella delle lingue e tabella Strings).
- **Settings API del core**: `add_settings_section()`, `add_settings_field()`, `settings_fields()`, form verso
  `options.php`, markup `form-table`, `submit_button()`. Nessun JS tranne la scelta di un'immagine. Resta una sola
  option, `easyrankly_settings`: ogni pagina invia solo i suoi campi e `Settings::sanitize()` li unisce a quelli
  salvati. `/wp/v2/settings` resta com'è.
- **Redirect e Custom code** come Articoli e Utenti: `WP_List_Table` (azioni di riga, ricerca, paginazione, azioni di
  gruppo) e una schermata "Aggiungi" / "Modifica" con un form inviato alla schermata stessa (nonce, capability), che
  salva tramite le route REST con `rest_do_request()`. Il codice degli snippet usa l'editor del core
  (`wp_enqueue_code_editor()`), che rispetta la preferenza dell'utente di spegnere l'evidenziazione.
- **Editor a blocchi e schermate dei termini**: il pannello nell'editor usa i componenti che l'editor carica comunque;
  i campi dei termini sono PHP classico.
- **CSS**: un solo foglio di stile, `assets/admin.css` (meno di 4 KB, nessun JS), che veste il markup del core sulle
  schermate del plugin: pagina larga al massimo 760px, titolo della sezione grigio e maiuscolo, box bianco, etichetta
  sopra il campo, ogni campo largo quanto il box, checkbox come interruttori on/off (12 ottobre 2026). `composer architecture`
  impedisce altri fogli, CSS inline e regole fuori dal wrapper `.easyrankly-admin` (CLAUDE.md, "Stile admin"). Il
  plugin non accoda `wp-components` sulle sue schermate (il core lo carica comunque in tutto l'admin per la palette
  dei comandi). Gli entry point `settings`, `redirects` e `snippets` sono spariti da `package.json`.

## 4. Dove vivono i dati

| Dato | Dove |
|---|---|
| Impostazioni | Una option autoload `easyrankly_settings` |
| SEO per contenuto | Post meta e term meta registrati con schema |
| Redirect, snippet | Post type non pubblici, con le loro cache in option autoload |
| Lingue | Option + tassonomia `erankly_language` |
| Gruppi di traduzione | Tassonomia `erankly_translation` |

L'elenco completo, chiave per chiave, è `docs/data-model.md`. I dati del Pro li registra e li rimuove il Pro: sono
nel suo piano (sezione 4) e nel suo `docs/data-model.md`.

## 5. Sicurezza

Le regole per chi scrive codice stanno in `CLAUDE.md` (sezioni "Sicurezza" e "Custom code"); il piano del controllo
completo, area per area, in `docs/sicurezza.md`; il controllo è la Fase 8 della roadmap, da chiudere prima della
2.0.0 (decisione del 12 ottobre 2026). Il repository è pubblico: i problemi trovati e non ancora corretti restano in
note private fuori dal repository, e la PR di correzione descrive il rinforzo senza la ricetta d'attacco.

### Chi può attaccare

| Attore | Cosa può fare oggi in WordPress |
|---|---|
| Visitatore anonimo | URL, query string, ricerca, intestazioni, commenti |
| Iscritto, collaboratore, autore | Scrivere i propri contenuti e i loro meta, usare la REST API autenticata |
| Editor | Contenuti di tutti, spesso `unfiltered_html` su sito singolo |
| Admin di un sito in multisite | `manage_options` ma **né** `unfiltered_html` **né** `edit_plugins` |
| Admin con `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` / `DISALLOW_UNFILTERED_HTML` | Admin volutamente limitato dal proprietario del sito |
| Chi fornisce un file WXR da importare | Contenuto arbitrario dentro l'importer del core |
| Un altro plugin o una SQL injection altrove | Scrittura diretta di option e post |

### Cosa va protetto, in ordine

1. **Esecuzione di PHP** (snippet PHP, `eval` in `PhpRunner.php`): equivale al controllo totale del server.
2. **HTML arbitrario in ogni pagina** (snippet HTML): equivale a XSS persistente su tutto il sito.
3. **Redirect**: redirect aperti, phishing, blocco del sito (loop, ReDoS).
4. **Uscita nel `<head>`** (titoli, meta, Open Graph, JSON-LD, hreflang, canonical): XSS riflessa o persistente.
5. **Dati non pubblici** (bozze, contenuti privati o protetti da password, codice degli snippet) esposti via REST,
   sitemap o meta.
6. **Disponibilità**: un visitatore non deve poter spegnere funzioni o rompere pagine.

### La regola di fondo per gli snippet

> Può far girare PHP solo chi potrebbe già farlo modificando un plugin dall'editor del core (`edit_plugins`).
> Può stampare HTML grezzo solo chi ha già `unfiltered_html`.

Ogni strada che permette di scendere sotto questa soglia è un problema. Un admin che rispetta la soglia e scrive
codice dannoso non è una vulnerabilità: è la funzione.

## 6. EasyRankly Pro: il contratto con il gratuito

EasyRankly Pro è un plugin a pagamento con licenza annuale, venduto fuori da WordPress.org come raccomandano le sue
[linee guida](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) per il codice a
pagamento: agente AI con proposte da approvare, Search Console, parole chiave (DataForSEO), link interni, contenuti,
report. Richiede il gratuito (`Requires Plugins: easyrankly`, supportato dal core dalla 6.5). Il suo progetto
(agente, modello, prezzi, licenza, backend, pagamenti) e la sua roadmap vivono nel repository privato
`easyrankly-pro`, branch `main`: `docs/piano.md` e `docs/roadmap.md`. Fino al 12 ottobre 2026 erano le sezioni 5 e 6
di questo file.

Il gratuito resta completo: nessuna funzione bloccata, nessun riferimento al Pro nel codice, nessun punto di
estensione scritto per lui (decisione del 9 ottobre 2026; i due della Fase 5, `easyrankly_agent_actions` ed
`easyrankly_create_proposal()`, sono stati tolti con l'agente). Il collegamento è il minimo, e usa solo ciò che il
gratuito espone già per sé:

- il Pro aggiunge le sue pagine come sottomenu di EasyRankly (slug `easyrankly`);
- legge e scrive i meta registrati con schema (anche via REST);
- legge e scrive i redirect con il post type `erankly_redirect` e la route `/wp/v2/easyrankly-redirects`, che
  applica le stesse regole dell'admin.

Questi nomi sono un contratto: cambiarli nel gratuito rompe il Pro, quindi si cambiano solo con una decisione. Un test
del Pro (sua Fase 0.5) li verifica contro il gratuito. Se allargare il contratto con `easyrankly_settings` e le
tassonomie del multilingua è la decisione aperta 6 di `docs/decisioni.md`.
