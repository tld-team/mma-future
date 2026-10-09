<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="rounded-xl border border-slate-200/70 bg-white p-4">
 <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3"><?php echo esc_html( $title ); ?></h3>
 <ul class="space-y-3 list-none m-0 p-0">
 <?php foreach ( $events as $event ) : ?>
  <li><span class="block text-sm font-medium text-slate-700"><?php echo esc_html( $event['event_name'] ); ?></span><span class="block text-xs text-slate-400 mt-0.5"><?php echo esc_html( mmaf_display_date( $event['event_date'] ) ); ?></span></li>
 <?php endforeach; ?>
 </ul>
</div>
