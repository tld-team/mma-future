# Fighter notifications runbook

The notification module arrived with `mma-future-data-engine` schema `1.18.0`; delivery requires schema `1.19.0`. It is disabled and paused by default. Calendar imports continue to work while the feature is disabled.

## Required configuration

Add production secrets outside Git, normally in `wp-config.php` or the managed runtime environment:

```php
define('MMAF_NOTIFICATIONS_ENABLED', true);
define('MMAF_SENDER_API_TOKEN', 'sender-api-token');
define('MMAF_SENDER_FROM_EMAIL', 'notifications@example.com');
define('MMAF_SENDER_FROM_NAME', 'MMA Future');
```

On staging, also define a comma-separated recipient allowlist for sports email (announcements, reminders and admin test sends). The transport rejects every other address before making an external API request:

```php
define('MMAF_NOTIFICATION_STAGING_ALLOWLIST', 'qa-gmail@example.com,qa-outlook@example.com');
```

The allowlist does not apply to account email. A confirmation or password link goes to the one address its recipient typed, on staging too; otherwise nobody outside the allowlist could register there. The per-address and per-network limits below still apply.

Local and development environments always use the fake mailbox stored in the `mmaf_notification_fake_mailbox` option. They never call Sender directly. Sender domain verification, SPF, DKIM and the visible From address must be complete before production is enabled.

Install and configure Nextend Social Login Free separately. Create distinct Google OAuth clients or authorized callback URLs for staging and production, request only identity/email scopes, and complete Google brand verification. The custom login page displays the Google button only when Nextend's shortcode is available. Registration and sign-in with email and password work without Nextend.

While the feature is enabled, the module allows new Google registrations even when WordPress **Anyone can register** is off, so keep that setting off: `wp-login.php` registration stays closed. New Google users get the `subscriber` role. Google sign-in and email-based account linking work only for reader accounts (subscriber without `edit_posts`); a reader who registered with email and password is linked to the Google account with the same address, which was confirmed before the account was created. Staff accounts are never linked and are refused with a message. Nextend errors return to `/login/`, keeping the pending follow.

## Reader accounts

Readers sign in at `/login/` and register at `/register/` with Google or with **email and password**. The earlier sign-in by emailed link is gone; a reader who only ever used it sets a password through "Forgot password?".

- **Registration is confirmed before the account exists.** The form stores the password as a hash against a one-time link (24 hours) and emails that link. Opening it shows a button; pressing the button creates the reader, already verified, and signs them in. So there is never an account whose mailbox nobody has proved they own, and a Google sign-in can safely be linked to an existing account with the same address.
- **Answers do not reveal who has an account.** Registering an address that already has one answers the same way; the owner gets an email saying so instead. "Forgot password?" answers the same way for any address.
- **Password reset**: `/login/?forgot=1` emails a link (one hour, single use) to `/notification-action/?purpose=password_reset…`. A password that is refused leaves the link usable. A successful reset signs the reader in and ends every other session. A signed-in reader can ask for the same link from the account page, which is also how a Google account gets a password.
- **Passwords**: 8 to 128 characters, not the address and not one of a few trivial passwords. They are never trimmed or sanitised.
- **Limits** (per 15 minutes): 3 emails per address and 12 per network for registration and for reset; 5 failed sign-ins per address and 30 per network.
- **Staff** are refused on these forms and keep the WordPress login.

REST, for signed-out visitors: `POST notifications/auth/register`, `auth/login`, `auth/password/forgot`. For the signed-in reader: `POST notifications/password-link`. The two confirmation forms post to `admin-post.php` as `mmafc_register_confirm` and `mmafc_password_reset`. They are deliberately not named `mmaf_…`: admin POSTs with that prefix pass through the canonical write guard and are refused while an import or calculation runs.

### When an account email does not arrive

**MMA Future → Notifications → Account emails** lists the last 30 account emails (confirmation, "already registered", password link) with the masked recipient and what the transport answered.

- `wp_mail_failed`: no `MMAF_SENDER_API_TOKEN` is set, so the host's mailer was used and refused the message.
- A Sender error text or `provider_4xx`: the API refused it, typically an unverified sending domain or From address.
- Nothing listed: the request was stopped before sending (a staff address, or the 15-minute limit).
- `Accepted` but nothing in the inbox: the provider took the message; check spam and the provider's own log.

The staging allowlist is never the reason: account email is not held to it. `staging_recipient_blocked` appears only for sports email and admin test sends.

## Release sequence

1. Deploy the code with the feature constant absent or false.
2. Run the coordinated additive migration: `wp mmaf system migrate`.
3. Confirm schema `1.19.0`, then configure Sender and Nextend on staging.
4. Set `MMAF_NOTIFICATIONS_ENABLED=true`, keep sending paused, and verify the staging allowlist and fake/real transport boundary.
5. Open **MMA Future → Notifications**, inspect pending state, then resume sending for the internal test group.
6. Verify receipt on Gmail and Outlook, including HTML/text rendering, unsubscribe and reminder confirmation.
7. Enable the production constant and resume sending only after DNS and OAuth checks pass.

