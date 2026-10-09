# Custom code: posizioni e problemi noti

Gli snippet di Custom code (`src/CustomCode/`) girano in una di quattro posizioni. Le regole generali (permessi,
sintassi, spegnimento automatico, modalità sicura, cache) sono nella sezione "Custom code" di `CLAUDE.md`.

| Posizione | Hook | Tipi | Dove gira |
|---|---|---|---|
| `head` | `wp_head` | HTML, PHP | Pagine del frontend |
| `body_open` | `wp_body_open` | HTML, PHP | Pagine del frontend (se il tema chiama `wp_body_open()`) |
| `footer` | `wp_footer` | HTML, PHP | Pagine del frontend |
| `everywhere` ("Ovunque, come functions.php") | `plugins_loaded` | Solo PHP | Ogni richiesta, tranne la schermata Custom code |

Il Runner aggancia ogni hook con la priorità predefinita 10. La priorità dello snippet decide solo l'ordine tra gli
snippet della stessa posizione, non la priorità sull'hook.

## Perché esiste "Ovunque"

Le posizioni di pagina girano dentro l'output della pagina: lì è già troppo tardi per togliere o cambiare hook che
partono prima (per esempio `template_redirect`, o i callback del core su `wp_head` alla stessa priorità 10). "Ovunque"
serve al codice che di solito si mette nel `functions.php` del tema o in un mu-plugin, senza modificare file.
Decisione di Enthony del 9 ottobre 2026, con l'obbligo di annotare qui ogni problema possibile.

## Problemi noti della posizione "Ovunque"

### Dove gira

- **Ovunque, admin compreso**: frontend, admin, `admin-ajax.php` (anche l'Heartbeat, ogni 15–60 secondi con l'admin
  aperto), `admin-post.php`, REST, `wp-login.php`, `xmlrpc.php`, `wp-cron.php`, WP-CLI, feed, sitemap, `robots.txt`,
  anteprima del Customizer, editor del sito. Uno snippet difettoso può rompere tutto questo, non solo le pagine.
- **Unica eccezione, la schermata Custom code** (`admin.php?page=easyrankly-snippets`, compresi il salvataggio del
  form e la richiesta REST interna con cui salva): lì gli snippet "Ovunque" non girano, così da lì si può sempre
  correggere o disattivare uno snippet rotto, purché si riesca a entrare nell'admin.
- **L'esclusione si legge dalla URL**, perché su `plugins_loaded` WordPress non conosce ancora la schermata. Chiunque
  può chiedere quella URL: senza login finisce nel redirect alla pagina di accesso, senza permessi in un errore. Su
  quella richiesta, però, uno snippet "Ovunque" non gira: se lo snippet fa da controllo di sicurezza (blocco di IP,
  intestazioni di sicurezza), su quella sola risposta di redirect o di errore il controllo manca. Per questo i controlli
  di sicurezza veri vanno nel server o in un plugin dedicato, non in uno snippet.
- **Anche gli effetti voluti mancano sulla schermata**: uno snippet che cambia l'aspetto o il comportamento dell'admin
  non si vede sulla schermata Custom code. È voluto.
- **La pagina di accesso non è esclusa**: uno snippet che rompe `wp-login.php` (redirect continui, `exit`, login
  bloccato) impedisce di arrivare alla schermata Custom code. Non è escluso apposta, perché molte personalizzazioni
  tipiche del `functions.php` agiscono proprio sull'accesso (`login_redirect`, logo della pagina di accesso).
- **Multisite**: gira su ogni sito dove EasyRankly è attivo, con gli snippet di quel sito. Nell'admin di rete gira
  sempre (la schermata Custom code non c'è).
- **Solo con EasyRankly attivo**: a differenza del `functions.php`, il codice smette di girare se EasyRankly viene
  disattivato, se la modalità di recupero di WordPress lo mette in pausa per un amministratore (vedi sotto) o con la
  modalità sicura. Codice da cui dipende il funzionamento del sito non dovrebbe vivere in uno snippet.

### Quando gira

Su `plugins_loaded`, priorità 10: tutti i plugin sono caricati, il tema no.

- **Prima del tema**: le funzioni del tema non esistono ancora. Il `functions.php` del tema gira dopo e può rimettere
  ciò che lo snippet toglie; un hook aggiunto dal tema non si può togliere direttamente. Per agire sul tema serve
  agganciarsi più tardi dallo snippet (per esempio `add_action( 'after_setup_theme', … )`).
- **Prima di `init`**: l'utente corrente non è ancora stabilito (`current_user_can()` e `is_user_logged_in()` lo
  forzano in anticipo; meglio usarle dentro un hook come `init`), i post type e le tassonomie dei plugin non sono
  registrati, la query principale non c'è ancora (`is_singular()` e simili non rispondono) e caricare traduzioni
  (`__()`) con un dominio di testo prima di `init` fa scattare l'avviso del core sulle traduzioni caricate troppo presto.
- **Funzioni pluggable già definite**: `wp_mail()`, `wp_set_auth_cookie()` e le altre di `pluggable.php` sono già
  caricate. Dichiararle di nuovo è un errore fatale ("Cannot redeclare") e lo snippet si spegne. Serve un mu-plugin.
- **Hook già passati**: `muplugins_loaded` e i callback di `plugins_loaded` con priorità minore di 10 (e quelli a
  priorità 10 di plugin caricati prima di EasyRankly) sono già partiti. Lo snippet deve usare hook successivi o una
  priorità più alta.
- **Dichiarazioni globali**: una funzione o classe dichiarata nello snippet esiste per tutta la richiesta. Se un
  altro plugin o il tema dichiarano lo stesso nome dopo, l'errore fatale cade su di loro, non sullo snippet, e lo
  snippet non si spegne. Conviene un prefisso proprio o le funzioni anonime.

