# Prontezza WordPress.org — EasyRankly 2.0.0

**Tipo:** aggiornamento di un plugin già nel Directory (non prima submission)  
**Scheda live:** [wordpress.org/plugins/easyrankly](https://wordpress.org/plugins/easyrankly) — versione **1.0.0**  
**Data `added` Directory (API 1.2, 9 settembre 2026):** 28 giugno 2026  
**Release date changelog 1.0.0:** 14 giugno 2026  
**Tree analizzato:** working copy locale 2.0.0, **non committata**  
**Data verifica di questa revisione:** avviata il 9 settembre 2026, conclusa il 10 settembre 2026  

## Verdetti (gate, non punteggio)

| Gate | Esito | Cosa significa |
| --- | --- | --- |
| Revisione indipendente del tree finale | **ESEGUITA — BLOCKED** | Diff, test scritti, controlli statici, smoke CLI, packaging e Plugin Check sono stati riesaminati. La review ha trovato e corretto difetti nei test; PHPUnit/MySQL, CI remota e verifiche manuali isolate restano bloccati. |
| Working tree (statica, packaging, test scritti) | **REVIEWED — BLOCKED** | Le correzioni verificabili localmente sono applicate; la suite PHPUnit richiesta non è stata eseguita in questo ambiente. |
| Pubblicazione WordPress.org | **NOT READY** | Nessuna azione di release è stata eseguita o autorizzata. |
| PHPUnit PHP 8.0 / 8.3 / Multisite su MySQL | **BLOCKED — ambiente locale** | Composer CLI e client MySQL assenti da PATH. Studio usa SQLite, non è la matrice ufficiale. |
| CI remota sul tree corrente | **BLOCKED — push** | Workflow aggiornato nel working tree; nessun run sul commit candidato. |
| ZIP installato e attivato in isolamento | **BLOCKED — ambiente isolato** | Lo ZIP è stato costruito, scansionato come sibling inattivo e rimosso. Working copy 2.0.0 resta il plugin attivo. |
| Multisite UI / no-JS browser | **BLOCKED** | Studio locale single-site; no-JS non esercitato. |
| Asset marketing Directory | **BLOCKED — asset marketing opzionali** | SVN ha solo icone 128/256. Non è un blocco tecnico del codice. |

Non c’è un punteggio 0–100 né un “Pass 18/18”.

Questo file mescola **quattro livelli di prova**, da non confondere:

1. **Risultati riprodotti in questa sessione** (lint, POT, build byte-identica, Plugin Check CSV/JSON, REST autosave con ripristino, smoke CLI Custom Code/import/user-search con cleanup, API Directory/SVN).
2. **Ispezioni statiche** (guideline, mappa dei percorsi, lettura del codice).
3. **Test automatici scritti ma non eseguiti** (PHPUnit locale assente).
4. **Dichiarazioni o gate del developer / proprietario** (originalità, storia SVN completa, condotta, pubblicazione).

Non è una garanzia di approvazione del Plugin Review Team.

---

## Identità Git (immutabile in questa attività)

| Campo | Valore |
| --- | --- |
| Root Git | `wp-content/plugins/easyrankly` |
| Branch | `beta` tracking `origin/beta` |
| HEAD iniziale | `7e56cbf8149629a96498971857a850b6a4f5dcf7` |
| HEAD finale | `7e56cbf8149629a96498971857a850b6a4f5dcf7` |
| Working tree iniziale (9 settembre 2026, revisione indipendente) | 19 file tracked modificati; untracked report, `admin-import-export.js`, `license.txt`, `test-rest-user-search.php`, `tools/` |
| Working tree alla chiusura della verifica finale | 21 file tracked modificati; 6 entry untracked; 0 file staged |
| `origin/main` | `e62476a03ae9876e83a556131f0fdccdbf632c91` |
| `git merge-base origin/main origin/beta` | exit 1 — **nessun merge base**. Non è stato tentato alcun riallineamento. |

Nessun commit, push, tag, SVN o release.

---

## Superfici da non confondere

1. **Working copy** — plugin attivo in Studio, HEAD invariato, tree sporco.
2. **Artefatto** — `.dist/easyrankly.zip` (gitignored), SHA-256 sotto; **non** attivato al posto della working copy.
3. **Suite PHPUnit** — scritta, **non eseguita** in locale.
4. **CI** — YAML configurato (single-site 8.0/8.3, Multisite 8.3, Plugin Check); **run remoto assente**.
5. **Browser** (sessione precedente, 9 settembre 2026) — caricamento schermate/script, copy di rischio, pulizia query reset, nessuna risorsa EasyRankly fallita osservata. **Non** è un salvataggio UI, **non** è l’autoreload con marker presente, **non** è Custom Code live.
6. **WordPress.org live / SVN** — 1.0.0.
7. **Release** — non autorizzata.

---

## Ambiente di verifica

| Strumento | Stato |
| --- | --- |
| WordPress Studio | 1.21.0 — `http://localhost:8881/`, WordPress **7.1**, PHP **8.4.24** |
| Database Studio | SQLite |
| Plugin Check | 2.1.0 |
| EasyRankly locale | 2.0.0 attivo, `is_multisite()=0` |
| WP-CLI | `studio wp` |
| Composer CLI / `mysql` | assenti da PATH |
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

`tests/bootstrap.php` espone `erankly_tests_load_settings_sanitizer()`. Il runtime di produzione non carica admin incondizionatamente. **I test non sono stati eseguiti.**

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

Gate dichiarati e coperti da test **scritti** (non eseguiti):

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

---

## Plugin Check (9 settembre 2026, dopo le ultime modifiche)

Working copy:

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

## Artefatto (dopo le ultime modifiche)

| Campo | Valore |
| --- | --- |
| Percorso | `wp-content/plugins/easyrankly/.dist/easyrankly.zip` |
| SHA-256 (due build temporanee + `.dist`, `cmp` identico) | `38e9c909cc64e9c59dff76f153d4914c004fc3b3259a87f6f1da29b6e3cf2aeb` |
| File non-directory | 118 |
| Root | solo `easyrankly/` |
| Inclusi | `easyrankly.php`, `readme.txt`, `uninstall.php`, `license.txt`, `composer.json` |
| Assenti | `.git`, `.github`, `.dist`, `.distignore`, `.gitignore`, `tests`, `tools`, `vendor`, `phpunit.xml.dist`, `WORDPRESS-ORG-READINESS.md`, checklist Cursor |

Lo SHA precedente `9ded7388…` **non** è più valido: `admin/settings-page.php`, import runner, POT e test non entrano tutti nello zip, ma settings-page e import runner sì.

---

## PHPUnit

| Ambiente | Esito |
| --- | --- |
| PHP 8.0 single-site/MySQL | **BLOCKED — Composer CLI + MySQL** |
| PHP 8.3 single-site/MySQL | **BLOCKED — ambiente locale** |
| PHP 8.3 Multisite/MySQL | **BLOCKED — ambiente locale** |

Metodi `test_*` nel tree: **87**, di cui **5** `@group ms-required` (skippati su single-site per disegno). Non riutilizzare il run CI `59 test / 168 assertion / 4 errori` sul commit HEAD: precede queste modifiche.

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
| 16 | A complete plugin must be available at submission | Zip 118 file con header, uninstall, license, runtime | CI verde **non** è il significato di G16; test non eseguiti impediscono di dichiarare il candidato pronto | Candidato zip completo; **non pronto** finché PHPUnit/CI restano BLOCKED |
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
| Marker `[data-erankly-migration-autoreload]` | **Assente** in quella sessione: **non** è un test del ramo attivo |
| REST autosave `website_name` (questa sessione) | `POST /erankly/v1/settings/general` status 200, valore persistito, poi ripristinato a stringa vuota tramite `erankly_update_plugin_option` (il `update_option` nativo non aveva riportato il valore) |
| Smoke CLI Custom Code/import/user-search | **34 check pass**: fixture privilegiata, revoca `unfiltered_html` effettiva, REST ristretto senza sovrascrittura o marker forgiato, import ristretto rifiutato prima del job, 20 utenti unici restituiti su 21; option ripristinata e 22 utenti temporanei rimossi |
| Salvataggio no-JS | **BLOCKED — no-JS** |
| Custom Code live UI | **Non eseguito**. Lo smoke CLI non sostituisce il flusso UI e i test PHPUnit restano scritti ma non eseguiti |
| ZIP attivato isolato | **BLOCKED** — sibling PCP inattivo rimosso |
| Multisite UI | **BLOCKED — ambiente Multisite manuale** |

---

## WordPress.org live (fetch 9 settembre 2026)

API 1.2: versione **1.0.0**, `added` 2026-06-28, `last_updated` 2026-06-28 12:27pm GMT, `requires` 6.5, `tested` 7.0.4, `requires_php` 8.0, `active_installs` 0. SVN: tag `1.0.0`; assets `icon-128x128.png`, `icon-256x256.png`; nessun banner.

---

## Cosa questa analisi non afferma

- Che PHPUnit o la CI remota siano verdi.
- Che le guideline 2, 9, 14, 18 siano “Pass”.
- Che tutti i percorsi Custom Code siano stati eseguiti in PHPUnit.
- Che lo ZIP sia il plugin in esecuzione.
- Che l’autoreload di migrazione sia stato visto in azione.
- Che la revisione indipendente renda il candidato pronto nonostante i gate ancora `BLOCKED`.

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
