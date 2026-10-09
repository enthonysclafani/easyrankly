# Piano del controllo di sicurezza di EasyRankly (gratuito)

Versione del 9 ottobre 2026, scritta su `Refactory` dopo i requisiti minimi (#42, #43). Il controllo parte dalla
testa di `Refactory` del giorno in cui inizia.

Questo documento è il **piano**: dice cosa controllare, come e in che ordine. Il controllo vero si fa dopo,
punto per punto, e ogni problema trovato diventa una PR separata verso `Refactory` con il suo test di regressione.

---

## 1. Perimetro

**Dentro:** tutto il plugin gratuito: `easyrankly.php`, `src/` (~9.000 righe), `uninstall.php`, `blocks/`,
`assets/` (JS dell'admin), workflow CI. Priorità assoluta: `src/CustomCode/` (snippet HTML e PHP).

**Fuori:** EasyRankly Pro (repository separato), il core di WordPress, i temi, la configurazione del server.
Le dipendenze di sviluppo (`vendor/`, `node_modules/`) si guardano solo per capire se finiscono nello zip
distribuito (non devono).

## 2. Modello di minaccia

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
5. **Dati non pubblici** (bozze, contenuti privati, codice degli snippet) esposti via REST o sitemap.
6. **Disponibilità**: un visitatore non deve poter spegnere funzioni o rompere pagine.

### La regola di fondo per gli snippet

> Può far girare PHP solo chi potrebbe già farlo modificando un plugin dall'editor del core (`edit_plugins`).
> Può stampare HTML grezzo solo chi ha già `unfiltered_html`.

Il controllo cerca **ogni strada** che permetta di scendere sotto questa soglia. Un admin che rispetta la soglia e
scrive codice dannoso non è una vulnerabilità: è la funzione.

---

## 3. Priorità 1: Custom code (snippet HTML e PHP)

File: `src/CustomCode/CustomCode.php`, `Runner.php`, `PhpRunner.php`, `RestController.php`, `Admin/Page.php`,
più `src/Admin/RecordsPage.php` e `RecordsTable.php` che la schermata usa.

### CC-01. Matrice dei permessi

Per ogni combinazione di **utente** × **ambiente** × **operazione** × **canale**, verificare cosa succede:

- Utenti: anonimo, iscritto, autore, editor, admin sito singolo, admin di sito in multisite, super admin,
  ruolo personalizzato con `manage_options` + `unfiltered_html` ma senza `edit_plugins`.
- Ambiente: normale, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `DISALLOW_UNFILTERED_HTML`, `EASYRANKLY_SAFE_MODE`.
- Operazioni su HTML e PHP: crea, leggi, modifica, attiva, disattiva, elimina, cambia tipo, cambia posizione.
- Canali: schermata admin, REST (`/wp/v2/easyrankly-snippets`), XML-RPC (`wp.newPost`, `wp.editPost` con
  `custom_fields`), WP-CLI (`wp post create/update`, `wp post meta update`), importer WXR, `wp_insert_post()` da codice.

Risultato atteso: una tabella con l'esito di ogni casella, e un test PHPUnit con data provider che la rende
permanente (in `tests/CustomCode/`). Ogni casella "sì" dove la regola di fondo dice "no" è un problema critico.

### CC-02. Tutte le strade di scrittura del codice

`validate_rest()` controlla solo la REST. Le altre strade contano su `map_meta_cap()`, `guard_type()` e sul filtro
della sintassi in `rebuild_cache()`. Da provare una per una:

- **Revisioni**: ripristinare una revisione di uno snippet PHP (`wp_restore_post_revision()`, endpoint REST delle
  revisioni, `revision.php`). Chi può farlo? Il contenuto ripristinato passa dalla validazione?
- **Autosalvataggi**: esiste `/wp/v2/easyrankly-snippets/{id}/autosaves`? Chi può crearne uno e cosa finisce in cache?
- **Meta via REST**: campo `meta` nel corpo della richiesta, anche su uno snippet esistente di altro tipo;
  `type` mancante sullo snippet (valore di default `html`) e poi aggiunto come `php`.
- **XML-RPC e WP-CLI**: creare uno snippet HTML e poi cambiargli il tipo; creare uno snippet senza meta `type` e
  aggiungerlo dopo; `wp post meta update` eseguito come utente senza `edit_plugins`.
- **Importer WXR**: snippet PHP in un WXR importato da un admin senza `edit_plugins`; `import_inactive()` copre anche
  la meta `type` aggiunta dopo l'inserimento?
- **Schermate del core**: `show_ui` è `false`; verificare che `post.php?post=ID&action=edit`, `edit.php?post_type=…`,
  modifica rapida e azioni di gruppo del core non siano raggiungibili.
- **Cestino e ripristino**: `untrashed_post` rimette attivo uno snippet PHP senza ricontrollare chi lo fa?
- **Plugin di terzi** (duplica articolo, editor di meta): elencare cosa scavalcano solo `validate_rest()` e cosa no.

### CC-03. L'option `easyrankly_snippets` come confine di fiducia

`Runner` esegue quello che trova nell'option autoload **senza ricontrollare** chi l'ha scritto. Quindi chi scrive
quell'option ottiene esecuzione di PHP. Da verificare:

- Strade del core che scrivono option arbitrarie: `wp-admin/options.php` (su sito singolo l'admin vede tutte le
  option; quelle serializzate dovrebbero essere bloccate: verificarlo), `/wp/v2/settings` (l'option non è registrata:
  confermarlo), WP-CLI `wp option update`, importatori di impostazioni di altri plugin.
- Un admin di sito in multisite o un ruolo senza `edit_plugins` ha una qualsiasi strada per scriverla?
- Rischio accettato da documentare: con accesso diretto al DB (SQL injection altrove) l'option diventa esecuzione di
  codice. È lo stesso rischio di `active_plugins` nel core, ma va scritto in `CLAUDE.md`.
- **Decisione da prendere** (vedi sezione 7): firmare il contenuto della cache con un HMAC basato su `wp_salt()` e
  rifiutare in `Runner` ciò che non torna. Costa poche righe e una funzione hash per richiesta.

### CC-04. Validazione della sintassi e errori a runtime

- `token_get_all( …, TOKEN_PARSE )` vede gli errori di parsing, non quelli di compilazione: funzione o classe già
  dichiarata, `declare(strict_types=1)` non in testa, `use` dentro `eval`, `__halt_compiler()`, `?>` seguito da HTML,
  `<?php` ripetuto. Per ognuno: cosa salva la REST, cosa succede alla prima esecuzione, lo snippet si spegne?
- Errori fatali che il `catch` non vede: memoria esaurita (la funzione di shutdown ha memoria per `wp_update_post()`?),
  tempo massimo, ricorsione infinita, `exit`/`die` nello snippet (pagina troncata, nessuno spegnimento),
  `ob_end_clean()`, `set_error_handler()` o `register_shutdown_function()` sovrascritti dallo snippet.
- `PhpRunner::$running`: se uno snippet ne include un altro tramite azioni annidate (`do_action('wp_head')` dentro uno
  snippet), viene spento lo snippet giusto?
- Più richieste in parallelo che falliscono insieme: scritture concorrenti, cache coerente alla fine?

### CC-05. Dove girano gli snippet

- Posizioni di pagina mai in `is_admin()`: verificare `admin-ajax.php`, `admin-post.php`, REST, `wp-login.php`, `wp-cron.php`, feed,
  embed (`/embed/`), anteprima del Customizer, iframe dell'editor del sito, sitemap XML, `robots.txt`.
- Un admin deve **sempre** poter rimediare a uno snippet rotto dall'admin. Provare uno snippet che rompe il frontend
  e uno che fa `wp_redirect()` di tutte le pagine: l'admin resta raggiungibile?
- Modalità sicura: solo dalla costante. Verificare che nessun parametro, cookie o intestazione la attivi o la spenga.
- Posizione `everywhere` (su `plugins_loaded`, ovunque tranne la schermata Custom code): verificare l'esclusione della
  schermata (anche il POST del form e la REST interna), che un HTML non ci arrivi da nessuna strada di scrittura e i
  problemi elencati in `docs/custom-code.md`.

### CC-06. Spegnimento automatico avviato da un visitatore

`disable()` scrive nel DB su una richiesta del frontend, spesso anonima. Da valutare:

- Un visitatore può spegnere di proposito uno snippet che lancia un'eccezione su certi input (es. legge `$_GET`)?
  È un modo per togliere al sito, ad esempio, il codice di tracciamento o di consenso cookie. Decidere se è accettabile.
- `kses_remove_filters()` / `kses_init()`: se `wp_update_post()` lancia un'eccezione, i filtri restano tolti per il
  resto della richiesta? Altri plugin agganciati a `save_post` girano in shutdown con i filtri tolti?
- `sanitize_text_field()` sul messaggio d'errore e `esc_html()` nell'avviso admin: il messaggio può contenere dati del
  visitatore (URL, input)? Finisce solo agli admin?

### CC-07. Lettura del codice degli snippet

Nessuno senza i permessi di modifica deve leggere il codice. Verificare: elenco e singolo REST, `?context=view`,
`?_embed`, `?_fields`, endpoint revisioni e autosalvataggi, `/wp/v2/search`, `/wp/v2/types`, feed, oEmbed, sitemap,
export WXR (cap `export`), `get_posts()` pubblico tramite query var (`?post_type=erankly_snippet&p=ID`),
`?p=ID&preview=true`.

### CC-08. Schermata admin degli snippet

- CSRF: salvataggio, eliminazione, attiva/disattiva singolo (GET con nonce), azioni di gruppo. Nonce legato all'ID?
- Il salvataggio passa da `rest_do_request()`: la REST interna non chiede il nonce `wp_rest`, quindi il nonce della
  schermata è l'unica difesa. Verificare che ogni ramo di `RecordsPage::load()` lo controlli **prima** di agire.
- Uscita: codice nella `<textarea>` (`</textarea>` dentro lo snippet), nome, messaggi `?message=`, impostazioni
  dell'editor di codice passate in JS inline con `wp_json_encode()`.
- `ids[]` nelle azioni di gruppo: ID di post di altri tipi vengono rifiutati?

### CC-09. Disinstallazione

`uninstall.php` elimina snippet, revisioni, meta e option. Verificare che non resti codice eseguibile
(option `easyrankly_snippets` inclusa) e che `UninstallTest.php` lo copra.

### CC-10. Revisione di WordPress.org (punto aperto in `docs/piano.md`)

Preparare il testo che giustifica gli snippet PHP per il team di revisione, basato sui risultati di CC-01…CC-09:
`eval` isolato in un file, soglia `edit_plugins`, rispetto di `DISALLOW_FILE_EDIT`, controllo della sintassi,
spegnimento automatico, modalità sicura, nessun codice remoto. Confronto con plugin già presenti nella directory
che fanno lo stesso (Code Snippets, WPCode). Lanciare Plugin Check **anche** su `PhpRunner.php` e annotare l'elenco
esatto dei messaggi, che la revisione vedrà.

---

## 4. Priorità 2: il resto del plugin

### RD. Redirect (`src/Redirects/`)

- **Redirect aperti**: le catture `$1` non devono cambiare l'host (c'è già un controllo in `Rule::match()`).
  Provare: catture con `@`, `:`, `//`, `\`, `%2F%2F`, caratteri Unicode, porta, userinfo, destinazione relativa
  `//evil.example`, schema `javascript:` / `data:`.
- **ReDoS**: `Rule::compile()` imposta limiti di backtracking. Provare pattern catastrofici salvati da un admin e
  URL lunghissime da un visitatore; il redirect "forzato" gira su **ogni** richiesta, non solo sul 404.
- **Header injection**: CR/LF nella destinazione (`wp_redirect()` li ripulisce: confermarlo).
- **Lettura**: `RestController` limita a `manage_options`; verificare revisioni, `_embed`, sitemap.
- **Hash in `post_name`**: collisioni o normalizzazioni diverse che fanno scattare la regola sbagliata.
- `SlugChanges.php`: chi può creare redirect indirettamente cambiando lo slug di un contenuto (un autore può creare
  un redirect da una URL che non è sua?).

### ML. Multilingua (`src/Multilingual/`)

- `Rest.php`: per ogni route, `permission_callback` contro i ruoli. In particolare: collegare un mio contenuto a uno
  che non posso modificare; copiare (`copy`) contenuti privati o bozze altrui; l'elenco dei candidati mostra titoli di
  contenuti privati?
- `Routing.php`: lettura di `REQUEST_URI`, `?lang=`, `rest_route`, redirect 301 (`wp_safe_redirect`), loop di redirect,
  cache poisoning se la lingua dipende da intestazioni.
- `TemplateParts.php` e `Menus.php`: lo slug della lingua finisce nel nome di una template part o di un menu: può
  contenere `../` o caratteri che portano a file o parti di altri temi?
- `SiteIdentity.php`, `Switcher.php`, `Hreflang.php`, `HomesSitemap.php`: escaping di nomi, URL e attributi del blocco.
- `Translations.php`: contenuti di altre lingue in bozza o privati che compaiono in hreflang o nel selettore.

### HD. Uscita nel `<head>` (`Titles`, `Meta`, `Social`, `Canonical`, `Robots`, `Schema`, `Breadcrumbs`)

- XSS riflessa: ricerca (`search_query`), paginazione, URL nel canonical, 404.
- XSS persistente: titoli e descrizioni scritti da un **collaboratore** nei meta (senza `unfiltered_html`), nomi dei
  termini, nome e motto del sito per lingua.
- JSON-LD: `JSON_HEX_TAG` è attivo; verificare `</script>`, `<!--`, U+2028/U+2029 in ogni campo, compresi quelli
  dalle impostazioni.
- Robots e canonical impostati da un autore: può mettere `noindex` o un canonical esterno su contenuti di altri, o
  sulla home? Decidere cosa è accettabile per ruolo.

### ST. Impostazioni (`src/Settings/`)

- Schema dell'option `easyrankly_settings` esposto in `/wp/v2/settings`: ogni campo ha `sanitize_callback` o schema
  stretto? Campi URL (profili social, logo), testo libero che finisce nel frontend.
- `RobotsTxt.php`: contenuto libero dell'admin in `robots.txt`; nessuna strada per un non admin.
- `Fields.php` `?language=`: uso del parametro senza controllo che la lingua esista.

### SM. Sitemap (`src/Sitemap/`)

Nessun contenuto privato, in bozza, protetto da password o `noindex`; nessun `erankly_snippet` o
`erankly_redirect`; nessun termine vuoto o di tassonomie nascoste; sitemap per lingua coerenti.

### AD. Admin generico e JS (`src/Admin/`, `assets/`, `blocks/`)

- Ogni schermata controlla la capability nel `load-…` e non solo nel menu.
- Ogni `phpcs:ignore` del plugin: fare l'inventario e giustificare ognuno (sono i punti in cui abbiamo detto
  "qui la regola non vale").
