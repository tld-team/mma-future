# Community runbook

Community turns a reader account into an identity: a public profile and a Fan Top 10 that feeds the fan ranking, then credibility levels and article comments in later releases. The module lives in `wp-content/plugins/mma-future-data-engine/includes/Services/Community/`. It is additive and off by default; with the switch off the site behaves exactly as before (`tests/integration/verify-community-off.php` holds that line).

## Switches

```php
define('MMAF_COMMUNITY_ENABLED', true);
```

Put the line **above** `/* That's all, stop editing! */` in `wp-config.php`, as the boolean `true` (not `'true'`). The area switches in the admin do nothing until it is set; while it is missing, **MMA Future → Community** says which condition below is not met.

Community is on only when all of these hold: the constant is `true`, the schema has reached `1.20.0`, and reader accounts (`MMAF_NOTIFICATIONS_ENABLED`) are on. The filter `mmaf_community_feature_enabled` can override the result.

Each area is switched separately, so a later area can be deployed dark and enabled after it has been checked on staging. Use the checkboxes under **MMA Future → Community → Oblasti**; they store the `mmaf_community_areas` option (default `["profiles"]`), which WP-CLI can set too. Choosing an area that needs profiles switches profiles on with it. The `mmaf_community_area_enabled` filter still has the last word.

```bash
wp option update mmaf_community_areas '["profiles","fan"]' --format=json
```

| Area | What it turns on |
| --- | --- |
| `profiles` | Profile form, public profile, account tabs. On by default once the feature is on. |
| `fan` | My Top 10, the Fans view of the rankings page, the fan-rank tag on fighter pages. Off until listed in the option. |
| `reputation` | XP, Scout levels and the octagon badge on profiles; with `fan`, props on Top 10 lists. Needs schema `1.21.0`; off until listed. |

## Release sequence

1. Deploy the code with the constant absent. Routes exist but answer 404; nothing else changes.
2. The release runner migrates to schema `1.20.0` (`wp mmaf system migrate`). **The next scraper import after a schema change must be a full package**: a delta whose base was exported under the old schema is refused.
3. Define `MMAF_COMMUNITY_ENABLED` on staging (and add `fan` to `mmaf_community_areas` for the Top 10) and check: sign in, open `/account/` (tabs appear, notifications unchanged), `/account/profile/`, save a profile, upload and remove a photo, open `/fan/{handle}/` signed out.
4. Enable production separately.

To turn everything off again, remove the constant. Data stays in place.

## Schema 1.21.0

| Table | Purpose |
| --- | --- |
| `mmaf_fan_list_ratings` | Props: one rating (1 Solid, 2 Sharp, 3 Elite) per reader per list, unique on `(list_id, rater_user_id)`. |
| `mmaf_rep_events` | One-off XP awards, unique on `(user_id, dedupe_key)`, so an award is stored once however often its trigger fires. |
| `mmaf_comment_votes` | Empty until comments arrive; created now so that release needs no migration. |

Only the `reputation` area waits for this schema (`Config::REPUTATION_SCHEMA_VERSION`). Profiles and the Top 10 keep working between the deploy and the migration. **The next scraper import after this migration must be a full package.**

## Schema 1.20.0

| Table | Purpose |
| --- | --- |
| `mmaf_profiles` | One row per reader: handle, display name, bio, social usernames, photo path, status, cached credibility points. |
| `mmaf_fan_lists` | One list per reader and board. Created empty in this release so the Fan Top 10 release needs no migration. |
| `mmaf_fan_list_items` | The ten positions of a list. |

Every table has a surrogate `id` and named unique keys (one profile per user, one owner per handle, one fighter per position). The migration verifies those keys, and `tests/integration/verify-community-schema.php` checks that a second migration has nothing left to change.

## Identity rules

Reader sign-in names are derived from the email address, so they are never printed. A profile is identified only by its `@handle` and display name.

- **Handle**: 3–20 characters, `a–z`, `0–9`, `_`, at least one letter. Reserved words (`admin`, `staff`, route names, anything starting with `mmafuture`), other readers' handles and the names of published fighters are refused. Add site-specific reserved handles with the `mmaf_community_reserved_handles` filter.
- **Changing a handle**: the first handle can be corrected once straight away; after that a change is allowed every 90 days (`mmaf_community_handle_change_days`).
- **Display name**: up to 40 characters. Readers cannot use names that read as the site or its staff.
- **Bio**: plain text, up to 280 characters. It is escaped on output and never auto-linked.
- **Social links**: Instagram, X, YouTube, TikTok, Facebook. Readers enter a username or paste their profile link; only the username is stored and the URL is composed by the site, with `rel="nofollow ugc noopener"`.

