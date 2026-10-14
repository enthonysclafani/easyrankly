# EasyRankly

Plugin SEO per WordPress, riscritto da zero su sole API native: niente tabelle custom, niente cron, niente script nel frontend.

- Regole del progetto: [CLAUDE.md](CLAUDE.md)
- Piano (visione, principi, sicurezza, contratto con il Pro): [docs/piano.md](docs/piano.md)
- Decisioni aperte e prese: [docs/decisioni.md](docs/decisioni.md)
- Fasi di sviluppo e punti in sospeso: [docs/roadmap.md](docs/roadmap.md)
- Modello dati: [docs/data-model.md](docs/data-model.md)
- Controllo di sicurezza: [docs/sicurezza.md](docs/sicurezza.md)
- Custom code, posizioni e problemi noti: [docs/custom-code.md](docs/custom-code.md)
- Compatibilità con WooCommerce e ACF: [docs/compatibilita.md](docs/compatibilita.md)
- Prompt per lavorare con Claude Code: [docs/prompt.md](docs/prompt.md)

## Sviluppo

Il branch `pre-release` è autonomo: contiene tutto il necessario per lavorare in locale o con Claude Code nel cloud.

- **Cloud (Claude Code)**: all'avvio della sessione `tools/cloud-setup.sh` (hook in `.claude/settings.json`) installa
  dipendenze, MariaDB e la suite di test di WordPress. Basta lanciare `composer check`. PHPStan nel cloud non si
  può installare: lo esegue la CI sulla pull request.
- **Locale**: servono PHP 8.0+, Composer, MySQL o MariaDB e Node 22.22.2+ (per gli asset admin).

```bash
npm install && npm run build   # asset admin in build/ (non committati)
composer install
tools/install-wp-tests.sh wordpress_test root '' 127.0.0.1   # una volta sola
composer check
```

Gli asset admin stanno in `assets/src/` e si compilano in `build/` con `@wordpress/scripts`. `build/` non è nel
repository: `tools/build-dist.sh` lo compila e lo aggiunge allo zip distribuibile.
Lo zip pronto, da installare su un sito di prova senza Node, si scarica da ogni esecuzione della CI
(GitHub > Actions > l'esecuzione > Artifacts > `easyrankly.zip`).

La CI su GitHub esegue gli stessi controlli a ogni push su `pre-release` e a ogni pull request.
