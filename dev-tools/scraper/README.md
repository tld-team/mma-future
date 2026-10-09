# MMA Future Tapology Scraper

This is a standalone local scraper for MMA Future data discovery. It opens Tapology FightCenter, applies regional MMA filters from the live DOM, opens event pages, parses visible fight-card data, optionally visits matchup pages, and writes normalized JSON under `scraper/data`.

It does not touch WordPress. It does not import data, write to a database, create REST endpoints, create cron jobs, calculate rankings, or modify the active theme/plugins.

## Install

From the project root:

```powershell
cd scraper
python -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install --upgrade pip
python -m pip install -e .[test]
python -m playwright install chromium
```

Install additional Playwright browser engines only when needed:

```powershell
.\.venv\Scripts\python.exe -m playwright install firefox
```

To use a locally installed browser instead of the bundled Playwright Chromium, keep
`--browser-engine chromium` and add a channel or executable path:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli browser-check --headed --browser-channel msedge
.\.venv\Scripts\python.exe -m mma_future_scraper.cli browser-check --headed --browser-channel chrome
.\.venv\Scripts\python.exe -m mma_future_scraper.cli browser-check --headed --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe"
.\.venv\Scripts\python.exe -m mma_future_scraper.cli browser-check --headed --browser-executable-path "C:\Users\lukam\AppData\Local\Programs\Opera\opera.exe"
```

If PowerShell blocks venv activation, run:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\.venv\Scripts\Activate.ps1
```

## Commands

Inspect current FightCenter filter controls:

```powershell
python -m mma_future_scraper.cli inspect-filters
```

Check that local Chromium can launch without contacting Tapology:

```powershell
python -m mma_future_scraper.cli browser-check --headed
```

Run a small headed discovery scrape:

```powershell
python -m mma_future_scraper.cli run --headed --results-only --limit-events 5
```

Common run options:

```powershell
python -m mma_future_scraper.cli run --results-only
python -m mma_future_scraper.cli run --upcoming-only
python -m mma_future_scraper.cli run --max-pages 3
python -m mma_future_scraper.cli run --max-events 10
python -m mma_future_scraper.cli run --limit-events 5
python -m mma_future_scraper.cli run --recheck-days 14
python -m mma_future_scraper.cli run --recheck-known-limit 50
python -m mma_future_scraper.cli run --stop-after-known-complete 20
python -m mma_future_scraper.cli run --force-recheck-event tapology_event_141836
python -m mma_future_scraper.cli run --force-recheck-event https://www.tapology.com/fightcenter/events/141836-example
python -m mma_future_scraper.cli run --force-full-scan
python -m mma_future_scraper.cli run --mode backfill --skip-known-complete
python -m mma_future_scraper.cli run --no-enrich-fighter-profiles
python -m mma_future_scraper.cli run --debug
python -m mma_future_scraper.cli run --headed --wait-for-manual-challenge 300
python -m mma_future_scraper.cli run --headed --wait-for-manual-challenge 300 --browser-profile-dir data/browser_profile/tapology
python -m mma_future_scraper.cli run --headed --browser-channel msedge --browser-profile-dir data/browser_profile/tapology-msedge
python -m mma_future_scraper.cli run --headed --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir data/browser_profile/tapology-brave
python -m mma_future_scraper.cli run --headed --browser-executable-path "C:\Users\lukam\AppData\Local\Programs\Opera\opera.exe" --browser-profile-dir data/browser_profile/tapology-opera
python -m mma_future_scraper.cli tapology-auth --browser-profile-dir data/browser_profile/tapology
python -m mma_future_scraper.cli tapology-auth --browser-channel msedge --browser-profile-dir data/browser_profile/tapology-msedge
python -m mma_future_scraper.cli tapology-auth --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir data/browser_profile/tapology-brave
python -m mma_future_scraper.cli tapology-auth --browser-executable-path "C:\Users\lukam\AppData\Local\Programs\Opera\opera.exe" --browser-profile-dir data/browser_profile/tapology-opera
python -m mma_future_scraper.cli tapology-auth --browser-engine firefox --browser-profile-dir data/browser_profile/tapology-firefox
python -m mma_future_scraper.cli build-fighter-profile-queue --include-run-history
python -m mma_future_scraper.cli enrich-fighter-profiles --limit-fighters 10
python -m mma_future_scraper.cli validate-latest
python -m mma_future_scraper.cli state-report
python -m mma_future_scraper.cli build-fighter-index
```

