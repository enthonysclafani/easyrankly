# Piano di EasyRankly 3

Questo documento raccoglie la visione del prodotto e le decisioni prese, con il loro perché.
`CLAUDE.md` contiene le regole operative; `docs/roadmap.md` l'avanzamento. Se una decisione cambia,
si aggiorna prima questo file, poi le regole e la roadmap.

## 1. Perché un nuovo plugin

Il vecchio EasyRankly (branch `main`, `beta`, `Alpha` di questo stesso repository) è cresciuto per moduli:
circa 36.000 righe di PHP, due tabelle custom (`erankly_redirects`, `erankly_languages`), job in cron per
migrazioni e import, form, strumenti database, multisite. Ripulirlo costerebbe più che riscriverlo.

Si riparte da un repository pulito sul branch `Refactory`, usando Alpha **solo come riferimento** per la logica già
testata (meta, schema, sitemap, matching dei redirect, editor del multilingua). Obiettivo: circa 10–12.000 righe.

## 2. Principi non negoziabili

1. **Zero tabelle custom.** Solo Options API, meta, post type non pubblici, tassonomie nascoste.
2. **Zero cron.** Il lavoro lungo si fa a lotti guidati dall'admin, anche nel Pro.
3. **Zero script nel frontend.** Lo schema JSON-LD è dati, non codice; il selettore di lingua è solo HTML.
4. **Solo API native di WordPress**, comprese quelle AI nel Pro: AI Client, Connectors, Abilities API.
5. **Nessun sistema a moduli.** Il plugin gratuito è unico e definito. L'unica estensione è EasyRankly Pro,
   un plugin separato e autonomo: nel gratuito non c'è nessun codice per il Pro, nemmeno punti di estensione dedicati
   (decisione di ottobre 2026, sezione 6).
6. **Nessuna funzione dedicata a multisite**, ma nessun conflitto: solo API per sito.
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
| Redirect | 301/302/307/410, esatti e regex | Post type `erankly_redirect`; hash della sorgente in `post_name` (indicizzato); ricerca solo su 404 più una piccola lista di "forzati" |
| Custom code | Snippet HTML e PHP in head, apertura del body, footer | Post type `erankly_snippet`; PHP solo con `edit_plugins`, controllo della sintassi senza esecuzione, disattivazione automatica in caso di errore, modalità sicura |
| Multilingua | Singolo sito: lingua per contenuto, gruppi di traduzioni, prefisso nella URL, hreflang, sitemap per lingua; menu e stringhe del tema per lingua (Fase 7) | Tassonomie nascoste (come Polylang); blocco selettore renderizzato lato server; menu e testi per lingua nella option e nei filtri del core, senza tabelle |
| EasyRankly Pro (plugin a parte) | Agente AI con proposte da approvare (sezione 5) e lavoro operativo di un SEO di agenzia: Search Console, parole chiave e cluster, link interni, contenuti, report | Plugin separato e autonomo, fuori da WordPress.org; usa solo ciò che il gratuito espone già (sezione 6) |

**Fuori perimetro**, con il motivo:

| Esclusa | Perché |
|---|---|
| Log dei 404, contatori di visite | Scrivono nel DB a ogni visita e rompono la cache; Search Console fornisce i 404 visti da Google |
| Punteggio SEO / analisi di leggibilità in tempo reale | Molto JS, valore discutibile; le proposte dell'agente del Pro fanno meglio lo stesso lavoro |
| Indice dei link in tabella | Sostituito da una tassonomia nascosta (vedi sezione 5) |
| Sitemap news e video, form, pulizia "bloat" | Fuori dalla direzione del prodotto |
| Funzioni multisite | Decisione esplicita: nessun codice dedicato |
| Google Indexing API | Google la consente solo per offerte di lavoro e dirette |
| IndexNow | Decisione di ottobre 2026: non interessa. Le sitemap del core bastano per la scoperta dei contenuti |
| Pubblicazione automatica | Rischio "scaled content abuse": i contenuti AI nascono bozze |

### Interfaccia admin: stile WordPress classico

Decisione di ottobre 2026. Le prime schermate (impostazioni, redirect, custom code, agente) sono app React con i
componenti di `@wordpress/components`: una pagina di impostazioni lunga, a schede grandi, e un aspetto diverso da
quello del resto dell'admin. Si torna allo stile delle schermate di WordPress, per uniformità e leggerezza.

