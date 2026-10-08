# EasyRankly

Plugin SEO per WordPress, riscritto da zero su sole API native: niente tabelle custom, niente cron, niente script nel frontend.

- Regole del progetto: [CLAUDE.md](CLAUDE.md)
- Fasi di sviluppo: [docs/roadmap.md](docs/roadmap.md)
- Prompt per lavorare con Claude Code: [docs/prompt.md](docs/prompt.md)
- Modello dati: [docs/data-model.md](docs/data-model.md)

## Sviluppo

Requisiti locali: PHP 8.1+, Composer, MySQL o MariaDB per i test.

```bash
composer install
tools/install-wp-tests.sh            # una volta sola
export WP_TESTS_DIR=/tmp/wordpress-develop/tests/phpunit
composer check
```

La CI su GitHub esegue gli stessi controlli a ogni push e pull request.
