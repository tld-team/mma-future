<?php if ( ! defined( 'ABSPATH' ) ) { exit; } if ( ! $data['items'] ) { echo '<p class="rk-empty py-12 text-center text-slate-500">' . esc_html( mmaf_site_field( 'empty_state_text' ) ?: 'No rankings match these filters.' ) . '</p>'; return; } ?>

<div><div class="rounded-2xl rk-wrap mmaf-ranking-table">
<table class="w-full text-sm">
<colgroup><col class="rk-col-rank"><col class="rk-col-fighter"><col class="rk-col-division"><col class="rk-col-score"><col class="rk-col-record"><col class="rk-col-finishes"><col class="rk-col-quality"><col class="rk-col-age"><col class="rk-col-streak"><col class="rk-col-date"><col class="rk-col-details"></colgroup>
<thead>
<tr>
<th scope="col" class="rk-th-rank px-4 py-3.5 text-left">
<a class="rk-sort-btn" data-rk-sort="rank" href="<?php echo esc_url( mmaf_presentation_query( array( $prefix . '_sort' => 'rank', $prefix . '_order' => ( 'rank' === ( $data['sort'] ?? 'rank' ) && 'asc' === ( $data['order'] ?? 'asc' ) ) ? 'desc' : 'asc', $prefix . '_page' => 1 ) ) ); ?>" role="button">
                  Rank
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewbox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path></svg>
</a>
</th>
<th scope="col" class="px-4 py-3.5 text-left">Fighter</th>
<th scope="col" class="px-4 py-3.5 text-left">Division</th>
<th scope="col" class="rk-score-col px-4 py-3.5">
<a class="rk-sort-btn" data-rk-sort="score" href="<?php echo esc_url( mmaf_presentation_query( array( $prefix . '_sort' => 'score', $prefix . '_order' => ( 'score' === ( $data['sort'] ?? 'rank' ) && 'asc' === ( $data['order'] ?? 'asc' ) ) ? 'desc' : 'asc', $prefix . '_page' => 1 ) ) ); ?>" role="button">
                  <?php echo esc_html(($data['view']??'main')==='provisional'?'Provisional sum':'Score'); ?>
                  <svg class="rk-sort-icon w-3 h-3" fill="none" stroke="currentColor" viewbox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"></path></svg>
</a>
</th>
<?php $career = mmaf_is_career_formula( $data ); ?>
<th scope="col" class="rk-part-col rk-part-col--first px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Record<?php if ( $career ) { mmaf_view( 'part-help', array( 'help_id' => $prefix . '_record_help', 'title' => 'Points from wins and losses', 'text' => 'Each scored win adds 0.5 points and each scored loss deducts 1.5. Draws and no contests add 0. The small line is the fight record.' ) ); } ?></span></th>
<th scope="col" class="rk-part-col px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Finishes <?php mmaf_view( 'finishes-help', array( 'help_id' => $prefix . '_finishes_help', 'finish_points' => mmaf_points_per_finish( $data ) ) ); ?></span></th>
<th scope="col" class="rk-part-col px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Opponents <?php mmaf_view( 'opponent-impact-help', array( 'help_id' => $prefix . '_impact_help' ) ); ?></span></th>
<th scope="col" class="rk-part-col rk-part-col--last px-4 py-3.5 text-right"><span class="rk-impact-heading-label">Age<?php if ( $career ) { mmaf_view( 'part-help', array( 'help_id' => $prefix . '_age_help', 'title' => 'Points from age', 'text' => 'Under 25 adds 1 point, 25 to 30 adds 0, and 31 or older deducts 1.' ) ); } ?></span></th>
<th scope="col" class="rk-streak-col px-4 py-3.5">Streak</th>
<th scope="col" class="px-4 py-3.5 text-left">Last Fight</th>
<th scope="col" class="px-4 py-3.5"><span class="sr-only">Details</span></th>
</tr>
</thead>
<tbody><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-row', compact( 'r', 'breakdown', 'prefix' ) ); } ?></tbody>
</table>
</div><div class="rk-cards"><?php foreach ( $data['items'] as $r ) { mmaf_view( 'ranking-card', compact( 'r', 'breakdown', 'prefix' ) ); } ?></div></div>
