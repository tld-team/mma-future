<?php
require __DIR__ . '/bootstrap.php';
use MMAF\DataEngine\Services\RegularImport\Transaction;
function assert_view( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function capture_view( $callback ) { ob_start(); $callback(); return ob_get_clean(); }
set_error_handler( static function( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new RuntimeException( "$message at $file:$line" ); } return false; } );
$service = mmaf_public_service();
$data = $service->rankings( array( 'board' => 'male', 'include_breakdown' => true ) );
$html = capture_view( static fn() => mmaf_render_rankings_table( $data, true, 'block_one' ) );
assert_view( str_contains( $html, 'rk-main-row' ) && str_contains( $html, 'rk-card' ), 'desktop table and mobile cards retain the original layouts' );
preg_match_all( '/data-fighter-id="(\d+)"/', $html, $ids );
assert_view( array_slice( $ids[1], 0, count( $data['items'] ) ) === array_slice( $ids[1], count( $data['items'] ) ), 'desktop and mobile use identical canonical results' );
$html2 = capture_view( static fn() => mmaf_render_rankings_table( $data, true, 'block_two' ) );
assert_view( str_contains( $html, 'block_one_detail_' ) && ! str_contains( $html2, 'block_one_detail_' ), 'ranking detail IDs are isolated by block instance' );
$context = $service->fighter( (int) $data['items'][0]['fighter_id'] );
$GLOBALS['mmaf_fighter_context'] = $context;
foreach ( array( 'hero', 'stats', 'latest', 'history', 'bio' ) as $section ) {
 $out = capture_view( static fn() => mmaf_render_fighter_section( $section ) );
 assert_view( strlen( $out ) > 100 && ! str_contains( $out, 'MMAFPHP' ) && ! str_contains( $out, '&lt;svg' ), "fighter $section renders canonical data without escaped template markup" );
}
// Empty imported histories must not masquerade as a verified debut record.
$empty_context = $context;
$empty_context['recent_fights'] = array();
$empty_context['stats'] = array_fill_keys( array( 'wins', 'losses', 'draws', 'nc', 'pro_fights_count', 'finish_wins' ), 0 );
$empty_context['career_coverage']['status'] = 'unavailable';
assert_view( ! mmaf_fighter_record_available( $empty_context ), 'unavailable history does not imply a verified zero record' );
$GLOBALS['mmaf_fighter_context'] = $empty_context;
$empty_hero = capture_view( static fn() => mmaf_render_fighter_section( 'hero' ) );
assert_view( ! str_contains( $empty_hero, '0–0–0' ) && str_contains( $empty_hero, 'Professional record not available yet' ), 'empty hero explains the missing record while preserving its ranking' );
$empty_history = capture_view( static fn() => mmaf_render_fighter_section( 'history' ) );
assert_view( str_contains( $empty_history, 'Fight history not available yet' ) && ! str_contains( $empty_history, '<select' ), 'missing history shows an explanation without unusable filters' );
$empty_context['career_coverage']['status'] = 'complete';
assert_view( mmaf_fighter_record_available( $empty_context ) && '0–0–0' === mmaf_record( $empty_context['stats'] ), 'confirmed zero record remains known zero' );
$empty_context['career_coverage']['status'] = 'partial';
$empty_context['stats']['pro_fights_count'] = 1;
$empty_context['stats']['losses'] = 1;
assert_view( mmaf_fighter_record_available( $empty_context ) && '0–1–0' === mmaf_record( $empty_context['stats'] ), 'zero wins with a recorded loss remains a real record' );
$GLOBALS['mmaf_fighter_context'] = $context;
$filtered_empty = capture_view( static fn() => mmaf_render_fights( array( 'items' => array() ) ) );
assert_view( str_contains( $filtered_empty, 'Try another result or method' ) && ! str_contains( $filtered_empty, 'not available yet' ), 'empty filter results do not claim that the career is unavailable' );
unset( $GLOBALS['mmaf_fighter_context'] );
assert_view( mmaf_instance_prefix( 'duplicate_block' ) !== mmaf_instance_prefix( 'duplicate_block' ), 'duplicated saved block IDs receive unique presentation prefixes' );
foreach ( array( 'home', 'about', 'contact', 'all-rankings' ) as $slug ) {
 $page = get_page_by_path( $slug );
 if ( ! $page && 'home' === $slug ) { $page = get_post( get_option( 'page_on_front' ) ); }
 assert_view( $page instanceof WP_Post, "$slug fixture exists" );
 $out = capture_view( static fn() => print do_blocks( $page->post_content ) );
 assert_view( ! str_contains( $out, 'MMAFPHP' ) && ! str_contains( $out, '&lt;svg' ) && ! str_contains( $out, 'style="width: 100%; height: 100%; object-fit: cover;">' ), "$slug blocks render without escaped or orphaned template markup" );
}
$server = rest_get_server();
$request = new WP_REST_Request( 'GET', '/mma-future-site/v1/views/rankings' );
$request->set_query_params( array( 'board' => 'male', 'q' => 'Pedro Henrique Dias', 'include_breakdown' => 1, 'prefix' => 'rest_fragment', 'view_url' => '/all-rankings/' ) );
$response = rest_do_request( $request ); $fragment = $response->get_data();
assert_view( str_contains( $fragment['html'], '/all-rankings/?rest_fragment_' ) && ! str_contains( $fragment['html'], '/wp-json/' ), 'fragment sort links retain the public page and current filters' );
assert_view( $response->get_status() === 200 && $fragment['total'] === 1 && str_contains( $fragment['html'], 'rest_fragment_detail_' ), 'REST fragments use the same presentation and search the entire board' );

assert_view( mmaf_display_percent( 1 ) === '100%' && mmaf_display_number( 0 ) === '0' && mmaf_display_number( null ) === '—', 'known zero, unknown values and percentage ratios retain their meaning' );
Transaction::begin();
try {
 $defaults = mmaf_content_defaults()['about-highlighted-data-section'];
 $broken = json_decode( wp_unslash( serialize_block_attributes( array( 'value' => $defaults['data_sources_text'] ) ) ), true )['value'];
 $blocks = array( array( 'blockName' => 'acf/about-highlighted-data-section', 'attrs' => array( 'data' => array( 'data_sources_text' => $broken, 'update_schedule_text' => '', 'limitations_text' => 'Editor <strong>kept</strong> "quoted" & accented ć.' ) ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
 $post_id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Isolated presentation repair fixture', 'post_content' => serialize_blocks( $blocks ) ) ) );
 $before = get_post( $post_id )->post_content;
 $expected = parse_blocks( $before )[0]['attrs']['data'];
 $report = mmaf_repair_seeded_content();
 assert_view( in_array( $post_id, $report['pages'], true ) && get_post( $post_id )->post_content === $before, 'repair dry-run reports the exact damaged seed without writing' );
 mmaf_repair_seeded_content( true ); clean_post_cache( $post_id );
 $saved = parse_blocks( get_post( $post_id )->post_content )[0]['attrs']['data'];
 assert_view( $saved['data_sources_text'] === $defaults['data_sources_text'], 'repair restores the recognized HTML default with correct slashing' );
 assert_view( $saved['update_schedule_text'] === '' && $saved['limitations_text'] === $expected['limitations_text'], 'deliberately empty and editor-modified content is preserved' );
 assert_view( ! in_array( $post_id, mmaf_repair_seeded_content( true )['pages'], true ), 'repair is idempotent' );
} finally { Transaction::rollback(); clean_post_cache( $post_id ); }
echo "Presentation and content repair checks completed.\n";
