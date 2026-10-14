<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<div class="rk-card rounded-2xl p-4" data-fighter-id="<?php echo esc_attr( $r['fighter_id'] ); ?>" style="background:#fff;border:1px solid rgba(226,232,240,0.7)">
<div class="flex items-center gap-3">
<div class="text-center shrink-0" style="min-width:2rem">
<div class="rk-rank-num font-black tabular-nums" style="font-size:1.2rem;line-height:1;color:#0f172a"><?php echo esc_html( $r['rank']??'—' ); ?></div>

</div>
<a class="shrink-0 block w-11 h-11 rounded-full overflow-hidden" href="<?php echo esc_url( $r['permalink'] ); ?>" style="border:1px solid #e2e8f0"><?php mmaf_opponent_image( array( 'image' => $r['image'] ) ); ?></a>
<div class="flex-1 min-w-0">
<a class="font-semibold block truncate leading-tight" href="<?php echo esc_url( $r['permalink'] ); ?>" style="color:#0f172a;text-decoration:none"><?php echo esc_html( $r['display_name'] ); ?></a>
<div class="text-xs truncate mt-0.5" style="color:#94a3b8;font-style:italic"><?php echo esc_html( $r['nickname'] ? '“' . $r['nickname'] . '”' : '' ); ?></div>
<div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em"><?php echo esc_html( $r['nationality'] ?: '—' ); ?></div>
</div>
<div class="shrink-0 text-right">
<div class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8"><?php echo esc_html( mmaf_display_number( $r['score'], 2 ) ); ?></div>
<div class="text-xs mt-0.5" style="color:#94a3b8;letter-spacing:.04em;text-transform:uppercase;font-size:.62rem;font-weight:700">score</div>
</div>
</div>
<div class="flex flex-wrap items-center gap-2 mt-3 text-xs" style="color:#64748b">
<span class="font-mono tabular-nums"><?php echo esc_html( mmaf_record( mmaf_display_record($r) ) ); ?></span>
<span style="color:#e2e8f0">|</span>
<span class="rk-streak" data-streak="<?php echo esc_attr( substr( $r['streak'] ?? '', 0, 1 ) ); ?>"><?php echo esc_html( $r['streak'] ?: '—' ); ?></span>
<span style="color:#e2e8f0">|</span>
<span style="color:#94a3b8"><?php echo esc_html( ucwords( str_replace( '_', ' ', $r['weight_class'] ) ) ); ?></span>
</div>
<div class="mt-2 text-xs">Last fight: <?php echo esc_html( mmaf_display_date( $r['last_fight_date'] ) ); ?></div>
<details class="mt-3">
<summary class="flex items-center gap-1 text-xs font-bold select-none" style="color:#0047A8;letter-spacing:.04em">Details <span class="rk-det-arr" style="font-size:.8em">▾</span></summary>
<div class="mt-3 pt-3" style="border-top:1px solid rgba(226,232,240,0.7)"><?php mmaf_view( 'ranking-detail', compact( 'r', 'breakdown' ) ); ?></div>
</details>
</div>
