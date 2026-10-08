# EasyRankly

Plugin SEO per WordPress, riscritto da zero su sole API native: niente tabelle custom, niente cron, niente script nel frontend.

- Piano e decisioni: [docs/piano.md](docs/piano.md)
- Regole del progetto: [CLAUDE.md](CLAUDE.md)
- Fasi di sviluppo: [docs/roadmap.md](docs/roadmap.md)
- Prompt per lavorare con Claude Code: [docs/prompt.md](docs/prompt.md)
- Modello dati: [docs/data-model.md](docs/data-model.md)

## Sviluppo

Il branch `Refactory` è autonomo: contiene tutto il necessario per lavorare in locale o con Claude Code nel cloud.

- **Cloud (Claude Code)**: all'avvio della sessione `tools/cloud-setup.sh` (hook in `.claude/settings.json`) installa
  dipendenze, MariaDB e la suite di test di WordPress. Basta lanciare `composer check`. PHPStan nel cloud non si
  può installare: lo esegue la CI sulla pull request.
- **Locale**: servono PHP 8.1+, Composer, MySQL o MariaDB e Node 22.22.2+ (per gli asset admin).

```bash
npm install && npm run build   # asset admin in build/ (non committati)
composer install
tools/install-wp-tests.sh wordpress_test root '' 127.0.0.1   # una volta sola
composer check
```

Gli asset admin stanno in `assets/src/` e si compilano in `build/` con `@wordpress/scripts`. `build/` non è nel
repository: `tools/build-dist.sh` lo compila e lo aggiunge allo zip distribuibile.

La CI su GitHub esegue gli stessi controlli a ogni push su `Refactory` e a ogni pull request.
