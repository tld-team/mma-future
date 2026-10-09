# MMA Future Scraper Inventory

Audit date: 2026-05-13
Audited scope: `scraper/` only. WordPress theme/plugin files under `app/public/` were not treated as part of the scraper.

## Executive Summary

The current scraper is a standalone Python package named `mma-future-scraper`. It uses Playwright to open Tapology FightCenter, applies regional MMA result/upcoming filters, opens event pages, parses professional MMA bouts, optionally opens Tapology bout matchup pages for better details, and writes JSON files under `scraper/data/`.

The scraper does not write to WordPress, does not resolve final canonical fighter identity, and does not calculate rankings. It does maintain scraper-level source IDs, internal scraper IDs, state indexes, incremental recheck state, and derived local fighter aggregation files.

The main importable contract today is `data/latest/results.json` plus selected provenance from `data/latest/run_manifest.json` and `data/latest/changes.json`. State files are useful for scraper operation and importer diagnostics, but they should not become canonical ranking tables.

## Project Structure

Important paths:

```text
scraper/
  README.md
  pyproject.toml
  requirements.txt
  SCRAPER_INVENTORY.md
  SCRAPER_DATA_CONTRACT.md

  src/mma_future_scraper/
    __init__.py
    browser.py
    cli.py
    config.py
    dedupe.py
    fighters.py
    logging_utils.py
    models.py
    normalize.py
    selectors.py
    state_report.py
    storage.py
    tapology.py
    utils.py
    validation.py

  tests/
    conftest.py
    test_dedupe.py
    test_fighters.py
    test_ids.py
    test_normalize.py
    test_records.py
    test_state_report.py
    test_storage_state.py
    test_tapology_parsing.py
    test_validation.py

  data/
    latest/
      results.json
      changes.json
      run_manifest.json
      state_report.json
    runs/
      {run_id}/
        results.json
        changes.json
        run_manifest.json
        debug.json              # only for debug runs
        partial_results.json    # failed/interrupted runs
        partial_upcoming.json   # older failed/interrupted run artifact
        raw_debug/*.html|*.png  # only when saved for parser/block debugging
    state/
      scraper_state.json
      events_index.json
      bouts_index.json
      incomplete_events.json
    derived/
      fighters_index.json
      fighter_fight_logs.json
      processor_report.json

  data_backup_stabilization_*/
  data_backup_before_may_2026_seed_*/
```

Source modules:

- `cli.py`: argparse entrypoint. Defines commands `run`, `inspect-filters`, `validate-latest`, `state-report`, and `build-fighter-index`.
- `tapology.py`: main scraping flow in `TapologyScraper`. Opens FightCenter, applies filters, traverses event list, opens event/matchup pages, parses events and bouts.
- `storage.py`: data directories, atomic JSON writes, immutable run artifacts, `latest/` mirrors, persistent state/index files, incremental recheck decision logic, content fingerprints, changes manifest.
- `normalize.py`: prefight record parsing, method normalization, round/time parsing, professional/amateur detection, non-MMA detection, status flag helper.
- `utils.py`: URL canonicalization, Tapology source ID extraction, fallback bout ID generation, date parsing, hashing.
- `selectors.py`: Tapology selectors and filter alias values.
- `browser.py`: Playwright launch, blocking UI dismissal, hard-block detection, raw debug artifact saving.
- `validation.py`: output and state validation.
- `state_report.py`: read-only local state report builder/writer.
- `fighters.py`: local derived fighter aggregation from `data/latest/results.json`.
- `dedupe.py`: event/bout dedupe helpers. Some functions such as `mark_new_flags()` and `build_success_state()` reflect an older simpler state model and are not used by the current `TapologyScraper.run()` path.
- `models.py`: schema version constants.
- `logging_utils.py`: structured console logging.

Package metadata:

- `pyproject.toml` package name: `mma-future-scraper`
- Python requirement: `>=3.11`
- Runtime dependencies: `playwright>=1.44`, `python-dateutil>=2.9`
- Test dependency: `pytest>=8.0`
- Package source root: `src`

## Current CLI Capabilities

All commands are run from `scraper/`:

```powershell
.\.venv\Scripts\python.exe -m mma_future_scraper.cli <command>
```

