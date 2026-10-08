# Modello dati

Ogni dato che il plugin salva è elencato qui. Se non è in questa pagina, il plugin non deve crearlo.
`uninstall.php` rimuove tutto ciò che è elencato, e `tests/UninstallTest.php` lo verifica.

Regola: zero tabelle custom. Solo Options API, post meta, term meta, user meta, post type non pubblici e tassonomie nascoste.

## Option

| Nome | Autoload | Contenuto | Introdotta in |
|---|---|---|---|
| `easyrankly_settings` | sì | Oggetto con tutte le impostazioni; schema in `src/Settings/Settings.php` (`Settings::schema()`), letto e scritto da `/wp/v2/settings`. Chiavi: `title_separator` (stringa, max 10, default `-`) | 1.1 |

## Post meta e term meta

| Chiave | Oggetto | Tipo | Contenuto | Introdotta in |
|---|---|---|---|---|
| _(nessuna per ora)_ | | | | |

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
