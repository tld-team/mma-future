<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! mmaf_fighter_has_fight_data( $c ) ) { echo '<section class="mmaf-section"><div class="mmaf-container">'; mmaf_view( 'empty-state', array( 'title' => 'Fight data not available yet', 'description' => 'Confirmed professional results are needed to display fight statistics and recent form.' ) ); echo '</div></section>'; return; }  ?>

<section class="mf-recent-fights bg-gradient-to-br from-slate-50 to-slate-100 py-12 lg:py-16">
<div class="container mx-auto px-4">
<!-- Section Title -->
<h2 class="font-heading font-bold text-xl md:text-2xl text-heading uppercase tracking-wide mb-8 lg:mb-10">Latest fights</h2>
<!-- Fight Cards Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 lg:gap-8"><?php if ( empty( $c['recent_fights'] ) ) { echo '<p>No recorded professional MMA fights are available.</p>'; } foreach ( array_slice( $c['recent_fights'], 0, 5 ) as $fight ) { mmaf_view( 'latest-card', array( 'fight' => $fight ) ); } ?></div>
</div>
</section>