`--limit-events` is an alias for `--max-events`.

`--browser-engine` selects the Playwright browser engine. Allowed values are `chromium`, `firefox`, and `webkit`; the default is `chromium`, preserving existing behavior. If the selected engine is not installed locally, install it with a command such as `.\.venv\Scripts\python.exe -m playwright install firefox`.

`--browser-channel` selects an installed Chromium-family browser channel. Allowed values are `chrome`, `chrome-beta`, `chrome-dev`, `msedge`, `msedge-beta`, and `msedge-dev`. Use it with `--browser-engine chromium` only. For local Cloudflare/manual verification, prefer a separate persistent profile per channel, for example `.\data\browser_profile\tapology-msedge` for Edge and `.\data\browser_profile\tapology-chrome` for Chrome.

`--browser-executable-path` launches a Chromium-family browser from a specific `.exe` path. This is useful for Brave, Vivaldi, or a Chrome install that does not behave well through `--browser-channel`. It cannot be combined with `--browser-channel`. Keep profile directories separate per browser executable, for example `.\data\browser_profile\tapology-brave`.

## Local Headed Operation

Use headed/manual mode for controlled local Tapology runs when headless Chromium is blocked by Cloudflare or a not-a-bot page. This mode does not bypass challenges or solve them automatically; it only gives the operator time to complete a visible browser challenge.

Before hitting Tapology, verify headed Chromium can launch locally:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli browser-check --headed
```

Recommended small incremental results command:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run --headed --results-only --from-date 2026-05-24 --max-events 5 --stop-after-known-complete 5 --wait-for-manual-challenge 300
```

Recommended cautious three-page results pass:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run `
  --headed `
  --results-only `
  --mode backfill `
  --max-pages 3 `
  --skip-known-complete `
  --stop-on-access-degradation `
  --max-event-fetch-failures 10 `
  --wait-for-manual-challenge 900 `
  --no-enrich-fighter-profiles `
  --debug
```

`--wait-for-manual-challenge SECONDS` accepts a non-negative integer. In headed mode, if a Cloudflare/Turnstile/not-a-bot page appears, the scraper prints operator instructions, waits up to that many seconds, polls the current page, and resumes only after the real FightCenter page is positively ready. Do not close the browser during the wait; leave it open until the scraper reports that it is resuming or has aborted. In headless mode this option is ignored with a warning and the scraper fails fast.

`--pause-on-manual-challenge` is an opt-in headed/manual hard-pause mode. When challenge markers are detected, the scraper stops automated FightCenter readiness checks and waits for terminal ENTER before inspecting the page again. Press ENTER only after the real Tapology FightCenter page with filters/events is visible. This is operator-controlled pause behavior, not bypass logic.

`--browser-profile-dir data/browser_profile/tapology` is an optional headed-only local browser profile directory. It lets the operator reuse local browser session state across manual runs; it is not a bypass mechanism, and `data/browser_profile/` is ignored by git. Keep profile directories separate per engine. Do not reuse Chromium profile directories for Firefox; for example, use `.\data\browser_profile\tapology-firefox` for Firefox.

### Operator-first Tapology access

Use this flow when Tapology verification blocks runs where the scraper is the first component to open FightCenter. This is manual/local session preparation only. It does not bypass Cloudflare, solve challenges, click Turnstile, use proxies, or export browser session data. Cookies stay inside the local browser profile under `data/browser_profile/`, which is gitignored.

Prepare the persistent headed session:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-engine firefox `
  --browser-profile-dir ".\data\browser_profile\tapology-firefox"
