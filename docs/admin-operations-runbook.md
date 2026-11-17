# MMA Future administration and staging operations

This delivery focuses on preparation, resumable calculations and verified staging releases. Automated archival and destructive retention remain disabled. Production deployment and ranking activation are separate decisions.

## Administrator workflow

**Save facts and confirmations** only saves the editor. It preserves approval/public/ranking decisions. Only a changed normalized fact or an explicitly checked **Confirm selected facts** receives manual protection; intentionally clearing a populated value is protected too. A photo, aliases or an unchanged Save do not request career preparation or a ranking draft. Save unsaved changes before opening an operation.

1. Open **MMA Future → Fighters**. Search, preparation and import filters are visible immediately; **More filters** contains facts, publication and ranking criteria. Active filters appear as removable labels; **Clear filters** preserves the page size. Quick views start a fresh scope. `Legacy / unknown import` means there is no saved evidence of historical membership. Fighter **Details** contains source/check dates, import evidence and WordPress links; **Ranking checks** expands the recorded blockers.
2. Select individual fighters (up to 100), the page, or all results of the current filter. Choose **Approve & prepare** and open its preview.
3. Review the exact frozen selection, exclusions and blockers. Confirm that manifest. Later imports cannot silently enter it.
4. Follow **Operations**. Preparation uses saved profiles, preserves verified manual facts, checks source identity, imports accepted career rows, rebuilds statistics and checks eligibility before publication.
5. Inspect review items through their fighter/import/operation links. A documented partial career stays partial. A conflict is reviewed individually; technical failure can be retried. **Prepare & publish profile** uses the same confirmed preparation pipeline, sets verified/public after its checks, and preserves existing ranking permission. **Approve & prepare** never silently falls back to profile-only publication after a ranking failure.
6. A completed scope produces one inactive ranking draft. Review it separately. This workflow does not activate it.

Pause stops future units; completed changes remain recorded. Resume continues checkpoints. Cancel does not undo already committed changes. Retry targets technical failures and rechecks current inputs. Historical jobs copied to local cannot be resumed as staging jobs.

The preview separates **saved-profile readiness** from **current ranking checks**. An available profile does not guarantee ranking eligibility. Current blockers appear beside each fighter; preparation may change the input, so final checks run again before publication. **Check selected without changes** does not apply public/verified/rankable statuses.

A failed first fetch means **Stored profile unavailable**. A later failed attempt retains the previous successful profile and shows both dates/statuses. Preview dates are saved for reference; a new calendar day alone does not change the content fingerprint. Final eligibility uses the current site date. Unfinished manifests from the older fingerprint format require **Create a fresh preview** and a new confirmation; completed changes stay recorded.

**Operations → Review existing fact protections** creates a paginated background evidence report. It compares existing protected facts with available saved evidence and field audits. Unknown intent stays unknown. The report never unlocks facts, modifies fighters or starts a ranking calculation. Its read failures retry at one/five minutes, then stop after three attempts.

## Ranking review

**MMA Future → Rankings** separates the live ranking from the latest calculation. **Ranking run**, **Category** and **Per page** choose a saved snapshot; selection and pagination never calculate or activate rankings. **Score breakdown** opens stored component values, and **Warning details** opens recorded source limitations. Historical formulas keep their own score contract.

**Calculation history** provides preview links and a separate disclosure for manual activation. The activation form names the exact run and retains its existing nonce and confirmation. A completed calculation with rows is technically activatable; this does not certify source quality. **Calculate Ranking Draft** queues the existing resumable calculation and opens Operations.

Statistics and ranking drafts are **manual by default**. Imports, profile preparation, UFC confirmations and edits only queue a refresh; nothing is calculated until an administrator clicks **Pokreni statistiku + ranking draft** in the MMA Future notice (CLI: `wp mmaf data-process calculate-queued --user=ADMIN_ID`). One approval produces one statistics generation and one inactive draft; later changes wait for a new approval. The age/inactivity disposition pass also runs only on request (**Proveri starost/neaktivnost** on the Rankings page, CLI: `wp mmaf data-process time-rules --user=ADMIN_ID`). The old automatic chain can be restored with the `mmaf_auto_calculation` option or filter. Explicit **Calculate** buttons, operation approvals and the import's own statistics phase are unchanged.

