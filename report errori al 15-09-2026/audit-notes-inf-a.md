# INF-A notes

Working tree authoritative. Read-only: ogni riga dei file assegnati letta con Read+offset (non grep). Nessuna mutazione a plugin/test/git.

## Per-file

### easyrankly.php (1130 lines, reviewed 1–1130)
Purpose: Bootstrap plugin (costanti, runtime-state, option writers, `erankly_bootstrap`, activate/deactivate, rewrite signature, REST user-search / LocalBusiness / settings autosave / special-pages). Blocco 794–1130: quattro route REST e i rispettivi handler.
Issues: M-01, M-16, L-16, INF-011/M-06, INF-035, INF-051, INF-052, ADM-010, SEO-024 (see-also: `meta.php` → `schema-jsonld.php` sempre loaded)
Bloat/comments: Commento 169–170 sul ternario autoload è falso (ramo `ERANKLY_OPTION` già uscito). Closure anonime per i provider sitemap (286–289, 316–318) non unhookable. `erankly_rest_local_business_sites` `hasMore` è `count===LIMIT` (falso positivo sull’ultima pagina esatta). User-search include `user_email` nelle colonne ma non nel JSON (ok, solo admin).

### uninstall.php (690 lines, reviewed 1–690)
Purpose: Pulizia verificata per-sito + network (options, transients, meta, tabella redirects, upload, cron). Abort su DELETE fallita; reti grandi → WP-CLI.
Issues: M-12, INF-002 (see-also: stesso `DELETE usermeta LIKE '_erankly_%'` globale, qui è uninstall totale quindi corretto; il wipe avviene già al primo `switch_to_blog`)
Bloat/comments: Inventario esplicito + catch-all `erankly_%` (addon nello stesso namespace, documentato). `rewrite_rules` core viene cancellata di proposito. Loop utenti `blog_id=0` su MS per transient per-user (M-12). DELETE usermeta ripetuta su ogni sito è ridondante (tabella globale).

### includes/helpers.php (47 lines, reviewed 1–47)
Purpose: Loader: kernel request-wide + `erankly_load_*` on-demand.
Issues: reviewed, no issues
Bloat/comments: Commento 42–43 dice che defaults/variabili restano fuori, ma `sanitization.php` (~890 righe, targeting + custom-code sanitizers) è sempre required. Non è un difetto funzionale.

### includes/helpers/content-defaults.php (177 lines, reviewed 1–177)
Purpose: Default identity/social, logo/name/description runtime, special-page keys + `erankly_current_special_page_key()`.
Issues: SEO-001/002/003 (see-also: `erankly_current_special_page_key` richiede `! is_singular()` quindi static front page → `''`), INF-029 (placeholder usati, non dead)
Bloat/comments: Header onesto (runtime defaults). Commento 145–147 documenta lo skip static front; è la causa condivisa di SEO-001–003.

### includes/helpers/core.php (51 lines, reviewed 1–51)
Purpose: URL assoluto, `array_is_list`, post types/tax public, `erankly_is_frontend_html_request()`.
Issues: INF-011/M-06
Bloat/comments: `erankly_array_is_list` è compat PHP 8.0 (nit N-pass1). `is_frontend_html_request` non esclude REST/feed/embed/robots (il layer custom-code aggiunge gate; head output no).

### includes/helpers/defaults.php (448 lines, reviewed 1–448)
Purpose: Template meta globali, sanitize entity/schema-type/directives/social, `erankly_default_settings()`.
Issues: L-14 (QAPage→WebPage), INF-020 (see-also: `erankly_sanitize_schema_type_name` è il sanitizer “core” vs adapter preg_replace)
Bloat/comments: `$has_directives` tratta qualsiasi chiave advanced presente come significativa (anche off) — intenzionale per override migrazione. Linked sanitizer: first public post type è source of truth; first-row vuota + no directives → `array()` (wipe). Default `global_special_meta` finisce anche nello snapshot network (su MS lo storage vero è per-sito).

### includes/helpers/feature-modules.php (21 lines, reviewed 1–21)
Purpose: Tre toggle (`redirects`, `sitemap`, `custom_code`).
Issues: reviewed, no issues
Bloat/comments: Nessuno. `enable_sitemap` ha default esplicito `0`; gli altri si fidano di empty.

