# EasyRankly

Plugin SEO per WordPress: leggero, senza moduli, costruito solo su API native.
Funzioni: SEO (meta, robots, canonical, social, schema), Redirect, Sitemap, Custom code (HTML e PHP),
Multilingua, Agente AI con proposte da approvare.

Queste regole valgono per ogni modifica, anche minima. Se una richiesta le viola, fermati e
spiega quale regola viene violata e quale alternativa nativa esiste. Non aggirarle.

Documenti di riferimento (tutti in questo branch):
- `docs/piano.md`: visione del prodotto e decisioni prese, con il loro perché. Leggilo prima di proporre funzioni.
- `docs/roadmap.md`: fasi, cosa è fatto e cosa no. È la fonte di verità su cosa fare dopo.
- `docs/data-model.md`: ogni dato salvato dal plugin. Se non è lì, il plugin non lo crea.
- `docs/prompt.md`: come si avvia e si chiude il lavoro su un punto della roadmap.

Il vecchio EasyRankly vive nel branch `Alpha` di questo stesso repository ed è **solo un riferimento**: leggilo per
capire la logica, poi riscrivi secondo queste regole. Non copiare file, tabelle, job o strutture a moduli.
Per leggerlo senza cambiare branch:

```bash
git fetch origin Alpha
git show origin/Alpha:includes/canonical.php        # un file
git ls-tree -r --name-only origin/Alpha includes/   # elenco dei file
```

## Branch e ambienti

- `Refactory` è il branch del nuovo plugin ed è autonomo: non dipende da file fuori dal repository.
- Ogni sessione lavora su un branch proprio e apre una pull request verso `Refactory` (mai verso `main`, che contiene
  il vecchio plugin). La CI gira su ogni pull request.
- **Cloud (Claude Code)**: all'avvio, `tools/cloud-setup.sh` (hook `SessionStart` in `.claude/settings.json`) installa
  dipendenze Composer, MariaDB e la suite di test di WordPress. Se all'avvio vedi un messaggio `[cloud-setup] ... failed`,
  risolvi quello prima di tutto il resto.
- **Locale**: il sito di sviluppo è in WordPress Studio; i comandi WP-CLI si lanciano come `studio wp ...` dalla
  radice del sito.

## Invarianti architetturali (non negoziabili)

`composer architecture` (`tools/check-architecture.php`) verifica in automatico le regole 1, 2, 3 e 5, più `$wpdb`,
`eval`, scritture su disco e la guardia di accesso diretto. Una violazione blocca la CI. Le regole 4, 6, 7 e 8
non si possono verificare in automatico: rispettale tu e controllale in review.

1. **Zero tabelle custom.** Mai `CREATE TABLE`, `dbDelta()`, `ALTER TABLE`.
   I dati vanno in: Options API, post meta, term meta, user meta, post type non pubblici, tassonomie nascoste.
2. **Zero cron.** Mai `wp_schedule_event()`, `wp_schedule_single_event()`, Action Scheduler o code di job.
   Il lavoro lungo si fa a lotti guidati dall'admin via REST: idempotente, riprendibile,
   con il cursore salvato in un'option.
3. **Zero script e fogli di stile nel frontend.** Nessun `wp_enqueue_script()`/`wp_enqueue_style()` fuori
   dall'admin, nessun `viewScript`/`viewScriptModule` nei `block.json`, nessun JS inline.
   Unica eccezione: `<script type="application/ld+json">` per lo schema (sono dati, non codice).
   Il codice che l'utente inserisce in Custom code è suo, non nostro.
4. **Zero chiamate HTTP nel frontend.** Le richieste remote partono solo da admin, REST o WP-CLI.
5. **Zero dipendenze PHP a runtime.** `vendor/` contiene solo strumenti di sviluppo e non viene distribuito.
   In JS sono ammessi solo i pacchetti `@wordpress/*`.
6. **Nessun sistema a moduli.** Niente registri di moduli, attivazione per modulo o "addon interni".
   Le impostazioni possono spegnere un comportamento, ma il codice resta un plugin unico.
7. **L'AI non scrive mai direttamente sul sito.** Ogni modifica passa da una proposta approvata da un utente
   (vedi "Agente AI").
8. **Nessuna funzione fuori perimetro** (vedi "Perimetro"). Proporla all'utente, non implementarla.

## API native: usa sempre queste

