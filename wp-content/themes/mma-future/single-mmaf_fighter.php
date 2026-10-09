<?php
$service = mmaf_public_service();
$fighter = $service ? ( new \MMAF\DataEngine\Repositories\RestReadRepository() )->fighter_by_wp_post_id( get_queried_object_id() ) : null;
$id = $fighter['id'] ?? null;
$context = $id && $service ? $service->fighter( $id ) : new WP_Error( 'not_found', 'Fighter not found.' );
if ( is_wp_error( $context ) ) { status_header( 404 ); }
get_header();
if ( is_wp_error( $context ) ) { echo '<main id="primary" class="mmaf-section"><div class="mmaf-container"><h1>Fighter not found</h1></div></main>'; }
else { $GLOBALS['mmaf_fighter_context'] = $context; echo '<main id="primary">'; foreach ( array( 'hero', 'stats', 'latest', 'history', 'bio' ) as $section ) { mmaf_render_fighter_section( $section ); } echo '</main>'; unset( $GLOBALS['mmaf_fighter_context'] ); }
get_footer();