- **Impostazioni su più pagine brevi**, come Impostazioni → Generali, Lettura, Permalink. Proposta:
  - *Generali*: separatore del titolo, breadcrumb (etichetta della home, tassonomia per tipo di contenuto);
  - *Schema e social*: identità (organizzazione o persona, nome, logo, profili), immagine social predefinita, utente X;
  - *Titoli e descrizioni*: template per contesto, una lingua per volta (scelta con un selettore che ricarica la pagina);
  - *Indicizzazione*: noindex per contesto, regole aggiunte a robots.txt;
  - *Lingue*: elenco delle lingue, una riga per lingua più una riga vuota per aggiungerne una.
- **Impostazioni con la Settings API del core**: `add_settings_section()`, `add_settings_field()`, `settings_fields()`,
  form inviato a `options.php`, markup `form-table` e `submit_button()`. Nessun JS, tranne la scelta di un'immagine
  dalla libreria media. Resta una sola option, `easyrankly_settings`: ogni pagina invia solo i suoi campi e
  `Settings::sanitize()` li unisce a quelli salvati (lo fa già). `/wp/v2/settings` resta com'è.
- **Redirect e Custom code** come Articoli e Utenti: elenco con `WP_List_Table` (azioni di riga, ricerca,
  paginazione, azioni di gruppo) e una schermata "Aggiungi" / "Modifica" con un form classico inviato alla
  schermata stessa (nonce, capability), che salva tramite le route REST esistenti con `rest_do_request()`.
  Il codice degli snippet si scrive con l'editor del core (`wp_enqueue_code_editor()`, lo stesso dell'editor dei
  temi), che rispetta la preferenza dell'utente di disattivare l'evidenziazione.
- **Agente AI** (passa al Pro con la Fase 6): resta in JS, perché l'analisi a lotti la guida il browser e le proposte si accettano senza ricaricare,
  ma con markup classico (`nav-tab-wrapper` per Proposte e Memoria, `wp-list-table`, `notice`, `button`).
  "Modifica e accetta" si apre nella riga, come la Modifica rapida degli articoli.
- **Editor a blocchi e schermate dei termini** non cambiano: il pannello nell'editor usa già i componenti che l'editor
  carica comunque; i campi dei termini sono già PHP classico.
- **CSS**: nessun foglio di stile del plugin e il plugin non accoda `wp-components` sulle sue schermate, solo le classi
  dell'admin del core (il core carica comunque `wp-components` in tutto l'admin per la palette dei comandi). Una regola CSS nostra va motivata. Gli entry point `settings`, `redirects` e `snippets`
  spariscono da `package.json`: meno JS da compilare e da caricare.

## 4. Dove vivono i dati

| Dato | Dove |
|---|---|
| Impostazioni | Una option autoload `easyrankly_settings` |
| SEO per contenuto | Post meta e term meta registrati con schema |
| Redirect, snippet | Post type non pubblici |
| Proposte e memoria dell'agente (Pro) | Post type non pubblici del Pro; stati delle proposte con `register_post_status()` |
| Lingue | Option + tassonomia `erankly_language` |
| Gruppi di traduzione | Tassonomia `erankly_translation` |
| Cluster di parole chiave, mappa dei link interni | Tassonomie nascoste del Pro |
| Chiavi AI dell'utente (Pro) | Connectors del core (Impostazioni → Connettori) |
| Token di Search Console e della licenza | Option del Pro cifrate con `sodium_crypto_secretbox()` |
| Storico del traffico | Nessuno: Search Console conserva già 16 mesi, si confrontano i periodi al momento |
| Dati remoti (Search Console, DataForSEO, AI) | Transient con scadenza |

## 5. Agente AI (EasyRankly Pro)

Decisione di ottobre 2026: l'agente vive tutto in EasyRankly Pro, anche le funzioni che prima erano nel gratuito
(meta e social, testo alternativo, redirect per contenuti cestinati, memoria). Il gratuito non ha AI. Il codice scritto
nelle Fasi 3 e 5 si porta nel repository del Pro e si toglie da qui (Fase 6 della roadmap). Questa sezione è il
progetto dell'agente: vale per il Pro.

### Ciclo

**Osserva → Proponi → Approvi tu → Esegue → Impara.**

### Le "mani": Abilities API

