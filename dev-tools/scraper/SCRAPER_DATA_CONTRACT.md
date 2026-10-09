# MMA Future Scraper Data Contract

Audit date: 2026-05-13
Primary import feed: `scraper/data/latest/results.json` copied from `scraper/data/runs/{run_id}/results.json` after a successful run.

This document describes the current JSON outputs. It is descriptive, not a guarantee that fields are canonical MMA Future fields. The scraper is a Tapology source adapter; the future WordPress Data Engine should perform canonical identity resolution, mapping, review, imports, stats rebuilds, and ranking calculations.

## Contract Summary

Primary result schema:

- `schema_version`: `scraper_results_v0_5`
- `source`: `tapology`
- `source_url`: `https://www.tapology.com/fightcenter`

Current latest dataset at audit time:

- Latest run: `20260513T011055Z`
- Latest `results.json`: 62 events, 291 retained professional completed bouts.
- State: 62 known events, 291 known bouts.
- Incomplete/recheck queue: 62 events due to recent update window or data/parser issues.
- Latest `results.json` and the matching run artifact were backfilled from v0.4 to v0.5 with `.\.venv\Scripts\python.exe scripts\backfill_results_v0_5.py`; the script wrote timestamped backups under `data/backups/`.

## Stability Labels

Field readiness labels used below:

- Ready: stable enough for plugin source import as scraper/Tapology provenance.
- Map: exists but must be normalized or mapped before canonical use.
- Review: exists but should trigger import review or low-confidence handling.
- Missing: not currently present but needed for safer import.
- Internal: scraper operational state, not canonical plugin data.
- Debug only: not for importer use.

## Normal Results Document

Path patterns:

```text
data/latest/results.json
data/runs/{run_id}/results.json
data/runs/{run_id}/partial_results.json
```

Purpose:

- `results.json` stores normalized Tapology regional MMA result event data from a run.
- `partial_results.json` can be written for failed runs and must not be treated as a successful import feed.

Top-level fields:

| Field | Type | Required | Nullable | Stability | Raw/normalized | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `schema_version` | string | yes | no | Ready | normalized | Current value `scraper_results_v0_5`. |
| `source` | string | yes | no | Ready | normalized | Current value `tapology`. |
| `source_url` | string URL | yes | no | Ready | raw/source | FightCenter URL. |
| `scraped_at` | ISO datetime string | yes | no | Ready | normalized | Set to run finish time on successful commit. |
| `filters` | object | yes | no | Ready | normalized | Declares intended source filters. |
| `run` | object | yes | no | Ready/Internal | normalized | Run provenance and counts. |
| `events` | array<object> | yes | no | Ready | normalized/source mix | Event objects. |

### `filters`

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `promotion` | string | Ready | Current value `regional`. |
| `schedule` | string | Ready | Current value `results` for result document. |
| `sport` | string | Ready | Current value `mma`. |
| `region` | string | Ready | Current value `all`. |

These are intended filter values, not proof that Tapology returned only those rows.

### `run`

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `run_id` | string | Ready | UTC timestamp format such as `20260513T011055Z`. |
| `started_at` | ISO datetime string | Ready | Start timestamp. |
| `finished_at` | ISO datetime string or null | Ready | Set on successful commit. |
| `events_seen` | integer | Internal | After commit equals event count in document. |
| `events_written` | integer | Internal | Current code sets to event count. |
| `events_known` | integer | Internal | Count of events already known before current run. |
| `skipped_events_no_usable_bouts` | integer | Review/Internal | Backward-compatible count of result events skipped because no retained professional completed MMA bout was available. |
| `skipped_events_non_mma_or_no_results` | integer | Review/Internal | Backward-compatible umbrella count for skipped result events whose warnings indicate non-MMA, no-result, all-amateur, or otherwise no usable pro MMA content. |
| `events_skipped_non_mma` | integer | Review/Internal | Additive clearer count for skipped non-MMA events. |
| `events_skipped_no_results` | integer | Review/Internal | Additive clearer count for skipped MMA events where source rows had no completed result yet. |
| `events_skipped_all_amateur` | integer | Review/Internal | Additive clearer count for skipped MMA events where observed MMA rows were amateur only. |
| `events_skipped_no_usable_pro_mma_bouts` | integer | Review/Internal | Additive clearer count for skipped MMA events with source rows but no retained professional completed MMA bout. This can overlap with `events_skipped_all_amateur`. |
| `skipped_event_examples` | array<object> | Review/Internal | Up to 10 skipped event examples with source IDs/URLs, warnings, and when available a `skip_category`/`reason` such as `non_mma`, `no_results`, `all_amateur`, or `no_usable_pro_mma_bouts`. |
| `bouts_seen` | integer | Internal | Backward-compatible alias for source bout rows seen: retained/written bouts plus structured skipped source rows. |
| `bouts_source_rows_seen` | integer | Internal | Explicit source bout rows seen: `bouts_written + bouts_skipped_amateur + bouts_skipped_non_mma + bouts_skipped_no_result + bouts_skipped_unknown_professional_status` when all skipped rows are represented by those warning counters; equivalently retained `bouts` plus `skipped_bouts`. |
| `bouts_written` | integer | Internal | Retained/written bout count; equals retained `bouts` length, not source rows seen. |
| `bouts_skipped_amateur` | integer | Review | Count of skipped amateur source rows. |
| `bouts_skipped_non_mma` | integer | Review | Count of skipped non-MMA source rows. |
| `bouts_skipped_no_result` | integer | Review | Count of skipped source rows where no completed result was available. |
| `bouts_skipped_unknown_professional_status` | integer | Review | Count of skipped source rows where professional/amateur status was not explicit. |
| `unmatched_nationality_raw_values` | array<object> | Review/Internal | Diagnostic-only frequencies for unrecognized Tapology profile-header flag labels. Each entry is `{ "raw": string, "count": integer }`. |
| `rejected_nationality_candidates` | array<object> | Review/Internal | Compact diagnostic-only frequencies for nationality-like values rejected before country normalization. Each entry is `{ "raw": string, "count": integer, "reason": string, "source_kind": string, "element_context": string }`. |
| `rejected_nationality_candidate_counts` | integer | Review/Internal | Total rejected nationality candidate observations. |
| `rejected_nationality_candidate_reasons` | object | Review/Internal | Count by rejection reason, such as `fighter_name_like`, `promotion_name_like`, `team_or_affiliation_like`, `low_confidence_two_letter_token`, `non_flag_context`, or `empty_or_missing_flag_metadata`. |
| `nationality_extraction_source_counts` | object | Review/Internal | Count by nationality extraction source kind and accepted source kind for scraper QA. |
| `unmatched_location_country_candidates` | array<object> | Review/Internal | Diagnostic-only frequencies for event `location` last components that looked like explicit country candidates but were not normalized. |
| `unmatched_born_country_candidates` | array<object> | Review/Internal | Diagnostic-only frequencies for `born_raw` last components that looked like explicit country candidates but were not normalized. |
| `unmatched_fighting_out_country_candidates` | array<object> | Review/Internal | Diagnostic-only frequencies for `fighting_out_of_raw` last components that looked like explicit country candidates but were not normalized. |
| `warnings_count` | integer | Review | Total run warning entries used for manifest consistency. Includes warnings from retained events plus skipped/failed event warning entries tracked in the run summary. |
| `retained_event_warnings_count` | integer | Review | Warning entries attached to retained events and retained bouts in this document. |
| `skipped_event_warnings_count` | integer | Review | Run warning entries that are not attached to retained events/bouts, usually skipped event or failed event warnings. |
| `errors_count` | integer | Review | Current successful result docs use `0`. |

