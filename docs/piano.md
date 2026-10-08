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
2. **Zero cron.** Il lavoro lungo si fa a lotti guidati dall'admin; la proattività "continua" vive sul Cloud.
3. **Zero script nel frontend.** Lo schema JSON-LD è dati, non codice; il selettore di lingua è solo HTML.
4. **Solo API native di WordPress** (core 7.0+), comprese quelle AI: AI Client, Connectors, Abilities API.
5. **Nessun sistema a moduli.** Un plugin unico e definito.
6. **Nessuna funzione dedicata a multisite**, ma nessun conflitto: solo API per sito.
7. **L'AI non scrive mai direttamente**: ogni modifica è una proposta che un umano approva.

Conseguenza principale: tutto ciò che è pesante o storico (andamento del posizionamento, analisi continue,
quote e crediti) vive su **EasyRankly Cloud**, non dentro WordPress.

## 3. Funzioni

| Area | Cosa fa | Come rispetta i principi |
|---|---|---|
| SEO | Title e description (template + override), robots, canonical, Open Graph e X, schema JSON-LD di base, robots.txt | Filtri del core: `document_title_parts`, `wp_robots`, `get_canonical_url`, `robots_txt` |
| Breadcrumb | Usa il blocco `core/breadcrumbs` del core; il plugin aggiunge BreadcrumbList | Filtro `block_core_breadcrumbs_items` |
| Sitemap | Estende le sitemap del core | `wp_sitemaps_*`, nessun generatore proprio |
| Redirect | 301/302/307/410, esatti e regex | Post type `erankly_redirect`; hash della sorgente in `post_name` (indicizzato); ricerca solo su 404 più una piccola lista di "forzati" |
| Custom code | Snippet HTML e PHP in head, apertura del body, footer | Post type `erankly_snippet`; PHP solo con `edit_plugins`, controllo della sintassi senza esecuzione, disattivazione automatica in caso di errore, modalità sicura |
| Multilingua | Singolo sito: lingua per contenuto, gruppi di traduzioni, prefisso nella URL, hreflang, sitemap per lingua | Tassonomie nascoste (come Polylang); blocco selettore renderizzato lato server |
| Agente AI | Lavoro operativo di un SEO di agenzia, sempre tramite proposte | Abilities API, AI Client del core, post type per proposte e memoria |

**Fuori perimetro**, con il motivo:

| Esclusa | Perché |
|---|---|
| Log dei 404, contatori di visite | Scrivono nel DB a ogni visita e rompono la cache; Search Console fornisce i 404 visti da Google |
| Punteggio SEO / analisi di leggibilità in tempo reale | Molto JS, valore discutibile; le proposte dell'agente fanno meglio lo stesso lavoro |
| Indice dei link in tabella | Sostituito da una tassonomia nascosta (vedi sezione 5) |
| Sitemap news e video, form, pulizia "bloat" | Fuori dalla direzione del prodotto |
| Funzioni multisite | Decisione esplicita: nessun codice dedicato |
| Google Indexing API | Google la consente solo per offerte di lavoro e dirette |
| Pubblicazione automatica | Rischio "scaled content abuse": i contenuti AI nascono bozze |

## 4. Dove vivono i dati

| Dato | Dove |
|---|---|
| Impostazioni | Una option autoload `easyrankly_settings` |
| SEO per contenuto | Post meta e term meta registrati con schema |
| Redirect, snippet, proposte, memoria | Post type non pubblici; stati delle proposte con `register_post_status()` |
| Lingue | Option + tassonomia `erankly_language` |
| Gruppi di traduzione | Tassonomia `erankly_translation` |
| Cluster di parole chiave, mappa dei link interni | Tassonomie nascoste |
| Chiavi AI dell'utente | Connectors del core (Impostazioni → Connettori) |
| Token di Search Console | Option cifrata con `sodium_crypto_secretbox()` |
| Storico del traffico | Nessuno: Search Console conserva già 16 mesi, si confrontano i periodi al momento |
| Dati remoti (Search Console, DataForSEO, AI) | Transient con scadenza |

## 5. Agente AI

### Ciclo

**Osserva → Proponi → Approvi tu → Esegue → Impara.**

### Le "mani": Abilities API

Ogni azione è un'ability `easyrankly/...` con schema di input e output, `permission_callback` e annotazioni
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

| Area | Esempi | Rischio |
|---|---|---|
| Meta e social | Title e description per query con molte impressioni e pochi clic | basso |
| Immagini | Testo alternativo mancante | basso |
| Redirect | 404 visto da Google → pagina più simile; contenuto cestinato con traffico → redirect | medio |
| Link interni | "Aggiungi un link da A a B in questo paragrafo"; pagine pilastro per cluster | medio |
| Contenuti | Aggiornamento di sezioni datate, rafforzamento delle pagine in posizione 8–20 | medio |
| Contenuti nuovi | Piano editoriale → brief → bozza (mai pubblicata), con i punti da verificare | medio |
| Multilingua | Bozza tradotta collegata al gruppo di traduzioni | medio |
| Indicizzazione | Noindex su pagine povere | alto |

