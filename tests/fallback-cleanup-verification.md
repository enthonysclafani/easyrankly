# Verifica e pulizia dei fallback EasyRankly

Data: 2 ottobre 2026. Ambiente verificato: WordPress 7.1.2, PHP 8.4, SQLite di WordPress Studio.

## Esito dell’analisi allegata

L’analisi è in gran parte corretta, ma la conclusione «100%» non è giustificata.

- I due fallback su `WEEK_IN_SECONDS` e `DAY_IN_SECONDS` sono irraggiungibili nel normale caricamento WordPress.
- Sono ridondanti i controlli sulle funzioni core incluse nelle versioni supportate e i sei controlli sulle funzioni interne caricate prima dell’uso.
- I controlli su `get_plugins()` non vanno eliminati: il plugin supporta WordPress 6.5, il cui caricamento frontend non include automaticamente `wp-admin/includes/plugin.php`. Nella versione locale 7.1.2 il file è invece incluso. Il risultato runtime della versione installata non dimostra che la stessa funzione sia disponibile in tutte le versioni supportate. Fonte: [codice ufficiale WordPress 6.5](https://github.com/WordPress/WordPress/blob/6.5/wp-settings.php).
- I quattro adapter contengono logica duplicata tra l’iteratore e la lettura a blocchi. Entrambi i percorsi sono usati: si può condividere la trasformazione dei dati, conservando i diversi meccanismi di lettura e paginazione.
- I conteggi dell’allegato sono incoerenti: sono elencati sei controlli interni, con un settimo esplicitamente legittimo; le coppie di duplicati sono quattro.
- Diverse versioni d’introduzione sono inesatte: i commenti del core indicano `is_post_publicly_viewable` 5.7.0, `wp_http_validate_url` 3.5.2, `wp_check_invalid_utf8` 2.8.0, `attachment_url_to_postid` 4.0.0 e `wp_convert_hr_to_bytes` 2.3.0 (spostata in `load.php` in 4.6.0). Restano tutte precedenti alla versione minima 6.5.

## Modifiche applicate

Eliminati 22 controlli ridondanti: 2 su costanti, 14 su funzioni core e 6 su funzioni interne. Le chiamate usano direttamente le API richieste dal plugin.

La trasformazione dei redirect è ora condivisa nei quattro adapter:

- AIOSEO: `map_redirect_row()`.
- Yoast: `map_redirect_option_entry()` per le opzioni Premium e legacy.
- Rank Math: `map_redirect_source()`; preservati conteggio delle sorgenti, limiti, avvisi e cursori di ripresa.
- SEOPress: l’iteratore riusa il metodo già esistente `map_redirect_record()`.

Sono stati mantenuti i controlli sulle API amministrative non sempre caricate, sui moduli opzionali e sulle estensioni PHP. Nessun cambiamento al core WordPress, al tema o ai mu-plugin. Le modifiche precedenti nel repository sono state conservate.

Aggiunti quattro test di regressione in `tests/test-migration-redirect-pagination.php`: confronto tra iteratore e pagine da un record, gestione delle sorgenti invalide, query, regex, regole disabilitate, visibilità e ripresa all’interno delle regole Rank Math con più sorgenti. Questi test passano anche sulla copia iniziale degli adapter.

## Verifiche

La suite è stata eseguita prima e dopo su database temporanei separati dal database del sito. La pulizia del codice ha conservato tutti i risultati. Dopo la successiva revisione del test di disinstallazione richiesta dall’utente, l’unico risultato cambiato è quello del test corretto, passato da fallito a superato. La suite completa è stata rieseguita in entrambi gli ambienti.

| Ambiente | Test | Passati | Saltati | Fallimenti | Nuove regressioni |
| --- | ---: | ---: | ---: | ---: | ---: |
| Sito singolo | 1.098 | 1087 | 11 | 0 | 0 |
| Multisito | 1.098 | 1095 | 3 | 0 | 0 |

Il test `ERankly_Uninstall_Cleanup_Test::test_failed_delete_is_not_reported_as_success` era utile, ma la simulazione del fallimento interferiva con la transazione dei test su SQLite. L’SQL deliberatamente invalido sollevava correttamente l’eccezione di disinstallazione; il driver SQLite annullava però anche la transazione contenente l’opzione appena creata come dato di prova, facendo fallire il controllo successivo sulla sua presenza. La diagnosi iniziale che attribuiva il problema all’eccezione era incompleta.

Il test è stato mantenuto e corretto: il filtro intercetta il `DELETE` e restituisce una query vuota, che sia `wpdb` nativo sia il drop-in SQLite rifiutano restituendo `false` prima di chiamare il driver. Il test verifica che il dato di prova sia presente prima e dopo, che la disinstallazione sollevi l’eccezione attesa e che sia tentata una sola cancellazione. Ripristina inoltre lo stato precedente della soppressione degli errori. Nessuna modifica al codice di disinstallazione in questa revisione.

- Sintassi PHP valida per tutti i 18 file di produzione modificati e il nuovo file di test.
- Homepage e pagina amministrativa EasyRankly: HTTP 200; campi di identità presenti, nessun errore PHP rilevato nelle pagine.
- Caricamento frontend senza WP-CLI: costanti e funzioni core interessate presenti; `add_settings_error`, `get_settings_errors` e `get_current_screen` assenti, come previsto.
- Compatibilità con WordPress 6.5 verificata sul caricamento e sulle versioni delle API; la suite runtime è stata eseguita su WordPress 7.1.2 con SQLite, non su MySQL o su un’installazione 6.5.

Snapshot iniziale, diff delle sole modifiche di questa attività e risultati JUnit sono conservati in `/private/tmp/erankly-fallback-cleanup-20261002/`.

Risultati JUnit della suite dopo la correzione del test, prova diagnostica della transazione SQLite e diff del test: `/private/tmp/erankly-uninstall-test-fix-20261002/`.
