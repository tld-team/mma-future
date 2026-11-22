# Fighter gender

Tapology has no gender field. Gender is taken from these sources, strongest first. A stronger source may correct a weaker one; nothing ever overwrites a manual value.

| # | Source | Provenance `source_type` | May overwrite |
|---|---|---|---|
| 1 | Admin edit on the Fighters screen | `manual_verified` | everything |
| 2 | The fighter's own "Regional MMA Rankings" text on the Tapology profile: `He/She is only ranked in regions where he/she has fought…`, and for women the `Pound for Pound Women` line | `tapology_profile_explicit_gender` | 3 and 4 |
| 3 | At least two opponents whose gender comes from 1 or 2, all agreeing | `inferred_opponent_gender` | empty only |
| 4 | Weight class, and only "male" for welterweight and heavier | `tapology_profile_inferred_weight_class` | empty only |

A weight class never yields "female": Tapology lists men at Strawweight too, and women fight at Lightweight.

## What the scraper emits

`profile_summary.gender` and `profile_summary.gender_evidence` (`pronoun`, `women_p4p_ranking`, `female_grouping_note`, `status`, `excerpt`); see `dev-tools/scraper/SCRAPER_DATA_CONTRACT.md`. Fighters outside the regional rankings (pre-debut, inactive, major organization) carry no pronoun, so `gender` is `null` for them. Measured on 1,414 saved profile captures: 1,260 male, 82 female, 72 without a signal, no contradictions.

Cached profiles parsed before the field existed are queued again with `stale_reason` `gender_evidence_missing`, Strawweight and Atomweight first.

## Profiles cached before the field existed

Two offline commands; neither contacts Tapology.

```
python -m mma_future_scraper.cli backfill-gender-evidence            # dry run
python -m mma_future_scraper.cli backfill-gender-evidence --apply
python -m mma_future_scraper.cli build-gender-evidence-queue --output /path/to/gender-evidence-queue.json
```

`backfill-gender-evidence` reads the evidence from the newest page capture saved under `data/runs/*/profile_snapshots/` and writes only `gender` and `gender_evidence` into `data/latest/fighter_profiles.json`. With `--apply` the previous file is kept next to it as `fighter_profiles.json.bak-gender-<time>`. A capture that landed on another fighter's page is ignored.

`build-gender-evidence-queue` writes a standard fighter queue of the profiles that still have no evidence and whose weight class cannot settle the gender: Strawweight and Atomweight first (95), then profiles without a weight class (90), then Flyweight, Bantamweight, Featherweight and Lightweight (70); the most recently active fighters first inside each group. Every item asks for the scope the cached profile already has, so a whole-career history is never replaced by a record-only profile. `--tracking-decisions` applies the same policy as the regular queue. Feed the file to the profile run with `--queue-file`.

### Queue only what WordPress still needs

Fetching every cached profile is slow, operator-attended work. WordPress knows which fighters actually lack a gender:

```
wp mmaf fighter gender-targets --output=/private/path/gender-targets.json
python -m mma_future_scraper.cli build-gender-evidence-queue \
  --targets /private/path/gender-targets.json --output /path/to/gender-evidence-queue.json
```

The export is read-only and lists fighters without a gender (`missing`) and women whose gender was only inferred (`inferred_female`), by default for statuses `provisional,verified` and tracking mode `normal` (`--status=provisional`, `--include-untracked`). A gender an admin set or cleared is not listed, and neither is a conflict: that one needs a person.

With `--targets` the queue holds only the listed fighters, whatever their weight class: inferred women first (95), then fighters without a gender (90), then listed fighters that have no cached profile yet (85). The report also says how many targets the cache already answers (`targets_resolved_by_cached_evidence`; the next import settles them) and lists the ones whose page was read and states no gender (`targets_needing_manual_gender`): another fetch would say the same, so those are for manual entry.

## What the importer does

- Gender is settled before the weight class, because the division a label means depends on it: a female Flyweight is `women_flyweight`; a male Strawweight is stored as `flyweight`; a Strawweight of unknown gender gets no division.
- An empty gender is filled. A gender that only source 3 or 4 put there is corrected when the profile states the other one (audit action `fighter_gender_corrected`), and the weight class follows.
- A manual gender, or one whose origin is not recorded, is left alone. The disagreement is kept as a conflict.

## Admin

Fighters list: `Gender: Unknown` lists fighters without a gender; `Gender source` filters by *Confirmed by Tapology*, *Inferred, unconfirmed*, *Set manually*, *Conflicts with Tapology*. The fighter edit screen shows the source under the Gender field.

## Reconcile command

```
wp mmaf fighter gender-reconcile            # read-only report
wp mmaf fighter gender-reconcile --apply
```

In order: re-applies stored profiles that state a gender to fighters whose gender is empty or only inferred; applies the opponent consensus; clears the `women_strawweight` label on bouts with a confirmed male participant. It also reports fighters whose trusted opponents have the other gender. The opponent consensus runs only through this command, not during an import.