Ogni azione è un'ability con schema di input e output, `permission_callback` e annotazioni
`readonly`/`destructive`. Le ability di sola lettura l'AI le usa liberamente; quelle che scrivono generano solo proposte.
L'AI Client del core sa fare function calling con le ability (`using_abilities()`).

### Proposte e dashboard Accetta / Rifiuta

- Post type `erankly_proposal`; stati: in attesa, accettata, rifiutata, superata, fallita.
- Ogni proposta contiene: azione e parametri validati, motivazione, dati a supporto (es. "1.240 impressioni, CTR 0,8%"),
  confidenza, anteprima delle differenze, impronta dell'oggetto originale, valore precedente.
- **Accetta**: rivalida tutto con i permessi di chi accetta; se l'oggetto è cambiato, la proposta è "superata".
- **Modifica e accetta**, **Rifiuta** (con motivo facoltativo), **Annulla** (ripristina il valore precedente).
- Lo storico delle proposte è anche il registro di tutto ciò che è stato fatto.
- Approvazione automatica: **spenta**. In futuro, eventualmente, per categoria con soglia di confidenza scelta dall'utente.

### Cosa può proporre

| Area | Esempi | Dove | Rischio |
|---|---|---|---|
| Meta e social | Title e description mancanti o deboli; con Search Console, per query con molte impressioni e pochi clic | Pro | basso |
| Immagini | Testo alternativo mancante | Pro | basso |
| Redirect | Contenuto cestinato → redirect; 404 visto da Google → pagina più simile | Pro | medio |
| Link interni | "Aggiungi un link da A a B in questo paragrafo"; pagine pilastro per cluster | Pro | medio |
| Contenuti | Aggiornamento di sezioni datate, rafforzamento delle pagine in posizione 8–20 | Pro | medio |
| Contenuti nuovi | Piano editoriale → brief → bozza (mai pubblicata), con i punti da verificare | Pro | medio |
| Multilingua | Bozza tradotta collegata al gruppo di traduzioni | Pro | medio |
| Indicizzazione | Noindex su pagine povere: l'AI lo propone, l'utente sceglie (decisione di ottobre 2026) | Pro | alto |

**Mai proponibili**: custom code, impostazioni, robots.txt, cancellazioni, pubblicazione, utenti e ruoli.

### Il lavoro da "SEO di agenzia"

Tutto lavoro di EasyRankly Pro.

1. **Onboarding**: domande su attività, pubblico, servizi, concorrenti; lettura del sito; tutto in memoria.
2. **Strategia keyword**: mappa parola chiave → pagina (una keyword principale per pagina), cannibalizzazioni,
   lacune rispetto ai concorrenti.
3. **Piano editoriale** mensile con brief.
4. **Contenuti nuovi**: bozze in blocchi nativi con link interni, meta, social, testo alternativo, schema.
5. **Interconnessioni**: alla pubblicazione, link dagli articoli correlati; pagine pilastro.
   Mappa dei link senza tabella: tassonomia nascosta dove ogni termine è una pagina di destinazione; il conteggio
   dei post per termine è il numero di pagine che la linkano. Suggerimenti semantici con embedding (AI Client) salvati nei meta.
6. **Ottimizzazione continua** con i dati di Search Console.
7. **Report mensile**: cosa è stato fatto e con quali risultati.

Cosa resta all'umano: link building e digital PR, esperienza reale nei contenuti (E-E-A-T), decisioni di business,
SEO tecnica lato server. Posizionamento: **l'agente fa il lavoro operativo, l'utente è il direttore che approva**.

### Memoria del progetto

- Post type `erankly_memory`: una voce per fatto, in Markdown (tono, pubblico, regole del brand, pagine strategiche,
  preferenze apprese, decisioni). Revisioni native come cronologia; import/export in `.md`.
- Non su disco: filesystem in sola lettura su molti hosting, più server, backup che escludono i file, rischio di lettura dal web.
- **Auto-apprendimento tramite contesto**, non riaddestramento: rifiuti motivati e modifiche alle proposte diventano
  preferenze visibili e modificabili; il tasso di accettazione per tipo regola cosa proporre.

### Proattività senza cron

