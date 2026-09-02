# EasyRankly — regole del plugin

Questo documento è normativo e autosufficiente. Report e conversazioni sono materiale storico, non fonti necessarie per interpretarlo. Ogni modifica a EasyRankly deve rispettarlo.

## Regola zero: deve sembrare WordPress core

EasyRankly deve sembrare una funzionalità inclusa in WordPress: stessi componenti, markup, spaziature, testi, interazioni, focus, accessibilità e convenzioni. Non basta copiarne i colori.

Se la parità visiva richiede API private o fragili, vince l'API pubblica stabile. L'eventuale scarto si corregge con il minimo CSS locale e con un test browser, mai copiando sottosistemi interni del core.

Ordine delle priorità:

1. sicurezza e integrità dei dati;
2. correttezza semantica e compatibilità pubblica;
3. API e comportamento nativi di WordPress;
4. accessibilità e coerenza visiva con il core;
5. semplicità e performance misurata.

## Governance

- Il maintainer del progetto decide le invarianti di prodotto; strumenti e collaboratori non possono derogarle implicitamente.
- Se un requisito reale contrasta con una regola, si modifica `rules.md` nello stesso intervento, motivando la decisione e aggiornando test e documentazione. Nessuna eccezione silenziosa.
- Un'eccezione temporanea deve indicare nel documento ambito, motivo e condizione di rimozione.
- Ogni nuova regola verificabile meccanicamente deve ricevere nello stesso intervento un controllo automatico proporzionato.

## Contratto di prodotto

- `Requires at least` e `Requires PHP` sono limiti minimi di supporto; devono coincidere tra header del plugin e `readme.txt`.
- `Tested up to` è la versione WordPress più alta verificata realmente, non il requisito minimo. Deve essere almeno pari a `Requires at least` e si aggiorna solo dopo i test.
- Il codice deve funzionare su WordPress 7.1 e PHP 7.4 finché quei limiti non vengono modificati esplicitamente. API più recenti richiedono un fallback verificato o l'aggiornamento dei requisiti.
- EasyRankly è l'unico proprietario SEO del sito. Non implementare modalità di coesistenza con altri plugin SEO.
- Un conflitto noto deve produrre un avviso amministrativo anche in network admin; non deve cambiare silenziosamente l'output né lasciare duplicati senza spiegazione.
- Gli hook di WordPress necessari al funzionamento non sono “apertura verso altri plugin” e vanno mantenuti.
- Non reintrodurre filtri pubblici `erankly_*` generici. Un hook del core, come `block_core_breadcrumbs_items`, va invece rispettato quando garantisce parità con WordPress.
- Non aggiungere compatibilità speculative. Le sole integrazioni multilingue previste sono TranslatePress e MultilingualPress, dopo test reali.
- EasyRankly non genera `hreflang`: appartiene al plugin multilingue.
- Non dichiarare compatibilità che non sia coperta da test.

## API e compatibilità

- Metodi e simboli sono privati per impostazione predefinita. Rendere pubblico solo un callback WordPress o un contratto deliberatamente documentato.
- Le API pubbliche esistenti non si rimuovono senza deprecazione, shim, changelog e test. Un cambio di major, da solo, non basta.
- Conservare gli shim `ERankly_Plugin::sanitize_head_code()` ed `erankly_get_business_profile()` finché il ciclo di deprecazione non è concluso esplicitamente.
- `ERankly_Plugin::get_business_profile()` resta l'API PHP pubblica del profilo normalizzato.
- Le chiavi persistenti `erankly_*` sono un confine di compatibilità: non rinominarle per uniformità estetica.
- Non esporre nuove costanti globali, helper globali o superfici di estensione senza un caso d'uso attuale.
- Le chiavi legacy in `uninstall.php` restano finché servono a eliminare correttamente dati di versioni precedenti e riportano la release in cui sono state ritirate.

## Footprint e dipendenze

