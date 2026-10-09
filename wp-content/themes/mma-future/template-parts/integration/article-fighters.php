<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="rounded-xl border border-slate-200/70 bg-white p-4">
 <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">Related Fighters</h3>
 <ul class="space-y-3 list-none m-0 p-0">
 <?php foreach ( $fighters as $fighter ) : $f = $fighter['fighter']; ?>
  <li><a href="<?php echo esc_url( $fighter['profile']['permalink'] ); ?>" class="flex items-center gap-3 group">
   <span class="flex-shrink-0 w-9 h-9 rounded-full overflow-hidden flex items-center justify-center text-xs font-bold text-white" style="background:var(--brand)"><?php mmaf_opponent_image( array( 'image' => $fighter['profile']['image'] ) ); ?></span>
   <span><span class="block text-sm font-medium text-slate-700 group-hover:text-slate-900 transition-colors"><?php echo esc_html( $f['display_name'] ); ?></span>
    <?php if ( 'unknown' !== $f['weight_class'] ) : ?><span class="block text-xs text-slate-400"><?php echo esc_html( ucwords( str_replace( '_', ' ', $f['weight_class'] ) ) ); ?></span><?php endif; ?>
   </span>
  </a></li>
 <?php endforeach; ?>
 </ul>
</div>
