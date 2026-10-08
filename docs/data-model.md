# Modello dati

Ogni dato che il plugin salva è elencato qui. Se non è in questa pagina, il plugin non deve crearlo.
`uninstall.php` rimuove tutto ciò che è elencato, e `tests/UninstallTest.php` lo verifica.

Regola: zero tabelle custom. Solo Options API, post meta, term meta, user meta, post type non pubblici e tassonomie nascoste.

## Option

| Nome | Autoload | Contenuto | Introdotta in |
|---|---|---|---|
| `easyrankly_settings` | sì | Oggetto con tutte le impostazioni; schema in `src/Settings/Settings.php` (`Settings::schema()`), letto e scritto da `/wp/v2/settings`. Chiavi: `title_separator` (stringa, max 10, default `-`, 1.1); `social_image` (ID allegato dell'immagine social di default, 1.5); `x_username` (utente X senza @, 1.5); `identity_type` (`organization` o `person`), `identity_name`, `identity_logo` (ID allegato), `same_as` (lista di URL http/https, max 20) per lo schema, 1.6; `breadcrumb_home_label` (etichetta della prima voce del blocco breadcrumb, vuota = quella di WordPress) e `breadcrumb_taxonomies` (oggetto tipo di contenuto → tassonomia del percorso), 1.7; `robots_txt` (regole aggiunte al robots.txt virtuale, max 5000 caratteri, 1.8); `noindex` (lista di chiavi di contesto con noindex, default vuota, 1.4); `templates` (oggetto contesto → `{title, description}`; contesti `home`, `single`, `archive`, `term`, `author`, `date`, `search`, `404` e `single-{tipo}`, `archive-{tipo}`, `term-{tassonomia}`, 1.3); `languages` (oggetto prefisso URL → `{locale, name}` in ordine di visualizzazione, max 20; la prima lingua è la predefinita e non ha prefisso; il multilingua si attiva da due lingue; 2.1); `language_templates` (oggetto lingua → contesto → `{title, description}`, stesse chiavi di contesto di `templates`; usato prima di `templates` sulle pagine della lingua, campi vuoti = template di tutte le lingue; 2.6) | 1.1 |
| `easyrankly_redirects_forced` | sì | Lista dei redirect attivi marcati "applica sempre" (`source`, `regex`, `target`, `code`), max 50. Letta a ogni richiesta frontend; ricostruita da `Redirects::rebuild_lists()` quando un redirect cambia. | 1.10 |
| `easyrankly_snippets` | sì | Cache degli snippet: `positions` (posizione → snippet attivi ordinati per priorità, con `id`, `type`, `priority`, `code`) ed `errors` (ID → nome degli snippet disattivati da un errore). Letta nel frontend e per l'avviso in admin; ricostruita da `CustomCode::rebuild_cache()` a ogni modifica. | 1.11 |
| `easyrankly_redirects_regex` | no | Lista dei redirect regex attivi non forzati, stessi campi. Letta solo su 404. | 1.10 |

## Post meta e term meta

| Chiave | Oggetto | Tipo | Contenuto | Introdotta in |
|---|---|---|---|---|
| `_easyrankly_title` | post e termini | string | Titolo SEO. Vuoto = default (template del titolo). | 1.2 |
| `_easyrankly_description` | post e termini | string | Meta description. Vuoto = default (template della descrizione). | 1.2 |
| `_easyrankly_canonical` | post e termini | string (URL) | URL canonico, solo http/https. Vuoto = URL costruito da WordPress. | 1.2 |
| `_easyrankly_noindex` | post e termini | boolean | Chiede ai motori di non indicizzare. false = default. | 1.2 |
| `_easyrankly_nofollow` | post e termini | boolean | Chiede ai motori di non seguire i link. false = default. | 1.2 |
| `_easyrankly_og_title` | post e termini | string | Titolo per la condivisione social. Vuoto = titolo SEO. | 1.2 |
| `_easyrankly_og_description` | post e termini | string | Descrizione per la condivisione social. Vuoto = meta description. | 1.2 |
| `_easyrankly_og_image` | post e termini | integer | ID dell'allegato per la condivisione social. 0 = default. | 1.2 |

Registrate con `register_post_meta( '', ... )` e `register_term_meta( '', ... )` in `src/Meta/Meta.php`; nel REST solo con `edit_post` / `edit_term` sull'oggetto.

Meta del post type `erankly_redirect` (`src/Redirects/Redirects.php`), nel REST solo con `manage_options`:

| Chiave | Tipo | Contenuto | Introdotta in |
|---|---|---|---|
| `_easyrankly_redirect_target` | string | Destinazione: percorso che inizia con `/` o URL http/https. Vuota per 410. Con regex può contenere `$1`, `$2`… | 1.10 |
| `_easyrankly_redirect_code` | integer | 301, 302, 307 o 410. Default 301. | 1.10 |
| `_easyrankly_redirect_regex` | boolean | La sorgente è un'espressione regolare. | 1.10 |
| `_easyrankly_redirect_forced` | boolean | Applica anche se la pagina esiste (non solo su 404). | 1.10 |

Meta del post type `erankly_snippet` (`src/CustomCode/CustomCode.php`), nel REST solo a chi può modificare lo snippet:

| Chiave | Tipo | Contenuto | Introdotta in |
|---|---|---|---|
| `_easyrankly_snippet_type` | string | `html` o `php`. Non cambia dopo la creazione. | 1.11 |
| `_easyrankly_snippet_position` | string | `head`, `body_open` o `footer`. | 1.11 |
| `_easyrankly_snippet_priority` | integer | Ordine nella posizione, 0–1000, default 10. | 1.11 |
| `_easyrankly_snippet_error` | string | Ultimo errore in esecuzione, che ha disattivato lo snippet. Sola lettura nel REST; cancellato al salvataggio successivo. | 1.11 |

## Post type non pubblici

| Nome | Contenuto | Stati custom | Introdotto in |
|---|---|---|---|
| `erankly_redirect` | Un redirect per post. `post_title` = sorgente normalizzata (percorso relativo alla home, minuscolo, senza query string né slash finale) o regex; `post_name` = md5 della sorgente esatta (colonna indicizzata, usata per la ricerca su 404) o `regex-{md5}`; `post_status` `publish` = attivo, `draft` = disattivo. Non pubblico, senza UI core, REST `/wp/v2/easyrankly-redirects` solo con `manage_options`. | nessuno | 1.10 |
| `erankly_snippet` | Uno snippet per post. `post_title` = nome, `post_content` = codice (le revisioni native ne tengono la cronologia), `post_status` `publish` = attivo, `draft` = disattivo. Serve `manage_options` più `unfiltered_html`; per il PHP anche `edit_plugins`. REST `/wp/v2/easyrankly-snippets`. | nessuno | 1.11 |

## Tassonomie nascoste

| Nome | Oggetti | Contenuto | Introdotta in |
|---|---|---|---|
| `erankly_language` | tipi di contenuto visualizzabili, tranne gli allegati | Lingua del contenuto: un termine per lingua, slug = prefisso URL della lingua (creato al primo uso). Al massimo un termine per post. Nessun termine, o una lingua tolta dalle impostazioni, = lingua predefinita. Registrata in `src/Multilingual/Multilingual.php`: niente UI, URL né REST; gestione dei termini solo con `manage_options`. | 2.1 |
| `erankly_translation` | come sopra | Gruppo di traduzioni: un termine per gruppo (nome = UUID), assegnato a tutte le traduzioni dello stesso contenuto. Un gruppo ha almeno due post, tutti dello stesso tipo, al massimo uno per lingua (`src/Multilingual/Translations.php`); i gruppi rimasti con un solo post si eliminano. | 2.1 |

## Transient

| Nome | Scadenza | Contenuto | Introdotto in |
|---|---|---|---|
| _(nessuno per ora)_ | | | |
