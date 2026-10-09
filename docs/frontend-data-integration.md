# Frontend data integration and fighter preparation

## Data flow

The public frontend reads canonical data through `PublicReadService`, shared by PHP rendering and REST controllers. Editorial text, links and media use versioned ACF groups. No public block fetches WordPress's own API to render its initial content.

Regular scraper ZIP imports persist the last successful complete profile payload privately for every identified source fighter, including provisional fighters. Each payload retains its own fetch date; the merged export date is not a substitute. Older packages and failed requests cannot replace a newer successful payload. Contradictory identities or equally dated contradictory content enter review. Source payloads and internal recipients are never exposed through the public API.

Regular import processes event results, stores profile snapshots, applies safe factual enrichment, prepares already approved careers in bounded batches, rechecks shared career coverage, rebuilds statistics and requests one inactive ranking draft. Manual decisions protect verified values. Evolving source-owned fields refresh only with newer evidence and matching provenance. A fighter approval uses the existing stored payload and makes no source HTTP requests.

Professional career rows share the existing events, bouts and participants. Strong source IDs prevent duplicate bouts across two fighter profiles and repeated packages. A partial event package does not delete unrelated bouts. W/L/D/NC are counted separately; amateur, cancelled, future and non-MMA entries stay excluded. Missing historical pre-fight records remain unknown.

Coverage `complete` requires verified professional MMA scope, source exhaustion, matching W/L/D/NC and no unresolved conflicts. Shared final-result corrections revalidate earlier coverage. Partial/unavailable/conflicting profiles remain explicit. A source's aggregate record does not silently replace calculated statistics.

## Administrator workflow

1. Import and inspect a regular ZIP in **MMA Future → Import**. Importing does not approve all discovered fighters.
2. Open **Canonical Fighters → Review new import candidates**. This filters provisional, nonpublic Tapology candidates and orders them by creation date. It is a candidate view, not a claim that every row is from the last package. Use search, filters and 25/50/100 rows per page to narrow the selection.
3. Select reviewed fighters on the current page. **Check selected without changes** previews the combined decision. **Approve & prepare (verified, public, rankable)** executes all three approvals, synchronizes published canonical profiles and queues stored data preparation. Select-all affects the current page only; the counter reports the exact selection. Each request is capped at 100 fighters; background preparation handles 10 per batch by default.
4. The result reports every updated, blocked and unchanged fighter with reasons. Missing birth/weight/history data, hidden/deleted/merged/retired states and v1.5 eligibility failures remain blocked. Individual verified/public/rankable controls remain available for decisions that should not be combined.
5. Read **Stored source / preparation** and the preparation notice. They show the per-profile source date, career coverage, failed source attempts, processing failures and draft ID. Failed preparation is retryable; incomplete source evidence requires review. No repeat scraping happens during approval.
6. Review the resulting ranking draft and activate it separately. Approval is an administrative permission, not a guarantee of formula eligibility after complete history changes. No import or worker activates a ranking.

Public profiles can display recorded fights before a career is confirmed complete. Known zero values stay zero; unknown values stay null. The active ranking's calculation/activation dates and current statistics date remain separate.

## Worker and one-time migration

The existing supervised runner runs regular import first, then stored profile preparation, under the same write coordination. Use a server cron every minute with the runner's three arguments: WordPress root, PHP executable and WP-CLI executable. Keep logs outside the web root. WordPress also schedules bounded preparation as a fallback; an external cron provides predictable processing without visitor traffic.

```sh
bash wp-content/plugins/mma-future-data-engine/bin/regular-import-runner.sh /path/to/staging /path/to/php /path/to/wp
wp --path=/path/to/staging mmaf data-process status
wp --path=/path/to/staging mmaf data-process work --limit=10
```

After backing up the target database and deploying code, seed already existing private exports once. This copies their last-success snapshots, not the local database, and does not fetch profiles or approve fighters. Streaming validation precedes writes, batches commit 100 profiles, and a file hash/checkpoint makes reruns resumable and idempotent.

```sh
wp --path=/path/to/staging mmaf data-process seed --file=/private/fighter_profiles.json
wp --path=/path/to/staging mmaf data-process seed --file=/private/career-history.json --prepare
```

`--prepare` queues only the existing public/rank-approved scope. A native career export is optional; future regular exports supply stored career rows directly. Conflicting redirected source identities require review, without automatic name merging. Existing older exports may lack strong bout links or trustworthy scope evidence; persistence preserves their useful data without declaring an unverified history complete. Individual editor values, global options and media have their own controlled migrations; ACF JSON transfers field definitions only.

## Local validation

Source CI validates PHP, JavaScript, ACF JSON, shell syntax, JSON document framing and scraper regression tests before FTP deployment. Integration tests require an **isolated local fixture database** and do not run against staging or production.

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
