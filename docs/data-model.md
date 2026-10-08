# Modello dati

Ogni dato che il plugin salva è elencato qui. Se non è in questa pagina, il plugin non deve crearlo.
`uninstall.php` rimuove tutto ciò che è elencato, e `tests/UninstallTest.php` lo verifica.

Regola: zero tabelle custom. Solo Options API, post meta, term meta, user meta, post type non pubblici e tassonomie nascoste.

## Option

| Nome | Autoload | Contenuto | Introdotta in |
|---|---|---|---|
| `easyrankly_settings` | sì | Oggetto con tutte le impostazioni; schema in `src/Settings/Settings.php` (`Settings::schema()`), letto e scritto da `/wp/v2/settings`. Chiavi: `title_separator` (stringa, max 10, default `-`, 1.1); `social_image` (ID allegato dell'immagine social di default, 1.5); `x_username` (utente X senza @, 1.5); `identity_type` (`organization` o `person`), `identity_name`, `identity_logo` (ID allegato), `same_as` (lista di URL http/https, max 20) per lo schema, 1.6; `breadcrumb_home_label` (etichetta della prima voce del blocco breadcrumb, vuota = quella di WordPress) e `breadcrumb_taxonomies` (oggetto tipo di contenuto → tassonomia del percorso), 1.7; `noindex` (lista di chiavi di contesto con noindex, default vuota, 1.4); `templates` (oggetto contesto → `{title, description}`; contesti `home`, `single`, `archive`, `term`, `author`, `date`, `search`, `404` e `single-{tipo}`, `archive-{tipo}`, `term-{tassonomia}`, 1.3) | 1.1 |

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

## Post type non pubblici

| Nome | Contenuto | Stati custom | Introdotto in |
|---|---|---|---|
| _(nessuno per ora)_ | | | |

## Tassonomie nascoste

| Nome | Oggetti | Contenuto | Introdotta in |
|---|---|---|---|
| _(nessuna per ora)_ | | | |

## Transient

| Nome | Scadenza | Contenuto | Introdotto in |
|---|---|---|---|
| _(nessuno per ora)_ | | | |