| Innesco | Dove | Esempi |
|---|---|---|
| Eventi editoriali (pubblicazione, cestino, cambio slug) | Gratuito e Pro | Redirect per contenuto cestinato; meta e alt mancanti; link dagli articoli correlati (Pro) |
| Apertura della dashboard | Gratuito e Pro | Analisi a lotti dal browser se l'ultima ha più di 24 ore; dati di Search Console (Pro) |

Nessuna analisi pianificata, nemmeno nel Pro: senza cron, l'agente lavora quando qualcuno usa il sito o la dashboard.
I flussi in più passi (ricerca → brief → bozza → link → meta) sono sequenze di richieste REST guidate dall'admin:
mai un'unica richiesta lunga.

### Sicurezza dell'agente

- Contenuti, query di Search Console, SERP e pagine esterne sono **dati non fidati** (prompt injection).
- Validazione deterministica fuori dal modello: allowlist delle azioni, schema, domini esterni bloccati o segnalati,
  tetto giornaliero di proposte.
- Esecuzione sempre con i permessi di chi accetta.

## 6. EasyRankly Pro e monetizzazione

Decisione di ottobre 2026. Sostituisce "EasyRankly Cloud": niente più abbonamento che rivende AI e dati con un
ricarico. Si vende un plugin, con una licenza annuale che include i dati che costano.

### Modello

- **Gratuito, su WordPress.org, completo**: SEO, sitemap, redirect, custom code e multilingua, senza AI. Nessuna
  funzione bloccata, nessun riferimento al Pro nel codice.
- **EasyRankly Pro, plugin a parte con licenza annuale**: l'agente AI della sezione 5, con il lavoro da "SEO di
  agenzia" (Search Console, parole chiave e cluster, link interni, contenuti, report). L'agente è la prima funzione del
  Pro, non l'unica: il Pro è organizzato per funzioni (cartella e namespace propri per ciascuna) così che se ne possano
  aggiungere altre, senza un sistema a moduli. Nome nel codice: `erankly-pro`. Venduto fuori da WordPress.org, come raccomandano le sue
  [linee guida](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) per il codice a
  pagamento. Richiede il gratuito (`Requires Plugins: easyrankly`, supportato dal core dalla 6.5).
- **AI sempre con le chiavi dell'utente**, anche nel Pro (Impostazioni → Connettori). Non rivendiamo l'AI: niente
  costi variabili che non controlliamo.
- **Search Console e DataForSEO inclusi nel prezzo**: li paghiamo noi. Search Console è gratuita (solo quote);
  DataForSEO ha un costo per richiesta, tenuto sotto controllo da quote per licenza e da una cache condivisa.
- La licenza dà diritto ad aggiornamenti, assistenza e dati inclusi. Il codice è GPL come quello del gratuito.
  A licenza scaduta il Pro resta installato: si fermano aggiornamenti, Search Console e DataForSEO (passano dal
  backend); ciò che usa solo l'AI dell'utente continua a funzionare.

### Prezzo

Confermato a ottobre 2026. I prezzi vivono solo sul backend e in Stripe, non nel codice dei plugin: cambiarli
non richiede un rilascio. Restano da verificare i prezzi e i termini di DataForSEO (vedi "Da verificare prima di vendere").