```

If Firefox is not installed for Playwright yet, install it first:

```powershell
.\.venv\Scripts\python.exe -m playwright install firefox
```

The command opens a headed Firefox browser with the persistent profile and waits for terminal ENTER. Manually open `https://www.tapology.com/fightcenter`, complete Cloudflare if shown, confirm the page loads normally, then press ENTER in the terminal.

For Chromium, use a different profile directory, for example:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-profile-dir ".\data\browser_profile\tapology"
```

For Microsoft Edge, use the Chromium engine with the Edge channel:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" `
  --browser-profile-dir ".\data\browser_profile\tapology-msedge"
```

If Edge does not work, use Brave through its executable path:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" `
  --browser-profile-dir ".\data\browser_profile\tapology-brave"
```

Chrome can also be launched directly:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-executable-path "C:\Program Files\Google\Chrome\Application\chrome.exe" `
  --browser-profile-dir ".\data\browser_profile\tapology-chrome"
```

Opera works the same way:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli tapology-auth `
  --browser-executable-path "C:\Users\lukam\AppData\Local\Programs\Opera\opera.exe" `
  --browser-profile-dir ".\data\browser_profile\tapology-opera"
```

Then run a one-page Firefox operator-first scrape:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run `
  --results-only `
  --mode backfill `
  --sport mma `
  --promotion-scope all `
  --max-pages 1 `
  --headed `
  --browser-engine firefox `
  --wait-for-manual-challenge 900 `
  --operator-navigates-to-fightcenter `
  --use-current-page `
  --browser-profile-dir ".\data\browser_profile\tapology-firefox" `
  --pause-on-manual-challenge `
  --no-enrich-fighter-profiles `
  --debug
```

`--operator-navigates-to-fightcenter` opens the headed browser but does not navigate to FightCenter before operator input. Manually open `https://www.tapology.com/fightcenter`, complete Cloudflare, set the desired filters, wait until the real FightCenter page with filters/events is visible, then press ENTER. While waiting for ENTER, the scraper does not navigate, reload, poll selectors, run readiness checks, apply filters, or parse events.

`--use-current-page` tells the scraper to use the visible FightCenter listing DOM as page 1 after readiness is confirmed. It skips the initial FightCenter `goto` when combined with operator navigation and does not reset or re-apply filters. The operator is responsible for setting the desired FightCenter filters before pressing ENTER:

- Promotion: All or the project-relevant non-UFC scope
- Schedule: Results
- Sport: MMA
- Region: All or blank

After ENTER, the scraper performs one readiness check. Cloudflare/security markers still block continuation even if the URL contains `/fightcenter`. If the current page is ready, the scraper prints a pre-scrape confirmation summary with the URL, title, readable filters, visible listing row count, and Results/MMA/FightCenter checks before opening event pages. If filter values cannot be read confidently, an interactive operator must confirm or abort; non-interactive runners fail clearly. Do not press ENTER while still on Cloudflare verification.

Codex and other non-interactive runners cannot complete the operator-first flow because real browser operation and terminal stdin are required. Run the commands in a genuinely interactive local PowerShell terminal.

If Tapology blocks again and the challenge cannot be completed within the timeout, stop the run. The failed run remains under `data/runs/{run_id}/` with a failed manifest and any partial results, while `data/latest/` and successful state are not advanced.

The scraper writes only local JSON artifacts under `scraper/data`. It does not touch WordPress, the database, rankings, stats rebuilds, or theme/plugin files.

## Storage Model

The scraper separates immutable run artifacts from operational state:

