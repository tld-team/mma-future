<?php if ( ! defined( 'ABSPATH' ) ) { exit; } if ( ! $data['items'] ) { echo '<p class="rk-empty py-12 text-center text-slate-500">' . esc_html( mmaf_site_field( 'empty_state_text' ) ?: 'No rankings match these filters.' ) . '</p>'; return; } ?>

<div><div class="rounded-2xl rk-wrap mmaf-ranking-table" style="border:1px solid rgba(226,232,240,0.7)">
<table class="w-full border-collapse text-sm" style="background:#fff">
<colgroup><col class="rk-col-rank"><col class="rk-col-fighter"><col class="rk-col-division"><col class="rk-col-score"><col class="rk-col-finishes"><col class="rk-col-quality"><col class="rk-col-record"><col class="rk-col-streak"><col class="rk-col-date"><col class="rk-col-details"></colgroup>
<thead>
<tr>
<th class="rk-th-rank px-4 py-3.5 text-left">
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
<th scope="col" class="rk-finishes-heading px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Finishes <?php mmaf_view( 'finishes-help', array( 'help_id' => $prefix . '_finishes_help' ) ); ?></span><span class="rk-impact-heading-note">+1 point each</span></th>
<th scope="col" class="rk-impact-heading px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Opponent quality <?php mmaf_view( 'opponent-impact-help', array( 'help_id' => $prefix . '_impact_help' ) ); ?></span><span class="rk-impact-heading-note">Extra points · included in Score</span></th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Record</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Streak</th>
<th class="px-4 py-3.5 text-left text-xs font-bold uppercase tracking-widest" style="color:#94a3b8">Last Fight</th>
<th class="px-4 py-3.5"></th>
</tr>
</thead>
<tbody><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-row', compact( 'r', 'breakdown', 'prefix' ) ); } ?></tbody>
</table>
</div><div class="rk-cards"><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-card', compact( 'r', 'breakdown', 'prefix' ) ); } ?></div></div>