## Event Object

Path:

```text
results.events[]
```

Purpose:

- A Tapology event source record with retained professional MMA bouts.

Fields:

| Field | Type | Required | Nullable/optional | Stability | Raw/normalized | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `internal_event_id` | string | yes after commit | no | Internal | normalized | `mf_event_000001`; stable within scraper state. |
| `source_event_id` | string | yes | no | Ready | normalized source ID | From Tapology event URL, e.g. `tapology_event_141836`. |
| `source_event_numeric_id` | string/null | yes | yes | Ready | normalized source ID | Numeric component of `source_event_id`, e.g. `"141836"`; null if unparseable. |
| `event_url` | string URL | yes | no | Ready | raw/source | Canonical Tapology event URL. |
| `event_name` | string | yes | no | Map | source text | From event page heading or list text. |
| `promotion_name` | string | usually | optional | Map | source text | From promotion link or event list text. |
| `promotion_url` | string URL | no | optional | Ready/Map | raw/source | Useful for deriving source promotion ID. |
| `source_promotion_id` | string/null | yes | yes | Ready | normalized source ID | From promotion URL, e.g. `tapology_promotion_2729`; null only with `missing_source_promotion_id`. |
| `source_promotion_numeric_id` | string/null | yes | yes | Ready | normalized source ID | Numeric component of `source_promotion_id`, e.g. `"2729"`; null only with `missing_source_promotion_id`. |
| `event_date` | `YYYY-MM-DD` string | usually | optional | Ready | normalized | Parsed from source text. |
| `event_time_raw` | string | upcoming only | optional | Map | source text | Not present in current result examples. |
| `timezone_raw` | string | upcoming only | optional | Map | source text | Not present in current result examples. |
| `location` | string | no | optional | Map | source text | Raw location text such as `Naples, Italy`. |
| `venue` | string | no | optional | Map | source text | Raw venue text. |
| `enclosure` | string | no | optional | Map | source text | Normalized to `Cage`, `Ring`, or null. Adjacent metadata such as ring announcers, TV announcers, matchmaker, and broadcast labels is not part of this field. |
| `city` | string/null | no | nullable | Ready/Map | normalized from `location` | First location component only when parseable. `Tokyo, Japan` => `Tokyo`; country-only values leave this null. |
| `country` | string/null | no | nullable | Ready/Map | normalized from `location` | Last component only when it is explicitly recognized by the centralized scraper country normalizer. The normalizer covers ISO 3166-1 countries plus common territories and Tapology-style labels such as `Guam`, `Hong Kong`, and the UK home nations. Current project convention keeps `England`, `Scotland`, `Wales`, and `Northern Ireland` distinct when those exact labels appear, while `UK` / `United Kingdom` normalize to `United Kingdom`. No country is inferred from region/state/prefecture names. |
| `region` | string/null | no | nullable | Ready/Map | normalized from `location` | Middle component for safe three-part values; null for `Tokyo, Japan` and `Naples, Italy`; for `Yoichi, Hokkaido`, country remains null. |
| `warnings` | array<string> | no | omitted when empty | Review | normalized warning codes | Examples below. |
| `bouts` | array<object> | yes | no | Ready | normalized/source mix | Retained bout records. |
| `source_event_status` | string | yes | no | Ready | normalized | One of `completed`, `in_progress`, `upcoming`, `cancelled`, `unknown`. Results-mode cancellation is derived from event-level/header/status markers only; cancelled bout text elsewhere on the page is not an event cancellation signal. |
| `source_bouts_total` | integer/null | no | nullable | Ready | source text | Backward-compatible Tapology MMA bout total parsed from labels such as `MMA Bouts: 7`; this is not an all-source-row total. |
| `source_mma_bouts_total` | integer/null | no | nullable | Ready | source text | Explicit alias for the Tapology MMA bout total. New consumers should prefer this over `source_bouts_total`. |
| `source_mma_bouts_total_mismatch` | object/null | no | nullable | Diagnostic | parser accounting | Present only when the existing `source_mma_bouts_total_mismatch:{source_total}:{mma_rows_seen}` warning is emitted. Includes source total, rows seen, retained bout count, skipped reasons, and non-MMA/amateur involvement flags. |
| `source_rows_seen` | integer | no | no | Ready | normalized | Retained bouts plus all structured skipped source rows, including non-MMA rows. |
| `source_mma_rows_seen` | integer | no | no | Ready | normalized | Retained professional MMA bouts plus skipped MMA rows. Non-MMA skipped rows are excluded. |
| `bouts_included` | integer | yes | no | Ready | normalized | Count of retained `bouts`. |
| `bouts_skipped_total` | integer | yes | no | Ready | normalized | Count of structured `skipped_bouts`. |
| `bouts_skipped_no_result` | integer | yes | no | Ready | normalized | Count of skipped rows with no completed result. |
| `needs_recheck` | boolean | yes | no | Ready | normalized | True for in-progress/upcoming events, unresolved no-result skipped rows, known incomplete source data, or material parser warnings. Amateur and non-MMA skipped rows alone do not make this true. |
| `skipped_bouts` | array<object> | yes | no | Ready | normalized/source mix | Machine-readable skipped rows; warning strings remain for compatibility. |
| `event_datetime_raw` | string/null | no | nullable | Ready | source text | Full source date/time text when available. |
| `event_time_raw` | string/null | no | nullable | Ready | source text | Source event start time when available. |
| `event_timezone_raw` | string/null | no | nullable | Ready | source text | Source timezone token when available. `timezone_raw` remains for compatibility. |
| `fighter_profiles` | object | yes | no | Ready | normalized/source mix | Event-level profile snapshots keyed by `source_fighter_id` when profile enrichment is enabled; default `{}`. |
| `identity_hash` | string | yes | no | Ready | normalized | Stable SHA-256 identity hash for the source event entity. |
| `content_hash` | string | yes | no | Ready | normalized | Stable SHA-256 content hash for material event data. |

Event warning examples:

- `fight_card_missing`
- `bout_failed:{exception}`
- `bouts_skipped_no_result:{count}`
- `bouts_skipped_amateur:{count}`
- `bouts_skipped_unknown_professional_status:{count}`
- `bouts_skipped_non_mma:{count}`
- `source_mma_bouts_total_mismatch:{source_total}:{mma_rows_seen}`
- `missing_source_promotion_id`

Fighter profile enrichment notes:

- `fighter_profiles` is event-level source metadata keyed by `source_fighter_id`; profile current records stay under `fighter_profiles[*].pro_mma_record` / `pro_mma_record_raw` and must not overwrite bout `fighter_a.prefight_record` or `fighter_b.prefight_record`, which remain card/matchup-derived.
- `fighter_profiles[*].nationality` is a stable object derived only from high-confidence Tapology profile-header flag/country metadata: `raw`, `country_name`, `country_code`, `source`, and `is_missing`. `raw` preserves the extracted flag metadata value exactly as seen in accepted header flag metadata; `source` is `profile_header_flag`. When the flag label is confidently recognized by the centralized country normalizer, `country_name` and `country_code` are populated with the scraper's canonical display values such as `United States` / `USA`, `Brazil` / `BRA`, `Japan` / `JPN`, `South Korea` / `KOR`, or `England` / `ENG`. When Tapology omits high-confidence flag metadata, or when a candidate is rejected as low-confidence / non-flag / fighter / promotion / team text, the object remains present as `{"raw": null, "country_name": null, "country_code": null, "source": "profile_header_flag", "is_missing": true}`. Rejected raw values are stored only in compact run/event diagnostics, not in accepted nationality output. When a high-confidence flag label is present but not recognized, `raw` may remain non-null while `country_name` / `country_code` stay null so diagnostics can surface the unmapped value. The scraper does not infer nationality from birthplace, fighting-out location, event location, region, city, prefecture, promotion, gym, affiliation, or fighter name, so nationality is not guaranteed for every fighter.
- Importers must treat `nationality` as optional. It is conservative and often missing; it must not be required for event or bout import, and it must not be inferred from `born_country` or `fighting_out_of_country`.
- `born_raw` preserves Tapology profile text when the source field is coherent. `fighting_out_of_raw` preserves Tapology profile text only when the extracted value remains a coherent location field; values contaminated by Tapology UI chrome, `N/A ...` leakage, or affiliation/team/gym bleed are nulled and accompanied by `fighting_out_parse_inconsistent`. Their `*_city`, `*_region`, and `*_country` fields use the same centralized country normalizer as event locations, but only against explicit country text already present in the raw location value. Country-only values such as `United States`, `Japan`, `Guam`, and `Hong Kong` produce null city/region and a country value. Two-part region values such as `Yoichi, Hokkaido` produce city/region and null country. No country is inferred from state, region, prefecture, gym, affiliation, or other page chrome unless the country appears explicitly in the raw text.
- `association` / team-like source values are accepted only when the extracted value remains coherent profile metadata. Values contaminated by Tapology page chrome, news, ad, navigation, or unrelated UI text are nulled and accompanied by `association_parse_inconsistent`.
- `born_country` and `fighting_out_of_country` are source metadata/review suggestions only. They are not canonical nationality, eligibility, public/rankable, or ranking inputs by themselves.
- Missing DOB or missing `age_at_event` must not block event or bout import. Later ranking/eligibility workflows may mark fighters or bouts as review/pending when those fields are needed.
- Run-level unmatched diagnostics are emitted as `unmatched_nationality_raw_values`, `unmatched_location_country_candidates`, `unmatched_born_country_candidates`, and `unmatched_fighting_out_country_candidates`. These are diagnostic only and do not change import behavior.
- `last_weigh_in_raw` is preserved only when the source value is a coherent weigh-in text such as `125.0 lbs`. Clearly invalid or contaminated values such as `PHOTOS : ...` or implausible values outside the accepted MMA range are nulled and accompanied by `last_weigh_in_parse_inconsistent`.
- `missing_or_unavailable:last_weigh_in_lbs` is the precise warning for a missing or unusable parsed weigh-in pounds value. `missing_or_unavailable:weight` remains as a temporary backward-compatible alias for downstream consumers that still expect the older warning name.
- `last_fight_raw`, `last_fight_date`, and `last_fight_promotion` are populated only when the parser can extract them from one coherent fight-history row/block. If a row appears to mix an event title/link from one DOM/text section with a date or opponent from another, profile enrichment remains successful where possible, questionable `last_fight_*` fields are null, and `profile_warnings` includes `last_fight_parse_inconsistent`.
- `profile_image_url` is intentionally emitted as `null`. Fighter images are selected manually in WordPress later; the scraper does not choose source images, download images, or build WordPress media import behavior.

Event identity:

- Primary source identity: `source_event_id`.
- Explicit numeric source identity: `source_event_numeric_id`.
- Promotion source identity: `source_promotion_id` / `source_promotion_numeric_id` when `promotion_url` has a parseable numeric ID.
- Source URL: `event_url`.
- Scraper internal identity: `internal_event_id`.
- `identity_hash` input includes hash type, source, `source_event_id`, `source_event_numeric_id`, canonical `event_url`, normalized `event_date`, and normalized `event_name`.
- `content_hash` input includes hash type, normalized event name/date, source promotion ID, promotion name/URL, raw location plus normalized city/region/country, venue, enclosure, event completeness fields, retained/skipped bout source IDs, stable fighter profile content, and material event warnings. It excludes run IDs, timestamps, internal IDs, `scraped_at`, profile fetch timestamps, and transient fetch metadata.
- State still stores an operational event fingerprint as `events_index[*].last_normalized_event_fingerprint`; importer code should prefer result-level `identity_hash`/`content_hash` for the v0.5 import contract.

Example event:

```json
{
  "source_event_id": "tapology_event_141836",
  "source_event_numeric_id": "141836",
  "event_url": "https://www.tapology.com/fightcenter/events/141836-rookie-fc-9",
  "event_name": "Rookie FC 9",
  "promotion_name": "Rookie Fighting Championship",
  "promotion_url": "https://www.tapology.com/fightcenter/promotions/2729-rookie-fighting-championship-rfc",
  "source_promotion_id": "tapology_promotion_2729",
  "source_promotion_numeric_id": "2729",
  "event_date": "2026-05-10",
  "location": "Naples, Italy",
  "city": "Naples",
  "region": null,
  "country": "Italy",
  "source_event_status": "completed",
  "source_bouts_total": 0,
  "bouts_included": 0,
  "bouts_skipped_total": 7,
  "bouts_skipped_no_result": 0,
  "needs_recheck": false,
  "skipped_bouts": [],
  "event_datetime_raw": null,
  "event_time_raw": null,
  "event_timezone_raw": null,
  "venue": "Palasirio",
  "warnings": ["bouts_skipped_amateur:7"],
  "bouts": [],
  "fighter_profiles": {},
  "identity_hash": "sha256-hex-string",
  "content_hash": "sha256-hex-string",
  "internal_event_id": "mf_event_000001"
}
```

## Bout Object

Path:

```text
results.events[].bouts[]
```

Purpose:

- A retained professional MMA bout record from an event page, optionally enriched by matchup page parsing.

Fields:

| Field | Type | Required | Nullable/optional | Stability | Raw/normalized | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `internal_bout_id` | string | yes after commit | no | Internal | normalized | `mf_bout_000001`; stable within scraper state. |
| `internal_event_id` | string | yes after commit | no | Internal | normalized | Parent scraper event ID. |
| `source_event_id` | string | yes after commit | no | Ready | normalized source ID | Parent Tapology event ID. |
| `source_bout_id` | string | yes | no | Ready/Review | normalized source ID | `tapology_bout_{id}` or fallback hash ID. |
| `source_bout_numeric_id` | string/null | yes | yes | Ready | normalized source ID | Numeric component of URL-derived `source_bout_id`; null for fallback IDs or unparseable URLs. |
| `bout_url` | string URL | yes by validator | no | Ready | raw/source | Tapology bout URL. If missing, validation fails for normal latest output. |
| `status` | string | yes | no | Ready/Map | normalized | `completed` for results, `upcoming` for upcoming. |
| `pro_am` | string | yes | no | Ready/Map | normalized | Current retained rows use `professional`. |
| `card_position` | string | yes | no | Map | normalized | `main_event`, `co_main_event`, `main_card`, `prelim`, `postlim`, `unknown`. |
| `weight_class` | string | no | optional | Map | source/normalized text | Often uppercase source text from matchup page. |
| `weight_lbs` | integer | no | optional | Map | normalized | Parsed pounds. |
| `title_fight` | object | no | optional | Map/Review | source/normalized | Title metadata if detected. |
| `winner` | string/null | key required | nullable | Ready/Map | normalized role | `fighter_a`, `fighter_b`, or null. |
| `loser` | string/null | no | nullable | Ready/Map | normalized role | `fighter_a`, `fighter_b`, or null. |
| `result_type` | string | yes | no | Ready/Map | normalized | `win_loss`, `draw`, `no_contest`, `cancelled`, `unknown`. |
| `fighter_a` | object | yes | no | Ready/Map | source/normalized mix | First fighter link found. Includes nullable source fighter ID. |
| `fighter_b` | object | yes | no | Ready/Map | source/normalized mix | Second fighter link found. Includes nullable source fighter ID. |
| `result` | object | yes | no | Ready/Map/Review | normalized | Method/round/time. |
| `flags` | object | yes | no | Ready/Review | normalized booleans | Explicit source-level import/scoring guidance; not a ranking decision. |
| `warnings` | array<string> | no | omitted when empty | Review | normalized warning codes | Do not ignore. |
| `identity_hash` | string | yes | no | Ready | normalized | Stable SHA-256 identity hash for the source bout entity. |
| `content_hash` | string | yes | no | Ready | normalized | Stable SHA-256 content hash for material bout data. |

Bout identity/content hash strategy:

- `identity_hash` input includes hash type, source, parent `source_event_id`, `source_bout_id`, `source_bout_numeric_id`, canonical `bout_url`, both fighter source IDs, and `card_position` as the available order/card context.
- `content_hash` input includes hash type, `result_type`, winner/loser role, method category/detail, round/time plus `time_semantics`, both prefight record objects, weight class, weight pounds, card position, title fight object, explicit flags, and material bout warnings.
- Both hashes use stable JSON serialization and SHA-256. They intentionally include different hash type markers and must not be equal.
- For fallback bout IDs (`tapology_bout_fallback_*`), `source_bout_numeric_id` is null and warning `fallback_source_bout_id` is added for auditability.

Important result/status notes:

- Result rows without completed results are skipped entirely in result mode and counted as event warnings.
- Cancelled bouts are not currently expected in normal result output because `has_completed_result()` returns false for cancelled/scrapped/postponed row text.
- Overturned rows are represented only when explicitly indicated by normalized source fields; normal result output currently sets `flags.is_overturned` to `false`.
- No-contest can appear as `result_type: "no_contest"` and `result.method_category: "no_contest"`.
- Draw can appear as `result_type: "draw"` and `result.method_category: "draw"`.
- `flags.is_scoring_candidate` is source-level import guidance only. It is `true` only for completed professional win/loss rows that are not amateur, cancelled, upcoming, or overturned. The future Data Engine can still override, review, or exclude the bout.

Flags object:

| Field | Type | Normal current result value | Notes |
| --- | --- | --- | --- |
| `is_completed` | boolean | `true` | True only when `status` is `completed` and the row is not upcoming/cancelled. |
| `is_professional` | boolean | `true` | True when retained row is explicitly professional. |
| `is_amateur` | boolean | `false` | True only for explicitly amateur retained rows; current result output skips amateur rows. |
| `is_cancelled` | boolean | `false` | True only for explicit cancelled/canceled/scrapped/postponed status or `result_type: "cancelled"`. |
| `is_overturned` | boolean | `false` | True only when a normalized source field explicitly marks the bout overturned. |
| `is_upcoming` | boolean | `false` | True when `status` is `upcoming`. |
| `is_scoring_candidate` | boolean | `true` for normal completed pro win/loss rows | False for draw, no contest, cancelled, unknown, amateur, upcoming, or overturned rows. |
| `is_bout_scoring_candidate` | boolean | same as `is_scoring_candidate` | Clear alias meaning only completed professional MMA bout with a valid win/loss result; not fighter rankability. |

Example bout:

```json
{
  "source_bout_id": "tapology_bout_1130296",
  "source_bout_numeric_id": "1130296",
  "bout_url": "https://www.tapology.com/fightcenter/bouts/1130296-combate-global-gianni-kriptonita-vazquez-vs-kamron-ortikov",
  "status": "completed",
  "pro_am": "professional",
  "card_position": "main_event",
  "weight_lbs": 135,
  "winner": "fighter_a",
  "loser": "fighter_b",
  "result_type": "win_loss",
  "fighter_a": {
    "name": "Gianni Vazquez",
    "url": "https://www.tapology.com/fightcenter/fighters/99104-gianni-vasquez-kriptonia",
    "source_fighter_id": "tapology_fighter_99104",
    "source_fighter_numeric_id": "99104",
    "prefight_record": {
      "raw": "13-5-1",
      "wins": 13,
      "losses": 5,
      "draws": 1,
      "nc": 0,
      "is_missing": false
    }
  },
  "fighter_b": {
    "name": "Kamron Ortikov",
    "url": "https://www.tapology.com/fightcenter/fighters/389586-kamron-ortikov",
    "source_fighter_id": "tapology_fighter_389586",
    "source_fighter_numeric_id": "389586",
    "prefight_record": {
      "raw": "7-2-0",
      "wins": 7,
      "losses": 2,
      "draws": 0,
      "nc": 0,
      "is_missing": false
    }
  },
  "result": {
    "method_category": "ko_tko",
    "method_detail": "Punches",
    "round": 2,
    "time": "1:34",
    "time_semantics": "round_clock"
  },
  "flags": {
    "is_completed": true,
    "is_professional": true,
    "is_amateur": false,
    "is_cancelled": false,
    "is_overturned": false,
    "is_upcoming": false,
    "is_scoring_candidate": true
  },
  "weight_class": "BANTAMWEIGHT",
  "identity_hash": "sha256-hex-string",
  "content_hash": "sha256-hex-string",
  "internal_bout_id": "mf_bout_000002",
  "internal_event_id": "mf_event_000002",
  "source_event_id": "tapology_event_140087"
}
```

## Fighter Object