**Mai proponibili**: custom code, impostazioni, robots.txt, cancellazioni, pubblicazione, utenti e ruoli.

### Il lavoro da "SEO di agenzia"

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
| Eventi editoriali (pubblicazione, cestino, cambio slug) | Plugin, gratuito | Redirect per contenuto cestinato; meta e alt mancanti |
| Apertura della dashboard | Plugin, gratuito | Analisi a lotti dal browser se l'ultima ha più di 24 ore |
| Analisi pianificata | EasyRankly Cloud, a pagamento | Analisi quotidiana di Search Console; il plugin scarica le proposte |

I flussi in più passi (ricerca → brief → bozza → link → meta) sono sequenze di richieste REST guidate dall'admin
o eseguite sul Cloud: mai un'unica richiesta lunga.

### Sicurezza dell'agente

- Contenuti, query di Search Console, SERP e pagine esterne sono **dati non fidati** (prompt injection).
- Validazione deterministica fuori dal modello: allowlist delle azioni, schema, domini esterni bloccati o segnalati,
  tetto giornaliero di proposte.
- Esecuzione sempre con i permessi di chi accetta.

## 6. EasyRankly Cloud e monetizzazione

### Modello

- **Gratuito (chiavi dell'utente)**: l'utente configura i suoi provider AI nei Connectors del core.
  L'agente lavora sugli eventi e all'apertura della dashboard.
- **EasyRankly Cloud (abbonamento a crediti)**: zero configurazione, analisi continua, Search Console, DataForSEO,
  eventuali modelli decisionali. Esempio di prezzo: N crediti al mese, 1 ricerca keyword = 5 crediti, 1 testo AI = 2 crediti.
  Il Cloud è registrato come provider dell'AI Client, quindi il plugin usa le stesse funzioni in entrambi i casi.

### Regole

- Le chiavi nostre (DataForSEO, provider AI, modelli decisionali) **non stanno mai nel plugin**: il codice PHP è leggibile da chiunque.
- Il plugin scarica le proposte dal Cloud; non espone endpoint pubblici in scrittura.
- Cache aggressiva sul Cloud: i dati delle keyword cambiano poco e una ricerca fatta da più clienti si paga una volta sola
  (buona parte del margine).
- Limiti per sito e protezioni anti-abuso.

### Search Console

- OAuth 2.0 con scope `webmasters.readonly`; l'API è gratuita (solo quote).
- Server OAuth centrale (es. `connect.easyrankly.com`), come Site Kit e Rank Math, perché Google accetta solo
  redirect URI registrati e ogni cliente ha un dominio diverso. Il client secret resta sul server.
- Scope "sensibile": serve la verifica dell'app (dominio verificato, privacy policy, video). Senza verifica: avviso
  "app non verificata" e limite di 100 utenti. Non serve l'audit CASA (riguarda gli scope "restricted").

### Stack proposto

- **Backend**: Cloudflare Workers con D1 (database), KV (cache) e cron trigger (sul backend i cron sono ammessi).
  Repository separato.
- **Pagamenti e licenze**: un *merchant of record* (Freemius, Lemon Squeezy o Paddle) che gestisce IVA UE, fatture e rimborsi.

### Da verificare prima di vendere

- **WordPress.org**: vietato il *trialware* (funzioni nel codice bloccate finché non paghi); consentito il *serviceware*
  (servizio esterno a pagamento), dichiarato nel readme con link a termini e privacy.
- **Termini di DataForSEO** sulla rivendita dei dati.
- **GDPR**: evitare provider che trattano dati fuori da UE/USA senza garanzie (es. DeepSeek); far scegliere il modello.

### Modelli decisionali

Possibile "sistema 1" per lo smistamento veloce: regole deterministiche → modello decisionale → LLM solo se serve.
Candidato: Jev di typesafe.ai (decisioni tipizzate con probabilità calibrata, costi dichiarati molto inferiori a un LLM).
È in early access, i numeri sono del produttore, non c'è lo schema pubblico dell'API né l'indicazione di dove tratta i dati.
Va integrato **solo sul Cloud, dietro un'interfaccia nostra**, dopo un test pratico su 2–3 decisioni reali
(es. "questo 404 merita un redirect?", "quale pagina è la destinazione giusta?").

## 7. Decisioni aperte

- Multilingua: solo SEO e collegamento delle traduzioni, o anche menu e stringhe del tema?
- Import da Yoast e Rank Math: solo meta e redirect, a lotti dall'admin?
- Schema extra: Local Business, Product per WooCommerce.
- IndexNow.
- Piattaforma di licenze e pagamenti.
- Requisiti minimi: WordPress 7.0 e PHP 8.1 (assunti in `CLAUDE.md`).
