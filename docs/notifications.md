# Fighter notifications runbook

The notification module is part of `mma-future-data-engine` schema `1.18.0`. It is additive and is disabled and paused by default. Calendar imports continue to work while the feature is disabled.

## Required configuration

Add production secrets outside Git, normally in `wp-config.php` or the managed runtime environment:

```php
define('MMAF_NOTIFICATIONS_ENABLED', true);
define('MMAF_SENDER_API_TOKEN', 'sender-api-token');
define('MMAF_SENDER_FROM_EMAIL', 'notifications@example.com');
define('MMAF_SENDER_FROM_NAME', 'MMA Future');
```

On staging, also define a comma-separated recipient allowlist. The transport rejects every other address before making an external API request:

```php
define('MMAF_NOTIFICATION_STAGING_ALLOWLIST', 'qa-gmail@example.com,qa-outlook@example.com');
```

Local and development environments always use the fake mailbox stored in the `mmaf_notification_fake_mailbox` option. They never call Sender directly. Sender domain verification, SPF, DKIM and the visible From address must be complete before production is enabled.

Install and configure Nextend Social Login Free separately. Create distinct Google OAuth clients or authorized callback URLs for staging and production, request only identity/email scopes, disable automatic email-based account linking, and complete Google brand verification. The custom login page displays the Google button only when Nextend's shortcode is available. Email-link login remains available without Nextend.

## Release sequence

1. Deploy the code with the feature constant absent or false.
2. Run the coordinated additive migration: `wp mmaf system migrate`.
3. Confirm schema `1.18.0`, then configure Sender and Nextend on staging.
4. Set `MMAF_NOTIFICATIONS_ENABLED=true`, keep sending paused, and verify the staging allowlist and fake/real transport boundary.
5. Open **MMA Future → Notifications**, inspect pending state, then resume sending for the internal test group.
6. Verify receipt on Gmail and Outlook, including HTML/text rendering, unsubscribe and reminder confirmation.
7. Enable the production constant and resume sending only after DNS and OAuth checks pass.

The server supervisor invokes `wp mmaf notifications work --limit=25` before the general background worker on schema `1.18.0+`. The supervisor must run every minute. A WP-Cron wake-up is also scheduled after a successful calendar transaction, but it is only a latency aid and is not the primary queue runner.

## Operational behavior

- The worker uses an atomic, expiring notification lock and a unique user/local-day message slot.
- Sender calls happen after database reservation and outside the calendar transaction.
- `429` responses honor `Retry-After`; known server failures use bounded backoff; uncertain submissions require review and are not blindly repeated.
- At 80% of the configured 15,000-message monthly quota the admin page warns. At 90% sports sending pauses automatically, leaving capacity for service login mail.
- Local fake messages can be inspected in the `mmaf_notification_fake_mailbox` option. Never copy that option to production.
- Accepted by the provider, confirmed delivery and failure are distinct states. The free-plan implementation does not depend on a paid webhook.

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
