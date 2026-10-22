# Upcoming event calendar

The calendar lane is intentionally separate from canonical results, statistics, fighter tracking, and live rankings.

## Collect and export

Run from `dev-tools/scraper` with the project environment active:

```bash
python -m mma_future_scraper.cli scrape-upcoming --days 10
python -m mma_future_scraper.cli export-upcoming-bundle --output output/upcoming-YYYYMMDD.zip
```

`--days` is inclusive of today and accepts 1–30. The default timezone is `Europe/Belgrade`. The scraper writes isolated state to `data/upcoming`, uses the shared professional-MMA and promotion classifiers, and preserves the last successful export when a run is blocked or incomplete.

The ZIP contains `mmaf_upcoming_v1` data, actual coverage, a run manifest, and a transport manifest with SHA-256 checksums.

## Import and edit

1. Run the additive schema migration: `wp mmaf system migrate`.
2. Open **MMA Future → Kalendar → ZIP import**.
3. Upload the package and review new, changed, unchanged, stale, protected, and review items.
4. Confirm the import. Repeating the same package is idempotent.

Manual events and bouts can be added from **Dodaj događaj**. Imported source values and editorial overrides are stored separately. Empty overrides, hidden rows, and manual bouts survive later source refreshes. Exact Tapology fighter identities may link to existing fighters, but names alone never create a match.

## Public output

Use the ACF block **Kalendar događaja** or the `[mmaf_event_calendar]` shortcode. Public routes are:

- `GET /wp-json/mma-future/v1/calendar`
- `GET /wp-json/mma-future/v1/calendar/day`

Supported timezone values are limited to the selector shown by the block. The URL parameters `calendar_month`, `calendar_date`, and `calendar_tz` create a shareable view. The server-rendered month and day remain usable without JavaScript.

## Verification

```bash
pytest -q dev-tools/scraper/tests/test_upcoming_calendar.py
wp eval-file tests/integration/verify-upcoming-calendar.php
```

The integration test verifies preview classification, idempotency, preservation of manual values and intentionally empty fields, manual events outside coverage, and unchanged canonical fighter/event/bout/ranking counts. It removes its isolated fixture rows when finished.