```text
scraper/data/
  latest/
    results.json
    fighter_profiles.json
    fighter_profiles_report.json
    changes.json
    run_manifest.json
    manual_review.json
    daily_summary.json
    state_report.json

  runs/
    20260512T194025Z/
      results.json
      checkpoint_results.json
      changes.json
      run_manifest.json
      debug.json

  state/
    scraper_state.json
    events_index.json
    bouts_index.json
    incomplete_events.json
```

Every run gets its own `data/runs/{run_id}/` folder. These files are historical audit artifacts. They are not scanned to decide what to scrape next.

The operational source of truth is `data/state/`:

- `scraper_state.json` tracks the last attempted run, last successful run, internal ID counters, and known totals.
- `events_index.json` is keyed by `source_event_id` and stores stable `internal_event_id`, event metadata, scrape timestamps, fingerprints, known bout IDs, completion state, and recheck flags.
- `bouts_index.json` is keyed by `source_bout_id` and stores stable `internal_bout_id`, parent event IDs, normalized fingerprints, result fields, and fighter names.
- `incomplete_events.json` is the persistent recheck queue.

`data/latest/` mirrors the newest successful run's `results.json`, `changes.json`, and `run_manifest.json` for convenience. `latest/fighter_profiles.json` is also used as a persistent successful profile cache when event-level fighter profile enrichment is enabled. It is not the only state source.

Dashboard import must treat these `data/latest/` files as the canonical pre-import contract:

- `results.json`: source of truth for events and bouts.
- `fighter_profiles.json`: source of truth for fighter profile snapshots.
- `run_manifest.json`: source of truth for the latest event run status and scraper counts.
- `fighter_profiles_report.json`: source of truth for latest profile enrichment coverage.
- `changes.json`: latest event/bout/fighter delta for import planning.
- `manual_review.json`: non-silent review list for incomplete or suspicious records.
- `daily_summary.json`: import gate result. Automated import should require `ready_for_import: true`.
- `state_report.json`: optional local state health snapshot.

Dashboard import should not read `partial_results.json`, `checkpoint_results.json`, browser cache, or individual `data/runs/{run_id}/` folders except for manual debugging.

## Run Artifacts

Successful runs write:

- `results.json`: normalized result data discovered during that run.
- `changes.json`: compact machine-readable lists of new, updated, unchanged, and incomplete event/bout internal IDs, plus `new_fighter_urls`, `fighters_to_refresh`, and `manual_review_events`.
- `run_manifest.json`: run status, options, and counts.
- `debug.json`: only when `--debug` is passed.
- `checkpoint_results.json`: best-effort partial results checkpoint written during results runs after successful event batches and again on fatal stop.

Normal `results.json` does not store raw page dumps, text excerpts, URL hashes, duplicate debug fields, matchup debug payloads, or ranking point assumptions.

## Incremental Rechecks

The scraper does not rely on a single "last scraped event" or "last scraped date" cursor. Each run considers:

- newly visible FightCenter result events
- incomplete events whose `next_recheck_after` has passed
- recent known events within `--recheck-days`, default `14`
- the newest known events up to `--recheck-known-limit`, default `50`
- forced events from `--force-recheck-event`
- every visible event when `--force-full-scan` is used

Traversal stops only after `--stop-after-known-complete` consecutive events are already known, complete, outside the recheck window, and not queued as incomplete. The default is `20`. Incomplete events whose `next_recheck_after` is still in the future can be skipped for that run, but they do not count toward this stop condition.

Older complete events outside the recheck window can be skipped safely. Recent events and due incomplete events remain eligible because Tapology may update older event pages after the first scrape.

`--mode backfill` still revisits visible known events by default. Add `--skip-known-complete` when doing a broader backfill pass where already complete, stable events should be treated like cached work and skipped outside the normal recheck scope. Skipped cached events are counted separately as `events_skipped_known_cache` in run summaries and manifests.

## Fighter Profile Cache

