<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function mmaf_render_editorial( $section ) {
 $views = array( 'hero' => 'about-hero', 'features' => 'about-features', 'how' => 'about-how', 'data' => 'about-data', 'faq' => 'about-faq', 'founder' => 'about-founder', 'team' => 'about-team', 'partners' => 'about-partners', 'cta' => 'about-cta' );
 if ( isset( $views[$section] ) ) { mmaf_view( $views[$section] ); }
}
function mmaf_render_contact() { mmaf_view( 'contact' ); }
function mmaf_render_article_relations( $post_id ) {
	$service = mmaf_public_service(); if ( ! $service ) { return; }
	$fighters = array();
	foreach ( array_slice( (array) mmaf_site_field( 'related_fighter_ids', $post_id ), 0, 3 ) as $id ) { $fighter = $service->fighter( (int) $id ); if ( ! is_wp_error( $fighter ) ) { $fighters[] = $fighter; } }
	if ( $fighters ) { mmaf_view( 'article-fighters', compact( 'fighters' ) ); }
	$events = array(); $repo = new \MMAF\DataEngine\Repositories\PublicReadRepository();
	foreach ( array_slice( (array) mmaf_site_field( 'related_event_ids', $post_id ), 0, 3 ) as $id ) { $event = $repo->event( (int) $id ); if ( $event ) { $events[] = $event; } }
	if ( $events ) { mmaf_view( 'article-events', array( 'events' => $events, 'title' => 'Related Events' ) ); }
	mmaf_site_link( mmaf_site_field( 'article_cta', $post_id ) );
}
function mmaf_render_upcoming_events() {
	if ( ! mmaf_public_service() ) { return; }
	$events = ( new \MMAF\DataEngine\Repositories\PublicReadRepository() )->upcoming_events();
	if ( ! $events ) { return; }
	mmaf_view( 'article-events', array( 'events' => $events, 'title' => 'Upcoming Events' ) );
}