### includes/helpers/global-meta.php (438 lines, reviewed 1–438)
Purpose: Absolutize URL, image extraction, post/term meta, robots object, lookup global entity (linked fallback).
Issues: INF-027, INF-028, INF-034/L-11, H-04 (see-also: `array_filter` su IDs in `erankly_get_image_block_attachment_ids`)
Bloat/comments: Header mente: “always loaded early”; in realtà lazy via `erankly_load_content_helpers()`. `erankly_get_object_robots_directive` restituisce la stringa stored se non-empty/non-inherit, senza allowlist. Linked directive: OR sul primo template con flag, non la first-row.

### includes/helpers/redirect-cache.php (90 lines, reviewed 1–90)
Purpose: Chiave object-cache namespaced + rotate generation + purge cache full-page di terze parti.
Issues: reviewed, no issues
Bloat/comments: Docblock 83–88 indentato dentro la funzione dopo codice (nit stile). Rotate fallita → transient, no throw. Hook terze parti intenzionali.

### includes/helpers/sanitization-schema.php (314 lines, reviewed 1–314)
Purpose: Choice list WebPage/Article, default row, LocalBusiness types, path/phone/country/coord/time/hours, LB page map, breadcrumb jsonld mode.
Issues: INF-053
Bloat/comments: `erankly_sanitize_local_business_pages` fa `switch_to_blog` senza try/finally (contrasto con `erankly_find_published_page_id`). Hours non valida opens<closes. `erankly_sanitize_relative_path` / coordinate castano ad array come `"Array"` (see-also INF-036).

### includes/helpers/sanitization.php (890 lines, reviewed 1–890)
Purpose: Primitive text/URL/JSON-LD/Twitter; custom-code cap/toggle/blocks; targeting condiviso schema+code; trim/normalize SEO.
Issues: INF-010, INF-021, INF-036, INF-048 (see-also: archive vuoto fail-closed, coerente), L-05 (`erankly_custom_code_context_allowlist` alias), M-11 (`erankly_trim_text` senza ellipsis), ADM-009 (see-also: toggle è capability-gated qui; l’UI può comunque mostrarlo)
Bloat/comments: File sempre loaded dal kernel. `front_page` (362–364) return true senza include/exclude; date/404/search/posts_page idem ma il commento 332–333 dice che include/exclude valgono solo singular/taxonomy/author — `front_page` statico è anche singular, quindi INF-021. 10×100KB + 100KB total/location + 3 location + legacy = option autoload enorme (INF-010).

### includes/helpers/settings.php (871 lines, reviewed 1–871)
Purpose: get/store/cache settings, toggle/collection keys, feature-module registration, merge submission, `erankly_get_setting`, migrazioni one-time (schema/title/LB pages) con checkpoint/lease.
Issues: INF-007 (see-also: context multilingual vuoto prima di `wp`), INF-035, INF-051
Bloat/comments: Header corretto (always loaded). Title/schema migrations: snapshot pre-lock + `replace=true` + flag settato anche se `update_plugin_settings` fallisce. LB pages migration è invece lease-aware e non marca complete su write fallita. `erankly_get_setting` filtra con context vuoto se query non ready.

### includes/helpers/sitemap-cache.php (151 lines, reviewed 1–151)
Purpose: URL sitemap (pretty vs query), stylesheet nativo, flush versionato, hook post/term/user meta.
Issues: INF-026 (see-also: flush `save_post` + static once-per-site; over-flush `deleted_post` su ogni `$post_id > 0`)
Bloat/comments: Versioning option (no wildcard DELETE) è il design giusto. Query fallback copre index + image/video/news; altri path pretty-only.

### includes/helpers/template-variables.php (679 lines, reviewed 1–679)
Purpose: `{{var}}` replace (HTML + JSON-LD), resolver + cache, preview admin, sample post/term.
Issues: INF-012, INF-034/L-11, H-06/SEO-014 (see-also: JSON-LD encode fail → `''`), SEO-007 (see-also: `canonical_url` / `seo_title` / `meta_description` richiamano i builder)
Bloat/comments: Header mente (“always loaded early”; lazy via content helpers). Cache `$key:$post_id` ignora queried object / paged. `{{page_number}}` floor 1. Preview `current_pagination='2'` / `pagination=Page 2 of 5` è stand-in, non live.

### includes/helpers/utils.php (168 lines, reviewed 1–168)
Purpose: Label taxonomy, `erankly_current_url`, image URL, social profiles, XML prepare/send.
Issues: INF-019, INF-030/M-05, INF-032
Bloat/comments: ETag su body originale; output è `DOMDocument->save()`. DOCTYPE check case-sensitive. `erankly_current_url` senza query string.