**Diagnostics** runs the existing detailed warning/integrity queries only after **Compute diagnostics** is requested. Counts across categories are row occurrences, not unique fighters. Large diagnostic scans are separate from ordinary paginated preview requests.

## Worker and diagnosis

The staging supervisor owns MMA Future processing; WordPress core cron runs separately. Schema 1.10.0 uses one bounded WordPress session per worker dispatch, checking the time/memory budget and release flags between existing atomic units. Preparation units process up to ten fighters (five during regular import); calculation steps retain their 250-row/byte budget. Do not launch competing worker scripts. See [the import pipeline runbook](import-pipeline.md) for cycle finalization, targeted recovery, capacity entry and destination receipts.

Readiness projection steps also stop at a three-second budget, leaving remaining rows for the next step. A human POST waits up to fifteen seconds for the current atomic unit and announces itself through `worker.lock.interactive`; system workers yield immediately when the shared lock is busy and stop at the next unit boundary while a human save is waiting, resuming on the next supervisor run. Longer frozen calculations can still reject a competing edit with a clear retry/pause message.

Check **Operations / Health** for status, phase, heartbeat, progress and error reason. No heartbeat for five minutes is a warning, ten minutes is critical. Heartbeat without progress may still indicate a stalled job. Critical mail is sent to `luka.m@tldteam.com`; actual receipt must be verified during staging acceptance.

Human CLI preview/control and fact-report commands require `--user=ADMIN_ID` with MMA Future management permission. The server supervisor uses its existing trusted system worker context. Status reads do not require a promotion authority.

Before retrying, resolve an identity or source-data conflict. A repeated conflict is not a transport error. Preserve the original saved profile and import evidence.

## Staging release

Development stays local. `main` publishes staging after CI validation and a deterministic build. The restricted FTPS account uploads only a complete code package and its ready marker into private incoming storage.

Releases require account quota evidence in private `capacity.json` that is at most one hour old. The runner separately checks filesystem space and the required backup/workspace reserve. Without fresh evidence every release fails with `Account quota is unknown or stale.`

**Automatic (recommended):** set `quota_command` in the private config to `["PYTHON", "PRIVATE_ROOT/current/cpanel-quota.py"]`. The every-minute monitor runs it and writes `capacity.json` (`source: cpanel_uapi`) from cPanel's own `uapi Quota get_quota_info`, the same figures as cPanel Statistics. cPanel's explicit unlimited value (0, shown as ∞) maps to `bytes_unlimited`/`inodes_unlimited`; a missing, malformed or failed response writes nothing, so the last evidence expires and releases stay blocked. Check it once by running the command by hand; it must print one JSON object.

**Manual fallback:** check cPanel Statistics and write `capacity.json` with the displayed disk limit/usage and inode limit/usage, `source: manual_cpanel` and the actual `checked_at` time. Keep a dated screenshot with the release evidence. Only use `bytes_unlimited: true` when cPanel explicitly displays an unlimited disk limit; an unknown limit stays unknown. A manual review expires after one hour.

A release rejected for stale quota is moved to `quarantine/SEQUENCE-COMMIT.failed` before any backup, so it can be retried: refresh the evidence, then move it back to `incoming/SEQUENCE-COMMIT.ready`. The release cron picks up the highest ready sequence within a minute.

The private runner verifies checksums and schema compatibility, waits for the worker checkpoint, prevents new writes, creates a checked backup, enters short maintenance, applies explicit migrations and verifies the site before reopening. CI success requires a receipt for the exact commit, sequence and package hash; uploaded/queued alone is not success.

If the hosting virtual host binds to a shared server IP, set `probe_destination` to that IP. The runner verifies that the destination is locally assigned and binds the request source to `127.0.0.1` (or `::1` for IPv6). HTTPS certificate verification stays enabled; private probes never follow redirects or use a proxy. The public URL receives a separate TLS/status check without the private header.

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
