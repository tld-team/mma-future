# Fighter tracking and archiving

Canonical fighters now have one tracking decision that is independent from publication and ranking approval:

- `normal` follows the existing preparation, review, publication and ranking flow.
- `paused` stays private and unranked. A new completed professional bout after the last tracking check makes the fighter eligible for one fresh profile/statistics check and, if the rules pass, creates an admin return candidate.
- `opponent_only` stays private and unranked permanently, but retains its canonical ID, Tapology identity, aliases, bouts, prefight records and audit history.

Changing a mode requires a reason. An evidence URL is stored when available. All changes are audited. Returning to `normal` leaves `is_public=0`, `is_rankable=0` and `rankability_status=pending_review`; publication and ranking still require the existing admin approval flow.

Confirmed UFC signings automatically set `opponent_only`. Correcting an erroneous UFC confirmation clears `in_ufc`, but leaves the fighter excluded with `ufc_confirmation_corrected_pending_review` until an administrator explicitly changes the tracking mode. Age and career-fight limits appear as read-only proposals under **Review → Tracking & Returns**. Inactivity and loss-limit proposals use `paused`. Missing facts and ambiguous evidence are not permanent exclusions.

Public queries only expose fighters with `is_public=1`. In another fighter's history an archived opponent's display name remains, while the profile link is omitted. Stored prefight records and activated ranking snapshots are unchanged by archiving.

## Private scraper manifest

Export the current WordPress decisions:

```bash
wp mmaf fighter tracking-export --output=/private/path/tracking-decisions.json
```

Apply them when building the profile queue:

```bash
python -m mma_future_scraper.cli build-fighter-profile-queue \
  --tracking-decisions /private/path/tracking-decisions.json
```

The same manifest also applies to direct event-level profile enrichment:

```bash
python -m mma_future_scraper.cli run --enrich-fighter-profiles \
  --tracking-decisions /private/path/tracking-decisions.json
```

`opponent_only` entries are skipped. `paused` entries are skipped until WordPress exports `recheck_due=true`. Queue and direct-run summaries include `tracking_skipped_count`, `tracking_skipped_by_mode`, and the request counts before and after tracking filters. Priority exports and WordPress profile preparation apply the same policy, and an incoming package cannot overwrite the canonical tracking decision.

## Calculation behavior

Statistics generations retain the previous aggregate and its original `calculated_at` for `opponent_only` fighters. They also retain a paused fighter's aggregate while no new completed professional bout exists. A missing aggregate is calculated once. Generation summaries report:

- `stats_rows_carried`
- `stats_rows_calculated`
- `paused_fighters_checked`
- `paused_profile_refresh_pending`
- `return_candidates`

Ranking calculations exclude every non-`normal` fighter. Activation rejects an older draft if it contains a fighter whose current tracking mode is no longer `normal`.

Before applying decisions to production data, take the normal verified backup. Use **Review → Tracking & Returns** for the read-only proposal list, then apply decisions from the fighter screen or the explicit-selection bulk controls so the audit trail and cache invalidation run normally.
