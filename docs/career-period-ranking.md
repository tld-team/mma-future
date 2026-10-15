# Career records and Formula v1.6

Plugin 2.7.0 adds schema 1.11.0. Existing ranking runs and canonical statistics keep their original meaning. Formula v1.6 uses the v1.5 coefficients with a shared scoring period: **9 May 2026 through the reference date, both inclusive**. Import dates and first-seen dates do not determine which bouts score.

## Three independent records

- `career_record`: the whole professional MMA career (W/L/D/NC), selected from an explicit protected career correction or the newest accepted source observation. Aggregates are never added to canonical fight totals. Ambiguous professional/sport context and conflicting aggregates require review. A legacy display override is not an eligibility input.
- `recorded_stats`: accepted individual results in the canonical database. The existing `stats` and ranking `record` fields retain this meaning.
- `scoring_assessment`: period statistics, accepted scoring bouts, coverage, sample size, qualification and reasons for incompleteness. Main positions require accepted career facts, confirmed period coverage, successful checks no older than seven days at calculation time, and the existing eligibility conditions. Known new or corrected results force refresh before the TTL expires.

`mmaf_career_observations` retains append-only observations and their previous selected values. `mmaf_scoring_assessments` freezes each fighter's career selection, canonical statistics, period source proof, scoring inputs, coefficients and assessment for a ranking run. Protected correction notes and source evidence stay private. Public records expose source type and actual successful check times; failed requests never update those times.

Career totals determine the 30-professional-fight limit and win/loss thresholds. Period wins, finish rate and latest fight determine tie breaks. Opponent quality always uses the stored pre-fight record. Missing methods and pre-fight records contribute the existing neutral value and produce explicit warnings. One win/loss bout is the minimum sample; fewer than three produces a small-sample warning independently of coverage. A provisional score is `null` when a score cannot be calculated, with a reason.

## Source evidence and import

The profile parser emits `career_record_evidence` and `period_evidence`. A generic “Pro Record” needs verified professional MMA context. Period evidence includes the first page, completed loading, chronological order, the May 9 boundary or reliable exhaustion, and the real check date. Missing old fight identities and old aggregate/history differences are reported separately from period problems. The destination additionally checks canonical identities, dates, reciprocal outcomes and every known period result. A freshly verified current UFC ranking widget is retained as a source eligibility input; it excludes a current UFC fighter without overwriting canonical or protected publication flags.

Persistent cache reuse is capped at seven days. A known source bout missing from the previous check, or a changed result, forces refresh even on the same day. The candidate queue carries those known results. Already-known ineligible careers can collect career facts without preparing detailed scoring history; the original DOM snapshot is retained privately for later recovery.

The existing cycle → finalize → ZIP → dry-run → import flow remains in use. Packages are immutable, imports stream records, and the bounded worker retains checkpoints. Replaying identical source observations or resuming the same calculation does not rewrite its frozen inputs.

## Public and admin views

`GET /wp-json/mma-future/v1/rankings?view=main` is the default. `view=provisional` exposes the active v1.6 run's provisional candidates with `rank` and `rank_position` set to `null`. Known ineligible fighters appear in neither prospect list; their public profiles remain available. The profile, cards and tables select the same career record and expose the saved assessment. The exact source check time remains visible.

The methodology label comes from the active run's frozen config. A legacy v1.5 run is not labelled with the May 9 period. Movers reports `methodology_changed` across different methods/scopes and compares only subsequent compatible activations. Old snapshots can be reactivated through the normal activation service without downgrading the schema.

The fighter editor separately shows career facts, recorded statistics and current period tasks. An explicit professional MMA correction requires counts, source evidence, a real verification time, reason and editor confirmation. Rankings admin previews show main, provisional and ineligible saved assessments with their points and next tasks.

## Rollout procedure

1. Run the Python/PHP fixtures and real parser → ZIP → import → assessment → inactive draft integration on an isolated database. Check CI on the exact commit.
2. Record the active run and ranking hash, fresh hosting capacity, and a restorable backup. Publish through the verified `main` release workflow. Verify schema 1.11.0 and plugin 2.7.0, and that the active ranking hash is unchanged.
3. Export the first 25 ranked fighters plus ranked career conflicts to a private file:

   ```sh
   wp mmaf scoring candidates --pilot=25 --output=/private/rollout/pilot.json
   ```

4. Fetch fresh source evidence with the existing queue enrichment command, adopt each declared run into a cycle, catch up event results after the prior packet's October 4 boundary, and finalize an immutable package. Review the readiness report and canonical dry-run before import. Preserve the last successful data when a source fails.
5. Import through the existing bounded worker. Export the approved public/rankable candidate population with `wp mmaf scoring candidates --output=/private/rollout/candidates.json`. Expand in bounded source batches. The full candidate export uses `career_record` for candidates without accepted career facts and for careers exceeding 30 fights. Import those observations, then export candidates again: accepted careers within the limit switch to `career_and_period`. Pilot exports request both directly. Failed source attempts retain the previous successful input and check time.
6. Calculate an inactive draft using the existing resumable ranking operation. Export its report:

   ```sh
   wp mmaf scoring report --run-id=NEW_DRAFT_ID --output=/private/rollout/draft-report.json
   ```

7. Review ready/provisional/ineligible counts, unresolved tasks, scores and the consequences of the new scope. **Activation is the final manual step.** Keep the previous active run available for reactivation. Code/database recovery uses the existing verified backup/recovery workflow; never manually lower the schema option on a migrated database.
