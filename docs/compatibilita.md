# Compatibilità con WooCommerce e ACF

Stato al 14 ottobre 2026. L'analisi è stata fatta leggendo il codice di **WooCommerce 11.x** (trunk 11.3.0-dev e
tag 10.2–11.2) e di **ACF 6.8.10**, senza installarli: va confermata su un sito con i due plugin attivi.
I punti aperti sono anche nella roadmap, sezione "Compatibilità con WooCommerce e ACF"; quelli in sospeso
(W5, A2) sono in `docs/decisioni.md`, "Da decidere".

In breve: con **WooCommerce** non si rompe niente, ma il multilingua non regge un negozio (W5). Con **ACF** non ci sono
conflitti tecnici; resta un limite del multilingua (A2).

## WooCommerce

### Fatto

- **W2. Campi SEO della pagina Negozio.** Da WooCommerce 11.0 l'archivio dei prodotti ha come oggetto interrogato la
  pagina Negozio (`WC_Query::pre_get_posts()`). `Meta::queried()` legge i meta di un `WP_Post` anche su
  `is_post_type_archive()`: titolo, description, noindex, canonical e immagine della pagina valgono per l'archivio.
- **W3. Canonical del Negozio.** `Canonical::archive_url()` costruisce l'URL dal tipo di contenuto della query, non
  dall'oggetto: il Negozio ha canonical (con il numero di pagina), `og:url` e `@id` dello schema corretti. Con il
  Negozio come pagina iniziale (non singolare) il canonical della home lo stampa il plugin.
- **W6. `og:type`.** `article` solo per i `post`, come lo schema `Article`: i prodotti sono `website`.
- **W7. Negozio vuoto fuori dalla sitemap.** Senza prodotti pubblicati la pagina Negozio ha noindex (archivio vuoto),
  quindi la sitemap delle pagine la toglie, come la pagina articoli senza articoli. La pagina si legge dall'option
  `woocommerce_shop_page_id`, la stessa di `wc_get_page_id()`, solo nelle richieste di sitemap e solo se esiste il tipo
  di contenuto `product`. È il primo codice che nomina WooCommerce (12 ottobre 2026, scelta di Enthony).
- **W4. Carrello, checkout e account fuori dalla sitemap.** WooCommerce li mette in noindex (`wc_page_no_robots()` su
  `wp_robots`), ma la sitemap delle pagine del core li elencava. Ora li toglie leggendo gli ID dalle option
  `woocommerce_cart_page_id`, `woocommerce_checkout_page_id` e `woocommerce_myaccount_page_id` (autoload, nessuna
  query), solo nelle richieste di sitemap e solo se esiste il tipo di contenuto `product`, come per il Negozio (W7).

- **W1. Campi SEO e lingua sui prodotti.** WooCommerce spegne l'editor a blocchi per `product`
  (`use_block_editor_for_post_type`) e i nostri pannelli esistono solo lì. Dove l'editor a blocchi non si usa
  (prodotti, plugin Classic Editor, tipi senza supporto `editor` o senza REST) l'editor classico ha un meta box "SEO"
  con gli stessi campi (`Meta\Admin\ClassicFields`) e, con due lingue, un meta box "Lingua" con lingua, traduzioni,
  "Crea bozza", "Collega" e "Scollega" (`Multilingual\Admin\ClassicPanel`). Salvano con nonce, `edit_post` e i
  controlli delle route REST; i valori passano dalle stesse sanitizzazioni. Eccezione alla regola "niente meta box
  classiche" decisa il 14 ottobre 2026. Gli **allegati** restano senza campi SEO (scelta di Enthony, stesso giorno):
  la pagina di un allegato usa template e regole di contesto.

Test: `tests/Canonical/ArchivePageTest.php` riproduce la query di WooCommerce senza WooCommerce.

### In sospeso

- **W5. Multilingua e negozio.** Oggi il multilingua non regge un negozio:
  - i prodotti senza lingua restano nella lingua predefinita, quindi il negozio nelle altre lingue è vuoto finché non
    si traducono uno per uno (dal 14 ottobre 2026 si può, con il meta box "Lingua" di W1);
  - "Crea traduzione" non copia i meta: il prodotto tradotto nasce senza prezzo, SKU, magazzino, galleria, attributi
    e varianti (post figli); anche copiandoli sarebbe un prodotto diverso, con il suo magazzino, e lo SKU deve essere
    unico;
  - carrello, checkout e account sono una pagina sola per WooCommerce: i suoi link portano alla lingua predefinita e
    la lingua cambia a metà acquisto; le email seguono la lingua del sito.
  Finché non si decide, il multilingua non è supportato sui negozi. Da valutare: togliere `product` dai tipi con
  lingua, per non svuotare il negozio. In sospeso per scelta di Enthony (14 ottobre 2026), insieme ad A2; il 15 ottobre 2026 rimandato a una versione
  successiva (ipotesi 2.1.0), con la scelta del modello dei prodotti come prima decisione (`docs/decisioni.md`, punto 2).