Paths:

```text
results.events[].bouts[].fighter_a
results.events[].bouts[].fighter_b
```

Fields:

| Field | Type | Required | Nullable/optional | Stability | Raw/normalized | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `name` | string | yes | no | Ready/Map | source text normalized whitespace | Display/source name only. |
| `url` | string URL | no | optional | Ready | raw/source | Tapology fighter URL. |
| `source_fighter_id` | string/null | yes | yes | Ready | normalized source ID | Derived from fighter URL as `tapology_fighter_{numeric_id}` for numeric URLs or `tapology_fighter_url_{stable_hash}` for slug-only fighter URLs; null only when missing/unparseable. |
| `source_fighter_numeric_id` | string/null | yes | yes | Ready | normalized source ID | Numeric component of `source_fighter_id`, e.g. `"99104"`; null for slug-only URL fallback IDs and missing/unparseable URLs. |
| `prefight_record` | object | yes | no | Ready/Review | normalized from source text | See below. |

Identity note:

- `source_fighter_id` is a Tapology source identifier only.
- The scraper does not resolve canonical fighter identity or merge fighters.
- If a fighter URL is slug-only, the scraper derives a stable URL-based fallback ID and leaves `source_fighter_numeric_id` null. If a fighter URL is missing or unparseable, the key is still present with `null`.

## Prefight Record Object

Paths:

```text
results.events[].bouts[].fighter_a.prefight_record
results.events[].bouts[].fighter_b.prefight_record
```

Fields:

| Field | Type | Required | Nullable | Stability | Notes |
| --- | --- | --- | --- | --- | --- |
| `raw` | string | yes | no | Ready/Review | Original parsed candidate, or `N/A`. |
| `wins` | integer/null | yes | yes | Ready/Review | Null when missing/unparsed. |
| `losses` | integer/null | yes | yes | Ready/Review | Null when missing/unparsed. |
| `draws` | integer/null | yes | yes | Ready/Review | Defaults to `0` when omitted from parsed W-L record. |
| `nc` | integer/null | yes | yes | Ready/Review | Defaults to `0` if no NC token in parsed record. |
| `is_missing` | boolean | yes | no | Ready | True when no reliable record was parsed. |

Examples:

```json
{
  "raw": "13-5-1",
  "wins": 13,
  "losses": 5,
  "draws": 1,
  "nc": 0,
  "is_missing": false
}
```

```json
{
  "raw": "N/A",
  "wins": null,
  "losses": null,
  "draws": null,
  "nc": null,
  "is_missing": true
}
```

## Result Object

Path:

```text
results.events[].bouts[].result
```

Fields:

| Field | Type | Required | Nullable/optional | Stability | Raw/normalized | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `method_category` | string | yes | no | Ready/Map/Review | normalized | Enum: `ko_tko`, `submission`, `decision`, `dq`, `no_contest`, `draw`, `unknown`. |
| `method_detail` | string | no | optional | Map/Review | source-derived normalized text | Examples: `Punches`, `Arm Triangle Choke`, `unanimous`. |
| `round` | integer | no | optional | Ready/Review | normalized | Parsed from `Round`, `Rd`, or `R` patterns. |
| `time` | string | no | optional | Ready/Review | normalized | `M:SS`/`MM:SS` string parsed from result segment or row. |
| `time_semantics` | string | no | optional | Ready/Review | normalized | Enum: `round_clock`, `total_duration`, `unknown`. Importer code must use this before treating `result.time` as a round clock. |

N/A/unknown handling:

- `normalize_method(None)`, blank, `N/A`, `unknown`, etc. produce `method_category: "unknown"`, `method_detail: null`, and warning `unknown_method`.
- `method_detail` may be absent after pruning when null.

Round/time semantics:

- Finish results use `result.time_semantics: "round_clock"` when `result.round` and `result.time` are parsed as the round and round-clock finish time.
- Decision rows can expose elapsed fight durations such as `15:00` or `25:00` with no final-round value. These use `result.time_semantics: "total_duration"`. Importer/ranking code must not interpret `result.time` as a final-round clock unless `time_semantics` is `round_clock`.
- Draw/no-contest style matchup text can also use `result.time_semantics: "total_duration"` when the local result segment explicitly marks the value as `Total`.
- Missing or ambiguous timing context uses `result.time_semantics: "unknown"` when the field is emitted.

## Title Fight Object

Path:

```text
results.events[].bouts[].title_fight
```

Fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `is_title_fight` | boolean | Map/Review | Current code sets true when title text detected. |
| `title_name` | string | Map/Review | Source-derived title/championship text. |
| `belt_status_before_fight` | string | Map/Review | Optional source-derived status. |

The object is omitted when no title info is detected or title line is `N/A`.

## Changes Document

Path patterns:

```text
data/latest/changes.json
data/runs/{run_id}/changes.json
```

Purpose:

- Machine-readable lists of scraper internal IDs that were new, updated, unchanged, or incomplete in the successful commit.
- Dashboard planning hints for fighters and manual review events derived from the latest successful output.

Top-level fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `schema_version` | string | Ready | Current value `scraper_changes_v0_1`. |
| `run_id` | string | Ready | Matches run manifest. |
| `new_events` | array<string> | Internal | Internal event IDs. |
| `updated_events` | array<string> | Internal | Internal event IDs whose normalized fingerprint changed. |
| `unchanged_events` | array<string> | Internal | Internal event IDs unchanged. |
| `new_bouts` | array<string> | Internal | Internal bout IDs. |
| `updated_bouts` | array<string> | Internal | Internal bout IDs whose normalized fingerprint changed. |
| `unchanged_bouts` | array<string> | Internal | Internal bout IDs unchanged. |
| `incomplete_events` | array<string> | Review/Internal | Internal event IDs queued for recheck. |
| `new_fighter_urls` | array<string> | Review | Fighter profile URLs seen in newly created events. |
| `fighters_to_refresh` | array<string> | Review | Fighter profile URLs seen in changed or incomplete events. |
| `manual_review_events` | array<string> | Review | Source event IDs that should appear in `manual_review.json`. |
| `warnings` | array<string> | Review | Run summary warnings. |

Plugin use:

- Useful for incremental import scheduling only if the plugin maintains a mapping from scraper internal IDs/source IDs to canonical records.
- Do not treat internal IDs as canonical WordPress IDs.

Example:

```json
{
  "schema_version": "scraper_changes_v0_1",
  "run_id": "20260513T011055Z",
  "new_events": ["mf_event_000001"],
  "updated_events": [],
  "unchanged_events": [],
  "new_bouts": ["mf_bout_000001"],
  "updated_bouts": [],
  "unchanged_bouts": [],
  "incomplete_events": ["mf_event_000001"],
  "new_fighter_urls": ["https://www.tapology.com/fightcenter/fighters/123-example"],
  "fighters_to_refresh": ["https://www.tapology.com/fightcenter/fighters/123-example"],
  "manual_review_events": ["tapology_event_123"],
  "warnings": []
}
```

## Manual Review Document

Path:

```text
data/latest/manual_review.json
```

Purpose:

- Machine-readable list of non-silent issues that should not disappear before dashboard import.
- Includes event fetch timeouts/failures, parser warnings, incomplete events, completed bouts with missing method category, failed profiles, fighters without profile records, missing fighter profiles, and missing event location/promotion identifiers.

Top-level fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `schema_version` | string | Ready | Current value `scraper_manual_review_v0_1`. |
| `generated_at` | ISO datetime string | Ready | |
| `run_id` | string/null | Ready | Latest event run ID when available. |
| `manual_review_count` | integer | Ready | Total review items. |
| `blocking_review_count` | integer | Ready | Items that block automated import. |
| `counts_by_type` | object | Ready | Counts keyed by review type. |
| `items` | array<object> | Ready | Each item has `type`, `entity`, `severity`, and `context`. |

## Daily Summary Document

Path:

```text
data/latest/daily_summary.json
```

Purpose:

- Final pre-import health gate. Automated dashboard import should proceed only when `ready_for_import` is `true`.

Top-level fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `schema_version` | string | Ready | Current value `scraper_daily_summary_v0_1`. |
| `ready_for_import` | boolean | Ready | False when any blocking issue exists. |
| `event_run_status` | string/null | Ready | Mirrors latest `run_manifest.status`. |
| `profile_run_status` | string | Ready | `success` or `partial`. |
| `events_total` | integer | Ready | Count from latest `results.json`. |
| `bouts_total` | integer | Ready | Count from latest `results.json`. |
| `profiles_total` | integer | Ready | Count from profile report or profile file. |
| `profiles_success` | integer | Ready | Successful profile count. |
| `manual_review_count` | integer | Ready | Count from `manual_review.json`. |
| `blocking_issues` | array<string> | Ready | Empty when ready for automated import. |
| `canonical_outputs` | object | Ready | Paths dashboard import may read. |
| `dashboard_import_contract` | object | Ready | Dedupe keys: source event, bout, and fighter IDs. |

## Run Manifest

Path patterns:

```text
data/latest/run_manifest.json
data/runs/{run_id}/run_manifest.json
```

Purpose:

- Run provenance, options, status, and counts.

Fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `schema_version` | string | Ready | Current value `scraper_run_manifest_v0_1`. |
| `run_id` | string | Ready | UTC timestamp ID. |
| `started_at` | ISO datetime string | Ready | |
| `finished_at` | ISO datetime string/null | Ready | Null possible for incomplete failed manifest construction. |
| `status` | string | Ready | `success` or `failed`. |
| `source` | string | Ready | `tapology`. |
| `mode` | string | Ready | `incremental` or `full_scan`. |
| `options` | object | Ready/Internal | CLI/config options used. |
| `counts` | object | Internal/Review | Run counters. |

Options fields:

- `limit_events`
- `max_pages`
- `from_date`
- `recheck_days`
- `recheck_known_limit`
- `stop_after_known_complete`
- `force_recheck_event`
- `force_full_scan`
- `page_pause_seconds`
- `profile_pause_seconds`
- `event_pause_seconds`
- `jitter_seconds`
- `max_profile_fetches`
- `profile_batch_size`
- `profile_batch_pause_seconds`
- `max_consecutive_access_failures`
- `max_profile_failure_rate`
- `max_event_fetch_failures`
- `stop_on_access_degradation`
- `load_more_post_click_wait_ms`
- `debug`

Counts fields:

- `events_seen`
- `events_scraped`
- `events_new`
- `events_updated`
- `events_unchanged`
- `events_rechecked`
- `events_marked_incomplete`
- `early_stop_triggered`
- `early_stop_reason`
- `pages_scanned`
- `first_listing_url`
- `last_listing_url`
- `listing_urls_visited`
- `pagination_next_found`
- `pagination_stop_reason` (`max_pages`, `natural_end_no_load_more_button`, `load_more_no_new_events`, `load_more_timeout`, `access_degraded`, plus existing project stop reasons)
- `load_more_clicks`
- `load_more_successes`
- `load_more_no_new_events`
- `load_more_timeouts`
- `unique_listing_event_urls_seen`
- `duplicate_listing_event_urls_seen`
- `page_pauses_count`
- `event_pauses_count`
- `profile_pauses_count`
- `profile_batch_pauses_count`
- `total_pause_seconds`
- `profile_fetches_before_cap`
- `profile_enrichment_partial`
- `access_stop_reason`
- `consecutive_access_failures`
- `event_fetch_failures`
- `skipped_events_no_usable_bouts` (backward-compatible skipped result-event count)
- `skipped_events_non_mma_or_no_results` (backward-compatible umbrella count)
- `events_skipped_non_mma`
- `events_skipped_no_results`
- `events_skipped_all_amateur`
- `events_skipped_no_usable_pro_mma_bouts`
- `bouts_seen` (backward-compatible alias for source rows seen)
- `bouts_source_rows_seen` (retained/written bouts plus structured skipped source rows)
- `bouts_written` (retained/written bouts only)
- `bouts_new`
- `bouts_updated`
- `bouts_unchanged`
- `bouts_skipped_amateur` (skipped source rows)
- `bouts_skipped_non_mma` (skipped source rows)
- `bouts_skipped_no_result` (skipped source rows)
- `bouts_skipped_unknown_professional_status` (skipped source rows)
- `source_mma_bouts_total_mismatches`
- `profile_enrichment_status`
- `profile_fetch_attempts`
- `profile_fetch_successes`
- `profile_fetch_failures`
- `profile_fetch_failure_reasons`
- `profile_parse_successes`
- `profile_parse_failures`
- `profile_context_counts` (`retained_bout_profiles`, `skipped_mma_profiles`, `skipped_non_mma_profiles`; diagnostic only)
- `profile_fields_coverage`
- `unmatched_nationality_raw_values`
- `rejected_nationality_candidates`
- `rejected_nationality_candidate_counts`
- `rejected_nationality_candidate_reasons`
- `nationality_extraction_source_counts`

Latest advancement and incomplete pagination:

- Successful runs write run artifacts, update scraper state, and advance `data/latest`.
- Failed or degraded attempts write run artifacts such as `partial_results.json` and `run_manifest.json`, update only attempt metadata in state, and do not advance `data/latest/results.json` as a complete dataset.
- If `max_pages > 1` and FightCenter load-more fails before the requested page count, the run is classified as failed/degraded under the existing failed-attempt convention. The manifest counts include `early_stop_triggered: true`, `early_stop_reason`, `pagination_stop_reason`, and `access_stop_reason` when applicable.
- A `load_more_timeout` is terminal for pagination reporting and must not be overwritten by `natural_end_no_load_more_button`.
- `unmatched_location_country_candidates`
- `unmatched_born_country_candidates`
- `unmatched_fighting_out_country_candidates`
- `top_profile_warning_codes`
- `warnings_count`
- `retained_event_warnings_count`
- `skipped_event_warnings_count`
- `errors_count`

## State Files

State files are scraper operational data. The plugin may read them for import diagnostics, but canonical plugin tables should not be copies of these files.

### `scraper_state.json`

Path:

```text
data/state/scraper_state.json
```

Fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `schema_version` | string | Internal | `scraper_state_v0_1`. |
| `source` | string | Ready | `tapology`. |
| `last_successful_run_id` | string/null | Internal | |
| `last_successful_started_at` | ISO datetime/null | Internal | |
| `last_successful_finished_at` | ISO datetime/null | Internal | |
| `last_attempted_run_id` | string/null | Internal | |
| `last_attempted_started_at` | ISO datetime/null | Internal | |
| `last_attempted_finished_at` | ISO datetime/null | Internal | |
| `next_internal_event_number` | integer | Internal | Next scraper ID counter. |
| `next_internal_bout_number` | integer | Internal | Next scraper ID counter. |
| `total_known_events` | integer | Internal | Derived from index length. |
| `total_known_bouts` | integer | Internal | Derived from index length. |
| `last_successful_newest_event_date` | date/null | Internal | |
| `last_successful_oldest_event_date` | date/null | Internal | |

### `events_index.json`

Path:

```text
data/state/events_index.json
```

Shape:

```text
{
  "{source_event_id}": { event index record }
}
```

Fields in each record:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `internal_event_id` | string | Internal | Stable scraper event ID. |
| `source_event_id` | string | Ready | Must match object key. |
| `event_url` | string URL | Ready | |
| `event_name` | string | Map | |
| `event_date` | date string/null | Ready | |
| `first_seen_at` | ISO datetime | Internal | |
| `last_seen_at` | ISO datetime | Internal | |
| `last_scraped_at` | ISO datetime | Internal | |
| `last_successfully_scraped_at` | ISO datetime | Internal | |
| `scrape_count` | integer | Internal | |
| `known_bout_ids` | array<string> | Internal | Internal bout IDs. |
| `known_completed_bout_ids` | array<string> | Internal | Internal bout IDs. |
| `known_bouts_count` | integer | Internal | |
| `known_completed_bouts_count` | integer | Internal | |
| `last_normalized_event_fingerprint` | string | Internal/Ready for diff | Content fingerprint; not in result output. |
| `state` | string | Review/Internal | Event recheck/import state. |
| `needs_recheck` | boolean | Review | |
| `recheck_reason` | string/null | Review | |
| `last_warning_codes` | array<string> | Review | Event and bout warnings from last scrape. |

State values seen/implemented:

- `pending_results`
- `no_relevant_pro_mma_bouts`
- `partial`
- `parser_attention`
- `complete_recent_recheck`
- `complete`
- `ignored` is recognized by skip logic but not produced by current code path found in this audit.

### `bouts_index.json`

Path:

```text
data/state/bouts_index.json
```

Shape:

```text
{
  "{source_bout_id}": { bout index record }
}
```

Fields in each record:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `internal_bout_id` | string | Internal | Stable scraper bout ID. |
| `source_bout_id` | string | Ready | Must match object key. |
| `internal_event_id` | string | Internal | Parent scraper event ID. |
| `source_event_id` | string | Ready | Parent Tapology event ID. |
| `bout_url` | string URL | Ready | |
| `first_seen_at` | ISO datetime | Internal | |
| `last_seen_at` | ISO datetime | Internal | |
| `last_scraped_at` | ISO datetime | Internal | |
| `last_successfully_scraped_at` | ISO datetime | Internal | |
| `normalized_fingerprint` | string | Internal/Ready for diff | Content fingerprint; not in result output. |
| `status` | string | Ready/Map | |
| `pro_am` | string | Ready/Map | |
| `result_type` | string | Ready/Map | |
| `winner` | string/null | Ready/Map | Role string. |
| `fighter_a_name` | string | Map | |
| `fighter_b_name` | string | Map | |

### `incomplete_events.json`

Path:

```text
data/state/incomplete_events.json
```

Shape:

```text
{
  "{source_event_id}": { incomplete event record }
}
```

Fields:

| Field | Type | Stability | Notes |
| --- | --- | --- | --- |
| `internal_event_id` | string | Internal | |
| `source_event_id` | string | Ready | Must match object key. |
| `source_event_numeric_id` | string/null | Ready | Numeric component when available. |
| `event_url` | string URL | Ready | |
| `event_name` | string | Map | |
| `event_date` | date string/null | Ready | |
| `source_event_status` | string | Ready | Source completeness status. |
| `source_bouts_total` | integer/null | Ready | Backward-compatible Tapology MMA bout total when available. |
| `source_mma_bouts_total` | integer/null | Ready | Explicit Tapology MMA bout total when available. |
| `bouts_included` | integer | Ready | Retained completed professional MMA bouts. |
| `bouts_skipped_total` | integer | Ready | Structured skipped bout count. |
| `bouts_skipped_no_result` | integer | Ready | Unresolved skipped result count. |
| `source_rows_seen` | integer | Ready | Retained bouts plus all structured skipped rows. |
| `source_mma_rows_seen` | integer | Ready | Retained bouts plus skipped MMA rows; excludes non-MMA rows. |
| `needs_recheck` | boolean | Ready | Event-level scraper completeness flag. |
| `first_marked_incomplete_at` | ISO datetime | Internal | |
| `last_seen_at` | ISO datetime | Internal | Last successful run that saw this queued event. |
| `last_rechecked_at` | ISO datetime | Internal | |
| `recheck_count` | integer | Internal | First queue write uses `0`. |
| `recheck_reason` | string | Review | |
| `next_recheck_after` | ISO datetime | Internal | Due timestamp. |
| `priority` | string | Review/Internal | `high` or `normal`. |

Recheck reasons:

- `no_completed_professional_mma_bouts`
- `missing_or_partial_results`
- `parser_warnings`

## State Report

Path:

```text
data/latest/state_report.json
```

Schema:

- `scraper_state_report_v0_1`

Purpose:

- Read-only local report for humans and diagnostics.
- It summarizes known events/bouts, incomplete queue, event states, missing critical data, duplicate IDs, orphan bouts, latest run counts, and changes.

Not a primary plugin import feed.

Useful diagnostic fields:

- `total_known_events`
- `total_known_bouts`
- `incomplete_events_count`
- `incomplete_events_by_reason`
- `event_states`
- `critical_missing_data`
- `bouts_by_result_type`
- `bouts_by_status`
- `duplicate_*`
- `orphan_bouts`
- `latest_run_summary`

## Debug Document

Path:

```text
data/runs/{run_id}/debug.json
```

Purpose:

- Parser maintenance and raw evidence. Only written when `--debug` is used.

Fields:

- `run_id`
- `source`
- `source_url`
- `started_at`
- `finished_at`
- `events`
- `bouts`
- `diagnostics`

Debug payloads may include:

- raw event list row text
- event page text excerpts
- matchup page text excerpts
- method raw strings
- result raw strings
- row prefight record candidates
- parser diagnostics

Importer guidance:

- Do not consume `debug.json` for canonical import.
- Normal validation intentionally rejects raw/debug fields in `results.json`.

## Raw Debug Artifacts

Path:

```text
data/runs/{run_id}/raw_debug/*.html
data/runs/{run_id}/raw_debug/*.png
```