Prima di scrivere codice, verifica se WordPress lo fa già. Se lo fa, usa o filtra il core.

| Bisogno | API da usare | Da non usare |
|---|---|---|
| Impostazioni | `register_setting()` con `type`, `default`, `sanitize_callback`, `show_in_rest` (schema) | option non registrate, array serializzati a mano |
| Dati per contenuto | `register_post_meta()` / `register_term_meta()` con schema, `single`, `auth_callback` | meta box con `$_POST` grezzo |
| Elenchi di record (redirect, snippet, proposte, memoria) | post type non pubblici + `WP_Query` | `$wpdb` diretto |
| Stati di un record | `register_post_status()` | meta "status" fatti a mano |
| Raggruppamenti (lingue, traduzioni, cluster di parole chiave) | tassonomie nascoste | tabelle o meta di relazione |
| Title | `pre_get_document_title`, `document_title_parts` | output buffering di `<title>` |
| Robots | filtro `wp_robots` | `<meta name="robots">` stampato a mano |
| Canonical | `get_canonical_url` | rimozione e riscrittura di `rel_canonical` |
| robots.txt | filtro `robots_txt` | file fisico |
| Sitemap | sitemap del core (`wp_sitemaps_*`, `WP_Sitemaps_Provider`) | generatore XML proprio |
| Breadcrumb | blocco core `core/breadcrumbs` + `block_core_breadcrumbs_items` | blocco breadcrumb proprio |
| Cambio slug dei post | `wp_old_slug_redirect()` del core | redirect duplicati per i post |
| HTTP verso terzi | `wp_safe_remote_get/post()` (URL dell'utente), `wp_remote_*()` (host fissi) | cURL, `file_get_contents()` |
| Cache | `wp_cache_*()` (gruppo `easyrankly`), transient per dati remoti | cache su file, variabili statiche globali |
| Endpoint | `register_rest_route()` con `permission_callback` e `args` con schema | `admin-ajax.php` |
| Azioni riusabili (UI, AI, agenti) | Abilities API (`wp_register_ability()` su `wp_abilities_api_init`, annotazioni `readonly`/`destructive`) | logica duplicata tra REST e UI |
| AI (testo, immagini, function calling) | `wp_ai_client_prompt()` + `using_abilities()`, controllando prima `wp_supports_ai()` | SDK o chiamate dirette ai provider |
| Credenziali di servizi esterni | Connectors API (`wp_connectors_init`) | campi API key fatti a mano |
| UI admin | `@wordpress/components`, `@wordpress/dataviews`, pannelli dell'editor (`PluginDocumentSettingPanel`) | jQuery, librerie UI esterne |
| Build JS | `@wordpress/scripts` (`wp-scripts build`), dipendenze da `*.asset.php` | bundler custom |
| Traduzioni | `__()`, `_x()`, `_n()`, `wp_set_script_translations()` | stringhe fisse |

## Sicurezza

- Ogni file PHP inizia con `defined( 'ABSPATH' ) || exit;` (tranne `uninstall.php`, che controlla `WP_UNINSTALL_PLUGIN`).
- **Capability, sempre**: impostazioni `manage_options`; dati di un contenuto `edit_post` / `edit_term`
  sull'oggetto specifico; snippet HTML `unfiltered_html`; snippet PHP `edit_plugins`.
  Mai controllare solo `is_admin()`.
- **REST**: `permission_callback` esplicito su ogni route (mai `__return_true` su route che scrivono o leggono dati privati);
  `args` con `type`, `sanitize_callback`, `validate_callback`.
- **Input**: sanitizza all'ingresso con la funzione più stretta possibile (`absint`, `sanitize_key`,
  `sanitize_text_field`, `esc_url_raw`, `rest_sanitize_boolean`, schema). Mai `$_GET`/`$_POST`/`$_REQUEST` senza
  `wp_unslash()` e sanitizzazione; nei form classici verifica il nonce.