### `run`

Scrapes Tapology FightCenter and writes run artifacts/state.

Implementation:

- Parser: `cli.build_parser()`
- Execution: `cli.main()`
- Scraper: `TapologyScraper.run()`

Default behavior:

- Scrapes both results and upcoming events unless one of the mode flags is supplied.
- Headless browser by default.
- Creates a new UTC timestamp run folder under `data/runs/{run_id}/`.
- Updates `data/latest/` and `data/state/` only on successful commit.

Options:

| Option | Input | What it does | Expected output | When to use |
| --- | --- | --- | --- | --- |
| `--results-only` | Flag | Scrapes only regional MMA results. Mutually exclusive with `--upcoming-only`. | `results.json`, `changes.json`, `run_manifest.json`; no new `upcoming.json` from current run. | Normal result imports/rechecks. |
| `--upcoming-only` | Flag | Scrapes only regional MMA upcoming events. | Current code still commits a `results.json` document, but it can be empty; writes `upcoming.json` in the run folder. `latest/` mirrors only results/changes/manifest. | Discovery of upcoming cards; not currently the primary plugin import feed. |
| `--headless` | Flag | Runs Chromium headless. This is the default. | Same JSON outputs. | Automation where Tapology does not block headless access. |
| `--headed` | Flag | Runs visible Chromium. | Same JSON outputs. | Local live runs when Tapology/Cloudflare blocks headless or when debugging selectors. |
| `--max-pages` | Integer | Maximum FightCenter list pages/load-more advances to traverse. | Stops after that many list pages. | Small bounded runs and parser testing. |
| `--max-events` | Integer | Maximum event pages to open. | Stops after that many scraped events. | Bounded runs. |
| `--limit-events` | Integer | Alias for `--max-events`; if supplied it overrides `--max-events` in config. | Same as `--max-events`. | Backward-compatible/short bounded runs. |
| `--from-date` | `YYYY-MM-DD` | Keeps events whose normalized `event_date` is on or after the date. If list order appears newest-to-oldest, traversal stops once older events are reached. | Results limited by event date; warning `from_date_stop_disabled:list_not_newest_to_oldest` if ordering is not confirmed. | Date-window seed/import runs, e.g. May 2026-only results. |
| `--recheck-days` | Integer, default `14` | Known result events inside this many days from event date remain eligible for recheck. | Recent complete events are kept in `incomplete_events.json` with `recent_update_window` until they age out. | Handling Tapology late updates after events. |
| `--recheck-known-limit` | Integer, default `50` | Always keeps this many newest known events eligible, based on `events_index` event dates. | Rechecks newest known source events even outside other conditions. | Safety net for late changes among newest events. |
| `--stop-after-known-complete` | Integer, default `20` | Stops list traversal after this many consecutive skipped events are known, complete, outside recheck scope, and not queued incomplete. | Shorter incremental runs. | Normal incremental operation. |
| `--force-recheck-event` | Source event ID or Tapology event URL; repeatable | Forces a specific event to be eligible. In `TapologyScraper.__init__`, URLs are normalized to `tapology_event_{id}` when possible. | Re-scrapes the forced visible event if it appears in the traversed list. | Repair/recheck known problematic events. |
| `--force-full-scan` | Flag | Disables incremental skipping for visible list events. | All visible events in traversed pages are eligible. Manifest `mode` becomes `full_scan`. | Full refresh/audit runs. |
| `--slow-mo` | Milliseconds | Passes Playwright slow motion. | Slower browser actions. | Debugging live DOM behavior. |
| `--debug` | Flag | Enables `debug.json` and raw debug captures in some failure paths. | Run folder may include `debug.json` and `raw_debug/*.html|*.png`. | Parser maintenance only; not importer input. |

### `inspect-filters`

Opens Tapology FightCenter, waits for filter controls, prints detected select/radio/checkbox controls and options as JSON.

Options:

- `--headless` / `--headed`
- `--slow-mo`

Expected output:

- JSON array printed to stdout. It is not written to `data/`.

Use when:

- Tapology changes filter markup.
- The scraper cannot find required controls.

Implementation:

- `TapologyScraper.inspect_filters()`
- `TapologyScraper._collect_filter_controls()`

