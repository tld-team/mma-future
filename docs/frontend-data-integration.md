# Frontend data integration and fighter preparation

## Data flow

The public frontend reads canonical data through `PublicReadService`, shared by PHP rendering and REST controllers. Editorial text, links and media use versioned ACF groups. No public block fetches WordPress's own API to render its initial content.

Regular scraper ZIP imports persist the last successful complete profile payload privately for every identified source fighter, including provisional fighters. Each payload retains its own fetch date; the merged export date is not a substitute. Older packages and failed requests cannot replace a newer successful payload. Contradictory identities or equally dated contradictory content enter review. Source payloads and internal recipients are never exposed through the public API.

Regular import processes event results, stores profile snapshots, applies safe factual enrichment, prepares already approved careers in bounded batches, rechecks shared career coverage, rebuilds statistics and requests one inactive ranking draft. Manual decisions protect verified values. Evolving source-owned fields refresh only with newer evidence and matching provenance. A fighter approval uses the existing stored payload and makes no source HTTP requests.

Professional career rows share the existing events, bouts and participants. Strong source IDs prevent duplicate bouts across two fighter profiles and repeated packages. A partial event package does not delete unrelated bouts. W/L/D/NC are counted separately; amateur, cancelled, future and non-MMA entries stay excluded. Missing historical pre-fight records remain unknown.

Coverage `complete` requires verified professional MMA scope, source exhaustion, matching W/L/D/NC and no unresolved conflicts. Shared final-result corrections revalidate earlier coverage. Partial/unavailable/conflicting profiles remain explicit. A source's aggregate record does not silently replace calculated statistics.

## Administrator workflow

The approval and deployment instructions in the earlier integration delivery are historical. Use [the current operations runbook](admin-operations-runbook.md) for daily administration, cron and releases.

Import stores profiles without publishing discovered fighters. Save preserves administrative decisions and protects only changed or explicitly confirmed facts. Single and filtered-set approval use a frozen operation manifest, explicit confirmation, saved JSON preparation, statistics and final checks before publication. Profile-only publication is a separate action. One completed changed scope creates one inactive draft; activation stays manual.

Public profiles can display recorded fights before a career is confirmed complete. Known zero values stay zero; unknown values stay null. The active ranking's calculation/activation dates and current statistics date remain separate.

The homepage ranking overview links to the full rankings beside both movers and leaders. Movers compare the two most recent distinct activations of the selected board, with matching formula rules and data scope. They select the largest absolute position differences, including rises and falls; ties use current rank and fighter ID. Both snapshots use the same current public/non-UFC visibility and contiguous positions, matching the public list without blocking every comparison after a UFC signing. New entries have a current position and a separate `New` label, never an invented numeric change. Identical snapshots remain explicitly unchanged; incompatible methodology remains unavailable.

## Worker and one-time migration

The staging server supervisor owns MMA Future work and runs once per minute. With `MMAF_SERVER_SUPERVISOR=true`, the legacy MMA Future WP-Cron callbacks do not run. Core WordPress cron runs separately. Do not run the historical regular-import shell runner alongside the operations supervisor. The [current runbook](admin-operations-runbook.md) describes release and recovery commands.

After backing up the target database and deploying code, seed already existing private exports once. This copies their last-success snapshots, not the local database, and does not fetch profiles or approve fighters. Streaming validation precedes writes, batches commit 100 profiles, and a file hash/checkpoint makes reruns resumable and idempotent.

```sh
wp --path=/path/to/staging mmaf data-process seed --file=/private/fighter_profiles.json
wp --path=/path/to/staging mmaf data-process seed --file=/private/career-history.json --prepare
```

`--prepare` queues only the existing public/rank-approved scope. A native career export is optional; future regular exports supply stored career rows directly. Conflicting redirected source identities require review, without automatic name merging. Existing older exports may lack strong bout links or trustworthy scope evidence; persistence preserves their useful data without declaring an unverified history complete. Individual editor values, global options and media have their own controlled migrations; ACF JSON transfers field definitions only.

## Local validation

Source CI validates PHP, JavaScript, ACF JSON, shell syntax, JSON document framing and scraper regression tests before verified FTPS deployment. Integration tests require an **isolated local fixture database** and do not run against staging or production.

Provide an external WordPress bootstrap using database `mmaf_frontend_dev` (a staging clone) or `mmaf_frontend_test` and environment `local`. Store credentials and exported fixtures outside Git. The bootstrap must define `ABSPATH`, the isolated database constants, local URLs, a private import directory and load `wp-settings.php`. Disable WP cron during tests. The pipeline and performance fixtures specifically require the staging-clone database. Run database-mutating fixtures sequentially; they use enclosing transactions and rollback.

```sh
export MMAF_TEST_BOOTSTRAP=/private/local-test-bootstrap.php
export MMAF_TEST_CAREER_EXPORT=/private/career-history.json
php -d memory_limit=512M tests/integration/verify-pipeline.php
php -d memory_limit=512M tests/integration/verify-bulk.php
php -d memory_limit=512M tests/integration/verify-career.php
php -d memory_limit=512M tests/integration/verify-public.php
php -d memory_limit=512M tests/integration/verify-movers.php
php -d memory_limit=512M tests/integration/verify-contact.php
php -d memory_limit=512M tests/integration/verify-readiness.php
php tests/unit/json-document.php
```

The contact fixture simulates accepted/rejected mail transport; actual delivery is verified separately on staging. Scraper regression tests use stored/synthetic fixtures and mocked requests:

```sh
cd dev-tools/scraper
python -m pip install -e '.[test]'
python -m pytest -q
```

## Scale and retention

Private last-success profile storage grows with the fighter population rather than with every scrape; failed attempts retain compact status metadata. Canonical bout identities and participant indexes support incremental updates and paginated public histories. Private payloads are not loaded by frontend lists or administrator status columns.

Statistics and diagnostics use batches of 250 instead of loading the entire population and all fight histories together. Ranking loads career history for calculation candidates only; eligible score rows still require memory for sorting. Full rebuild time still grows with the number of canonical records. This is bounded working memory for ingestion/statistics, not a promise of constant execution time or unlimited ranking scale.

For multi-year operation, monitor canonical row counts, private import files, audit/review growth, ranking snapshots, worker duration, peak memory and slow queries. RAM helps database caching but does not replace indexes, bounded queries, background computation or a retention policy. Preserve required ranking activation/comparison history; archive historical import artifacts and review/audit history according to a deliberate retention policy. Do not delete canonical careers to save space. Disk, CPU and I/O capacity matter alongside RAM.

## Release boundary

This phase targets staging. The active v1.5 ranking remains unchanged until a reviewed manual activation. A new career coverage version starts a fresh comparison baseline; incompatible Top Movers comparisons show unavailable. Newsletter integration, a new formula, event page architecture and recursive imports of every opponent's career remain outside this release.