- JS dell'admin e blocchi: `dangerouslySetInnerHTML`, `innerHTML`, render lato server con attributi del blocco.

---

## 5. Metodo

1. **Statica.** Inventario dei punti sensibili con `rg` (`echo`, `printf`, `eval`, `wp_redirect`, `update_option`,
   `register_rest_route`, `$_GET`/`$_POST`/`$_SERVER`, `phpcs:ignore`). PHPCS con il ruleset
   `WordPress.Security` senza esclusioni. PHPStan livello 8 (gira in CI). Plugin Check sullo zip completo.
2. **Test automatici.** Ogni verifica che si può scrivere come test diventa un test PHPUnit nella cartella del modulo
   (`tests/CustomCode/`, `tests/Redirects/`, …): la matrice CC-01 per prima. Restano nel repository come guardia.
3. **Prove manuali sul sito Studio.** Quello che PHPUnit non vede bene: XML-RPC, importer WXR, multisite, costanti
   `DISALLOW_*`, errori fatali reali (memoria, tempo), Customizer ed editor del sito.
4. **Fuzzing leggero.** URL e catture per i redirect, URL con prefisso di lingua per il routing.

### Come si registra un problema

Per ogni problema: ID (es. `CC-02-a`), gravità (Critica, Alta, Media, Bassa, Informativa), chi può sfruttarlo,
passi per riprodurlo, correzione proposta. **Il repository è pubblico**: i problemi non ancora risolti restano
in note private fuori dal repository, mai in issue o PR pubbliche prima della correzione.
La PR di correzione descrive il rinforzo senza la ricetta d'attacco; il resoconto completo si pubblica dopo.

