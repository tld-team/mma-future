# MMA Future

Operator workflow za proveru i potvrđivanje UFC potpisa opisan je u [docs/UFC_SIGNING_WORKFLOW.md](docs/UFC_SIGNING_WORKFLOW.md).

WordPress theme, canonical MMA Data Engine, ACF definitions and scraper source for MMA Future.

Development and database tests run locally first. Pushes to `main` run source validation and then deploy the WordPress code to the configured staging site through GitHub Actions. Production is a separate release decision. Git does not transfer WordPress database content, media, private source JSON, credentials or backups.

- `wp-content/themes/mma-future`: server-rendered editorial content, fighter profiles, rankings, blog and contact form.
- `wp-content/plugins/mma-future-data-engine`: canonical fighters/events/bouts, regular ZIP imports, stored profile preparation, statistics and ranking drafts.
- `dev-tools/scraper`: scraper source and regression tests; excluded from web deployment.
- `tests`: source checks and isolated WordPress integration fixtures; excluded from deployment.
- [Data flow, administration and deployment](docs/frontend-data-integration.md).
- [Fighter tracking and archiving](docs/FIGHTER_TRACKING_WORKFLOW.md).
- [Scraping cycles, import packages, recovery and delta imports](docs/import-pipeline.md).
- [Upcoming event calendar workflow](docs/UPCOMING_CALENDAR_WORKFLOW.md).

ACF Pro must be active. The theme's `acf-json` directory contains versioned field definitions; saved values stay in the database. Newsletter integration remains deferred.

For a headed Firefox operator session, use a dedicated profile directory:

```powershell
python -m mma_future_scraper.cli tapology-auth --browser-engine firefox --browser-profile-dir .\data\browser_profile\tapology-firefox
```

Do not reuse Chromium profile directories for Firefox; each browser engine must keep a separate operator profile.
