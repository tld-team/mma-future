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