## 6. Ordine di lavoro

1. Custom code: CC-01 (matrice), CC-02 (strade di scrittura), CC-03 (option). È dove un problema vale di più.
2. Custom code: CC-04 … CC-09, poi il testo CC-10 per WordPress.org.
3. Redirect (RD) e uscita nel `<head>` (HD): sono raggiungibili dai visitatori anonimi.
4. Multilingua (ML): REST con controlli per contenuto, la parte più recente.
5. Impostazioni, sitemap, admin generico (ST, SM, AD).
6. Resoconto finale, aggiornamento di `CLAUDE.md` (rischi accettati) e di `docs/piano.md` (punto WordPress.org chiuso).

Ogni area può andare in un thread a sé; le correzioni sono PR piccole, una per problema, verso `Refactory`.

## 7. Decisioni per Enthony

1. **Firma della cache degli snippet (CC-03).** Aggiungere un HMAC con `wp_salt()` così che un'option scritta da
   fuori non venga eseguita, oppure accettare il rischio e documentarlo. Consiglio: aggiungerla, è poco codice.
2. **Spegnimento avviato dai visitatori (CC-06).** Accettarlo (lo snippet è difettoso comunque) o spegnere lo
   snippet solo per gli errori fatali e limitarsi a registrare le eccezioni. Consiglio: accettarlo e documentarlo.
3. **Strumenti esterni.** Usare solo quelli già presenti (PHPCS, PHPStan, Plugin Check) o aggiungere strumenti di
   analisi come Semgrep in CI (nuova dipendenza di sviluppo). Consiglio: solo quelli presenti.
4. **Dove vive questo piano.** Deciso il 9 ottobre 2026: il piano sta qui, in `docs/sicurezza.md`; i risultati
   restano in note private fuori dal repository finché i problemi non sono corretti (sezione 5).
