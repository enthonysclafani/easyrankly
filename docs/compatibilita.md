# Compatibilità con WooCommerce e ACF

Stato al 10 ottobre 2026. L'analisi è stata fatta leggendo il codice di **WooCommerce 11.x** (trunk 11.3.0-dev e
tag 10.2–11.2) e di **ACF 6.8.10**, senza installarli: va confermata su un sito con i due plugin attivi.
I punti aperti sono anche nella roadmap, sezione "Compatibilità con WooCommerce e ACF".

In breve: con **WooCommerce** non si rompe niente, ma il supporto non è completo (W1, W4, W5). Con **ACF** non ci sono
conflitti tecnici; restano due limiti (A1, A2).

## WooCommerce

### Fatto

- **W2. Campi SEO della pagina Negozio.** Da WooCommerce 11.0 l'archivio dei prodotti ha come oggetto interrogato la
  pagina Negozio (`WC_Query::pre_get_posts()`). `Meta::queried()` legge i meta di un `WP_Post` anche su
  `is_post_type_archive()`: titolo, description, noindex, canonical e immagine della pagina valgono per l'archivio.
- **W3. Canonical del Negozio.** `Canonical::archive_url()` costruisce l'URL dal tipo di contenuto della query, non
  dall'oggetto: il Negozio ha canonical (con il numero di pagina), `og:url` e `@id` dello schema corretti. Con il
  Negozio come pagina iniziale (non singolare) il canonical della home lo stampa il plugin.
- **W6. `og:type`.** `article` solo per i `post`, come lo schema `Article`: i prodotti sono `website`.

Test: `tests/Canonical/ArchivePageTest.php` riproduce la query di WooCommerce senza WooCommerce.

### Da fare

- **W1. Pannello SEO e Lingua sui prodotti.** WooCommerce spegne l'editor a blocchi per `product`
  (`use_block_editor_for_post_type`) e i nostri pannelli esistono solo lì: per un prodotto non si possono impostare
  i campi SEO né la lingua (i meta funzionano via REST). Proposta: un meta box nell'editor classico con gli stessi
  campi, solo per i tipi di contenuto che non usano l'editor a blocchi, salvato con nonce e `edit_post`. È
  un'eccezione alla regola "niente meta box classiche" di `CLAUDE.md`: va decisa. Coprirebbe anche Classic Editor e
  i tipi di contenuto senza supporto `editor`. Le categorie e i tag prodotto hanno già i campi.
- **W4. Sitemap.** WooCommerce mette in noindex carrello, checkout e account (filtro `wp_robots`), ma la sitemap delle
  pagine del core li elenca. Proposta: escludere gli ID nelle option `woocommerce_cart_page_id`,
  `woocommerce_checkout_page_id` e `woocommerce_myaccount_page_id` (autoload, nessuna query). Sarebbe il primo
  codice che nomina WooCommerce.
- **W5. Multilingua e negozio.** Oggi il multilingua non regge un negozio:
  - senza il pannello Lingua (W1) i prodotti restano nella lingua predefinita, quindi il negozio nelle altre lingue
    è vuoto;
  - "Crea traduzione" non copia i meta: il prodotto tradotto nasce senza prezzo, SKU, magazzino, galleria, attributi
    e varianti (post figli); anche copiandoli sarebbe un prodotto diverso, con il suo magazzino, e lo SKU deve essere
    unico;
  - carrello, checkout e account sono una pagina sola per WooCommerce: i suoi link portano alla lingua predefinita e
    la lingua cambia a metà acquisto; le email seguono la lingua del sito.
  Finché non si decide, il multilingua non è supportato sui negozi. Da valutare: togliere `product` dai tipi con
  lingua, per non svuotare il negozio.

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
  `@id` in conflitto. Non lo generiamo (decisione in `docs/piano.md`, sezione 7).
- Robots: noi e WooCommerce aggiungiamo solo restrizioni con `wp_robots`. Gli endpoint mandano `X-Robots-Tag: noindex`.
- Canonical di categorie, tag e filtri (`?filter_color=`, `?orderby=`): dal link del termine, senza parametri.
- Redirect: solo su 404, non toccano carrello, checkout o `wc-ajax`.
- Breadcrumb: "tassonomia per tipo di contenuto" funziona con `product` → `product_cat`.
- Sitemap: prodotti, categorie e tag prodotto dal core; varianti e ordini no (non pubblici).

## ACF

### Da fare

- **A1. Description dai campi ACF.** La description di ripiego è il riassunto di `post_excerpt` o `post_content` senza
  blocchi non testuali (`Template::excerpt()`): i campi ACF sono meta e i blocchi `acf/...` vengono tolti, quindi le
  pagine fatte solo di campi o blocchi ACF restano senza description (e senza `og:description`). Proposta: una
  variabile di template per un campo personalizzato (es. `%cf:nome_campo%`) letta con `get_post_meta()`, già in cache.
  Non nominerebbe ACF. È una funzione nuova: va decisa.
- **A2. Traduzioni con campi ACF.** `Translations::create_translation()` non copia i meta: la traduzione di una pagina
  fatta di campi ACF nasce vuota. Proposta: copiare i meta del post d'origine tranne quelli interni (`_edit_lock`,
  `_edit_last`, `_wp_old_slug`, `_thumbnail_id`, i nostri `_easyrankly_*`), compresi i riferimenti `_campo` →
  `field_…` che ACF usa per leggere i valori. Da decidere insieme a W5 (per i prodotti copierebbe anche lo SKU).

### Nessun conflitto

- Aggiungiamo il supporto `custom-fields` ai tipi pubblici; ACF toglie comunque il meta box `postcustom`
  (`remove_wp_meta_box`, predefinito `true`). I nostri meta iniziano con `_` e non compaiono.
- Editor a blocchi con meta box ACF: il salvataggio REST (i nostri meta) e quello dei meta box (i campi ACF) non si
  toccano.
- Tipi di contenuto e tassonomie creati con ACF: registrati su `acf/init` (dentro `init` a priorità 5), prima della
  nostra registrazione dei meta (`init` 99, più `registered_post_type`). Hanno campi SEO, sitemap e lingua come gli
  altri; senza supporto `editor` tornano al caso W1 (niente pannello).
- Termini: i nostri campi e quelli ACF stanno nello stesso form.
- Shortcode `[acf]`: lo togliamo senza eseguirlo, nessun valore finisce nella description.
