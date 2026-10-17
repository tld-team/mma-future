# Scraping cycles and regular imports

The supported flow is a declared scraping cycle, an immutable offline package,
the staging preflight, a dry-run, **Start import**, a private backup, committed
changes, career verification, statistics and a final report. Ranking activation
remains a separate administrator action.

Plugin 2.8.0 requires the explicit database migration to schema 1.12.0. Deploy
through the existing verified release runner. Legacy contract v1 remains readable;
new exports use evidence contract v2. See [event evidence and priorities](scraper-priorities.md)
for the additive registry, source identity gates and persistent event coverage.

## Prepare one package after all runs

Use the Python environment installed from `dev-tools/scraper`. Set
`MMAF_SCRAPER_DATA_DIR` before starting Python when retaining an existing data
directory outside the checkout. The default is `dev-tools/scraper/data`.
All commands in a cycle must use the same data directory.

```sh
export MMAF_SCRAPER_DATA_DIR="/absolute/path/to/existing/scraper/data"

python -m mma_future_scraper.cli begin-import-cycle \
  --cycle-id daily-2026-10-14 --event-runs 1 --profile-runs 1

python -m mma_future_scraper.cli run --results-only --cumulative-results \
  --no-enrich-fighter-profiles \
  --cycle-id daily-2026-10-14 --cycle-task events-1

python -m mma_future_scraper.cli build-fighter-profile-queue

python -m mma_future_scraper.cli enrich-fighter-profiles \
  --from-queue --limit-fighters 20 \
  --cycle-id daily-2026-10-14 --cycle-task profiles-1 \
  --finalize-import --import-output-dir output/import-ready
```

Choose the planned run counts and normal source/browser limits for the intended
scope. A twenty-profile run is a bounded example, not a claim that every profile
was fetched. Declare additional `profiles-2`, `profiles-3`, etc. before starting
a larger cohort. Use a source enrichment queue for a recovery cohort.

The last successful planned task can publish automatically. To finalize manually:

```sh
python -m mma_future_scraper.cli finalize-import \
  --cycle-id daily-2026-10-14 --output-dir output/import-ready
```

The cycle directory contains `package.zip`, `package.sha256`,
`readiness-report.json`, `readiness-report.md`, `source-enrichment-queue.json`
and `cycle-manifest.json`. The ZIP manifest binds the cycle, source runs, sealed
input hashes, evidence contract and every package file. Publication also binds
the separate reports. Repeating finalization returns the same verified ZIP.
It performs no network scraping and leaves `latest` unchanged.

An unfinished task blocks finalization. All scraper CLI commands share a writer
lock. A crash before a task finishes leaves a retryable task; after publication,
retry verifies and recovers the existing package. Changes to sealed input require
a new cycle, including changes to the incomplete-event state.

```sh
python -m mma_future_scraper.cli retry-import-task \
  --cycle-id daily-2026-10-14 --cycle-task profiles-1
```

Rerun that task with its original arguments. Do not retry a task still running in
another process. The writer lock prevents a competing CLI reset. Do not edit a
sealed registry or overwrite an already published package.

For already completed runs, declare a cycle and register the exact current
outputs before finalizing:

```sh
python -m mma_future_scraper.cli record-import-run \
  --cycle-id existing-cohort --cycle-task events-1 \
  --run-dir "$MMAF_SCRAPER_DATA_DIR/latest"
python -m mma_future_scraper.cli record-import-run \
  --cycle-id existing-cohort --cycle-task profiles-1 \
  --run-dir "$MMAF_SCRAPER_DATA_DIR/latest"
```

Registration compares actual bytes with `latest`; an unrelated run folder cannot
stand in for the declared latest output. Reports are regenerated from the sealed
copy. The current dataset can be packaged without pretending it was freshly
scraped.

## Read the evidence report

Transport/results readiness, successful profile candidates and career evidence
are separate. A legacy profile may supply useful facts while its career needs
classification, exact links, scope or exhaustion evidence. Candidate readiness
does not grant publication or certify destination coverage.

The report counts parser versions, missing evidence groups, failed recorded
attempts, unrecorded historical attempts and the last merged batch. A failed new
fetch retains the old success content while recording its own time and reason.
The latest merge timestamp is not the observation date of every retained profile.

Exact bout identities connect two perspectives. Incompatible confirmed results,
participants, events or dates enter `review_conflict`, grouped by shared bout.
They are excluded from automatic source fetching. Missing source proof enters
`refresh_source_evidence`. Career verification still runs against the final
destination after all accepted perspectives are committed.

## Recover older profiles

New successful profile fetches retain a structured DOM snapshot in
`data/runs/<run>/profile_snapshots/`. The parser checks a redirected profile's
identity and captures the actual observation time. A professional section can
provide classification only with an explicit verified row boundary and proof.

