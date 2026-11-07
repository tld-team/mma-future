# Fighter tracking and ranking disposition

Canonical fighters now have one tracking decision that is independent from publication and ranking approval:

- `normal` follows the existing preparation, review, publication and ranking flow.
- `paused` is temporarily outside ranking. A confirmed basic professional profile may remain public. A new completed professional bout after the last tracking check makes the fighter eligible for one fresh profile/statistics check and, if the rules pass, creates an admin return candidate.
- `opponent_only` is permanently outside ranking, but retains its canonical ID, Tapology identity, aliases, bouts, prefight records and audit history. A confirmed basic professional profile may be public and searchable.

Changing a mode requires a reason. An evidence URL is stored when available. All changes are audited. Returning to `normal` leaves `is_public=0`, `is_rankable=0` and `rankability_status=pending_review`; publication and ranking still require the existing admin approval flow.

Confirmed UFC signings, an exact age above the formula limit, and an accepted professional record above the career-fight limit automatically set `opponent_only`. Inactivity and loss-limit exclusions use `paused`. Missing facts, a birth year without an exact date, incomplete records, and ambiguous evidence are unresolved rather than permanent exclusions. The importer makes the same decision after a successful profile is saved.

Public queries expose confirmed public profiles independently of current ranking status. Ranking lists, profile score/rank fields, cards, movers, new entries and ranking API responses additionally require the current ranking disposition. An out-of-ranking profile therefore has no live score or rank even if an older active snapshot still contains it.

For the existing database, first inspect a small read-only slice, then continue the resumable approved pass on staging:

```bash
wp mmaf fighter ranking-disposition --limit=50
wp mmaf fighter ranking-disposition --apply --limit=100
```

Repeat the second command until it reports `completed: true`. `--reset` deliberately restarts a completed pass. The normal background preparation worker also checks a bounded rotating slice for date-based transitions, without scraping.

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

Statistics generations retain an `opponent_only` aggregate only while its accepted bout inputs are unchanged; a new or corrected fight refreshes the public statistics without reopening ranking. They retain a paused fighter's aggregate while no new completed professional bout exists. A missing aggregate is calculated once. Generation summaries report:

- `stats_rows_carried`
- `stats_rows_calculated`
- `paused_fighters_checked`
- `paused_profile_refresh_pending`
- `return_candidates`

Ranking calculations exclude every non-`normal` fighter. Activation rejects an older draft if it contains a fighter whose current tracking mode is no longer `normal`.

Before applying decisions to production data, take the normal verified backup. Use **Review → Tracking & Returns** for the read-only proposal list, then apply decisions from the fighter screen or the explicit-selection bulk controls so the audit trail and cache invalidation run normally.