- EasyRankly non effettua richieste di rete in frontend, admin, attivazione o uninstall. URL mostrati o serializzati come dati non sono richieste.
- Vietati telemetria, attivazione licenza, update checker proprietari, font o asset da CDN e servizi remoti impliciti.
- Non distribuire librerie runtime di terze parti. Riutilizzare WordPress e il browser; eventuali dipendenze solo di sviluppo restano fuori dallo ZIP.
- Il frontend non carica CSS o JavaScript del plugin. Riceve soltanto markup server-side e il Custom Code autorizzato; asset editor/admin solo nelle schermate che li usano.
- La persistenza è limitata a Options API e post, term e user meta con chiavi dichiarate e prefissate. Vietati tabelle custom, transients persistenti, cron, code e scritture su filesystem.
- Ogni nuova chiave persistente nasce come costante, con sanitizzazione, autorizzazione, uninstall e test di parità nello stesso intervento.

## Interfaccia nativa

- Usare Settings API, REST Settings, `wp.media` e i componenti pubblici di `wp.components`/`wp.editor` prima di scrivere UI propria.
- Non usare `@wordpress/private-apis`, componenti privati o dipendenze sperimentali per imitare il core.
- Riutilizzare classi core solo dopo averne verificato esistenza e comportamento nella versione minima supportata. Nessuna classe “fantasma”.
- Non combinare wrapper core con classi nate per un DOM diverso senza verificare margini, gap, altezza e allineamento.
- Popover e dropdown devono essere ancorati come l'equivalente core, mantenere il focus e funzionare da tastiera.
- Ogni controllo deve avere un nome accessibile. Usare `label_for` nei campi semplici; per campi compositi usare `fieldset`/`legend` e label individuali.
- Pulsanti iconici, modali e controlli devono avere `aria-label` coerenti; non usare il placeholder come label.
- Usare markup e classi delle notice di WordPress. Le notice devono essere visibili solo agli utenti con capability adeguata.
- Nessuna notice fuori dalle schermate EasyRankly, salvo il conflitto SEO dichiarato. Vietati redirect all'attivazione, widget dashboard, upsell e richieste di recensione.
- Evitare CSS proprio. Se indispensabile, deve essere minimo, scoped al plugin e basato sui token/valori del core; nessun override globale.
- Tutte le stringhe possedute dal plugin devono essere traducibili con il text domain letterale `easyrankly`; niente domini in variabili o concatenazioni traducibili.
- Usare `_n()` per i plurali e placeholder con `sprintf()`; aggiungere commenti `translators:` quando esistono placeholder.
- Gli script traducibili devono dichiarare `wp-i18n` e usare `wp_set_script_translations()`.
- Quando si replica un output visibile posseduto dal core, riusare la sua traduzione e il suo filtro. L'assenza intenzionale del text domain richiede una motivazione inline per PHPCS.
- Non dichiarare `Domain Path` senza distribuire realmente i cataloghi di traduzione.
- Le anteprime media devono eliminare `srcset`, `sizes`, `width` e `height` obsoleti quando cambia immagine.
- Testi, readme e changelog devono descrivere il comportamento reale dell'interfaccia.

## Sicurezza e dati

- Il Custom Code raw richiede sempre `unfiltered_html`; autorizzazione e sanitizzazione restano separate.
- Le impostazioni globali e le relative schermate richiedono `manage_options`; il codice raw globale richiede anche `unfiltered_html`.
- I dati per contenuto richiedono `edit_post`; i dati delle tassonomie richiedono `edit_term`. Non ridurre queste capability per comodità.
- Applicare le stesse regole a post meta, opzioni REST, revisioni e autosalvataggi. I filtri `*_for_revision` sono indispensabili e non vanno rimossi.
- Ogni `register_setting()` dichiara tipo, default e `sanitize_callback`; ogni dato esposto in REST ha uno schema preciso.
- Ogni `register_meta()` dichiara tipo, cardinalità, default, `sanitize_callback`, `auth_callback` e schema REST quando esposto.
- Ogni route REST custom ha una `permission_callback` reale, mai `__return_true` per operazioni riservate.
- Ogni form admin usa la Settings API oppure verifica esplicitamente nonce e capability prima di scrivere.
- Ai confini WordPress trattare gli input come `mixed`, validarli e normalizzarli. All'interno usare tipi nativi PHP 7.4 e non ripetere guardie già garantite.
- Sanitizzare opzioni e meta anche in lettura quando possono provenire da importazioni o versioni precedenti.
- Accettare per gli URL social solo URL web assoluti validi.
- Fare escaping nel contesto finale di output. Il markup trusted resta intatto soltanto dopo il controllo capability.
- Un valore non valido non deve essere scartato in silenzio: mostrare un `settings_error` chiaro e mantenere un comportamento deterministico.
- Getter e rendering frontend non devono scrivere nel database. Le migrazioni avvengono in un contesto amministrativo o di upgrade.
- L'uninstall multisito deve restare paginato e pulire sia i dati correnti sia quelli legacy noti.
- SQL diretto solo quando manca un'API WordPress adeguata, sempre tramite `$wpdb->prepare()` e placeholder corretti, incluso `%i` per gli identificatori. Vietata l'interpolazione di valori dinamici.

