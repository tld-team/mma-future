# Presentation restoration

The visual reference is the theme at `ba440be`, checked against the private backup from before integration. The original markup, spacing, cards, icons and section classes are reused with ACF editorial values and canonical public data. The reference contains prototype content; prototype people, partnerships, scores and results are not seeded as facts.

## Section map

All restored views live in `wp-content/themes/mma-future/template-parts/integration/`. `inc/presentation.php` supplies small presentation helpers. The existing block entry points and automatic canonical fighter template call the same renderers.

| Previous block / area | Restored view | Data preparation |
| --- | --- | --- |
| Short Ranking Overview | `home-rankings.php` | `mmaf_render_rankings(false)`, active board and activation comparison |
| All Rankings Table | `all-rankings.php`, `rankings-rows.php`, row/card/detail partials | `mmaf_render_rankings(true)`, shared public service |
| Single Fighter Hero | `fighter-hero.php` | Shared fighter context, canonical facts, media and active snapshot |
| Single Fighter Quick Stats | `fighter-stats.php` | Shared fighter context and canonical statistics |
| Single Fighter Latest Fights | `fighter-latest.php`, `latest-card.php` | Latest confirmed professional fights |
| Single Fighter Full Fight History | `fighter-history.php`, `history-card.php` | Paginated public history |
| Single Fighter Bio | `fighter-bio.php` | ACF editorial content and canonical facts |
| About Hero | `about-hero.php` | ACF block values |
| About Featured | `about-features.php` | ACF features and decorative icons |
| About How It Works | `about-how.php` | ACF steps/principles, optional active score example |
| About Highlighted Data | `about-data.php` | ACF source/update/limitation text |
| About FAQ | `about-faq.php` | ACF title and global FAQ scope |
| About Founder Bio | `about-founder.php` | Explicit editorial identity and media |
| About Our Team | `about-team.php` | ACF members and contributor links |
| About Partners Logos | `about-partners.php` | Global confirmed partner list |
| About CTA | `about-cta.php` | ACF text and links; the old empty template is now populated |
| Contact Main | `contact.php` | ACF block values/public contact settings, existing secure handler |
| Footer | `footer.php` | ACF descriptions/titles, registered WP menus and public contacts |
| Article relations / upcoming events | `article-fighters.php`, `article-events.php` | Canonical public fighter IDs and actual events |
| Header Top Bar | `mmaf_render_top_bar()` | Saved global messages, labels, links and duration |
| Home Hero, Highlights, latest blog cards | Existing original block templates | Preserved ACF keys and WP queries |

The old per-section fighter and rankings styles are now in `assets/src/css/integration.css`, copied to dist by `npm run tw_build`. The Tailwind build scans the restored templates and emits `assets/dist/css/output.css`. Do not replace the Tailwind output with the unrelated legacy SCSS build. The original fighter background is bundled locally in the theme.

## Behavior and migration

- Desktop rows and mobile cards use the same canonical result array. Board search, sorting and Top 15 run before pagination and retain canonical rank numbers.
- Initial content and filter links render on the server. JavaScript enhances each block instance separately; even copied saved block IDs receive unique presentation prefixes.
- REST fragments reuse the row/card/history templates. Their links return to the public page with current filters. Private source payloads and mail recipients remain excluded.
- Missing facts use the existing frame with a neutral avatar or an unknown marker. Known zero remains zero; percentages are ratios multiplied by 100. v1.5 scores are direct totals, including negative totals.
- No numeric score delta, invented opponent score, prototype results, or forced partner/founder identities are restored. Editorial empty values stay empty.
- Top Bar and partner motion pause on hover/focus and respect reduced motion. A single saved message/partner is static. Duplicated marquee content is hidden from assistive technology.
- Contact retains real server validation, mail handling, errors and the `admin-post` fallback. The reason choices are restored as radio cards. JavaScript preserves input after failure.

After a database backup, preview both content operations and review their reports before applying on staging:

```sh
wp --path=/path/to/staging mmaf frontend migrate
wp --path=/path/to/staging mmaf frontend repair-content
wp --path=/path/to/staging mmaf frontend migrate --apply
wp --path=/path/to/staging mmaf frontend repair-content --apply
```

`migrate` seeds only missing fields; it does not overwrite deliberate empty values. `repair-content` matches exact known defaults damaged by an earlier unslashed block write. It does not broadly replace `u003c` in arbitrary editorial content. Both use correctly slashed `wp_update_post` input. Repeat execution produces no duplicate content.

## Verification

Use the isolated local bootstrap documented in `frontend-data-integration.md`:

```sh
php tests/integration/verify-presentation.php
```

The fixture checks original layouts, matching desktop/mobile IDs, five shared fighter sections, duplicate instance isolation, rendered page blocks, REST fragments, correct zero/null/percentage display and dry-run/idempotent repair with editor changes preserved. Existing public, contact, movers, bulk, career and pipeline fixtures remain required. Formula v1.5 has its separate deterministic suite.

Compare the restored pages with locally rendered `ba440be` reference templates at desktop, tablet and 390 × 844. Record content-related differences separately: real fighter names, missing optional facts/photos, empty confirmed partner/team lists, accurate dates and direct scores naturally differ from prototype content. Private reference routes, screenshots, source packages and database exports are outside the Git root and are not deployed.
