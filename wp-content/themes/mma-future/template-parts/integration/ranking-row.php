<?php if ( ! defined( 'ABSPATH' ) ) { exit; }  ?>

<tr class="rk-main-row" data-fighter-id="<?php echo esc_attr( $r['fighter_id'] ); ?>" style="border-bottom:1px solid rgba(226,232,240,0.7)">
<td class="rk-td-rank px-4 py-3">
<div class="rk-rank-num font-black tabular-nums" style="font-size:1.15rem;line-height:1;color:#0f172a"><?php echo esc_html( $r['rank']??'—' ); ?></div>

</td>
<td class="px-4 py-3">
<div class="flex items-center gap-3">
<a class="shrink-0 block w-9 h-9 rounded-full overflow-hidden" href="<?php echo esc_url( $r['permalink'] ); ?>" style="border:1px solid #e2e8f0"><?php mmaf_opponent_image( array( 'image' => $r['image'] ) ); ?></a>
<div class="min-w-0">
<a class="font-semibold leading-tight block truncate" href="<?php echo esc_url( $r['permalink'] ); ?>" onmouseout="this.style.color='#0f172a'" onmouseover="this.style.color='#0047A8'" style="color:#0f172a;transition:color .15s;text-decoration:none"><?php echo esc_html( $r['display_name'] ); ?></a>
<div class="text-xs mt-0.5 truncate" style="color:#94a3b8;font-style:italic"><?php echo esc_html( $r['nickname'] ? '“' . $r['nickname'] . '”' : '' ); ?></div>
<div class="text-xs font-mono mt-0.5" style="color:#94a3b8;letter-spacing:.06em"><?php echo esc_html( $r['nationality'] ?: '—' ); ?></div>
</div>
</div>
</td>
<td class="px-4 py-3 text-xs whitespace-nowrap font-medium" style="color:#94a3b8"><?php echo esc_html( ucwords( str_replace( '_', ' ', $r['weight_class'] ) ) ); ?></td>
<td class="px-4 py-3 text-right"><span class="tabular-nums" style="font-size:1.1rem;font-weight:900;color:#0047A8"><?php echo esc_html( mmaf_display_number( $r['score'], 2 ) ); ?></span></td>
<td class="rk-impact-cell px-4 py-3 text-right"><?php mmaf_view( 'opponent-impact', compact( 'r' ) ); ?></td>
<td class="px-4 py-3 text-sm font-mono whitespace-nowrap tabular-nums" style="color:#475569"><?php echo esc_html( mmaf_record( mmaf_display_record($r) ) ); ?></td>
<td class="px-4 py-3"><span class="rk-streak" data-streak="<?php echo esc_attr( substr( $r['streak'] ?? '', 0, 1 ) ); ?>"><?php echo esc_html( $r['streak'] ?: '—' ); ?></span></td>
<td class="px-4 py-3 text-sm whitespace-nowrap"><?php echo esc_html( mmaf_display_date( $r['last_fight_date'] ) ); ?></td>
<td class="px-4 py-3">
<button aria-controls="<?php echo esc_attr( $prefix . '_detail_' . $r['fighter_id'] ); ?>" aria-expanded="false" aria-label="<?php echo esc_attr( 'Details for ' . $r['display_name'] ); ?>" class="rk-expand flex items-center justify-center w-9 h-9 rounded-lg" data-rk-expand="" style="border:1px solid #e2e8f0;background:#fff;color:#64748b" type="button">
<svg class="rk-chev w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewbox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round"></path></svg>
</button>
</td>
</tr>
<tr class="rk-detail-row" data-rk-detail="" id="<?php echo esc_attr( $prefix . '_detail_' . $r['fighter_id'] ); ?>">
<td colspan="9" style="padding:0;background:#f8fafc;border-bottom:1px solid rgba(226,232,240,0.7)">
<div class="flex flex-wrap items-center gap-8 px-5 py-5" style="border-top:1px solid rgba(226,232,240,0.7)"><?php mmaf_view( 'ranking-detail', compact( 'r', 'breakdown' ) ); ?></div>
</td>
</tr>
