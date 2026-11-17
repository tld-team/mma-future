<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_display_number( $value, $decimals = 0 ) { return null === $value ? '—' : number_format_i18n( (float) $value, $decimals ); }
function mmaf_display_date( $value ) { return $value ? wp_date( 'M j, Y', strtotime( $value ), wp_timezone() ) : '—'; }
/** Whole-career formulas (v1.8+) share one public explanation. */
function mmaf_is_career_formula( array $context ): bool {
    $scope = $context['scoring_scope'] ?? ( $context['dates']['scoring_scope'] ?? '' );
    if ( 'professional_mma_career_v1' === $scope ) { return true; }
    $version = $context['formula_version'] ?? ( $context['dates']['formula_version'] ?? '' );
    return in_array( $version, array( 'v1.8', 'v1.9' ), true );
}
/** Points one known KO/TKO or submission win adds, from the active run's formula. */
function mmaf_points_per_finish( array $context ): ?float {
    $value = $context['points_per_finish'] ?? ( $context['dates']['points_per_finish'] ?? ( $context['scoring_finishes']['points_per_finish'] ?? null ) );
    return is_numeric( $value ) && (float) $value > 0 ? (float) $value : null;
}
function mmaf_points_text( float $points ): string {
    $text = rtrim( rtrim( number_format( $points, 2, '.', '' ), '0' ), '.' );
    return $text . ( 1.0 === $points ? ' point' : ' points' );
}
/** Signed points without padding zeros: +2, +2.25, −1.5, 0. */
function mmaf_display_points( $value ): string {
    if ( ! is_numeric( $value ) ) { return '—'; }
    $number = (float) $value;
    $text = rtrim( rtrim( number_format( abs( $number ), 3, '.', '' ), '0' ), '.' );
    return ( $number > 0 ? '+' : ( $number < 0 ? '−' : '' ) ) . $text;
}
/** Marks deductions so they read as deductions at a glance. */
function mmaf_negative_class( $value ): string { return is_numeric( $value ) && (float) $value < 0 ? ' rk-neg' : ''; }
/** The parts that add up to the displayed score, or null when they are not all known. */
function mmaf_score_components( array $ranking ): ?array {
    $parts = $ranking['score_components'] ?? null;
    return is_array( $parts ) && isset( $parts['record_points'], $parts['finish_points'], $parts['opponent_points'], $parts['age_points'], $parts['total'] ) ? $parts : null;
}
/** One description of each score part, shared by the table row, the mobile card and the details panel. */
function mmaf_score_parts( array $r ): array {
    $c = mmaf_score_components( $r ); $f = $r['scoring_finishes'] ?? null; $o = $r['opponent_impact'] ?? null;
    $record = mmaf_record( mmaf_display_record( $r ) ); $per = mmaf_points_per_finish( $r );
    $count = $f ? (int) $f['count'] : null; $unknown = $f ? (int) $f['unknown_win_methods'] : 0;
    $finishes = null === $count ? '' : ( $unknown && ! $count ? 'Finishes unknown' : ( $unknown ? '≥' : '' ) . $count . ( 1 === $count ? ' finish' : ' finishes' ) );
    $wins = $c['scoring_wins'] ?? null; $losses = $c['scoring_losses'] ?? null;
    $missing = $o ? $o['missing_record_bouts'] : 0;
    return array(
        'record' => array( 'label' => 'Record', 'value' => $c['record_points'] ?? null, 'note' => '—' === $record ? '' : $record, 'warn' => '',
            'lines' => null !== $wins && null !== $losses ? array( $wins . ( 1 === $wins ? ' win' : ' wins' ) . ' · ' . $losses . ( 1 === $losses ? ' loss' : ' losses' ) ) : array() ),
        'finishes' => array( 'label' => 'Finishes', 'value' => $f['points'] ?? ( $c['finish_points'] ?? null ), 'note' => $finishes,
            'warn' => $unknown ? $unknown . ( 1 === $unknown ? ' win method unknown' : ' win methods unknown' ) : '',
            'lines' => $f ? array_values( array_filter( array( $unknown && ! $count ? '' : $finishes . ( null === $per ? '' : ' × ' . rtrim( rtrim( number_format( $per, 2, '.', '' ), '0' ), '.' ) ), $f['ko_tko'] . ' KO/TKO · ' . $f['submission'] . ' SUB' ) ) ) : array() ),
        'opponents' => array( 'label' => 'Opponents', 'value' => $o['total_points'] ?? ( $c['opponent_points'] ?? null ), 'note' => $o && null === $missing ? 'Opponent record coverage unavailable' : '',
            'warn' => $missing > 0 ? $o['known_record_bouts'] . '/' . $o['scoring_bouts'] . ' opponent records known' : '',
            'lines' => $o ? array( 'Win adjustment ' . mmaf_display_points( $o['win_points'] ), 'Extra loss penalty ' . mmaf_display_points( $o['loss_points'] ) ) : array() ),
        'age' => array( 'label' => 'Age', 'value' => $c['age_points'] ?? null, 'note' => isset( $r['age'] ) ? 'Age ' . (int) $r['age'] : '', 'warn' => '',
            'lines' => isset( $r['age'] ) ? array( 'Age ' . (int) $r['age'] ) : array() ),
    );
}
/** A score part as one number with at most one line of context; details live in the breakdown panel. */
function mmaf_render_score_part( array $part, string $sr = '' ): void {
    $value = $part['value']; $known = is_numeric( $value );
    echo '<div class="rk-part' . ( $known && 0.0 === (float) $value ? ' is-zero' : '' ) . '"><span class="rk-impact-total' . mmaf_negative_class( $value ) . '">' . ( $sr ? '<span class="sr-only">' . esc_html( $sr ) . ': </span>' : '' ) . esc_html( mmaf_display_points( $value ) ) . ( $sr && $known ? '<span class="sr-only"> points</span>' : '' ) . '</span>';
    if ( '' !== $part['note'] ) { echo '<span class="rk-impact-note">' . esc_html( $part['note'] ) . '</span>'; }
    if ( '' !== $part['warn'] ) { echo '<span class="rk-impact-note rk-impact-note--partial">' . esc_html( $part['warn'] ) . '</span>'; }
    echo '</div>';
}
function mmaf_display_percent( $value ) { return null === $value ? '—' : number_format_i18n( 100 * (float) $value, 0 ) . '%'; }
function mmaf_display_signed_points( $value ): string {
    if ( null === $value ) { return '—'; }
    $number = (float) $value;
    $decimals = abs( $number - round( $number ) ) < 0.000001 ? 0 : 3;
    $formatted = mmaf_display_number( abs( $number ), $decimals );
    return ( $number > 0 ? '+' : ( $number < 0 ? '−' : '' ) ) . $formatted;
}
function mmaf_display_method( array $fight ): string {
	$labels = array( 'ko_tko' => 'KO/TKO', 'submission' => 'Submission', 'decision' => 'Decision', 'other' => 'Other', 'unknown' => 'Unknown' );
	$detail = trim( (string) ( $fight['method_detail'] ?? '' ) );
	if ( $detail ) { return preg_replace_callback( '/\b(ko|tko|dq)\b/i', static function( $match ) { return strtoupper( $match[1] ); }, $detail ); }
	return $labels[ $fight['method_category'] ] ?? ucwords( str_replace( '_', ' ', $fight['method_category'] ) );
}
function mmaf_record( $stats ) {
	if ( ! is_array( $stats ) || null === ( $stats['wins'] ?? null ) ) { return '—'; }
	return $stats['wins'] . '–' . $stats['losses'] . '–' . $stats['draws'] . ( ! empty( $stats['nc'] ) ? ' (' . $stats['nc'] . ' NC)' : '' );
}
function mmaf_display_record(array $context): ?array {
    return $context['career_record']['record']??($context['record']??$context['stats']??null);
}
function mmaf_record_label(array $context): string {
    return !empty($context['career_record']['record']) ? ('Professional MMA career record'.(($context['career_record']['status']??'')==='review'?' — pending review':'')) : 'Recorded fights';
}
/** Plain-text pieces of a scoring assessment, shared by the ranking rows and the fighter hero. */
function mmaf_scoring_assessment_parts(array $context): ?array {
    $a=$context['scoring_assessment']??null;if(!$a){return null;}
    $notes=array();
    if(($a['methodology']??'')==='professional_mma_career_v1'){$d=$a['detail_coverage']??[];$notes['coverage']='An unknown pre-fight record or method adds 0 extra points; a confirmed 0–0 is a known record. The current profile record can differ from the frozen scoring inputs. Known/unknown opponent records: '.($d['known_records']??0).'/'.($d['unknown_records']??0).'; methods: '.($d['known_methods']??0).'/'.($d['unknown_methods']??0).'.';}
    if(!empty($a['no_recent_fights'])){$notes['no_recent_fights']='No fights since 9 May 2026.';}
    if($a['status']==='provisional'){$notes['provisional']='Provisional sum: '.mmaf_display_number($a['known_points']??null,2).' · No final score or main ranking position yet. '.implode('; ',array_map(static fn($r)=>str_replace('_',' ',$r),$a['reasons']));}
    return array('label'=>$context['dates']['score_label']??'MMA Future Score','status'=>ucfirst($a['status']),'sample_size'=>$a['sample_size'],'checked'=>!empty($a['verified_at'])?gmdate('Y-m-d H:i:s',strtotime($a['verified_at'])).' UTC':'unknown','career'=>($a['methodology']??'')==='professional_mma_career_v1','notes'=>$notes);
}
/** Fine print for the fighter hero, collapsed into one discreet disclosure. */
function mmaf_render_fighter_hero_details(array $c): void {
    $f=$c['fighter']??array(); $dates=$c['dates']??array();
    $p=($f['ranking_status']??'')==='ranked_or_candidate'?mmaf_scoring_assessment_parts($c):null;
    $rows=array('Record'=>mmaf_fighter_record_available($c)?mmaf_record_label($c):'Professional record not available yet');
    if($p){$rows['Score basis']=$p['label'];$rows['Assessment']=$p['status'].' · '.$p['sample_size'].' scoring bouts';$rows['Checked']=$p['checked'];}
    elseif(!empty($dates['scoring_scope'])&&!empty($dates['score_label'])){$rows['Score basis']=$dates['score_label'];}
    $rows+=array('Rankings calculated'=>mmaf_display_date($dates['ranking_calculated_at']??null),'Activated'=>mmaf_display_date($dates['ranking_activated_at']??null),'Statistics updated'=>mmaf_display_date($dates['stats_updated_at']??null));
    echo '<details class="mf-hero-details"><summary class="mf-hero-details__summary"><svg aria-hidden="true" class="mf-hero-details__icon" fill="currentColor" viewBox="0 0 20 20"><path clip-rule="evenodd" fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"/></svg><span>Score &amp; data details</span><svg aria-hidden="true" class="mf-hero-details__chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg></summary><div class="mf-hero-details__panel"><dl class="mf-hero-details__list">';
    foreach($rows as $label=>$value){echo '<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($value).'</dd></div>';}
    echo '</dl>';
    if($p&&$p['notes']){echo '<div class="mf-hero-details__notes">';foreach($p['notes'] as $note){echo '<p>'.esc_html($note).'</p>';}echo '</div>';}
    echo '</div></details>';
}
/** An empty imported record must not imply that the fighter has never competed. */
function mmaf_fighter_has_fight_data( array $context ): bool {
	return ! empty( $context['recent_fights'] ) || (int) ( $context['stats']['pro_fights_count'] ?? 0 ) > 0;
}
function mmaf_fighter_record_available( array $context ): bool {
	return !empty($context['career_record']['record']) || mmaf_fighter_has_fight_data( $context ) || ( 'complete' === ( $context['career_coverage']['status'] ?? '' ) && 0 === ( $context['stats']['pro_fights_count'] ?? null ) );
}
function mmaf_render_dates( $data ) {
	if(!empty($data['scoring_scope'])){echo '<p class="mmaf-dates">'.esc_html($data['score_label']).'</p>';}
	echo '<p class="mmaf-dates">Rankings calculated: ' . esc_html( mmaf_display_date( $data['ranking_calculated_at'] ?? null ) ) . ' · Activated: ' . esc_html( mmaf_display_date( $data['ranking_activated_at'] ?? null ) ) . ' · Statistics updated: ' . esc_html( mmaf_display_date( $data['stats_updated_at'] ?? null ) ) . '</p>';
}
function mmaf_fighter_context() {
	if ( isset( $GLOBALS['mmaf_fighter_context'] ) ) { return $GLOBALS['mmaf_fighter_context']; }
	$id = (int) mmaf_site_field( 'preview_fighter_id' );
	$service = mmaf_public_service(); return $service && $id ? $service->fighter( $id ) : new WP_Error( 'no_fighter', 'Choose a public fighter to preview this block.' );
}
function mmaf_fighter_image( $context, $class = 'mmaf-avatar' ) {
	$image = $context['profile']['image'] ?? null; $hero = $context['editorial']['hero_image'] ?? null;
	if ( $hero ) { echo mmaf_site_image( $hero, 'large', array( 'class' => $class ) ); }
	elseif ( ! empty( $image['url'] ) ) { echo '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $image['url'] ) . '" alt="' . esc_attr( $image['alt'] ?: $context['fighter']['display_name'] ) . '">'; }
	else { echo '<span class="' . esc_attr( $class ) . ' mmaf-avatar--placeholder" aria-hidden="true"><i class="fas fa-user"></i></span>'; }
}
function mmaf_render_fights( $history ) {
 if ( empty( $history['items'] ) ) { mmaf_view( 'empty-state', array( 'title' => 'No fights to show', 'description' => 'Try another result or method, or return to the first page.' ) ); return; }
 echo '<div class="mf-fight-history__list flex flex-col gap-4">';
 foreach ( $history['items'] as $fight ) { mmaf_view( 'history-card', compact( 'fight' ) ); }
 echo '</div>';
}

function mmaf_pagination( $data, $prefix ) {
	if ( $data['total_pages'] < 2 ) { return; }
	echo '<nav class="mmaf-pagination" aria-label="Results pages">';
	foreach ( array( $data['page'] - 1 => 'Previous', $data['page'] + 1 => 'Next' ) as $page => $text ) {
		if ( $page < 1 || $page > $data['total_pages'] ) { continue; }
		echo '<a class="mmaf-button mmaf-button--secondary" data-page="' . (int) $page . '" href="' . esc_url( mmaf_presentation_query( array( $prefix . '_page' => $page ) ) ) . '">' . esc_html( $text ) . '</a>';
	}
	echo '<span>Page ' . (int) $data['page'] . ' of ' . (int) $data['total_pages'] . '</span></nav>';
}
function mmaf_render_fighter_section( $section ) {
 if ( ! in_array( $section, array( 'hero', 'stats', 'latest', 'history', 'bio' ), true ) ) { return; }
 $c = mmaf_fighter_context();
 if ( is_wp_error( $c ) ) { if ( is_admin() ) { echo '<p>' . esc_html( $c->get_error_message() ) . '</p>'; } return; }
 $f = $c['fighter']; $stats = $c['stats']; $overall = null;
 foreach ( $c['rankings'] as $ranking ) { if ( 'overall' === $ranking['board'] ) { $overall = $ranking; break; } }
 $facts = mmaf_fighter_facts( $f );
 $prefix = mmaf_instance_prefix( 'mmaf_history_' . (int) $f['id'] . '_' . sanitize_key( $GLOBALS['mmaf_block_instance'] ?? 'profile' ) . '_' . $section );
 $options = array( 'per_page' => 10 ); $history = null;
 if ( 'history' === $section ) {
  foreach ( array( 'page', 'result', 'method', 'sort' ) as $key ) { if ( isset( $_GET[$prefix . '_' . $key] ) && is_scalar( $_GET[$prefix . '_' . $key] ) ) { $options[$key] = sanitize_text_field( wp_unslash( $_GET[$prefix . '_' . $key] ) ); } }
  $history = mmaf_public_service()->history( $f['id'], $options );
  if ( is_wp_error( $history ) ) { echo '<p>' . esc_html( $history->get_error_message() ) . '</p>'; return; }
 }
 mmaf_view( 'fighter-' . $section, compact( 'c', 'f', 'stats', 'overall', 'facts', 'prefix', 'options', 'history' ) );
}

function mmaf_filter_select( $name, $label, $choices, $value ) {
	echo '<label data-filter="' . esc_attr( substr( $name, strrpos( $name, '_' ) + 1 ) ) . '">' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
	foreach ( $choices as $key => $text ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $text ) . '</option>'; } echo '</select></label>';
}
function mmaf_score_details( $ranking ) {
	if ( empty( $ranking['breakdown'] ) ) { return; }
	$score_label=mmaf_is_career_formula($ranking)?'Whole professional MMA career':(($ranking['formula_version']??'')==='v1.7'?'Career base + recent quality':((($ranking['formula_version']??'')==='v1.6')?'MMA Future points since 9 May 2026':'MMA Future Score'));
	echo '<details class="mmaf-score-details"><summary>Score details</summary><p>' . esc_html($score_label) . ': ' . esc_html( mmaf_display_number( $ranking['score'], 2 ) ) . ' · Formula ' . esc_html( $ranking['formula_version'] ) . '</p>';
	$walk = static function( $data ) use ( &$walk ) {
		echo '<dl>'; foreach ( $data as $label => $value ) {
			if ( in_array( $label, array( 'fighter_id', 'bout_id', 'event_id', 'raw_score', 'total_score' ), true ) ) { continue; }
			if ( 'finish_rate' === $label ) { echo '<div><dt>Finish rate</dt><dd>' . esc_html( mmaf_display_percent( $value ) ) . '</dd></div>'; }
			elseif ( is_numeric( $value ) ) { echo '<div><dt>' . esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</dt><dd>' . esc_html( mmaf_display_number( $value, 2 ) ) . '</dd></div>'; }
			elseif ( is_array( $value ) && ! array_is_list( $value ) ) { echo '<div><dt>' . esc_html( ucwords( str_replace( '_', ' ', $label ) ) ) . '</dt><dd>'; $walk( $value ); echo '</dd></div>'; }
		} echo '</dl>';
	}; $walk( $ranking['breakdown'] ); echo '</details>';
}
function mmaf_render_rankings_table( $data, $breakdown = false, $prefix = 'mmaf' ) {
 $data['items'] = array_map( static fn( $row ) => $row + ($row['scoring_assessment']['career_stats']??$row['scoring_assessment']['period_record']??$row['scoring_assessment']['period_stats']??[]) + ( $row['stats'] ?? array() ) + ['streak'=>null,'last_fight_date'=>null,'finish_rate'=>null], $data['items'] );
 mmaf_view( 'rankings-rows', compact( 'data', 'breakdown', 'prefix' ) );
}

function mmaf_render_rankings( $full = false, $block_id = '' ) {
 $service = mmaf_public_service(); if ( ! $service ) { return; }
 $prefix = mmaf_instance_prefix( 'mmaf_rankings_' . sanitize_key( $block_id ?: wp_unique_id() ) );
 $options = array( 'board' => mmaf_site_field( $full ? 'default_board' : 'ranking_board' ) ?: ( $full ? 'male' : 'overall' ), 'per_page' => max( 1, (int) ( mmaf_site_field( $full ? 'rows_per_page' : 'top_five_count' ) ?: ( $full ? 25 : 5 ) ) ), 'include_breakdown' => $full && mmaf_site_field( 'show_score_breakdown' ) );
 if ( $full ) { foreach ( array( 'board', 'q', 'sort', 'order', 'top15', 'page' ) as $key ) { if ( isset( $_GET[$prefix . '_' . $key] ) && is_scalar( $_GET[$prefix . '_' . $key] ) ) { $options[$key] = sanitize_text_field( wp_unslash( $_GET[$prefix . '_' . $key] ) ); } } }
 $options['view'] = 'main'; // The public site shows only the official activated ranking.
 $options['include_scoring_fights'] = (bool) $full; // The fight-by-fight list in the row details.
 $data = $service->rankings( $options );
 if ( is_wp_error( $data ) ) { echo '<p>' . esc_html( $data->get_error_message() ) . '</p>'; return; }
 $data += array( 'sort' => $options['sort'] ?? 'rank', 'order' => $options['order'] ?? 'asc' );
 $movers = $full ? null : $service->movers( $options['board'], 3, ['include_new'=>false] );
 mmaf_view( $full ? 'all-rankings' : 'home-rankings', compact( 'data', 'options', 'prefix', 'movers' ) );
}

function mmaf_render_faq( $page ) {
	foreach ( (array) mmaf_site_setting( 'faq_items' ) as $row ) {
		if ( empty( $row['question'] ) || ! in_array( $row['show_on'] ?? 'both', array( 'both', $page ), true ) ) { continue; }
		echo '<details class="mmaf-faq"><summary>' . esc_html( $row['question'] ) . '</summary><div class="mmaf-prose">' . wp_kses_post( $row['answer'] ) . '</div></details>';
	}
}
add_action( 'rest_api_init', static function() {
	foreach ( array( 'rankings', 'history' ) as $kind ) {
		register_rest_route( 'mma-future-site/v1', '/views/' . $kind, array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => static function( WP_REST_Request $request ) use ( $kind ) {
			$service = mmaf_public_service(); if ( ! $service ) { return new WP_Error( 'unavailable', 'Public data is temporarily unavailable.', array( 'status' => 503 ) ); }
			$options = $request->get_params(); if ( 'rankings' === $kind ) { $options['view'] = 'main'; $options['include_scoring_fights'] = true; } $data = 'rankings' === $kind ? $service->rankings( $options ) : $service->history( (int) ( $options['fighter_id'] ?? 0 ), $options );
			if ( is_wp_error( $data ) ) { return $data; }
			$data += array( 'sort' => $options['sort'] ?? 'rank', 'order' => $options['order'] ?? 'asc' );
			$prefix = sanitize_key( $options['prefix'] ?? 'mmaf' );
			$fallback = 'rankings' === $kind ? mmaf_rankings_url() : get_post_type_archive_link( 'mmaf_fighter' );
			$candidate = is_string( $options['view_url'] ?? null ) ? esc_url_raw( $options['view_url'] ) : $fallback;
			$host = wp_parse_url( $candidate, PHP_URL_HOST );
			$base = ( ! $host && str_starts_with( $candidate, '/' ) && ! str_starts_with( $candidate, '//' ) ) || $host === wp_parse_url( home_url(), PHP_URL_HOST ) ? $candidate : $fallback;
			$args = array();
			foreach ( array( 'board', 'q', 'sort', 'order', 'top15', 'result', 'method' ) as $key ) { if ( isset( $options[$key] ) && is_scalar( $options[$key] ) ) { $args[$prefix . '_' . $key] = sanitize_text_field( $options[$key] ); } }
			$previous_url = $GLOBALS['mmaf_view_url'] ?? null;
			$GLOBALS['mmaf_view_url'] = add_query_arg( $args, $base );
			try {
				ob_start(); if ( 'rankings' === $kind ) { mmaf_render_rankings_table( $data, ! empty( $options['include_breakdown'] ), $prefix ); } else { mmaf_render_fights( $data ); } mmaf_pagination( $data, $prefix ); $html = ob_get_clean();
			} finally { if ( null === $previous_url ) { unset( $GLOBALS['mmaf_view_url'] ); } else { $GLOBALS['mmaf_view_url'] = $previous_url; } }
			return array( 'html' => $html, 'page' => $data['page'], 'total' => $data['total'], 'total_pages' => $data['total_pages'] );
		} ) );
	}
} );
