<?php if ( ! defined( 'ABSPATH' ) ) { exit; }
$assessment = mmaf_scoring_assessment_parts( $r );
$meta = array( mmaf_is_career_formula( $r ) ? 'Known finishes / all career wins' : ( ! empty( $r['scoring_assessment'] ) ? 'Finish rate for wins from 9 May 2026' : 'Recorded finish rate' ) => mmaf_display_percent( $r['finish_rate'] ), 'Record' => mmaf_record( mmaf_display_record( $r ) ) );
if ( $assessment ) {
    $meta['Scoring bouts'] = $assessment['sample_size'];
    if ( 'Main' !== $assessment['status'] ) { $meta['Status'] = $assessment['status']; }
    $meta['Checked'] = 'unknown' === $assessment['checked'] ? 'unknown' : substr( $assessment['checked'], 0, 10 );
}
?>
<div class="rk-detail-main flex-1 min-w-0">
 <?php mmaf_view( 'score-sum', compact( 'r' ) ); ?>
 <ul class="rk-detail-meta"><?php foreach ( $meta as $label => $value ) : ?><li><?php echo esc_html( $label ); ?> <strong><?php echo esc_html( $value ); ?></strong></li><?php endforeach; ?></ul>
 <?php mmaf_view( 'score-fights', compact( 'r' ) ); ?>
 <?php foreach ( $assessment['notes'] ?? array() as $key => $note ) : ?>
  <?php if ( 'coverage' === $key ) : ?><details class="rk-fineprint"><summary>How the data is counted</summary><p><?php echo esc_html( $assessment['label'] . ' ' . $note ); ?></p></details>
  <?php else : ?><p class="rk-detail-note"><?php echo esc_html( $note ); ?></p><?php endif; ?>
 <?php endforeach; ?>
 <?php if ( $breakdown ) { mmaf_score_details( $r ); } ?>
</div>
<a href="<?php echo esc_url( $r['permalink'] ); ?>" class="rk-detail-profile inline-flex items-center justify-center gap-1.5 text-sm font-semibold py-2.5 px-4 rounded-xl" style="background:#eff6ff;border:1px solid #bfdbfe;color:#0047A8;text-decoration:none">View Profile <?php mmaf_view( 'icon-arrow' ); ?></a>
