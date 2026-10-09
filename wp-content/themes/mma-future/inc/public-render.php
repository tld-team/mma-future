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
	if ( ! $history['items'] ) { echo '<p>No recorded professional MMA fights are available.</p>'; return; }
	echo '<div class="mmaf-table-wrap"><table class="mmaf-table mmaf-fights"><thead><tr><th>Result</th><th>Opponent</th><th>Event</th><th>Date</th><th>Method</th><th>Round / time</th></tr></thead><tbody>';
	foreach ( $history['items'] as $fight ) {
		$result = array( 'win' => 'W', 'loss' => 'L', 'draw' => 'D', 'no_contest' => 'NC' )[$fight['result']];
		echo '<tr><td data-label="Result"><span class="mmaf-result mmaf-result--' . esc_attr( $fight['result'] ) . '">' . $result . '</span>' . ( ! empty( $fight['record_note'] ) ? '<small class="mmaf-record-note">' . esc_html( $fight['record_note'] ) . '</small>' : '' ) . '</td><td data-label="Opponent">';
		$name = $fight['opponent']['display_name'] ?: 'Unknown opponent';
		if ( $fight['opponent']['permalink'] ) { echo '<a href="' . esc_url( $fight['opponent']['permalink'] ) . '">' . esc_html( $name ) . '</a>'; } else { echo esc_html( $name ); }
		echo '</td><td data-label="Event">' . esc_html( $fight['event_name'] ) . '</td><td data-label="Date">' . esc_html( mmaf_display_date( $fight['event_date'] ) ) . '</td><td data-label="Method">' . esc_html( mmaf_display_method( $fight ) ) . '</td><td data-label="Round / time">' . esc_html( ( $fight['round'] ?? '—' ) . ' / ' . ( $fight['time'] ?: '—' ) ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}
function mmaf_pagination( $data, $prefix ) {
	if ( $data['total_pages'] < 2 ) { return; }
	echo '<nav class="mmaf-pagination" aria-label="Results pages">';
	foreach ( array( $data['page'] - 1 => 'Previous', $data['page'] + 1 => 'Next' ) as $page => $text ) {
		if ( $page < 1 || $page > $data['total_pages'] ) { continue; }
		echo '<a class="mmaf-button mmaf-button--secondary" data-page="' . (int) $page . '" href="' . esc_url( add_query_arg( $prefix . '_page', $page ) ) . '">' . esc_html( $text ) . '</a>';
	}
	echo '<span>Page ' . (int) $data['page'] . ' of ' . (int) $data['total_pages'] . '</span></nav>';
}
function mmaf_render_fighter_section( $section ) {
	$c = mmaf_fighter_context();
	if ( is_wp_error( $c ) ) { if ( is_admin() ) { echo '<p>' . esc_html( $c->get_error_message() ) . '</p>'; } return; }
	$f = $c['fighter']; $s = $c['stats']; $overall = null;
	foreach ( $c['rankings'] as $ranking ) { if ( 'overall' === $ranking['board'] ) { $overall = $ranking; } }
	if ( 'bio' === $section && empty( $c['editorial']['biography'] ) && empty( $c['editorial']['career_highlights'] ) && ! array_filter( array_intersect_key( $f, array_flip( array( 'reach_cm','stance','birthplace','fighting_out_of','team_name','coach_name' ) ) ) ) ) { return; }
	echo '<section class="mmaf-section mmaf-fighter-' . esc_attr( $section ) . ( 'hero' === $section ? ' mf-hero mmaf-dark' : '' ) . '"><div class="mmaf-container">';
	if ( 'hero' === $section ) {
		echo '<div class="mmaf-fighter-heading">'; mmaf_fighter_image( $c, 'mmaf-fighter-photo' ); echo '<div><p class="mmaf-eyebrow">Fighter profile</p><h1>' . esc_html( $f['display_name'] ) . '</h1>';
		if ( $f['nickname'] ) { echo '<p class="mmaf-lead">“' . esc_html( $f['nickname'] ) . '”</p>'; }
		echo '<p class="mmaf-record">' . esc_html( mmaf_record( $s ) ) . '</p><p>' . esc_html( $c['career_coverage']['label'] ) . '</p><dl class="mmaf-facts">';
		$facts = array( 'Nationality' => $f['nationality'], 'Division' => 'unknown' !== $f['weight_class'] ? ucwords( str_replace( '_', ' ', $f['weight_class'] ) ) : null, 'Age' => null === $f['age'] ? null : ( 'approximate' === $f['age_precision'] ? 'Approx. ' : '' ) . $f['age'], 'Height' => $f['height_cm'] ? $f['height_cm'] . ' cm' : $f['height'], 'Overall rank' => $overall ? '#' . $overall['rank'] : null, 'MMA Future Score' => $overall ? mmaf_display_number( $overall['score'], 2 ) : null );
		foreach ( $facts as $label => $value ) { if ( null !== $value && '' !== $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; } }
		echo '</dl>'; mmaf_render_dates( $c['dates'] ); echo '</div></div>';
	} elseif ( 'stats' === $section ) {
		echo '<h2>Quick stats</h2><div class="mmaf-stat-grid">';
		$values = array( 'Wins' => $s['wins'] ?? null, 'Losses' => $s['losses'] ?? null, 'Draws' => $s['draws'] ?? null, 'No contests' => $s['nc'] ?? null, 'KO / TKO wins' => $s['ko_tko_wins'] ?? null, 'Submission wins' => $s['submission_wins'] ?? null, 'Decision wins' => $s['decision_wins'] ?? null );
		foreach ( $values as $label => $value ) { echo '<div class="mmaf-card"><span>' . esc_html( $label ) . '</span><strong>' . mmaf_display_number( $value ) . '</strong></div>'; }
		foreach ( array( 'Finish rate' => $s['finish_rate'] ?? null, 'Win rate' => $s['win_rate'] ?? null ) as $label => $value ) { echo '<div class="mmaf-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( mmaf_display_percent( $value ) ) . '</strong></div>'; }
		echo '</div>'; if ( ! empty( $s['recent_form'] ) ) { echo '<p>Recent form: <strong>' . esc_html( $s['recent_form'] ) . '</strong></p>'; }
	} elseif ( 'latest' === $section ) {
		echo '<h2>Latest fights</h2>'; $history = array( 'items' => array_slice( $c['recent_fights'], 0, 5 ) ); mmaf_render_fights( $history );
	} elseif ( 'history' === $section ) {
		$prefix = 'mmaf_history_' . (int) $f['id'] . '_' . sanitize_key( $GLOBALS['mmaf_block_instance'] ?? 'profile' );
		$options = array( 'per_page' => 10 ); foreach ( array( 'page', 'result', 'method', 'sort' ) as $key ) { if ( isset( $_GET[$prefix . '_' . $key] ) ) { $options[$key] = sanitize_text_field( wp_unslash( $_GET[$prefix . '_' . $key] ) ); } }
		$history = mmaf_public_service()->history( $f['id'], $options );
		echo '<h2>Fight history</h2><p>' . esc_html( $c['career_coverage']['label'] ) . '</p>';
		if ( 'complete' !== $c['career_coverage']['status'] ) { echo '<p class="mmaf-dates">' . esc_html( $c['career_coverage']['reason'] ) . '</p>'; }
		echo '<div data-mmaf-browser="history" data-endpoint="fighters/' . (int) $f['id'] . '/fights" data-prefix="' . esc_attr( $prefix ) . '" data-per-page="10"><form class="mmaf-filters" method="get">';
		mmaf_filter_select( $prefix . '_result', 'Result', array( '' => 'All results', 'win' => 'Wins', 'loss' => 'Losses', 'draw' => 'Draws', 'no_contest' => 'No contests' ), $options['result'] ?? '' );
		mmaf_filter_select( $prefix . '_method', 'Method', array( '' => 'All methods', 'ko_tko' => 'KO / TKO', 'submission' => 'Submission', 'decision' => 'Decision', 'other' => 'Other', 'unknown' => 'Unknown' ), $options['method'] ?? '' );
		mmaf_filter_select( $prefix . '_sort', 'Order', array( 'latest' => 'Newest first', 'oldest' => 'Oldest first' ), $options['sort'] ?? 'latest' );
		echo '<button class="mmaf-button">Apply</button></form><p class="mmaf-browser-status" aria-live="polite"></p><div class="mmaf-browser-results">'; mmaf_render_fights( $history ); mmaf_pagination( $history, $prefix ); echo '</div></div>';
	} elseif ( 'bio' === $section ) {
		echo '<h2>Fighter biography &amp; facts</h2>';
		if ( ! empty( $c['editorial']['biography'] ) ) { echo '<div class="mmaf-prose">' . wp_kses_post( $c['editorial']['biography'] ) . '</div>'; } echo '<ul>';
		foreach ( (array) $c['editorial']['career_highlights'] as $highlight ) { echo '<li>' . esc_html( $highlight['highlight_text'] ?? '' ) . '</li>'; } echo '</ul><dl class="mmaf-facts">';
		foreach ( array( 'Reach' => $f['reach_cm'] ? $f['reach_cm'] . ' cm' : null, 'Stance' => $f['stance'], 'Birthplace' => $f['birthplace'], 'Fighting out of' => $f['fighting_out_of'], 'Team' => $f['team_name'], 'Coach' => $f['coach_name'] ) as $label => $value ) { if ( $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>'; } }
		echo '</dl><div class="mmaf-actions">'; foreach ( (array) $c['editorial']['fighter_social_links'] as $row ) { mmaf_site_link( $row['link'] ?? null, 'mmaf-button mmaf-button--secondary' ); } echo '</div>';
	}
	echo '</div></section>';
}
function mmaf_filter_select( $name, $label, $choices, $value ) {
	echo '<label>' . esc_html( $label ) . '<select name="' . esc_attr( $name ) . '">';
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
function mmaf_render_rankings_table( $data, $breakdown = false ) {
	if ( empty( $data['items'] ) ) { echo '<p>' . esc_html( mmaf_site_field( 'empty_state_text' ) ?: 'No rankings match these filters.' ) . '</p>'; return; }
	echo '<div class="mmaf-table-wrap"><table class="mmaf-table mmaf-rankings"><thead><tr><th>Rank</th><th>Fighter</th><th>Record</th><th>MMA Future Score</th><th>Division</th></tr></thead><tbody>';
	foreach ( $data['items'] as $r ) {
		echo '<tr><td data-label="Rank">#' . (int) $r['rank'] . '</td><td data-label="Fighter"><a class="mmaf-fighter-link" href="' . esc_url( $r['permalink'] ) . '">';
		if ( ! empty( $r['image']['url'] ) ) { echo '<img class="mmaf-avatar" src="' . esc_url( $r['image']['url'] ) . '" alt="" loading="lazy">'; }
		else { echo '<span class="mmaf-avatar mmaf-avatar--placeholder" aria-hidden="true"><i class="fas fa-user"></i></span>'; }
		echo '<span>' . esc_html( $r['display_name'] ) . '</span></a></td><td data-label="Record">' . esc_html( mmaf_record( $r['record'] ) ) . '</td><td data-label="MMA Future Score"><strong>' . esc_html( mmaf_display_number( $r['score'], 2 ) ) . '</strong>'; if ( $breakdown ) { mmaf_score_details( $r ); } echo '</td><td data-label="Division">' . esc_html( ucwords( str_replace( '_', ' ', $r['weight_class'] ) ) ) . '</td></tr>';
	} echo '</tbody></table></div>';
}
function mmaf_render_rankings( $full = false, $block_id = '' ) {
	$service = mmaf_public_service(); if ( ! $service ) { return; }
	$prefix = 'mmaf_rankings_' . sanitize_key( $block_id ?: wp_unique_id() );
	$options = array( 'board' => mmaf_site_field( $full ? 'default_board' : 'ranking_board' ) ?: ( $full ? 'male' : 'overall' ), 'per_page' => max( 1, (int) ( mmaf_site_field( $full ? 'rows_per_page' : 'top_five_count' ) ?: ( $full ? 25 : 5 ) ) ), 'include_breakdown' => $full && mmaf_site_field( 'show_score_breakdown' ) );
	if ( $full ) { foreach ( array( 'board', 'q', 'sort', 'order', 'top15', 'page' ) as $key ) { if ( isset( $_GET[$prefix . '_' . $key] ) ) { $options[$key] = sanitize_text_field( wp_unslash( $_GET[$prefix . '_' . $key] ) ); } } }
	$data = $service->rankings( $options );
	if ( is_wp_error( $data ) ) { echo '<p>' . esc_html( $data->get_error_message() ) . '</p>'; return; }
	echo '<section class="mmaf-section ' . ( $full ? 'all-rankings-table-section' : 'short-ranking-overview' ) . '"><div class="mmaf-container">'; mmaf_section_heading( $full ? 'h1' : 'h2' ); mmaf_render_dates( $data );
	echo '<div data-mmaf-browser="rankings" data-prefix="' . esc_attr( $prefix ) . '" data-endpoint="rankings" data-per-page="' . (int) $options['per_page'] . '" data-breakdown="' . (int) $options['include_breakdown'] . '">';
	if ( $full ) {
		echo '<form class="mmaf-filters" method="get">'; $labels = \MMAF\DataEngine\Services\PublicReadService::board_labels(); $choices = array_intersect_key( $labels, array_flip( $data['available_boards'] ) ); if ( ! $choices ) { $choices = $labels; }
		mmaf_filter_select( $prefix . '_board', 'Ranking board', $choices, $options['board'] );
		echo '<label>Search fighters<input type="search" name="' . esc_attr( $prefix . '_q' ) . '" value="' . esc_attr( $options['q'] ?? '' ) . '" placeholder="Name or nationality"></label>';
		mmaf_filter_select( $prefix . '_sort', 'Sort by', array( 'rank' => 'Rank', 'score' => 'Score' ), $options['sort'] ?? 'rank' ); mmaf_filter_select( $prefix . '_order', 'Order', array( 'asc' => 'Ascending', 'desc' => 'Descending' ), $options['order'] ?? 'asc' );
		echo '<label class="mmaf-checkbox"><input type="checkbox" name="' . esc_attr( $prefix . '_top15' ) . '" value="1"' . checked( ! empty( $options['top15'] ), true, false ) . '>Top 15 only</label><button class="mmaf-button">Apply</button></form>';
	}
	echo '<p class="mmaf-browser-status" aria-live="polite"></p><div class="mmaf-browser-results">'; mmaf_render_rankings_table( $data, $options['include_breakdown'] ); if ( $full ) { mmaf_pagination( $data, $prefix ); } echo '</div></div>';
	if ( ! $full && mmaf_site_field( 'show_top_movers' ) ) {
		$movers = $service->movers( $options['board'], 3 ); echo '<div class="mmaf-movers"><h3>' . esc_html( mmaf_site_field( 'top_movers_title' ) ) . '</h3>';
		if ( empty( $movers['items'] ) ) { echo '<p>' . esc_html( $movers['message'] ) . '</p>'; }
		else { echo '<div class="mmaf-card-grid">'; foreach ( $movers['items'] as $m ) { echo '<a class="mmaf-card" href="' . esc_url( $m['permalink'] ) . '"><h4>' . esc_html( $m['display_name'] ) . '</h4><strong>↑ ' . (int) $m['rank_change'] . '</strong><p>#' . (int) $m['previous_rank'] . ' → #' . (int) $m['current_rank'] . '</p></a>'; } echo '</div>'; }
		if ( ! empty( $movers['new_entries'] ) ) { echo '<p>New to this board: '; foreach ( array_slice( $movers['new_entries'], 0, 3 ) as $entry ) { echo '<a href="' . esc_url( $entry['permalink'] ) . '">' . esc_html( $entry['display_name'] ) . '</a> <span class="mmaf-result">New</span> '; } echo '</p>'; }
		echo '</div>';
	}
	mmaf_section_links(); echo '</div></section>';
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
			ob_start(); if ( 'rankings' === $kind ) { mmaf_render_rankings_table( $data, ! empty( $options['include_breakdown'] ) ); } else { mmaf_render_fights( $data ); } mmaf_pagination( $data, sanitize_key( $options['prefix'] ?? 'mmaf' ) ); $html = ob_get_clean();
			return array( 'html' => $html, 'page' => $data['page'], 'total' => $data['total'], 'total_pages' => $data['total_pages'] );
		} ) );
	}
} );