### `validate-latest`

Validates current latest files:

- `data/latest/results.json` schema `scraper_results_v0_5`
- `data/latest/changes.json` schema `scraper_changes_v0_1`
- `data/latest/run_manifest.json` schema `scraper_run_manifest_v0_1`

Additional checks include duplicate source/internal IDs, missing IDs, banned raw/debug/hash fields, bad parent event references, missing fighter names, missing prefight records, missing result method category, invalid method category, missing/non-boolean bout flags, and impossible scoring-candidate flag combinations.

Expected output:

- Structured log lines.
- Exit code `0` on success, `1` on validation failure.

Implementation:

- `cli.validate_latest()`
- `validation.validate_results_document()`

### `state-report`

Builds and prints a read-only report from local JSON files and writes:

```text
data/latest/state_report.json
```

Expected output:

- Human-readable state summary on stdout.
- JSON report in `data/latest/state_report.json`.

Use when:

- Auditing known events/bouts, incomplete queue, duplicate IDs, orphan bouts, and latest run summary.

Implementation:

- `state_report.build_state_report()`
- `state_report.write_state_report()`
- `state_report.format_state_report()`

### `build-fighter-index`

Builds local derived fighter aggregation from `data/latest/results.json`:

```text
data/derived/fighters_index.json
data/derived/fighter_fight_logs.json
data/derived/processor_report.json
```

This is not final ranking logic. It groups fighters using Tapology fighter URL identity when available, creates dataset-level fight logs, and reports missing method/prefight/winner-loser data.

Expected output:

- Summary printed to stdout.
- Exit code `0` if derived validation has no errors, else `1`.

Implementation:

- `fighters.build_fighter_index_from_latest()`

## Data Flow

### Browser and FightCenter opening

`TapologyScraper.run()` creates a UTC `run_id`, creates `data/runs/{run_id}/`, and calls `DataStore.begin_attempt()` before opening the browser.

`browser.launch_browser()` starts Playwright Chromium with viewport `1365x900`, `headless` from config, and default action timeout from `ScraperConfig.action_timeout_ms`.

For each requested kind (`results`, `upcoming`), `TapologyScraper._scrape_kind()`:

1. Opens `https://www.tapology.com/fightcenter`.
2. Calls `dismiss_blocking_ui()` to click common cookie/modal buttons.
3. Calls `_raise_if_blocked()` which uses `detect_hard_block()` against page title/body text for captcha/security/access-denied/login/block patterns.
4. Waits for FightCenter controls via `_wait_for_filter_controls()`.
5. Applies filters via `_apply_filters()`.

### Filter application

Selectors/constants:

- Form ID: `fightCenterRefine`
- Event list selector: `.fightcenterEvents`
- Required filters are inferred from live DOM by `_collect_filter_controls()`.

Filter aliases:

- Promotion: `regional`
- Schedule results: `results`, `result`
- Schedule upcoming: `upcoming`, `future`
- Sport: `mma`, `mixed martial arts`
- Region: blank/all/any-like values where available

`_apply_filters()` finds matching `select` controls by label/name/id/nearby text and option aliases. It selects promotion, schedule, sport, and optionally region, then `_submit_filters()` clicks a search/filter submit button or calls `form.requestSubmit()`. It waits until the event list exists and changes.

### Event list traversal

`TapologyScraper._traverse_event_list()` handles pagination/load-more behavior and incremental skipping.

Event list extraction:

- `_extract_event_list_items()` evaluates DOM under `.fightcenterEvents`.
- It gathers unique links matching `a[href*='/fightcenter/events/']`.
- For each link, it records link text, canonical event URL, `source_event_id`, date parsed from row text, promotion parsed from row text, location, visible main bout text, list page number, and list position.

Pagination/load more:

- `_advance_list_page()` tries selectors:
  - `.fightcenterEvents .load-more-button`
  - `.fightcenterEvents a:has-text('More')`
  - `.fightcenterEvents a:has-text('Next')`
  - `.fightcenterEvents a[href*='page=']`
  - `.load-more-button`
- `_wait_for_more_events()` considers the page advanced if the event link count grows, or URL changes with new event URLs.

From-date behavior:

- `_from_date` is parsed from `ScraperConfig.from_date`.
- Events before `from_date` are skipped.
- If the list appears newest-to-oldest, traversal stops when it reaches older rows after seeing eligible rows.
- If ordering is not confirmed, it logs warning `from_date_stop_disabled:list_not_newest_to_oldest` and does not rely on early stop.

### Incremental scrape/recheck decision

For result runs, `_traverse_event_list()` calls `storage.should_recheck_event()` for each list item.

Events are scraped when:

- `--force-full-scan` is used.
- The event matches `--force-recheck-event`.
- The event is not in `events_index.json`.
- The event is in `incomplete_events.json` and `next_recheck_after` is due.
- The event index record has `needs_recheck`.
- The event date is inside `--recheck-days`.
- The event is among the newest `--recheck-known-limit` known events.
- The existing state is not one of `complete`, `ignored`, or `no_relevant_pro_mma_bouts`.

Events are skipped when:

- They are known complete and outside recheck scope.
- They are queued incomplete but not due yet.

Traversal stops after `--stop-after-known-complete` consecutive known-complete skips. Incomplete-not-due skips do not count toward this stop condition.

### Event page extraction

`TapologyScraper._scrape_event_page()` opens each event URL and extracts:

- `source_event_id` from URL via `utils.source_event_id_from_url()`
- `event_url`
- `event_name` from first visible `h1`/`h2`, fallback list item
- `promotion_name` from promotion link or list item
- `promotion_url`
- `event_date`
- upcoming-only `event_time_raw` and `timezone_raw`
- `location`, `venue`, `enclosure`
- `city`, `country`, `region` currently set as `None`/list region and pruned if none
- `warnings`
- `bouts`

Fight card lookup uses `TapologyScraper._find_fight_card()` with `PREFERRED_FIGHT_CARD_SELECTORS`:

- `#sectionFightCard`
- `[id*='FightCard']`
- `section:has-text('Fight Card')`
- `div:has-text('Fight Card')`
- `table:has(a[href*='/fightcenter/bouts/'])`
- `ul:has(a[href*='/fightcenter/bouts/'])`

If no card is found, the event gets `fight_card_missing` and no bouts.

### Bout extraction

`TapologyScraper._parse_bouts()` evaluates containers under the fight card by:

- First finding links matching `a[href*='/fightcenter/bouts/']`.
- Expanding to nearest row/list/article/bout/fight container.
- Falling back to any `tr`, `li`, `article`, or `div` with at least two fighter links matching `a[href*='/fightcenter/fighters/']`.

Rows are skipped when:

- `professional_status(raw_text)` returns `amateur`.
- `is_explicit_non_mma_bout(raw_text)` detects boxing/kickboxing/grappling/Muay Thai and no MMA token.
- Professional status is `unknown`.
- Result mode and `has_completed_result(raw_text)` is false.

Skip counts are added as event warnings:

- `bouts_skipped_no_result:{n}`
- `bouts_skipped_amateur:{n}`
- `bouts_skipped_unknown_professional_status:{n}`
- `bouts_skipped_non_mma:{n}`

### Professional vs amateur distinction

The active filter is text based:

- `normalize.professional_status()` returns `amateur` if row text contains `amateur` or `amat`.
- It returns `pro` if row text contains `pro` or `professional`.
- Otherwise it returns `unknown`.

Unknown professional status is skipped, not imported as a pro ranking candidate.

### Bout normalization

`TapologyScraper._normalize_bout_row()` creates bout records with:

- `source_bout_id` from Tapology bout URL, or a fallback deterministic hash based on event URL/date, sorted fighter names, weight class, and method.
- `bout_url`
- `status`: `completed` for results, `upcoming` for upcoming
- `pro_am`: always `professional` for retained rows
- `card_position`
- `weight_class`
- `weight_lbs`
- `title_fight`
- `winner`/`loser` as role strings `fighter_a` or `fighter_b`
- `result_type`
- `fighter_a` and `fighter_b` objects with name, Tapology URL, prefight record
- `result` object with normalized method, round, time
- `warnings`

If a bout URL exists, `_scrape_matchup_page()` opens it and `_parse_matchup_page_details()` attempts to improve prefight records, method details, card position, weight, and title-fight data. `_apply_matchup_details()` merges those details back into the row-level bout.

