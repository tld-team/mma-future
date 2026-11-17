# Community runbook

Community turns a reader account into an identity: a public profile now, then the Fan Top 10, credibility levels and article comments in later releases. The module lives in `wp-content/plugins/mma-future-data-engine/includes/Services/Community/`. It is additive and off by default; with the switch off the site behaves exactly as before (`tests/integration/verify-community-off.php` holds that line).

## Switches

```php
define('MMAF_COMMUNITY_ENABLED', true);
```

Community is on only when all of these hold: the constant is `true`, the schema has reached `1.20.0`, and reader accounts (`MMAF_NOTIFICATIONS_ENABLED`) are on. The filter `mmaf_community_feature_enabled` can override the result.

Each area is switched separately through the `mmaf_community_areas` option (default `["profiles"]`) or the `mmaf_community_area_enabled` filter, so a later area can be deployed dark and enabled after it has been checked on staging:

```bash
wp option update mmaf_community_areas '["profiles"]' --format=json
```

## Release sequence

1. Deploy the code with the constant absent. Routes exist but answer 404; nothing else changes.
2. The release runner migrates to schema `1.20.0` (`wp mmaf system migrate`). **The next scraper import after a schema change must be a full package**: a delta whose base was exported under the old schema is refused.
3. Define `MMAF_COMMUNITY_ENABLED` on staging and check: sign in, open `/account/` (tabs appear, notifications unchanged), `/account/profile/`, save a profile, upload and remove a photo, open `/fan/{handle}/` signed out.
4. Enable production separately.

To turn everything off again, remove the constant. Data stays in place.

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

## Routes and caching

| URL | What it is |
| --- | --- |
| `/account/profile/` | The reader's own form. Signed-out visitors are sent to `/login/`. |
| `/fan/{handle}/` | The public profile. Hidden or unknown handles answer 404. |

Both are excluded from page caches and marked `noindex` for now. The public profile prints only the profile itself; the "Edit profile" link is revealed by the script for its owner, so the page can be cached later without change.

The script reads the session (`admin-ajax.php?action=mmafc_session`) with a **GET** and takes its REST nonce from there. This must stay a GET and must not be renamed to start with `mmaf_`: admin POSTs with that prefix pass through the canonical write guard and are refused while an import or calculation runs. For the same reason the moderation form posts as `mmafc_profile_moderate`. Community code never takes the canonical write mutex.

Rewrite rules are registered on `init` at priority 9 and flushed once per `Routes::REWRITE_VERSION`, because the account routes flush from their own `init` callback at priority 10.

REST (`mma-future/v1/community/…`, signed-in reader, own profile only): `GET|POST profile`, `GET profile/handle?value=`, `POST|DELETE profile/avatar`. Validation errors answer 400 with `data.fields` keyed by form field.

During a backup or release, writes answer a plain-text 503. The form explains that, keeps what was typed and can be saved again.

## Moderation

**MMA Future → Community** lists profiles with search. A moderator can hide a profile (its public page answers 404 and its photo is replaced by the placeholder; the owner can still edit it), show it again, or remove its photo.

## Privacy

The profile is part of the WordPress personal-data export and erasure tools. Erasing a reader, or deleting the account, removes the profile, the photo file and the reader's fan list.

## Tests

- `tests/unit/community-handles.php`: handle and social-link rules.
- `tests/integration/verify-community-schema.php`, `verify-community-profiles.php`, `verify-community-off.php`: need the CI database.
- `tests/frontend/community.test.cjs`: the profile form in jsdom.
