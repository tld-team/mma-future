<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_render_editorial( $section ) {
	// Do not present demo people or partnerships when the editor has not confirmed them.
	if ( 'founder' === $section && ! mmaf_site_field( 'founder_name' ) ) { return; }
	if ( 'partners' === $section && ! mmaf_site_setting( 'partners' ) ) { return; }
	echo '<section class="mmaf-section about-' . esc_attr( $section ) . '-section' . ( in_array( $section, array( 'hero', 'cta' ), true ) ? ' mmaf-dark' : '' ) . '"><div class="mmaf-container">';
	mmaf_section_heading( 'hero' === $section ? 'h1' : 'h2' );
	if ( 'hero' === $section ) {
		mmaf_section_links(); echo '<div class="mmaf-badges">'; foreach ( (array) mmaf_site_field( 'badges' ) as $row ) { echo '<span>' . esc_html( $row['badge_text'] ) . '</span>'; } echo '</div>';
	} elseif ( 'features' === $section ) {
		echo '<div class="mmaf-card-grid">'; foreach ( (array) mmaf_site_field( 'features' ) as $row ) { echo '<div class="mmaf-card">' . mmaf_site_image( $row['feature_icon'], 'thumbnail', array( 'class' => 'mmaf-feature-icon' ) ) . '<h3>' . esc_html( $row['feature_title'] ) . '</h3><p>' . esc_html( $row['feature_description'] ) . '</p></div>'; } echo '</div>';
	} elseif ( 'how' === $section ) {
		echo '<ol class="mmaf-steps">'; foreach ( (array) mmaf_site_field( 'steps' ) as $row ) { echo '<li class="mmaf-card"><h3>' . esc_html( $row['step_title'] ) . '</h3><p>' . esc_html( $row['step_description'] ) . '</p></li>'; } echo '</ol><ul>'; foreach ( (array) mmaf_site_field( 'principles' ) as $row ) { echo '<li>' . esc_html( $row['principle_text'] ) . '</li>'; } echo '</ul>';
		$id = (int) mmaf_site_field( 'example_fighter_id' ); $service = mmaf_public_service(); $example = $id && $service ? $service->fighter( $id ) : null;
		if ( $example && ! is_wp_error( $example ) ) { foreach ( $example['rankings'] as $ranking ) { if ( 'overall' === $ranking['board'] ) { echo '<div class="mmaf-card"><h3>Active score example: ' . esc_html( $example['fighter']['display_name'] ) . '</h3>'; mmaf_score_details( $ranking ); echo '</div>'; break; } } }
	} elseif ( 'data' === $section ) {
		echo '<div class="mmaf-card-grid">'; foreach ( array( 'data_sources_text' => 'Sources', 'update_schedule_text' => 'Updates', 'limitations_text' => 'Coverage and limitations' ) as $name => $label ) { if ( $text = mmaf_site_field( $name ) ) { echo '<div class="mmaf-card"><h3>' . esc_html( $label ) . '</h3><div class="mmaf-prose">' . wp_kses_post( wpautop( $text ) ) . '</div></div>'; } } echo '</div>';
		if ( $text = mmaf_site_field( 'disclaimer' ) ) { echo '<p class="mmaf-dates">' . esc_html( $text ) . '</p>'; }
	} elseif ( 'faq' === $section ) { mmaf_render_faq( has_block( 'acf/all-rankings-table-section', get_queried_object_id() ) ? 'rankings' : 'about' );
	} elseif ( 'founder' === $section ) {
		echo '<div class="mmaf-founder">' . mmaf_site_image( mmaf_site_field( 'founder_photo' ), 'large', array( 'class' => 'mmaf-founder-photo' ) ) . '<div><h3>' . esc_html( mmaf_site_field( 'founder_name' ) ) . '</h3><p class="mmaf-eyebrow">' . esc_html( mmaf_site_field( 'founder_role' ) ) . '</p><div class="mmaf-prose">' . wp_kses_post( mmaf_site_field( 'founder_biography' ) ) . '</div><ul>';
		foreach ( (array) mmaf_site_field( 'experience_items' ) as $row ) { echo '<li>' . esc_html( $row['experience_text'] ) . '</li>'; } echo '</ul>';
		if ( $text = mmaf_site_field( 'founder_quote' ) ) { echo '<blockquote>' . esc_html( $text ) . '</blockquote>'; }
		echo mmaf_site_image( mmaf_site_field( 'signature_image' ), 'medium', array( 'class' => 'mmaf-signature' ) ) . '<div class="mmaf-actions">'; foreach ( (array) mmaf_site_field( 'founder_links' ) as $row ) { mmaf_site_link( $row['link'] ); } echo '</div></div></div>';
	} elseif ( 'team' === $section ) {
		echo '<div class="mmaf-card-grid">'; foreach ( (array) mmaf_site_field( 'team_members' ) as $row ) { echo '<div class="mmaf-card">' . mmaf_site_image( $row['member_photo'], 'medium', array( 'class' => 'mmaf-team-photo' ) ) . '<h3>' . esc_html( $row['member_name'] ) . '</h3><p class="mmaf-eyebrow">' . esc_html( $row['member_role'] ) . '</p><p>' . esc_html( $row['member_description'] ) . '</p><p>' . esc_html( $row['member_focus'] ) . '</p>'; mmaf_site_link( $row['member_link'] ); echo '</div>'; } echo '</div>'; mmaf_section_links();
	} elseif ( 'partners' === $section ) {
		echo '<div class="mmaf-partners">'; foreach ( (array) mmaf_site_setting( 'partners' ) as $row ) {
			if ( empty( $row['partner_name'] ) || empty( $row['partner_logo'] ) ) { continue; }
			$link = $row['partner_link']; $image = mmaf_site_image( $row['partner_logo'], 'medium', array( 'alt' => $row['partner_name'] ) );
			if ( ! empty( $link['url'] ) ) { echo '<a href="' . esc_url( $link['url'] ) . '"' . ( '_blank' === ( $link['target'] ?? '' ) ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . $image . '</a>'; } else { echo $image; }
		} echo '</div>'; if ( $text = mmaf_site_field( 'disclaimer' ) ) { echo '<p class="mmaf-dates">' . esc_html( $text ) . '</p>'; }
	} elseif ( 'cta' === $section ) { mmaf_section_links(); }
	echo '</div></section>';
}
function mmaf_render_contact() {
	echo '<section class="mmaf-section contact-main-section"><div class="mmaf-container">'; mmaf_section_heading( 'h1' ); echo '<div class="mmaf-contact-grid"><div><div class="mmaf-contact-details">';
	if ( $email = mmaf_site_setting( 'contact_email' ) ) { echo '<p><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>'; }
	if ( $phone = mmaf_site_setting( 'contact_phone' ) ) { echo '<p><a href="tel:' . esc_attr( preg_replace( '/[^+0-9]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a></p>'; }
	if ( $location = mmaf_site_setting( 'contact_location' ) ) { $map = mmaf_site_setting( 'contact_map_link' ); echo '<p>' . ( $map ? '<a href="' . esc_url( $map ) . '" target="_blank" rel="noopener noreferrer">' : '' ) . esc_html( $location ) . ( $map ? '</a>' : '' ) . '</p>'; }
	echo '</div>'; foreach ( (array) mmaf_site_field( 'contact_reasons' ) as $row ) { echo '<div class="mmaf-card"><h3>' . esc_html( $row['reason_title'] ) . '</h3><p>' . esc_html( $row['reason_description'] ) . '</p></div>'; } echo '<div class="mmaf-actions">'; foreach ( (array) mmaf_site_field( 'quick_links' ) as $row ) { mmaf_site_link( $row['link'] ); } echo '</div></div>';
	$id = wp_unique_id( 'mmaf-contact-' );
	echo '<form id="' . esc_attr( $id . 'form' ) . '" class="mmaf-contact-form mmaf-card" data-mmaf-contact method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h2>' . esc_html( mmaf_site_field( 'form_title' ) ) . '</h2><input type="hidden" name="action" value="mmaf_contact">'; echo '<input type="hidden" name="contact_nonce" value="' . esc_attr( wp_create_nonce( 'mmaf_contact' ) ) . '"><input type="hidden" name="contact_form_id" value="' . esc_attr( $id . 'form' ) . '">';
	if ( isset( $_GET['contact_status'] ) ) { $sent = 'sent' === sanitize_key( $_GET['contact_status'] ); echo '<p role="status">' . esc_html( $sent ? ( mmaf_site_setting( 'contact_success_message' ) ?: 'Your message has been accepted.' ) : 'Unable to send the message. Please check the form and try again.' ) . '</p>'; }
	foreach ( array( 'name' => 'text', 'email' => 'email', 'organization' => 'text', 'website' => 'url' ) as $name => $type ) {
		$label = mmaf_site_field( 'form_' . $name . '_label' ); $required = in_array( $name, array( 'name', 'email' ), true );
		echo '<label for="' . esc_attr( $id . $name ) . '">' . esc_html( $label ) . ( $required ? ' *' : '' ) . '</label><input id="' . esc_attr( $id . $name ) . '" name="' . $name . '" type="' . $type . '" maxlength="' . ( 'website' === $name ? 500 : ( 'email' === $name ? 254 : ( 'name' === $name ? 120 : 160 ) ) ) . '" placeholder="' . esc_attr( mmaf_site_field( 'form_' . $name . '_placeholder' ) ) . '"' . ( $required ? ' required' : '' ) . '><span class="mmaf-field-error" data-error-for="' . $name . '"></span>';
	}
	echo '<label for="' . esc_attr( $id . 'reason' ) . '">' . esc_html( mmaf_site_field( 'form_reason_label' ) ) . ' *</label><select id="' . esc_attr( $id . 'reason' ) . '" name="reason" required><option value="">' . esc_html( mmaf_site_field( 'form_reason_placeholder' ) ) . '</option>'; foreach ( mmaf_contact_reasons() as $key => $reason ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $reason ) . '</option>'; } echo '</select><span class="mmaf-field-error" data-error-for="reason"></span>';
	echo '<label for="' . esc_attr( $id . 'message' ) . '">' . esc_html( mmaf_site_field( 'form_message_label' ) ) . ' *</label><textarea id="' . esc_attr( $id . 'message' ) . '" name="message" rows="7" maxlength="6000" required placeholder="' . esc_attr( mmaf_site_field( 'form_message_placeholder' ) ) . '"></textarea><span class="mmaf-field-error" data-error-for="message"></span><div class="mmaf-honeypot" aria-hidden="true"><label>Leave this blank<input name="company_fax" tabindex="-1" autocomplete="off"></label></div><p class="mmaf-dates">' . esc_html( mmaf_site_field( 'privacy_notice' ) ) . '</p><button class="mmaf-button" type="submit">' . esc_html( mmaf_site_field( 'submit_label' ) ) . '</button><p class="mmaf-contact-status" role="status" tabindex="-1"></p></form></div></div></section>';
}
function mmaf_render_article_relations( $post_id ) {
	$service = mmaf_public_service(); if ( ! $service ) { return; }
	$fighters = array();
	foreach ( array_slice( (array) mmaf_site_field( 'related_fighter_ids', $post_id ), 0, 3 ) as $id ) { $fighter = $service->fighter( (int) $id ); if ( ! is_wp_error( $fighter ) ) { $fighters[] = $fighter; } }
	if ( $fighters ) { echo '<div class="mmaf-card"><h3>Related fighters</h3><ul class="list-none">';
		foreach ( $fighters as $fighter ) { echo '<li><a href="' . esc_url( $fighter['profile']['permalink'] ) . '">' . esc_html( $fighter['fighter']['display_name'] ) . '</a></li>'; } echo '</ul></div>'; }
	$events = array(); $repo = new \MMAF\DataEngine\Repositories\PublicReadRepository();
	foreach ( array_slice( (array) mmaf_site_field( 'related_event_ids', $post_id ), 0, 3 ) as $id ) { $event = $repo->event( (int) $id ); if ( $event ) { $events[] = $event; } }
	if ( $events ) { echo '<div class="mmaf-card"><h3>Related events</h3>'; foreach ( $events as $event ) { echo '<p><strong>' . esc_html( $event['event_name'] ) . '</strong><br><span class="mmaf-dates">' . esc_html( mmaf_display_date( $event['event_date'] ) ) . '</span></p>'; } echo '</div>'; }
	mmaf_site_link( mmaf_site_field( 'article_cta', $post_id ) );
}
function mmaf_render_upcoming_events() {
	if ( ! mmaf_public_service() ) { return; }
	$events = ( new \MMAF\DataEngine\Repositories\PublicReadRepository() )->upcoming_events();
	if ( ! $events ) { return; }
	echo '<div class="mmaf-card"><h3>Upcoming events</h3>';
	foreach ( $events as $event ) { echo '<p><strong>' . esc_html( $event['event_name'] ) . '</strong><br><span class="mmaf-dates">' . esc_html( mmaf_display_date( $event['event_date'] ) ) . '</span></p>'; } echo '</div>';
}