### Incomplete event handling

`storage._event_recheck_status()` determines event state after a successful run:

- No bouts and recent: `pending_results`, needs recheck, reason `no_completed_professional_mma_bouts`, high priority.
- No bouts and old: `no_relevant_pro_mma_bouts`, no recheck.
- Material event warnings such as skipped no-result rows: `partial`, needs recheck, reason `missing_or_partial_results`.
- Parser warnings or missing critical bout values: `parser_attention`, needs recheck.
- Recent complete events: `complete_recent_recheck`, needs recheck, reason `recent_update_window`.
- Old clean events: `complete`, no recheck.

Current latest state report shows all 62 known latest events are in `incomplete_events.json` because the seed run covered May 1-10, 2026 and is still inside the default 14-day recheck window or has data issues.

### Duplicate avoidance across runs

There are two layers:

1. Per-page/run dedupe:
   - `_traverse_event_list()` dedupes event URLs already seen in the current traversal.
   - `_parse_bouts()` dedupes row keys by source bout ID or URL hash.
   - `dedupe_events()` dedupes final event and bout lists by source ID/URL before commit.

2. Persistent state:
   - `events_index.json` keyed by `source_event_id`.
   - `bouts_index.json` keyed by `source_bout_id`.
   - Existing source IDs reuse their original `internal_event_id`/`internal_bout_id`.
   - `normalized_fingerprint()` detects updated normalized content while ignoring timestamps/run/internal IDs.

## Deduplication and State

### Already known events

Known events are records in `data/state/events_index.json`, keyed by `source_event_id`.

On commit, `_apply_results_to_state()`:

- Reuses `internal_event_id` if the event already exists.
- Assigns `mf_event_000001`-style IDs only for new source events.
- Compares `last_normalized_event_fingerprint` to detect new/updated/unchanged.
- Updates known bout IDs, scrape timestamps, recheck state, and warning codes.

### Already known bouts

Known bouts are records in `data/state/bouts_index.json`, keyed by `source_bout_id`.

On commit:

- Reuses existing `internal_bout_id`.
- Assigns `mf_bout_000001`-style IDs for new source bouts.
- Compares `normalized_fingerprint` to detect new/updated/unchanged.

### Incomplete events and recheck queue

Queued events are stored in `data/state/incomplete_events.json`, keyed by `source_event_id`.

Queue records include:

- internal/source event IDs
- event URL/name/date
- `first_marked_incomplete_at`
- `last_rechecked_at`
- `recheck_count`
- `recheck_reason`
- `next_recheck_after`
- `priority`

High priority events are rechecked after 1 day. Normal priority events are rechecked after 3 days.

### Latest dataset

`data/latest/` is a convenience mirror of the newest successful run's:

- `results.json`
- `changes.json`
- `run_manifest.json`

`state-report` additionally writes `data/latest/state_report.json`.

Importer guidance: `latest/` is useful as a moving import feed, but the plugin should record the source `run_id` it imported so imports are auditable and repeatable.

### Immutable run artifacts

Every run writes under `data/runs/{run_id}/`. Successful current runs write:

- `results.json`
- `changes.json`
- `run_manifest.json`
- `upcoming.json` if upcoming scraping was requested
- `debug.json` only if `--debug`

Failed runs can write:

- `partial_results.json`
- `run_manifest.json` with `status: failed`
- `debug.json` if debug was active
- raw debug files in `raw_debug/` for some hard-block/fight-card failures

### Atomic writes

`storage.atomic_write_json()` writes to a dot-prefixed temp file in the same directory and then replaces the target path.

`DataStore._commit_state_files()` writes state files to a temporary `.pending_*` directory, then replaces state files. Tests cover simulated state write failure preserving previous state.

### Content hash vs identity hash

Current active code has:

- Source identity IDs:
  - `tapology_event_{numeric_id}` from event URLs.
  - `tapology_bout_{numeric_id}` from bout URLs.
  - `tapology_bout_fallback_{hash}` when a bout URL/source ID is missing.
  - `tapology_fighter_{numeric_id}` from fighter URLs when available.
  - `tapology_promotion_{numeric_id}` from promotion URLs when available.