The server supervisor invokes `wp mmaf notifications work --limit=25` before the general background worker on schema `1.18.0+`. The supervisor must run every minute. A WP-Cron wake-up is also scheduled after a successful calendar transaction, but it is only a latency aid and is not the primary queue runner.

## Operational behavior

- The worker uses an atomic, expiring notification lock. Announcements keep a unique reader/local-day slot (`messages.digest_day`); reminders are sent on their own channel outside it.
- Sender calls happen after database reservation and outside the calendar transaction.
- `429` responses honor `Retry-After`; known server failures use bounded backoff; uncertain submissions require review and are not blindly repeated.
- At 80% of the configured 15,000-message monthly quota the admin page warns. At 90% sports sending pauses automatically, leaving capacity for service login mail.
- Local fake messages can be inspected in the `mmaf_notification_fake_mailbox` option. Never copy that option to production.
- Accepted by the provider, confirmed delivery and failure are distinct states. The free-plan implementation does not depend on a paid webhook.

## Email content and preview

`EmailComposer` decides what an email says; `templates/email/notification.php` decides how it looks (nested tables with inline styles, because email clients ignore stylesheets). Each email summarises the event (promotion, title, date in the reader's timezone, venue), shows both fighters with their profile photos, and ends with two calls to action: a per-fight **Remind me** button and a link back to the site. Reminder emails never offer another reminder.

**MMA Future → Notifications → Email preview** renders the announcement and the reminder with the same code, from the next upcoming fight on the calendar, and can send either to the signed-in admin as a test. Previews and tests issue no secure tokens; their action links open the account page. A test send goes through the normal transport, so on staging the admin's address must be on the allowlist, and locally it lands in the fake mailbox.

After a calendar import, the confirmation message states how many new and changed fights were recorded for linked fighters, how many readers follow them, and whether sending is paused.

## Followers overview

**MMA Future → Followers** shows who follows whom. The default tab lists followed fighters with their follower count, most followed first; the count opens the readers behind it. The second tab lists readers with the fighters they follow, their reminders, last delivered email, and whether they actually receive email (a reader who unsubscribed, switched notifications off or never verified their address is labelled as such). Both tabs can be searched and sorted.

Three CSV exports follow the current search: fighters, readers, and one row per reader–fighter pair. The files carry a UTF-8 byte order mark for Excel, and cells that a spreadsheet would run as a formula are neutralised. The page shows email addresses and is limited to `manage_options`.

## Announcements and reminders

**Announcements** (new fight, schedule or opponent change, cancellation, postponement) are batched into at most one email per reader per local day. The first one goes out right after the calendar import that produced it; anything later the same day waits for 09:00 local the next morning.

**Reminders** are opt-in per fight and are not part of that daily slot: a reminder is never delayed or dropped because an announcement already went out that day, and two fights on the same day each get theirs.

- With a confirmed start time the reminder is sent 24 hours before it. Without one there is no "24 hours before", so it is sent at 09:00 reader-local on the day before the event date.
- A reminder can only be requested while its send time is still ahead. `Reminders::in_time()` is the single rule behind every "Remind me" control (email, account page, calendar) and the server check.
- Every calendar write re-times open reminders, including a start time that moves within the same date. A reminder already sent is re-armed only if the fight moves far enough that a new one is still ahead.
- Reminders whose fight has started leave the queue as `expired`.

A fight is announced to a fighter's followers the first time it *can* be: when it first appears, when it is confirmed after review, or when a corner is linked to a fighter profile after the fight was already on the calendar. In the last case only the newly linked fighter's followers are told, and nobody is told twice about the same fight.

### Schema 1.19.0

`messages` gains `channel` (`digest` or `reminder`) and `digest_day`; the unique key moves from `(user_id, local_day)` to `(user_id, digest_day)`, which reminders leave `NULL`. `reminders` gains `event_start_snapshot`. `wp mmaf system migrate` backfills existing rows, re-times open reminders and drops the old key last, so an interrupted run repeats safely.

Releases deploy code before they migrate. Until the schema reaches 1.19.0 the feature reports itself off (no Follow buttons, no sending) instead of querying columns that do not exist; calendar changes are still recorded and are sent after the migration. **Do not roll back to a release older than this one after migrating:** the old code relies on the dropped key to keep one message per day.

## Restore and privacy

The notification tables use the normal WordPress table prefix and are included by full-database backup/restore. After any restore, set `mmaf_notifications_paused=1` before releasing the application, inspect the queue and only then resume; this prevents restored jobs from being sent again.

Message bodies are cleared after 30 days, delivery attempts after 90 days and expired tokens after their cleanup grace period. Raw tokens are never stored. WordPress personal-data export and erasure callbacks include subscriptions, reminders and preferences while retaining only the minimum delivery record required to prevent duplicates.

## Verification

Run the database integration checks in an isolated local WordPress database:

```bash
php -d memory_limit=256M tests/integration/verify-upcoming-calendar.php
php -d memory_limit=256M tests/integration/verify-notifications.php
```

The notification check covers multi-fighter following, both participants in one bout, first-follow announcements, the one-message daily slot, deferred changes, hashed one-time tokens, timezone-slot protection, an atomic worker lock, local fake transport and a synthetic 1,000-subscriber lookup.