## Output SEO e ownership

- Per ogni richiesta deve esistere un solo title, canonical, meta description, set Open Graph/X e grafo JSON-LD gestito.
- Il Custom Code valido prevale sull'output automatico soltanto per l'elemento che possiede realmente.
- Analizzare HTML con `WP_HTML_Tag_Processor`, non con regex.
- Un claim JSON-LD proviene solo da un nodo radice o da un membro di `@graph`; un oggetto annidato non deve sottrarre ownership al nodo principale.
- I tipi schema equivalenti devono essere normalizzati in un unico gruppo di ownership.
- Ogni riferimento `@id` deve puntare a un nodo emesso o a un nodo manuale valido. Nessun riferimento pendente.
- Serializzare lo schema automatico con `wp_json_encode()` e stamparlo con `wp_print_inline_script_tag()`. Vietato costruire o stampare manualmente il tag `<script type="application/ld+json">`.
- Non inventare dati strutturati non dimostrabili, per esempio un `SearchAction` senza una reale implementazione.
- Breadcrumb visibili e `BreadcrumbList` devono coincidere. Riutilizzare helper e filtro `block_core_breadcrumbs_items` del core; se gli helper richiesti non esistono, omettere il solo breadcrumb schema senza fatal error.
- Canonical, `og:url`, URL e ID schema devono rappresentare il contesto e la lingua correnti.
- Home e pagina articoli devono seguire `show_on_front`; non usare ID residui di `page_on_front` o `page_for_posts` fuori dal loro contesto.
- Date Open Graph e schema devono descrivere lo stesso istante con la timezone WordPress coerente.
- `article:section` usa una categoria editoriale reale; la categoria predefinita da sola non è una sezione.
- Le regole comuni per preview, feed, robots, contenuti protetti, non pubblici e `noindex` devono vivere in predicati condivisi; le differenze semantiche fra feature devono essere esplicite e testate.
- Il merge di `X-Robots-Tag` resta case-insensitive e idempotente e non elimina direttive di WordPress o di altri componenti.
- La sitemap applica l'esclusione `noindex` solo alle query del provider interessato, preserva vincoli esistenti e non introduce join o `GROUP BY` evitabili.

## Multisito, lingue e cache

- Ogni cache runtime dipendente dal sito o dalla lingua deve includere almeno `get_current_blog_id()` e `get_locale()`; se dipende dal contenuto deve includere anche il post ID.
- `switch_to_blog()` e il cambio di locale non devono mai riutilizzare business profile, social settings, identity schema, variabili o Custom Code di un altro contesto.
- Le cache condividono un solo reset interno, usato da migrazioni e test.
- Caching solo per valori idempotenti nel contesto della richiesta. Non congelare callback o dati prima che il loro contesto WordPress sia stabile.
- MultilingualPress deve funzionare tramite le API multisito native. TranslatePress richiede test reali su URL tradotti, metadata, JSON-LD e sitemap.
- Il plugin multilingue possiede URL alternativi e `hreflang`; EasyRankly produce soltanto metadata corretti per la lingua corrente.

## Architettura e stile del codice