- **Output**: escape il più tardi possibile (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
  JSON-LD con `wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )`.
- **SQL**: se mai servisse `$wpdb` (solo in un file in allowlist, con commento che spiega perché le API non bastano),
  sempre `$wpdb->prepare()` con `%i` per gli identificatori.
- **Redirect**: destinazioni interne con `wp_safe_redirect()`; destinazioni esterne solo se create da un utente
  con `manage_options`, validate con `wp_http_validate_url()`. Proteggi dai loop (sorgente ≠ destinazione,
  massimo un salto per richiesta).
- **Segreti**: token e chiavi mai nel JS, nelle risposte REST, nei log o negli export. Nella UI mostrali mascherati.
  I token OAuth si cifrano con `sodium_crypto_secretbox()` usando una chiave derivata da `wp_salt( 'auth' )`.
- **Vietati**: `eval`, `create_function`, `extract`, `unserialize()` su dati esterni, `$_SERVER` non sanitizzato,
  include di percorsi dinamici, scrittura di file su disco.
  Unica eccezione: `eval` in `src/CustomCode/PhpRunner.php`, con le regole della sezione "Custom code".
- **SSRF**: URL forniti dall'utente solo con `wp_safe_remote_*()`.

## Custom code (HTML e PHP)

- Gli snippet sono un post type non pubblico `erankly_snippet` (tipo `html` o `php`, posizione, priorità, attivo).
  Le revisioni native fanno da cronologia del codice.
- Gli snippet HTML richiedono `unfiltered_html`. Gli snippet PHP richiedono `edit_plugins`: sono quindi disattivati
  in automatico con `DISALLOW_FILE_EDIT`.
- Prima del salvataggio il PHP si valida con `token_get_all( $code, TOKEN_PARSE )`, senza eseguirlo.
  Uno snippet con errori di sintassi non si salva come attivo.
- In esecuzione ogni snippet PHP gira in `try { } catch ( \Throwable )`: al primo errore lo snippet si disattiva da solo,
  l'errore viene salvato sullo snippet e l'admin vede un avviso.
- Modalità sicura: con la costante `EASYRANKLY_SAFE_MODE` nessuno snippet viene eseguito. Mai attivabile da URL.
- Gli snippet attivi stanno in cache in un'unica option autoload, aggiornata al salvataggio: zero query nel frontend.
- Gli snippet importati arrivano sempre disattivati. L'AI non può mai creare, proporre o modificare snippet.

## Prestazioni

- **Percorso caldo (frontend)**: nessuna query SQL in più rispetto a WordPress senza plugin.
  I meta del contenuto sono già in cache con l'oggetto interrogato; le impostazioni stanno in **una** option
  autoload (`easyrankly_settings`). Ogni query aggiuntiva va motivata e messa in cache in `wp_cache` con invalidazione esplicita.
- **Redirect**: la ricerca avviene solo su `template_redirect` quando `is_404()`, più una lista piccola di
  "redirect forzati" nell'option autoload. Ricerca per hash della sorgente normalizzata in `post_name` (colonna indicizzata).
- **Autoload**: solo dati piccoli e letti a ogni richiesta. Tutto il resto con `autoload = false`.
- **Caricamento pigro**: il codice admin si carica solo in admin; gli asset admin solo sulle schermate che li usano
  (controlla `get_current_screen()` / hook suffix).
- **Niente lavoro su `init`** oltre alle registrazioni (post type, tassonomie, meta, rewrite).
- **Niente `flush_rewrite_rules()`** a ogni richiesta: solo su attivazione o cambio di impostazioni che lo richiedono.
- **Dati remoti** (Search Console, DataForSEO, AI): sempre in transient con scadenza; mai richiesti in modo sincrono
  al caricamento di una pagina admin che non li mostra.
- Verifica con Query Monitor prima di chiudere un task che tocca il frontend.

## PHP: stile e struttura

- PHP minimo 8.1, WordPress minimo 7.0. Namespace `EasyRankly\`, autoload PSR-4 senza Composer (`src/autoload.php`).
- Standard: WordPress Coding Standards (`WordPress-Extra`, `WordPress-Docs`), PHPCompatibilityWP, PHPStan livello 8
  con `szepeviktor/phpstan-wordpress`. Zero errori, zero `phpcs:ignore` senza motivazione nella stessa riga.
- Una funzionalità = una cartella in `src/` con una classe che espone `register(): void`.
  Il bootstrap (`easyrankly.php`) crea `Plugin` e chiama `register()`; `Plugin::register()` chiama il `register()`
  di ogni funzionalità, una riga ciascuna.
- Gli hook si agganciano dentro `register()` o dentro un callback agganciato da `register()` (es. `load-{$hook}`
  dentro `admin_menu`). Mai a livello di file, mai nel costruttore: `composer architecture` lo verifica.
- Il codice che usa asset admin sta in una cartella `Admin/` (es. `src/Redirects/Admin/`): è l'unico posto dove
  `composer architecture` permette `wp_enqueue_script()` e `wp_enqueue_style()`.
- Niente singleton, niente service container, niente astrazioni "per il futuro". Tre righe ripetute sono meglio
  di un'astrazione prematura.
- **Callback degli hook**: WordPress passa spesso stringhe dove ti aspetti interi. Nei callback usa parametri
  `mixed` o non tipizzati e converti esplicitamente; i tipi rigidi vanno nei metodi interni.
- Prefissi: option `easyrankly_`, meta `_easyrankly_` (protetti), hook `easyrankly_`, gruppo cache `easyrankly`,
  post type e tassonomie `erankly_` (max 20 caratteri).
- Ogni hook pubblico che esponiamo (`apply_filters`/`do_action`) ha un docblock con `@since` e `@param`. Aggiungerne
  uno è una promessa di compatibilità: fallo solo se serve.
- Codice, commenti e stringhe sorgente in inglese; stringhe traducibili con text domain `easyrankly` e commento
  `translators:` quando contengono segnaposti.

## Dati e ciclo di vita

- Ogni option, meta, post type e tassonomia è registrato in un unico punto e documentato in `docs/data-model.md`.
- Versione dello schema dati in `easyrankly_db_version`. Le migrazioni girano su `admin_init` se la versione è
  vecchia, sono idempotenti e a lotti (vedi invariante 2).
- `uninstall.php` rimuove **tutto** ciò che il plugin ha creato sul sito. Un test lo verifica.
- **Multisite: nessuna funzione dedicata, ma nessun conflitto.** Niente impostazioni di rete, niente codice che
  cicla sui siti, niente `*_site_option()`, niente `switch_to_blog()`. Usando solo API per sito (`get_option()`,
  meta, post type) il plugin funziona da solo su ogni sito del network senza codice aggiuntivo.
- Pulizia senza cron: proposte rifiutate o superate più vecchie di 90 giorni si eliminano a lotti all'apertura
  della dashboard.

## Servizi esterni e AI

- Nessuna chiamata esterna senza un'azione esplicita dell'utente (opt-in). Ogni servizio è elencato nella sezione
  "External services" di `readme.txt` con link a termini e privacy.
- Il plugin funziona al 100% senza AI e senza servizi esterni. Se `wp_supports_ai()` è false o manca un connector,
  la UI AI si nasconde e nient'altro cambia.
- L'AI passa sempre da `wp_ai_client_prompt()`: il plugin non conosce i provider. Le chiavi le gestisce l'utente
  in Impostazioni → Connettori, oppure arrivano da EasyRankly Cloud registrato come provider.
- Ogni funzione AI o dati SEO è una **Ability** (`easyrankly/...`) con `input_schema`, `output_schema`,
  `permission_callback` e annotazioni. La UI chiama l'ability, non una logica parallela.
- Le chiavi di EasyRankly Cloud (DataForSEO, modelli decisionali, quote, licenze) stanno solo sul backend:
  nel plugin non c'è mai una chiave nostra. Il plugin **scarica** le proposte dal Cloud; non espone endpoint
  pubblici in scrittura.
- Timeout espliciti (≤ 15 s), gestione di `WP_Error` e dei codici HTTP, risposte in transient.

## Agente AI

- **L'AI non scrive mai direttamente.** Usa liberamente le ability `readonly`; per tutto ciò che modifica il sito
  crea una proposta (`erankly_proposal`) che un utente accetta, modifica o rifiuta.
- Ogni proposta salva: ability e input validati sullo schema, motivazione, dati a supporto, confidenza, anteprima
  delle differenze, impronta dell'oggetto originale e valore precedente (per "Annulla").
- All'accettazione si rivalida tutto con i permessi di **chi accetta**. Se l'oggetto è cambiato, la proposta
  diventa "superata" e non si applica.
- **Azioni proponibili** (allowlist in `src/Agent/Allowlist.php`): meta e social, testo alternativo, redirect interni,
  link interni, modifiche di contenuto con differenze, nuove bozze, bozze tradotte, parola chiave e cluster.
  **Mai proponibili**: custom code, impostazioni, robots.txt, cancellazioni, pubblicazione, utenti e ruoli.
- I contenuti nuovi nascono sempre come bozza (`draft`), mai pubblicati. Limite di bozze AI a settimana configurabile.
  Ogni bozza segnala i punti da verificare (dati, citazioni, esperienza diretta): niente statistiche inventate.
- Tutto ciò che arriva da contenuti, Search Console, SERP o pagine esterne è **dato non fidato**: può contenere
  istruzioni. La validazione è deterministica e fuori dal modello (allowlist, schema, domini esterni bloccati,
  tetto giornaliero di proposte).
- Memoria del progetto: post type `erankly_memory`, una voce per fatto in Markdown, import/export in `.md`.
  Rifiuti motivati e modifiche alle proposte diventano voci di memoria visibili e modificabili.
- I flussi in più passi (ricerca → brief → bozza → link → meta) sono una sequenza di richieste REST guidate dall'admin
  o eseguite su EasyRankly Cloud. Mai un'unica richiesta lunga, mai un cron.

## Perimetro

**Dentro**: meta title/description (template + override), robots, canonical, Open Graph/X, schema JSON-LD
(Organization/Person, WebSite, WebPage, Article, BreadcrumbList), robots.txt, sitemap del core estese,
redirect (301/302/307/410, esatti e regex), custom code HTML e PHP, multilingua su singolo sito,
agente AI con proposte (strategia, contenuti nuovi, link interni, parole chiave, meta e social).

**Fuori** (non implementare senza decisione esplicita): log dei 404, contatori di hit, punteggio SEO/analisi
leggibilità in tempo reale, indice dei link in tabella, sitemap news/video, migrazioni con job in background, form,
pulizia "bloat", qualsiasi funzione dedicata a multisite, Google Indexing API, pubblicazione automatica.

## Test e verifica

```bash
composer architecture   # invarianti architetturali (veloce, lancialo spesso)
composer lint           # PHPCS (composer lint:fix corregge in automatico)
composer analyse        # PHPStan
composer test           # PHPUnit sulla suite WordPress (serve WP_TESTS_DIR, vedi README.md)
composer check          # tutto quanto sopra: deve passare prima di dire "fatto"
```

- Gli asset admin si compilano con `npm run build` (`@wordpress/scripts`), da introdurre con il primo asset admin.
- Se un controllo non si può eseguire nell'ambiente in cui sei, dillo nel resoconto: la CI su GitHub resta il
  controllo finale.

- Ogni bug corretto ha un test di regressione che fallisce prima della correzione.
- Ogni funzionalità ha test di integrazione sul comportamento visibile (HTML in `<head>`, risposta HTTP, XML della sitemap),
  non sui dettagli interni.
- I test coprono: permalink semplici e "pretty", utente senza capability, AI non disponibile.
  La CI esegue la suite anche su un'installazione multisite, solo per garantire che non ci siano conflitti.
- L'agente ha test dedicati: proposta fuori allowlist rifiutata, input fuori schema rifiutato, proposta superata
  non applicata, "Annulla" che ripristina il valore precedente, istruzioni iniettate nei contenuti ignorate.

## Definizione di "fatto"

Un task è finito solo quando:
1. `composer check` passa (e `npm run build`, se ci sono asset admin) senza errori né warning nuovi.
2. Le invarianti architetturali sono rispettate (nessuna eccezione aggiunta all'allowlist senza approvazione).
3. Il comportamento visibile è coperto da test di integrazione (HTML in `<head>`, risposte HTTP, XML della sitemap).
   In locale verificalo anche sul sito Studio; nel cloud i test di integrazione sono la verifica.
4. `docs/data-model.md` e `readme.txt` sono aggiornati se cambiano dati o servizi esterni; `uninstall.php` e
   `tests/UninstallTest.php` coprono ogni nuovo dato.
5. La casella del punto in `docs/roadmap.md` è spuntata.
6. Nel resoconto dici cosa hai verificato e cosa no.

## Modo di lavorare

- Leggi il codice esistente prima di scrivere: imita naming, struttura e densità dei commenti.
- Cambia il minimo necessario. Niente refactor, rinomine o "migliorie" non richieste nello stesso task.
- Prima di aggiungere una dipendenza, un hook pubblico, un'option, un meta o un'azione all'allowlist dell'agente: chiedi.
- Messaggi di commit in italiano, all'indicativo presente, che spiegano il perché (es. "Sposta il lookup dei redirect su 404 per non interrogare il DB a ogni pagina").
- Se un'istruzione in questo file è in conflitto con la richiesta dell'utente, segnalalo invece di scegliere da solo.
