# Prontezza WordPress.org — EasyRankly 2.0.0

**Tipo:** aggiornamento di un plugin già nel Directory (non prima submission)  
**Scheda live:** [wordpress.org/plugins/easyrankly](https://wordpress.org/plugins/easyrankly) — versione **1.0.0**  
**Data `added` Directory (API 1.2, 9 settembre 2026):** 28 giugno 2026  
**Release date changelog 1.0.0:** 14 giugno 2026  
**Tree analizzato:** working copy locale 2.0.0, **non committata**  
**Data verifica di questa revisione:** avviata il 9 settembre 2026, conclusa il 10 settembre 2026; follow-up del 14 settembre 2026 sul delta non committato (report GPT-6, chiusura N1–N4, poi re-verifica PHP 8.0 / Multisite UI / revisione del tree)

## Verdetti (gate, non punteggio)

| Gate | Esito | Cosa significa |
| --- | --- | --- |
| Revisione indipendente del tree finale | **ESEGUITA — Cursor, non GPT-6** | Rilettura avversariale dei caller N1–N4 sul tree dopo la chiusura. Stesso lineage dell’implementazione: **non** sostituisce GPT-6. N1–N4 **RISOLTI** nei caller; restano P3 UI/`hasMore` e un P2 di tooling CI sul `composer.lock`. |
| Working tree (statica, packaging, test scritti) | **REVIEWED — residui tooling** | N1–N4 e i test associati sono nel working tree. `composer.lock` (untracked, solo dev) non è installabile su PHP 8.0/8.3. |
| Pubblicazione WordPress.org | **NOT READY** | Nessuna azione di release è stata eseguita o autorizzata. |
| PHPUnit PHP 8.0 / 8.3 / 8.5 / Multisite su MySQL | **ESEGUITO — locale Cursor** | PHP **8.0.30**, **8.3.33** e **8.5.10**, single-site e Multisite, vs MySQL `wordpress_test`. CI remota non lanciata. |
| Plugin Check sul ZIP N1–N4 | **ESEGUITO — Cursor; non ripetuto** | ZIP `59d651b7…` invariato dopo questa re-verifica: 0 ERROR / 8 WARNING. PCP non rieseguito perché SHA e inventario coincidono. |
| CI remota sul tree corrente | **BLOCKED — push + lockfile** | Nessun run remoto. In più `composer install` dal lock fallisce su PHP 8.0 e 8.3 (`doctrine/instantiator` 2.1.0 richiede `^8.4`). |
| ZIP installato e attivato in isolamento | **BLOCKED — ambiente isolato** | Lo ZIP è stato costruito e scansionato come sibling inattivo `easyrankly-pcp-scan`, poi rimosso. Working copy 2.0.0 resta il plugin attivo. |
| Multisite UI | **ESEGUITO — sito Studio separato** | `http://erankly-ms.local/` (non il sito primario `:8881`). N1/N2 sul network Schema. no-JS **non** esercitato. |
| no-JS browser | **BLOCKED — no-JS** | Non esercitato. |
| Asset marketing Directory | **BLOCKED — asset marketing opzionali** | SVN ha solo icone 128/256. Non è un blocco tecnico del codice. |

Non c’è un punteggio 0–100 né un “Pass 18/18”.

Questo file mescola **quattro livelli di prova**, da non confondere:

1. **Risultati riprodotti in questa sessione Cursor** (lint PHP 8.0.30 e 8.3.33, probe JS, build byte-identica SHA `59d651b7…`, PHPUnit 8.0.30 / 8.3.33 / 8.5.10 single-site e Multisite su MySQL `wordpress_test`, browser N1/N2 su Studio `localhost:8881` e sul sito Multisite `erankly-ms.local`). Plugin Check sul ZIP **non** è stato ripetuto: SHA e 117 file invariati.
2. **Ispezioni statiche** (guideline, mappa dei percorsi, lettura del codice, `composer install --dry-run` sul lock).
3. **Esiti di sessioni precedenti** (Plugin Check 2.1.0 sul ZIP `59d651b7…`; REST autosave `website_name`; smoke CLI). Non vanno riattribuiti come se GPT-6 li avesse riprodotti.
4. **Dichiarazioni o gate del developer / proprietario** (originalità, storia SVN completa, condotta, pubblicazione). GPT-6 **non** ha rieseguito questo follow-up. La rilettura N1–N4 è Cursor, stesso lineage dell’implementazione.

Non è una garanzia di approvazione del Plugin Review Team.

---

## Identità Git (immutabile in questa attività)

| Campo | Valore |
| --- | --- |
| Root Git | `wp-content/plugins/easyrankly` |
| Branch | `beta` tracking `origin/beta` |
| HEAD iniziale (9 settembre 2026) | `7e56cbf8149629a96498971857a850b6a4f5dcf7` |
| HEAD al follow-up N1–N4 | `08036d506ca2ec7434db9361afaeb9b22fe5fbda` |
| HEAD finale | `08036d506ca2ec7434db9361afaeb9b22fe5fbda` (nessun commit in questa attività) |
| Working tree alla chiusura N1–N4 | 41 file tracked modificati; untracked `composer.lock`, `tests/js/local-business-widget-probe.cjs`, `tests/test-local-business-rest.php`, `tests/test-uninstall-cleanup.php`; 0 file staged |
| `origin/main` | `e62476a03ae9876e83a556131f0fdccdbf632c91` |
| `git merge-base origin/main origin/beta` | exit 1 — **nessun merge base**. Non è stato tentato alcun riallineamento. |

Nessun commit, push, tag, SVN o release.

---

## Superfici da non confondere

1. **Working copy** — plugin attivo in Studio, HEAD invariato, tree sporco.
2. **Artefatto** — `.dist/easyrankly.zip` (gitignored), SHA-256 sotto; **non** attivato al posto della working copy.
3. **Suite PHPUnit** — scritta; eseguita in questa sessione Cursor su PHP 8.0.30 / 8.3.33 / 8.5.10 e MySQL `wordpress_test` (single-site e Multisite). Non è un run CI remoto né una riproduzione GPT-6.
4. **CI** — YAML configurato (single-site 8.0/8.3, Multisite 8.3, Plugin Check); **run remoto assente**. `composer.lock` corrente non è installabile su PHP 8.0/8.3.
5. **Browser** — 9 settembre 2026: caricamento schermate (non N1/N2). 14 settembre 2026: N1/N2 su Schema (`localhost:8881`), poi probe pages e mapping LocalBusiness ripristinati su quel sito. Stesso giorno: N1/N2 sul network Schema di `http://erankly-ms.local/` (sito Studio separato). **Non** è no-JS, **non** è Custom Code live.
6. **WordPress.org live / SVN** — 1.0.0.
7. **Release** — non autorizzata.

---

## Ambiente di verifica

| Strumento | Stato |
| --- | --- |
| WordPress Studio | 1.21.0 — sito primario `http://localhost:8881/` (single-site, **non** convertito). Sito Multisite di prova `http://erankly-ms.local/` in `~/Studio/easyrankly-ms`, WordPress **7.1**, PHP **8.4**, symlink al working copy, plugin network-activated |
| Database Studio | SQLite (entrambi i siti). PHPUnit **non** usa questi DB |
| Plugin Check | 2.1.0 usato in precedenza sul ZIP, poi disinstallato; **non** reinstallato in questa re-verifica |
| EasyRankly locale | 2.0.0 attivo. Primario: `is_multisite()=0`. `erankly-ms.local`: `is_multisite()=1` |
| WP-CLI | `studio wp` |
| Composer CLI / `mysql` | `vendor/bin/phpunit` e `mysql` presenti; PHP CLI **8.5.10** (`/opt/homebrew/bin/php`), **8.3.33** (`/opt/homebrew/opt/php@8.3/bin/php`), **8.0.30** keg-only (`/opt/homebrew/opt/php@8.0/bin/php`, tap `shivammathur/php`, **non** linkato come default) |
| Node / rsync / zip / ruby YAML | presenti |

---

## Differenze rispetto alla revisione indipendente del 9 settembre 2026

La revisione indipendente ha confermato lint, build SHA `9ded7388…`, Plugin Check 0 ERROR / 7 WARNING, license GPL, POT subtract pulito, e ha classificato come **non eseguiti** PHPUnit, Multisite, ZIP attivato, autosave, Custom Code live, autoreload attivo, CI remota. Ha inoltre trovato la tabella Guideline 6–17 associata ai requisiti sbagliati e checkmark della checklist precedente contraddetti dall’evidenza.

La remediation eseguita da Cursor:

- ha corretto report e checklist precedente;
- ha rafforzato i test REST/Custom Code/output e la lane CI Multisite;
- ha estratto `erankly_persist_settings_submission()` senza cambiare il comportamento del save di rete;
- ha rieseguito lint, POT, build (nuovo hash) e Plugin Check;
- ha esercitato il REST autosave su `website_name` e ripristinato l’option;
- **non** ha eseguito PHPUnit MySQL, **non** ha attivato lo ZIP, **non** ha fatto push.

La verifica indipendente finale:

- ha trovato che i test revocavano `unfiltered_html` sull'oggetto utente già in cache, perciò la capability restava effettivamente attiva; i test ora invalidano la cache e ricreano il current user;
- ha sostituito le asserzioni di output vuoto senza fixture con probe realmente memorizzati;
- ha sostituito l'ispezione statica di `REST_REQUEST` / `XMLRPC_REQUEST` con due test process-isolated che definiscono le costanti reali;
- ha eseguito uno smoke CLI reversibile di **34 check** su REST Custom Code ristretto, marker legacy, import e cap user-search; impostazioni ripristinate e 22 utenti temporanei rimossi;
- ha rieseguito lint, POT subtract, build riproducibile e le tre scansioni Plugin Check sul tree risultante;
- **non** ha potuto eseguire PHPUnit/MySQL o la CI remota e quindi mantiene il verdetto `BLOCKED`.

---

## Cosa è stato corretto (codice di questa sessione e precedenti)

### PHPUnit bootstrap

`tests/bootstrap.php` espone `erankly_tests_load_settings_sanitizer()` e `erankly_tests_set_accessible()` (chiama `ReflectionMethod::setAccessible()` solo se `PHP_VERSION_ID < 80100`). È un fix **solo test** per la deprecazione PHP 8.5; non entra nello ZIP. Il runtime di produzione non carica admin incondizionatamente. PHPUnit 8.0.30 / 8.3.33 / 8.5.10 vs MySQL `wordpress_test` è stato eseguito in questa sessione Cursor dopo N1–N4.

### CI (configurazione locale, non run remoto)

`.github/workflows/phpunit.yml`:

- PHP **8.0** e **8.3** single-site;
- PHP **8.3** Multisite (`WP_MULTISITE=1`), suite completa, più un passo che fallisce se i test `@group ms-required` sono skippati o assenti;
- job Plugin Check su zip con `wordpress/plugin-check-action@v1` (`build-dir`, `slug`, `wp-version: latest`, `include-experimental: false`, `ignore-warnings: true`, `ignore-errors: false`, `categories: plugin_repo`).

Input dell’Action verificati sulla documentazione pubblica di `wordpress/plugin-check-action` il 9 settembre 2026. YAML validato con `ruby -ryaml`.

### REST user search

Limite `number => 20`, niente email in JSON, ricerca server-side ancora su `user_email`. Il test `test_user_search_caps_results_at_twenty` ora richiede **esattamente 20** risultati appartenenti all’insieme creato dal test.

### Custom Code

Non è Guideline 4. Rientra nella Review Checklist ufficiale, sezioni distinte **Subject Matter** (plugin che permettono raw JS/CSS/HTML/PHP senza sanitizzazione o sicurezza adeguata) e **Security and Privacy**. La presenza della feature **non** è un rifiuto automatico.

Gate dichiarati e coperti da test **scritti** (inclusi nella suite PHP 8.3.33 Cursor):

| Percorso | Boundary | Test |
| --- | --- | --- |
| REST `POST/PUT /erankly/v1/settings/{panel}` | permission + sanitizer | `tests/test-custom-code-boundaries.php` |
| Form/network persist | `erankly_persist_settings_submission()` (stesso sanitize+write del handler di rete, senza `exit`) | stesso file |
| Import `start` / `start_from_file` | `unfiltered_html_required` **prima** di job/file | stesso file |
| Worker | job assente ⇒ nessuna mutazione; `apply_payload_batch` sanitizza budget/legacy fidato | stesso file |
| Marker `legacy_migrated` | un client REST non può forgiarlo | stesso file + sanitizer diretto |
| Output | `erankly_custom_code_should_output()` | `tests/test-robots-and-custom-code.php` |

**Modello di fiducia dell’import:** il worker WP-Cron gira senza utente interattivo (user 0) e tratta lo spool come fidato. Il gate di capability è `ERankly_Import_Job_Runner::start()` / `start_from_file()`, prima che esistano job o file.

**Output — cosa è coperto da test scritti vs ispezione:**

| Contesto | Tipo di prova prevista |
| --- | --- |
| Frontend HTML previsto | `should_output` true + echo una volta in HEAD |
| Feed, admin screen | `should_output` false + hook di render silenziosi |
| AJAX / cron | filtri `wp_doing_ajax` / `wp_doing_cron` (API core) |
| robots / preview / embed / trackback | `go_to` + query flags WordPress |
| REST_REQUEST / XML-RPC | due test `@runInSeparateProcess` definiscono le rispettive costanti e verificano `should_output` false più hook silenziosi; test scritti, **non eseguiti** |

Default `enable_custom_code => 0`. Nessun `eval` / `create_function` nel PHP di produzione.

### Build e licenza

`tools/build-dist.sh`. `license.txt` GPL-2.0 ufficiale (SHA-256 `edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`).

### N1–N4 (14 settembre 2026)

Chiusura dei quattro P2 della seconda revisione GPT-6, senza riaprire R1–R6.

| Rilievo | Correzione |
| --- | --- |
| N1 | Abilitare LocalBusiness carica il primo batch siti/pagine (`ensureSitesLoaded` / `data-erankly-sites-initialized`). |
| N2 | Il selettore usa `pagesUrl` con `q`/`offset`; “Load more pages”; etichette `title (path) [#id]`. |
| N3 | Dopo il lock il worker rilegge il path persistito; `complete` richiede `$expected_input_id`. |
| N4 | Checkpoint, marker e complete sono legati al proprietario della lease; renew o stop. |
| Compat | Shim JSON-LD; meta add-on importabili sui term (documentato + sanitize); notice Multisite `get_users( blog_id => 0 )`; TTL utenti eliminati documentato; ganci uninstall `simulate_*` rimossi dal codice shipped. |

R1–R6 restano chiusi come nella seconda revisione. Non è stata cambiata la politica sui network marcati v1.

### Revisione del tree dopo N1–N4 (14 settembre 2026, Cursor)

Non è GPT-6. È una rilettura dei caller di produzione e dei test rispetto all’accettazione del piano. Stesso lineage di chi ha implementato N1–N4.

| Rilievo | Esito | Prove |
| --- | --- | --- |
| N1 | **RISOLTO** | Toggle off→on chiama `ensureSitesLoaded`; `data-erankly-sites-initialized` evita il refetch. Browser SS (`localhost:8881`) e MS (`erankly-ms.local`): primo batch senza reload. Probe Node. |
| N2 | **RISOLTO** | `pagesUrl` + `q`/`offset`; “Load more pages”; etichette `title (path) [#id]`; selezione conservata in ricerca. Browser: pagina 51–60 selezionata e persistita (SS ID 69; MS ID 64). |
| N3 | **RISOLTO** | Dopo il lock `unset( $path )` e rilettura dello stored path. `complete(..., $expected_input_id)`. Test: path persistito `/new-path/` prima del secondo `advance('/old-path/')`; `complete` rifiuta un `input_id` straniero. |
| N4 | **RISOLTO** | Checkpoint/marker/complete richiedono lease viva; `erankly_renew_settings_lock()` CAS; worker scaduto non marca complete né riavvolge il checkpoint del successore. Test lifecycle + batch MS live `@group ms-required`. |
| R1–R6 | **Non riaperti** | Nessun caller N1–N4 li reintroduce. |

**Qualità.** Il protocollo di migrazione è verboso ma allineato al mutex esistente; niente nuove API di simulazione shipped. Gli shim JSON-LD sono due wrapper. Il CSS del page-picker è minimo.

**Completezza.** I quattro P2 GPT-6 sono chiusi nei caller. Gap di verifica: “Load more sites” non esercitato in browser MS (6 siti < 20); retry di rete non esercitato in browser (coperto dal probe Node); no-JS assente.

**Bloat.** Delta shipped vs HEAD ~+2050/−316 su 31 file, di cui N1–N4 sono soprattutto `admin-widgets.js`, `helpers/settings.php`, `schema-jsonld.php`, REST pages, renderer. `uninstall.php` +507 e i ritocchi meta/migrazione appartengono al lavoro R1–R6 da conservare. `composer.lock` è solo `packages-dev`, assente dallo ZIP.

**Residui (non riaprono N1–N4).**

- P3: `hasMore` siti usa `count === LIMIT` senza peek `LIMIT+1` (falso positivo a esattamente 20 siti). Pagine: il renderer/`appendSite` possono mostrare “Load more pages” quando `count >= 50` per via della pagina inclusa.
- P3: `nextOffset` è sempre `$offset + LIMIT`. JS `appendSite` imposta l’offset a `pages.length` (il renderer PHP usa `min(count, LIMIT)`).
- P3: `complete(..., $expected_input_id = '')` salta il check identità se il caller omette l’argomento; `advance()` / `maybe_migrate()` lo passano.
- **P2 tooling:** `composer.lock` pinna `doctrine/instantiator` 2.1.0 (`php ^8.4`). `composer install --dry-run` fallisce su PHP 8.0.30 e 8.3.33. PHPUnit locale 8.0/8.3 ha usato il `vendor/` già installato con PHP 8.5. La job CI 8.0/8.3 farebbe `composer-install` e si fermerebbe lì. Non è un difetto del runtime shipped. Non corretto in questa revisione.

---

## Plugin Check (storico, 9 settembre 2026)

Questi esiti appartengono allo ZIP precedente (SHA-256 `38e9c909…`, 118 file). **Non** certificano l’artefatto del 14 settembre 2026. Plugin Check non è stato ripetuto sul nuovo ZIP.

Working copy (scansione storica):

```bash
studio wp plugin check easyrankly \
  --mode=update \
  --exclude-directories=tests,.github,tools,.dist \
  --exclude-files=phpunit.xml.dist,.gitignore,.distignore,WORDPRESS-ORG-READINESS.md \
  --require=wp-content/plugins/plugin-check/cli.php \
  --format=json
```

Cursor ha registrato i conteggi dal testo `FILE:` + array JSON; la verifica indipendente finale li ha riconfermati con i campi CSV `type,code,file,line`. In nessun caso è stato usato il solo exit code.

| Superficie | Modo | Exit CLI | ERROR | WARNING |
| --- | --- | --- | --- | --- |
| Working copy | update | 0 | **0** | **7** |
| ZIP estratto in `easyrankly-pcp-scan` (sibling, **non** attivato, poi rimosso) | update | 0 | **0** | **7** |
| Stesso artefatto | new | 0 | **0** | **7** |

Plugin attivo dopo la scansione: EasyRankly 2.0.0 working copy.

### WARNING residui

| Codice | File:riga | Noto o nuovo | Decisione |
| --- | --- | --- | --- |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/opengraph.php:463` | noto | Accettato — reverse-map attachment con `$wpdb->prepare` |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/opengraph.php:463` | noto | Accettato — lookup puntuale |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/meta.php:414` | noto | Accettato — migrazione batch post meta |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/meta.php:414` | noto | Accettato — stessa migrazione |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/meta.php:421` | noto | Accettato — term meta, stesso batch |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/meta.php:421` | noto | Accettato — stessa migrazione |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | `includes/robots.php:499` | noto | Falso positivo — filtro core `robots_txt` |

PCP non ha segnalato ERROR i18n. Questo **non** dimostra l’assenza assoluta di problemi di escaping/nonce/capability.

---

## Plugin Check (14 settembre 2026, ZIP N1–N4)

Questi esiti appartengono allo ZIP SHA-256 `59d651b77eb4635b7accffbd2aa196cc95b00b37a9a34feb1fb61d159eab11b3` (117 file). Plugin Check 2.1.0 è stato installato temporaneamente su Studio, usato per scansionare lo ZIP estratto in `wp-content/plugins/easyrankly-pcp-scan` (**inactive**, `--slug=easyrankly`), poi disinstallato. Il sibling è stato rimosso. Plugin attivo dopo la scansione: EasyRankly 2.0.0 working copy.

Comando (CSV `type,code,file,line`; non è stato usato il solo exit code):

```bash
studio wp plugin check easyrankly-pcp-scan \
  --mode=update \
  --slug=easyrankly \
  --format=csv \
  --fields=type,code,file,line
```

Stesso comando con `--mode=new`.

| Superficie | Modo | Exit CLI | ERROR | WARNING |
| --- | --- | --- | --- | --- |
| ZIP estratto in `easyrankly-pcp-scan` (sibling, **non** attivato, poi rimosso) | update | 0 | **0** | **8** |
| Stesso artefatto | new | 0 | **0** | **8** |

### WARNING sul ZIP `59d651b7…`

| Codice | File:riga | Noto o nuovo rispetto a `38e9c909…` | Decisione |
| --- | --- | --- | --- |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound` | `includes/robots.php:509` | noto (riga spostata da 499) | Falso positivo — filtro core `robots_txt` |
| `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized` | `includes/robots.php:629` | nuovo rispetto allo scan storico | Preesistente: `$_SERVER['REQUEST_URI']` passa da `wp_unslash` + `wp_parse_url(…, PHP_URL_PATH)`. `robots.php` **non** è nel delta N1–N4. Non corretto in questo follow-up. |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/opengraph.php:463` | noto | Accettato — reverse-map attachment con `$wpdb->prepare` |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/opengraph.php:463` | noto | Accettato — lookup puntuale |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/meta.php:471` | noto (riga spostata da 414) | Accettato — migrazione batch post meta |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/meta.php:471` | noto | Accettato — stessa migrazione |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` | `includes/meta.php:478` | noto (riga spostata da 421) | Accettato — term meta, stesso batch |
| `WordPress.DB.DirectDatabaseQuery.NoCaching` | `includes/meta.php:478` | noto | Accettato — stessa migrazione |

PCP non ha segnalato ERROR i18n. Questo **non** dimostra l’assenza assoluta di problemi di escaping/nonce/capability.

---

## Artefatto (14 settembre 2026, dopo N1–N4)

Due build consecutive con `tools/build-dist.sh` (staging temporaneo + `.dist`), `cmp` identico, ripetute dopo la re-verifica PHP 8.0/8.5. `blocks/breadcrumbs/block.json` è presente; Composer, vendor, test e tooling sono assenti. SHA precedente `88cb40ae…` **non** è valido per questo tree. Plugin Check **non** ripetuto: SHA e 117 file invariati.

| Campo | Valore |
| --- | --- |
| Percorso | `wp-content/plugins/easyrankly/.dist/easyrankly.zip` |
| SHA-256 | `59d651b77eb4635b7accffbd2aa196cc95b00b37a9a34feb1fb61d159eab11b3` |
| File non-directory | 117 |
| Root | solo `easyrankly/` |
| Inclusi | `easyrankly.php`, `readme.txt`, `uninstall.php`, `license.txt`, `blocks/breadcrumbs/block.json` |
| Assenti | `.git`, `.github`, `.dist`, `.distignore`, `.gitignore`, `tests`, `tools`, `vendor`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `WORDPRESS-ORG-READINESS.md`, checklist Cursor |

SHA storici, **non** validi per questo artefatto: `88cb40ae9d8a54bffc7426254dcf35fa63ad823b3342b985f203c90031514e03`; `9ded7388…`; `38e9c909cc64e9c59dff76f153d4914c004fc3b3259a87f6f1da29b6e3cf2aeb` (118 file, PCP 0 ERROR / 7 WARNING). Non riutilizzare hash di una review precedente.

---

## PHPUnit

Isolamento: suite WordPress in `/tmp/wordpress-develop/tests/phpunit`, database MySQL `wordpress_test` su `127.0.0.1`. Il sito Studio usa `DB_NAME=wordpress` e non è stato toccato dalla suite. Comando: `WP_TESTS_DIR=/tmp/wordpress-develop/tests/phpunit WP_TESTS_PHPUNIT_POLYFILLS_PATH=vendor/yoast/phpunit-polyfills vendor/bin/phpunit -c phpunit.xml.dist`.

I conteggi sotto sono esiti Cursor di questa sessione, dopo N1–N4 e dopo il helper `erankly_tests_set_accessible()`. GPT-6 **non** li ha riprodotti. Isolamento invariato: `wordpress_test` su `127.0.0.1`; i siti Studio SQLite non sono toccati. PHP 8.0 ha usato `WP_PHP_BINARY=/opt/homebrew/opt/php@8.0/bin/php` via prepend `/tmp/erankly-php80-prepend.php`. PHP 8.3 e 8.5 hanno usato il config di default (`WP_PHP_BINARY` 8.3); il processo PHPUnit 8.5 è comunque 8.5.10.

| Ambiente | Esito |
| --- | --- |
| PHP 8.0.30 single-site / MySQL `wordpress_test` | **1025 test, 3690 assertion, 0 failure, 16 skipped.** |
| PHP 8.0.30 Multisite (`WP_MULTISITE=1`) / MySQL `wordpress_test` | **1025 test, 3698 assertion, 0 failure, 10 skipped.** |
| PHP 8.3.33 single-site / MySQL `wordpress_test` | **1025 test, 3690 assertion, 0 failure, 16 skipped.** |
| PHP 8.3.33 Multisite (`WP_MULTISITE=1`) / MySQL `wordpress_test` | **1025 test, 3698 assertion, 0 failure, 10 skipped.** I 10 skip restanti non sono i `@group ms-required`. |
| PHP 8.5.10 single-site / MySQL `wordpress_test` | **1025 test, 3690 assertion, 0 failure, 16 skipped.** La failure storica `ReflectionMethod::setAccessible()` non si riproduce. |
| PHP 8.5.10 Multisite (`WP_MULTISITE=1`) / MySQL `wordpress_test` | **1025 test, 3698 assertion, 0 failure, 10 skipped.** |
| Probe JS `tests/js/local-business-widget-probe.cjs` | **ok** (Node, DOM simulato: toggle off→on, `pagesUrl`, paginazione) |
| `composer install --dry-run` dal lock su PHP 8.0.30 e 8.3.33 | **FAIL** — `doctrine/instantiator` 2.1.0 richiede `php ^8.4` |

Metodi `test_*` nel tree: **1025**, di cui **10** annotazioni `@group ms-required`. Plugin Check sul ZIP `59d651b7…`: vedi sezione dedicata (0 ERROR / 8 WARNING).

---

## Guideline 1–18

Fonte: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

Aggiornamento dichiarato **nel corpo della pagina** (fetch 9 settembre 2026): **Last Updated: March 15, 2024**. Il chrome/handbook della stessa URL mostra anche “Last modified / Last updated: March 11, 2026”. Non si usa la data di oggi come data della policy; non si usa la sola data di chrome al posto del “Last Updated” del documento.

Ogni riga: requisito ufficiale; evidenza esaminata; limite; stato non assoluto.

| # | Requisito ufficiale | Evidenza EasyRankly | Limite | Stato |
| ---: | --- | --- | --- | --- |
| 1 | Compatible with the GNU GPL | Header/readme `GPL-2.0-or-later`; `license.txt` byte-identico al testo GNU GPL v2 nello zip | Non è un audit di ogni file/immagine/dipendenza di terze | Nessun rilievo sul perimetro esaminato |
| 2 | Developers are responsible for contents and actions | Adapter di migrazione e codice nel tree | Originalità, autorizzazioni e condotta **non** sono dimostrate da un’analisi statica | **Richiede attestazione** del developer |
| 3 | A stable version must be available from the Directory page | Live 1.0.0 su WordPress.org | Il candidato 2.0.0 **non** è la stable Directory | Live 1.0.0; 2.0.0 è gate futuro |
| 4 | Code must be mostly human readable | JS/CSS di produzione non offuscati; sorgenti PHP leggibili | Custom Code dell’amministratore **non** è G4 | Nessun rilievo G4 sul perimetro |
| 5 | Trialware is not permitted | Nessuna scadenza, quota o feature locale bloccata da pagamento nel core esaminato | Upsell futuro non esaminato oltre il tree corrente | Nessun rilievo sul perimetro |
| 6 | Software as a Service is permitted | Il plugin non è un client di un SaaS proprietario EasyRankly; YouTube/Vimeo/Gravatar sono servizi documentati nel readme | Documentare un servizio ≠ consenso automatico (vedi G7) | **N/A / nessun rilievo SaaS proprio**; servizi terzi documentati |
| 7 | May not track users without consent | Nessun analytics/phone-home del plugin nel perimetro; Gravatar/YouTube/Vimeo documentati; email REST minimizzata (non è G7) | Snippet Custom Code dell’utente possono chiamare terzi; consenso = opt-in/configurazione del servizio, non solo una riga di readme | Nessun rilievo G7 sul core del plugin; Custom Code resta responsabilità dell’admin |
| 8 | May not send executable code via third-party systems | Nessun updater remoto o CDN di JS/CSS di produzione; Custom Code è colla dell’admin, non download automatico | Non equivale a un test dinamico di tutte le richieste HTTP | Nessun rilievo sul perimetro; Custom Code distinto |
| 9 | Must not do anything illegal, dishonest, or morally offensive | Code review non ha trovato sockpuppeting, mining, ecc. nel tree | Una review statica **non** può certificare la condotta | Limite della code review; **non è Pass** |
| 10 | No public-site credits/links without permission | Nessun “powered by” di default trovato nei PHP/JS di produzione | Output di snippet utente può contenere link scelti dall’admin | Nessun rilievo sul perimetro del plugin |
| 11 | Should not hijack the admin dashboard | Menu/settings EasyRankly; notice di reset/import contestuali | Non è stato fatto un audit UX di ogni notice in ogni ruolo | Nessun rilievo sul perimetro ispezionato |
| 12 | Public-facing WordPress.org pages may not spam | readme locale 2.0.0: 5 tag, niente affiliate; scheda live 1.0.0 ancora con Health/wizard | Keyword stuffing della scheda live 1.0.0 è un fatto Directory, non del candidato locale | Locale: nessun rilievo; live 1.0.0 non rappresenta la 2.0.0 |
| 13 | Must use WordPress’ default libraries | Nessuna copia di jQuery/SimplePie/PHPMailer/PHPass nel plugin | Non è una prova formale di ogni asset | Nessun rilievo sul perimetro |
| 14 | Frequent commits should be avoided | SVN pubblico: un tag `1.0.0`; disciplina futura di release | Non è stata scaricata l’intera history SVN; G14 non chiede “aggiornamenti frequenti” | Gate futuro di release; storia SVN parziale |
| 15 | Version numbers must be incremented | Locale 2.0.0 vs live 1.0.0 in header/readme; tag SVN `2.0.0` assente | Coerenza trunk/`Stable tag` è un passo SVN futuro | Incremento locale presente; tag Directory **non** creato |
| 16 | A complete plugin must be available at submission | Zip 117 file con header, uninstall, license, runtime, `block.json`; PCP 0 ERROR / 8 WARNING sul ZIP `59d651b7…` | CI remota non eseguita; `composer.lock` non installabile su PHP 8.0/8.3; no-JS e ZIP-attivato assenti | Candidato zip completo; **non pronto** per Directory |
| 17 | Respect trademarks, copyrights, project names | Slug `easyrankly`; nome non inizia con “wordpress”; riferimenti Yoast/Rank Math come importer | Titolarità del marchio EasyRankly: **attestazione** | Nessun rilievo evidente sul slug; titolarità da confermare |
| 18 | Directory Team reserve rights | Non è un test tecnico | Non può essere “Pass” | **Non è un test** |

---

## Procedure ufficiali per ogni release futura (non autorizzate)

Fonti: [How to use Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [Release Confirmation](https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/), [Automated Security Review](https://developer.wordpress.org/plugins/wordpress-org/automated-security-review/).

1. SVN: `trunk` poi tag `2.0.0` coerente con header e `Stable tag`.
2. Se Release Confirmation è abilitata per il plugin: confermare dal dashboard **dopo** tag e `Stable tag` coerenti.
3. L’Automated Security Review parte per **ogni nuova release taggata** rilevata, non per un generico commit SVN. Se il punteggio supera la soglia, **quella versione** può essere bloccata. Risposte previste: pubblicare una **nuova** versione corretta, oppure rispondere all’email se si ritiene un falso positivo.

---

## Runtime / manuali

| Caso | Esito |
| --- | --- |
| Browser 9 settembre 2026 (sessione precedente) | Schermate e script attesi; copy Custom Code; `history.replaceState` su `erankly_reset_notice`; nessuna risorsa EasyRankly fallita osservata |
| Browser 14 settembre 2026 — N1/N2 LocalBusiness | Studio `http://localhost:8881/` single-site. Toggle off→on ha caricato il primo batch (50 pagine + placeholder) senza reload. “Load more pages” ha mostrato le pagine 51–60 (`erankly-n2-probe-59` ID 69). Ricerca “Sede unica 59”, titoli duplicati distinguibili per path/ID, selezione 69 persistita da autosave (`local_business_pages[1]=69`), on→off→on senza righe sito duplicate. Probe pages eliminate e `enable_local_business` riportato a 0. Retry di rete non esercitato in browser (coperto dal probe Node). |
| Browser 14 settembre 2026 — N1/N2 Multisite | Sito Studio **separato** `http://erankly-ms.local/` (WP 7.1, PHP 8.4, SQLite, `is_multisite()=1`). Il sito primario `:8881` **non** è stato convertito. Network Schema: toggle off→on ha mostrato 6 selettori sito; blog 1 ha caricato 50 pagine + “Load more pages”; dopo il click, `erankly-n2-probe-59` ID **64** selezionato e persistito (`local_business_pages[1]=64`); ricerca “Sede unica 59” ha tenuto la selezione. “Load more sites” non visibile (6 siti < 20). Fixture lasciate su quel sito: `enable_local_business=1`, mapping, 60 probe pages, siti `/sede-2/`…`/sede-6/`. |
| Marker `[data-erankly-migration-autoreload]` | **Assente** in quella sessione: **non** è un test del ramo attivo |
| REST autosave `website_name` (questa sessione) | `POST /erankly/v1/settings/general` status 200, valore persistito, poi ripristinato a stringa vuota tramite `erankly_update_plugin_option` (il `update_option` nativo non aveva riportato il valore) |
| Smoke CLI Custom Code/import/user-search | **34 check pass**: fixture privilegiata, revoca `unfiltered_html` effettiva, REST ristretto senza sovrascrittura o marker forgiato, import ristretto rifiutato prima del job, 20 utenti unici restituiti su 21; option ripristinata e 22 utenti temporanei rimossi |
| Salvataggio no-JS | **BLOCKED — no-JS** |
| Custom Code live UI | **Non eseguito**. Lo smoke CLI non sostituisce il flusso UI |
| ZIP attivato isolato | **BLOCKED** — sibling PCP inattivo rimosso |
| Multisite UI | **ESEGUITO** sul sito Studio `erankly-ms.local` (vedi riga browser). Non è il sito primario. |

---

## WordPress.org live (fetch 9 settembre 2026)

API 1.2: versione **1.0.0**, `added` 2026-06-28, `last_updated` 2026-06-28 12:27pm GMT, `requires` 6.5, `tested` 7.0.4, `requires_php` 8.0, `active_installs` 0. SVN: tag `1.0.0`; assets `icon-128x128.png`, `icon-256x256.png`; nessun banner.

---

## Cosa questa analisi non afferma

- Che la CI remota sia verde. PHPUnit 8.0/8.3/8.5 locale è un esito Cursor su `vendor/` già presente; `composer install` dal lock fallisce su PHP 8.0 e 8.3.
- Che le guideline 2, 9, 14, 18 siano “Pass”.
- Che tutti i percorsi Custom Code siano stati eseguiti in PHPUnit.
- Che lo ZIP sia il plugin in esecuzione.
- Che l’autoreload di migrazione sia stato visto in azione.
- Che Plugin Check 0 ERROR / 7 WARNING (SHA `38e9c909…`) o lo SHA `88cb40ae…` valgano per lo ZIP `59d651b7…`.
- Che GPT-6 abbia verificato il tree N1–N4: ha riprodotto i quattro P2 sul delta precedente. La rilettura successiva è Cursor, stesso lineage dell’implementazione.
- Che il sito primario Studio sia Multisite, o che le fixture su `erankly-ms.local` siano state rimosse.

---

## Fonti ufficiali

- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/
- https://developer.wordpress.org/plugins/wordpress-org/automated-security-review/
- https://developer.wordpress.org/plugins/wordpress-org/release-confirmation-emails/
- https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- https://wordpress.org/plugins/plugin-check/
- https://github.com/WordPress/plugin-check-action/blob/main/action.yml
- https://wordpress.org/plugins/easyrankly/
- https://plugins.svn.wordpress.org/easyrankly/
- https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt
