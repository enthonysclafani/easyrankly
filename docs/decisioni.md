# Decisioni

Il posto unico delle decisioni di EasyRankly, gratuito e Pro: in alto quelle da prendere, sotto il registro di quelle
prese, con data e perché. Quando una decisione si prende, passa dalla prima parte alla seconda e si aggiornano i
documenti che la applicano (`CLAUDE.md`, `docs/piano.md`, `docs/roadmap.md`). Il piano tiene i principi e la visione;
qui ci sono le scelte.

Le decisioni del Pro sono scritte per esteso nel `docs/piano.md` del repository `easyrankly-pro`, sezione 5, con
numeri fissi perché la sua roadmap li cita: qui c'è una riga ciascuna con la proposta.

## Da decidere

Aggiornato al 12 ottobre 2026.

### Gratuito

1. **Campi SEO e lingua nell'editor classico (W1).** I prodotti di WooCommerce, i siti con Classic Editor e i tipi
   di contenuto senza supporto `editor` non hanno il pannello SEO né il pannello Lingua (i meta funzionano via REST).
   Proposta: un meta box classico con gli stessi campi, solo per i tipi che non usano l'editor a blocchi, salvato con
   nonce e `edit_post`. È un'eccezione alla regola "niente meta box classiche" di `CLAUDE.md`. Insieme si decidono i
   campi SEO degli **allegati** (`attachment_fields_to_edit` / `attachment_fields_to_save`): i meta sono registrati,
   ma la schermata dei media non ha campi (Enthony, 12 ottobre 2026: non ora, insieme a W1). Dettagli:
   `docs/compatibilita.md`, W1.
2. **Multilingua sui negozi (W5) e copia dei meta nelle traduzioni (A2).** Oggi il multilingua non regge un negozio:
   prodotti senza lingua (W1), "Crea traduzione" non copia prezzo, SKU, magazzino e varianti, carrello e checkout
   sono una pagina sola. Domande: copiare i meta del post d'origine nelle traduzioni (tranne quelli interni e i
   nostri `_easyrankly_*`, compresi i riferimenti `_campo` → `field_…` di ACF)? Togliere intanto `product` dai tipi
   con lingua, per non svuotare il negozio nelle altre lingue? Dettagli: `docs/compatibilita.md`, W5 e A2.
3. **Description da un campo personalizzato (A1).** Le pagine fatte solo di campi o blocchi ACF restano senza
   description automatica. Proposta: una variabile di template (es. `%cf:nome_campo%`) letta con `get_post_meta()`,
   già in cache, che non nomina ACF. È una funzione nuova. Dettagli: `docs/compatibilita.md`, A1.
