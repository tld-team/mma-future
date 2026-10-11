# MMA Future administration and staging operations

This delivery focuses on preparation, resumable calculations and verified staging releases. Automated archival and destructive retention remain disabled. Production deployment and ranking activation are separate decisions.

## Administrator workflow

1. Open **MMA Future → Fighters**. Combine the import, preparation and status filters. `Legacy / unknown import` means there is no saved evidence of historical membership.
2. Select individual fighters (up to 100), the page, or all results of the current filter. Choose **Approve & prepare** and open its preview.
3. Review the exact frozen selection, exclusions and blockers. Confirm that manifest. Later imports cannot silently enter it.
4. Follow **Operations**. Preparation uses saved profiles, preserves verified manual facts, checks source identity, imports accepted career rows, rebuilds statistics and checks eligibility before publication.
5. Inspect review items through their fighter/import/operation links. A documented partial career stays partial. A conflict is reviewed individually; technical failure can be retried. A public profile can be approved without ranking eligibility.
6. A completed scope produces one inactive ranking draft. Review it separately. This workflow does not activate it.

Pause stops future units; completed changes remain recorded. Resume continues checkpoints. Cancel does not undo already committed changes. Retry targets technical failures and rechecks current inputs. Historical jobs copied to local cannot be resumed as staging jobs.

The preview separates **saved-profile readiness** from **current ranking checks**. An available profile does not guarantee ranking eligibility. Current blockers appear beside each fighter; preparation may change the input, so final checks run again before publication. **Check selected without changes** does not apply public/verified/rankable statuses.

## Worker and diagnosis

The staging supervisor owns MMA Future processing; WordPress core cron runs separately. Preparation is bounded to ten fighters; calculation steps to 250 rows and a byte budget. Do not launch competing worker scripts.

Check **Operations / Health** for status, phase, heartbeat, progress and error reason. No heartbeat for five minutes is a warning, ten minutes is critical. Heartbeat without progress may still indicate a stalled job. Critical mail is sent to `luka.m@tldteam.com`; actual receipt must be verified during staging acceptance.

Before retrying, resolve an identity or source-data conflict. A repeated conflict is not a transport error. Preserve the original saved profile and import evidence.

## Staging release

Development stays local. `main` publishes staging after CI validation and a deterministic build. The restricted FTPS account uploads only a complete code package and its ready marker into private incoming storage.

The private runner verifies checksums and schema compatibility, waits for the worker checkpoint, prevents new writes, creates a checked backup, enters short maintenance, applies explicit migrations and verifies the site before reopening. CI success requires a receipt for the exact commit, sequence and package hash; uploaded/queued alone is not success.

Do not enable the new main workflow before the private launcher, gate, backup and compatible fallback have been installed and verified. Existing FTP secrets remain unchanged during the transition.

Private launcher commands (use the hosting configuration and installed Python path):

```text
launch.py CONFIG status
launch.py CONFIG release
launch.py CONFIG backup
launch.py CONFIG rollback --sequence VERIFIED_SEQUENCE
```

Rollback requires a previously verified release compatible with the current database schema. It restores code and removed files; it does not restore the database. Its sequence watermark never decreases. Interrupted release/rollback commands resume their saved evidence. Failed recovery keeps maintenance enabled and requires intervention.

## Backup, restore and local copies

Keep SQL, media objects, credentials and profile JSON outside Git and the public directory. A valid backup needs readable nonempty SQL, gzip integrity, checksums, table/row counts, ranking baseline and media references. Record a successful restore into an isolated database; file existence alone is insufficient.

`local-sync.py export/preview/apply` transfers a private staging snapshot with media hashes. Inspect preview before applying and preserve the current local backup. Only core WordPress tables receive URL replacement; serialized values are preserved and GUID/source identities are untouched. Local mail, automatic cron and copied work stay disabled/paused.

Automatic deletion, archive scheduling and file retention are deferred. Keep `mmaf_retention_enabled` false and do not run `retention enable` or file-retention `--apply`. A hosting-only backup remains vulnerable to loss of the hosting account; an offsite copy is a later improvement.

## Acceptance record

Record commit, schema, active ranking ID/hash, backup/restore evidence, real administrator checks and operational email receipt. Local synthetic scale results are recorded separately from measured hosting behavior. A phase is not accepted solely because unit tests pass.
