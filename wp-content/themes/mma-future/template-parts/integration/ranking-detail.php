<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="flex-1 min-w-0">
 <p class="text-xs text-slate-500 mb-2"><?php echo !empty($r['scoring_assessment'])?'Finish rate for wins from 9 May 2026:':'Recorded finish rate:'; ?> <strong><?php echo esc_html( mmaf_display_percent( $r['finish_rate'] ) ); ?></strong> · Record: <?php echo esc_html( mmaf_record( mmaf_display_record($r) ) ); ?></p>
 <?php mmaf_render_scoring_assessment($r); ?>
 <?php if ( $breakdown ) { mmaf_score_details( $r ); } ?>
</div>
<a href="<?php echo esc_url( $r['permalink'] ); ?>" class="inline-flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 px-4 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <?php mmaf_view( 'icon-arrow' ); ?></a>