### includes/helpers/video.php (134 lines, reviewed 1–134)
Purpose: Extract YouTube/Vimeo/HTML5/embed; embed/content/thumbnail URL; alias sitemap.
Issues: L-13/INF-031 (Shorts / haystack incompleto), L-03 (alias `erankly_get_sitemap_video_*`)
Bloat/comments: Embed iframe → watch URL, quindi thumbnail YouTube matcha. Relative HTML5 demandano il caller. `youtube-nocookie` in extract iframe, non nel primo haystack watch.

### includes/compatibility.php (209 lines, reviewed 1–209)
Purpose: Owner SEO esterni, `should_output_head`, localize URL, Woo wrappers, suppress/serve sitemaps, notice admin.
Issues: M-02/INF-041, INF-007 (see-also: `localize_url` catch → URL `''`), INF-011/M-06
Bloat/comments: Notice fail-open se `get_current_screen()` non è `WP_Screen` (mostra su tutto l’admin). Alias legacy `erankly_enable_sitemaps_with_external_seo` riceve lo stato di *suppress*. Custom-code non è menzionato nel testo notice (continua a girare con Yoast — probabilmente intenzionale).

### includes/compatibility-legacy.php (95 lines, reviewed 1–95)
Purpose: 60 alias hook `easyrankly_*` → `erankly_*` via wrapper `has_filter` + `apply_filters_ref_array`.
Issues: M-03/INF-038
Bloat/comments: Registrati su ogni request a priority 999 / 99 args. Costo = un callback extra per hook canonico ad ogni `apply_filters`.

### includes/compatibility-woocommerce.php (175 lines, reviewed 1–175)
Purpose: Product JSON-LD (offer, rating, review, brand) solo se Woo attivo.
Issues: INF-015, H-04, M-08
Bloat/comments: `erankly_get_og_image()` è della request, non di `$post_id`. `array_filter($data)` droppa `0`/`''`. Review senza rating restano. File lazy dal wrapper.

### includes/custom-code.php (206 lines, reviewed 1–206)
Purpose: Boot hook + migrate legacy snippet → blocks + output HEAD/body verbatim.
Issues: INF-021 (see-also: matcher), INF-037, INF-011 (see-also: gate extra rispetto a `is_frontend_html_request`)
Bloat/comments: `erankly_update_plugin_settings($changes)` return ignorato; migrate su ogni `init`. `function_exists(erankly_is_frontend_html_request)` è morto in pratica (core sempre loaded). Echo non escaped: intenzionale, cap al save.

### includes/settings-lock.php (302 lines, reviewed 1–302)
Purpose: Mutex settings (option/sitemeta CAS), acquire/renew/release, `erankly_update_plugin_settings`, interlock `pre_update_*`.
Issues: H-02/INF-009, INF-010
Bloat/comments: Su MS il lock è network (`sitemeta`) — questo file è il pattern *corretto*; INF-003 è l’altro lock (data-transfer per-blog). Interlock lock-fail → `old_value` (save silenzioso). `update_option(..., true)` autoload sempre per ERANKLY_OPTION. Commenti lunghi ma precisi sul sanitize/idempotent success.

## Findings

### INF-051 — medium — bug-latente
- file: includes/helpers/settings.php:267–316, 319–381
- why: `erankly_maybe_migrate_post_type_schema()` e `erankly_maybe_migrate_settings()` fanno `erankly_update_plugin_settings( $settings, '', true )` su uno snapshot letto *prima* del lock e **ignorano** WP_Error/false. Poi settano comunque `erankly_migrated_*_v1`. Se un altro writer tiene il mutex (autosave, import, altro init) la write fallisce e il flag impedisce ogni retry. Title restano `{{post_title}} - {{site_name}}`; schema types restano nel fallback `global_post_type_meta` finché non si salva il pannello Schema. Può succedere su 1/N upgrade concorrenti con un save admin. See-also INF-035 (stale replace snapshot).
- action: Settare il flag solo dopo write verificata (`true === $result`). In caso di lock, return senza flag. Meglio: rileggere settings sotto lock e mergeare solo le chiavi migrate (niente `replace=true` sullo snapshot pre-lock).