### Output

- **Quello che lo snippet stampa viene scartato**: su `plugins_loaded` non c'è ancora una pagina, e un `echo` (anche
  uno spazio o dell'HTML dopo `?>`) partirebbe prima delle intestazioni HTTP o dentro una risposta JSON. Il Runner apre
  un buffer prima dello snippet e lo butta dopo. Quello che lo snippet stampa più tardi, dentro gli hook che aggiunge,
  esce normalmente.
- **Buffer lasciati aperti**: se lo snippet apre un suo buffer e lo lascia aperto (il trucco del buffer su tutta la
  pagina), il Runner non chiude né il suo né il proprio, perché chiudere il proprio chiuderebbe anche quello dello
  snippet; l'eventuale output stampato prima dallo snippet non viene scartato. Uno snippet che chiude buffer non suoi
  (`ob_end_clean()` in più) può togliere quelli di WordPress o di altri plugin.
- **Gli snippet HTML non possono andare in "Ovunque"**: la REST risponde `400 easyrankly_snippet_position`; se la
  posizione arriva da un'altra strada (meta scritto via codice, importazione), lo snippet non entra in cache e non
  gira.

### Errori e recupero

- **Eccezioni ed errori PHP catturabili**: lo snippet si spegne da solo come nelle altre posizioni. Può succedere
  prima di `init`, quando il post type degli snippet non è ancora registrato: lo spegnimento funziona lo stesso (gli
  stati del core sono già registrati da `wp-settings.php` prima dei plugin).
- **Errori fatali** (funzione dichiarata due volte, memoria, tempo massimo): la richiesta fallisce con la schermata
  "errore critico", poi la funzione di shutdown spegne lo snippet e la richiesta successiva funziona. Il gestore di
  errori fatali di WordPress attribuisce l'errore a EasyRankly: manda la mail di modalità di recupero
  all'amministratore e, per chi entra con quel link, mette in pausa l'intero plugin (tutte le funzioni SEO, non solo lo
  snippet) finché non lo riattiva. Se l'errore fatale lascia poca memoria o poco tempo, lo spegnimento in shutdown
  potrebbe non riuscire (punto CC-04 di `docs/sicurezza.md`).
- **Errori che non sono errori**: `exit`, `die`, `wp_die()`, redirect su ogni richiesta, cicli lunghi, capacità degli
  utenti cambiate, utenti o opzioni modificati a ogni richiesta. Lo snippet non si spegne da solo. Rimedi, in ordine:
  1. la schermata Custom code, se si riesce a entrare;
  2. la costante `define( 'EASYRANKLY_SAFE_MODE', true );` in `wp-config.php`: nessuno snippet gira;
  3. rinominare la cartella del plugin via FTP o dal pannello dell'hosting.
- **Effetti ripetuti**: lo snippet gira a ogni richiesta, anche più volte al minuto per l'Heartbeat e il cron. Codice
  che scrive nel database (crea post, utenti, opzioni) lo fa ogni volta, anche per i visitatori anonimi.
- **WP-CLI**: lo snippet gira in ogni comando `wp`. Se rompe WP-CLI: `wp --skip-plugins=easyrankly` oppure la
  modalità sicura.
- **Spegnimento avviato da chiunque**: come per le altre posizioni (CC-06 di `docs/sicurezza.md`), un visitatore che
  provoca un'eccezione spegne lo snippet; qui le strade sono di più (REST, AJAX, cron, accesso).
- **Richieste in parallelo**: con l'admin aperto (Heartbeat, REST dell'editor) più richieste possono fallire insieme
  e scrivere lo stesso spegnimento; il risultato finale è comunque lo snippet spento con l'ultimo errore.

### Prestazioni

- Gira a ogni richiesta PHP. EasyRankly non aggiunge query (la cache è nell'option autoload già caricata), ma le query,
  le chiamate HTTP e i calcoli dello snippet si pagano ovunque, admin e AJAX compresi. La regola "nessuna query in più
  nel frontend" vale per il codice del plugin, non per quello che l'utente scrive.

### Sicurezza

- **Stessi permessi delle altre posizioni**: scrivere uno snippet PHP richiede `edit_plugins` e niente gira con
  `DISALLOW_FILE_EDIT` o `DISALLOW_FILE_MODS`. Chi ha `edit_plugins` può già modificare i file dei plugin: la posizione
  non dà poteri nuovi.
- **Raggio d'azione più ampio**: il codice gira anche quando un amministratore usa l'admin, con i suoi permessi. Uno
  snippet malevolo inserito da un amministratore compromesso può agire come qualunque amministratore che apre una
  pagina (creare utenti, cambiare opzioni). È lo stesso rischio del `functions.php`.
- **Option degli snippet come confine di fiducia** (CC-03 di `docs/sicurezza.md`): con accesso diretto al database,
  l'option `easyrankly_snippets` diventa esecuzione di codice anche in admin, cron e WP-CLI, non più solo nel frontend.
  La decisione sulla firma HMAC della cache pesa di più.
- **Revisione di WordPress.org** (CC-10): una posizione che esegue PHP a ogni richiesta, admin compreso, rende ancora
  più centrale la giustificazione degli snippet PHP. Plugin come Code Snippets e WPCode offrono la stessa possibilità
  ("Run everywhere").

### Da verificare a mano

- Su un sito reale (Studio): uno snippet "Ovunque" che rompe l'admin con un redirect, poi il recupero dalla schermata
  Custom code e con `EASYRANKLY_SAFE_MODE`.
- La mail e la pausa della modalità di recupero dopo un errore fatale in uno snippet "Ovunque".
- Lo snippet della richiesta originale (togliere lo shortlink da `wp_head` e dall'intestazione HTTP) su una pagina vera.
