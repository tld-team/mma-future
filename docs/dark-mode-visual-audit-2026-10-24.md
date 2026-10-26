# Dark mode visual audit — 2026-10-24

Baseline: f18cee4. Light remains the default; all new declarations are inside
`prefers-color-scheme: dark`. No layout, templates, JavaScript, content or data changes.

## Reproduced findings and fixes

- Calendar: white past/pending cells, pale partial-day gradients, event labels,
  empty state and event panels; dark introductory text; bright grid lines.
  Theme the calendar variables and explicit states, including its late-loaded CSS.
- Fighter profile: pale section gradients made light headings disappear. Use the
  canvas background for stats, recent fights, history and bio; restore readable
  opponent links, fact values and headings; tone down result-icon circles.
- Rankings: mobile cards sit outside the desktop `.rk-wrap` scope. Correct their
  names, finish/quality values, streak badges, dividers and detail actions. Also
  correct desktop expanded actions, help-button states, partial-record notes,
  the board selector surround and its mobile label.
- Articles: the actual content wrapper is `.js-post-content`, not `.entry-content`.
  Correct inherited body text and default headings/links, preserving explicit
  Gutenberg color/background classes. Theme related cards, metadata, category
  badge and sharing navigation. Preserve the primary copy-link button.
- Blog archive: replace the pale category-strip edge fade with the canvas fade.
- About/home: readable feature icons, muted chips and dark button surfaces;
  monochrome uploaded icons get a small light tile without changing source images.
- Directory/contact: readable introductory/summary text and optional-field label.

## Validation

- Screenshot-first review of staging: home, rankings, directory, fighter profile,
  About, Contact, blog archive and article.
- Local dark visual checks at 804 px, 390 px, 320 px and 1440 px across the relevant
  templates. Local content differs from staging; verify the deployed content too.
- Interactions checked: mobile menu and ranking submenu, ranking row/card details,
  live no-results search, contact reason selection. No real form submission.
- Production build passed. PHP syntax: 311 files, zero errors. CSS parsed with
  PostCSS; source and distribution match; `git diff --check` passed.
- Parsed CSS comparison after removing dark media rules: light declarations
  identical to baseline. Generated assets changed only color-scheme CSS/hash.
- Secondary/muted/link/error text tokens against the three dark surfaces exceed
  4.5:1 (minimum measured 5.94:1). This is a token check, not a complete WCAG audit.
- This pass does not claim Firefox/Safari, actual forced-dark extensions or every
  content-dependent state were tested. No OS preferences or extension settings changed.