## Photos

The uploaded file is never kept. It must be a JPG, PNG or WebP of at most 2 MB (`mmaf_community_avatar_max_bytes`) and 24 megapixels; it is decoded with GD, cropped to a 256 px square and written as `uploads/mmaf-avatars/{random}.webp` (JPG where GD has no WebP). That drops EXIF data. Photos are plain files, not Media Library attachments.

Wherever WordPress prints an avatar for a reader, it uses this photo or a local placeholder, so no Gravatar request (a hash of the reader's email) is made. Staff avatars are untouched.

## Getting a new reader to a profile

Every sign-in lands on `/account/` by default, which is about email notifications. So:

- **After registering** with email and password, a reader who came with no destination of their own is sent to `/account/profile/` instead (`mmaf_account_registered_return_to`). A destination they brought, such as the fighter they wanted to follow, is kept.
- **On `/account/`**, a reader without a profile sees a "Set up your profile" invitation under the tabs. This also reaches readers who registered with Google.
- **On `/account/top-10/`**, a new account is told when its picks start to count (see "Which lists count" below), so a first save that does not move the fan ranking yet does not look broken.

## Routes and caching

| URL | What it is |
| --- | --- |
| `/account/profile/` | The reader's own form. Signed-out visitors are sent to `/login/`. |
| `/account/top-10/` | The reader's Top 10 editor (`fan` area). |
| `/fan/{handle}/` | The public profile. Hidden or unknown handles answer 404. |

Both are excluded from page caches and marked `noindex` for now. The public profile prints only the profile itself; the "Edit profile" link is revealed by the script for its owner, so the page can be cached later without change.

The script reads the session (`admin-ajax.php?action=mmafc_session`) with a **GET** and takes its REST nonce from there. This must stay a GET and must not be renamed to start with `mmaf_`: admin POSTs with that prefix pass through the canonical write guard and are refused while an import or calculation runs. For the same reason the moderation form posts as `mmafc_profile_moderate`. Community code never takes the canonical write mutex.

Rewrite rules are registered on `init` at priority 9 and flushed once per `Routes::REWRITE_VERSION`, because the account routes flush from their own `init` callback at priority 10.

REST (`mma-future/v1/community/…`, signed-in reader, own data only): `GET|POST profile`, `GET profile/handle?value=`, `POST|DELETE profile/avatar`, `GET|POST top-10` (`fighter_ids`, `revision`). Validation errors answer 400 with `data.fields` keyed by form field. `GET fan-scores?fighter_ids=` is public and may be cached for a minute.

During a backup or release, writes answer a plain-text 503. The form explains that, keeps what was typed and can be saved again.

## Fan Top 10 and the fan ranking

A reader ranks up to ten fighters on `/account/top-10/`. Only those ten positions are stored; the rest of the field is the official order. A fighter can be added only while the official ranking lists them, and stays on the list afterwards (a fighter who signs with the UFC keeps their place, marked "Signed with the UFC", and stops scoring). The reader's Top 10 is shown on their public profile as a fight card: #1 as the main event, #2 and #3 as co-main events, then the rest, each with its distance from the official rank (▲ higher, ▼ lower, = the same). The profile photo sits in an octagon coloured like the reader's level. After saving, the editor shows the profile link with a copy button, or invites a reader without a profile to set one up.

**Points.** Positions 1 to 10 are worth 25, 18, 15, 12, 10, 8, 6, 4, 2 and 1 (`mmaf_community_fan_points` takes a list of ten). A fighter's fan score is the sum over every counted list; ties fall to more first places, then more lists, then the better official rank.

**No stored totals.** The standings are one `GROUP BY` over `mmaf_fan_list_items`, cached through `PublicCacheService` under the `mmaf_fan_revision` option, which every save rotates. So the standings follow each save, cannot drift from the lists, and need no recalculation when an account is removed, a reader is excluded or a fighter leaves the ranking. Saving a list does **not** rotate `mmaf_public_data_revision`: that would purge the whole page cache on every save.

**Which lists count.** A list counts when its owner is a reader with a confirmed email (staff can keep a list; it is stored as `unlisted`), the account is at least 24 hours old (`mmaf_community_fan_min_account_hours`), and the reader's profile is neither hidden by a moderator nor marked `shadow`. A fighter is ranked only while the official ranking would list them.

**Boards.** A fighter has one fan score. The division, gender and age views are the overall standings narrowed to the fighters on that official board and renumbered.

**Saving.** One transaction: lock the list row, compare the revision, replace the positions (keeping `picked_at` for fighters that stay), advance the revision. A save from an older copy answers 409 and the editor offers to reload; an unsaved order is kept in the browser and restored after a reload. The editor works with buttons and the arrow keys; dragging is an enhancement built on pointer events, with no third-party library.

### Where the theme calls in

Three guarded calls, each a no-op while the `fan` area is off, so the official ranking renders exactly as before:

| Theme file | Call |
| --- | --- |
| `inc/public-render.php`, `mmaf_render_rankings()` | `FanBoard::requested()` and `FanBoard::render()`: the Fans view replaces the official section for `?mmaf_ranking=fans` |
| `template-parts/integration/all-rankings.php` | `FanBoard::source_toggle( 'official' )`: the Official / Fans switch |
| `template-parts/integration/fighter-hero.php` | `FanBoard::chip( $fighter_id )`: a hidden placeholder the script fills with "Fan rank #N" |

The Fans view is rendered by the plugin with plain links for division and page, and is never page-cached. The theme's own ranking templates, script and stylesheet are unchanged, so the theme needs no rebuild. Fighter pages stay cached: the tag is filled from the public `fan-scores` route.

## Credibility: XP, levels and props

`XP = earned points + tenure`. Earned points are cached in `mmaf_profiles.rep_points` and recalculated from the stored facts whenever they change, so the cache cannot drift; tenure is added when read.

| Source | XP |
| --- | --- |
| Complete profile: photo, bio and a display name other than the handle | +10, once |
| First full Top 10 | +15, once |
| Props on your Top 10: Solid / Sharp / Elite | +1 / +2 / +3 per reader; they can change or withdraw it |
| Tenure | +10 for every full three months since registration, at most 80 |

Levels: 1 Spectator 0 · 2 Spotter 25 · 3 Scout 100 · 4 Senior Scout 300 · 5 Head Scout 750 · 6 Matchmaker 2000 · 7 Oracle 5000. Earned points never go below zero. Tenure alone cannot reach Scout.

**Badge.** An inline SVG octagon (`Badge::svg()`): the level number in the middle, and one side lit for every eighth of the way to the next level. Steel for levels 1–2, brand blue for 3–6, gold for Oracle. It shows on the public profile (with the XP bar) and on the reader's own profile form, with where the XP came from.

**Props.** A reader gives props to another reader's Top 10 from their public profile. Only readers at level 2 or above may give them (a complete profile and a full Top 10 are enough), never to their own list, at most 30 changes an hour. Staff accounts neither give nor receive props. The totals are printed in the page; the viewer's own rating and the controls are loaded by the script.

**Awards that existed before levels** are granted the next time the reader saves, opens their profile form or the `reputation` route.

REST (`mma-future/v1/community/…`): `GET reputation` (the signed-in reader's level and breakdown); `GET props?handle=` (public totals, plus the viewer's rating and whether they may rate); `POST props` (`handle`, `value` 1–3); `DELETE props?handle=`. A refused rating answers 400 with the reason in `data.fields.props`.

## Moderation

**MMA Future → Community** lists profiles with search, their level and XP, and exports them as CSV. Below the list: the top of the fan ranking, the readers with the most XP, and who gave the most props in the last seven days (where accounts rating each other show up).

A moderator can **exclude a profile from ranking** (status `shadow`): the page stays public and its owner notices nothing, but their Top 10 and the props they give stop counting. A moderator can also hide a profile (its public page answers 404, its photo is replaced by the placeholder and its Top 10 stops counting; the owner can still edit it), show it again, or remove its photo.

## Privacy

The profile is part of the WordPress personal-data export and erasure tools. Erasing a reader, or deleting the account, removes the profile, the photo file, the reader's fan list, their XP awards and the props they gave or received; the lists they rated lose those props. The export includes the level and how many props the reader gave.

## Tests

- `tests/unit/community-handles.php`: handle and social-link rules. `tests/unit/community-fan-points.php`: the points schedule and the order of the standings. `tests/unit/community-levels.php`: levels, tenure and the badge.
- `tests/integration/verify-community-reputation.php`: awards, who may give props, moderation, tenure, REST and erasure.
- `tests/integration/verify-community-schema.php`, `verify-community-profiles.php`, `verify-community-fan.php`, `verify-community-off.php`: need the CI database.
- `tests/frontend/community.test.cjs` and `community-editor.test.cjs`: the profile form and the Top 10 editor in jsdom. Dragging itself is not covered there (jsdom has no layout); check it by hand on a phone and a desktop.
