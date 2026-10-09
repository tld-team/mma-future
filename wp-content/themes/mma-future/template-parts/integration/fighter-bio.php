<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<section class="mf-bio-section">
<div class="container mx-auto px-4">
<!-- ===================== ABOUT SECTION ===================== -->
<div class="mf-about">
<!-- Header with Title + Anchor Links -->
<header class="mf-about__header">
<h2 class="mf-about__title">About the fighter</h2>
<nav aria-label="Quick navigation" class="mf-about__anchors">
<a class="mf-about__anchor" href="#<?php echo esc_attr( $prefix . '_facts' ); ?>">Biographical facts</a>
</nav>
</header>
<!-- About Grid: Bio Text (8 cols) + Key Facts (4 cols) -->
<div class="mf-about__grid">
<!-- Bio Text Block -->
<?php if ( ! empty( $c['editorial']['biography'] ) || ! empty( $c['editorial']['career_highlights'] ) ) : ?><div class="mf-about__text">

<!-- Bio Body Wrapper with Fade Effect -->
<div class="mf-about__body-wrapper">
<div class="mf-about__body" data-slot="bio"><?php echo wp_kses_post( $c['editorial']['biography'] ); foreach ( (array) $c['editorial']['career_highlights'] as $row ) { echo '<p>' . esc_html( $row['highlight_text'] ?? '' ) . '</p>'; } ?></div>
</div>

</div><?php endif; ?>
<!-- Key Facts Sidebar -->
<aside class="mf-about__sidebar">
<div class="mf-keyfacts">
<h3 class="mf-keyfacts__title">Key facts</h3>
<dl class="mf-keyfacts__list"><?php foreach ( $facts as $label => $value ) : if ( null === $value || '' === $value ) { continue; } ?><div class="mf-keyfacts__row">
<svg class="mf-keyfacts__icon" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
<div class="mf-keyfacts__content">
<dt class="mf-keyfacts__label"><?php echo esc_html( $label ); ?></dt>
<dd class="mf-keyfacts__value" data-slot="keyfacts-nationality"><?php echo esc_html( $value ); ?></dd>
</div>
</div><?php endforeach; ?></dl>
</div>
</aside>
</div>
</div>
<!-- ===================== BIOGRAPHICAL DATA SECTION ===================== -->
<div class="mf-bio-data" id="<?php echo esc_attr( $prefix . '_facts' ); ?>">
<div class="container mx-auto px-4">
<!-- Header -->
<header class="mf-bio-data__header">
<h2 class="mf-bio-data__title">Biographical facts</h2>
<p class="mf-bio-data__subtitle">Verified facts and characteristics</p>
</header>
<!-- Bio Cards Grid -->
<div class="mf-bio-cards-grid">
<!-- Card 1: Basic Info -->
<article class="mf-bio-card">
<h3 class="mf-bio-card__title">Personal information</h3>
<dl class="mf-bio-card__list"><?php foreach ( array( 'Nickname', 'Date of birth', 'Age', 'Nationality', 'Birthplace', 'Fighting out of' ) as $label ) : $value = $facts[$label]; if ( null === $value || '' === $value ) { continue; } ?><div class="mf-bio-card__row">
<svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
<div class="mf-bio-card__content">
<dt class="mf-bio-card__label"><?php echo esc_html( $label ); ?></dt>
<dd class="mf-bio-card__value" data-slot="nickname"><?php echo esc_html( $value ); ?></dd>
</div>
</div><?php endforeach; ?></dl>
</article>
<!-- Card 2: Physical Characteristics -->
<article class="mf-bio-card">
<h3 class="mf-bio-card__title">Physical characteristics</h3>
<dl class="mf-bio-card__list"><?php foreach ( array( 'Height', 'Reach', 'Division', 'Stance' ) as $label ) : $value = $facts[$label]; if ( null === $value || '' === $value ) { continue; } ?><div class="mf-bio-card__row">
<svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
<div class="mf-bio-card__content">
<dt class="mf-bio-card__label"><?php echo esc_html( $label ); ?></dt>
<dd class="mf-bio-card__value" data-slot="height"><?php echo esc_html( $value ); ?></dd>
</div>
</div><?php endforeach; ?></dl>
</article>
<!-- Card 3: Career -->
<article class="mf-bio-card">
<h3 class="mf-bio-card__title">Career</h3>
<dl class="mf-bio-card__list"><?php foreach ( array( 'Team', 'Coach' ) as $label ) : $value = $facts[$label]; if ( null === $value || '' === $value ) { continue; } ?><div class="mf-bio-card__row">
<svg class="mf-bio-card__icon" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
<div class="mf-bio-card__content">
<dt class="mf-bio-card__label"><?php echo esc_html( $label ); ?></dt>
<dd class="mf-bio-card__value" data-slot="team"><?php echo esc_html( $value ); ?></dd>
</div>
</div><?php endforeach; ?></dl>
</article>
</div>
</div>
</div>
</div>
</section>