Purpose:

- Saved browser page HTML/screenshots for hard-block or fight-card debugging.

Importer guidance:

- Debug only.

## Upcoming Document

Path:

```text
data/runs/{run_id}/upcoming.json
```

Schema:

- `scraper_upcoming_v0_1`

Purpose:

- Upcoming event discovery. Shape is similar to results but `filters.schedule` is `upcoming`, event objects can include `event_time_raw` and `timezone_raw`, and bout `status` is `upcoming`.

Readiness:

- Not currently mirrored to `data/latest/` by `DataStore._commit_latest()`.
- Not validated by `validate-latest`.
- Not recommended as the first plugin import contract until separately stabilized.

## Derived Fighter Index Files

These are local derived aggregation outputs, not source scrape outputs and not final rankings.

### `fighters_index.json`

Path:

```text
data/derived/fighters_index.json
```

Schema:

- `derived_fighters_index_v0_1`

Top-level fields:

- `schema_version`
- `generated_at`
- `source_run_id`
- `fighters`

Fighter keys:

- `tapology_fighter_{numeric_id}` when fighter URL includes numeric ID.
- `tapology_fighter_url_{hash}` when URL exists but numeric ID parsing fails.
- `missing_url_{hash}` when only name exists.

Record fields:

- `fighter_key`
- `fighter_source_url`
- `fighter_source_id`
- `display_name`
- `name_variants`
- `bouts_count_in_dataset`
- `wins_in_dataset`
- `losses_in_dataset`
- `draws_in_dataset`
- `nc_in_dataset`
- `finishes_in_dataset`
- `ko_tko_wins_in_dataset`
- `submission_wins_in_dataset`
- `decision_wins_in_dataset`
- `last_fight_date_in_dataset`
- `last_fight_event`
- `last_fight_result`
- `seen_as_fighter_a_count`
- `seen_as_fighter_b_count`
- `tapology_profile_record`
- `prefight_record_latest`
- `computed_record_from_imported_bouts`
- `record_sources`

Record source fields:

- `record_sources.tapology_profile_record`: current successful profile record from `data/latest/fighter_profiles.json`, with source URL and scrape timestamp.
- `record_sources.prefight_record_latest`: latest bout-level prefight record from `results.json`, with event/bout provenance.
- `record_sources.computed_record_from_imported_bouts`: W/L/D/NC computed only from collected completed professional bouts in the imported dataset.
- `record_sources.record_mismatch`: true when Tapology profile record and computed imported-bout record disagree.
- `record_sources.warnings`: compact warning codes, including `missing_tapology_profile_record`, `missing_prefight_record_source`, and `tapology_profile_record_mismatch`.

Readiness:

- Useful for analysis and missing data reports.
- Not canonical fighter identity.
- Not a ranking calculation.
- Dashboard import should not overwrite the computed internal record from `tapology_profile_record`; it should treat profile records as external review data.

### `fighter_fight_logs.json`

Path:

```text
data/derived/fighter_fight_logs.json
```

Schema:

- `derived_fighter_fight_logs_v0_1`

Rows include:

- `fighter_key`
- event and bout IDs
- opponent name/URL
- `result_for_fighter`
- method fields
- round/time
- weight/card position
- fighter/opponent prefight records
- opponent prefight differential

Readiness:

- Useful for a future stats/ranking design review.
- Should not be imported as canonical fight history without identity review.

### `processor_report.json`

Path:

```text
data/derived/processor_report.json
```

Schema:

- `derived_fighter_processor_report_v0_1`

Reports:

- unique fighter count
- fight-log row count
- missing URLs
- missing method categories
- missing prefight records
- missing winner/loser mappings
- unavailable opponent prefight differentials
- top dataset wins/finishes
- validation errors

Readiness:

- Diagnostic only.

## Field Readiness for Plugin Import

### Ready for plugin import

Use these as source-adapter facts:

- `schema_version`
- `source`
- `source_url`
- `scraped_at`
- `run.run_id`
- `run.started_at`
- `run.finished_at`
- `filters`
- `event.source_event_id`
- `event.source_event_numeric_id`
- `event.event_url`
- `event.event_date`
- `event.event_name` as source display text
- `event.promotion_url`
- `event.source_promotion_id`
- `event.source_promotion_numeric_id`
- `event.promotion_name` as source display text
- `bout.source_event_id`
- `bout.source_bout_id` when it is a Tapology URL-derived ID
- `bout.source_bout_numeric_id`
- `bout.bout_url`
- event and bout `identity_hash`
- event and bout `content_hash`
- `bout.status`
- `bout.pro_am`
- `bout.flags`
- `fighter_a.name`, `fighter_b.name`
- `fighter_a.url`, `fighter_b.url`
- `fighter_a.source_fighter_id`, `fighter_b.source_fighter_id`
- `fighter_a.source_fighter_numeric_id`, `fighter_b.source_fighter_numeric_id`
- prefight record object shape
- `result_type`
- `winner`/`loser` role fields for win/loss bouts
- `result.method_category`
- `result.round`
- `result.time`
- `warnings` as review flags

### Needs normalization/mapping

- `internal_event_id`
- `internal_bout_id`
- `event_name`
- `promotion_name`
- `location`
- `venue`
- `enclosure`
- `weight_class`
- `weight_lbs`
- `card_position`
- `title_fight`
- `method_detail`
- `winner`/`loser` role values to canonical fighter IDs
- fighter source URLs to canonical fighters
- prefight records to canonical prefight snapshots
- warning strings to structured review flags
- changes arrays to importer scheduling state

### Missing / required before import

- reliable result/source evidence field such as `method_raw` or compact `source_result_raw`
- structured warning objects with `code`, `severity`, and `field_path`
- JSON Schema or equivalent machine-readable contract

Remaining importer risks:

- Promotion IDs are source IDs only; canonical promotion/organization mapping still belongs in the importer/Data Engine.
- Fighter source IDs are source IDs only; canonical fighter creation, identity review, and merge resolution remain outside the scraper.
- Fallback bout IDs are auditable but still lower confidence than URL-derived Tapology bout IDs.
- `content_hash` detects source content changes, not semantic canonical changes after importer mapping.
- Structured warning objects and JSON Schema are still not implemented.

## Import Safety Rules

Recommended plugin behavior:

1. Import only successful `run_manifest.status == "success"` runs.
2. Treat `results.schema_version` as a strict compatibility gate.
3. Store `source`, `run_id`, `source_event_id`, and `source_bout_id` with every imported source row.
4. Do not create canonical fighters solely by name; use fighter URL/source ID when available and queue ambiguous identities for review.
5. Do not calculate rankings from scraper output directly.
6. Do not treat scraper `internal_*` IDs as WordPress primary keys.
7. Queue any event/bout with warnings, missing fighter URLs, unknown method category, missing prefight record, fallback bout ID, or incomplete state for review.
8. Keep raw/source data separate from canonical mapped data.
9. Build plugin imports to be idempotent by source IDs and run provenance.
10. Require fixture contract tests before enabling automated imports.
