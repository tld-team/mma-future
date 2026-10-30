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

Supported timezone values are limited to the selector shown by the block. The URL parameters `calendar_month`, `calendar_date`, and `calendar_tz` create a shareable view. The server-rendered month and day remain usable without JavaScript. `calendar_page` selects a three-event fallback page; the previous/next links preserve the date and time zone.

## Verification

```bash
pytest -q dev-tools/scraper/tests/test_upcoming_calendar.py
wp eval-file tests/integration/verify-upcoming-calendar.php
```

The integration test verifies preview classification, idempotency, preservation of manual values and intentionally empty fields, manual events outside coverage, and unchanged canonical fighter/event/bout/ranking counts. It removes its isolated fixture rows when finished.

## Compact calendar UI

At desktop widths (1024px and above), the 360px calendar sits beside the daily schedule. Each date shows its event count. The initial response contains at most three collapsed event summaries; “Show more” fetches the next three through the existing day API (`page`, `per_page=3`, `total`). “Show fewer” restores the first three. One fight card opens at a time. Date/month/time-zone changes reset the list and invalidate previous requests. A failed additional page preserves the current list and can be retried.

The native details controls and previous/next page links work without JavaScript. Browser storage is optional. Calendar CSS/JS asset versions include their file modification times so a release does not reuse stale cached assets.

Run the database-free renderer/DOM regression suite (PHP 8.2+ and Node 18+):

```bash
npm --prefix tests/frontend ci
npm --prefix tests/frontend test
```

Set `MMAF_TEST_PHP` to the PHP executable when it is not on PATH. The fixture is synthetic, does not bootstrap WordPress, and is excluded from deployment with the rest of `tests/`. For a local visual preview, run `php -S 127.0.0.1:8098 -t tests/frontend` and open `/fixture.php`; its typography stylesheet uses the existing `mma-future.local` site. Days 1–7 cover 0, 1, 3, 4, 19, 23 events and incomplete coverage. `nojs=1` omits the calendar script.
