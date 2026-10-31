# Career records and Formula v1.7

Formula v1.7 uses a hybrid professional-MMA score. The accepted full-career W/L aggregate supplies base points, while finish and opponent-quality adjustments apply only to accepted results from **9 May 2026 through the ranking reference date, inclusive**. Import and first-seen dates never decide the scoring period. Formula v1.5 and v1.6 runs keep their frozen behavior and remain readable/activatable.

## Score contract

```text
score = 0.5 × career wins
      − 1.5 × career losses
      + recent finish points
      + recent win-opponent points
      + recent loss-quality points
      + one age adjustment
```

Draws and no contests contribute zero but are included when splitting the aggregate. The historical record is the accepted career record minus every accepted recent W/L/D/NC result. Career and period observations must share the same source content hash (or, for a protected manual correction, the same verified snapshot date). Negative components, source evidence newer than the reference date, confirmed older results exceeding the derived historical aggregate, or a mismatch between accepted recent W/L results and scoring rows blocks the final score; values are never clamped to zero.

`career_record`, canonical `recorded_stats`, and the frozen `scoring_assessment` remain separate facts. Career aggregates are scoring inputs only: they are not added to canonical statistics and do not create synthetic bouts. Old opponent and method details are intentionally irrelevant to v1.7.

Age is applied once. Recent wins receive the existing KO/TKO/submission bonus and pre-fight opponent differential. Recent losses receive the existing loss-quality adjustment. A current opponent record is never substituted for the record immediately before the fight.

## Evidence and eligibility

A main v1.7 position requires:

- an accepted professional-MMA career aggregate and complete source coverage through the May 9 boundary (or reliable history exhaustion);
- successful career and period verification no older than seven days at calculation time;
- exactly one scoring row for every accepted recent W/L result;
- verified opponent pre-fight W/L values for every recent W/L result; a stored differential alone is not evidence and a contradictory differential is a review conflict;
- a known method for every recent win;
- at least one career win or loss and all existing age, career-fight-count, loss-limit, UFC, tracking, rankability and publication rules.

An unknown method on a recent loss does not block the result. A fighter with no recent fight may rank when the source positively confirms the empty period and supplies a last professional fight date for the 24-month activity rule. `sample_size` continues to mean recent W/L scoring rows and may therefore be zero.

Incomplete calculations have `score`, `raw_score`, and assessment `total_score` set to `null` and receive no main position. `known_points` may expose the sum of currently known components, but it is explicitly provisional and is not a lower bound.

Tie-break order is recent wins, recent-win finish rate, younger age, latest confirmed professional fight date, then fighter ID. With no recent wins the displayed finish rate is `—` and its sorting value is zero.

## Storage and system behavior

Formula v1.7 has scope `professional_mma_hybrid_20260509_v1`. Each run freezes the formula config, accepted career observation, derived historical record, recent record, source evidence, scoring rows, breakdown, reasons and input hash in the existing run/snapshot/assessment storage. No new database table is required.

Direct and bounded calculation use the same evaluator and sorter. Activation rejects a main snapshot unless its immutable v1.7 assessment is complete, confirmed, reason-free and numerically identical. The first v1.7 activation is a Movers methodology boundary; Movers resumes after a second activation with the same version, scope and config.

The public API retains `main` and `provisional` routes. v1.7 adds `historical_record`, `period_record`, `historical_base_points`, `period_base_points`, `career_base_points`, `known_points`, completeness reasons and the no-recent-fights flag. All public surfaces use the same label:

> Osnovni poeni za celu karijeru; završnice i kvalitet protivnika od 9. maja 2026.

The live career record may advance after activation; the score explanation always uses the career snapshot frozen in the active run.

## Import and rollout

The established cycle → finalize → ZIP → dry-run → approved import → preparation → statistics → inactive draft flow remains unchanged. Preparation fingerprint `career_hybrid_tracking_v3`, scraper readiness policy `import_hybrid_evidence_v2`, and the calculation-policy hash force re-evaluation under v1.7. Delta imports created against an older policy require a new full base/receipt.

Readiness treats missing recent pre-fight records and missing recent-win methods as blockers. Old history gaps remain separate and do not block the hybrid score when the accepted aggregate and recent-period proof are sound. Failed fetches never refresh the successful observation date; manual protections and publication/ranking approvals are preserved.

Rollout steps:

1. Run deterministic formula tests and the isolated parser → package → import → preparation → draft → activation suite on the exact release commit.
2. Capture the active run/hash and a verified backup. Deploy code without changing the active ranking.
3. Pilot the current and previous top 25, ranked conflicts, and verified fighters with no recent bouts.
4. Enrich approved candidates in bounded batches. Review missing career facts, recent pre-fight records, recent-win methods, scoring mismatches, gender and category gaps.
5. Create a full import base/receipt under the new policies and calculate an inactive v1.7 draft.
6. Independently recompute every main score from its frozen career/recent inputs and review entries, exits and category changes.
7. Activate manually. Keep a supported, currently valid prior run available; before deploying older code, reactivate a run that code can safely read and re-check UFC/tracking exclusions.