### Lasciati così, di proposito

- Titoli degli endpoint dell'account (Ordini, Indirizzi…): il nostro `pre_get_document_title` stampa il titolo della
  pagina Account invece di quello dell'endpoint. Pagine in noindex: solo estetico.
- Doppio `WebSite` quando il Negozio è la home nei temi classici (WooCommerce aggiunge il suo con `SearchAction`):
  innocuo.
- Doppio `BreadcrumbList` solo se il template ha sia il blocco breadcrumb del core sia quello di WooCommerce: va
  evitato nel tema.
- Prodotti nascosti dal catalogo restano in sitemap, come nel core: WooCommerce non li mette in noindex.

### Cosa convive già

- Schema `Product`: lo stampa WooCommerce nel footer, separato dal nostro grafo (`#product` contro `#webpage`), senza
  `@id` in conflitto. Non lo generiamo (decisione in `docs/decisioni.md`).
- Robots: noi e WooCommerce aggiungiamo solo restrizioni con `wp_robots`. Gli endpoint mandano `X-Robots-Tag: noindex`.
- Canonical di categorie, tag e filtri (`?filter_color=`, `?orderby=`): dal link del termine, senza parametri.
- Redirect: solo su 404, non toccano carrello, checkout o `wc-ajax`.
- Breadcrumb: "tassonomia per tipo di contenuto" funziona con `product` → `product_cat`.
- Sitemap: prodotti, categorie e tag prodotto dal core; varianti e ordini no (non pubblici).

## ACF

### Fatto

- **A1. Description dai campi ACF.** La description di ripiego è il riassunto di `post_excerpt` o `post_content`
  senza blocchi non testuali (`Template::excerpt()`): le pagine fatte solo di campi ACF restavano senza description.
  Ora i template e gli override di title e description accettano `{{cf:nome_campo}}`, il valore di un campo
  personalizzato del contenuto o del termine (`Template::custom_field()`), letto con `get_post_meta()` /
  `get_term_meta()` dai meta già in cache: nessuna query in più. Solo valori di testo (gli array, come gallerie o
  relazioni, danno vuoto; un campo immagine dà il suo ID, quindi va usato con campi di testo), senza markup né
  shortcode, tagliati a 160 caratteri. Mai chiavi protette (`_…`, `is_protected_meta`), mai post protetti da
  password; i campi di un termine valgono solo nella lingua predefinita, come i suoi testi. Non nomina ACF (14 ottobre
  2026). Limite: i **blocchi ACF** salvano i loro valori dentro il contenuto, non nei meta, quindi non si leggono.

### In sospeso

- **A2. Traduzioni con campi ACF.** `Translations::create_translation()` non copia i meta: la traduzione di una pagina
  fatta di campi ACF nasce vuota. Proposta: copiare i meta del post d'origine tranne quelli interni (`_edit_lock`,
  `_edit_last`, `_wp_old_slug`, `_thumbnail_id`, i nostri `_easyrankly_*`), compresi i riferimenti `_campo` →
  `field_…` che ACF usa per leggere i valori. Insieme a W5 (per i prodotti copierebbe anche lo SKU): in sospeso per
  scelta di Enthony (14 ottobre 2026), rimandato con W5 (ipotesi 2.1.0).

### Nessun conflitto

- Aggiungiamo il supporto `custom-fields` ai tipi pubblici; ACF toglie comunque il meta box `postcustom`
  (`remove_wp_meta_box`, predefinito `true`). I nostri meta iniziano con `_` e non compaiono.
- Editor a blocchi con meta box ACF: il salvataggio REST (i nostri meta) e quello dei meta box (i campi ACF) non si
  toccano.
- Tipi di contenuto e tassonomie creati con ACF: registrati su `acf/init` (dentro `init` a priorità 5), prima della
  nostra registrazione dei meta (`init` 99, più `registered_post_type`). Hanno campi SEO, sitemap e lingua come gli
  altri; senza supporto `editor` hanno i meta box classici di W1.
- Termini: i nostri campi e quelli ACF stanno nello stesso form.
- Shortcode `[acf]`: lo togliamo senza eseguirlo, nessun valore finisce nella description.