Licenza annuale con rinnovo automatico, prezzi IVA esclusa (l'IVA la aggiunge e la versa Stripe al checkout):

| Licenza | Siti | Prezzo all'anno | Dati DataForSEO inclusi (tetto di spesa nostro) |
|---|---|---|---|
| Personale | 1 | €99 | $1 al mese |
| Professionale | 5 | €249 | $2,50 al mese, condivisi tra i siti |
| Agenzia | 20 | €599 | $6 al mese, condivisi tra i siti |

Regola: il costo dei dati resta intorno al 12% di quanto incassiamo, e la cache condivisa lo abbassa ancora.
Conto per la licenza Personale, con IVA italiana: Stripe trattiene circa €6 (3,5% di Managed Payments e commissione
della carta), restano circa €93. Il tetto di DataForSEO vale circa $12 all'anno. Il backend costa $5 al mese per tutti
i clienti insieme. Margine per licenza: circa €80, prima delle tasse e del nostro tempo.

Cosa compra $1 al mese, con i prezzi di DataForSEO stimati più avanti: circa 10 ricerche di idee per parole chiave
(100 risultati ciascuna), 2 aggiornamenti dei volumi di ricerca (fino a 1.000 parole chiave ciascuno), 1 analisi delle
parole chiave posizionate del sito e 200 controlli di SERP. È più di quanto serve a un sito per la mappa parola chiave →
pagina e il piano editoriale del mese. Le quote si mostrano nel Pro come azioni (es. "ricerche di parole chiave
rimaste"), non in dollari. Quando finiscono, le funzioni che usano DataForSEO aspettano il mese successivo; il resto
del Pro continua a funzionare.

Confronto (prezzi di listino per un sito, da recensioni di terze parti, non verificati sui siti dei produttori):
Yoast SEO Premium circa $119 all'anno, Rank Math Pro circa $96 il primo anno e $108 dal rinnovo (siti personali
illimitati), SEOPress Pro $49. €99 sta nella stessa fascia di Yoast e Rank Math; il valore del Pro sono i dati delle
parole chiave già inclusi e l'agente che lavora con Search Console.

Stima dei prezzi di DataForSEO (pay-as-you-go, in dollari, **da verificare**: il sito di DataForSEO non era
raggiungibile dall'ambiente di lavoro e i valori vengono dai riassunti dei risultati di ricerca; dal 1° luglio 2026
DataForSEO ha alzato del 20% i prezzi di Labs e Keywords Data, e la stima lo include per prudenza):

| Richiesta | Prezzo stimato |
|---|---|
| Idee e suggerimenti di parole chiave (Labs) | circa $0,0144 a richiesta + $0,000144 per risultato |
| Volumi di ricerca (Google Ads, fino a 1.000 parole chiave) | circa $0,07 (coda standard) – $0,11 (live) |
| Parole chiave posizionate di un sito (Labs, 1.000 risultati) | circa $0,16 |
| SERP di Google, prime 10 posizioni | $0,0006 (coda standard) – $0,002 (live) |

Fonti: [prezzi di Labs](https://dataforseo.com/pricing/dataforseo-labs/dataforseo-google-api),
[Google Ads](https://dataforseo.com/pricing/keywords-data/google-ads),
[SERP](https://dataforseo.com/pricing/google-serp/google-organic-serp-api),
[aggiornamento di luglio 2026](https://dataforseo.com/update/pricing-update-in-dataforseo-apis),
[pagamento minimo](https://dataforseo.com/help-center/minimum-payment).

### Cosa sta nel gratuito per il Pro

Niente (decisione di ottobre 2026): il Pro è autonomo e nel gratuito non c'è codice scritto per lui, nemmeno punti di
estensione generici. I due della Fase 5 (filtro `easyrankly_agent_actions` e `easyrankly_create_proposal()`) si
tolgono con l'agente. Il collegamento è il minimo:

- il Pro dichiara `Requires Plugins: easyrankly` e aggiunge le sue pagine come sottomenu di EasyRankly (slug `easyrankly`);
- legge e scrive i dati del gratuito con le API che il gratuito espone già per sé: i meta registrati con schema (anche
  via REST) e il post type `erankly_redirect` con la sua route `/wp/v2/easyrankly-redirects`, che applica le stesse
  regole dell'admin.

Questi nomi diventano un contratto: cambiarli nel gratuito rompe il Pro, quindi si cambiano solo con una decisione.

### Il backend del Pro

Cloudflare Workers con D1 (database) e KV (cache), in un repository separato. Costo fisso di partenza: piano Workers
Paid da $5 al mese per tutto l'account, con 10 milioni di richieste incluse
([prezzi](https://developers.cloudflare.com/workers/platform/pricing/)). Nessuna interfaccia web: fa quattro cose,
tutte come API chiamate dal Pro (solo da admin, REST o WP-CLI).

1. **Licenze**: riceve i webhook di Stripe, attiva i siti, risponde sullo stato della licenza.
2. **Aggiornamenti**: il Pro dichiara `Update URI` e risponde al filtro `update_plugins_{$hostname}` del core
   (dalla 5.8): il controllo degli aggiornamenti resta quello di WordPress. Lo zip si scarica solo con una licenza valida.
   Durante il cron del core il Pro usa solo la risposta in cache: la chiamata al backend parte dalle pagine admin.
3. **DataForSEO**: unico punto con la nostra chiave. Quote per licenza, cache condivisa tra i clienti (una parola
   chiave cercata da più siti si paga una volta), limiti anti-abuso.
4. **OAuth di Search Console** su `connect.easyrankly.com`: Google accetta solo redirect URI registrati e ogni cliente
   ha un dominio diverso. Il client secret resta sul server; il refresh token si salva cifrato sul sito e si rinnova
   tramite il backend; i dati di Search Console vanno dal sito a Google senza passare da noi.

### Licenza senza un'applicazione custom

L'utente non ha un "account" da noi. Ogni cosa ha già un posto:

| Cosa | Dove |
|---|---|
| Acquisto | Stripe Checkout con Managed Payments (link dal sito easyrankly.com) |
| Abbonamento, carta, disdetta, ricevute e fatture | Sito di Link (link.com), incluso in Managed Payments; in più il Customer Portal di Stripe, aperto dal Pro |
| Attivazione e dati della licenza | Pagina "Licenza" del Pro, dentro WordPress |

Attivazione con l'email d'acquisto, senza chiavi da copiare o da perdere:

1. Nel Pro, l'amministratore scrive l'email usata per l'acquisto.
2. Il backend controlla che a quell'email corrisponda una licenza attiva e invia un codice di 6 cifre.
3. L'amministratore inserisce il codice; il backend registra il sito e restituisce un token per quel sito, che il Pro
   salva cifrato con `sodium_crypto_secretbox()`.

La pagina "Licenza" del Pro mostra stato e scadenza, siti attivi (con "Scollega"), consumo delle quote del mese e il
pulsante "Gestisci abbonamento", che apre il Customer Portal di Stripe con una sessione creata dal backend. Se i siti
sono al limite, l'attivazione propone di scollegarne uno. Serve solo un servizio di email transazionale per i codici
(da scegliere).

Alternative scartate:

- **Freemius**: licenze, aggiornamenti, portale clienti e merchant of record già pronti, ma richiede il suo SDK nel
  plugin (dipendenza PHP a runtime, contro l'invariante 5) e costa circa il 7% più le commissioni di pagamento
  ([prezzi](https://freemius.com/help/documentation/getting-started/our-pricing/)).
- **Lemon Squeezy**: chiavi di licenza e portale clienti inclusi, senza SDK. Ma Stripe, che l'ha acquisita, sta
  portando i suoi venditori su Managed Payments: rischioso per un prodotto nuovo.
- **Servizi di licenze dedicati** (Keygen e simili): un fornitore in più, mentre il backend serve comunque per
  DataForSEO e Search Console.

### Pagamenti: Stripe Managed Payments

Managed Payments è il *merchant of record* di Stripe: il venditore legale è Stripe, che gestisce IVA UE, sales tax e
GST (oltre 80 paesi), frodi e contestazioni. Così non serve registrarsi all'OSS né gestire l'IVA in proprio.

- **Costo**: 3,5% per transazione riuscita, calcolato sull'importo IVA inclusa, più le commissioni di pagamento di
  Stripe ([prezzi di Managed Payments](https://support.stripe.com/questions/managed-payments-pricing)).
- **Vincoli**: solo prodotti digitali (software e servizi web rientrano), solo Checkout Sessions e Payment Links,
  verifica di idoneità da parte di Stripe ([idoneità](https://docs.stripe.com/payments/managed-payments/eligibility),
  [changelog](https://docs.stripe.com/payments/managed-payments/changelog)). L'Italia è tra i paesi ammessi.
- Rinnovo automatico annuale. Ricevute, fatture, rimborsi e assistenza sui pagamenti li gestisce Stripe tramite Link
  ([come funziona](https://docs.stripe.com/payments/managed-payments/how-it-works)).
- **I plugin non parlano mai con Stripe**: nessuna chiave Stripe, nessun webhook sul sito. Parla con Stripe solo il backend.

### Regole

- Le chiavi nostre (DataForSEO, Stripe, client secret di Google) **stanno solo sul backend**: il codice PHP è leggibile da chiunque.
- Il sito non espone endpoint pubblici: è sempre il Pro a chiamare il backend, da admin, REST o WP-CLI.
- Cache aggressiva sul backend: i dati delle parole chiave cambiano poco (buona parte del margine).
- Quote per licenza e protezioni anti-abuso: il costo dei dati deve restare una piccola parte del prezzo.

### Search Console

- OAuth 2.0 con scope `webmasters.readonly`; l'API è gratuita (solo quote).
- Server OAuth centrale (`connect.easyrankly.com`), come Site Kit e Rank Math. Il client secret resta sul server.
- Scope "sensibile": serve la verifica dell'app (dominio verificato, privacy policy, video). Senza verifica: avviso
  "app non verificata" e limite di 100 utenti. Non serve l'audit CASA (riguarda gli scope "restricted").

### Da verificare prima di vendere

- **WordPress.org**: il gratuito resta completo, senza funzioni bloccate né trialware; il codice a pagamento sta solo
  nel Pro, ospitato altrove.
- **Termini di DataForSEO** sull'uso dei dati dentro un prodotto venduto a terzi.
- **Verifica di idoneità di Stripe Managed Payments** dell'account. Se l'esito è negativo, si passa a Paddle: nel
  plugin non cambia nulla, perché la licenza la gestisce il backend.
- **GDPR**: il backend tratta email dei clienti, indirizzi dei siti e parole chiave cercate; servono privacy policy e
  registro dei trattamenti. Per l'AI, far scegliere all'utente il provider (evitare chi tratta dati fuori da UE/USA senza garanzie).

### Modelli decisionali

Rimandati. L'idea era un "sistema 1" sul backend (regole deterministiche → modello decisionale → LLM solo se serve),
con candidato Jev di typesafe.ai. Con l'AI pagata dall'utente, un modello decisionale sul backend sarebbe un costo
nostro non coperto: da rivalutare dopo il lancio del Pro, con un test su 2–3 decisioni reali.

## 7. Decisioni di ottobre 2026 (dalle decisioni aperte)

- **IndexNow**: non si fa (vedi "Fuori perimetro").
- **Prezzi del Pro**: confermati quelli della sezione 6; si aggiornano sul backend e in Stripe senza toccare i plugin.
- **Noindex su pagine povere**: azione proponibile del Pro, rischio alto. L'AI la propone con i dati a supporto,
  l'utente sceglie; nessuna approvazione automatica.
- **Multilingua**: copre anche menu e stringhe del tema, senza tabelle né moduli (Fase 7 della roadmap). Oggi:
  - menu, titolo e descrizione del sito sono gli stessi in tutte le lingue (quelli della lingua predefinita);
  - le stringhe del tema e di WordPress seguono il locale della pagina, se il pacchetto di lingua è installato;
  - categorie e tag non si traducono: valgono per tutte le lingue e i loro archivi mostrano i contenuti della lingua corrente.

  Strada proposta: titolo e descrizione del sito per lingua nella option `easyrankly_settings` (filtri
  `option_blogname` e `option_blogdescription`); un menu per lingua e per posizione, scelto con il filtro
  `theme_mod_nav_menu_locations` per i temi classici e con il filtro sugli attributi del blocco `core/navigation` per i
  temi a blocchi. Ogni option o meta nuovo va approvato nel piano del punto.
- **Requisiti minimi**: il minimo è quello che il codice richiede davvero, il più basso possibile senza peggiorare il
  codice né aggiungere compatibilità a mano. Oggi WordPress 7.0 serve all'agente (AI Client e Connectors), che passa
  al Pro: il Pro resta su 7.0, il gratuito può scendere a quanto chiedono le API del core che usa davvero. Il codice
  PHP non sembra usare nulla oltre PHP 8.0 (da confermare con PHPCompatibility).
- **Agente AI**: tutto in EasyRankly Pro, niente nel gratuito, nessuna migrazione (sezione 5 e Fase 6 della roadmap).
  Il Pro sta nel repository `easyrankly-pro`, si scarica dal sito di EasyRankly e richiede il gratuito.
- **Vendita, download e licenze del Pro**: backend nostro con Stripe Managed Payments, come nella sezione 6. Managed
  Payments incassa e gestisce le tasse ma non le licenze: licenze, attivazione e download degli aggiornamenti li
  gestisce il backend, che serve comunque per DataForSEO e Search Console. Scartate SureCart e WooCommerce (con
  abbonamenti e un gestore di licenze): legherebbero la vendita a un sito WordPress.

## 8. Decisioni aperte

- **Multilingua**: quali "stringhe del tema" oltre a menu, titolo e descrizione del sito (testi scritti nel tema o
  nei template parts, widget). Le stringhe tradotte dai file `.mo` seguono già il locale.
- Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin?
- Schema extra: Local Business, Product per WooCommerce.