- Explicit numeric source ID fields in `results.json`:
  - event `source_event_numeric_id`
  - bout `source_bout_numeric_id`
  - fighter `source_fighter_numeric_id`
  - event promotion `source_promotion_numeric_id`
- URL hash helpers:
  - `utils.url_hash()`, used for per-row dedupe and debug naming.
  - Validation bans raw/debug hash fields other than the v0.5 output `identity_hash` and `content_hash`.
- Content fingerprints:
  - `storage.normalized_fingerprint()` for events and bouts.
  - Event fingerprint stored as `last_normalized_event_fingerprint`.
  - Bout fingerprint stored as `normalized_fingerprint`.

Normal v0.5 `results.json` exposes event and bout `identity_hash` and `content_hash`. These are deterministic SHA-256 hashes over stable JSON payloads. Identity hashes use source/entity identity inputs and exclude run IDs, timestamps, `scraped_at`, and internal IDs. Content hashes use material normalized source content, explicit bout flags, and warnings, also excluding volatile and internal fields. State index fingerprints remain operational scraper fingerprints, not the primary import contract.

## Current JSON Artifact Inventory

See `SCRAPER_DATA_CONTRACT.md` for the field-by-field contract.

Current JSON file types:

- `data/latest/results.json`
- `data/runs/{run_id}/results.json`
- `data/runs/{run_id}/upcoming.json`
- `data/latest/changes.json`
- `data/runs/{run_id}/changes.json`
- `data/latest/run_manifest.json`
- `data/runs/{run_id}/run_manifest.json`
- `data/latest/state_report.json`
- `data/state/scraper_state.json`
- `data/state/events_index.json`
- `data/state/bouts_index.json`
- `data/state/incomplete_events.json`
- `data/runs/{run_id}/debug.json`
- `data/runs/{run_id}/partial_results.json`
- `data/derived/fighters_index.json`
- `data/derived/fighter_fight_logs.json`
- `data/derived/processor_report.json`

## Known Limitations and Risks

- Tapology selectors are fragile. The scraper depends on `.fightcenterEvents`, `#fightCenterRefine`, fight card selectors, and URL path patterns.
- Filter selection is heuristic. It searches labels/names/ids/nearby text and option aliases, not a stable API.
- Professional/amateur detection is raw text based. Unknown status is skipped, which avoids bad imports but can miss real pro bouts.
- Non-MMA detection is raw text based and only checks a small set of sports.
- Fighter source IDs are now explicit in `results.json` as nullable `fighter_a.source_fighter_id` and `fighter_b.source_fighter_id`, derived from Tapology fighter URLs when present. Missing/unparseable fighter URLs still produce `null`, so canonical identity resolution remains a plugin responsibility.
- Final fighter identity is not resolved. Same fighter identity across renamed URLs, missing URLs, aliases, or duplicate Tapology profiles is not solved.
- Prefight records are parsed from row text or matchup page text. Missing records are represented, but reliability depends on Tapology page text order.
- Method parsing is regex based and can be ambiguous. `N/A`, blank, and unrecognized methods become `method_category: "unknown"` and warning `unknown_method`.
- Result winner/loser inference is text based. Draw/no-contest handling exists, but overturned/cancelled nuance is limited.
- Bout `flags` are explicit in `results.json` and include completed/pro/amateur/cancelled/overturned/upcoming/scoring-candidate booleans. Current normal result output remains completed professional rows; amateur, non-MMA, and unfinished result rows are still skipped.
- Event `city`, `country`, and sometimes `region` are not populated in current event output.
- Result rows without completed result are skipped and counted, not included as cancelled/upcoming/no-result bouts.
- Normal output intentionally excludes raw text, URL hashes, and debug fields.
- State `raw_event_states` can be less expressive than semantic state report output. Example current `events_index.json` has many raw `partial` states because recent recheck events use queue reason `recent_update_window`; `state_report.py` maps these into semantic `complete_recent_recheck` where appropriate.
- Upcoming scraping exists, but latest mirroring and validation are centered on results. The plugin import feed should treat upcoming as not ready until its contract is separately stabilized.
- The scraper can hit Cloudflare/security hard blocks in headless mode. The code detects these and fails rather than bypassing them.