### INF-052 — medium — bug-latente
- file: easyrankly.php:762–776
- why: La deactivation di rete catcha `Throwable` per-sito, logga solo se `WP_DEBUG`, e continua. `register_deactivation_hook` termina “ok”: il plugin risulta disattivato in rete anche se uno o più siti hanno ancora cron (import/migration/reset) e `rewrite_rules`/`rewrite_signature`. Uninstall invece abortisce. Su un sito con errore DB/`wp_unschedule_hook` il job può rifire dopo la “disattivazione”.
- action: Contare i fallimenti; se >0, `wp_die` 409 (come il path CLI-required) oppure non considerare la network deactivation riuscita. Almeno `error_log` incondizionato + notice network.

### INF-053 — low — bug-latente
- file: includes/helpers/sanitization-schema.php:290–304
- why: `switch_to_blog( $blog_id )` senza try/finally. `erankly_find_published_page_id()` (settings.php:392–407) usa già try/finally. Se `get_post()` o un filtro lancia, il resto della request (autosave General che ri-sanitizza *tutta* la mappa LB) resta sul blog sbagliato: settings/letture del sito corrente diventano quelle dell’altro sito. Raro, ma il path gira su ogni `erankly_sanitize_settings()`.
- action: Stesso try/finally di `erankly_find_published_page_id`.

### M-01 — medium — dead code (see-also, non rinumerare)
- file: easyrankly.php:157–171
- why: Il ramo `else` fa `update_option( $key, $value, ERANKLY_OPTION === $key )` ma `ERANKLY_OPTION` è già gestito nel primo `if`. Il ternario è sempre false; il commento sull’autoload settings è morto.
- action: `update_option( $key, $value, false )` e cancellare il commento fuorviante.

### M-02 — medium — API trap (see-also)
- file: includes/compatibility.php:145–152
- why: `erankly_enable_sitemaps_with_external_seo` riceve/restituisce lo stato di *suppress*, non di enable. Un integratore che legge il nome storica inverte il booleano.
- action: Deprecare l’alias; documentare; eventualmente invertire il valore passato al legacy filter.

### M-03 — medium — costo fisso (see-also INF-038)
- file: includes/compatibility-legacy.php:76–95
- why: 60 wrapper registrati su ogni request.
- action: Registrare l’alias solo se `has_filter( $legacy )` a `plugins_loaded` tardivo, o droppare in 3.0.

### M-05 / INF-030 — medium — incompletezza (see-also)
- file: includes/helpers/utils.php:41–47
- why: `erankly_current_url()` è `home_url($wp->request)` + slash, senza query string. Chi la usa come canonical/self ottiene URL sbagliati su `?s=` / filtri.
- action: Includere `$_SERVER['QUERY_STRING']` allowlistata, o non usarla dove serve la URL pubblica reale.

### M-06 / INF-011 — medium — gate troppo largo (see-also)
- file: includes/helpers/core.php:49–50; easyrankly.php:328–330; includes/compatibility.php:70–74
- why: `!is_admin && !ajax && !cron` è vero per feed, embed, REST (se mai arrivasse a `wp`), robots. I moduli frontend (title/OG/schema) si agganciano. Custom-code ha gate extra; head no.
- action: Allineare a `erankly_custom_code_should_output()` o escludere esplicitamente feed/embed/robots/REST.

### M-08 — medium — schema (see-also)
- file: includes/compatibility-woocommerce.php:134–153
- why: Review senza `rating` restano nel grafo (Review senza reviewRating).
- action: Skippare commenti senza rating numerico, o richiedere rating per l’inclusione.

### M-11 — medium — output (see-also)
- file: includes/helpers/sanitization.php:841–852
- why: Trim a N caratteri senza ellipsis; l’excerpt tagliato sembra una frase completa.
- action: Aggiungere `…` quando si taglia (o documentare che è intenzionale).

### M-12 — medium — perf uninstall (see-also)
- file: uninstall.php:377–409
- why: `get_users` paginato, su MS `blog_id=0` (tutti gli utenti di rete) solo per cancellare 3 transient per user. Il limite CLI è sul *conteggio siti*, non utenti: 40 siti × 50k users può timeoutare anche sotto la soglia 100 siti.
- action: Prefixed transient SQL + known names; non enumerare tutti gli utenti. Oppure includere il conteggio users nel gate CLI.