```sh
python -m mma_future_scraper.cli prepare-import-recovery \
  --source-profiles "$MMAF_SCRAPER_DATA_DIR/latest/fighter_profiles.json" \
  --snapshots-dir /absolute/path/to/coherent/saved-snapshots \
  --output-dir output/recovery/cohort-1
```

This creates candidate reparsed profiles, a recovery report and a remaining
source queue atomically. It preserves the source file and observation dates,
binds each candidate to its original content and snapshot hash, and refuses
ambiguous duplicate snapshots. A different reparse at the same observation time
as stored success requires review; it is not silently treated as a new fetch.

For rows without recoverable DOM evidence, use a bounded targeted fetch:

```sh
python -m mma_future_scraper.cli enrich-fighter-profiles --from-queue \
  --queue-file output/recovery/cohort-1/source_enrichment_queue.json \
  --limit-fighters 20 \
  --cycle-id recovery-1 --cycle-task profiles-1
```

Begin the recovery cycle first and register its existing event output. The queue
does not reselect source IDs already attempted since that queue was generated,
so subsequent bounded batches advance. A later newly generated queue permits a
fresh retry of profiles still missing evidence. Existing browser, challenge and
source access limits continue to apply.

The 14 October legacy audit found zero eligible stored DOM snapshots for the
9,245 profiles. Those missing historical proofs require fresh source observation.
The synthetic recovery cohort demonstrates preserved observations and complete
evidence through the actual Python-to-PHP import, but is not a claim that the
legacy careers have been repaired.

## Import on staging

1. Open **MMA Future → Import → Setup**. Check schema, extensions, private
   storage, worker heartbeat, backup space and account bytes/inodes. If capacity
   evidence is stale, enter actual current cPanel usage and limits in the provided
   form. The system records a fresh observation only after valid values are saved.
2. Select `package.zip`. Package validation and the dry-run start automatically.
   Review source evidence and the proposed changes, then select **Start import**.
3. Follow each phase's actual units: package batches, prepared profiles, verified
   careers and calculated fighters. Closing the page does not stop the worker.
   Pause takes effect between committed units; Resume uses saved checkpoints.
4. Download the final report and source enrichment queue. New/changed raw
   snapshots and profiles with applied fact fields have separate counters.
   Review conflicts and the inactive ranking draft separately.

The managed supervisor uses `mmaf background work --seconds=35 --memory-mb=256`.
One WordPress bootstrap handles multiple existing atomic units. The loop checks
time, memory, step budget and release/pause flags between units, and yields to
existing shared locks. It reports phase duration, query count and peak memory.
It does not guarantee that an individual backup fits the loop's elapsed budget;
backup creation retains its existing dedicated lock and consistency checks.

## Backups and restore verification

Each backup verifies readable SQL, gzip CRC/size, SHA-256, table/row metadata,
ranking baseline and locally managed media. A full isolated restore is a separate
check. Setup displays the latest recorded successful restore.

After schema/backup-format changes, and at least monthly, run the supported
verification command with the private runner configuration and a newly prepared
empty dedicated verification database:

```sh
python3 ops/verify-import-backup.py --config /private/operations/config.json \
  --job-id 123 --media-dir /private/operations/verification-media-123
```

The configuration must have mode 600 and a `verification` target accepted by the
existing restore engine. The command binds the requested import backup to its
private catalog, uses the existing supervisor/restore locks, restores SQL and
media without WordPress/email/cron, verifies rows/rankings/media, and updates the
restore evidence. It refuses a populated verification database and does not
create or clear one. Existing verified retention rules and import holds continue
to apply. A fresh capacity observation is required for filesystem work.

## Optional delta packages

First complete a full import with this release and download its final report.
Imports completed before receipt support require one new full import. Then:

```sh
python -m mma_future_scraper.cli finalize-import \
  --cycle-id next-cohort --output-dir output/import-ready \
  --base-receipt /absolute/path/to/completed-import-report.json
```

Delta selection uses the receipt's acknowledged input hashes, not `changes.json`.
The backend verifies destination, base job/hash, schema, policy/formula and current
canonical revision before writes. Missing/expired receipts, changed rules or a
changed destination require a full package. A source deletion does not delete a
canonical record. Whole changed events remain intact, preserving existing partial
event protection. An identical input can produce a valid empty delta.

Unchanged careers are reused only when both source content and canonical context
match, including shared bouts, participants, identity bindings and manual facts.
Statistics are reused only with unchanged calculation inputs, the same site date,
current rules and complete fighter coverage; otherwise the existing coalesced
generation produces one inactive draft.

## Verification

CI runs scraper regressions and shared URL/digest fixtures, builds the actual
parser/cycle ZIP, imports it into an isolated database and verifies both career
perspectives, receipts/deltas, context invalidation, progress counters, worker
budgets and capacity validation. Existing manual/ranking protection and restore
tests remain enabled. Production data and credentials never enter the fixtures.
