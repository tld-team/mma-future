# Event evidence and editorial priorities

Plugin 2.8.1 / schema 1.12.0 adds private editorial targets and evidence contract v2.
Deploy and migrate with the verified staging runner. Existing snapshots and scoring
coefficients do not change. Activation remains an explicit ranking operation.

The client CSV is input data, not application configuration. No fighter names,
client scores or membership exceptions are embedded in application code. Any UTF-8
CSV with `Ime` or `name` can be previewed and imported at Fighters → Priorities.
Sports values remain original-row hints only. Exact repeated rows are idempotent;
namesakes with different original hints stay separate. Name suggestions require
review. Confirm numeric Tapology identity or an already mapped canonical fighter
before exporting the queue. Targets can be disabled or resolved later.

Exported queues use the existing `mmaf_source_enrichment_v1` fetch path, twenty
profiles per run. Confirmed targets initially request career aggregates; eligible
career candidates request the period on a subsequent queue export. The existing
profile parser still requires explicit professional MMA scope and period proof.
A successful profile of a confirmed new source may create an unpublished,
non-rankable provisional fighter without inventing an event. Source identity is
checked under the shared write lock immediately before creation. Publication uses
the existing preparation, review and bulk approval flow. Priority grants no score.

`priority_targets.json` binds packaged profile outcomes to target IDs, source IDs,
site and binding revision. Disabled, rebound, foreign-site or mismatched targets
fail validation. Canonical linking during import preserves the export revision
so replay is safe. Administrative writes require manage_options, nonce and the
existing shared operation guard; changes are audited. No priority REST resource
or public priority field is exposed.

## Event coverage

Full and compact cards are grouped by source bout ID. Explicit hidden per-bout
labels classify the sport and professional division. Participants, result badges,
method, clock and explicit pre-fight comparison records are separate fields.
Post-fight `Up to/Down to` values never populate pre-fight records. A mixed card is
filtered by bout. Confirmed completed professionals yielding zero accepted results
stop the run and retain the captured structured rows.

Parser v3 also explains explicitly labelled junior, Sanda and bare-knuckle bouts.
Only standard MMA rows contribute to the source MMA control count. On structured
cards the independent outcome badges establish completion; a belt abbreviation or
a fighter called Ko cannot establish a result. Cards with no published results
remain partial, and an empty priority registry renders without PHP deprecations.

Every v2 bundle includes `event_coverage.json` (`mmaf_event_coverage_v1`). Each event
retains source URL, actual observation time, parser version, expected MMA count,
unique bout decisions and reasons. Status is complete, partial, unavailable or
explicitly excluded. Complete coverage requires explained unique rows and matching
MMA totals when supplied. Unknown classification, missing cards and missing results
remain partial. Deliberate policy scope exclusions carry their own source evidence.
The saved `event_snapshots` can be reparsed with `event_recovery.reparse_event_snapshot`
without another network fetch; the observation time is preserved.

Python export and PHP preparation cross-check the coverage against accepted result
IDs, event proof, counters and run manifest. Every accepted covered event must be
present in the package. Delta export narrows coverage to selected results plus open
or excluded events. Manifest digests cover both evidence sidecars. Contract v1 is
still accepted as legacy and cannot establish confirmed new event coverage.

Transport validity and event completeness are separate in import review and the
final report. Safe results from partial events may import, but the import remains
completed_with_review and retains its original evidence. Neither a successful HTTP
request nor an empty parser output establishes complete coverage.

## Verification

Run the scraper pytest suite, offline saved-DOM test
`tests/integration/verify-event-dom.py`, and isolated WordPress integration tests
`verify-priorities.php`, `verify-import-pipeline.php`, `verify-scoring-period.php`.
The DOM test requires Playwright Chromium; it makes no network requests. Integration
fixtures require `MMAF_TEST_BOOTSTRAP` and the Python pipeline ZIP fixture used in CI.
All fixture writes roll back, including temporary ranking activations.

For a rollout, record every CSV target outcome, every known missing event outcome,
recent listing pagination and source failures. Fetch a failed target at most once
more in the cycle; keep the named unresolved list. Do not recursively fetch all
opponents' careers. After ordinary import and profile approval, rebuild statistics
and calculate a new inactive v1.6 draft with the May 9, 2026 period. Review the full
priority status export. Do not activate an earlier pilot as the rollout result.
