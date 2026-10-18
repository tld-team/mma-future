<?php if ( ! defined( 'ABSPATH' ) ) { exit; } if ( ! $data['items'] ) { echo '<p class="rk-empty py-12 text-center text-slate-500">' . esc_html( mmaf_site_field( 'empty_state_text' ) ?: 'No rankings match these filters.' ) . '</p>'; return; } ?>

<div><div class="hidden sm:block rounded-2xl rk-wrap mmaf-ranking-table" style="border:1px solid rgba(226,232,240,0.7)">
<table class="w-full border-collapse text-sm" style="background:#fff">
<thead>
<tr>
<th class="rk-th-rank px-4 py-3.5 text-left" style="width:5.5rem">
<a class="rk-sort-btn" data-rk-sort="rank" href="<?php echo esc_url( mmaf_presentation_query( array( $prefix . '_sort' => 'rank', $prefix . '_order' => ( 'rank' === ( $data['sort'] ?? 'rank' ) && 'asc' === ( $data['order'] ?? 'asc' ) ) ? 'desc' : 'asc', $prefix . '_page' => 1 ) ) ); ?>" role="button">
                  Rank
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewbox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path></svg>
</a>
</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Fighter</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Division</th>
<th class="px-4 py-3.5 text-right">
<a class="rk-sort-btn ml-auto" data-rk-sort="score" href="<?php echo esc_url( mmaf_presentation_query( array( $prefix . '_sort' => 'score', $prefix . '_order' => ( 'score' === ( $data['sort'] ?? 'rank' ) && 'asc' === ( $data['order'] ?? 'asc' ) ) ? 'desc' : 'asc', $prefix . '_page' => 1 ) ) ); ?>" role="button">
                  Score
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewbox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path></svg>
</a>
</th>
<th scope="col" class="rk-impact-heading px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Opponent impact <?php mmaf_view( 'opponent-impact-help', array( 'help_id' => $prefix . '_impact_help' ) ); ?></span><span class="rk-impact-heading-note">Wins / Losses · points</span></th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Record</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Streak</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Last Fight</th>
<th class="px-4 py-3.5" style="width:3rem"></th>
</tr>
</thead>
<tbody><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-row', compact( 'r', 'breakdown', 'prefix' ) ); } ?></tbody>
</table>
</div><div class="sm:hidden space-y-2.5 rk-cards"><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-card', compact( 'r', 'breakdown', 'prefix' ) ); } ?></div></div>