### M-16 — medium — API (see-also)
- file: easyrankly.php:1058–1066
- why: Autosave settings restituisce HTTP 200 `saved:true` anche con `incomplete:true` e `errors[]` del sanitizer. Il client può trattarlo come successo pieno.
- action: 409/400 se `errors` non è vuoto; `saved` false.

### H-02 / INF-009 — high — silent no-op (see-also)
- file: includes/settings-lock.php:265–289
- why: Interlock che non ottiene il lock ritorna `$old_value`. options.php / Settings API sembrano aver salvato. Nessun errore UI.
- action: Segnalare errore (settings_error / WP_Error) invece di fingere un no-op di successo.

### H-04 — high — array_filter (see-also)
- file: includes/compatibility-woocommerce.php:75
- why: `array_filter( $data )` senza callback droppa `0` / `''` / `'0'`. Name/sku/price edge.
- action: `erankly_filter_empty_schema_values` (già esiste) o callback che droppa solo `null`/`''`.

### H-06 — high — JSON-LD replace (see-also)
- file: includes/helpers/template-variables.php:35–57
- why: `wp_json_encode` fail → replacement `''`; il probe `x` vs runtime può divergere (SEO-014).
- action: Fail-closed sul documento, non silenziare il token.

### INF-007 — high — context memo (see-also)
- file: includes/helpers/settings.php:249–259; includes/compatibility.php:77–90
- why: `erankly_get_setting` passa context vuoto prima di `wp`. `erankly_localize_url` su Throwable azzera l’URL.
- action: Non memoizzare context vuoto; su exception lasciare l’URL originale.

### INF-010 — medium — autoload (see-also)
- file: includes/settings-lock.php:226–228; includes/helpers/sanitization.php:301–314, 717–781
- why: Settings sempre autoload; fino a ~100KB×3 location (+ legacy) nella riga `alloptions`.
- action: Custom code in option non-autoload o post/option dedicata; o rifiutare oversize invece di tenere tutto in `erankly_settings`.

### INF-012 — medium — cache vars (see-also)
- file: includes/helpers/template-variables.php:60–67, 231–234
- why: Cache `$key:$post_id` non include queried/paged. `{{page_number}}` è sempre `max(1, paged)` quindi “1” anche su URL non paginata (template “Page {{page_number}}” sporca la home).
- action: Cache key + context (paged, queried id). `page_number` vuoto quando `$paged < 2`, come `current_pagination`.

### INF-015 — medium — Woo OG (see-also)
- file: includes/compatibility-woocommerce.php:28–31
- why: `erankly_get_og_image()` è della request corrente, non del prodotto `$post_id`. Schema Product su un contesto non-prodotto (o prodotto sbagliato nella query) prende l’immagine sbagliata.
- action: Featured/`_product_image_gallery` di `$post_id`, non OG globale.

### INF-019 — medium — ETag (see-also)
- file: includes/helpers/utils.php:106–167
- why: ETag = hash($body); la response 200 emette `DOMDocument->save('php://output')` che può differire (whitespace, encoding). Client 304 vs body fresco inconsistenti; cache intermedie sbagliate.
- action: Hashare i bytes effettivamente emessi, o emettere `$body` originale.

### INF-021 — medium — targeting (see-also)
- file: includes/helpers/sanitization.php:362–364
- why: `front_page` matcha e return subito. Include/exclude (e post types) ignorati. Una static Page on front è anche singular: un blocco “front_page + exclude questa pagina” gira comunque. Custom-code.php solo delega.
- action: Se `is_front_page() && is_singular()`, applicare include/exclude come il ramo singular; oppure documentare che `front_page` è sempre globale.

### INF-026 — medium — flush (see-also)
- file: includes/helpers/sitemap-cache.php:37–47, 67–71
- why: Static once-per-request è ok per version bump; `deleted_post` flusha ogni id>0 (revisioni/auto-draft). Rumore, non correttezza.
- action: Stesso guard di `flush_sitemap_cache_for_post` (skip revision).

### INF-027 — medium — unsanitized robots (see-also)
- file: includes/helpers/global-meta.php:217–249
- why: Direttiva object tornata raw se non `''`/`inherit`. Meta crafted (`noindex, follow`, token extra) arriva all’output robots senza allowlist (il sanitizer settings invece ha allowlist).
- action: Allowlist come `erankly_sanitize_global_entity_directives`; default `inherit`.