4. **Multilingua sui siti che non lo usano** (discussa il 10 ottobre 2026, rimandata il 12 ottobre: per ora non si fa
   niente). Con meno di due lingue il multilingua non cambia nulla (`Languages::enabled()`: nessuna query, nessuna
   rewrite rule, nessun pannello), ma restano circa 42 callback che escono dopo un controllo, le due tassonomie
   nascoste, la route REST e il blocco "Language switcher" nell'inserter. Un interruttore esplicito è scartato
   (un'option nuova e un passo verso un sistema a moduli). Due possibilità annotate:
   1. *Spegnimento automatico sotto le due lingue*, senza option nuove: `Multilingual::register()` aggancia solo ciò
      che serve ad accorgersi dell'aggiunta di una lingua (salvataggio delle impostazioni, rewrite rule) e non
      registra il blocco. Guadagno di prestazioni minimo e non misurato (frazioni di millisecondo, quasi tutto dal
      filtro `home_url`; lo stesso si ottiene mettendo il controllo delle lingue in testa a quel filtro). Il
      vantaggio vero è un editor senza il blocco quando non serve.
   2. *Pagina Lingue nascosta sui temi classici*, saltando il sottomenu nel ciclo di `Settings\Admin\Page`, solo se il
      tema è classico **e** non ci sono lingue: chi passa a un tema classico con le lingue già attive deve poterle
      ancora gestire o togliere.
5. *Decisa il 13 ottobre 2026* (spegnimento di uno snippet PHP avviato da un visitatore, CC-06): vedi "Sicurezza"
   tra le decise. Il numero resta per non cambiare i rimandi alle altre.

### Pro (dettagli nel `docs/piano.md` del Pro, sezione 5)

6. **Pro 1: cosa l'agente legge dal gratuito** (prima della Fase 1 del Pro). Il vecchio agente usava classi interne
   del gratuito; il contratto oggi copre solo menu, meta registrati e redirect, quindi l'agente vedrebbe solo gli
   override e non il title che Google mostra. Proposta: aggiungere al contratto, senza codice nuovo nel gratuito,
   l'option `easyrankly_settings` (già nello schema di `/wp/v2/settings`) e le tassonomie `erankly_language` ed
   `erankly_translation`; il Pro ricalcola title e description con le regole dei template, con un test che confronta
   il risultato con l'`<head>` del gratuito. Se si decide, va scritto nella sezione "EasyRankly Pro" di `CLAUDE.md`
   e in `docs/piano.md`, sezione 6.
7. **Pro 2: nomi nel codice** (prima della Fase 0.2 del Pro). Proposta: namespace `EasyRankly\Pro\`, text domain
   `erankly-pro`, option e hook `easyrankly_pro_`, meta `_easyrankly_pro_`, post type e tassonomie `erankly_` (si
   tengono `erankly_proposal` ed `erankly_memory`), categoria di ability `erankly-pro`.
8. **Pro 4: servizio di email transazionale** per i codici di attivazione della licenza (prima della Fase 2.3 del
   Pro). Nessuna proposta ancora.
9. **Pro 5: primo rilascio in vendita** (prima della Fase 8 del Pro). Proposta: Fasi 0–5 (agente, licenza,
   aggiornamenti, Search Console, DataForSEO e mappa delle parole chiave), perché il prezzo promette i dati delle
   parole chiave inclusi; link interni e contenuti arrivano come aggiornamenti.
10. **Modelli decisionali** (es. Jev di typesafe.ai): rimandati. L'idea era un "sistema 1" sul backend (regole
    deterministiche → modello decisionale → LLM solo se serve). Con l'AI pagata dall'utente, un modello sul backend
    sarebbe un costo nostro non coperto: da rivalutare dopo il lancio del Pro, con un test su 2–3 decisioni reali.

## Decise

Registro per argomento. Ogni voce dice cosa si è deciso, quando e perché; i dettagli di come è fatto stanno nei
documenti indicati.

### Prodotto e perimetro

- **Riscrittura da zero** (7–8 ottobre 2026): il vecchio plugin (circa 36.000 righe, due tabelle custom, job in cron,
  moduli) costava più da ripulire che da riscrivere. Branch `Refactory` (oggi `pre-release`), Alpha (oggi branch `Decommissioned`) solo come riferimento
  (`docs/piano.md`, sezione 1).
- **Agente AI tutto nel Pro, niente AI nel gratuito** (9 ottobre 2026): costruito nelle Fasi 3 e 5, tolto con la
  Fase 6, senza migrazione perché la riscrittura non era pubblicata. Invariante 7 di `CLAUDE.md`.
- **Nessun punto di estensione per il Pro** (9 ottobre 2026): il Pro usa solo ciò che il gratuito espone per sé
  (`docs/piano.md`, sezione 6). I due della Fase 5 sono stati tolti.
- **IndexNow** (9 ottobre 2026): non si fa. Le sitemap del core bastano per la scoperta dei contenuti.
- **Schema Product per WooCommerce** (10 ottobre 2026): non si fa. WooCommerce stampa già il suo JSON-LD `Product`
  e Google lo legge così com'è.
- **Local Business** (10 ottobre 2026): terzo tipo di identità del sito, accanto a organizzazione e persona. Una
  sola sede (tipo schema.org, indirizzo, telefono, coordinate, al massimo due fasce orarie al giorno) nell'option
  delle impostazioni, stampata nel nodo `#organization`. Niente sedi multiple, niente post type.
- **WooCommerce e ACF** (10 ottobre 2026): il plugin convive con entrambi senza conflitti; con WooCommerce si sono
  corretti il Negozio (campi SEO, canonical, sitemap) e `og:type`. Il resto si completa più avanti: W4 (carrello,
  checkout e account fuori dalla sitemap) è fatto (14 ottobre 2026), W1, W5, A1 e A2 sono qui sopra tra le decisioni
  aperte (`docs/compatibilita.md`).
- **Redirect 451** (10 ottobre 2026): per le pagine tolte per un ordine legale (DMCA, diritto all'oblio). Come il 410
  non ha destinazione e mostra il template 404 con il suo codice.
- **Import da Yoast e Rank Math** (10 ottobre 2026): si fa, più avanti. Solo meta (title, description, robots,
  canonical e social di contenuti e termini) e redirect, non le impostazioni globali; a lotti guidati dall'admin via
  REST con il cursore in un'option (niente cron). SEOPress e All in One SEO eventualmente dopo. Riferimento Alpha (branch `Decommissioned`):
  `includes/migrations/`. In roadmap, "Funzioni decise ma non ancora fatte".
- **Funzioni SEO delle versioni precedenti** (12 ottobre 2026), dopo il confronto di `main`, `beta` e `Alpha` con
  questo branch:
  - *Pagine allegato*: si riporta, ma senza un'impostazione nostra. Una casella nella pagina Indicizzazione legge e
    scrive l'option del core `wp_attachment_pages_enabled` (WordPress 6.4): spenta, il core porta l'indirizzo
    dell'allegato al file. Serve ai siti nati prima della 6.4, che hanno ancora le pagine e nessuna schermata per
    spegnerle. Non si cancella alla disinstallazione: è del core.
  - *Feed*: `X-Robots-Tag: noindex, follow` su tutti i feed, sempre, senza impostazione.
  - *Paginazione*: niente noindex né nofollow sulle pagine 2 e successive (Google lo sconsiglia: peggiora la
    scoperta dei contenuti). Il suffisso del titolo c'è già come variabile `{{page}}`.
  - *Direttive robots avanzate* (`max-snippet`, `max-video-preview`, `nosnippet`, `noimageindex`, `notranslate`,
    `indexifembedded`): non si fanno. `max-image-preview:large` lo stampa già il core; i casi rari si coprono con uno
    snippet PHP sul filtro `wp_robots`.
  - *Categoria principale*: non si fa (non interessa).
- **Custom code "Ovunque"** (9 ottobre 2026): una posizione solo per snippet PHP, su `plugins_loaded` in ogni
  richiesta come il `functions.php` di un tema, tranne sulla schermata Custom code. Serve a togliere o cambiare hook
  senza modificare file. Rischi: `docs/custom-code.md`.
- **Plugin Check in CI esclude solo `src/CustomCode/PhpRunner.php`** (8 ottobre 2026): segnala ogni `eval` come
  errore. Prima del rilascio si fa comunque un Plugin Check completo anche su quel file.

### Interfaccia admin

- **Stile WordPress classico** (9 ottobre 2026, Fase 4): Settings API su più pagine brevi, `WP_List_Table`, markup
  del core, nessun `wp-components` né CSS nostri sulle schermate del plugin. Perché: le prime schermate React avevano
  un aspetto diverso dal resto dell'admin e pesavano di più (`docs/piano.md`, sezione 3).
- **Uno stile admin unico** (12 ottobre 2026): un solo foglio `assets/admin.css` (circa 3 KB, nessun JS) veste il
  markup del core come le vecchie Beta e Alpha (box titolati, campi al 100%, interruttori on/off), sostituendo il
  "nessun CSS nostro" della voce sopra. Perché: aspetto più curato a costo quasi nullo, e uno stile deciso una volta
  sola. `composer architecture` impedisce che ogni modifica aggiunga regole nuove (CLAUDE.md, "Stile admin").
- **Pagina articoli con un contesto suo** nelle impostazioni, separato dagli archivi dei tipi di contenuto
  (12 ottobre 2026, PR #64).
- **Campi SEO nel form "Aggiungi" dei termini**: non si fanno (12 ottobre 2026): restano solo in "Modifica".

### Multilingua

- **Menu e stringhe del tema per lingua, senza tabelle** (9 ottobre 2026, Fase 7). Il testo vive in un oggetto del
  core e il plugin sceglie la sua variante per lingua: titolo e motto del sito nell'option (`option_blogname`,
  `option_blogdescription`); nei temi a blocchi i template part `{slug}-{lingua}` (`header-en`) e i pattern
  sincronizzati "Nome - IT" per "Nome". Prima della Fase 7 menu, titolo e motto erano quelli della lingua
  predefinita in tutte le lingue.
- **Pattern non sincronizzati esclusi** (9 ottobre 2026): inseriti diventano una copia modificabile dei blocchi, e
  sostituirla con un'altra versione cancellerebbe le modifiche. Un testo in un template va spostato in un template
  part o in un pattern sincronizzato.
- **Categorie e tag non si traducono**: valgono per tutte le lingue e i loro archivi mostrano i contenuti della lingua
  corrente. I testi SEO dei termini valgono solo nella lingua predefinita; nelle altre si usano i template
  (12 ottobre 2026, PR #64).
- **Multilingua solo con i temi a blocchi** (9 ottobre 2026, rivisto il 10 ottobre). SEO, sitemap, redirect e custom
  code funzionano con ogni tema; con un tema classico cambiano lingua contenuti, indirizzi, titolo e motto, ma non
  menu, widget e testi del Customizer, e la pagina Lingue lo dice con un avviso. Le posizioni di menu per lingua dei
  temi classici sono state tolte il 10 ottobre (coprivano solo i menu e lasciavano il resto del tema a metà). Perché
  non togliere del tutto i temi classici: il codice solo per loro era circa l'1% del plugin, ma si sarebbe esclusa la
  maggior parte dei siti WordPress (analisi del 10 ottobre 2026).
- **Menu per lingua dalla pagina Lingue tolto** (12 ottobre 2026, PR #66): il menu di una lingua sta nel suo template
  part (`header-en` con il blocco Navigazione che punta al menu inglese). Lo scambio del menu nel blocco
  `core/navigation` faceva la stessa cosa e risparmiava solo di duplicare l'header. Le scelte salvate vengono
  ignorate e spariscono dall'option al primo salvataggio, senza migrazione.
- **Lingua predefinita bloccata** (10 ottobre 2026): la pagina Lingue rifiuta di cambiare la prima lingua finché
  esistono contenuti senza lingua, che altrimenti cambierebbero lingua da soli.
- **Etichetta "Home" del breadcrumb per lingua** e `og:locale:alternate` per le traduzioni (12 ottobre 2026, PR #64).

### Requisiti, prestazioni e dati

- **Requisiti minimi il più bassi possibile senza peggiorare il codice** (9 ottobre 2026). PHP 8.0 (`match`, `?->`,
  `mixed`), verificato dalla CI su PHP 8.0. WordPress 7.0: lo richiede solo il breadcrumb (blocco `core/breadcrumbs`
  e i suoi filtri); senza, il minimo sarebbe 6.6 (`PluginDocumentSettingPanel`), poi 6.5
  (`wp_is_serving_rest_request()`) e 6.3 (`wp_cache_set_last_changed()`).
- **Una query in più sugli archivi di termine e sulla pagina articoli** (12 ottobre 2026): i meta dell'oggetto
  interrogato, che il core lì non carica; nessuna con una cache persistente. Eccezione scritta in `CLAUDE.md`,
  sezione Prestazioni, e contata da `tests/QueriesTest.php`.
- **WooCommerce nominato nel codice una volta** (12 ottobre 2026): la pagina Negozio senza prodotti esce dalla sitemap
  leggendo `woocommerce_shop_page_id`, solo nelle richieste di sitemap (W7). Dal 14 ottobre 2026 allo stesso modo
  escono carrello, checkout e account (W4), con le option `woocommerce_{pagina}_page_id`.
- **Disinstallazione completa anche in multisite** (12 ottobre 2026, PR #71): `uninstall.php` cicla su ogni sito con
  `get_sites()` a lotti di 100 e `switch_to_blog()`. La regola "nessuna funzione multisite" vale per il
  funzionamento normale. Principio di Enthony: un bisogno importante batte una regola generale; va sollevato, non
  saltato.
- **Debito tecnico accettato** (12 ottobre 2026, PR #70): la query dei pattern e dei template part nelle lingue non
  predefinite resta (misurata: non aggiunge query, precarica per il core; l'alternativa sarebbe una mappa in
  un'option autoload); Redirect e Custom code tengono REST e salvataggio separati (permessi diversi); lo stile
  dell'anteprima dell'immagine social nell'editor resta inline (un file CSS per sette regole costerebbe di più).
- **Redirect con la query string** (12 ottobre 2026, PR #64): casella per redirect, spenta di default, che aggiunge
  alla destinazione la query string dell'indirizzo visitato.

### Pubblicazione

- **La riscrittura esce come 2.0.0** (12 ottobre 2026): lo slug `easyrankly` ha già la 1.0.0 su WordPress.org (meno di
  10 installazioni), quindi è un aggiornamento con un commit SVN, senza una nuova revisione. Non serve un testo che
  giustifichi gli snippet PHP al team di revisione (CC-10 tolto, PR #69).
- **Nessuna migrazione dalla 1.0.0** (12 ottobre 2026): meta `_erankly_*`, option, tabelle e cron della 1.0.0 restano
  orfani; il changelog dice che le impostazioni non vengono importate.
- **Controllo di sicurezza completo prima della 2.0.0** (12 ottobre 2026): per il gratuito è un bloccante della
  pubblicazione (roadmap, Fase 8).

### Sicurezza

- **Il piano sta in `docs/sicurezza.md`, i risultati fuori dal repository** finché non sono corretti (9 ottobre
  2026): il repository è pubblico. Le PR di correzione descrivono il rinforzo senza la ricetta d'attacco.
- **Firma HMAC della cache degli snippet** (12 ottobre 2026, PR #73): HMAC con `wp_salt( 'auth' )` su ID, tipo,
  posizione e codice di ogni snippet in `easyrankly_snippets` (chiave `sig`); `Runner` non esegue ciò che non torna e
  ricostruisce la cache dagli snippet. Nessuna option o meta nuova; dopo un cambio di chiavi la cache si rifà alla
  prima pagina.
- **Rischi residui del controllo di sicurezza** (13 ottobre 2026, Fase 8.6, Enthony: "sistemiamoli tutti"):
  - *Snippet PHP spenti da un visitatore (CC-06)*: un errore catturabile viene solo registrato (il primo) e mostrato
    all'admin; lo snippet si spegne solo per un errore fatale. Il prezzo: uno snippet davvero rotto fallisce a ogni
    richiesta finché un admin non lo corregge.
  - *Redirect forzato e pagina spostata*: un redirect forzato per il nuovo indirizzo di una pagina spostata resta
    attivo (è una scelta dell'amministratore); chi ha spostato la pagina vede un avviso. Gli altri redirect per quel
    indirizzo si disattivano come prima.
  - *Schermata Custom code*: gli snippet "Ovunque" restano fermi lì solo per chi può gestire gli snippet.
  - *Canonical verso un altro sito*: solo chi può modificare i contenuti altrui (`edit_others_posts`); per gli altri
    il valore viene scartato. Il noindex sui propri contenuti resta a chi li modifica.
  - *Accettato*: chi scrive direttamente nel database ottiene esecuzione di PHP, come con `active_plugins` del core;
    scritto in `CLAUDE.md`, sezione Sicurezza.
- **Strumenti di analisi: solo quelli già presenti** (12 ottobre 2026): PHPCS con `WordPress.Security`, PHPStan,
  Plugin Check, `composer architecture`. Semgrep no: da riconsiderare solo se il controllo trova un tipo di problema
  che questi strumenti non vedono.

### EasyRankly Pro (dettagli nel repository `easyrankly-pro`)

- **Il Pro sostituisce "EasyRankly Cloud"** (9 ottobre 2026): niente abbonamento che rivende AI e dati con un
  ricarico; si vende un plugin con licenza annuale che include i dati che costano. AI sempre con le chiavi
  dell'utente.
- **Prezzi confermati** (9 ottobre 2026): vivono solo sul backend e in Stripe, cambiarli non richiede un rilascio.
- **Vendita, licenze e download con un backend nostro e Stripe Managed Payments** (9 ottobre 2026). Scartati
  Freemius (SDK a runtime, circa 7%), Lemon Squeezy (in migrazione verso Managed Payments), servizi di licenze
  dedicati, SureCart e WooCommerce (legherebbero la vendita a un sito WordPress).
- **Noindex su pagine povere** (9 ottobre 2026): azione proponibile del Pro, rischio alto; l'AI la propone con i dati,
  l'utente sceglie, nessuna approvazione automatica.
- **Repository e domini del backend** (12 ottobre 2026): repository privato `easyrankly-backend`, API su
  `api.easyrankly.com`, OAuth su `connect.easyrankly.com` (Pro, decisione 3).
- **Versione minima del Pro** (12 ottobre 2026): WordPress 7.0 e PHP 8.0, come il gratuito (Pro, decisione 6).
