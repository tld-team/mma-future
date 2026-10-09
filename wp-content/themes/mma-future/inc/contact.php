<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read the configured reasons from saved Contact blocks, shared by both handlers. */
function mmaf_contact_reasons(): array {
	static $reasons = null;
	if ( null !== $reasons ) { return $reasons; }
	$reasons = array();
	$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 's' => 'acf/contact-main-section' ) );
	$walk = static function( $blocks ) use ( &$walk, &$reasons ) {
		foreach ( $blocks as $block ) {
			if ( 'acf/contact-main-section' === $block['blockName'] ) {
				$data = $block['attrs']['data'] ?? array(); $count = (int) ( $data['contact_reasons'] ?? 0 );
				for ( $i = 0; $i < $count; $i++ ) {
					$title = trim( (string) ( $data['contact_reasons_' . $i . '_reason_title'] ?? '' ) );
					if ( '' !== $title ) { $reasons[sanitize_title( $title )] = $title; }
				}
			}
			$walk( $block['innerBlocks'] );
		}
	};
	foreach ( $pages as $page ) { $walk( parse_blocks( $page->post_content ) ); }
	return $reasons;
}

function mmaf_contact_submit( array $input ) {
	if ( ! is_string( $input['contact_nonce'] ?? null ) || ! wp_verify_nonce( $input['contact_nonce'], 'mmaf_contact' ) ) { return new WP_Error( 'expired', 'Please reload the page and try again.', array( 'status' => 403 ) ); }
	if ( ! empty( $input['company_fax'] ) ) { return new WP_Error( 'spam', 'Unable to send this message.', array( 'status' => 400 ) ); }
	$errors = array(); $values = array();
	foreach ( array( 'name' => 120, 'email' => 254, 'reason' => 160, 'organization' => 160, 'website' => 500, 'message' => 6000 ) as $key => $limit ) {
		$raw = isset( $input[$key] ) && is_scalar( $input[$key] ) ? trim( (string) $input[$key] ) : '';
		if ( strlen( $raw ) > $limit ) { $errors[$key] = 'This field is too long.'; }
		$values[$key] = 'message' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		if ( in_array( $key, array( 'name', 'email', 'reason', 'message' ), true ) && '' === $values[$key] ) { $errors[$key] = 'This field is required.'; }
	}
	if ( ! is_email( $values['email'] ) || preg_match( '/[\r\n]/', $values['email'] ) ) { $errors['email'] = 'Enter a valid email address.'; }
	$reasons = mmaf_contact_reasons();
	if ( ! isset( $reasons[$values['reason']] ) ) { $errors['reason'] = 'Choose an available reason.'; }
	if ( $values['website'] && ! filter_var( $values['website'], FILTER_VALIDATE_URL ) ) { $errors['website'] = 'Enter a full website URL.'; }
	if ( $errors ) { return new WP_Error( 'validation', 'Please check the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) ); }
	$rate_key = 'mmaf_contact_' . substr( hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ), wp_salt( 'nonce' ) ), 0, 32 );
	$rate = (array) get_transient( $rate_key ); $now = time();
	if ( isset( $rate['last'] ) && $now - $rate['last'] < 30 || (int) ( $rate['count'] ?? 0 ) >= 5 ) { return new WP_Error( 'rate_limit', 'Please wait before sending another message.', array( 'status' => 429 ) ); }
	// Count accepted attempts, including mail failures. Never store message contents.
	set_transient( $rate_key, array( 'last' => $now, 'count' => (int) ( $rate['count'] ?? 0 ) + 1 ), HOUR_IN_SECONDS );
	$recipient = mmaf_site_setting( 'contact_recipient' );
	if ( ! is_email( $recipient ) ) { return new WP_Error( 'mail_configuration', 'The contact form is temporarily unavailable.', array( 'status' => 503 ) ); }
	$host = wp_parse_url( home_url(), PHP_URL_HOST ); $host = preg_replace( '/^www\./', '', $host );
	$from = apply_filters( 'mmaf_contact_from', 'website@' . $host );
	$subject = '[MMA Future] ' . $reasons[$values['reason']];
	$body = "Name: {$values['name']}\nEmail: {$values['email']}\nOrganization: {$values['organization']}\nWebsite: {$values['website']}\nReason: {$reasons[$values['reason']]}\n\n{$values['message']}";
	$sent = wp_mail( $recipient, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8', 'From: MMA Future <' . sanitize_email( $from ) . '>', 'Reply-To: ' . $values['email'] ) );
	if ( ! $sent ) { return new WP_Error( 'mail_failed', mmaf_site_setting( 'contact_error_message' ) ?: 'Unable to send your message. Please try again later.', array( 'status' => 502 ) ); }
	return array( 'success' => true, 'message' => mmaf_site_setting( 'contact_success_message' ) ?: 'Your message has been accepted. Thank you for contacting MMA Future.' );
}
add_action( 'rest_api_init', static function() {
	register_rest_route( 'mma-future-site/v1', '/contact', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => static function( WP_REST_Request $request ) { return mmaf_contact_submit( $request->get_params() ); } ) );
} );
function mmaf_contact_fallback() {
	$result = mmaf_contact_submit( wp_unslash( $_POST ) );
	$url = wp_get_referer() ?: home_url( '/contact/' ); $url = remove_query_arg( array( 'contact_status' ), $url );
	wp_safe_redirect( add_query_arg( 'contact_status', is_wp_error( $result ) ? $result->get_error_code() : 'sent', $url ) . '#' . sanitize_key( is_string( $_POST['contact_form_id'] ?? null ) ? $_POST['contact_form_id'] : 'mmaf-contact-form' ) ); exit;
}
add_action( 'admin_post_mmaf_contact', 'mmaf_contact_fallback' );
add_action( 'admin_post_nopriv_mmaf_contact', 'mmaf_contact_fallback' );
add_action( 'template_redirect', static function() {
	if ( is_page() && has_block( 'acf/contact-main-section', get_queried_object_id() ) ) {
		if ( function_exists( 'wpfc_exclude_current_page' ) ) { wpfc_exclude_current_page(); }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
	}
} );