When `--enrich-fighter-profiles` is enabled, successful entries from `data/latest/fighter_profiles.json` are reused before opening Tapology fighter profile pages. The default cache TTL is 60 days. Use `--profile-cache-ttl-days N` to change it, or `--no-profile-cache` to force live profile fetches.

Profile cache activity is written to run summaries and manifests as `profile_cache_hits`, `profile_cache_misses`, `profile_cache_expired`, and `profile_cache_disabled`. Cached event-level profile records include `profile_cache_hit`, `profile_cache_source`, and `profile_cache_scraped_at`.

## Incomplete Events

An event is queued in `incomplete_events.json` when no completed professional MMA bouts were found, results are missing or unknown, parser warnings indicate uncertainty, or the event is recent enough that Tapology may still update it.

Event pages that appear in a listing but fail to fetch are also queued. Timeout failures use `recheck_reason: "event_fetch_timeout"`; other event fetch failures use `recheck_reason: "event_fetch_failed"`. These records are represented in `events_index.json`, `incomplete_events.json`, `changes.json`, and `manual_review.json`, so a listing event is never silently lost.

When a later run scrapes the event cleanly and it is outside the recheck window, it is removed from the queue.

## IDs and Fingerprints

`source_event_id` and `source_bout_id` are the primary dedupe keys. The v0.5 results contract also exposes numeric source ID components where Tapology URLs allow parsing:

- events: `source_event_numeric_id`
- bouts: `source_bout_numeric_id`
- fighters: `source_fighter_numeric_id`
- promotions: `source_promotion_id` and `source_promotion_numeric_id`

Existing source IDs always reuse their original internal IDs:

- events use `internal_event_id`, for example `mf_event_000001`
- bouts use `internal_bout_id`, for example `mf_bout_000001`

Event and bout `identity_hash` fields recognize the same Tapology source entity across runs. Event and bout `content_hash` fields detect material source content changes, including explicit bout import/scoring flags. Both use stable JSON serialization and SHA-256, exclude timestamps, run IDs, `scraped_at`, and internal IDs, and are distinct from the operational state fingerprints.

Deterministic normalized state fingerprints still detect updates for scraper state. Fingerprints ignore timestamps, run IDs, debug data, and internal IDs, while including important normalized fight data such as fighters, source IDs, prefight records, winner/loser, result type, method, round/time, weight, card position, and title-fight information.

If a fingerprint changes, the item is reported as updated in `changes.json` and keeps the same internal ID.

## Retention

Do not delete run folders by default. Four hundred or more normal JSON run folders per year is acceptable because these artifacts are small and organized by timestamp.

Debug files can be larger. During development, old debug runs can be deleted more aggressively.

Safe during development:

- old `data/runs/{run_id}/` folders if you no longer need audit history
- `debug.json` files from debug runs
- old failed partial run folders

Preserve in production:

- `data/state/scraper_state.json`
- `data/state/events_index.json`
- `data/state/bouts_index.json`
- `data/state/incomplete_events.json`

Warning: deleting `data/state/` resets internal IDs and can confuse a future importer that has already seen previous `mf_event_*` or `mf_bout_*` IDs.

## Atomic Writes

JSON files are written through temporary files and atomically replaced. Successful commits write run artifacts, stage state files, commit state/index files, then update `latest/`.

If scraping, validation, or state commit fails, previous successful state is not advanced and internal ID counters are not permanently moved forward. The run may leave a failed `run_manifest.json` and partial results for inspection.

Temporary files and pending state folders are ignored by loaders and are not treated as committed state.

## Validation

Validation rejects raw/debug fields in normal output, duplicate source IDs, duplicate internal IDs, missing internal/source IDs, missing or identical identity/content hashes, misaligned numeric source IDs, invalid promotion ID/null-warning combinations, bad parent event references, invalid state/index mappings, incomplete events that reference unknown events, and internal counters that are not greater than assigned IDs.

## Tests