## Plugin Import Readiness

### Ready for plugin import

These fields are stable enough to consume as source-adapter facts, assuming the plugin treats them as Tapology source data and stores import run provenance:

- Document: `schema_version`, `source`, `source_url`, `scraped_at`, `run.run_id`.
- Event source identity: `source_event_id`, `source_event_numeric_id`, `identity_hash`, `content_hash`, `event_url`, `event_name`, `event_date`.
- Promotion source fields: `promotion_name`, `promotion_url`, `source_promotion_id`, `source_promotion_numeric_id`.
- Event location text: `location`, `venue`, `enclosure` when present.
- Bout source identity when URL exists: `source_bout_id`, `source_bout_numeric_id`, `identity_hash`, `content_hash`, `bout_url`.
- Parent links: bout `source_event_id`, `internal_event_id`/`internal_bout_id` for scraper provenance.
- Fighter source URLs, source IDs, numeric source IDs, and display names: `fighter_a.name`, `fighter_a.url`, `fighter_a.source_fighter_id`, `fighter_a.source_fighter_numeric_id`, `fighter_b.name`, `fighter_b.url`, `fighter_b.source_fighter_id`, `fighter_b.source_fighter_numeric_id`.
- Bout status/pro-am for retained result rows: `status: completed`, `pro_am: professional`.
- Bout explicit flags: `flags.is_completed`, `flags.is_professional`, `flags.is_amateur`, `flags.is_cancelled`, `flags.is_overturned`, `flags.is_upcoming`, and `flags.is_scoring_candidate`.
- Result role fields when `result_type: win_loss`: `winner`, `loser`.
- Draw/no-contest indicator: `result_type`.
- Method category values from current enum: `ko_tko`, `submission`, `decision`, `dq`, `no_contest`, `draw`, `unknown`.
- Round/time when present.
- Prefight record object shape, including explicit missing state.
- Warnings arrays as parser/import caution signals.

### Needs normalization/mapping

These fields exist but should be mapped before becoming canonical plugin data:

- `internal_event_id` and `internal_bout_id`: stable within scraper state, useful as source-import IDs, but not canonical WordPress entity IDs.
- `event_name`: source display text; not a canonical event identity by itself.
- `promotion_name`: source display text; must map to canonical organization/promotion records.
- `location`, `venue`, `enclosure`: raw text fields; need location parsing and normalization.
- `weight_class`: source text and sometimes uppercase; map to canonical weight divisions.
- `weight_lbs`: useful but should be validated against canonical division/rules.
- `card_position`: enum-like scraper values, but needs canonical mapping.
- `title_fight`: optional source-derived object; should map to canonical title/fight metadata only after review.
- `method_detail`: source-derived detail text; needs method taxonomy mapping.
- `winner`/`loser`: role references only, not fighter IDs.
- Fighter names and URLs: need canonical identity resolution/review.
- Prefight records: import as source-provided prefight snapshot, but do not treat as final career truth without source confidence and review.
- `warnings`: should become import review flags, not canonical fight data.
- `changes.json` new/updated/unchanged arrays: useful for import scheduling, but IDs are scraper-internal.

### Missing / required before import

The future Data Engine will need these before fully safe canonical import:

- Raw source slug fields, if the importer needs more than numeric IDs and canonical URLs.
- Explicit bout source anchor/row position when no bout URL exists, beyond the current `fallback_source_bout_id` warning.
- Explicit source raw result token, if safe to expose, or a normalized result evidence field.
- Clear distinction between source method raw, normalized method category, and canonical method mapping.
- Event timezone and local start time for results, if Tapology reliably exposes them.
- Parsed city/country/region.
- Parser confidence/review severity beyond free-form warnings.
- Contract fixtures and a JSON Schema or equivalent validator for plugin import.

## Recommended Next Scraper Improvements

Only scraper improvements that help a future WordPress Data Engine import safely:

1. Preserve a safe `method_raw` or `source_result_raw` field if it can be kept concise and not treated as debug/raw page dump.
2. Add JSON Schema files or Pydantic/dataclass contract tests for `results.json`, `changes.json`, and state files.
3. Add fixed fixture JSON output tests for plugin import contract stability.
4. Add parser fixtures from saved Tapology HTML for event list, event page, and matchup page parsing.
5. Make fallback bout IDs more auditable by storing fallback components or a separate fallback reason.
6. Add warning codes with severity and field path, instead of only strings.
7. Stabilize upcoming output separately before any plugin consumes upcoming events.
8. Add a dry-run fixture command that reads saved HTML and writes deterministic JSON without live Tapology access.

Do not add ranking calculations, WordPress database writes, REST endpoints, or canonical identity resolution to the scraper.

## Tests

Command run:

```powershell
if (Test-Path .\.venv\Scripts\python.exe) { & .\.venv\Scripts\python.exe -m pytest } else { python -m pytest }
```

Result:

```text
87 passed in 1.05s
```

Current test coverage:

- `tests/test_dedupe.py`: current/legacy event and bout dedupe helpers, new-flag helpers, older success state accumulation.
- `tests/test_fighters.py`: derived fighter aggregation, win/loss/draw/no-contest role mapping, finish counts, opponent prefight differential, duplicate fight-log suppression, missing method/prefight reports, derived validation.
- `tests/test_ids.py`: Tapology event/bout source ID extraction, canonical URL hash stability, fallback bout hash order independence.
- `tests/test_normalize.py`: prefight record parsing, method normalization and details, completed-result detection, professional/amateur detection, non-MMA detection.
- `tests/test_records.py`: date parsing.
- `tests/test_state_report.py`: state report from fixture files, missing optional latest files, duplicate IDs, orphan bouts, writing report JSON, read-only state behavior.
- `tests/test_storage_state.py`: successful commit artifacts/latest/state, ID reuse, update detection, incomplete/recent/no-relevant event states, recheck decision logic, forced recheck by ID/URL, failed run behavior, simulated state write failure, duplicate internal ID validation.
- `tests/test_tapology_parsing.py`: matchup prefight record parsing, avoiding event start time as bout result time, card/title parsing, from-date inclusive boundary.
- `tests/test_validation.py`: lean payload validation, rejecting debug/raw/hash fields and empty warning arrays.

Important untested behavior before plugin import:

- Live DOM filter application against current Tapology markup.
- Event list pagination/load-more against saved HTML fixtures.
- Event page fight-card selector fallbacks against real saved pages.
- Row-level fighter extraction with missing fighter links or duplicate links.
- Fallback source bout ID stability across realistic no-bout-link rows.
- Cancelled, overturned, no-contest, draw, and `N/A` method examples from real HTML.
- Unknown professional status examples that should or should not be skipped.
- Upcoming output contract.
- JSON contract fixtures that plugin import can pin to.
- Source fighter ID/promotion ID extraction in output.

## Final Summary

What we already have:

- A standalone, reasonably well-structured Tapology source adapter.
- Stable scraper-level event and bout source IDs when Tapology URLs expose numeric IDs.
- Persistent state indexes, internal scraper IDs, incremental recheck logic, immutable run artifacts, and atomic state writes.
- A lean normalized `results.json` output with events, completed professional bouts, fighter names/URLs, prefight records, result roles, method category/details, round/time, and warnings.
- Good unit coverage for normalization, state commits, recheck decisions, validation, and derived fighter aggregation.

What is safe to rely on:

- `schema_version`, `source`, `run_id`, `scraped_at`.
- Event/bout `identity_hash` and `content_hash` for source-entity matching and content-diff detection.
- `source_event_id` and `event_url` for Tapology event identity.
- `source_bout_id` and `bout_url` when the Tapology bout URL exists.
- Scraper internal IDs as scraper provenance, not canonical WordPress IDs.
- Warnings and incomplete queue as signals for review/recheck.

What is not safe to rely on yet:

- Final fighter identity.
- Canonical promotion/location/weight/method mapping.
- Complete prefight record accuracy without review.
- Fallback bout identity without extra audit fields.
- Direct canonical import of all fields without a review/mapping layer.

Next development step:

Start the WordPress Data Engine plugin skeleton as a source adapter consumer. Remaining scraper work should stay focused on evidence fields, fixture/schema coverage, parser fixtures, fallback ID auditability, warning structure, and separately stabilizing upcoming output.
