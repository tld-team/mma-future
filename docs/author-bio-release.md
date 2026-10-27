# About author block

The existing `acf/about-founder-bio-section` and its field keys remain unchanged. The portrait is 192 × 240 px from 768 px and 128 × 160 px below that breakpoint. Optional role, biography, experience, quote, signature and social links render only when populated. Social links include names, network icons, a new-tab announcement and keyboard focus styling. The block stylesheet is loaded for frontend rendering and ACF previews.

## Content delivery

Git deployment carries code only. Copy `dev-tools/seed-about-author.php` and the supplied `Nikola-Spasojevic.jpeg` into a private directory outside the web root, then run WP-CLI `eval-file` with `preview` and `apply` arguments. The script permits only local or the configured staging hostname. It saves a private content backup before changing the published About page, reuses its Founder block and places it immediately before FAQ, and deduplicates the photo by SHA-256. A repeat apply returns `already_applied`; subsequent editorial changes require review. Keep the script's backup and receipt outside Git.

The seeded English biography is draft copy. Experience, quote and signature remain as previously saved. The Facebook address intentionally retains the supplied fragment. No production or ranking activation is part of this delivery.

## Validation

Local verification used a separate database with copied jobs quarantined: 517 scraper tests, 83 formula assertions, all 14 unit groups, and UFC signing/import, tracking, public-contract and presentation integration checks passed. PHP lint, ACF JSON, JavaScript syntax and the production build passed. Frontend checks at 320, 390, 768 and 1440 px in both themes confirmed portrait sizes, 44 px links, visible keyboard focus and no horizontal overflow; the ACF editor preview was also inspected.