- `easyrankly.php` resta un bootstrap e una facciata piccoli; la logica vive nei moduli tematici `includes/`.
- Ogni trait possiede una responsabilità descritta dal proprio nome. Ownership resta in `erankly-ownership.php`, schema in `erankly-schema.php`, social in `erankly-social.php`, e così via.
- Evitare dipendenze circolari e metodi collocati nel trait sbagliato. Lo split deve essere logico, non solo fisico.
- Non introdurre container, service locator, factory, interfacce con una sola implementazione o autoloader senza necessità concreta.
- Riutilizzare prima WordPress core, poi il codice già presente, infine il minimo codice nuovo. Nessuna nuova dipendenza per poche righe.
- Centralizzare predicati e normalizzazioni condivise; non astrarre differenze realmente semantiche.
- Eliminare codice morto e difese divenute irraggiungibili, ma non sicurezza, accessibilità o compatibilità pubblica.
- I callback WordPress restano tolleranti sugli input. I metodi interni usano return type nativi compatibili con PHP 7.4.
- Tutti i file devono essere parsabili su PHP 7.4: vietati, tra gli altri, union type, `mixed` nativo, constructor property promotion, named arguments, `match`, nullsafe operator, enum, `readonly`, `str_contains()` e `str_starts_with()`.
- Non usare `declare(strict_types=1)`: la tolleranza ai confini segue il modello di WordPress core.
- I commenti spiegano sicurezza, invarianti e decisioni non ovvie. Vietati docblock che ripetono soltanto nome, parametri o `return void`.
- Le righe vuote utili alla leggibilità non sono un problema. Nessuna metrica di righe sostituisce chiarezza e correttezza.
- I nomi nuovi seguono i confini esistenti; non creare una terza famiglia di prefissi.

## Performance

- Una funzione pura costosa si calcola una volta per richiesta e per contesto.
- Cataloghi tradotti, analisi Head, impostazioni social, profilo business e identity schema non devono essere ricostruiti inutilmente.
- Non ottimizzare per ipotesi. Misurare prima e dopo, a freddo e a caldo.
- Le query sitemap vanno provate su volumi realistici prima di cambiarne strategia.
- Preferire cache di richiesta semplici; introdurre cache persistenti solo con invalidazione dimostrata.
- Deletion over addition: la soluzione più corta è corretta solo se risolve la causa e lascia una prova eseguibile.

## Test e release

- Ogni bug corretto lascia un test che falliva prima del fix.
- Le fixture dei test sono autonome, create dal test e rimosse in `finally`; non dipendono dai contenuti personali del sito.
- Ogni test ripristina query globali, post globale, opzioni, hook e cache che modifica.
- Coprire almeno: revisioni e capability, ownership manuale e JSON-LD annidato, breadcrumb core, CPT e tassonomie registrati tardi, cache sito/locale, front page, descrizioni dei template, social preview, sitemap di post e termini e compatibilità delle API pubbliche.
- Eseguire tutti gli smoke test su WordPress Studio e il lint PHP/JavaScript/JSON.
- PHPCS con WordPress-Extra e PHPCompatibilityWP per PHP 7.4 è un gate di release. Ogni `phpcs:ignore` richiede una motivazione inline.
- Plugin Check ufficiale, inclusi i controlli sperimentali, deve essere pulito sul pacchetto distribuibile.
- Renderizzare almeno home e un articolo reali: un solo grafo JSON-LD valido, nessun metadata duplicato, nessun warning PHP.
- Ogni modifica all'editor richiede un controllo browser: pannello, riga Indexing, popover, modale Custom Code, tastiera, focus e console.
- Versione dell'header plugin, `Stable tag` e versione del blocco devono coincidere.
- `Requires at least` e `Requires PHP` devono coincidere tra header e readme; `Tested up to` deve rappresentare una verifica reale.
- Ogni costante di persistenza deve comparire nell'uninstall; il test di conformità verifica meccanicamente questa parità.
- Readme e changelog si aggiornano nello stesso intervento quando cambia un comportamento pubblico.
- Lo ZIP deve essere costruibile in modo deterministico, verificato e attivabile su un sito pulito.
- Report, test, tooling e questo file sono artefatti di sviluppo e non entrano nel pacchetto distribuibile.

## Definition of done

Una modifica è conclusa soltanto quando:

- appare e si comporta come WordPress core;
- non introduce duplicati SEO, riferimenti schema pendenti o contaminazioni tra sito e lingua;
- mantiene sicurezza, dati persistenti e API pubbliche;
- non aggiunge superficie, dipendenze o astrazioni non necessarie;
- passa verifiche automatiche, rendering reale e controllo browser proporzionati al rischio;
- documentazione e pacchetto descrivono esattamente ciò che viene distribuito;
- non introduce rete, telemetria, asset frontend o nuova persistenza implicita.