### INF-028 — low — linked inherit (see-also)
- file: includes/helpers/global-meta.php:416–437
- why: Con linked=true, `noindex` di un’entità senza riga diventa true se *qualsiasi* altra riga ha il flag (OR), non la first-row usata dai title.
- action: Allineare alla first-row (stesso contratto del sanitizer linked).

### INF-029 — superseded — placeholders USED (see-also)
- file: includes/helpers/content-defaults.php:8–26
- why: `erankly_default_social_image_placeholder` / `erankly_default_organization_logo_placeholder` sono usati da admin (meta-box, panels), non dead.
- action: Nessuna. Non trattare come dead code.

### INF-032 — low — DOCTYPE (see-also)
- file: includes/helpers/utils.php:96–99
- why: `str_contains( $body, '<!DOCTYPE' )` è case-sensitive; `<!doctype` passa e va a `loadXML`.
- action: `stripos` / `str_contains( strtoupper($body), '<!DOCTYPE' )`.

### INF-034 / L-11 — low — commento bugiardo (see-also)
- file: includes/helpers/global-meta.php:2; includes/helpers/template-variables.php:2
- why: Dichiarano “always loaded early on every request”; il loader li tira dentro solo da `erankly_load_content_helpers()`.
- action: Correggere l’header.

### INF-035 — low — migrate snapshot (see-also)
- file: includes/helpers/settings.php:272–315, 324–377
- why: Title/schema migration: read settings → (altre cose) → `update_plugin_settings(..., replace=true)` con snapshot stale. Un save admin in mezzo viene sovrascritto. Il lock serializza solo la write, non la read. See-also INF-051 (flag comunque settato).
- action: Read+write sotto lo stesso lock; merge chiavi, non replace dello snapshot intero.

### INF-036 — low — Array string (see-also)
- file: includes/helpers/sanitization.php:15–16
- why: `(string) $value` su array → `"Array"`, che `sanitize_text_field` accetta. Un POST malformato persiste la letterale `Array` in title/Twitter/ecc.
- action: `is_scalar` come `erankly_sanitize_schema_type_name`.

### INF-037 — low — migrate ogni init (see-also)
- file: includes/custom-code.php:23–52
- why: `erankly_maybe_migrate_legacy_custom_code` su ogni `init` frontend. Return di `erankly_update_plugin_settings` ignorato: lock fail → retry infinito (almeno non setta un flag morto). Write extra se il legacy resta.
- action: Flag one-time; non migrare su visitor `init` (solo admin / primo privileged request). Controllare il result.

### L-03 / INF-031 — low — video (see-also)
- file: includes/helpers/video.php:17–19, 59–64, 91–134
- why: Haystack watch non include Shorts/`youtube-nocookie` (gli iframe nocookie sì). Quattro alias sitemap. Shorts in content non estratti.
- action: Stesso haystack della sitemap SQL; o drop alias se il sitemap chiama già le funzioni nuove.

### L-05 — low — alias (see-also)
- file: includes/helpers/sanitization.php:324–327
- why: `erankly_custom_code_context_allowlist` è un wrapper a una riga.
- action: Inlinare i caller.

### L-14 — low — QAPage (see-also)
- file: includes/helpers/defaults.php:271–273
- why: `QAPage` forzatamente `WebPage`. Import da altri SEO perde il tipo.
- action: Tenere QAPage se è un tipo valido Schema.org, o mappare solo in UI.

### L-16 — low — throw escaped (see-also)
- file: easyrankly.php:162, 417, 430, 500, 523, 702, 727
- why: `throw new RuntimeException( esc_html__( ... ) )`. Il REST rimanda `getMessage()` già escaped; UI può mostrare entità.
- action: Throw della stringa raw; escape al output.

### ADM-010 — medium — wipe map (see-also)
- file: easyrankly.php:1108–1114
- why: Payload senza `global_special_meta` (o non-array) → `$map = array()` → `erankly_update_special_meta_map` sostituisce con vuoto. Autosave JS rotto / key typo cancella title/robots special pages del sito.
- action: 400 se la chiave manca; merge, non replace-with-empty.

### SEO-001/002/003 — high — homepage statica (see-also)
- file: includes/helpers/content-defaults.php:151–176
- why: `erankly_current_special_page_key()` non ritorna `homepage` se `is_singular()` (static front). Title/OG/robots special-page Homepage non si applicano; sitemap/seo-state invece usano `homepage`.
- action: `is_front_page()` prima di singular (o chiave `homepage` anche per Page on front).

Remaining ranges: none.