Pure-function and fixture tests do not require live Tapology access:

```powershell
.\.venv\Scripts\python.exe -m pytest
```

Compile the scraper package:

```powershell
.\.venv\Scripts\python.exe -m compileall src
```

Validate latest JSON:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
```

Build and enforce the dashboard pre-import gate:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli pre-import-check --expected-pages 15 --max-event-fetch-failures 0 --min-profile-success-rate 0.99
```

`validate-latest` writes `daily_summary.json` and `manual_review.json` as a convenience, but it returns success or failure only for JSON/schema integrity. `pre-import-check` is the command that should block automated dashboard import when `ready_for_import` is false.

## Production Daily Run

Event daily:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run `
  --results-only `
  --mode daily `
  --sport mma `
  --promotion-scope all `
  --max-pages 15 `
  --skip-known-complete `
  --cumulative-results `
  --recheck-days 0 `
  --recheck-known-limit 0 `
  --headed `
  --browser-channel msedge `
  --wait-for-manual-challenge 600 `
  --operator-navigates-to-fightcenter `
  --use-current-page `
  --browser-profile-dir ".\data\browser_profile\tapology-brave" `
  --pause-on-manual-challenge `
  --no-enrich-fighter-profiles `
  --page-pause-seconds 4 `
  --jitter-seconds 1 `
  --stop-on-access-degradation `
  --max-event-fetch-failures 10 `
  --debug `
  --stop-after-known-complete 99999
```

Then:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-index
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-profile-queue --queue-mode strict-daily
.\.venv\Scripts\python.exe -m mma_future_scraper.cli enrich-fighter-profiles --from-queue --limit-fighters 300 --headed --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe"
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-profile-enrichment-report
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
.\.venv\Scripts\python.exe -m mma_future_scraper.cli state-report
.\.venv\Scripts\python.exe -m mma_future_scraper.cli pre-import-check --expected-pages 15 --max-event-fetch-failures 0 --min-profile-success-rate 0.99
```

Queue modes:

- `default`: keep the standard queue artifact and refresh entries where `should_refresh=true`.
- `strict-daily`: include only refreshable fighters seen in events listed by the latest `changes.json`.
- `recovery`: focus on failed profiles or profiles with retry-oriented stale reasons.
- `full-maintenance`: keep the broad TTL-driven maintenance queue.

## Staged Tapology Verification Recipes

Use these stages in order. Do not move to the next stage until the previous run validates, `data/runs/{run_id}/run_manifest.json` is successful, and the pagination/profile/source-total diagnostics look acceptable. If Cloudflare, access degradation, repeated timeouts, or elevated profile failures appear, stop and cool down; do not repeatedly retry failed large runs.

Stage A - one-page Brave operator verification:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run --results-only --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir ".\data\browser_profile\tapology-brave" --headed --operator-navigates-to-fightcenter --use-current-page --no-enrich-fighter-profiles --max-pages 1 --debug
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
```

Stage B - 2-3 page Brave verification with conservative pacing:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run --results-only --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir ".\data\browser_profile\tapology-brave" --headed --operator-navigates-to-fightcenter --use-current-page --no-enrich-fighter-profiles --max-pages 3 --page-pause-seconds 90 --jitter-seconds 5 --stop-on-access-degradation --debug
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
```

Stage C - five-page Brave verification:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run --results-only --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir ".\data\browser_profile\tapology-brave" --headed --operator-navigates-to-fightcenter --use-current-page --no-enrich-fighter-profiles --max-pages 5 --page-pause-seconds 90 --jitter-seconds 5 --stop-on-access-degradation --debug
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
```

