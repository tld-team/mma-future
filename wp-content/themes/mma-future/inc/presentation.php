<?php
/** Presentation uses the original theme layouts; data remains in the public service. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_view( string $name, array $values = array() ): void {
	if ( ! preg_match( '/^[a-z-]+$/D', $name ) ) { return; }
	$path = get_template_directory() . '/template-parts/integration/' . $name . '.php';
	if ( ! is_file( $path ) ) { return; }
	extract( $values, EXTR_SKIP );
	require $path;
}

/** Stable per-request suffixes also cover duplicated saved ACF block IDs. */
function mmaf_instance_prefix( string $base ): string {
	static $counts = array();
	$base = sanitize_key( $base );
	$counts[$base] = ( $counts[$base] ?? 0 ) + 1;
	return $base . ( $counts[$base] > 1 ? '_' . $counts[$base] : '' );
}

function mmaf_presentation_link( $link, string $class ): void {
	if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['title'] ) ) { return; }
	$blank = '_blank' === ( $link['target'] ?? '' );
	echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $link['url'] ) . '"' . ( $blank ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '><span>' . esc_html( $link['title'] ) . '</span>';
	mmaf_view( 'icon-arrow' );
	echo '</a>';
}

function mmaf_social_links( array $rows, string $class, string $field = 'profile_link' ): void {
	$icons = array( 'facebook' => 'facebook-f', 'twitter' => 'x-twitter', 'x' => 'x-twitter', 'instagram' => 'instagram', 'youtube' => 'youtube', 'linkedin' => 'linkedin-in' );
	foreach ( $rows as $row ) {
		$link = $row[$field] ?? null;
		$url = is_array( $link ) ? ( $link['url'] ?? '' ) : $link;
		if ( ! $url ) { continue; }
		$platform = sanitize_key( $row['platform'] ?? '' );
		$label = is_array( $link ) ? ( $link['title'] ?? ucfirst( $platform ) ) : ucfirst( $platform );
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '">';
		if ( isset( $icons[$platform] ) ) { echo '<i class="fab fa-' . esc_attr( $icons[$platform] ) . '" aria-hidden="true"></i>'; }
		else { echo esc_html( $label ?: 'Profile' ); }
		echo '</a>';
	}
}

function mmaf_fighter_facts( array $fighter ): array {
	return array(
		'Nationality' => $fighter['nationality'] ?? null,
		'Height' => isset( $fighter['height_cm'] ) ? $fighter['height_cm'] . ' cm' : ( $fighter['height'] ?? null ),
		'Date of birth' => ! empty( $fighter['date_of_birth'] ) ? mmaf_display_date( $fighter['date_of_birth'] ) : ( ! empty( $fighter['birth_year'] ) ? 'Approx. ' . $fighter['birth_year'] : null ),
		'Age' => isset( $fighter['age'] ) ? ( 'approximate' === $fighter['age_precision'] ? 'Approx. ' : '' ) . $fighter['age'] : null,
		'Division' => ! empty( $fighter['weight_class'] ) && 'unknown' !== $fighter['weight_class'] ? ucwords( str_replace( '_', ' ', $fighter['weight_class'] ) ) : null,
		'Nickname' => $fighter['nickname'] ?? null,
		'Birthplace' => $fighter['birthplace'] ?? null,
		'Fighting out of' => $fighter['fighting_out_of'] ?? null,
		'Reach' => isset( $fighter['reach_cm'] ) ? $fighter['reach_cm'] . ' cm' : null,
		'Stance' => $fighter['stance'] ?? null,
		'Team' => $fighter['team_name'] ?? null,
		'Coach' => $fighter['coach_name'] ?? null,
	);
}

/** Public contact values only; the private recipient never reaches a view. */
function mmaf_render_contact_details( string $class, bool $list = false ): void {
	$values = array(
		'email' => array( 'envelope', mmaf_site_setting( 'contact_email' ), 'mailto:' . mmaf_site_setting( 'contact_email' ) ),
		'phone' => array( 'phone', mmaf_site_setting( 'contact_phone' ), 'tel:' . preg_replace( '/[^+0-9]/', '', (string) mmaf_site_setting( 'contact_phone' ) ) ),
		'location' => array( 'map-marker-alt', mmaf_site_setting( 'contact_location' ), mmaf_site_setting( 'contact_map_link' ) ),
	);
	foreach ( $values as $key => [$icon, $label, $url] ) {
		if ( ! $label ) { continue; }
		echo $list ? '<li class="flex items-center" style="color:#647488">' : '<div>';
		echo '<i class="fas fa-' . esc_attr( $icon ) . ' mr-2" aria-hidden="true"></i>';
		if ( $url ) { echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( 'location' === $key ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
		else { echo esc_html( $label ); }
		echo $list ? '</li>' : '</div>';
	}
}

function mmaf_opponent_name( array $opponent, string $class ): void {
	$name = $opponent['display_name'] ?: 'Unknown opponent';
	if ( ! empty( $opponent['permalink'] ) ) {
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $opponent['permalink'] ) . '">' . esc_html( $name ) . '</a>';
	} else { echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $name ) . '</span>'; }
}

function mmaf_opponent_image( array $opponent ): void {
	if ( ! empty( $opponent['image']['url'] ) ) { echo '<img class="w-full h-full object-cover" src="' . esc_url( $opponent['image']['url'] ) . '" alt="" loading="lazy">'; }
	else { echo '<span class="mmaf-neutral-avatar mmaf-avatar--placeholder w-full h-full" aria-hidden="true"><i class="fas fa-user"></i></span>'; }
}

/** Fragment links return to the public page, never to the REST response itself. */
function mmaf_presentation_query( array $args ): string {
	return add_query_arg( $args, $GLOBALS['mmaf_view_url'] ?? false );
}
