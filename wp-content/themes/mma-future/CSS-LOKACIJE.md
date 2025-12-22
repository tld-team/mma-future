# 📍 Lokacije predefinisanih CSS vrednosti

Ovaj dokument pokazuje gde se nalaze sve predefinisane CSS vrednosti u projektu.

## 🎨 1. Tailwind CSS Konfiguracija
**Fajl:** `tailwind.config.js`

### Boje
- `primary` (plava) - linija 14-26
- `secondary` (siva) - linija 27-39
- `accent` (crvena) - linija 40-52
- `success`, `warning`, `danger`, `muted` - linija 53-61

### Fontovi
- `font-sans` - Inter (linija 65)
- `font-display` / `font-heading` - Sora (linija 68-70)
- `font-body` - Inter (linija 69)

### Veličine fontova
- `fontSize` - linija 74-88 (base je 18px)

### Spacing, Border Radius, Animacije
- `spacing` - linija 104-109
- `borderRadius` - linija 112-115
- `animation` - linija 118-122
- `keyframes` - linija 138-147

### Gradijenti i Senke
- `backgroundImage` - linija 123-130
- `boxShadow` - linija 131-136

---

## 🎨 2. WordPress Theme.json (Gutenberg)
**Fajl:** `theme.json`

### Boje za blokove
- `palette` - linija 10-51 (Base, Contrast, Accent 1-6)

### Fontovi
- `fontFamilies` - linija 153-184 (Manrope, Fira Code)

### Spacing
- `spacingSizes` - linija 59-95 (Tiny do XX-Large)

### Tipografija
- `fontSizes` - linija 109-152 (Small do XX-Large)
- Globalni font: Manrope (linija 201)
- H1-H6 veličine - linija 635-668

### Stilovi za blokove
- Button stilovi - linija 213-230
- Heading stilovi - linija 635-675
- Link stilovi - linija 676-685

---

## 🎨 3. SCSS Varijable
**Fajl:** `assets/src/scss/_variables.scss`

### Boje
```scss
$primary-dark: #1a202c;
$secondary-dark: #2d3748;
$accent-red: #e53e3e;
```

### Fontovi
```scss
$font-inter: 'Inter', sans-serif;
$font-oswald: 'Oswald', sans-serif;
```

### Senke
```scss
$shadow-fighter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.5));
```

### Gradijenti
```scss
$gradient-bg: linear-gradient(135deg, $primary-dark 0%, $secondary-dark 100%);
$gradient-stats: linear-gradient(90deg, rgba(26, 32, 44, 0.9) 0%, rgba(45, 55, 72, 0.7) 100%);
$gradient-divider: linear-gradient(90deg, transparent 0%, $accent-red 50%, transparent 100%);
```

---

## 🎨 4. Osnovni Stilovi
**Fajl:** `assets/src/scss/_base.scss`

- Body font: Inter (linija 12)
- Heading fontovi: Oswald (linija 18)
- Reset stilovi (linija 5-9)

---

## 🎨 5. Komponente
**Fajl:** `assets/src/scss/_components.scss`

### Klase
- `.gradient-bg` - linija 4-6
- `.stats-gradient` - linija 9-11
- `.fighter-image` - linija 14-16
- `.section-divider` - linija 19-22

### Mobile Menu
- Burger menu stilovi - linija 25-235
- Mobile navigation linkovi - linija 94-174

### Footer
- Footer linkovi - linija 243-269

---

## 🎨 6. SCSS Mixini
**Fajl:** `assets/src/scss/_mixins.scss`

- `@mixin gradient` - linija 2-4
- `@mixin box-shadow` - linija 7-9
- `@mixin flex-center` - linija 12-16
- `@mixin mobile` - linija 19-23
- `@mixin tablet` - linija 25-29
- `@mixin desktop` - linija 31-35

---

## 📝 Kako menjati vrednosti

### Za Tailwind klase (npr. `bg-primary`, `text-heading`)
→ Menjaj u `tailwind.config.js`

### Za WordPress blokove u editoru
→ Menjaj u `theme.json`

### Za SCSS varijable (npr. `$primary-dark`)
→ Menjaj u `_variables.scss`

### Za custom komponente
→ Menjaj u `_components.scss`

### Za osnovne stilove (body, headings)
→ Menjaj u `_base.scss`

---

## ⚠️ Napomena

Nakon izmena u SCSS fajlovima, potrebno je rekompajlirati CSS:
```bash
npm run dev  # ili npm run build
```

Tailwind i theme.json se automatski procesiraju, ali možda će biti potrebno osvežiti cache u WordPress-u.