Stage D - full 15-page run, only after A-C pass:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli run --results-only --browser-executable-path "C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe" --browser-profile-dir ".\data\browser_profile\tapology-brave" --headed --operator-navigates-to-fightcenter --use-current-page --no-enrich-fighter-profiles --max-pages 15 --page-pause-seconds 90 --jitter-seconds 5 --stop-on-access-degradation --debug
.\.venv\Scripts\python.exe -m mma_future_scraper.cli validate-latest
```

After a successful event-only run, build and consume the fighter queue separately:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-index
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-profile-queue --include-run-history
.\.venv\Scripts\python.exe -m mma_future_scraper.cli enrich-fighter-profiles --from-queue --limit-fighters 20
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-profile-enrichment-report
```

After a successful run, validate `data/latest/results.json` with `validate-latest`, then inspect the specific new `data/runs/{run_id}/results.json` before using it for a plugin import dry-run. From the plugin directory, the dry-run command shape is:

```powershell
$env:MMAF_CLI_DB_HOST='127.0.0.1:10030'; php tools\run-import-dry-run.php C:\Users\lukam\Local Sites\mma-future\scraper\data\runs\{run_id}\results.json
```

The dry-run must not be followed by an actual import without explicit approval. If it writes `source_import_run` metadata, record the new dry-run ID and remember that canonical fighters, events, and bouts were not modified by the dry-run.

Print a local state report:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli state-report
```

`state-report` only reads local JSON files from `data/state/` and `data/latest/`. It does not collect new data, open a browser, change internal IDs, delete records, or modify state files. It writes a convenience report to `data/latest/state_report.json` with known event/bout totals, incomplete queue counts, due/not-due rechecks, event states, bout result/status groups, missing IDs, orphan bouts, duplicate ID issues, and latest run summary.

## Derived Fighter Index

Build local fighter aggregation files from the already-collected normalized results:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-index
```

This command reads `data/latest/results.json` and writes:

```text
scraper/data/derived/
  fighters_index.json
  fighter_fight_logs.json
  processor_report.json
```

`fighters_index.json` groups fighters by stable Tapology fighter URL identity when available, tracks dataset-level wins/losses/draws/no-contests, finish counts, latest dataset fight, name variants, and fighter A/B appearances. It also separates record sources for dashboard/review use:

- `tapology_profile_record`: current external profile record from `data/latest/fighter_profiles.json`.
- `prefight_record_latest`: latest bout-level prefight record seen in `results.json`.
- `computed_record_from_imported_bouts`: record computed only from collected completed professional bouts.
- `record_sources.record_mismatch`: true when the Tapology profile record and computed imported-bout record disagree.

`fighter_fight_logs.json` stores one row per fighter per collected bout, including event and bout IDs, opponent, result for that fighter, method, round/time, weight, card position, prefight records, and opponent prefight win-loss differential when calculable.

`processor_report.json` summarizes readiness for a later ranking step, including unique fighter count, fight-log row count, missing URLs, missing method categories, missing prefight records, missing win/loss mappings, unavailable opponent prefight differentials, record mismatches, and top fighters by dataset wins/finishes.

Important: these derived files are dataset-level aggregation only. They are not full career records and they are not final MMA Future rankings. The production ranking formula is intentionally left for a later step.

## Debug Mode

Use `--debug` only when parser maintenance needs raw evidence:

```powershell
python -m mma_future_scraper.cli run --headed --results-only --limit-events 3 --debug
```

Debug mode writes `debug.json` in the current run folder. It may include raw row text, page text excerpts, page titles, and parser diagnostics. It is not copied into normal results and should not be consumed by importer code.

## Optional Fighter Profile Enrichment Prototype

This scraper keeps event/result scraping separate from fighter profile scraping. The normal workflow is:

1. Run event scraping with `--no-enrich-fighter-profiles`
2. Validate `results.json`
3. Build/refresh the fighter index
4. Build the fighter profile queue
5. Enrich fighter profiles from the queue in smaller batches
6. Build the enrichment report

Build the queue:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli build-fighter-profile-queue --include-run-history
```

Then run a small enrichment batch:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli enrich-fighter-profiles --from-queue --limit-fighters 10
```

