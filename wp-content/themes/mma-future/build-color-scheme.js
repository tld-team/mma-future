const fs = require('fs');
const postcss = require('postcss');

// Keep one set of dark styles in source. Preserve the system fallback without JS,
// and let an explicit choice override the OS without changing selector specificity.
const css = postcss.parse(fs.readFileSync('assets/src/css/color-scheme.css', 'utf8'));
function scope(rule, condition) {
    rule.selectors = rule.selectors.map(selector => selector === ':root'
        ? `:root:where(${condition})`
        : `:where(:root${condition}) ${selector}`);
}
css.walkAtRules('media', media => {
    if (media.params !== '(prefers-color-scheme: dark)') return;
    const manual = media.clone();
    manual.walkRules(rule => scope(rule, '[data-theme="dark"]'));
    media.parent.insertBefore(media, manual.nodes);
    media.walkRules(rule => scope(rule, ':not([data-theme])'));
});
fs.writeFileSync('assets/dist/css/color-scheme.css', css.toString());
