<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_display_number( $value, $decimals = 0 ) { return null === $value ? '—' : number_format_i18n( (float) $value, $decimals ); }
function mmaf_display_date( $value ) { return $value ? wp_date( 'M j, Y', strtotime( $value ), wp_timezone() ) : '—'; }
function mmaf_display_percent( $value ) { return null === $value ? '—' : number_format_i18n( 100 * (float) $value, 0 ) . '%'; }
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
/** An empty imported record must not imply that the fighter has never competed. */
function mmaf_fighter_has_fight_data( array $context ): bool {
	return ! empty( $context['recent_fights'] ) || (int) ( $context['stats']['pro_fights_count'] ?? 0 ) > 0;
}
function mmaf_fighter_record_available( array $context ): bool {
	return mmaf_fighter_has_fight_data( $context ) || ( 'complete' === ( $context['career_coverage']['status'] ?? '' ) && 0 === ( $context['stats']['pro_fights_count'] ?? null ) );
}
function mmaf_render_dates( $data ) {
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
	echo '<details class="mmaf-score-details"><summary>Score details</summary><p>MMA Future Score: ' . esc_html( mmaf_display_number( $ranking['score'], 2 ) ) . ' · Formula ' . esc_html( $ranking['formula_version'] ) . '</p>';
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
 $data['items'] = array_map( static fn( $row ) => $row + ( $row['stats'] ?? array() ), $data['items'] );
 mmaf_view( 'rankings-rows', compact( 'data', 'breakdown', 'prefix' ) );
}

function mmaf_render_rankings( $full = false, $block_id = '' ) {
 $service = mmaf_public_service(); if ( ! $service ) { return; }
 $prefix = mmaf_instance_prefix( 'mmaf_rankings_' . sanitize_key( $block_id ?: wp_unique_id() ) );
 $options = array( 'board' => mmaf_site_field( $full ? 'default_board' : 'ranking_board' ) ?: ( $full ? 'male' : 'overall' ), 'per_page' => max( 1, (int) ( mmaf_site_field( $full ? 'rows_per_page' : 'top_five_count' ) ?: ( $full ? 25 : 5 ) ) ), 'include_breakdown' => $full && mmaf_site_field( 'show_score_breakdown' ) );
 if ( $full ) { foreach ( array( 'board', 'q', 'sort', 'order', 'top15', 'page' ) as $key ) { if ( isset( $_GET[$prefix . '_' . $key] ) && is_scalar( $_GET[$prefix . '_' . $key] ) ) { $options[$key] = sanitize_text_field( wp_unslash( $_GET[$prefix . '_' . $key] ) ); } } }
 $data = $service->rankings( $options );
 if ( is_wp_error( $data ) ) { echo '<p>' . esc_html( $data->get_error_message() ) . '</p>'; return; }
 $data += array( 'sort' => $options['sort'] ?? 'rank', 'order' => $options['order'] ?? 'asc' );
 $movers = $full ? null : $service->movers( $options['board'], 3 );
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
			$options = $request->get_params(); $data = 'rankings' === $kind ? $service->rankings( $options ) : $service->history( (int) ( $options['fighter_id'] ?? 0 ), $options );
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