For local parser debugging:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli enrich-fighter-profiles --from-queue --headed --limit-fighters 5 --debug
```

The command reads fighter profile URLs from the queue when `--from-queue` is set, otherwise it falls back to `data/latest/results.json`. It visits profiles sequentially and writes separate enrichment artifacts:

```text
scraper/data/runs/{run_id}/fighter_profiles.json
scraper/data/latest/fighter_profiles.json
scraper/data/latest/fighter_profile_queue.json
scraper/data/latest/fighter_profile_queue_report.json
```

`latest/fighter_profiles.json` is merge-safe. New successful profile scrapes replace older entries for the same fighter identity, failed refreshes do not delete a previously successful cached profile, and untouched cached profiles remain in place across batches.

It does not update `results.json`, `changes.json`, `run_manifest.json`, scraper state indexes, WordPress data, database tables, ranking inputs, public status, rankable status, fighter linking, or canonical fighter records.

The output schema is `tapology_fighter_profiles_v0_1`. Fields are source suggestions for later review, not canonical truth. Exact DOB is captured only when explicitly shown; birth year is only derived from an exact DOB. Age alone is not used to derive DOB or birth year. Missing DOB or `age_at_event` must not block event/bout import. Nationality is conservative and often missing; it is accepted only from high-confidence profile-header flag/country metadata and is never inferred from born location, fighting-out location, event location, promotion, fighter name, team, gym, affiliation, or body text. `born_country` and `fighting_out_of_country` are source metadata/review suggestions, not canonical nationality. Profile current records remain under event-level `fighter_profiles` and must not overwrite bout prefight records. `profile_image_url` is intentionally emitted as `null`; fighter images are selected manually in WordPress later. Affiliation/team-like profile fields are source suggestions only, and values contaminated by Tapology page chrome, news, ad, or unrelated UI text are emitted as `null` with a parse warning.

If a profile is blocked, malformed, missing, or unparseable, the profile status is one of `blocked`, `not_found`, `failed_parse`, or `skipped_no_url`. Existing debug artifact support is used to write HTML and screenshots under the run folder's `raw_debug/` directory for failed profile pages.

The regular WordPress ZIP importer consumes `fighter_profiles.json` after event data. It fills only its approved safe fields when the canonical value is empty and field provenance is not manually protected. Invalid, ambiguous, unmatched, or already populated values are preserved for review. Public/rankable approval, fighter merging, complete career-history import, and ranking activation remain manual.

Create the immutable package used by **MMA Future > Import** only after scraping has finished:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli export-import-bundle --output C:\path\to\mmaf-import-YYYYMMDD.zip --expected-pages 15 --max-event-fetch-failures 0 --min-profile-success-rate 0.99
```

The exporter copies a stable snapshot, rebuilds its pre-import health artifacts, verifies that source files did not change while copying, and writes a manifest containing source run IDs, counts, byte sizes, and SHA-256 checksums. It never overwrites an existing ZIP.

For multi-page FightCenter runs, the initial visible listing is page/batch 1. If `--max-pages` requests additional batches and `#loadMoreButton` times out or appends no new unique event URLs before the requested page count is reached, the attempt is treated as failed/degraded under the scraper's existing failed-attempt convention. It writes run artifacts, including partial results and a manifest with the pagination stop reason, but it does not advance `data/latest/results.json` as a complete dataset.

## Known Limitations

- Tapology markup can change without notice; use `inspect-filters` and debug artifacts to update selectors.
- Regional event pages may not always expose professional/amateur status. Unknown professional status is skipped for results rather than imported as a ranking candidate.
- Missing prefight records are preserved as missing records.
- `N/A`, blank, and unknown methods normalize to `method_category = "unknown"` and keep the event queued for recheck.
- Tapology may show a Cloudflare security verification page to headless Chromium in this environment. Do not bypass it; use `--headed` for live local discovery runs when needed.
