# MMA Future

WordPress theme, canonical MMA Data Engine, ACF definitions and scraper source for MMA Future.

Development and database tests run locally first. Pushes to `main` run source validation and then deploy the WordPress code to the configured staging site through GitHub Actions. Production is a separate release decision. Git does not transfer WordPress database content, media, private source JSON, credentials or backups.

- `wp-content/themes/mma-future`: server-rendered editorial content, fighter profiles, rankings, blog and contact form.
- `wp-content/plugins/mma-future-data-engine`: canonical fighters/events/bouts, regular ZIP imports, stored profile preparation, statistics and ranking drafts.
- `dev-tools/scraper`: scraper source and regression tests; excluded from web deployment.
- `tests`: source checks and isolated WordPress integration fixtures; excluded from deployment.
- [Data flow, administration and deployment](docs/frontend-data-integration.md).
- [Scraping cycles, import packages, recovery and delta imports](docs/import-pipeline.md).

ACF Pro must be active. The theme's `acf-json` directory contains versioned field definitions; saved values stay in the database. Newsletter integration remains deferred.
